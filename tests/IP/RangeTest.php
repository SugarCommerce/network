<?php
/**
 * Ranges of addresses, however they are written.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\Range;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class RangeTest
 */
final class RangeTest extends TestCase {

	/**
	 * What is typed, and the one spelling it is stored in.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function spellings(): array {
		return [
			'an address'                       => [ '203.0.113.9', '203.0.113.9' ],
			'an address with space round it'   => [ "  203.0.113.9\n", '203.0.113.9' ],
			'ipv6, long'                       => [ '2001:0DB8:0:0:0:0:0:7', '2001:db8::7' ],
			'ipv6 in brackets'                 => [ '[2001:db8::7]', '2001:db8::7' ],
			'ipv4 carried in ipv6'             => [ '::ffff:203.0.113.9', '203.0.113.9' ],
			'a network'                        => [ '203.0.113.0/24', '203.0.113.0/24' ],
			'a host in a network'              => [ '203.0.113.77/24', '203.0.113.0/24' ],
			'a prefix off a byte'              => [ '203.0.113.77/26', '203.0.113.64/26' ],
			'a prefix of one bit'              => [ '203.0.113.77/1', '128.0.0.0/1' ],
			'everything'                       => [ '203.0.113.77/0', '0.0.0.0/0' ],
			'one address as a range'           => [ '203.0.113.9/32', '203.0.113.9' ],
			'an ipv6 network'                  => [ '2001:db8:aa:bb::1/32', '2001:db8::/32' ],
			'an ipv6 prefix off a byte'        => [ '2001:db8:ffff::/37', '2001:db8:f800::/37' ],
			'one ipv6 address as a range'      => [ '2001:DB8::7/128', '2001:db8::7' ],
			'every ipv6 address'               => [ '2001:db8::/0', '::/0' ],
			'a mapped network'                 => [ '::ffff:203.0.113.77/24', '203.0.113.0/24' ],
			'one trailing star'                => [ '203.0.113.*', '203.0.113.0/24' ],
			'two trailing stars'               => [ '203.0.*.*', '203.0.0.0/16' ],
			'three trailing stars'             => [ '203.*.*.*', '203.0.0.0/8' ],
			'trailing star, padded numbers'    => [ '010.001.02.*', '10.1.2.0/24' ],
			'a star in the middle'             => [ '203.*.113.9', '203.*.113.9' ],
			'a star in the middle, padded'     => [ '203.*.007.09', '203.*.7.9' ],
			'stars either side'                => [ '203.*.113.*', '203.*.113.*' ],
			'a star first'                     => [ '*.0.113.9', '*.0.113.9' ],
			'an ipv6 star'                     => [ '2001:db8:*', '2001:db8::/32' ],
			'an ipv6 star, padded and upper'   => [ '2001:0DB8:00aa:*', '2001:db8:aa::/48' ],
			'ipv6 stars written out'           => [ '2001:db8:*:*:*:*:*:*', '2001:db8::/32' ],
			'an ipv6 star in the middle'       => [ '2001:*:0005', '2001:*:5' ],
			'ipv6 trailing stars left off'     => [ '2001:*:5:*:*', '2001:*:5' ],
			'an ipv6 star first'               => [ '*:db8:*:1', '*:db8:*:1' ],
			'from and to'                      => [ '203.0.113.1-203.0.113.50', '203.0.113.1-203.0.113.50' ],
			'from and to, with spaces'         => [ ' 203.0.113.1 - 203.0.113.50 ', '203.0.113.1-203.0.113.50' ],
			'from and to, backwards'           => [ '203.0.113.50-203.0.113.1', '203.0.113.1-203.0.113.50' ],
			'from and to, across a byte'       => [ '203.0.113.200-203.0.114.2', '203.0.113.200-203.0.114.2' ],
			'from and to the same address'     => [ '203.0.113.9-203.0.113.9', '203.0.113.9' ],
			'ipv6 from and to'                 => [ '2001:DB8::ff-2001:db8::1', '2001:db8::1-2001:db8::ff' ],
			'mapped from and to'               => [ '::ffff:203.0.113.1-203.0.113.50', '203.0.113.1-203.0.113.50' ],

			'nothing'                          => [ '', null ],
			'a word'                           => [ 'localhost', null ],
			'three parts'                      => [ '203.0.113', null ],
			'a part too large'                 => [ '203.0.113.256', null ],
			'a prefix too long'                => [ '203.0.113.0/33', null ],
			'an ipv6 prefix too long'          => [ '2001:db8::/129', null ],
			'a mapped prefix read as ipv6'     => [ '::ffff:203.0.113.0/120', null ],
			'a prefix that is not a number'    => [ '203.0.113.0/x', null ],
			'a prefix with a sign'             => [ '203.0.113.0/-1', null ],
			'no prefix'                        => [ '203.0.113.0/', null ],
			'a netmask'                        => [ '203.0.113.0/255.255.255.0', null ],
			'two slashes'                      => [ '203.0.113.0/24/8', null ],
			'nothing but stars'                => [ '*.*.*.*', null ],
			'one star'                         => [ '*', null ],
			'three parts and a star'           => [ '203.0.*', null ],
			'five parts'                       => [ '203.0.113.9.*', null ],
			'a star part too large'            => [ '203.*.113.256', null ],
			'half a star'                      => [ '203.0.113.1*', null ],
			'an ipv6 star in a gap'            => [ '2001:db8::*', null ],
			'nothing but ipv6 stars'           => [ '*:*', null ],
			'nine ipv6 groups'                 => [ '2001:db8:1:2:3:4:5:6:*', null ],
			'an ipv6 group too long'           => [ '2001:0db8a:*', null ],
			'half an ipv6 star'                => [ '2001:db8*', null ],
			'a star and a prefix'              => [ '203.0.113.*/24', null ],
			'from one family to the other'     => [ '203.0.113.1-2001:db8::1', null ],
			'three ends'                       => [ '203.0.113.1-203.0.113.5-203.0.113.9', null ],
			'an end missing'                   => [ '203.0.113.1-', null ],
			'an end that is not an address'    => [ '203.0.113.1-50', null ],
			'a range of ranges'                => [ '203.0.113.0/24-203.0.114.0/24', null ],
		];
	}

	/**
	 * Every way of writing a range is read, and stored one way.
	 *
	 * @param string      $typed  What was typed.
	 * @param string|null $stored What is kept.
	 */
	#[DataProvider( 'spellings' )]
	public function test_normalize( string $typed, ?string $stored ): void {
		$this->assertSame( $stored, Range::normalize( $typed ) );
		$this->assertSame( null !== $stored, Range::is_valid( $typed ) );

		// Storing what was stored changes nothing.
		if ( null !== $stored ) {
			$this->assertSame( $stored, Range::normalize( $stored ) );
		}
	}

	/**
	 * A range is more than one address.
	 */
	public function test_is_range(): void {
		foreach ( [ '203.0.113.0/24', '203.0.113.*', '203.*.113.9', '2001:db8:*', '2001:*:5', '203.0.113.1-203.0.113.50', '2001:db8::/32', '2001:db8::1-2001:db8::ff', '0.0.0.0/0' ] as $range ) {
			$this->assertTrue( Range::is_range( $range ), $range );
		}

		foreach ( [ '203.0.113.9', '203.0.113.9/32', '2001:db8::7', '2001:db8::7/128', '203.0.113.9-203.0.113.9', '', 'a-b', '*.*.*.*', '203.0.113.0/33' ] as $not ) {
			$this->assertFalse( Range::is_range( $not ), $not );
		}
	}

	/**
	 * What each range holds, and what it does not.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}>
	 */
	public static function holdings(): array {
		return [
			'a /24'                    => [ '203.0.113.0/24', [ '203.0.113.0', '203.0.113.9', '203.0.113.255', '::ffff:203.0.113.9' ], [ '203.0.112.255', '203.0.114.0', '2001:db8::1', '::203.0.113.9' ] ],
			'a /24 not on its network' => [ '203.0.113.77/24', [ '203.0.113.1' ], [ '203.0.114.77' ] ],
			'a /26'                    => [ '203.0.113.64/26', [ '203.0.113.64', '203.0.113.127' ], [ '203.0.113.63', '203.0.113.128' ] ],
			'a /31'                    => [ '203.0.113.8/31', [ '203.0.113.8', '203.0.113.9' ], [ '203.0.113.7', '203.0.113.10' ] ],
			'a /1'                     => [ '128.0.0.0/1', [ '128.0.0.0', '255.255.255.255' ], [ '127.255.255.255', '0.0.0.0' ] ],
			'everything in ipv4'       => [ '0.0.0.0/0', [ '0.0.0.0', '203.0.113.9', '255.255.255.255' ], [ '::', '2001:db8::1' ] ],
			'an ipv6 /32'              => [ '2001:db8::/32', [ '2001:db8::1', '2001:DB8:ffff:ffff:ffff:ffff:ffff:ffff', '[2001:db8::7]' ], [ '2001:db9::1', '2001:db7:ffff::', '203.0.113.9' ] ],
			'an ipv6 /37'              => [ '2001:db8:f800::/37', [ '2001:db8:f800::1', '2001:db8:ffff::1' ], [ '2001:db8:f7ff::1', '2001:db8:f000::1' ] ],
			'everything in ipv6'       => [ '::/0', [ '::', '2001:db8::1' ], [ '0.0.0.0', '203.0.113.9', '::ffff:203.0.113.9' ] ],
			'a trailing star'          => [ '203.0.113.*', [ '203.0.113.0', '203.0.113.255' ], [ '203.0.114.1', '2001:db8::1' ] ],
			'a star is one whole part' => [ '203.0.1.*', [ '203.0.1.7' ], [ '203.0.15.7', '203.0.10.7', '203.0.11.7' ] ],
			'a star in the middle'     => [ '203.*.113.9', [ '203.0.113.9', '203.255.113.9', '::ffff:203.7.113.9' ], [ '203.0.113.90', '203.0.113.8', '204.0.113.9', '203.0.11.39' ] ],
			'stars either side'        => [ '203.*.113.*', [ '203.1.113.1' ], [ '203.1.114.1' ] ],
			'a padded star pattern'    => [ '203.*.007.09', [ '203.1.7.9' ], [ '203.1.7.90' ] ],
			'an ipv6 star'             => [ '2001:db8:*', [ '2001:db8::1', '2001:DB8:ffff::', '2001:0db8:85a3::1' ], [ '2001:db9::1', '2001:db7:ffff::', '203.0.113.9', '32.1.13.184' ] ],
			'an ipv6 star, padded'     => [ '2001:0DB8:*', [ '2001:db8:85a3::1' ], [ '2001:db9::1' ] ],
			'an ipv6 star in between'  => [ '2001:*:5', [ '2001:aa:5::', '2001:ffff:5:1:2:3:4:5' ], [ '2001:aa:6::', '2002:aa:5::' ] ],
			'an ipv6 star first'       => [ '*:db8:*:1', [ '2001:db8:7:1::', 'ffff:db8:0:1::9' ], [ '2001:db8:7:2::', '2001:db9:7:1::' ] ],
			'from and to'              => [ '203.0.113.10-203.0.113.50', [ '203.0.113.10', '203.0.113.33', '203.0.113.50', '::ffff:203.0.113.20' ], [ '203.0.113.9', '203.0.113.51', '203.0.114.20', '2001:db8::1' ] ],
			'from and to, backwards'   => [ '203.0.113.50-203.0.113.10', [ '203.0.113.33' ], [ '203.0.113.51' ] ],
			'across a byte'            => [ '203.0.113.200-203.0.114.2', [ '203.0.113.255', '203.0.114.0', '203.0.114.2' ], [ '203.0.114.3', '203.0.113.199' ] ],
			'above half the family'    => [ '127.255.255.250-128.0.0.5', [ '127.255.255.255', '128.0.0.0' ], [ '128.0.0.6', '127.255.255.249' ] ],
			'ipv6 from and to'         => [ '2001:db8:aa::1-2001:db8:aa::ff', [ '2001:db8:aa::1', '2001:db8:aa::80', '2001:db8:aa::ff' ], [ '2001:db8:aa::100', '2001:db8:aa::', '203.0.113.9' ] ],
			'ipv6 ends round ipv4 bytes' => [ 'ca00::-cc00::', [ 'cb00:7109::' ], [ '203.0.113.9' ] ],
			'ipv4 ends round ipv6 bytes' => [ '32.1.13.0-32.1.13.255', [ '32.1.13.184' ], [ '2001:db8::1' ] ],
			'an ipv6 net of ipv4 bytes'  => [ 'cb00:7109::/32', [ 'cb00:7109::1' ], [ '203.0.113.9' ] ],
			'one address'              => [ '203.0.113.9', [ '203.0.113.9', '::ffff:203.0.113.9' ], [ '203.0.113.8', '2001:db8::1' ] ],
			'one ipv6 address'         => [ '2001:DB8:0:0::7', [ '2001:db8::7', '[2001:db8::7]' ], [ '2001:db8::8' ] ],
		];
	}

	/**
	 * A range holds what is in it, in its own family, and nothing else.
	 *
	 * @param string             $range   The range.
	 * @param array<int, string> $inside  Addresses it holds.
	 * @param array<int, string> $outside Addresses it does not.
	 */
	#[DataProvider( 'holdings' )]
	public function test_contains( string $range, array $inside, array $outside ): void {
		foreach ( $inside as $ip ) {
			$this->assertTrue( Range::contains( $range, $ip ), $ip . ' in ' . $range );
		}

		foreach ( $outside as $ip ) {
			$this->assertFalse( Range::contains( $range, $ip ), $ip . ' not in ' . $range );
		}
	}

	/**
	 * Nonsense on either side is a miss, never an error.
	 */
	public function test_contains_nothing_malformed(): void {
		foreach ( [ '', 'nonsense', '203.0.113.0/33', '203.0.113.0/x', '*.*', '203.0.*', 'a-b', '203.0.113.1-2001:db8::1', '2001:db8::*', '*.*.*.*', '*:*' ] as $range ) {
			$this->assertFalse( Range::contains( $range, '203.0.113.9' ), $range );
			$this->assertFalse( Range::contains( $range, '2001:db8::1' ), $range );
		}

		foreach ( [ '', 'nonsense', '203.0.113', '203.0.113.9/32', '999.1.1.1' ] as $ip ) {
			$this->assertFalse( Range::contains( '0.0.0.0/0', $ip ), $ip );
			$this->assertFalse( Range::contains( '*.*.*.9', $ip ), $ip );
		}
	}

	/**
	 * A list is held by when any of it holds.
	 */
	public function test_contains_any(): void {
		$list = [ '198.51.100.7', '203.0.113.0/24', '192.0.2.*', '10.0.0.1-10.0.0.9', '2001:db8::/32', null, 7, [ '8.8.8.8' ] ];

		foreach ( [ '198.51.100.7', '203.0.113.200', '192.0.2.1', '10.0.0.5', '2001:db8::9' ] as $ip ) {
			$this->assertTrue( Range::contains_any( $list, $ip ), $ip );
		}

		foreach ( [ '198.51.100.8', '8.8.8.8', '10.0.0.10', '2001:db9::1', '' ] as $ip ) {
			$this->assertFalse( Range::contains_any( $list, $ip ), $ip );
		}

		$this->assertFalse( Range::contains_any( [], '203.0.113.9' ) );
	}

	/**
	 * An IPv4 range is counted; an IPv6 one has no count, and says so.
	 */
	public function test_size(): void {
		$sizes = [
			'203.0.113.9'               => 1,
			'203.0.113.9/32'            => 1,
			'203.0.113.8/31'            => 2,
			'203.0.113.0/24'            => 256,
			'203.0.113.77/24'           => 256,
			'203.0.113.*'               => 256,
			'203.0.*.*'                 => 65536,
			'203.*.113.9'               => 256,
			'203.*.113.*'               => 65536,
			'*.*.*.9'                   => 16777216,
			'10.0.0.0/8'                => 16777216,
			'203.0.113.1-203.0.113.50'  => 50,
			'203.0.113.50-203.0.113.1'  => 50,
			'203.0.113.200-203.0.114.2' => 59,
			'127.255.255.250-128.0.0.5' => 12,
			'0.0.0.0-255.255.255.255'   => 4294967296,
			'0.0.0.0/0'                 => 4294967296,
			'128.0.0.0/1'               => 2147483648,
			'::ffff:203.0.113.0/24'     => 256,
		];

		foreach ( $sizes as $range => $size ) {
			$this->assertSame( $size, Range::size( (string) $range ), (string) $range );
		}

		// Not nought, and not whatever is left of 2 ** 96 in an integer.
		foreach ( [ '2001:db8::7', '2001:db8::/32', '2001:db8::/127', '::/0', '2001:db8::1-2001:db8::ff', '2001:*:5', '', 'nonsense', '203.0.113.0/33', '*.*.*.*' ] as $range ) {
			$this->assertNull( Range::size( $range ), $range );
		}
	}

	/**
	 * How wide a range is: the bits all of it shares.
	 */
	public function test_prefix(): void {
		$prefixes = [
			'203.0.113.9'                           => 32,
			'2001:db8::7'                           => 128,
			'203.0.113.0/24'                        => 24,
			'203.0.113.9/32'                        => 32,
			'0.0.0.0/0'                             => 0,
			'2001:db8::/32'                         => 32,
			'::/0'                                  => 0,
			'203.0.113.*'                           => 24,
			'203.*.*.*'                             => 8,
			'203.*.113.9'                           => 8,
			'203.0.*.9'                             => 16,
			'*.0.113.9'                             => 0,
			'2001:db8:*'                            => 32,
			'2001:db8:aa:*:5'                       => 48,
			'*:db8:*:1'                             => 0,
			'203.0.113.1-203.0.113.50'              => 26,
			'203.0.113.0-203.0.113.255'             => 24,
			'203.0.113.200-203.0.114.2'             => 22,
			'127.255.255.255-128.0.0.0'             => 0,
			'1.0.0.0-1.255.255.255'                 => 8,
			'2001:db8:aa::1-2001:db8:aa::ff'        => 120,
			'2001:db8::1-2001:db9::1'               => 31,
			'2001:db8::1-2002:db8::1'               => 14,
			'2001::-2001:ffff:ffff:ffff:ffff:ffff:ffff:ffff' => 16,
			'::1-8000::'                            => 0,
		];

		foreach ( $prefixes as $range => $prefix ) {
			$this->assertSame( $prefix, Range::prefix( (string) $range ), (string) $range );
		}

		foreach ( [ '', 'nonsense', '203.0.113.0/33', '*.*.*.*' ] as $range ) {
			$this->assertNull( Range::prefix( $range ), $range );
		}
	}

	/**
	 * The family of a range.
	 */
	public function test_version(): void {
		foreach ( [ '203.0.113.9', '203.0.113.0/24', '203.*.113.9', '203.0.113.1-203.0.113.50', '::ffff:203.0.113.0/24' ] as $range ) {
			$this->assertSame( 4, Range::version( $range ), $range );
		}

		foreach ( [ '2001:db8::7', '2001:db8::/32', '2001:db8::1-2001:db8::ff', '2001:*:5' ] as $range ) {
			$this->assertSame( 6, Range::version( $range ), $range );
		}

		$this->assertSame( 0, Range::version( 'nonsense' ) );
	}

	/**
	 * A generated sweep: a normalized range holds exactly its own addresses.
	 *
	 * Every /n in both families, checked against its first and last
	 * address and the ones either side of them.
	 */
	public function test_every_prefix_length_holds_its_own_ends(): void {
		foreach ( [ 4 => '203.0.113.77', 16 => '2001:db8:85a3:8d3:1319:8a2e:370:7348' ] as $bytes => $address ) {
			$packed = (string) inet_pton( $address );

			for ( $prefix = 1; $prefix < $bytes * 8; $prefix++ ) {
				$range = Range::normalize( $address . '/' . $prefix );

				$this->assertNotNull( $range );
				$this->assertSame( $prefix, Range::prefix( (string) $range ) );

				$mask  = str_pad( str_repeat( "\xff", intdiv( $prefix, 8 ) ) . ( $prefix % 8 ? chr( ( 0xff << ( 8 - $prefix % 8 ) ) & 0xff ) : '' ), $bytes, "\x00" );
				$first = $packed & $mask;
				$last  = $packed | ~$mask;

				$this->assertTrue( Range::contains( (string) $range, (string) inet_ntop( $first ) ), "$range first" );
				$this->assertTrue( Range::contains( (string) $range, (string) inet_ntop( $last ) ), "$range last" );
				$this->assertTrue( Range::contains( (string) $range, $address ), "$range itself" );

				// One bit beyond the prefix, flipped: the neighbouring network.
				$outside                          = $first;
				$outside[ intdiv( $prefix - 1, 8 ) ] = chr( ord( $outside[ intdiv( $prefix - 1, 8 ) ] ) ^ ( 0x80 >> ( ( $prefix - 1 ) % 8 ) ) );

				$this->assertFalse( Range::contains( (string) $range, (string) inet_ntop( $outside ) ), "$range neighbour" );

				// The same range written from its first address to its last.
				$span = inet_ntop( $first ) . '-' . inet_ntop( $last );

				$this->assertSame( $prefix, Range::prefix( $span ), $span );
				$this->assertFalse( Range::contains( $span, (string) inet_ntop( $outside ) ), "$span neighbour" );

				if ( 4 === $bytes ) {
					$this->assertSame( 2 ** ( 32 - $prefix ), Range::size( (string) $range ) );
					$this->assertSame( 2 ** ( 32 - $prefix ), Range::size( $span ) );
				}
			}
		}
	}
}
