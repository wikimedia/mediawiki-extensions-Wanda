<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\SMWQueryHandler;
use MediaWikiUnitTestCase;
use ReflectionClass;

/**
 * Pure-logic unit tests for SMWQueryHandler. Everything exercised here avoids the
 * real Semantic MediaWiki backend: validateAndSanitize(), formatResultsAsContext(),
 * buildAskString() and getSchemaDescription() (with its property index seeded via
 * reflection) need neither SMW nor MediaWiki services. buildSources(), which depends
 * on Title/SpecialPage, is covered separately as an integration test.
 *
 * @covers \MediaWiki\Extension\Wanda\SMWQueryHandler
 * @group Wanda
 */
class SMWQueryHandlerTest extends MediaWikiUnitTestCase {

	private function newHandler( array $excluded = [], int $maxResults = 50 ): SMWQueryHandler {
		return new SMWQueryHandler( 'ollama', 'gemma:2b', '', 'http://x/', 30, $excluded, 3, $maxResults );
	}

	/**
	 * Build a handler with its property index pre-seeded via reflection so
	 * getSchemaDescription() never reaches the real SMW store.
	 *
	 * @param array $index Map of property label => human-readable type label
	 * @return SMWQueryHandler
	 */
	private function newSeededHandler( array $index ): SMWQueryHandler {
		$handler = $this->newHandler();
		$rc = new ReflectionClass( $handler );
		$prop = $rc->getProperty( 'propertyIndex' );
		$prop->setValue( $handler, $index );
		return $handler;
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param SMWQueryHandler $handler
	 * @param string $method
	 * @param mixed ...$args
	 * @return mixed
	 */
	private function callPrivate( SMWQueryHandler $handler, string $method, ...$args ) {
		$rc = new ReflectionClass( $handler );
		$m = $rc->getMethod( $method );
		return $m->invokeArgs( $handler, $args );
	}

	// --- validateAndSanitize ------------------------------------------------

	public function testValidateRejectsMissingConditions() {
		$this->assertNull( $this->newHandler()->validateAndSanitize( [] ) );
		$this->assertNull( $this->newHandler()->validateAndSanitize( [ 'conditions' => '' ] ) );
	}

	public function testValidateRejectsMalformedConditions() {
		// A condition must contain a complete [[...]] descriptor.
		$this->assertNull( $this->newHandler()->validateAndSanitize( [ 'conditions' => 'Category:City' ] ) );
		$this->assertNull( $this->newHandler()->validateAndSanitize( [ 'conditions' => '[[Category:City' ] ) );
	}

	public function testValidateRejectsForbiddenCharactersInConditions() {
		// Braces would let the query break out into other parser constructs.
		$this->assertNull( $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]{{Evil}}',
		] ) );
	}

	public function testValidateRejectsLonePipeInConditions() {
		// A lone "|" would open a new {{#ask:}} parameter (e.g. |format=) and must
		// be rejected, unlike the legitimate "||" OR operator below.
		$this->assertNull( $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]|format=template',
		] ) );
	}

	/**
	 * The "||" OR operator is the exact disjunction syntax both SMW prompt templates
	 * instruct the LLM to emit (e.g. [[Located in::Germany||France]]). Paired pipes
	 * must pass validation; the descriptor is preserved verbatim.
	 */
	public function testValidateAcceptsOrDisjunction() {
		$result = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Located in::Germany||France]]',
		] );
		$this->assertIsArray( $result );
		$this->assertSame( '[[Located in::Germany||France]]', $result['conditions'] );
	}

	public function testValidateAcceptsMinimalConditions() {
		$result = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
		] );

		$this->assertIsArray( $result );
		$this->assertSame( '[[Category:City]]', $result['conditions'] );
		$this->assertSame( [], $result['printouts'] );
		$this->assertSame( '', $result['sort'] );
		$this->assertSame( '', $result['order'] );
		// Default limit is an int (contrast with Cargo's string limit).
		$this->assertSame( 10, $result['limit'] );
	}

	public function testValidateNormalisesPrintoutsArray() {
		$result = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			// Leading "?" is stripped; entries with forbidden chars are dropped.
			'printouts' => [ '?Population', 'Area', 'Bad[Field]', '  ' ],
		] );

		$this->assertSame( [ 'Population', 'Area' ], $result['printouts'] );
	}

	public function testValidateNormalisesPrintoutsString() {
		$result = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			// A comma-separated string is accepted too.
			'printouts' => '?Population, Area',
		] );

		$this->assertSame( [ 'Population', 'Area' ], $result['printouts'] );
	}

	public function testValidateSanitisesSortAndOrder() {
		// A sort containing forbidden chars is dropped; an unknown order is cleared.
		$result = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			'sort' => 'Pop[ulation]',
			'order' => 'sideways',
		] );
		$this->assertSame( '', $result['sort'] );
		$this->assertSame( '', $result['order'] );

		// Valid sort/order pass through; order is lower-cased.
		$result2 = $this->newHandler()->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			'sort' => 'Population',
			'order' => 'DESC',
		] );
		$this->assertSame( 'Population', $result2['sort'] );
		$this->assertSame( 'desc', $result2['order'] );
	}

	public function testValidateCapsLimitToMaxResults() {
		$handler = $this->newHandler( [], 25 );
		// Over-range limit falls back to min( 10, maxResults ).
		$result = $handler->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			'limit' => 999,
		] );
		$this->assertSame( 10, $result['limit'] );

		// An in-range explicit limit is preserved.
		$result2 = $handler->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			'limit' => 5,
		] );
		$this->assertSame( 5, $result2['limit'] );
	}

	public function testValidateLimitFallbackRespectsSmallMaxResults() {
		// When maxResults < 10, the fallback default must not exceed it.
		$handler = $this->newHandler( [], 3 );
		$result = $handler->validateAndSanitize( [
			'conditions' => '[[Category:City]]',
			'limit' => 999,
		] );
		$this->assertSame( 3, $result['limit'] );
	}

	// --- buildAskString -----------------------------------------------------

	public function testBuildAskStringRendersFullQuery() {
		$ask = $this->callPrivate( $this->newHandler(), 'buildAskString', [
			'conditions' => '[[Category:City]]',
			'printouts' => [ 'Population', 'Area' ],
			'sort' => 'Population',
			'order' => 'desc',
			'limit' => 5,
		] );

		$this->assertStringContainsString( '{{#ask: [[Category:City]]', $ask );
		$this->assertStringContainsString( '|?Population', $ask );
		$this->assertStringContainsString( '|?Area', $ask );
		$this->assertStringContainsString( '|limit=5', $ask );
		$this->assertStringContainsString( '|sort=Population', $ask );
		$this->assertStringContainsString( '|order=desc', $ask );
		$this->assertStringEndsWith( ' }}', $ask );
	}

	public function testBuildAskStringOmitsEmptySortAndOrder() {
		$ask = $this->callPrivate( $this->newHandler(), 'buildAskString', [
			'conditions' => '[[Category:City]]',
			'printouts' => [],
			'sort' => '',
			'order' => '',
			'limit' => 10,
		] );
		$this->assertStringContainsString( '|limit=10', $ask );
		$this->assertStringNotContainsString( 'sort=', $ask );
		$this->assertStringNotContainsString( 'order=', $ask );
	}

	// --- formatResultsAsContext ---------------------------------------------

	public function testFormatResultsAsContextEmpty() {
		$this->assertSame( '', $this->newHandler()->formatResultsAsContext( [] ) );
	}

	public function testFormatResultsAsContextRendersTable() {
		$out = $this->newHandler()->formatResultsAsContext( [
			[ '_page' => 'Berlin', 'Population' => '3700000' ],
			[ '_page' => 'Munich', 'Population' => '1500000' ],
		] );

		$this->assertStringContainsString( '--- Semantic MediaWiki results (2 rows) ---', $out );
		$this->assertStringContainsString( '| _page | Population |', $out );
		$this->assertStringContainsString( '| Berlin | 3700000 |', $out );
		$this->assertStringContainsString( '| Munich | 1500000 |', $out );
	}

	public function testFormatResultsAsContextUnionsSparseHeaders() {
		// The second row has a column the first lacks; the header row unions both
		// and the missing cell renders empty.
		$out = $this->newHandler()->formatResultsAsContext( [
			[ '_page' => 'Berlin', 'Population' => '3700000' ],
			[ '_page' => 'Paris', 'Area' => '105' ],
		] );

		$this->assertStringContainsString( '| _page | Population | Area |', $out );
		// Berlin has no Area → trailing empty cell.
		$this->assertStringContainsString( '| Berlin | 3700000 |  |', $out );
		// Paris has no Population → empty middle cell.
		$this->assertStringContainsString( '| Paris |  | 105 |', $out );
	}

	public function testFormatResultsAsContextEscapesPipes() {
		$out = $this->newHandler()->formatResultsAsContext( [
			[ '_page' => 'A', 'Note' => 'x | y' ],
		] );
		$this->assertStringContainsString( 'x \\| y', $out );
	}

	// --- getSchemaDescription -----------------------------------------------

	public function testGetSchemaDescriptionEmptyWhenNoProperties() {
		$this->assertSame( '', $this->newSeededHandler( [] )->getSchemaDescription() );
	}

	public function testGetSchemaDescriptionListsProperties() {
		$out = $this->newSeededHandler( [
			'Population' => 'Number',
			'Located in' => 'Page',
		] )->getSchemaDescription();

		$this->assertStringContainsString( 'PROPERTIES', $out );
		$this->assertStringContainsString( '- Population (Number)', $out );
		$this->assertStringContainsString( '- Located in (Page)', $out );
	}

	public function testGetSchemaDescriptionTruncatesLongIndex() {
		// Build far more properties than fit in the 4000-char budget so the
		// "[... N more properties omitted]" marker appears.
		$index = [];
		for ( $i = 0; $i < 400; $i++ ) {
			$index[ 'PropertyWithAFairlyLongName' . $i ] = 'Text';
		}
		$out = $this->newSeededHandler( $index )->getSchemaDescription();

		$this->assertMatchesRegularExpression( '/\[\.\.\. \d+ more properties omitted\]/', $out );
		// The budget is a soft cap of 4000 chars plus the header and marker line.
		$this->assertLessThan( 4300, strlen( $out ) );
	}
}
