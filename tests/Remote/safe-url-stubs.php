<?php
/**
 * Core's safe HTTP calls, actions, and curl_setopt() as SafeUrl sees it.
 *
 * A request is answered from the same queue as Service's (rs_http) and kept
 * in rs_requests, with whether core's safe variant was used and the pin
 * SafeUrl gave curl -- which a real handle cannot be asked for afterwards.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Remote {

	/**
	 * Record CURLOPT_RESOLVE; pass anything else through.
	 *
	 * An unqualified call resolves to a function in the caller's namespace
	 * before the global one.
	 *
	 * @param mixed $handle The handle.
	 * @param int   $option The option.
	 * @param mixed $value  Its value.
	 *
	 * @return bool
	 */
	function curl_setopt( $handle, int $option, $value ): bool {
		if ( CURLOPT_RESOLVE === $option ) {
			$GLOBALS['su_pin'] = $value;

			return true;
		}

		return \curl_setopt( $handle, $option, $value );
	}
}

namespace {

	$GLOBALS['su_pin'] = null;

	/**
	 * A request through core's safe variant, or the plain one: run the
	 * `http_api_curl` actions on a real handle, then answer from the queue.
	 *
	 * @param string $method The method.
	 * @param string $url    The address.
	 * @param array  $args   The arguments.
	 * @param bool   $safe   Whether it was core's safe variant.
	 *
	 * @return array|WP_Error
	 */
	function su_request( string $method, string $url, array $args, bool $safe ) {
		$GLOBALS['su_pin'] = null;

		if ( function_exists( 'curl_init' ) ) {
			$handle = curl_init();

			do_action( 'http_api_curl', $handle, $args, $url );
		}

		if ( $safe ) {
			$args['safe'] = true;
		}

		$args['pin'] = $GLOBALS['su_pin'];

		return rs_respond( $method, $url, $args );
	}

	/**
	 * An answer for the queue.
	 *
	 * @param int                   $code    Status.
	 * @param array<string, string> $headers Headers.
	 *
	 * @return array
	 */
	function su_answer( int $code, array $headers = [] ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => $headers,
			'body'     => '',
		];
	}

	if ( ! function_exists( 'wp_safe_remote_post' ) ) {
		/**
		 * @param string $url  URL.
		 * @param array  $args Arguments.
		 *
		 * @return array|WP_Error
		 */
		function wp_safe_remote_post( $url, $args = [] ) {
			return su_request( 'POST', $url, $args, true );
		}
	}

	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		/**
		 * @param string $url  URL.
		 * @param array  $args Arguments.
		 *
		 * @return array|WP_Error
		 */
		function wp_safe_remote_get( $url, $args = [] ) {
			return su_request( 'GET', $url, $args, true );
		}
	}

	if ( ! function_exists( 'wp_safe_remote_request' ) ) {
		/**
		 * @param string $url  URL.
		 * @param array  $args Arguments, `method` among them.
		 *
		 * @return array|WP_Error
		 */
		function wp_safe_remote_request( $url, $args = [] ) {
			return su_request( (string) ( $args['method'] ?? 'GET' ), $url, $args, true );
		}
	}

	if ( ! function_exists( 'wp_remote_request' ) ) {
		/**
		 * @param string $url  URL.
		 * @param array  $args Arguments, `method` among them.
		 *
		 * @return array|WP_Error
		 */
		function wp_remote_request( $url, $args = [] ) {
			return su_request( (string) ( $args['method'] ?? 'GET' ), $url, $args, false );
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		/**
		 * @param array|WP_Error $response Response.
		 * @param string         $header   Name.
		 *
		 * @return string
		 */
		function wp_remote_retrieve_header( $response, $header ) {
			return is_array( $response ) ? (string) ( array_change_key_case( $response['headers'] ?? [] )[ strtolower( $header ) ] ?? '' ) : '';
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		/**
		 * Run the callbacks registered for an action.
		 *
		 * @param string $hook    Hook name.
		 * @param mixed  ...$args Arguments.
		 *
		 * @return void
		 */
		function do_action( string $hook, ...$args ): void {
			foreach ( $GLOBALS['ru_actions'][ $hook ] ?? [] as $callback ) {
				$callback( ...array_slice( $args, 0, 1 ) );
			}
		}
	}

	if ( ! function_exists( 'remove_action' ) ) {
		/**
		 * Forget a callback.
		 *
		 * @param string   $hook     Hook name.
		 * @param callable $callback Callback.
		 *
		 * @return bool
		 */
		function remove_action( string $hook, callable $callback ): bool {
			foreach ( $GLOBALS['ru_actions'][ $hook ] ?? [] as $index => $attached ) {
				if ( $attached === $callback ) {
					unset( $GLOBALS['ru_actions'][ $hook ][ $index ] );

					return true;
				}
			}

			return false;
		}
	}
}
