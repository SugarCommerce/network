<?php
/**
 * A named header, the connection gate, and the filter names.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Country;

use SugarCommerce\Network\Country;
use SugarCommerce\Network\Models\Country\Result;
use SugarCommerce\Network\Country\Sources\Cloudflare;
use SugarCommerce\Network\Country\Sources\Generic;
use SugarCommerce\Network\Country\Sources\Header;
use SugarCommerce\Network\TrustedProxies;
use PHPUnit\Framework\TestCase;

/**
 * Class HeaderTest
 */
final class HeaderTest extends TestCase {

	protected function setUp(): void {
		vc_reset_filters();
		Country::reset();
		Country::use_prefix( '' );
	}

	protected function tearDown(): void {
		vc_reset_filters();
		Country::reset();
		Country::use_prefix( '' );
	}

	/**
	 * A header the bundled sources do not know is read by name.
	 */
	public function test_a_named_header_is_read(): void {
		$source = new Header( 'X-Geo-Country', 'balancer', 'high' );

		$this->assertSame( 'FR', $source->resolve( [ 'HTTP_X_GEO_COUNTRY' => ' fr ' ] ) );
		$this->assertSame( 'balancer', $source->get_name() );
		$this->assertSame( 'high', $source->get_confidence() );
		$this->assertNull( $source->resolve( [] ) );
	}

	/**
	 * A name pasted in PHP's spelling is the same header.
	 */
	public function test_the_name_may_be_given_in_php_spelling(): void {
		$this->assertSame( 'FR', ( new Header( 'HTTP_X_GEO_COUNTRY' ) )->resolve( [ 'HTTP_X_GEO_COUNTRY' => 'FR' ] ) );
	}

	/**
	 * XX, T1 and anything not two letters are no country.
	 */
	public function test_a_named_header_refuses_what_is_not_a_country(): void {
		$source = new Header( 'X-Geo-Country' );

		foreach ( [ 'XX', 'xx', 'T1', 'GBR', 'G', "GB\nX", '' ] as $value ) {
			$this->assertNull( $source->resolve( [ 'HTTP_X_GEO_COUNTRY' => $value ] ), $value );
		}

		$this->assertNull( $source->resolve( [ 'HTTP_X_GEO_COUNTRY' => [ 'GB' ] ] ) );
	}

	/**
	 * With proxies named, the header is believed only from them.
	 */
	public function test_with_proxies_the_header_is_believed_only_from_them(): void {
		$source = new Header( 'X-Geo-Country', 'balancer', 'high', TrustedProxies::none()->and_also( [ '10.0.0.0/8' ] ) );

		$this->assertSame( 'DE', $source->resolve( [ 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_GEO_COUNTRY' => 'DE' ] ) );
		$this->assertNull( $source->resolve( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_GEO_COUNTRY' => 'DE' ] ) );
		$this->assertNull( $source->resolve( [ 'HTTP_X_GEO_COUNTRY' => 'DE' ] ) );
	}

	/**
	 * A load balancer that is the only way in may be trusted from any address.
	 */
	public function test_a_list_trusted_from_any_address_reads_from_any_address(): void {
		$source = new Header( 'X-Geo-Country', 'balancer', 'high', TrustedProxies::none()->from_any_address() );

		$this->assertSame( 'DE', $source->resolve( [ 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_GEO_COUNTRY' => 'DE' ] ) );
	}

	/**
	 * A forged signature no longer gets a spoofed Cloudflare header through,
	 * once Cloudflare's addresses are named.
	 */
	public function test_cloudflare_with_its_ranges_refuses_a_forged_signature(): void {
		$forged = [
			'REMOTE_ADDR'       => '203.0.113.9',
			'HTTP_CF_IPCOUNTRY' => 'US',
			'HTTP_CF_RAY'       => '8a1b2c3d4e5f6789-LHR',
		];
		$real   = [ 'REMOTE_ADDR' => '104.16.0.1' ] + $forged;

		$this->assertSame( 'US', ( new Cloudflare() )->resolve( $forged ), 'The signature gate alone believes it.' );
		$this->assertNull( ( new Cloudflare( TrustedProxies::cloudflare() ) )->resolve( $forged ) );
		$this->assertSame( 'US', ( new Cloudflare( TrustedProxies::cloudflare() ) )->resolve( $real ) );

		// Both gates hold: the address alone is not enough for a gated source.
		$this->assertNull( ( new Cloudflare( TrustedProxies::cloudflare() ) )->resolve( [ 'REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_IPCOUNTRY' => 'US' ] ) );
	}

	/**
	 * The filters are named after the default prefix until told otherwise.
	 */
	public function test_the_filters_keep_their_names_by_default(): void {
		$this->assertSame( 'visitor_country_sources', Country::hook( 'sources' ) );
		$this->assertSame( 'visitor_country_result', Country::hook( 'result' ) );
	}

	/**
	 * A plugin can give its copy filters of its own.
	 */
	public function test_a_plugin_can_name_its_own_filters(): void {
		Country::set_sources( [ new Generic() ] );
		add_filter( 'visitor_country_result', static fn(): Result => new Result( 'FR', 'other-plugin', 'high' ) );
		add_filter( 'myplugin_country_result', static fn( Result $result ): Result => new Result( 'IE', 'mine', 'high' ) );

		Country::use_prefix( 'my-plugin!' );

		$this->assertSame( 'myplugin_country_result', Country::hook( 'result' ) );
		$this->assertSame( 'IE', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'GB' ] ) );

		Country::use_prefix( '' );

		$this->assertSame( 'FR', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'GB' ] ) );
	}

	/**
	 * The sources filter follows the prefix too.
	 */
	public function test_the_sources_filter_follows_the_prefix(): void {
		Country::use_prefix( 'myplugin' );
		add_filter( 'myplugin_country_sources', static fn(): array => [] );

		$this->assertSame( '', Country::resolve( [ 'HTTP_X_COUNTRY_CODE' => 'GB' ] ) );
	}
}
