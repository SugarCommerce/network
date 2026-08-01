<?php
/**
 * UserAgent, Referrer and Cloudflare test suite.
 *
 * @package   SugarMill\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarMill\Network\ClientHints;
use SugarMill\Network\Cloudflare;
use SugarMill\Network\Referrer;
use SugarMill\Network\TrustedProxies;
use SugarMill\Network\UserAgent;

final class UserAgentTest extends TestCase {

	private const CHROME  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
	private const SAFARI  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15';
	private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
	private const IPAD    = 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/604.1';
	private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
	private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';
	private const EDGE    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0';

	/* ─── Browser and OS ────────────────────────────────────────────── */

	#[DataProvider( 'browsers' )]
	public function test_browser_detection( string $agent, string $expected ): void {
		$this->assertSame( $expected, UserAgent::browser( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function browsers(): array {
		return array(
			'chrome desktop' => array( self::CHROME, 'Chrome' ),
			'safari desktop' => array( self::SAFARI, 'Safari' ),
			'safari mobile'  => array( self::IPHONE, 'Safari Mobile' ),
			'firefox'        => array( self::FIREFOX, 'Firefox' ),
			// Edge's UA contains "Chrome", so ordering is what saves this.
			'edge'           => array( self::EDGE, 'Edge' ),
			'chrome mobile'  => array( self::ANDROID, 'Chrome Mobile' ),
		);
	}

	#[DataProvider( 'operating_systems' )]
	public function test_os_detection( string $agent, string $expected ): void {
		$this->assertSame( $expected, UserAgent::os( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function operating_systems(): array {
		return array(
			'macos'   => array( self::CHROME, 'macOS' ),
			'ios'     => array( self::IPHONE, 'iOS' ),
			'android' => array( self::ANDROID, 'Android' ),
			'windows' => array( self::FIREFOX, 'Windows 10/11' ),
		);
	}

	public function test_browser_version_is_extracted(): void {
		$this->assertSame( '131.0.0.0', UserAgent::browser_version( self::CHROME ) );
		$this->assertSame( '133.0', UserAgent::browser_version( self::FIREFOX ) );
	}

	public function test_unrecognised_agents_return_null(): void {
		$this->assertNull( UserAgent::browser( 'something else entirely' ) );
		$this->assertNull( UserAgent::os( 'something else entirely' ) );
	}

	/* ─── Device type ───────────────────────────────────────────────── */

	public function test_device_classification(): void {
		$this->assertSame( 'desktop', UserAgent::device_type( self::CHROME ) );
		$this->assertSame( 'mobile', UserAgent::device_type( self::IPHONE ) );
		$this->assertSame( 'tablet', UserAgent::device_type( self::IPAD ) );
		$this->assertSame( 'mobile', UserAgent::device_type( self::ANDROID ) );
		$this->assertSame( 'bot', UserAgent::device_type( 'Googlebot/2.1' ) );
		$this->assertSame( 'unknown', UserAgent::device_type( '' ) );
	}

	public function test_an_android_tablet_lacks_the_mobile_token(): void {
		$tablet = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

		$this->assertTrue( UserAgent::is_tablet( $tablet ) );
		$this->assertFalse( UserAgent::is_mobile( $tablet ) );
	}

	/* ─── Bots ──────────────────────────────────────────────────────── */

	#[DataProvider( 'bot_agents' )]
	public function test_crawlers_are_detected( string $agent ): void {
		$this->assertTrue( UserAgent::is_bot( $agent ) );
	}

	/** @return array<string, array{0: string}> */
	public static function bot_agents(): array {
		return array(
			'googlebot'   => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'bingbot'     => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'gptbot'      => array( 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' ),
			'claudebot'   => array( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' ),
			'perplexity'  => array( 'Mozilla/5.0 (compatible; PerplexityBot/1.0)' ),
			'bytespider'  => array( 'Mozilla/5.0 (compatible; Bytespider)' ),
			'facebook'    => array( 'facebookexternalhit/1.1' ),
			'slack'       => array( 'Slackbot-LinkExpanding 1.0' ),
			'ahrefs'      => array( 'Mozilla/5.0 (compatible; AhrefsBot/7.0)' ),
			'curl'        => array( 'curl/8.7.1' ),
			'wget'        => array( 'Wget/1.21.4' ),
			'python'      => array( 'python-requests/2.32.3' ),
			'go'          => array( 'Go-http-client/2.0' ),
			'postman'     => array( 'PostmanRuntime/7.42.0' ),
			'generic bot' => array( 'SomeNew Bot/1.0' ),
			'empty'       => array( '' ),
		);
	}

	#[DataProvider( 'human_agents' )]
	public function test_real_browsers_are_not_flagged( string $agent ): void {
		$this->assertFalse( UserAgent::is_bot( $agent ) );
	}

	/** @return array<string, array{0: string}> */
	public static function human_agents(): array {
		return array(
			'chrome'  => array( self::CHROME ),
			'safari'  => array( self::SAFARI ),
			'iphone'  => array( self::IPHONE ),
			'android' => array( self::ANDROID ),
			'firefox' => array( self::FIREFOX ),
			'edge'    => array( self::EDGE ),
			// Cubot is a real Android phone brand. A substring match on
			// "bot" classifies every one of its owners as a crawler.
			'cubot phone' => array( 'Mozilla/5.0 (Linux; Android 13; Cubot_NOTE_20) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36' ),
			// Same trap, different word.
			'abbott in path' => array( 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36 Abbott/2.0' ),
		);
	}

	public function test_windows_11_is_not_claimed_from_a_user_agent(): void {
		// Windows 11 reports "Windows NT 10.0", identical to Windows 10.
		// Claiming to know which is a lie; the range is the honest answer.
		$win11 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

		$this->assertSame( 'Windows 10/11', UserAgent::os( $win11 ) );
	}

	public function test_client_hints_distinguish_windows_versions(): void {
		$win11 = array( 'HTTP_SEC_CH_UA_PLATFORM' => '"Windows"', 'HTTP_SEC_CH_UA_PLATFORM_VERSION' => '"15.0.0"' );
		$win10 = array( 'HTTP_SEC_CH_UA_PLATFORM' => '"Windows"', 'HTTP_SEC_CH_UA_PLATFORM_VERSION' => '"10.0.0"' );

		$this->assertSame( 'Windows 11', ClientHints::windows_version( $win11 ) );
		$this->assertSame( 'Windows 10', ClientHints::windows_version( $win10 ) );
		$this->assertNull( ClientHints::windows_version( array() ) );
		$this->assertNull( ClientHints::windows_version( array( 'HTTP_SEC_CH_UA_PLATFORM' => '"macOS"' ) ) );
	}

	public function test_client_hint_brands_drop_the_decoy_entries(): void {
		$server = array( 'HTTP_SEC_CH_UA' => '"Chromium";v="131", "Google Chrome";v="131", "Not_A Brand";v="24"' );

		$this->assertSame( array( 'Chromium' => '131', 'Google Chrome' => '131' ), ClientHints::brands( $server ) );
	}

	public function test_client_hints_are_absent_for_non_chromium(): void {
		$this->assertFalse( ClientHints::available( array() ) );
		$this->assertNull( ClientHints::platform( array() ) );
		$this->assertNull( ClientHints::is_mobile( array() ) );
	}

	public function test_client_hint_mobile_flag(): void {
		$this->assertTrue( ClientHints::is_mobile( array( 'HTTP_SEC_CH_UA_MOBILE' => '?1' ) ) );
		$this->assertFalse( ClientHints::is_mobile( array( 'HTTP_SEC_CH_UA_MOBILE' => '?0' ) ) );
	}

	public function test_ai_user_agents_are_separable_from_training_crawlers(): void {
		// Blocking a training crawler is a licensing decision; blocking
		// these means refusing somebody who asked to see the page.
		$this->assertTrue( UserAgent::is_ai_user_agent( 'Mozilla/5.0 ChatGPT-User/1.0' ) );
		$this->assertTrue( UserAgent::is_ai_user_agent( 'Mozilla/5.0 (compatible; Perplexity-User/1.0)' ) );
		$this->assertTrue( UserAgent::is_ai_user_agent( 'Mozilla/5.0 (compatible; Claude-User/1.0)' ) );

		$this->assertFalse( UserAgent::is_ai_user_agent( 'Mozilla/5.0 (compatible; GPTBot/1.1)' ) );
		$this->assertFalse( UserAgent::is_ai_user_agent( 'Mozilla/5.0 (compatible; ClaudeBot/1.0)' ) );
		$this->assertFalse( UserAgent::is_ai_user_agent( self::CHROME ) );
	}

	#[DataProvider( 'modern_ai_bots' )]
	public function test_current_ai_crawlers_are_recognised( string $agent ): void {
		$this->assertTrue( UserAgent::is_bot( $agent ), 'is_bot' );
		$this->assertTrue( UserAgent::is_ai_bot( $agent ), 'is_ai_bot' );
	}

	/** @return array<string, array{0: string}> */
	public static function modern_ai_bots(): array {
		return array(
			'ChatGPT-User'   => array( 'Mozilla/5.0 ChatGPT-User/1.0; +https://openai.com/bot' ),
			'OAI-SearchBot'  => array( 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)' ),
			'Claude-User'    => array( 'Mozilla/5.0 (compatible; Claude-User/1.0)' ),
			'Perplexity-User'=> array( 'Mozilla/5.0 (compatible; Perplexity-User/1.0)' ),
			'MistralAI-User' => array( 'Mozilla/5.0 (compatible; MistralAI-User/1.0)' ),
			'DuckAssistBot'  => array( 'Mozilla/5.0 (compatible; DuckAssistBot/1.0)' ),
			'Meta external'  => array( 'meta-externalagent/1.1' ),
			'GoogleOther'    => array( 'Mozilla/5.0 (compatible; GoogleOther)' ),
			'Applebot-Ext'   => array( 'Mozilla/5.0 (compatible; Applebot-Extended/0.1)' ),
			'DeepSeek'       => array( 'Mozilla/5.0 (compatible; DeepSeekBot/1.0)' ),
			'Firecrawl'      => array( 'FirecrawlAgent/1.0' ),
			'bedrockbot'     => array( 'Mozilla/5.0 (compatible; bedrockbot/1.0)' ),
		);
	}

	public function test_ai_crawlers_are_separable_from_search_crawlers(): void {
		$this->assertTrue( UserAgent::is_ai_bot( 'Mozilla/5.0 (compatible; GPTBot/1.1)' ) );
		$this->assertTrue( UserAgent::is_ai_bot( 'Mozilla/5.0 (compatible; ClaudeBot/1.0)' ) );
		$this->assertFalse( UserAgent::is_ai_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertFalse( UserAgent::is_ai_bot( self::CHROME ) );
	}

	public function test_named_crawlers_are_identified(): void {
		$this->assertSame( 'Googlebot', UserAgent::bot_name( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertNull( UserAgent::bot_name( self::CHROME ) );
	}

	/* ─── Description ───────────────────────────────────────────────── */

	public function test_descriptions_are_readable(): void {
		$this->assertSame( 'Chrome 131 on macOS', UserAgent::describe( self::CHROME ) );
		$this->assertSame( 'Firefox 133 on Windows 10/11', UserAgent::describe( self::FIREFOX ) );
		$this->assertSame( 'Googlebot', UserAgent::describe( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
	}

	public function test_the_current_agent_is_capped(): void {
		$this->assertSame( 512, mb_strlen( UserAgent::current( array( 'HTTP_USER_AGENT' => str_repeat( 'a', 2000 ) ) ) ) );
		$this->assertSame( '', UserAgent::current( array() ) );
	}

	/* ─── Referrer ──────────────────────────────────────────────────── */

	public function test_search_engines_are_classified(): void {
		$source = Referrer::analyse( 'https://www.google.com/search?q=drum+samples' );

		$this->assertSame( 'search', $source->channel );
		$this->assertSame( 'Google', $source->name );
		$this->assertSame( 'drum samples', $source->term );
		$this->assertSame( 'Google search for "drum samples"', $source->label() );
	}

	public function test_ai_search_referrers_are_recognised(): void {
		$this->assertSame( 'Perplexity', Referrer::analyse( 'https://www.perplexity.ai/search/abc' )->name );
		$this->assertSame( 'ChatGPT', Referrer::analyse( 'https://chatgpt.com/c/abc' )->name );
	}

	public function test_social_networks_are_classified(): void {
		$this->assertSame( 'Twitter', Referrer::analyse( 'https://t.co/abc123' )->name );
		$this->assertSame( 'social', Referrer::analyse( 'https://www.linkedin.com/feed/' )->channel );
		$this->assertSame( 'Hacker News', Referrer::analyse( 'https://news.ycombinator.com/item?id=1' )->name );
	}

	public function test_webmail_is_classified_as_email(): void {
		$this->assertSame( 'email', Referrer::analyse( 'https://mail.google.com/mail/u/0/' )->channel );
	}

	public function test_an_unknown_site_is_a_referral(): void {
		$source = Referrer::analyse( 'https://blog.example.com/post' );

		$this->assertSame( 'referral', $source->channel );
		$this->assertSame( 'example.com', $source->name );
	}

	public function test_no_referrer_is_direct(): void {
		$source = Referrer::analyse( '' );

		$this->assertTrue( $source->is_direct() );
		$this->assertSame( 'Direct', $source->label() );
	}

	public function test_campaigns_beat_the_referrer_header(): void {
		$source = Referrer::analyse(
			'https://www.google.com/search?q=x',
			array( 'utm_campaign' => 'spring-2026', 'utm_source' => 'newsletter', 'utm_medium' => 'email' )
		);

		$this->assertSame( 'email', $source->channel );
		$this->assertSame( 'spring-2026', $source->name );
	}

	public function test_paid_campaigns_are_distinguished(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => 'q1', 'utm_medium' => 'cpc' ) );

		$this->assertSame( 'paid', $source->channel );
		$this->assertTrue( $source->is_campaign() );
	}

	public function test_campaign_values_are_sanitised(): void {
		$source = Referrer::analyse( '', array( 'utm_campaign' => '<script>alert(1)</script>spring' ) );

		$this->assertStringNotContainsString( '<', $source->name );
	}

	public function test_internal_referrers_are_detected(): void {
		$this->assertTrue( Referrer::is_internal( 'https://shop.example.com/a', 'https://example.com' ) );
		$this->assertFalse( Referrer::is_internal( 'https://other.com/a', 'https://example.com' ) );
	}

	public function test_root_domain_extraction(): void {
		$this->assertSame( 'example.com', Referrer::root_domain( 'www.example.com' ) );
		$this->assertSame( 'example.com', Referrer::root_domain( 'a.b.example.com' ) );
		$this->assertSame( 'example.com', Referrer::root_domain( 'example.com' ) );
	}

	/* ─── Cloudflare ────────────────────────────────────────────────── */

	public function test_cloudflare_headers_are_read_from_cloudflare(): void {
		$cf     = new Cloudflare();
		$server = array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_IPCOUNTRY' => 'gb', 'HTTP_CF_RAY' => '8a1b2c3d4e5f6789-LHR' );

		$this->assertTrue( $cf->is_active( $server ) );
		$this->assertSame( 'GB', $cf->country( $server ) );
		$this->assertSame( '8a1b2c3d4e5f6789-LHR', $cf->ray_id( $server ) );
	}

	public function test_cloudflare_headers_are_ignored_from_elsewhere(): void {
		$cf     = new Cloudflare();
		$server = array( 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_CF_IPCOUNTRY' => 'GB' );

		$this->assertFalse( $cf->is_active( $server ) );
		$this->assertNull( $cf->country( $server ) );
	}

	public function test_tor_exit_nodes_are_flagged(): void {
		$cf = new Cloudflare();

		$this->assertTrue( $cf->is_tor( array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_IPCOUNTRY' => 'T1' ) ) );
		$this->assertFalse( $cf->is_tor( array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_IPCOUNTRY' => 'GB' ) ) );
	}

	public function test_malformed_cloudflare_headers_are_rejected(): void {
		$cf     = new Cloudflare( TrustedProxies::cloudflare() );
		$server = array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_IPCOUNTRY' => 'NOT-A-COUNTRY', 'HTTP_CF_RAY' => 'nonsense' );

		$this->assertNull( $cf->country( $server ) );
		$this->assertNull( $cf->ray_id( $server ) );
	}
}
