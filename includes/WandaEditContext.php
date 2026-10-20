<?php

namespace MediaWiki\Extension\Wanda;

use ApiMain;
use ExtensionRegistry;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use RuntimeException;
use WikitextContent;

/**
 * Read-only, single-source context retrieval. The main API calls this once per
 * selected source and stores the returned snapshot on its batch. Sources are
 * citations, NEVER edit targets. No final APIChat answer, request LLM overrides,
 * model-invented titles, arbitrary URL fetching, or search snippets are used.
 *
 * Native wiki retrieval uses WandaAllowedNamespaces ([] = all real namespaces),
 * WandaMaxContextChars (default 10000, hard cap 50000, minimum 128), and
 * WandaDisabledSources. Work is bounded to one search and five page reads.
 * Core owns search/DB timeouts; this service cannot interrupt in-process queries.
 *
 * The existing optional handlers cannot safely satisfy this contract unmodified:
 * - CargoQueryHandler::query passes private aggregates through without provenance.
 * - SMWQueryHandler::query has no requesting authority or per-value read gate.
 * - WikibaseQueryHandler::query has no enforced LIMIT, permits SERVICE, and lacks
 *   an authority contract for private stores (even with maxQuerySteps = 1).
 * - ExternalWikiSearchHandler follows unchecked redirects and buffers unbounded
 *   response bodies. It cannot guarantee the configured-wiki-only fetch boundary.
 * Do not call these handlers then filter their already combined content/citations.
 * Safe adapters need changes outside this service. They must use configured LLM
 * settings only, maxQuerySteps = 1, row/response bounds, validated provenance,
 * authority-aware results, and bounded configured timeouts before being enabled.
 * No LLM/network adapter is advertised here until those guarantees exist.
 */
class WandaEditContext {

	private const MAX_PAGES = 5;
	private const MAX_PAGE_BYTES = 1048576;
	private const MAX_PAGE_CHARS = 4000;
	private const TRUNCATED = "[TRUNCATED]\n";
	/** @var IContextSource */
	private $context;

	public function __construct( IContextSource $context ) {
		$this->context = $context;
	}

	/** Only keys that retrieve() can actually serve are advertised. */
	public function availableSources(): array {
		return $this->sourceError( 'wiki' ) === null ? [ 'wiki' ] : [];
	}

