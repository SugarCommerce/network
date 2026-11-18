<?php
/**
 * The calls that replace the old libraries' global helpers.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use SugarCommerce\Network\Email;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\Referrer;
use SugarCommerce\Network\Request;
use PHPUnit\Framework\TestCase;

/**
 * The README's "Moving from arraypress/wp-*" table, for the helpers.
 *
 * The old libraries each declared a few global functions. A global function
 * is the one thing Strauss cannot prefix, so two plugins bundling different
 * copies shared whichever loaded first -- and parse_email() in one plugin
 * handed back the other plugin's Email. This library declares none; each
 * row of the table is the static call that gives the same answer, and each
 * is checked here against what the helper promised.
 */
final class MovingTest extends TestCase {

	protected function setUp(): void {
		ru_reset_globals();
		ua_reset_server();
	}

	protected function tearDown(): void {
		ru_reset_globals();
		ua_reset_server();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * No global helper is declared.
	 */
	public function test_no_global_helper_is_declared(): void {
		$helpers = [
			'get_user_ip',
			'anonymize_ip',
			'sanitize_ip_list',
			'get_user_agent',
			'get_referrer_url',
			'get_referrer_source',
			'get_referrer_utm_params',
			'is_referrer_from_search',
			'get_referrer_search_terms',
			'parse_email',
			'sanitize_email_list',
		];

		foreach ( $helpers as $function ) {
			$this->assertFalse( function_exists( $function ), sprintf( '%s() is declared.', $function ) );
		}

		$this->assertSame( [], glob( dirname( __DIR__ ) . '/src/Functions.php' ) );
	}

	/**
	 * get_user_ip(), anonymize_ip(), sanitize_ip_list().
	 */
	public function test_the_ip_helpers(): void {
		$_SERVER['REMOTE_ADDR'] = '93.184.216.34';

		$this->assertSame( '93.184.216.34', IP::get() );
		$this->assertSame( '93.184.216.0', IP::anonymize( '93.184.216.34' ) );

		// sanitize_ip_list() returned text by default; the method returns a
		// list unless asked.
		$this->assertSame( "203.0.113.9\n198.51.100.0/24", IP::sanitize_pattern_list( "203.0.113.9\nnonsense\n198.51.100.0/24", true ) );
	}

	/**
	 * get_user_agent().
	 */
	public function test_the_agent_helper(): void {
		$this->assertSame( '', Request::agent() );

		$_SERVER['HTTP_USER_AGENT'] = 'curl/8.4.0';

		$this->assertSame( 'curl/8.4.0', Request::agent() );
	}

	/**
	 * The five referrer helpers, including the two that promised a string
	 * where the method may return null.
	 */
	public function test_the_referrer_helpers(): void {
		$url = 'https://www.google.com/search?q=felt+hats';

		$this->assertSame( 'google', Referrer::get_source( $url ) );
		$this->assertTrue( Referrer::is_search_engine( $url ) );
		$this->assertSame( 'felt hats', Referrer::get_search_terms( $url ) ?? '' );
		$this->assertSame( 'news', Referrer::get_utm_parameters( 'https://x.test/?utm_source=news' )['source'] );
		$this->assertSame(
			[ 'source', 'medium', 'campaign', 'term', 'content' ],
			array_keys( Referrer::get_utm_parameters( 'https://example.test/' ) )
		);

		$this->assertSame( '', Referrer::get() ?? '' );
		$this->assertSame( '', Referrer::get_search_terms( 'https://blog.test/' ) ?? '' );
		$this->assertSame( 'direct', Referrer::get_source() );

		$_SERVER['HTTP_REFERER'] = 'https://example.test/a';

		$this->assertSame( 'https://example.test/a', Referrer::get() ?? '' );
	}

	/**
	 * parse_email() and sanitize_email_list().
	 */
	public function test_the_email_helpers(): void {
		$this->assertSame( 'pat@example.com', (string) Email::parse( ' Pat@Example.com ' ) );
		$this->assertNull( Email::parse( 'nope' ) );
		$this->assertIsString( Email::sanitize_pattern_list( "*@example.com\n", true ) );
	}
}
