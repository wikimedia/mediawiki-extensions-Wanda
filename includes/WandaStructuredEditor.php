<?php

namespace MediaWiki\Extension\Wanda;

/**
 * Source-agnostic structured wikitext editor for MediaWiki templates, infoboxes,
 * wikitables, and <ref> footnote citations.
 *
 * Applies structured edit operations surgically to existing wikitext while
 * preserving untouched parameters, HTML comments, nested markup, and surrounding
 * prose byte-for-byte.
 */
class WandaStructuredEditor {

	/**
	 * Apply a sequence of structured operations to a wikitext document.
	 *
	 * Supported operation types:
	 * - set_template_fields: update or add named template/infobox parameters
	 * - remove_template_fields: remove named template/infobox parameters
	 * - upsert_table_rows (or append_table_rows): update or append wikitable rows
	 *
	 * @param string $wikitext
	 * @param array $operations
	 * @return string
	 */
	public static function apply( string $wikitext, array $operations ): string {
		$current = $wikitext;
		foreach ( $operations as $op ) {
			if ( !is_array( $op ) || empty( $op['type'] ) ) {
				continue;
			}
			switch ( $op['type'] ) {
				case 'set_template_fields':
					$current = self::setTemplateFields(
						$current,
						(string)( $op['template'] ?? '' ),
						(array)( $op['fields'] ?? [] ),
						(array)( $op['citations'] ?? [] )
					);
					break;
				case 'remove_template_fields':
					$current = self::removeTemplateFields(
						$current,
						(string)( $op['template'] ?? '' ),
						(array)( $op['fields'] ?? [] )
					);
					break;
				case 'upsert_table_rows':
				case 'append_table_rows':
					$current = self::upsertTableRows(
						$current,
						(array)( $op['rows'] ?? [] ),
						(array)( $op['headers'] ?? [] ),
						(string)( $op['section'] ?? '' ),
						(string)( $op['keyColumn'] ?? '' ),
						(int)( $op['tableIndex'] ?? 0 )
					);
					break;
			}
		}
		return $current;
	}

	/**
	 * Normalize a parameter or column name for case- and underscore-insensitive matching.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function normalizeKey( string $name ): string {
		$name = trim( $name );
		$name = str_replace( '_', ' ', $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		return mb_strtolower( $name, 'UTF-8' );
	}

	/**
	 * Normalize a template name, stripping any leading Template: namespace prefix.
	 *
	 * @param string $name
	 * @return string
	 */
	private static function normalizeTemplateName( string $name ): string {
		$clean = preg_replace( '/<!--.*?-->/s', '', $name );
		$norm = self::normalizeKey( $clean );
		if ( strpos( $norm, 'template:' ) === 0 ) {
			$norm = trim( substr( $norm, strlen( 'template:' ) ) );
		}
		return $norm;
	}

	/**
	 * Format citation metadata into a standard MediaWiki <ref> footnote tag.
	 *
	 * @param array|string $citation
	 * @return string
	 */
	public static function formatCitation( $citation ): string {
		if ( is_string( $citation ) ) {
			$trimmed = trim( $citation );
			if ( $trimmed === '' ) {
				return '';
			}
			if ( stripos( $trimmed, '<ref' ) === 0 ) {
				return $trimmed;
			}
			return "<ref>{$trimmed}</ref>";
		}
		if ( !is_array( $citation ) ) {
			return '';
		}
		$url = trim( (string)( $citation['url'] ?? $citation['href'] ?? '' ) );
		$title = trim( (string)( $citation['title'] ?? '' ) );
		if ( $url === '' && $title === '' ) {
			return '';
		}
		if ( $url !== '' && $title !== '' ) {
			return "<ref>[{$url} {$title}]</ref>";
		}
		if ( $url !== '' ) {
			return "<ref>{$url}</ref>";
		}
		return "<ref>{$title}</ref>";
	}

