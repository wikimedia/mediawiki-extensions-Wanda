<?php

namespace MediaWiki\Extension\Wikai\Maintenance;

use Maintenance;
use MediaWiki\Extension\Wikai\Hooks\PageIndexUpdater;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

$maintClass = ReindexAllPages::class;

class ReindexAllPages extends Maintenance {
	public function execute() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select( 'page', [ 'page_title' ], [], __METHOD__ );
		foreach ( $res as $row ) {
			$title = $row->page_title;
			wfDebugLog( 'Chatbot', "Reindexing page => " . $title->getText() );
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			PageIndexUpdater::updateIndex( $title, $wikiPage );
		}
	}
}

require_once RUN_MAINTENANCE_IF_MAIN;
