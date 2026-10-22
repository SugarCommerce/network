<?php
/**
 * Regression tests for the 1.3.0 security review.
 *
 * Each test here failed before the fix it covers.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\Cidr;
use SugarCommerce\Network\ClientHints;
use SugarCommerce\Network\ClientIp;
use SugarCommerce\Network\Cloudflare;
use SugarCommerce\Network\Host;
use SugarCommerce\Network\Ip;
use SugarCommerce\Network\Referrer;
use SugarCommerce\Network\TrustedProxies;
use SugarCommerce\Network\UserAgent;

final class HardeningTest extends TestCase {

	/* ─── Which headers are believed ────────────────────────────────── */

	/**
	 * Your own nginx at 10.0.0.5 passes `CF-Connecting-IP` through from
	 * the client untouched. With the old default header list that header
	 * was read first, so any visitor chose their own address.
	 */
	public function test_a_custom_proxy_does_not_believe_cf_connecting_ip(): void {
		$resolver = new ClientIp( new TrustedProxies( array( '10.0.0.5' ) ) );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array(
				'REMOTE_ADDR'           => '10.0.0.5',
				'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
				'HTTP_X_REAL_IP'        => '1.2.3.4',
				'HTTP_X_FORWARDED_FOR'  => '93.184.216.34',
			) )
		);
	}

	/**
	 * Cloudflare in front of your balancer: somebody who reaches the
	 * balancer directly sends their own `CF-Connecting-IP`, and the
	 * balancer is trusted. Only the appended chain is right.
	 */
	public function test_cloudflare_with_extra_ranges_reads_only_the_forwarded_chain(): void {
		$resolver = new ClientIp( TrustedProxies::cloudflare( array( '10.0.0.0/8' ) ) );

		$this->assertSame(
			'198.51.100.7',
			$resolver->resolve( array(
				'REMOTE_ADDR'           => '10.0.0.5',
				'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
				'HTTP_X_FORWARDED_FOR'  => '1.2.3.4, 198.51.100.7',
			) )
		);
	}

	public function test_cloudflare_alone_still_reads_cf_connecting_ip(): void {
		$this->assertSame(
			array( 'CF-Connecting-IP', 'X-Forwarded-For' ),
			TrustedProxies::cloudflare()->headers
		);
	}

	/**
	 * The README's "behind your own load balancer" line read no header at
	 * all, because `none()` carried an empty header list into the copy.
	 */
	public function test_none_and_also_reads_the_forwarded_chain(): void {
		$resolver = new ClientIp( TrustedProxies::none()->and_also( '10.0.0.0/8' ) );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '93.184.216.34' ) )
		);
		$this->assertFalse( TrustedProxies::none()->trusts( '10.0.0.5' ) );
	}

	/**
	 * A proxy that cannot tell appends `unknown`. Stepping over it walked
	 * into the part of the chain the client wrote.
	 */
	public function test_an_unparseable_hop_ends_the_walk(): void {
		$resolver = new ClientIp( TrustedProxies::local() );

		$this->assertSame(
			'127.0.0.1',
			$resolver->resolve( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, unknown' ) )
		);
	}

	public function test_addresses_come_back_canonical(): void {
		$resolver = new ClientIp();

		$this->assertSame( '2001:db8::1', $resolver->resolve( array( 'REMOTE_ADDR' => '2001:DB8:0:0:0:0:0:1' ) ) );
		$this->assertSame( '203.0.113.5', $resolver->resolve( array( 'REMOTE_ADDR' => '::ffff:203.0.113.5' ) ) );
	}

	public function test_a_dual_stack_cloudflare_address_is_trusted(): void {
		$resolver = new ClientIp( TrustedProxies::cloudflare() );

		$this->assertSame(
			'93.184.216.34',
			$resolver->resolve( array( 'REMOTE_ADDR' => '::ffff:104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '93.184.216.34' ) )
		);
	}

	public function test_non_string_server_values_are_ignored(): void {
		$this->assertNull( ( new ClientIp() )->resolve( array( 'REMOTE_ADDR' => array( '1.2.3.4' ) ) ) );
		$this->assertFalse( ( new Cloudflare() )->is_active( array( 'REMOTE_ADDR' => 104 ) ) );
		$this->assertSame( '', UserAgent::current( array( 'HTTP_USER_AGENT' => array( 'x' ) ) ) );
		$this->assertFalse( ClientHints::available( array( 'HTTP_SEC_CH_UA' => array( 'x' ) ) ) );
	}

	/* ─── Cloudflare ────────────────────────────────────────────────── */

	public function test_cf_visitor_is_reduced_to_a_token(): void {
		$cloudflare = new Cloudflare();
		$server     = array( 'REMOTE_ADDR' => '104.16.0.1' );

		$this->assertSame( 'https', $cloudflare->tls_version( $server + array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ) );
		$this->assertNull( $cloudflare->tls_version( $server + array( 'HTTP_CF_VISITOR' => '{"scheme":"<script>"}' ) ) );
		$this->assertNull( $cloudflare->tls_version( $server + array( 'HTTP_CF_VISITOR' => '{"scheme":"https","tls":["x"]}' ) ) );
	}

	/* ─── A newline on the end ──────────────────────────────────────── */

	/**
	 * `$` also matches just before a final newline. Each of these got past
	 * a check anchored with it, by putting the newline somewhere a trim()
	 * does not reach: before a trailing dot, after a slash, inside JSON.
	 */
	public function test_a_typed_domain_ending_in_a_newline_is_refused(): void {
		$this->assertSame( '', Host::normalize( "shop.example.com\n." ) );
	}

	public function test_a_prefix_ending_in_a_newline_is_refused(): void {
		$this->assertFalse( Cidr::is_valid( "10.0.0.0/8\n" ) );
		$this->assertFalse( Cidr::contains( '10.1.2.3', "10.0.0.0/8\n" ) );
		$this->assertTrue( Cidr::contains( '10.1.2.3', '10.0.0.0/8' ) );
	}

	public function test_cf_visitor_ending_in_a_newline_is_refused(): void {
		$server = array( 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_VISITOR' => '{"scheme":"https\n"}' );

		$this->assertNull( ( new Cloudflare() )->tls_version( $server ) );
	}

	/* ─── Private addresses ─────────────────────────────────────────── */

	/**
	 * Every one of these reaches something that is not the public
	 * internet, and every one used to be called public.
	 */
	#[DataProvider( 'non_public' )]
	public function test_non_public_addresses_are_private( string $ip ): void {
		$this->assertTrue( Ip::is_private( $ip ) );
	}

	/** @return array<string, array{0: string}> */
	public static function non_public(): array {
		return array(
			'NAT64 loopback'   => array( '64:ff9b::7f00:1' ),
			'NAT64 metadata'   => array( '64:ff9b::a9fe:a9fe' ),
			'6to4 loopback'    => array( '2002:7f00:1::' ),
			'6to4 private'     => array( '2002:a00:1::1' ),
			'Teredo'           => array( '2001::1' ),
			'IPv6 docs'        => array( '2001:db8::1' ),
			'IPv6 multicast'   => array( 'ff02::1' ),
			'IETF protocol'    => array( '192.0.0.1' ),
			'TEST-NET-1'       => array( '192.0.2.1' ),
			'benchmarking'     => array( '198.18.0.1' ),
			'multicast'        => array( '224.0.0.1' ),
			'this network'     => array( '0.1.2.3' ),
		);
	}

	public function test_public_addresses_inside_transition_prefixes_are_public(): void {
		$this->assertFalse( Ip::is_private( '64:ff9b::808:808' ) );
		$this->assertFalse( Ip::is_private( '2002:808:808::' ) );
		$this->assertFalse( Ip::is_private( '::ffff:8.8.8.8' ) );
		$this->assertTrue( Ip::is_private( '::ffff:127.0.0.1' ) );
	}

	/* ─── Hosts ─────────────────────────────────────────────────────── */

	/**
	 * `parse_url()` returns these as hosts, and each ends with the domain;
	 * a browser goes somewhere else entirely.
	 */
	#[DataProvider( 'smuggled_hosts' )]
	public function test_malformed_hosts_are_not_within( string $url ): void {
		$host = (string) parse_url( $url, PHP_URL_HOST );

		$this->assertNotSame( '', $host );
		$this->assertFalse( Host::is_within( $host, 'example.com' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function smuggled_hosts(): array {
		return array(
			'backslash' => array( 'https://evil.com\\.example.com/' ),
			'percent'   => array( 'https://evil.com%2f.example.com/' ),
		);
	}

	public function test_well_formed_hosts_are_still_within(): void {
		$this->assertTrue( Host::is_within( 'Shop.Example.com:8443', 'example.com' ) );
		$this->assertTrue( Host::is_within( '_acme.example.com', 'example.com' ) );
		$this->assertTrue( Host::is_within( '[::1]:80', '::1' ) );
	}

	/* ─── User agents ───────────────────────────────────────────────── */

	/**
	 * A padded agent that fails the `.*A.*B(?!.*C)` patterns costs time in
	 * proportion to a power of its length. The matchers stop at 512 bytes.
	 */
	public function test_padded_user_agents_are_cut_before_matching(): void {
		$agent = str_repeat( 'Chrome/1 Mobile Edge ', 4000 );
		$start = hrtime( true );

		UserAgent::describe( $agent );
		UserAgent::device_type( $agent );

		$this->assertLessThan( 50_000_000, hrtime( true ) - $start, 'Classification took longer than 50ms.' );
	}

	/* ─── Referrers ─────────────────────────────────────────────────── */

	/**
	 * `t.co` is inside `microsoft.com`, and `x.com` inside `dropbox.com`.
	 */
	#[DataProvider( 'not_social' )]
	public function test_fragments_match_on_a_label( string $referrer ): void {
		$this->assertSame( 'referral', Referrer::analyse( $referrer )->channel );
	}

	/** @return array<string, array{0: string}> */
	public static function not_social(): array {
		return array(
			'microsoft' => array( 'https://www.microsoft.com/' ),
			'dropbox'   => array( 'https://www.dropbox.com/s/abc' ),
			'flask'     => array( 'https://flask.com/' ),
			'mygoogle'  => array( 'https://mygoogle.blog/' ),
		);
	}

	public function test_gemini_is_not_reported_as_google(): void {
		$this->assertSame( 'Gemini', Referrer::analyse( 'https://gemini.google.com/app' )->name );
		$this->assertSame( 'Google', Referrer::analyse( 'https://www.google.co.uk/search?q=x' )->name );
	}

	/* ─── Client hints ──────────────────────────────────────────────── */

	public function test_brands_are_sanitised_and_capped(): void {
		$header = '"<b>Evil</b>' . "\x01" . '";v="1<", ' . implode( ', ', array_map( static fn( int $i ): string => '"B' . $i . '";v="' . $i . '"', range( 1, 50 ) ) );
		$brands = ClientHints::brands( array( 'HTTP_SEC_CH_UA' => $header ) );

		$this->assertLessThanOrEqual( 8, count( $brands ) );

		foreach ( $brands as $brand => $version ) {
			$this->assertMatchesRegularExpression( '/^[\x20-\x7E]{1,64}$/', (string) $brand );
			$this->assertMatchesRegularExpression( '/^[\x20-\x7E]{0,64}$/', $version );
		}
	}
}
