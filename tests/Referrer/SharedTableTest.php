<?php
/**
 * The table of inputs shared with the JavaScript package.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Referrer;

use SugarCommerce\Network\Host;
use SugarCommerce\Network\Referrer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This library and @flarecart/referrer are the same rules written twice, and
 * two copies of a rule drift. tests/fixtures/sources.json is what holds them
 * together: every input the JavaScript suite asserts on, a few hundred more,
 * and the answer the JavaScript package gives for each.
 *
 * The file is in the JavaScript package's shape -- camelCase keys, its
 * function names as section names -- so the same file can be read by a test
 * over there. When a table changes on either side, regenerate it and see what
 * moved.
 *
 * An entry that carries `js` and `why` is one where this library answers
 * differently on purpose. `expect` is still what is asserted; `js` records
 * what the package returns today, so the difference is written down rather
 * than rediscovered.
 */
final class SharedTableTest extends TestCase {

	/**
	 * The fixture, decoded once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $fixture = null;

	/**
	 * analyse() files every input the way the JavaScript package does.
	 *
	 * @param array<string, mixed> $input  What the page knows, in the package's shape.
	 * @param array<string, mixed> $expect The source it should come back as.
	 */
	#[DataProvider( 'analyseProvider' )]
	public function test_a_visit_is_filed_the_same_way( array $input, array $expect ): void {
		$this->assertSame( $expect, self::in_js_shape( Referrer::analyse( self::in_php_shape( $input ) ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function analyseProvider(): array {
		$cases = [];

		foreach ( array_merge( self::section( 'analyse' ), self::section( 'query' ) ) as $index => $entry ) {
			$cases[ $index . ' ' . json_encode( $entry['input'] ) ] = [ $entry['input'], $entry['expect'] ];
		}

		return $cases;
	}

	/**
	 * The pieces analyse() is built from give the same answers too.
	 *
	 * @param string $method The method here.
	 * @param mixed  $input  Its argument, or its arguments as a list.
	 * @param mixed  $expect What it should return.
	 */
	#[DataProvider( 'pieceProvider' )]
	public function test_a_piece_gives_the_same_answer( string $method, mixed $input, mixed $expect ): void {
		$arguments = in_array( $method, [ 'is_internal', 'is_within' ], true ) ? $input : [ $input ];

		// The JavaScript table's isDomainOrSubdomain is Host::is_within() here.
		$callable = 'is_within' === $method ? [ Host::class, $method ] : [ Referrer::class, $method ];

		$this->assertSame( $expect, $callable( ...$arguments ) );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
	 */
	public static function pieceProvider(): array {
		$methods = [
			'safeUrl'             => 'safe_url',
			'toTag'               => 'to_tag',
			'hostOf'              => 'host_of',
			'rootDomain'          => 'root_domain',
			'landingPath'         => 'landing_path',
			'classifyAndroidApp'  => 'classify_android_app',
			'isIgnoredHost'       => 'is_ignored_host',
			'isInternal'          => 'is_internal',
			'isDomainOrSubdomain' => 'is_within',
		];

		$cases = [];

		foreach ( $methods as $section => $method ) {
			foreach ( self::section( $section ) as $index => $entry ) {
				$cases[ $section . ' ' . $index . ' ' . json_encode( $entry['input'] ) ] = [ $method, $entry['input'], $entry['expect'] ];
			}
		}

		return $cases;
	}

	/**
	 * The table is the size it was written at.
	 *
	 * A data provider that comes back empty passes: no cases, no failures. So
	 * a fixture that failed to load, or a section renamed on one side only,
	 * would read as agreement.
	 */
	public function test_the_table_was_actually_read(): void {
		$this->assertGreaterThanOrEqual( 200, count( self::analyseProvider() ) );
		$this->assertGreaterThanOrEqual( 100, count( self::pieceProvider() ) );
	}

	/**
	 * Every channel is exercised, so none of them is agreed on by default.
	 */
	public function test_the_table_covers_every_channel(): void {
		$seen = [];

		foreach ( self::section( 'analyse' ) as $entry ) {
			$seen[ $entry['expect']['channel'] ] = true;
		}

		foreach ( Referrer::CHANNELS as $channel ) {
			$this->assertArrayHasKey( $channel, $seen, sprintf( 'No input in the table is filed under "%s".', $channel ) );
		}
	}

	/**
	 * The differences are few, and each one says why.
	 *
	 * This is the list to shrink: every entry is something the JavaScript
	 * package should pick up, at which point `js` and `why` come off it.
	 */
	public function test_every_deliberate_difference_is_explained(): void {
		$differences = 0;

		foreach ( self::fixture() as $section ) {
			foreach ( is_array( $section ) ? $section : [] as $entry ) {
				if ( array_key_exists( 'js', $entry ) ) {
					++$differences;
					$this->assertNotSame( '', (string) ( $entry['why'] ?? '' ) );
					$this->assertNotSame( $entry['expect'], $entry['js'] );
				}
			}
		}

		$this->assertLessThanOrEqual( 15, $differences, 'A new difference from the JavaScript package: port it, or record why not.' );
	}

	/**
	 * One section of the fixture.
	 *
	 * @param string $name The section, named for the JavaScript function.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function section( string $name ): array {
		return self::fixture()[ $name ] ?? [];
	}

	/**
	 * The fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(): array {
		if ( null === self::$fixture ) {
			self::$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/sources.json' ), true, 512, JSON_THROW_ON_ERROR );
		}

		return self::$fixture;
	}

	/**
	 * An input in the package's shape, as analyse() takes it here.
	 *
	 * @param array<string, mixed> $input `referrer`, `url`, `query`, `inApp`, `siteUrl`, `ignore`.
	 *
	 * @return array<string, mixed>
	 */
	private static function in_php_shape( array $input ): array {
		$names = [
			'inApp'   => 'in_app',
			'siteUrl' => 'site_url',
		];

		$shaped = [];

		foreach ( $input as $name => $value ) {
			$shaped[ $names[ $name ] ?? $name ] = $value;
		}

		return $shaped;
	}

	/**
	 * A result from here, in the package's shape and key order.
	 *
	 * @param array<string, mixed> $source What analyse() returned.
	 *
	 * @return array<string, mixed>
	 */
	private static function in_js_shape( array $source ): array {
		return [
			'channel'      => $source['channel'],
			'source'       => $source['source'],
			'medium'       => $source['medium'],
			'campaign'     => $source['campaign'],
			'term'         => $source['term'],
			'content'      => $source['content'],
			'clickId'      => $source['click_id'],
			'inApp'        => $source['in_app'],
			'referrerHost' => $source['referrer_host'],
			'internal'     => $source['internal'],
			'label'        => $source['label'],
		];
	}
}
