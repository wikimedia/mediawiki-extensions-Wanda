<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\LLMClient;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\LLMClient
 */
class LLMClientTest extends MediaWikiUnitTestCase {

	/**
	 * @param string $provider
	 * @param array[] $responses Queued responses, as status/body/error arrays
	 * @param string $model
	 * @param string $key
	 * @param string $endpoint
	 * @return LLMClient Records requests in its public $requests property
	 */
	private function newClient(
		string $provider,
		array $responses,
		string $model = 'test-model',
		string $key = 'secret',
		string $endpoint = 'http://llm.test/api/'
	): LLMClient {
		return new class( $provider, $model, $key, $endpoint, 30, $responses ) extends LLMClient {
			/** @var array[] */
			public $requests = [];
			/** @var int */
			public $backoffs = 0;
			/** @var array[] */
			private $responses;

			public function __construct(
				string $provider, string $model, string $key, string $endpoint, int $timeout, array $responses
			) {
				parent::__construct( $provider, $model, $key, $endpoint, $timeout );
				$this->responses = $responses;
			}

			protected function sendRequest( string $url, string $data, array $headers ): array {
				$this->requests[] = [ 'url' => $url, 'payload' => json_decode( $data, true ), 'headers' => $headers ];
				return array_shift( $this->responses );
			}

			protected function backoff( int $attempt ): void {
				$this->backoffs++;
			}
		};
	}

	private function okResponse( array $body ): array {
		return [ 'status' => 200, 'body' => json_encode( $body ), 'error' => '', 'timedOut' => false ];
	}

	private function errorResponse( int $status, array $body = [] ): array {
		return [ 'status' => $status, 'body' => json_encode( $body ), 'error' => '', 'timedOut' => false ];
	}

	public static function provideEndpoints(): array {
		return [
			'trailing slash' => [ 'http://llm.test/api/' ],
			'no trailing slash' => [ 'http://llm.test/api' ],
			'full path' => [ 'http://llm.test/api/generate' ],
		];
	}

	/**
	 * @dataProvider provideEndpoints
	 */
	public function testOllama( string $endpoint ) {
		$client = $this->newClient( 'ollama', [ $this->okResponse( [ 'response' => 'hi' ] ) ], 'm', '', $endpoint );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$request = $client->requests[0];
		$this->assertSame( 'http://llm.test/api/generate', $request['url'] );
		$this->assertSame( 'prompt', $request['payload']['prompt'] );
		$this->assertFalse( $request['payload']['stream'] );
		$this->assertSame( 100, $request['payload']['options']['num_predict'] );
		$this->assertSame( 0.1, $request['payload']['options']['temperature'] );
	}

	/**
	 * @dataProvider provideModels
	 */
	public function testGetOpenAITokenKeyForModel( string $model, string $expected ) {
		$this->assertSame( $expected, LLMClient::getOpenAITokenKeyForModel( $model ) );
	}

	public static function provideModels() {
		return [
			'empty string defaults to max_tokens' => [ '', 'max_tokens' ],
			'gpt-4' => [ 'gpt-4', 'max_tokens' ],
			'gpt-3.5-turbo' => [ 'gpt-3.5-turbo', 'max_tokens' ],
			'gpt-4o (no o1/o3 token)' => [ 'gpt-4o', 'max_tokens' ],
			'plain ollama model' => [ 'gemma:2b', 'max_tokens' ],
			'gpt-5 family' => [ 'gpt-5', 'max_completion_tokens' ],
			'gpt-5 variant' => [ 'gpt-5.4-nano', 'max_completion_tokens' ],
			'prefixed gpt-5' => [ 'openai/gpt-5-nano', 'max_completion_tokens' ],
			'uppercase gpt-5' => [ 'GPT-5-MINI', 'max_completion_tokens' ],
			'o1 series' => [ 'o1', 'max_completion_tokens' ],
			'o3 series' => [ 'o3-mini', 'max_completion_tokens' ],
			'prefixed o1' => [ 'openai/o1-preview', 'max_completion_tokens' ],
		];
	}

