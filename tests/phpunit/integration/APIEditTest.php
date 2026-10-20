<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApiMain;
use ApiUsageException;
use HashBagOStuff;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\Wanda\APIEdit;
use MediaWiki\Extension\Wanda\WandaEditContext;
use MediaWiki\Extension\Wanda\WandaEditHandler;
use MediaWiki\Extension\Wanda\WandaEditPlanner;
use MediaWiki\Extension\Wanda\WandaEditService;
use MediaWiki\Extension\Wanda\WandaEditStore;
use MediaWiki\Extension\Wanda\WandaPageDiscovery;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use RequestContext;
use User;
use WikitextContent;

/**
 * Network-free API tests: real permissions, public revisions, proposal store
 * and core action=edit, with only the LLM replaced by a deterministic mock.
 *
 * @covers \MediaWiki\Extension\Wanda\APIEdit
 * @covers \MediaWiki\Extension\Wanda\WandaEditService
 * @group Wanda
 * @group Database
 */
class APIEditTest extends MediaWikiIntegrationTestCase {

	/** @var WandaEditStore */
	private $store;
	/** @var string */
	private $newText = 'Reviewed content.';
	/** @var bool */
	private $changed = true;
	/** @var int */
	private $generationCount = 0;

	private function serviceWithPlan( array $plan, array $titles, ?array $evidence = null ): WandaEditService {
		$context = new RequestContext();
		$context->setUser( $this->getTestUser()->getUser() );
		$handler = $this->createMock( WandaEditHandler::class );
		$planner = $this->createMock( WandaEditPlanner::class );
		$planner->method( 'plan' )->willReturn( $plan );
		$discovery = $this->createMock( WandaPageDiscovery::class );
		$discovery->method( 'discover' )->willReturn( [ 'titles' => $titles, 'truncated' => false ] );
		$retrieval = $this->createMock( WandaEditContext::class );
		$retrieval->method( 'availableSources' )->willReturn( [ 'wiki' ] );
		if ( $evidence !== null ) {
			$retrieval->expects( $this->once() )->method( 'retrieve' )->with( 'Evidence', 'wiki' )
				->willReturn( $evidence );
		}
		$service = $this->getMockBuilder( WandaEditService::class )
			->setConstructorArgs( [ $context, $this->store, $handler ] )
			->onlyMethods( [ 'createPlanner', 'createDiscovery', 'createContext', 'rateLimit' ] )->getMock();
		$service->method( 'createPlanner' )->willReturn( $planner );
		$service->method( 'createDiscovery' )->willReturn( $discovery );
		$service->method( 'createContext' )->willReturn( $retrieval );
		return $service;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'WandaEnableEditing', true );
		$this->setGroupPermissions( '*', 'wanda-edit', true );
		$this->overrideConfigValue( 'WandaBatchEditNamespaces', [ 0 ] );
		$this->overrideConfigValue( 'RateLimits', [] );
		$this->store = new WandaEditStore( new HashBagOStuff(), WikiMap::getCurrentWikiId() );
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
		$handler = $this->createMock( WandaEditHandler::class );
		$handler->method( 'proposeEdit' )->willReturnCallback( function () {
			$this->generationCount++;
			return [ 'changed' => $this->changed, 'newtext' => $this->newText, 'explanation' => 'Test proposal' ];
		} );
		$store = $this->store;
		$api->getModuleManager()->addModule( 'wandaedit', 'action', [
			'class' => APIEdit::class,
			'factory' => static function ( $main, $name ) use ( $store, $handler ) {
				return new class( $main, $name, $store, $handler ) extends APIEdit {
					private $store;
					private $handler;

					public function __construct( $main, $name, $store, $handler ) {
						parent::__construct( $main, $name );
						$this->store = $store;
						$this->handler = $handler;
					}

					protected function createStore(): WandaEditStore {
						return $this->store;
					}

					protected function createHandler(): WandaEditHandler {
						return $this->handler;
					}
				};
			},
		] );

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
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'setup' ),
			$page->exists() ? EDIT_UPDATE : EDIT_NEW );
		Title::clearCaches();
	}

	private function previewProposal( string $title, ?User $user = null ): string {
		[ $threw, $key, $data ] = $this->doWandaEdit( [
			'title' => $title, 'message' => 'Update the text', 'summary' => 'Reviewed summary',
		], $user );
		$this->assertFalse( $threw, $key );
		$this->assertTrue( $data['changed'] );
		$this->assertNotEmpty( $data['proposalid'] );
		$this->assertArrayNotHasKey( 'newtext', $data );
		return $data['proposalid'];
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

	public function testConfirmWithoutProposalIsRejected() {
		$this->createPage( 'Wanda edit test empty newtext', 'x' );

		[ $threw, $key ] = $this->doWandaEdit( [
			'title' => 'Wanda edit test empty newtext', 'message' => 'x',
			'confirm' => '1', 'newtext' => '   ',
		] );

		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-invalid-record', $key );
	}

	public function testConfirmOnNonexistentPageCreatesIt() {
		$title = 'Wanda edit test create via confirm';
		$this->assertFalse( Title::newFromText( $title )->exists() );

		$id = $this->previewProposal( $title );
		[ $threw, , $data ] = $this->doWandaEdit( [
			'confirm' => '1', 'proposalid' => $id,
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

		$id = $this->previewProposal( $title );
		[ $threw, , $data ] = $this->doWandaEdit( [
			'confirm' => '1', 'proposalid' => $id,
		] );

		$this->assertFalse( $threw );
		$this->assertSame( 'success', $data['result'] );
		$this->assertFalse( $data['new'] );
		$revision = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $data['newrevid'] );
		$this->assertSame( $this->newText, $revision->getContent( SlotRecord::MAIN )->getText() );
		$this->assertSame( $this->getTestUser()->getUser()->getId(), $revision->getUser()->getId() );
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

		$id = $this->previewProposal( $title, $this->getTestSysop()->getUser() );
		[ $threw, , $data ] = $this->doWandaEdit(
			[
				'confirm' => '1', 'proposalid' => $id,
			],
			$this->getTestSysop()->getUser()
		);

		$this->assertFalse( $threw );
		$this->assertSame( 'success', $data['result'] );
	}

	public function testConfirmIgnoresTamperedTextTitleInstructionAndSummary(): void {
		$title = 'Wanda tamper target';
		$this->createPage( $title, 'Original' );
		$id = $this->previewProposal( $title );
		[ $threw, , $data ] = $this->doWandaEdit( [
			'confirm' => 1, 'proposalid' => $id, 'title' => 'Other target', 'newtext' => 'Injected',
			'message' => 'Different instruction', 'summary' => 'Injected summary', 'baserevid' => 0,
			'recreate' => 1, 'bot' => 1, 'apiendpoint' => 'https://attacker.invalid/',
		] );
		$this->assertFalse( $threw );
		$this->assertSame( $title, $data['title'] );
		$revision = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionById( $data['newrevid'] );
		$this->assertSame( $this->newText, $revision->getContent( SlotRecord::MAIN )->getText() );
		$this->assertSame( 'Reviewed summary', $revision->getComment()->text );
	}

	public function testSuccessfulRetryReturnsIdenticalResult(): void {
		$title = 'Wanda retry target';
		$this->createPage( $title, 'Original' );
		$id = $this->previewProposal( $title );
		$first = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$second = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertFalse( $first[0] );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->generationCount );
	}

	public function testProposalBelongsToItsAuthor(): void {
		$id = $this->previewProposal( 'Wanda owner target' );
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ],
			$this->getTestSysop()->getUser() );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-invalid-record', $key );
	}

	public function testExpiredProposalIsRejected(): void {
		$now = time();
		$this->store = $this->getMockBuilder( WandaEditStore::class )
			->setConstructorArgs( [ new HashBagOStuff(), WikiMap::getCurrentWikiId(), 10 ] )
			->onlyMethods( [ 'now' ] )->getMock();
		$this->store->method( 'now' )->willReturnCallback( static function () use ( &$now ) {
			return $now;
		} );
		$id = $this->previewProposal( 'Wanda expired target' );
		$now += 11;
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-invalid-record', $key );
	}

	/** @dataProvider provideCreationRace */
	public function testStaleRevisionOrCreationRaceIsRejected( bool $existing ): void {
		$title = 'Wanda stale target ' . (int)$existing;
		if ( $existing ) {
			$this->createPage( $title, 'Original' );
		}
		$id = $this->previewProposal( $title );
		$this->createPage( $title, 'Concurrent edit' );
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-stale', $key );
	}

	public static function provideCreationRace(): array {
		return [ [ true ], [ false ] ];
	}

	public function testRightsAreRecheckedOnConfirm(): void {
		$id = $this->previewProposal( 'Wanda revoked right target' );
		$this->setGroupPermissions( '*', 'wanda-edit', false );
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-permission', $key );
	}

	public function testReadPermissionIsRequiredBeforeGeneration(): void {
		$this->setGroupPermissions( '*', 'read', false );
		$this->setGroupPermissions( 'user', 'read', false );
		$this->setGroupPermissions( 'autoconfirmed', 'read', false );
		[ $threw ] = $this->doWandaEdit( [ 'title' => 'Wanda unreadable', 'message' => 'Update' ] );
		$this->assertTrue( $threw );
		$this->assertSame( 0, $this->generationCount );
	}

	private function batch( string $title, bool $research = false ): string {
		return $this->store->create( 'batch', (string)$this->getTestUser()->getUser()->getId(), [
			'plan' => [ 'instruction' => 'Update', 'researchQuery' => $research ? 'Evidence' : '',
				'sources' => $research ? [ 'wiki' ] : [] ],
			'candidates' => [ $title ], 'contexts' => [], 'previews' => [],
		] );
	}

	public function testBatchMembershipIsRequired(): void {
		$id = $this->batch( 'Wanda allowed member' );
		[ $threw, $key ] = $this->doWandaEdit( [ 'batchid' => $id, 'title' => 'Wanda other member' ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-not-candidate', $key );
		$this->assertSame( 0, $this->generationCount );
	}

	public function testNamespaceCapIsRequiredEvenForStoredMember(): void {
		$title = 'Talk:Wanda outside namespace';
		$id = $this->batch( $title );
		[ $threw, $key ] = $this->doWandaEdit( [ 'batchid' => $id, 'title' => $title ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-namespace', $key );
	}

	public function testResearchMustCompleteBeforePreview(): void {
		$title = 'Wanda research target';
		$id = $this->batch( $title, true );
		[ $threw, $key ] = $this->doWandaEdit( [ 'batchid' => $id, 'title' => $title ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-context-required', $key );
		$this->assertSame( 0, $this->generationCount );
		[ $threw, $key ] = $this->doWandaEdit( [ 'stage' => 'context', 'batchid' => $id, 'source' => 'cargo' ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-source-unsupported', $key );
	}

	/** @dataProvider provideCreationRace */
	public function testBatchPreviewCachesChangesAndNoChanges( bool $changed ): void {
		$this->changed = $changed;
		$title = 'Wanda cached target ' . (int)$changed;
		$id = $this->batch( $title );
		$first = $this->doWandaEdit( [ 'batchid' => $id, 'title' => $title ] );
		$second = $this->doWandaEdit( [ 'batchid' => $id, 'title' => $title, 'message' => 'Tampered' ] );
		$this->assertFalse( $first[0] );
		$this->assertSame( $first, $second );
		$this->assertSame( $changed, $first[2]['changed'] );
		$this->assertSame( 1, $this->generationCount );
	}

	public function testUncertainSaveDoesNotWriteAgain(): void {
		$title = 'Wanda uncertain target';
		$this->createPage( $title, 'Original' );
		$id = $this->previewProposal( $title );
		$owner = (string)$this->getTestUser()->getUser()->getId();
		$this->store->withLock( 'proposal', $id, $owner, function ( array $proposal ) use ( $id, $owner ) {
			$proposal['state'] = 'saving';
			$this->store->update( 'proposal', $id, $owner, $proposal );
		} );
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-save-uncertain', $key );
	}

	public function testUncertainCommittedSaveIsRecoveredWithoutRewrite(): void {
		$title = 'Wanda recovered target';
		$this->createPage( $title, 'Original' );
		$id = $this->previewProposal( $title );
		$first = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertFalse( $first[0] );
		$owner = (string)$this->getTestUser()->getUser()->getId();
		$this->store->withLock( 'proposal', $id, $owner, function ( array $proposal ) use ( $id, $owner ) {
			$proposal['state'] = 'saving';
			unset( $proposal['result'] );
			$this->store->update( 'proposal', $id, $owner, $proposal );
		} );
		$this->assertSame( $first, $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] ) );
	}

	public function testPlanCapsEvenUnexpectedDiscoveryOutput(): void {
		$this->overrideConfigValue( 'WandaBatchEditMaxPages', 500 );
		$titles = [];
		for ( $i = 0; $i < 55; $i++ ) {
			$titles[] = 'Wanda cap target ' . $i;
			$this->createPage( end( $titles ), 'Original' );
		}
		$service = $this->serviceWithPlan( [
			'scope' => [ 'type' => 'search', 'query' => 'Wanda' ], 'instruction' => 'Model rewrite',
			'researchQuery' => '', 'sources' => [],
		], $titles );
		$result = $service->plan( 'Original instruction', '', [ 'wiki' ] );
		$this->assertCount( 50, $result['candidates'] );
		$this->assertSame( 50, $result['maxpages'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( [], $result['sources'] );
		$stored = $this->store->get( 'batch', $result['batchid'], (string)$this->getTestUser()->getUser()->getId() );
		$this->assertSame( 'Original instruction', $stored['plan']['instruction'] );
	}

	public function testResearchDoesNotSilentlyDropRequestedUnsupportedSources(): void {
		$service = $this->serviceWithPlan( [
			'scope' => [ 'type' => 'search', 'query' => 'Wanda' ], 'researchQuery' => 'Evidence',
			'sources' => [ 'wiki' ],
		], [ 'Wanda target' ] );
		$this->expectExceptionMessage( 'wanda-edit-source-unsupported' );
		$service->plan( 'Update using evidence', '', [ 'wiki', 'cargo' ] );
	}

	public function testSinglePlanUsesCanonicalTitleNotModelScope(): void {
		$service = $this->serviceWithPlan( [
			'scope' => [ 'type' => 'search', 'query' => 'Ignored' ], 'researchQuery' => '', 'sources' => [],
		], [] );
		$result = $service->plan( 'Update', 'Wanda_single_target', [ 'wiki' ] );
		$this->assertSame( 'single: Wanda single target', $result['scope'] );
		$this->assertSame( [ [ 'title' => 'Wanda single target' ] ], $result['candidates'] );
	}

	public function testMergedContentHookCannotSaveAConcurrentRevision(): void {
		$title = 'Wanda merge race target';
		$this->createPage( $title, "First\n\nLast" );
		$this->newText = "Changed first\n\nLast";
		$id = $this->previewProposal( $title );
		$ran = false;
		$this->setTemporaryHook( 'EditFilterMergedContent', function () use ( &$ran, $title ) {
			if ( !$ran ) {
				$ran = true;
				$this->createPage( $title, "First\n\nConcurrent last" );
			}
			return true;
		} );
		[ $threw ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $ran );
		$this->assertTrue( $threw );
	}

	public function testContextSnapshotIsCachedAndEmptyEvidenceFailsExplicitly(): void {
		$title = 'Wanda context cache target';
		$id = $this->batch( $title, true );
		$evidence = [ 'source' => 'wiki', 'content' => 'Evidence text',
			'sources' => [ [ 'title' => 'Wanda citation', 'href' => '/wiki/Wanda_citation' ] ] ];
		$service = $this->serviceWithPlan( [], [], $evidence );
		$first = $service->retrieveContext( $id, 'wiki' );
		$this->assertSame( $first, $service->retrieveContext( $id, 'wiki' ) );
		$this->assertTrue( $first['complete'] );
		$empty = $this->serviceWithPlan( [], [], [ 'source' => 'wiki', 'content' => '', 'sources' => [] ] );
		$this->expectExceptionMessage( 'wanda-edit-context-empty' );
		$empty->retrieveContext( $this->batch( $title, true ), 'wiki' );
	}

	public function testPageSizeLimitPrecedesGeneration(): void {
		$this->overrideConfigValue( 'WandaEditMaxPageBytes', 4 );
		$this->createPage( 'Wanda too large', 'Large content' );
		[ $threw, $key ] = $this->doWandaEdit( [ 'title' => 'Wanda too large', 'message' => 'Update' ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-too-large', $key );
		$this->assertSame( 0, $this->generationCount );
	}

	public function testFeatureFlagIsRecheckedOnConfirm(): void {
		$id = $this->previewProposal( 'Wanda disabled after preview' );
		$this->overrideConfigValue( 'WandaEnableEditing', false );
		[ $threw, $key ] = $this->doWandaEdit( [ 'confirm' => 1, 'proposalid' => $id ] );
		$this->assertTrue( $threw );
		$this->assertSame( 'wanda-api-error-edit-disabled', $key );
	}
}
