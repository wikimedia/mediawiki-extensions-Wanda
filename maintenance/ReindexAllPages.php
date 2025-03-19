<?php

namespace MediaWiki\Extension\Wikai\Maintenance;

use Maintenance;
use MediaWiki\Extension\Wikai\Hooks\PageIndexUpdater;
use MediaWiki\MediaWikiServices;
use Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

$maintClass = ReindexAllPages::class;

class ReindexAllPages extends Maintenance {
	public function execute() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select( 'page', [ 'page_title', 'page_namespace' ], [], __METHOD__ );

		foreach ( $res as $row ) {
			$title = Title::newFromText( $row->page_title, $row->page_namespace );
			if ( !$title ) {
				wfDebugLog( 'Chatbot', "Invalid title found in database: " . $row->page_title );
				continue;
			}

			wfDebugLog( 'Chatbot', "Reindexing page => " . $title->getPrefixedText() );

			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			if ( !$wikiPage ) {
				wfDebugLog( 'Chatbot', "Failed to load WikiPage for " . $title->getPrefixedText() );
				continue;
			}

			PageIndexUpdater::updateIndex( $title, $wikiPage );
		}
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;
