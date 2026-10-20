<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaEditHandler;
use MediaWiki\Extension\Wanda\WandaEditPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure contract tests for scope inference; no MediaWiki services or LLM required.
 * @covers \MediaWiki\Extension\Wanda\WandaEditPlanner
 * @group Wanda
 */
class WandaEditPlannerTest extends TestCase {

	private function planner(): WandaEditPlanner {
		// The handler is unused for scope inference; a stub avoids its constructor.
		return new WandaEditPlanner( $this->createMock( WandaEditHandler::class ) );
	}

	private function scope( string $instruction ): array {
		return $this->planner()->plan( $instruction, [ 'wiki' ], false )['scope'];
	}

	public function testSinglePageRequestUsesSingleScopeAndNoResearch(): void {
		$plan = $this->planner()->plan( 'Fix the typo', [ 'wiki' ], true );
		$this->assertSame( [ 'type' => 'single', 'query' => '' ], $plan['scope'] );
		$this->assertSame( '', $plan['researchQuery'] );
		$this->assertSame( [], $plan['sources'] );
	}

	public function testEmptyInstructionRejected(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wanda-plan-empty' );
		$this->planner()->plan( '   ', [ 'wiki' ], false );
	}

	public function testNonWikiSourcesBecomeResearch(): void {
		$plan = $this->planner()->plan( 'Summarize sales', [ 'wiki', 'cargo' ], false );
		$this->assertSame( 'Summarize sales', $plan['researchQuery'] );
		$this->assertSame( [ 'cargo' ], $plan['sources'] );
	}

	/** @dataProvider provideReplacementInstructions */
	public function testFindReplaceSourceTermDrivesSearchScope( string $instruction, string $expected ): void {
		$this->assertSame( [ 'type' => 'search', 'query' => $expected ], $this->scope( $instruction ) );
	}

	public static function provideReplacementInstructions(): array {
		return [
			'misspelled all-the-occurrence filler stripped' => [
				'Replace all the occurence of Sanjay to Sanjai', 'Sanjay',
			],
			'quoted pair keeps the source token' => [
				"Change 'colour' to 'color' across the wiki", 'colour',
			],
			'bare change verb' => [ 'change colour to color', 'colour' ],
			'occurrences-of filler stripped' => [
				'Replace every occurrence of foo with bar', 'foo',
			],
			'multi-word source retained' => [
				"Change the value of John Smith to 555", 'John Smith',
			],
		];
	}

	public function testCategoryScopeWinsOverReplacementTerm(): void {
		$this->assertSame(
			[ 'type' => 'category', 'query' => 'Plants' ],
			$this->scope( 'Replace foo with bar in Category:Plants' )
		);
		$this->assertSame(
			[ 'type' => 'category', 'query' => 'Prime Ministers of India' ],
			$this->scope( 'Find pages in Category:Prime Ministers of India and add a See also section' )
		);
		$this->assertSame(
			[ 'type' => 'category', 'query' => 'Restaurants' ],
			$this->scope( 'In [[Category:Restaurants]] add [[Category:Dining]]' )
		);
	}

	/** @dataProvider provideSiteWideInstructions */
	public function testTermlessSiteWideRequestsEnumerateAllPages( string $instruction ): void {
		$this->assertSame( [ 'type' => 'allpages', 'query' => '' ], $this->scope( $instruction ) );
	}

	public static function provideSiteWideInstructions(): array {
		return [
			[ 'Add a cleanup notice to every page' ],
			[ 'Append a footer across the wiki' ],
			[ 'Add categories site-wide' ],
			[ 'across all pages in the wiki, add [[Category:Reviewed]] at the bottom' ],
			[ 'On all the articles in the whole wiki, add [[Category:Reviewed]]' ],
		];
	}

	public function testPlainInstructionFallsBackToSemanticScope(): void {
		$this->assertSame(
			[ 'type' => 'semantic', 'query' => 'Fix the spelling errors' ],
			$this->scope( 'Fix the spelling errors' )
		);
	}

	public function testTopicInstructionExtractsCleanTopicAndIgnoresWikitextCategoryTag(): void {
		$this->assertSame(
			[ 'type' => 'semantic', 'query' => 'football players' ],
			$this->scope( 'Find all pages about football players and add [[Category:Footballers]] at the bottom' )
		);
		$this->assertSame(
			[ 'type' => 'semantic', 'query' => 'presidents or prime ministers' ],
			$this->scope( 'Find pages about presidents or prime ministers and add a "== See also ==" section' )
		);
		$this->assertSame(
			[ 'type' => 'semantic', 'query' => 'computer pioneers or mathematicians' ],
			$this->scope( 'Update articles discussing computer pioneers or mathematicians to include a Legacy section' )
		);
		$this->assertSame(
			[ 'type' => 'semantic', 'query' => 'artificial intelligence models' ],
			$this->scope( 'Change all pages about artificial intelligence models by adding [[Category:AI]]' )
		);
	}
}
