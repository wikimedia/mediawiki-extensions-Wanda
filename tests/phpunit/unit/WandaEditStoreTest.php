<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use BagOStuff;
use HashBagOStuff;
use MediaWiki\Extension\Wanda\WandaEditStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\Wanda\WandaEditStore
 * @group Wanda
 */
class WandaEditStoreTest extends TestCase {

	/** @var HashBagOStuff */
	private $cache;
	/** @var WandaEditStore */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new HashBagOStuff();
		$this->store = new WandaEditStore( $this->cache, 'testwiki' );
	}

	public function testRandomIdsAndCrossRequestRead(): void {
		$data = [ 'newtext' => 'private reviewed text', 'baseRevId' => 42 ];
		$id = $this->store->create( 'proposal', '7', $data );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $id );
		$this->assertNotSame( $id, $this->store->create( 'proposal', '7', $data ) );
		$nextRequest = new WandaEditStore( $this->cache, 'testwiki' );
		$this->assertSame( $data, $nextRequest->get( 'proposal', $id, '7' ) );
	}

	/** @dataProvider provideForeignRecords */
	public function testRecordIdentityIsBound( string $wiki, string $kind, string $owner ): void {
		$id = $this->store->create( 'proposal', '7', [] );
		$other = new WandaEditStore( $this->cache, $wiki );
		$this->expectExceptionMessage( 'wanda-edit-invalid-record' );
		$other->get( $kind, $id, $owner );
	}

	public static function provideForeignRecords(): array {
		return [ [ 'otherwiki', 'proposal', '7' ], [ 'testwiki', 'batch', '7' ], [ 'testwiki', 'proposal', '8' ] ];
	}

	/** @dataProvider provideInvalidIds */
	public function testMalformedIdsFailClosed( string $id ): void {
		$this->expectExceptionMessage( 'wanda-edit-invalid-record' );
		$this->store->get( 'proposal', $id, '7' );
	}

	public static function provideInvalidIds(): array {
		return [ [ '' ], [ '../secret' ], [ str_repeat( 'a', 63 ) ], [ str_repeat( 'G', 64 ) ] ];
	}

	public function testCannotUpdateOutsideLock(): void {
		$id = $this->store->create( 'proposal', '7', [] );
		$this->expectExceptionMessage( 'wanda-edit-store-failed' );
		$this->store->update( 'proposal', $id, '7', [ 'state' => 'saved' ] );
	}

	public function testLockExcludesAnotherRequestAndReleases(): void {
		$id = $this->store->create( 'proposal', '7', [ 'state' => 'ready' ] );
		$other = new WandaEditStore( $this->cache, 'testwiki' );
		$this->store->withLock( 'proposal', $id, '7', function ( array $data ) use ( $other, $id ) {
			$this->assertSame( 'ready', $data['state'] );
			try {
				$other->withLock( 'proposal', $id, '7', static function () {
					TestCase::fail( 'Concurrent worker acquired the lease' );
				} );
				$this->fail( 'Expected a busy error' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'wanda-edit-busy', $e->getMessage() );
			}
			$this->store->update( 'proposal', $id, '7', [ 'state' => 'saved', 'result' => [ 'newrevid' => 99 ] ] );
		} );
		$result = $other->withLock( 'proposal', $id, '7', static function ( array $data ) {
			return $data['result'];
		} );
		$this->assertSame( [ 'newrevid' => 99 ], $result );
	}

	public function testExceptionPreservesUncertainSaveMarkerAndReleasesLease(): void {
		$id = $this->store->create( 'proposal', '7', [ 'state' => 'ready' ] );
		try {
			$this->store->withLock( 'proposal', $id, '7', function () use ( $id ) {
				$this->store->update( 'proposal', $id, '7', [ 'state' => 'saving' ] );
				throw new RuntimeException( 'lost response' );
			} );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'lost response', $e->getMessage() );
		}
		$this->assertSame( 'saving', $this->store->withLock( 'proposal', $id, '7', static function ( array $data ) {
			return $data['state'];
		} ) );
	}

	public function testExpiryIsAbsoluteAndUpdatesDoNotExtendIt(): void {
		$now = time();
		$store = $this->getMockBuilder( WandaEditStore::class )
			->setConstructorArgs( [ $this->cache, 'testwiki', 10 ] )->onlyMethods( [ 'now' ] )->getMock();
		$store->method( 'now' )->willReturnCallback( static function () use ( &$now ) {
			return $now;
		} );
		$id = $store->create( 'batch', '7', [] );
		$now += 9;
		$store->withLock( 'batch', $id, '7', static function () use ( $store, $id ) {
			$store->update( 'batch', $id, '7', [ 'updated' => true ] );
		} );
		$now++;
		$this->expectExceptionMessage( 'wanda-edit-invalid-record' );
		$store->get( 'batch', $id, '7' );
	}

	public function testExpirationDuringWorkPreventsUpdate(): void {
		$now = time();
		$store = $this->getMockBuilder( WandaEditStore::class )
			->setConstructorArgs( [ $this->cache, 'testwiki', 10 ] )->onlyMethods( [ 'now' ] )->getMock();
		$store->method( 'now' )->willReturnCallback( static function () use ( &$now ) {
			return $now;
		} );
		$id = $store->create( 'batch', '7', [] );
		$this->expectExceptionMessage( 'wanda-edit-invalid-record' );
		$store->withLock( 'batch', $id, '7', static function () use ( &$now, $store, $id ) {
			$now += 11;
			$store->update( 'batch', $id, '7', [] );
		} );
	}

	public function testEvictionNeverRecreatesRecord(): void {
		$id = $this->store->create( 'batch', '7', [] );
		$this->cache->clear();
		$this->expectExceptionMessage( 'wanda-edit-invalid-record' );
		$this->store->withLock( 'batch', $id, '7', static function () {
			TestCase::fail( 'Missing record callback ran' );
		} );
	}

	public function testFailedAddDoesNotReturnUsableId(): void {
		$cache = $this->createMock( BagOStuff::class );
		$cache->method( 'makeGlobalKey' )->willReturn( 'test-key' );
		$cache->method( 'add' )->willReturn( false );
		$this->expectExceptionMessage( 'wanda-edit-store-failed' );
		( new WandaEditStore( $cache, 'testwiki' ) )->create( 'proposal', '7', [] );
	}

	public function testFalseSuccessFromCacheIsRejected(): void {
		$cache = $this->createMock( BagOStuff::class );
		$cache->method( 'makeGlobalKey' )->willReturn( 'test-key' );
		$cache->method( 'add' )->willReturn( true );
		$cache->method( 'get' )->willReturn( false );
		$this->expectExceptionMessage( 'wanda-edit-store-failed' );
		( new WandaEditStore( $cache, 'testwiki' ) )->create( 'proposal', '7', [] );
	}

	public function testFailedUpdateIsNotSilentlyAccepted(): void {
		$cache = $this->getMockBuilder( HashBagOStuff::class )->onlyMethods( [ 'set' ] )->getMock();
		$cache->method( 'set' )->willReturn( false );
		$store = new WandaEditStore( $cache, 'testwiki' );
		$id = $store->create( 'proposal', '7', [] );
		$this->expectExceptionMessage( 'wanda-edit-store-failed' );
		$store->withLock( 'proposal', $id, '7', static function () use ( $store, $id ) {
			$store->update( 'proposal', $id, '7', [ 'state' => 'saved' ] );
		} );
	}
}
