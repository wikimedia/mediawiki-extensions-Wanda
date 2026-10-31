<?php

namespace MediaWiki\Extension\Wanda;

use ExtensionRegistry;
use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use User;

class CargoQueryHandler {
	private const LLM_MAX_TOKENS = 768;
	private const LLM_TEMPERATURE = 0.1;

	/** @var LLMClient */
	private $llm;
	/** @var array */
	private $excludedTables;
	/** @var int Maximum number of sequential query steps */
	private $maxQuerySteps;
	/** @var array|null Cache of discovered table names */
	private $availableTables = null;
	/** @var array|null Cache of table schemas */
	private $tableSchemas = null;
	/** @var User|null Requesting user whose read permissions results are gated by */
	private $user;

	/**
	 * @param LLMClient $llm
	 * @param array $excludedTables
	 * @param int $maxQuerySteps
	 * @param User|null $user Requesting user; when set, results are filtered to
	 *   the pages this user is allowed to read. Null disables permission gating.
	 */
	public function __construct(
		LLMClient $llm,
		array $excludedTables = [],
		int $maxQuerySteps = 3,
		?User $user = null
	) {
		$this->llm = $llm;
		$this->excludedTables = $excludedTables;
		$this->maxQuerySteps = max( 1, min( $maxQuerySteps, 10 ) );
		$this->user = $user;
	}

