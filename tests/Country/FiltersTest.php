<?php
/**
 * WordPress integration tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Country;

use SugarCommerce\Network\Country;
use SugarCommerce\Network\Models\Country\Result;
use SugarCommerce\Network\Country\Sources\Callback;
use PHPUnit\Framework\TestCase;

/**
 * Two hooks and an unslash, which is the whole of what this library asks of
 * WordPress.
 *
 * The hooks matter because the source registry is process-wide and a site
 * often wants a per-request answer: force a country while testing a geo-gated
 * checkout, override detection for a known office address, skip a paid lookup
 * for a logged-in administrator. Doing any of that through the static registry
 * means mutating global state on every request and remembering to put it back.
 */
final class FiltersTest extends TestCase {

	/**
	 * Both registries here are global, so both get cleared.
	 */
	protected function setUp(): void {
		Country::reset();
		vc_reset_filters();
	}

	/**
	 * And again, so nothing leaks into another file.
	 */
	protected function tearDown(): void {
		Country::reset();
		vc_reset_filters();
	}

	/**
	 * A filter has the last word on the resolved country.
	 */
	public function test_the_result_can_be_replaced_by_a_filter(): void {
		add_filter( 'visitor_country_result', static fn() => new Result( 'JP', 'forced', 'high' ) );

		$result = Country::resolve_detailed(
			[
				'HTTP_CF_IPCOUNTRY' => 'GB',
				'HTTP_CF_RAY'       => '1',
			]
		);

		$this->assertSame( 'JP', $result->get_country() );
		$this->assertSame( 'forced', $result->get_source() );

		// And through the plain accessor, which is what most callers use.
		$this->assertSame( 'JP', Country::resolve( [] ) );
	}

	/**
	 * A filter that returns something else does not break the request.
	 *
	 * This runs on every page load. A filter written by somebody else that
	 * returns a string, or forgets to return at all, should give the resolved
	 * answer rather than a fatal.
	 */
	public function test_a_filter_returning_the_wrong_thing_is_ignored(): void {
		add_filter( 'visitor_country_result', static fn() => 'GB' );

		$this->assertSame( 'FR', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'FR' ] ) );

		vc_reset_filters();
		add_filter( 'visitor_country_result', static fn() => null );

		$this->assertSame( 'FR', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'FR' ] ) );
	}

	/**
	 * The filter sees the result that was resolved, and the request.
	 */
	public function test_the_result_filter_receives_the_resolution_and_the_request(): void {
		$seen_result = null;
		$seen_server = null;

		add_filter(
			'visitor_country_result',
			static function ( $result, $server ) use ( &$seen_result, &$seen_server ) {
				$seen_result = $result;
				$seen_server = $server;

				return $result;
			}
		);

		Country::resolve_detailed( [ 'HTTP_X_COUNTRY_CODE' => 'IE' ] );

		$this->assertInstanceOf( Result::class, $seen_result );
		$this->assertSame( 'IE', $seen_result->get_country() );
		$this->assertSame( [ 'HTTP_X_COUNTRY_CODE' => 'IE' ], $seen_server );
	}

	/**
	 * A miss reaches the filter too.
	 *
	 * Overriding detection is most useful precisely when nothing resolved --
	 * a site behind no CDN at all wants to plug in its own answer.
	 */
	public function test_a_miss_reaches_the_result_filter(): void {
		add_filter( 'visitor_country_result', static fn( $result ) => $result->has_country() ? $result : new Result( 'US', 'fallback', 'low' ) );

		$this->assertSame( 'US', Country::resolve( [] ) );
	}

	/**
	 * The source chain can be changed for one request without touching the
	 * registry.
	 */
	public function test_the_source_chain_can_be_filtered_per_request(): void {
		add_filter(
			'visitor_country_sources',
			static fn( array $sources ) => array_merge(
				[ new Callback( static fn(): string => 'NO', 'per_request' ) ],
				$sources
			)
		);

		$this->assertSame( 'per_request', Country::resolve_detailed( [] )->get_source() );

		// The registry itself is untouched, so the next request without the
		// filter behaves as it always did.
		vc_reset_filters();

		$this->assertSame( '', Country::resolve( [] ) );
	}

	/**
	 * A filter that puts something odd in the chain is stepped over.
	 */
	public function test_a_non_source_in_the_filtered_chain_is_skipped(): void {
		add_filter(
			'visitor_country_sources',
			static fn( array $sources ) => array_merge( [ 'nonsense', null ], $sources )
		);

		$this->assertSame( 'FR', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'FR' ] ) );
	}

	/**
	 * A filter can empty the chain entirely.
	 */
	public function test_the_chain_can_be_emptied_by_a_filter(): void {
		add_filter( 'visitor_country_sources', static fn() => [] );

		$this->assertSame(
			'',
			Country::resolve(
				[
					'HTTP_CF_IPCOUNTRY' => 'GB',
					'HTTP_CF_RAY'       => '1',
				]
			)
		);
	}

	/**
	 * The superglobal is unslashed before it is read.
	 *
	 * WordPress adds backslashes to $_SERVER on load, and they were never in
	 * the request. A country code has no character that would be escaped, so
	 * the visible symptom is not a mangled country -- it is a signature header
	 * that no longer matches what the CDN sent, and a country that silently
	 * stops resolving.
	 */
	public function test_the_superglobal_is_unslashed(): void {
		$original = $_SERVER;

		try {
			// What WordPress hands back for a CF-Visitor of {"scheme":"https"}.
			$_SERVER = [
				'HTTP_CF_IPCOUNTRY' => 'GB',
				'HTTP_CF_VISITOR'   => '{\"scheme\":\"https\"}',
			];

			$result = Country::resolve_detailed();

			$this->assertSame( 'GB', $result->get_country() );

			$seen = null;

			add_filter(
				'visitor_country_result',
				static function ( $r, $server ) use ( &$seen ) {
					$seen = $server['HTTP_CF_VISITOR'];

					return $r;
				}
			);

			Country::resolve_detailed();

			$this->assertSame( '{"scheme":"https"}', $seen, 'The server array was read with its slashes still on.' );
		} finally {
			$_SERVER = $original;
		}
	}
}
