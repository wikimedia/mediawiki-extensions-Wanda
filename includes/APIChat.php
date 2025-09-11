<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
use stdClass;

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
	/** @var string */
	private static $embeddingModel;
	/** @var int */
	private static $maxTokens;
	/** @var float */
	private static $temperature;
	/** @var int */
	private static $timeout;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'LLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->detectElasticsearchIndex();
		self::$llmProvider = strtolower( $this->getConfig()->get( 'LLMProvider' ) ?? "ollama" );
		self::$llmModel = $this->getConfig()->get( 'LLMModel' ) ?? "gemma:2b";
		self::$llmApiKey = $this->getConfig()->get( 'LLMApiKey' ) ?? "";
		self::$llmApiEndpoint = $this->getConfig()->get( 'LLMApiEndpoint' ) ?? "http://ollama:11434/api/";
		self::$embeddingModel = $this->getConfig()->get( 'LLMEmbeddingModel' ) ?? "nomic-embed-text";
		self::$maxTokens = $this->getConfig()->get( 'LLMMaxTokens' ) ?? 1000;
		self::$temperature = $this->getConfig()->get( 'LLMTemperature' ) ?? 0.7;
		self::$timeout = $this->getConfig()->get( 'LLMTimeout' ) ?? 30;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = trim( $params['message'] );

		// Validate input parameters
		if ( empty( $userQuery ) ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-empty-question' )->text() );
			return;
		}

		if ( strlen( $userQuery ) > 1000 ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-question-too-long' )->text() );
			return;
		}

		// Validate provider configuration
		if ( !$this->validateProviderConfig() ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-config-invalid' )->text() );
			return;
		}

		// Check for Elasticsearch index
		$index = $this->detectElasticsearchIndex();
		if ( !$index ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-no-index' )->text() );
			return;
		}

		// Search for context
		$searchResults = $this->queryElasticsearch( $userQuery );
		if ( empty( $searchResults ) ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-no-results' )->text() );
			return;
		}

		// Generate response
		$response = $this->generateLLMResponse( $userQuery, $searchResults );
		if ( !$response ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-generation-failed' )->text() );
			return;
		}

		// Prepare source data
		$sourceData = array_map( function( $result ) {
			return isset( $result['_source']['page_title'] ) ? $result['_source']['page_title'] : 'Unknown source';
		}, $searchResults );

		// Return response along with source attribution
		$this->getResult()->addValue( null, "response", $response );
		$this->getResult()->addValue( null, "source", implode( ', ', array_unique( $sourceData ) ) );
	}

	/**
	 * Validate provider configuration
	 */
	private function validateProviderConfig() {
		switch ( self::$llmProvider ) {
			case 'openai':
			case 'anthropic':
			case 'azure':
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
	 * Detects the most recent Elasticsearch index dynamically.
	 */
	private function detectElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			return null;
		}

		// Filter indices related to MediaWiki embeddings
		$validIndices = array_filter( $indices, static function ( $index ) {
			return strpos( $index['index'], 'mediawiki_content_' ) === 0;
		} );

		// Sort by index creation order and return the most recent one
		usort( $validIndices, static function ( $a, $b ) {
			return strcmp( $b['index'], $a['index'] );
		} );

		$selectedIndex = $validIndices[0]['index'] ?? null;

		return $selectedIndex;
	}

	private function queryElasticsearch( $queryText ) {
		$queryEmbedding = $this->generateEmbedding( $queryText );
		
		if ( $queryEmbedding && isset( $queryEmbedding['embeddings'][0] ) ) {
			// Use vector similarity search
			return $this->vectorSearch( $queryEmbedding['embeddings'][0] );
		} else {
			// Fallback to text-based search
			return $this->textSearch( $queryText );
		}
	}

	/**
	 * Vector-based similarity search
	 */
	private function vectorSearch( $queryEmbedding ) {
		$queryData = [
			"query" => [
				"script_score" => [
					"query" => [ "match_all" => new stdClass() ],
					"script" => [
						"source" => "cosineSimilarity(params.query_vector, doc['embedding']) + 1.0",
						"params" => [ "query_vector" => $queryEmbedding ]
					]
				]
			],
			"size" => 5
		];

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_search" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );
		$data = json_decode( $response, true );

		if ( empty( $data['hits']['hits'] ) ) {
			return null;
		}

		$bestMatch = $data['hits']['hits'][0]['_source'];
		return [
			"content" => $bestMatch['content'],
			"source" => $bestMatch['title']
		];
	}

	/**
	 * Text-based search fallback
	 */
	private function textSearch( $queryText ) {
		$queryData = [
			"query" => [
				"multi_match" => [
					"query" => $queryText,
					"fields" => ["title^2", "content"],
					"type" => "best_fields",
					"fuzziness" => "AUTO"
				]
			],
			"size" => 5
		];

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_search" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );
		$data = json_decode( $response, true );

		if ( empty( $data['hits']['hits'] ) ) {
			return null;
		}

		$bestMatch = $data['hits']['hits'][0]['_source'];
		return [
			"content" => $bestMatch['content'],
			"source" => $bestMatch['title']
		];
	}

	/**
	 * Generate an embedding for the query using configured LLM provider.
	 */
	private function generateEmbedding( $text ) {
		switch ( self::$llmProvider ) {
			case 'openai':
				return $this->generateOpenAIEmbedding( $text );
			case 'anthropic':
				return $this->generateAnthropicEmbedding( $text );
			case 'azure':
				return $this->generateAzureEmbedding( $text );
			case 'ollama':
			default:
				return $this->generateOllamaEmbedding( $text );
		}
	}

	/**
	 * Generate embedding using Ollama API.
	 */
	private function generateOllamaEmbedding( $text ) {
		$payload = [ "model" => self::$embeddingModel, "input" => $text ];
		$embeddingEndpoint = self::$llmApiEndpoint . "embed";

		$ch = curl_init( $embeddingEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $jsonResponse ?? null;
	}

	/**
	 * Generate embedding using OpenAI API.
	 */
	private function generateOpenAIEmbedding( $text ) {
		if ( empty( self::$llmApiKey ) ) {
			return null;
		}

		$payload = [
			"input" => $text,
			"model" => self::$embeddingModel ?: "text-embedding-ada-002"
		];

		$ch = curl_init( "https://api.openai.com/v1/embeddings" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json",
			"Authorization: Bearer " . self::$llmApiKey
		] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		// Convert OpenAI response format to match Ollama format
		if ( isset( $jsonResponse['data'][0]['embedding'] ) ) {
			return [ 'embeddings' => [ $jsonResponse['data'][0]['embedding'] ] ];
		}

		return null;
	}

	/**
	 * Generate embedding using Azure OpenAI API.
	 */
	private function generateAzureEmbedding( $text ) {
		if ( empty( self::$llmApiKey ) ) {
			return null;
		}

		$payload = [
			"input" => $text
		];

		// Azure endpoint should include the deployment name
		$ch = curl_init( self::$llmApiEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			"Content-Type: application/json",
			"api-key: " . self::$llmApiKey
		] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		// Convert Azure response format to match Ollama format
		if ( isset( $jsonResponse['data'][0]['embedding'] ) ) {
			return [ 'embeddings' => [ $jsonResponse['data'][0]['embedding'] ] ];
		}

		return null;
	}

	/**
	 * Generate embedding using Anthropic API (placeholder - Anthropic doesn't have embeddings API)
	 */
	private function generateAnthropicEmbedding( $text ) {
		// Anthropic doesn't provide embeddings API, fall back to text similarity
		return null;
	}

	/**
	 * Generates response using configured LLM provider with context
	 */
	private function generateLLMResponse( $query, $context ) {
		$prompt = "Based on the following wiki content, answer the query:\n\n"
			. "Wiki Content:\n" . $context . "\n\n"
			. "User Query:\n" . $query . "\n\n"
			. "\nYour answer should be based on the provided context only."
			. " Do not hallucinate and do not write anything apart from the answer."
			. " No need to mention these instructions in the answer.";

		switch ( self::$llmProvider ) {
			case 'openai':
				return $this->generateOpenAIResponse( $prompt );
			case 'anthropic':
				return $this->generateAnthropicResponse( $prompt );
			case 'azure':
				return $this->generateAzureResponse( $prompt );
			case 'ollama':
			default:
				return $this->generateOllamaResponse( $prompt );
		}
	}

	/**
	 * Generate response using Ollama
	 */
	private function generateOllamaResponse( $prompt ) {
		$data = json_encode( [ 
			"model" => self::$llmModel, 
			"prompt" => $prompt, 
			"stream" => false,
			"options" => [
				"temperature" => self::$temperature,
				"num_predict" => self::$maxTokens
			]
		] );
		
		$ch = curl_init( self::$llmApiEndpoint . "generate" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->msg( 'wanda-api-error-fallback' )->text();
		}

		return $jsonResponse['response'] ?? $this->msg( 'wanda-api-error-fallback' )->text();
	}

	/**
	 * Generate response using OpenAI
	 */
	private function generateOpenAIResponse( $prompt ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-openai-key' )->text();
		}

		$data = json_encode( [
			"model" => self::$llmModel ?: "gpt-3.5-turbo",
			"messages" => [
				[ "role" => "user", "content" => $prompt ]
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
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->msg( 'wanda-api-error-fallback' )->text();
		}

		return $jsonResponse['choices'][0]['message']['content'] ?? $this->msg( 'wanda-api-error-fallback' )->text();
	}

	/**
	 * Generate response using Anthropic Claude
	 */
	private function generateAnthropicResponse( $prompt ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-anthropic-key' )->text();
		}

		$data = json_encode( [
			"model" => self::$llmModel ?: "claude-3-haiku-20240307",
			"messages" => [
				[ "role" => "user", "content" => $prompt ]
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
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->msg( 'wanda-api-error-fallback' )->text();
		}

		return $jsonResponse['content'][0]['text'] ?? $this->msg( 'wanda-api-error-fallback' )->text();
	}

	/**
	 * Generate response using Azure OpenAI
	 */
	private function generateAzureResponse( $prompt ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-azure-key' )->text();
		}

		$data = json_encode( [
			"messages" => [
				[ "role" => "user", "content" => $prompt ]
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
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->msg( 'wanda-api-error-fallback' )->text();
		}

		return $jsonResponse['choices'][0]['message']['content'] ?? $this->msg( 'wanda-api-error-fallback' )->text();
	}

	public function getAllowedParams() {
		return [ "message" => [ "type" => "string", "required" => true ] ];
	}
}