	/**
	 * Locate top-level templates in wikitext, ignoring comments, <nowiki>, and parser functions.
	 *
	 * @param string $wikitext
	 * @return array<int,array{name:string,start:int,end:int,raw:string}>
	 */
	public static function findTemplates( string $wikitext ): array {
		$templates = [];
		$len = strlen( $wikitext );
		$i = 0;

		while ( $i < $len ) {
			if ( substr( $wikitext, $i, 4 ) === '<!--' ) {
				$close = strpos( $wikitext, '-->', $i + 4 );
				$i = ( $close === false ) ? $len : $close + 3;
				continue;
			}
			if ( strncasecmp( substr( $wikitext, $i, 8 ), '<nowiki>', 8 ) === 0 ) {
				$close = stripos( $wikitext, '</nowiki>', $i + 8 );
				$i = ( $close === false ) ? $len : $close + 9;
				continue;
			}
			if ( $i + 1 < $len && $wikitext[$i] === '{' && $wikitext[$i + 1] === '{' ) {
				if ( $i + 2 < $len && $wikitext[$i + 2] === '{' ) {
					$i += 3;
					continue;
				}
				$end = self::findMatchingTemplateEnd( $wikitext, $i );
				if ( $end !== null ) {
					$raw = substr( $wikitext, $i, $end - $i );
					$inner = substr( $raw, 2, -2 );
					$parts = self::splitTopLevelPipes( $inner );
					$rawName = trim( preg_replace( '/<!--.*?-->/s', '', $parts[0]['text'] ?? '' ) );
					if ( $rawName !== '' && $rawName[0] !== '#' ) {
						$templates[] = [
							'name' => $rawName,
							'start' => $i,
							'end' => $end,
							'raw' => $raw,
						];
					}
					$i = $end;
					continue;
				}
			}
			$i++;
		}
		return $templates;
	}

	/**
	 * Find the byte offset immediately after the matching '}}' for a '{{' at $start.
	 *
	 * @param string $text
	 * @param int $start
	 * @return int|null
	 */
	private static function findMatchingTemplateEnd( string $text, int $start ): ?int {
		$len = strlen( $text );
		$depth = 0;
		$i = $start;

		while ( $i < $len ) {
			if ( substr( $text, $i, 4 ) === '<!--' ) {
				$close = strpos( $text, '-->', $i + 4 );
				$i = ( $close === false ) ? $len : $close + 3;
				continue;
			}
			if ( strncasecmp( substr( $text, $i, 8 ), '<nowiki>', 8 ) === 0 ) {
				$close = stripos( $text, '</nowiki>', $i + 8 );
				$i = ( $close === false ) ? $len : $close + 9;
				continue;
			}
			if ( $i + 2 < $len && substr( $text, $i, 3 ) === '{{{' ) {
				$close = strpos( $text, '}}}', $i + 3 );
				$i = ( $close === false ) ? $len : $close + 3;
				continue;
			}
			if ( $i + 1 < $len && $text[$i] === '{' && $text[$i + 1] === '{' ) {
				$depth++;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $text[$i] === '}' && $text[$i + 1] === '}' ) {
				$depth--;
				$i += 2;
				if ( $depth === 0 ) {
					return $i;
				}
				continue;
			}
			$i++;
		}
		return null;
	}

	/**
	 * Split a string on top-level '|' characters, ignoring pipes inside nested
	 * templates, links, wikitables, comments, and <nowiki>.
	 *
	 * @param string $inner
	 * @return array<int,array{text:string,offset:int}>
	 */
	public static function splitTopLevelPipes( string $inner ): array {
		$segments = [];
		$len = strlen( $inner );
		$tplDepth = 0;
		$linkDepth = 0;
		$tableDepth = 0;
		$segStart = 0;
		$i = 0;

		while ( $i < $len ) {
			if ( substr( $inner, $i, 4 ) === '<!--' ) {
				$close = strpos( $inner, '-->', $i + 4 );
				$i = ( $close === false ) ? $len : $close + 3;
				continue;
			}
			if ( strncasecmp( substr( $inner, $i, 8 ), '<nowiki>', 8 ) === 0 ) {
				$close = stripos( $inner, '</nowiki>', $i + 8 );
				$i = ( $close === false ) ? $len : $close + 9;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === '{' && $inner[$i + 1] === '{' ) {
				$tplDepth++;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === '}' && $inner[$i + 1] === '}' && $tplDepth > 0 ) {
				$tplDepth--;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === '[' && $inner[$i + 1] === '[' ) {
				$linkDepth++;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === ']' && $inner[$i + 1] === ']' && $linkDepth > 0 ) {
				$linkDepth--;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === '{' && $inner[$i + 1] === '|' ) {
				$tableDepth++;
				$i += 2;
				continue;
			}
			if ( $i + 1 < $len && $inner[$i] === '|' && $inner[$i + 1] === '}' && $tableDepth > 0 ) {
				$tableDepth--;
				$i += 2;
				continue;
			}
			if ( $inner[$i] === '|' && $tplDepth === 0 && $linkDepth === 0 && $tableDepth === 0 ) {
				$segments[] = [
					'text' => substr( $inner, $segStart, $i - $segStart ),
					'offset' => $segStart,
				];
				$segStart = $i + 1;
			}
			$i++;
		}
		$segments[] = [
			'text' => substr( $inner, $segStart ),
			'offset' => $segStart,
		];
		return $segments;
	}

