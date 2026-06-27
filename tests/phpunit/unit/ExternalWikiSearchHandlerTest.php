<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\ExternalWikiSearchHandler;
use MediaWikiUnitTestCase;
use ReflectionClass;

/**
 * @covers \MediaWiki\Extension\Wanda\ExternalWikiSearchHandler
 * @group Wanda
 */
class ExternalWikiSearchHandlerTest extends MediaWikiUnitTestCase {

	/**
	 * Build a handler with a minimal allowed-wiki set.
	 *
	 * @param array $wikis Keyed wiki config as in $wgWandaExternalWikis
	 * @param array $opts Override constructor defaults
	 * @return ExternalWikiSearchHandler
	 */
	private function newHandler( array $wikis = [], array $opts = [] ): ExternalWikiSearchHandler {
		return new ExternalWikiSearchHandler(
			$wikis,
			$opts['maxResults'] ?? 3,
			$opts['extractLen'] ?? 10000,
			$opts['timeout'] ?? 10,
			$opts['minESScore'] ?? 0.0,
			$opts['defaultNamespaces'] ?? [ 0 ]
		);
	}

	/**
	 * Invoke a private/protected method via reflection.
	 *
	 * @param ExternalWikiSearchHandler $handler
	 * @param string $method
	 * @param mixed ...$args
	 * @return mixed
	 */
	private function call( ExternalWikiSearchHandler $handler, string $method, ...$args ) {
		$m = ( new ReflectionClass( $handler ) )->getMethod( $method );
		return $m->invokeArgs( $handler, $args );
	}

	/**
	 * Seed the static capability cache via reflection so tests that call
	 * methods depending on probed capabilities never make real HTTP requests.
	 *
	 * @param ExternalWikiSearchHandler $handler
	 * @param string $baseUrl Already-normalised URL (lowercase, no trailing slash)
	 * @param array $caps Partial capability array — missing keys use defaults
	 */
	private function seedCapCache(
		ExternalWikiSearchHandler $handler,
		string $baseUrl,
		array $caps = []
	): void {
		$defaults = [ 'hasTextExtracts' => false, 'wikibaseItemProp' => false ];
		$rc = new ReflectionClass( $handler );
		$prop = $rc->getProperty( 'capabilityCache' );
		$current = $prop->getValue( null ) ?: [];
		$current[$baseUrl] = array_merge( $defaults, $caps );
		$prop->setValue( null, $current );
	}

	protected function setUp(): void {
		parent::setUp();
		$rc = new ReflectionClass( ExternalWikiSearchHandler::class );
		$rc->getProperty( 'capabilityCache' )->setValue( null, [] );
	}

	// test if the apiUrl() method builds the correct URL with query parameters

	public function testApiUrlBuildsCorrectly(): void {
		$url = $this->call( $this->newHandler(), 'apiUrl', 'https://en.wikipedia.org', [
			'action' => 'query',
			'format' => 'json',
		] );
		$this->assertSame(
			'https://en.wikipedia.org/w/api.php?action=query&format=json',
			$url
		);
	}

	// isAllowed for cases of true for configured wiki, false for unconfigured wiki, and false for empty wiki list

	public function testIsAllowedReturnsTrueForConfiguredWiki(): void {
		$handler = $this->newHandler( [
			'Wikipedia (English)' => [ 'url' => 'https://en.wikipedia.org' ],
		] );
		$this->assertTrue(
			$this->call( $handler, 'isAllowed', 'https://en.wikipedia.org' )
		);
	}

	public function testIsAllowedReturnsFalseForUnconfiguredWiki(): void {
		$handler = $this->newHandler( [
			'Wikipedia (English)' => [ 'url' => 'https://en.wikipedia.org' ],
		] );
		$this->assertFalse(
			$this->call( $handler, 'isAllowed', 'https://evil.example.com' )
		);
	}

	public function testIsAllowedReturnsFalseForEmptyWikiList(): void {
		$this->assertFalse(
			$this->call( $this->newHandler(), 'isAllowed', 'https://en.wikipedia.org' )
		);
	}

	// strategyLabel for cases for TextExtracts-capable wiki, non-capable wiki, and unprobed wiki

