<?php
/**
 * Resolver chain tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Country;

use SugarCommerce\Network\Country;
use SugarCommerce\Network\Country\Result;
use SugarCommerce\Network\Country\Sources\Callback;
use SugarCommerce\Network\Country\Sources\Cloudflare;
use SugarCommerce\Network\Country\Sources\Generic;
use PHPUnit\Framework\TestCase;

/**
 * Country is a static facade over an ordered list of sources, and both of
 * those words are the risk.
 *
 * Ordered, because the chain is the whole design: a Cloudflare header outranks
 * an X-Country-Code one, and a site that has registered its own MaxMind lookup
 * expects it consulted where it asked for it to be. Static, because the list
 * survives between calls — and, in a suite, between tests.
 */
final class CountryTest extends TestCase {

	/**
	 * Put the chain back to the bundled sources.
	 *
	 * The registry is static, so a test that registers a source would
	 * otherwise be visible to every test that runs after it. Which order
	 * PHPUnit picks is not something to rely on.
	 */
	protected function setUp(): void {
		Country::reset();
	}

	/**
	 * And again afterwards, so nothing leaks out of this file either.
	 */
	protected function tearDown(): void {
		Country::reset();
	}

	/**
	 * A source that always answers.
	 *
	 * @param string $code The country to return.
	 * @param string $name The source name.
	 *
	 * @return Callback
	 */
	private function always( string $code, string $name = 'stub' ): Callback {
		return new Callback( static fn(): string => $code, $name );
	}

	/**
	 * The first source that answers wins, and the rest are not consulted.
	 */
	public function test_the_first_source_to_answer_wins(): void {
		$reached = false;

		Country::set_sources(
			[
				$this->always( 'GB', 'first' ),
				new Callback(
					static function () use ( &$reached ) {
						$reached = true;

						return 'FR';
					},
					'second'
				),
			]
		);

		$result = Country::resolve_detailed( [] );

		$this->assertSame( 'GB', $result->get_country() );
		$this->assertSame( 'first', $result->get_source() );
		$this->assertFalse( $reached, 'A later source ran after an earlier one had answered.' );
	}

	/**
	 * A source that cannot answer hands on to the next.
	 */
	public function test_a_source_that_misses_hands_on(): void {
		Country::set_sources(
			[
				new Callback( static fn() => null, 'misses' ),
				$this->always( 'JP', 'answers' ),
			]
		);

		$this->assertSame( 'JP', Country::resolve( [] ) );
	}

	/**
	 * A prepended source runs before the bundled ones; an appended one after.
	 *
	 * This is the difference between "my GeoIP database is authoritative" and
	 * "use my GeoIP database when the CDN did not say", and getting it
	 * backwards would look like it worked — the answers are usually the same.
	 */
	public function test_prepend_outranks_the_defaults_and_add_does_not(): void {
		$server = [
			'HTTP_CF_IPCOUNTRY' => 'GB',
			'HTTP_CF_RAY'       => '1',
		];

		Country::add_source( $this->always( 'FR', 'appended' ) );
		$this->assertSame( 'cloudflare', Country::resolve_detailed( $server )->get_source() );

		Country::reset();

		Country::prepend_source( $this->always( 'FR', 'prepended' ) );
		$this->assertSame( 'prepended', Country::resolve_detailed( $server )->get_source() );
	}

	/**
	 * The default chain prefers the vendor headers over the generic one.
	 *
	 * Both are present here, which is the realistic case behind a CDN sitting
	 * in front of a load balancer. The vendor header is the one the vendor
	 * sets and the generic one is a convention anybody can write.
	 */
	public function test_a_vendor_header_outranks_the_generic_one(): void {
		$result = Country::resolve_detailed(
			[
				'HTTP_CF_IPCOUNTRY'   => 'GB',
				'HTTP_CF_RAY'         => '1',
				'HTTP_X_COUNTRY_CODE' => 'FR',
			]
		);

		$this->assertSame( 'GB', $result->get_country() );
		$this->assertSame( 'cloudflare', $result->get_source() );
		$this->assertSame( 'high', $result->get_confidence() );
	}

	/**
	 * An unbelievable vendor header does not block a lower-ranked one.
	 *
	 * The Cloudflare header is here without a Cloudflare signature, so it is
	 * refused — and refusing has to mean handing on, not answering "unknown".
	 */
	public function test_a_refused_header_does_not_stop_the_chain(): void {
		$result = Country::resolve_detailed(
			[
				'HTTP_CF_IPCOUNTRY'   => 'GB',
				'HTTP_X_COUNTRY_CODE' => 'FR',
			]
		);

		$this->assertSame( 'FR', $result->get_country() );
		$this->assertSame( 'generic', $result->get_source() );
	}

