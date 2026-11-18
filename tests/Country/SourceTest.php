<?php
/**
 * Header source tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Country;

use SugarCommerce\Network\Country\Sources\BunnyCDN;
use SugarCommerce\Network\Country\Sources\Callback;
use SugarCommerce\Network\Country\Sources\Cloudflare;
use SugarCommerce\Network\Country\Sources\CloudFront;
use SugarCommerce\Network\Country\Sources\Fastly;
use SugarCommerce\Network\Country\Sources\Generic;
use SugarCommerce\Network\Country\Sources\ServerGeoIP;
use SugarCommerce\Network\Country\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A country source reads one header and decides whether to believe it.
 *
 * Both halves matter. Reading it is trivial; deciding is where the library
 * earns its place, because the values a CDN puts in these headers are not all
 * country codes — "XX" for unknown, "T1" for a Tor exit — and the header
 * itself is only trustworthy when the request actually came through the CDN.
 */
final class SourceTest extends TestCase {

	/**
	 * A header value is read, upper-cased and returned.
	 */
	public function test_a_country_header_resolves(): void {
		$this->assertSame(
			'GB',
			( new Cloudflare() )->resolve(
				[
					'HTTP_CF_IPCOUNTRY' => 'gb',
					'HTTP_CF_RAY'       => '8d0000000000-LHR',
				]
			)
		);
	}

	/**
	 * Every bundled source reads the header its vendor actually sets.
	 *
	 * A wrong header name is invisible: the source returns null, the next one
	 * in the chain answers or nothing does, and the only symptom is that
	 * country detection quietly never works behind that CDN.
	 *
	 * @param Source $source The source.
	 * @param string $header The `$_SERVER` key it should read.
	 * @param array  $extra  Any signature headers it also requires.
	 */
	#[DataProvider( 'headerProvider' )]
	public function test_each_source_reads_its_own_header( Source $source, string $header, array $extra ): void {
		$this->assertSame( 'FR', $source->resolve( $extra + [ $header => 'FR' ] ) );
		$this->assertNull( $source->resolve( $extra + [ 'HTTP_SOMETHING_ELSE' => 'FR' ] ) );
	}

