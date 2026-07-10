<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaEditHandler;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\WandaEditHandler::parseEditResponse
 * @group Wanda
 */
class WandaEditHandlerTest extends MediaWikiUnitTestCase {

	private function newHandler(): WandaEditHandler {
		return new WandaEditHandler( 'ollama', 'gemma:2b', '', 'http://x/', 30 );
	}

	public function testParsesPlainJson() {
		$result = $this->newHandler()->parseEditResponse(
			'{"changed": true, "newtext": "{{Infobox|phone=555}}", "explanation": "Set phone"}'
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( '{{Infobox|phone=555}}', $result['newtext'] );
		$this->assertSame( 'Set phone', $result['explanation'] );
	}

	public function testStripsCodeFences() {
		$result = $this->newHandler()->parseEditResponse(
			"```json\n{\"changed\": true, \"newtext\": \"Hello\", \"explanation\": \"x\"}\n```"
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Hello', $result['newtext'] );
	}

	public function testExtractsJsonWrappedInProse() {
		$result = $this->newHandler()->parseEditResponse(
			'Sure, here is the edit: {"changed": true, "newtext": "abc", "explanation": "y"} Hope that helps!'
		);

		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'abc', $result['newtext'] );
	}

	public function testChangedFalseIsRespected() {
		$result = $this->newHandler()->parseEditResponse(
			'{"changed": false, "newtext": "", "explanation": "Field not found"}'
		);

		$this->assertFalse( $result['changed'] );
		$this->assertSame( '', $result['newtext'] );
		$this->assertSame( 'Field not found', $result['explanation'] );
		$this->assertArrayNotHasKey( 'failed', $result );
	}

	public function testChangedTrueButEmptyTextTreatedAsNoChange() {
		$result = $this->newHandler()->parseEditResponse(
			'{"changed": true, "newtext": "   ", "explanation": "oops"}'
		);

		$this->assertFalse( $result['changed'] );
		$this->assertSame( '', $result['newtext'] );
	}

	/**
	 * @dataProvider provideUnparseable
	 */
	public function testUnparseableResponsesReturnNoChange( string $response ) {
		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( '', $result['newtext'] );
		$this->assertTrue( $result['failed'] );
	}

	public static function provideUnparseable(): array {
		return [
			'empty string' => [ '' ],
			'no json' => [ 'I could not do that.' ],
			'broken json' => [ '{"changed": true, "newtext": ' ],
		];
	}

	public function testRepairsLiteralNewlinesInsideNewtext() {
		$response = "{\"changed\": true, \"newtext\": \"{{Infobox\n| name = X\n}}\nBody text.\", " .
			"\"explanation\": \"Added infobox\"}";

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( "{{Infobox\n| name = X\n}}\nBody text.", $result['newtext'] );
		$this->assertArrayNotHasKey( 'failed', $result );
	}

	public function testRepairsLiteralTabsAndCarriageReturns() {
		$response = "{\"changed\": true, \"newtext\": \"a\tb\r\nc\", \"explanation\": \"x\"}";

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( "a\tb\r\nc", $result['newtext'] );
	}

	public function testRepairLeavesAlreadyValidEscapesUntouched() {
		$response = '{"changed": true, "newtext": "line1\nline2\tend", "explanation": "x"}';

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( "line1\nline2\tend", $result['newtext'] );
	}

	public function testRepairAppliesToExplanationFieldToo() {
		$response = "{\"changed\": true, \"newtext\": \"ok\", \"explanation\": \"line one\nline two\"}";

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( "line one\nline two", $result['explanation'] );
	}

	public function testRepairHandlesRealisticMultiTemplatePage() {
		$newtext = "{{Infobox\n| name = X\n| children = A, B\n}}\n\n" .
			"Some prose.\n\n{{Reflist}}\n\n[[Category:Test]]";
		$response = '{"changed": true, "newtext": "' . $newtext . '", "explanation": "Added children"}';

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( $newtext, $result['newtext'] );
	}

	public function testUnescapedQuoteInsideStringIsAKnownLimitation() {
		$response = '{"changed": true, "newtext": "He said "hello" to her.", "explanation": "x"}';

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['failed'] );
	}

	public function testInvalidBackslashEscapeIsAKnownLimitation() {
		$response = '{"changed": true, "newtext": "match \\d+ digits", "explanation": "x"}';

		$result = $this->newHandler()->parseEditResponse( $response );

		$this->assertTrue( $result['failed'] );
	}
}
