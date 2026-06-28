<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApiMain;
use MediaWiki\Extension\Wanda\APIChat;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use RequestContext;
use Wikimedia\TestingAccessWrapper;

/**
 * Integration tests for the token-budget feature of APIChat.
 *
 * @covers \MediaWiki\Extension\Wanda\APIChat::checkAndConsumeTokenBudget
 * @covers \MediaWiki\Extension\Wanda\APIChat::execute
 * @group Wanda
 * @group Database
 */
class APIChatBudgetIntegrationTest extends MediaWikiIntegrationTestCase {

	/** @var \HashBagOStuff */
	private \HashBagOStuff $stash;

	/** @var APIChat (partial mock, constructor disabled) */
	private APIChat $chat;

	/** @var TestingAccessWrapper around $this->chat */
	private TestingAccessWrapper $wrapper;

	protected function setUp(): void {
		parent::setUp();

		$this->stash = new \HashBagOStuff();
		$this->setService( 'MainObjectStash', $this->stash );

		$this->overrideConfigValues( [
			'WandaLLMProvider'           => 'ollama',
			'WandaLLMModel'              => 'gemma:2b',
			'WandaLLMApiKey'             => '',
			'WandaLLMApiEndpoint'        => 'http://ollama:11434/api/',
			'WandaLLMMaxTokens'          => 1000,
			'WandaLLMTemperature'        => '0.7',
			'WandaLLMTimeout'            => 30,
			'WandaLLMElasticsearchUrl'   => 'http://elasticsearch:9200',
			'WandaLLMEmbeddingModel'     => '',
			'WandaVectorSearchMinScore'  => 1.7,
			'WandaMaxContextChars'       => 10000,
			'WandaCustomPrompt'          => '',
			'WandaCustomPromptTitle'     => '',
			// skip ES in all tests
			'WandaSkipESQuery'           => true,
			'WandaUseContentLang'        => false,
			'WandaConversationMaxChars'  => 6000,
			'WandaCargoExcludedTables'   => [],
			'WandaCargoMaxQuerySteps'    => 3,
			'WandaSparqlEndpoint'        => 'https://query.wikidata.org/sparql',
			'WandaWikidataApiEndpoint'   => 'https://www.wikidata.org/w/api.php',
			'WandaWikidataLang'          => 'en',
			'WandaWikidataMaxQuerySteps' => 3,
			'WandaSparqlTimeout'         => 60,
			'WandaRAGSources'            => [],
			// overridden per-test where needed
			'WandaDailyTokenBudget'      => 1000,
			'WandaExternalWikis'         => [],
			'WandaExternalWikiMaxResults' => 3,
			'WandaExternalWikiExtractLen' => 1200,
			'WandaExternalWikiTimeout'   => 10,
			'WandaExternalWikiMinESScore' => 0.0,
			'WandaExternalWikiDefaultNamespaces' => [ 0 ],
			'WandaMaxImageSize'          => 5242880,
			'WandaShowConfidenceScore'   => false,
			'WandaShowPopup'             => false,
			'WandaEnableAttachments'     => false,
			'WandaAutoReindex'           => false,
			'WandaRAGSourceNames'        => [],
			'WandaDisabledSources'       => [],
		] );

		$context = new RequestContext();
		$main    = new ApiMain( $context );
		$this->chat    = new StubAPIChat( $main, 'wandachat' );
		$this->wrapper = TestingAccessWrapper::newFromObject( $this->chat );
	}

	private function setBudget( int $v ): void {
		$rp = ( new ReflectionClass( APIChat::class ) )->getProperty( 'dailyTokenBudget' );
		$rp->setValue( null, $v );
	}

	private function setMaxTokens( int $v ): void {
		$rp = ( new ReflectionClass( APIChat::class ) )->getProperty( 'maxTokens' );
		$rp->setValue( null, $v );
	}

	private function normalUser(): \User {
		return $this->getTestUser()->getUser();
	}

	private function sysopUser(): \User {
		return $this->getTestSysop()->getUser();
	}

	private function chatAs( \User $user, string $ip = '127.0.0.1' ): TestingAccessWrapper {
		$req = new FauxRequest();
		$req->setIP( $ip );

		// Snapshot the budget the test set BEFORE construction, because the
		// real constructor resets the static $dailyTokenBudget from config.
		$rp = ( new \ReflectionClass( APIChat::class ) )->getProperty( 'dailyTokenBudget' );
		$budgetSnapshot = $rp->getValue( null );

		$chat = $this->getMockBuilder( StubAPIChat::class )
			->setConstructorArgs( [ new ApiMain( new RequestContext() ), 'wandachat' ] )
			->onlyMethods( [ 'getUser', 'getRequest' ] )
			->getMock();

		$chat->method( 'getUser' )->willReturn( $user );
		$chat->method( 'getRequest' )->willReturn( $req );

		// Restore the test's budget after the constructor clobbered it.
		$rp->setValue( null, $budgetSnapshot );

		return TestingAccessWrapper::newFromObject( $chat );
	}

