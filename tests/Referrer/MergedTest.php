<?php
/**
 * What the referrer reads now that the agent is in the same library.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Referrer;

use SugarCommerce\Network\Referrer;
use PHPUnit\Framework\TestCase;

/**
 * Class MergedTest
 */
final class MergedTest extends TestCase {

	/**
	 * TikTok's in-app browser on iOS.
	 */
	private const TIKTOK = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_34.1.0 JsSdk/2.0 NetType/WIFI Channel/App Store ByteLocale/en Region/GB';

	protected function setUp(): void {
		ru_reset_globals();
		ua_reset_server();
	}

	protected function tearDown(): void {
		ru_reset_globals();
		ua_reset_server();
		$_GET = [];
	}

	/**
	 * A visit from TikTok's own browser, which sent no referrer, is TikTok.
	 */
	public function test_the_in_app_browser_is_read_from_the_request(): void {
		$_SERVER['HTTP_USER_AGENT'] = self::TIKTOK;

		$source = Referrer::from_request();

		$this->assertSame( 'TikTok', $source['in_app'] );
		$this->assertSame( 'TikTok', $source['source'] );
		$this->assertSame( 'social', $source['channel'] );
	}

	/**
	 * Passing '' says there is none; passing a name says which.
	 */
	public function test_the_caller_can_still_say(): void {
		$_SERVER['HTTP_USER_AGENT'] = self::TIKTOK;

		$this->assertSame( 'direct', Referrer::from_request( '' )['channel'] );
		$this->assertSame( 'Instagram', Referrer::from_request( 'Instagram' )['source'] );
	}

	/**
	 * A normal browser is no in-app browser, and direct stays direct.
	 */
	public function test_a_normal_browser_is_direct(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

		$source = Referrer::from_request();

		$this->assertNull( $source['in_app'] );
		$this->assertSame( 'direct', $source['channel'] );
	}

	/**
	 * A referrer that is not UTF-8 is refused, not stored.
	 */
	public function test_a_referrer_that_is_not_utf8_is_refused(): void {
		$this->assertSame( '', Referrer::safe_url( "https://example.com/\xff\xfe" ) );
		$this->assertSame( 'https://example.com/%C3%A9', Referrer::safe_url( 'https://example.com/%C3%A9' ) );
		$this->assertSame( 'https://example.com/é', Referrer::safe_url( 'https://example.com/é' ) );
	}
}
