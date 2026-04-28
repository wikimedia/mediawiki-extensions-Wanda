<?php

namespace MediaWiki\Extension\Wanda;

use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;

class WikidataQueryHandler {
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
	/** @var string BCP-47 language code for Wikidata labels */
	private $lang;
	/** @var int Maximum number of sequential query steps */
	private $maxQuerySteps;
	/** @var string SPARQL endpoint URL */
	private $sparqlEndpoint;
	/** @var string Wikidata API endpoint URL */
	private $wikidataApiEndpoint;

	/**
	 * Common Wikidata SPARQL prefixes injected automatically into every query.
	 * @var array<string,string>
	 */
	private static $sparqlPrefixes = [
		'wd'        => 'http://www.wikidata.org/entity/',
		'wdt'       => 'http://www.wikidata.org/prop/direct/',
		'wikibase'  => 'http://wikiba.se/ontology#',
		'p'         => 'http://www.wikidata.org/prop/',
		'ps'        => 'http://www.wikidata.org/prop/statement/',
		'pq'        => 'http://www.wikidata.org/prop/qualifier/',
		'rdfs'      => 'http://www.w3.org/2000/01/rdf-schema#',
		'bd'        => 'http://www.bigdata.com/rdf#',
		'schema'    => 'http://schema.org/',
		'skos'      => 'http://www.w3.org/2004/02/skos/core#',
	];

	/**
	 * @param string $llmProvider
	 * @param string $llmModel
	 * @param string $llmApiKey
	 * @param string $llmApiEndpoint
	 * @param int $timeout
	 * @param string $lang
	 * @param int $maxQuerySteps
	 * @param string $sparqlEndpoint
	 * @param string $wikidataApiEndpoint
	 */
	public function __construct(
		string $llmProvider,
		string $llmModel,
		string $llmApiKey,
		string $llmApiEndpoint,
		int $timeout,
		string $lang = 'en',
		int $maxQuerySteps = 3,
		string $sparqlEndpoint = 'https://query.wikidata.org/sparql',
		string $wikidataApiEndpoint = 'https://www.wikidata.org/w/api.php'
	) {
		$this->llmProvider = $llmProvider;
		$this->llmModel = $llmModel;
		$this->llmApiKey = $llmApiKey;
		$this->llmApiEndpoint = $llmApiEndpoint;
		$this->timeout = $timeout;
		$this->lang = $lang ?: 'en';
		$this->maxQuerySteps = max( 1, min( $maxQuerySteps, 10 ) );
		$this->sparqlEndpoint = $sparqlEndpoint ?: 'https://query.wikidata.org/sparql';
		$this->wikidataApiEndpoint = $wikidataApiEndpoint ?: 'https://www.wikidata.org/w/api.php';
	}

