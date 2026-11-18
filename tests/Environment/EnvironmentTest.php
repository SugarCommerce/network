<?php
/**
 * Which kind of site this is.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Environment;

use SugarCommerce\Network\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Getting this wrong is not cosmetic. A plugin asks whether it is on
 * production to decide whether to run a licence check, take a real
 * payment or report an analytics event -- so a live site told it is
 * staging quietly stops doing the things it is there to do.
 */
final class EnvironmentTest extends TestCase {

	protected function setUp(): void {
		env_reset();
	}

	protected function tearDown(): void {
		env_reset();
	}

	/**
	 * Point the stubs at a site.
	 *
	 * @param string $url The site URL.
	 */
	private function site( string $url ): void {
		$GLOBALS['ru_site_url'] = $url;
	}

	/**
	 * Exactly one of the four answers true, and it is type().
	 *
	 * @param string $type The expected type.
	 */
	private function assertType( string $type ): void {
		$this->assertSame( $type, Environment::type() );

		$answers = [
			Environment::LOCAL       => Environment::is_local(),
			Environment::DEVELOPMENT => Environment::is_development(),
			Environment::STAGING     => Environment::is_staging(),
			Environment::PRODUCTION  => Environment::is_production(),
		];

		$this->assertSame( [ $type ], array_keys( array_filter( $answers ) ) );
	}

	/**
	 * Hosts that are somebody's own machine.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function local_hosts(): array {
		return [
			'localhost'           => [ 'http://localhost' ],
			'localhost on a port' => [ 'http://localhost:8080' ],
			'a .localhost name'   => [ 'http://shop.localhost' ],
			'loopback'            => [ 'http://127.0.0.1' ],
			'IPv6 loopback'       => [ 'http://[::1]:8080' ],
			'a .test domain'      => [ 'http://mysite.test' ],
			'a .local domain'     => [ 'http://mysite.local' ],
			'a .example domain'   => [ 'http://shop.example' ],
			'a .invalid domain'   => [ 'http://shop.invalid' ],
			'wordpress-develop'   => [ 'http://src.wordpress-develop.test' ],
			'localhost.localdomain' => [ 'http://localhost.localdomain' ],
			'a private range'     => [ 'http://192.168.1.10' ],
			'the 10 range'        => [ 'http://10.0.0.5' ],
			'carrier-grade NAT'   => [ 'http://100.64.0.1' ],
			'a private IPv6'      => [ 'http://[fd00::1]' ],
			'no host at all'      => [ '' ],
		];
	}

	/**
	 * @param string $url The site URL.
	 */
	#[DataProvider( 'local_hosts' )]
	public function test_a_local_host_is_local( string $url ): void {
		$this->site( $url );

		$this->assertType( Environment::LOCAL );
	}

	/**
	 * `.dev` is a real public ending, not a local one.
	 *
	 * Google runs it, and browsers preload it as HTTPS-only. A live shop at
	 * `shop.dev` was told it was somebody's laptop.
	 */
	public function test_dev_is_not_local(): void {
		$this->site( 'https://shop.dev' );

		$this->assertFalse( Environment::is_local_host( 'shop.dev' ) );
		$this->assertType( Environment::PRODUCTION );
	}

