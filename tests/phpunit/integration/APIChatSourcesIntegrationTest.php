<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApiMain;
use MediaWiki\Extension\Wanda\APIChat;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;
use RequestContext;

/**
 * @covers \MediaWiki\Extension\Wanda\APIChat::execute
 * @covers \MediaWiki\Extension\Wanda\APIChat::extractCitedSources
 * @group Wanda
 * @group Database
 */
class APIChatSourcesIntegrationTest extends MediaWikiIntegrationTestCase {

	private const SEARCH_RESULTS = [
		'content' => "--- [P1] From page: Alpha ---\nA\n\n--- [P2] From page: Beta ---\nB",
		'source' => 'Alpha | Beta',
		'titles' => [ 'Alpha', 'Beta' ],
		'num_results' => 2,
	];

	protected function setUp(): void {
		parent::setUp();
		( new \ReflectionClass( APIChat::class ) )->getProperty( 'skipESQuery' )->setValue( null, false );
		$this->overrideConfigValues( [
			'WandaDailyTokenBudget' => 0,
			'WandaExternalWikis' => [],
			'WandaDisabledSources' => [],
		] );
	}

	private function execute( string $text, array $extra = [] ): array {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [
			'action' => 'wandachat',
			'message' => 'hello',
			'format' => 'json',
		], true ) );
		$user = $this->getTestUser()->getUser();
		$this->overrideUserPermissions( $user, [ 'noratelimit', 'wanda-chat' ] );
		$context->setUser( $user );

		$stub = new StubAPIChat( new ApiMain( $context, true ), 'wandachat' );
		$stub->setTestUser( $user );
		$stub->setSearchResults( self::SEARCH_RESULTS );
		$stub->setLlmResult( [ 'text' => $text, 'tokens' => 10, 'error' => false ] + $extra );
		$stub->execute();

		return $stub->getResult()->getResultData( [], [ 'Strip' => 'all' ] );
	}

	public function testOnlyCitedPagesAreReturnedAndTrailerIsStripped(): void {
		$result = $this->execute( "Beta answer.\n<SOURCES>P2</SOURCES>" );

		$this->assertSame( 'Beta answer.', $result['response'] );
		$this->assertSame( [ 'Beta' ], $result['sources'] );
		$this->assertSame( 'Beta', $result['source'] );
	}

	public function testNoneCitedReturnsNoWikiSources(): void {
		$result = $this->execute( "Not in the wiki.\n<SOURCES>none</SOURCES>" );

		$this->assertSame( 'Not in the wiki.', $result['response'] );
		$this->assertArrayNotHasKey( 'sources', $result );
		$this->assertArrayNotHasKey( 'source', $result );
	}

	public function testMissingTrailerFallsBackToRetrievedPages(): void {
		$result = $this->execute( 'Answer without trailer.' );

		$this->assertSame( [ 'Alpha', 'Beta' ], $result['sources'] );
	}

	public function testPagesTruncatedFromPromptAreNeverReported(): void {
		$result = $this->execute( "Answer\n<SOURCES>P1, P2</SOURCES>", [ 'citablePages' => 1 ] );

		$this->assertSame( [ 'Alpha' ], $result['sources'] );
	}
}
