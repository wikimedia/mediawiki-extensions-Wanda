<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WikidataQueryHandler;
use MediaWikiUnitTestCase;
use ReflectionClass;

/**
 * @covers \MediaWiki\Extension\Wanda\WikidataQueryHandler
 * @group Wanda
 */
class WikidataQueryHandlerTest extends MediaWikiUnitTestCase {

	private function newHandler(): WikidataQueryHandler {
		return new WikidataQueryHandler(
			'ollama',
			'gemma:2b',
			'',
			'http://ollama:11434/api/',
			30
		);
	}

	/**
	 * Invoke a private/protected method via reflection.
	 * @param WikidataQueryHandler $handler
	 * @param string $method
	 * @param array $args
	 * @return mixed
	 */
	private function invoke( WikidataQueryHandler $handler, string $method, array $args ) {
		$m = ( new ReflectionClass( $handler ) )->getMethod( $method );
		return $m->invokeArgs( $handler, $args );
	}

	public function testCacheKey() {
		$this->assertSame(
			'en|item|France',
			$this->invoke( $this->newHandler(), 'cacheKey', [ 'France', 'item', 'en' ] )
		);
	}

	/**
	 * @dataProvider provideSparql
	 */
	public function testValidateSparql( string $sparql, bool $expected ) {
		$this->assertSame(
			$expected,
			$this->invoke( $this->newHandler(), 'validateSparql', [ $sparql ] )
		);
	}

	public static function provideSparql() {
		return [
			'valid SELECT' => [ 'SELECT ?x WHERE { ?x wdt:P31 wd:Q5 }', true ],
			'lowercase select' => [ 'select ?x where { ?x wdt:P31 wd:Q5 }', true ],
			'INSERT rejected' => [ 'INSERT DATA { wd:Q1 rdfs:label "x" }', false ],
			'DELETE rejected' => [ 'DELETE WHERE { ?x ?y ?z }', false ],
			'ASK without SELECT rejected' => [ 'ASK { ?x wdt:P31 wd:Q5 }', false ],
			'forbidden keyword inside string literal is ignored' => [
				'SELECT ?x WHERE { ?x rdfs:label ?l . FILTER(STR(?l) = "INSERT") }',
				true,
			],
		];
	}

	public function testEnsurePrefixesAddsMissingPrefixes() {
		$out = $this->invoke( $this->newHandler(), 'ensurePrefixes', [ 'SELECT ?x WHERE {}' ] );
		$this->assertStringContainsString(
			'PREFIX wd: <http://www.wikidata.org/entity/>',
			$out
		);
		$this->assertStringContainsString( 'SELECT ?x WHERE {}', $out );
	}

	public function testEnsurePrefixesDoesNotDuplicateExisting() {
		$sparql = "PREFIX wd: <http://www.wikidata.org/entity/>\nSELECT ?x WHERE {}";
		$out = $this->invoke( $this->newHandler(), 'ensurePrefixes', [ $sparql ] );
		$this->assertSame( 1, substr_count( $out, 'PREFIX wd:' ) );
	}

	public function testSubstituteEntitiesReplacesPlaceholder() {
		$out = $this->invoke( $this->newHandler(), 'substituteEntities', [
			'SELECT ?x WHERE { ?x wdt:P31 FRANCE }',
			[ [ 'placeholder' => 'FRANCE', 'id' => 'wd:Q142' ] ],
		] );
		$this->assertSame( 'SELECT ?x WHERE { ?x wdt:P31 wd:Q142 }', $out );
	}

	public function testSubstituteEntitiesReplacesLongestPlaceholderFirst() {
		// FRANCE must not corrupt FRANCE_REGION: longest placeholder is replaced first.
		$out = $this->invoke( $this->newHandler(), 'substituteEntities', [
			'FRANCE FRANCE_REGION',
			[
				[ 'placeholder' => 'FRANCE', 'id' => 'wd:Q142' ],
				[ 'placeholder' => 'FRANCE_REGION', 'id' => 'wd:Q999' ],
			],
		] );
		$this->assertSame( 'wd:Q142 wd:Q999', $out );
	}

	public function testParseSearchResponseRejectsNon200() {
		$this->assertNull(
			$this->invoke( $this->newHandler(), 'parseSearchResponse', [ '{"search":[]}', 500, 'height' ] )
		);
	}

	public function testParseSearchResponseRejectsEmptySearch() {
		$this->assertNull(
			$this->invoke( $this->newHandler(), 'parseSearchResponse', [ '{"search":[]}', 200, 'height' ] )
		);
	}

	public function testParseSearchResponsePrefersLabelMatch() {
		$body = json_encode( [ 'search' => [
			[ 'id' => 'P2044', 'label' => 'elevation above sea level',
				'description' => 'd1', 'match' => [ 'type' => 'alias' ] ],
			[ 'id' => 'P2048', 'label' => 'height',
				'description' => 'd2', 'match' => [ 'type' => 'label' ] ],
		] ] );
		$result = $this->invoke( $this->newHandler(), 'parseSearchResponse', [ $body, 200, 'height' ] );
		$this->assertSame( [
			'id' => 'P2048',
			'label' => 'height',
			'description' => 'd2',
		], $result );
	}

	public function testFormatResultsAsContextEmpty() {
		$this->assertSame(
			'',
			$this->invoke( $this->newHandler(), 'formatResultsAsContext', [ [] ] )
		);
	}

	public function testFormatResultsAsContextEscapesPipes() {
		$out = $this->invoke( $this->newHandler(), 'formatResultsAsContext', [ [
			[ 'item' => 'Q1', 'label' => 'a|b' ],
			[ 'item' => 'Q2', 'label' => 'c' ],
		] ] );
		$this->assertStringContainsString( '--- Wikidata results (2 rows) ---', $out );
		$this->assertStringContainsString( '| item | label |', $out );
		// The pipe inside the value is escaped so it does not break the table.
		$this->assertStringContainsString( 'a\\|b', $out );
	}

	public function testBuildSourcesFromEntitiesAndRows() {
		$sources = $this->newHandler()->buildSources(
			[ [ 'item' => 'Q42', 'other' => 'notAQid' ] ],
			[
				[ 'id' => 'Q142', 'label' => 'France' ],
				[ 'id' => 'Q142', 'label' => 'dup' ],
			]
		);

		// Deduplicated entity (Q142) + the Q-id discovered in the rows (Q42).
		$this->assertSame( [
			[
				'title' => 'France (Q142)',
				'href' => 'https://www.wikidata.org/wiki/Q142',
				'type' => 'wikidata',
			],
			[
				'title' => 'Q42',
				'href' => 'https://www.wikidata.org/wiki/Q42',
				'type' => 'wikidata',
			],
		], $sources );
	}
}
