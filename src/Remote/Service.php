<?php
/**
 * Service
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Remote;

use SugarCommerce\Network\IP;
use WP_Error;

/**
 * Class Service
 *
 * The base for a client of a keyed third-party API -- an IP reputation
 * service, a geocoder: its key, the cache in front of it, the memory of what
 * just failed, and reading what the HTTP API hands back. sugarcommerce/ip-lookup
 * and sugarcommerce/geocoding build their providers on it.
 *
 * A subclass names itself and its family in constants, and asks with
 * remember(), request() and json():
 *
 *     final class Acme extends Service {
 *         protected const ID          = 'acme';
 *         protected const NAME        = 'Acme';
 *         protected const CACHE_GROUP = 'lookups';
 *         protected const CACHE_TTL   = DAY_IN_SECONDS;
 *
 *         public function check( string $ip ): array|WP_Error {
 *             if ( ! self::is_lookupable( $ip ) ) {
 *                 return $this->invalid_ip( $ip );
 *             }
 *
 *             return $this->remember( $ip, fn() => $this->json(
 *                 $this->request( 'GET', 'https://api.acme.test/' . rawurlencode( $ip ), [ 'headers' => [ 'Key' => $this->api_key ] ] )
 *             ) );
 *         }
 *     }
 *
 * Answers are kept as transients for the cache lifetime. A failure is kept
 * too, briefly: without that, an API that is down or out of quota is asked
 * again on every request and each one waits out the whole timeout -- on a
 * checkout, the difference between a slow page and an abandoned basket. A
 * quota error is worse still, since retrying it per request spends the next
 * allowance as fast as it is granted.
 *
 * @since 1.0.0
 */
abstract class Service {

	/**
	 * The service's id: part of its cache keys, and the start of its error
	 * codes.
	 */
	protected const ID = '';

	/**
	 * The service's name, for error messages.
	 */
	protected const NAME = '';

	/**
	 * The family of services this one belongs to, which starts its cache
	 * keys: `iplookup`, `geocoding`. Two families with a service of the same
	 * id keep apart, and each family's transients can be told by name.
	 */
	protected const CACHE_GROUP = 'remote';

	/**
	 * How long an answer is kept, by default.
	 */
	protected const CACHE_TTL = 3600;

	/**
	 * Whether answers are cached.
	 *
	 * @var bool
	 */
	private bool $cache_enabled = true;

	/**
	 * How long an answer is kept, in seconds.
	 *
	 * @var int
	 */
	private int $cache_ttl;

	/**
	 * How long a failure is remembered, in seconds. 0 retries every time.
	 *
	 * @var int
	 */
	private int $failure_ttl = 60;

	/**
	 * Build the client.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The API key.
	 * @param array  $args    {
	 *     Optional settings.
	 *
	 *     @type bool $cache       Cache answers. Default true.
	 *     @type int  $cache_ttl   Seconds an answer is kept. Default per service.
	 *     @type int  $failure_ttl Seconds a failure is remembered. Default 60.
	 * }
	 */
	public function __construct( #[\SensitiveParameter] protected string $api_key = '', array $args = [] ) {
		$this->cache_ttl = static::CACHE_TTL;

		if ( isset( $args['cache'] ) ) {
			$this->set_cache_enabled( (bool) $args['cache'] );
		}

		if ( isset( $args['cache_ttl'] ) ) {
			$this->set_cache_ttl( (int) $args['cache_ttl'] );
		}

		if ( isset( $args['failure_ttl'] ) ) {
			$this->set_failure_ttl( (int) $args['failure_ttl'] );
		}
	}

	/**
	 * The service's id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return static::ID;
	}

	/**
	 * The service's name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return static::NAME;
	}

	/**
	 * The API key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_api_key(): string {
		return $this->api_key;
	}

	/**
	 * Use another API key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The key.
	 *
	 * @return static
	 */
	public function set_api_key( #[\SensitiveParameter] string $api_key ): static {
		$this->api_key = $api_key;

		return $this;
	}

	/**
	 * Turn caching on or off.
	 *
	 * Off means off: no answer is read or kept, and no failure remembered.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Whether to cache.
	 *
	 * @return static
	 */
	public function set_cache_enabled( bool $enabled ): static {
		$this->cache_enabled = $enabled;

		return $this;
	}