	/**
	 * Check if the Cargo extension is loaded.
	 *
	 * @return bool
	 */
	public static function isCargoAvailable(): bool {
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

		if ( !self::isCargoAvailable() ) {
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

			// Permission gating: every page that contributes fields to a row must
			// be readable by the requesting user. Inject each table's _pageName so
			// the rows can be filtered after execution.
			//
			// Aggregate queries (GROUP BY / COUNT / AVG / ...) expose derived
			// statistics rather than page content or titles, and have no per-page
			// provenance to attribute a row to, so they are passed through
			// unfiltered (consistent with the task's scope of reading *pages*).
			$pageColumns = [];
			if ( $this->user !== null && !$this->isAggregateQuery( $validParams ) ) {
				[ $validParams['fields'], $pageColumns ] = $this->injectPageProvenance(
					$validParams['fields'], $validParams['tables']
				);
			}

			$queryError = null;
			$rows = $this->executeSafeQuery( $validParams, $queryError );

			// Keep only rows whose every contributing page the user may read, then
			// drop the synthetic provenance columns so they never reach the LLM.
			if ( $this->user !== null && is_array( $rows ) && !empty( $pageColumns ) ) {
				$rows = self::filterRowsByReadablePages( $rows, $pageColumns, [ $this, 'canUserReadPage' ] );
				$rows = self::stripSyntheticColumns( $rows, $pageColumns );
			}
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
	 * Whether the query has no per-row page provenance and therefore cannot be
	 * permission-checked against individual pages (GROUP BY or aggregate
	 * functions in the field list).
	 *
	 * @param array $params Validated query params
	 * @return bool
	 */
	private function isAggregateQuery( array $params ): bool {
		if ( trim( (string)( $params['group_by'] ?? '' ) ) !== '' ) {
			return true;
		}
		$fields = (string)( $params['fields'] ?? '' );
		return (bool)preg_match( '/\b(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i', $fields );
	}

	/**
	 * Get the reference (alias if present, else table name) for each table in a
	 * comma-separated tables clause. e.g. "Employees=E,Departments" → [E, Departments].
	 *
	 * @param string $tables
	 * @return array
	 */
	private function getQueryTableRefs( string $tables ): array {
		$refs = [];
		foreach ( explode( ',', $tables ) as $t ) {
			$parts = array_map( 'trim', explode( '=', trim( $t ) ) );
			$name = $parts[0];
			if ( $name === '' ) {
				continue;
			}
			$refs[] = ( isset( $parts[1] ) && $parts[1] !== '' ) ? $parts[1] : $name;
		}
		return $refs;
	}

	/**
	 * Ensure the selected fields expose the _pageName of every table contributing
	 * to a row, so read permission can be verified per page.
	 *
	 * For a single table the bare _pageName is selected (preserving existing
	 * source attribution). For joins, each table's _pageName is selected under a
	 * synthetic alias (__wanda_page_N) which is stripped before the rows are
	 * formatted or cited.
	 *
	 * @param string $fields Original field list
	 * @param string $tables Tables clause
	 * @return array [ string $fields, array $pageColumns ] — the (possibly
	 *   extended) field list and the row keys that hold page names to check.
	 */
	private function injectPageProvenance( string $fields, string $tables ): array {
		$refs = $this->getQueryTableRefs( $tables );
		$fields = trim( $fields );

		if ( count( $refs ) <= 1 ) {
			// Single table: bare _pageName matches the Cargo default and the
			// _pageName key that buildSources() already relies on.
			if ( !$this->fieldsContainPageName( $fields ) ) {
				$fields = ( $fields === '' ) ? '_pageName' : $fields . ',_pageName';
			}
			return [ $fields, [ '_pageName' ] ];
		}

		$pageColumns = [];
		foreach ( $refs as $i => $ref ) {
			$alias = '__wanda_page_' . $i;
			$pageColumns[] = $alias;
			$fields = ( $fields === '' ? '' : $fields . ',' ) . $ref . '._pageName=' . $alias;
		}
		return [ $fields, $pageColumns ];
	}

	/**
	 * Whether the (bare) _pageName field is already present in a field list.
	 *
	 * @param string $fields
	 * @return bool
	 */
	private function fieldsContainPageName( string $fields ): bool {
		foreach ( explode( ',', $fields ) as $f ) {
			$name = trim( explode( '=', trim( $f ) )[0] );
			if ( strpos( $name, '.' ) !== false ) {
				$name = explode( '.', $name, 2 )[1];
			}
			if ( strcasecmp( trim( $name ), '_pageName' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filter rows to those whose every contributing page the user may read.
	 *
	 * A row is kept only if, for every provenance column, the page name is
	 * non-empty and passes the read predicate. Missing/empty provenance fails
	 * closed (the row is dropped), so unattributable data is never surfaced.
	 *
	 * @param array $rows Result rows
	 * @param array $pageColumns Row keys holding page names to check
	 * @param callable $canRead fn( string $pageName ): bool
	 * @return array Filtered rows
	 */
	public static function filterRowsByReadablePages( array $rows, array $pageColumns, callable $canRead ): array {
		$filtered = [];
		foreach ( $rows as $row ) {
			$readable = true;
			foreach ( $pageColumns as $col ) {
				$pageName = isset( $row[$col] ) ? trim( (string)$row[$col] ) : '';
				if ( $pageName === '' || !$canRead( $pageName ) ) {
					$readable = false;
					break;
				}
			}
			if ( $readable ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	/**
	 * Remove synthetic provenance columns (__wanda_page_*) from result rows so
	 * they are never shown to the LLM or used for source attribution.
	 *
	 * @param array $rows
	 * @param array $pageColumns
	 * @return array
	 */
	private static function stripSyntheticColumns( array $rows, array $pageColumns ): array {
		$synthetic = array_filter( $pageColumns, static function ( $c ) {
			return strncmp( $c, '__wanda_page_', 13 ) === 0;
		} );
		if ( empty( $synthetic ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			foreach ( $synthetic as $c ) {
				unset( $row[$c] );
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Whether the requesting user is allowed to read the given page.
	 *
	 * Delegates to core PermissionManager so Cargo results inherit the exact read
	 * permissions of the user (per-namespace $wgGroupPermissions, Lockdown, etc.).
	 *
	 * @param string $pageName Prefixed page name from a row's _pageName
	 * @return bool
	 */
	private function canUserReadPage( string $pageName ): bool {
		$title = Title::newFromText( $pageName );
		if ( !$title ) {
			return false;
		}
		return MediaWikiServices::getInstance()->getPermissionManager()
			->userCan( 'read', $this->user, $title );
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
		$response = $this->llm->complete( $prompt, self::LLM_MAX_TOKENS, self::LLM_TEMPERATURE );
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

		$maxContextChars = intdiv( APIChat::$maxContextChars, 4 );
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

		// Build a URL for each individual table's Special:CargoTables page.
		// The special page only exists when the Cargo extension is installed, so
		// guard against missing aliases (which would otherwise emit a warning).
		$cargoTablesExists = MediaWikiServices::getInstance()
			->getSpecialPageFactory()
			->exists( 'CargoTables' );
		$tableHrefs = [];
		foreach ( $tableNames as $tableName ) {
			if ( $cargoTablesExists ) {
				$specialTitle = SpecialPage::getTitleFor( 'CargoTables', $tableName );
				$tableHrefs[$tableName] = $specialTitle ? $specialTitle->getLocalURL() : '';
			} else {
				$tableHrefs[$tableName] = '';
			}
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
				$sources[] = [
					'title' => $tableName,
					'href' => $tableHref,
					'cargoTable' => $tableName,
					'tableHref' => $tableHref,
					'type' => 'cargo'
				];
			}
		}

		return $sources;
	}
}
