<?php

namespace MediaWiki\Extension\Wanda;

use ApiMain;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Title\Title;
use RuntimeException;

/**
 * Bounded candidate discovery, not a wiki-wide enumeration or an edit authorization.
 * The caller must recheck permissions and revisions when previewing/saving.
 * An empty namespace configuration means all nonnegative, registered namespaces.
 * Exceptions contain fixed identifiers for the calling API to translate.
 */
class WandaPageDiscovery {

	public const DEFAULT_LIMIT = 10;
	public const MAX_LIMIT = 50;
	/**
	 * Absolute safety ceiling for the admin-configurable per-batch page cap. Keeps a
	 * misconfiguration from turning a batch into an unbounded scan and stays within the
	 * MediaWiki list-query maximum for ordinary (non-apihighlimits) callers.
	 */
	public const ABSOLUTE_MAX_LIMIT = 500;

	/** @var IContextSource */
	private $context;
	/** @var int */
	private $limit;
	/** @var int Effective per-batch ceiling (config-driven, clamped to ABSOLUTE_MAX_LIMIT). */
	private $maxLimit;
	/** @var int[] */
	private $namespaces;

	public function __construct(
		IContextSource $context, int $limit, array $namespaces, int $maxLimit = self::MAX_LIMIT
	) {
		$this->context = $context;
		$this->maxLimit = self::normalizeMaxLimit( $maxLimit );
		$this->limit = self::normalizeLimit( $limit, $this->maxLimit );
		$this->namespaces = self::normalizeNamespaces(
			$namespaces,
			MediaWikiServices::getInstance()->getNamespaceInfo()->getValidNamespaces()
		);
	}

	/**
	 * One API query, at most limit + 1 candidates; never follows continuation.
	 * Rejected candidates are not replaced by an unbounded scan. Truncation describes
	 * the underlying query window, not the existence/count of readable matches.
	 * @param array $scope ['type' => 'search'|'category'|'allpages'|'semantic', 'query' => string]
	 * @return array ['titles' => string[], 'truncated' => bool]
	 */
	public function discover( array $scope ): array {
		$scope = self::normalizeScope( $scope );
		$window = min( $this->limit + 1, self::ABSOLUTE_MAX_LIMIT );
		if ( $scope['type'] === 'allpages' ) {
			return $this->discoverAllPages( $window );
		}
		if ( $scope['type'] === 'semantic' ) {
			return $this->discoverSemantic( $scope['query'], $window );
		}
		if ( $scope['type'] === 'search' ) {
			return $this->discoverKeyword( $scope['query'], $window );
		}

		$category = Title::newFromText( $scope['query'], NS_CATEGORY );
		if ( !$category || $category->isExternal() || $category->hasFragment()
			|| $category->getNamespace() !== NS_CATEGORY
		) {
			throw new RuntimeException( 'wanda-discovery-invalid-category' );
		}
		if ( !$this->context->getAuthority()->authorizeRead( 'read', $category ) ) {
			throw new RuntimeException( 'wanda-discovery-category-unreadable' );
		}
		return $this->runListQuery( [
			'action' => 'query',
			'list' => 'categorymembers',
			'cmtitle' => $category->getPrefixedText(),
			'cmnamespace' => implode( '|', $this->namespaces ),
			'cmprop' => 'title',
			'cmlimit' => $window,
		], 'categorymembers', $window );
	}

	/**
	 * Exact full-text (keyword) discovery over the configured namespaces. Also the
	 * safe fallback for {@see self::discoverSemantic()} when the vector index or the
	 * embedding provider is unavailable, so a conceptual instruction still yields
	 * relevance-ranked candidates instead of failing.
	 * @param string $query
	 * @param int $window
	 * @return array ['titles' => string[], 'truncated' => bool]
	 */
	private function discoverKeyword( string $query, int $window ): array {
		return $this->runListQuery(
			self::searchParameters( $query, $window, $this->namespaces, $this->maxLimit ),
			'search', $window
		);
	}

