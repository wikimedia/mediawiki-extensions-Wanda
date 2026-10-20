<?php

namespace MediaWiki\Extension\Wanda;

use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use RuntimeException;
use stdClass;

/**
 * Hybrid semantic and keyword retrieval service combining dense-vector search
 * and BM25 search over the Elasticsearch content index.
 */
class WandaSemanticDiscovery {

	public const UNAVAILABLE = 'wanda-discovery-semantic-unavailable';
	public const INDEX_PREFIX = 'mediawiki_content_';
	public const RRF_K = 60;
	public const POOL_MULTIPLIER = 3;
	public const MAX_POOL = 100;
	public const KEYWORD_SCORE_FRACTION = 0.3;
	public const VECTOR_SCORE_FRACTION = 0.91;
	public const MIN_VECTOR_RELEVANCE_SCORE = 1.55;

	/**
	 * Conversational and boilerplate words ignored during BM25 keyword matching.
	 */
	private const QUERY_STOPWORDS = [
		'who', 'what', 'where', 'when', 'why', 'how', 'which', 'whom', 'whose',
		'is', 'are', 'was', 'were', 'be', 'been', 'being',
		'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'shall',
		'tell', 'me', 'about', 'explain', 'describe', 'compare', 'find', 'search',
		'show', 'list', 'give', 'get', 'all', 'every', 'each', 'any', 'some',
		'page', 'pages', 'article', 'articles', 'wiki', 'site',
		'in', 'on', 'at', 'to', 'from', 'for', 'with', 'by', 'of', 'into',
		'the', 'a', 'an', 'this', 'that', 'these', 'those', 'and', 'or', 'please',
	];

	/** @var IContextSource */
	private $context;

	public function __construct( ?IContextSource $context = null ) {
		$this->context = $context ?? RequestContext::getMain();
	}

	/**
	 * Search for candidate pages using hybrid vector and keyword retrieval.
	 *
	 * @param string $query Query string
	 * @param int $window Maximum candidate titles to return
	 * @return array{titles:string[],truncated:bool}
	 * @throws RuntimeException
	 */
	public function search( string $query, int $window ): array {
		$searchHost = $this->resolveSearchHost();
		if ( $searchHost === '' ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}

		$index = $this->detectIndex( $searchHost );
		$embedding = $this->embed( $query );
		if ( !$embedding ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}

		$config = $this->context->getConfig();
		$minScore = (float)( $config->get( 'WandaVectorSearchMinScore' ) ?? 1.7 );
		$timeout = (int)( $config->get( 'WandaLLMTimeout' ) ?? 30 );
		$pool = max( $window, min( $window * self::POOL_MULTIPLIER, self::MAX_POOL ) );

		$vectorHits = $this->vectorSearch( $searchHost, $index, $embedding, $pool, $minScore, $timeout );
		$filteredVectorHits = self::filterVectorHits( $vectorHits );
		$vectorTitles = array_values( array_unique(
			array_column( array_column( $filteredVectorHits, '_source' ), 'title' )
		) );

		$keywordHits = $this->keywordSearch( $searchHost, $index, $query, $pool, $timeout );
		$filteredKeywordHits = self::filterKeywordHits( $keywordHits );
		$keywordTitles = array_values( array_unique(
			array_column( array_column( $filteredKeywordHits, '_source' ), 'title' )
		) );

		$titles = self::fuseRankings( [ $vectorTitles, $keywordTitles ] );

		return [
			'titles' => array_slice( $titles, 0, $window ),
			'truncated' => count( $titles ) > $window,
		];
	}

