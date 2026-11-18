<?php
/**
 * Server
 *
 * The request's `$_SERVER`, read one way everywhere in the library, and
 * what it says about the web server.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Server Class
 *
 * Every reader here takes a `$_SERVER`-shaped array, so it can be tested
 * and so a queued job can hand over the request it saved. Null means the
 * live request, and this is where that is read: unslashed, because
 * WordPress runs add_magic_quotes() over `$_SERVER` on load, and a header
 * with a quote in it -- `CF-Visitor: {"scheme":"https"}`, a quoted user
 * agent, a client hint -- would otherwise arrive with backslashes that
 * were never sent.
 *
 * @since 1.0.0
 */
final class Server {

	/**
	 * The server array to read.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in for it. Null
	 *                           reads the live one, unslashed.
	 *
	 * @return array
	 */
	public static function get( ?array $server = null ): array {
		if ( null !== $server ) {
			return $server;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every reader validates the one value it takes.
		return function_exists( 'wp_unslash' ) ? (array) wp_unslash( $_SERVER ) : $_SERVER;
	}

	/**
	 * One value, as trimmed text.
	 *
	 * Anything that is not a string or a number -- an array smuggled in
	 * where a header was expected -- is nothing, never "Array".
	 *
	 * @param array  $server `$_SERVER`, or a stand-in.
	 * @param string $key    The key: `REMOTE_ADDR`, `HTTP_CF_RAY`.
	 *
	 * @return string Empty when absent.
	 */
	public static function value( array $server, string $key ): string {
		$value = $server[ $key ] ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * One request header's value, by the name it is sent under.
	 *
	 * @param array  $server `$_SERVER`, or a stand-in.
	 * @param string $header The header as written in a request: `CF-IPCountry`.
	 *
	 * @return string Empty when absent.
	 */
	public static function header( array $server, string $header ): string {
		return self::value( $server, self::key( $header ) );
	}

	/**
	 * The `$_SERVER` key PHP files a request header under.
	 *
	 * `CF-Connecting-IP` is `HTTP_CF_CONNECTING_IP`.
	 *
	 * @param string $header The header as written in a request.
	 *
	 * @return string
	 */
	public static function key( string $header ): string {
		return 'HTTP_' . strtoupper( str_replace( '-', '_', trim( $header ) ) );
	}

	/**
	 * The web server's own description of itself: `nginx/1.27.0`.
	 *
	 * Read fresh each time. The old static cache answered every later call
	 * with the first request's value, which a test, or a job handed a saved
	 * request, could not get past.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in. Null reads the live one.
	 *
	 * @return string Empty when the server does not say.
	 */
	public static function software( ?array $server = null ): string {
		$software = self::value( self::get( $server ), 'SERVER_SOFTWARE' );

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $software ) : trim( strip_tags( $software ) );
	}

	/**
	 * Whether the web server is Apache.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function is_apache( ?array $server = null ): bool {
		return false !== stripos( self::software( $server ), 'apache' );
	}

	/**
	 * Whether the web server is nginx.
	 *
	 * Flywheel -- what Local runs -- is nginx under another name.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function is_nginx( ?array $server = null ): bool {
		$software = self::software( $server );

		return false !== stripos( $software, 'nginx' ) || false !== stripos( $software, 'flywheel' );
	}

	/**
	 * Whether the web server is LiteSpeed.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function is_litespeed( ?array $server = null ): bool {
		return false !== stripos( self::software( $server ), 'litespeed' );
	}

	/**
	 * Whether the web server is IIS.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function is_iis( ?array $server = null ): bool {
		return false !== stripos( self::software( $server ), 'microsoft-iis' );
	}

	/**
	 * The server's own address.
	 *
	 * `SERVER_ADDR` when it is an address -- the server sets it, but it
	 * reaches PHP through the same array as everything the client sends --
	 * else what the machine's host name resolves to.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return string
	 */
	public static function address( ?array $server = null ): string {
		$address = self::value( self::get( $server ), 'SERVER_ADDR' );

		if ( IP::is_valid( $address ) ) {
			return $address;
		}

		return gethostbyname( gethostname() ?: 'localhost' );
	}

	/**
	 * The port the request arrived on.
	 *
	 * `SERVER_PORT` when it is a port, 1 to 65535; else 443 for HTTPS and
	 * 80 for HTTP.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return int
	 */
	public static function port( ?array $server = null ): int {
		$server = self::get( $server );
		$port   = self::value( $server, 'SERVER_PORT' );

		if ( 1 === preg_match( '/^\d{1,5}$/D', $port ) && (int) $port >= 1 && (int) $port <= 65535 ) {
			return (int) $port;
		}

		$https = strtolower( self::value( $server, 'HTTPS' ) );

		return '' !== $https && 'off' !== $https ? 443 : 80;
	}

	/**
	 * Whether the server reads an `.htaccess` file.
	 *
	 * Apache with mod_rewrite seen. Conservative: under PHP-FPM the module
	 * list cannot be read, and without `HTTP_MOD_REWRITE` set this answers
	 * false on an Apache that would have read the file.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function supports_htaccess( ?array $server = null ): bool {
		return self::is_apache( $server ) && self::has_mod_rewrite( $server );
	}

	/**
	 * Whether Apache's mod_rewrite is loaded.
	 *
	 * From Apache's module list when PHP can see it, else from the
	 * `HTTP_MOD_REWRITE` variable WordPress's own rules set
	 * (`REDIRECT_HTTP_MOD_REWRITE` after an internal redirect).
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function has_mod_rewrite( ?array $server = null ): bool {
		if ( ! self::is_apache( $server ) ) {
			return false;
		}

		$modules = self::modules();

		if ( null !== $modules ) {
			return in_array( 'mod_rewrite', $modules, true );
		}

		$server = self::get( $server );

		foreach ( [ 'HTTP_MOD_REWRITE', 'REDIRECT_HTTP_MOD_REWRITE' ] as $name ) {
			if ( 'on' === strtolower( (string) getenv( $name ) ) || 'on' === strtolower( self::value( $server, $name ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether pretty permalinks can work.
	 *
	 * Apache with mod_rewrite; nginx and LiteSpeed, whose rewriting is
	 * configured elsewhere; IIS with its URL Rewrite module.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function supports_url_rewriting( ?array $server = null ): bool {
		if ( self::is_apache( $server ) ) {
			return self::has_mod_rewrite( $server );
		}

		if ( self::is_nginx( $server ) || self::is_litespeed( $server ) ) {
			return true;
		}

		if ( self::is_iis( $server ) ) {
			return isset( self::get( $server )['IIS_UrlRewriteModule'] );
		}

		return false;
	}

	/**
	 * Whether PHP can gzip a response.
	 *
	 * @return bool
	 */
	public static function supports_gzip(): bool {
		return function_exists( 'gzencode' ) || function_exists( 'gzcompress' );
	}

	/**
	 * Whether the server can send a file for PHP (`X-Sendfile`,
	 * `X-Accel-Redirect`).
	 *
	 * Apache with mod_xsendfile; LiteSpeed always. nginx needs an
	 * `internal` location nobody can see from PHP, so it is false unless
	 * the `sugarcommerce_nginx_xsendfile` filter says it is set up.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function has_xsendfile( ?array $server = null ): bool {
		if ( self::is_apache( $server ) ) {
			return in_array( 'mod_xsendfile', self::modules() ?? [], true );
		}

		if ( self::is_litespeed( $server ) ) {
			return true;
		}

		if ( self::is_nginx( $server ) ) {
			return (bool) apply_filters( 'sugarcommerce_nginx_xsendfile', false );
		}

		return false;
	}

	/**
	 * Apache's loaded modules.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return array<int, string>|null Null when this is not Apache, or PHP
	 *                                 cannot see the list (PHP-FPM, CLI).
	 */
	public static function apache_modules( ?array $server = null ): ?array {
		return self::is_apache( $server ) ? self::modules() : null;
	}

	/**
	 * Whether an Apache module is loaded: `mod_headers`.
	 *
	 * @param string     $module The module.
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool False when the list cannot be read.
	 */
	public static function has_apache_module( string $module, ?array $server = null ): bool {
		return in_array( $module, self::apache_modules( $server ) ?? [], true );
	}

	/**
	 * Apache's module list, when PHP runs inside Apache.
	 *
	 * @return array<int, string>|null
	 */
	private static function modules(): ?array {
		if ( ! function_exists( 'apache_get_modules' ) ) {
			return null;
		}

		$modules = apache_get_modules();

		return is_array( $modules ) ? array_values( array_map( 'strval', $modules ) ) : null;
	}
}