	public function testStrategyLabelExtractsWhenCapable(): void {
		$handler = $this->newHandler();
		$this->seedCapCache( $handler, 'https://en.wikipedia.org', [ 'hasTextExtracts' => true ] );
		$this->assertSame(
			'prop=extracts',
			$this->call( $handler, 'strategyLabel', 'https://en.wikipedia.org' )
		);
	}

	public function testStrategyLabelRevisionsWhenNoTextExtracts(): void {
		$handler = $this->newHandler();
		$this->seedCapCache( $handler, 'https://plain.wiki.org', [ 'hasTextExtracts' => false ] );
		$this->assertSame(
			'prop=revisions (wikitext strip)',
			$this->call( $handler, 'strategyLabel', 'https://plain.wiki.org' )
		);
	}

	public function testStrategyLabelDefaultsToRevisionsWhenNotProbed(): void {
		$this->assertSame(
			'prop=revisions (wikitext strip)',
			$this->call( $this->newHandler(), 'strategyLabel', 'https://unknown.wiki' )
		);
	}

	// parseCapabilities for cases of both flags present and empty extensions array

	public function testParseCapabilitiesDetectsBothFlags(): void {
		$data = [ 'query' => [ 'extensions' => [
			[ 'name' => 'TextExtracts' ],
			[ 'name' => 'WikibaseClient' ],
		] ] ];
		$caps = $this->call( $this->newHandler(), 'parseCapabilities', $data );
		$this->assertTrue( $caps['hasTextExtracts'] );
		$this->assertTrue( $caps['wikibaseItemProp'] );
	}

	public function testParseCapabilitiesReturnsDefaultsForEmptyExtensions(): void {
		$data = [ 'query' => [ 'extensions' => [] ] ];
		$caps = $this->call( $this->newHandler(), 'parseCapabilities', $data );
		$this->assertFalse( $caps['hasTextExtracts'] );
		$this->assertFalse( $caps['wikibaseItemProp'] );
	}

	// parseExtractResponse for cases of extracts strategy, wikibase item present, missing page, and multiple pages

	private function makeExtractsPage( array $overrides = [] ): array {
		return array_merge( [
			'pageid'       => 1,
			'title'        => 'Test Article',
			'extract'      => 'This is the extract.',
			'canonicalurl' => 'https://en.wikipedia.org/wiki/Test_Article',
			'pageprops'    => [],
		], $overrides );
	}

	public function testParseExtractResponseExtractsStrategy(): void {
		$data = [ 'query' => [ 'pages' => [
			$this->makeExtractsPage(),
		] ] ];
		$pages = $this->call(
			$this->newHandler(), 'parseExtractResponse', $data, 'extracts', 'https://en.wikipedia.org'
		);
		$this->assertCount( 1, $pages );
		$this->assertSame( 'Test Article', $pages[0]['title'] );
		$this->assertSame( 'This is the extract.', $pages[0]['extract'] );
		$this->assertSame( 'https://en.wikipedia.org/wiki/Test_Article', $pages[0]['url'] );
		$this->assertNull( $pages[0]['wikibaseItem'] );
	}

	public function testParseExtractResponseExtractsWikibaseItem(): void {
		$data = [ 'query' => [ 'pages' => [
			$this->makeExtractsPage( [ 'pageprops' => [ 'wikibase_item' => 'Q42' ] ] ),
		] ] ];
		$pages = $this->call(
			$this->newHandler(), 'parseExtractResponse', $data, 'extracts', 'https://en.wikipedia.org'
		);
		$this->assertSame( 'Q42', $pages[0]['wikibaseItem'] );
	}

	public function testParseExtractResponseSkipsMissingPages(): void {
		$data = [ 'query' => [ 'pages' => [
			$this->makeExtractsPage( [ 'missing' => true, 'extract' => '' ] ),
		] ] ];
		$pages = $this->call(
			$this->newHandler(), 'parseExtractResponse', $data, 'extracts', 'https://en.wikipedia.org'
		);
		$this->assertCount( 0, $pages );
	}

