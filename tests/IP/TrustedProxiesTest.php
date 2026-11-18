<?php
/**
 * Which connections are believed, and for which headers.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * Class TrustedProxiesTest
 */
final class TrustedProxiesTest extends TestCase {

	/**
	 * Out of the box nobody is a proxy.
	 */
	public function test_none_trusts_nobody(): void {
		$none = TrustedProxies::none();

		foreach ( [ '127.0.0.1', '10.0.0.5', '173.245.48.9', '203.0.113.9', '::1', '' ] as $ip ) {
			$this->assertFalse( $none->trusts( $ip ), $ip );
		}

		$this->assertSame( [], $none->ranges );
		$this->assertSame( [ 'X-Forwarded-For' ], $none->headers );
		$this->assertFalse( $none->any_address );
	}

	/**
	 * Cloudflare's addresses are Cloudflare, in both families.
	 */
	public function test_cloudflare(): void {
		$cloudflare = TrustedProxies::cloudflare();

		foreach ( [ '173.245.48.9', '104.16.0.1', '172.64.0.1', '2606:4700::1111', '2a06:98c7::1', '::ffff:173.245.48.9' ] as $ip ) {
			$this->assertTrue( $cloudflare->trusts( $ip ), $ip );
		}

		foreach ( [ '203.0.113.9', '10.0.0.5', '127.0.0.1', '172.63.255.255', '2606:4701::1', '' ] as $ip ) {
			$this->assertFalse( $cloudflare->trusts( $ip ), $ip );
		}

		$this->assertSame( [ 'CF-Connecting-IP', 'X-Forwarded-For' ], $cloudflare->headers );
		$this->assertSame( TrustedProxies::CLOUDFLARE, $cloudflare->ranges );
	}

	/**
	 * A fresher list replaces the bundled one and does not add to it.
	 */
	public function test_cloudflares_list_can_be_replaced(): void {
		$fresh = TrustedProxies::cloudflare( [ '192.0.2.0/24' ] );

		$this->assertTrue( $fresh->trusts( '192.0.2.200' ) );
		$this->assertFalse( $fresh->trusts( '173.245.48.9' ) );
		$this->assertSame( [ 'CF-Connecting-IP', 'X-Forwarded-For' ], $fresh->headers );

		// An emptied list is no list, and believes nothing.
		$this->assertFalse( TrustedProxies::cloudflare( [] )->trusts( '173.245.48.9' ) );
	}

	/**
	 * This machine and private networks, with all of RFC 4193.
	 */
	public function test_local(): void {
		$local = TrustedProxies::local();

		foreach ( [ '127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.4', '::1', 'fd00::5', 'fc00::5', '::ffff:10.0.0.5' ] as $ip ) {
			$this->assertTrue( $local->trusts( $ip ), $ip );
		}

		foreach ( [ '203.0.113.9', '173.245.48.9', '172.32.0.1', 'fe80::1', '2001:db8::1' ] as $ip ) {
			$this->assertFalse( $local->trusts( $ip ), $ip );
		}

		$this->assertSame( [ 'X-Forwarded-For' ], $local->headers );
	}

	/**
	 * More proxies are added to a copy, in any way a range is written.
	 */
	public function test_and_also(): void {
		$none = TrustedProxies::none();
		$more = $none->and_also( [ '198.51.100.7', '192.0.2.0/24', '203.0.113.*', '100.64.0.1-100.64.0.9', ' ', null, [ 'x' ], '198.51.100.7' ] );

		$this->assertNotSame( $none, $more );
		$this->assertSame( [], $none->ranges );
		$this->assertSame( [ '198.51.100.7', '192.0.2.0/24', '203.0.113.*', '100.64.0.1-100.64.0.9' ], $more->ranges );

		foreach ( [ '198.51.100.7', '192.0.2.200', '203.0.113.9', '100.64.0.5' ] as $ip ) {
			$this->assertTrue( $more->trusts( $ip ), $ip );
		}

		$this->assertFalse( $more->trusts( '198.51.100.8' ) );
		$this->assertFalse( $more->trusts( '100.64.0.10' ) );
	}

