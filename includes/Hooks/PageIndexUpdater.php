<?php

namespace MediaWiki\Extension\Wanda\Hooks;

use ApprovedRevs;
use Content;
use DeferredUpdates;
use ExtensionRegistry;
use IDBAccessObject;
use MediaWiki\Extension\Wanda\EmbeddingGenerator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use UploadBase;
use WikiPage;

class PageIndexUpdater {
	/** @var string */
	private static $searchHost;
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
	private static $wgproxy;
	/** @var string */
	private static $llmApiEndpoint;
	/** @var int */
	private static $timeout;
	/** @var bool[] */
	private static $pendingTitles = [];

	/**
	 * Initializes LLM and search engine settings from MediaWiki config.
	 */
	public static function initialize() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		self::$searchHost = $config->get( 'WandaSearchEngineUrl' ) ?? "http://localhost:9200";

		self::$llmProvider = strtolower( $config->get( 'WandaLLMProvider' ) ?? 'ollama' );
		self::$llmModel = $config->get( 'WandaLLMModel' ) ?? 'gemma:2b';
		self::$llmEmbeddingModel = $config->get( 'WandaLLMEmbeddingModel' ) ?? self::$llmModel;
		self::$llmApiKey = $config->get( 'WandaLLMApiKey' ) ?? '';
		self::$wgproxy = $config->get( 'HTTPProxy' ) ?? "";
		self::$llmApiEndpoint = $config->get( 'WandaLLMApiEndpoint' ) ?? 'http://ollama:11434/api/';
		self::$timeout = $config->get( 'WandaLLMTimeout' ) ?? 30;

		self::$indexName = self::detectOrCreateSearchEngineIndex();

		if ( !self::$indexName ) {
			wfDebugLog( 'Wanda', "No valid search engine index found. Skipping indexing." );
		}
	}

	/**
	 * Detects or creates a search engine index dynamically.
	 */
	private static function detectOrCreateSearchEngineIndex() {
		$ch = curl_init( self::$searchHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Wanda', "Failed to retrieve search engine indices." );
			return self::createSearchEngineIndex();
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
			wfDebugLog( 'Wanda', "No valid search engine index found. Creating a new one." );
			return self::createSearchEngineIndex();
		}

		self::verifyIndexMapping( $selectedIndex );
		return $selectedIndex ?: null;
	}

	/**
	 * Creates a new search engine index if none exists.
	 */
	private static function createSearchEngineIndex() {
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

		$ch = curl_init( self::$searchHost . "/$newIndex" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "PUT" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $mapping ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );

		wfDebugLog(
			'Wanda',
			"Created new search engine index: $newIndex with embedding dimensions: $dimensions. Response: $response"
		);
		return $newIndex;
	}

	/**
	 * Verifies and updates the index mapping if needed.
	 */
	private static function verifyIndexMapping( $indexName ) {
		$ch = curl_init( self::$searchHost . "/$indexName/_mapping" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );

		$mapping = json_decode( $response, true );
	}

	/**
	 * Updates or adds a wiki page's content to the search engine.
	 */
	public static function updateIndex( Title $title, WikiPage $wikiPage ) {
		self::initialize();
		if ( !self::$indexName ) {
			wfDebugLog( 'Wanda', "Skipping indexing due to missing index." );
			return;
		}

		$content = self::resolveIndexableContent( $title, $wikiPage );
		if ( !$content ) {
			wfDebugLog( 'Wanda', "No indexable content, removing from index: " . $title->getPrefixedText() );
			self::deleteDocument( $title );
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
			self::$timeout,
			self::$wgproxy
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

		$ch = curl_init( self::$searchHost . "/" . self::$indexName . "/_doc/" .
			urlencode( $title->getPrefixedText() ) );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $document ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );

		wfDebugLog( 'Wanda', "Indexed page: " . $title->getPrefixedText() . " Response: " . $response );
	}

	/**
	 * Returns the content that should be indexed for a page, or null if the page
	 * should not be in the index.
	 */
	public static function resolveIndexableContent( Title $title, WikiPage $wikiPage ): ?Content {
		if ( !self::usesApprovedRevisions( $title ) ) {
			return $wikiPage->getContent();
		}

		$revId = ApprovedRevs::getApprovedRevID( $title );
		if ( !$revId ) {
			return null;
		}

		$revision = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $revId );
		return $revision ? $revision->getContent( SlotRecord::MAIN ) : null;
	}

	private static function usesApprovedRevisions( Title $title ): bool {
		return self::isApprovedOnlyEnabled() && ApprovedRevs::pageIsApprovable( $title );
	}

	private static function isApprovedOnlyEnabled(): bool {
		return MediaWikiServices::getInstance()->getMainConfig()->get( 'WandaIndexApprovedOnly' )
			&& ExtensionRegistry::getInstance()->isLoaded( 'ApprovedRevs' );
	}

	/**
	 * Removes a page from the Elasticsearch index.
	 */
	public static function deleteFromIndex( Title $title ) {
		self::initialize();
		if ( !self::$indexName ) {
			return;
		}
		self::deleteDocument( $title );
	}

	private static function deleteDocument( Title $title ) {
		$ch = curl_init( self::$searchHost . "/" . self::$indexName . "/_doc/" .
			urlencode( $title->getPrefixedText() ) );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "DELETE" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $ch );

		wfDebugLog( 'Wanda', "Removed page from index: " . $title->getPrefixedText() . " Response: " . $response );
	}

	/**
	 * Reindexes a page once the current request has finished its updates.
	 */
	private static function scheduleReindex( Title $title ) {
		$key = $title->getPrefixedDBkey();
		if ( isset( self::$pendingTitles[$key] ) ) {
			return;
		}
		self::$pendingTitles[$key] = true;

		DeferredUpdates::addCallableUpdate( static function () use ( $title, $key ) {
			unset( self::$pendingTitles[$key] );
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			$wikiPage->loadPageData( IDBAccessObject::READ_LATEST );
			self::updateIndex( $title, $wikiPage );
		} );
	}

	private static function scheduleRemoval( Title $title ) {
		DeferredUpdates::addCallableUpdate( static function () use ( $title ) {
			self::deleteFromIndex( $title );
		} );
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
			self::$timeout,
			self::$wgproxy
		);
	}

	/**
	 * Hooks to trigger indexing.
	 */
	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revision, $editResult ) {
		self::scheduleReindex( $wikiPage->getTitle() );
	}

	public static function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		self::scheduleRemoval( Title::castFromPageIdentity( $page ) );
	}

	public static function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		self::scheduleRemoval( Title::newFromLinkTarget( $old ) );
		self::scheduleReindex( Title::newFromLinkTarget( $new ) );
	}

	public static function onApprovedRevsRevisionApproved( $output, $title, $revId, $content ) {
		if ( self::isApprovedOnlyEnabled() ) {
			self::scheduleReindex( Title::castFromPageIdentity( $title ) );
		}
	}

	public static function onApprovedRevsRevisionUnapproved( $output, $title, $content ) {
		if ( self::isApprovedOnlyEnabled() ) {
			self::scheduleReindex( Title::castFromPageIdentity( $title ) );
		}
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
