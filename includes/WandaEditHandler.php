<?php

namespace MediaWiki\Extension\Wanda;

use MediaWiki\Extension\Wanda\Prompts\PromptTemplate;

/**
 * Generates proposed wikitext edits with an LLM.
 *
 * This is the editing counterpart of {@see CargoQueryHandler} / the retrieval
 * handlers: it owns a self-contained set of provider calls (the codebase
 * intentionally keeps each handler's LLM plumbing local rather than sharing a
 * client) and turns a natural-language editing instruction plus the current
 * page wikitext into a proposed new revision.
 *
 * Edits are proposed via {@see proposeEdit}, which handles both free-text/prose
 * changes and infobox/template field changes on a single page.
 */
class WandaEditHandler {
	private const LLM_TEMPERATURE = 0.1;

	/** @var LLMClient */
	private $llm;
	/** @var int */
	private $maxTokens;

	/**
	 * @param LLMClient $llm
	 * @param int $maxTokens Upper bound on the response size; edits echo the whole
	 *   page back so this should be generous.
	 */
	public function __construct(
		LLMClient $llm,
		int $maxTokens = 4096
	) {
		$this->llm = $llm;
		$this->maxTokens = max( 256, $maxTokens );
	}

	/**
	 * Propose an edit for a page: prose/free-text changes as well as
	 * infobox/template field changes.
	 *
	 * @param string $instruction Natural-language editing instruction
	 * @param string $currentWikitext Full current wikitext of the target page,
	 *   or the empty string when the page is being created
	 * @param string $context Optional bounded, provenance-carrying research
	 *   context (e.g. retrieved wiki excerpts) the model may draw facts from
	 *   when fulfilling the instruction. Empty when no research was requested.
	 * @return array{changed:bool,newtext:string,explanation:string,failed?:bool}
	 *   `failed` is set when the LLM call failed or returned an unusable
	 *   response, as opposed to the model legitimately proposing no change.
	 */
	public function proposeEdit( string $instruction, string $currentWikitext, string $context = '' ): array {
		$prompt = PromptTemplate::render( 'general-edit', [
			'instruction' => $instruction,
			'wikitext' => $currentWikitext,
			'context' => trim( $context ),
		] );

		$response = $this->llm->complete( $prompt, $this->maxTokens, self::LLM_TEMPERATURE );
		if ( $response === null ) {
			return [ 'changed' => false, 'newtext' => '', 'explanation' => '', 'failed' => true ];
		}

		return $this->parseEditResponse( $response );
	}

	/**
	 * Parse the model's JSON edit response, tolerating code fences and surrounding prose.
	 *
	 * @param string $response Raw LLM output
	 * @return array{changed:bool,newtext:string,explanation:string,failed?:bool}
	 *   `failed` is set when the response could not be parsed at all.
	 */
	public function parseEditResponse( string $response ): array {
		$default = [ 'changed' => false, 'newtext' => '', 'explanation' => '', 'failed' => true ];

		$text = trim( $response );
		// Strip ``` / ```json fences if the model wrapped its answer.
		$text = preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $text );

		// Isolate the outermost JSON object if the model added stray prose.
		$start = strpos( $text, '{' );
		$end = strrpos( $text, '}' );
		if ( $start === false || $end === false || $end <= $start ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler: no JSON object in LLM response: ' .
				substr( $response, 0, 300 ) );
			return $default;
		}
		$json = substr( $text, $start, $end - $start + 1 );

		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$data = json_decode( self::escapeControlCharsInJsonStrings( $json ), true );
		}
		if ( !is_array( $data ) ) {
			wfDebugLog( 'Wanda', 'WandaEditHandler: unparseable JSON in LLM response: ' .
				substr( $response, 0, 300 ) );
			return $default;
		}

		$changed = !empty( $data['changed'] );
		$newtext = isset( $data['newtext'] ) ? (string)$data['newtext'] : '';
		$explanation = isset( $data['explanation'] ) ? (string)$data['explanation'] : '';

		// A "changed" answer with no text is meaningless; treat it as no change.
		if ( $changed && trim( $newtext ) === '' ) {
			return [ 'changed' => false, 'newtext' => '', 'explanation' => $explanation ];
		}

		return [
			'changed' => $changed,
			'newtext' => $newtext,
			'explanation' => $explanation,
		];
	}

	/**
	 * Escape raw control characters (newlines, tabs, etc.) that appear literally
	 * inside JSON string literals, leaving already-valid escape sequences and
	 * everything outside strings untouched.
	 *
	 * LLMs asked to return "wikitext inside a JSON string" reliably produce the
	 * wikitext's real line breaks instead of the \n escape the JSON spec requires,
	 * which makes json_decode() reject an otherwise well-formed response.
	 *
	 * @param string $json
	 * @return string
	 */
	private static function escapeControlCharsInJsonStrings( string $json ): string {
		$out = '';
		$inString = false;
		$escapedNext = false;
		$len = strlen( $json );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $json[$i];

			if ( !$inString ) {
				if ( $ch === '"' ) {
					$inString = true;
				}
				$out .= $ch;
				continue;
			}

			if ( $escapedNext ) {
				$out .= $ch;
				$escapedNext = false;
				continue;
			}

			if ( $ch === '\\' ) {
				$out .= $ch;
				$escapedNext = true;
				continue;
			}

			if ( $ch === '"' ) {
				$inString = false;
				$out .= $ch;
				continue;
			}

			switch ( $ch ) {
				case "\n":
					$out .= '\\n';
					break;
				case "\r":
					$out .= '\\r';
					break;
				case "\t":
					$out .= '\\t';
					break;
				default:
					$out .= ( ord( $ch ) < 0x20 ) ? sprintf( '\\u%04x', ord( $ch ) ) : $ch;
			}
		}

		return $out;
	}
}
