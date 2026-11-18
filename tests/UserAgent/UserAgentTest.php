<?php
/**
 * UserAgent, Referrer and Cloudflare test suite.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\UserAgent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\ClientHints;
use SugarCommerce\Network\UserAgent;

final class UserAgentTest extends TestCase {

	private const CHROME  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
	private const SAFARI  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15';
	private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
	private const IPAD    = 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/604.1';
	private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36';
	private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0';
	private const EDGE    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0';
	private const WEBVIEW = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36';
	private const INSTAGRAM  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0 (iPhone15,2; iOS 18_0; en_GB; en-GB; scale=3.00; 1179x2556; 642201423)';
	private const CHROMEBOOK = 'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

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
			// A browser is named, not a device: whether it is a phone is
			// device_type()'s answer, and "Safari Mobile" as a browser split
			// one browser's numbers in two.
			'safari mobile'  => array( self::IPHONE, 'Safari' ),
			'firefox'        => array( self::FIREFOX, 'Firefox' ),
			// Edge's UA contains "Chrome", so ordering is what saves this.
			'edge'           => array( self::EDGE, 'Edge' ),
			'chrome mobile'  => array( self::ANDROID, 'Chrome' ),
			// Every browser on iOS is WebKit and says "Safari". Its own
			// token is what tells them apart.
			'chrome on ios'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1', 'Chrome' ),
			'firefox on ios' => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/132.0 Mobile/15E148 Safari/605.1.15', 'Firefox' ),
			'edge on ios'    => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) EdgiOS/131.0.2903.92 Version/18.0 Mobile/15E148 Safari/604.1', 'Edge' ),
			'the google app' => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/340.0.682450399 Mobile/15E148 Safari/604.1', 'Google app' ),
			'samsung'        => array( 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet' ),
			'yandex'         => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.0.0 Mobile Safari/537.36', 'Yandex Browser' ),
			'opera'          => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0', 'Opera' ),
			'electron'       => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Slack/4.41.97 Chrome/130.0.6723.152 Electron/33.2.0 Safari/537.36', 'Electron' ),
			'android webview' => array( self::WEBVIEW, 'Android WebView' ),
			// WebKit with no "Safari" in it: an app's own view.
			'ios webview'    => array( self::INSTAGRAM, 'iOS WebView' ),
			'ie 11'          => array( 'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko', 'Internet Explorer' ),
			// ChromeOS is a system. As a browser name it hid that this is Chrome.
			'chromebook'     => array( self::CHROMEBOOK, 'Chrome' ),
			// Vivaldi hides its token from most sites and reads as Chrome
			// there, so it reads as Chrome everywhere rather than as two
			// browsers depending on who asked.
			'vivaldi'        => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Vivaldi/7.0.3495.27', 'Chrome' ),
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
			'chromeos'      => array( self::CHROMEBOOK, 'ChromeOS' ),
			// A distribution is not a system, and most do not say which they are.
			'ubuntu'        => array( 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Linux' ),
			'windows 7'     => array( 'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko', 'Windows 7' ),
			// Both of these also say "Android", which is why they come first.
			'harmonyos'     => array( 'Mozilla/5.0 (Linux; Android 12; ANA-AN00; HMSCore 6.13.0.302) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.5735.196 HuaweiBrowser/15.0.4.312 Mobile Safari/537.36 HarmonyOS', 'HarmonyOS' ),
			'windows phone' => array( 'Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Mobile Safari/537.36 Edge/15.15254', 'Windows Phone' ),
			'ipad'          => array( self::IPAD, 'iOS' ),
		);
	}

	public function test_every_name_that_can_be_returned_is_listed(): void {
		// One name each, though Chrome and Firefox each have two patterns.
		$this->assertSame( array_unique( UserAgent::browsers() ), UserAgent::browsers() );
		$this->assertContains( 'Samsung Internet', UserAgent::browsers() );
		$this->assertNotContains( 'Chrome Mobile', UserAgent::browsers() );
		$this->assertContains( 'ChromeOS', UserAgent::operating_systems() );
		$this->assertContains( 'TikTok', UserAgent::in_app_browsers() );

		foreach ( self::browsers() as list( $agent ) ) {
			$this->assertContains( UserAgent::browser( $agent ), UserAgent::browsers() );
		}

		foreach ( self::operating_systems() as list( $agent ) ) {
			$this->assertContains( UserAgent::os( $agent ), UserAgent::operating_systems() );
		}
	}

	public function test_browser_version_is_extracted(): void {
		$this->assertSame( '131.0.0.0', UserAgent::browser_version( self::CHROME ) );
		$this->assertSame( '133.0', UserAgent::browser_version( self::FIREFOX ) );
		$this->assertSame( '11.0', UserAgent::browser_version( 'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0) like Gecko' ) );
		// A web view states no version of its own.
		$this->assertNull( UserAgent::browser_version( self::INSTAGRAM ) );
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

	/**
	 * A named crawler is a whole token, not a substring.
	 *
	 * The names looked distinctive enough for a substring to be safe.
	 * `Operator` is inside "OperatorConsole" and `Trae` inside
	 * "UltraEdge", and each of those was a browser logged as an AI agent.
	 */
	#[DataProvider( 'lookalike_agents' )]
	public function test_a_name_inside_another_word_is_not_a_bot( string $agent ): void {
		$this->assertFalse( UserAgent::is_bot( $agent ) );
		$this->assertFalse( UserAgent::is_ai_bot( $agent ) );
		$this->assertNull( UserAgent::bot_name( $agent ) );
		$this->assertSame( 'desktop', UserAgent::device_type( $agent ) );
	}

	/** @return array<string, array{0: string}> */
	public static function lookalike_agents(): array {
		return array(
			'operator' => array( self::CHROME . ' OperatorConsole/2.1' ),
			'trae'     => array( self::CHROME . ' UltraEdit/3.0' ),
			'cursor'   => array( self::CHROME . ' Precursory/1.0' ),
			'devin'    => array( self::CHROME . ' Devinci/4' ),
			'openai'   => array( self::CHROME . ' OpenAirPlayer/2' ),
		);
	}

	public function test_the_longest_name_at_the_same_spot_wins(): void {
		$this->assertSame( 'Applebot-Extended', UserAgent::bot_name( 'Mozilla/5.0 (compatible; Applebot-Extended/0.1)' ) );
		$this->assertSame( 'Applebot', UserAgent::bot_name( 'Mozilla/5.0 (compatible; Applebot/0.1)' ) );
		// And the leftmost in the agent, not the first in the list.
		$this->assertSame( 'TelegramBot', UserAgent::bot_name( 'TelegramBot (like TwitterBot)' ) );
		// Named as the list spells it, however the agent does.
		$this->assertSame( 'Meta-ExternalAgent', UserAgent::bot_name( 'meta-externalagent/1.1' ) );
	}

	/* ─── Link previews ─────────────────────────────────────────────── */

	#[DataProvider( 'link_previews' )]
	public function test_a_link_preview_is_named( string $agent, string $expected ): void {
		$this->assertSame( $expected, UserAgent::link_preview( $agent ) );
		$this->assertTrue( UserAgent::is_link_preview( $agent ) );
		// Not a visit, so not a browser either.
		$this->assertTrue( UserAgent::is_bot( $agent ) );
		$this->assertSame( $expected . ' link preview', UserAgent::describe( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function link_previews(): array {
		return array(
			'whatsapp' => array( 'WhatsApp/2.24.1.6 A', 'WhatsApp' ),
			// Signal borrows WhatsApp's name.
			'signal'   => array( 'WhatsApp/2', 'WhatsApp' ),
			'slack'    => array( 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Slackbot' ),
			'discord'  => array( 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)', 'Discordbot' ),
			'telegram' => array( 'TelegramBot (like TwitterBot)', 'TelegramBot' ),
			// iMessage borrows Facebook's and Twitter's.
			'imessage' => array( 'facebookexternalhit/1.1 Facebot Twitterbot/1.0', 'facebookexternalhit' ),
			'linkedin' => array( 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)', 'LinkedInBot' ),
			'skype'    => array( 'Mozilla/5.0 (Windows NT 6.1; WOW64) SkypeUriPreview Preview/0.5', 'SkypeUriPreview' ),
			'bluesky'  => array( 'Mozilla/5.0 (compatible; Bluesky Cardyb/1.1; +mailto:support@bsky.app)', 'Bluesky Cardyb' ),
			'mastodon' => array( 'http.rb/5.1.1 (Mastodon/4.3.0; +https://mastodon.social/)', 'Mastodon' ),
		);
	}

	public function test_a_browser_or_a_crawler_is_not_a_link_preview(): void {
		$this->assertNull( UserAgent::link_preview( self::IPHONE ) );
		$this->assertFalse( UserAgent::is_link_preview( self::IPHONE ) );
		$this->assertFalse( UserAgent::is_link_preview( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
	}

	/* ─── Tools ─────────────────────────────────────────────────────── */

	#[DataProvider( 'tools' )]
	public function test_a_tool_is_named_by_what_it_starts_with( string $agent, string $expected ): void {
		$this->assertSame( $expected, UserAgent::tool( $agent ) );
		$this->assertTrue( UserAgent::is_bot( $agent ) );
		$this->assertSame( 'bot', UserAgent::device_type( $agent ) );
		$this->assertSame( $expected, UserAgent::describe( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function tools(): array {
		return array(
			'curl'       => array( 'curl/8.7.1', 'curl' ),
			'wget'       => array( 'Wget/1.21.4', 'Wget' ),
			'requests'   => array( 'python-requests/2.32.3', 'Python' ),
			'httpx'      => array( 'python-httpx/0.27.0', 'Python' ),
			'go'         => array( 'Go-http-client/2.0', 'Go' ),
			'aria2'      => array( 'aria2/1.37.0', 'aria2' ),
			'deno'       => array( 'Deno/2.0.0', 'Deno' ),
			'bun'        => array( 'Bun/1.1.30', 'Bun' ),
			'undici'     => array( 'undici', 'Node.js' ),
			'java'       => array( 'Java/17.0.9', 'Java' ),
			'okhttp'     => array( 'okhttp/4.12.0', 'OkHttp' ),
			'postman'    => array( 'PostmanRuntime/7.42.0', 'Postman' ),
			// The one that hides inside a Mozilla-shaped string.
			'powershell' => array( 'Mozilla/5.0 (Windows NT; Windows NT 10.0; en-GB) WindowsPowerShell/5.1.19041.4648', 'PowerShell' ),
		);
	}

	public function test_a_tools_name_elsewhere_in_the_agent_is_not_a_tool(): void {
		// Only at the start, where each puts its name. Anywhere else would
		// call a browser "Java" for a word in a plugin list.
		$this->assertNull( UserAgent::tool( self::CHROME ) );
		$this->assertNull( UserAgent::tool( self::CHROME . ' Bunny/1.0 Denon/2' ) );
		$this->assertFalse( UserAgent::is_bot( self::CHROME . ' Bunny/1.0 Denon/2' ) );
	}

	/* ─── In-app browsers ───────────────────────────────────────────── */

	#[DataProvider( 'in_app_agents' )]
	public function test_an_in_app_browser_is_named( string $agent, string $expected ): void {
		$this->assertSame( $expected, UserAgent::in_app_browser( $agent ) );
		// A person, in an app. Not a bot.
		$this->assertFalse( UserAgent::is_bot( $agent ) );
		$this->assertStringStartsWith( $expected . ' in-app browser on ', UserAgent::describe( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function in_app_agents(): array {
		$ios     = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ';
		$android = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.0.0 Mobile Safari/537.36 ';

		return array(
			'tiktok ios'        => array( $ios . 'musical_ly_36.5.0 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/en Region/GB BytedanceWebview/d8a21c6', 'TikTok' ),
			'tiktok android'    => array( $android . 'trill_360005 BytedanceWebview/d8a21c6', 'TikTok' ),
			'instagram ios'     => array( self::INSTAGRAM, 'Instagram' ),
			'instagram android' => array( $android . 'Instagram 350.0.0.32.92 Android (34/14; 420dpi; 1080x2400; Google; Pixel 8; shiba; shiba; en_GB; 642201423)', 'Instagram' ),
			'facebook ios'      => array( $ios . '[FBAN/FBIOS;FBAV/480.0.0.39.105;FBBV/1;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/18.0;FBSS/3;FBID/phone;FBLC/en_GB;FBOP/5]', 'Facebook' ),
			'facebook android'  => array( $android . '[FB_IAB/FB4A;FBAV/480.0.0.39.105;]', 'Facebook' ),
			// Carries Facebook's token too, so it has to be asked first.
			'messenger'         => array( $ios . '[FBAN/MessengerForiOS;FBAV/480.0.0.39.105;FBBV/1;FBDV/iPhone15,2]', 'Messenger' ),
			'threads'           => array( $android . 'Barcelona 289.0.0.77.109 Android', 'Threads' ),
			'snapchat'          => array( $ios . 'Snapchat/13.10.0.42 (like Safari/8618.1.15.10.15, panda)', 'Snapchat' ),
			'linkedin'          => array( $ios . '[LinkedInApp]/9.30.1234', 'LinkedIn' ),
			'pinterest'         => array( $ios . '[Pinterest/iOS]', 'Pinterest' ),
			'line'              => array( $ios . 'Safari Line/14.16.0', 'LINE' ),
			'wechat'            => array( $ios . 'MicroMessenger/8.0.50(0x1800322d) NetType/WIFI Language/zh_CN', 'WeChat' ),
			'telegram'          => array( $android . 'Telegram-Android/11.2.3 (Google Pixel 8; Android 14; SDK 34; HIGH)', 'Telegram' ),
			'kakaotalk'         => array( $ios . 'KAKAOTALK 10.8.0', 'KakaoTalk' ),
		);
	}

	public function test_a_normal_browser_is_in_no_app(): void {
		foreach ( array( self::CHROME, self::SAFARI, self::IPHONE, self::IPAD, self::ANDROID, self::FIREFOX, self::EDGE, self::WEBVIEW ) as $agent ) {
			$this->assertNull( UserAgent::in_app_browser( $agent ), $agent );
		}

		// Nor is a crawler with an app's name in its own.
		$this->assertNull( UserAgent::in_app_browser( 'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)' ) );
		$this->assertNull( UserAgent::in_app_browser( 'TelegramBot (like TwitterBot)' ) );
		$this->assertNull( UserAgent::in_app_browser( 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36 Online/1.0' ) );
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
		$this->assertNull( ClientHints::browser( array() ) );
	}

	public function test_client_hints_name_the_browser_the_user_agent_hides(): void {
		// Brave reads as Chrome in every user agent. This is the only
		// place it says its name.
		$brave = array( 'HTTP_SEC_CH_UA' => '"Brave";v="131", "Chromium";v="131", "Not_A Brand";v="24"' );
		$edge  = array( 'HTTP_SEC_CH_UA' => '"Microsoft Edge";v="131", "Chromium";v="131"' );

		$this->assertSame( 'Brave', ClientHints::browser( $brave ) );
		$this->assertSame( 'Microsoft Edge', ClientHints::browser( $edge ) );
		$this->assertSame( 'Google Chrome', ClientHints::browser( array( 'HTTP_SEC_CH_UA' => '"Chromium";v="131", "Google Chrome";v="131"' ) ) );
		$this->assertSame( 'Chromium', ClientHints::browser( array( 'HTTP_SEC_CH_UA' => '"Chromium";v="131"' ) ) );
	}

	public function test_a_header_with_no_real_brand_is_not_client_hints(): void {
		$this->assertFalse( ClientHints::available( array( 'HTTP_SEC_CH_UA' => 'garbage' ) ) );
		$this->assertFalse( ClientHints::available( array( 'HTTP_SEC_CH_UA' => '"Not_A Brand";v="24"' ) ) );
		$this->assertTrue( ClientHints::available( array( 'HTTP_SEC_CH_UA' => '"Chromium";v="131"' ) ) );
	}

	public function test_client_hints_read_everything_at_once(): void {
		$server = array(
			'HTTP_SEC_CH_UA'                  => '"Brave";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
			'HTTP_SEC_CH_UA_MOBILE'           => '?0',
			'HTTP_SEC_CH_UA_PLATFORM'         => '"Windows"',
			'HTTP_SEC_CH_UA_PLATFORM_VERSION' => '"15.0.0"',
			'HTTP_SEC_CH_UA_ARCH'             => '"x86"',
		);

		$this->assertSame(
			array(
				'available'        => true,
				'brands'           => array(
					'Brave'    => '131',
					'Chromium' => '131',
				),
				'browser'          => 'Brave',
				'platform'         => 'Windows',
				'platform_version' => '15.0.0',
				'windows_version'  => 'Windows 11',
				'mobile'           => false,
				'model'            => null,
				'architecture'     => 'x86',
			),
			ClientHints::read( $server )
		);
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
		$this->assertSame( 'Edge 131 on Windows 10/11', UserAgent::describe( self::EDGE ) );
		$this->assertSame( 'Safari 17 on iOS', UserAgent::describe( self::IPHONE ) );
		$this->assertSame( 'Instagram in-app browser on iOS', UserAgent::describe( self::INSTAGRAM ) );
		$this->assertSame( 'WhatsApp link preview', UserAgent::describe( 'WhatsApp/2.24.1.6 A' ) );
		$this->assertSame( 'GPTBot', UserAgent::describe( 'Mozilla/5.0 (compatible; GPTBot/1.1)' ) );
		$this->assertSame( 'curl', UserAgent::describe( 'curl/8.7.1' ) );
		$this->assertSame( 'Unknown', UserAgent::describe( 'Something/1.0' ) );
		$this->assertSame( 'Bot', UserAgent::describe( '' ) );
	}

	public function test_everything_is_parsed_at_once(): void {
		$this->assertSame(
			array(
				'browser'       => 'Chrome',
				'version'       => '131.0.0.0',
				'os'            => 'macOS',
				'device'        => 'desktop',
				'bot'           => false,
				'bot_name'      => null,
				'ai_bot'        => false,
				'ai_user_agent' => false,
				'tool'          => null,
				'in_app'        => null,
				'link_preview'  => null,
				'label'         => 'Chrome 131 on macOS',
			),
			UserAgent::parse( self::CHROME )
		);

		$this->assertSame( 'Instagram', UserAgent::parse( self::INSTAGRAM )['in_app'] );
		$this->assertSame( 'unknown', UserAgent::parse( null )['device'] );
	}

	public function test_an_agent_is_trimmed_and_capped_before_it_is_read(): void {
		$this->assertSame( 512, mb_strlen( UserAgent::normalise( '  ' . str_repeat( 'x', 2000 ) ) ) );
		$this->assertSame( '', UserAgent::normalise( null ) );
		$this->assertSame( UserAgent::MAX_LENGTH, mb_strlen( UserAgent::normalise( str_repeat( 'x', 2000 ) ) ) );
		// What parse() reads is the capped agent: a name past the cap is not there.
		$this->assertFalse( UserAgent::parse( str_repeat( 'x', 600 ) . ' Googlebot/2.1' )['bot'] );
	}

	public function test_the_current_agent_is_capped(): void {
		$this->assertSame( 512, mb_strlen( UserAgent::current( array( 'HTTP_USER_AGENT' => str_repeat( 'a', 2000 ) ) ) ) );
		$this->assertSame( '', UserAgent::current( array() ) );
	}

	/* ─── Referrer ──────────────────────────────────────────────────── */












	/* ─── Cloudflare ────────────────────────────────────────────────── */




}
