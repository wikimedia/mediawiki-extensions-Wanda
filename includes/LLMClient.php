<?php

namespace MediaWiki\Extension\Wanda;

/**
 * Sends completion, chat and embedding requests to the configured LLM provider.
 */
class LLMClient {
	private const MAX_ATTEMPTS = 3;
	private const TRANSIENT_HTTP_CODES = [ 429, 500, 502, 503, 504 ];

	/** @var string */
	private $provider;
	/** @var string */
	private $model;
	/** @var string */
	private $apiKey;
	/** @var string */
	private $apiEndpoint;
	/** @var int */
	private $timeout;
	/** @var string */
	private $proxy;

	/**
	 * @param string $provider One of ollama, openai, anthropic, azure, gemini
	 * @param string $model
	 * @param string $apiKey
	 * @param string $apiEndpoint
	 * @param int $timeout Seconds, per attempt
	 * @param string $proxy Optional outbound HTTP proxy
	 */
	public function __construct(
		string $provider,
		string $model,
		string $apiKey,
		string $apiEndpoint,
		int $timeout,
		string $proxy = ''
	) {
		$this->provider = strtolower( $provider );
		$this->model = trim( $model );
		$this->apiKey = $apiKey;
		$this->apiEndpoint = $apiEndpoint;
		$this->timeout = $timeout;
		$this->proxy = $proxy;
	}

