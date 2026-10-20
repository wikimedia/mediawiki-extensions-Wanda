<?php

namespace MediaWiki\Extension\Wanda\Tests\Unit;

use MediaWiki\Extension\Wanda\WandaEditContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure normalization/source-policy tests; permission integration needs MediaWiki core.
 * @covers \MediaWiki\Extension\Wanda\WandaEditContext
 * @group Wanda
 */
class WandaEditContextTest extends TestCase {

	/** @dataProvider provideSourcePolicies */
	public function testSourcePolicy(
		string $source, array $disabled, bool $cargo, bool $smw, ?string $expected
	): void {
		$this->assertSame( $expected, WandaEditContext::validateSource(
			$source, $disabled,
			[ 'wikidata' => [ 'apiUrl' => 'https://www.wikidata.org/w/api.php' ] ],
			[ 'Wikipedia' => [ 'url' => 'https://en.wikipedia.org' ] ],
			$cargo, $smw
		) );
	}

	public static function provideSourcePolicies(): array {
		return [
			[ 'wiki', [], false, false, null ],
			[ 'wiki', [ 'wiki' ], false, false, 'wanda-context-source-disabled' ],
			[ 'cargo', [], false, false, 'wanda-context-source-unavailable' ],
			[ 'cargo', [], true, false, 'wanda-context-source-permissions-unavailable' ],
			[ 'smw', [], false, false, 'wanda-context-source-unavailable' ],
			[ 'smw', [], false, true, 'wanda-context-source-permissions-unavailable' ],
			[ 'wikidata', [], false, false, 'wanda-context-source-bounds-unavailable' ],
			[ 'externalwiki', [], false, false, 'wanda-context-source-bounds-unavailable' ],
			[ 'wikidata', [ 'wikidata' ], false, false, 'wanda-context-source-disabled' ],
			[ 'RAG:docs', [], false, false, 'wanda-context-source-unsupported' ],
			[ 'publicknowledge', [], false, false, 'wanda-context-source-unsupported' ],
			[ 'wiki|cargo', [], true, true, 'wanda-context-source-invalid' ],
			[ ' wiki ', [], false, false, 'wanda-context-source-invalid' ],
			[ 'Wiki', [], false, false, 'wanda-context-source-invalid' ],
			[ 'https://example.org', [], false, false, 'wanda-context-source-invalid' ],
			[ '', [], false, false, 'wanda-context-source-invalid' ],
		];
	}

	public function testUnconfiguredSourcesAreUnavailable(): void {
		$this->assertSame( 'wanda-context-source-invalid',
			WandaEditContext::validateSource( 'wikidata', [], [], [], false, false ) );
		$this->assertSame( 'wanda-context-source-unavailable',
			WandaEditContext::validateSource( 'externalwiki', [], [], [], false, false ) );
	}

	/** @dataProvider provideHrefs */
	public function testHrefValidation( string $href, bool $expected ): void {
		$this->assertSame( $expected, WandaEditContext::isSafeHref( $href ) );
	}

	public static function provideHrefs(): array {
		return [
			[ '/wiki/Some_page', true ],
			[ '/w/index.php?title=Some%20page&oldid=123', true ],
			[ 'https://example.org/wiki/Item#Section', true ],
			[ 'http://example.org/', true ],
			[ '//evil.example/a', false ],
			[ '/%2fevil.example/a', false ],
			[ '/\\evil.example', false ],
			[ '/%5cevil.example', false ],
			[ "https://example.org/\nsecret", false ],
			[ '/wiki/Foo%0d%0aLocation:evil', false ],
			[ 'https://user:secret@example.org/', false ],
			[ 'javascript:alert(1)', false ],
			[ 'data:text/html,bad', false ],
			[ 'file:///etc/passwd', false ],
			[ 'ftp://example.org/', false ],
			[ 'https:///missing-host', false ],
			[ 'relative/path', false ],
			[ '#section', false ],
			[ '', false ],
			[ '/' . str_repeat( 'x', 2048 ), false ],
		];
	}

