<?php
/**
 * Request bridge tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\UserAgent;

use SugarCommerce\Network\Request;
use PHPUnit\Framework\TestCase;

/**
 * UserAgent and ClientHints take what they are given, which is what makes them
 * testable. Request decides what to give them, and that is where WordPress has
 * opinions.
 */
final class RequestTest extends TestCase {

	private const CHROME  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
	private const IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
	private const IPAD    = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	/**
	 * Clear the request.
	 */
	protected function setUp(): void {
		ua_reset_server();
	}

	/**
	 * And again.
	 */
	protected function tearDown(): void {
		ua_reset_server();
	}

	/**
	 * No header is an empty string, not a guess.
	 */
	public function test_no_header_is_nothing(): void {
		$this->assertSame( '', Request::agent() );
		$this->assertSame( 'unknown', Request::device_type() );
		$this->assertFalse( Request::is_ai_bot() );
	}

	/**
	 * The slashes core added are taken back off.
	 *
	 * WordPress runs add_magic_quotes() over $_SERVER as well as the request
	 * superglobals, so an agent containing a quote arrives with a backslash
	 * that was never sent.
	 */
	public function test_the_header_is_unslashed(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux) MyBrowser/1.0 \\"beta\\"';

		$this->assertStringNotContainsString( '\\', Request::agent() );
		$this->assertStringContainsString( '"beta"', Request::agent() );
	}

	/**
	 * Markup in the header does not survive.
	 */
	public function test_markup_is_stripped(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 <script>alert(1)</script>';

		$this->assertStringNotContainsString( '<script', Request::agent() );
	}

	/**
	 * An absurdly long header is cut down.
	 *
	 * It ends up in log rows and database columns, and nothing legitimate is
	 * this long.
	 */
	public function test_an_overlong_header_is_truncated(): void {
		$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'A', 4096 );

		$this->assertSame( 512, mb_strlen( Request::agent() ) );
	}

	/**
	 * The client hint decides where the browser sent one.
	 *
	 * This is the reason the bridge exists. `Sec-CH-UA-Mobile` is stated by
	 * the browser rather than inferred from a string that has been lying since
	 * Netscape, so it wins -- and it can disagree with the agent, which is
	 * what this asserts.
	 */
	public function test_the_client_hint_beats_the_agent_string(): void {
		// A desktop agent, but the browser says it is a phone.
		$_SERVER['HTTP_USER_AGENT']       = self::CHROME;
		$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?1';

		$this->assertSame( 'mobile', Request::device_type() );
		$this->assertTrue( Request::is_mobile() );

		// And the other way: a phone agent, but the browser says otherwise.
		$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?0';
		$_SERVER['HTTP_USER_AGENT']       = self::IPHONE;

		$this->assertSame( 'desktop', Request::device_type() );
		$this->assertFalse( Request::is_mobile() );
	}

	/**
	 * Without a hint, the agent string decides.
	 */
	public function test_without_a_hint_the_agent_decides(): void {
		$_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
		$this->assertSame( 'mobile', Request::device_type() );

		$_SERVER['HTTP_USER_AGENT'] = self::CHROME;
		$this->assertSame( 'desktop', Request::device_type() );
	}

	/**
	 * A tablet stays a tablet even when the hint says "mobile".
	 *
	 * The hint is a yes-or-no about phones and has nothing to say about
	 * tablets, so it cannot be the whole answer.
	 */
	public function test_a_tablet_survives_the_hint(): void {
		$_SERVER['HTTP_USER_AGENT']       = self::IPAD;
		$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?1';

		$this->assertSame( 'tablet', Request::device_type() );
	}

	/**
	 * A bot is a bot whatever the hints say.
	 *
	 * Bots do not send client hints, so a hint arriving alongside a crawler
	 * agent is somebody's idea of a disguise.
	 */
	public function test_a_bot_outranks_the_hints(): void {
		$_SERVER['HTTP_USER_AGENT']       = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$_SERVER['HTTP_SEC_CH_UA_MOBILE'] = '?0';

		$this->assertSame( 'bot', Request::device_type() );
	}

	/**
	 * An AI crawler and an AI agent acting for a person are told apart.
	 *
	 * Worth distinguishing: one is an indexing pass and the other is a visit
	 * somebody asked for. They should be counted differently, and the second
	 * should not be caught by the rules written for the first.
	 */
	public function test_an_ai_crawler_and_an_ai_visitor_differ(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';

		$this->assertTrue( Request::is_ai_bot() );
		$this->assertFalse( Request::is_ai_user_agent() );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)';

		$this->assertTrue( Request::is_ai_user_agent() );
	}

	/**
	 * A chat app drawing a link's card is not somebody visiting.
	 *
	 * The fetch arrives like any other request, and counting it spends a
	 * buyer's download on a preview nobody has looked at yet.
	 */
	public function test_a_link_preview_is_not_a_visit(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WhatsApp/2.24.1.6 A';

		$this->assertTrue( Request::is_link_preview() );
		$this->assertSame( 'bot', Request::device_type() );
		$this->assertSame( 'WhatsApp link preview', Request::describe() );

		$_SERVER['HTTP_USER_AGENT'] = self::IPHONE;

		$this->assertFalse( Request::is_link_preview() );
	}

	/**
	 * The app a request came from is named when the app says so.
	 *
	 * An in-app browser usually sends no referrer, so this is what turns a
	 * "direct" visit into a TikTok one.
	 */
	public function test_the_app_a_request_came_from_is_named(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_36.5.0 BytedanceWebview/d8a21c6';

		$this->assertSame( 'TikTok', Request::in_app_browser() );
		$this->assertSame( 'mobile', Request::device_type() );

		$_SERVER['HTTP_USER_AGENT'] = self::IPHONE;

		$this->assertNull( Request::in_app_browser() );

		unset( $_SERVER['HTTP_USER_AGENT'] );

		$this->assertNull( Request::in_app_browser() );
		$this->assertFalse( Request::is_link_preview() );
	}

	/**
	 * A description reads like something you would put in a log row.
	 */
	public function test_a_request_describes_itself(): void {
		$_SERVER['HTTP_USER_AGENT'] = self::CHROME;

		$this->assertSame( 'Chrome 131 on macOS', Request::describe() );
	}
}
