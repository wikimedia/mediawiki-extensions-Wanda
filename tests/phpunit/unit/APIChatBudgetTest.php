<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\APIChat;
use MediaWikiUnitTestCase;
use ReflectionClass;
use Wikimedia\TestingAccessWrapper;

/**
 * Unit tests for the parts of APIChat::checkAndConsumeTokenBudget that are
 * reachable without touching MediaWikiServices or a real object stash.
 *
 * @covers \MediaWiki\Extension\Wanda\APIChat::checkAndConsumeTokenBudget
 * @group Wanda
 */
class APIChatBudgetTest extends MediaWikiUnitTestCase {

	/**
	 * Set the private static $dailyTokenBudget without constructing APIChat.
	 */
	private static function setBudget( int $v ): void {
		$rp = ( new ReflectionClass( APIChat::class ) )->getProperty( 'dailyTokenBudget' );
		$rp->setValue( null, $v );
	}

	/**
	 * Return a partial-mock APIChat that exposes getUser() and getRequest()
	 * without running the real constructor.
	 *
	 * @param \User $user
	 * @param \WebRequest $request
	 */
	private function wrapChat( \User $user, \WebRequest $request ): TestingAccessWrapper {
		$chat = $this->getMockBuilder( APIChat::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getUser', 'getRequest' ] )
			->getMock();

		$chat->method( 'getUser' )->willReturn( $user );
		$chat->method( 'getRequest' )->willReturn( $request );

		return TestingAccessWrapper::newFromObject( $chat );
	}

	private function makeSysop(): \User {
		$user = $this->createMock( \User::class );
		$user->method( 'isAllowed' )->with( 'noratelimit' )->willReturn( true );
		// These must never be reached when sysop short-circuits.
		$user->expects( $this->never() )->method( 'isAnon' );
		$user->expects( $this->never() )->method( 'getId' );
		return $user;
	}

	private function makeNormalUser( int $id = 1 ): \User {
		$user = $this->createMock( \User::class );
		$user->method( 'isAllowed' )->with( 'noratelimit' )->willReturn( false );
		$user->method( 'isAnon' )->willReturn( false );
		$user->method( 'getId' )->willReturn( $id );
		return $user;
	}

	private function makeRequest( string $ip = '127.0.0.1' ): \WebRequest {
		$req = $this->createMock( \WebRequest::class );
		$req->method( 'getIP' )->willReturn( $ip );
		return $req;
	}

	public function testSysopBypassReturnsNullImmediately(): void {
		self::setBudget( 500 );

		$wrapper = $this->wrapChat( $this->makeSysop(), $this->makeRequest() );
		$result  = $wrapper->checkAndConsumeTokenBudget( 999 );

		$this->assertNull(
			$result,
			'A user with noratelimit must bypass the budget and receive null.'
		);
	}

	public function testSysopBypassWorksEvenWhenBudgetIsZero(): void {
		self::setBudget( 0 );

		$wrapper = $this->wrapChat( $this->makeSysop(), $this->makeRequest() );
		$result  = $wrapper->checkAndConsumeTokenBudget( 1 );

		$this->assertNull( $result );
	}

	public function testSysopBypassWorksEvenWithVeryLargeTokenCount(): void {
		self::setBudget( 100 );

		$wrapper = $this->wrapChat( $this->makeSysop(), $this->makeRequest() );
		$result  = $wrapper->checkAndConsumeTokenBudget( PHP_INT_MAX );

		$this->assertNull( $result );
	}
}
