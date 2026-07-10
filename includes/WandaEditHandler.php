<?php

namespace MediaWiki\Extension\Wanda;

use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;

/**
 * Generates proposed wikitext edits with an LLM.
 *
 * This is the editing counterpart of {@see CargoQueryHandler} / the retrieval
 * handlers: it owns a self-contained set of provider calls (the codebase
 * intentionally keeps each handler's LLM plumbing local rather than sharing a
 * client) and turns a natural-language editing instruction plus the current
 * page wikitext into a proposed new revision.
 *
 * Edits are proposed via {@see proposeEdit}, which handles both free-text/prose
 * changes and infobox/template field changes on a single page.
 */
class WandaEditHandler {
	/** @var string */
	private $llmProvider;
	/** @var string */
	private $llmModel;
	/** @var string */
	private $llmApiKey;
	/** @var string */
	private $llmApiEndpoint;
	/** @var int */
	private $timeout;
	/** @var int */
	private $maxTokens;
	/** @var string Optional outbound HTTP proxy (global $wgHTTPProxy) */
	private $proxy;

	/**
	 * @param string $llmProvider
	 * @param string $llmModel
	 * @param string $llmApiKey
	 * @param string $llmApiEndpoint
	 * @param int $timeout
	 * @param int $maxTokens Upper bound on the response size; edits echo the whole
	 *   page back so this should be generous.
	 * @param string $proxy Optional outbound HTTP proxy
	 */
	public function __construct(
		string $llmProvider,
		string $llmModel,
		string $llmApiKey,
		string $llmApiEndpoint,
		int $timeout,
		int $maxTokens = 4096,
		string $proxy = ''
	) {
		$this->llmProvider = strtolower( $llmProvider );
		$this->llmModel = $llmModel;
		$this->llmApiKey = $llmApiKey;
		$this->llmApiEndpoint = $llmApiEndpoint;
		$this->timeout = $timeout;
		$this->maxTokens = max( 256, $maxTokens );
		$this->proxy = $proxy;
	}

	/**
	 * Propose an edit for a page: prose/free-text changes as well as
	 * infobox/template field changes.
	 *
	 * @param string $instruction Natural-language editing instruction
	 * @param string $currentWikitext Full current wikitext of the target page,
	 *   or the empty string when the page is being created
	 * @return array{changed:bool,newtext:string,explanation:string,failed?:bool}
	 *   `failed` is set when the LLM call failed or returned an unusable
	 *   response, as opposed to the model legitimately proposing no change.
	 */
	public function proposeEdit( string $instruction, string $currentWikitext ): array {
		$prompt = PromptTemplate::render( 'general-edit', [
			'instruction' => $instruction,
			'wikitext' => $currentWikitext,
		] );

		$response = $this->callLLM( $prompt );
		if ( $response === null ) {
			return [ 'changed' => false, 'newtext' => '', 'explanation' => '', 'failed' => true ];
		}

		return $this->parseEditResponse( $response );
	}

	/**
	 * Parse the model's JSON edit response, tolerating code fences and surrounding prose.
	 *
	 * @param string $response Raw LLM output
	 * @return array{changed:bool,newtext:string,explanation:string,failed?:bool}
	 *   `failed` is set when the response could not be parsed at all.
	 */
	public function parseEditResponse( string $response ): array {
		$default = [ 'changed' => false, 'newtext' => '', 'explanation' => '', 'failed' => true ];

		$text = trim( $response );
		// Strip ``` / ```json fences if the model wrapped its answer.
		$text = preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $text );

