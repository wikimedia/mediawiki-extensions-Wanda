<?php

namespace MediaWiki\Extension\Wanda;

class EmbeddingGenerator {

	/**
	 * Generate embeddings using the specified provider
	 *
	 * @param string $text Text to generate embeddings for
	 * @param string $provider LLM provider (openai, ollama, gemini, azure)
	 * @param string $apiKey API key for the provider
	 * @param string $apiEndpoint API endpoint URL
	 * @param string $model Model name to use
	 * @param int $timeout Request timeout in seconds
	 * @param string $wgproxy optional proxy server
	 * @return array|null Embedding vector or null on failure
	 */
	public static function generate( $text, $provider, $apiKey, $apiEndpoint, $model, $timeout, $wgproxy ) {
		$client = new LLMClient(
			(string)$provider,
			(string)$model,
			(string)$apiKey,
			(string)$apiEndpoint,
			(int)$timeout,
			(string)$wgproxy
		);
		return $client->embed( $text );
	}

	/**
	 * Get embedding dimensions for a provider
	 *
	 * @param string $provider LLM provider name
	 * @return int Dimension size
	 */
	public static function getDimensions( $provider ) {
		switch ( $provider ) {
			case 'openai':
			case 'azure':
				return 1536;
			case 'gemini':
				return 768;
			case 'ollama':
				return 1024;
			default:
				return 1536;
		}
	}

	/**
	 * Chunk text into semantically coherent pieces based on MediaWiki structure
	 *
	 * @param string $text Full wiki text content
	 * @param int $maxChunkSize Maximum characters per chunk (default: 5000)
	 * @return array Array of text chunks
	 */
	public static function chunkText( $text, $maxChunkSize = 5000 ) {
		$chunks = [];

		// First, try to split by MediaWiki section headings
		// (==, ===, etc.)
		$sections = preg_split(
			'/(^|\n)(={2,})\s*(.+?)\s*\2(\n|$)/m',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( count( $sections ) > 1 ) {
			// We have sections, process them
			$currentChunk = '';
			for ( $i = 0; $i < count( $sections ); $i++ ) {
				$part = $sections[$i];

				// If this part with current chunk exceeds limit, save current and start new
				if ( strlen( $currentChunk . $part ) > $maxChunkSize && !empty( $currentChunk ) ) {
					// Before saving, check if currentChunk itself is too large
					if ( strlen( $currentChunk ) > $maxChunkSize ) {
						$chunks = array_merge( $chunks, self::subdivideChunk( $currentChunk, $maxChunkSize ) );
					} else {
						$chunks[] = trim( $currentChunk );
					}
					$currentChunk = $part;
				} else {
					$currentChunk .= $part;
				}
			}

			// Add remaining content
			if ( !empty( $currentChunk ) ) {
				if ( strlen( $currentChunk ) > $maxChunkSize ) {
					$chunks = array_merge( $chunks, self::subdivideChunk( $currentChunk, $maxChunkSize ) );
				} else {
					$chunks[] = trim( $currentChunk );
				}
			}
		} else {
			// No sections found, just subdivide the whole text
			$chunks = self::subdivideChunk( $text, $maxChunkSize );
		}

		// Remove empty chunks
		$chunks = array_filter( $chunks, static function ( $chunk ) {
			return !empty( trim( $chunk ) );
		} );

		return array_values( $chunks );
	}

	/**
	 * Subdivide a large chunk into smaller pieces
	 * by paragraphs, then sentences
	 *
	 * @param string $text Text to subdivide
	 * @param int $maxSize Maximum size per chunk
	 * @return array Array of subdivided chunks
	 */
	private static function subdivideChunk( $text, $maxSize ) {
		if ( strlen( $text ) <= $maxSize ) {
			return [ $text ];
		}

		$chunks = [];

		// First try splitting by paragraphs (double newlines)
		$paragraphs = preg_split( '/\n\s*\n/', $text );

		$currentChunk = '';
		foreach ( $paragraphs as $para ) {
			if ( strlen( $currentChunk . "\n\n" . $para ) > $maxSize && !empty( $currentChunk ) ) {
				$chunks[] = trim( $currentChunk );
				$currentChunk = $para;
			} else {
				$currentChunk .= ( empty( $currentChunk ) ? '' : "\n\n" ) . $para;
			}

			// If single paragraph exceeds limit, split by sentences
			if ( strlen( $currentChunk ) > $maxSize ) {
				$sentences = preg_split( '/([.!?]+\s+)/', $currentChunk, -1, PREG_SPLIT_DELIM_CAPTURE );
				$sentenceChunk = '';

				for ( $i = 0; $i < count( $sentences ); $i++ ) {
					if ( strlen( $sentenceChunk . $sentences[$i] ) > $maxSize && !empty( $sentenceChunk ) ) {
						$chunks[] = trim( $sentenceChunk );
						$sentenceChunk = $sentences[$i];
					} else {
						$sentenceChunk .= $sentences[$i];
					}
				}

				$currentChunk = $sentenceChunk;
			}
		}

		// Add remaining content
		if ( !empty( $currentChunk ) ) {
			// If still too large, hard split as last resort
			if ( strlen( $currentChunk ) > $maxSize ) {
				$hardChunks = str_split( $currentChunk, $maxSize );
				$chunks = array_merge( $chunks, $hardChunks );
			} else {
				$chunks[] = trim( $currentChunk );
			}
		}

		return $chunks;
	}

	/**
	 * Generate embeddings for multiple text chunks
	 *
	 * @param array $chunks Array of text chunks
	 * @param string $provider LLM provider
	 * @param string $apiKey API key
	 * @param string $apiEndpoint API endpoint
	 * @param string $model Model name
	 * @param int $timeout Timeout in seconds
	 * @param string $wgproxy optional proxy server
	 * @return array Array of embedding vectors
	 */
	public static function generateBatch(
		$chunks,
		$provider,
		$apiKey,
		$apiEndpoint,
		$model,
		$timeout,
		$wgproxy
	) {
		$embeddings = [];

		foreach ( $chunks as $chunk ) {
			$embedding = self::generate(
				$chunk,
				$provider,
				$apiKey,
				$apiEndpoint,
				$model,
				$timeout,
				$wgproxy
			);
			if ( $embedding !== null ) {
				$embeddings[] = $embedding;
			}
		}

		return $embeddings;
	}
}
