<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use MediaWiki\Extension\Wanda\SMWQueryHandler;
use MediaWikiIntegrationTestCase;

/**
 * buildSources() resolves each result subject through MediaWiki's Title and
 * SpecialPage services (it links to Special:Browse), so it is exercised as an
 * integration test rather than a pure unit test.
 *
 * @covers \MediaWiki\Extension\Wanda\SMWQueryHandler::buildSources
 * @group Wanda
 */
class SMWQueryHandlerSourcesTest extends MediaWikiIntegrationTestCase {

	private function newHandler(): SMWQueryHandler {
		return new SMWQueryHandler( 'ollama', 'm', '', 'http://x/', 30 );
	}

	public function testBuildSourcesFromRowPages() {
		$sources = $this->newHandler()->buildSources( [
			[ '_page' => 'Berlin', 'Population' => '3700000' ],
			[ '_page' => 'Munich', 'Population' => '1500000' ],
			// Duplicate subject page is collapsed.
			[ '_page' => 'Berlin', 'Population' => '3700000' ],
		] );

		$this->assertCount( 2, $sources );
		$this->assertSame( 'Berlin', $sources[0]['title'] );
		$this->assertSame( 'Munich', $sources[1]['title'] );
		foreach ( $sources as $source ) {
			$this->assertSame( 'smw', $source['type'] );
			$this->assertNotSame( '', $source['href'] );
		}
	}

	public function testBuildSourcesSkipsRowsWithoutPage() {
		// Rows lacking a "_page" key (e.g. aggregate-style results) contribute no
		// citation; SMW has no table-level fallback like Cargo.
		$sources = $this->newHandler()->buildSources( [
			[ 'Population' => '3700000' ],
			[ '_page' => '', 'Population' => '1' ],
		] );

		$this->assertSame( [], $sources );
	}

	public function testBuildSourcesEmptyRows() {
		$this->assertSame( [], $this->newHandler()->buildSources( [] ) );
	}
}