	/**
	 * Main entry point. Query Wikidata for context relevant to the user question.
	 * Supports multi-step reasoning: the LLM can issue sequential queries where
	 * later queries depend on earlier results.
	 *
	 * @param string $userQuery
	 * @return array {content: string, sources: array, num_results: int, steps: array, entities: array}
	 */
	public function query( string $userQuery ): array {
		$steps = [];
		$empty = [
			'content' => '',
			'sources' => [],
			'num_results' => 0,
			'steps' => &$steps,
			'entities' => []
		];

		$allContent = '';
		$allSources = [];
		$allRowCount = 0;
		$allEntities = [];
		$previousResults = '';
		$seenEntityIds = [];
		$seenSourceKeys = [];

		for ( $step = 0; $step < $this->maxQuerySteps; $step++ ) {
			$stepNum = $step + 1;

			if ( $step === 0 ) {
				$llmResult = $this->generateWikidataQuery( $userQuery );
			} else {
				$llmResult = $this->generateFollowUpQuery(
					$userQuery, $allEntities, $previousResults, $stepNum
				);
			}

			if ( $llmResult === null ) {
				if ( $step === 0 ) {
					wfDebugLog( 'Wanda', 'WikidataQueryHandler: LLM determined no Wikidata query is relevant' );
					return $empty;
				}
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: multi-step — no further query at step ' . $stepNum );
				break;
			}

			// Resolve new entity mentions to real QIDs/PIDs via Wikidata search API
			$newEntityMentions = $llmResult['entities'] ?? [];
			$resolvedNew = $this->resolveEntities( $newEntityMentions );

			foreach ( $resolvedNew as $entity ) {
				$id = $entity['id'] ?? '';
				if ( $id !== '' && !isset( $seenEntityIds[$id] ) ) {
					$seenEntityIds[$id] = true;
					$allEntities[] = $entity;
				}
			}

			// Substitute resolved IDs into the LLM-generated SPARQL template
			$sparql = $this->substituteEntities( $llmResult['sparql'], $allEntities );
			$status = $llmResult['status'];
			$reasoning = $llmResult['reasoning'];

			// Safety validation
			if ( !$this->validateSparql( $sparql ) ) {
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: SPARQL failed safety validation at step ' . $stepNum );
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'message' => 'SPARQL query failed safety validation',
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			// Execute the SPARQL query
			$queryError = null;
			$rows = $this->executeSparqlQuery( $sparql, $queryError );

			if ( $rows === null || empty( $rows ) ) {
				$errMsg = $rows === null
					? 'SPARQL execution error' . ( $queryError !== null ? ': ' . $queryError : '' )
					: 'Query returned no results';
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: ' . $errMsg . ' at step ' . $stepNum );
				$steps[] = [
					'type' => 'error',
					'step' => $stepNum,
					'sparql' => $sparql,
					'message' => $errMsg,
				];
				if ( $step === 0 ) {
					return $empty;
				}
				break;
			}

			$stepContent = $this->formatResultsAsContext( $rows );
			$stepSources = $this->buildSources( $rows, $resolvedNew );

			$steps[] = [
				'type' => 'query',
				'step' => $stepNum,
				'sparql' => $sparql,
				'entities' => $resolvedNew,
				'rows' => count( $rows ),
				'status' => $status,
				'reasoning' => $reasoning,
			];

			$allContent .= ( $allContent !== '' ? "\n\n" : '' ) . $stepContent;
			$allRowCount += count( $rows );

			foreach ( $stepSources as $source ) {
				$key = $source['href'] ?? ( $source['title'] ?? '' );
				if ( $key !== '' && !isset( $seenSourceKeys[$key] ) ) {
					$seenSourceKeys[$key] = true;
					$allSources[] = $source;
				}
			}

			$previousResults .= ( $previousResults !== '' ? "\n\n" : '' ) . $stepContent;

			wfDebugLog(
				'Wanda',
				'WikidataQueryHandler: step ' . $stepNum . ' returned ' . count( $rows ) .
				' rows (status: ' . $status . ')'
			);

			if ( $status !== 'NEEDS_MORE' ) {
				break;
			}

			if ( $step === $this->maxQuerySteps - 1 ) {
				wfDebugLog(
					'Wanda',
					'WikidataQueryHandler: reached maximum query steps (' . $this->maxQuerySteps . ')'
				);
			}
		}

		if ( $allContent === '' ) {
			return $empty;
		}

		return [
			'content'     => $allContent,
			'sources'     => $allSources,
			'num_results' => $allRowCount,
			'steps'       => $steps,
			'entities'    => $allEntities,
		];
	}

	/**
	 * Resolve entity mentions (from LLM output) to real Wikidata QIDs/PIDs.
	 *
	 * @param array $entityMentions Array of {label, type, placeholder}
	 * @return array Resolved entities with 'id', 'label', 'description', 'type', 'placeholder'
	 */
	private function resolveEntities( array $entityMentions ): array {
		$resolved = [];
		foreach ( $entityMentions as $mention ) {
			$label = trim( $mention['label'] ?? '' );
			$type = ( $mention['type'] ?? 'item' ) === 'property' ? 'property' : 'item';
			$placeholder = trim( $mention['placeholder'] ?? '' );

			if ( $label === '' ) {
				continue;
			}

			$hit = $this->searchWikidata( $label, $type );
			if ( $hit === null ) {
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: could not resolve entity "' . $label . '"' );
				continue;
			}

			$resolved[] = [
				'label'       => $hit['label'],
				'id'          => $hit['id'],
				'description' => $hit['description'],
				'type'        => $type,
				'placeholder' => $placeholder,
			];
		}
		return $resolved;
	}

	/**
	 * Search Wikidata for one entity by label, returning the top hit.
	 *
	 * @param string $label
	 * @param string $type 'item' or 'property'
	 * @return array|null {id, label, description}
	 */
	private function searchWikidata( string $label, string $type ): ?array {
		$params = http_build_query( [
			'action'   => 'wbsearchentities',
			'search'   => $label,
			'language' => $this->lang,
			'format'   => 'json',
			'limit'    => 5,
			'type'     => $type,
		] );
		$url = rtrim( $this->wikidataApiEndpoint, '/' ) . '?' . $params;

		$response = $this->curlGet( $url, [
			'User-Agent: Wanda-MediaWiki-Extension/2.0 (https://www.mediawiki.org/wiki/Extension:Wanda)',
			'Accept: application/json',
		] );

		if ( $response === null ) {
			return null;
		}

		$data = json_decode( $response, true );
		$searchResults = $data['search'] ?? [];

		if ( empty( $searchResults ) ) {
			// Retry in English if the configured language returned nothing
			if ( $this->lang !== 'en' ) {
				$paramsEn = http_build_query( [
					'action'   => 'wbsearchentities',
					'search'   => $label,
					'language' => 'en',
					'format'   => 'json',
					'limit'    => 5,
					'type'     => $type,
				] );
				$responseEn = $this->curlGet(
					rtrim( $this->wikidataApiEndpoint, '/' ) . '?' . $paramsEn,
					[ 'User-Agent: Wanda-MediaWiki-Extension/2.0', 'Accept: application/json' ]
				);
				if ( $responseEn !== null ) {
					$dataEn = json_decode( $responseEn, true );
					$searchResults = $dataEn['search'] ?? [];
				}
			}
			if ( empty( $searchResults ) ) {
				return null;
			}
		}

		// Prefer an exact label match over alias/description matches.
		// e.g. searching "height" returns P2044 (alias match) before P2048 (label match).
		$hit = $searchResults[0];
		foreach ( $searchResults as $candidate ) {
			if ( ( $candidate['match']['type'] ?? '' ) === 'label' ) {
				$hit = $candidate;
				break;
			}
		}
		return [
			'id'          => $hit['id'],
			'label'       => $hit['label'] ?? $label,
			'description' => $hit['description'] ?? '',
		];
	}

	/**
	 * Replace entity placeholder tokens in a SPARQL template with real Wikidata IDs.
	 *
	 * @param string $sparql
	 * @param array $entities Resolved entities with 'placeholder' and 'id'
	 * @return string
	 */
	private function substituteEntities( string $sparql, array $entities ): string {
		foreach ( $entities as $entity ) {
			$placeholder = $entity['placeholder'] ?? '';
			$id = $entity['id'] ?? '';
			if ( $placeholder !== '' && $id !== '' ) {
				// Replace bare placeholder tokens (the handler writes wd:PLACEHOLDER / wdt:PLACEHOLDER)
				$sparql = str_replace( $placeholder, $id, $sparql );
			}
		}
		return $sparql;
	}

	/**
	 * Validate that the SPARQL query is safe (read-only, no mutation statements).
	 *
	 * @param string $sparql
	 * @return bool
	 */
	private function validateSparql( string $sparql ): bool {
		$upper = strtoupper( trim( $sparql ) );

		$forbidden = [ 'INSERT', 'DELETE', 'DROP', 'CLEAR', 'LOAD', 'CREATE', 'ADD', 'MOVE', 'COPY' ];
		foreach ( $forbidden as $keyword ) {
			// Match whole-word to avoid false positives inside IRIs or string literals
			if ( preg_match( '/\b' . $keyword . '\b/', $upper ) ) {
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: forbidden SPARQL keyword "' . $keyword . '"' );
				return false;
			}
		}

		// Must contain at least one read-form keyword
		if ( !preg_match( '/\b(SELECT|CONSTRUCT|ASK|DESCRIBE)\b/i', $sparql ) ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler: SPARQL lacks SELECT/CONSTRUCT/ASK/DESCRIBE' );
			return false;
		}

		return true;
	}

	/**
	 * Add standard Wikidata PREFIX declarations if not already present.
	 *
	 * @param string $sparql
	 * @return string
	 */
	private function ensurePrefixes( string $sparql ): string {
		$prefixBlock = '';
		foreach ( self::$sparqlPrefixes as $prefix => $uri ) {
			if ( !preg_match( '/\bPREFIX\s+' . preg_quote( $prefix, '/' ) . '\s*:/i', $sparql ) ) {
				$prefixBlock .= "PREFIX {$prefix}: <{$uri}>\n";
			}
		}
		return $prefixBlock . $sparql;
	}

	/**
	 * Execute a SPARQL query and return flattened result rows.
	 *
	 * @param string $sparql
	 * @param string|null &$error Populated with error message on failure
	 * @return array|null Array of associative rows, or null on failure
	 */
	private function executeSparqlQuery( string $sparql, ?string &$error = null ): ?array {
		$sparql = $this->ensurePrefixes( $sparql );

		$url = rtrim( $this->sparqlEndpoint, '/' ) . '?query=' . urlencode( $sparql ) . '&format=json';

		$response = $this->curlGet( $url, [
			'Accept: application/sparql-results+json',
			'User-Agent: Wanda-MediaWiki-Extension/2.0 (https://www.mediawiki.org/wiki/Extension:Wanda)',
		] );

		if ( $response === null ) {
			$error = 'Failed to reach SPARQL endpoint';
			return null;
		}

		$data = json_decode( $response, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$error = 'Invalid JSON from SPARQL endpoint: ' . json_last_error_msg();
			wfDebugLog( 'Wanda', 'WikidataQueryHandler: ' . $error );
			return null;
		}

		// Handle ASK query boolean result
		if ( isset( $data['boolean'] ) ) {
			return [ [ 'result' => $data['boolean'] ? 'true' : 'false' ] ];
		}

		$bindings = $data['results']['bindings'] ?? null;
		if ( $bindings === null ) {
			$error = 'Unexpected SPARQL response structure';
			return null;
		}

		if ( empty( $bindings ) ) {
			return [];
		}

		// Flatten each binding to a simple key => value array
		$rows = [];
		foreach ( $bindings as $binding ) {
			$row = [];
			foreach ( $binding as $var => $valueObj ) {
				$value = $valueObj['value'] ?? '';
				$type = $valueObj['type'] ?? '';

				if ( $type === 'uri' ) {
					// Shorten Wikidata entity URIs to just the Q/P identifier
					if ( preg_match( '/(Q\d+|P\d+)$/', $value, $m ) ) {
						$value = $m[1];
					}
				}
				$row[$var] = $value;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Generate initial Wikidata SPARQL query via LLM.
	 *
	 * @param string $userQuery
	 * @return array|null {sparql, status, reasoning, entities} or null if NO_QUERY
	 */
	private function generateWikidataQuery( string $userQuery ): ?array {
		$prompt = PromptTemplate::render( 'wikidata-query', [
			'question' => $userQuery,
			'lang'     => $this->lang,
		] );

		return $this->callAndParseWikidataLLM( $prompt );
	}

	/**
	 * Generate the next query in a multi-step sequence.
	 *
	 * @param string $userQuery Original user question
	 * @param array $resolvedEntities Entities resolved so far
	 * @param string $previousResults Formatted results from prior steps
	 * @param int $stepNumber Current step number (2-based)
	 * @return array|null
	 */
	private function generateFollowUpQuery(
		string $userQuery,
		array $resolvedEntities,
		string $previousResults,
		int $stepNumber
	): ?array {
		$maxPreviousChars = 4000;
		if ( strlen( $previousResults ) > $maxPreviousChars ) {
			$previousResults = substr( $previousResults, 0, $maxPreviousChars ) .
				"\n[... earlier results truncated ...]";
		}

		$prompt = PromptTemplate::render( 'wikidata-followup', [
			'step'             => $stepNumber,
			'question'         => $userQuery,
			'lang'             => $this->lang,
			'entities'         => $this->formatEntitiesForPrompt( $resolvedEntities ),
			'previous_results' => $previousResults,
		] );

		return $this->callAndParseWikidataLLM( $prompt );
	}

	/**
	 * Format resolved entities as a compact text block for prompt injection.
	 *
	 * @param array $entities
	 * @return string
	 */
	private function formatEntitiesForPrompt( array $entities ): string {
		if ( empty( $entities ) ) {
			return '(none resolved yet)';
		}
		$lines = [];
		foreach ( $entities as $entity ) {
			$line = $entity['id'] . ' = ' . $entity['label'];
			if ( !empty( $entity['description'] ) ) {
				$line .= ' (' . $entity['description'] . ')';
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Call the LLM and parse the structured JSON response for a Wikidata query.
	 *
	 * @param string $prompt
	 * @return array|null {sparql, status, reasoning, entities} or null
	 */
	private function callAndParseWikidataLLM( string $prompt ): ?array {
		$response = $this->callLLM( $prompt );
		if ( $response === null ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler: LLM call failed' );
			return null;
		}

		$response = trim( $response );
		wfDebugLog( 'Wanda', 'WikidataQueryHandler LLM response: ' . substr( $response, 0, 600 ) );

		if ( stripos( $response, 'NO_QUERY' ) !== false ) {
			return null;
		}

		// Strip optional markdown code fences
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response );
		$response = preg_replace( '/\s*```\s*$/', '', $response );

		$parsed = json_decode( $response, true );
		if ( $parsed === null || !is_array( $parsed ) ) {
			// Try to extract a JSON object from surrounding text
			if ( preg_match( '/(\{.+\})/s', $response, $m ) ) {
				$parsed = json_decode( $m[1], true );
			}
		}

		if ( $parsed === null || !is_array( $parsed ) ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler: failed to parse LLM response as JSON' );
			return null;
		}

		$sparql = trim( $parsed['sparql'] ?? '' );
		if ( $sparql === '' ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler: LLM response missing "sparql" field' );
			return null;
		}

		$status = $parsed['status'] ?? 'FINAL_ANSWER';
		if ( $status !== 'NEEDS_MORE' ) {
			$status = 'FINAL_ANSWER';
		}

		$reasoning = $parsed['reasoning'] ?? '';
		if ( $reasoning !== '' ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler reasoning: ' . $reasoning );
		}

		return [
			'sparql'    => $sparql,
			'status'    => $status,
			'reasoning' => $reasoning,
			'entities'  => $parsed['entities'] ?? [],
		];
	}

	/**
	 * Format SPARQL result rows as a Markdown table for LLM context.
	 *
	 * @param array $rows
	 * @return string
	 */
	public function formatResultsAsContext( array $rows ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$maxContextChars = 3000;
		$headers = array_keys( $rows[0] );

		$output = '--- Wikidata results (' . count( $rows ) . ' rows) ---' . "\n";
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
	 * Build source citation objects for Wikidata results.
	 *
	 * @param array $rows Result rows
	 * @param array $entities Resolved entities for this step
	 * @return array
	 */
	public function buildSources( array $rows, array $entities ): array {
		$sources = [];
		$seenIds = [];

		// Cite each resolved entity as a source
		foreach ( $entities as $entity ) {
			$id = $entity['id'] ?? '';
			if ( $id === '' || isset( $seenIds[$id] ) ) {
				continue;
			}
			$seenIds[$id] = true;
			$label = $entity['label'] ?? $id;
			$sources[] = [
				'title' => $label . ' (' . $id . ')',
				'href'  => 'https://www.wikidata.org/wiki/' . rawurlencode( $id ),
				'type'  => 'wikidata',
			];
		}

		// Also cite any Q-item IDs that appear in the result rows
		foreach ( $rows as $row ) {
			foreach ( $row as $value ) {
				if ( is_string( $value ) && preg_match( '/^Q\d+$/', $value ) && !isset( $seenIds[$value] ) ) {
					$seenIds[$value] = true;
					$sources[] = [
						'title' => $value,
						'href'  => 'https://www.wikidata.org/wiki/' . rawurlencode( $value ),
						'type'  => 'wikidata',
					];
				}
			}
		}

		return $sources;
	}

	/**
	 * Call the LLM at low temperature for deterministic structured output.
	 *
	 * @param string $prompt
	 * @return string|null
	 */
	private function callLLM( string $prompt ): ?string {
		$maxTokens = 1024;
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
				wfDebugLog( 'Wanda', 'WikidataQueryHandler: unknown LLM provider: ' . $this->llmProvider );
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
			'model'   => $this->llmModel,
			'prompt'  => $prompt,
			'stream'  => false,
			'options' => [
				'temperature' => $temperature,
				'num_predict' => $maxTokens,
			]
		];

		$response = $this->curlPost(
			rtrim( $this->llmApiEndpoint, '/' ) . '/generate',
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

		$model = trim( $this->llmModel ?: 'gpt-4-turbo' );
		$tokenKey = APIChat::getOpenAITokenKeyForModel( $model );
		$payload = [
			'model'      => $model,
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
			'temperature' => $temperature,
			$tokenKey    => $maxTokens,
		];

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->llmApiKey,
		];

		$response = $this->curlPost(
			'https://api.openai.com/v1/chat/completions',
			json_encode( $payload ),
			$headers
		);

		if ( $response === null ) {
			return null;
		}

		$json = json_decode( $response, true );

		// Retry once with the alternate token-count key if OpenAI rejects the first
		$apiMsg = $json['error']['message'] ?? '';
		if ( is_string( $apiMsg ) && $apiMsg !== '' ) {
			$retryKey = null;
			if ( $tokenKey === 'max_tokens' && stripos( $apiMsg, 'max_completion_tokens' ) !== false ) {
				$retryKey = 'max_completion_tokens';
			} elseif ( $tokenKey === 'max_completion_tokens' && stripos( $apiMsg, 'max_tokens' ) !== false ) {
				$retryKey = 'max_tokens';
			}
			if ( $retryKey !== null ) {
				$payload[$retryKey] = $maxTokens;
				unset( $payload[$tokenKey] );
				$response = $this->curlPost(
					'https://api.openai.com/v1/chat/completions',
					json_encode( $payload ),
					$headers
				);
				$json = $response !== null ? json_decode( $response, true ) : null;
			}
		}

		return $json['choices'][0]['message']['content'] ?? null;
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
			'model'       => $this->llmModel ?: 'claude-3-haiku-20240307',
			'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
			'max_tokens'  => $maxTokens,
			'temperature' => $temperature,
		];

		$response = $this->curlPost(
			'https://api.anthropic.com/v1/messages',
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'x-api-key: ' . $this->llmApiKey,
				'anthropic-version: 2023-06-01',
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

		$model = trim( $this->llmModel ?: '' );
		$tokenKey = ( $model !== '' &&
			( preg_match( '/(^|\\/)(o1|o3)/i', $model ) || stripos( $model, 'gpt-5' ) !== false ) )
			? 'max_completion_tokens'
			: 'max_tokens';

		$payload = [
			'messages'    => [ [ 'role' => 'user', 'content' => $prompt ] ],
			$tokenKey     => $maxTokens,
			'temperature' => $temperature,
		];

		$response = $this->curlPost(
			$this->llmApiEndpoint,
			json_encode( $payload ),
			[
				'Content-Type: application/json',
				'api-key: ' . $this->llmApiKey,
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
		} elseif ( strpos( $base, 'https://' ) !== 0 ) {
			$base = 'https://' . $base;
		}
		$url = $base . '/models/' . rawurlencode( $model ) .
			':generateContent?key=' . urlencode( $this->llmApiKey );

		$payload = [
			'contents'        => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ] ],
			'generationConfig' => [
				'temperature'    => $temperature,
				'maxOutputTokens' => $maxTokens,
			],
		];

		$response = $this->curlPost( $url, json_encode( $payload ), [ 'Content-Type: application/json' ] );
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
		unset( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler cURL POST error: ' . $curlError );
			return null;
		}
		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler POST HTTP ' . $httpCode . ': ' .
				substr( (string)$response, 0, 300 ) );
			return null;
		}
		return $response;
	}

	/**
	 * Generic cURL GET helper.
	 *
	 * @param string $url
	 * @param array $headers
	 * @return string|null Response body or null on failure
	 */
	private function curlGet( string $url, array $headers = [] ): ?string {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
		if ( !empty( $headers ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$response = curl_exec( $ch );
		$httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curlError = curl_error( $ch );
		unset( $ch );

		if ( $curlError ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler cURL GET error: ' . $curlError );
			return null;
		}
		if ( $httpCode !== 200 ) {
			wfDebugLog( 'Wanda', 'WikidataQueryHandler GET HTTP ' . $httpCode . ' for: ' . $url );
			return null;
		}
		return $response;
	}
}