	/**
	 * @param string $query Native full-text search input
	 * @param string $source Exactly one case-sensitive source key
	 * @return array ['content' => string, 'sources' => [['title' => string, 'href' => string]], 'source' => string]
	 * @throws RuntimeException Fixed error identifier only; no backend messages/secrets.
	 */
	public function retrieve( string $query, string $source ): array {
		$error = $this->sourceError( $source );
		if ( $error !== null ) {
			throw new RuntimeException( $error );
		}
		try {
			$query = WandaPageDiscovery::normalizeScope( [ 'type' => 'search', 'query' => $query ] )['query'];
		} catch ( RuntimeException $e ) {
			throw new RuntimeException( 'wanda-context-invalid-query' );
		}
		$maxChars = self::normalizeMaxChars( (int)$this->config( 'WandaMaxContextChars', 10000 ) );
		$namespaces = WandaPageDiscovery::normalizeNamespaces(
			(array)$this->config( 'WandaAllowedNamespaces', [] ),
			MediaWikiServices::getInstance()->getNamespaceInfo()->getValidNamespaces()
		);
		$data = $this->search( $query, $namespaces );
		$rows = $data['query']['search'] ?? [];
		if ( !is_array( $rows ) ) {
			throw new RuntimeException( 'wanda-context-query-failed' );
		}
		$truncated = WandaPageDiscovery::hasMoreResults( $data, count( $rows ), self::MAX_PAGES + 1 );
		$pages = [];
		$seen = [];
		// The sixth hit only establishes truncation; no content is read for it.
		foreach ( array_slice( $rows, 0, self::MAX_PAGES ) as $row ) {
			if ( !is_array( $row ) || !is_string( $row['title'] ?? null ) ) {
				continue;
			}
			$title = Title::newFromText( $row['title'] );
			if ( !$title || $title->isExternal() || $title->hasFragment()
				|| !in_array( $title->getNamespace(), $namespaces, true )
				|| !$this->context->getAuthority()->authorizeRead( 'read', $title )
			) {
				continue;
			}
			$canonical = $title->getPrefixedText();
			if ( isset( $seen[$canonical] ) ) {
				continue;
			}
			$seen[$canonical] = true;
			$revision = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionByTitle( $title );
			if ( !$revision ) {
				continue;
			}
			if ( $revision->getSize() > self::MAX_PAGE_BYTES ) {
				$truncated = true;
				continue;
			}
			// FOR_PUBLIC excludes revision-deleted/suppressed text even for privileged users.
			$content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::FOR_PUBLIC );
			if ( !( $content instanceof WikitextContent ) ) {
				continue;
			}
			$text = $content->getText();
			$truncated = $truncated || mb_strlen( $text, 'UTF-8' ) > self::MAX_PAGE_CHARS;
			$pages[] = [
				'title' => $canonical,
				'href' => $title->getLocalURL(),
				'content' => mb_substr( $text, 0, self::MAX_PAGE_CHARS, 'UTF-8' ),
			];
		}
		return self::packWikiContext( $pages, $maxChars, $truncated );
	}

	private function config( string $name, $default ) {
		$config = $this->context->getConfig();
		return $config->has( $name ) ? ( $config->get( $name ) ?? $default ) : $default;
	}

	private function sourceError( string $source ): ?string {
		$registry = ExtensionRegistry::getInstance();
		return self::validateSource(
			$source,
			(array)$this->config( 'WandaDisabledSources', [] ),
			(array)$this->config( 'WandaWikibaseSources', [] ),
			(array)$this->config( 'WandaExternalWikis', [] ),
			$registry->isLoaded( 'Cargo' ),
			$registry->isLoaded( 'SemanticMediaWiki' ) || defined( 'SMW_VERSION' )
		);
	}

	/** Pure source policy: null = available; a fixed identifier = unavailable. */
	public static function validateSource(
		string $source,
		array $disabled,
		array $wikibases,
		array $externalWikis,
		bool $cargoLoaded,
		bool $smwLoaded
	): ?string {
		if ( in_array( $source, $disabled, true ) ) {
			return 'wanda-context-source-disabled';
		}
		if ( $source === 'wiki' ) {
			return null;
		}
		if ( $source === 'publicknowledge' || strncmp( $source, 'RAG:', 4 ) === 0 ) {
			return 'wanda-context-source-unsupported';
		}
		if ( $source === 'cargo' || $source === 'smw' ) {
			$loaded = $source === 'cargo' ? $cargoLoaded : $smwLoaded;
			return $loaded ? 'wanda-context-source-permissions-unavailable' : 'wanda-context-source-unavailable';
		}
		if ( $source === 'externalwiki' ) {
			return $externalWikis ? 'wanda-context-source-bounds-unavailable' : 'wanda-context-source-unavailable';
		}
		if ( array_key_exists( $source, $wikibases ) ) {
			return 'wanda-context-source-bounds-unavailable';
		}
		return 'wanda-context-source-invalid';
	}

	/** Parent request flags/LLM credentials are deliberately not propagated. */
	protected function search( string $query, array $namespaces ): array {
		$params = WandaPageDiscovery::searchParameters( $query, self::MAX_PAGES + 1, $namespaces );
		$context = new DerivativeContext( $this->context );
		$context->setAuthority( $this->context->getAuthority() );
		$context->setRequest( new DerivativeRequest( $this->context->getRequest(), $params, true ) );
		try {
			$api = new ApiMain( $context, false, true );
			$api->execute();
			$data = $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] );
		} catch ( \Exception $e ) {
			throw new RuntimeException( 'wanda-context-query-failed' );
		}
		if ( !is_array( $data ) || isset( $data['error'] ) || isset( $data['errors'] ) ) {
			throw new RuntimeException( 'wanda-context-query-failed' );
		}
		return $data;
	}

	/** Do not silently exceed small configured budgets to fit a truncation notice. */
	public static function normalizeMaxChars( int $limit ): int {
		if ( $limit < 128 ) {
			throw new RuntimeException( 'wanda-context-limit-too-small' );
		}
		return min( $limit, 50000 );
	}

	/**
	 * Root-relative local paths or absolute HTTP(S) URLs only. Protocol-relative
	 * URLs, credentials, controls, backslashes and encoded controls are rejected.
	 * This validates citations, NOT destinations for fetching or edit targets.
	 */
	public static function isSafeHref( string $href ): bool {
		if ( $href === '' || strlen( $href ) > 2048
			|| preg_match( '/[\x00-\x20\x7f\\\\]/', $href )
			|| preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $href )
		) {
			return false;
		}
		if ( $href[0] === '/' ) {
			// Also reject encoded network-path references at the start of a local path.
			return !preg_match( '~^/(?:/|%2f)~i', $href );
		}
		$parts = parse_url( $href );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true )
			&& !empty( $parts['host'] ) && !isset( $parts['user'] ) && !isset( $parts['pass'] )
			&& filter_var( $href, FILTER_VALIDATE_URL ) !== false;
	}

	/**
	 * Normalize already-authorized wiki page records; this helper does not grant read access.
	 * Only citations with included content survive. Limit includes headers/marker in UTF-8
	 * characters. Records past five, oversized text and any skipped records are explicit.
	 */
	public static function packWikiContext( array $pages, int $maxChars, bool $truncated = false ): array {
		$maxChars = self::normalizeMaxChars( $maxChars );
		$truncated = $truncated || count( $pages ) > self::MAX_PAGES;
		$content = '';
		$sources = [];
		$seen = [];
		$budget = $maxChars - mb_strlen( self::TRUNCATED, 'UTF-8' );
		foreach ( array_slice( $pages, 0, self::MAX_PAGES ) as $page ) {
			if ( !is_array( $page ) || !is_string( $page['title'] ?? null )
				|| !is_string( $page['href'] ?? null ) || !is_string( $page['content'] ?? null )
				|| !self::isSafeHref( $page['href'] ) || trim( $page['title'] ) === ''
				|| strlen( $page['title'] ) > 1024
				|| !mb_check_encoding( $page['title'] . $page['content'], 'UTF-8' )
				|| preg_match( '/[\x00-\x1f\x7f]/', $page['title'] )
			) {
				$truncated = true;
				continue;
			}
			if ( isset( $seen[$page['href']] ) || $page['content'] === '' ) {
				continue;
			}
			$seen[$page['href']] = true;
			$header = ( $content === '' ? "[Native wiki search sample; not exhaustive.]\n" : "\n\n" )
				. '### ' . $page['title'] . "\n";
			$remaining = $budget - mb_strlen( $content . $header, 'UTF-8' );
			if ( $remaining < 1 ) {
				$truncated = true;
				break;
			}
			$length = min( $remaining, self::MAX_PAGE_CHARS );
			$truncated = $truncated || mb_strlen( $page['content'], 'UTF-8' ) > $length;
			$content .= $header . mb_substr( $page['content'], 0, $length, 'UTF-8' );
			$sources[] = [ 'title' => $page['title'], 'href' => $page['href'] ];
		}
		return [
			'content' => ( $truncated ? self::TRUNCATED : '' ) . $content,
			'sources' => $sources,
			'source' => 'wiki',
		];
	}
}
