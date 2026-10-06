<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApprovedRevs;
use MediaWiki\Extension\Wanda\Hooks\PageIndexUpdater;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\Hooks\PageIndexUpdater
 * @group Wanda
 * @group Database
 */
class PageIndexUpdaterTest extends MediaWikiIntegrationTestCase {

	public function testIndexesLatestContentByDefault() {
		$page = $this->getExistingTestPage();

		$content = PageIndexUpdater::resolveIndexableContent( $page->getTitle(), $page );

		$this->assertTrue( $page->getContent()->equals( $content ) );
	}

	public function testSkipsApprovablePageWithoutApprovedRevision() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Approved Revs' );
		$this->overrideConfigValue( 'WandaIndexApprovedOnly', true );
		$page = $this->getNonexistingTestPage();
		$this->editPage( $page, 'Unreviewed text', '', NS_MAIN, $this->getTestUser()->getAuthority() );

		$this->assertNull( PageIndexUpdater::resolveIndexableContent( $page->getTitle(), $page ) );
	}

	public function testIndexesApprovedRevisionInsteadOfLatest() {
		$this->markTestSkippedIfExtensionNotLoaded( 'Approved Revs' );
		$this->overrideConfigValue( 'WandaIndexApprovedOnly', true );
		$page = $this->getNonexistingTestPage();
		$author = $this->getTestUser()->getAuthority();
		$approvedRevId = $this->editPage( $page, 'Reviewed text', '', NS_MAIN, $author )->getNewRevision()->getId();
		$this->editPage( $page, 'Unreviewed text', '', NS_MAIN, $author );
		$approver = $this->getTestSysop()->getUser();
		ApprovedRevs::saveApprovedRevIDInDB( $page->getTitle(), $approvedRevId, $approver, false );

		$content = PageIndexUpdater::resolveIndexableContent( $page->getTitle(), $page );

		$this->assertSame( 'Reviewed text', $content->getText() );
	}
}
