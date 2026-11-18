<?php
/**
 * The table of agents shared with the JavaScript package.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     2.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\UserAgent;

use SugarCommerce\Network\ClientHints;
use SugarCommerce\Network\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This library and `@flarecart/user-agent` are the same rules written
 * twice, and two copies of a rule drift. tests/fixtures/agents.json is
 * what holds them together: every agent the JavaScript suite asserts on,
 * the ones the two were compared with, and what the JavaScript package
 * says about each.
 *
 * The file is in the package's shape — camelCase keys, its function
 * names as section names — so a test over there can read the same file.
 * When a list changes on either side, regenerate it and see what moved.
 */
final class SharedTableTest extends TestCase {

	/**
	 * The fixture, decoded once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $fixture = null;

	#[DataProvider( 'agents' )]
	public function test_an_agent_is_read_the_same_way( string $agent, array $expect ): void {
		$parsed = UserAgent::parse( $agent );

		$this->assertSame(
			$expect,
			array(
				'browser'     => $parsed['browser'],
				'version'     => $parsed['version'],
				'os'          => $parsed['os'],
				'device'      => $parsed['device'],
				'bot'         => $parsed['bot'],
				'botName'     => $parsed['bot_name'],
				'aiBot'       => $parsed['ai_bot'],
				'aiUserAgent' => $parsed['ai_user_agent'],
				'tool'        => $parsed['tool'],
				'inApp'       => $parsed['in_app'],
				'linkPreview' => $parsed['link_preview'],
				'label'       => $parsed['label'],
			)
		);
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>}> */
	public static function agents(): array {
		$cases = array();

		foreach ( self::section( 'parse' ) as $index => $entry ) {
			$cases[ $index . ' ' . $entry['input'] ] = array( $entry['input'], $entry['expect'] );
		}

		return $cases;
	}

	#[DataProvider( 'hints' )]
	public function test_client_hints_are_read_the_same_way( array $headers, array $expect ): void {
		$server = array();

		// `sec-ch-ua-mobile` over there is HTTP_SEC_CH_UA_MOBILE here.
		foreach ( $headers as $name => $value ) {
			$server[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = $value;
		}

		$read = ClientHints::read( $server );

		$this->assertSame(
			$expect,
			array(
				'available'       => $read['available'],
				'brands'          => $read['brands'],
				'browser'         => $read['browser'],
				'platform'        => $read['platform'],
				'platformVersion' => $read['platform_version'],
				'windowsVersion'  => $read['windows_version'],
				'mobile'          => $read['mobile'],
				'model'           => $read['model'],
				'architecture'    => $read['architecture'],
			)
		);
	}

	/** @return array<string, array{0: array<string, string>, 1: array<string, mixed>}> */
	public static function hints(): array {
		$cases = array();

		foreach ( self::section( 'clientHints' ) as $index => $entry ) {
			$cases[ $index . ' ' . json_encode( $entry['input'] ) ] = array( $entry['input'], $entry['expect'] );
		}

		return $cases;
	}

	public function test_windows_versions_are_told_apart_the_same_way(): void {
		foreach ( self::section( 'windowsVersion' ) as $entry ) {
			list( $platform, $version ) = $entry['input'];

			$server = array_filter(
				array(
					'HTTP_SEC_CH_UA_PLATFORM'         => $platform,
					'HTTP_SEC_CH_UA_PLATFORM_VERSION' => $version,
				)
			);

			$this->assertSame( $entry['expect'], ClientHints::windows_version( $server ), json_encode( $entry['input'] ) );
		}
	}

	public function test_brands_are_parsed_the_same_way(): void {
		foreach ( self::section( 'parseBrands' ) as $entry ) {
			$this->assertSame( $entry['expect'], ClientHints::brands( array( 'HTTP_SEC_CH_UA' => $entry['input'] ) ), json_encode( $entry['input'] ) );
		}
	}

	public function test_the_table_was_actually_read(): void {
		// A data provider that comes back empty passes: no cases, no
		// failures. A fixture that failed to load, or a section renamed
		// on one side only, would read as agreement.
		$this->assertGreaterThanOrEqual( 100, count( self::agents() ) );
		$this->assertGreaterThanOrEqual( 10, count( self::hints() ) );
		$this->assertNotEmpty( self::section( 'windowsVersion' ) );
		$this->assertNotEmpty( self::section( 'parseBrands' ) );
	}

	public function test_the_table_covers_every_kind_of_agent(): void {
		$seen = array();

		foreach ( self::section( 'parse' ) as $entry ) {
			$expect = $entry['expect'];

			$seen[ 'device ' . $expect['device'] ] = true;

			foreach ( array( 'aiBot', 'aiUserAgent', 'tool', 'inApp', 'linkPreview' ) as $kind ) {
				if ( $expect[ $kind ] ) {
					$seen[ $kind ] = true;
				}
			}
		}

		foreach ( array( 'device desktop', 'device mobile', 'device tablet', 'device bot', 'device unknown', 'aiBot', 'aiUserAgent', 'tool', 'inApp', 'linkPreview' ) as $kind ) {
			$this->assertArrayHasKey( $kind, $seen, sprintf( 'No agent in the table is a "%s".', $kind ) );
		}
	}

	/**
	 * One section of the fixture.
	 *
	 * @param string $name The section, named for the JavaScript function.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function section( string $name ): array {
		if ( null === self::$fixture ) {
			self::$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/agents.json' ), true, 512, JSON_THROW_ON_ERROR );
		}

		return self::$fixture[ $name ] ?? array();
	}
}