	/**
	 * Execute hybrid search for chat context.
	 *
	 * @param string $query User query text
	 * @param int $limit Maximum number of pages to combine in the context
	 * @param array $allowedNamespaces Optional namespace filter
	 * @param callable|null $canRead Callback checking if user can read the title
	 * @return array{content:string,source:string,num_results:int}|null
	 */
	public function queryChat(
		string $query,
		int $limit = 3,
		array $allowedNamespaces = [],
		?callable $canRead = null
	): ?array {
		$searchHost = $this->resolveSearchHost();
		if ( $searchHost === '' ) {
			wfDebugLog( 'Wanda', 'WandaSemanticDiscovery: No search engine host configured' );
			return null;
		}

		try {
			$index = $this->detectIndex( $searchHost );
		} catch ( RuntimeException $e ) {
			wfDebugLog( 'Wanda', 'WandaSemanticDiscovery: Failed to detect index: ' . $e->getMessage() );
			return null;
		}

		$config = $this->context->getConfig();
		$minScore = (float)( $config->get( 'WandaVectorSearchMinScore' ) ?? 1.7 );
		$timeout = (int)( $config->get( 'WandaLLMTimeout' ) ?? 30 );
		$pool = max( $limit * self::POOL_MULTIPLIER, 10 );

		$embedding = $this->embed( $query );
		$vectorHits = [];
		if ( $embedding !== null ) {
			try {
				$vectorHits = $this->vectorSearch( $searchHost, $index, $embedding, $pool, $minScore, $timeout );
			} catch ( RuntimeException $e ) {
				wfDebugLog( 'Wanda', 'WandaSemanticDiscovery: Vector search failed: ' . $e->getMessage() );
			}
		}

		$keywordHits = [];
		try {
			$keywordHits = $this->keywordSearch( $searchHost, $index, $query, $pool, $timeout );
		} catch ( RuntimeException $e ) {
			wfDebugLog( 'Wanda', 'WandaSemanticDiscovery: Keyword search failed: ' . $e->getMessage() );
		}

		if ( empty( $vectorHits ) && empty( $keywordHits ) ) {
			return null;
		}

		$filterHit = static function ( array $hit ) use ( $canRead, $allowedNamespaces ): bool {
			$titleText = $hit['_source']['title'] ?? '';
			if ( $titleText === '' ) {
				return false;
			}
			if ( !empty( $allowedNamespaces ) ) {
				$title = Title::newFromText( $titleText );
				if ( !$title || !in_array( $title->getNamespace(), $allowedNamespaces, true ) ) {
					return false;
				}
			}
			return $canRead ? $canRead( $titleText ) : true;
		};

		$readableVectorHits = array_filter( $vectorHits, $filterHit );
		$readableKeywordHits = array_filter( $keywordHits, $filterHit );

		$filteredVectorHits = self::filterVectorHits( $readableVectorHits );
		$vectorTitles = array_values( array_unique(
			array_column( array_column( $filteredVectorHits, '_source' ), 'title' )
		) );

		$filteredKeywordHits = self::filterKeywordHits( $readableKeywordHits );
		$keywordTitles = array_values( array_unique(
			array_column( array_column( $filteredKeywordHits, '_source' ), 'title' )
		) );

		if ( empty( $vectorTitles ) && empty( $keywordTitles ) ) {
			return null;
		}

		$contentMap = [];
		foreach ( array_merge( $readableVectorHits, $readableKeywordHits ) as $hit ) {
			$title = $hit['_source']['title'] ?? '';
			$content = $hit['_source']['content'] ?? $hit['_source']['text'] ?? '';
			if ( $title !== '' && $content !== '' && !isset( $contentMap[$title] ) ) {
				$contentMap[$title] = trim( $content );
			}
		}

		$fusedScores = self::fuseScores( [ $vectorTitles, $keywordTitles ] );
		$topScore = reset( $fusedScores ) ?: 0.0;
		$topTitles = [];
		foreach ( $fusedScores as $title => $score ) {
			// Drop low-scoring single-retriever hits if significantly below the top score
			if ( $topScore > 0 && $score < $topScore * 0.55 ) {
				continue;
			}
			$topTitles[] = $title;
			if ( count( $topTitles ) >= $limit ) {
				break;
			}
		}

		$combinedContent = [];
		$sources = [];
		foreach ( $topTitles as $title ) {
			if ( !isset( $contentMap[$title] ) || $contentMap[$title] === '' ) {
				continue;
			}
			$combinedContent[] = "--- From page: " . $title . " ---\n" . $contentMap[$title];
			$sources[] = $title;
		}

		if ( empty( $combinedContent ) ) {
			return null;
		}

		wfDebugLog(
			'Wanda',
			"WandaSemanticDiscovery: Hybrid search found " . count( $sources ) . " pages: " .
			implode( ', ', $sources )
		);

		return [
			'content' => implode( "\n\n", $combinedContent ),
			'source' => implode( ' | ', $sources ),
			'num_results' => count( $sources ),
		];
	}

	/**
	 * Merge multiple ranked lists of titles using Reciprocal Rank Fusion (RRF).
	 *
	 * @param string[][] $rankings Ordered title lists
	 * @param int $k RRF smoothing constant
	 * @return string[] Fused ordering, most relevant first
	 */
	public static function fuseRankings( array $rankings, int $k = self::RRF_K ): array {
		return array_keys( self::fuseScores( $rankings, $k ) );
	}