	/**
	 * Cloudflare's header is believed only while the list is Cloudflare's.
	 *
	 * A proxy of your own passes CF-Connecting-IP through from whoever
	 * reached it, so adding one stops the header being read.
	 */
	public function test_adding_a_proxy_stops_cloudflares_header_being_read(): void {
		$mixed = TrustedProxies::cloudflare()->and_also( [ '10.0.0.0/8' ] );

		$this->assertSame( [ 'X-Forwarded-For' ], $mixed->headers );
		$this->assertTrue( $mixed->trusts( '173.245.48.9' ) );
		$this->assertTrue( $mixed->trusts( '10.0.0.5' ) );

		// Named on purpose, and left with nothing else, it falls back to the safe one.
		$only = TrustedProxies::cloudflare()->using_headers( [ 'CF-Connecting-IP' ] )->and_also( [ '10.0.0.0/8' ] );

		$this->assertSame( [ 'X-Forwarded-For' ], $only->headers );

		// Named last, it is the caller's decision.
		$chosen = TrustedProxies::cloudflare()->and_also( [ '10.0.0.0/8' ] )->using_headers( [ 'CF-Connecting-IP' ] );

		$this->assertSame( [ 'CF-Connecting-IP' ], $chosen->headers );

		// Other headers are kept.
		$this->assertSame( [ 'X-Real-IP' ], TrustedProxies::local()->using_headers( [ 'X-Real-IP' ] )->and_also( [ '198.51.100.7' ] )->headers );
	}

	/**
	 * The headers are the ones named, cleaned, in order.
	 */
	public function test_using_headers(): void {
		$local = TrustedProxies::local();
		$named = $local->using_headers( [ ' X-Real-IP: ', 'HTTP_X_CLIENT', '', '<b>', 'X-Real-IP', null ] );

		$this->assertSame( [ 'X-Forwarded-For' ], $local->headers );
		$this->assertSame( [ 'X-Real-IP', 'X-CLIENT', 'b' ], $named->headers );
		$this->assertSame( $local->ranges, $named->ranges );

		$this->assertSame( [], $local->using_headers( [] )->headers );
	}

	/**
	 * What is typed for a header's name is made into a header's name.
	 */
	public function test_header_name(): void {
		$this->assertSame( 'X-Real-IP', TrustedProxies::header_name( ' X-Real-IP: ' ) );
		$this->assertSame( 'X-REAL-IP', TrustedProxies::header_name( 'HTTP_X_REAL_IP' ) );
		$this->assertSame( 'X-real-ip', TrustedProxies::header_name( 'http_X_real_ip' ) );
		$this->assertSame( 'XForwardedFor', TrustedProxies::header_name( 'X Forwarded_For' ) );
		$this->assertSame( 'scriptalert1script', TrustedProxies::header_name( '<script>alert(1)</script>' ) );
		$this->assertSame( '', TrustedProxies::header_name( [ 'x' ] ) );
		$this->assertSame( '', TrustedProxies::header_name( null ) );
		$this->assertSame( '', TrustedProxies::header_name( ' :: ' ) );
		$this->assertSame( 64, strlen( TrustedProxies::header_name( str_repeat( 'a', 200 ) ) ) );
	}

	/**
	 * Reading from any address is a copy with the same list.
	 */
	public function test_from_any_address(): void {
		$local = TrustedProxies::local();
		$any   = $local->from_any_address();

		$this->assertFalse( $local->any_address );
		$this->assertTrue( $any->any_address );
		$this->assertSame( $local->ranges, $any->ranges );

		// Which addresses are proxies is unchanged: only whether the connection is asked.
		$this->assertFalse( $any->trusts( '203.0.113.9' ) );
		$this->assertTrue( $any->and_also( [ '198.51.100.7' ] )->using_headers( [ 'X-Real-IP' ] )->any_address );
	}
}
