<?php

namespace MediaWiki\Extension\Wanda;

use RuntimeException;

/**
 * Turns a natural-language batch-edit instruction into a bounded discovery
 * scope plus an optional research request, without ever inventing page
 * titles itself (candidate titles always come from {@see WandaPageDiscovery}
 * or, for single-page edits, from the user-supplied title).
 *
 * Deliberately rule-based rather than another LLM round trip: the source
 * list a caller wants ("wiki", "cargo", "smw", ...) is the same explicit,
 * user-controlled checkbox input already used by the chat retrieval flow
 * (see {@see APIChat}), so it is a reliable, low-risk signal for whether
 * research-grounded editing was actually requested — no free-text
 * classification is needed to decide that.
 */
class WandaEditPlanner {

	/** @var WandaEditHandler Reserved for future LLM-assisted scope refinement; unused today. */
	private $handler;

	public function __construct( WandaEditHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * @param string $instruction Natural-language editing instruction
	 * @param string[] $sources Sources explicitly selected by the caller (e.g. ['wiki'])
	 * @param bool $singlePage Whether the caller already supplied a specific target title
	 * @return array{scope:array{type:string,query:string},researchQuery:string,sources:string[]}
	 */
	public function plan( string $instruction, array $sources, bool $singlePage ): array {
		$instruction = trim( $instruction );
		if ( $instruction === '' ) {
			throw new RuntimeException( 'wanda-plan-empty' );
		}

		// The page's own current wikitext is already supplied directly to the
		// editor; only sources beyond "wiki" require a separate research fetch.
		$research = array_values( array_filter( array_unique( $sources ),
			static function ( $source ) {
				return is_string( $source ) && $source !== '' && $source !== 'wiki';
			}
		) );

		return [
			// Overridden by the caller for single-page requests; only the batch
			// (blank-title) path relies on the inferred scope below.
			'scope' => $singlePage ? [ 'type' => 'single', 'query' => '' ] : $this->inferScope( $instruction ),
			'researchQuery' => $research ? $instruction : '',
			'sources' => $research,
		];
	}

	private function inferScope( string $instruction ): array {
		// Explicit category scoping (excluding wikitext [[Category:...]] additions) wins over inferred terms.
		$category = self::extractCategoryScope( $instruction );
		if ( $category !== '' ) {
			return [ 'type' => 'category', 'query' => $category ];
		}
		// A find-and-replace source term makes discovery precise: search for the text that
		// must already exist on candidate pages rather than the whole natural-language
		// sentence, whose non-content words (e.g. "replace", "every") match nothing and,
		// under the default database search's all-terms-required semantics, return zero pages.
		$term = self::findReplaceSource( $instruction );
		if ( $term !== '' ) {
			return [ 'type' => 'search', 'query' => $term ];
		}
		// Explicit site-wide phrasing with no searchable term enumerates candidate pages
		// directly (bounded by the batch cap in WandaPageDiscovery), so requests like
		// "add a notice to every page" are not silently reduced to a hopeless text search.
		if ( self::isSiteWide( $instruction ) ) {
			return [ 'type' => 'allpages', 'query' => '' ];
		}
		// A free-form instruction with no exact term, category or explicit site-wide phrasing
		// is treated as a conceptual request: rank candidates by embedding similarity to the
		// extracted topic or instruction (WandaPageDiscovery falls back to keyword search when
		// no vector index is available), so "tidy the pages about migratory birds" finds
		// topically related pages instead of a whole-sentence keyword search that matches nothing.
		return [ 'type' => 'semantic', 'query' => self::extractTopicQuery( $instruction ) ];
	}

	/**
	 * Extract a source category scope from the instruction while ignoring category links
	 * that are the edit payload (e.g. "add [[Category:Reviewed]]").
	 *
	 * @param string $instruction
	 * @return string
	 */
	private static function extractCategoryScope( string $instruction ): string {
		if ( preg_match(
			'/\b(?:in|from|under|within)\s+\[\[\s*category\s*:\s*([^\]\n|]+)/i',
			$instruction,
			$matches
		) ) {
			return trim( $matches[1] );
		}
		$editVerbs = 'add|append|insert|prepend|remove|delete|replace|change|substitute|swap|'
			. 'rename|convert|update|set|put|create|include|summarize|tidy|fix|rewrite|expand|tag';
		if ( preg_match(
			'/(?<!\[)(?<!\badd\s)(?<!\bappend\s)(?<!\binsert\s)(?<!\bprepend\s)(?<!\bremove\s)'
				. '(?<!\bdelete\s)(?<!\bput\s)(?<!\binclude\s)\bcategory\s*:\s*([^,.;:\n\[\]()]+?)'
				. '(?=\s+(?:and\s+|to\s+|then\s+|by\s+)?(?:' . $editVerbs . ')(?:ing)?\b|[,.;:\n\[\]()]|\s*$)/i',
			$instruction,
			$matches
		) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Extract the target topic when an instruction is phrased as "find/edit pages about <topic>
	 * and <edit action>", so the edit action itself (e.g. "add a See also section") does not
	 * pollute the retrieval query. Falls back to the full instruction when no topic clause is found.
	 *
	 * @param string $instruction
	 * @return string
	 */
	private static function extractTopicQuery( string $instruction ): string {
		$actionBoundary = '(?:\s+(?:and(?:\s+then)?|to|by)\s+(?:add(?:ing)?|append(?:ing)?|'
			. 'insert(?:ing)?|prepend(?:ing)?|remove|removing|delete|deleting|replace|replacing|'
			. 'change|changing|update|updating|set(?:ting)?|put(?:ting)?|create|creating|'
			. 'include|including|summarize|summarizing|rewrite|rewriting|tidy|tidying|'
			. 'fix(?:ing)?|expand(?:ing)?|tag(?:ging)?)\b'
			. '|\s+at\s+the\s+(?:bottom|top|end|beginning|start)\b|[,.;]|\s*$)';
		if ( preg_match(
			'/\b(?:pages?|articles?)\s+(?:about|on|regarding|concerning|related\s+to|relating\s+to|'
				. 'mentioning|containing|referencing|discussing|describing|for)\s+(.+?)'
				. $actionBoundary . '/iu',
			$instruction,
			$matches
		) ) {
			$topic = trim( $matches[1], " \t\n\r\0\x0B'\"." );
			if ( $topic !== '' ) {
				return $topic;
			}
		}
		return $instruction;
	}

	/**
	 * Extract the source term of a find-and-replace instruction, i.e. the existing text
	 * the user wants changed, so discovery can locate pages that actually contain it.
	 * Quoted terms are preferred; otherwise a leading "replace/change/... X with/to Y"
	 * pattern is used. Returns '' when the instruction is not a recognisable replacement.
	 *
	 * @param string $instruction
	 * @return string
	 */
	private static function findReplaceSource( string $instruction ): string {
		// Quoted pair: "... 'colour' with 'color' ...". The first quoted token is the source.
		if ( preg_match(
			'/[\'"\x{201c}\x{201d}\x{2018}\x{2019}]([^\'"\x{201c}\x{201d}\x{2018}\x{2019}]{1,100})'
				. '[\'"\x{201c}\x{201d}\x{2018}\x{2019}]\s*(?:with|to|into|by|→|->)\s*'
				. '[\'"\x{201c}\x{201d}\x{2018}\x{2019}]/u',
			$instruction, $matches
		) ) {
			return trim( $matches[1] );
		}
		// Unquoted: "replace <source> with/to <target>". Exclude general edit instructions like
		// "Update pages about X to include Y" or "Change articles on X by adding Y".
		$nonReplacementAction = 'add|append|insert|prepend|include|remove|delete|create|have|'
			. 'contain|show|list|summarize|mention|adding|appending|inserting|prepending|'
			. 'including|removing|deleting|creating|updating|changing|replacing|summarizing';
		if ( preg_match(
			'/\b(?:replace|change|substitute|swap|rename|convert|update)\b'
				. '\s+(.+?)\s+(?:(?:with|into|→|->)\s+\S|(?:to|by)\s+(?!'
				. $nonReplacementAction . '\b)\S)/iu',
			$instruction, $matches
		) ) {
			$stripped = self::stripLeadingFiller( $matches[1] );
			if ( !preg_match( '/\b(?:pages?|articles?|wiki)\b/i', $stripped ) ) {
				return $stripped;
			}
		}
		return '';
	}

	/**
	 * Remove leading determiners and find-and-replace boilerplate ("all", "the",
	 * "every occurrence of", "the word", ...) from a captured source phrase so what
	 * remains is the searchable term. Returns '' if nothing but filler was present.
	 *
	 * @param string $phrase
	 * @return string
	 */
	private static function stripLeadingFiller( string $phrase ): string {
		$phrase = trim( $phrase, " \t\n\r\0\x0B'\"" );
		$filler = '/^(?:all|each|every|any|both|the|a|an|of|word|words|name|names|term|terms|'
			. 'value|values|field|phrase|phrases|text|string|strings|occurrence|occurrences|'
			. 'occurence|occurences|instance|instances|reference|references|mention|mentions)\b'
			. '[\s:]*/iu';
		do {
			$phrase = preg_replace( $filler, '', $phrase, -1, $count );
			$phrase = ltrim( $phrase );
		} while ( $count > 0 && $phrase !== '' );
		return mb_substr( trim( $phrase ), 0, 100 );
	}

	/**
	 * Whether the instruction explicitly asks for a wiki-wide change with no term to
	 * search on (e.g. "on every page", "across the wiki", "site-wide").
	 *
	 * @param string $instruction
	 * @return bool
	 */
	private static function isSiteWide( string $instruction ): bool {
		$qualifier = 'about|on|regarding|concerning|related\s+to|relating\s+to|mentioning|'
			. 'containing|referencing|discussing|describing|with|having|that|which|where|'
			. 'whose|for|from|under|in\s+(?!the\s+(?:whole\s+|entire\s+)?wiki\b)';
		return (bool)preg_match(
			'/\b(?:every|all|each)(?:\s+of)?(?:\s+the)?\s+(?:pages?|articles?)\b(?!\s+(?:' . $qualifier . ')\b)'
				. '|\bacross\s+the\s+(?:whole\s+|entire\s+)?wiki\b'
				. '|\bsite-?wide\b|\b(?:whole|entire)\s+wiki\b|\bon\s+the\s+(?:whole\s+|entire\s+)?wiki\b/i',
			$instruction
		);
	}
}