	public function testFirstRequestUnderBudgetReturnsNull(): void {
		$this->setBudget( 1000 );
		$wrapper = $this->chatAs( $this->normalUser() );

		$result = $wrapper->checkAndConsumeTokenBudget( 300 );

		$this->assertNull( $result, 'First request (300/1000) must be within budget.' );
	}

	public function testExactlyAtBudgetAfterChargeIsAllowed(): void {
		$this->setBudget( 500 );
		$wrapper = $this->chatAs( $this->normalUser() );
		$result = $wrapper->checkAndConsumeTokenBudget( 500 );

		$this->assertNull( $result, 'A request that lands the counter exactly on the limit must be allowed.' );
	}

	public function testFirstRequestExceedingEntireBudgetReturnsError(): void {
		$this->setBudget( 100 );
		$wrapper = $this->chatAs( $this->normalUser() );

		$result = $wrapper->checkAndConsumeTokenBudget( 500 );

		$this->assertIsString( $result, 'A request exceeding the entire budget must return an error string.' );
		$this->assertNotSame( '', $result );
	}

	public function testRegisteredUserKeyContainsUserId(): void {
		$this->setBudget( 1000 );
		$user = $this->normalUser();

		$capturedKey = null;
		$captureMakeKey = static function ( ...$parts ) use ( &$capturedKey ) {
			$capturedKey = implode( ':', $parts );
			return $capturedKey;
		};

		$stashSpy = $this->createMock( \BagOStuff::class );
		$stashSpy->method( 'makeKey' )->willReturnCallback( $captureMakeKey );
		$stashSpy->method( 'incrWithInit' )->willReturn( 50 );
		$this->setService( 'MainObjectStash', $stashSpy );

		$wrapper = $this->chatAs( $user );
		$wrapper->checkAndConsumeTokenBudget( 50 );

		$this->assertNotNull( $capturedKey );
		$this->assertStringContainsString(
			'user:' . $user->getId(),
			$capturedKey,
			'Registered-user stash key must contain "user:<id>".'
		);
	}

	public function testAnonymousUserKeyContainsIp(): void {
		$this->setBudget( 1000 );

		$ip          = '203.0.113.42';
		$capturedKey = null;

		$stashSpy = $this->createMock( \BagOStuff::class );
		$stashSpy->method( 'makeKey' )
			->willReturnCallback( static function ( ...$parts ) use ( &$capturedKey ) {
				$capturedKey = implode( ':', $parts );
				return $capturedKey;
			} );
		$stashSpy->method( 'incrWithInit' )->willReturn( 50 );
		$this->setService( 'MainObjectStash', $stashSpy );

		$anonUser = $this->getMockBuilder( \User::class )
			->onlyMethods( [ 'isAllowed', 'isAnon', 'getId', 'getName' ] )
			->getMock();
		$anonUser->method( 'isAllowed' )->willReturn( false );
		$anonUser->method( 'isAnon' )->willReturn( true );
		$anonUser->method( 'getId' )->willReturn( 0 );
		$anonUser->method( 'getName' )->willReturn( $ip );

		$wrapper = $this->chatAs( $anonUser, $ip );
		$wrapper->checkAndConsumeTokenBudget( 50 );

		$this->assertNotNull( $capturedKey );
		$this->assertStringContainsString(
			"ip:{$ip}",
			$capturedKey,
			'Anonymous-user stash key must contain "ip:<address>".'
		);
		$this->assertStringNotContainsString(
			'user:',
			$capturedKey,
			'Anonymous-user key must not use a user-ID segment.'
		);
	}

	public function testTwoDifferentRegisteredUsersHaveDistinctKeys(): void {
		$this->setBudget( 1000 );

		$keys = [];
		$this->stash = new \HashBagOStuff();
		$this->setService( 'MainObjectStash', $this->stash );

		$user1 = $this->getTestUser( [ 'user' ] )->getUser();
		$user2 = $this->getTestUser( [ 'user' ] )->getUser();

		$w1 = $this->chatAs( $user1 );
		$w2 = $this->chatAs( $user2 );

		$w1->checkAndConsumeTokenBudget( 10 );
		$w2->checkAndConsumeTokenBudget( 10 );

		// Both should still be under budget (distinct keys → distinct counters).
		$result1 = $w1->checkAndConsumeTokenBudget( 10 );
		$result2 = $w2->checkAndConsumeTokenBudget( 10 );

		$this->assertNull( $result1, 'User 1 should still have budget remaining.' );
		$this->assertNull( $result2, 'User 2 should still have budget remaining (separate key).' );
	}

