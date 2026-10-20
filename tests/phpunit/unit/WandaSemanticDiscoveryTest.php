<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaSemanticDiscovery;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\WandaSemanticDiscovery
 * @group Wanda
 */
class WandaSemanticDiscoveryTest extends MediaWikiUnitTestCase {

	public function testFuseRankingsCombinesDualRetrieversWithRrf() {
		// Vector retriever ranks PageA first, PageB second.
		$vectorTitles = [ 'PageA', 'PageB' ];
		// Keyword retriever ranks PageB first, PageC second.
		$keywordTitles = [ 'PageB', 'PageC' ];

		// PageB has rank 2 in vector (1/62) and rank 1 in keyword (1/61): score = 1/62 + 1/61 = 0.03252.
		// PageA has rank 1 in vector (1/61) only: score = 0.01639.
		// PageC has rank 2 in keyword (1/62) only: score = 0.01613.
		$fused = WandaSemanticDiscovery::fuseRankings( [ $vectorTitles, $keywordTitles ] );

		$this->assertSame( [ 'PageB', 'PageA', 'PageC' ], $fused );
	}

	public function testFuseRankingsSingleRetrieverPreservesOrder() {
		$titles = [ 'Alpha', 'Beta', 'Gamma' ];
		$fused = WandaSemanticDiscovery::fuseRankings( [ $titles ] );
		$this->assertSame( $titles, $fused );
	}

	public function testFuseRankingsEmptyInput() {
		$this->assertSame( [], WandaSemanticDiscovery::fuseRankings( [] ) );
		$this->assertSame( [], WandaSemanticDiscovery::fuseRankings( [ [], [] ] ) );
	}

	public function testFuseRankingsIgnoresDuplicatesWithinSingleList() {
		$titlesWithDups = [ 'First', 'First', 'Second' ];
		$fused = WandaSemanticDiscovery::fuseRankings( [ $titlesWithDups ] );
		$this->assertSame( [ 'First', 'Second' ], $fused );
	}

	public function testFilterKeywordHitsDropsLowScoreTail() {
		$hits = [
			[ '_score' => 10.0, '_source' => [ 'title' => 'Top Hit' ] ],
			[ '_score' => 5.0, '_source' => [ 'title' => 'Mid Hit' ] ],
			[ '_score' => 3.0, '_source' => [ 'title' => 'Cutoff Hit' ] ],
			[ '_score' => 1.0, '_source' => [ 'title' => 'Noise Hit' ] ],
		];

		// KEYWORD_SCORE_FRACTION is 0.3, so cutoff floor = 10.0 * 0.3 = 3.0.
		// Hits with score < 3.0 (such as 1.0) should be dropped.
		$filtered = WandaSemanticDiscovery::filterKeywordHits( $hits );

		$this->assertCount( 3, $filtered );
		$this->assertSame(
			[ 'Top Hit', 'Mid Hit', 'Cutoff Hit' ],
			array_column( array_column( $filtered, '_source' ), 'title' )
		);
	}

	public function testFilterKeywordHitsDropsEmptyTitles() {
		$hits = [
			[ '_score' => 10.0, '_source' => [ 'title' => '' ] ],
			[ '_score' => 9.0, '_source' => [] ],
			[ '_score' => 8.0, '_source' => [ 'title' => 'Valid Title' ] ],
		];

		$filtered = WandaSemanticDiscovery::filterKeywordHits( $hits );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'Valid Title', $filtered[0]['_source']['title'] );
	}

	public function testFilterVectorHitsDropsLowSimilarityAndBelowMinimumFloor() {
		$hits = [
			[ '_score' => 1.60, '_source' => [ 'title' => 'Strong Match' ] ],
			[ '_score' => 1.56, '_source' => [ 'title' => 'Close Match' ] ],
			[ '_score' => 1.48, '_source' => [ 'title' => 'Baseline Noise' ] ],
		];

		$filtered = WandaSemanticDiscovery::filterVectorHits( $hits );
		$this->assertSame(
			[ 'Strong Match', 'Close Match' ],
			array_column( array_column( $filtered, '_source' ), 'title' )
		);

		$onlyNoise = [
			[ '_score' => 1.45, '_source' => [ 'title' => 'Unrelated Page' ] ],
		];
		$this->assertSame( [], WandaSemanticDiscovery::filterVectorHits( $onlyNoise ) );
	}

	public function testStripQueryStopwordsRemovesConversationalAndWikiBoilerplate() {
		$this->assertSame(
			'Ada Lovelace',
			WandaSemanticDiscovery::stripQueryStopwords( 'Who is Ada Lovelace?' )
		);
		$this->assertSame(
			'soccer players player',
			WandaSemanticDiscovery::stripQueryStopwords( 'Who are the soccer players in this wiki?' )
		);
		$this->assertSame(
			'categories category matches match',
			WandaSemanticDiscovery::stripQueryStopwords( 'Find all categories and matches' )
		);
		$this->assertSame(
			'',
			WandaSemanticDiscovery::stripQueryStopwords( 'Who is in this wiki?' )
		);
	}
}
