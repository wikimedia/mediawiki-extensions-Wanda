<?php

namespace MediaWiki\Extension\Wanda\Hooks;

use MediaWiki\Extension\Wanda\EmbeddingGenerator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use UploadBase;
use WikiPage;

class PageIndexUpdater {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;
	/** @var string */
	private static $llmModel;
	/** @var string */
	private static $llmEmbeddingModel;
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

		self::$llmProvider = strtolower( $config->get( 'WandaLLMProvider' ) ?? 'ollama' );
		self::$llmModel = $config->get( 'WandaLLMModel' ) ?? 'gemma:2b';
		self::$llmEmbeddingModel = $config->get( 'WandaLLMEmbeddingModel' ) ?? self::$llmModel;
		self::$llmApiKey = $config->get( 'WandaLLMApiKey' ) ?? '';
		self::$llmApiEndpoint = $config->get( 'WandaLLMApiEndpoint' ) ?? 'http://ollama:11434/api/';
		self::$timeout = $config->get( 'WandaLLMTimeout' ) ?? 30;

		self::$indexName = self::detectOrCreateElasticsearchIndex();

		if ( !self::$indexName ) {
			wfDebugLog( 'Wanda', "No valid Elasticsearch index found. Skipping indexing." );
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
			wfDebugLog( 'Wanda', "Failed to retrieve Elasticsearch indices." );
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
			wfDebugLog( 'Wanda', "No valid Elasticsearch index found. Creating a new one." );
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

		wfDebugLog( 'Wanda', "Creating index with provider: " . self::$llmProvider );

		$dimensions = EmbeddingGenerator::getDimensions( self::$llmProvider );
		wfDebugLog( 'Wanda', "Using embedding dimensions: $dimensions for provider: " . self::$llmProvider );

		$mapping = [
			"mappings" => [
				"properties" => [
					"title" => [ "type" => "text" ],
					"content" => [ "type" => "text" ],
					"content_chunks" => [ "type" => "text" ],
					"content_vectors" => [
						"type" => "nested",
						"properties" => [
							"vector" => [
								"type" => "dense_vector",
								"dims" => $dimensions,
								"index" => true,
								"similarity" => "cosine"
							],
							"chunk_index" => [ "type" => "integer" ]
						]
					]
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

		wfDebugLog(
			'Wanda',
			"Created new Elasticsearch index: $newIndex with embedding dimensions: $dimensions. Response: $response"
		);
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
			wfDebugLog( 'Wanda', "Skipping indexing due to missing index." );
			return;
		}

		$content = $wikiPage->getContent();
		if ( !$content ) {
			wfDebugLog( 'Wanda', "Skipping indexing for empty content: " . $title->getPrefixedText() );
			return;
		}

		$text = $content->getTextForSearchIndex( $content );
		$pdfText = self::extractTextFromPDF( $title );
		$fullText = trim( $text . "\n" . $pdfText );

		// Chunk the text semantically
		$chunks = EmbeddingGenerator::chunkText( $fullText, 5000 );
		$logMsg = "[WANDA INDEXING] Split " . $title->getPrefixedText() . " into " . count( $chunks ) . " chunks";
		wfDebugLog( 'Wanda', $logMsg );

		// Generate embeddings for each chunk
		$embeddings = EmbeddingGenerator::generateBatch(
			$chunks,
			self::$llmProvider,
			self::$llmApiKey,
			self::$llmApiEndpoint,
			self::$llmEmbeddingModel,
			self::$timeout
		);

		$document = [
			"title" => $title->getPrefixedText(),
			"content" => $fullText,
			"content_chunks" => $chunks
		];

		if ( !empty( $embeddings ) ) {
			// Format embeddings for nested structure
			$vectorObjects = [];
			foreach ( $embeddings as $index => $embedding ) {
				$vectorObjects[] = [
					"vector" => $embedding,
					"chunk_index" => $index
				];
			}
			$document['content_vectors'] = $vectorObjects;
			wfDebugLog(
				'Wanda',
				"Generated " . count( $embeddings ) . " embeddings for: " . $title->getPrefixedText()
			);
		} else {
			wfDebugLog(
				'Wanda',
				"Failed to generate embeddings for: " . $title->getPrefixedText()
			);
		}

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_doc/" . urlencode( $title->getPrefixedText() ) );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $document ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		wfDebugLog( 'Wanda', "Indexed page: " . $title->getPrefixedText() . " Response: " . $response );
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
	 * Generate embedding vector for text using configured provider
	 */
	private static function generateEmbedding( $text ) {
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
	 * Hooks to trigger indexing.
	 */
	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revision, $editResult ) {
		self::updateIndex( $wikiPage->getTitle(), $wikiPage );
	}

	/**
	 * Hook to index files upon upload completion.
	 *
	 * @param UploadBase $uploadBase The uploaded file object.
	 */
	public static function onUploadComplete( UploadBase $uploadBase ) {
		// The UploadComplete hook passes the File object when an upload finishes.
		// Maintain previous behavior: build a Title for the file and reindex it.
		$title = Title::makeTitleSafe( NS_FILE, $uploadBase->getTitle() );
		if ( !$title ) {
			return;
		}
		self::updateIndex( $title, new WikiPage( $title ) );
	}
}
