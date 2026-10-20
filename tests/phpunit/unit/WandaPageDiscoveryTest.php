<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaPageDiscovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure contract tests; no MediaWiki services, database, HTTP, or LLM required.
 * @covers \MediaWiki\Extension\Wanda\WandaPageDiscovery
 * @group Wanda
 */
class WandaPageDiscoveryTest extends TestCase {

	/** @dataProvider provideLimits */
	public function testNormalizeLimit( int $input, int $expected ): void {
		$this->assertSame( $expected, WandaPageDiscovery::normalizeLimit( $input ) );
	}

	public static function provideLimits(): array {
		return [ [ -1, 10 ], [ 0, 10 ], [ 1, 1 ], [ 10, 10 ], [ 50, 50 ], [ 10000, 50 ] ];
	}

	public function testNamespacesNormalizeAndDeduplicate(): void {
		$this->assertSame( [ 0, 14 ], WandaPageDiscovery::normalizeNamespaces(
			[ '0', 14, 0, '14' ], [ 0, 1, 14 ]
		) );
	}

	public function testEmptyNamespacesUseAllRealCoreNamespaces(): void {
		$this->assertSame( [ 0, 1, 14, 3000 ], WandaPageDiscovery::normalizeNamespaces(
			[], [ -2, -1, 0, 1, 14, 3000 ]
		) );
	}

	/** @dataProvider provideInvalidNamespaces */
	public function testInvalidNamespacesFailClosed( array $namespaces ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wanda-discovery-invalid-namespaces' );
		WandaPageDiscovery::normalizeNamespaces( $namespaces, [ 0, 1, 14 ] );
	}

	public static function provideInvalidNamespaces(): array {
		return [ [ [ -1 ] ], [ [ 99 ] ], [ [ '*' ] ], [ [ false ] ], [ [ 0.5 ] ], [ [ '0|1' ] ], [ [ [] ] ] ];
	}

	public function testNoValidNamespacesFailsClosed(): void {
		$this->expectException( RuntimeException::class );
		WandaPageDiscovery::normalizeNamespaces( [], [ -2, -1 ] );
	}

	public function testScopeDiscardsUntrustedFlags(): void {
		$this->assertSame( [ 'type' => 'search', 'query' => 'hello world' ],
			WandaPageDiscovery::normalizeScope( [
				'type' => 'search', 'query' => ' hello world ', 'action' => 'edit',
				'assert' => 'bot', 'continue' => '-||', 'titles' => [ 'Invented target' ],
			] )
		);
	}

	public function testCategoryScopeAcceptsBareOrLocalizedNames(): void {
		foreach ( [ 'Plants', 'Category:Plants', 'Kategorie:Pflanzen' ] as $query ) {
			$this->assertSame( [ 'type' => 'category', 'query' => $query ],
				WandaPageDiscovery::normalizeScope( [ 'type' => 'category', 'query' => $query ] )
			);
		}
	}

	public function testAllPagesScopeIgnoresAnyQuery(): void {
		$this->assertSame( [ 'type' => 'allpages', 'query' => '' ],
			WandaPageDiscovery::normalizeScope( [ 'type' => 'allpages', 'query' => 'ignored' ] )
		);
		$this->assertSame( [ 'type' => 'allpages', 'query' => '' ],
			WandaPageDiscovery::normalizeScope( [ 'type' => 'allpages', 'query' => '' ] )
		);
	}

	/** @dataProvider provideInvalidScopes */
	public function testInvalidScope( array $scope ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wanda-discovery-invalid-scope' );
		WandaPageDiscovery::normalizeScope( $scope );
	}

	public static function provideInvalidScopes(): array {
		return [
			[ [] ],
			[ [ 'type' => 'all', 'query' => 'x' ] ],
			[ [ 'type' => 'search', 'query' => [] ] ],
			[ [ 'type' => 'search', 'query' => '  ' ] ],
			[ [ 'type' => 'search', 'query' => "a\0b" ] ],
			[ [ 'type' => 'search', 'query' => "a\nb" ] ],
			[ [ 'type' => 'search', 'query' => "\xff" ] ],
			[ [ 'type' => 'search', 'query' => str_repeat( 'a', 4097 ) ] ],
		];
	}

	public function testSearchUsesOnlyBoundedKnownParametersWithoutSnippets(): void {
		$this->assertSame( [
			'action' => 'query',
			'list' => 'search',
			'srsearch' => 'hello',
			'srwhat' => 'text',
			'srnamespace' => '0|14',
			'srlimit' => 51,
			'srprop' => '',
			'srinfo' => 'totalhits',
		], WandaPageDiscovery::searchParameters( ' hello ', 10000, [ 0, 14 ] ) );
		$this->assertSame( 1, WandaPageDiscovery::searchParameters( 'x', -1, [ 0 ] )['srlimit'] );
	}

	/** @dataProvider provideTruncation */
	public function testTruncation( array $data, int $count, bool $expected ): void {
		$this->assertSame( $expected, WandaPageDiscovery::hasMoreResults( $data, $count, 11 ) );
	}

	public static function provideTruncation(): array {
		return [
			'empty' => [ [], 0, false ],
			'short' => [ [], 10, false ],
			'saturated even without metadata' => [ [], 11, true ],
			'modern continuation with no visible rows' => [ [ 'continue' => [ 'cmcontinue' => 'x' ] ], 0, true ],
			'legacy continuation' => [ [ 'query-continue' => [] ], 0, true ],
			'total exceeds filtered rows' => [ [ 'query' => [ 'searchinfo' => [ 'totalhits' => 100 ] ] ], 2, true ],
			'exact total' => [ [ 'query' => [ 'searchinfo' => [ 'totalhits' => 2 ] ] ], 2, false ],
			'backend warning' => [ [ 'warnings' => [ 'search' => 'incomplete' ] ], 0, true ],
		];
	}
}
