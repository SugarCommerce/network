<?php
/**
 * Server
 *
 * The request's `$_SERVER`, read one way everywhere in the library.
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
}
