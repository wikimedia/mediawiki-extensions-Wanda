<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wanda\Specials\SpecialAIChat;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use OutputPage;
use ReflectionMethod;

/**
 * @covers \MediaWiki\Extension\Wanda\Specials\SpecialAIChat
 * @group Wanda
 */
class SpecialAIChatTest extends MediaWikiIntegrationTestCase {

	public function testNameIsWanda() {
		$this->assertSame( 'Wanda', ( new SpecialAIChat() )->getName() );
	}

	public function testGroupNameIsWiki() {
		$method = new ReflectionMethod( SpecialAIChat::class, 'getGroupName' );
		$this->assertSame( 'wiki', $method->invoke( new SpecialAIChat() ) );
	}

	public function testExecuteRendersContainerAndConfigVars() {
		$special = new SpecialAIChat();

		$context = new RequestContext();
		$context->setTitle( Title::newFromText( 'Special:Wanda' ) );
		$output = new OutputPage( $context );
		$context->setOutput( $output );
		$special->setContext( $context );

		$special->execute( null );

		$this->assertStringContainsString( 'chat-bot-container', $output->getHTML() );

		$jsVars = $output->getJsConfigVars();
		$this->assertArrayHasKey( 'WandaEnableAttachments', $jsVars );
		$this->assertArrayHasKey( 'WandaMaxImageCount', $jsVars );
		$this->assertArrayHasKey( 'WandaRAGSourceNames', $jsVars );
	}
}