	/**
	 * Update or add named parameters on a target template, or prepend a new template if absent.
	 *
	 * @param string $wikitext
	 * @param string $targetTemplate
	 * @param array $fields
	 * @param array $citations
	 * @return string
	 */
	public static function setTemplateFields(
		string $wikitext,
		string $targetTemplate,
		array $fields,
		array $citations = []
	): string {
		if ( !$fields ) {
			return $wikitext;
		}
		$templates = self::findTemplates( $wikitext );
		$matched = self::matchTemplate( $templates, $targetTemplate );

		if ( $matched === null ) {
			$tplTitle = $targetTemplate !== '' ? $targetTemplate : 'Infobox';
			$lines = [ '{{' . $tplTitle ];
			foreach ( $fields as $k => $v ) {
				$valStr = self::appendFieldCitation( (string)$v, $k, $citations );
				$lines[] = '| ' . $k . ' = ' . $valStr;
			}
			$lines[] = '}}';
			$newBlock = implode( "\n", $lines );
			return $wikitext === '' ? $newBlock : $newBlock . "\n\n" . ltrim( $wikitext, "\n" );
		}

		$updatedRaw = self::mutateTemplateRaw( $matched['raw'], $fields, $citations );
		return substr( $wikitext, 0, $matched['start'] )
			. $updatedRaw
			. substr( $wikitext, $matched['end'] );
	}

	/**
	 * Remove named parameters from a target template.
	 *
	 * @param string $wikitext
	 * @param string $targetTemplate
	 * @param array $fieldNames
	 * @return string
	 */
	public static function removeTemplateFields(
		string $wikitext,
		string $targetTemplate,
		array $fieldNames
	): string {
		if ( !$fieldNames ) {
			return $wikitext;
		}
		$templates = self::findTemplates( $wikitext );
		$matched = self::matchTemplate( $templates, $targetTemplate );
		if ( $matched === null ) {
			return $wikitext;
		}

		$removeSet = [];
		foreach ( $fieldNames as $fn ) {
			$removeSet[self::normalizeKey( (string)$fn )] = true;
		}

		$inner = substr( $matched['raw'], 2, -2 );
		$segments = self::splitTopLevelPipes( $inner );
		$kept = [ $segments[0]['text'] ];

		$segCount = count( $segments );
		for ( $idx = 1; $idx < $segCount; $idx++ ) {
			$seg = $segments[$idx]['text'];
			$eqPos = self::findTopLevelEquals( $seg );
			if ( $eqPos !== null ) {
				$key = self::normalizeKey( substr( $seg, 0, $eqPos ) );
				if ( isset( $removeSet[$key] ) ) {
					if ( substr( $seg, -1 ) === "\n" && isset( $segments[$idx + 1] ) ) {
						$lastKeptIdx = count( $kept ) - 1;
						$kept[$lastKeptIdx] = rtrim( $kept[$lastKeptIdx], " \t" );
						if ( substr( $kept[$lastKeptIdx], -1 ) !== "\n" ) {
							$kept[$lastKeptIdx] .= "\n";
						}
					}
					continue;
				}
			}
			$kept[] = $seg;
		}

		$updatedRaw = '{{' . implode( '|', $kept ) . '}}';
		return substr( $wikitext, 0, $matched['start'] )
			. $updatedRaw
			. substr( $wikitext, $matched['end'] );
	}

