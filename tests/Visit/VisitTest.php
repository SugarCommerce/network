<?php
/**
 * A visit, pseudonymised for a log.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Visit;

use SugarCommerce\Network\TrustedProxies;
use SugarCommerce\Network\Visit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class VisitTest
 */
final class VisitTest extends TestCase {

	/**
	 * Chrome on a Mac.
	 */
	private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

	/**
	 * An address inside Cloudflare's published ranges.
	 */
	private const EDGE = '173.245.48.1';

	/**
	 * A hash that shows what it was given.
	 *
	 * @return \Closure
	 */
	private static function hash(): \Closure {
		return static fn( string $value ): string => 'h:' . $value;
	}

	/**
	 * Capture with the showing hash.
	 *
	 * @param array               $server         Server array.
	 * @param TrustedProxies|null $proxies        Proxies.
	 * @param string              $country_header Country header.
	 *
	 * @return Visit
	 */
	private static function capture( array $server, ?TrustedProxies $proxies = null, string $country_header = '' ): Visit {
		return Visit::capture( self::hash(), $proxies, $country_header, $server );
	}

	/**
	 * A direct visit is hashed and summarised.
	 */
	public function test_a_direct_visit_is_hashed_and_summarised(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'     => '203.0.113.42',
				'HTTP_USER_AGENT' => self::CHROME,
			]
		);

		$this->assertSame(
			[
				'ip_hash'    => 'h:203.0.113.42',
				'agent_hash' => 'h:' . self::CHROME,
				'country'    => null,
				'device'     => 'Chrome 131 on macOS',
				'network'    => '203.0.113.0/24',
			],
			$visit->to_array()
		);
	}

	/**
	 * The summary is only what a person may see.
	 */
	public function test_the_summary_is_only_what_a_person_may_see(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'           => self::EDGE,
				'HTTP_CF_CONNECTING_IP' => '203.0.113.42',
				'HTTP_CF_IPCOUNTRY'     => 'ie',
				'HTTP_USER_AGENT'       => 'curl/8.4.0',
			],
			TrustedProxies::cloudflare(),
			Visit::CLOUDFLARE_COUNTRY
		);

		$this->assertSame(
			[
				'country' => 'IE',
				'device'  => 'curl',
				'network' => '203.0.113.0/24',
			],
			$visit->summary()
		);
		$this->assertStringNotContainsString( '203.0.113.42', implode( ' ', $visit->summary() ) );
	}

	/**
	 * A stored row reads back into the same visit.
	 */
	public function test_a_stored_row_reads_back_into_the_same_visit(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'     => '2001:db8:85a3::8a2e:370:7334',
				'HTTP_USER_AGENT' => self::CHROME,
			]
		);

		$this->assertEquals( $visit, new Visit( ...$visit->to_array() ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public static function addresses(): array {
		return [
			'ipv4'              => [ '203.0.113.42', 'h:203.0.113.42', '203.0.113.0/24' ],
			'ipv6'              => [ '2001:db8:85a3::8a2e:370:7334', 'h:2001:db8:85a3::8a2e:370:7334', '2001:db8:85a3::/48' ],
			'ipv6 spelled long' => [ '2001:0DB8:85A3:0000:0000:8A2E:0370:7334', 'h:2001:db8:85a3::8a2e:370:7334', '2001:db8:85a3::/48' ],
			'ipv4-mapped ipv6'  => [ '::ffff:203.0.113.42', 'h:203.0.113.42', '203.0.113.0/24' ],
			'not an address'    => [ 'not-an-ip', '', null ],
			'out of range'      => [ '203.0.113.256', '', null ],
			'empty'             => [ '', '', null ],
		];
	}

	/**
	 * The address is canonical before it is hashed or masked.
	 *
	 * @param string      $remote  REMOTE_ADDR.
	 * @param string      $hash    Wanted hash.
	 * @param string|null $network Wanted network.
	 */
	#[DataProvider( 'addresses' )]
	public function test_the_address_is_canonical_before_it_is_hashed_or_masked( string $remote, string $hash, ?string $network ): void {
		$visit = self::capture( [ 'REMOTE_ADDR' => $remote ] );

		$this->assertSame( $hash, $visit->ip_hash );
		$this->assertSame( $network, $visit->network );
	}

	/**
	 * No address at all records none.
	 */
	public function test_no_remote_address_at_all_records_none(): void {
		$visit = self::capture( [ 'HTTP_USER_AGENT' => self::CHROME ] );

		$this->assertSame( '', $visit->ip_hash );
		$this->assertNull( $visit->network );
		$this->assertSame( 'Chrome 131 on macOS', $visit->device );
	}

	/**
	 * A forwarding header from a trusted proxy names the visitor.
	 */
	public function test_a_forwarding_header_from_a_trusted_proxy_names_the_visitor(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.5',
			],
			TrustedProxies::none()->and_also( [ '10.0.0.0/8' ] )
		);

		$this->assertSame( 'h:198.51.100.7', $visit->ip_hash );
		$this->assertSame( '198.51.100.0/24', $visit->network );
	}

	/**
	 * From anyone else it is ignored.
	 */
	public function test_a_forwarding_header_from_anyone_else_is_ignored(): void {
		$server = [
			'REMOTE_ADDR'           => '203.0.113.42',
			'HTTP_X_FORWARDED_FOR'  => '198.51.100.7',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
		];

		$this->assertSame( 'h:203.0.113.42', self::capture( $server )->ip_hash, 'no proxies' );
		$this->assertSame( 'h:203.0.113.42', self::capture( $server, TrustedProxies::cloudflare() )->ip_hash, 'not from the edge' );
	}

	/**
	 * An IPv6 visitor behind an IPv6 edge.
	 */
	public function test_an_ipv6_visitor_behind_an_ipv6_edge(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'           => '2606:4700::1',
				'HTTP_CF_CONNECTING_IP' => '2001:db8:abcd:12::99',
				'HTTP_CF_IPCOUNTRY'     => 'DE',
			],
			TrustedProxies::cloudflare(),
			Visit::CLOUDFLARE_COUNTRY
		);

		$this->assertSame( 'h:2001:db8:abcd:12::99', $visit->ip_hash );
		$this->assertSame( '2001:db8:abcd::/48', $visit->network );
		$this->assertSame( 'DE', $visit->country );
	}

	/**
	 * The country header is believed only from a trusted proxy.
	 */
	public function test_the_country_header_is_believed_only_from_a_trusted_proxy(): void {
		$server = [
			'REMOTE_ADDR'       => '203.0.113.42',
			'HTTP_CF_IPCOUNTRY' => 'US',
			'HTTP_CF_RAY'       => '8a1b2c3d4e5f6789-LHR',
		];

		$this->assertNull( self::capture( $server, TrustedProxies::cloudflare(), Visit::CLOUDFLARE_COUNTRY )->country, 'not from the edge' );
		$this->assertNull( self::capture( $server, null, Visit::CLOUDFLARE_COUNTRY )->country, 'no proxies' );
	}

	/**
	 * No country header named, no country.
	 */
	public function test_no_country_header_named_records_no_country(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'       => self::EDGE,
				'HTTP_CF_IPCOUNTRY' => 'IE',
			],
			TrustedProxies::cloudflare()
		);

		$this->assertNull( $visit->country );
	}

	/**
	 * Any country header can be named.
	 */
	public function test_any_country_header_can_be_named(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'                    => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR'           => '198.51.100.7',
				'HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'FR',
			],
			TrustedProxies::none()->and_also( [ '10.0.0.0/8' ] ),
			'CloudFront-Viewer-Country'
		);

		$this->assertSame( 'FR', $visit->country );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function countries(): array {
		return [
			'uppercase'        => [ 'GB', 'GB' ],
			'lowercase'        => [ 'gb', 'GB' ],
			'padded'           => [ ' nl ', 'NL' ],
			'kosovo'           => [ 'XK', 'XK' ],
			'unknown'          => [ 'XX', null ],
			'tor'              => [ 'T1', null ],
			'three letters'    => [ 'GBR', null ],
			'one letter'       => [ 'G', null ],
			'empty'            => [ '', null ],
			'trailing newline' => [ "GB\n", 'GB' ],
			'injected'         => [ "GB\nX", null ],
			'not a string'     => [ [ 'GB' ], null ],
		];
	}

	/**
	 * The country is two letters or nothing.
	 *
	 * @param mixed       $header  Header value.
	 * @param string|null $country Wanted.
	 */
	#[DataProvider( 'countries' )]
	public function test_the_country_is_two_letters_or_nothing( mixed $header, ?string $country ): void {
		$visit = Visit::capture(
			self::hash(),
			TrustedProxies::cloudflare(),
			Visit::CLOUDFLARE_COUNTRY,
			[
				'REMOTE_ADDR'       => self::EDGE,
				'HTTP_CF_IPCOUNTRY' => $header,
			]
		);

		$this->assertSame( $country, $visit->country );
	}

	/**
	 * No agent is no hash and no device.
	 */
	public function test_no_agent_is_no_hash_and_no_device(): void {
		foreach ( [ [], [ 'HTTP_USER_AGENT' => '' ], [ 'HTTP_USER_AGENT' => "  \t " ], [ 'HTTP_USER_AGENT' => [ self::CHROME ] ] ] as $headers ) {
			$visit = self::capture( [ 'REMOTE_ADDR' => '203.0.113.42' ] + $headers );

			$this->assertSame( '', $visit->agent_hash );
			$this->assertNull( $visit->device );
		}
	}

	/**
	 * An agent nothing recognises is an unknown device, not none.
	 */
	public function test_an_agent_nothing_recognises_is_an_unknown_device_not_none(): void {
		$visit = self::capture(
			[
				'REMOTE_ADDR'     => '203.0.113.42',
				'HTTP_USER_AGENT' => 'zzz',
			]
		);

		$this->assertSame( 'Unknown', $visit->device );
		$this->assertSame( 'h:zzz', $visit->agent_hash );
	}

	/**
	 * One browser is one hash however its header was padded.
	 */
	public function test_one_browser_is_one_hash_however_its_header_was_padded(): void {
		$plain  = self::capture( [ 'HTTP_USER_AGENT' => self::CHROME ] );
		$padded = self::capture( [ 'HTTP_USER_AGENT' => '  ' . self::CHROME . "\t" ] );

		$this->assertSame( $plain->agent_hash, $padded->agent_hash );
	}

	/**
	 * An oversized agent is capped before it is hashed or described.
	 */
	public function test_an_oversized_agent_is_capped_before_it_is_hashed_or_described(): void {
		$visit = self::capture( [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/' . str_repeat( '9', 2000 ) . '.0' ] );

		$this->assertSame( 2 + 512, strlen( $visit->agent_hash ) );
		$this->assertNotNull( $visit->device );
		$this->assertSame( Visit::DEVICE_LENGTH, mb_strlen( (string) $visit->device ) );
		$this->assertStringStartsWith( 'Chrome 999', (string) $visit->device );
	}

	/**
	 * The hash is never asked about nothing.
	 */
	public function test_the_hash_is_never_asked_about_nothing(): void {
		$calls = [];
		$hash  = static function ( string $value ) use ( &$calls ): string {
			$calls[] = $value;

			return 'x';
		};

		$visit = Visit::capture( $hash, null, '', [] );

		$this->assertSame( [], $calls );
		$this->assertSame(
			[
				'ip_hash'    => '',
				'agent_hash' => '',
				'country'    => null,
				'device'     => null,
				'network'    => null,
			],
			$visit->to_array()
		);
	}

	/**
	 * hmac() is keyed SHA-256.
	 */
	public function test_hmac_is_keyed_sha256(): void {
		$server = [
			'REMOTE_ADDR'     => '203.0.113.42',
			'HTTP_USER_AGENT' => self::CHROME,
		];
		$visit  = Visit::capture( Visit::hmac( 'secret' ), null, '', $server );

		$this->assertSame( hash_hmac( 'sha256', '203.0.113.42', 'secret' ), $visit->ip_hash );
		$this->assertSame( hash_hmac( 'sha256', self::CHROME, 'secret' ), $visit->agent_hash );
		$this->assertNotSame( $visit->ip_hash, Visit::capture( Visit::hmac( 'other' ), null, '', $server )->ip_hash );
	}

	/**
	 * hmac() refuses an empty secret.
	 */
	public function test_hmac_refuses_an_empty_secret(): void {
		$this->expectException( \InvalidArgumentException::class );

		Visit::hmac( '' );
	}

	/**
	 * Without a server array the live request is read, unslashed.
	 */
	public function test_without_a_server_array_the_live_request_is_read(): void {
		$saved   = $_SERVER;
		$_SERVER = [
			'REMOTE_ADDR'     => '198.51.100.7',
			'HTTP_USER_AGENT' => addslashes( 'Mozilla/5.0 "quoted" ' ) . self::CHROME,
		];

		try {
			$visit = Visit::capture( self::hash() );
		} finally {
			$_SERVER = $saved;
		}

		$this->assertSame( 'h:198.51.100.7', $visit->ip_hash );
		$this->assertSame( 'h:Mozilla/5.0 "quoted" ' . self::CHROME, $visit->agent_hash );
	}
}