	public function testParseExtractResponseHandlesMultiplePages(): void {
		$data = [ 'query' => [ 'pages' => [
			$this->makeExtractsPage( [ 'pageid' => 1, 'title' => 'Page A', 'extract' => 'Extract A' ] ),
			$this->makeExtractsPage( [ 'pageid' => 2, 'title' => 'Page B', 'extract' => 'Extract B' ] ),
		] ] ];
		$pages = $this->call(
			$this->newHandler(), 'parseExtractResponse', $data, 'extracts', 'https://en.wikipedia.org'
		);
		$this->assertCount( 2, $pages );
		$this->assertSame( 'Page A', $pages[0]['title'] );
		$this->assertSame( 'Page B', $pages[1]['title'] );
	}

	// parseExtractResponse to check no wikitext remains in the extract when using revisions strategy and
	//  length is truncated to extractLen

	public function testParseExtractResponseRevisionsStripsWikitext(): void {
		$wikitext = "{{Infobox}}\n'''Marie Curie''' was a physicist.";
		$data = [ 'query' => [ 'pages' => [ [
			'pageid'       => 1,
			'title'        => 'Marie Curie',
			'canonicalurl' => 'https://plain.wiki/wiki/Marie_Curie',
			'revisions'    => [ [
				'slots' => [ 'main' => [ 'content' => $wikitext ] ]
			] ],
		] ] ] ];
		$pages = $this->call(
			$this->newHandler( [], [ 'extractLen' => 500 ] ),
			'parseExtractResponse', $data, 'revisions', 'https://plain.wiki'
		);
		$this->assertCount( 1, $pages );
		$this->assertStringNotContainsString( '{{', $pages[0]['extract'] );
		$this->assertStringNotContainsString( "'''", $pages[0]['extract'] );
		$this->assertStringContainsString( 'Marie Curie', $pages[0]['extract'] );
	}

	public function testParseExtractResponseRevisionsTruncatesToExtractLen(): void {
		$longWikitext = str_repeat( 'word ', 500 );
		$data = [ 'query' => [ 'pages' => [ [
			'pageid'    => 1,
			'title'     => 'Long Page',
			'revisions' => [ [
				'slots' => [ 'main' => [ 'content' => $longWikitext ] ]
			] ],
		] ] ] ];
		$pages = $this->call(
			$this->newHandler( [], [ 'extractLen' => 100 ] ),
			'parseExtractResponse', $data, 'revisions', 'https://plain.wiki'
		);
		$this->assertCount( 1, $pages );
		$this->assertLessThanOrEqual( 100, mb_strlen( $pages[0]['extract'] ) );
	}

	// query() gating by wikis defined in the constructor, returning empty results when no wikis are configured

	public function testQueryReturnsEmptyWhenNoWikisConfigured(): void {
		$result = $this->newHandler()->query( 'test query' );
		$this->assertSame( '', $result['content'] );
		$this->assertSame( [], $result['sources'] );
		$this->assertSame( 0, $result['num_results'] );
		$this->assertSame( [], $result['steps'] );
	}

	// defaultNamespaces and per-wiki namespaces override

	public function testDefaultNamespacesUsedWhenWikiHasNone(): void {
		$handler = $this->newHandler(
			[ 'Wikipedia (English)' => [ 'url' => 'https://en.wikipedia.org' ] ],
			[ 'defaultNamespaces' => [ 0, 4 ] ]
		);
		$rc = new ReflectionClass( $handler );
		$prop = $rc->getProperty( 'defaultNamespaces' );
		$this->assertSame( [ 0, 4 ], $prop->getValue( $handler ) );
	}

	public function testPerWikiNamespacesOverrideDefault(): void {
		$handler = $this->newHandler(
			[ 'My Wiki' => [ 'url' => 'https://wiki.example.org', 'namespaces' => [ 0, 100 ] ] ],
			[ 'defaultNamespaces' => [ 0 ] ]
		);
		$rc = new ReflectionClass( $handler );
		$wikis = $rc->getProperty( 'allowedWikis' )->getValue( $handler );
		$this->assertSame( [ 0, 100 ], $wikis['My Wiki']['namespaces'] );
	}
}
