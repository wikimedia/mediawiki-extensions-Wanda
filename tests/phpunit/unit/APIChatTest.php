<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\APIChat;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\APIChat::getOpenAITokenKeyForModel
 * @group Wanda
 */
class APIChatTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideModels
	 */
	public function testGetOpenAITokenKeyForModel( string $model, string $expected ) {
		$this->assertSame( $expected, APIChat::getOpenAITokenKeyForModel( $model ) );
	}

	public static function provideModels() {
		return [
			'empty string defaults to max_tokens' => [ '', 'max_tokens' ],
			'gpt-4' => [ 'gpt-4', 'max_tokens' ],
			'gpt-3.5-turbo' => [ 'gpt-3.5-turbo', 'max_tokens' ],
			'gpt-4o (no o1/o3 token)' => [ 'gpt-4o', 'max_tokens' ],
			'plain ollama model' => [ 'gemma:2b', 'max_tokens' ],
			'gpt-5 family' => [ 'gpt-5', 'max_completion_tokens' ],
			'gpt-5 variant' => [ 'gpt-5.4-nano', 'max_completion_tokens' ],
			'prefixed gpt-5' => [ 'openai/gpt-5-nano', 'max_completion_tokens' ],
			'uppercase gpt-5' => [ 'GPT-5-MINI', 'max_completion_tokens' ],
			'o1 series' => [ 'o1', 'max_completion_tokens' ],
			'o3 series' => [ 'o3-mini', 'max_completion_tokens' ],
			'prefixed o1' => [ 'openai/o1-preview', 'max_completion_tokens' ],
		];
	}
}
