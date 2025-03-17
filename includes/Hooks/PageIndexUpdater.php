<?php

namespace MediaWiki\Extension\Chatbot\Hooks;

use File;
use MediaWiki\Hook\FileUploadCompleteHook;
use MediaWiki\Hook\PageContentSaveCompleteHook;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use Status;

class PageIndexUpdater implements PageContentSaveCompleteHook, FileUploadCompleteHook {

	private $apiEndpoint;
	private $embeddingModel;
	private $elasticSearchUrl;
	private $defaultIndex;
	private $chunkSize;

	public function __construct() {
		$this->apiEndpoint = ( $this->getConfig()->get( 'LLMApiEndpoint' ) ?? "http://ollama:11434/api/" ) . "embeddings/";
		$this->embeddingModel = $this->getConfig()->get( 'LLMOllamaEmbeddingModel' ) ?? "nomic-embed-text";
		$this->elasticSearchUrl = $this->getConfig()->get( 'LLMElasticsearchUrl' ) ?? "http://localhost:9200";
		$this->defaultIndex = $this->getConfig()->get( 'LLMElasticsearchIndex' ) ?? "wiki_embeddings";
		$this->chunkSize = $this->getConfig()->get( 'LLMEmbeddingChunkSize' ) ?? 8000;
	}

	/**
	 * Hook: Runs when a wiki page is edited or created
	 */
	public function onPageContentSaveComplete(
		WikiPage $wikiPage, RevisionRecord $revision, string $editSummary,
		int $flags, \UserIdentity $user, $content, Status $status
	) {
		$title = $wikiPage->getTitle()->getText();
		$text = ContentHandler::getContentText( $content );
		$indexName = $this->getDynamicIndexName();

		$this->processContent( $title, $text, $indexName );
	}

	/**
	 * Hook: Runs when a new file is uploaded
	 */
	public function onFileUploadComplete( File $file ) {
		$title = $file->getTitle()->getText();
		$text = $this->extractFileText( $file );
		$indexName = $this->getDynamicIndexName();

		if ( $text ) {
			$this->processContent( $title, $text, $indexName );
		} else {
			wfDebugLog( 'Chatbot', "Failed to extract text from file: $title" );
		}
	}

	/**
	 * Process content, generate embeddings, and index in Elasticsearch
	 */
	private function processContent( $title, $text, $indexName ) {
		$chunks = $this->splitIntoChunks( $text );
		foreach ( $chunks as $index => $chunk ) {
			$embedding = $this->generateEmbedding( $chunk );
			if ( !$embedding ) {
				wfDebugLog( 'Chatbot', "Failed to generate embedding for: $title (Chunk $index)" );
				continue;
			}
			$this->indexChunk( $title, $chunk, $embedding, $index, $indexName );
		}
	}

	/**
	 * Extracts text content from uploaded files (PDF, text files)
	 */
	private function extractFileText( File $file ) {
		$path = $file->getLocalRefPath();
		if ( !$path ) {
			return null;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, [ 'txt', 'csv', 'md' ] ) ) {
			return file_get_contents( $path );
		} elseif ( $ext === 'pdf' ) {
			$pdfExtract = shell_exec( command: "pdftotext '$path' -" );
			if ( $pdfExtract ) {
				return $pdfExtract;
			} else {
				throw new MWException( $this->msg( 'wikai-chat-pdftotext-error' )->parse() );
			}
		}

		return null;
	}

	private function splitIntoChunks( $text ) {
		$words = explode( " ", $text );
		$chunks = [];
		$currentChunk = "";

		foreach ( $words as $word ) {
			if ( strlen( $currentChunk ) + strlen( $word ) < $this->chunkSize ) {
				$currentChunk .= " " . $word;
			} else {
				$chunks[] = trim( $currentChunk );
				$currentChunk = $word;
			}
		}

		if ( !empty( $currentChunk ) ) {
			$chunks[] = trim( $currentChunk );
		}

		return $chunks;
	}

	private function generateEmbedding( $text ) {
		$payload = [ "model" => $this->embeddingModel, "input" => $text ];
		$response = $this->sendPostRequest( $this->apiEndpoint, $payload );
		return $response['embedding'] ?? null;
	}

	private function indexChunk( $title, $chunk, $embedding, $chunkIndex, $indexName ) {
		$payload = [ "title" => $title, "chunk_index" => $chunkIndex, "text" => $chunk, "embedding" => $embedding ];
		$docId = urlencode( $title ) . "_chunk_" . $chunkIndex;
		$url = "{$this->elasticSearchUrl}/{$indexName}/_doc/$docId";
		$this->sendPutRequest( $url, $payload );
	}

	/**
	 * Retrieves the most relevant Elasticsearch index dynamically
	 */
	private function getDynamicIndexName() {
		$url = "{$this->elasticSearchUrl}/_cat/indices?v&format=json";
		$indices = $this->sendGetRequest( $url );

		if ( !is_array( $indices ) ) {
			return $this->defaultIndex;
		}

		foreach ( $indices as $index ) {
			if ( strpos( $index['index'], "mediawiki_content" ) !== false ) {
				return $index['index'];
			}
		}

		return $this->defaultIndex;
	}

	private function sendPostRequest( $url, $data ) {
		$opts = [
			'http' => [ 'method' => 'POST', 'header' => "Content-Type: application/json",
			'content' => json_encode( $data ), 'timeout' => 10 ]
		];
		return json_decode( file_get_contents( $url, false, stream_context_create( $opts ) ), true );
	}

	private function sendPutRequest( $url, $data ) {
		$opts = [
			'http' => [ 'method' => 'PUT', 'header' => "Content-Type: application/json",
			'content' => json_encode( $data ), 'timeout' => 10 ]
		];
		return json_decode( file_get_contents( $url, false, stream_context_create( $opts ) ), true );
	}

	private function sendGetRequest( $url ) {
		return json_decode( file_get_contents( $url ), true );
	}
}