		// Isolate the outermost JSON object if the model added stray prose.
		$start = strpos( $text, '{' );
		$end = strrpos( $text, '}' );
		if ( $start === false || $end === false || $end <= $start ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler: no JSON object in LLM response: ' .
				substr( $response, 0, 300 ) );
			return $default;
		}
		$json = substr( $text, $start, $end - $start + 1 );

		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$data = json_decode( self::escapeControlCharsInJsonStrings( $json ), true );
		}
		if ( !is_array( $data ) ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler: unparseable JSON in LLM response: ' .
				substr( $response, 0, 300 ) );
			return $default;
		}

		$changed = !empty( $data['changed'] );
		$newtext = isset( $data['newtext'] ) ? (string)$data['newtext'] : '';
		$explanation = isset( $data['explanation'] ) ? (string)$data['explanation'] : '';

		// A "changed" answer with no text is meaningless; treat it as no change.
		if ( $changed && trim( $newtext ) === '' ) {
			return [ 'changed' => false, 'newtext' => '', 'explanation' => $explanation ];
		}

		return [
			'changed' => $changed,
			'newtext' => $newtext,
			'explanation' => $explanation,
		];
	}

	/**
	 * Escape raw control characters (newlines, tabs, etc.) that appear literally
	 * inside JSON string literals, leaving already-valid escape sequences and
	 * everything outside strings untouched.
	 *
	 * LLMs asked to return "wikitext inside a JSON string" reliably produce the
	 * wikitext's real line breaks instead of the \n escape the JSON spec requires,
	 * which makes json_decode() reject an otherwise well-formed response.
	 *
	 * @param string $json
	 * @return string
	 */
	private static function escapeControlCharsInJsonStrings( string $json ): string {
		$out = '';
		$inString = false;
		$escapedNext = false;
		$len = strlen( $json );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[$i];

			if ( !$inString ) {
				if ( $ch === '"' ) {
					$inString = true;
				}
				$out .= $ch;
				continue;
			}

			if ( $escapedNext ) {
				$out .= $ch;
				$escapedNext = false;
				continue;
			}

			if ( $ch === '\\' ) {
				$out .= $ch;
				$escapedNext = true;
				continue;
			}

			if ( $ch === '"' ) {
				$inString = false;
				$out .= $ch;
				continue;
			}

			switch ( $ch ) {
				case "\n":
					$out .= '\\n';
					break;
				case "\r":
					$out .= '\\r';
					break;
				case "\t":
					$out .= '\\t';
					break;
				default:
					$out .= ( ord( $ch ) < 0x20 ) ? sprintf( '\\u%04x', ord( $ch ) ) : $ch;
			}
		}

		return $out;
	}

	/**
	 * @param string $prompt
	 * @return string|null
	 */
	private function callLLM( string $prompt ): ?string {
		// Deterministic edits: keep temperature low.
		$temperature = 0.1;

		switch ( $this->llmProvider ) {
			case 'ollama':
				return $this->callOllama( $prompt, $this->maxTokens, $temperature );
			case 'openai':
				return $this->callOpenAI( $prompt, $this->maxTokens, $temperature );
			case 'anthropic':
				return $this->callAnthropic( $prompt, $this->maxTokens, $temperature );
			case 'azure':
				return $this->callAzure( $prompt, $this->maxTokens, $temperature );
			case 'gemini':
				return $this->callGemini( $prompt, $this->maxTokens, $temperature );
			default:
				wfDebugLog( 'Wanda', 'WandaEditHandler: unknown LLM provider: ' . $this->llmProvider );
				return null;
		}
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callOllama( string $prompt, int $maxTokens, float $temperature ): ?string {
		$payload = [
			'model' => $this->llmModel,
			'prompt' => $prompt,
			'stream' => false,
			'options' => [
				'temperature' => $temperature,
				'num_predict' => $maxTokens
			]
		];

		$response = $this->curlPost(
			$this->llmApiEndpoint . 'generate',
			json_encode( $payload ),
			[ 'Content-Type: application/json' ]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['response'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callOpenAI( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = trim( (string)( $this->llmModel ?: 'gpt-4-turbo' ) );
		$basePayload = [
			'model' => $model,
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			'temperature' => $temperature
		];

		$tokenKey = APIChat::getOpenAITokenKeyForModel( $model );
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->llmApiKey
		];

		$sendRequest = function ( $payload ) use ( $headers ): ?string {
			return $this->curlPost(
				'https://api.openai.com/v1/chat/completions',
				json_encode( $payload ),
				$headers
			);
		};

		$payload = $basePayload;
		$payload[$tokenKey] = $maxTokens;
		$response = $sendRequest( $payload );

		// Retry once if OpenAI rejects the token parameter name.
		if ( $response !== null ) {
			$json = json_decode( $response, true );
			$apiMessage = $json['error']['message'] ?? '';
			if ( is_string( $apiMessage ) && $apiMessage !== '' ) {
				$mentionsMaxTokens = stripos( $apiMessage, 'max_tokens' ) !== false;
				$mentionsMaxCompletion = stripos( $apiMessage, 'max_completion_tokens' ) !== false;

				$retryKey = null;
				if ( $tokenKey === 'max_tokens' && $mentionsMaxCompletion ) {
					$retryKey = 'max_completion_tokens';
				} elseif ( $tokenKey === 'max_completion_tokens' && $mentionsMaxTokens ) {
					$retryKey = 'max_tokens';
				}

				if ( $retryKey !== null ) {
					wfDebugLog( 'Wanda', 'WandaEditHandler: retrying OpenAI with ' . $retryKey );
					$retryPayload = $basePayload;
					$retryPayload[$retryKey] = $maxTokens;
					$response = $sendRequest( $retryPayload );
					$json = $response !== null ? json_decode( $response, true ) : null;
				}
			}

			return $json['choices'][0]['message']['content'] ?? null;
		}

		return null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callAnthropic( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$payload = [
			'model' => $this->llmModel ?: 'claude-3-haiku-20240307',
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			'max_tokens' => $maxTokens,
			'temperature' => $temperature
		];

		$response = $this->curlPost(
			'https://api.anthropic.com/v1/messages',
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'x-api-key: ' . $this->llmApiKey,
				'anthropic-version: 2023-06-01'
			]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['content'][0]['text'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callAzure( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = trim( (string)( $this->llmModel ?: '' ) );
		$tokenKey = 'max_tokens';
		if ( $model !== '' ) {
			if ( preg_match( '/(^|\\/)(o1|o3)/i', $model ) || stripos( $model, 'gpt-5' ) !== false ) {
				$tokenKey = 'max_completion_tokens';
			}
		}

		$payload = [
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			$tokenKey => $maxTokens,
			'temperature' => $temperature
		];

		$response = $this->curlPost(
			$this->llmApiEndpoint,
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'api-key: ' . $this->llmApiKey
			]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['choices'][0]['message']['content'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callGemini( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = $this->llmModel ?: 'gemini-1.5-flash';
		$base = $this->llmApiEndpoint ?: 'https://generativelanguage.googleapis.com/v1';
		$base = rtrim( $base, '/' );
		if ( strpos( $base, 'http://' ) === 0 ) {
			$base = 'https://' . substr( $base, 7 );
		} elseif ( strpos( $base, 'https://' ) !== 0 && strpos( $base, 'http://' ) !== 0 ) {
			$base = 'https://' . $base;
		}
		$url = $base . '/models/' . rawurlencode( $model ) .
			':generateContent?key=' . urlencode( $this->llmApiKey );

		$payload = [
			'contents' => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ]
			],
			'generationConfig' => [
				'temperature' => $temperature,
				'maxOutputTokens' => $maxTokens
			]
		];

		$response = $this->curlPost(
			$url,
			json_encode( $payload ),
			[ 'Content-Type: application/json' ]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		$text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if ( $text === null ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler: Gemini returned no text; finishReason=' .
				( $json['candidates'][0]['finishReason'] ?? 'unknown' ) .
				' promptFeedback=' . json_encode( $json['promptFeedback'] ?? null ) );
		}
		return $text;
	}

	/**
	 * Generic cURL POST helper.
	 *
	 * @param string $url
	 * @param string $data
	 * @param array $headers
	 * @return string|null Response body or null on failure
	 */
	private function curlPost( string $url, string $data, array $headers ): ?string {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		if ( !empty( $this->proxy ) ) {
			curl_setopt( $ch, CURLOPT_PROXY, $this->proxy );
		}

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler cURL error: ' . $curlError );
			return null;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler HTTP ' . $httpCode . ': ' .
				substr( (string)$response, 0, 500 ) );
			return null;
		}

		return $response;
	}
}
