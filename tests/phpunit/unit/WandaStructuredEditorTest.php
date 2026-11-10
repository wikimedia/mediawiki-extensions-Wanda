<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaStructuredEditor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\WandaStructuredEditor
 * @group Wanda
 */
class WandaStructuredEditorTest extends MediaWikiUnitTestCase {

	public function testUpdatesBlockInfoboxPreservingCommentsAndAlignment() {
		$page = <<<'WIKITEXT'
{{Short description|Capital city of Massachusetts}}
<!-- Top-level editorial note -->
{{Infobox settlement
| name             = Boston
| settlement_type  = [[City]]
| population_total = 675,647 <!-- 2020 census figure -->
| mayor            = [[Kim Janey]]
| coordinates      = {{coord|42.3601|-71.0589|region:US-MA|display=inline,title}}
}}

'''Boston''' is the capital of [[Massachusetts]].
WIKITEXT;

		$updated = WandaStructuredEditor::apply( $page, [
			[
				'type' => 'set_template_fields',
				'template' => 'Infobox settlement',
				'fields' => [
					'Mayor' => '[[Michelle Wu]]',
					'population total' => '689,326',
					'area_code' => '617, 857',
				],
				'citations' => [
					'population total' => [
						'url' => 'https://www.census.gov/quickfacts/boston',
						'title' => 'US Census Bureau',
					],
				],
			],
		] );

		$this->assertStringContainsString( '<!-- Top-level editorial note -->', $updated );
		$this->assertStringContainsString( '| mayor            = [[Michelle Wu]]', $updated );
		$this->assertStringContainsString(
			'| population_total = 689,326<ref>[https://www.census.gov/quickfacts/boston US Census Bureau]</ref>'
				. ' <!-- 2020 census figure -->',
			$updated
		);
		$this->assertStringContainsString( '| area_code        = 617, 857', $updated );
		$this->assertStringContainsString(
			'{{coord|42.3601|-71.0589|region:US-MA|display=inline,title}}',
			$updated
		);
		$this->assertStringContainsString( "'''Boston''' is the capital of [[Massachusetts]].", $updated );
	}

	public function testUpdatesInlineTemplateAndCreatesMissingTemplate() {
		$inline = "{{Restaurant|name=Giacomo's|phone=617-523-9026}}\nGreat pasta.";
		$updatedInline = WandaStructuredEditor::setTemplateFields(
			$inline,
			'Restaurant',
			[ 'phone' => '617-523-9026 (Cash only)', 'rating' => '4.7' ]
		);

		$this->assertSame(
			"{{Restaurant|name=Giacomo's|phone=617-523-9026 (Cash only)|rating=4.7}}\nGreat pasta.",
			$updatedInline
		);

		$noTemplate = "'''Acme Corp''' is a software company.";
		$withNewTemplate = WandaStructuredEditor::setTemplateFields(
			$noTemplate,
			'Infobox company',
			[ 'name' => 'Acme Corp', 'founded' => '2020' ]
		);

		$expected = "{{Infobox company\n| name = Acme Corp\n| founded = 2020\n}}\n\n"
			. "'''Acme Corp''' is a software company.";
		$this->assertSame( $expected, $withNewTemplate );
	}

	public function testRemovesTemplateFieldsCleanly() {
		$page = "{{Infobox person\n| name = Alice\n| fax = 555-0000\n| website = https://example.com\n}}";
		$updated = WandaStructuredEditor::removeTemplateFields(
			$page,
			'Infobox person',
			[ 'fax' ]
		);

		$this->assertSame(
			"{{Infobox person\n| name = Alice\n| website = https://example.com\n}}",
			$updated
		);
	}

	public function testUpsertsInlineWikitableRowsWithCitations() {
		$page = <<<'WIKITEXT'
== Notable Restaurants ==
{| class="wikitable"
! Restaurant !! Neighborhood !! Specialty
|-
| Union Oyster House || Downtown || Clam chowder
|-
| Regina Pizzeria || North End || Brick-oven pizza
|}
WIKITEXT;

		$updated = WandaStructuredEditor::upsertTableRows(
			$page,
			[
				[
					'Restaurant' => 'Union Oyster House',
					'Neighborhood' => 'Downtown Historic District',
					'Specialty' => 'Clam chowder & raw bar',
				],
				[
					'Restaurant' => 'Neptune Oyster',
					'Neighborhood' => 'North End',
					'Specialty' => 'Warm buttered lobster roll',
					'_citations' => [
						'Specialty' => [
							'url' => 'https://boston.eater.com/neptune-oyster',
							'title' => 'Eater Boston',
						],
					],
				],
			]
		);

		$this->assertStringContainsString(
			'| Union Oyster House || Downtown Historic District || Clam chowder & raw bar',
			$updated
		);
		$this->assertStringContainsString(
			'| Regina Pizzeria || North End || Brick-oven pizza',
			$updated
		);
		$this->assertStringContainsString(
			'| Neptune Oyster || North End || Warm buttered lobster roll'
				. '<ref>[https://boston.eater.com/neptune-oyster Eater Boston]</ref>',
			$updated
		);
	}

	public function testUpsertsMultilineWikitablePreservingCellAttributes() {
		$page = <<<'WIKITEXT'
{| class="wikitable sortable"
|-
! Product
! Price
! Stock
|-
| [[Widget A]]
| style="text-align:right;" | $10
| 50
|}
WIKITEXT;

		$updated = WandaStructuredEditor::upsertTableRows(
			$page,
			[
				[ 'Product' => 'Widget A', 'Price' => '$12', 'Stock' => '45' ],
				[ 'Product' => 'Widget B', 'Price' => '$20', 'Stock' => '100' ],
			],
			[],
			'',
			'Product'
		);

		$this->assertStringContainsString( '| style="text-align:right;" | $12', $updated );
		$this->assertStringContainsString( "|\n| Widget B\n| $20\n| 100\n|}", str_replace( '|-', '|', $updated ) );
	}

	public function testCreatesNewSectionAndTableWhenMissing() {
		$page = "'''Giacomo's''' is an Italian restaurant.";
		$updated = WandaStructuredEditor::upsertTableRows(
			$page,
			[
				[ 'Dish' => 'Fra Diavolo', 'Price' => '$28' ],
			],
			[],
			'Menu Highlights'
		);

		$expectedTable = "== Menu Highlights ==\n{| class=\"wikitable\"\n"
			. "! Dish !! Price\n|-\n| Fra Diavolo || \$28\n|}";
		$this->assertStringContainsString( $expectedTable, $updated );
	}

	public function testResolvesTableBySectionHeadingWhenMultipleTablesExist() {
		$page = <<<'WIKITEXT'
== Demographics ==
{| class="wikitable"
! Year !! Population
|-
| 2020 || 675,647
|}

== Restaurants ==
{| class="wikitable"
! Name !! Cuisine
|-
| Oleana || Mediterranean
|}
WIKITEXT;

		$updated = WandaStructuredEditor::upsertTableRows(
			$page,
			[
				[ 'Name' => 'Sarma', 'Cuisine' => 'Meze' ],
			],
			[],
			'Restaurants'
		);

		$this->assertStringContainsString( "| 2020 || 675,647\n|}", $updated );
		$this->assertStringContainsString( "| Oleana || Mediterranean\n|-\n| Sarma || Meze\n|}", $updated );
	}
}