	/**
	 * @param string|null $model
	 * @return string The request parameter this model takes its token limit in
	 */
	public static function getOpenAITokenKeyForModel( ?string $model ): string {
		$model = trim( (string)$model );

		if ( preg_match( '/(^|\\/)(o1|o3)/i', $model ) || stripos( $model, 'gpt-5' ) !== false ) {
			return 'max_completion_tokens';
		}

		return 'max_tokens';
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature Not sent to Gemini, which rejects sampling parameters
	 * @return string|null Generated text, or null on failure
	 */
	public function complete( string $prompt, int $maxTokens, float $temperature ): ?string {
		return $this->generate( $prompt, $maxTokens, $temperature )['text'];
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature Not sent to Gemini, which rejects sampling parameters
	 * @param array[] $images Each with `mime` and base64 `data`
	 * @param array[]|null $messages Multi-turn role/content messages. Used instead of
	 *   $prompt when there are no images; Ollama always uses $prompt.
	 * @return array{text:?string,tokens:int,truncated:bool,error:?string,status:int,apiMessage:string}
	 *   `error` is null on success, otherwise one of no-key, unknown-provider, connection,
	 *   http, empty, invalid-json, format, blocked, token-limit.
	 */
	public function chat(
		string $prompt,
		int $maxTokens,
		float $temperature,
		array $images = [],
		?array $messages = null
	): array {
		return $this->generate( $prompt, $maxTokens, $temperature, $images, $messages, true );
	}

	/**
	 * @param string $text
	 * @return array|null Embedding vector, or null on failure
	 */
	public function embed( string $text ): ?array {
		$endpoint = rtrim( $this->apiEndpoint, '/' );
		$jsonHeader = 'Content-Type: application/json';

		switch ( $this->provider ) {
			case 'openai':
				$json = $this->post(
					$endpoint . '/embeddings',
					[ 'input' => $text, 'model' => $this->model ],
					[ $jsonHeader, 'Authorization: Bearer ' . $this->apiKey ]
				)['json'];
				$embedding = $json['data'][0]['embedding'] ?? null;
				break;
			case 'ollama':
				$json = $this->post(
					$endpoint . '/embed',
					[ 'model' => $this->model, 'input' => $text ],
					[ $jsonHeader ]
				)['json'];
				$embedding = $json['embeddings'][0] ?? null;
				break;
			case 'gemini':
				$json = $this->post(
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) .
						':embedContent?key=' . urlencode( $this->apiKey ),
					[
						'content' => [ 'parts' => [ [ 'text' => $text ] ] ],
						// Must match the index mapping; these models default to 3072 dimensions.
						'outputDimensionality' => EmbeddingGenerator::getDimensions( 'gemini' ),
					],
					[ $jsonHeader ]
				)['json'];
				$embedding = $json['embedding']['values'] ?? null;
				break;
			case 'azure':
				$json = $this->post(
					$endpoint . '/embeddings?api-version=2023-05-15',
					[ 'input' => $text, 'model' => $this->model ],
					[ $jsonHeader, 'api-key: ' . $this->apiKey ]
				)['json'];
				$embedding = $json['data'][0]['embedding'] ?? null;
				break;
			default:
				wfDebugLog( 'Wanda', 'LLMClient: unknown embedding provider: ' . $this->provider );
				return null;
		}

		if ( !is_array( $embedding ) ) {
			wfDebugLog( 'Wanda', 'LLMClient: ' . $this->provider . ' embedding missing from response' );
			return null;
		}
		return $embedding;
	}

	private function generate(
		string $prompt,
		int $maxTokens,
		float $temperature,
		array $images = [],
		?array $messages = null,
		bool $useThinking = false
	): array {
		if ( $this->provider !== 'ollama' && $this->apiKey === '' ) {
			wfDebugLog( 'Wanda', 'LLMClient: no API key configured for ' . $this->provider );
			return $this->failure( 'no-key' );
		}

		$messages = $images === [] ? $messages : null;

		switch ( $this->provider ) {
			case 'ollama':
				return $this->callOllama( $prompt, $maxTokens, $temperature, $images );
			case 'openai':
				return $this->callOpenAI(
					$this->buildOpenAIMessages( $prompt, $images, $messages ), $maxTokens, $temperature
				);
			case 'anthropic':
				return $this->callAnthropic( $prompt, $maxTokens, $temperature, $images, $messages );
			case 'azure':
				return $this->callAzure(
					$this->buildOpenAIMessages( $prompt, $images, $messages ), $maxTokens, $temperature
				);
			case 'gemini':
				return $this->callGemini( $prompt, $maxTokens, $images, $messages, $useThinking );
			default:
				wfDebugLog( 'Wanda', 'LLMClient: unknown LLM provider: ' . $this->provider );
				return $this->failure( 'unknown-provider' );
		}
	}

	private function callOllama( string $prompt, int $maxTokens, float $temperature, array $images ): array {
		$url = rtrim( $this->apiEndpoint, '/' );
		if ( substr( $url, -strlen( '/generate' ) ) !== '/generate' ) {
			$url .= '/generate';
		}

		$payload = [
			'model' => $this->model,
			'prompt' => $prompt,
			'stream' => false,
			'options' => [
				'temperature' => $temperature,
				'num_predict' => $maxTokens
			]
		];
		if ( $images !== [] ) {
			$payload['images'] = array_column( $images, 'data' );
		}

		$response = $this->post( $url, $payload, [ 'Content-Type: application/json' ] );
		$json = $response['json'];

		return $this->buildResult(
			$response,
			$json['response'] ?? null,
			(int)( ( $json['prompt_eval_count'] ?? 0 ) + ( $json['eval_count'] ?? 0 ) ),
			$maxTokens
		);
	}

	/**
	 * @param string $prompt
	 * @param array[] $images
	 * @param array[]|null $messages
	 * @return array[] Messages in the format shared by OpenAI and Azure
	 */
	private function buildOpenAIMessages( string $prompt, array $images, ?array $messages ): array {
		if ( $messages !== null ) {
			return $messages;
		}

		$content = $prompt;
		if ( $images !== [] ) {
			$content = [ [ 'type' => 'text', 'text' => $prompt ] ];
			foreach ( $images as $image ) {
				$content[] = [
					'type' => 'image_url',
					'image_url' => [ 'url' => 'data:' . $image['mime'] . ';base64,' . $image['data'] ]
				];
			}
		}

		return [ [ 'role' => 'user', 'content' => $content ] ];
	}

	private function callOpenAI( array $messages, int $maxTokens, float $temperature ): array {
		$url = 'https://api.openai.com/v1/chat/completions';
		$model = $this->model ?: 'gpt-4-turbo';
		$payload = [
			'model' => $model,
			'messages' => $messages,
			'temperature' => $temperature
		];
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->apiKey
		];

		$tokenKey = self::getOpenAITokenKeyForModel( $model );
		$response = $this->post( $url, $payload + [ $tokenKey => $maxTokens ], $headers );

		$retryKey = $this->getAlternateTokenKey( $tokenKey, $response['apiMessage'] );
		if ( $retryKey !== null ) {
			wfDebugLog( 'Wanda', 'LLMClient: retrying OpenAI with ' . $retryKey );
			$response = $this->post( $url, $payload + [ $retryKey => $maxTokens ], $headers );
		}

		return $this->buildResult(
			$response,
			$response['json']['choices'][0]['message']['content'] ?? null,
			(int)( $response['json']['usage']['total_tokens'] ?? 0 ),
			$maxTokens
		);
	}

