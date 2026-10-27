<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\APIChat;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\APIChat::getOpenAITokenKeyForModel
 * @covers \MediaWiki\Extension\Wanda\APIChat::filterReadableHits
 * @covers \MediaWiki\Extension\Wanda\APIChat::extractCitedSources
 * @group Wanda
 */
class APIChatTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideModels
	 */
	public function testGetOpenAITokenKeyForModel( string $model, string $expected ) {
		$this->assertSame( $expected, APIChat::getOpenAITokenKeyForModel( $model ) );
	}

	private static function hit( string $title ): array {
		return [ '_source' => [ 'title' => $title ] ];
	}

	public function testFilterReadableHitsKeepsOnlyReadablePages() {
		$hits = [
			self::hit( 'Public Page' ),
			self::hit( 'Secret Page' ),
			self::hit( 'Another Public Page' ),
		];

		// Predicate simulating a user who cannot read the "Secret" page.
		$canRead = static function ( string $title ): bool {
			return strpos( $title, 'Secret' ) === false;
		};

		$filtered = APIChat::filterReadableHits( $hits, $canRead );

		$this->assertSame(
			[ 'Public Page', 'Another Public Page' ],
			array_column( array_column( $filtered, '_source' ), 'title' )
		);
	}

	public function testFilterReadableHitsPreservesOrder() {
		$hits = [ self::hit( 'A' ), self::hit( 'B' ), self::hit( 'C' ) ];
		$filtered = APIChat::filterReadableHits( $hits, static fn () => true );
		$this->assertSame(
			[ 'A', 'B', 'C' ],
			array_column( array_column( $filtered, '_source' ), 'title' )
		);
	}

	public function testFilterReadableHitsDropsHitsWithoutTitle() {
		$hits = [
			self::hit( 'Has Title' ),
			[ '_source' => [] ],
			[ '_source' => [ 'title' => '' ] ],
		];
		// Predicate would accept everything; empty/missing titles must still be dropped.
		$filtered = APIChat::filterReadableHits( $hits, static fn () => true );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'Has Title', $filtered[0]['_source']['title'] );
	}

	public function testFilterReadableHitsEmptyInput() {
		$this->assertSame( [], APIChat::filterReadableHits( [], static fn () => true ) );
	}

	public function testFilterReadableHitsNoneReadable() {
		$hits = [ self::hit( 'A' ), self::hit( 'B' ) ];
		$this->assertSame( [], APIChat::filterReadableHits( $hits, static fn () => false ) );
	}

	private const PAGES = [ 'Alpha', 'Beta', 'Gamma' ];

	public function testExtractCitedSourcesKeepsOnlyCitedPages() {
		$result = APIChat::extractCitedSources( "Beta is a letter.\n<SOURCES>P2</SOURCES>", self::PAGES );
		$this->assertSame( 'Beta is a letter.', $result['text'] );
		$this->assertSame( [ 'Beta' ], $result['titles'] );
	}

	public function testExtractCitedSourcesIgnoresUnknownAndDuplicateIds() {
		$result = APIChat::extractCitedSources( "Answer\n<SOURCES>P3, P9, P0, p3, P1</SOURCES>", self::PAGES );
		$this->assertSame( [ 'Alpha', 'Gamma' ], $result['titles'] );
	}

	public function testExtractCitedSourcesAcceptsExactTitlesButNotInventedOnes() {
		$result = APIChat::extractCitedSources( "Answer\n<SOURCES>gamma, Delta</SOURCES>", self::PAGES );
		$this->assertSame( [ 'Gamma' ], $result['titles'] );
	}

	public function testExtractCitedSourcesNoneMeansNoPages() {
		$result = APIChat::extractCitedSources( "I don't know.\n<SOURCES>none</SOURCES>", self::PAGES );
		$this->assertSame( "I don't know.", $result['text'] );
		$this->assertSame( [], $result['titles'] );
	}

	/**
	 * @dataProvider provideLooseTrailers
	 */
	public function testExtractCitedSourcesToleratesLooseFormatting( string $body, ?array $expected ) {
		$result = APIChat::extractCitedSources( "Answer\n<SOURCES>$body</SOURCES>", self::PAGES );
		$this->assertSame( 'Answer', $result['text'] );
		$this->assertSame( $expected, $result['titles'] );
	}

	public static function provideLooseTrailers() {
		return [
			'and-separated' => [ 'P1 and P3', [ 'Alpha', 'Gamma' ] ],
			'semicolons' => [ 'P1; P3', [ 'Alpha', 'Gamma' ] ],
			'trailing period' => [ 'P1, P3.', [ 'Alpha', 'Gamma' ] ],
			'empty' => [ '', [] ],
			'unresolvable text' => [ 'foo', null ],
			'only unknown ids' => [ 'P7', null ],
		];
	}

	public function testExtractCitedSourcesRemovesEmptyCodeFence() {
		$result = APIChat::extractCitedSources( "Answer\n```\n<SOURCES>P1</SOURCES>\n```", self::PAGES );
		$this->assertSame( 'Answer', $result['text'] );
		$this->assertSame( [ 'Alpha' ], $result['titles'] );
	}

	public function testExtractCitedSourcesMissingTrailerReturnsNull() {
		$result = APIChat::extractCitedSources( 'Plain answer', self::PAGES );
		$this->assertSame( 'Plain answer', $result['text'] );
		$this->assertNull( $result['titles'] );
	}

	public function testExtractCitedSourcesUnclosedTrailerIsStrippedButNotTrusted() {
		$result = APIChat::extractCitedSources( "Answer\n<SOURCES>P1, P", self::PAGES );
		$this->assertSame( 'Answer', $result['text'] );
		$this->assertNull( $result['titles'] );
	}

	public function testExtractCitedSourcesUsesLastTrailerAndStripsDecoration() {
		$text = "Alpha [P1] and Gamma [P1, P3].\n**Sources:** `<SOURCES>P2</SOURCES>`\n<SOURCES>[P1], [P3]</SOURCES>";
		$result = APIChat::extractCitedSources( $text, self::PAGES );
		$this->assertSame( 'Alpha and Gamma.', $result['text'] );
		$this->assertSame( [ 'Alpha', 'Gamma' ], $result['titles'] );
	}

	public function testExtractCitedSourcesLeavesBracketsAloneWithoutWikiPages() {
		$result = APIChat::extractCitedSources( 'Use slot [P1].', [] );
		$this->assertSame( 'Use slot [P1].', $result['text'] );
		$this->assertNull( $result['titles'] );
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
}
