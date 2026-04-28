<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

class APIChat extends ApiBase {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;
	/** @var string */
	private static $llmProvider;
	/** @var string */
	private static $llmModel;
	/** @var string */
	private static $llmEmbeddingModel;
	/** @var string */
	private static $llmApiKey;
	/** @var string */
	private static $llmApiEndpoint;
	/** @var int */
	private static $maxTokens;
	/** @var float */
	private static $temperature;
	/** @var int */
	private static $timeout;
	/** @var string */
	private static $customPromptTitle;
	/** @var string */
	private static $customPrompt;
	/** @var bool */
	private static $usePublicKnowledge = false;
	/** @var bool */
	private static $skipESQuery = false;
	/** @var bool */
	private static $useContentLang = false;
	/** @var float */
	private static $vectorSearchMinScore;
	/** @var bool */
	private static $enableConversationMemory = true;
	/** @var int */
	private static $conversationMaxChars = 6000;
	/** @var bool */
	private static $enableCargoQueries = false;
	/** @var array */
	private static $cargoExcludedTables = [];
	/** @var int */
	private static $cargoMaxQuerySteps = 3;
	/** @var bool */
	private static $enableWikidataQueries = false;
	/** @var string */
	private static $sparqlEndpoint = 'https://query.wikidata.org/sparql';
	/** @var string */
	private static $wikidataApiEndpoint = 'https://www.wikidata.org/w/api.php';
	/** @var string */
	private static $wikidataLang = 'en';
	/** @var int */
	private static $wikidataMaxQuerySteps = 3;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'WandaLLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->detectElasticsearchIndex();
		self::$llmProvider = strtolower( $this->getConfig()->get( 'WandaLLMProvider' ) ?? "ollama" );
		self::$llmModel = $this->getConfig()->get( 'WandaLLMModel' ) ?? "gemma:2b";
		self::$llmEmbeddingModel = $this->getConfig()->get( 'WandaLLMEmbeddingModel' ) ?? self::$llmModel;
		self::$llmApiKey = $this->getConfig()->get( 'WandaLLMApiKey' ) ?? "";

		// Set default endpoint based on provider
		$defaultEndpoint = "http://ollama:11434/api/";
		if ( self::$llmProvider === 'gemini' ) {
			$defaultEndpoint = "https://generativelanguage.googleapis.com/v1";
		}
		self::$llmApiEndpoint = $this->getConfig()->get( 'WandaLLMApiEndpoint' ) ?? $defaultEndpoint;

		self::$maxTokens = $this->getConfig()->get( 'WandaLLMMaxTokens' ) ?? 2048;
		self::$temperature = $this->getConfig()->get( 'WandaLLMTemperature' ) ?? 0.7;
		self::$timeout = $this->getConfig()->get( 'WandaLLMTimeout' ) ?? 30;
		self::$customPromptTitle = $this->getConfig()->get( 'WandaCustomPromptTitle' ) ?? "";
		self::$customPrompt = $this->getConfig()->get( 'WandaCustomPrompt' ) ?? "";
		self::$skipESQuery = $this->getConfig()->get( 'WandaSkipESQuery' ) ?? false;
		self::$useContentLang = $this->getConfig()->get( 'WandaUseContentLang' ) ?? false;
		self::$vectorSearchMinScore = $this->getConfig()->get( 'WandaVectorSearchMinScore' ) ?? 1.7;
		self::$enableConversationMemory = $this->getConfig()->get( 'WandaEnableConversationMemory' ) ?? true;
		self::$conversationMaxChars = $this->getConfig()->get( 'WandaConversationMaxChars' ) ?? 6000;
		self::$enableCargoQueries = $this->getConfig()->get( 'WandaEnableCargoQueries' ) ?? false;
		self::$cargoExcludedTables = $this->getConfig()->get( 'WandaCargoExcludedTables' ) ?? [];
		self::$cargoMaxQuerySteps = $this->getConfig()->get( 'WandaCargoMaxQuerySteps' ) ?? 3;
		self::$sparqlEndpoint = $this->getConfig()->get( 'WandaSparqlEndpoint' ) ??
			'https://query.wikidata.org/sparql';
		self::$wikidataApiEndpoint = $this->getConfig()->get( 'WandaWikidataApiEndpoint' ) ??
			'https://www.wikidata.org/w/api.php';
		self::$wikidataLang = $this->getConfig()->get( 'WandaWikidataLang' ) ?? 'en';
		self::$wikidataMaxQuerySteps = $this->getConfig()->get( 'WandaWikidataMaxQuerySteps' ) ?? 3;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = trim( $params['message'] );
		$allowPublicKnowledge = !empty( $params['usepublicknowledge'] );
		$imagesList = !empty( $params['images'] ) ? $params['images'] : '';
		$wikidataOnly = !empty( $params['wikidataonly'] );
		if ( !empty( $params['wikidatalang'] ) ) {
			self::$wikidataLang = trim( $params['wikidatalang'] );
		}
		$this->overrideLlmParameters( $params );

		// Parse per-request source selection
		$requestedSources = !empty( $params['sources'] )
			? array_filter( array_map( 'trim', explode( '|', $params['sources'] ) ) )
			: [ 'wiki' ];
		if ( in_array( 'publicknowledge', $requestedSources ) ) {
			$allowPublicKnowledge = true;
		}
		if ( !in_array( 'wiki', $requestedSources ) ) {
			self::$skipESQuery = true;
		}
		self::$enableWikidataQueries = in_array( 'wikidata', $requestedSources );
		if ( in_array( 'cargo', $requestedSources ) ) {
			self::$enableCargoQueries = true;
		} elseif ( !empty( $params['sources'] ) ) {
			self::$enableCargoQueries = false;
		}

		// Parse conversation history if provided and enabled
		$conversationHistory = [];
		if ( self::$enableConversationMemory && !empty( $params['conversationhistory'] ) ) {
			$decoded = json_decode( $params['conversationhistory'], true );
			if ( is_array( $decoded ) ) {
				$conversationHistory = $this->truncateConversationHistory( $decoded );
			}
		}
		$userLang = $this->getContext()->getLanguage()->getCode();
		$contentLang = MediaWikiServices::getInstance()->getContentLanguage()->getCode();

		// Validate input parameters
		if ( empty( $userQuery ) ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-empty-question' )->text() );
			return;
		}

		// Process and validate attached images
		$imageContext = '';
		$imageData = [];
		if ( !empty( $imagesList ) ) {
			$processedImages = $this->processAttachedImages( $imagesList );
			$imageContext = $processedImages['context'];
			$imageData = $processedImages['images'];
		}

		// Validate provider configuration
		if ( !$this->validateProviderConfig() ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-config-invalid' )->text() );
			return;
		}

		// If skipESQuery or wikidataonly is enabled, bypass Elasticsearch entirely
		if ( self::$skipESQuery || $wikidataOnly ) {
			$contextStr = '';
			$searchResults = null;
		} else {
			// Check for Elasticsearch index
			$index = $this->detectElasticsearchIndex();
			wfDebugLog( 'Wanda', "Detected index: " . ( $index ?: 'none' ) );

			// Search for context when possible
			$searchResults = $index ? $this->queryElasticsearch( $userQuery ) : null;
			wfDebugLog( 'Wanda', "Search results: " .
				( $searchResults ? json_encode( array_keys( $searchResults ) ) : 'null' ) );

			// Build context string from results
			$contextStr = '';
			if ( $searchResults && is_array( $searchResults ) ) {
				if ( isset( $searchResults['content'] ) ) {
					$contextStr = $searchResults['content'];
					wfDebugLog( 'Wanda', "Context length: " . strlen( $contextStr ) . " characters" );
				} else {
					wfDebugLog( 'Wanda', "Warning: Search results missing 'content' field" );
				}
			} else {
				wfDebugLog( 'Wanda', "No search results or invalid format" );
			}
		}