	/**
	 * Whether answers are cached.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_cache_enabled(): bool {
		return $this->cache_enabled;
	}

	/**
	 * How long an answer is kept.
	 *
	 * @since 1.0.0
	 *
	 * @param int $seconds Seconds; 0 keeps it until something clears it.
	 *
	 * @return static
	 */
	public function set_cache_ttl( int $seconds ): static {
		$this->cache_ttl = max( 0, $seconds );

		return $this;
	}

	/**
	 * How long an answer is kept, in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_cache_ttl(): int {
		return $this->cache_ttl;
	}

	/**
	 * How long a failure is remembered.
	 *
	 * Short on purpose: long enough that an outage does not cost every
	 * visitor a full timeout, short enough that a recovered API is picked up
	 * within a minute.
	 *
	 * @since 1.0.0
	 *
	 * @param int $seconds Seconds; 0 retries every time.
	 *
	 * @return static
	 */
	public function set_failure_ttl( int $seconds ): static {
		$this->failure_ttl = max( 0, $seconds );

		return $this;
	}

	/**
	 * How long a failure is remembered, in seconds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_failure_ttl(): int {
		return $this->failure_ttl;
	}

	/**
	 * Forget cached answers: one, or every one this service kept.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $identifier What was asked about, as the service
	 *                                keyed it: an address, a query. Null
	 *                                clears everything with this service's
	 *                                prefix.
	 *
	 * @return bool
	 */
	public function clear_cache( ?string $identifier = null ): bool {
		if ( null !== $identifier ) {
			$key = $this->cache_key( $identifier );

			delete_transient( $key . '_f' );

			return (bool) delete_transient( $key );
		}

		/*
		 * Start a new generation of keys, so nothing kept before is found
		 * again. With a persistent object cache the transients are not in
		 * the options table at all, so finding them by name (below) finds
		 * nothing -- the old answers would have kept being served until
		 * they expired. The orphaned entries expire on their own.
		 */
		update_option( $this->generation_option(), $this->generation() + 1, true );

		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return true;
		}

		/*
		 * Without an object cache, tidy the old rows away now rather than
		 * leave them for the expired-transient cleanup.
		 *
		 * Find the names, then let delete_transient() do the deleting.
		 *
		 * Deleting the rows directly leaves every _transient_timeout_* row
		 * behind -- those do not carry the prefix, so a DELETE ... LIKE
		 * orphans one options row per cached lookup, permanently.
		 * delete_transient() removes both halves, fires the
		 * delete_transient_* hooks, and clears the entry from an external
		 * object cache, none of which a raw DELETE does.
		 */
		$like = $wpdb->esc_like( '_transient_' . $this->cache_prefix() ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}

