<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\EmbeddingGenerator;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wanda\EmbeddingGenerator
 * @group Wanda
 */
class EmbeddingGeneratorTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideDimensions
	 */
	public function testGetDimensions( string $provider, int $expected ) {
		$this->assertSame( $expected, EmbeddingGenerator::getDimensions( $provider ) );
	}

	public static function provideDimensions() {
		return [
			'openai' => [ 'openai', 1536 ],
			'azure' => [ 'azure', 1536 ],
			'gemini' => [ 'gemini', 768 ],
			'ollama' => [ 'ollama', 1024 ],
			'unknown provider falls back to 1536' => [ 'something-else', 1536 ],
		];
	}

	public function testChunkTextEmptyReturnsEmptyArray() {
		$this->assertSame( [], EmbeddingGenerator::chunkText( '' ) );
	}

	public function testChunkTextShortTextReturnsSingleChunk() {
		$text = 'Hello world. This is a test.';
		$this->assertSame( [ $text ], EmbeddingGenerator::chunkText( $text ) );
	}

	public function testChunkTextWithSectionsFitsInOneChunkWhenUnderLimit() {
		$text = "Intro paragraph here.\n\n== Section One ==\nContent of one.\n\n"
			. "== Section Two ==\nContent of two.";
		$chunks = EmbeddingGenerator::chunkText( $text, 5000 );

		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'Intro paragraph', $chunks[0] );
		$this->assertStringContainsString( 'Section One', $chunks[0] );
		$this->assertStringContainsString( 'Section Two', $chunks[0] );
	}

	public function testChunkTextSplitsBySectionWhenOverLimit() {
		$text = "Intro paragraph here.\n\n== Section One ==\nContent of one.\n\n"
			. "== Section Two ==\nContent of two.";
		$chunks = EmbeddingGenerator::chunkText( $text, 30 );

		// Grounded against the real implementation: the heading-aware split
		// produces three chunks for this input at a 30-char budget.
		$this->assertCount( 3, $chunks );
		$this->assertStringContainsString( 'Section One', $chunks[1] );
		$this->assertStringContainsString( 'Section Two', $chunks[2] );
	}

	public function testChunkTextSubdividesLongSectionlessText() {
		$text = str_repeat( 'Sentence one. Sentence two. Sentence three. ', 10 );
		$chunks = EmbeddingGenerator::chunkText( $text, 50 );

		// No headings: falls through to paragraph/sentence subdivision.
		$this->assertCount( 10, $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertNotSame( '', trim( $chunk ) );
		}
	}

	public function testChunkTextDropsEmptyChunks() {
		$chunks = EmbeddingGenerator::chunkText( "\n\n   \n\n" );
		$this->assertSame( [], $chunks );
	}

	public function testGenerateBatchSkipsNullEmbeddings() {
		// An unknown provider makes generate() return null for every chunk,
		// so the batch result is empty (no network access involved).
		$result = EmbeddingGenerator::generateBatch(
			[ 'chunk a', 'chunk b' ],
			'unknown-provider',
			'',
			'',
			'',
			5,
			''
		);
		$this->assertSame( [], $result );
	}

	public function testGenerateUnknownProviderReturnsNull() {
		$this->assertNull(
			EmbeddingGenerator::generate( 'text', 'nope', '', '', '', 5, '' )
		);
	}
}
