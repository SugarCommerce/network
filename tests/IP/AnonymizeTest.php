<?php
/**
 * Anonymized addresses: the same answer as WordPress's privacy tools.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use SugarCommerce\Network\IPs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Class AnonymizeTest
 */
final class AnonymizeTest extends TestCase {

	/**
	 * Without WordPress loaded, the answer is core's
	 * wp_privacy_anonymize_ip() -- each expected value below is what core
	 * 7.1 returns for the same input.
	 *
	 * @param string $ip       An address.
	 * @param string $expected Core's answer.
	 */
	#[DataProvider( 'core_answers' )]
	public function test_it_answers_as_core_does( string $ip, string $expected ): void {
		$this->assertSame( $expected, IP::anonymize( $ip ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function core_answers(): array {
		return [
			'IPv4 loses its last octet' => [ '93.184.216.34', '93.184.216.0' ],
			'IPv6 keeps its /64'        => [ '2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3::' ],
			'IPv6 is lower-cased'       => [ '2001:DB8::1', '2001:db8::' ],
			'loopback'                  => [ '::1', '::' ],
			'all zeros'                 => [ '::', '::' ],
			'link-local'                => [ 'fe80::1', 'fe80::' ],
			'IPv4-mapped'               => [ '::ffff:203.0.113.42', '::ffff:203.0.113.0' ],
			'already a /64'             => [ '2001:db8:85a3:8d3::', '2001:db8:85a3:8d3::' ],
		];
	}

	/**
	 * Zeroing only the last group kept a /112 -- one household.
	 */
	public function test_an_ipv6_address_keeps_no_more_than_its_network(): void {
		$this->assertNotSame( '2001:db8:85a3:8d3:1319:8a2e:370:0', IP::anonymize( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ) );
	}

	/**
	 * Not an address: null, as before (core would say "0.0.0.0").
	 */
	public function test_an_invalid_address_is_null(): void {
		$this->assertNull( IP::anonymize( 'not-an-ip' ) );
		$this->assertNull( IP::anonymize( '' ) );
		$this->assertSame( [ '203.0.113.0', '2001:db8::' ], IPs::anonymize( [ '203.0.113.9', 'nonsense', '2001:db8::5' ] ) );
	}

	/**
	 * With WordPress loaded, core answers, so a filter or a future change
	 * in core applies here too.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_it_hands_over_to_core_when_wordpress_is_loaded(): void {
		require_once __DIR__ . '/stubs/wp-privacy-anonymize-ip.php';

		$this->assertSame( 'core:203.0.113.42', IP::anonymize( '203.0.113.42' ) );
		$this->assertNull( IP::anonymize( 'nonsense' ) );
	}
}