	/**
	 * @param string $tokenKey The token parameter that was sent
	 * @param string $apiMessage Error message returned by OpenAI
	 * @return string|null The parameter to retry with, if OpenAI rejected $tokenKey
	 */
	private function getAlternateTokenKey( string $tokenKey, string $apiMessage ): ?string {
		if ( $tokenKey === 'max_tokens' && stripos( $apiMessage, 'max_completion_tokens' ) !== false ) {
			return 'max_completion_tokens';
		}
		if ( $tokenKey === 'max_completion_tokens' && stripos( $apiMessage, 'max_tokens' ) !== false ) {
			return 'max_tokens';
		}
		return null;
	}

	private function callAnthropic(
		string $prompt,
		int $maxTokens,
		float $temperature,
		array $images,
		?array $messages
	): array {
		$system = '';
		if ( $messages !== null ) {
			$turns = [];
			foreach ( $messages as $message ) {
				if ( $message['role'] === 'system' ) {
					$system = $message['content'];
				} else {
					$turns[] = $message;
				}
			}
		} else {
			$content = $prompt;
			if ( $images !== [] ) {
				$content = [];
				foreach ( $images as $image ) {
					$content[] = [
						'type' => 'image',
						'source' => [
							'type' => 'base64',
							'media_type' => $image['mime'],
							'data' => $image['data']
						]
					];
				}
				$content[] = [ 'type' => 'text', 'text' => $prompt ];
			}
			$turns = [ [ 'role' => 'user', 'content' => $content ] ];
		}

		$payload = [
			'model' => $this->model ?: 'claude-haiku-4-5-20251001',
			'messages' => $turns,
			'max_tokens' => $maxTokens,
			'temperature' => $temperature
		];
		if ( $system !== '' ) {
			$payload['system'] = $system;
		}

		$response = $this->post(
			'https://api.anthropic.com/v1/messages',
			$payload,
			[
				'Content-Type: application/json',
				'x-api-key: ' . $this->apiKey,
				'anthropic-version: 2023-06-01'
			]
		);
		$usage = $response['json']['usage'] ?? [];

		return $this->buildResult(
			$response,
			$response['json']['content'][0]['text'] ?? null,
			(int)( ( $usage['input_tokens'] ?? 0 ) + ( $usage['output_tokens'] ?? 0 ) ),
			$maxTokens
		);
	}

	private function callAzure( array $messages, int $maxTokens, float $temperature ): array {
		$tokenKey = self::getOpenAITokenKeyForModel( $this->model );

		$response = $this->post(
			$this->apiEndpoint,
			[
				'messages' => $messages,
				$tokenKey => $maxTokens,
				'temperature' => $temperature
			],
			[
				'Content-Type: application/json',
				'api-key: ' . $this->apiKey
			]
		);

		return $this->buildResult(
			$response,
			$response['json']['choices'][0]['message']['content'] ?? null,
			(int)( $response['json']['usage']['total_tokens'] ?? 0 ),
			$maxTokens
		);
	}

