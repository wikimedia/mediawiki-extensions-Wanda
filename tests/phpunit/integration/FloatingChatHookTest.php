<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use MediaWiki\Extension\Wanda\Hooks\FloatingChatHook;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use OutputPage;
use Skin;

/**
 * @covers \MediaWiki\Extension\Wanda\Hooks\FloatingChatHook
 * @group Wanda
 */
class FloatingChatHookTest extends MediaWikiIntegrationTestCase {

	public function testSkipsFloatingChatOnWandaSpecialPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'isSpecial' )->with( 'Wanda' )->willReturn( true );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->expects( $this->never() )->method( 'addModules' );
		$out->expects( $this->never() )->method( 'addJsConfigVars' );

		FloatingChatHook::onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}

	public function testAddsFloatingChatOnRegularPage() {
		$title = $this->createMock( Title::class );
		$title->method( 'isSpecial' )->willReturn( false );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->expects( $this->once() )
			->method( 'addModules' )
			->with( 'ext.wanda.floating' );
		$out->expects( $this->atLeastOnce() )
			->method( 'addJsConfigVars' );

		FloatingChatHook::onBeforePageDisplay( $out, $this->createMock( Skin::class ) );
	}
}
