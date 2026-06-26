<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use MediaWiki\Extension\Wanda\CargoQueryHandler;
use MediaWikiIntegrationTestCase;

/**
 * buildSources() depends on MediaWiki's Title and SpecialPage services, so it is
 * exercised as an integration test rather than a pure unit test.
 *
 * @covers \MediaWiki\Extension\Wanda\CargoQueryHandler::buildSources
 * @group Wanda
 */
class CargoQueryHandlerSourcesTest extends MediaWikiIntegrationTestCase {

	private function newHandler(): CargoQueryHandler {
		return new CargoQueryHandler( 'ollama', 'm', '', 'http://x/', 30 );
	}

	public function testBuildSourcesFromRowPageNames() {
		$sources = $this->newHandler()->buildSources( [
			[ '_pageName' => 'Alice', 'Salary' => '100' ],
			[ '_pageName' => 'Bob', 'Salary' => '200' ],
			// Duplicate page name is collapsed.
			[ '_pageName' => 'Alice', 'Salary' => '150' ],
		], 'Employees=E,Departments=D' );

		$this->assertCount( 2, $sources );
		$this->assertSame( 'Alice', $sources[0]['title'] );
		$this->assertSame( 'Bob', $sources[1]['title'] );
		foreach ( $sources as $source ) {
			$this->assertSame( 'cargo', $source['type'] );
			// Alias is stripped: the primary table is the real name "Employees".
			$this->assertSame( 'Employees', $source['cargoTable'] );
			$this->assertNotSame( '', $source['href'] );
		}
	}

	public function testBuildSourcesAggregateFallsBackToTableCitations() {
		// No _pageName in any row (an aggregate query): cite each table instead.
		$sources = $this->newHandler()->buildSources( [
			[ 'COUNT' => '42' ],
		], 'Employees,Departments' );

		$titles = array_column( $sources, 'title' );
		$this->assertContains( 'Employees', $titles );
		$this->assertContains( 'Departments', $titles );
		foreach ( $sources as $source ) {
			$this->assertSame( 'cargo', $source['type'] );
		}
	}

	public function testBuildSourcesEmptyRows() {
		$this->assertSame( [], $this->newHandler()->buildSources( [], 'Employees' ) );
	}
}
