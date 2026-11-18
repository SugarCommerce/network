<?php
/**
 * The cached third-party API base: the cache, the failure memory, refused
 * addresses and reading an answer.
 *
 * sugarcommerce/ip-lookup and sugarcommerce/geocoding test it again through
 * their real providers.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Remote;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\Remote\Response;
use SugarCommerce\Network\Remote\Service;
use WP_Error;

/**
 * A service that asks about an address.
 */
final class Acme extends Service {

	protected const ID          = 'acme';
	protected const NAME        = 'Acme';
	protected const CACHE_GROUP = 'lookups';

	/**
	 * Ask about an address.
	 *
	 * @param string $ip The address.
	 *
	 * @return AcmeAnswer|WP_Error
	 */
	public function check( string $ip ): AcmeAnswer|WP_Error {
		if ( ! self::is_lookupable( $ip ) ) {
			return $this->invalid_ip( $ip );
		}

		$data = $this->remember( $ip, fn() => $this->json( $this->request( 'GET', 'https://api.acme.test/' . $ip ) ) );

		return is_wp_error( $data ) ? $data : new AcmeAnswer( $data );
	}

	/**
	 * Ask with a POST, kept for a time of its own.
	 *
	 * @param string $query What to ask.
	 *
	 * @return mixed
	 */
	public function search( string $query ): mixed {
		return $this->remember( 'q:' . $query, fn() => $this->json( $this->request( 'POST', 'https://api.acme.test/search', [ 'body' => $query ] ) ), 30 );
	}
}

/**
 * A service of the default family, kept a day.
 */
final class Plain extends Service {

	protected const ID        = 'plain';
	protected const NAME      = 'Plain';
	protected const CACHE_TTL = 86400;

	/**
	 * Ask.
	 *
	 * @param string $query What to ask.
	 *
	 * @return mixed
	 */
	public function ask( string $query ): mixed {
		return $this->remember( $query, fn() => $this->json( $this->request( 'GET', 'https://plain.test/' . $query ) ) );
	}
}

/**
 * Acme's answer.
 */
final class AcmeAnswer extends Response {

	/**
	 * @return int
	 */
	public function score(): int {
		return (int) $this->get( 'score', 0 );
	}
}

/**
 * What every service built on the base shares.
 */
