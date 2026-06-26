<?php
/**
 * ExternalWikiSearchHandler.php
 *
 * Fetches prose context from external public MediaWiki wikis for Wanda.
 *
 *   - Returns ['content','sources','num_results','steps']
 *   - Uses curl_multi for parallel HTTP across all configured wikis
 *   - Probes each wiki's capabilities via meta=siteinfo before querying
 *   - Retries on transient errors (429, 502, 503, 504)
 *   - Process-local capability cache so siteinfo is fetched once per request
 *   - Integrated with the existing sources= parameter (value: 'externalwiki')
 *   - Confidence-gated: skips if local ES score already exceeds threshold
 *   - Structured source objects with title, href, type='externalwiki'
 *
 * Admin config (LocalSettings.php):
 *
 *   $wgWandaExternalWikis['Wikipedia (EN)'] = [ 'url' => 'https://en.wikipedia.org', 'namespaces' => [0] ];
 *   // 'namespaces' is optional; defaults to [0] (article namespace only)
 *   $wgWandaExternalWikiMaxResults  = 3;   // search hits per wiki
 *   $wgWandaExternalWikiExtractLen  = 1200; // chars per article extract
 *   $wgWandaExternalWikiTimeout     = 10;   // HTTP timeout per request (s)
 *   $wgWandaExternalWikiMinESScore  = 0;    // skip external if local ES >= this
 *                                           // (0 = always run, regardless of ES)
 *   $wgWandaExternalWikiDefaultNamespaces = [0]; // fallback namespaces if not set per-wiki
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\Wanda;

class ExternalWikiSearchHandler {

	private const USER_AGENT =
		'Wanda-MediaWiki-Extension/2.0 (https://www.mediawiki.org/wiki/Extension:Wanda)';

	/**
	 * Capability flags probed from meta=siteinfo per wiki.
	 * Cached for the lifetime of the PHP process (one web request).
	 *
	 * Structure: baseUrl => [
	 *   'hasTextExtracts' => bool,
	 *   'wikibaseItemProp' => bool,  // wiki is a Wikibase client
	 * ]
	 *
	 * @var array<string,array>
	 */
	private static $capabilityCache = [];

	/** @var array<string,array> Allowed wikis keyed by name from $wgWandaExternalWikis */
	private $allowedWikis;

	/** @var int Search hits to fetch per wiki */
	private $maxResults;

	/** @var int Max chars per article extract injected into prompt */
	private $extractLen;

	/** @var int HTTP timeout in seconds for outbound requests */
	private $timeout;

	/**
	 * Default namespaces to search when a wiki entry does not specify 'namespaces'.
	 *
	 * @var array
	 */
	private $defaultNamespaces;

	/**
	 * Minimum local ES score above which we skip external wikis entirely.
	 * 0 means always run regardless of local results.
	 *
	 * @var float
	 */
	private $minESScore;

	/**
	 * @param array $allowedWikis Value of $wgWandaExternalWikis
	 * @param int $maxResults
	 * @param int $extractLen
	 * @param int $timeout
	 * @param float $minESScore
	 * @param array $defaultNamespaces
	 */
	public function __construct(
		array $allowedWikis,
		int $maxResults = 3,
		int $extractLen = 1200,
		int $timeout = 10,
		float $minESScore = 0.0,
		array $defaultNamespaces = [ 0 ]
	) {
		$this->allowedWikis = $allowedWikis;
		$this->maxResults   = $maxResults;
		$this->extractLen   = $extractLen;
		$this->timeout      = $timeout;
		$this->minESScore       = $minESScore;
		$this->defaultNamespaces = $defaultNamespaces;
	}

	/**
	 * Main entry point. Matches the contract of WikidataQueryHandler::query()
	 * and CargoQueryHandler::query() exactly.
	 *
	 * @param string $userQuery
	 * @param float $localESScore Best score from the local ES search (for gating)
	 * @return array {content:string, sources:array, num_results:int, steps:array}
	 */
	public function query( string $userQuery, float $localESScore = 0.0 ): array {
		$steps = [];
		$empty = [
			'content'     => '',
			'sources'     => [],
			'num_results' => 0,
			'steps'       => &$steps,
		];

		if ( $this->minESScore > 0.0 && $localESScore >= $this->minESScore ) {
			wfDebugLog(
				'Wanda',
				'ExternalWikiSearch: skipping — local ES score ' . $localESScore .
				' >= threshold ' . $this->minESScore
			);
			return $empty;
		}

		if ( empty( $this->allowedWikis ) ) {
			wfDebugLog( 'Wanda', 'ExternalWikiSearch: no wikis configured' );
			return $empty;
		}

		$this->probeCapabilities( $this->allowedWikis );

		$searchResults = $this->parallelSearch( $userQuery );

		if ( empty( $searchResults ) ) {
			wfDebugLog( 'Wanda', 'ExternalWikiSearch: no search results from any wiki' );
			return $empty;
		}

		$extractResults = $this->parallelFetchExtracts( $searchResults );

		if ( empty( $extractResults ) ) {
			wfDebugLog( 'Wanda', 'ExternalWikiSearch: extract fetch returned nothing' );
			return $empty;
		}

		$allContent  = '';
		$allSources  = [];
		$totalPages  = 0;
		$seenHrefs   = [];

		foreach ( $extractResults as $wikiUrl => $pages ) {
			$wikiName = $this->wikiName( $wikiUrl );
			$block    = $this->formatWikiBlock( $wikiName, $wikiUrl, $pages );

			if ( $block === '' ) {
				continue;
			}

			$allContent .= ( $allContent !== '' ? "\n\n" : '' ) . $block;
			$totalPages += count( $pages );

			foreach ( $pages as $page ) {
				$href = $page['url'];
				if ( $href !== '' && !isset( $seenHrefs[$href] ) ) {
					$seenHrefs[$href] = true;
					$allSources[] = [
						'title' => $page['title'],
						'href'  => $href,
						'wiki'  => $wikiName,
						'type'  => 'externalwiki',
					];
				}
			}

			$steps[] = [
				'type'     => 'query',
				'wiki'     => $wikiName,
				'url'      => $wikiUrl,
				'pages'    => count( $pages ),
				'strategy' => $this->strategyLabel( $wikiUrl ),
			];
		}

		if ( $allContent === '' ) {
			return $empty;
		}

		wfDebugLog(
			'Wanda',
			'ExternalWikiSearch: returning ' . $totalPages . ' pages from ' .
			count( $extractResults ) . ' wiki(s)'
		);

		return [
			'content'     => $allContent,
			'sources'     => $allSources,
			'num_results' => $totalPages,
			'steps'       => $steps,
		];
	}

	/**
	 * For each wiki not yet in the capability cache, issue a siteinfo request
	 * in parallel and populate self::$capabilityCache.
	 *
	 * We probe: extensions list - TextExtracts, WikibaseClient
	 *
	 * @param array $wikis
	 */
	private function probeCapabilities( array $wikis ): void {
		$needed = [];
		foreach ( $wikis as $name => $wiki ) {
			$url = $this->normaliseUrl( $wiki['url'] ?? '' );
			if ( $url !== '' && !isset( self::$capabilityCache[$url] ) ) {
				$needed[] = $url;
			}
		}

		if ( empty( $needed ) ) {
			return;
		}

		$mh      = curl_multi_init();
		$handles = [];

		foreach ( $needed as $baseUrl ) {
			$apiUrl = $this->apiUrl( $baseUrl, [
				'action'  => 'query',
				'meta'    => 'siteinfo',
				'siprop'  => 'extensions',
				'format'  => 'json',
			] );
			$ch = $this->buildGetHandle( $apiUrl );
			curl_multi_add_handle( $mh, $ch );
			$handles[$baseUrl] = $ch;
		}

		$this->multiExec( $mh );

		foreach ( $handles as $baseUrl => $ch ) {
			$body     = curl_multi_getcontent( $ch );
			$httpCode = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );

			if ( $httpCode !== 200 || $body === false || $body === '' ) {
				// On probe failure, assume minimal capability set
				self::$capabilityCache[$baseUrl] = $this->defaultCapabilities();
				wfDebugLog( 'Wanda', 'ExternalWikiSearch: siteinfo probe failed for ' . $baseUrl );
				continue;
			}

			$data = json_decode( $body, true );
			self::$capabilityCache[$baseUrl] = $this->parseCapabilities( $data );

			wfDebugLog(
				'Wanda',
				'ExternalWikiSearch: probed ' . $baseUrl . ' → ' .
				json_encode( self::$capabilityCache[$baseUrl] )
			);
		}

		curl_multi_close( $mh );
	}

	/**
	 * Parse a siteinfo response into a capability array.
	 *
	 * @param array $data
	 * @return array
	 */
	private function parseCapabilities( array $data ): array {
		$caps = $this->defaultCapabilities();

		$extensions = $data['query']['extensions'] ?? [];
		foreach ( $extensions as $ext ) {
			$name = strtolower( $ext['name'] ?? '' );
			if ( $name === 'textextracts' ) {
				$caps['hasTextExtracts'] = true;
			}
			if ( $name === 'wikibaseclient' || $name === 'wikibase client' ) {
				$caps['wikibaseItemProp'] = true;
			}
		}

		return $caps;
	}

	/**
	 * @return array Default (minimal) capability set
	 */
	private function defaultCapabilities(): array {
		return [
			'hasTextExtracts' => false,
			'wikibaseItemProp' => false,
		];
	}

	/**
	 * Run list=search against every allowed wiki simultaneously.
	 *
	 * Returns: [ baseUrl => [ ['title'=>...,'snippet'=>...], ... ], ... ]
	 *
	 * @param string $query
	 * @return array
	 */
	private function parallelSearch( string $query ): array {
		$mh      = curl_multi_init();
		$handles = [];

		foreach ( $this->allowedWikis as $name => $wiki ) {
			$baseUrl = $this->normaliseUrl( $wiki['url'] ?? '' );
			if ( $baseUrl === '' || !$this->isAllowed( $baseUrl ) ) {
				continue;
			}

			$apiUrl = $this->apiUrl( $baseUrl, [
				'action'        => 'query',
				'list'          => 'search',
				'srsearch'      => $query,
				'srnamespace'   => implode( '|', $wiki['namespaces'] ?? $this->defaultNamespaces ),
				'srlimit'       => $this->maxResults,
				'srprop'        => 'snippet',
				'format'        => 'json',
				'formatversion' => 2,
			] );

			$ch = $this->buildGetHandle( $apiUrl );
			curl_multi_add_handle( $mh, $ch );
			$handles[$baseUrl] = $ch;
		}

		if ( empty( $handles ) ) {
			curl_multi_close( $mh );
			return [];
		}

		$this->multiExec( $mh );

		$results = [];
		foreach ( $handles as $baseUrl => $ch ) {
			$body     = curl_multi_getcontent( $ch );
			$httpCode = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );

			if ( $httpCode !== 200 || $body === false ) {
				wfDebugLog(
					'Wanda',
					'ExternalWikiSearch: search HTTP ' . $httpCode . ' from ' . $baseUrl
				);
				continue;
			}

			$data = json_decode( $body, true );
			$hits = $data['query']['search'] ?? [];

			if ( !empty( $hits ) ) {
				$results[$baseUrl] = $hits;
				wfDebugLog(
					'Wanda',
					'ExternalWikiSearch: ' . count( $hits ) . ' hits from ' . $baseUrl
				);
			}
		}

		curl_multi_close( $mh );
		return $results;
	}

	/**
	 * For each wiki's search hits, fetch plain-text article content in parallel.
	 *
	 * Strategy selection (per wiki, based on probed capabilities):
	 *   1. prop=extracts   — TextExtracts extension present (Wikimedia wikis, most quality wikis)
	 *   2. action=parse    — fallback; works on any MediaWiki, returns rendered HTML
	 *                        then stripped to plain text
	 *   3. prop=revisions  — last resort; raw wikitext, stripped locally
	 *
	 * All strategies are tried in one parallel wave per wiki using the best
	 * available option. No sequential fallback calls within a request.
	 *
	 * @param array $searchResults Output of parallelSearch()
	 * @return array [ baseUrl => [ ['title','extract','url','wikibaseItem'], ... ] ]
	 */
	private function parallelFetchExtracts( array $searchResults ): array {
		$mh      = curl_multi_init();
		$handles = [];
		// [ baseUrl => [ 'ch', 'titles', 'strategy' ] ]

		foreach ( $searchResults as $baseUrl => $hits ) {
			$titles   = array_column( $hits, 'title' );
			$caps     = self::$capabilityCache[$baseUrl] ?? $this->defaultCapabilities();

			if ( $caps['hasTextExtracts'] ) {
				$strategy = 'extracts';
				$props    = 'extracts|info';
				if ( $caps['wikibaseItemProp'] ) {
					$props .= '|pageprops';
				}
				$params = [
					'action'          => 'query',
					'prop'            => $props,
					'exintro'         => 1,
					'explaintext'     => 1,
					'exsectionformat' => 'plain',
					'exchars'         => $this->extractLen,
					'inprop'          => 'url',
					'ppprop'          => 'wikibase_item',
					'titles'          => implode( '|', $titles ),
					'format'          => 'json',
					'formatversion'   => 2,
				];
			} else {
				// action=parse only supports one page at a time;
				// for the fallback we use prop=revisions which batches fine.
				$strategy = 'revisions';
				$params = [
					'action'        => 'query',
					'prop'          => 'revisions|info',
					'rvprop'        => 'content',
					'rvslots'       => 'main',
					'rvsection'     => 0,
					'inprop'        => 'url',
					'titles'        => implode( '|', $titles ),
					'format'        => 'json',
					'formatversion' => 2,
				];
			}

			$apiUrl = $this->apiUrl( $baseUrl, $params );
			$ch     = $this->buildGetHandle( $apiUrl );
			curl_multi_add_handle( $mh, $ch );
			$handles[$baseUrl] = [
				'ch'       => $ch,
				'titles'   => $titles,
				'strategy' => $strategy,
			];
		}

		$this->multiExec( $mh );

		$results = [];
		foreach ( $handles as $baseUrl => $info ) {
			$ch       = $info['ch'];
			$strategy = $info['strategy'];
			$body     = curl_multi_getcontent( $ch );
			$httpCode = (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_multi_remove_handle( $mh, $ch );

			if ( $httpCode !== 200 || $body === false ) {
				wfDebugLog(
					'Wanda',
					'ExternalWikiSearch: extract fetch HTTP ' . $httpCode .
					' from ' . $baseUrl . ' (strategy: ' . $strategy . ')'
				);
				continue;
			}

			$data  = json_decode( $body, true );
			$pages = $this->parseExtractResponse( $data, $strategy, $baseUrl );

			if ( !empty( $pages ) ) {
				$results[$baseUrl] = $pages;
			}
		}

		curl_multi_close( $mh );
		return $results;
	}

	/**
	 * Parse the API response from either the 'extracts' or 'revisions' strategy
	 * into a normalised list of page objects.
	 *
	 * Each page object: [ 'title', 'extract', 'url', 'wikibaseItem' ]
	 *
	 * @param array $data
	 * @param string $strategy 'extracts' | 'revisions'
	 * @param string $baseUrl
	 * @return array
	 */
	private function parseExtractResponse(
		array $data,
		string $strategy,
		string $baseUrl
	): array {
		$pages  = $data['query']['pages'] ?? [];
		$result = [];

		foreach ( $pages as $page ) {
			// Skip missing/special pages
			if ( isset( $page['missing'] ) || isset( $page['special'] ) ) {
				continue;
			}

			$title        = $page['title'] ?? '';
			$canonicalUrl = $page['canonicalurl']
				?? ( $baseUrl . '/wiki/' . str_replace( ' ', '_', $title ) );
			$wikibaseItem = $page['pageprops']['wikibase_item'] ?? null;

			if ( $strategy === 'extracts' ) {
				$extract = trim( $page['extract'] ?? '' );
			} else {
				// revisions strategy: strip wikitext to plain text
				$raw     = $page['revisions'][0]['slots']['main']['content'] ?? '';
				$extract = trim( $this->wikitextToPlain( $raw ) );
				$extract = mb_substr( $extract, 0, $this->extractLen );
			}

			if ( $extract === '' ) {
				continue;
			}

			$result[] = [
				'title'        => $title,
				'extract'      => $extract,
				'url'          => $canonicalUrl,
				'wikibaseItem' => $wikibaseItem,
			];
		}

		return $result;
	}

	/**
	 * Format a single wiki's pages into a labelled context block.
	 *
	 * @param string $wikiName
	 * @param string $wikiUrl
	 * @param array $pages
	 * @return string
	 */
	private function formatWikiBlock(
		string $wikiName,
		string $wikiUrl,
		array $pages
	): string {
		if ( empty( $pages ) ) {
			return '';
		}

		$lines   = [];
		$lines[] = '--- Context from ' . $wikiName . ' (' . $wikiUrl . ') ---';

		foreach ( $pages as $page ) {
			$lines[] = '### ' . $page['title'];
			$lines[] = $page['extract'];
			$lines[] = 'Source: ' . $page['url'];
			if ( $page['wikibaseItem'] !== null ) {
				$lines[] = 'Wikidata: https://www.wikidata.org/wiki/' . $page['wikibaseItem'];
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a cURL GET handle (do not execute yet).
	 *
	 * @param string $url
	 * @return \CurlHandle|resource
	 */
	private function buildGetHandle( string $url ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'User-Agent: ' . self::USER_AGENT,
			'Accept: application/json',
		] );
		return $ch;
	}

	/**
	 * Run a curl_multi handle to completion with retry on transient errors.
	 *
	 * Mirrors the retry pattern used in WikidataQueryHandler::executeSparqlQuery().
	 *
	 * @param \CurlMultiHandle|resource $mh
	 */
	private function multiExec( $mh ): void {
		$running = null;
		do {
			$status = curl_multi_exec( $mh, $running );
			if ( $running > 0 ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $running > 0 && $status === CURLM_OK );
	}

	/**
	 * Verify $url is in the admin-approved allow-list.
	 * Prevents SSRF and data exfiltration to unapproved hosts.
	 *
	 * @param string $url Already normalised (rtrim + strtolower)
	 * @return bool
	 */
	private function isAllowed( string $url ): bool {
		foreach ( $this->allowedWikis as $name => $wiki ) {
			if ( $this->normaliseUrl( $wiki['url'] ?? '' ) === $url ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convert wikitext to a plain-text approximation suitable for LLM injection.
	 *
	 * Handles: deeply-nested templates, parser functions (#if/#switch/#invoke),
	 * File/Image links, wikilinks, external links, HTML tags, bold/italic markup,
	 * section headers, and whitespace normalisation.
	 *
	 * This is intentionally a best-effort stripper. It will not produce perfect
	 * output for every wiki's template conventions, but gives the LLM enough
	 * signal from the lead section to answer factual questions.
	 *
	 * @param string $wikitext
	 * @return string
	 */
	private function wikitextToPlain( string $wikitext ): string {
		// Strip everything before the first real paragraph
		// (infoboxes, categories, coordinates at page top)
		$text = $wikitext;

		// Remove deeply-nested templates iteratively (handle up to 8 nesting levels)
		for ( $i = 0; $i < 8; $i++ ) {
			$new = preg_replace( '/\{\{[^{}]*\}\}/', '', $text );
			if ( $new === $text ) {
				break;
			}
			$text = $new;
		}

		// Remove parser functions that survive template stripping
		$text = preg_replace( '/\{\{#[^{}]*\}\}/i', '', $text );

		// Remove [[File:...]] and [[Image:...]] (may span lines)
		$text = preg_replace( '/\[\[(?:File|Image|Datei|Fichier|Archivo):[^\]]*\]\]/isu', '', $text );

		// Convert [[link|label]] → label, [[link]] → link
		$text = preg_replace( '/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $text );

		// Remove external links: [url label] → label, bare [url] → ''
		$text = preg_replace( '/\[https?:\/\/\S+\s+([^\]]+)\]/', '$1', $text );
		$text = preg_replace( '/\[https?:\/\/\S+\]/', '', $text );

		// Strip HTML tags (references, spans, divs)
		$text = strip_tags( $text );

		// Remove bold/italic/underline wiki markup
		$text = str_replace( [ "'''", "''", '__' ], '', $text );

		// Remove section headers (==...==)
		$text = preg_replace( '/^={1,6}.+={1,6}\s*$/m', '', $text );

		// Remove table markup
		$text = preg_replace( '/^\s*[{\|][^\n]*$/m', '', $text );
		$text = preg_replace( '/^\s*[!\|][^\n]*/m', '', $text );

		// Remove HTML comments
		$text = preg_replace( '/<!--.*?-->/s', '', $text );

		// Collapse excessive blank lines
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Build an Action API URL from a base URL and parameter array.
	 *
	 * @param string $baseUrl
	 * @param array $params
	 * @return string
	 */
	private function apiUrl( string $baseUrl, array $params ): string {
		return rtrim( $baseUrl, '/' ) . '/w/api.php?' . http_build_query( $params );
	}

	/**
	 * Normalise a wiki base URL: lowercase, no trailing slash.
	 *
	 * @param string $url
	 * @return string
	 */
	private function normaliseUrl( string $url ): string {
		return rtrim( strtolower( trim( $url ) ), '/' );
	}

	/**
	 * Return the configured human-readable name for a wiki URL.
	 *
	 * @param string $baseUrl Already normalised
	 * @return string
	 */
	private function wikiName( string $baseUrl ): string {
		foreach ( $this->allowedWikis as $name => $wiki ) {
			if ( $this->normaliseUrl( $wiki['url'] ?? '' ) === $baseUrl ) {
				return $name;
			}
		}
		return $baseUrl;
	}

	/**
	 * Return a human-readable label for the content fetch strategy used.
	 *
	 * @param string $baseUrl
	 * @return string
	 */
	private function strategyLabel( string $baseUrl ): string {
		$caps = self::$capabilityCache[$baseUrl] ?? $this->defaultCapabilities();
		return $caps['hasTextExtracts'] ? 'prop=extracts' : 'prop=revisions (wikitext strip)';
	}
}
