<?php

namespace MediaWiki\Extension\Wanda\Hooks;

use DatabaseUpdater;
use MediaWiki\Extension\Wanda\Maintenance\ReindexAllPages;
use MediaWiki\MediaWikiServices;

/**
 * Registers post-update maintenance tasks (automatic reindex after update.php)
 */
class SchemaUpdates {
	/**
	 * Hook: LoadExtensionSchemaUpdates
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$enabled = $config->has( 'WandaAutoReindex' ) ? (bool)$config->get( 'WandaAutoReindex' ) : true;

		if ( !$enabled ) {
			return;
		}

		// Only schedule if the maintenance class is available (defensive check)
		if ( class_exists( ReindexAllPages::class ) ) {
			$updater->addPostDatabaseUpdateMaintenance( ReindexAllPages::class );
		}
	}
}
