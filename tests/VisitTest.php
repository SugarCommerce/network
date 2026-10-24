<?php
/**
 * Visit tests.
 *
 * @package SugarCommerce\Network\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\TrustedProxies;
use SugarCommerce\Network\Visit;

/**
 * Class VisitTest
 */
final class VisitTest extends TestCase {

	private const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

	/**
	 * An address inside Cloudflare's published ranges.
	 */
	private const string EDGE = '173.245.48.1';

	/**
	 * A hash that shows what it was given.
	 *
	 * @return \Closure(string): string
	 */
	private static function hash(): \Closure {
		return static fn( string $value ): string => 'h:' . $value;
	}

	/**
	 * @param array<string, string> $server
	 */
	private static function capture( array $server, ?TrustedProxies $proxies = null, string $country_header = '' ): Visit {
		return Visit::capture( self::hash(), $proxies, $country_header, $server );
	}

	/* ─── The record ────────────────────────────────────────────────── */

	public function test_a_direct_visit_is_hashed_and_summarised(): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => self::CHROME ) );

		$this->assertSame(
			array(
				'ip_hash'    => 'h:203.0.113.42',
				'agent_hash' => 'h:' . self::CHROME,
				'country'    => null,
				'device'     => 'Chrome 131 on macOS',
				'network'    => '203.0.113.0',
			),
			$visit->to_array()
		);
	}

	public function test_the_summary_is_only_what_a_person_may_see(): void {
		$visit = self::capture(
			array( 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_CONNECTING_IP' => '203.0.113.42', 'HTTP_CF_IPCOUNTRY' => 'ie', 'HTTP_USER_AGENT' => 'curl/8.4.0' ),
			TrustedProxies::cloudflare(),
			Visit::CLOUDFLARE_COUNTRY
		);

		$this->assertSame( array( 'country' => 'IE', 'device' => 'curl', 'network' => '203.0.113.0' ), $visit->summary() );
		$this->assertStringNotContainsString( '203.0.113.42', implode( ' ', $visit->summary() ) );
	}

	public function test_a_stored_row_reads_back_into_the_same_visit(): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334', 'HTTP_USER_AGENT' => self::CHROME ) );
		$row   = $visit->to_array();

		$this->assertEquals( $visit, new Visit( ...$row ) );
	}

	/* ─── Addresses ─────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{string, string, string|null}>
	 */
	public static function addresses(): array {
		return array(
			'ipv4'                => array( '203.0.113.42', 'h:203.0.113.42', '203.0.113.0' ),
			'ipv6'                => array( '2001:db8:85a3::8a2e:370:7334', 'h:2001:db8:85a3::8a2e:370:7334', '2001:db8:85a3::' ),
			'ipv6 spelled long'   => array( '2001:0DB8:85A3:0000:0000:8A2E:0370:7334', 'h:2001:db8:85a3::8a2e:370:7334', '2001:db8:85a3::' ),
			'ipv4-mapped ipv6'    => array( '::ffff:203.0.113.42', 'h:203.0.113.42', '203.0.113.0' ),
			'bracketed ipv6'      => array( '[2001:db8::1]', 'h:2001:db8::1', '2001:db8::' ),
			'not an address'      => array( 'not-an-ip', '', null ),
			'out of range'        => array( '203.0.113.256', '', null ),
			'empty'               => array( '', '', null ),
		);
	}

	#[DataProvider( 'addresses' )]
	public function test_the_address_is_canonical_before_it_is_hashed_or_masked( string $remote, string $hash, ?string $network ): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => $remote ) );

		$this->assertSame( $hash, $visit->ip_hash );
		$this->assertSame( $network, $visit->network );
	}

	public function test_no_remote_address_at_all_records_none(): void {
		$visit = self::capture( array( 'HTTP_USER_AGENT' => self::CHROME ) );

		$this->assertSame( '', $visit->ip_hash );
		$this->assertNull( $visit->network );
		$this->assertSame( 'Chrome 131 on macOS', $visit->device );
	}

	public function test_one_spelling_of_an_address_is_one_hash(): void {
		$short = self::capture( array( 'REMOTE_ADDR' => '2001:db8::1' ) );
		$long  = self::capture( array( 'REMOTE_ADDR' => '2001:DB8:0:0:0:0:0:1' ) );

		$this->assertSame( $short->ip_hash, $long->ip_hash );
	}

	/* ─── Proxies ───────────────────────────────────────────────────── */

	public function test_a_forwarding_header_from_a_trusted_proxy_names_the_visitor(): void {
		$visit = self::capture(
			array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.5' ),
			TrustedProxies::none()->and_also( '10.0.0.0/8' )
		);

		$this->assertSame( 'h:198.51.100.7', $visit->ip_hash );
		$this->assertSame( '198.51.100.0', $visit->network );
	}

	public function test_a_forwarding_header_from_anyone_else_is_ignored(): void {
		$server = array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' );

		$this->assertSame( 'h:203.0.113.42', self::capture( $server )->ip_hash, 'no proxies' );
		$this->assertSame( 'h:203.0.113.42', self::capture( $server, TrustedProxies::cloudflare() )->ip_hash, 'not from the edge' );
	}

	public function test_an_ipv6_visitor_behind_an_ipv6_edge(): void {
		$visit = self::capture(
			array( 'REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8:abcd:12::99', 'HTTP_CF_IPCOUNTRY' => 'DE' ),
			TrustedProxies::cloudflare(),
			Visit::CLOUDFLARE_COUNTRY
		);

		$this->assertSame( 'h:2001:db8:abcd:12::99', $visit->ip_hash );
		$this->assertSame( '2001:db8:abcd::', $visit->network );
		$this->assertSame( 'DE', $visit->country );
	}

	/* ─── Country ───────────────────────────────────────────────────── */

	public function test_the_country_header_is_believed_only_from_a_trusted_proxy(): void {
		$server = array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_CF_IPCOUNTRY' => 'US' );

		$this->assertNull( self::capture( $server, TrustedProxies::cloudflare(), Visit::CLOUDFLARE_COUNTRY )->country, 'not from the edge' );
		$this->assertNull( self::capture( $server, null, Visit::CLOUDFLARE_COUNTRY )->country, 'no proxies' );
	}

	public function test_no_country_header_named_records_no_country(): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_IPCOUNTRY' => 'IE' ), TrustedProxies::cloudflare() );

		$this->assertNull( $visit->country );
	}

	public function test_any_country_header_can_be_named(): void {
		$visit = self::capture(
			array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'FR' ),
			TrustedProxies::none()->and_also( '10.0.0.0/8' ),
			'CloudFront-Viewer-Country'
		);

		$this->assertSame( 'FR', $visit->country );
	}

	/**
	 * @return array<string, array{mixed, string|null}>
	 */
	public static function countries(): array {
		return array(
			'uppercase'       => array( 'GB', 'GB' ),
			'lowercase'       => array( 'gb', 'GB' ),
			'padded'          => array( ' nl ', 'NL' ),
			'kosovo'          => array( 'XK', 'XK' ),
			'unknown'         => array( 'XX', null ),
			'tor'             => array( 'T1', null ),
			'three letters'   => array( 'GBR', null ),
			'one letter'      => array( 'G', null ),
			'empty'           => array( '', null ),
			'trailing newline' => array( "GB\n", 'GB' ),
			'injected'        => array( "GB\nX", null ),
			'not a string'    => array( array( 'GB' ), null ),
		);
	}

	#[DataProvider( 'countries' )]
	public function test_the_country_is_two_letters_or_nothing( mixed $header, ?string $country ): void {
		$visit = Visit::capture( self::hash(), TrustedProxies::cloudflare(), Visit::CLOUDFLARE_COUNTRY, array( 'REMOTE_ADDR' => self::EDGE, 'HTTP_CF_IPCOUNTRY' => $header ) );

		$this->assertSame( $country, $visit->country );
	}

	public function test_a_missing_country_header_is_no_country(): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => self::EDGE ), TrustedProxies::cloudflare(), Visit::CLOUDFLARE_COUNTRY );

		$this->assertNull( $visit->country );
	}

	/* ─── User agent ────────────────────────────────────────────────── */

	public function test_no_agent_is_no_hash_and_no_device(): void {
		foreach ( array( array(), array( 'HTTP_USER_AGENT' => '' ), array( 'HTTP_USER_AGENT' => "  \t " ) ) as $headers ) {
			$visit = self::capture( array( 'REMOTE_ADDR' => '203.0.113.42' ) + $headers );

			$this->assertSame( '', $visit->agent_hash );
			$this->assertNull( $visit->device );
		}
	}

	public function test_an_agent_nothing_recognises_is_an_unknown_device_not_none(): void {
		$visit = self::capture( array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => 'zzz' ) );

		$this->assertSame( 'Unknown', $visit->device );
		$this->assertSame( 'h:zzz', $visit->agent_hash );
	}

	public function test_one_browser_is_one_hash_however_its_header_was_padded(): void {
		$plain  = self::capture( array( 'HTTP_USER_AGENT' => self::CHROME ) );
		$padded = self::capture( array( 'HTTP_USER_AGENT' => '  ' . self::CHROME . "\t" ) );

		$this->assertSame( $plain->agent_hash, $padded->agent_hash );
	}

	public function test_an_oversized_agent_is_capped_before_it_is_hashed_or_described(): void {
		$visit = self::capture( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/' . str_repeat( '9', 2000 ) . '.0' ) );

		$this->assertSame( 2 + 512, strlen( $visit->agent_hash ) );
		$this->assertNotNull( $visit->device );
		$this->assertLessThanOrEqual( 80, mb_strlen( $visit->device ) );
		$this->assertStringStartsWith( 'Chrome 999', $visit->device );
	}

	public function test_a_non_string_agent_is_none(): void {
		$visit = Visit::capture( self::hash(), null, '', array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => array( self::CHROME ) ) );

		$this->assertSame( '', $visit->agent_hash );
		$this->assertNull( $visit->device );
	}

	/* ─── Hashing ───────────────────────────────────────────────────── */

	public function test_the_hash_is_never_asked_about_nothing(): void {
		$calls = array();
		$hash  = static function ( string $value ) use ( &$calls ): string {
			$calls[] = $value;

			return 'x';
		};

		$visit = Visit::capture( $hash, null, '', array() );

		$this->assertSame( array(), $calls );
		$this->assertSame( array( 'ip_hash' => '', 'agent_hash' => '', 'country' => null, 'device' => null, 'network' => null ), $visit->to_array() );
	}

	public function test_hmac_is_keyed_sha256(): void {
		$visit = Visit::capture( Visit::hmac( 'secret' ), null, '', array( 'REMOTE_ADDR' => '203.0.113.42', 'HTTP_USER_AGENT' => self::CHROME ) );

		$this->assertSame( hash_hmac( 'sha256', '203.0.113.42', 'secret' ), $visit->ip_hash );
		$this->assertSame( hash_hmac( 'sha256', self::CHROME, 'secret' ), $visit->agent_hash );
		$this->assertNotSame( $visit->ip_hash, Visit::capture( Visit::hmac( 'other' ), null, '', array( 'REMOTE_ADDR' => '203.0.113.42' ) )->ip_hash );
	}

	public function test_hmac_refuses_an_empty_secret(): void {
		$this->expectException( \InvalidArgumentException::class );

		Visit::hmac( '' );
	}

	/* ─── The superglobal ───────────────────────────────────────────── */

	public function test_without_a_server_array_the_superglobal_is_read(): void {
		$saved   = $_SERVER;
		$_SERVER = array( 'REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => self::CHROME );

		try {
			$visit = Visit::capture( self::hash() );
		} finally {
			$_SERVER = $saved;
		}

		$this->assertSame( 'h:198.51.100.7', $visit->ip_hash );
		$this->assertSame( 'Chrome 131 on macOS', $visit->device );
	}
}
