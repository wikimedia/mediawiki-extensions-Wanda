<?php

namespace MediaWiki\Extension\Wanda\Tests\Integration;

use ApiMain;
use ApiUsageException;
use MediaWiki\Extension\Wanda\APIChat;
use MediaWikiIntegrationTestCase;
use RequestContext;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\Wanda\APIChat::canUserReadTitle
 * @group Wanda
 * @group Database
 */
class APIChatTest extends MediaWikiIntegrationTestCase {

	/** @var APIChat */
	private $apiChat;
	/** @var TestingAccessWrapper */
	private $apiChatWrapper;

	protected function setUp(): void {
		parent::setUp();
		$context = new RequestContext();
		$main = new ApiMain( $context );
		$this->apiChat = new APIChat( $main, 'chat' );
		$this->apiChatWrapper = TestingAccessWrapper::newFromObject( $this->apiChat );
	}

	public function testCanUserReadTitle_AllowedNamespaces() {
		// Namespace 0 (Main), Namespace 2 (User), Namespace 1 (Talk)

		// If provided, only specified namespaces are allowed
		$this->assertTrue(
			$this->apiChatWrapper->canUserReadTitle( 'Main Page', [ 0, 2 ] )
		);
		$this->assertTrue(
			$this->apiChatWrapper->canUserReadTitle( 'User:Alice', [ 0, 2 ] )
		);

		// This should be false because Talk namespace (1) is not in the allowed list [0, 2]
		$this->assertFalse(
			$this->apiChatWrapper->canUserReadTitle( 'Talk:Main Page', [ 0, 2 ] )
		);
	}

	public function testCanUserReadTitle_EmptyAllowedNamespacesMeansAll() {
		// If empty, it means all namespaces are allowed
		$this->assertTrue(
			$this->apiChatWrapper->canUserReadTitle( 'Main Page', [] )
		);
		$this->assertTrue(
			$this->apiChatWrapper->canUserReadTitle( 'User:Alice', [] )
		);
		$this->assertTrue(
			$this->apiChatWrapper->canUserReadTitle( 'Talk:Main Page', [] )
		);
	}

	/**
	 * @covers \MediaWiki\Extension\Wanda\APIChat::getAllowedParams
	 */
	public function testLlmConfigIsNotOverridableByRequest() {
		$this->assertArrayNotHasKey( 'apiendpoint', $this->apiChat->getAllowedParams() );
	}

	/**
	 * @covers \MediaWiki\Extension\Wanda\APIChat::mustBePosted
	 */
	public function testMustBePosted() {
		$this->assertTrue( $this->apiChat->mustBePosted() );
	}

	/**
	 * @covers \MediaWiki\Extension\Wanda\APIChat::execute
	 */
	public function testExecuteRequiresChatRight() {
		$this->setGroupPermissions( '*', 'wanda-chat', false );
		$this->expectException( ApiUsageException::class );
		$this->apiChat->execute();
	}
}