	/**
	 * Calculate Reciprocal Rank Fusion scores across several ranked title lists.
	 *
	 * @param string[][] $rankings Ordered title lists
	 * @param int $k RRF smoothing constant
	 * @return array<string,float> Map of title => score
	 */
	public static function fuseScores( array $rankings, int $k = self::RRF_K ): array {
		$scores = [];
		foreach ( $rankings as $titles ) {
			$rank = 0;
			$seenInList = [];
			foreach ( $titles as $title ) {
				if ( !is_string( $title ) || $title === '' || isset( $seenInList[$title] ) ) {
					continue;
				}
				$seenInList[$title] = true;
				$rank++;
				$scores[$title] = ( $scores[$title] ?? 0.0 ) + 1.0 / ( $k + $rank );
			}
		}
		arsort( $scores );
		return $scores;
	}

	/**
	 * Filter keyword hits relative to the top hit's score.
	 *
	 * @param array $hits Raw search engine hits
	 * @param float $fraction Relevance floor fraction of top score
	 * @return array Filtered hits above the floor
	 */
	public static function filterKeywordHits(
		array $hits,
		float $fraction = self::KEYWORD_SCORE_FRACTION
	): array {
		$floor = null;
		$filtered = [];
		foreach ( $hits as $hit ) {
			$title = $hit['_source']['title'] ?? null;
			if ( !is_string( $title ) || $title === '' ) {
				continue;
			}
			$score = (float)( $hit['_score'] ?? 0.0 );
			if ( $floor === null ) {
				$floor = $score * $fraction;
			}
			if ( $floor > 0.0 && $score < $floor ) {
				break;
			}
			$filtered[] = $hit;
		}
		return $filtered;
	}

	/**
	 * Filter vector hits relative to the top hit's cosine similarity.
	 *
	 * @param array $hits Raw search engine vector hits
	 * @param float $fraction Relevance floor fraction of top cosine similarity
	 * @return array Filtered hits above the floor
	 */
	public static function filterVectorHits(
		array $hits,
		float $fraction = self::VECTOR_SCORE_FRACTION
	): array {
		$floor = null;
		$filtered = [];
		foreach ( $hits as $hit ) {
			$title = $hit['_source']['title'] ?? null;
			if ( !is_string( $title ) || $title === '' ) {
				continue;
			}
			$score = (float)( $hit['_score'] ?? 0.0 );
			if ( $score < self::MIN_VECTOR_RELEVANCE_SCORE ) {
				break;
			}
			if ( $floor === null ) {
				$topCosine = max( 0.0, $score - 1.0 );
				$floor = max( self::MIN_VECTOR_RELEVANCE_SCORE, 1.0 + $topCosine * $fraction );
			}
			if ( $floor > 1.0 && $score < $floor ) {
				break;
			}
			$filtered[] = $hit;
		}
		return $filtered;
	}