	/**
	 * One row per bundled source.
	 *
	 * @return array<string, array{0: Source, 1: string, 2: array<string, string>}>
	 */
	public static function headerProvider(): array {
		return [
			'cloudflare'  => [ new Cloudflare(), 'HTTP_CF_IPCOUNTRY', [ 'HTTP_CF_RAY' => '1' ] ],
			'cloudfront'  => [ new CloudFront(), 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', [] ],
			'fastly'      => [ new Fastly(), 'HTTP_FASTLY_CLIENT_COUNTRY', [ 'HTTP_FASTLY_FF' => '1' ] ],
			'bunnycdn'    => [ new BunnyCDN(), 'HTTP_CDN_LOOPCOUNTRY', [] ],
			'server geoip'=> [ new ServerGeoIP(), 'GEOIP_COUNTRY_CODE', [] ],
			'generic'     => [ new Generic(), 'HTTP_X_COUNTRY_CODE', [] ],
		];
	}

	/**
	 * A gated header is not believed without evidence the CDN sent it.
	 *
	 * These headers are set by the CDN on every request that passes through
	 * it. Nothing stops somebody sending one to the origin directly, and an
	 * origin reachable by IP usually is. So the country is read only when at
	 * least one other header the same CDN sets is present.
	 *
	 * Any one of them is enough, deliberately: which of them appears varies
	 * with the CDN's own configuration, and requiring a specific one would
	 * mean the country silently never resolves on some accounts.
	 *
	 * @param Source   $source     The source.
	 * @param string   $header     The country header.
	 * @param string[] $signatures Headers that each count as evidence.
	 */
	#[DataProvider( 'gatedProvider' )]
	public function test_a_gated_header_is_not_believed_on_its_own( Source $source, string $header, array $signatures ): void {
		$this->assertNull(
			$source->resolve( [ $header => 'GB' ] ),
			'A country header with no signature was believed.'
		);

		foreach ( $signatures as $signature ) {
			$this->assertSame(
				'GB',
				$source->resolve(
					[
						$header    => 'GB',
						$signature => 'something',
					]
				),
				sprintf( '%s does not count as a signature.', $signature )
			);
		}
	}

	/**
	 * The sources that gate, and what they accept as evidence.
	 *
	 * @return array<string, array{0: Source, 1: string, 2: string[]}>
	 */
	public static function gatedProvider(): array {
		return [
			'cloudflare' => [
				new Cloudflare(),
				'HTTP_CF_IPCOUNTRY',
				[ 'HTTP_CF_RAY', 'HTTP_CF_VISITOR', 'HTTP_CF_CONNECTING_IP' ],
			],
			'fastly'     => [
				new Fastly(),
				'HTTP_FASTLY_CLIENT_COUNTRY',
				[ 'HTTP_FASTLY_FF', 'HTTP_FASTLY_CLIENT_IP' ],
			],
		];
	}

	/**
	 * The sources that do not gate, which is a decision rather than an
	 * oversight.
	 *
	 * CloudFront and BunnyCDN have no second header worth requiring, and
	 * GEOIP_COUNTRY_CODE is written by a server module rather than arriving
	 * over the wire — a request cannot set it. The generic header can be set
	 * by anybody, which is exactly why it is ranked last and marked low
	 * confidence rather than gated on a signature it does not have.
	 *
	 * Pinned so that adding a gate becomes a decision: a gate on a header
	 * whose CDN sends nothing else would stop it resolving entirely, and the
	 * symptom is silence.
	 *
	 * @param Source $source The source.
	 * @param string $header The country header.
	 */
	#[DataProvider( 'ungatedProvider' )]
	public function test_an_ungated_header_is_believed_on_its_own( Source $source, string $header ): void {
		$this->assertSame( 'GB', $source->resolve( [ $header => 'GB' ] ) );
	}

	/**
	 * @return array<string, array{0: Source, 1: string}>
	 */
	public static function ungatedProvider(): array {
		return [
			'cloudfront'   => [ new CloudFront(), 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' ],
			'bunnycdn'     => [ new BunnyCDN(), 'HTTP_CDN_LOOPCOUNTRY' ],
			'server geoip' => [ new ServerGeoIP(), 'GEOIP_COUNTRY_CODE' ],
			'generic'      => [ new Generic(), 'HTTP_X_COUNTRY_CODE' ],
		];
	}

	/**
	 * A value that is not a country code is refused, not trimmed into one.
	 *
	 * The source comment records what this is guarding: an earlier version
	 * took the first two characters and then validated, so "INVALID123" passed
	 * as "IN" — India. A wrong country that looks right is worse than no
	 * country at all, because nothing downstream can tell.
	 *
	 * @param string $value What the header carries.
	 */
	#[DataProvider( 'refusedProvider' )]
	public function test_a_value_that_is_not_a_country_code_is_refused( string $value ): void {
		$this->assertNull( ( new Generic() )->resolve( [ 'HTTP_X_COUNTRY_CODE' => $value ] ) );
	}

	/**
	 * Values seen in the wild that are not alpha-2 country codes.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedProvider(): array {
		return [
			'longer, starting with a real code' => [ 'INVALID123' ],
			'an ISO-3166 alpha-3 code'          => [ 'GBR' ],
			'one letter'                        => [ 'G' ],
			'Cloudflare\'s Tor sentinel'        => [ 'T1' ],
			'digits'                            => [ '12' ],
			'a mix'                             => [ 'G1' ],
			'punctuation'                       => [ 'G-' ],
			'empty'                             => [ '' ],
			'whitespace only'                   => [ '   ' ],
		];
	}

	/**
	 * A sentinel means "unknown", so the chain carries on.
	 *
	 * XX passes every shape check — two characters, both letters — which is
	 * why it is listed explicitly rather than left to validation.
	 */
	public function test_an_unknown_sentinel_falls_through(): void {
		$this->assertNull(
			( new Cloudflare() )->resolve(
				[
					'HTTP_CF_IPCOUNTRY' => 'XX',
					'HTTP_CF_RAY'       => '1',
				]
			)
		);
	}

	/**
	 * Surrounding whitespace is not a reason to refuse a header.
	 */
	public function test_a_padded_value_still_resolves(): void {
		$this->assertSame( 'DE', ( new Generic() )->resolve( [ 'HTTP_X_COUNTRY_CODE' => "  de \n" ] ) );
	}

	/**
	 * A custom resolver is held to the same validation as a header.
	 *
	 * The point of Callback is that a site can plug in its own GeoIP lookup.
	 * That lookup is somebody else's code returning somebody else's data, so
	 * it gets checked exactly like a header a CDN sent.
	 */
	public function test_a_callback_result_is_validated_too(): void {
		$this->assertSame( 'ES', ( new Callback( static fn(): string => 'es' ) )->resolve( [] ) );

		foreach ( [ 'GBR', '', '12', 'X' ] as $bad ) {
			$this->assertNull(
				( new Callback( static fn(): string => $bad ) )->resolve( [] ),
				sprintf( 'A callback returning "%s" was believed.', $bad )
			);
		}
	}

	/**
	 * A callback that returns nothing at all is a miss, not a crash.
	 */
	public function test_a_callback_returning_null_is_a_miss(): void {
		$this->assertNull( ( new Callback( static fn() => null ) )->resolve( [] ) );
		$this->assertNull( ( new Callback( static fn() => false ) )->resolve( [] ) );
		$this->assertNull( ( new Callback( static fn() => 42 ) )->resolve( [] ) );
	}

	/**
	 * A callback is handed the server array.
	 */
	public function test_a_callback_receives_the_server_array(): void {
		$seen = null;

		( new Callback(
			static function ( array $server ) use ( &$seen ) {
				$seen = $server;

				return 'IT';
			}
		) )->resolve( [ 'REMOTE_ADDR' => '203.0.113.1' ] );

		$this->assertSame( [ 'REMOTE_ADDR' => '203.0.113.1' ], $seen );
	}

	/**
	 * The confidence tiers are the ones the interface documents.
	 *
	 * Callers are invited to branch on these — skipping a paid lookup when a
	 * high-confidence source answered — so the strings are part of the
	 * contract rather than a label.
	 */
	public function test_the_confidence_tiers_are_what_the_contract_says(): void {
		$this->assertSame( 'high', ( new Cloudflare() )->get_confidence() );
		$this->assertSame( 'high', ( new CloudFront() )->get_confidence() );
		$this->assertSame( 'high', ( new Fastly() )->get_confidence() );
		$this->assertSame( 'high', ( new BunnyCDN() )->get_confidence() );
		$this->assertSame( 'medium', ( new ServerGeoIP() )->get_confidence() );

		// The header name is a convention, not a vendor's, so anyone who knows
		// the convention can set it.
		$this->assertSame( 'low', ( new Generic() )->get_confidence() );
	}
}