	public function testOpenAI() {
		$client = $this->newClient( 'openai', [
			$this->okResponse( [ 'choices' => [ [ 'message' => [ 'content' => 'hi' ] ] ] ] )
		], 'gpt-4' );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$request = $client->requests[0];
		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $request['url'] );
		$this->assertContains( 'Authorization: Bearer secret', $request['headers'] );
		$this->assertSame( 'prompt', $request['payload']['messages'][0]['content'] );
	}

	public function testOpenAIRetriesWithAlternateTokenKeyOnRejection() {
		$client = $this->newClient( 'openai', [
			$this->errorResponse( 400, [ 'error' => [ 'message' => "Use 'max_completion_tokens' instead." ] ] ),
			$this->okResponse( [ 'choices' => [ [ 'message' => [ 'content' => 'hi' ] ] ] ] )
		], 'gpt-4' );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$this->assertCount( 2, $client->requests );
		$this->assertArrayHasKey( 'max_tokens', $client->requests[0]['payload'] );
		$this->assertArrayHasKey( 'max_completion_tokens', $client->requests[1]['payload'] );
		$this->assertArrayNotHasKey( 'max_tokens', $client->requests[1]['payload'] );
	}

	public function testAnthropic() {
		$client = $this->newClient( 'anthropic', [ $this->okResponse( [ 'content' => [ [ 'text' => 'hi' ] ] ] ) ] );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$request = $client->requests[0];
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $request['url'] );
		$this->assertContains( 'x-api-key: secret', $request['headers'] );
		$this->assertSame( 100, $request['payload']['max_tokens'] );
	}

	public static function provideAzureTokenKeys(): array {
		return [
			'older model' => [ 'gpt-4', 'max_tokens' ],
			'o-series' => [ 'o1-mini', 'max_completion_tokens' ],
			'gpt-5' => [ 'gpt-5-mini', 'max_completion_tokens' ],
		];
	}

	/**
	 * @dataProvider provideAzureTokenKeys
	 */
	public function testAzure( string $model, string $tokenKey ) {
		$client = $this->newClient( 'azure', [
			$this->okResponse( [ 'choices' => [ [ 'message' => [ 'content' => 'hi' ] ] ] ] )
		], $model );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$request = $client->requests[0];
		$this->assertSame( 'http://llm.test/api/', $request['url'] );
		$this->assertContains( 'api-key: secret', $request['headers'] );
		$this->assertSame( 100, $request['payload'][$tokenKey] );
	}

	public function testGemini() {
		$client = $this->newClient( 'gemini', [
			$this->okResponse( [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'hi' ] ] ] ] ] ] )
		], 'gemini-pro', 'secret', 'http://gemini.test/v1/' );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );

		$request = $client->requests[0];
		$this->assertSame(
			'https://gemini.test/v1/models/gemini-pro:generateContent?key=secret',
			$request['url']
		);
		$this->assertSame( 100, $request['payload']['generationConfig']['maxOutputTokens'] );
		$this->assertArrayNotHasKey( 'temperature', $request['payload']['generationConfig'] );
	}

	public function testGeminiWithoutTextReturnsNull() {
		$client = $this->newClient( 'gemini', [
			$this->okResponse( [ 'candidates' => [ [ 'finishReason' => 'SAFETY' ] ] ] )
		] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
	}

	public function testProviderNameIsCaseInsensitive() {
		$client = $this->newClient( 'Ollama', [ $this->okResponse( [ 'response' => 'hi' ] ) ] );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );
	}

	public function testUnknownProviderReturnsNull() {
		$client = $this->newClient( 'nonesuch', [] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertSame( [], $client->requests );
	}

	public static function provideKeyedProviders(): array {
		return [ [ 'openai' ], [ 'anthropic' ], [ 'azure' ], [ 'gemini' ] ];
	}

	/**
	 * @dataProvider provideKeyedProviders
	 */
	public function testMissingApiKeyReturnsNullWithoutRequest( string $provider ) {
		$client = $this->newClient( $provider, [], 'm', '' );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertSame( [], $client->requests );
	}

	public function testChatReturnsTextAndReportedTokens() {
		$client = $this->newClient( 'anthropic', [ $this->okResponse( [
			'content' => [ [ 'text' => 'hi' ] ],
			'usage' => [ 'input_tokens' => 3, 'output_tokens' => 4 ]
		] ) ] );

		$result = $client->chat( 'prompt', 100, 0.1 );

		$this->assertSame( 'hi', $result['text'] );
		$this->assertSame( 7, $result['tokens'] );
		$this->assertNull( $result['error'] );
	}

	public function testChatFallsBackToMaxTokensWhenUsageIsMissing() {
		$client = $this->newClient( 'ollama', [ $this->okResponse( [ 'response' => 'hi' ] ) ] );

		$this->assertSame( 100, $client->chat( 'prompt', 100, 0.1 )['tokens'] );
	}

	public function testChatUsesMessagesForMultiTurn() {
		$messages = [
			[ 'role' => 'system', 'content' => 'be brief' ],
			[ 'role' => 'user', 'content' => 'hello' ]
		];
		$client = $this->newClient( 'anthropic', [ $this->okResponse( [ 'content' => [ [ 'text' => 'hi' ] ] ] ) ] );

		$client->chat( 'prompt', 100, 0.1, [], $messages );

		$payload = $client->requests[0]['payload'];
		$this->assertSame( 'be brief', $payload['system'] );
		$this->assertSame( [ $messages[1] ], $payload['messages'] );
	}

	public function testChatMapsGeminiRoles() {
		$client = $this->newClient( 'gemini', [ $this->okResponse( [] ) ] );

		$client->chat( 'prompt', 100, 0.1, [], [
			[ 'role' => 'system', 'content' => 'a' ],
			[ 'role' => 'assistant', 'content' => 'b' ]
		] );

		$contents = $client->requests[0]['payload']['contents'];
		$this->assertSame( [ 'user', 'model' ], array_column( $contents, 'role' ) );
	}

	public function testImagesTakePrecedenceOverMessages() {
		$client = $this->newClient( 'openai', [ $this->okResponse( [] ) ], 'gpt-4' );

		$client->chat(
			'prompt', 100, 0.1,
			[ [ 'mime' => 'image/png', 'data' => 'abc' ] ],
			[ [ 'role' => 'user', 'content' => 'ignored' ] ]
		);

		$content = $client->requests[0]['payload']['messages'][0]['content'];
		$this->assertSame( 'prompt', $content[0]['text'] );
		$this->assertSame( 'data:image/png;base64,abc', $content[1]['image_url']['url'] );
	}

	public function testOllamaSendsImages() {
		$client = $this->newClient( 'ollama', [ $this->okResponse( [ 'response' => 'hi' ] ) ] );

		$client->chat( 'prompt', 100, 0.1, [ [ 'mime' => 'image/png', 'data' => 'abc' ] ] );

		$this->assertSame( [ 'abc' ], $client->requests[0]['payload']['images'] );
	}

	public function testOnlyChatSendsGeminiThinkingConfig() {
		$body = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => 'hi' ] ] ] ] ] ];
		$client = $this->newClient( 'gemini', [ $this->okResponse( $body ), $this->okResponse( $body ) ] );

		$client->chat( 'prompt', 100, 0.1 );
		$client->complete( 'prompt', 100, 0.1 );

		$this->assertArrayHasKey( 'thinkingConfig', $client->requests[0]['payload']['generationConfig'] );
		$this->assertArrayNotHasKey( 'thinkingConfig', $client->requests[1]['payload']['generationConfig'] );
	}

	public function testGeminiTruncatedResponseKeepsText() {
		$client = $this->newClient( 'gemini', [ $this->okResponse( [
			'candidates' => [ [ 'finishReason' => 'MAX_TOKENS', 'content' => [ 'parts' => [ [ 'text' => 'hi' ] ] ] ] ]
		] ) ] );

		$result = $client->chat( 'prompt', 100, 0.1 );

		$this->assertSame( 'hi', $result['text'] );
		$this->assertTrue( $result['truncated'] );
	}

	public function testGeminiTruncatedResponseWithoutText() {
		$client = $this->newClient( 'gemini', [ $this->okResponse( [
			'candidates' => [ [ 'finishReason' => 'MAX_TOKENS' ] ]
		] ) ] );

		$this->assertSame( 'token-limit', $client->chat( 'prompt', 100, 0.1 )['error'] );
	}

	public function testGeminiBlockedRequest() {
		$client = $this->newClient( 'gemini', [
			$this->okResponse( [ 'promptFeedback' => [ 'blockReason' => 'SAFETY' ] ] )
		] );

		$result = $client->chat( 'prompt', 100, 0.1 );

		$this->assertSame( 'blocked', $result['error'] );
		$this->assertSame( 'SAFETY', $result['apiMessage'] );
	}

	public static function provideChatFailures(): array {
		$timeout = [ 'status' => 0, 'body' => '', 'error' => 'timed out', 'timedOut' => true ];
		$http = [ 'status' => 403, 'body' => '{"error":{"message":"denied"}}', 'error' => '', 'timedOut' => false ];
		$empty = [ 'status' => 200, 'body' => '', 'error' => '', 'timedOut' => false ];
		$badJson = [ 'status' => 200, 'body' => 'nope', 'error' => '', 'timedOut' => false ];
		$noText = [ 'status' => 200, 'body' => '{"choices":[]}', 'error' => '', 'timedOut' => false ];
		return [
			'connection' => [ $timeout, 'connection', 0, '' ],
			'http' => [ $http, 'http', 403, 'denied' ],
			'empty' => [ $empty, 'empty', 200, '' ],
			'invalid json' => [ $badJson, 'invalid-json', 200, 'Syntax error' ],
			'format' => [ $noText, 'format', 200, '' ],
		];
	}

	/**
	 * @dataProvider provideChatFailures
	 */
	public function testChatReportsFailures( array $response, string $error, int $status, string $apiMessage ) {
		$client = $this->newClient( 'azure', [ $response ] );

		$result = $client->chat( 'prompt', 100, 0.1 );

		$this->assertNull( $result['text'] );
		$this->assertSame( $error, $result['error'] );
		$this->assertSame( $status, $result['status'] );
		$this->assertSame( $apiMessage, $result['apiMessage'] );
	}

	public function testChatReportsMissingKeyAndUnknownProvider() {
		$this->assertSame( 'no-key', $this->newClient( 'openai', [], 'm', '' )->chat( 'p', 1, 0.1 )['error'] );
		$this->assertSame(
			'unknown-provider',
			$this->newClient( 'nonesuch', [] )->chat( 'p', 1, 0.1 )['error']
		);
	}

	public static function provideEmbeddings(): array {
		$openAIBody = [ 'data' => [ [ 'embedding' => [ 0.5 ] ] ] ];
		return [
			'openai' => [ 'openai', $openAIBody, 'http://llm.test/api/embeddings' ],
			'ollama' => [ 'ollama', [ 'embeddings' => [ [ 0.5 ] ] ], 'http://llm.test/api/embed' ],
			'azure' => [ 'azure', $openAIBody, 'http://llm.test/api/embeddings?api-version=2023-05-15' ],
			'gemini' => [
				'gemini',
				[ 'embedding' => [ 'values' => [ 0.5 ] ] ],
				'https://generativelanguage.googleapis.com/v1beta/models/test-model:embedContent?key=secret'
			],
		];
	}

	/**
	 * @dataProvider provideEmbeddings
	 */
	public function testEmbed( string $provider, array $body, string $url ) {
		$client = $this->newClient( $provider, [ $this->okResponse( $body ) ] );

		$this->assertSame( [ 0.5 ], $client->embed( 'text' ) );
		$this->assertSame( $url, $client->requests[0]['url'] );
	}

	public function testEmbedRequestsIndexDimensionsFromGemini() {
		$client = $this->newClient( 'gemini', [ $this->okResponse( [ 'embedding' => [ 'values' => [ 0.5 ] ] ] ) ] );

		$client->embed( 'text' );

		$this->assertSame( 768, $client->requests[0]['payload']['outputDimensionality'] );
	}

	public function testEmbedRetriesTransientFailure() {
		$client = $this->newClient( 'ollama', [
			$this->errorResponse( 503 ),
			$this->okResponse( [ 'embeddings' => [ [ 0.5 ] ] ] )
		] );

		$this->assertSame( [ 0.5 ], $client->embed( 'text' ) );
		$this->assertCount( 2, $client->requests );
	}

	public function testEmbedReturnsNullOnFailureOrUnknownProvider() {
		$this->assertNull( $this->newClient( 'ollama', [ $this->errorResponse( 400 ) ] )->embed( 'text' ) );
		$this->assertNull( $this->newClient( 'anthropic', [] )->embed( 'text' ) );
	}

	public function testRetriesTransientHttpFailure() {
		$client = $this->newClient( 'ollama', [
			$this->errorResponse( 503 ),
			$this->errorResponse( 429 ),
			$this->okResponse( [ 'response' => 'hi' ] )
		] );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertCount( 3, $client->requests );
		$this->assertSame( 2, $client->backoffs );
	}

	public function testRetriesCurlError() {
		$client = $this->newClient( 'ollama', [
			[ 'status' => 0, 'body' => '', 'error' => 'connection reset', 'timedOut' => false ],
			$this->okResponse( [ 'response' => 'hi' ] )
		] );

		$this->assertSame( 'hi', $client->complete( 'prompt', 100, 0.1 ) );
	}

	public function testDoesNotRetryTimeout() {
		$client = $this->newClient( 'ollama', [
			[ 'status' => 0, 'body' => '', 'error' => 'timed out', 'timedOut' => true ]
		] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertCount( 1, $client->requests );
		$this->assertSame( 0, $client->backoffs );
	}

	public function testGivesUpAfterMaxAttempts() {
		$client = $this->newClient( 'ollama', [
			$this->errorResponse( 500 ),
			$this->errorResponse( 500 ),
			$this->errorResponse( 500 )
		] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertCount( 3, $client->requests );
	}

	public function testDoesNotRetryClientError() {
		$client = $this->newClient( 'anthropic', [
			$this->errorResponse( 401, [ 'error' => [ 'message' => 'bad key' ] ] )
		] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
		$this->assertCount( 1, $client->requests );
		$this->assertSame( 0, $client->backoffs );
	}

	public function testUnparseableResponseReturnsNull() {
		$client = $this->newClient( 'ollama', [
			[ 'status' => 200, 'body' => 'not json', 'error' => '', 'timedOut' => false ]
		] );

		$this->assertNull( $client->complete( 'prompt', 100, 0.1 ) );
	}
}
