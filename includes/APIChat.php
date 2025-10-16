<?php

namespace MediaWiki\Extension\Wanda;

use ApiBase;
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

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'WandaLLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->detectElasticsearchIndex();
		self::$llmProvider = strtolower( $this->getConfig()->get( 'WandaLLMProvider' ) ?? "ollama" );
		self::$llmModel = $this->getConfig()->get( 'WandaLLMModel' ) ?? "gemma:2b";
		self::$llmApiKey = $this->getConfig()->get( 'WandaLLMApiKey' ) ?? "";
		self::$llmApiEndpoint = $this->getConfig()->get( 'WandaLLMApiEndpoint' ) ?? "http://ollama:11434/api/";
		self::$maxTokens = $this->getConfig()->get( 'WandaLLMMaxTokens' ) ?? 1000;
		self::$temperature = $this->getConfig()->get( 'WandaLLMTemperature' ) ?? 0.7;
		self::$timeout = $this->getConfig()->get( 'WandaLLMTimeout' ) ?? 30;
		self::$customPromptTitle = $this->getConfig()->get( 'WandaCustomPromptTitle' ) ?? "";
		self::$customPrompt = $this->getConfig()->get( 'WandaCustomPrompt' ) ?? "";
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = trim( $params['message'] );
		$allowPublicKnowledge = !empty( $params['usepublicknowledge'] );

		// If caller provided a temperature override, parse and validate it
		if ( isset( $params['temperature'] ) ) {
			self::$temperature = $this->parseTemperature( $params['temperature'] );
		} else {
			// Ensure configured temperature is a valid float within range
			self::$temperature = $this->parseTemperature( self::$temperature );
		}

		if ( isset( $params['customprompt'] ) ) {
			self::$customPrompt = trim( $params['customprompt'] );
		}

		if ( isset( $params['customprompttitle'] ) ) {
			self::$customPromptTitle = trim( $params['customprompttitle'] );
		}

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

		// Check for Elasticsearch index unless we're allowed to fall back to public knowledge
		$index = $this->detectElasticsearchIndex();
		if ( !$index ) {
			if ( !$allowPublicKnowledge ) {
				$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-no-index' )->text() );
				return;
			}
		}

		// Search for context when possible
		$searchResults = $index ? $this->queryElasticsearch( $userQuery ) : null;
		if ( empty( $searchResults ) && !$allowPublicKnowledge ) {
			$this->getResult()->addValue( null, "response", $this->msg( 'wanda-api-error-no-results' )->text() );
			return;
		}

		// Build context string from results (single best match currently)
		$contextStr = $searchResults && is_array( $searchResults ) && isset( $searchResults['content'] )
			? $searchResults['content']
			: ( $searchResults ? (string)$searchResults : null );

		$response = $this->generateLLMResponse( $userQuery, $contextStr, $allowPublicKnowledge );
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

		// Filter indices related to Wanda content
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
		return $this->textSearch( $queryText );
	}

	/**
	 * Text-based search
	 */
	private function textSearch( $queryText ) {
		$queryData = [
			"query" => [
				"multi_match" => [
					"query" => $queryText,
					"fields" => [ "title^2", "content" ],
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
	 * Generate response using Google Gemini (Generative Language API)
	 */
	private function generateGeminiResponse( $prompt ) {
		if ( empty( self::$llmApiKey ) ) {
			return $this->msg( 'wanda-api-error-gemini-key' )->text();
		}

		$model = self::$llmModel ?: 'gemini-1.5-flash';
		$base = rtrim( self::$llmApiEndpoint ?: 'https://generativelanguage.googleapis.com/v1', '/' );
		$url = $base . '/models/' . rawurlencode( $model ) . ':generateContent?key=' . urlencode( self::$llmApiKey );
		$payload = [
			'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ],
			'generationConfig' => [
				'temperature' => self::$temperature,
				'maxOutputTokens' => self::$maxTokens
			]
		];

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, self::$timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );

		$response = curl_exec( $ch );
		curl_close( $ch );
		$json = json_decode( $response, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->msg( 'wanda-api-error-fallback' )->text();
		}
		if ( isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return $json['candidates'][0]['content']['parts'][0]['text'];
		}
		return $this->msg( 'wanda-api-error-fallback' )->text();
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

	/**
	 * Driver function to generate an LLM response given a user query and retrieved context.
	 *
	 * @param string $userQuery The original user question
	 * @param string|null $context Retrieved wiki content used as grounding context
	 * @return string|false LLM answer text or false on complete failure
	 */
	private function generateLLMResponse( $userQuery, $context, $allowPublicKnowledge = false ) {
		// Basic guards
		if ( !$userQuery ) {
			return false;
		}

		// Prepare (truncate) context to avoid excessively large prompts.
		$context = trim( (string)$context );
		if ( $context === '' ) {
			$contextBlock = "(No additional context from the knowledge base was found.)";
		} else {
			// Rough character cap – MediaWiki pages can be large; keep prompt manageable.
			// Heuristic
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
			// Prompt template – wiki-only vs wiki+public modes
			if ( $allowPublicKnowledge ) {
				$prompt = "You are a helpful assistant answering questions for a MediaWiki site.\n" .
					"Prefer using the provided wiki context when possible. " .
					"If the context is missing or insufficient, " .
					"you may also rely on your general public knowledge to answer accurately.\n" .
					"If using public knowledge due to missing wiki details, do NOT fabricate citations.\n" .
					"Output <PUBLIC_KNOWLEDGE> in the response if your answer is based on general knowledge " .
					"rather than the provided context.\n" .
					"No need to mention if the current context cannot answer the question.\n" .
					"Context (may be empty):\n" . $contextBlock . "\n\n" .
					"User Question: " . $userQuery . "\n\n" .
					"Answer:";
			} else {
				$prompt = "You are an assistant helping answer questions about this MediaWiki instance.\n" .
					"Use ONLY the provided context to answer. If the answer is not contained in the context, " .
					"output exactly the single token: NO_MATCHING_CONTEXT (no other text).\n" .
					"Cite the source title(s) mentioned in the context if relevant.\n\n" .
					"Context:\n" . $contextBlock . "\n\n" .
					"User Question: " . $userQuery . "\n\n" .
					"Answer:";
			}
		}

		$response = null;
		switch ( self::$llmProvider ) {
			case 'ollama':
				$response = $this->generateOllamaResponse( $prompt );
				break;
			case 'openai':
				$response = $this->generateOpenAIResponse( $prompt );
				break;
			case 'anthropic':
				$response = $this->generateAnthropicResponse( $prompt );
				break;
			case 'azure':
				$response = $this->generateAzureResponse( $prompt );
				break;
			case 'gemini':
				$response = $this->generateGeminiResponse( $prompt );
				break;
			default:
				// Unknown provider (should have been validated earlier)
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
			"message" => null,
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
			"usepublicknowledge" => false
		];
	}
}
