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
	 * @return array|null Embedding vector or null on failure
	 */
	public static function generate( $text, $provider, $apiKey, $apiEndpoint, $model, $timeout ) {
		switch ( $provider ) {
			case 'openai':
				return self::generateOpenAIEmbedding( $text, $apiKey, $apiEndpoint, $model, $timeout );
			case 'ollama':
				return self::generateOllamaEmbedding( $text, $apiEndpoint, $model, $timeout );
			case 'gemini':
				return self::generateGeminiEmbedding( $text, $apiKey, $model, $timeout );
			case 'azure':
				return self::generateAzureEmbedding( $text, $apiKey, $apiEndpoint, $model, $timeout );
			default:
				wfDebugLog( 'Wanda', "Unknown embedding provider: $provider" );
				return null;
		}
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
	 * @return array Array of embedding vectors
	 */
	public static function generateBatch(
		$chunks,
		$provider,
		$apiKey,
		$apiEndpoint,
		$model,
		$timeout
	) {
		$embeddings = [];

		foreach ( $chunks as $chunk ) {
			$embedding = self::generate(
				$chunk,
				$provider,
				$apiKey,
				$apiEndpoint,
				$model,
				$timeout
			);
			if ( $embedding !== null ) {
				$embeddings[] = $embedding;
			}
		}

		return $embeddings;
	}

	/**
	 * Generate OpenAI embeddings
	 */
	private static function generateOpenAIEmbedding( $text, $apiKey, $apiEndpoint, $model, $timeout ) {
		$url = rtrim( $apiEndpoint, '/' ) . '/embeddings';
		$data = [
			'input' => $text,
			'model' => $model
		];

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $apiKey
		] );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			wfDebugLog( 'Wanda', 'OpenAI embedding error: ' . curl_error( $ch ) );
			curl_close( $ch );
			return null;
		}
		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "OpenAI embedding HTTP error: $httpCode - $response" );
			return null;
		}

		$result = json_decode( $response, true );
		if ( isset( $result['data'][0]['embedding'] ) ) {
			return $result['data'][0]['embedding'];
		}

		wfDebugLog( 'Wanda', 'OpenAI embedding missing data: ' . $response );
		return null;
	}

	/**
	 * Generate Ollama embeddings
	 */
	private static function generateOllamaEmbedding( $text, $apiEndpoint, $model, $timeout ) {
		$url = rtrim( $apiEndpoint, '/' ) . '/embed';
		$data = [
			'model' => $model,
			'input' => $text
		];

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			wfDebugLog( 'Wanda', 'Ollama embedding error: ' . curl_error( $ch ) );
			curl_close( $ch );
			return null;
		}
		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Ollama embedding HTTP error: $httpCode - $response" );
			return null;
		}

		$result = json_decode( $response, true );
		if ( isset( $result['embeddings'][0] ) ) {
			return $result['embeddings'][0];
		}

		wfDebugLog( 'Wanda', 'Ollama embedding missing data: ' . $response );
		return null;
	}

	/**
	 * Generate Gemini embeddings
	 */
	private static function generateGeminiEmbedding( $text, $apiKey, $model, $timeout ) {
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$apiKey}";
		$data = [
			'content' => [
				'parts' => [
					[ 'text' => $text ]
				]
			]
		];

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			wfDebugLog( 'Wanda', 'Gemini embedding error: ' . curl_error( $ch ) );
			curl_close( $ch );
			return null;
		}
		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Gemini embedding HTTP error: $httpCode - $response" );
			return null;
		}

		$result = json_decode( $response, true );
		if ( isset( $result['embedding']['values'] ) ) {
			return $result['embedding']['values'];
		}

		wfDebugLog( 'Wanda', 'Gemini embedding missing data: ' . $response );
		return null;
	}

	/**
	 * Generate Azure OpenAI embeddings
	 */
	private static function generateAzureEmbedding(
		$text,
		$apiKey,
		$apiEndpoint,
		$model,
		$timeout
	) {
		// Azure endpoint format:
		// https://{resource}.openai.azure.com/openai/deployments/{deployment}/embeddings?api-version=2023-05-15
		$url = rtrim( $apiEndpoint, '/' ) . '/embeddings?api-version=2023-05-15';
		$data = [
			'input' => $text,
			'model' => $model
		];

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'api-key: ' . $apiKey
		] );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			wfDebugLog( 'Wanda', 'Azure embedding error: ' . curl_error( $ch ) );
			curl_close( $ch );
			return null;
		}
		curl_close( $ch );

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', "Azure embedding HTTP error: $httpCode - $response" );
			return null;
		}

		$result = json_decode( $response, true );
		if ( isset( $result['data'][0]['embedding'] ) ) {
			return $result['data'][0]['embedding'];
		}

		wfDebugLog( 'Wanda', 'Azure embedding missing data: ' . $response );
		return null;
	}
}