	/**
	 * Match a template by name, with fallback matching across Infobox templates.
	 *
	 * @param array $templates
	 * @param string $targetTemplate
	 * @return array|null
	 */
	private static function matchTemplate( array $templates, string $targetTemplate ): ?array {
		$normTarget = self::normalizeTemplateName( $targetTemplate );
		foreach ( $templates as $tpl ) {
			$normName = self::normalizeTemplateName( $tpl['name'] );
			if ( $normTarget === '' || $normName === $normTarget ) {
				return $tpl;
			}
		}
		if ( strpos( $normTarget, 'infobox' ) === 0 ) {
			foreach ( $templates as $tpl ) {
				$normName = self::normalizeTemplateName( $tpl['name'] );
				if ( strpos( $normName, 'infobox' ) === 0 ) {
					return $tpl;
				}
			}
		}
		return null;
	}

	/**
	 * Append a formatted <ref> citation to a field value if provided and not already present.
	 *
	 * @param string $value
	 * @param string|int $key
	 * @param array $citations
	 * @return string
	 */
	private static function appendFieldCitation( string $value, $key, array $citations ): string {
		if ( stripos( $value, '<ref' ) !== false ) {
			return $value;
		}
		$normKey = self::normalizeKey( (string)$key );
		foreach ( $citations as $ck => $cite ) {
			if ( (string)$ck === (string)$key || self::normalizeKey( (string)$ck ) === $normKey ) {
				return $value . self::formatCitation( $cite );
			}
		}
		return $value;
	}

	/**
	 * Mutate parameters inside a template raw block while preserving comments and alignment.
	 *
	 * @param string $raw
	 * @param array $fields
	 * @param array $citations
	 * @return string
	 */
	private static function mutateTemplateRaw( string $raw, array $fields, array $citations ): string {
		$inner = substr( $raw, 2, -2 );
		$segments = self::splitTopLevelPipes( $inner );

		$pending = [];
		foreach ( $fields as $k => $v ) {
			$valStr = self::appendFieldCitation( (string)$v, $k, $citations );
			$pending[self::normalizeKey( (string)$k )] = [
				'key' => (string)$k,
				'value' => $valStr,
				'applied' => false,
			];
		}

		$isMultiline = strpos( $inner, "\n" ) !== false;
		$eqColumnWidth = 0;
		$samplePrefix = $isMultiline ? ' ' : '';
		$sampleEqAround = $isMultiline ? ' = ' : '=';

		$segCount = count( $segments );
		for ( $idx = 1; $idx < $segCount; $idx++ ) {
			$seg = $segments[$idx]['text'];
			$eqPos = self::findTopLevelEquals( $seg );
			if ( $eqPos === null ) {
				continue;
			}
			$rawKeyPart = substr( $seg, 0, $eqPos );
			$rawValPart = substr( $seg, $eqPos + 1 );

			if ( preg_match( '/^([ \t]*)([^\s].*?)\s*$/s', $rawKeyPart, $m ) ) {
				$samplePrefix = $m[1];
				$totalBeforeEq = strlen( $rawKeyPart ) - strlen( $m[1] );
				if ( $totalBeforeEq > $eqColumnWidth ) {
					$eqColumnWidth = $totalBeforeEq;
				}
			}
			if ( preg_match( '/^(\s*)=\s*/', substr( $seg, $eqPos ) ) ) {
				$afterEqSpace = preg_match( '/^(\s+)/', $rawValPart, $vm ) ? $vm[1] : '';
				$sampleEqAround = '=' . ( $afterEqSpace !== '' ? ' ' : '' );
			}

			$normKey = self::normalizeKey( $rawKeyPart );
			if ( isset( $pending[$normKey] ) ) {
				$newVal = $pending[$normKey]['value'];
				$pending[$normKey]['applied'] = true;

				if ( preg_match( '/^([ \t]*)(.*?)((?:[ \t]*<!--.*?-->)*)(\s*)$/s', $rawValPart, $vm ) ) {
					$leadSpace = $vm[1] !== '' ? $vm[1] : ( $isMultiline ? ' ' : '' );
					$trailingComments = $vm[3];
					$trailSpace = $vm[4];
					$segments[$idx]['text'] = $rawKeyPart . '=' . $leadSpace
						. $newVal . $trailingComments . $trailSpace;
				} else {
					$segments[$idx]['text'] = $rawKeyPart . '= ' . $newVal . ( $isMultiline ? "\n" : '' );
				}
			}
		}

		$unapplied = array_filter( $pending, static function ( $item ) {
			return !$item['applied'];
		} );

		if ( $unapplied ) {
			$lastIdx = count( $segments ) - 1;
			if ( $isMultiline ) {
				$lastText = $segments[$lastIdx]['text'];
				$closingIndent = '';
				if ( preg_match( '/^(.*?\n)([ \t]*)$/s', $lastText, $lm ) ) {
					$segments[$lastIdx]['text'] = $lm[1];
					$closingIndent = $lm[2];
				} elseif ( substr( $lastText, -1 ) !== "\n" ) {
					$segments[$lastIdx]['text'] = $lastText . "\n";
				}
				foreach ( $unapplied as $item ) {
					$paddedKey = $eqColumnWidth > 0
						? str_pad( $item['key'], max( strlen( $item['key'] ) + 1, $eqColumnWidth ), ' ', STR_PAD_RIGHT )
						: $item['key'] . ' ';
					$segments[] = [
						'text' => $samplePrefix . $paddedKey . '= ' . $item['value'] . "\n",
						'offset' => 0,
					];
				}
				$segments[count( $segments ) - 1]['text'] .= $closingIndent;
			} else {
				foreach ( $unapplied as $item ) {
					$segments[] = [
						'text' => $samplePrefix . $item['key'] . $sampleEqAround . $item['value'],
						'offset' => 0,
					];
				}
			}
		}

		$rebuilt = implode( '|', array_column( $segments, 'text' ) );
		return '{{' . $rebuilt . '}}';
	}

