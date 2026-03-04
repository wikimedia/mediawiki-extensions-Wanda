<?php

namespace MediaWiki\Extension\Wanda;

use ExtensionRegistry;
use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class CargoQueryHandler {
	/** @var string */
	private $llmProvider;
	/** @var string */
	private $llmModel;
	/** @var string */
	private $llmApiKey;
	/** @var string */
	private $llmApiEndpoint;
	/** @var int */
	private $timeout;
	/** @var array */
	private $excludedTables;
	/** @var int Maximum number of sequential query steps */
	private $maxQuerySteps;
	/** @var array|null Cache of discovered table names */
	private $availableTables = null;
	/** @var array|null Cache of table schemas */
	private $tableSchemas = null;

	/**
	 * @param string $llmProvider
	 * @param string $llmModel
	 * @param string $llmApiKey
	 * @param string $llmApiEndpoint
	 * @param int $timeout
	 * @param array $excludedTables
	 * @param int $maxQuerySteps
	 */
	public function __construct(
		string $llmProvider,
		string $llmModel,
		string $llmApiKey,
		string $llmApiEndpoint,
		int $timeout,
		array $excludedTables = [],
		int $maxQuerySteps = 3
	) {
		$this->llmProvider = $llmProvider;
		$this->llmModel = $llmModel;
		$this->llmApiKey = $llmApiKey;
		$this->llmApiEndpoint = $llmApiEndpoint;
		$this->timeout = $timeout;
		$this->excludedTables = $excludedTables;
		$this->maxQuerySteps = max( 1, min( $maxQuerySteps, 10 ) );
	}

	/**
	 * Check if the Cargo extension is loaded.
	 *
	 * @return bool
	 */
	public function isCargoAvailable(): bool {
		return ExtensionRegistry::getInstance()->isLoaded( 'Cargo' );
	}

	/**
	 * Main entry point. Query Cargo tables for context relevant to the user question.
	 * Supports multi-step reasoning: the LLM can issue sequential queries where
	 * later queries depend on earlier results.
	 *
	 * @param string $userQuery
	 * @return array {content: string, sources: array, num_results: int, steps: array}
	 */
	public function query( string $userQuery ): array {
		$steps = [];
		$empty = [ 'content' => '', 'sources' => [], 'num_results' => 0, 'steps' => &$steps ];

		if ( !$this->isCargoAvailable() ) {
			wfDebugLog( 'Wanda', 'Cargo extension is not loaded, skipping Cargo queries' );
			$steps[] = [ 'type' => 'error', 'message' => 'Cargo extension is not loaded' ];
			return $empty;
		}

		$schemaDescription = $this->getSchemaDescription();
		if ( $schemaDescription === '' ) {
			wfDebugLog( 'Wanda', wfMessage( 'wanda-cargo-no-tables' )->text() );
			// No structured data available; treat as no Cargo context.
			return $empty;
		}
		// Table discovery is useful for debugging, but is noisy in the UI.
		$tableNames = $this->getAvailableTables();
		wfDebugLog( 'Wanda', 'Cargo available tables: ' . implode( ', ', $tableNames ) );

		$allContent = '';
		$allSources = [];
		$allRowCount = 0;
		$previousResults = '';
		$seenSourceTitles = [];

		for ( $step = 0; $step < $this->maxQuerySteps; $step++ ) {
			if ( $step === 0 ) {
				$llmResult = $this->generateCargoQuery( $userQuery, $schemaDescription );
			} else {
				$llmResult = $this->generateFollowUpQuery(
					$userQuery, $schemaDescription, $previousResults, $step + 1
				);
			}

			if ( $llmResult === null ) {
				if ( $step === 0 ) {
					wfDebugLog( 'Wanda', 'LLM determined no Cargo query is relevant for this question' );
					// No query is relevant; do not surface Cargo thinking UI.
					return $empty;
				}
				wfDebugLog( 'Wanda', 'Cargo multi-step: LLM returned no query at step ' .
					( $step + 1 ) . ', returning accumulated results' );
				break;
			}

			$queryParams = $llmResult['params'];
			$status = $llmResult['status'];
			$reasoning = $llmResult['reasoning'];
			$stepNum = $step + 1;

			$validParams = $this->validateAndSanitize( $queryParams );
			if ( $validParams === null ) {
				wfDebugLog( 'Wanda', wfMessage( 'wanda-cargo-query-error' )->text() .
					' (step ' . $stepNum . ')' );
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'tables' => $queryParams['tables'] ?? 'unknown',
					'where' => (string)( $queryParams['where'] ?? '' ),
					'join_on' => (string)( $queryParams['join_on'] ?? '' ),
					'message' => 'Query failed validation',
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			$queryError = null;
			$rows = $this->executeSafeQuery( $validParams, $queryError );
			if ( $rows === null || empty( $rows ) ) {
				wfDebugLog( 'Wanda', wfMessage( 'wanda-cargo-query-failed' )->text() .
					' (step ' . $stepNum . ')' );
				$errMsg = $rows === null
					? 'Query execution error' . ( $queryError !== null ? ': ' . $queryError : '' )
					: 'Query returned no rows';
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'tables' => $validParams['tables'],
					'where' => $validParams['where'],
					'join_on' => $validParams['join_on'],
					'message' => $errMsg,
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			$stepContent = $this->formatResultsAsContext( $rows, $validParams['tables'] );
			$stepSources = $this->buildSources( $rows, $validParams['tables'] );

			// Record the full query context for the UI thinking panel
			$steps[] = [
				'type' => 'query',
				'step' => $stepNum,
				'tables' => $validParams['tables'],
				'fields' => $validParams['fields'],
				'where' => $validParams['where'],
				'join_on' => $validParams['join_on'],
				'rows' => count( $rows ),
				'status' => $status,
				'reasoning' => $reasoning,
			];

			$allContent .= ( $allContent !== '' ? "\n\n" : '' ) . $stepContent;
			$allRowCount += count( $rows );

			foreach ( $stepSources as $source ) {
				$key = $source['title'] ?? '';
				if ( $key !== '' && !isset( $seenSourceTitles[$key] ) ) {
					$seenSourceTitles[$key] = true;
					$allSources[] = $source;
				}
			}

			$previousResults .= ( $previousResults !== '' ? "\n\n" : '' ) . $stepContent;

			wfDebugLog( 'Wanda', 'Cargo query step ' . $stepNum . ' returned ' .
				count( $rows ) . ' rows from table: ' . $validParams['tables'] .
				' (status: ' . $status . ')' );

			if ( $status !== 'NEEDS_MORE' ) {
				break;
			}

			if ( $step === $this->maxQuerySteps - 1 ) {
				wfDebugLog( 'Wanda', wfMessage( 'wanda-cargo-multistep-max-reached',
					$this->maxQuerySteps )->text() );
			}
		}

		if ( $allContent === '' ) {
			return $empty;
		}

		return [
			'content' => $allContent,
			'sources' => $allSources,
			'num_results' => $allRowCount,
			'steps' => $steps
		];
	}

	/**
	 * Get all available Cargo table names (minus excluded ones).
	 *
	 * @return array
	 */
	private function getAvailableTables(): array {
		if ( $this->availableTables !== null ) {
			return $this->availableTables;
		}

		$allTables = \CargoUtils::getTables();
		$this->availableTables = array_values( array_diff( $allTables, $this->excludedTables ) );
		return $this->availableTables;
	}

	/**
	 * Build a compact schema description for the LLM.
	 *
	 * @return string
	 */
	public function getSchemaDescription(): string {
		$tableNames = $this->getAvailableTables();
		if ( empty( $tableNames ) ) {
			return '';
		}

		try {
			$schemas = \CargoUtils::getTableSchemas( $tableNames );
		} catch ( \MWException $e ) {
			wfDebugLog( 'Wanda', 'Failed to get Cargo table schemas: ' . $e->getMessage() );
			return '';
		}

		$this->tableSchemas = $schemas;
		$lines = [];
		$totalLen = 0;
		$maxSchemaChars = 4000;

		// Build per-table field index for relationship detection
		$tableFields = [];
		foreach ( $schemas as $tableName => $schema ) {
			$fields = [ '_pageName (Page)' ];
			$fieldNames = [ '_pageName' ];
			foreach ( $schema->mFieldDescriptions as $fieldName => $fieldDesc ) {
				$typeStr = $fieldDesc->mType;
				if ( $fieldDesc->mIsList ) {
					$typeStr = 'List of ' . $typeStr;
				}
				$fields[] = $fieldName . ' (' . $typeStr . ')';
				$fieldNames[] = $fieldName;
			}
			$tableFields[$tableName] = $fieldNames;

			$line = 'Table: ' . $tableName . "\n  Fields: " . implode( ', ', $fields );
			$lineLen = strlen( $line );

			if ( $totalLen + $lineLen > $maxSchemaChars ) {
				$remaining = count( $schemas ) - count( $lines );
				if ( $remaining > 0 ) {
					$lines[] = '[... ' . $remaining . ' more tables omitted]';
				}
				break;
			}

			$lines[] = $line;
			$totalLen += $lineLen + 2;
		}

		// Detect cross-table relationships
		$relationships = $this->detectRelationships( $tableFields, $tableNames );
		if ( !empty( $relationships ) ) {
			$relSection = "RELATIONSHIPS (possible joins):\n" . implode( "\n", $relationships );
			if ( $totalLen + strlen( $relSection ) <= $maxSchemaChars + 1000 ) {
				$lines[] = $relSection;
			}
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Detect potential join relationships between tables based on field names.
	 *
	 * @param array $tableFields Map of tableName => array of field names
	 * @param array $tableNames List of all table names
	 * @return array Human-readable relationship descriptions
	 */
	private function detectRelationships( array $tableFields, array $tableNames ): array {
		$relationships = [];
		$tableNameLower = [];
		foreach ( $tableNames as $t ) {
			$tableNameLower[strtolower( $t )] = $t;
		}
		$seen = [];

		foreach ( $tableFields as $tableName => $fields ) {
			foreach ( $fields as $fieldName ) {
				if ( $fieldName === '_pageName' ) {
					continue;
				}

				// Check if field name matches another table name (foreign key pattern)
				$fieldLower = strtolower( $fieldName );
				if ( isset( $tableNameLower[$fieldLower] ) && $tableNameLower[$fieldLower] !== $tableName ) {
					$target = $tableNameLower[$fieldLower];
					$key = $tableName . '.' . $fieldName . '->' . $target;
					if ( !isset( $seen[$key] ) ) {
						$seen[$key] = true;
						$relationships[] = '- ' . $tableName . '.' . $fieldName .
							' → ' . $target . '._pageName (foreign key)';
					}
				}

				// Check for shared field names across tables (natural join candidates)
				foreach ( $tableFields as $otherTable => $otherFields ) {
					if ( $otherTable === $tableName ) {
						continue;
					}
					$pairKey = $tableName < $otherTable
						? $tableName . '|' . $otherTable . '|' . $fieldName
						: $otherTable . '|' . $tableName . '|' . $fieldName;
					if ( isset( $seen[$pairKey] ) ) {
						continue;
					}
					if ( in_array( $fieldName, $otherFields ) ) {
						$seen[$pairKey] = true;
						$relationships[] = '- ' . $tableName . '.' . $fieldName .
							' = ' . $otherTable . '.' . $fieldName . ' (shared field)';
					}
				}
			}

			// _pageName join: any two tables can be joined on _pageName if they
			// store data about the same pages
			foreach ( $tableFields as $otherTable => $otherFields ) {
				if ( $otherTable <= $tableName ) {
					continue;
				}
				$pairKey = $tableName . '|' . $otherTable . '|_pageName';
				if ( !isset( $seen[$pairKey] ) ) {
					$seen[$pairKey] = true;
					$relationships[] = '- ' . $tableName . '._pageName = ' .
						$otherTable . '._pageName (page-level join)';
				}
			}
		}

		return $relationships;
	}

	/**
	 * Use the LLM to generate a Cargo query from the user question and schema.
	 * Returns a structured result with status, params, and reasoning.
	 *
	 * @param string $userQuery
	 * @param string $schemaDescription
	 * @return array|null {status: string, params: array, reasoning: string} or null if NO_QUERY
	 */
	private function generateCargoQuery( string $userQuery, string $schemaDescription ): ?array {
		$prompt = PromptTemplate::render( 'cargo-query', [
			'schema' => $schemaDescription,
			'question' => $userQuery,
		] );

		return $this->callAndParseCargoLLM( $prompt );
	}

	/**
	 * Generate the next Cargo query in a multi-step sequence.
	 * Includes results from prior steps so the LLM can use them for reasoning.
	 *
	 * @param string $userQuery Original user question
	 * @param string $schemaDescription Table schema
	 * @param string $previousResults Formatted results from all prior steps
	 * @param int $stepNumber Current step number (2-based)
	 * @return array|null {status: string, params: array, reasoning: string} or null if NO_QUERY
	 */
	private function generateFollowUpQuery(
		string $userQuery,
		string $schemaDescription,
		string $previousResults,
		int $stepNumber
	): ?array {
		$maxPreviousChars = 6000;
		if ( strlen( $previousResults ) > $maxPreviousChars ) {
			$previousResults = substr( $previousResults, 0, $maxPreviousChars ) .
				"\n[... earlier results truncated ...]";
		}

		$prompt = PromptTemplate::render( 'cargo-followup', [
			'step' => $stepNumber,
			'question' => $userQuery,
			'schema' => $schemaDescription,
			'previous_results' => $previousResults,
		] );

		return $this->callAndParseCargoLLM( $prompt );
	}

	/**
	 * Call the LLM with a cargo prompt and parse the structured response.
	 *
	 * @param string $prompt
	 * @return array|null {status: string, params: array, reasoning: string} or null
	 */
	private function callAndParseCargoLLM( string $prompt ): ?array {
		$response = $this->callLLM( $prompt );
		if ( $response === null ) {
			wfDebugLog( 'Wanda', 'Cargo query generation LLM call failed' );
			return null;
		}

		$response = trim( $response );
		wfDebugLog( 'Wanda', 'Cargo query LLM response: ' . substr( $response, 0, 500 ) );

		// Check for NO_QUERY response
		if ( stripos( $response, 'NO_QUERY' ) !== false ) {
			return null;
		}

		// Try direct JSON decode
		$parsed = json_decode( $response, true );
		if ( $parsed === null || !is_array( $parsed ) ) {
			// Try extracting JSON from surrounding text
			if ( preg_match( '/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $response, $matches ) ) {
				$parsed = json_decode( $matches[0], true );
			}
		}

		if ( $parsed === null || !is_array( $parsed ) ) {
			wfDebugLog( 'Wanda', 'Failed to parse Cargo query from LLM response' );
			return null;
		}

		// Extract protocol fields
		$status = $parsed['status'] ?? 'FINAL_ANSWER';
		$reasoning = $parsed['reasoning'] ?? '';

		// Normalize status to known values
		if ( $status !== 'NEEDS_MORE' ) {
			$status = 'FINAL_ANSWER';
		}

		if ( $reasoning !== '' ) {
			wfDebugLog( 'Wanda', 'Cargo multi-step reasoning: ' . $reasoning );
		}

		// Remove protocol fields before passing to validateAndSanitize
		unset( $parsed['status'] );
		unset( $parsed['reasoning'] );

		return [
			'status' => $status,
			'params' => $parsed,
			'reasoning' => $reasoning
		];
	}

	/**
	 * Validate and sanitize LLM-generated query parameters.
	 *
	 * @param array $params
	 * @return array|null Sanitized params or null if invalid
	 */
	public function validateAndSanitize( array $params ): ?array {
		// Required key
		if ( empty( $params['tables'] ) ) {
			wfDebugLog( 'Wanda', 'Cargo query validation: missing tables parameter' );
			return null;
		}

		$forbidden = [ '--', '#', '/*', ';', '@', '<?', 'SELECT ', 'FROM ', 'INTO ', 'UNION ',
			'DROP ', 'DELETE ', 'INSERT ', 'UPDATE ' ];
		$checkFields = [ 'tables', 'fields', 'where', 'join_on', 'group_by', 'having', 'order_by' ];

		foreach ( $checkFields as $field ) {
			$value = $params[$field] ?? '';
			if ( !is_string( $value ) ) {
				$params[$field] = '';
				continue;
			}
			$upper = strtoupper( $value );
			foreach ( $forbidden as $pattern ) {
				if ( strpos( $upper, strtoupper( $pattern ) ) !== false ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: forbidden pattern "' .
						$pattern . '" found in ' . $field );
					return null;
				}
			}
		}

		// Validate table names against known tables
		$requestedTables = array_map( 'trim', explode( ',', $params['tables'] ) );
		$availableTables = $this->getAvailableTables();
		foreach ( $requestedTables as $table ) {
			// Strip alias (e.g. "Table=t1" -> "Table")
			$tableName = explode( '=', $table )[0];
			$tableName = trim( $tableName );
			if ( !in_array( $tableName, $availableTables ) ) {
				wfDebugLog( 'Wanda', 'Cargo query validation: unknown table "' . $tableName . '"' );
				return null;
			}
		}

		// Validate field names against schema
		if ( !empty( $params['fields'] ) ) {
			$requestedFields = array_map( 'trim', explode( ',', $params['fields'] ) );
			$knownFields = $this->getKnownFields( $requestedTables );

			foreach ( $requestedFields as $field ) {
				// Strip alias (e.g. "Field=Alias" -> "Field")
				$fieldName = explode( '=', $field )[0];
				$fieldName = trim( $fieldName );
				// Strip table prefix (e.g. "Table.Field" -> "Field")
				if ( strpos( $fieldName, '.' ) !== false ) {
					$fieldName = explode( '.', $fieldName, 2 )[1];
				}
				// Allow _pageName and SQL functions
				if ( $fieldName === '_pageName' || $fieldName === '_pageID' ||
					$fieldName === '_pageTitle' || $fieldName === '_pageNamespace' ) {
					continue;
				}
				if ( preg_match( '/^\w+\s*\(/i', $fieldName ) ) {
					// Any function call is allowed here; Cargo enforces its own function whitelist.
					continue;
				}
				if ( !in_array( $fieldName, $knownFields ) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: unknown field "' . $fieldName . '"' );
					return null;
				}
			}
		}

		// Validate join_on references
		if ( !empty( $params['join_on'] ) ) {
			$knownFields = $knownFields ?? $this->getKnownFields( $requestedTables );
			$builtInFields = [ '_pageName', '_pageID', '_pageTitle', '_pageNamespace', '_ID' ];

			// Build the set of valid table identifiers: both real names AND their aliases.
			// e.g. "Employees=E,Departments=D" → ['Employees','E','Departments','D']
			$validTableRefs = [];
			foreach ( $requestedTables as $t ) {
				$parts = array_map( 'trim', explode( '=', $t ) );
				$validTableRefs[] = $parts[0];
				if ( isset( $parts[1] ) && $parts[1] !== '' ) {
					$validTableRefs[] = $parts[1];
				}
			}

			// join_on is comma-separated conditions like "A.x=B.y,B.z=C.w"
			$joinParts = array_map( 'trim', explode( ',', $params['join_on'] ) );
			foreach ( $joinParts as $condition ) {
				// Each condition is "Table.Field OP Table.Field" where OP is =, HOLDS, <=, >=, <, >
				if ( !preg_match(
					'/^(\w+)\.(\w+)\s*(=|HOLDS|<=|>=|<|>)\s*(\w+)\.(\w+)$/i',
					$condition,
					$m
				) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: malformed join_on condition "' .
						$condition . '"' );
					return null;
				}
				$joinTable1 = $m[1];
				$joinField1 = $m[2];
				$joinTable2 = $m[4];
				$joinField2 = $m[5];

				if ( !in_array( $joinTable1, $validTableRefs ) &&
					!in_array( $joinTable1, $availableTables ) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: unknown table "' .
						$joinTable1 . '" in join_on' );
					return null;
				}
				if ( !in_array( $joinTable2, $validTableRefs ) &&
					!in_array( $joinTable2, $availableTables ) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: unknown table "' .
						$joinTable2 . '" in join_on' );
					return null;
				}
				if ( !in_array( $joinField1, $knownFields ) &&
					!in_array( $joinField1, $builtInFields ) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: unknown field "' .
						$joinField1 . '" in join_on' );
					return null;
				}
				if ( !in_array( $joinField2, $knownFields ) &&
					!in_array( $joinField2, $builtInFields ) ) {
					wfDebugLog( 'Wanda', 'Cargo query validation: unknown field "' .
						$joinField2 . '" in join_on' );
					return null;
				}
			}
		}

		// Cap limit
		$limit = isset( $params['limit'] ) ? intval( $params['limit'] ) : 10;
		if ( $limit < 1 || $limit > 50 ) {
			$limit = 10;
		}
		$params['limit'] = (string)$limit;

		// Ensure all expected keys are strings
		$defaults = [
			'tables' => '', 'fields' => '_pageName', 'where' => '',
			'join_on' => '', 'group_by' => '', 'having' => '',
			'order_by' => '', 'limit' => '10'
		];
		foreach ( $defaults as $key => $default ) {
			if ( !isset( $params[$key] ) || !is_string( $params[$key] ) ) {
				$params[$key] = $default;
			}
		}

		return $params;
	}

	/**
	 * Get the set of known field names for the given table names.
	 *
	 * @param array $tableNames
	 * @return array
	 */
	private function getKnownFields( array $tableNames ): array {
		$fields = [];
		if ( $this->tableSchemas === null ) {
			try {
				$cleanNames = [];
				foreach ( $tableNames as $t ) {
					$cleanNames[] = trim( explode( '=', $t )[0] );
				}
				$this->tableSchemas = \CargoUtils::getTableSchemas( $cleanNames );
			} catch ( \MWException $e ) {
				return [];
			}
		}

		foreach ( $this->tableSchemas as $schema ) {
			foreach ( $schema->mFieldDescriptions as $fieldName => $desc ) {
				$fields[] = $fieldName;
			}
		}

		return array_unique( $fields );
	}

	/**
	 * Execute a validated Cargo query safely.
	 *
	 * @param array $params
	 * @param string|null &$error Populated with the exception message on failure
	 * @return array|null Array of rows or null on failure
	 */
	private function executeSafeQuery( array $params, ?string &$error = null ): ?array {
		try {
			$sqlQuery = \CargoSQLQuery::newFromValues(
				$params['tables'],
				$params['fields'],
				$params['where'],
				$params['join_on'],
				$params['group_by'],
				$params['having'],
				$params['order_by'],
				$params['limit'],
				'0'
			);
			return $sqlQuery->run();
		} catch ( \MWException $e ) {
			$error = $e->getMessage();
			wfDebugLog( 'Wanda', 'Cargo query execution failed: ' . $e->getMessage() );
			return null;
		} catch ( \Exception $e ) {
			$error = $e->getMessage();
			wfDebugLog( 'Wanda', 'Cargo query unexpected error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Format Cargo query results as context text for the LLM.
	 *
	 * @param array $rows
	 * @param string $tableName
	 * @return string
	 */
	public function formatResultsAsContext( array $rows, string $tableName ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$maxContextChars = 3000;
		$headers = array_keys( $rows[0] );

		$output = '--- Cargo data from table: ' . $tableName .
			' (' . count( $rows ) . ' rows) ---' . "\n";
		$output .= '| ' . implode( ' | ', $headers ) . " |\n";

		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $headers as $h ) {
				$values[] = $row[$h] ?? '';
			}
			$line = '| ' . implode( ' | ', $values ) . " |\n";

			if ( strlen( $output ) + strlen( $line ) > $maxContextChars ) {
				$output .= '[... results truncated]' . "\n";
				break;
			}
			$output .= $line;
		}

		return $output;
	}

	/**
	 * Build source citation objects for Cargo results.
	 *
	 * @param array $rows
	 * @param string $tables
	 * @return array
	 */
	public function buildSources( array $rows, string $tables ): array {
		$sources = [];
		$seenPages = [];

		// Parse individual table names, stripping aliases (e.g. "MyTable=T1" → "MyTable")
		$tableNames = array_map( static function ( $t ) {
			return trim( explode( '=', trim( $t ) )[0] );
		}, explode( ',', $tables ) );

		// Build a URL for each individual table's Special:CargoTables page
		$tableHrefs = [];
		foreach ( $tableNames as $tableName ) {
			$specialTitle = SpecialPage::getTitleFor( 'CargoTables', $tableName );
			$tableHrefs[$tableName] = $specialTitle ? $specialTitle->getLocalURL() : '';
		}

		// The primary table (first listed) owns the _pageName attribution for row-level sources
		$primaryTable = $tableNames[0];
		$primaryTableHref = $tableHrefs[$primaryTable] ?? '';

		// Row-level sources from _pageName
		foreach ( $rows as $row ) {
			$pageName = $row['_pageName'] ?? null;
			if ( $pageName === null || $pageName === '' || isset( $seenPages[$pageName] ) ) {
				continue;
			}
			$seenPages[$pageName] = true;

			$title = Title::newFromText( $pageName );
			if ( $title ) {
				$sources[] = [
					'title' => $pageName,
					'href' => $title->getLocalURL( 'action=pagevalues' ),
					'cargoTable' => $primaryTable,
					'tableHref' => $primaryTableHref,
					'type' => 'cargo'
				];
			}
		}

		// For aggregate queries (no row-level pages), cite each table separately
		if ( empty( $sources ) && !empty( $rows ) ) {
			foreach ( $tableHrefs as $tableName => $tableHref ) {
				if ( $tableHref !== '' ) {
					$sources[] = [
						'title' => $tableName,
						'href' => $tableHref,
						'cargoTable' => $tableName,
						'tableHref' => $tableHref,
						'type' => 'cargo'
					];
				}
			}
		}

		return $sources;
	}

	/**
	 * Simplified LLM call for generating Cargo queries.
	 * Uses low temperature and small token budget for deterministic JSON output.
	 *
	 * @param string $prompt
	 * @return string|null
	 */
	private function callLLM( string $prompt ): ?string {
		$maxTokens = 768;
		$temperature = 0.1;

		switch ( $this->llmProvider ) {
			case 'ollama':
				return $this->callOllama( $prompt, $maxTokens, $temperature );
			case 'openai':
				return $this->callOpenAI( $prompt, $maxTokens, $temperature );
			case 'anthropic':
				return $this->callAnthropic( $prompt, $maxTokens, $temperature );
			case 'azure':
				return $this->callAzure( $prompt, $maxTokens, $temperature );
			case 'gemini':
				return $this->callGemini( $prompt, $maxTokens, $temperature );
			default:
				wfDebugLog( 'Wanda', 'CargoQueryHandler: unknown LLM provider: ' . $this->llmProvider );
				return null;
		}
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callOllama( string $prompt, int $maxTokens, float $temperature ): ?string {
		$payload = [
			'model' => $this->llmModel,
			'prompt' => $prompt,
			'stream' => false,
			'options' => [
				'temperature' => $temperature,
				'num_predict' => $maxTokens
			]
		];

		$response = $this->curlPost(
			$this->llmApiEndpoint . 'generate',
			json_encode( $payload ),
			[ 'Content-Type: application/json' ]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['response'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callOpenAI( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = trim( (string)( $this->llmModel ?: 'gpt-4-turbo' ) );
		$basePayload = [
			'model' => $model,
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			'temperature' => $temperature
		];

		$tokenKey = APIChat::getOpenAITokenKeyForModel( $model );
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->llmApiKey
		];

		$sendRequest = function ( $payload ) use ( $headers ): ?string {
			$response = $this->curlPost(
				'https://api.openai.com/v1/chat/completions',
				json_encode( $payload ),
				$headers
			);
			return $response;
		};

		$payload = $basePayload;
		$payload[$tokenKey] = $maxTokens;
		$response = $sendRequest( $payload );

		// Retry once if OpenAI rejects the token parameter name.
		if ( $response !== null ) {
			$json = json_decode( $response, true );
			$apiMessage = $json['error']['message'] ?? '';
			if ( is_string( $apiMessage ) && $apiMessage !== '' ) {
				$mentionsMaxTokens = stripos( $apiMessage, 'max_tokens' ) !== false;
				$mentionsMaxCompletion = stripos( $apiMessage, 'max_completion_tokens' ) !== false;

				$retryKey = null;
				if ( $tokenKey === 'max_tokens' && $mentionsMaxCompletion ) {
					$retryKey = 'max_completion_tokens';
				} elseif ( $tokenKey === 'max_completion_tokens' && $mentionsMaxTokens ) {
					$retryKey = 'max_tokens';
				}

				if ( $retryKey !== null ) {
					wfDebugLog( 'Wanda', 'CargoQueryHandler: retrying OpenAI with ' . $retryKey );
					$retryPayload = $basePayload;
					$retryPayload[$retryKey] = $maxTokens;
					$response = $sendRequest( $retryPayload );
					$json = $response !== null ? json_decode( $response, true ) : null;
				}
			}

			return $json['choices'][0]['message']['content'] ?? null;
		}

		return null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callAnthropic( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$payload = [
			'model' => $this->llmModel ?: 'claude-3-haiku-20240307',
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			'max_tokens' => $maxTokens,
			'temperature' => $temperature
		];

		$response = $this->curlPost(
			'https://api.anthropic.com/v1/messages',
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'x-api-key: ' . $this->llmApiKey,
				'anthropic-version: 2023-06-01'
			]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['content'][0]['text'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callAzure( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = trim( (string)( $this->llmModel ?: '' ) );
		$tokenKey = 'max_tokens';
		if ( $model !== '' ) {
			if ( preg_match( '/(^|\\/)(o1|o3)/i', $model ) || stripos( $model, 'gpt-5' ) !== false ) {
				$tokenKey = 'max_completion_tokens';
			}
		}

		$payload = [
			'messages' => [
				[ 'role' => 'user', 'content' => $prompt ]
			],
			$tokenKey => $maxTokens,
			'temperature' => $temperature
		];

		$response = $this->curlPost(
			$this->llmApiEndpoint,
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'api-key: ' . $this->llmApiKey
			]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['choices'][0]['message']['content'] ?? null;
	}

	/**
	 * @param string $prompt
	 * @param int $maxTokens
	 * @param float $temperature
	 * @return string|null
	 */
	private function callGemini( string $prompt, int $maxTokens, float $temperature ): ?string {
		if ( empty( $this->llmApiKey ) ) {
			return null;
		}

		$model = $this->llmModel ?: 'gemini-1.5-flash';
		$base = $this->llmApiEndpoint ?: 'https://generativelanguage.googleapis.com/v1';
		$base = rtrim( $base, '/' );
		if ( strpos( $base, 'http://' ) === 0 ) {
			$base = 'https://' . substr( $base, 7 );
		} elseif ( strpos( $base, 'https://' ) !== 0 && strpos( $base, 'http://' ) !== 0 ) {
			$base = 'https://' . $base;
		}
		$url = $base . '/models/' . rawurlencode( $model ) .
			':generateContent?key=' . urlencode( $this->llmApiKey );

		$payload = [
			'contents' => [
				[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ]
			],
			'generationConfig' => [
				'temperature' => $temperature,
				'maxOutputTokens' => $maxTokens
			]
		];

		$response = $this->curlPost(
			$url,
			json_encode( $payload ),
			[ 'Content-Type: application/json' ]
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );
		return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
	}

	/**
	 * Generic cURL POST helper.
	 *
	 * @param string $url
	 * @param string $data
	 * @param array $headers
	 * @return string|null Response body or null on failure
	 */
	private function curlPost( string $url, string $data, array $headers ): ?string {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', 'CargoQueryHandler cURL error: ' . $curlError );
			return null;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', 'CargoQueryHandler HTTP ' . $httpCode . ': ' .
				substr( $response, 0, 500 ) );
			return null;
		}

		return $response;
	}
}
