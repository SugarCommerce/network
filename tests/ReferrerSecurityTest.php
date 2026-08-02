<?php
/**
 * Referrer sanitisation.
 *
 * @package   Sugar\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sugar\Network\Referrer;

/**
 * The `Referer` header and the UTM query parameters are attacker-controlled
 * and routinely persisted against an order, then printed into an admin
 * table. Everything here is about what must not survive that round trip.
 */
final class ReferrerSecurityTest extends TestCase {

	/**
	 * A stored `javascript:` referrer rendered as a link is cross-site
	 * scripting one click away.
	 */
	#[DataProvider( 'unusable_referrers' )]
	public function test_only_navigable_schemes_survive( string $referrer ): void {
		$this->assertSame( '', Referrer::safe_url( $referrer ) );

		$source = Referrer::analyse( $referrer );

		$this->assertSame( 'direct', $source->channel );
		$this->assertSame( '', $source->referrer );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_referrers(): array {
		return array(
			'javascript'      => array( 'javascript:alert(document.cookie)' ),
			'javascript case' => array( 'JaVaScRiPt:alert(1)' ),
			'data'            => array( 'data:text/html,<script>alert(1)</script>' ),
			'vbscript'        => array( 'vbscript:msgbox(1)' ),
			'file'            => array( 'file:///etc/passwd' ),
			'protocol rel'    => array( '//evil.com/path' ),
			'bare host'       => array( 'evil.com' ),
			'no host'         => array( 'https://' ),
			'empty'           => array( '' ),
			'whitespace'      => array( "   \t  " ),
			'newline'         => array( "https://ok.com/\r\nSet-Cookie: a=b" ),
			'null byte'       => array( "https://ok.com/\x00evil" ),
			'over length'     => array( 'https://ok.com/' . str_repeat( 'a', 2100 ) ),
		);
	}

	public function test_ordinary_referrers_pass_through(): void {
		$url = 'https://news.example.com/article?id=12#top';

		$this->assertSame( $url, Referrer::safe_url( $url ) );
	}

	/**
	 * These are illegal raw in a URI, so encoding them is lossless for a
	 * real referrer — and it removes the characters that break out of an
	 * `href="…"` attribute.
	 */
	public function test_attribute_breaking_characters_are_encoded(): void {
		$safe = Referrer::safe_url( 'https://evil.com/"><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '"', $safe );
		$this->assertStringNotContainsString( '<', $safe );
		$this->assertStringNotContainsString( '>', $safe );
		$this->assertStringStartsWith( 'https://evil.com/%22%3E%3Cscript%3E', $safe );
	}

	public function test_a_stored_referrer_never_contains_a_quote(): void {
		$source = Referrer::analyse( 'https://google.com/search?q=%22onload%3Dalert(1)' );

		$this->assertStringNotContainsString( '"', $source->referrer );
		$this->assertStringNotContainsString( '"', $source->term );
	}

	/**
	 * `strip_tags` removes `<script>` but leaves `" onmouseover=…`, which
	 * is all an unescaped attribute needs.
	 */
	#[DataProvider( 'hostile_campaigns' )]
	public function test_campaign_values_lose_attribute_syntax( string $campaign ): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => $campaign ) );

		foreach ( array( '<', '>', '"', "'", '`', '\\' ) as $character ) {
			$this->assertStringNotContainsString( $character, $source->name );
		}

		// The label wraps the name in its own pair of quotes. More than
		// two means one came from the campaign; fewer means the whole
		// value was stripped and the visit fell back to direct.
		$this->assertLessThanOrEqual( 2, substr_count( $source->label(), '"' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_campaigns(): array {
		return array(
			'attribute break' => array( '" onmouseover=alert(1) x="' ),
			'tag'             => array( '<img src=x onerror=alert(1)>' ),
			'single quote'    => array( "' onfocus=alert(1) autofocus '" ),
			'backtick'        => array( '`alert(1)`' ),
			'backslash'       => array( 'a\\"b' ),
		);
	}

	public function test_campaign_values_are_capped(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => str_repeat( 'a', 5000 ) ) );

		$this->assertSame( 200, mb_strlen( $source->name ) );
	}

	public function test_control_characters_are_stripped_from_campaigns(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => "spring\r\n2026\x00" ) );

		$this->assertSame( 'spring 2026', $source->name );
	}

	public function test_invalid_utf8_is_discarded(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => "\xC3\x28bad", 'utm_source' => 'ok' ) );

		$this->assertSame( 'ok', $source->name );
	}

	public function test_non_string_query_values_are_ignored(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => array( 'x' ), 'utm_source' => 'news' ) );

		$this->assertSame( 'news', $source->name );
	}
}