	/**
	 * Nothing resolving is a Result, not a null.
	 *
	 * The reason the library returns an envelope at all: logging and
	 * serialising code can treat a hit and a miss the same way.
	 */
	public function test_a_miss_still_returns_a_result(): void {
		$result = Country::resolve_detailed( [] );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertFalse( $result->has_country() );
		$this->assertSame( '', $result->get_country() );
		$this->assertSame( '', Country::resolve( [] ) );
		$this->assertSame(
			[
				'country'    => '',
				'source'     => '',
				'confidence' => '',
			],
			$result->to_array()
		);
	}

	/**
	 * A hit carries the same keys as a miss.
	 */
	public function test_a_hit_carries_the_same_shape(): void {
		Country::set_sources( [ $this->always( 'NL', 'stub' ) ] );

		$this->assertSame(
			[
				'country'    => 'NL',
				'source'     => 'stub',
				'confidence' => 'medium',
			],
			Country::resolve_detailed( [] )->to_array()
		);
	}

	/**
	 * A Result stringifies to the country code.
	 */
	public function test_a_result_stringifies_to_the_country(): void {
		Country::set_sources( [ $this->always( 'PT' ) ] );

		$this->assertSame( 'in PT', 'in ' . Country::resolve_detailed( [] ) );
		$this->assertSame( '', (string) Result::none() );
	}

	/**
	 * A Result upper-cases whatever it is handed.
	 */
	public function test_a_result_upper_cases_its_country(): void {
		$this->assertSame( 'GB', ( new Result( 'gb' ) )->get_country() );
	}

	/**
	 * Replacing the chain drops anything that is not a source.
	 *
	 * set_sources() takes a plain array, so the wrong thing can be handed in.
	 * Dropping it beats fataling on the next request in a resolver that runs
	 * on every page load.
	 */
	public function test_replacing_the_chain_drops_what_is_not_a_source(): void {
		Country::set_sources( [ $this->always( 'BE' ), 'nonsense', null, 42 ] );

		$this->assertCount( 1, Country::get_sources() );
		$this->assertSame( 'BE', Country::resolve( [] ) );
	}

	/**
	 * Replacing the chain leaves it indexed from zero.
	 */
	public function test_replacing_the_chain_reindexes(): void {
		Country::set_sources( [ 'nonsense', $this->always( 'BE' ) ] );

		$this->assertSame( [ 0 ], array_keys( Country::get_sources() ) );
	}

	/**
	 * Resetting restores the bundled sources.
	 */
	public function test_reset_restores_the_defaults(): void {
		Country::set_sources( [] );
		$this->assertSame( [], Country::get_sources() );

		Country::reset();

		$names = array_map( static fn( $one ) => $one->get_name(), Country::get_sources() );

		$this->assertSame(
			[ 'cloudflare', 'cloudfront', 'fastly', 'bunnycdn', 'server_geoip', 'generic' ],
			$names
		);
	}

	/**
	 * With no argument the resolver reads the live superglobal.
	 *
	 * The parameter exists so a test does not have to, but the default path is
	 * the one every caller uses and it is one `??` away from reading nothing.
	 */
	public function test_the_live_server_array_is_read_by_default(): void {
		$original = $_SERVER;

		try {
			$_SERVER = [
				'HTTP_CF_IPCOUNTRY' => 'SE',
				'HTTP_CF_RAY'       => '1',
			];

			$this->assertSame( 'SE', Country::resolve() );
		} finally {
			$_SERVER = $original;
		}
	}

	/**
	 * A source is only asked once per resolution.
	 *
	 * resolve() is implemented on top of resolve_detailed(), and a chain that
	 * reaches a paid lookup wants to reach it once.
	 */
	public function test_a_source_is_consulted_once( ): void {
		$calls = 0;

		Country::set_sources(
			[
				new Callback(
					static function () use ( &$calls ) {
						++$calls;

						return 'AT';
					}
				),
			]
		);

		Country::resolve( [] );

		$this->assertSame( 1, $calls );
	}

	/**
	 * A registered source is kept, not copied by value.
	 *
	 * add_source() has to initialise the chain before appending; an
	 * implementation that reads the getter and forgets to write back leaves
	 * the source registered nowhere.
	 */
	public function test_an_added_source_is_actually_registered(): void {
		Country::add_source( new Generic() );

		$this->assertCount( 7, Country::get_sources() );

		Country::prepend_source( new Cloudflare() );

		$this->assertCount( 8, Country::get_sources() );
		$this->assertSame( 'cloudflare', Country::get_sources()[0]->get_name() );
	}
}
