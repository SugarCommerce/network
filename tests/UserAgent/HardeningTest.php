<?php
/**
 * What arrives where an agent or a hint should be.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\UserAgent;

use SugarCommerce\Network\ClientHints;
use SugarCommerce\Network\Request;
use SugarCommerce\Network\UserAgent;
use PHPUnit\Framework\TestCase;

/**
 * Class HardeningTest
 */
final class HardeningTest extends TestCase {

	protected function setUp(): void {
		ua_reset_server();
	}

	protected function tearDown(): void {
		ua_reset_server();
	}

	/**
	 * clean() trims, caps, and gives null for nothing.
	 */
	public function test_clean_trims_caps_and_gives_null_for_nothing(): void {
		$this->assertSame( 'Mozilla/5.0', UserAgent::clean( "  Mozilla/5.0 \t" ) );
		$this->assertSame( UserAgent::MAX_LENGTH, mb_strlen( (string) UserAgent::clean( str_repeat( 'é', 2000 ) ) ) );
		$this->assertTrue( mb_check_encoding( (string) UserAgent::clean( str_repeat( 'é', 2000 ) ), 'UTF-8' ) );

		foreach ( [ null, '', "   \n", [ 'x' ], 42 ] as $nothing ) {
			$this->assertNull( UserAgent::clean( $nothing ), var_export( $nothing, true ) );
		}
	}

	/**
	 * current() is clean() or ''.
	 */
	public function test_current_is_clean_or_empty(): void {
		$this->assertSame( 'curl/8', UserAgent::current( [ 'HTTP_USER_AGENT' => ' curl/8 ' ] ) );
		$this->assertSame( '', UserAgent::current( [ 'HTTP_USER_AGENT' => '   ' ] ) );
		$this->assertSame( '', UserAgent::current( [ 'HTTP_USER_AGENT' => [ 'x' ] ] ) );
		$this->assertSame( '', UserAgent::current( [] ) );
		$this->assertSame( 512, mb_strlen( UserAgent::current( [ 'HTTP_USER_AGENT' => str_repeat( 'a', 2000 ) ] ) ) );
	}

	/**
	 * The live request is read unslashed.
	 */
	public function test_current_reads_the_live_request_unslashed(): void {
		$_SERVER['HTTP_USER_AGENT'] = addslashes( 'Agent "quoted"' );

		$this->assertSame( 'Agent "quoted"', UserAgent::current() );
	}

	/**
	 * An array where the header should be is no agent, not "Array".
	 */
	public function test_request_agent_ignores_an_array(): void {
		$_SERVER['HTTP_USER_AGENT'] = [ 'Mozilla/5.0' ];

		$this->assertSame( '', Request::agent() );
		$this->assertSame( 'unknown', Request::device_type() );
	}

	/**
	 * Brands are printable, and no more than MAX_BRANDS of them.
	 */
	public function test_brands_are_sanitised_and_capped(): void {
		$header = '"<b>Evil</b>' . "\x01" . '";v="1<", ' . implode( ', ', array_map( static fn( int $i ): string => '"B' . $i . '";v="' . $i . '"', range( 1, 50 ) ) );
		$brands = ClientHints::brands( [ 'HTTP_SEC_CH_UA' => $header ] );

		$this->assertCount( ClientHints::MAX_BRANDS, $brands );

		foreach ( $brands as $brand => $version ) {
			$this->assertMatchesRegularExpression( '/^[\x20-\x7E]{1,64}$/', (string) $brand );
			$this->assertMatchesRegularExpression( '/^[\x20-\x7E]{0,64}$/', $version );
		}
	}

	/**
	 * A hint that is not a string is no hint.
	 */
	public function test_a_hint_that_is_not_a_string_is_none(): void {
		$this->assertFalse( ClientHints::available( [ 'HTTP_SEC_CH_UA' => [ '"Chromium";v="131"' ] ] ) );
		$this->assertNull( ClientHints::platform( [ 'HTTP_SEC_CH_UA_PLATFORM' => [ 'Windows' ] ] ) );
		$this->assertNull( ClientHints::is_mobile( [ 'HTTP_SEC_CH_UA_MOBILE' => [ '?1' ] ] ) );
	}

	/**
	 * The live hints are read unslashed: they arrive quoted.
	 */
	public function test_live_hints_are_read_unslashed(): void {
		$_SERVER['HTTP_SEC_CH_UA_PLATFORM'] = addslashes( '"Windows"' );

		$this->assertSame( 'Windows', ClientHints::platform() );
	}
}
