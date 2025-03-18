<?php

namespace MediaWiki\Extension\Wikai\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

class ReindexAllPages extends Maintenance {
	public function execute() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $dbr->select( 'page', [ 'page_title' ], [], __METHOD__ );
		foreach ( $res as $row ) {
			$title = $row->page_title;
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			$content = ContentHandler::getContentText( $wikiPage->getContent() );
			( new PageIndexUpdater() )->processContent( $title, $content );
		}
	}
}
