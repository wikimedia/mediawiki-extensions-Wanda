<?php

namespace MediaWiki\Extension\Wanda;

use IDBAccessObject;
use Job;
use MediaWiki\Extension\Wanda\Hooks\PageIndexUpdater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Syncs a page with the search index based on its state when the job runs.
 */
class WandaIndexPageJob extends Job {

	public function __construct( Title $title, array $params = [] ) {
		parent::__construct( 'wandaIndexPage', $title, $params );
		$this->removeDuplicates = true;
	}

	public function run() {
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $this->title );
		$wikiPage->loadPageData( IDBAccessObject::READ_LATEST );

		if ( !PageIndexUpdater::updateIndex( $this->title, $wikiPage ) ) {
			$this->setLastError( 'Failed to index ' . $this->title->getPrefixedText() );
			return false;
		}
		return true;
	}
}
