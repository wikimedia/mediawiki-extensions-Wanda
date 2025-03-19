<?php

namespace MediaWiki\Extension\Wikai\Hooks;

use MediaWiki\MediaWikiServices;
use Title;
use WikiPage;

class PageIndexUpdater {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;

	/**
	 * Initializes Elasticsearch settings from MediaWiki config.
	 */
	public static function initialize() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		self::$esHost = $config->get( 'LLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = self::detectElasticsearchIndex();

		if ( !self::$indexName ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found. Creating a new one..." );
			self::$indexName = self::createElasticsearchIndex();
		}
	}

	/**
	 * Detects the most recent Elasticsearch index dynamically.
	 */
	private static function detectElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Chatbot', "Failed to retrieve Elasticsearch indices." );
			return null;
		}

		$validIndices = array_filter( $indices, static function ( $index ) {
			return strpos( $index['index'], 'mediawiki_content_' ) === 0;
		} );

		usort( $validIndices, static function ( $a, $b ) {
			return strcmp( $b['index'], $a['index'] );
		} );

		return $validIndices[0]['index'] ?? null;
	}

	/**
	 * Ensures the Elasticsearch index exists, creating it if necessary.
	 */
	private static function createElasticsearchIndex() {
		$indexName = "mediawiki_content";
		$ch = curl_init( self::$esHost . "/" . $indexName );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_NOBODY, true ); // Check if index exists
		curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $httpCode == 404 ) {
			wfDebugLog( 'Chatbot', "Index '$indexName' does not exist. Creating..." );
			self::createIndexMapping( $indexName );
		} else {
			wfDebugLog( 'Chatbot', "Index '$indexName' already exists." );
		}

		return $indexName;
	}

	/**
	 * Creates an Elasticsearch index with `dense_vector` mapping.
	 */
	private static function createIndexMapping( $indexName ) {
		$mapping = [
			"mappings" => [
				"properties" => [
					"title" => [ "type" => "text" ],
					"content" => [ "type" => "text" ],
					"embedding" => [
						"type" => "dense_vector",
						"dims" => 768,
						"index" => true,
						"similarity" => "cosine"
					]
				]
			]
		];

		$ch = curl_init( self::$esHost . "/" . $indexName );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "PUT" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $mapping ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		wfDebugLog( 'Chatbot', "Index '$indexName' created with mapping: " . $response );
	}

	/**
	 * Updates or adds a wiki page's content to Elasticsearch.
	 */
	public static function updateIndex( Title $title, WikiPage $wikiPage ) {
		if ( !self::$indexName ) {
			wfDebugLog( 'Chatbot', "Skipping indexing due to missing index." );
			return;
		}

		$content = $wikiPage->getContent();
		if ( !$content ) {
			wfDebugLog( 'Chatbot', "Skipping indexing for empty content: " . $title->getPrefixedText() );
			return;
		}

		$contentHandler = $content->getContentHandler();
		$text = $contentHandler::getContentText( $content );
		$pdfText = self::extractTextFromPDF( $title );
		$fullText = trim( $text . "\n" . $pdfText );

		$embedding = self::generateEmbedding( $fullText )[ 'embeddings' ] ?? null;
		if ( !$embedding ) {
			wfDebugLog( 'Chatbot', "Failed to generate embedding for: " . $title->getPrefixedText() );
			return;
		}

		$document = [
			"title" => $title->getPrefixedText(),
			"content" => $fullText,
			"embedding" => array_map( 'floatval', $embedding )
		];

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_doc/" . urlencode( $title->getPrefixedText() ) );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $document ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		wfDebugLog( 'Chatbot', "Indexed page: " . $title->getPrefixedText() . " Response: " . $response );
	}

	/**
	 * Generates an embedding for the text using Ollama API.
	 */
	private static function generateEmbedding( $text ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$embeddingModel = $config->get( 'LLMOllamaEmbeddingModel' ) ?? "nomic-embed-text";
		$embeddingEndpoint = $config->get( 'LLMApiEndpoint' ) . "embed";

		$payload = [ "model" => $embeddingModel, "input" => $text ];

		$ch = curl_init( $embeddingEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		return json_decode( $response, true ) ?? null;
	}

	/**
	 * Extracts text from a PDF file using pdftotext.
	 */
	private static function extractTextFromPDF( Title $title ) {
		$fileRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$file = $fileRepo->findFile( $title );

		if ( !$file || $file->getMimeType() !== 'application/pdf' ) {
			return '';
		}

		$pdfPath = $file->getLocalRefPath();
		$output = [];

		if ( $pdfPath && file_exists( $pdfPath ) ) {
			$cmd = "pdftotext -layout " . escapeshellarg( $pdfPath ) . " -";
			exec( $cmd, $output );
		}

		return implode( "\n", $output );
	}
}
