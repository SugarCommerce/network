<?php
/**
 * ClientIp, TrustedProxies and Cidr test suite.
 *
 * @package   SugarMill\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarMill\Network\Cidr;
use SugarMill\Network\ClientIp;
use SugarMill\Network\Ip;
use SugarMill\Network\TrustedProxies;

final class ClientIpTest extends TestCase {

	/* ─── CIDR matching ─────────────────────────────────────────────── */

	#[DataProvider( 'cidr_cases' )]
	public function test_cidr_containment( string $ip, string $range, bool $expected ): void {
		$this->assertSame( $expected, Cidr::contains( $ip, $range ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function cidr_cases(): array {
		return array(
			'v4 inside /8'        => array( '10.1.2.3', '10.0.0.0/8', true ),
			'v4 outside /8'       => array( '11.1.2.3', '10.0.0.0/8', false ),
			'v4 inside /24'       => array( '192.168.1.55', '192.168.1.0/24', true ),
			'v4 outside /24'      => array( '192.168.2.55', '192.168.1.0/24', false ),
			'v4 /32 self'         => array( '8.8.8.8', '8.8.8.8/32', true ),
			'v4 /32 neighbour'    => array( '8.8.8.9', '8.8.8.8/32', false ),
			'v4 /0 matches all'   => array( '1.2.3.4', '0.0.0.0/0', true ),
			'odd prefix /12 in'   => array( '172.16.0.1', '172.16.0.0/12', true ),
			'odd prefix /12 out'  => array( '172.32.0.1', '172.16.0.0/12', false ),
			'odd prefix /10 in'   => array( '100.64.0.1', '100.64.0.0/10', true ),
			'odd prefix /10 out'  => array( '100.128.0.1', '100.64.0.0/10', false ),
			'v6 inside /32'       => array( '2606:4700::1', '2606:4700::/32', true ),
			'v6 outside /32'      => array( '2001:4860::1', '2606:4700::/32', false ),
			'v6 /128 self'        => array( '::1', '::1/128', true ),
			'bare address match'  => array( '8.8.8.8', '8.8.8.8', true ),
			'bare address miss'   => array( '8.8.8.9', '8.8.8.8', false ),
			'v6 spelling variant' => array( '::1', '0:0:0:0:0:0:0:1', true ),
			// The bug that crashed wp-ip-utils: mismatched families must
			// be a miss, never an error.
			'v4 against v6 range' => array( '8.8.8.8', '::1/128', false ),
			'v6 against v4 range' => array( '::1', '10.0.0.0/8', false ),
			'garbage ip'          => array( 'nope', '10.0.0.0/8', false ),
			'garbage range'       => array( '10.0.0.1', 'nope/8', false ),
			'prefix too large'    => array( '10.0.0.1', '10.0.0.0/33', false ),
		);
	}

	public function test_mixed_family_lists_do_not_crash(): void {
		$list = array( '10.0.0.0/8', '2606:4700::/32', '192.168.1.5', '203.0.113.*' );

		$this->assertFalse( Cidr::matches_any( '8.8.8.8', $list ) );
		$this->assertTrue( Cidr::matches_any( '10.1.2.3', $list ) );
		$this->assertTrue( Cidr::matches_any( '2606:4700::1', $list ) );
		$this->assertTrue( Cidr::matches_any( '192.168.1.5', $list ) );
		$this->assertTrue( Cidr::matches_any( '203.0.113.9', $list ) );
	}

	/* ─── Wildcards ─────────────────────────────────────────────────── */

	public function test_wildcards_match_per_label(): void {
		$this->assertTrue( Cidr::matches( '192.168.1.5', '192.168.1.*' ) );
		$this->assertTrue( Cidr::matches( '192.168.9.5', '192.168.*.*' ) );

		// The trap a naive string prefix comparison falls into.
		$this->assertFalse( Cidr::matches( '192.168.15.5', '192.168.1.*' ) );
	}

	public function test_wildcards_are_refused_for_ipv6(): void {
		$this->assertFalse( Cidr::matches( '2606:4700::1', '2606:4700:*' ) );
	}

	public function test_pattern_lists_are_filtered(): void {
		$this->assertSame(
			array( '10.0.0.0/8', '192.168.1.*', '8.8.8.8' ),
			Cidr::filter_valid( "10.0.0.0/8\nnot-an-ip\n192.168.1.*\n\n8.8.8.8\n999.1.1.1/8" )
		);
	}

	/* ─── Trusted proxies ───────────────────────────────────────────── */

	public function test_cloudflare_ranges_are_recognised(): void {
		$proxies = TrustedProxies::cloudflare();

		$this->assertTrue( $proxies->trusts( '104.16.0.1' ) );
		$this->assertTrue( $proxies->trusts( '2606:4700::1' ) );
		$this->assertFalse( $proxies->trusts( '8.8.8.8' ) );
	}

	public function test_none_trusts_nothing(): void {
		$this->assertFalse( TrustedProxies::none()->trusts( '127.0.0.1' ) );
		$this->assertFalse( TrustedProxies::none()->trusts( '104.16.0.1' ) );
	}

	public function test_local_trusts_private_networks_only(): void {
		$proxies = TrustedProxies::local();

		$this->assertTrue( $proxies->trusts( '127.0.0.1' ) );
		$this->assertTrue( $proxies->trusts( '10.0.0.1' ) );
		$this->assertFalse( $proxies->trusts( '104.16.0.1' ) );
	}

	/* ─── Spoofing ──────────────────────────────────────────────────── */

	/**
	 * The whole point of the class. Every one of these headers is
	 * attacker-supplied, so from an untrusted origin they must be
	 * ignored entirely.
	 */
	#[DataProvider( 'forwarding_headers' )]
	public function test_forwarding_headers_are_ignored_from_untrusted_origins( string $header ): void {
		$resolver = new ClientIp( TrustedProxies::cloudflare() );

		$this->assertSame(
			'8.8.8.8',
			$resolver->resolve( array( 'REMOTE_ADDR' => '8.8.8.8', $header => '1.2.3.4' ) )
		);
	}

	/** @return array<string, array{0: string}> */
	public static function forwarding_headers(): array {
		return array(
			'CF-Connecting-IP' => array( 'HTTP_CF_CONNECTING_IP' ),
			'X-Forwarded-For'  => array( 'HTTP_X_FORWARDED_FOR' ),
			'X-Real-IP'        => array( 'HTTP_X_REAL_IP' ),
		);
	}

	public function test_no_proxies_configured_means_no_headers_believed(): void {
		$resolver = new ClientIp();

		$this->assertSame(
			'8.8.8.8',
			$resolver->resolve( array( 'REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ) )
		);
	}

	/* ─── Legitimate proxying ───────────────────────────────────────── */

	public function test_cloudflare_header_is_honoured_from_cloudflare(): void {
		$resolver = new ClientIp( TrustedProxies::cloudflare() );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '93.184.216.34' ) )
		);
	}

	public function test_local_proxy_header_is_honoured(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ) )
		);
	}

	/**
	 * X-Forwarded-For grows left to right, so the rightmost non-proxy
	 * entry is the real client. The leftmost is whatever the caller
	 * chose to send.
	 */
	public function test_forwarded_for_is_read_from_the_right(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array(
				'REMOTE_ADDR'          => '127.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 93.184.216.34, 10.0.0.5',
			) )
		);
	}

	public function test_ports_are_stripped(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34:51234' ) )
		);
	}

	public function test_bracketed_ipv6_with_a_port_is_parsed(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'2001:4860:4860::8888',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '[2001:4860:4860::8888]:443' ) )
		);
	}

	public function test_bare_ipv6_is_not_mistaken_for_a_port(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'2001:4860:4860::8888',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '2001:4860:4860::8888' ) )
		);
	}

	/* ─── Degenerate input ──────────────────────────────────────────── */

	public function test_missing_remote_addr_returns_null(): void {
		$this->assertNull( ( new ClientIp() )->resolve( array() ) );
	}

	public function test_garbage_remote_addr_returns_null(): void {
		$this->assertNull( ( new ClientIp() )->resolve( array( 'REMOTE_ADDR' => 'not-an-ip' ) ) );
	}

	public function test_unusable_header_falls_back_to_remote_addr(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'127.0.0.1',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'garbage, junk' ) )
		);
	}

	/* ─── Address classification ────────────────────────────────────── */

	public function test_private_classification(): void {
		$this->assertTrue( Ip::is_private( '10.0.0.1' ) );
		$this->assertTrue( Ip::is_private( '127.0.0.1' ) );
		$this->assertTrue( Ip::is_private( '100.64.0.1' ), 'CGNAT' );
		$this->assertTrue( Ip::is_private( 'nonsense' ) );
		$this->assertFalse( Ip::is_private( '8.8.8.8' ) );
		$this->assertFalse( Ip::is_private( '2606:4700::1' ) );
	}

	public function test_version_detection(): void {
		$this->assertSame( 4, Ip::version( '8.8.8.8' ) );
		$this->assertSame( 6, Ip::version( '::1' ) );
		$this->assertSame( 0, Ip::version( 'nope' ) );
	}

	/* ─── Anonymisation ─────────────────────────────────────────────── */

	public function test_ipv4_drops_the_last_octet(): void {
		$this->assertSame( '192.168.1.0', Ip::anonymize( '192.168.1.55' ) );
		$this->assertSame( '8.8.8.0', Ip::anonymize( '8.8.8.8' ) );
	}

	public function test_ipv6_keeps_only_the_first_48_bits(): void {
		$this->assertSame( '2606:4700:4700::', Ip::anonymize( '2606:4700:4700::1111' ) );
	}

	public function test_anonymising_nonsense_returns_empty(): void {
		$this->assertSame( '', Ip::anonymize( 'not-an-ip' ) );
	}

	public function test_hashing_is_stable_and_keyed(): void {
		$a = Ip::hash( '8.8.8.8', 'secret-one' );
		$b = Ip::hash( '8.8.8.8', 'secret-one' );
		$c = Ip::hash( '8.8.8.8', 'secret-two' );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertSame( 64, strlen( $a ) );
	}

	public function test_hashing_normalises_equivalent_spellings(): void {
		$this->assertSame(
			Ip::hash( '::1', 'secret' ),
			Ip::hash( '0:0:0:0:0:0:0:1', 'secret' )
		);
	}

	public function test_hashing_without_a_secret_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Ip::hash( '8.8.8.8', '' );
	}
}