final class ServiceTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		rs_reset();
	}

	/**
	 * Queue a JSON answer.
	 *
	 * @param mixed $data   What to encode.
	 * @param int   $status The status.
	 *
	 * @return void
	 */
	private function queue( mixed $data, int $status = 200 ): void {
		$GLOBALS['rs_http'][] = [
			'response' => [ 'code' => $status ],
			'body'     => is_string( $data ) ? $data : (string) json_encode( $data ),
		];
	}

	/**
	 * Queue a transport failure.
	 *
	 * @return void
	 */
	private function queue_failure(): void {
		$GLOBALS['rs_http'][] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}

	/**
	 * An answer is read, and a second ask comes from the cache.
	 */
	public function test_an_answer_is_cached(): void {
		$this->queue( [ 'score' => 42 ] );
		$acme = new Acme( 'key' );

		$this->assertSame( 42, $acme->check( '8.8.8.8' )->score() );
		$this->assertSame( 42, $acme->check( '8.8.8.8' )->score() );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );
		$this->assertSame( 'application/json', $GLOBALS['rs_requests'][0]['args']['headers']['Accept'] );
		$this->assertSame( 15, $GLOBALS['rs_requests'][0]['args']['timeout'] );
	}

	/**
	 * Keys are the family, the id, and a hash of the question and the API
	 * key -- unchanged until the first clear.
	 */
	public function test_keys_name_the_family_and_the_service(): void {
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'a' => 1 ] );

		( new Acme( 'key' ) )->check( '8.8.8.8' );
		( new Plain( 'key' ) )->ask( 'x' );

		$this->assertSame(
			[ 'lookups_acme_' . md5( '8.8.8.8|key' ), 'remote_plain_' . md5( 'x|key' ) ],
			array_keys( $GLOBALS['rs_transients'] )
		);
	}

	/**
	 * An answer is kept for the cache lifetime and no longer; one asked for
	 * with a lifetime of its own keeps that one.
	 */
	public function test_an_answer_expires(): void {
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'n' => 1 ] );
		$this->queue( [ 'n' => 2 ] );
		$this->queue( [ 'score' => 2 ] );
		$acme = new Acme( 'key', [ 'cache_ttl' => 100 ] );

		$acme->check( '8.8.8.8' );
		$acme->search( 'q' );
		$GLOBALS['rs_now'] += 30;
		$this->assertSame( [ 'n' => 2 ], $acme->search( 'q' ) );
		$GLOBALS['rs_now'] += 69;
		$this->assertSame( 1, $acme->check( '8.8.8.8' )->score() );
		$GLOBALS['rs_now'] += 1;
		$this->assertSame( 2, $acme->check( '8.8.8.8' )->score() );
	}

	/**
	 * A failure is remembered briefly, per question, then retried.
	 */
	public function test_a_failure_is_remembered_briefly(): void {
		$this->queue_failure();
		$this->queue( [ 'score' => 3 ] );
		$this->queue( [ 'score' => 7 ] );
		$acme = new Acme( 'key' );

		$this->assertSame( 'acme_http_error', $acme->check( '8.8.8.8' )->get_error_code() );
		$this->assertSame( 'acme_recent_failure', $acme->check( '8.8.8.8' )->get_error_code() );
		$this->assertSame( 3, $acme->check( '1.1.1.1' )->score(), 'A failure for one address blinded the client to another.' );

		$GLOBALS['rs_now'] += 60;
		$this->assertSame( 7, $acme->check( '8.8.8.8' )->score() );
		$this->assertCount( 3, $GLOBALS['rs_requests'] );
	}

	/**
	 * A failure lifetime of 0 retries every time; with the cache off
	 * nothing is read, kept or remembered.
	 */
	public function test_the_cache_and_the_failure_memory_turn_off(): void {
		$this->queue_failure();
		$this->queue_failure();
		$acme = new Acme( 'key', [ 'failure_ttl' => 0 ] );
		$acme->check( '8.8.8.8' );
		$acme->check( '8.8.8.8' );
		$this->assertCount( 2, $GLOBALS['rs_requests'] );

		rs_reset();
		$this->queue_failure();
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'score' => 1 ] );
		$acme = new Acme( 'key', [ 'cache' => false ] );
		$acme->check( '8.8.8.8' );
		$acme->check( '8.8.8.8' );
		$acme->check( '8.8.8.8' );
		$this->assertCount( 3, $GLOBALS['rs_requests'] );
		$this->assertSame( [], $GLOBALS['rs_transients'] );
	}

	/**
	 * Two API keys never share an answer.
	 */
	public function test_the_cache_is_per_key(): void {
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'score' => 2 ] );

		$this->assertSame( 1, ( new Acme( 'one' ) )->check( '8.8.8.8' )->score() );
		$this->assertSame( 2, ( new Acme( 'two' ) )->check( '8.8.8.8' )->score() );
	}

	/**
	 * Clearing one answer, then every answer with the prefix -- and
	 * nothing of another service's.
	 */
	public function test_clear_cache(): void {
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'score' => 1 ] );
		$this->queue( [ 'a' => 1 ] );
		$acme = new Acme( 'key' );
		$acme->check( '8.8.8.8' );
		$acme->check( '1.1.1.1' );
		( new Plain( 'key' ) )->ask( 'x' );

		$this->assertTrue( $acme->clear_cache( '8.8.8.8' ) );
		$this->assertCount( 2, $GLOBALS['rs_transients'] );

		$this->assertTrue( $acme->clear_cache() );
		$this->assertSame( [ 'remote_plain_' . md5( 'x|key' ) ], array_keys( $GLOBALS['rs_transients'] ) );
	}

	/**
	 * With a persistent object cache the transients are not in the options
	 * table; clearing everything must still mean the next ask goes out.
	 */
	public function test_clear_cache_with_a_persistent_object_cache(): void {
		$GLOBALS['rs_object_cache'] = true;
		$this->queue( [ 'score' => 10 ] );
		$this->queue( [ 'score' => 20 ] );
		$this->queue( [ 'score' => 30 ] );
		$acme = new Acme( 'key' );

		$this->assertSame( 10, $acme->check( '8.8.8.8' )->score() );
		$this->assertTrue( $acme->clear_cache() );
		$this->assertSame( 20, $acme->check( '8.8.8.8' )->score() );
		$this->assertSame( 20, $acme->check( '8.8.8.8' )->score() );

		// Another client of the same service shares the clearing.
		$this->assertTrue( ( new Acme( 'key' ) )->clear_cache() );
		$this->assertSame( 30, $acme->check( '8.8.8.8' )->score() );
		$this->assertCount( 3, $GLOBALS['rs_requests'] );
	}

	/**
	 * An address off the public internet is refused before any request.
	 */
	public function test_an_address_not_worth_asking_about_costs_nothing(): void {
		foreach ( [ '', 'not-an-ip', '192.168.1.10', '127.0.0.1', '203.0.113.9', '100.64.0.1', '::1', '64:ff9b::7f00:1' ] as $ip ) {
			$this->assertFalse( Acme::is_lookupable( $ip ), $ip );
			$this->assertSame( 'acme_invalid_ip', ( new Acme( 'key' ) )->check( $ip )->get_error_code(), $ip );
		}

		$this->assertTrue( Acme::is_lookupable( ' 8.8.8.8 ' ) );
		$this->assertSame( [], $GLOBALS['rs_requests'] );
	}

	/**
	 * A status outside 2xx, or a body that is not a JSON object, is an
	 * error that says which.
	 */
	public function test_an_unreadable_answer_is_an_error(): void {
		$this->queue( [ 'error' => 'quota' ], 429 );
		$this->queue( 'not json' );
		$acme = new Acme( 'key', [ 'failure_ttl' => 0 ] );

		$error = $acme->check( '8.8.8.8' );
		$this->assertSame( 'acme_api_error', $error->get_error_code() );
		$this->assertSame( [ 'status' => 429 ], $error->get_error_data() );
		$this->assertSame( 'acme_bad_response', $acme->check( '8.8.8.8' )->get_error_code() );
	}

	/**
	 * The settings, held to what makes sense.
	 */
	public function test_settings(): void {
		$acme = new Acme( 'key', [ 'cache_ttl' => -5, 'failure_ttl' => -1 ] );

		$this->assertSame( 0, $acme->get_cache_ttl() );
		$this->assertSame( 0, $acme->get_failure_ttl() );
		$this->assertSame( 3600, ( new Acme() )->get_cache_ttl() );
		$this->assertSame( 86400, ( new Plain() )->get_cache_ttl() );
		$this->assertSame( 'acme', $acme->get_id() );
		$this->assertSame( 'Acme', $acme->get_name() );
		$this->assertSame( 'other', $acme->set_api_key( 'other' )->get_api_key() );
		$this->assertFalse( $acme->set_cache_enabled( false )->is_cache_enabled() );
	}

	/**
	 * A response hands back what the service said, by its own names.
	 */
	public function test_a_response_reads_the_answer(): void {
		$answer = new AcmeAnswer( [ 'score' => 9, 'isp' => 'Example' ] );

		$this->assertSame( 9, $answer->score() );
		$this->assertSame( 'Example', $answer->isp );
		$this->assertTrue( isset( $answer->isp ) );
		$this->assertFalse( isset( $answer->asn ) );
		$this->assertNull( $answer->asn );
		$this->assertSame( 'none', $answer->get( 'asn', 'none' ) );
		$this->assertSame( [ 'score' => 9, 'isp' => 'Example' ], $answer->to_array() );
	}
}
