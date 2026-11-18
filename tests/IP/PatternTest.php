<?php
/**
 * Pattern validation and matching.
 *
 * These two have to agree. A pattern that is_match() honours but
 * is_valid_pattern() rejects is a rule that works until the list is saved,
 * and then silently stops existing.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class PatternTest
 */
final class PatternTest extends TestCase {

	/**
	 * Everything the matcher supports survives validation.
	 *
	 * @param string $pattern The pattern.
	 */
	#[DataProvider( 'supportedPatterns' )]
	public function test_supported_patterns_are_valid( string $pattern ): void {
		$this->assertTrue( IP::is_valid_pattern( $pattern ), "{$pattern} should be a valid pattern." );
	}

	/**
	 * And survives the sanitiser, which is where they were being lost.
	 *
	 * @param string $pattern The pattern.
	 */
	#[DataProvider( 'supportedPatterns' )]
	public function test_supported_patterns_survive_sanitising( string $pattern ): void {
		$this->assertContains( $pattern, IP::sanitize_pattern_list( $pattern ) );
	}

	/**
	 * Patterns the matcher honours.
	 *
	 * @return array
	 */
	public static function supportedPatterns(): array {
		return [
			'ipv4'          => [ '192.168.1.1' ],
			'ipv6'          => [ '2001:db8::1' ],
			'ipv4 cidr'     => [ '198.51.100.0/24' ],
			'ipv6 cidr'     => [ '2001:db8::/32' ],
			'wildcard octet' => [ '192.168.1.*' ],
			'wildcard pair' => [ '192.168.*.*' ],
			'ipv6 wildcard' => [ '2001:db8:*' ],
			'from and to'   => [ '192.168.1.1-192.168.1.50' ],
		];
	}

	/**
	 * A wildcard pattern actually matches once it is kept.
	 */
	public function test_a_kept_wildcard_still_matches(): void {
		$list = IP::sanitize_pattern_list( "192.168.*.*\n203.0.113.5" );

		$this->assertTrue( IP::is_match( '192.168.4.9', $list ) );
		$this->assertTrue( IP::is_match( '203.0.113.5', $list ) );
		$this->assertFalse( IP::is_match( '198.51.100.1', $list ) );
	}

	/**
	 * Every supported pattern matches what it describes, through the names
	 * the old engine had -- which now read ranges with Range.
	 */
	public function test_the_old_names_match_through_range(): void {
		$this->assertTrue( IP::is_match( '2001:DB8::1', [ ' 2001:db8::1 ' ] ), 'An address is compared as an address.' );
		$this->assertTrue( IP::is_match( '::ffff:203.0.113.9', [ '203.0.113.0/24' ] ) );
		$this->assertTrue( IP::is_match( '192.168.1.20', [ '192.168.1.1-192.168.1.50' ] ) );
		$this->assertTrue( IP::is_match( '2001:db8:85a3::1', [ '2001:db8:*' ] ) );
		$this->assertFalse( IP::is_match( '2001:db9::1', [ '2001:db8:*' ] ) );
		$this->assertFalse( IP::is_match( '203.0.113.9', [ '*.*.*.*', null, 7 ] ), 'A match-everything pattern matched.' );
		$this->assertFalse( IP::is_match( 'not an ip', [ '0.0.0.0/0' ] ) );

		$this->assertTrue( IP::is_in_range( '203.0.113.9', '203.0.113.0/24' ) );
		$this->assertFalse( IP::is_in_range( '203.0.113.9', '203.0.113.*' ), 'is_in_range() is for CIDR ranges.' );
		$this->assertFalse( IP::is_in_range( '32.1.13.184', '2001:db8::/32' ), 'An IPv4 address matched an IPv6 range byte for byte.' );
		$this->assertFalse( IP::is_in_range( '203.0.113.9', '203.0.113.0/33' ) );

		$this->assertTrue( IP::matches_wildcard( '203.0.113.9', '203.0.113.*' ) );
		$this->assertTrue( IP::matches_wildcard( '2001:db8::1', '2001:db8:*' ) );
		$this->assertFalse( IP::matches_wildcard( '203.0.113.9', '203.0.113.0/24' ), 'A CIDR range is not a wildcard.' );

		$this->assertTrue( IP::is_valid_range( '2001:db8::/32' ) );
		$this->assertFalse( IP::is_valid_range( '203.0.113.9' ) );
		$this->assertTrue( IP::is_valid_wildcard( '203.*.113.9' ) );
		$this->assertFalse( IP::is_valid_wildcard( '203.0.113.9' ) );
	}

	/**
	 * Nonsense is still rejected.
	 *
	 * @param string $pattern The pattern.
	 */
	#[DataProvider( 'rejectedPatterns' )]
	public function test_nonsense_is_rejected( string $pattern ): void {
		$this->assertFalse( IP::is_valid_pattern( $pattern ), "{$pattern} should not be a valid pattern." );
	}

	/**
	 * Patterns that cannot be meant.
	 *
	 * @return array
	 */
	public static function rejectedPatterns(): array {
		return [
			'empty'            => [ '' ],
			'whitespace'       => [ '   ' ],
			'words'            => [ 'localhost' ],
			'out of range'     => [ '999.999.999.999' ],
			'bad prefix'       => [ '192.168.1.0/33' ],
			'bare wildcard'    => [ '*' ],
			'all wildcards v4' => [ '*.*.*.*' ],
			'all wildcards v6' => [ '*:*' ],
			'wildcard words'   => [ 'evil*.example.com' ],
			'v6 wildcard gap'  => [ '2001:db8::*' ],
			'short v4 wildcard' => [ '192.168.*' ],
		];
	}

	/**
	 * A pattern matching everything is refused rather than silently accepted.
	 *
	 * In an allowlist it is indistinguishable from no restriction; in a
	 * blocklist it closes the store.
	 */
	public function test_a_match_everything_pattern_is_refused(): void {
		$this->assertSame( [], IP::sanitize_pattern_list( "*\n*.*.*.*" ) );
	}

	/**
	 * The sanitiser can hand back the newline form it was given.
	 */
	public function test_the_sanitiser_round_trips_as_a_string(): void {
		$this->assertSame( "192.168.*.*\n10.0.0.1", IP::sanitize_pattern_list( " 192.168.*.* \n\n10.0.0.1\nnonsense\n", true ) );
	}

	/**
	 * Duplicates collapse.
	 */
	public function test_duplicates_collapse(): void {
		$this->assertSame( [ '10.0.0.1' ], IP::sanitize_pattern_list( "10.0.0.1\n10.0.0.1" ) );
	}
}
