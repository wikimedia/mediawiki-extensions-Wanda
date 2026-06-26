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

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param CargoQueryHandler $handler
	 * @param string $method
	 * @param mixed ...$args
	 * @return mixed
	 */
	private function callPrivate( CargoQueryHandler $handler, string $method, ...$args ) {
		$rc = new ReflectionClass( $handler );
		$m = $rc->getMethod( $method );
		return $m->invokeArgs( $handler, $args );
	}

	public function testFilterRowsByReadablePagesSingleColumn() {
		$rows = [
			[ '_pageName' => 'Public', 'Salary' => '100' ],
			[ '_pageName' => 'Secret', 'Salary' => '200' ],
			[ '_pageName' => 'AlsoPublic', 'Salary' => '300' ],
		];
		$canRead = static function ( string $page ): bool {
			return $page !== 'Secret';
		};

		$filtered = CargoQueryHandler::filterRowsByReadablePages( $rows, [ '_pageName' ], $canRead );

		$this->assertSame(
			[ 'Public', 'AlsoPublic' ],
			array_column( $filtered, '_pageName' )
		);
	}

	public function testFilterRowsByReadablePagesJoinRequiresAllReadable() {
		// A joined row carries one page per table; the row is kept only when
		// every contributing page is readable.
		$rows = [
			// Both readable → kept.
			[ '__wanda_page_0' => 'EmpA', '__wanda_page_1' => 'DeptX', 'Salary' => '1' ],
			// Department page restricted → dropped even though employee is readable.
			[ '__wanda_page_0' => 'EmpB', '__wanda_page_1' => 'SecretDept', 'Salary' => '2' ],
			// Employee page restricted → dropped.
			[ '__wanda_page_0' => 'SecretEmp', '__wanda_page_1' => 'DeptY', 'Salary' => '3' ],
		];
		$canRead = static function ( string $page ): bool {
			return strpos( $page, 'Secret' ) === false;
		};

		$filtered = CargoQueryHandler::filterRowsByReadablePages(
			$rows, [ '__wanda_page_0', '__wanda_page_1' ], $canRead
		);

		$this->assertCount( 1, $filtered );
		$this->assertSame( '1', $filtered[0]['Salary'] );
	}

	public function testFilterRowsByReadablePagesFailsClosedOnMissingProvenance() {
		$rows = [
			[ '_pageName' => 'Public' ],
			// Missing provenance column → dropped (fail closed).
			[ 'Salary' => '5' ],
			// Empty provenance → dropped.
			[ '_pageName' => '' ],
		];
		$filtered = CargoQueryHandler::filterRowsByReadablePages(
			$rows, [ '_pageName' ], static fn () => true
		);
		$this->assertCount( 1, $filtered );
		$this->assertSame( 'Public', $filtered[0]['_pageName'] );
	}

	public function testIsAggregateQueryDetectsGroupByAndFunctions() {
		$handler = $this->newSeededHandler();

		$this->assertTrue( $this->callPrivate( $handler, 'isAggregateQuery',
			[ 'fields' => 'Dept', 'group_by' => 'Dept' ] ) );
		$this->assertTrue( $this->callPrivate( $handler, 'isAggregateQuery',
			[ 'fields' => 'COUNT(Name)=cnt', 'group_by' => '' ] ) );
		$this->assertTrue( $this->callPrivate( $handler, 'isAggregateQuery',
			[ 'fields' => 'AVG(Salary)', 'group_by' => '' ] ) );
		$this->assertFalse( $this->callPrivate( $handler, 'isAggregateQuery',
			[ 'fields' => 'Name,Salary', 'group_by' => '' ] ) );
	}

	public function testInjectPageProvenanceSingleTableAddsBarePageName() {
		$handler = $this->newSeededHandler();

		[ $fields, $cols ] = $this->callPrivate(
			$handler, 'injectPageProvenance', 'Name,Salary', 'Employees'
		);
		$this->assertSame( 'Name,Salary,_pageName', $fields );
		$this->assertSame( [ '_pageName' ], $cols );

		// Already present → not duplicated.
		[ $fields2, $cols2 ] = $this->callPrivate(
			$handler, 'injectPageProvenance', '_pageName,Name', 'Employees'
		);
		$this->assertSame( '_pageName,Name', $fields2 );
		$this->assertSame( [ '_pageName' ], $cols2 );
	}

	public function testInjectPageProvenanceJoinAddsPerTableAliases() {
		$handler = $this->newSeededHandler();

		[ $fields, $cols ] = $this->callPrivate(
			$handler, 'injectPageProvenance', 'E.Name,D.DeptName', 'Employees=E,Departments=D'
		);
		$this->assertStringContainsString( 'E._pageName=__wanda_page_0', $fields );
		$this->assertStringContainsString( 'D._pageName=__wanda_page_1', $fields );
		$this->assertSame( [ '__wanda_page_0', '__wanda_page_1' ], $cols );
	}
}