	/**
	 * Public addresses and ordinary names are not local.
	 *
	 * @param string $host The host.
	 */
	#[DataProvider( 'public_hosts' )]
	public function test_a_public_host_is_not_local( string $host ): void {
		$this->assertFalse( Environment::is_local_host( $host ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function public_hosts(): array {
		return [
			'a public address'    => [ '8.8.8.8' ],
			'a public IPv6'       => [ '[2606:4700::1111]' ],
			'an ordinary domain'  => [ 'example.com' ],
			'localhost as a label' => [ 'localhost.evil.com' ],
			'test as a label'     => [ 'test.example.com' ],
			'local in a word'     => [ 'mylocal' ],
			'a .dev domain'       => [ 'shop.dev' ],
		];
	}

	/**
	 * Hosts that are a staging copy.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function staging_hosts(): array {
		return [
			'a staging subdomain' => [ 'https://staging.example.com' ],
			'a stage subdomain'   => [ 'https://stage.example.com' ],
			'a test subdomain'    => [ 'https://test.example.com' ],
			'a demo subdomain'    => [ 'https://demo.example.com' ],
			'a staging suffix'    => [ 'https://example.staging' ],
		];
	}

	/**
	 * @param string $url The site URL.
	 */
	#[DataProvider( 'staging_hosts' )]
	public function test_a_staging_host_is_staging( string $url ): void {
		$this->site( $url );

		$this->assertType( Environment::STAGING );
	}

	/**
	 * Hosts that merely contain one of those words.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function production_hosts(): array {
		return [
			'latest'              => [ 'https://latest.example.com' ],
			'a word ending in it' => [ 'https://protest.example.com' ],
			'a word starting it'  => [ 'https://staging-archive.example.com' ],
			'in the path only'    => [ 'https://example.com/demo/page' ],
			'a plain host'        => [ 'https://example.com' ],
		];
	}

	/**
	 * @param string $url The site URL.
	 */
	#[DataProvider( 'production_hosts' )]
	public function test_a_word_inside_a_label_is_not_staging( string $url ): void {
		$this->site( $url );

		$this->assertType( Environment::PRODUCTION );
	}

	/**
	 * A local host that is also named staging is local: the more specific.
	 */
	public function test_local_wins_over_a_staging_name(): void {
		$this->site( 'http://staging.mysite.test' );

		$this->assertType( Environment::LOCAL );
	}

	/**
	 * The home address counts as well as the site address.
	 */
	public function test_the_home_address_counts(): void {
		$GLOBALS['ru_home_url'] = 'http://shop.test';

		$this->assertType( Environment::LOCAL );
	}

	/**
	 * What the site owner set wins over every guess.
	 *
	 * @param string $declared WP_ENVIRONMENT_TYPE.
	 * @param string $url      The site URL, which would guess otherwise.
	 * @param string $type     The answer.
	 */
	#[DataProvider( 'declared' )]
	public function test_wordpress_is_asked_first( string $declared, string $url, string $type ): void {
		$this->site( $url );
		putenv( 'WP_ENVIRONMENT_TYPE=' . $declared );

		$this->assertType( $type );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function declared(): array {
		return [
			'staging on a live-looking host'      => [ 'staging', 'https://example.com', Environment::STAGING ],
			'development on a live-looking host'  => [ 'development', 'https://example.com', Environment::DEVELOPMENT ],
			'local on a live-looking host'        => [ 'local', 'https://example.com', Environment::LOCAL ],
			// Said outright, production is believed even on a .test host --
			// the default is the only production that is guessed past.
			'production on a local-looking host'  => [ 'production', 'http://shop.test', Environment::PRODUCTION ],
			'production on a staging-looking host' => [ 'production', 'https://staging.example.com', Environment::PRODUCTION ],
			// Not a type WordPress knows: production, as WordPress says.
			'nonsense'                            => [ 'qa', 'http://shop.test', Environment::PRODUCTION ],
		];
	}

	/**
	 * The usual variables, when WordPress was told nothing.
	 *
	 * @param string $name  The variable.
	 * @param string $value Its value.
	 * @param string $type  The answer.
	 */
	#[DataProvider( 'variables' )]
	public function test_the_usual_variables( string $name, string $value, string $type ): void {
		putenv( $name . '=' . $value );

		$this->assertType( $type );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function variables(): array {
		return [
			'WP_ENV staging'           => [ 'WP_ENV', 'staging', Environment::STAGING ],
			'ENVIRONMENT stage'        => [ 'ENVIRONMENT', 'Stage', Environment::STAGING ],
			'APP_ENV staging'          => [ 'APP_ENV', 'staging', Environment::STAGING ],
			'WP_ENV development'       => [ 'WP_ENV', 'development', Environment::DEVELOPMENT ],
			'APP_ENV local'            => [ 'APP_ENV', 'local', Environment::LOCAL ],
			'WP_ENV production'        => [ 'WP_ENV', 'production', Environment::PRODUCTION ],
		];
	}

	/**
	 * WP_DEBUG is the last sign of a development site, never a reason to
	 * call a declared production site anything else.
	 *
	 * A live shop with WP_DEBUG on to log an error was never production,
	 * so it skipped its licence check and took test payments.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_debug_is_the_last_fallback(): void {
		define( 'WP_DEBUG', true );

		$this->assertType( Environment::DEVELOPMENT );

		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$this->assertType( Environment::PRODUCTION );
		putenv( 'WP_ENVIRONMENT_TYPE' );

		// And it comes after the host.
		$this->site( 'https://staging.example.com' );
		$this->assertType( Environment::STAGING );
	}

	/**
	 * The constant is read as well as the environment variable, and wins.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_constant_is_read(): void {
		define( 'WP_ENVIRONMENT_TYPE', 'staging' );
		putenv( 'WP_ENVIRONMENT_TYPE=development' );

		$this->assertType( Environment::STAGING );
	}

	/**
	 * Production is what is left.
	 */
	public function test_production_is_what_is_left(): void {
		$this->site( 'https://example.com' );

		$this->assertType( Environment::PRODUCTION );
		$this->assertSame( 'production', Environment::PRODUCTION );
		$this->assertSame( 'local', Environment::LOCAL );
	}
}
