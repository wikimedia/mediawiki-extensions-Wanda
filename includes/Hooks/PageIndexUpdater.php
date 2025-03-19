<?php

namespace MediaWiki\Extension\Wikai\Hooks;

use File;
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
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found. Skipping indexing." );
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
			return null; // No default fallback
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

		if ( !$selectedIndex ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found." );
		}

		return $selectedIndex;
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

		$embedding = self::generateEmbedding( $fullText )[ 'embeddings' ];
		if ( !$embedding ) {
			wfDebugLog( 'Chatbot', "Failed to generate embedding for: " . $title->getPrefixedText() );
			return;
		}

		$document = [
			"title" => $title->getPrefixedText(),
			"content" => $fullText,
			"embedding" => $embedding
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
	 * Generate an embedding for the text using Ollama API.
	 */
	private static function generateEmbedding( $text ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$embeddingModel = $config->get( 'LLMOllamaEmbeddingModel' ) ?? "nomic-embed-text"; // Use embedding model
		$embeddingEndpoint = $config->get( 'LLMApiEndpoint' ) . "embed";

		$payload = [ "model" => $embeddingModel, "input" => $text ];

		$ch = curl_init( $embeddingEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Chatbot', "JSON Decode Error: " . json_last_error_msg() );
		}

		return $jsonResponse ?? null;
	}

	/**
	 * Extracts text from an attached PDF using pdftotext.
	 */
	private static function extractTextFromPDF( Title $title ) {
		$fileRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$file = $fileRepo->findFile( $title );

		if ( !$file || $file->getMimeType() !== 'application/pdf' ) {
			return ''; // No PDF found
		}

		$pdfPath = $file->getLocalRefPath();
		$output = [];

		if ( $pdfPath && file_exists( $pdfPath ) ) {
			$cmd = "pdftotext -layout " . escapeshellarg( $pdfPath ) . " -";
			exec( $cmd, $output );
		}

		return implode( "\n", $output );
	}

	/**
	 * Hook: Handles page save to trigger indexing.
	 */
	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revision, $editResult ) {
		self::initialize();
		self::updateIndex( $wikiPage->getTitle(), $wikiPage );
	}

	/**
	 * Hook: Handles file upload to extract PDF text and index it.
	 */
	public static function onFileUploadComplete( File $file ) {
		self::initialize();

		$title = Title::makeTitleSafe( NS_FILE, $file->getTitle() );
		if ( !$title ) {
			return;
		}

		$wikiPage = new WikiPage( $title );
		self::updateIndex( $title, $wikiPage );
	}
}
