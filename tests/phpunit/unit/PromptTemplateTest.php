<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\Prompts\PromptTemplate
 * @group Wanda
 */
class PromptTemplateTest extends MediaWikiUnitTestCase {

	public function testRenderLoadsShippedTemplate() {
		$out = PromptTemplate::render( 'system-without-knowledge' );
		$this->assertNotSame( '', $out );
		$this->assertStringContainsString(
			'Answer the following question',
			$out
		);
	}

	public function testRenderLeavesUnknownPlaceholdersUntouched() {
		// Substituting a token the template does not contain must not error
		// and must not alter unrelated content.
		$plain = PromptTemplate::render( 'system-with-knowledge' );
		$withVars = PromptTemplate::render(
			'system-with-knowledge',
			[ 'NONEXISTENT_TOKEN' => 'XYZ' ]
		);
		$this->assertSame( $plain, $withVars );
		$this->assertStringNotContainsString( 'XYZ', $withVars );
	}

	public function testRenderSubstitutesPlaceholders() {
		// Write a temporary template under the real templates directory so we
		// can assert {{key}} substitution mechanics deterministically.
		$dir = dirname( ( new \ReflectionClass( PromptTemplate::class ) )->getFileName() )
			. '/templates';
		$name = 'wanda-unit-test-' . getmypid();
		$path = "$dir/$name.txt";
		file_put_contents( $path, 'Hello {{name}}, you have {{count}} messages.' );

		try {
			$out = PromptTemplate::render( $name, [
				'name' => 'Ada',
				'count' => 3,
			] );
			$this->assertSame( 'Hello Ada, you have 3 messages.', $out );
		} finally {
			unlink( $path );
		}
	}

	public function testRenderMissingTemplateThrows() {
		// file_get_contents() emits a warning before returning false; swallow it
		// so the assertion targets the RuntimeException, not the warning.
		$caught = null;
		set_error_handler( static function () {
			return true;
		} );
		try {
			PromptTemplate::render( 'wanda-definitely-missing-xyz' );
		} catch ( \RuntimeException $e ) {
			$caught = $e;
		} finally {
			restore_error_handler();
		}

		$this->assertInstanceOf( \RuntimeException::class, $caught );
		$this->assertStringContainsString( 'Prompt template not found', $caught->getMessage() );
	}
}
