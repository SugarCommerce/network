<?php
/**
 * Environment
 *
 * Which kind of site this is: somebody's own machine, a staging copy, a
 * development site, or the live one.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Environment Class
 *
 * A plugin asks this to decide whether to run a licence check, take a
 * real payment or send an analytics event, so a live site told it is
 * staging quietly stops doing what it is there for.
 *
 * WordPress is asked first. `WP_ENVIRONMENT_TYPE` is the site owner
 * saying what the site is, and that is believed over any guess. Only a
 * site that never said -- `wp_get_environment_type()` giving its default
 * `production` with nothing set -- is guessed at, from its address and
 * the usual environment variables:
 *
 *     Environment::type();            // "local", "development", "staging", "production"
 *     Environment::is_production();
 *
 * Exactly one of the four is true.
 *
 * @since 1.0.0
 */
final class Environment {

	/**
	 * Somebody's own machine.
	 *
	 * @var string
	 */
	public const LOCAL = 'local';

	/**
	 * A development site.
	 *
	 * @var string
	 */
	public const DEVELOPMENT = 'development';

	/**
	 * A staging copy.
	 *
	 * @var string
	 */
	public const STAGING = 'staging';

	/**
	 * The live site.
	 *
	 * @var string
	 */
	public const PRODUCTION = 'production';

	/**
	 * Hosts that are local by name: wordpress-develop's and the old
	 * Linux default.
	 *
	 * @var array<int, string>
	 */
	private const LOCAL_HOSTS = [
		'localhost.localdomain',
		'local.wordpress.test',
		'local.wordpress-trunk.test',
		'src.wordpress-develop.test',
		'build.wordpress-develop.test',
	];

	/**
	 * Which kind of site this is.
	 *
	 * @return string One of the class constants.
	 */
	public static function type(): string {
		$type = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : self::declared();

		if ( self::PRODUCTION !== $type || self::was_declared() ) {
			return in_array( $type, [ self::LOCAL, self::DEVELOPMENT, self::STAGING ], true ) ? $type : self::PRODUCTION;
		}

		return self::guess();
	}

	/**
	 * Whether this is somebody's own machine.
	 *
	 * @return bool
	 */
	public static function is_local(): bool {
		return self::LOCAL === self::type();
	}

	/**
	 * Whether this is a staging copy.
	 *
	 * @return bool
	 */
	public static function is_staging(): bool {
		return self::STAGING === self::type();
	}

	/**
	 * Whether this is a development site.
	 *
	 * @return bool
	 */
	public static function is_development(): bool {
		return self::DEVELOPMENT === self::type();
	}

	/**
	 * Whether this is the live site.
	 *
	 * @return bool
	 */
	public static function is_production(): bool {
		return self::PRODUCTION === self::type();
	}

	/**
	 * Whether a host name is local by itself.
	 *
	 * Loopback (`localhost`, `*.localhost`, `127.0.0.1`, `::1`), a private
	 * or reserved address, and the names set aside never to resolve on the
	 * internet: `*.test`, `*.local`, `*.example`, `*.invalid` (RFC 2606,
	 * 6761, 6762). Not `*.dev`: that is a real, public ending. No host at
	 * all is local.
	 *
	 * @param string $host The host, with or without a port.
	 *
	 * @return bool
	 */
	public static function is_local_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return true;
		}

		if ( Host::is_loopback( $host ) || in_array( $host, self::LOCAL_HOSTS, true ) ) {
			return true;
		}

		$address = trim( Host::without_port( $host ), '[]' );

		if ( IP::is_valid( $address ) ) {
			return ! IP::is_public( $address );
		}

		return 1 === preg_match( '/\.(?:test|local|localhost|example|invalid)$/D', Host::without_port( $host ) );
	}

	/**
	 * The guess for a site that never said what it is.
	 *
	 * Its address first, then `WP_ENV`, `ENVIRONMENT` and `APP_ENV`, then
	 * `WP_DEBUG` as the last sign of a development site.
	 *
	 * @return string
	 */
	private static function guess(): string {
		$hosts = array_unique(
			[
				(string) wp_parse_url( (string) get_site_url(), PHP_URL_HOST ),
				(string) wp_parse_url( (string) get_home_url(), PHP_URL_HOST ),
			]
		);

		foreach ( $hosts as $host ) {
			if ( self::is_local_host( $host ) ) {
				return self::LOCAL;
			}
		}

		// The host, at a label boundary: `latest.example.com` contains
		// "test." and is not staging.
		foreach ( $hosts as $host ) {
			if ( 1 === preg_match( '/(?:^|\.)(?:staging|stage|test|demo)(?:\.|$)/i', $host ) ) {
				return self::STAGING;
			}
		}

		$said = array_map( [ self::class, 'setting' ], [ 'WP_ENV', 'ENVIRONMENT', 'APP_ENV' ] );

		if ( array_intersect( $said, [ 'staging', 'stage' ] ) ) {
			return self::STAGING;
		}

		if ( array_intersect( $said, [ 'development', 'dev', 'local' ] ) ) {
			return in_array( 'local', $said, true ) ? self::LOCAL : self::DEVELOPMENT;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return self::DEVELOPMENT;
		}

		return self::PRODUCTION;
	}

	/**
	 * Whether `WP_ENVIRONMENT_TYPE` was set, as a constant or in the
	 * environment.
	 *
	 * @return bool
	 */
	private static function was_declared(): bool {
		return '' !== self::setting( 'WP_ENVIRONMENT_TYPE' );
	}

	/**
	 * `WP_ENVIRONMENT_TYPE` read as WordPress reads it, for when
	 * WordPress is not loaded.
	 *
	 * @return string
	 */
	private static function declared(): string {
		$type = self::setting( 'WP_ENVIRONMENT_TYPE' );

		return in_array( $type, [ self::LOCAL, self::DEVELOPMENT, self::STAGING ], true ) ? $type : self::PRODUCTION;
	}

	/**
	 * A setting from the constant, else the environment variable, lower case.
	 *
	 * @param string $name The name.
	 *
	 * @return string Empty when unset.
	 */
	private static function setting( string $name ): string {
		$value = defined( $name ) ? constant( $name ) : getenv( $name );

		return is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
	}
}