		if ( !empty( $imageContext ) && empty( $imageData ) ) {
			$contextStr = ( $contextStr ? $contextStr . "\n\n" : "" ) . $imageContext;
		}

		$memoryDisabled = !empty( $params['memorydisabled'] );

		// Cargo structured data retrieval
		$cargoSources = [];
		$cargoSteps = [];
		$cargoContext = '';
		if ( self::$enableCargoQueries ) {
			$cargoHandler = new CargoQueryHandler(
				self::$llmProvider,
				self::$llmModel,
				self::$llmApiKey,
				self::$llmApiEndpoint,
				self::$timeout,
				self::$cargoExcludedTables,
				self::$cargoMaxQuerySteps
			);
			$cargoResult = $cargoHandler->query( $userQuery );
			$cargoSteps = $cargoResult['steps'] ?? [];
			if ( !empty( $cargoResult['content'] ) ) {
				$cargoContext = $cargoResult['content'];
				$cargoSources = $cargoResult['sources'] ?? [];
				wfDebugLog( 'Wanda', "Cargo query returned " .
					$cargoResult['num_results'] . " results" );
			}
		}

		// Wikidata knowledge graph retrieval
		$wikidataSources = [];
		$wikidataSteps = [];
		$wikidataEntities = [];
		$wikidataContext = '';
		if ( self::$enableWikidataQueries ) {
			$wikidataHandler = new WikidataQueryHandler(
				self::$llmProvider,
				self::$llmModel,
				self::$llmApiKey,
				self::$llmApiEndpoint,
				self::$timeout,
				self::$wikidataLang,
				self::$wikidataMaxQuerySteps,
				self::$sparqlEndpoint,
				self::$wikidataApiEndpoint
			);
			$wikidataResult = $wikidataHandler->query( $userQuery );
			$wikidataSteps = $wikidataResult['steps'] ?? [];
			$wikidataEntities = $wikidataResult['entities'] ?? [];
			if ( !empty( $wikidataResult['content'] ) ) {
				$wikidataContext = $wikidataResult['content'];
				$wikidataSources = $wikidataResult['sources'] ?? [];
				wfDebugLog( 'Wanda', "Wikidata query returned " .
					$wikidataResult['num_results'] . " results" );
			}
		}

		$response = $this->generateLLMResponse(
			$userQuery,
			$contextStr,
			$allowPublicKnowledge,
			$imageData,
			$userLang,
			$contentLang,
			$conversationHistory,
			$memoryDisabled,
			$cargoContext,
			$wikidataContext
		);
		if ( !$response ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-generation-failed' )->text() );
			return;
		}

		// Prepare source data only when we used wiki context
		$sourceData = [];
		if ( $searchResults && isset( $searchResults['source'] ) ) {
			$sourceData[] = $searchResults['source'];
		}

		// Return response along with source attribution
		$this->getResult()->addValue( null, "response", $response );
		// Backwards-compatible single string of wiki sources
		if ( $searchResults && isset( $searchResults['source'] ) && $searchResults['source'] !== '' ) {
			$this->getResult()->addValue( null, "source", $searchResults['source'] );
		}

