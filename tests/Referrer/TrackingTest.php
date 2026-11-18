<?php
/**
 * Tracking parameter tests.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Referrer;

use SugarCommerce\Network\Referrer\Tracking;
use PHPUnit\Framework\TestCase;

/**
 * Covers Tracking.
 */
class TrackingTest extends TestCase {

	/**
	 * The utm_ family is removed whole, including names added after this was written.
	 *
	 * @return void
	 */
	public function test_strips_utm_family(): void {
		$url = 'https://example.com/p?utm_source=nl&utm_medium=email&utm_campaign=spring&utm_marketing_tactic=x&sku=A1';

		$this->assertSame( 'https://example.com/p?sku=A1', Tracking::strip( $url ) );
	}

	/**
	 * Vendor click identifiers are removed by exact name.
	 *
	 * @return void
	 */
	public function test_strips_click_ids(): void {
		$url = 'https://example.com/?gclid=1&fbclid=2&msclkid=3&ttclid=4&yclid=5&keep=6';

		$this->assertSame( 'https://example.com/?keep=6', Tracking::strip( $url ) );
	}

	/**
	 * Generic application parameters survive.
	 *
	 * This is the regression the curated list exists for: `s` is WordPress's own
	 * search parameter and `v` is a YouTube video id. A list that removes them
	 * silently destroys the URLs it is asked to clean.
	 *
	 * @return void
	 */
	public function test_preserves_generic_parameters(): void {
		$cases = [
			'https://example.com/?s=blue+widget',
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'https://example.com/shop?type=shirt&price=20&from=nav&ref=abc&tag=sale',
			'https://example.com/p?u=1&h=2&x=3&b=4&r=5&t=6',
		];

		foreach ( $cases as $url ) {
			$this->assertSame( $url, Tracking::strip( $url ), $url );
			$this->assertFalse( Tracking::has( $url ), $url );
		}
	}

	/**
	 * Analytics families are matched by prefix.
	 *
	 * @return void
	 */
	public function test_strips_prefix_families(): void {
		$url = 'https://example.com/?pk_campaign=a&mtm_kwd=b&matomo_x=c&piwik_y=d&hsa_acc=e&_hsenc=f&__hstc=g&stm_source=h&id=9';

		$this->assertSame( 'https://example.com/?id=9', Tracking::strip( $url ) );
	}

	/**
	 * A URL with no query string is returned untouched.
	 *
	 * @return void
	 */
	public function test_url_without_query_is_unchanged(): void {
		$this->assertSame( 'https://example.com/page', Tracking::strip( 'https://example.com/page' ) );
		$this->assertFalse( Tracking::has( 'https://example.com/page' ) );
		$this->assertSame( [], Tracking::extract( 'https://example.com/page' ) );
	}

	/**
	 * A URL whose parameters are all tracking loses its query string entirely.
	 *
	 * @return void
	 */
	public function test_strips_every_parameter(): void {
		$this->assertSame( 'https://example.com/', Tracking::strip( 'https://example.com/?utm_source=a&gclid=b' ) );
	}

	/**
	 * The keep list wins over both the exact names and the prefixes.
	 *
	 * @return void
	 */
	public function test_keep_list_preserves_parameters(): void {
		$url = 'https://example.com/?utm_source=a&utm_medium=b&gclid=c';

		$this->assertSame(
			'https://example.com/?utm_source=a',
			Tracking::strip( $url, [], [ 'utm_source' ] )
		);
	}

	/**
	 * The extra list removes parameters the global list deliberately omits.
	 *
	 * @return void
	 */
	public function test_extra_list_removes_site_specific_parameters(): void {
		$this->assertSame(
			'https://example.com/?x=2',
			Tracking::strip( 'https://example.com/?v=1&x=2', [ 'v' ] )
		);
	}

	/**
	 * extract() returns the tracking parameters and their values.
	 *
	 * @return void
	 */
	public function test_extract_returns_name_value_pairs(): void {
		$found = Tracking::extract( 'https://example.com/?utm_source=nl&fbclid=xyz&sku=A1' );

		$this->assertSame(
			[
				'utm_source' => 'nl',
				'fbclid'     => 'xyz',
			],
			$found
		);
	}

	/**
	 * extract() honours the keep and extra lists, so it agrees with strip().
	 *
	 * @return void
	 */
	public function test_extract_honours_keep_and_extra(): void {
		$url = 'https://example.com/?utm_source=a&v=1';

		$this->assertSame( [ 'v' => '1' ], Tracking::extract( $url, [ 'v' ], [ 'utm_source' ] ) );
	}

	/**
	 * An array-valued tracking parameter yields an empty string, not a PHP notice.
	 *
	 * @return void
	 */
	public function test_extract_flattens_array_values(): void {
		$this->assertSame( [ 'utm_source' => '' ], Tracking::extract( 'https://example.com/?utm_source[]=a&utm_source[]=b' ) );
	}

	/**
	 * A tracking parameter with no value is still detected.
	 *
	 * @return void
	 */
	public function test_valueless_parameter_is_detected(): void {
		$url = 'https://example.com/?gclid&sku=A1';

		$this->assertTrue( Tracking::has( $url ) );
		$this->assertSame( [ 'gclid' => '' ], Tracking::extract( $url ) );
	}

	/**
	 * has() reports true only when something would actually be removed.
	 *
	 * @return void
	 */
	public function test_has_matches_strip(): void {
		$this->assertTrue( Tracking::has( 'https://example.com/?utm_source=a' ) );
		$this->assertFalse( Tracking::has( 'https://example.com/?sku=A1' ) );
		$this->assertFalse( Tracking::has( 'https://example.com/?utm_source=a', [], [ 'utm_source' ] ) );
	}

	/**
	 * The fragment is preserved.
	 *
	 * @return void
	 */
	public function test_fragment_survives(): void {
		$this->assertSame(
			'https://example.com/p?sku=A1#reviews',
			Tracking::strip( 'https://example.com/p?utm_source=a&sku=A1#reviews' )
		);
	}

	/**
	 * No parameter is short enough to collide with an application parameter.
	 *
	 * Guards the curated list against regaining the entries that made the
	 * previous implementation destructive.
	 *
	 * @return void
	 */
	public function test_no_parameter_is_dangerously_short(): void {
		foreach ( Tracking::PARAMS as $param ) {
			$this->assertGreaterThanOrEqual( 3, strlen( $param ), $param );
		}
	}

	/**
	 * Neither table repeats itself, and no exact name is already covered by a prefix.
	 *
	 * @return void
	 */
	public function test_tables_have_no_redundant_entries(): void {
		$this->assertSame( Tracking::PARAMS, array_values( array_unique( Tracking::PARAMS ) ) );
		$this->assertSame( Tracking::PREFIXES, array_values( array_unique( Tracking::PREFIXES ) ) );

		foreach ( Tracking::PARAMS as $param ) {
			foreach ( Tracking::PREFIXES as $prefix ) {
				$this->assertFalse(
					str_starts_with( $param, $prefix ),
					"{$param} is already covered by the {$prefix} prefix."
				);
			}
		}
	}

	/**
	 * A parameter name that merely contains a prefix is not matched.
	 *
	 * @return void
	 */
	public function test_prefix_matches_only_at_the_start(): void {
		$url = 'https://example.com/?my_utm_source=a&sort_pk_id=b';

		$this->assertSame( $url, Tracking::strip( $url ) );
	}

}