	private function callGemini(
		string $prompt,
		int $maxTokens,
		array $images,
		?array $messages,
		bool $useThinking
	): array {
		$base = rtrim( $this->apiEndpoint ?: 'https://generativelanguage.googleapis.com/v1', '/' );
		if ( strpos( $base, 'http://' ) === 0 ) {
			$base = 'https://' . substr( $base, 7 );
		} elseif ( strpos( $base, 'https://' ) !== 0 ) {
			$base = 'https://' . $base;
		}
		$model = $this->model ?: 'gemini-1.5-flash';

		$generationConfig = [ 'maxOutputTokens' => $maxTokens ];
		if ( $useThinking ) {
			$generationConfig['thinkingConfig'] = strpos( $model, 'gemini-2.5' ) === 0
				? [ 'thinkingBudget' => 2048 ]
				: [ 'thinkingLevel' => 'low' ];
		}

		$response = $this->post(
			$base . '/models/' . rawurlencode( $model ) . ':generateContent?key=' . urlencode( $this->apiKey ),
			[
				'contents' => $this->buildGeminiContents( $prompt, $images, $messages ),
				'generationConfig' => $generationConfig
			],
			[ 'Content-Type: application/json' ]
		);

		$json = $response['json'];
		$candidate = $json['candidates'][0] ?? [];
		$text = $candidate['content']['parts'][0]['text'] ?? null;
		$truncated = ( $candidate['finishReason'] ?? '' ) === 'MAX_TOKENS';
		$usage = $json['usageMetadata'] ?? [];
		$tokens = (int)( $usage['totalTokenCount'] ?? 0 ) ?: (int)(
			( $usage['promptTokenCount'] ?? 0 ) +
			( $usage['candidatesTokenCount'] ?? 0 ) +
			( $usage['thoughtsTokenCount'] ?? 0 )
		);

		if ( $text === null && $response['error'] === null ) {
			wfDebugLog( 'Wanda', 'LLMClient: Gemini returned no text; finishReason=' .
				( $candidate['finishReason'] ?? 'unknown' ) .
				' promptFeedback=' . json_encode( $json['promptFeedback'] ?? null ) );
			if ( $truncated ) {
				return $this->failure( 'token-limit', $response['status'], '', $tokens ?: $maxTokens );
			}
			if ( isset( $json['promptFeedback']['blockReason'] ) ) {
				return $this->failure( 'blocked', $response['status'], $json['promptFeedback']['blockReason'] );
			}
		}

		return [ 'truncated' => $truncated ] + $this->buildResult( $response, $text, $tokens, $maxTokens );
	}

	private function buildGeminiContents( string $prompt, array $images, ?array $messages ): array {
		if ( $messages === null ) {
			$parts = [ [ 'text' => $prompt ] ];
			foreach ( $images as $image ) {
				$parts[] = [ 'inline_data' => [ 'mime_type' => $image['mime'], 'data' => $image['data'] ] ];
			}
			return [ [ 'role' => 'user', 'parts' => $parts ] ];
		}

		$roles = [ 'system' => 'user', 'assistant' => 'model' ];
		$contents = [];
		foreach ( $messages as $message ) {
			$contents[] = [
				'role' => $roles[$message['role']] ?? $message['role'],
				'parts' => [ [ 'text' => $message['content'] ] ]
			];
		}
		return $contents;
	}

	/**
	 * @param array $response From post()
	 * @param mixed $text Generated text extracted from the response
	 * @param int $tokens Tokens used, or 0 when the provider did not report them
	 * @param int $maxTokens
	 * @return array See chat()
	 */
	private function buildResult( array $response, $text, int $tokens, int $maxTokens ): array {
		if ( $response['error'] !== null ) {
			return $this->failure( $response['error'], $response['status'], $response['apiMessage'] );
		}
		if ( !is_string( $text ) ) {
			wfDebugLog( 'Wanda', 'LLMClient: unexpected ' . $this->provider . ' response: ' .
				substr( (string)json_encode( $response['json'] ), 0, 500 ) );
			return $this->failure( 'format', $response['status'], $response['apiMessage'] );
		}
		return [
			'text' => $text,
			'tokens' => $tokens ?: $maxTokens,
			'truncated' => false,
			'error' => null,
			'status' => $response['status'],
			'apiMessage' => '',
		];
	}

