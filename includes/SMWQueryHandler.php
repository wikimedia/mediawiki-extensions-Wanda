<?php

namespace MediaWiki\Extension\Wanda;

use ExtensionRegistry;
use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Retrieves structured context from a local Semantic MediaWiki (SMW) store by
 * letting the LLM author SMW "ask" queries (the same query language used by the
 * {{#ask:}} parser function). Modelled closely on CargoQueryHandler — SMW is, like
 * Cargo, an in-wiki structured-data store, so the multi-step query/validate/execute
 * pipeline is identical in shape. Only the query language and execution backend differ.
 *
 * All calls into the SMW PHP API are wrapped in try/catch because SMW is an optional
 * runtime dependency that is not present in this checkout; the handler degrades to an
 * empty result whenever SMW is unavailable or an API call fails.
 */
class SMWQueryHandler {
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
	/** @var array Property labels to hide from the LLM */
	private $excludedProperties;
	/** @var int Maximum number of sequential query steps */
	private $maxQuerySteps;
	/** @var int Hard cap on rows returned per query */
	private $maxResults;
	/** @var array|null Cache of discovered property metadata: [label => typeLabel] */
	private $propertyIndex = null;

	/** @var array Human-readable names for the common SMW datatype IDs. */
	private static $typeLabels = [
		'_wpg' => 'Page',
		'_txt' => 'Text',
		'_cod' => 'Code',
		'_num' => 'Number',
		'_qty' => 'Quantity',
		'_dat' => 'Date',
		'_boo' => 'Boolean',
		'_geo' => 'Geographic coordinate',
		'_uri' => 'URL',
		'_ema' => 'Email',
		'_tel' => 'Telephone number',
		'_rec' => 'Record',
		'_mlt_rec' => 'Monolingual text',
		'_eid' => 'External identifier',
		'_keyw' => 'Keyword',
	];

	/**
	 * @param string $llmProvider
	 * @param string $llmModel
	 * @param string $llmApiKey
	 * @param string $llmApiEndpoint
	 * @param int $timeout
	 * @param array $excludedProperties
	 * @param int $maxQuerySteps
	 * @param int $maxResults
	 */
	public function __construct(
		string $llmProvider,
		string $llmModel,
		string $llmApiKey,
		string $llmApiEndpoint,
		int $timeout,
		array $excludedProperties = [],
		int $maxQuerySteps = 3,
		int $maxResults = 50
	) {
		$this->llmProvider = $llmProvider;
		$this->llmModel = $llmModel;
		$this->llmApiKey = $llmApiKey;
		$this->llmApiEndpoint = $llmApiEndpoint;
		$this->timeout = $timeout;
		$this->excludedProperties = $excludedProperties;
		$this->maxQuerySteps = max( 1, min( $maxQuerySteps, 10 ) );
		$this->maxResults = max( 1, min( $maxResults, 500 ) );
	}

	/**
	 * Check if Semantic MediaWiki is loaded.
	 *
	 * SMW registers itself under the name "SemanticMediaWiki" and also defines the
	 * SMW_VERSION constant; either signal is sufficient.
	 *
	 * @return bool
	 */
	public static function isSMWAvailable(): bool {
		return defined( 'SMW_VERSION' )
			|| ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' );
	}

	/**
	 * Main entry point. Query the SMW store for context relevant to the user question.
	 * Supports multi-step reasoning: the LLM can issue sequential queries where later
	 * queries depend on earlier results.
	 *
	 * @param string $userQuery
	 * @return array {content: string, sources: array, num_results: int, steps: array}
	 */
	public function query( string $userQuery ): array {
		$steps = [];
		$empty = [ 'content' => '', 'sources' => [], 'num_results' => 0, 'steps' => &$steps ];

		if ( !self::isSMWAvailable() ) {
			wfDebugLog( 'Wanda', 'Semantic MediaWiki is not loaded, skipping SMW queries' );
			$steps[] = [ 'type' => 'error', 'message' => 'Semantic MediaWiki is not loaded' ];
			return $empty;
		}

		$schemaDescription = $this->getSchemaDescription();
		if ( $schemaDescription === '' ) {
			wfDebugLog( 'Wanda', 'No SMW properties are available for querying' );
			// No structured data available; treat as no SMW context.
			return $empty;
		}

		$allContent = '';
		$allSources = [];
		$allRowCount = 0;
		$previousResults = '';
		$seenSourceTitles = [];

		for ( $step = 0; $step < $this->maxQuerySteps; $step++ ) {
			$stepNum = $step + 1;

			if ( $step === 0 ) {
				$llmResult = $this->generateSMWQuery( $userQuery, $schemaDescription );
			} else {
				$llmResult = $this->generateFollowUpQuery(
					$userQuery, $schemaDescription, $previousResults, $stepNum
				);
			}

			if ( $llmResult === null ) {
				if ( $step === 0 ) {
					wfDebugLog( 'Wanda', 'LLM determined no SMW query is relevant for this question' );
					return $empty;
				}
				wfDebugLog( 'Wanda', 'SMW multi-step: LLM returned no query at step ' .
					$stepNum . ', returning accumulated results' );
				break;
			}

			$validParams = $this->validateAndSanitize( $llmResult['params'] );
			$status = $llmResult['status'];
			$reasoning = $llmResult['reasoning'];

			if ( $validParams === null ) {
				wfDebugLog( 'Wanda', 'SMW query failed validation (step ' . $stepNum . ')' );
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'conditions' => (string)( $llmResult['params']['conditions'] ?? '' ),
					'message' => 'Query failed validation',
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			$ask = $this->buildAskString( $validParams );

			$queryError = null;
			$rows = $this->executeQuery( $validParams, $queryError );
			if ( $rows === null || empty( $rows ) ) {
				$errMsg = $rows === null
					? 'Query execution error' . ( $queryError !== null ? ': ' . $queryError : '' )
					: 'Query returned no results';
				wfDebugLog( 'Wanda', 'SMW query ' . $errMsg . ' (step ' . $stepNum . ')' );
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'conditions' => $validParams['conditions'],
					'ask' => $ask,
					'message' => $errMsg,
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			$stepContent = $this->formatResultsAsContext( $rows, $validParams['conditions'] );
			$stepSources = $this->buildSources( $rows );

			$steps[] = [
				'type' => 'query',
				'step' => $stepNum,
				'conditions' => $validParams['conditions'],
				'printouts' => implode( ', ', $validParams['printouts'] ),
				'ask' => $ask,
				'rows' => count( $rows ),
				'results' => array_map( static function ( $row ) {
					if ( isset( $row['_page'] ) ) {
						$row['page'] = $row['_page'];
					}
					return $row;
				}, $rows ),
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

			wfDebugLog( 'Wanda', 'SMW query step ' . $stepNum . ' returned ' .
				count( $rows ) . ' rows (status: ' . $status . ')' );

			if ( $status !== 'NEEDS_MORE' ) {
				break;
			}

			if ( $step === $this->maxQuerySteps - 1 ) {
				wfDebugLog( 'Wanda', 'SMW reached maximum query steps (' . $this->maxQuerySteps . ')' );
			}
		}

		if ( $allContent === '' ) {
			return $empty;
		}

		return [
			'content' => $allContent,
			'sources' => $allSources,
			'num_results' => $allRowCount,
			'steps' => $steps,
		];
	}

	/**
	 * Build a compact description of the available SMW properties for the LLM.
	 *
	 * @return string
	 */
	public function getSchemaDescription(): string {
		$index = $this->getPropertyIndex();
		if ( empty( $index ) ) {
			return '';
		}

		$lines = [];
		$totalLen = 0;
		$maxSchemaChars = 4000;

		foreach ( $index as $label => $typeLabel ) {
			$line = '- ' . $label . ' (' . $typeLabel . ')';
			$lineLen = strlen( $line );
			if ( $totalLen + $lineLen > $maxSchemaChars ) {
				$remaining = count( $index ) - count( $lines );
				if ( $remaining > 0 ) {
					$lines[] = '[... ' . $remaining . ' more properties omitted]';
				}
				break;
			}
			$lines[] = $line;
			$totalLen += $lineLen + 1;
		}

		return "PROPERTIES (use as [[Property::value]] filters and ?Property printouts):\n"
			. implode( "\n", $lines );
	}

	/**
	 * Discover user-defined properties and their datatypes from the SMW store.
	 *
	 * @return array Map of property label => human-readable type label
	 */
	private function getPropertyIndex(): array {
		if ( $this->propertyIndex !== null ) {
			return $this->propertyIndex;
		}

		$this->propertyIndex = [];

		try {
			$store = \SMW\StoreFactory::getStore();

			$requestOptions = new \SMW\RequestOptions();
			$requestOptions->limit = 500;
			$requestOptions->sort = true;

			$lookup = $store->getPropertiesSpecial( $requestOptions );
			// Modern SMW returns a ListLookup; older variants return a plain array.
			$list = is_object( $lookup ) && method_exists( $lookup, 'fetchList' )
				? $lookup->fetchList()
				: $lookup;

			if ( !is_array( $list ) ) {
				return $this->propertyIndex;
			}

			$excluded = array_flip( $this->excludedProperties );

			foreach ( $list as $entry ) {
				// Each entry is [ DIProperty $property, int $usageCount ].
				$property = is_array( $entry ) ? ( $entry[0] ?? null ) : $entry;
				if ( !( $property instanceof \SMW\DIProperty ) ) {
					continue;
				}
				// Only surface user-defined properties; built-ins are noise for Q&A.
				if ( method_exists( $property, 'isUserDefined' ) && !$property->isUserDefined() ) {
					continue;
				}

				$label = $property->getLabel();
				if ( $label === '' || isset( $excluded[$label] ) ) {
					continue;
				}

				$this->propertyIndex[$label] = $this->typeLabelFor( $property );
			}
		} catch ( \Throwable $e ) {
			wfDebugLog( 'Wanda', 'Failed to discover SMW properties: ' . $e->getMessage() );
			return [];
		}

		return $this->propertyIndex;
	}

	/**
	 * Resolve a human-readable datatype label for an SMW property.
	 *
	 * @param \SMW\DIProperty $property
	 * @return string
	 */
	private function typeLabelFor( $property ): string {
		try {
			$typeId = $property->findPropertyTypeID();
		} catch ( \Throwable $e ) {
			return 'Page';
		}

		if ( isset( self::$typeLabels[$typeId] ) ) {
			return self::$typeLabels[$typeId];
		}

		// Fall back to SMW's own type registry for less common datatypes.
		try {
			$registry = \SMW\DataTypeRegistry::getInstance();
			$label = $registry->findTypeLabel( $typeId );
			if ( is_string( $label ) && $label !== '' ) {
				return $label;
			}
		} catch ( \Throwable $e ) {
			// ignore — fall through to the raw id
		}

		return $typeId;
	}

	/**
	 * Use the LLM to generate an SMW query from the user question and schema.
	 *
	 * @param string $userQuery
	 * @param string $schemaDescription
	 * @return array|null {status, params, reasoning} or null if NO_QUERY
	 */
	private function generateSMWQuery( string $userQuery, string $schemaDescription ): ?array {
		$prompt = PromptTemplate::render( 'smw-query', [
			'schema' => $schemaDescription,
			'question' => $userQuery,
		] );

		return $this->callAndParseSMWLLM( $prompt );
	}

	/**
	 * Generate the next SMW query in a multi-step sequence.
	 *
	 * @param string $userQuery
	 * @param string $schemaDescription
	 * @param string $previousResults
	 * @param int $stepNumber
	 * @return array|null
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

		$prompt = PromptTemplate::render( 'smw-followup', [
			'step' => $stepNumber,
			'question' => $userQuery,
			'schema' => $schemaDescription,
			'previous_results' => $previousResults,
		] );

		return $this->callAndParseSMWLLM( $prompt );
	}

	/**
	 * Call the LLM with an SMW prompt and parse the structured response.
	 *
	 * @param string $prompt
	 * @return array|null {status, params, reasoning} or null
	 */
	private function callAndParseSMWLLM( string $prompt ): ?array {
		$response = $this->callLLM( $prompt );
		if ( $response === null ) {
			wfDebugLog( 'Wanda', 'SMW query generation LLM call failed' );
			return null;
		}

		$response = trim( $response );
		wfDebugLog( 'Wanda', 'SMW query LLM response: ' . substr( $response, 0, 500 ) );

		if ( stripos( $response, 'NO_QUERY' ) !== false ) {
			return null;
		}

		// Strip optional markdown code fences
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```\s*$/', '', $response );

		$parsed = json_decode( $response, true );
		if ( $parsed === null || !is_array( $parsed ) ) {
			// Try to extract a JSON object from surrounding text
			if ( preg_match( '/\{.+\}/s', $response, $matches ) ) {
				$parsed = json_decode( $matches[0], true );
			}
		}

		if ( $parsed === null || !is_array( $parsed ) ) {
			wfDebugLog( 'Wanda', 'Failed to parse SMW query from LLM response' );
			return null;
		}

		$status = $parsed['status'] ?? 'FINAL_ANSWER';
		if ( $status !== 'NEEDS_MORE' ) {
			$status = 'FINAL_ANSWER';
		}

		$reasoning = $parsed['reasoning'] ?? '';
		if ( $reasoning !== '' ) {
			wfDebugLog( 'Wanda', 'SMW multi-step reasoning: ' . $reasoning );
		}

		unset( $parsed['status'], $parsed['reasoning'] );

		return [
			'status' => $status,
			'params' => $parsed,
			'reasoning' => $reasoning,
		];
	}

	/**
	 * Validate and sanitize LLM-generated SMW query parameters.
	 *
	 * @param array $params
	 * @return array|null Sanitized params or null if invalid
	 */
	public function validateAndSanitize( array $params ): ?array {
		$conditions = trim( (string)( $params['conditions'] ?? '' ) );

		// The condition string is the heart of an ask query. It must contain at least
		// one [[...]] descriptor; without it SMW would attempt to match every page.
		if ( $conditions === '' || strpos( $conditions, '[[' ) === false
			|| strpos( $conditions, ']]' ) === false ) {
			wfDebugLog( 'Wanda', 'SMW validation: missing or malformed conditions' );
			return null;
		}

		// Reject characters that could break out of the ask query into other parser
		// constructs. Ask queries are read-only by nature, so this is belt-and-braces.
		// The "||" OR operator is legitimate SMW syntax inside a descriptor
		// (e.g. [[Located in::Germany||France]]), so allow paired pipes but reject
		// braces and any lone "|", which would open a new parser-function parameter.
		if ( preg_match( '/[{}]/', $conditions )
			|| strpos( str_replace( '||', '', $conditions ), '|' ) !== false
		) {
			wfDebugLog( 'Wanda', 'SMW validation: forbidden character in conditions' );
			return null;
		}

		// Printouts: normalise to a clean string list, stripping any leading "?".
		$printouts = [];
		$rawPrintouts = $params['printouts'] ?? [];
		if ( is_string( $rawPrintouts ) ) {
			$rawPrintouts = array_map( 'trim', explode( ',', $rawPrintouts ) );
		}
		if ( is_array( $rawPrintouts ) ) {
			foreach ( $rawPrintouts as $p ) {
				$p = ltrim( trim( (string)$p ), '?' );
				$p = trim( $p );
				if ( $p === '' || preg_match( '/[{}|\[\]]/', $p ) ) {
					continue;
				}
				$printouts[] = $p;
			}
		}

		$sort = trim( (string)( $params['sort'] ?? '' ) );
		if ( preg_match( '/[{}|\[\]]/', $sort ) ) {
			$sort = '';
		}

		$order = strtolower( trim( (string)( $params['order'] ?? '' ) ) );
		if ( !in_array( $order, [ 'asc', 'desc', 'rand', 'random' ], true ) ) {
			$order = '';
		}

		$format = strtolower( trim( (string)( $params['format'] ?? 'list' ) ) );
		if ( !in_array( $format, [ 'list', 'count' ], true ) ) {
			$format = 'list';
		}

		$maxLimit = $format === 'count' ? 500 : $this->maxResults;
		$limit = isset( $params['limit'] ) ? intval( $params['limit'] ) : 10;
		if ( $limit < 1 || $limit > $maxLimit ) {
			$limit = $format === 'count' ? 500 : min( 10, $this->maxResults );
		}

		return [
			'conditions' => $conditions,
			'printouts' => $printouts,
			'format' => $format,
			'sort' => $sort,
			'order' => $order,
			'limit' => $limit,
		];
	}

	/**
	 * Reconstruct a human-readable {{#ask:}} string for display in the thinking panel.
	 *
	 * @param array $params Sanitized params
	 * @return string
	 */
	private function buildAskString( array $params ): string {
		$parts = [ $params['conditions'] ];
		if ( ( $params['format'] ?? 'list' ) === 'count' ) {
			$parts[] = 'format=count';
		}
		foreach ( $params['printouts'] as $p ) {
			$parts[] = '?' . $p;
		}
		$parts[] = 'limit=' . $params['limit'];
		if ( $params['sort'] !== '' ) {
			$parts[] = 'sort=' . $params['sort'];
		}
		if ( $params['order'] !== '' ) {
			$parts[] = 'order=' . $params['order'];
		}
		return '{{#ask: ' . implode( ' |', $parts ) . ' }}';
	}

	/**
	 * Execute a validated SMW ask query and return flattened result rows.
	 *
	 * @param array $params Sanitized params
	 * @param string|null &$error Populated with the error message on failure
	 * @return array|null Array of associative rows, or null on failure
	 */
	private function executeQuery( array $params, ?string &$error = null ): ?array {
		// Build the parameter list exactly as the {{#ask:}} parser function receives it:
		// the first element is the condition string, then "?Printout" and "param=value".
		$isCount = ( $params['format'] ?? 'list' ) === 'count';
		$queryParams = [ $params['conditions'] ];
		if ( $isCount ) {
			$queryParams[] = 'format=count';
		}
		foreach ( $params['printouts'] as $p ) {
			$queryParams[] = '?' . $p;
		}
		$queryParams[] = 'limit=' . $params['limit'];
		if ( $params['sort'] !== '' ) {
			$queryParams[] = 'sort=' . $params['sort'];
		}
		if ( $params['order'] !== '' ) {
			$queryParams[] = 'order=' . $params['order'];
		}

		try {
			$processor = \SMWQueryProcessor::class;

			[ $queryString, $processedParams, $printRequests ] =
				$processor::getComponentsFromFunctionParams( $queryParams, false );

			// Add the implicit mainlabel (the subject page) print request, exactly as the
			// {{#ask:}} parser function does via QueryProcessor::getResultFromFunctionParams().
			// getComponentsFromFunctionParams() only returns the *explicit* "?Printout"
			// columns; without addThisPrintout() a query with no printouts (which the prompt
			// actively encourages for page-list questions) has zero print requests, so
			// getNext() yields empty rows and the query returns nothing — no content and no
			// source citations. addThisPrintout() is a no-op when a THIS request is already
			// present, so it is safe to call unconditionally. We still read the subject via
			// getResultSubject() when flattening and skip the now-present empty-label column.
			if ( method_exists( $processor, 'addThisPrintout' ) ) {
				$processor::addThisPrintout( $printRequests, $processedParams );
			}

			$processedParams = $processor::getProcessedParams( $processedParams, $printRequests );

			$query = $processor::createQuery(
				$queryString,
				$processedParams,
				$processor::SPECIAL_PAGE,
				'',
				$printRequests
			);

			$store = \SMW\StoreFactory::getStore();
			$queryResult = $store->getQueryResult( $query );

			if ( !is_object( $queryResult ) || !method_exists( $queryResult, 'getNext' ) ) {
				$error = 'Unexpected SMW query result object';
				return null;
			}

			if ( $isCount ) {
				if ( method_exists( $queryResult, 'getCountValue' ) ) {
					$count = (int)$queryResult->getCountValue();
				} else {
					$count = 0;
					while ( $queryResult->getNext() !== false ) {
						$count++;
					}
				}
				return [ [ '_count' => $count ] ];
			}

			return $this->flattenQueryResult( $queryResult );
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
			wfDebugLog( 'Wanda', 'SMW query execution failed: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Flatten an SMWQueryResult into an array of associative rows.
	 * Each row carries a "_page" key (the result subject) plus one key per printout.
	 *
	 * @param \SMWQueryResult $queryResult An SMWQueryResult
	 * @return array
	 */
	private function flattenQueryResult( $queryResult ): array {
		$rows = [];

		// getNext() returns an array of SMWResultArray columns, or false when exhausted.
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $resultRow = $queryResult->getNext() ) !== false ) {
			if ( !is_array( $resultRow ) ) {
				continue;
			}

			$row = [];
			$pageTitle = '';

			// All columns in a row share the same result subject (the matched page).
			if ( isset( $resultRow[0] ) && method_exists( $resultRow[0], 'getResultSubject' ) ) {
				try {
					$subject = $resultRow[0]->getResultSubject();
					$title = $subject->getTitle();
					if ( $title instanceof Title ) {
						$pageTitle = $title->getPrefixedText();
					}
				} catch ( \Throwable $e ) {
					// leave $pageTitle empty
				}
			}
			if ( $pageTitle !== '' ) {
				$row['_page'] = $pageTitle;
			}

			foreach ( $resultRow as $field ) {
				try {
					$printRequest = $field->getPrintRequest();
					$label = $printRequest ? $printRequest->getLabel() : '';
				} catch ( \Throwable $e ) {
					$label = '';
				}
				// The mainlabel column (empty label) is the subject, already captured.
				if ( $label === '' ) {
					continue;
				}

				$values = [];
				try {
					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
					while ( ( $dataValue = $field->getNextDataValue() ) !== false ) {
						$text = $dataValue->getShortWikiText();
						if ( $text !== '' ) {
							$values[] = $text;
						}
					}
				} catch ( \Throwable $e ) {
					// skip this column's values on error
				}

				$row[$label] = implode( ', ', $values );
			}

			if ( !empty( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Format SMW query results as context text for the LLM.
	 *
	 * @param array $rows
	 * @return string
	 */
	public function formatResultsAsContext( array $rows, string $conditions = '' ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		if ( count( $rows ) === 1 && array_key_exists( '_count', $rows[0] ) && count( $rows[0] ) === 1 ) {
			$count = (int)$rows[0]['_count'];
			$condNote = $conditions !== '' ? ' (conditions: ' . $conditions . ')' : '';
			return '--- Semantic MediaWiki count result ---' . "\n"
				. 'Total matching pages' . $condNote . ': ' . $count . "\n";
		}

		$maxContextChars = intdiv( APIChat::$maxContextChars, 4 );

		// Union the keys across rows so sparse printouts still get a column.
		$headers = [];
		foreach ( $rows as $row ) {
			foreach ( array_keys( $row ) as $h ) {
				$headers[$h] = true;
			}
		}
		$headers = array_keys( $headers );

		$output = '--- Semantic MediaWiki results (' . count( $rows ) . ' rows) ---' . "\n";
		if ( $conditions !== '' ) {
			$output .= 'Matched by conditions: ' . $conditions . "\n";
		}
		$output .= '| ' . implode( ' | ', $headers ) . " |\n";
		$output .= '| ' . implode( ' | ', array_fill( 0, count( $headers ), '---' ) ) . " |\n";

		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $headers as $h ) {
				$values[] = str_replace( '|', '\\|', $row[$h] ?? '' );
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
	 * Build source citation objects from the result rows' subject pages.
	 *
	 * @param array $rows
	 * @return array
	 */
	public function buildSources( array $rows ): array {
		$sources = [];
		$seenPages = [];

		foreach ( $rows as $row ) {
			$pageName = $row['_page'] ?? '';
			if ( $pageName === '' || isset( $seenPages[$pageName] ) ) {
				continue;
			}
			$seenPages[$pageName] = true;

			$title = Title::newFromText( $pageName );
			if ( $title ) {
				// Prefer SMW's Special:Browse view for the page's semantic data; fall back
				// to the normal page URL when SMW (which registers Special:Browse) is not
				// installed. Resolving an unregistered special page would otherwise emit a
				// warning, so the availability check guards the lookup as well as the URL.
				if ( self::isSMWAvailable() ) {
					$href = SpecialPage::getTitleFor( 'Browse', $pageName )->getLocalURL();
				} else {
					$href = $title->getLocalURL();
				}
				$sources[] = [
					'title' => $pageName,
					'href' => $href,
					'type' => 'smw',
				];
			}
		}

		return $sources;
	}

	/**
	 * Simplified LLM call for generating SMW queries.
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
				wfDebugLog( 'Wanda', 'SMWQueryHandler: unknown LLM provider: ' . $this->llmProvider );
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
			return $this->curlPost(
				'https://api.openai.com/v1/chat/completions',
				json_encode( $payload ),
				$headers
			);
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
					wfDebugLog( 'Wanda', 'SMWQueryHandler: retrying OpenAI with ' . $retryKey );
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
			wfDebugLog( 'Wanda', 'SMWQueryHandler cURL error: ' . $curlError );
			return null;
		}

		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', 'SMWQueryHandler HTTP ' . $httpCode . ': ' .
				substr( (string)$response, 0, 500 ) );
			return null;
		}

		return $response;
	}
}