	/**
	 * Semantic discovery: rank candidates by embedding similarity to the instruction
	 * using the shared vector index, falling back to exact keyword discovery when that
	 * index or the embedding provider is unavailable. Titles come only from real index
	 * hits and are still gated by {@see self::isEligible()}.
	 * @param string $query
	 * @param int $window
	 * @return array ['titles' => string[], 'truncated' => bool]
	 */
	private function discoverSemantic( string $query, int $window ): array {
		try {
			$result = $this->createSemanticDiscovery()->search( $query, $window );
		} catch ( RuntimeException $e ) {
			if ( $e->getMessage() !== WandaSemanticDiscovery::UNAVAILABLE ) {
				throw $e;
			}
			return $this->discoverKeyword( $query, $window );
		}
		$titles = [];
		foreach ( $result['titles'] as $candidate ) {
			if ( !is_string( $candidate ) ) {
				continue;
			}
			$title = Title::newFromText( $candidate );
			if ( !$title || !$this->isEligible( $title ) ) {
				continue;
			}
			$canonical = $title->getPrefixedText();
			$titles[$canonical] = $canonical;
		}
		return [
			'titles' => array_slice( array_values( $titles ), 0, $this->limit ),
			'truncated' => (bool)$result['truncated'] || count( $titles ) > $this->limit,
		];
	}

	/** Seam for tests: builds the vector-backed semantic discovery helper. */
	protected function createSemanticDiscovery(): WandaSemanticDiscovery {
		return new WandaSemanticDiscovery( $this->context );
	}

	/**
	 * Run one bounded list query and reduce it to eligible, de-duplicated titles.
	 * @param array $params Service-built API query parameters.
	 * @param string $list Result list key ('search' or 'categorymembers').
	 * @param int $window
	 * @return array ['titles' => string[], 'truncated' => bool]
	 */
	private function runListQuery( array $params, string $list, int $window ): array {
		$data = $this->executeQuery( $params );
		$rows = $data['query'][$list] ?? [];
		if ( !is_array( $rows ) ) {
			throw new RuntimeException( 'wanda-discovery-query-failed' );
		}
		$truncated = self::hasMoreResults( $data, count( $rows ), $window );
		$titles = [];
		foreach ( array_slice( $rows, 0, $window ) as $row ) {
			if ( !is_array( $row ) || !is_string( $row['title'] ?? null ) ) {
				continue;
			}
			$title = Title::newFromText( $row['title'] );
			if ( !$title || !$this->isEligible( $title ) ) {
				continue;
			}
			$canonical = $title->getPrefixedText();
			$titles[$canonical] = $canonical;
		}
		return [
			'titles' => array_slice( array_values( $titles ), 0, $this->limit ),
			'truncated' => $truncated || count( $titles ) > $this->limit,
		];
	}

	/**
	 * Bounded wiki-wide enumeration across the configured namespaces for term-less
	 * site-wide requests. Not a full-wiki scan: each namespace is queried once for at
	 * most window nonredirect titles and iteration stops as soon as the window fills.
	 * @param int $window
	 * @return array ['titles' => string[], 'truncated' => bool]
	 */
	private function discoverAllPages( int $window ): array {
		$titles = [];
		$truncated = false;
		foreach ( $this->namespaces as $namespace ) {
			if ( count( $titles ) >= $window ) {
				$truncated = true;
				break;
			}
			$data = $this->executeQuery( [
				'action' => 'query',
				'list' => 'allpages',
				'apnamespace' => $namespace,
				'apfilterredir' => 'nonredirects',
				'aplimit' => $window,
			] );
			$rows = $data['query']['allpages'] ?? [];
			if ( !is_array( $rows ) ) {
				throw new RuntimeException( 'wanda-discovery-query-failed' );
			}
			if ( isset( $data['continue'] ) || isset( $data['query-continue'] )
				|| !empty( $data['warnings'] ) || count( $rows ) >= $window
			) {
				$truncated = true;
			}
			foreach ( $rows as $row ) {
				if ( !is_array( $row ) || !is_string( $row['title'] ?? null ) ) {
					continue;
				}
				$title = Title::newFromText( $row['title'] );
				if ( !$title || !$this->isEligible( $title ) ) {
					continue;
				}
				$canonical = $title->getPrefixedText();
				$titles[$canonical] = $canonical;
			}
		}
		return [
			'titles' => array_slice( array_values( $titles ), 0, $this->limit ),
			'truncated' => $truncated || count( $titles ) > $this->limit,
		];
	}

	private function isEligible( Title $title ): bool {
		if ( $title->isExternal() || $title->hasFragment()
			|| !in_array( $title->getNamespace(), $this->namespaces, true )
		) {
			return false;
		}
		$authority = $this->context->getAuthority();
		// No title or snippet escapes until these checks pass. No redirect following.
		return $authority->authorizeRead( 'read', $title )
			&& $authority->definitelyCan( 'edit', $title )
			&& $title->exists()
			&& $title->getContentModel() === CONTENT_MODEL_WIKITEXT;
	}