	/**
	 * Strip conversational and boilerplate tokens from query before keyword matching.
	 *
	 * @param string $query
	 * @return string
	 */
	public static function stripQueryStopwords( string $query ): string {
		$tokens = preg_split( '/[^\p{L}\p{N}_-]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
		if ( !$tokens ) {
			return '';
		}
		$stopSet = array_fill_keys( self::QUERY_STOPWORDS, true );
		$kept = [];
		foreach ( $tokens as $token ) {
			if ( isset( $stopSet[strtolower( $token )] ) ) {
				continue;
			}
			$kept[] = $token;
			if ( preg_match( '/^[a-z]*[^aeiou]ies$/i', $token ) && strlen( $token ) >= 5 ) {
				$kept[] = substr( $token, 0, -3 ) . 'y';
			} elseif ( preg_match( '/^[a-z]+(?:ss|x|z|ch|sh)es$/i', $token ) ) {
				$kept[] = substr( $token, 0, -2 );
			} elseif ( preg_match( '/^[a-z]{3,}[^ssui]s$/i', $token ) ) {
				$kept[] = substr( $token, 0, -1 );
			}
		}
		return implode( ' ', array_unique( $kept ) );
	}

	/**
	 * Vector search over nested chunk vectors.
	 *
	 * @param string $searchHost
	 * @param string $index
	 * @param array $embedding
	 * @param int $size
	 * @param float $minScore
	 * @param int $timeout
	 * @return array
	 * @throws RuntimeException
	 */
	private function vectorSearch(
		string $searchHost, string $index, array $embedding, int $size, float $minScore, int $timeout
	): array {
		$body = [
			'size' => max( 1, $size ),
			'query' => [
				'nested' => [
					'path' => 'content_vectors',
					'score_mode' => 'max',
					'query' => [
						'script_score' => [
							'query' => [ 'match_all' => new stdClass() ],
							'script' => [
								'source' =>
									"cosineSimilarity(params.query_vector, 'content_vectors.vector') + 1.0",
								'params' => [ 'query_vector' => $embedding ],
							],
						],
					],
				],
			],
			'_source' => [ 'title', 'content', 'text' ],
			'min_score' => $minScore,
		];
		$response = $this->post( $searchHost . '/' . rawurlencode( $index ) . '/_search', $body, $timeout );
		$hits = $response['hits']['hits'] ?? null;
		if ( !is_array( $hits ) ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}
		return $hits;
	}

	/**
	 * Keyword search over title and content.
	 *
	 * @param string $searchHost
	 * @param string $index
	 * @param string $query
	 * @param int $size
	 * @param int $timeout
	 * @return array
	 */
	private function keywordSearch(
		string $searchHost, string $index, string $query, int $size, int $timeout
	): array {
		$cleanedQuery = self::stripQueryStopwords( $query );
		if ( $cleanedQuery === '' ) {
			return [];
		}
		$body = [
			'size' => max( 1, $size ),
			'query' => [
				'multi_match' => [
					'query' => $cleanedQuery,
					'fields' => [ 'title^2', 'content', 'text' ],
					'analyzer' => 'stop',
				],
			],
			'_source' => [ 'title', 'content', 'text' ],
		];
		try {
			$response = $this->post( $searchHost . '/' . rawurlencode( $index ) . '/_search', $body, $timeout );
			$hits = $response['hits']['hits'] ?? null;
			return is_array( $hits ) ? $hits : [];
		} catch ( RuntimeException $e ) {
			if ( $e->getMessage() === self::UNAVAILABLE ) {
				return [];
			}
			throw $e;
		}
	}

	/**
	 * Generate and cache embedding for the query.
	 *
	 * @param string $query
	 * @return array|null
	 */
	private function embed( string $query ): ?array {
		$config = $this->context->getConfig();
		$provider = strtolower( (string)( $config->get( 'WandaLLMProvider' ) ?? 'ollama' ) );
		$model = (string)( $config->get( 'WandaLLMEmbeddingModel' ) ?? $config->get( 'WandaLLMModel' ) ?? '' );
		$endpoint = (string)( $config->get( 'WandaLLMApiEndpoint' ) ?? '' );
		$apiKey = (string)( $config->get( 'WandaLLMApiKey' ) ?? '' );
		$timeout = (int)( $config->get( 'WandaLLMTimeout' ) ?? 30 );
		$proxy = (string)( MediaWikiServices::getInstance()->getMainConfig()->get( 'HTTPProxy' ) ?? '' );

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$key = $cache->makeKey( 'wanda-embedding', $provider, $model, sha1( $query ) );
		$embedding = $cache->getWithSetCallback(
			$key,
			$cache::TTL_DAY,
			static function () use ( $query, $provider, $apiKey, $endpoint, $model, $timeout, $proxy ) {
				$vector = EmbeddingGenerator::generate(
					$query, $provider, $apiKey, $endpoint, $model, $timeout, $proxy
				);
				return ( is_array( $vector ) && $vector !== [] ) ? $vector : false;
			}
		);
		return ( is_array( $embedding ) && $embedding !== [] ) ? $embedding : null;
	}

	/**
	 * @return string
	 */
	private function resolveSearchHost(): string {
		$config = $this->context->getConfig();
		$host = $config->has( 'WandaSearchEngineUrl' ) ? $config->get( 'WandaSearchEngineUrl' ) : '';
		return rtrim( (string)$host, '/' );
	}

	/**
	 * Resolve the latest content index.
	 *
	 * @param string|null $searchHost
	 * @return string
	 * @throws RuntimeException
	 */
	public function detectIndex( ?string $searchHost = null ): string {
		if ( $searchHost === null || $searchHost === '' ) {
			$searchHost = $this->resolveSearchHost();
		}
		if ( $searchHost === '' ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}

		$rows = $this->get( $searchHost . '/_cat/indices?format=json', 5 );
		$names = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$name = $row['index'] ?? '';
				if ( is_string( $name ) && strpos( $name, self::INDEX_PREFIX ) === 0 ) {
					$names[] = $name;
				}
			}
		}
		if ( !$names ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}
		rsort( $names );
		return $names[0];
	}

	/**
	 * @param string $url
	 * @param int $timeout
	 * @return array|null
	 */
	private function get( string $url, int $timeout ): ?array {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		$raw = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$failed = curl_errno( $ch );
		if ( $failed || $code !== 200 || !is_string( $raw ) ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $url
	 * @param array $body
	 * @param int $timeout
	 * @return array
	 * @throws RuntimeException
	 */
	private function post( string $url, array $body, int $timeout ): array {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		$raw = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$failed = curl_errno( $ch );
		if ( $failed || $code !== 200 || !is_string( $raw ) ) {
			throw new RuntimeException( self::UNAVAILABLE );
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}
}
