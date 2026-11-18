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