		$allSources = [];
		if ( $searchResults && isset( $searchResults['source'] ) && $searchResults['source'] !== '' ) {
			$wikiTitles = array_map( 'trim', explode( ',', $searchResults['source'] ) );
			$wikiTitles = array_values( array_filter( $wikiTitles ) );
			$allSources = $wikiTitles;
		}
		if ( !empty( $cargoSources ) ) {
			$allSources = array_merge( $allSources, $cargoSources );
		}
		if ( !empty( $wikidataSources ) ) {
			$allSources = array_merge( $allSources, $wikidataSources );
		}
		if ( !empty( $allSources ) ) {
			$this->getResult()->addValue( null, "sources", $allSources );
		}
		if ( !empty( $cargoSteps ) ) {
			$this->getResult()->addValue( null, "cargoSteps", $cargoSteps );
		}
		if ( !empty( $wikidataSteps ) ) {
			$this->getResult()->addValue( null, "wikidataSteps", $wikidataSteps );
		}
		$this->getResult()->addValue( null, "requestedSources", array_values( $requestedSources ) );
		if ( !empty( $wikidataEntities ) ) {
			$this->getResult()->addValue( null, "wikidataEntities", $wikidataEntities );
		}
	}

	/**
	 * Override default LLM parameters with values from request.
	 *
	 * @param array $params Parameter array to update LLM settings.
	 * @return void
	 */
	private function overrideLlmParameters( array $params ) {
		if ( isset( $params['provider'] ) && !empty( $params['provider'] ) ) {
			self::$llmProvider = strtolower( trim( $params['provider'] ) );
		}
		if ( isset( $params['model'] ) && !empty( $params['model'] ) ) {
			self::$llmModel = trim( $params['model'] );
		}
		if ( isset( $params['apikey'] ) && !empty( $params['apikey'] ) ) {
			self::$llmApiKey = trim( $params['apikey'] );
		}
		if ( isset( $params['apiendpoint'] ) && !empty( $params['apiendpoint'] ) ) {
			self::$llmApiEndpoint = trim( $params['apiendpoint'] );
		}
		if ( isset( $params['maxtokens'] ) && is_numeric( $params['maxtokens'] ) ) {
			self::$maxTokens = $params['maxtokens'];
		}
		if ( isset( $params['temperature'] ) ) {
			self::$temperature = $this->parseTemperature( $params['temperature'] );
		} else {
			self::$temperature = $this->parseTemperature( self::$temperature );
		}
		if ( isset( $params['timeout'] ) && is_numeric( $params['timeout'] ) ) {
			self::$timeout = $params['timeout'];
		}
		if ( isset( $params['usepublicknowledge'] ) ) {
			self::$usePublicKnowledge = $params['usepublicknowledge'];
		}
		if ( isset( $params['customprompt'] ) ) {
			self::$customPrompt = trim( $params['customprompt'] );
		}
		if ( isset( $params['customprompttitle'] ) ) {
			self::$customPromptTitle = trim( $params['customprompttitle'] );
		}
		if ( isset( $params['skipesquery'] ) ) {
			self::$skipESQuery = $params['skipesquery'];
		}
	}

	/**
	 * Validate provider configuration
	 */
	private function validateProviderConfig() {
		switch ( self::$llmProvider ) {
			case 'openai':
			case 'anthropic':
			case 'azure':
			case 'gemini':
				if ( empty( self::$llmApiKey ) ) {
					return false;
				}
				break;
			case 'ollama':
				if ( empty( self::$llmApiEndpoint ) ) {
					return false;
				}
				break;
			default:
				return false;
		}
		return true;
	}

	/**
	 * Parse temperature value and validate it's between 0.0 and 1.0.
	 * Throws a MediaWiki exception if value is invalid or out of range.
	 *
	 * @param mixed $temp
	 * @return float
	 * @throws \MediaWiki\Rest\HttpException
	 */
	private function parseTemperature( $temp ): float {
		// Use configured default if null or empty
		if ( $temp === null || $temp === '' ) {
			return floatval( self::$temperature );
		}

		// Must be numeric
		if ( !is_numeric( $temp ) ) {
			throw new \MediaWiki\Rest\HttpException(
				$this->msg( 'wanda-api-error-temperature-invalid' )->text(),
				400
			);
		}

		$t = floatval( $temp );
		// Must be within range 0.0 - 1.0
		if ( $t < 0.0 || $t > 1.0 ) {
			throw new \MediaWiki\Rest\HttpException(
				$this->msg( 'wanda-api-error-temperature-outofrange' )->text(),
				400
			);
		}

		return $t;
	}

	/**
	 * Process attached images and return both context and image data.
	 * Validates file count and size limits.
	 *
	 * @param string $imagesList Pipe-separated list of image titles
	 * @return array Array with 'context' (string) and 'images' (array of image data)
	 */
	private function processAttachedImages( $imagesList ) {
		$maxImageSize = $this->getConfig()->get( 'WandaMaxImageSize' ) ?? 5242880;

		$imageTitles = explode( '|', $imagesList );

		if ( empty( $imageTitles ) ) {
			return [ 'context' => '', 'images' => [] ];
		}

		$imageContextParts = [];
		$imageData = [];

		foreach ( $imageTitles as $titleStr ) {
			$titleStr = trim( $titleStr );
			if ( empty( $titleStr ) ) {
				continue;
			}

			$title = Title::newFromText( $titleStr );
			if ( !$title || !$title->exists() || $title->getNamespace() !== NS_FILE ) {
				continue;
			}

			$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile( $title );
			if ( !$file || !$file->exists() ) {
				continue;
			}

			if ( $file->getSize() > $maxImageSize ) {
				continue;
			}

			$imageUrl = $file->getFullUrl();
			$localPath = $file->getLocalRefPath();
			$description = '';
			$wikiPage = new WikiPage( $title );
			if ( $wikiPage && $wikiPage->exists() ) {
				$content = $wikiPage->getContent();
				if ( $content ) {
					$text = $content->getText();
					$lines = explode( "\n", $text );
					foreach ( $lines as $line ) {
						$line = trim( $line );
						if ( !empty( $line ) && !preg_match( '/^[\[\{]/', $line ) ) {
							$description = substr( $line, 0, 500 );
							break;
						}
					}
				}
			}

			$imageContextParts[] = sprintf(
				"Image: %s\nURL: %s\nDescription: %s\nSize: %s x %s pixels\nFile size: %s bytes",
				$title->getText(),
				$imageUrl,
				$description ? $description : 'No description available',
				$file->getWidth(),
				$file->getHeight(),
				$file->getSize()
			);

			$imageData[] = [
				'url' => $imageUrl,
				'localPath' => $localPath,
				'title' => $title->getText(),
				'description' => $description,
				'width' => $file->getWidth(),
				'height' => $file->getHeight(),
				'mime' => $file->getMimeType()
			];
		}

		if ( empty( $imageContextParts ) ) {
			return [ 'context' => '', 'images' => [] ];
		}

		return [
			'context' => "Attached Images:\n" . implode( "\n\n", $imageContextParts ),
			'images' => $imageData
		];
	}

	/**
	 * Detects the most recent Elasticsearch index dynamically.
	 */
	private function detectElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Failed to get Elasticsearch indices. HTTP code: " . $httpCode );
			return null;
		}

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Wanda', "Invalid response from Elasticsearch indices endpoint" );
			return null;
		}

		// Filter indices related to Wanda content
		$validIndices = array_filter( $indices, static function ( $index ) {
			return strpos( $index['index'], 'mediawiki_content_' ) === 0;
		} );

		if ( empty( $validIndices ) ) {
			wfDebugLog(
				'Wanda',
				"No mediawiki_content_* indices found. Available indices: " .
				implode( ', ', array_column( $indices, 'index' ) )
			);
			return null;
		}

		// Sort by index creation order and return the most recent one
		usort( $validIndices, static function ( $a, $b ) {
			return strcmp( $b['index'], $a['index'] );
		} );

		$selectedIndex = $validIndices[0]['index'] ?? null;
		wfDebugLog( 'Wanda', "Selected Elasticsearch index: " . $selectedIndex );

		return $selectedIndex;
	}

	private function queryElasticsearch( $queryText ) {
		$vectorResult = $this->vectorSearch( $queryText );
		if ( $vectorResult !== null ) {
			wfDebugLog( 'Wanda', "Using vector search results" );
			return $vectorResult;
		}

		// Fallback to text search
		wfDebugLog( 'Wanda', "Falling back to text search" );
		return $this->textSearch( $queryText );
	}

	/**
	 * Vector-based semantic search using embeddings
	 */
	private function vectorSearch( $queryText ) {
		if ( empty( self::$indexName ) ) {
			return null;
		}

		$embedding = $this->generateEmbedding( $queryText );
		if ( $embedding === null ) {
			wfDebugLog( 'Wanda', "Failed to generate embedding for query" );
			return null;
		}

		// Perform kNN search across all chunks using nested query
		$queryData = [
			"size" => 5,
			"query" => [
				"nested" => [
					"path" => "content_vectors",
					"score_mode" => "max",
					"query" => [
						"script_score" => [
							"query" => [ "match_all" => new \stdClass() ],
							"script" => [
								"source" => "cosineSimilarity(params.query_vector, 'content_vectors.vector') + 1.0",
								"params" => [
									"query_vector" => $embedding
								]
							]
						]
					],
					"inner_hits" => [
						"size" => 1,
						"_source" => [ "chunk_index" ]
					]
				]
			],
			"_source" => [ "title", "content", "content_chunks" ],
			"min_score" => self::$vectorSearchMinScore
		];

		$searchUrl = self::$esHost . "/" . self::$indexName . "/_search";
		wfDebugLog( 'Wanda', "Vector searching Elasticsearch at: " . $searchUrl );

		$ch = curl_init( $searchUrl );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', "Vector search cURL error: " . $curlError );
			return null;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Vector search failed with HTTP " . $httpCode . ": " . $response );
			return null;
		}

		$data = json_decode( $response, true );

		if ( empty( $data['hits']['hits'] ) ) {
			wfDebugLog( 'Wanda', "Vector search returned no results" );
			return null;
		}

		// Get top 3 results and combine them
		$topHits = array_slice( $data['hits']['hits'], 0, 3 );
		$combinedContent = [];
		$sources = [];
		$seenTitles = [];

		foreach ( $topHits as $hit ) {
			$source = $hit['_source'];
			$title = $source['title'] ?? 'Unknown';
			$content = $source['content'] ?? $source['text'] ?? '';

			if ( !empty( $content ) && !empty( $title ) ) {
				$combinedContent[] = "--- From page: " . $title .
					" (Similarity: " . round( $hit['_score'], 2 ) .
					") ---\n" . trim( $content );
				$sources[] = $title;
			}
		}

		if ( empty( $combinedContent ) ) {
			return null;
		}

		wfDebugLog(
			'Wanda',
			"Vector search found " . count( $sources ) . " relevant pages: " . implode( ', ', $sources )
		);

		return [
			"content" => implode( "\n\n", $combinedContent ),
			"source" => implode( ', ', array_unique( $sources ) ),
			"num_results" => count( $sources )
		];
	}

	/**
	 * Generate embedding vector for text using configured provider
	 */
	private function generateEmbedding( $text ) {
		return EmbeddingGenerator::generate(
			$text,
			self::$llmProvider,
			self::$llmApiKey,
			self::$llmApiEndpoint,
			self::$llmEmbeddingModel,
			self::$timeout
		);
	}

	/**
	 * Text-based search
	 */
	private function textSearch( $queryText ) {
		// Check if index is set
		if ( empty( self::$indexName ) ) {
			wfDebugLog( 'Wanda', "Cannot search: index name is empty. ES Host: " . self::$esHost );
			return null;
		}

		$queryData = [
			"query" => [
				"multi_match" => [
					"query" => $queryText,
					"fields" => [ "title^3", "content^2", "text" ],
					"type" => "best_fields",
					"fuzziness" => "AUTO"
				]
			],
			"size" => 5,
			"_source" => [ "title", "content", "text" ],
			"min_score" => 1.0
		];

		$searchUrl = self::$esHost . "/" . self::$indexName . "/_search";
		wfDebugLog( 'Wanda', "Searching Elasticsearch at: " . $searchUrl . " for query: " . $queryText );

		$ch = curl_init( $searchUrl );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', "Elasticsearch cURL error: " . $curlError );
			return null;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Elasticsearch search failed with HTTP " . $httpCode . ": " . $response );
			return null;
		}

		$data = json_decode( $response, true );

		if ( empty( $data['hits']['hits'] ) ) {
			wfDebugLog( 'Wanda', "Elasticsearch returned no results for query: " . $queryText );
			return null;
		}

		// Get top 3 results and combine them
		$topHits = array_slice( $data['hits']['hits'], 0, 3 );
		$combinedContent = [];
		$sources = [];
		$seenTitles = [];

		foreach ( $topHits as $hit ) {
			$source = $hit['_source'];
			$title = $source['title'] ?? 'Unknown';
			$content = $source['content'] ?? $source['text'] ?? '';

			if ( !empty( $content ) && !empty( $title ) ) {
				$combinedContent[] = "--- From page: " . $title .
					" (Score: " . round( $hit['_score'], 2 )
					. ") ---\n" . trim( $content );
				$sources[] = $title;
			}
		}

		if ( empty( $combinedContent ) ) {
			return null;
		}

		wfDebugLog( 'Wanda', "Found " . count( $sources ) . " relevant pages: " . implode( ', ', $sources ) );

		return [
			"content" => implode( "\n\n", $combinedContent ),
			"source" => implode( ', ', array_unique( $sources ) ),
			"num_results" => count( $sources )
		];
	}

	/**
	 * Generate response using Google Gemini (Generative Language API)
	 */
	private function generateGeminiResponse( $prompt, $imageData = [], $chatMessages = null ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-gemini-key' )->text();
		}

		$model = self::$llmModel ?: 'gemini-1.5-flash';
		// Force HTTPS for Gemini API (SSL is required)
		$base = self::$llmApiEndpoint ?: 'https://generativelanguage.googleapis.com/v1';
		$base = rtrim( $base, '/' );
		// Ensure HTTPS is used
		if ( strpos( $base, 'http://' ) === 0 ) {
			$base = 'https://' . substr( $base, 7 );
		} elseif ( strpos( $base, 'https://' ) !== 0 && strpos( $base, 'http://' ) !== 0 ) {
			$base = 'https://' . $base;
		}
		$url = $base . '/models/' . rawurlencode( $model ) . ':generateContent?key=' . urlencode( self::$llmApiKey );
		$parts = [];
		$parts[] = [ 'text' => $prompt ];

		if ( !empty( $imageData ) ) {
			foreach ( $imageData as $img ) {
				$imageContent = false;
				$source = '';

				if ( !empty( $img['localPath'] ) && file_exists( $img['localPath'] ) ) {
					$imageContent = file_get_contents( $img['localPath'] );
					$source = 'local path: ' . $img['localPath'];
				}

				if ( $imageContent === false && !empty( $img['url'] ) ) {
					$context = stream_context_create( [
						'http' => [
							'timeout' => 10,
							'follow_location' => 1,
							'max_redirects' => 3
						],
						'ssl' => [
							'verify_peer' => false,
							'verify_peer_name' => false
						]
					] );

					$imageContent = file_get_contents( $img['url'], false, $context );
					$source = 'URL: ' . $img['url'];
				}

				if ( $imageContent !== false && strlen( $imageContent ) > 0 ) {
					$parts[] = [
						'inline_data' => [
							'mime_type' => $img['mime'] ?? 'image/jpeg',
							'data' => base64_encode( $imageContent )
						]
					];
				} else {
					$lastError = error_get_last();
					throw new \MediaWiki\Rest\HttpException(
						$lastError['message'],
						400
					);
				}
			}
		}

		// Build contents: use multi-turn if chatMessages provided, otherwise single turn
		$contents = [];
		if ( $chatMessages !== null && empty( $imageData ) ) {
			// Convert chat messages to Gemini format
			foreach ( $chatMessages as $msg ) {
				$role = $msg['role'];
				// Gemini uses 'user' and 'model' roles; map 'system' and 'assistant'
				if ( $role === 'system' ) {
					$role = 'user';
				} elseif ( $role === 'assistant' ) {
					$role = 'model';
				}
				$contents[] = [
					'role' => $role,
					'parts' => [ [ 'text' => $msg['content'] ] ]
				];
			}
		} else {
			$contents = [ [ 'role' => 'user', 'parts' => $parts ] ];
		}

		$payload = [
			'contents' => $contents,
			'generationConfig' => [
				'temperature' => self::$temperature,
				'maxOutputTokens' => self::$maxTokens,
				'thinkingConfig' => [
					// Conservative budget to avoid MAX_TOKENS errors
					'thinkingBudget' => 2048
				]
			]
		];

		$maxRetries = 3;
		$retryDelay = 1;
		$lastError = null;

		for ( $attempt = 0; $attempt < $maxRetries; $attempt++ ) {
			if ( $attempt > 0 ) {
				wfDebugLog( 'Wanda', "Gemini retry attempt " . ( $attempt + 1 ) . " after " . $retryDelay . "s delay" );
				sleep( $retryDelay );
				$retryDelay *= 2;
			}

			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );

			$response = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curlError = curl_error( $ch );

			if ( $curlError ) {
				wfDebugLog( 'Wanda', "Gemini cURL error: " . $curlError );
				$lastError = "Connection error: Unable to reach Gemini API.";
				continue;
			}

			if ( $httpCode === 200 ) {
				break;
			}

			$shouldRetry = false;
			if ( $httpCode === 503 || $httpCode === 429 ) {
				$shouldRetry = true;
			}

			wfDebugLog( 'Wanda', "Gemini HTTP error code: " . $httpCode . ", Response: " . $response );
			$errorMsg = "Gemini API error (HTTP " . $httpCode . ")";
			if ( $response ) {
				$errorData = json_decode( $response, true );
				if ( isset( $errorData['error']['message'] ) ) {
					$errorMsg .= ": " . $errorData['error']['message'];
				}
			}

			$lastError = $errorMsg;

			if ( !$shouldRetry ) {
				return $errorMsg;
			}
		}

		if ( $httpCode !== 200 ) {
			$finalError = $lastError ?: "Gemini API error after " .
				$maxRetries . " attempts.";
			if ( $httpCode === 503 ) {
				$finalError .= " The service is currently overloaded. 
					Please try again in a few moments or consider using Ollama for local processing.";
			}
			return $finalError;
		}

		$json = json_decode( $response, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Wanda', "Gemini JSON decode error: " . json_last_error_msg() );
			return "Invalid JSON response from Gemini: " . json_last_error_msg();
		}

		// Check for MAX_TOKENS finish reason (response was truncated)
		if (
			isset( $json['candidates'][0]['finishReason'] )
			&& $json['candidates'][0]['finishReason'] === 'MAX_TOKENS'
		) {
			if ( isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
				return $json['candidates'][0]['content']['parts'][0]['text'] .
					"\n\n[Response truncated due to token limit. " .
					"Current limit: " . self::$maxTokens . " tokens]";
			}
			return $this->msg( 'wanda-api-error-token-limit', self::$maxTokens )->text();
		}

		if ( !isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
			wfDebugLog( 'Wanda', "Gemini response missing expected fields. Response: " . print_r( $json, true ) );
			if ( isset( $json['promptFeedback']['blockReason'] ) ) {
				return "Gemini blocked the request: " . $json['promptFeedback']['blockReason'];
			}
			return "Unexpected response format from Gemini.";
		}

		return $json['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Generate response using Ollama
	 */
	private function generateOllamaResponse( $prompt, $imageData = [] ) {
		$payload = [
			"model" => self::$llmModel,
			"prompt" => $prompt,
			"stream" => false,
			"options" => [
				"temperature" => self::$temperature,
				"num_predict" => self::$maxTokens
			]
		];

		if ( !empty( $imageData ) ) {
			$images = [];
			foreach ( $imageData as $img ) {
				$imageContent = false;

				if ( !empty( $img['localPath'] ) && file_exists( $img['localPath'] ) ) {
					$imageContent = file_get_contents( $img['localPath'] );
				}

				if ( $imageContent === false && !empty( $img['url'] ) ) {
					$imageContent = file_get_contents( $img['url'] );
				}

				if ( $imageContent !== false ) {
					$images[] = base64_encode( $imageContent );
				} else {
					throw new \MediaWiki\Rest\HttpException(
						$lastError['message'],
						400
					);
				}
			}
			if ( !empty( $images ) ) {
				$payload['images'] = $images;
			}
		}

		$data = json_encode( $payload );

		$ch = curl_init( self::$llmApiEndpoint . "generate" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		// Log error details for debugging
		if ( $curlError ) {
			wfDebugLog( 'Wanda', "Ollama cURL error: " . $curlError );
			return "Connection error: Unable to reach Ollama service at " . self::$llmApiEndpoint;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Ollama HTTP error code: " . $httpCode . ", Response: " . $response );
			return "API error: Ollama returned HTTP code "
				. $httpCode . ". Please check your Ollama service.";
		}

		if ( empty( $response ) ) {
			wfDebugLog( 'Wanda', "Ollama returned empty response" );
			return "Empty response from Ollama service. 
				Please check if the model '" . self::$llmModel . "' is available.";
		}

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Wanda', "Ollama JSON decode error: "
				. json_last_error_msg() . ", Response: " . substr( $response, 0, 500 ) );
			return "Invalid JSON response from Ollama: " . json_last_error_msg();
		}

		if ( !isset( $jsonResponse['response'] ) ) {
			wfDebugLog(
				'Wanda',
				"Ollama response missing 'response' field. Full response: "
				. print_r( $jsonResponse, true )
			);
			return "Unexpected response format from Ollama. Response: "
				. ( isset( $jsonResponse['error'] ) ? $jsonResponse['error'] : 'Unknown error' );
		}

		return $jsonResponse['response'];
	}

	/**
	 * Generate response using OpenAI
	 */
	public static function getOpenAITokenKeyForModel( $model ): string {
		$model = trim( (string)$model );
		if ( $model === '' ) {
			return 'max_tokens';
		}

		// Newer OpenAI models (o-series + GPT-5 family) use max_completion_tokens.
		// We match conservatively but allow prefixes like "openai/gpt-5.4-nano".
		if ( preg_match( '/(^|\\/)(o1|o3)/i', $model ) || stripos( $model, 'gpt-5' ) !== false ) {
			return 'max_completion_tokens';
		}

		return 'max_tokens';
	}

	private function generateOpenAIResponse( $prompt, $imageData = [], $chatMessages = null ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-openai-key' )->text();
		}

		// Use multi-turn messages if available and no images
		if ( $chatMessages !== null && empty( $imageData ) ) {
			$messages = $chatMessages;
		} else {
			$messageContent = [];

			if ( !empty( $imageData ) ) {
				$messageContent[] = [
					"type" => "text",
					"text" => $prompt
				];

				foreach ( $imageData as $img ) {
					$imageContent = false;

					if ( !empty( $img['localPath'] ) && file_exists( $img['localPath'] ) ) {
						$imageContent = file_get_contents( $img['localPath'] );
					}

					if ( $imageContent === false && !empty( $img['url'] ) ) {
						$imageContent = file_get_contents( $img['url'] );
					}

					if ( $imageContent !== false ) {
						$base64 = base64_encode( $imageContent );
						$mimeType = $img['mime'] ?? 'image/jpeg';
						$dataUrl = "data:" . $mimeType . ";base64," . $base64;

						$messageContent[] = [
							"type" => "image_url",
							"image_url" => [
								"url" => $dataUrl
							]
						];
					} else {
						throw new \MediaWiki\Rest\HttpException(
							$lastError['message'],
							400
						);
					}
				}
			} else {
				$messageContent = $prompt;
			}

			$messages = [
				[ "role" => "user", "content" => $messageContent ]
			];
		}

		$model = trim( self::$llmModel ?: "gpt-4-turbo" );
		$basePayload = [
			"model" => $model,
			"messages" => $messages,
			"temperature" => self::$temperature
		];

		// Some newer OpenAI models expect max_completion_tokens instead of max_tokens.
		// Choose a sensible default based on model name, but also retry automatically
		// if OpenAI rejects the parameter.
		$tokenKey = self::getOpenAITokenKeyForModel( $model );
		$payload = $basePayload;
		$payload[$tokenKey] = self::$maxTokens;

		$sendRequest = static function ( $payloadData ) {
			$data = json_encode( $payloadData );

			$ch = curl_init( "https://api.openai.com/v1/chat/completions" );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, [
				"Content-Type: application/json",
				"Authorization: Bearer " . self::$llmApiKey
			] );

			$response = curl_exec( $ch );
			$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curlError = curl_error( $ch );

			return [ $response, $httpCode, $curlError ];
		};

		[ $response, $httpCode, $curlError ] = $sendRequest( $payload );

		// Retry once if OpenAI complains about the token parameter.
		if ( $httpCode !== 200 && is_string( $response ) && $response !== '' ) {
			$errorData = json_decode( $response, true );
			$apiMessage = $errorData['error']['message'] ?? '';
			if ( is_string( $apiMessage ) && $apiMessage !== '' ) {
				$mentionsMaxTokens = stripos( $apiMessage, 'max_tokens' ) !== false;
				$mentionsMaxCompletion = stripos( $apiMessage, 'max_completion_tokens' ) !== false;

				$retryKey = null;
				if ( $tokenKey === 'max_tokens' && $mentionsMaxCompletion ) {
					$retryKey = 'max_completion_tokens';
				} elseif ( $tokenKey === 'max_completion_tokens' && $mentionsMaxTokens ) {
					$retryKey = 'max_tokens';
				}

				if ( $retryKey !== null ) {
					wfDebugLog( 'Wanda', 'OpenAI retrying with ' . $retryKey . ' for model ' . $model );
					$retryPayload = $basePayload;
					$retryPayload[$retryKey] = self::$maxTokens;
					[ $response, $httpCode, $curlError ] = $sendRequest( $retryPayload );
				}
			}
		}

		if ( $curlError ) {
			wfDebugLog( 'Wanda', "OpenAI cURL error: " . $curlError );
			return "Connection error: Unable to reach OpenAI API.";
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "OpenAI HTTP error code: " . $httpCode . ", Response: " . $response );
			$errorMsg = "OpenAI API error (HTTP " . $httpCode . ")";
			if ( $response ) {
				$errorData = json_decode( $response, true );
				if ( isset( $errorData['error']['message'] ) ) {
					$errorMsg .= ": " . $errorData['error']['message'];
				}
			}
			return $errorMsg;
		}

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Wanda', "OpenAI JSON decode error: " . json_last_error_msg() );
			return "Invalid JSON response from OpenAI: " . json_last_error_msg();
		}

		if ( !isset( $jsonResponse['choices'][0]['message']['content'] ) ) {
			wfDebugLog( 'Wanda', "OpenAI response missing expected fields. 
				Response: " . print_r( $jsonResponse, true ) );
			return "Unexpected response format from OpenAI.";
		}

		return $jsonResponse['choices'][0]['message']['content'];
	}

	/**
	 * Generate response using Anthropic Claude
	 */
	private function generateAnthropicResponse( $prompt, $imageData = [], $chatMessages = null ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-anthropic-key' )->text();
		}

		// Use multi-turn messages if available and no images
		$systemPrompt = '';
		if ( $chatMessages !== null && empty( $imageData ) ) {
			// Anthropic uses a separate 'system' field; extract from chatMessages
			$messages = [];
			foreach ( $chatMessages as $msg ) {
				if ( $msg['role'] === 'system' ) {
					$systemPrompt = $msg['content'];
				} else {
					$messages[] = $msg;
				}
			}
		} else {
			$messageContent = [];

			if ( !empty( $imageData ) ) {
				foreach ( $imageData as $img ) {
					$imageContent = false;

					if ( !empty( $img['localPath'] ) && file_exists( $img['localPath'] ) ) {
						$imageContent = file_get_contents( $img['localPath'] );
					}

					if ( $imageContent === false && !empty( $img['url'] ) ) {
						$imageContent = file_get_contents( $img['url'] );
					}

					if ( $imageContent !== false ) {
						$mediaType = $img['mime'] ?? 'image/jpeg';
						$messageContent[] = [
							"type" => "image",
							"source" => [
								"type" => "base64",
								"media_type" => $mediaType,
								"data" => base64_encode( $imageContent )
							]
						];
					} else {
						throw new \MediaWiki\Rest\HttpException(
							$lastError['message'],
							400
						);
					}
				}

				$messageContent[] = [
					"type" => "text",
					"text" => $prompt
				];
			} else {
				$messageContent = $prompt;
			}

			$messages = [
				[ "role" => "user", "content" => $messageContent ]
			];
		}

		$payloadData = [
			"model" => self::$llmModel ?: "claude-3-haiku-20240307",
			"messages" => $messages,
			"max_tokens" => self::$maxTokens,
			"temperature" => self::$temperature
		];
		if ( !empty( $systemPrompt ) ) {
			$payloadData['system'] = $systemPrompt;
		}
		$data = json_encode( $payloadData );

		$ch = curl_init( "https://api.anthropic.com/v1/messages" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json",
			"x-api-key: " . self::$llmApiKey,
			"anthropic-version: 2023-06-01"
		] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', "Anthropic cURL error: " . $curlError );
			return "Connection error: Unable to reach Anthropic API.";
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Anthropic HTTP error code: " . $httpCode . ", Response: " . $response );
			$errorMsg = "Anthropic API error (HTTP " . $httpCode . ")";
			if ( $response ) {
				$errorData = json_decode( $response, true );
				if ( isset( $errorData['error']['message'] ) ) {
					$errorMsg .= ": " . $errorData['error']['message'];
				}
			}
			return $errorMsg;
		}

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Wanda', "Anthropic JSON decode error: " . json_last_error_msg() );
			return "Invalid JSON response from Anthropic: " . json_last_error_msg();
		}

		if ( !isset( $jsonResponse['content'][0]['text'] ) ) {
			wfDebugLog( 'Wanda', "Anthropic response missing expected fields. Response: "
				. print_r( $jsonResponse, true ) );
			return "Unexpected response format from Anthropic.";
		}

		return $jsonResponse['content'][0]['text'];
	}

	/**
	 * Generate response using Azure OpenAI
	 */
	private function generateAzureResponse( $prompt, $imageData = [], $chatMessages = null ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-azure-key' )->text();
		}

		// Use multi-turn messages if available and no images
		if ( $chatMessages !== null && empty( $imageData ) ) {
			$messages = $chatMessages;
		} else {
			$messageContent = [];

			if ( !empty( $imageData ) ) {
				$messageContent[] = [
					"type" => "text",
					"text" => $prompt
				];

				foreach ( $imageData as $img ) {
					$imageContent = false;

					if ( !empty( $img['localPath'] ) && file_exists( $img['localPath'] ) ) {
						$imageContent = file_get_contents( $img['localPath'] );
					}

					if ( $imageContent === false && !empty( $img['url'] ) ) {
						$imageContent = file_get_contents( $img['url'] );
					}

					if ( $imageContent !== false ) {
						$base64 = base64_encode( $imageContent );
						$mimeType = $img['mime'] ?? 'image/jpeg';
						$dataUrl = "data:" . $mimeType . ";base64," . $base64;

						$messageContent[] = [
							"type" => "image_url",
							"image_url" => [
								"url" => $dataUrl
							]
						];
					} else {
						throw new \MediaWiki\Rest\HttpException(
							$lastError['message'],
							400
						);
					}
				}
			} else {
				$messageContent = $prompt;
			}

			$messages = [
				[ "role" => "user", "content" => $messageContent ]
			];
		}

		$tokenKey = $this->getOpenAITokenKeyForModel( self::$llmModel );
		$data = json_encode( [
			"messages" => $messages,
			$tokenKey => self::$maxTokens,
			"temperature" => self::$temperature
		] );

		// Azure endpoint should include the deployment name
		$ch = curl_init( self::$llmApiEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json",
			"api-key: " . self::$llmApiKey
		] );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', "Azure cURL error: " . $curlError );
			return "Connection error: Unable to reach Azure OpenAI endpoint.";
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Azure HTTP error code: " . $httpCode . ", Response: " . $response );
			$errorMsg = "Azure OpenAI API error (HTTP " . $httpCode . ")";
			if ( $response ) {
				$errorData = json_decode( $response, true );
				if ( isset( $errorData['error']['message'] ) ) {
					$errorMsg .= ": " . $errorData['error']['message'];
				}
			}
			return $errorMsg;
		}

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Wanda', "Azure JSON decode error: " . json_last_error_msg() );
			return "Invalid JSON response from Azure: " . json_last_error_msg();
		}

		if ( !isset( $jsonResponse['choices'][0]['message']['content'] ) ) {
			wfDebugLog( 'Wanda', "Azure response missing expected fields. Response: "
				. print_r( $jsonResponse, true ) );
			return "Unexpected response format from Azure OpenAI.";
		}

		return $jsonResponse['choices'][0]['message']['content'];
	}

	/**
	 * Manage conversation history to stay within character budget.
	 * When history exceeds the limit, older messages are summarized via the LLM
	 * and replaced with a single summary message. Falls back to simple truncation
	 * if the summarization call fails.
	 *
	 * @param array $history Array of {role, content} message objects
	 * @return array Managed history array
	 */
	private function truncateConversationHistory( array $history ) {
		$maxChars = self::$conversationMaxChars;
		$totalChars = 0;

		// Calculate total character count
		foreach ( $history as $msg ) {
			$totalChars += strlen( $msg['content'] ?? '' );
		}

		// If within budget, return as-is
		if ( $totalChars <= $maxChars ) {
			return $history;
		}

		wfDebugLog( 'Wanda', "Conversation history ({$totalChars} chars) 
			exceeds budget ({$maxChars}). Summarizing older messages." );

		// Split history: keep roughly the newest half, summarize the oldest half
		$splitPoint = max( 1, intdiv( count( $history ), 2 ) );
		// Ensure split is on a pair boundary (user+assistant)
		if ( $splitPoint % 2 !== 0 ) {
			$splitPoint = min( $splitPoint + 1, count( $history ) - 1 );
		}
		$oldMessages = array_slice( $history, 0, $splitPoint );
		$recentMessages = array_slice( $history, $splitPoint );

		// Try to summarize old messages via LLM
		$summary = $this->summarizeConversation( $oldMessages );

		if ( $summary !== false ) {
			// Prepend the summary as a context message
			$summaryMsg = [
				'role' => 'user',
				'content' => '[Summary of earlier conversation]: ' . $summary
			];
			$result = array_merge( [ $summaryMsg ], $recentMessages );

			wfDebugLog( 'Wanda', "Summarized " . count( $oldMessages ) .
				" old messages into " . strlen( $summary ) . " chars. Keeping " .
				count( $recentMessages ) . " recent messages." );

			return $result;
		}

		// Fallback: simple truncation if summarization fails
		wfDebugLog( 'Wanda', "Summarization failed, falling back to simple truncation." );
		while ( $totalChars > $maxChars && count( $history ) > 0 ) {
			$removed = array_shift( $history );
			$totalChars -= strlen( $removed['content'] ?? '' );
		}

		return $history;
	}

	/**
	 * Summarize a set of conversation messages using the configured LLM.
	 *
	 * @param array $messages Array of conversation messages to summarize
	 * @return string|false Summary text, or false on failure
	 */
	private function summarizeConversation( array $messages ) {
		if ( empty( $messages ) ) {
			return false;
		}

		// Build a prompt for summarization
		$conversationText = '';
		foreach ( $messages as $msg ) {
			$role = ( $msg['role'] === 'assistant' ) ? 'Assistant' : 'User';
			$conversationText .= $role . ": " . ( $msg['content'] ?? '' ) . "\n";
		}

		$summaryPrompt = "Summarize the following conversation concisely, preserving the key topics discussed, " .
			"important facts mentioned, and any decisions or conclusions reached. " .
			"Keep the summary brief (2-4 sentences).\n\n" .
			"Conversation:\n" . $conversationText . "\n\nSummary:";

		try {
			// Use a lower max_tokens for the summary to keep it compact
			$originalMaxTokens = self::$maxTokens;
			self::$maxTokens = 256;

			switch ( self::$llmProvider ) {
				case 'ollama':
					$result = $this->generateOllamaResponse( $summaryPrompt, [] );
					break;
				case 'openai':
					$result = $this->generateOpenAIResponse( $summaryPrompt, [] );
					break;
				case 'anthropic':
					$result = $this->generateAnthropicResponse( $summaryPrompt, [] );
					break;
				case 'azure':
					$result = $this->generateAzureResponse( $summaryPrompt, [] );
					break;
				case 'gemini':
				default:
					$result = $this->generateGeminiResponse( $summaryPrompt, [] );
					break;
			}

			self::$maxTokens = $originalMaxTokens;

			if ( $result && is_string( $result ) && strlen( $result ) > 10 ) {
				return trim( $result );
			}

			return false;
		} catch ( \Exception $e ) {
			self::$maxTokens = $originalMaxTokens ?? 2048;
			wfDebugLog( 'Wanda', "Conversation summarization failed: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Build conversation history formatted for chat-based providers.
	 * Returns array of {role, content} messages suitable for OpenAI/Anthropic/Azure APIs.
	 *
	 * @param array $conversationHistory Raw conversation history
	 * @param string $systemPrompt System/context prompt to prepend
	 * @param string $userQuery Current user query
	 * @param array $imageData Image data for the current message
	 * @return array Messages array for chat APIs
	 */
	private function buildChatMessages( $conversationHistory, $systemPrompt, $userQuery, $imageData = [] ) {
		$messages = [];

		// Add system prompt as the first message
		$messages[] = [ 'role' => 'system', 'content' => $systemPrompt ];

		// Add conversation history
		foreach ( $conversationHistory as $msg ) {
			$role = ( $msg['role'] === 'assistant' ) ? 'assistant' : 'user';
			$messages[] = [ 'role' => $role, 'content' => $msg['content'] ?? '' ];
		}

		// Add current user query
		if ( !empty( $imageData ) ) {
			// Image handling is done by the provider-specific method
			$messages[] = [ 'role' => 'user', 'content' => $userQuery ];
		} else {
			$messages[] = [ 'role' => 'user', 'content' => $userQuery ];
		}

		return $messages;
	}

	/**
	 * Build a flat prompt string with conversation history for non-chat providers (e.g. Ollama).
	 *
	 * @param array $conversationHistory Raw conversation history
	 * @param string $basePrompt The base prompt including context
	 * @return string Combined prompt string
	 */
	private function buildFlatPromptWithHistory( $conversationHistory, $basePrompt ) {
		if ( empty( $conversationHistory ) ) {
			return $basePrompt;
		}

		$historyText = "Previous conversation:\n";
		foreach ( $conversationHistory as $msg ) {
			$role = ( $msg['role'] === 'assistant' ) ? 'Assistant' : 'User';
			$historyText .= $role . ": " . ( $msg['content'] ?? '' ) . "\n";
		}
		$historyText .= "\n";

		return $historyText . $basePrompt;
	}

	/**
	 * Driver function to generate an LLM response given a user query and retrieved context.
	 *
	 * @param string $userQuery The original user question
	 * @param string|null $context Retrieved wiki content used as grounding context
	 * @param bool $allowPublicKnowledge Whether to allow LLM to use public knowledge
	 * @param array $imageData Array of image data
	 * @param string $userLang User's interface language code
	 * @param string $contentLang Wiki's content language code
	 * @param array $conversationHistory Previous conversation messages
	 * @return string|false LLM answer text or false on complete failure
	 */
	private function generateLLMResponse(
		$userQuery,
		$context,
		$allowPublicKnowledge = false,
		$imageData = [],
		$userLang = 'en',
		$contentLang = 'en',
		$conversationHistory = [],
		$memoryDisabled = false,
		$cargoContext = '',
		$wikidataContext = ''
	) {
		if ( !$userQuery ) {
			return false;
		}

		if ( $memoryDisabled ) {
			$conversationHistory = [];
		}

		$context = trim( (string)$context );
		$cargoContext = trim( (string)$cargoContext );
		$wikidataContext = trim( (string)$wikidataContext );
		$maxContextChars = 10000;

		// Allocate budget across the three context sources so none is dropped silently.
		$wikidataBudget = 0;
		if ( $wikidataContext !== '' ) {
			$wikidataBudget = min( 3000, $maxContextChars );
			if ( strlen( $wikidataContext ) > $wikidataBudget ) {
				$wikidataContext = substr( $wikidataContext, 0, $wikidataBudget ) . "\n[...truncated...]";
			}
		}

		$cargoBudget = 0;
		if ( $cargoContext !== '' ) {
			$cargoBudget = min( 3000, $maxContextChars - strlen( $wikidataContext ) );
			if ( strlen( $cargoContext ) > $cargoBudget ) {
				$cargoContext = substr( $cargoContext, 0, $cargoBudget ) . "\n[...truncated...]";
			}
		}

		$wikiBudget = max( 0, $maxContextChars - strlen( $wikidataContext ) - strlen( $cargoContext ) );
		if ( $context !== '' && strlen( $context ) > $wikiBudget ) {
			$context = substr( $context, 0, $wikiBudget ) . "\n[...truncated...]";
		}

		$contextBlock = '';
		if ( $wikidataContext !== '' ) {
			$contextBlock .= "Structured data from Wikidata:\n" . $wikidataContext;
		}
		if ( $cargoContext !== '' ) {
			$contextBlock .= ( $contextBlock !== '' ? "\n\n" : '' )
				. "Structured data from database:\n" . $cargoContext;
		}
		if ( $context !== '' ) {
			$contextBlock .= ( $contextBlock !== '' ? "\n\n" : '' )
				. "Information from wiki pages:\n" . $context;
		}
		if ( $contextBlock === '' ) {
			$contextBlock = "(No additional context from the knowledge base was found.)";
		}

		$dataInstructions = "IMPORTANT: Never output raw wikitext or suggest the user run database queries. " .
			"If structured data from the database is provided and relevant to the question, " .
			"treat it as authoritative and answer directly from it. " .
			"Present any structured data directly in a clear, readable format " .
			"(use lists or tables as appropriate). " .
			"If no data is available to answer the question, say so.\n\n";

		$languageInstruction = '';
		if ( $userLang && $userLang !== 'en' ) {
			$languageInstruction = "\nIMPORTANT: Please provide your answer in the language with code '{$userLang}'.";

			// If user language differs from content language, add translation note
			if ( $contentLang && $userLang !== $contentLang ) {
				$languageInstruction .= " The wiki content is in '{$contentLang}', " .
					"but your response must be in '{$userLang}'.";
			}
		}

		// Build the system prompt (context + instructions) and the user question separately
		$systemPrompt = '';
		if ( self::$customPrompt !== '' ) {
			$systemPrompt = self::$customPrompt . "\n\n" .
				$dataInstructions .
				"Context:\n" . $contextBlock . $languageInstruction;
		} elseif ( self::$customPromptTitle !== '' ) {
			$title = Title::newFromText( self::$customPromptTitle );
			$wikipage = new WikiPage( $title );
			$content = $wikipage->getContent()->getText();

			$systemPrompt = $content . "\n\n" .
				$dataInstructions .
				"Context:\n" . $contextBlock . $languageInstruction;
		} else {
			$template = $allowPublicKnowledge ? 'system-with-knowledge' : 'system-without-knowledge';
			$systemPrompt = PromptTemplate::render( $template, [ 'context' => $contextBlock ] )
				. $languageInstruction;
		}

		// Prepend language instruction if configured
		if ( self::$useContentLang ) {
			$contentLangCode = MediaWikiServices::getInstance()->getContentLanguage()->getCode();
			$systemPrompt = "IMPORTANT: Please provide your answer in the language with code '" .
				$contentLangCode . "'. Your entire response should be written in this language. " .
				"However, if you do not have sufficient knowledge to answer accurately in the '" .
				$contentLangCode . "' language, you may respond in English as a fallback.\n\n" . $systemPrompt;
		}

		// If user explicitly disabled memory, add instruction to system prompt
		if ( $memoryDisabled ) {
			$systemPrompt .= "\n\nIMPORTANT: Conversation memory has been disabled by the user. " .
				"You have NO access to any previous conversation history. " .
				"If the user asks about previous messages, earlier discussions, 
					or anything from a prior conversation, " .
				"you must clearly state that there is no previous conversation history available " .
				"because conversation memory is currently turned off.";
		}

		// For chat-based providers, build multi-turn messages;
		// for completion-based providers, build a flat prompt string
		$hasHistory = !empty( $conversationHistory );

		if ( $hasHistory ) {
			wfDebugLog( 'Wanda', "Conversation history: " . count( $conversationHistory ) . " messages" );
		}

		// Build the flat prompt (used by Ollama and as fallback)
		$flatPrompt = $systemPrompt . "\n\nQuestion: " . $userQuery . "\n\nAnswer:";
		if ( $hasHistory ) {
			$flatPrompt = $this->buildFlatPromptWithHistory( $conversationHistory, $flatPrompt );
		}

		wfDebugLog( 'Wanda', "Prompt length: " . strlen( $flatPrompt ) .
			" characters, Context block length: " . strlen( $contextBlock ) . " characters" );
		wfDebugLog( 'Wanda', "First 500 chars of prompt: " . substr( $flatPrompt, 0, 500 ) );

		// Build chat messages for multi-turn providers
		$chatMessages = $hasHistory
			? $this->buildChatMessages( $conversationHistory, $systemPrompt, $userQuery, $imageData )
			: null;

		$response = null;
		switch ( self::$llmProvider ) {
			case 'ollama':
				$response = $this->generateOllamaResponse( $flatPrompt, $imageData );
				break;
			case 'openai':
				$response = $this->generateOpenAIResponse( $flatPrompt, $imageData, $chatMessages );
				break;
			case 'anthropic':
				$response = $this->generateAnthropicResponse( $flatPrompt, $imageData, $chatMessages );
				break;
			case 'azure':
				$response = $this->generateAzureResponse( $flatPrompt, $imageData, $chatMessages );
				break;
			case 'gemini':
				$response = $this->generateGeminiResponse( $flatPrompt, $imageData, $chatMessages );
				break;
			default:
				return false;
		}

		// Normalize / sanitize response
		if ( !is_string( $response ) || trim( $response ) === '' ) {
			return false;
		}

		// Remove any leading/trailing whitespace or stray control characters
		$clean = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $response ) );
		return $clean === '' ? false : $clean;
	}

	public function getAllowedParams() {
		return [
			"message" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_REQUIRED => false
			],
			"customprompt" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$customPrompt,
				ParamValidator::PARAM_REQUIRED => false
			],
			"customprompttitle" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$customPromptTitle,
				ParamValidator::PARAM_REQUIRED => false
			],
			"maxtokens" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => self::$maxTokens,
				ParamValidator::PARAM_REQUIRED => false
			],
			"model" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$llmModel,
				ParamValidator::PARAM_REQUIRED => false
			],
			"provider" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$llmProvider,
				ParamValidator::PARAM_REQUIRED => false
			],
			"apikey" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$llmApiKey,
				ParamValidator::PARAM_REQUIRED => false
			],
			"apiendpoint" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$llmApiEndpoint,
				ParamValidator::PARAM_REQUIRED => false
			],
			"timeout" => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => self::$timeout,
				ParamValidator::PARAM_REQUIRED => false
			],
			"temperature" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$temperature,
				ParamValidator::PARAM_REQUIRED => false
			],
			"usepublicknowledge" => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => self::$usePublicKnowledge,
				ParamValidator::PARAM_REQUIRED => false
			],
			"skipesquery" => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => self::$skipESQuery,
				ParamValidator::PARAM_REQUIRED => false
			],
			"images" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_REQUIRED => false
			],
			"conversationhistory" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_REQUIRED => false
			],
			"memorydisabled" => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				ParamValidator::PARAM_REQUIRED => false
			],
			"wikidataonly" => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
				ParamValidator::PARAM_REQUIRED => false
			],
			"wikidatalang" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => self::$wikidataLang,
				ParamValidator::PARAM_REQUIRED => false
			],
			"sources" => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => 'wiki',
				ParamValidator::PARAM_REQUIRED => false
			]
		];
	}
}
