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

class ReindexAllPages extends Maintenance {
	public function execute() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select( 'page', [ 'page_title' ], [], __METHOD__ );
		foreach ( $res as $row ) {
			$title = $row->page_title;
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			PageIndexUpdater::updateIndex( $title, $wikiPage );
		}
	}
}