	public function testCharacterLimits(): void {
		$this->assertSame( 128, WandaEditContext::normalizeMaxChars( 128 ) );
		$this->assertSame( 10000, WandaEditContext::normalizeMaxChars( 10000 ) );
		$this->assertSame( 50000, WandaEditContext::normalizeMaxChars( PHP_INT_MAX ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wanda-context-limit-too-small' );
		WandaEditContext::normalizeMaxChars( 127 );
	}

	public function testEmptyContextHasStableContract(): void {
		$this->assertSame( [ 'content' => '', 'sources' => [], 'source' => 'wiki' ],
			WandaEditContext::packWikiContext( [], 10000 ) );
	}

	public function testCitationsAreDeduplicatedAndNotTargets(): void {
		$page = [ 'title' => 'A', 'href' => '/wiki/A', 'content' => 'Verified text', 'apikey' => 'SECRET' ];
		$result = WandaEditContext::packWikiContext( [ $page, $page ], 10000 );
		$this->assertSame( [ [ 'title' => 'A', 'href' => '/wiki/A' ] ], $result['sources'] );
		$this->assertSame( [ 'content', 'sources', 'source' ], array_keys( $result ) );
		$this->assertStringNotContainsString( 'SECRET', $result['content'] );
		$this->assertStringContainsString( 'not exhaustive', $result['content'] );
		$this->assertStringNotContainsString( '[TRUNCATED]', $result['content'] );
	}

	public function testMultibyteContentAndMarkerStayWithinBudget(): void {
		$result = WandaEditContext::packWikiContext( [
			[ 'title' => '日本語', 'href' => '/wiki/Japanese', 'content' => str_repeat( '猫', 1000 ) ],
		], 128 );
		$this->assertSame( 128, mb_strlen( $result['content'], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $result['content'], 'UTF-8' ) );
		$this->assertStringStartsWith( "[TRUNCATED]\n", $result['content'] );
		$this->assertCount( 1, $result['sources'] );
	}

	public function testPageCountAndPerPageTextAreBounded(): void {
		$pages = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$pages[] = [ 'title' => "Page $i", 'href' => "/wiki/Page_$i", 'content' => str_repeat( 'x', 6000 ) ];
		}
		$result = WandaEditContext::packWikiContext( $pages, 50000 );
		$this->assertCount( 5, $result['sources'] );
		$this->assertSame( 5, substr_count( $result['content'], str_repeat( 'x', 4000 ) ) );
		$this->assertStringNotContainsString( str_repeat( 'x', 4001 ), $result['content'] );
		$this->assertStringStartsWith( '[TRUNCATED]', $result['content'] );
		$this->assertStringNotContainsString( 'Page 5', $result['content'] );
	}

	public function testExcludedContentDoesNotLeaveDanglingCitation(): void {
		$result = WandaEditContext::packWikiContext( [
			[ 'title' => 'A', 'href' => '/wiki/A', 'content' => str_repeat( 'a', 500 ) ],
			[ 'title' => 'B', 'href' => '/wiki/B', 'content' => 'Never included' ],
		], 128 );
		$this->assertSame( [ [ 'title' => 'A', 'href' => '/wiki/A' ] ], $result['sources'] );
		$this->assertStringNotContainsString( 'Never included', $result['content'] );
	}

	public function testUnsafeRecordsDoNotLeakTheirContent(): void {
		$result = WandaEditContext::packWikiContext( [
			[ 'title' => 'Bad', 'href' => 'javascript:bad', 'content' => 'SECRET' ],
			[ 'title' => "Header\nInjection", 'href' => '/wiki/A', 'content' => 'SECRET' ],
			[ 'title' => 'Bad encoding', 'href' => '/wiki/B', 'content' => "\xffSECRET" ],
			[ 'title' => 'Missing fields' ],
		], 10000 );
		$this->assertSame( "[TRUNCATED]\n", $result['content'] );
		$this->assertSame( [], $result['sources'] );
	}

	public function testContinuationIsExplicitEvenWithNoReadablePages(): void {
		$this->assertSame( [ 'content' => "[TRUNCATED]\n", 'sources' => [], 'source' => 'wiki' ],
			WandaEditContext::packWikiContext( [], 10000, true ) );
	}
}
