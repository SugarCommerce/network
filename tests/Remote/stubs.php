<?php
/**
 * Transients, options, the HTTP API and WP_Error, for Remote\Service.
 *
 * Transients expire by a clock the tests move on, and the HTTP API answers
 * from a queue the tests fill.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

/**
 * Transients with when each expires (0 = never), the clock, the answers
 * waiting, the requests made, and whether transients live in a persistent
 * object cache rather than the options table.
 */
$GLOBALS['rs_transients']   = [];
$GLOBALS['rs_now']          = 1_800_000_000;
$GLOBALS['rs_http']         = [];
$GLOBALS['rs_requests']     = [];
$GLOBALS['rs_object_cache'] = false;

/**
 * Forget every transient, answer and request.
 *
 * @return void
 */
function rs_reset(): void {
	$GLOBALS['rs_transients']   = [];
	$GLOBALS['rs_now']          = 1_800_000_000;
	$GLOBALS['rs_http']         = [];
	$GLOBALS['rs_requests']     = [];
	$GLOBALS['rs_object_cache'] = false;

	foreach ( array_keys( $GLOBALS['ru_options'] ) as $name ) {
		if ( str_ends_with( (string) $name, 'generation' ) ) {
			unset( $GLOBALS['ru_options'][ $name ] );
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Core's error object, near enough.
	 */
	class WP_Error {

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}

		/**
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Anything.
	 *
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string    $name     Option.
	 * @param mixed     $value    Value.
	 * @param bool|null $autoload Autoload.
	 *
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['ru_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $key Key.
	 *
	 * @return mixed
	 */
	function get_transient( $key ) {
		$entry = $GLOBALS['rs_transients'][ $key ] ?? null;

		if ( null === $entry || ( 0 !== $entry['expires'] && $entry['expires'] <= $GLOBALS['rs_now'] ) ) {
			unset( $GLOBALS['rs_transients'][ $key ] );

			return false;
		}

		return $entry['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $key        Key.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Seconds.
	 *
	 * @return bool
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['rs_transients'][ $key ] = [
			'value'   => $value,
			'expires' => $expiration > 0 ? $GLOBALS['rs_now'] + (int) $expiration : 0,
		];

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $key Key.
	 *
	 * @return bool
	 */
	function delete_transient( $key ) {
		$found = isset( $GLOBALS['rs_transients'][ $key ] );
		unset( $GLOBALS['rs_transients'][ $key ] );

		return $found;
	}
}

/**
 * Answer a request from the queue, and keep it.
 *
 * @param string $method The method.
 * @param string $url    The URL.
 * @param array  $args   The arguments.
 *
 * @return array|WP_Error
 */
function rs_respond( string $method, string $url, array $args ) {
	$GLOBALS['rs_requests'][] = compact( 'method', 'url', 'args' );

	return array_shift( $GLOBALS['rs_http'] ) ?? new WP_Error( 'http_request_failed', 'Nothing queued.' );
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 *
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = [] ) {
		return rs_respond( 'GET', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 *
	 * @return array|WP_Error
	 */
	function wp_remote_post( $url, $args = [] ) {
		return rs_respond( 'POST', $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array|WP_Error $response Response.
	 *
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? $response['response']['code'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array|WP_Error $response Response.
	 *
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? $response['body'] : '';
	}
}

/**
 * The options table, for clear_cache() with no key: the transient rows a
 * LIKE finds, and none with a persistent object cache.
 */
$GLOBALS['wpdb'] = new class() {

	/**
	 * @var string
	 */
	public string $options = 'wp_options';

	/**
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * @param string $query   Query.
	 * @param mixed  ...$args Arguments.
	 *
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return (string) $args[0];
	}

	/**
	 * @param string $like The LIKE pattern, as prepare() left it.
	 *
	 * @return string[]
	 */
	public function get_col( $like ) {
		if ( $GLOBALS['rs_object_cache'] ) {
			return [];
		}

		$prefix = stripslashes( substr( $like, 0, -1 ) );

		return array_values(
			array_filter(
				array_map( static fn( $key ) => '_transient_' . $key, array_keys( $GLOBALS['rs_transients'] ) ),
				static fn( $name ) => str_starts_with( $name, $prefix )
			)
		);
	}
};