	/** Execute only service-built parameters; never merge the parent's GET/POST values. */
	protected function executeQuery( array $params ): array {
		$context = new DerivativeContext( $this->context );
		$context->setAuthority( $this->context->getAuthority() );
		$context->setRequest( new DerivativeRequest( $this->context->getRequest(), $params, true ) );
		try {
			$api = new ApiMain( $context, false, true );
			$api->execute();
			$data = $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] );
		} catch ( \Exception $e ) {
			// Do not expose backend errors, query details or credentials to the caller.
			throw new RuntimeException( 'wanda-discovery-query-failed' );
		}
		if ( !is_array( $data ) || isset( $data['error'] ) || isset( $data['errors'] ) ) {
			throw new RuntimeException( 'wanda-discovery-query-failed' );
		}
		return $data;
	}

	/** Nonpositive input uses the main API default; callers cap at the effective ceiling. */
	public static function normalizeLimit( int $limit, int $maxLimit = self::MAX_LIMIT ): int {
		$ceiling = self::normalizeMaxLimit( $maxLimit );
		return $limit > 0 ? min( $limit, $ceiling ) : min( self::DEFAULT_LIMIT, $ceiling );
	}

	/**
	 * Clamp an admin-configured per-batch ceiling into the supported range: a nonpositive
	 * value falls back to the built-in default, and any value is capped at ABSOLUTE_MAX_LIMIT.
	 */
	public static function normalizeMaxLimit( int $maxLimit ): int {
		if ( $maxLimit <= 0 ) {
			return self::MAX_LIMIT;
		}
		return min( $maxLimit, self::ABSOLUTE_MAX_LIMIT );
	}

	/**
	 * Validate namespace IDs against core, rather than turning malformed input into NS_MAIN.
	 * @param array $namespaces Configured IDs (integer or decimal string)
	 * @param int[] $validNamespaces Core NamespaceInfo::getValidNamespaces()
	 * @return int[]
	 */
	public static function normalizeNamespaces( array $namespaces, array $validNamespaces ): array {
		$valid = array_values( array_filter( $validNamespaces, static function ( $id ) {
			return is_int( $id ) && $id >= 0;
		} ) );
		$result = [];
		foreach ( $namespaces ?: $valid as $namespace ) {
			if ( !( is_int( $namespace ) ||
				( is_string( $namespace ) && preg_match( '/\A[0-9]+\z/', $namespace ) ) )
				|| !in_array( (int)$namespace, $valid, true )
			) {
				throw new RuntimeException( 'wanda-discovery-invalid-namespaces' );
			}
			$result[(int)$namespace] = (int)$namespace;
		}
		if ( !$result ) {
			throw new RuntimeException( 'wanda-discovery-invalid-namespaces' );
		}
		return array_values( $result );
	}

	/** Extra scope fields are discarded, including API flags and proposed title targets. */
	public static function normalizeScope( array $scope ): array {
		$type = $scope['type'] ?? null;
		if ( !in_array( $type, [ 'search', 'category', 'allpages', 'semantic' ], true )
			|| !is_string( $scope['query'] ?? null )
		) {
			throw new RuntimeException( 'wanda-discovery-invalid-scope' );
		}
		// Site-wide enumeration takes no query; any provided value is discarded.
		if ( $type === 'allpages' ) {
			return [ 'type' => 'allpages', 'query' => '' ];
		}
		$query = trim( $scope['query'] );
		if ( $query === '' || strlen( $query ) > 4096 || !mb_check_encoding( $query, 'UTF-8' )
			|| preg_match( '/[\x00-\x1f\x7f]/', $query )
		) {
			throw new RuntimeException( 'wanda-discovery-invalid-scope' );
		}
		return [ 'type' => $type, 'query' => $query ];
	}

	/** Fixed full-text query shape shared with native edit-context retrieval. No snippets. */
	public static function searchParameters(
		string $query, int $window, array $namespaces, int $maxLimit = self::MAX_LIMIT
	): array {
		$scope = self::normalizeScope( [ 'type' => 'search', 'query' => $query ] );
		return [
			'action' => 'query',
			'list' => 'search',
			'srsearch' => $scope['query'],
			'srwhat' => 'text',
			'srnamespace' => implode( '|', $namespaces ),
			'srlimit' => max( 1, min( $window, self::normalizeMaxLimit( $maxLimit ) + 1 ) ),
			'srprop' => '',
			'srinfo' => 'totalhits',
		];
	}

	/** Saturation and warnings are conservative: never assert exhaustiveness on uncertainty. */
	public static function hasMoreResults( array $data, int $count, int $window ): bool {
		return isset( $data['continue'] ) || isset( $data['query-continue'] )
			|| !empty( $data['warnings'] ) || $count >= $window
			|| ( $data['query']['searchinfo']['totalhits'] ?? 0 ) > $count;
	}
}
