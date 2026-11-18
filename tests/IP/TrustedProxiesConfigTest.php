<?php
/**
 * IP::trusted_proxies(), configured by the new names and the old ones.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use SugarCommerce\Network\TrustedProxies;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * SUGARCOMMERCE_TRUSTED_PROXIES and the sugarcommerce_trusted_proxies
 * filter are the names to use. Sites that set ARRAYPRESS_TRUSTED_PROXIES
 * or the arraypress_trusted_proxies filter keep working: the constants'
 * lists are joined, and the old filter runs before the new one.
 *
 * A constant cannot be undefined, so each case runs in its own process.
 */
final class TrustedProxiesConfigTest extends TestCase {

	protected function setUp(): void {
		vc_reset_filters();
	}

	protected function tearDown(): void {
		vc_reset_filters();
	}

	public function test_nothing_set_is_local_and_cloudflare(): void {
		$this->assertSame( array_merge( TrustedProxies::LOCAL, TrustedProxies::CLOUDFLARE ), IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_new_constant_alone(): void {
		define( 'SUGARCOMMERCE_TRUSTED_PROXIES', ' 203.0.113.5 , 198.51.100.0/24,' );

		$this->assertSame( [ '203.0.113.5', '198.51.100.0/24' ], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_old_constant_alone(): void {
		define( 'ARRAYPRESS_TRUSTED_PROXIES', '203.0.113.5,198.51.100.0/24' );

		$this->assertSame( [ '203.0.113.5', '198.51.100.0/24' ], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_old_constant_as_a_list(): void {
		define( 'ARRAYPRESS_TRUSTED_PROXIES', [ 'a' => '192.0.2.1' ] );

		$this->assertSame( [ '192.0.2.1' ], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_an_empty_old_constant_trusts_nothing(): void {
		define( 'ARRAYPRESS_TRUSTED_PROXIES', '' );

		$this->assertSame( [], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_both_constants_are_joined_without_repeats(): void {
		define( 'SUGARCOMMERCE_TRUSTED_PROXIES', '203.0.113.5,198.51.100.0/24' );
		define( 'ARRAYPRESS_TRUSTED_PROXIES', [ '198.51.100.0/24', '192.0.2.1' ] );

		$this->assertSame( [ '203.0.113.5', '198.51.100.0/24', '192.0.2.1' ], IP::trusted_proxies() );
	}

	public function test_the_new_filter_alone(): void {
		add_filter( 'sugarcommerce_trusted_proxies', fn( array $ranges ) => [ ...$ranges, '192.0.2.9' ] );

		$this->assertSame(
			[ ...TrustedProxies::LOCAL, ...TrustedProxies::CLOUDFLARE, '192.0.2.9' ],
			IP::trusted_proxies()
		);
	}

	public function test_the_old_filter_alone(): void {
		add_filter( 'arraypress_trusted_proxies', fn() => [ '192.0.2.8' ] );

		$this->assertSame( [ '192.0.2.8' ], IP::trusted_proxies() );
	}

	public function test_the_old_filter_runs_first_and_the_new_one_sees_its_work(): void {
		add_filter( 'arraypress_trusted_proxies', fn( array $ranges ) => [ '192.0.2.8' ] );
		add_filter( 'sugarcommerce_trusted_proxies', fn( array $ranges ) => [ ...$ranges, '192.0.2.9' ] );

		$this->assertSame( [ '192.0.2.8', '192.0.2.9' ], IP::trusted_proxies() );
	}

	public function test_a_filter_that_returns_no_list_is_ignored(): void {
		add_filter( 'arraypress_trusted_proxies', fn() => 'nonsense' );
		add_filter( 'sugarcommerce_trusted_proxies', fn() => null );

		$this->assertSame( array_merge( TrustedProxies::LOCAL, TrustedProxies::CLOUDFLARE ), IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_old_filter_sees_the_old_constant(): void {
		define( 'ARRAYPRESS_TRUSTED_PROXIES', '203.0.113.5' );
		add_filter( 'arraypress_trusted_proxies', fn( array $ranges ) => [ ...$ranges, '192.0.2.8' ] );

		$this->assertSame( [ '203.0.113.5', '192.0.2.8' ], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_both_constants_and_both_filters(): void {
		define( 'SUGARCOMMERCE_TRUSTED_PROXIES', '203.0.113.5' );
		define( 'ARRAYPRESS_TRUSTED_PROXIES', '198.51.100.0/24' );
		add_filter( 'arraypress_trusted_proxies', fn( array $ranges ) => [ ...$ranges, '192.0.2.8' ] );
		add_filter( 'sugarcommerce_trusted_proxies', fn( array $ranges ) => [ ...$ranges, '192.0.2.9' ] );

		$this->assertSame( [ '203.0.113.5', '198.51.100.0/24', '192.0.2.8', '192.0.2.9' ], IP::trusted_proxies() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_old_constant_reaches_get_and_trust(): void {
		define( 'ARRAYPRESS_TRUSTED_PROXIES', '203.0.113.5' );
		$_SERVER['REMOTE_ADDR']          = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';

		$this->assertSame( '8.8.8.8', IP::get() );
		$this->assertTrue( IP::trust()->trusts( '203.0.113.5' ) );
		$this->assertFalse( IP::trust()->trusts( '127.0.0.1' ) );
	}
}