	/**
	 * Drive execute() through a StubAPIChat that returns $llmResult from
	 * generateLLMResponse() without hitting any real LLM endpoint.
	 *
	 * @param array|false $llmResult Return value for generateLLMResponse().
	 * @param array $params API request parameters.
	 * @return array The raw API result data array.
	 */
	private function executeWithLlmResult( $llmResult, array $params = [] ): array {
		$params += [
			'action'  => 'wandachat',
			'message' => 'hello',
			'format'  => 'json',
		];

		// wasPosted
		$request = new FauxRequest( $params, true );
		$context = new RequestContext();
		$context->setRequest( $request );

		// enableWrite
		$main = new ApiMain( $context, true );

		$user = $this->getTestUser()->getUser();
		$this->overrideUserPermissions( $user, [ 'noratelimit' ] );
		$context->setUser( $user );

		$rp = ( new \ReflectionClass( APIChat::class ) )->getProperty( 'dailyTokenBudget' );
		$budgetSnapshot = $rp->getValue( null );

		$stub = new StubAPIChat( $main, 'wandachat' );
		$stub->setLlmResult( $llmResult );
		$stub->setTestUser( $user );

		$rp->setValue( null, $budgetSnapshot );

		$stub->execute();

		return $stub->getResult()->getResultData( [], [ 'Strip' => 'all' ] );
	}

	public function testBudgetDisabledSkipsCheckOnSuccessfulResponse(): void {
		$this->setBudget( 0 );
		$this->setMaxTokens( 500 );

		$result = $this->executeWithLlmResult( [
			'text'   => 'Hello from LLM',
			'tokens' => 400,
			'error'  => false,
		] );

		$this->assertSame( 'Hello from LLM', $result['response'] ?? null,
			'Response must pass through when budget is disabled.' );
		$this->assertArrayNotHasKey( 'budget_exceeded', $result,
			'budget_exceeded must not appear when budget is disabled.' );
	}

	public function testBudgetNotConsumedWhenLlmResultHasErrorFlag(): void {
		$this->setBudget( 100 );
		$this->setMaxTokens( 50 );

		$result = $this->executeWithLlmResult( [
			'text'   => 'Some error text from provider',
			'tokens' => 50,
			// error flag set
			'error'  => true,
		] );

		$this->assertArrayNotHasKey( 'budget_exceeded', $result,
			'budget_exceeded must not appear when the LLM result carries an error flag.' );
	}

	public function testBudgetNotConsumedWhenActualTokensIsZero(): void {
		$this->setBudget( 100 );

		$result = $this->executeWithLlmResult( [
			'text'   => 'Zero-token response',
			// no tokens reported
			'tokens' => 0,
			'error'  => false,
		] );

		$this->assertArrayNotHasKey( 'budget_exceeded', $result,
			'budget_exceeded must not appear when actualTokens is zero.' );
		$this->assertSame( 'Zero-token response', $result['response'] ?? null );
	}

	public function testBudgetWithinLimitAllowsResponseThrough(): void {
		$this->setBudget( 1000 );

		$result = $this->executeWithLlmResult( [
			'text'   => 'A normal answer',
			'tokens' => 200,
			'error'  => false,
		] );

		$this->assertSame( 'A normal answer', $result['response'] ?? null,
			'Response must pass through when tokens are within the daily budget.' );
		$this->assertArrayNotHasKey( 'budget_exceeded', $result );
	}

	public function testRemainingIsZeroWhenBudgetAlreadyFullyExhausted(): void {
		$this->setBudget( 100 );

		$user     = $this->normalUser();
		$utcDay   = gmdate( 'Y-m-d' );
		$identity = 'user:' . $user->getId();
		$key      = $this->stash->makeKey( 'wanda', 'token-budget', $identity, $utcDay );
		$this->stash->incrWithInit( $key, 86400, 100, 100 );

		$wrapper = $this->chatAs( $user );
		$error   = $wrapper->checkAndConsumeTokenBudget( 50 );

		$this->assertIsString( $error, 'Fully-exhausted budget must return an error string.' );
		$this->assertSame(
			wfMessage( 'wanda-api-error-budget-exceeded', 100, 0 )->text(),
			$error,
			'Error must use the budget-exceeded message with 0 tokens remaining.'
		);
	}

	public function testIncrWithInitReceivesSameValueAndInitAsActualTokens(): void {
		$this->setBudget( 1000 );

		$capturedValue = null;
		$capturedInit  = null;

		$stashSpy = $this->createMock( \BagOStuff::class );
		$stashSpy->method( 'makeKey' )
			->willReturnCallback( static fn ( ...$p ) => implode( ':', $p ) );
		$stashSpy->method( 'incrWithInit' )
			->willReturnCallback(
				static function ( $key, $ttl, $value, $init )
					use ( &$capturedValue, &$capturedInit ) {
					$capturedValue = $value;
					$capturedInit  = $init;
					// return the init value as newUsed (within budget)
					return $init;
				}
			);
		$this->setService( 'MainObjectStash', $stashSpy );

		$actualTokens = 237;
		$wrapper = $this->chatAs( $this->normalUser() );
		$wrapper->checkAndConsumeTokenBudget( $actualTokens );

		$this->assertSame( $actualTokens, $capturedValue,
			'incrWithInit $value must equal $actualTokens.' );
		$this->assertSame( $actualTokens, $capturedInit,
			'incrWithInit $init must equal $actualTokens so the first call creates and charges atomically.' );
		$this->assertSame( $capturedValue, $capturedInit,
			'$value and $init must be identical.' );
	}
}
