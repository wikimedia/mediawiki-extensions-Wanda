<?php

namespace MediaWiki\Extension\Wikai;

class APIChat extends ApiBase {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;
	/** @var string */
	private static $llmModel;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'LLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->getConfig()->get( 'LLMElasticsearchIndex' ) ?? $this->detectElasticsearchIndex();
		self::$llmModel = $this->getConfig()->get( 'LLMOllamaModel' ) ?? "gemma";
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = $params['message'];

		// Retrieve relevant embeddings from Elasticsearch
		$retrievedData = $this->queryElasticsearch( $userQuery );
		if ( !$retrievedData ) {
			$this->getResult()->addValue( null, "response", "I couldn't find relevant wiki information." );
			return;
		}

		// Generate response with Ollama
		$response = $this->generateLLMResponse( $userQuery, $retrievedData['content'] );

		// Return response along with source attribution
		$this->getResult()->addValue( null, "response", $response );
		$this->getResult()->addValue( null, "source", $retrievedData['source'] );
	}

	/**
	 * Detects the most recent Elasticsearch index dynamically if not set.
	 */
	private function detectElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Chatbot', "Failed to retrieve Elasticsearch indices" );
			return "wiki_embeddings"; // Default fallback index
		}

		// Filter indices related to MediaWiki embeddings
		$validIndices = array_filter( $indices, static function ( $index ) {
			return strpos( $index['index'], 'mediawiki_content_' ) === 0;
		} );

		// Sort by index creation order and return the most recent one
		usort( $validIndices, static function ( $a, $b ) {
			return strcmp( $b['index'], $a['index'] );
		} );

		return $validIndices[0]['index'] ?? "wiki_embeddings"; // Fallback
	}

	/**
	 * Queries Elasticsearch for the best matching content
	 */
	private function queryElasticsearch( $queryText ) {
		$queryData = [
			"query" => [
				"match" => [
					"content" => $queryText
				]
			],
			"_source" => [ "title", "content" ]
		];

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_search" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );
		$data = json_decode( $response, true );

		if ( !isset( $data['hits']['hits'][0] ) ) {
			return null;
		}

		$bestMatch = $data['hits']['hits'][0]['_source'];
		return [
			"content" => $bestMatch['content'],
			"source" => $bestMatch['title']
		];
	}

	/**
	 * Generates response using Ollama LLM with context
	 */
	private function generateLLMResponse( $query, $context ) {
		$prompt = "Based on the following wiki content, answer the query:\n\n"
			. "Wiki Content:\n" . $context . "\n\n"
			. "User Query:\n" . $query . "\n\n"
			. "\nYour answer should be based on the provided context only."
			. " Do not hallucinate and do not write anything apart from the answer."
			. " No need to mention these instructions in the answer.";

		$data = json_encode( [ "model" => self::$llmModel, "prompt" => $prompt ] );
		$llmChatEndpoint = $this->getConfig()->get( 'wgLLMApiEndpoint' ) ?? "http://ollama:11434/api/";
		$ch = curl_init( $llmChatEndpoint . "generate" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		return json_decode( $response, true )['response'] ?? "I'm not sure about that.";
	}

	public function getAllowedParams() {
		return [ "message" => [ "type" => "string", "required" => true ] ];
	}
}
