<?php
/**
 * What the PHP runtime allows, and the disk under it.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Environment;

use SugarCommerce\Network\Disk;
use SugarCommerce\Network\Php;
use PHPUnit\Framework\TestCase;

/**
 * These decide whether a plugin refuses to activate, so a wrong answer is
 * either a plugin that will not run where it would have been fine, or one
 * that runs where it cannot.
 */
final class PhpTest extends TestCase {

	/**
	 * A version requirement is compared as a version.
	 */
	public function test_version_requirements_compare_numerically(): void {
		$this->assertSame( PHP_VERSION, Php::version() );
		$this->assertTrue( Php::meets_version( '5.6' ) );
		$this->assertTrue( Php::meets_version( PHP_VERSION ) );
		$this->assertTrue( Php::meets_version( '8.3' ) );
		$this->assertFalse( Php::meets_version( '99.0' ) );
		$this->assertFalse( Php::meets_version( PHP_MAJOR_VERSION . '.' . ( PHP_MINOR_VERSION + 10 ) ) );
	}

	/**
	 * An extension this process has, and one nobody has.
	 */
	public function test_extensions_are_reported_from_the_runtime(): void {
		$this->assertTrue( Php::has_extension( 'json' ) );
		$this->assertFalse( Php::has_extension( 'not-a-real-extension' ) );
	}

	/**
	 * Only the missing ones come back, in the order asked for.
	 */
	public function test_missing_extensions_lists_what_is_absent(): void {
		$this->assertSame( [ 'nope-one', 'nope-two' ], Php::missing_extensions( [ 'nope-one', 'json', 'nope-two', 'pcre' ] ) );
		$this->assertSame( [], Php::missing_extensions( [ 'json', 'pcre' ] ) );
	}

	/**
	 * A function that exists is available; one that does not is not.
	 */
	public function test_functions(): void {
		$this->assertTrue( Php::has_function( 'strlen' ) );
		$this->assertFalse( Php::has_function( 'not_a_real_function' ) );
	}

	/**
	 * A function switched off in disable_functions is not available.
	 *
	 * The setting can only be made at start-up, so this runs a PHP of its
	 * own with it set.
	 */
	public function test_a_disabled_function_is_not_available(): void {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$code     = 'require ' . var_export( $autoload, true ) . '; echo json_encode( [ \SugarCommerce\Network\Php::has_function( "str_rot13" ), \SugarCommerce\Network\Php::has_function( "strrev" ) ] );';
		$output   = shell_exec( escapeshellarg( PHP_BINARY ) . ' -n -d disable_functions=str_rot13 -r ' . escapeshellarg( $code ) . ' 2>&1' );

		$this->assertSame( '[false,true]', trim( (string) $output ) );
	}

	/**
	 * The limits are bytes, not the shorthand the ini file holds.
	 */
	public function test_limits_come_back_as_bytes(): void {
		$limit = Php::memory_limit();

		$this->assertTrue( $limit > 1024 || -1 === $limit, 'Got ' . $limit );
		$this->assertSame( wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ), Php::upload_max_filesize() );
		$this->assertSame( wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ), Php::post_max_size() );
		$this->assertSame( (int) ini_get( 'max_execution_time' ), Php::max_execution_time() );
		$this->assertSame( (int) ini_get( 'max_input_vars' ), Php::max_input_vars() );
		$this->assertIsBool( Php::uploads_enabled() );
		$this->assertIsBool( Php::url_fopen_enabled() );
	}

	/**
	 * A memory requirement can be given in shorthand.
	 */
	public function test_a_memory_requirement_accepts_shorthand(): void {
		$original = ini_get( 'memory_limit' );

		try {
			ini_set( 'memory_limit', '128M' );

			$this->assertSame( 128 * 1024 * 1024, Php::memory_limit() );
			$this->assertTrue( Php::has_memory( '1K' ) );
			$this->assertTrue( Php::has_memory( 1024 ) );
			$this->assertFalse( Php::has_memory( '64G' ) );
			$this->assertFalse( Php::has_memory( 129 * 1024 * 1024 ) );
		} finally {
			ini_set( 'memory_limit', (string) $original );
		}
	}

	/**
	 * No limit at all satisfies any requirement.
	 */
	public function test_no_limit_satisfies_everything(): void {
		$original = ini_get( 'memory_limit' );

		try {
			ini_set( 'memory_limit', '-1' );

			$this->assertSame( -1, Php::memory_limit() );
			$this->assertTrue( Php::has_memory( '64G' ) );
		} finally {
			ini_set( 'memory_limit', (string) $original );
		}
	}

	/**
	 * Exactly one operating system is the one being run on, and it is
	 * PHP's own family.
	 */
	public function test_one_operating_system_answers_true(): void {
		$answers = array_filter(
			[
				'Windows' => Disk::is_windows(),
				'Linux'   => Disk::is_linux(),
				'Darwin'  => Disk::is_macos(),
			]
		);

		$this->assertSame( [ PHP_OS_FAMILY ], array_keys( $answers ) );
	}

	/**
	 * The disk the library is on has a size, some of it free.
	 */
	public function test_disk_space(): void {
		$space = Disk::space( __DIR__ );

		$this->assertIsArray( $space );
		$this->assertSame( [ 'total', 'free', 'used', 'percent' ], array_keys( $space ) );
		$this->assertGreaterThan( 0, $space['total'] );
		$this->assertEqualsWithDelta( $space['total'] - $space['free'], $space['used'], 1.0 );
		$this->assertGreaterThanOrEqual( 0, $space['percent'] );
		$this->assertLessThanOrEqual( 100, $space['percent'] );

		$this->assertNull( Disk::space( __DIR__ . '/no-such-folder' ) );
		$this->assertTrue( Disk::has_space( '1K', __DIR__ ) );
		$this->assertTrue( Disk::has_space( 1, __DIR__ ) );
		$this->assertFalse( Disk::has_space( PHP_INT_MAX, __DIR__ ) );
		$this->assertFalse( Disk::has_space( '1K', __DIR__ . '/no-such-folder' ) );
	}

	/**
	 * A disk that reports no size is 0% full, not a division by zero.
	 */
	public function test_a_zero_size_disk_is_not_a_division_by_zero(): void {
		$figures = new \ReflectionMethod( Disk::class, 'figures' );

		$this->assertSame(
			[
				'total'   => 0.0,
				'free'    => 0.0,
				'used'    => 0.0,
				'percent' => 0.0,
			],
			$figures->invoke( null, 0.0, 0.0 )
		);

		$this->assertSame( 75.0, $figures->invoke( null, 400.0, 100.0 )['percent'] );
	}
}
