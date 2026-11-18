<?php
/**
 * Public addresses, anonymised networks and keyed hashes.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class PublicTest
 */
final class PublicTest extends TestCase {

	/**
	 * Every one of these reaches something that is not the public
	 * internet, and PHP's flags call each of them public.
	 *
	 * @param string $ip An address.
	 */
	#[DataProvider( 'non_public' )]
	public function test_non_public_addresses_are_not_public( string $ip ): void {
		$this->assertFalse( IP::is_public( $ip ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function non_public(): array {
		return [
			'NAT64 loopback'    => [ '64:ff9b::7f00:1' ],
			'NAT64 metadata'    => [ '64:ff9b::a9fe:a9fe' ],
			'NAT64 local-use'   => [ '64:ff9b:1::808:808' ],
			'6to4 loopback'     => [ '2002:7f00:1::' ],
			'6to4 private'      => [ '2002:a00:1::1' ],
			'mapped loopback'   => [ '::ffff:127.0.0.1' ],
			'Teredo'            => [ '2001::1' ],
			'IPv6 docs'         => [ '2001:db8::1' ],
			'IPv6 multicast'    => [ 'ff02::1' ],
			'IPv6 unique local' => [ 'fd00::1' ],
			'IPv6 link-local'   => [ 'fe80::1' ],
			'IETF protocol'     => [ '192.0.0.1' ],
			'TEST-NET-1'        => [ '192.0.2.1' ],
			'TEST-NET-2'        => [ '198.51.100.1' ],
			'TEST-NET-3'        => [ '203.0.113.1' ],
			'benchmarking'      => [ '198.18.0.1' ],
			'carrier NAT'       => [ '100.64.0.1' ],
			'multicast'         => [ '224.0.0.1' ],
			'class E'           => [ '240.0.0.1' ],
			'this network'      => [ '0.1.2.3' ],
			'RFC 1918'          => [ '10.0.0.1' ],
			'loopback'          => [ '127.0.0.1' ],
			'IPv6 loopback'     => [ '::1' ],
			'unspecified'       => [ '::' ],
			'not an address'    => [ 'nonsense' ],
			'empty'             => [ '' ],
		];
	}

	/**
	 * Public addresses, including inside the transition prefixes.
	 */
	public function test_public_addresses_are_public(): void {
		foreach ( [ '8.8.8.8', '2606:4700::1', '64:ff9b::808:808', '2002:808:808::', '::ffff:8.8.8.8', '[2606:4700::1]' ] as $ip ) {
			$this->assertTrue( IP::is_public( $ip ), $ip );
		}
	}

	/**
	 * is_private() keeps PHP's reading: narrower than ! is_public().
	 */
	public function test_is_private_is_unchanged(): void {
		$this->assertFalse( IP::is_private( '64:ff9b::7f00:1' ) );
		$this->assertFalse( IP::is_private( '100.64.0.1' ) );
		$this->assertTrue( IP::is_private( '10.0.0.1' ) );
	}

	/**
	 * The anonymised network says how big it is.
	 *
	 * @param string      $ip       An address.
	 * @param string|null $expected Wanted.
	 */
	#[DataProvider( 'networks' )]
	public function test_an_anonymised_network_says_its_size( string $ip, ?string $expected ): void {
		$this->assertSame( $expected, IP::anonymized_cidr( $ip ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function networks(): array {
		return [
			'v4'                   => [ '203.0.113.42', '203.0.113.0/24' ],
			'v4 already anonymous' => [ '203.0.113.0', '203.0.113.0/24' ],
			'v6'                   => [ '2001:db8:85a3:1234::1', '2001:db8:85a3::/48' ],
			'v6 bracketed'         => [ '[2001:db8::1]', '2001:db8::/48' ],
			'v6 spelt long'        => [ '2001:0DB8:0000:FFFF:0:0:0:1', '2001:db8::/48' ],
			'v4 mapped'            => [ '::ffff:203.0.113.42', '203.0.113.0/24' ],
			'not an address'       => [ 'nope', null ],
			'empty'                => [ '', null ],
		];
	}

	/**
	 * A keyed hash: stable, keyed, one per address however spelt.
	 */
	public function test_the_hash_is_keyed_and_canonical(): void {
		$a = IP::hash( '8.8.8.8', 'secret-one' );

		$this->assertSame( $a, IP::hash( '8.8.8.8', 'secret-one' ) );
		$this->assertSame( hash_hmac( 'sha256', '8.8.8.8', 'secret-one' ), $a );
		$this->assertNotSame( $a, IP::hash( '8.8.8.8', 'secret-two' ) );
		$this->assertNotSame( $a, IP::hash( '8.8.4.4', 'secret-one' ) );
		$this->assertSame( 64, strlen( $a ) );
		$this->assertSame( IP::hash( '::1', 'secret' ), IP::hash( '0:0:0:0:0:0:0:1', 'secret' ) );
		$this->assertSame( IP::hash( '203.0.113.9', 'secret' ), IP::hash( '::ffff:203.0.113.9', 'secret' ) );
	}

	/**
	 * Without a secret the hash is a lookup table away.
	 */
	public function test_the_hash_refuses_an_empty_secret(): void {
		$this->expectException( \InvalidArgumentException::class );

		IP::hash( '8.8.8.8', '' );
	}

	/**
	 * A pasted line of comma-separated addresses is that many patterns.
	 */
	public function test_a_comma_separated_list_is_split(): void {
		$this->assertSame(
			[ '203.0.113.9', '198.51.100.0/24', '192.0.2.*' ],
			IP::sanitize_pattern_list( "203.0.113.9, 198.51.100.0/24\r\n192.0.2.*,,nonsense" )
		);
		$this->assertSame( "203.0.113.9\n198.51.100.7", IP::sanitize_pattern_list( '203.0.113.9,198.51.100.7', true ) );
	}
}
