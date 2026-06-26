<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\CargoQueryHandler;
use MediaWikiUnitTestCase;
use ReflectionClass;
use stdClass;

/**
 * @covers \MediaWiki\Extension\Wanda\CargoQueryHandler
 * @group Wanda
 */
class CargoQueryHandlerTest extends MediaWikiUnitTestCase {

	/**
	 * Build a handler with its table/schema caches pre-seeded via reflection so
	 * validateAndSanitize() never reaches the real Cargo extension.
	 */
	private function newSeededHandler(): CargoQueryHandler {
		$handler = new CargoQueryHandler( 'ollama', 'gemma:2b', '', 'http://x/', 30, [], 3 );
		$rc = new ReflectionClass( $handler );

		$avail = $rc->getProperty( 'availableTables' );
		$avail->setValue( $handler, [ 'Employees', 'Departments' ] );

		$empSchema = new stdClass();
		$empSchema->mFieldDescriptions = [ 'Name' => null, 'Salary' => null, 'Dept' => null ];
		$deptSchema = new stdClass();
		$deptSchema->mFieldDescriptions = [ 'DeptName' => null ];

		$schemas = $rc->getProperty( 'tableSchemas' );
		$schemas->setValue( $handler, [ $empSchema, $deptSchema ] );

		return $handler;
	}

	public function testFormatResultsAsContextEmpty() {
		$handler = new CargoQueryHandler( 'ollama', 'm', '', 'http://x/', 30 );
		$this->assertSame( '', $handler->formatResultsAsContext( [], 'Employees' ) );
	}

	public function testFormatResultsAsContextRendersTable() {
		$handler = new CargoQueryHandler( 'ollama', 'm', '', 'http://x/', 30 );
		$out = $handler->formatResultsAsContext( [
			[ '_pageName' => 'P1', 'Name' => 'Alice' ],
			[ '_pageName' => 'P2', 'Name' => 'Bob' ],
		], 'Employees' );

		$this->assertStringContainsString(
			'--- Cargo data from table: Employees (2 rows) ---',
			$out
		);
		$this->assertStringContainsString( '| _pageName | Name |', $out );
		$this->assertStringContainsString( '| P1 | Alice |', $out );
		$this->assertStringContainsString( '| P2 | Bob |', $out );
	}

	public function testValidateRejectsMissingTables() {
		$this->assertNull( $this->newSeededHandler()->validateAndSanitize( [] ) );
	}

	public function testValidateRejectsForbiddenPatterns() {
		$this->assertNull( $this->newSeededHandler()->validateAndSanitize( [
			'tables' => 'Employees',
			'where' => 'DROP TABLE x',
		] ) );
	}

	public function testValidateRejectsUnknownTable() {
		$this->assertNull( $this->newSeededHandler()->validateAndSanitize( [
			'tables' => 'NotARealTable',
		] ) );
	}

	public function testValidateRejectsUnknownField() {
		$this->assertNull( $this->newSeededHandler()->validateAndSanitize( [
			'tables' => 'Employees',
			'fields' => 'Bogus',
		] ) );
	}

	public function testValidateAcceptsValidQueryAndCapsLimit() {
		$result = $this->newSeededHandler()->validateAndSanitize( [
			'tables' => 'Employees',
			'fields' => 'Name,Salary',
			'limit' => 999,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Employees', $result['tables'] );
		$this->assertSame( 'Name,Salary', $result['fields'] );
		// Out-of-range limit falls back to the default of 10 (as a string).
		$this->assertSame( '10', $result['limit'] );
		// Missing optional keys are filled with string defaults.
		$this->assertSame( '', $result['where'] );
		$this->assertSame( '', $result['join_on'] );
		$this->assertSame( '', $result['group_by'] );
		$this->assertSame( '', $result['having'] );
		$this->assertSame( '', $result['order_by'] );
	}

	public function testValidateAllowsBuiltinAndFunctionFields() {
		$result = $this->newSeededHandler()->validateAndSanitize( [
			'tables' => 'Employees',
			'fields' => '_pageName,COUNT(Name)=cnt',
		] );
		$this->assertIsArray( $result );
		$this->assertSame( '_pageName,COUNT(Name)=cnt', $result['fields'] );
	}
}