		return true;
	}

	/**
	 * Whether an address is worth asking a service about.
	 *
	 * For the services that are asked about IP addresses: one off the public
	 * internet costs a request and a quota slot, and no service knows
	 * anything about it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip The address.
	 *
	 * @return bool False for anything not on the public internet.
	 */
	public static function is_lookupable( string $ip ): bool {
		return IP::is_public( trim( $ip ) );
	}

	/**
	 * Fetch something, from the cache when it is there.
	 *
	 * @param string   $identifier What is asked, unique to the question.
	 * @param callable $fetch      Asks the API: returns the answer, or a
	 *                             WP_Error that is remembered as a failure.
	 * @param int|null $ttl        Seconds to keep it, if not the cache lifetime.
	 *
	 * @return mixed|WP_Error
	 */
	protected function remember( string $identifier, callable $fetch, ?int $ttl = null ): mixed {
		$key = $this->cache_key( $identifier );

		if ( $this->cache_enabled ) {
			$stored = get_transient( $key );

			if ( is_array( $stored ) && array_key_exists( 'answer', $stored ) ) {
				return $stored['answer'];
			}

			// A lookup that just failed will almost certainly fail again.
			// Answer now rather than making this visitor wait out the
			// timeout as well.
			if ( $this->failure_ttl > 0 && get_transient( $key . '_f' ) ) {
				return $this->error(
					'recent_failure',
					/* translators: %s: the service's name, e.g. ProxyCheck or Maps.co. */
					sprintf( __( '%s lookup failed recently; not retrying yet.', 'sugarcommerce' ), static::NAME )
				);
			}
		}

		$answer = $fetch();

		if ( ! $this->cache_enabled ) {
			return $answer;
		}

		if ( is_wp_error( $answer ) ) {
			if ( $this->failure_ttl > 0 ) {
				set_transient( $key . '_f', 1, $this->failure_ttl );
			}

			return $answer;
		}

		set_transient( $key, [ 'answer' => $answer ], $ttl ?? $this->cache_ttl );

		return $answer;
	}

	/**
	 * The transient key for a question.
	 *
	 * Includes the key: two accounts on different plans get different
	 * answers to the same question, and must not share one.
	 *
	 * @param string $identifier What is asked.
	 *
	 * @return string
	 */
	protected function cache_key( string $identifier ): string {
		$generation = $this->generation();

		return $this->cache_prefix() . ( $generation > 0 ? $generation . '_' : '' ) . md5( $identifier . '|' . $this->api_key );
	}

	/**
	 * How many times every answer has been cleared: part of each key, so
	 * clearing everything works whether or not the transients live in the
	 * options table.
	 *
	 * @return int 0 until the first clear_cache(), so keys made before then
	 *             still match.
	 */
	private function generation(): int {
		return max( 0, (int) get_option( $this->generation_option(), 0 ) );
	}

	/**
	 * The option holding the generation.
	 *
	 * @return string
	 */
	private function generation_option(): string {
		return $this->cache_prefix() . 'generation';
	}

	/**
	 * The start of every transient this service keeps: its family, then
	 * its id.
	 *
	 * @return string
	 */
	protected function cache_prefix(): string {
		return static::CACHE_GROUP . '_' . static::ID . '_';
	}

	/**
	 * Make a request and decode the JSON it answers with.
	 *
	 * @param string $method `GET` or `POST`.
	 * @param string $url    Where.
	 * @param array  $args   wp_remote_request() arguments.
	 *
	 * @return array{status: int, body: string, data: mixed}|WP_Error The
	 *         status, the raw body, and the decoded body (null when it was
	 *         not JSON). A WP_Error only when the request never completed.
	 */
	protected function request( string $method, string $url, array $args = [] ): array|WP_Error {
		$args = array_replace_recursive(
			[
				'method'  => $method,
				'timeout' => 15,
				'headers' => [ 'Accept' => 'application/json' ],
			],
			$args
		);

		$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $this->error(
				'http_error',
				sprintf(
					/* translators: 1: the service's name, 2: the error message from WP_Http. */
					__( '%1$s request failed: %2$s', 'sugarcommerce' ),
					static::NAME,
					$response->get_error_message()
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => $body,
			'data'   => json_decode( $body, true ),
		];
	}

	/**
	 * The usual reading of a JSON API's answer: a 2xx status and a JSON
	 * object, or an error.
	 *
	 * @param array|WP_Error $response What request() returned.
	 *
	 * @return array|WP_Error The decoded body.
	 */
	protected function json( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return $this->error(
				'api_error',
				sprintf(
					/* translators: 1: the service's name, 2: the HTTP status code. */
					__( '%1$s returned HTTP %2$d.', 'sugarcommerce' ),
					static::NAME,
					$response['status']
				),
				[ 'status' => $response['status'] ]
			);
		}

		if ( ! is_array( $response['data'] ) ) {
			return $this->error(
				'bad_response',
				/* translators: %s: the service's name. */
				sprintf( __( '%s returned a response that could not be read.', 'sugarcommerce' ), static::NAME ),
				[ 'status' => $response['status'] ]
			);
		}

		return $response['data'];
	}

	/**
	 * An error, its code starting with the service's id.
	 *
	 * @param string $code    The code, without the prefix.
	 * @param string $message The message.
	 * @param mixed  $data    Anything else.
	 *
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, mixed $data = '' ): WP_Error {
		return new WP_Error( static::ID . '_' . $code, $message, $data );
	}

	/**
	 * The error for an address that is not worth asking about: see
	 * is_lookupable().
	 *
	 * @param string $ip The address.
	 *
	 * @return WP_Error
	 */
	protected function invalid_ip( string $ip ): WP_Error {
		return $this->error(
			'invalid_ip',
			/* translators: %s: the IP address. */
			sprintf( __( 'Not a public IP address: %s', 'sugarcommerce' ), $ip )
		);
	}
}
