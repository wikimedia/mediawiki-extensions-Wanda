<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
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

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'WandaLLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->detectElasticsearchIndex();
		self::$llmProvider = strtolower( $this->getConfig()->get( 'WandaLLMProvider' ) ?? "ollama" );
		self::$llmModel = $this->getConfig()->get( 'WandaLLMModel' ) ?? "gemma:2b";
		self::$llmApiKey = $this->getConfig()->get( 'WandaLLMApiKey' ) ?? "";

		// Set default endpoint based on provider
		$defaultEndpoint = "http://ollama:11434/api/";
		if ( self::$llmProvider === 'gemini' ) {
			$defaultEndpoint = "https://generativelanguage.googleapis.com/v1";
		}
		self::$llmApiEndpoint = $this->getConfig()->get( 'WandaLLMApiEndpoint' ) ?? $defaultEndpoint;

		self::$maxTokens = $this->getConfig()->get( 'WandaLLMMaxTokens' ) ?? 1000;
		self::$temperature = $this->getConfig()->get( 'WandaLLMTemperature' ) ?? 0.7;
		self::$timeout = $this->getConfig()->get( 'WandaLLMTimeout' ) ?? 30;
		self::$customPromptTitle = $this->getConfig()->get( 'WandaCustomPromptTitle' ) ?? "";
		self::$customPrompt = $this->getConfig()->get( 'WandaCustomPrompt' ) ?? "";
		self::$skipESQuery = $this->getConfig()->get( 'WandaSkipESQuery' ) ?? false;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = trim( $params['message'] );
		$allowPublicKnowledge = !empty( $params['usepublicknowledge'] );
		$imagesList = !empty( $params['images'] ) ? $params['images'] : '';
		$this->overrideLlmParameters( $params );

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

		// If skipESQuery is enabled, use the context parameter (or empty string)
		if ( self::$skipESQuery ) {
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

		$response = $this->generateLLMResponse( $userQuery, $contextStr, $allowPublicKnowledge, $imageData );
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
		if ( !empty( $sourceData ) ) {
			$this->getResult()->addValue( null, "source", implode( ', ', array_unique( $sourceData ) ) );
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
		curl_close( $ch );

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
			wfDebugLog( 'Wanda',
				"No mediawiki_content_* indices found. Available indices: " .
					implode( ', ', array_column( $indices, 'index' ) ) );
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
		return $this->textSearch( $queryText );
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
		curl_close( $ch );

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
	private function generateGeminiResponse( $prompt, $imageData = [] ) {
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

		$payload = [
			'contents' => [ [ 'role' => 'user', 'parts' => $parts ] ],
			'generationConfig' => [
				'temperature' => self::$temperature,
				'maxOutputTokens' => self::$maxTokens
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
			curl_close( $ch );

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
		curl_close( $ch );

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
			wfDebugLog( 'Wanda',
				"Ollama response missing 'response' field. Full response: "
				. print_r( $jsonResponse, true ) );
			return "Unexpected response format from Ollama. Response: "
				. ( isset( $jsonResponse['error'] ) ? $jsonResponse['error'] : 'Unknown error' );
		}

		return $jsonResponse['response'];
	}

	/**
	 * Generate response using OpenAI
	 */
	private function generateOpenAIResponse( $prompt, $imageData = [] ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-openai-key' )->text();
		}

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

		$data = json_encode( [
			"model" => self::$llmModel ?: "gpt-4-turbo",
			"messages" => [
				[ "role" => "user", "content" => $messageContent ]
			],
			"max_tokens" => self::$maxTokens,
			"temperature" => self::$temperature
		] );

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
		curl_close( $ch );

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
	private function generateAnthropicResponse( $prompt, $imageData = [] ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-anthropic-key' )->text();
		}

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

		$data = json_encode( [
			"model" => self::$llmModel ?: "claude-3-haiku-20240307",
			"messages" => [
				[ "role" => "user", "content" => $messageContent ]
			],
			"max_tokens" => self::$maxTokens,
			"temperature" => self::$temperature
		] );

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
		curl_close( $ch );

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
	private function generateAzureResponse( $prompt, $imageData = [] ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-azure-key' )->text();
		}
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

		$data = json_encode( [
			"messages" => [
				[ "role" => "user", "content" => $messageContent ]
			],
			"max_tokens" => self::$maxTokens,
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
		curl_close( $ch );

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
	 * Driver function to generate an LLM response given a user query and retrieved context.
	 *
	 * @param string $userQuery The original user question
	 * @param string|null $context Retrieved wiki content used as grounding context
	 * @return string|false LLM answer text or false on complete failure
	 */
	private function generateLLMResponse( $userQuery, $context, $allowPublicKnowledge = false, $imageData = [] ) {
		if ( !$userQuery ) {
			return false;
		}

		$context = trim( (string)$context );
		if ( $context === '' ) {
			$contextBlock = "(No additional context from the knowledge base was found.)";
		} else {
			$maxContextChars = 8000;
			if ( strlen( $context ) > $maxContextChars ) {
				$context = substr( $context, 0, $maxContextChars ) . "\n[...truncated...]";
			}
			$contextBlock = $context;
		}

		if ( self::$customPrompt !== '' ) {
			$prompt = self::$customPrompt .
				"\n\nContext:\n" . $contextBlock . "\n\n" .
				"User Question: " . $userQuery . "\n\n" .
				"Answer:";
		} elseif ( self::$customPromptTitle !== '' ) {
			$title = Title::newFromText( self::$customPromptTitle );
			$wikipage = new WikiPage( $title );
			$content = $wikipage->getContent()->getText();

			$prompt = $content .
				"\n\nContext:\n" . $contextBlock . "\n\n" .
				"User Question: " . $userQuery . "\n\n" .
				"Answer:";
		} else {
			if ( $allowPublicKnowledge ) {
				$prompt = "You are a helpful assistant. 
					Answer the user's question based on the information provided below.\n\n" .
					"Wiki Content:\n" . $contextBlock . "\n\n" .
					"Question: " . $userQuery . "\n\n" .
					"Instructions: Use the wiki content above to answer the question. 
						If the information is there, use it. If not, use your knowledge.\n\n" .
					"Answer:";
			} else {
				$prompt = "Answer the following question using the information provided.\n\n" .
					"Information from wiki pages:\n" . $contextBlock . "\n\n" .
					"Question: " . $userQuery . "\n\n" .
					"Provide a helpful answer based on the information above:\n";
			}
		}

		wfDebugLog( 'Wanda', "Prompt length: " . strlen( $prompt ) .
			" characters, Context block length: " . strlen( $contextBlock ) . " characters" );
		wfDebugLog( 'Wanda', "First 500 chars of prompt: " . substr( $prompt, 0, 500 ) );

		$response = null;
		switch ( self::$llmProvider ) {
			case 'ollama':
				$response = $this->generateOllamaResponse( $prompt, $imageData );
				break;
			case 'openai':
				$response = $this->generateOpenAIResponse( $prompt, $imageData );
				break;
			case 'anthropic':
				$response = $this->generateAnthropicResponse( $prompt, $imageData );
				break;
			case 'azure':
				$response = $this->generateAzureResponse( $prompt, $imageData );
				break;
			case 'gemini':
				$response = $this->generateGeminiResponse( $prompt, $imageData );
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
			]
		];
	}
}
