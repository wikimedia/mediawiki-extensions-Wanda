<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApiMain;
use ApiUsageException;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RequestContext;
use User;
use WikitextContent;

/**
 * Exercises the wandaedit API end to end: the permission/validation gates in
 * APIEdit::execute(), and the create-vs-update save paths. Cases that would
 * require a real LLM call (the happy-path preview) are intentionally left to
 * WandaEditHandlerTest and manual verification, so this suite stays
 * network-free and safe for CI.
 *
 * @covers \MediaWiki\Extension\Wanda\APIEdit
 * @group Wanda
 * @group Database
 */
class APIEditTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'WandaEnableEditing', true );
		$this->setGroupPermissions( '*', 'wanda-edit', true );
	}

	/**
	 * @param array $params
	 * @param User|null $user
	 * @return array [threw:bool, messageKey:string, data:array]
	 */
	private function doWandaEdit( array $params, ?User $user = null ): array {
		$user = $user ?? $this->getTestUser()->getUser();
		$params += [ 'action' => 'wandaedit', 'format' => 'json' ];

		$req = new FauxRequest( $params, true );
		$req->setVal( 'token', $user->getEditToken( '', $req ) );
		$context = new RequestContext();
		$context->setRequest( $req );
		$context->setUser( $user );
		$api = new ApiMain( $context, true );

		try {
			$api->execute();
			return [ false, '', $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] ) ];
		} catch ( ApiUsageException $e ) {
			return [ true, $e->getMessageObject()->getKey(), [] ];
		}
	}

	private function createPage( string $title, string $text ): void {
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( Title::newFromText( $title ) );
		$updater = $page->newPageUpdater( $this->getTestSysop()->getUser() );
		$updater->setContent( SlotRecord::MAIN, new WikitextContent( $text ) );
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'setup' ), EDIT_NEW );
	}

	public function testFeatureFlagDisabledIsRejected() {
		$this->overrideConfigValue( 'WandaEnableEditing', false );
		$this->createPage( 'Wanda edit test disabled flag', 'x' );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => 'Wanda edit test disabled flag', 'message' => 'x',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-disabled', $key );
	}

	public function testUserWithoutRightIsDenied() {
		$this->setGroupPermissions( '*', 'wanda-edit', false );
		$this->createPage( 'Wanda edit test no right', 'x' );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => 'Wanda edit test no right', 'message' => 'x',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-permission', $key );
	}

	public function testInvalidTitleIsRejected() {
		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => '<invalid|title>[[', 'message' => 'x',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-notitle', $key );
	}

	public function testEmptyInstructionIsRejected() {
		$this->createPage( 'Wanda edit test empty instruction', 'x' );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => 'Wanda edit test empty instruction', 'message' => '   ',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-empty-question', $key );
	}

	public function testConfirmWithEmptyNewtextIsRejected() {
		$this->createPage( 'Wanda edit test empty newtext', 'x' );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => 'Wanda edit test empty newtext', 'message' => 'x',
			'confirm' => '1', 'newtext' => '   ',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-failed', $key );
	}

	public function testConfirmOnNonexistentPageCreatesIt() {
		$title = 'Wanda edit test create via confirm';
		$this->assertFalse( Title::newFromText( $title )->exists() );

		[ $threw, , $data ] = $this->doWandaEdit( [
			'title' => $title, 'message' => 'x',
			'confirm' => '1', 'newtext' => 'Brand new content.',
		] );

		$this->assertFalse( $threw );
		$this->assertSame( 'success', $data['result'] );
		$this->assertTrue( $data['new'] );
		Title::clearCaches();
		$this->assertTrue( Title::newFromText( $title )->exists() );
	}

	public function testConfirmOnExistingPageUpdatesIt() {
		$title = 'Wanda edit test update via confirm';
		$this->createPage( $title, 'Original content.' );

		[ $threw, , $data ] = $this->doWandaEdit( [
			'title' => $title, 'message' => 'x',
			'confirm' => '1', 'newtext' => 'Updated content.',
		] );

		$this->assertFalse( $threw );
		$this->assertSame( 'success', $data['result'] );
		$this->assertFalse( $data['new'] );
	}

	public function testMissingCreatePermissionOnNewPageIsDenied() {
		$this->setGroupPermissions( '*', 'createpage', false );
		$this->setGroupPermissions( 'user', 'createpage', false );
		$title = 'Wanda edit test no create permission';
		$this->assertFalse( Title::newFromText( $title )->exists() );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => $title, 'message' => 'x',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-permission', $key );
	}

	public function testProtectedPageBlocksUserWithoutEditRight() {
		$title = 'Wanda edit test protected page';
		$this->createPage( $title, 'Protected content.' );

		$page = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( Title::newFromText( $title ) );
		$cascade = false;
		$page->doUpdateRestrictions(
			[ 'edit' => 'sysop', 'move' => 'sysop' ],
			[ 'edit' => '', 'move' => '' ],
			$cascade,
			'test protect',
			$this->getTestSysop()->getUser()
		);

		// A plain user has the wanda-edit right (granted wiki-wide in setUp) but
		// not sysop, so page protection should still block them.
		[ $threw, $key ] = $this->doWandaEdit(
			[ 'title' => $title, 'message' => 'x' ],
			$this->getTestUser()->getUser()
		);

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-permission', $key );
	}

	public function testProtectedPageAllowsAuthorizedUserViaConfirm() {
		$title = 'Wanda edit test protected page allowed';
		$this->createPage( $title, 'Protected content.' );

		$page = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( Title::newFromText( $title ) );
		$cascade = false;
		$page->doUpdateRestrictions(
			[ 'edit' => 'sysop', 'move' => 'sysop' ],
			[ 'edit' => '', 'move' => '' ],
			$cascade,
			'test protect',
			$this->getTestSysop()->getUser()
		);

		[ $threw, , $data ] = $this->doWandaEdit(
			[
				'title' => $title, 'message' => 'x',
				'confirm' => '1', 'newtext' => 'Updated protected content.',
			],
			$this->getTestSysop()->getUser()
		);

		$this->assertFalse( $threw );
		$this->assertSame( 'success', $data['result'] );
	}
}
