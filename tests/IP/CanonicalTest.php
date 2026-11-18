<?php
/**
 * One spelling for an address, and what kind of address it is.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\Host;
use SugarCommerce\Network\IP;
use PHPUnit\Framework\TestCase;

/**
 * Class CanonicalTest
 */
final class CanonicalTest extends TestCase {

	/**
	 * The same address typed several ways is one address.
	 */
	public function test_an_address_has_one_spelling(): void {
		$spellings = [
			'203.0.113.9'             => '203.0.113.9',
			" 203.0.113.9\n"          => '203.0.113.9',
			'2001:DB8:0:0:0:0:0:1'    => '2001:db8::1',
			'2001:0db8::0001'         => '2001:db8::1',
			'[2001:db8::1]'           => '2001:db8::1',
			' [2001:db8::1] '         => '2001:db8::1',
			'0:0:0:0:0:0:0:1'         => '::1',
			'::ffff:203.0.113.9'      => '203.0.113.9',
			'::FFFF:CB00:7109'        => '203.0.113.9',
			'[::ffff:203.0.113.9]'    => '203.0.113.9',
			'0:0:0:0:0:ffff:7f00:1'   => '127.0.0.1',
		];

		foreach ( $spellings as $typed => $canonical ) {
			$this->assertSame( $canonical, IP::canonical( $typed ), $typed );
		}
	}

	/**
	 * What is not an address has no spelling.
	 */
	public function test_what_is_not_an_address_is_null(): void {
		foreach ( [ '', ' ', '203.0.113', '203.0.113.256', '007.0.0.1', '203.0.113.9:80', '[2001:db8::1]:443', '203.0.113.0/24', '203.0.113.*', 'fe80::1%eth0', 'localhost', '[ ::1 ]', '2001:db8::g' ] as $typed ) {
			$this->assertNull( IP::canonical( $typed ), $typed );
			$this->assertNull( IP::pack( $typed ), $typed );
			$this->assertSame( 0, IP::version( $typed ), $typed );
		}
	}

	/**
	 * Packed, an address is four bytes or sixteen, and a mapped one is four.
	 */
	public function test_packed_addresses(): void {
		$this->assertSame( "\xcb\x00\x71\x09", IP::pack( '203.0.113.9' ) );
		$this->assertSame( "\xcb\x00\x71\x09", IP::pack( '::ffff:203.0.113.9' ) );
		$this->assertSame( 16, strlen( (string) IP::pack( '2001:db8::1' ) ) );
	}

	/**
	 * The family, with a mapped address counted as the IPv4 one it is.
	 */
	public function test_version(): void {
		$this->assertSame( 4, IP::version( '203.0.113.9' ) );
		$this->assertSame( 4, IP::version( '::ffff:203.0.113.9' ) );
		$this->assertSame( 6, IP::version( '2001:db8::1' ) );
		$this->assertSame( 6, IP::version( '[::1]' ) );
	}

	/**
	 * This machine, in both families and every spelling.
	 */
	public function test_loopback(): void {
		foreach ( [ '127.0.0.1', '127.255.255.254', '::1', '0:0:0:0:0:0:0:1', '[::1]', '::ffff:127.0.0.1' ] as $ip ) {
			$this->assertTrue( IP::is_loopback( $ip ), $ip );
		}

		foreach ( [ '126.255.255.255', '128.0.0.1', '::2', '::', '0.0.0.0', '10.0.0.1', '7f00::1', 'nonsense', '' ] as $ip ) {
			$this->assertFalse( IP::is_loopback( $ip ), $ip );
		}
	}

	/**
	 * The address that means no address.
	 */
	public function test_unspecified(): void {
		foreach ( [ '0.0.0.0', '::', '0:0:0:0:0:0:0:0', '[::]', '::ffff:0.0.0.0' ] as $ip ) {
			$this->assertTrue( IP::is_unspecified( $ip ), $ip );
		}

		foreach ( [ '0.0.0.1', '::1', '127.0.0.1', 'nonsense', '' ] as $ip ) {
			$this->assertFalse( IP::is_unspecified( $ip ), $ip );
		}
	}

	/**
	 * Private networks and this machine: RFC 1918, RFC 4193, loopback.
	 */
	public function test_local(): void {
		foreach ( [ '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.4', '127.0.0.1', '::1', 'fd12:3456::1', 'fc00::1', '::ffff:10.0.0.5' ] as $ip ) {
			$this->assertTrue( IP::is_local( $ip ), $ip );
		}

		// Link-local and the reserved blocks are is_private()'s, not this.
		foreach ( [ '172.32.0.1', '11.0.0.1', '192.169.0.1', '169.254.1.1', 'fe80::1', '203.0.113.9', '2001:db8::1', 'fb00::1', 'fe00::1', '' ] as $ip ) {
			$this->assertFalse( IP::is_local( $ip ), $ip );
		}

		$this->assertTrue( IP::is_private( '169.254.1.1' ) );
	}

	/**
	 * A port after an address is not part of it.
	 */
	public function test_without_port(): void {
		$this->assertSame( '203.0.113.9', Host::without_port( '203.0.113.9:51234' ) );
		$this->assertSame( '203.0.113.9', Host::without_port( ' 203.0.113.9 ' ) );
		$this->assertSame( '2001:db8::1', Host::without_port( '[2001:db8::1]:443' ) );
		$this->assertSame( '2001:db8::1', Host::without_port( '[2001:db8::1]' ) );

		// A bare IPv6 address is full of colons and has no port.
		$this->assertSame( '2001:db8::1', Host::without_port( '2001:db8::1' ) );
		$this->assertSame( '[2001:db8::1', Host::without_port( '[2001:db8::1' ) );
	}
}
