<?php

namespace MediaWiki\Extension\Wanda\Hooks;

use File;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use WikiPage;

class PageIndexUpdater {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;
	/** @var string */
	private static $llmProvider;
	/** @var string */
	private static $llmApiKey;
	/** @var string */
	private static $llmApiEndpoint;
	/** @var int */
	private static $timeout;

	/**
	 * Initializes Elasticsearch settings from MediaWiki config.
	 */
	public static function initialize() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		self::$esHost = $config->get( 'WandaLLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = self::detectOrCreateElasticsearchIndex();
		self::$llmProvider = strtolower( $config->get( 'WandaLLMProvider' ) ?? 'ollama' );
		self::$llmApiKey = $config->get( 'WandaLLMApiKey' ) ?? '';
		self::$llmApiEndpoint = $config->get( 'WandaLLMApiEndpoint' ) ?? 'http://ollama:11434/api/';
		self::$timeout = $config->get( 'WandaLLMTimeout' ) ?? 30;

		if ( !self::$indexName ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found. Skipping indexing." );
		}
	}

	/**
	 * Detects or creates an Elasticsearch index dynamically.
	 */
	private static function detectOrCreateElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Chatbot', "Failed to retrieve Elasticsearch indices." );
			return self::createElasticsearchIndex();
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

		if ( !$selectedIndex ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found. Creating a new one." );
			return self::createElasticsearchIndex();
		}

		self::verifyIndexMapping( $selectedIndex );
		return $selectedIndex ?: null;
	}

	/**
	 * Creates a new Elasticsearch index if none exists.
	 */
	private static function createElasticsearchIndex() {
		$newIndex = "mediawiki_content_" . time();
		$mapping = [
			"mappings" => [
				"properties" => [
					"title" => [ "type" => "text" ],
					"content" => [ "type" => "text" ]
				]
			]
		];

		$ch = curl_init( self::$esHost . "/$newIndex" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "PUT" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $mapping ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		wfDebugLog( 'Chatbot', "Created new Elasticsearch index: $newIndex. Response: $response" );
		return $newIndex;
	}

	/**
	 * Verifies and updates the index mapping if needed.
	 */
	private static function verifyIndexMapping( $indexName ) {
		$ch = curl_init( self::$esHost . "/$indexName/_mapping" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$mapping = json_decode( $response, true );
	}

	/**
	 * Updates or adds a wiki page's content to Elasticsearch.
	 */
	public static function updateIndex( Title $title, WikiPage $wikiPage ) {
		self::initialize();
		if ( !self::$indexName ) {
			wfDebugLog( 'Chatbot', "Skipping indexing due to missing index." );
			return;
		}

		$content = $wikiPage->getContent();
		if ( !$content ) {
			wfDebugLog( 'Chatbot', "Skipping indexing for empty content: " . $title->getPrefixedText() );
			return;
		}

		$text = $content->getTextForSearchIndex( $content );
		$pdfText = self::extractTextFromPDF( $title );
		$fullText = trim( $text . "\n" . $pdfText );
		$document = [
			"title" => $title->getPrefixedText(),
			"content" => $fullText
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
	 * Extracts text from an attached PDF using pdftotext.
	 */
	private static function extractTextFromPDF( Title $title ) {
		$fileRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$file = $fileRepo->findFile( $title );

		if ( !$file || $file->getMimeType() !== 'application/pdf' ) {
			return '';
		}

		$pdfPath = $file->getLocalRefPath();
		if ( !$pdfPath || !file_exists( $pdfPath ) ) {
			return '';
		}

		$output = [];
		exec( "pdftotext -layout " . escapeshellarg( $pdfPath ) . " -", $output );

		return implode( "\n", $output );
	}

	/**
	 * Hooks to trigger indexing.
	 */
	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revision, $editResult ) {
		self::updateIndex( $wikiPage->getTitle(), $wikiPage );
	}

	public static function onUploadComplete( File $file ) {
		// The UploadComplete hook passes the File object when an upload finishes.
		// Maintain previous behavior: build a Title for the file and reindex it.
		$title = Title::makeTitleSafe( NS_FILE, $file->getTitle() );
		if ( !$title ) {
			return;
		}
		self::updateIndex( $title, new WikiPage( $title ) );
	}
}