	private function failure( string $error, int $status = 0, string $apiMessage = '', int $tokens = 0 ): array {
		return [
			'text' => null,
			'tokens' => $tokens,
			'truncated' => false,
			'error' => $error,
			'status' => $status,
			'apiMessage' => $apiMessage,
		];
	}

	/**
	 * POST JSON, retrying transient failures with exponential backoff.
	 *
	 * @param string $url
	 * @param array $payload
	 * @param string[] $headers
	 * @return array{json:array,status:int,error:?string,apiMessage:string}
	 */
	private function post( string $url, array $payload, array $headers ): array {
		$data = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		for ( $attempt = 1; ; $attempt++ ) {
			[ 'status' => $status, 'body' => $body, 'error' => $error, 'timedOut' => $timedOut ] =
				$this->sendRequest( $url, $data, $headers );

			if ( $error === '' && $status === 200 ) {
				return $this->decodeResponse( $status, $body );
			}

			// A timeout already cost the full budget, and chat requests are interactive.
			$transient = !$timedOut &&
				( $error !== '' || in_array( $status, self::TRANSIENT_HTTP_CODES, true ) );
			if ( !$transient || $attempt >= self::MAX_ATTEMPTS ) {
				wfDebugLog( 'Wanda', 'LLMClient: ' .
					( $error !== '' ? 'cURL error: ' . $error : 'HTTP ' . $status . ': ' . substr( $body, 0, 500 ) ) );
				return $error !== ''
					? [ 'json' => [], 'status' => $status, 'error' => 'connection', 'apiMessage' => '' ]
					: [ 'json' => [], 'error' => 'http' ] + $this->decodeResponse( $status, $body );
			}

			wfDebugLog( 'Wanda', 'LLMClient: transient failure (attempt ' . $attempt . '/' .
				self::MAX_ATTEMPTS . '): ' . ( $error ?: 'HTTP ' . $status ) . '; retrying' );
			$this->backoff( $attempt );
		}
	}

	private function decodeResponse( int $status, string $body ): array {
		if ( $body === '' ) {
			return [ 'json' => [], 'status' => $status, 'error' => 'empty', 'apiMessage' => '' ];
		}

		$json = json_decode( $body, true );
		if ( !is_array( $json ) ) {
			wfDebugLog( 'Wanda', 'LLMClient: invalid JSON: ' . substr( $body, 0, 500 ) );
			return [ 'json' => [], 'status' => $status, 'error' => 'invalid-json',
				'apiMessage' => json_last_error_msg() ];
		}

		$apiMessage = $json['error']['message'] ?? $json['error'] ?? '';
		return [
			'json' => $json,
			'status' => $status,
			'error' => null,
			'apiMessage' => is_string( $apiMessage ) ? $apiMessage : '',
		];
	}

	/**
	 * @param string $url
	 * @param string $data
	 * @param string[] $headers
	 * @return array{status:int,body:string,error:string,timedOut:bool}
	 */
	protected function sendRequest( string $url, string $data, array $headers ): array {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		if ( $this->proxy !== '' ) {
			curl_setopt( $ch, CURLOPT_PROXY, $this->proxy );
		}

		$body = curl_exec( $ch );

		return [
			'status' => (int)curl_getinfo( $ch, CURLINFO_HTTP_CODE ),
			'body' => (string)$body,
			'error' => curl_error( $ch ),
			'timedOut' => curl_errno( $ch ) === CURLE_OPERATION_TIMEDOUT,
		];
	}

	/**
	 * @param int $attempt Number of the attempt that just failed, from 1
	 */
	protected function backoff( int $attempt ): void {
		usleep( 250000 * ( 1 << ( $attempt - 1 ) ) );
	}
}