	/**
	 * Find the first top-level '=' in a parameter segment.
	 *
	 * @param string $segment
	 * @return int|null
	 */
	private static function findTopLevelEquals( string $segment ): ?int {
		$len = strlen( $segment );
		$tplDepth = 0;
		$linkDepth = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $i + 1 < $len && $segment[$i] === '{' && $segment[$i + 1] === '{' ) {
				$tplDepth++;
				$i++;
				continue;
			}
			if ( $i + 1 < $len && $segment[$i] === '}' && $segment[$i + 1] === '}' && $tplDepth > 0 ) {
				$tplDepth--;
				$i++;
				continue;
			}
			if ( $i + 1 < $len && $segment[$i] === '[' && $segment[$i + 1] === '[' ) {
				$linkDepth++;
				$i++;
				continue;
			}
			if ( $i + 1 < $len && $segment[$i] === ']' && $segment[$i + 1] === ']' && $linkDepth > 0 ) {
				$linkDepth--;
				$i++;
				continue;
			}
			if ( $segment[$i] === '=' && $tplDepth === 0 && $linkDepth === 0 ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Update or append rows in a MediaWiki wikitable, or create a new table if none exists.
	 *
	 * @param string $wikitext
	 * @param array $rows
	 * @param array $headers
	 * @param string $section
	 * @param string $keyColumn
	 * @param int $tableIndex
	 * @return string
	 */
	public static function upsertTableRows(
		string $wikitext,
		array $rows,
		array $headers = [],
		string $section = '',
		string $keyColumn = '',
		int $tableIndex = 0
	): string {
		if ( !$rows ) {
			return $wikitext;
		}

		$tables = self::findTables( $wikitext );
		$targetTable = self::resolveTargetTable( $wikitext, $tables, $section, $tableIndex );

		if ( $targetTable === null ) {
			if ( !$headers ) {
				$firstRow = reset( $rows );
				if ( is_array( $firstRow ) && array_keys( $firstRow ) !== range( 0, count( $firstRow ) - 1 ) ) {
					$headers = array_keys( array_filter( $firstRow, static function ( $k ) {
						return $k !== '_citations';
					}, ARRAY_FILTER_USE_KEY ) );
				}
			}
			$tableLines = [ '{| class="wikitable"' ];
			if ( $headers ) {
				$tableLines[] = '! ' . implode( ' !! ', $headers );
			}
			foreach ( $rows as $row ) {
				$cells = self::mapRowToColumns( (array)$row, $headers );
				$tableLines[] = '|-' . "\n" . '| ' . implode( ' || ', $cells );
			}
			$tableLines[] = '|}';
			$newTable = implode( "\n", $tableLines );

			if ( $section !== '' && strpos( $wikitext, "== {$section} ==" ) === false ) {
				$newTable = "== {$section} ==\n" . $newTable;
			}
			return $wikitext === '' ? $newTable : rtrim( $wikitext ) . "\n\n" . $newTable . "\n";
		}

		$updatedTableRaw = self::mutateTableRaw( $targetTable['raw'], $rows, $headers, $keyColumn );
		return substr( $wikitext, 0, $targetTable['start'] )
			. $updatedTableRaw
			. substr( $wikitext, $targetTable['end'] );
	}

	/**
	 * Resolve which table to edit based on section heading or explicit index.
	 *
	 * @param string $wikitext
	 * @param array $tables
	 * @param string $section
	 * @param int $tableIndex
	 * @return array|null
	 */
	private static function resolveTargetTable(
		string $wikitext,
		array $tables,
		string $section,
		int $tableIndex
	): ?array {
		if ( !$tables ) {
			return null;
		}
		if ( $section !== '' ) {
			$pattern = '/^(={2,6})\s*' . preg_quote( trim( $section ), '/' ) . '\s*\1[ \t]*$/im';
			if ( preg_match( $pattern, $wikitext, $m, PREG_OFFSET_CAPTURE ) ) {
				$sectionStart = $m[0][1] + strlen( $m[0][0] );
				$nextSectionPos = strlen( $wikitext );
				$nextPattern = '/^={2,6}[^=].*?={2,6}[ \t]*$/m';
				if ( preg_match( $nextPattern, $wikitext, $nm, PREG_OFFSET_CAPTURE, $sectionStart ) ) {
					$nextSectionPos = $nm[0][1];
				}
				foreach ( $tables as $table ) {
					if ( $table['start'] >= $sectionStart && $table['start'] < $nextSectionPos ) {
						return $table;
					}
				}
			}
		}
		return $tables[$tableIndex] ?? null;
	}

	/**
	 * Locate top-level '{| ... |}' wikitables in wikitext.
	 *
	 * @param string $wikitext
	 * @return array<int,array{start:int,end:int,raw:string}>
	 */
	public static function findTables( string $wikitext ): array {
		$tables = [];
		$len = strlen( $wikitext );
		$i = 0;

		while ( $i < $len ) {
			if ( $i + 1 < $len && $wikitext[$i] === '{' && $wikitext[$i + 1] === '|' ) {
				$depth = 1;
				$j = $i + 2;
				while ( $j < $len && $depth > 0 ) {
					if ( $j + 1 < $len && $wikitext[$j] === '{' && $wikitext[$j + 1] === '|' ) {
						$depth++;
						$j += 2;
						continue;
					}
					if ( $j + 1 < $len && $wikitext[$j] === '|' && $wikitext[$j + 1] === '}' ) {
						$depth--;
						$j += 2;
						if ( $depth === 0 ) {
							$tables[] = [
								'start' => $i,
								'end' => $j,
								'raw' => substr( $wikitext, $i, $j - $i ),
							];
							$i = $j;
							continue 2;
						}
						continue;
					}
					$j++;
				}
			}
			$i++;
		}
		return $tables;
	}

	/**
	 * Mutate an existing wikitable's rows, updating matching rows in-place and appending new rows.
	 *
	 * @param string $tableRaw
	 * @param array $incomingRows
	 * @param array $explicitHeaders
	 * @param string $keyColumn
	 * @return string
	 */
	private static function mutateTableRaw(
		string $tableRaw,
		array $incomingRows,
		array $explicitHeaders,
		string $keyColumn
	): string {
		$lines = explode( "\n", $tableRaw );
		$detectedHeaders = [];
		$rowStarts = [];
		$lineCount = count( $lines );
		$closingLineIdx = $lineCount - 1;
		$seenDataCell = false;

		for ( $i = 0; $i < $lineCount; $i++ ) {
			$trimmed = ltrim( $lines[$i] );
			if ( strpos( $trimmed, '|}' ) === 0 ) {
				$closingLineIdx = $i;
				break;
			}
			if ( strpos( $trimmed, '|-' ) === 0 ) {
				$rowStarts[] = $i;
				continue;
			}
			if ( $trimmed !== '' && $trimmed[0] === '!' && !$seenDataCell ) {
				$headerLine = substr( $trimmed, 1 );
				foreach ( explode( '!!', $headerLine ) as $hCell ) {
					$parts = explode( '|', $hCell );
					$detectedHeaders[] = trim( end( $parts ) );
				}
				continue;
			}
			if ( strpos( $trimmed, '|' ) === 0 && strpos( $trimmed, '|+' ) !== 0 && strpos( $trimmed, '|}' ) !== 0 ) {
				$seenDataCell = true;
			}
		}

		$existingRows = [];
		$rowStartCount = count( $rowStarts );
		for ( $r = 0; $r < $rowStartCount; $r++ ) {
			$startLine = $rowStarts[$r];
			$endLine = ( $r + 1 < $rowStartCount ) ? $rowStarts[$r + 1] - 1 : $closingLineIdx - 1;
			$cells = [];
			$cellAttrs = [];
			$isInlineRow = false;
			for ( $l = $startLine + 1; $l <= $endLine; $l++ ) {
				$t = ltrim( $lines[$l] );
				if ( strpos( $t, '|' ) === 0 && strpos( $t, '|-' ) !== 0 && strpos( $t, '|}' ) !== 0 ) {
					$cellLine = substr( $t, 1 );
					if ( strpos( $cellLine, '||' ) !== false ) {
						$isInlineRow = true;
						foreach ( explode( '||', $cellLine ) as $c ) {
							[ $attr, $val ] = self::splitCellAttrAndValue( $c );
							$cellAttrs[] = $attr;
							$cells[] = $val;
						}
					} else {
						[ $attr, $val ] = self::splitCellAttrAndValue( $cellLine );
						$cellAttrs[] = $attr;
						$cells[] = $val;
					}
				}
			}
			if ( $cells ) {
				$existingRows[] = [
					'startLine' => $startLine,
					'endLine' => $endLine,
					'cells' => $cells,
					'attrs' => $cellAttrs,
					'isInline' => $isInlineRow,
				];
			}
		}

		$columns = $detectedHeaders ?: $explicitHeaders;
		$keyColIdx = 0;
		if ( $keyColumn !== '' && $columns ) {
			foreach ( $columns as $cIdx => $colName ) {
				if ( self::normalizeKey( $colName ) === self::normalizeKey( $keyColumn ) ) {
					$keyColIdx = $cIdx;
					break;
				}
			}
		}

		$preferInline = $existingRows ? $existingRows[0]['isInline'] : true;
		$rowsToAppend = [];

		foreach ( $incomingRows as $inRow ) {
			$mappedCells = self::mapRowToColumns( (array)$inRow, $columns );
			$rowKeyVal = isset( $mappedCells[$keyColIdx] )
				? self::stripRefsAndLinks( $mappedCells[$keyColIdx] )
				: '';
			$updatedExisting = false;

			if ( $rowKeyVal !== '' ) {
				foreach ( $existingRows as &$exRow ) {
					$exKeyVal = isset( $exRow['cells'][$keyColIdx] )
						? self::stripRefsAndLinks( $exRow['cells'][$keyColIdx] )
						: '';
					if ( $exKeyVal !== '' && self::normalizeKey( $exKeyVal ) === self::normalizeKey( $rowKeyVal ) ) {
						foreach ( $mappedCells as $cIdx => $val ) {
							if ( $val !== '' ) {
								$exRow['cells'][$cIdx] = $val;
							}
						}
						$exRow['modified'] = true;
						$updatedExisting = true;
						break;
					}
				}
				unset( $exRow );
			}

			if ( !$updatedExisting ) {
				$rowsToAppend[] = $mappedCells;
			}
		}

		for ( $r = count( $existingRows ) - 1; $r >= 0; $r-- ) {
			$exRow = $existingRows[$r];
			if ( empty( $exRow['modified'] ) ) {
				continue;
			}
			$formattedCells = [];
			foreach ( $exRow['cells'] as $cIdx => $cVal ) {
				$attr = $exRow['attrs'][$cIdx] ?? '';
				$formattedCells[] = $attr !== '' ? $attr . ' | ' . $cVal : $cVal;
			}
			$newLines = [ $lines[$exRow['startLine']] ];
			if ( $exRow['isInline'] ) {
				$newLines[] = '| ' . implode( ' || ', $formattedCells );
			} else {
				foreach ( $formattedCells as $fc ) {
					$newLines[] = '| ' . $fc;
				}
			}
			array_splice(
				$lines,
				$exRow['startLine'],
				$exRow['endLine'] - $exRow['startLine'] + 1,
				$newLines
			);
		}

		$closingLineIdx = count( $lines ) - 1;
		for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
			if ( strpos( ltrim( $lines[$i] ), '|}' ) === 0 ) {
				$closingLineIdx = $i;
				break;
			}
		}

		if ( $rowsToAppend ) {
			$appendLines = [];
			foreach ( $rowsToAppend as $cells ) {
				$appendLines[] = '|-';
				if ( $preferInline ) {
					$appendLines[] = '| ' . implode( ' || ', $cells );
				} else {
					foreach ( $cells as $c ) {
						$appendLines[] = '| ' . $c;
					}
				}
			}
			array_splice( $lines, $closingLineIdx, 0, $appendLines );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Map a positional or column-keyed row array to an ordered list of cell strings.
	 *
	 * @param array $row
	 * @param array $columns
	 * @return string[]
	 */
	private static function mapRowToColumns( array $row, array $columns ): array {
		$citations = isset( $row['_citations'] ) && is_array( $row['_citations'] ) ? $row['_citations'] : [];
		unset( $row['_citations'] );

		if ( array_keys( $row ) === range( 0, count( $row ) - 1 ) || !$columns ) {
			$cells = array_map( 'strval', array_values( $row ) );
			foreach ( $citations as $colIdx => $cite ) {
				if ( isset( $cells[$colIdx] ) && stripos( $cells[$colIdx], '<ref' ) === false ) {
					$cells[$colIdx] .= self::formatCitation( $cite );
				}
			}
			return $cells;
		}

		$normRow = [];
		foreach ( $row as $k => $v ) {
			$valStr = self::appendFieldCitation( (string)$v, $k, $citations );
			$normRow[self::normalizeKey( (string)$k )] = $valStr;
		}

		$cells = [];
		foreach ( $columns as $colName ) {
			$normCol = self::normalizeKey( $colName );
			$cells[] = $normRow[$normCol] ?? '';
		}
		return $cells;
	}

	/**
	 * Strip <ref> tags and unwrap [[Page|Label]] links for key-column comparison.
	 *
	 * @param string $cell
	 * @return string
	 */
	private static function stripRefsAndLinks( string $cell ): string {
		$clean = preg_replace( '~<ref\b[^>]*>.*?</ref>~is', '', $cell );
		$clean = preg_replace( '~\[\[(?:[^\]|]+\|)?([^\]]+)\]\]~', '$1', $clean );
		return trim( $clean );
	}

	/**
	 * Split a single table cell into [attributePrefix, cellValue], ignoring pipes inside links or templates.
	 *
	 * @param string $rawCell
	 * @return array{0:string,1:string}
	 */
	private static function splitCellAttrAndValue( string $rawCell ): array {
		$segments = self::splitTopLevelPipes( $rawCell );
		if ( count( $segments ) >= 2 ) {
			$attr = trim( $segments[0]['text'] );
			$val = trim( implode( '|', array_slice( array_column( $segments, 'text' ), 1 ) ) );
			return [ $attr, $val ];
		}
		return [ '', trim( $rawCell ) ];
	}
}
