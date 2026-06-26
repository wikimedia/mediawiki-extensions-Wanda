<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use DatabaseUpdater;
use MediaWiki\Extension\Wanda\Hooks\SchemaUpdates;
use MediaWiki\Extension\Wanda\Maintenance\ReindexAllPages;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\Hooks\SchemaUpdates
 * @group Wanda
 */
class SchemaUpdatesTest extends MediaWikiIntegrationTestCase {

	public function testSchedulesReindexWhenAutoReindexEnabled() {
		$this->overrideConfigValue( 'WandaAutoReindex', true );

		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->expects( $this->once() )
			->method( 'addPostDatabaseUpdateMaintenance' )
			->with( ReindexAllPages::class );

		SchemaUpdates::onLoadExtensionSchemaUpdates( $updater );
	}

	public function testSkipsReindexWhenAutoReindexDisabled() {
		$this->overrideConfigValue( 'WandaAutoReindex', false );

		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->expects( $this->never() )
			->method( 'addPostDatabaseUpdateMaintenance' );

		SchemaUpdates::onLoadExtensionSchemaUpdates( $updater );
	}
}
