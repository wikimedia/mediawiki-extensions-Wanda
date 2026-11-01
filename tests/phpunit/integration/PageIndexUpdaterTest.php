<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApprovedRevs;
use JobQueueGroup;
use MediaWiki\Extension\Wanda\Hooks\PageIndexUpdater;
use MediaWiki\Extension\Wanda\WandaIndexPageJob;
use MediaWiki\Title\Title;
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

	public function testSkipsRedirects() {
		$title = $this->getExistingTestPage()->getTitle();
		$this->editPage( $title, '#REDIRECT [[Target]]' );
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );

		$this->assertNull( PageIndexUpdater::resolveIndexableContent( $title, $page ) );
	}

	public function testSkipsDeletedPages() {
		$page = $this->getNonexistingTestPage();

		$this->assertNull( PageIndexUpdater::resolveIndexableContent( $page->getTitle(), $page ) );
	}

	public function testPageSaveQueuesIndexJob() {
		$page = $this->getExistingTestPage();
		$queued = $this->captureQueuedTitles();

		PageIndexUpdater::onPageSaveComplete( $page, null, '', 0, null, null );

		$this->assertSame( [ $page->getTitle()->getPrefixedText() ], $queued->titles );
	}

	public function testPageMoveQueuesIndexJobsForBothTitles() {
		$old = Title::makeTitle( NS_MAIN, 'Wanda old' );
		$new = Title::makeTitle( NS_MAIN, 'Wanda new' );
		$queued = $this->captureQueuedTitles();

		PageIndexUpdater::onPageMoveComplete( $old, $new, null, 1, 0, '', null );

		$this->assertSame( [ 'Wanda old', 'Wanda new' ], $queued->titles );
	}

	private function captureQueuedTitles(): object {
		$queued = (object)[ 'titles' => [] ];
		$group = $this->createMock( JobQueueGroup::class );
		$group->method( 'lazyPush' )->willReturnCallback( function ( $job ) use ( $queued ) {
			$this->assertInstanceOf( WandaIndexPageJob::class, $job );
			$queued->titles[] = $job->getTitle()->getPrefixedText();
		} );
		$this->setService( 'JobQueueGroup', $group );
		return $queued;
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
