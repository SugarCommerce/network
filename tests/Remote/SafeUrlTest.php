<?php
/**
 * The one outbound-address guard: every threat it stands against.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Remote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\Remote\SafeUrl;
use WP_Error;

/**
 * An address somebody typed in must not make the server ask inside its own
 * network -- by name, by literal, by any spelling of an address, by one
 * private answer among several, or by a redirect -- and nothing at all may
 * reach a metadata service.
 */
final class SafeUrlTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		ru_reset_globals();
		rs_reset();
		$GLOBALS['su_pin'] = null;
	}

	/**
	 * A guard whose DNS answers from a table; an IP literal is itself.
	 *
	 * @param array<string, string[]> $dns  Host => addresses.
	 * @param array<string, bool>     $args Constructor flags.
	 *
	 * @return SafeUrl
	 */
	private function guard( array $dns = [], array $args = [] ): SafeUrl {
		return new SafeUrl( ...( $args + [ 'resolver' => static fn( string $host ): array => $dns[ $host ] ?? SafeUrl::resolve( $host ) ] ) );
	}

	/**
	 * Every name resolves, and to somewhere public.
	 *
	 * @param array<string, bool> $args Constructor flags.
	 *
	 * @return SafeUrl
	 */
	private function anywhere( array $args = [] ): SafeUrl {
		return new SafeUrl( ...( $args + [ 'resolver' => static fn(): array => [ '93.184.216.34' ] ] ) );
	}

	/**
	 * Addresses and whether each is the public internet.
	 *
	 * @param string $ip       The address.
	 * @param bool   $expected Whether it is public.
	 */
	#[DataProvider( 'addresses' )]
	public function test_which_addresses_are_public( string $ip, bool $expected ): void {
		$this->assertSame( $expected, IP::is_public( $ip ) );
	}

	/**
	 * Every kind of address that is not the internet, and some that are.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function addresses(): array {
		return [
			'public v4'             => [ '93.184.216.34', true ],
			'public v6'             => [ '2606:2800:220:1:248:1893:25c8:1946', true ],
			'loopback'              => [ '127.0.0.1', false ],
			'loopback, elsewhere'   => [ '127.8.9.10', false ],
			'ten'                   => [ '10.0.0.5', false ],
			'one-seven-two'         => [ '172.16.0.1', false ],
			'one-seven-two, top'    => [ '172.31.255.255', false ],
			'just past it'          => [ '172.32.0.1', true ],
			'one-nine-two'          => [ '192.168.1.1', false ],
			'metadata service'      => [ '169.254.169.254', false ],
			'carrier NAT'           => [ '100.64.0.1', false ],
			'this network'          => [ '0.0.0.0', false ],
			'multicast'             => [ '224.0.0.1', false ],
			'documentation'         => [ '203.0.113.9', false ],
			'v6 loopback'           => [ '::1', false ],
			'v6 unique local'       => [ 'fd00::1', false ],
			'v6 link-local'         => [ 'fe80::1', false ],
			'mapped metadata'       => [ '::ffff:169.254.169.254', false ],
			'mapped public'         => [ '::ffff:93.184.216.34', true ],
			'mapped, in hex'        => [ '::ffff:a9fe:a9fe', false ],
			'NAT64 metadata'        => [ '64:ff9b::a9fe:a9fe', false ],
			'NAT64, written long'   => [ '0064:ff9b::a9fe:a9fe', false ],
			'6to4'                  => [ '2002:0a00:0005::1', false ],
			'v6 documentation'      => [ '2001:db8::1', false ],
			'bracketed v6 loopback' => [ '[::1]', false ],
			'not an address'        => [ 'localhost', false ],
		];
	}

	/**
	 * The IPv4 address an IPv6 one carries is what a packet goes to.
	 */
	public function test_the_carried_address_is_the_one_judged(): void {
		$this->assertSame( '169.254.169.254', IP::carried( '::ffff:a9fe:a9fe' ) );
		$this->assertSame( '169.254.169.254', IP::carried( '64:ff9b::a9fe:a9fe' ) );
		$this->assertSame( '10.0.0.5', IP::carried( '2002:0a00:0005::1' ) );
		$this->assertSame( '2001:db8::1', IP::carried( '[2001:DB8:0::1]' ) );
		$this->assertNull( IP::carried( 'localhost' ) );
	}

	/**
	 * Addresses refused before anything is looked up.
	 *
	 * @param string $url The address.
	 */
	#[DataProvider( 'malformed' )]
	public function test_malformed_addresses_are_refused( string $url ): void {
		// What refuses these has to be the shape of the address, not the
		// lookup: every name resolves, and to somewhere public.
		$this->assertNotNull( $this->anywhere()->refuse( $url ) );
		$this->assertNotNull( $this->anywhere( [ 'allow_private' => true ] )->malformed( $url ) );
	}

	/**
	 * Addresses that parsers disagree about, and numbers that are addresses.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed(): array {
		return [
			'credentials'      => [ 'https://real.example@10.0.0.5/' ],
			'user only'        => [ 'https://admin@hooks.example/' ],
			'not http'         => [ 'gopher://hooks.example/' ],
			'file'             => [ 'file:///etc/passwd' ],
			'a newline'        => [ "https://hooks.example\n/" ],
			'a space'          => [ 'https://hooks .example/' ],
			'encoded dot'      => [ 'https://hooks%2eexample/' ],
			'no host'          => [ 'https:///path' ],
			'unicode host'     => [ 'https://hööks.example/' ],
			'a zone index'     => [ 'https://[fe80::1%25en0]/' ],
			'double dot'       => [ 'https://hooks..example/' ],
			'decimal address'  => [ 'http://2852039166/' ],
			'hex address'      => [ 'http://0xa9fea9fe/' ],
			'octal address'    => [ 'http://0251.0376.0251.0376/' ],
			'short address'    => [ 'http://169.254.43518/' ],
			'google metadata'  => [ 'http://metadata.google.internal/computeMetadata/v1/' ],
			'trailing dot'     => [ 'http://METADATA.google.internal./' ],
			'aws metadata'     => [ 'http://169.254.169.254/latest/meta-data/' ],
			'alibaba metadata' => [ 'http://100.100.100.200/latest/meta-data/' ],
			'unspecified'      => [ 'http://0.0.0.0:9000/' ],
			'a bare zero'      => [ 'http://0/' ],
		];
	}

	/**
	 * The same address, well formed, passes: the refusals above are about shape.
	 */
	public function test_a_plain_address_passes(): void {
		$this->assertNull( $this->anywhere()->refuse( 'https://hooks.example/in?x=1' ) );
		$this->assertNull( $this->anywhere()->malformed( 'http://hooks.example/in?x=1' ) );
	}

	/**
	 * https_only refuses http, and nothing else changes.
	 */
	public function test_https_only_refuses_http(): void {
		$guard = $this->anywhere( [ 'https_only' => true ] );

		$this->assertNotNull( $guard->refuse( 'http://store.example/' ) );
		$this->assertNotNull( $guard->malformed( 'HTTP://store.example/' ) );
		$this->assertNull( $guard->refuse( 'https://store.example/' ) );
		$this->assertNull( $this->anywhere()->refuse( 'http://store.example/' ) );
	}

	/**
	 * A private literal, by any spelling.
	 */
	public function test_private_literals_are_refused(): void {
		$guard = $this->guard();

		$this->assertNotNull( $guard->refuse( 'http://127.0.0.1/' ) );
		$this->assertNotNull( $guard->refuse( 'http://10.0.0.5/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[::1]/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[fd00::1]/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[::ffff:169.254.169.254]/latest/meta-data/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[64:ff9b::7f00:1]/' ) );
		$this->assertNull( $guard->refuse( 'https://93.184.216.34/' ) );
	}

	/**
	 * A name is judged by every address it has, not the first.
	 */
	public function test_one_private_answer_among_public_ones_is_refused(): void {
		$guard = $this->guard(
			[
				'rebind.example' => [ '93.184.216.34', '10.0.0.5' ],
				'six.example'    => [ '93.184.216.34', 'fd00::5' ],
				'fine.example'   => [ '93.184.216.34', '2606:2800:220:1::1' ],
			]
		);

		$this->assertNotNull( $guard->refuse( 'https://rebind.example/' ) );
		$this->assertNotNull( $guard->refuse( 'https://six.example/' ), 'The AAAA record was not checked.' );
		$this->assertNull( $guard->refuse( 'https://fine.example/' ) );
	}

	/**
	 * A name that does not resolve has nowhere to go; nor does one that
	 * resolves to something that is not an address.
	 */
	public function test_a_name_that_does_not_resolve_is_refused(): void {
		$this->assertNotNull( $this->guard( [ 'nowhere.example' => [] ] )->refuse( 'https://nowhere.example/' ) );
		$this->assertNotNull( $this->guard( [ 'odd.example' => [ 'localhost' ] ], [ 'allow_private' => true ] )->refuse( 'https://odd.example/' ) );
	}

	/**
	 * WordPress's own escape hatch is honoured, for a development setup.
	 */
	public function test_the_core_filter_allows_a_private_host(): void {
		add_filter( 'http_request_host_is_external', static fn( $external, string $host ): bool => 'hooks.test' === $host );

		$guard = $this->guard(
			[
				'hooks.test' => [ '127.0.0.1' ],
				'other.test' => [ '127.0.0.1' ],
			]
		);

		$this->assertNull( $guard->refuse( 'http://hooks.test/' ) );
		$this->assertNotNull( $guard->refuse( 'http://other.test/' ) );
	}

	/**
	 * Private networks and loopback can be allowed: a storage server on the
	 * LAN, a development setup.
	 */
	public function test_private_addresses_can_be_allowed(): void {
		$guard = $this->guard( [ 'minio.lan' => [ '192.168.1.20' ] ], [ 'allow_private' => true ] );

		$this->assertNull( $guard->refuse( 'http://127.0.0.1/' ) );
		$this->assertNull( $guard->refuse( 'http://minio.lan:9000/' ) );
		$this->assertNull( $guard->refuse( 'http://[fd00::1]/' ) );
	}

	/**
	 * The site's own host is asked when that is allowed, wherever it
	 * resolves -- and only then.
	 */
	public function test_the_sites_own_host_can_be_allowed(): void {
		$dns = [
			'example.test'  => [ '127.0.0.1' ],
			'example.other' => [ '127.0.0.1' ],
		];

		$this->assertNull( $this->guard( $dns, [ 'own_host' => true ] )->refuse( 'https://EXAMPLE.test/' ) );
		$this->assertNotNull( $this->guard( $dns, [ 'own_host' => true ] )->refuse( 'https://example.other/' ) );
		$this->assertNotNull( $this->guard( $dns )->refuse( 'https://example.test/' ) );
	}

	/**
	 * Nothing lets a request reach a metadata service, link-local or "no
	 * address": not allow_private, not the site's own host, not the filter.
	 *
	 * @param string $ip What the name resolves to.
	 */
	#[DataProvider( 'unreachable' )]
	public function test_metadata_and_link_local_are_never_reached( string $ip ): void {
		add_filter( 'http_request_host_is_external', static fn(): bool => true );

		$dns = [
			'evil.example' => [ $ip ],
			'example.test' => [ $ip ],
		];

		$this->assertNotNull( $this->guard( $dns, [ 'allow_private' => true ] )->refuse( 'http://evil.example/' ) );
		$this->assertNotNull( $this->guard( $dns, [ 'own_host' => true ] )->refuse( 'https://example.test/' ) );
		$this->assertNotNull( $this->guard( $dns, [ 'allow_private' => true ] )->refuse( 'http://[' . trim( $ip, '[]' ) . ']/' ) );
	}

	/**
	 * Addresses no setting reaches.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unreachable(): array {
		return [
			'aws metadata'       => [ '169.254.169.254' ],
			'ecs credentials'    => [ '169.254.170.2' ],
			'alibaba metadata'   => [ '100.100.100.200' ],
			'this network'       => [ '0.0.0.0' ],
			'v6 unspecified'     => [ '::' ],
			'v6 link-local'      => [ 'fe80::1' ],
			'aws v6 metadata'    => [ 'fd00:ec2::254' ],
			'google v6 metadata' => [ 'fd20:ce::254' ],
			'mapped metadata'    => [ '::ffff:169.254.169.254' ],
			'NAT64 metadata'     => [ '64:ff9b::a9fe:a9fe' ],
		];
	}

	/**
	 * The host-only check a settings screen makes as it saves: metadata
	 * services and numbers that are addresses, never a private network.
	 */
	public function test_a_metadata_host_is_recognised_without_dns(): void {
		foreach ( [ '169.254.169.254', '169.254.170.2:80', 'METADATA.google.internal', 'metadata.google.internal.', '100.100.100.200', '0.0.0.0', '0.0.0.0:9000', '0', '2852039166', '0xa9fea9fe', '0251.0376.0251.0376', '169.254.43518', '[fe80::1]', '[::ffff:169.254.169.254]:80', 'fd00:ec2::254', 'instance-data' ] as $host ) {
			$this->assertTrue( SafeUrl::is_metadata_host( $host ), $host );
		}

		foreach ( [ 'minio.local:9000', '192.168.1.20:9000', '10.0.0.5', '127.0.0.1:9000', 's3.eu-west-2.amazonaws.com', '169.254.example.com', '100.100.100.201', '[::1]:9000', '[fd00::1]' ] as $host ) {
			$this->assertFalse( SafeUrl::is_metadata_host( $host ), $host );
		}
	}

	/**
	 * A refused address is never requested.
	 */
	public function test_a_refused_request_is_never_made(): void {
		$guard = $this->guard( [ 'inside.example' => [ '10.1.2.3' ] ] );

		$this->assertInstanceOf( WP_Error::class, $guard->post( 'https://inside.example/', [ 'body' => 'x' ] ) );
		$this->assertInstanceOf( WP_Error::class, $guard->get( 'https://inside.example/' ) );
		$this->assertSame( [], $GLOBALS['rs_requests'] );
	}

	/**
	 * Core's safe variant is the base; the plain one only where private
	 * addresses are allowed, which core's would refuse after this passed them.
	 */
	public function test_core_safe_requests_unless_private_is_allowed(): void {
		$GLOBALS['rs_http'] = [ su_answer( 200 ), su_answer( 200 ), su_answer( 200 ) ];

		$this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->post( 'https://hooks.example/' );
		$this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->get( 'https://hooks.example/' );
		$this->guard( [ 'minio.lan' => [ '192.168.1.20' ] ], [ 'allow_private' => true ] )->get( 'http://minio.lan:9000/' );

		$this->assertSame( [ 'POST', 'GET', 'GET' ], array_column( $GLOBALS['rs_requests'], 'method' ) );
		$this->assertTrue( $GLOBALS['rs_requests'][0]['args']['safe'] );
		$this->assertTrue( $GLOBALS['rs_requests'][1]['args']['safe'] );
		$this->assertArrayNotHasKey( 'safe', $GLOBALS['rs_requests'][2]['args'] );
		$this->assertFalse( $GLOBALS['rs_requests'][2]['args']['reject_unsafe_urls'] );
	}

	/**
	 * Without following, a redirect is the answer.
	 */
	public function test_a_redirect_is_not_followed_by_default(): void {
		$GLOBALS['rs_http'][] = su_answer( 308, [ 'Location' => 'https://elsewhere.example/' ] );

		$result = $this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->post( 'https://hooks.example/', [ 'redirection' => 5 ] );

		$this->assertSame( 308, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );
		$this->assertSame( 0, $GLOBALS['rs_requests'][0]['args']['redirection'] );
	}

	/**
	 * Followed, each hop is checked: a public address cannot bounce inside.
	 */
	public function test_a_redirect_inside_the_network_is_refused(): void {
		$GLOBALS['rs_http'][] = su_answer( 307, [ 'Location' => 'http://10.0.0.5/admin' ] );

		$result = $this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->post( 'https://hooks.example/', [], 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );
	}

	/**
	 * Not even with private addresses allowed may a redirect reach metadata.
	 */
	public function test_a_redirect_to_metadata_is_refused_whatever_is_allowed(): void {
		$GLOBALS['rs_http'][] = su_answer( 302, [ 'Location' => 'http://169.254.169.254/latest/meta-data/' ] );

		$result = $this->guard( [ 'minio.lan' => [ '192.168.1.20' ] ], [ 'allow_private' => true ] )->get( 'http://minio.lan/', [], 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );
	}

	/**
	 * A redirect to somewhere public is followed with the same request.
	 */
	public function test_a_public_redirect_is_followed(): void {
		$GLOBALS['rs_http'] = [ su_answer( 308, [ 'Location' => '/v2/hook' ] ), su_answer( 200 ) ];

		$result = $this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->post( 'https://hooks.example/v1/hook', [ 'body' => 'same' ], 1 );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $result ) );
		$this->assertSame( 'https://hooks.example/v2/hook', $GLOBALS['rs_requests'][1]['url'] );
		$this->assertSame( 'same', $GLOBALS['rs_requests'][1]['args']['body'] );
	}

	/**
	 * Relative and scheme-relative locations are read against the address
	 * that answered.
	 */
	public function test_relative_locations_are_resolved(): void {
		$GLOBALS['rs_http'] = [
			su_answer( 302, [ 'Location' => 'next' ] ),
			su_answer( 302, [ 'Location' => '//other.example/x' ] ),
			su_answer( 200 ),
		];

		$this->anywhere()->get( 'https://hooks.example:8443/a/b', [], 2 );

		$this->assertSame( 'https://hooks.example:8443/a/next', $GLOBALS['rs_requests'][1]['url'] );
		$this->assertSame( 'https://other.example/x', $GLOBALS['rs_requests'][2]['url'] );
	}

	/**
	 * Only as many as asked for.
	 */
	public function test_redirects_stop_at_the_limit(): void {
		$GLOBALS['rs_http'] = [
			su_answer( 302, [ 'Location' => 'https://hooks.example/a' ] ),
			su_answer( 302, [ 'Location' => 'https://hooks.example/b' ] ),
		];

		$result = $this->guard( [ 'hooks.example' => [ '93.184.216.34' ] ] )->post( 'https://hooks.example/', [], 1 );

		$this->assertSame( 302, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 2, $GLOBALS['rs_requests'] );
	}

	/**
	 * A 303 says "go and GET something else": not a delivery of a post, but
	 * followed by a get.
	 */
	public function test_a_303_is_followed_only_by_a_get(): void {
		$GLOBALS['rs_http'] = [ su_answer( 303, [ 'Location' => 'https://hooks.example/thanks' ] ) ];

		$result = $this->anywhere()->post( 'https://hooks.example/', [], 3 );

		$this->assertSame( 303, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );

		rs_reset();
		$GLOBALS['rs_http'] = [ su_answer( 303, [ 'Location' => 'https://hooks.example/thanks' ] ), su_answer( 200 ) ];

		$this->assertSame( 200, wp_remote_retrieve_response_code( $this->anywhere()->get( 'https://hooks.example/', [], 3 ) ) );
		$this->assertCount( 2, $GLOBALS['rs_requests'] );
	}

	/**
	 * The pin names the host and port that were checked, and goes afterwards.
	 */
	public function test_the_pin_is_for_this_request_only(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'No curl.' );
		}

		$GLOBALS['rs_http'] = [ su_answer( 200 ), su_answer( 200 ) ];

		$this->guard( [ 'hooks.example' => [ '2606:2800:220:1::1' ] ] )->post( 'http://hooks.example:8080/' );
		$this->guard( [ 'store.example' => [ '93.184.216.34' ] ] )->get( 'https://store.example/' );

		$this->assertSame( [ 'hooks.example:8080:[2606:2800:220:1::1]' ], $GLOBALS['rs_requests'][0]['args']['pin'] );
		$this->assertSame( [ 'store.example:443:93.184.216.34' ], $GLOBALS['rs_requests'][1]['args']['pin'] );
		$this->assertSame( [], array_values( $GLOBALS['ru_actions']['http_api_curl'] ?? [] ) );
	}

	/**
	 * Any other method passes the same checks: a PUT inside the network is
	 * never made, a DELETE to metadata never even with private allowed.
	 */
	public function test_another_method_is_checked_the_same(): void {
		$guard = $this->guard( [ 'inside.example' => [ '10.1.2.3' ] ] );

		$this->assertInstanceOf( WP_Error::class, $guard->request( 'PUT', 'https://inside.example/key', [ 'body' => 'x' ] ) );
		$this->assertInstanceOf( WP_Error::class, $this->anywhere( [ 'allow_private' => true ] )->request( 'DELETE', 'http://169.254.169.254/latest/' ) );
		$this->assertSame( [], $GLOBALS['rs_requests'] );
	}

	/**
	 * Another method goes out as itself, through core's safe call unless
	 * private addresses are allowed, pinned like a get or a post.
	 */
	public function test_another_method_goes_out_as_itself_and_pinned(): void {
		$GLOBALS['rs_http'] = [ su_answer( 200 ), su_answer( 204 ) ];

		$this->guard( [ 'store.example' => [ '93.184.216.34' ] ] )->request( 'put', 'https://store.example/key', [ 'body' => 'bytes', 'method' => 'GET' ] );
		$this->guard( [ 'minio.lan' => [ '192.168.1.20' ] ], [ 'allow_private' => true ] )->request( 'DELETE', 'http://minio.lan:9000/key' );

		$this->assertSame( [ 'PUT', 'DELETE' ], array_column( $GLOBALS['rs_requests'], 'method' ) );
		$this->assertSame( 'bytes', $GLOBALS['rs_requests'][0]['args']['body'] );
		$this->assertTrue( $GLOBALS['rs_requests'][0]['args']['safe'] );
		$this->assertArrayNotHasKey( 'safe', $GLOBALS['rs_requests'][1]['args'] );
		$this->assertFalse( $GLOBALS['rs_requests'][1]['args']['reject_unsafe_urls'] );

		if ( function_exists( 'curl_init' ) ) {
			$this->assertSame( [ 'store.example:443:93.184.216.34' ], $GLOBALS['rs_requests'][0]['args']['pin'] );
			$this->assertSame( [ 'minio.lan:9000:192.168.1.20' ], $GLOBALS['rs_requests'][1]['args']['pin'] );
		}
	}

	/**
	 * Another method follows only a 307 or 308, which keep it, and checks the
	 * hop: a 302 is the answer, and a 307 inside the network is refused.
	 */
	public function test_another_method_follows_only_what_keeps_it(): void {
		$GLOBALS['rs_http'] = [ su_answer( 302, [ 'Location' => 'https://store.example/b' ] ) ];

		$this->assertSame( 302, wp_remote_retrieve_response_code( $this->anywhere()->request( 'PUT', 'https://store.example/a', [], 3 ) ) );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );

		rs_reset();
		$GLOBALS['rs_http'] = [ su_answer( 307, [ 'Location' => 'https://store.example/b' ] ), su_answer( 200 ) ];

		$this->assertSame( 200, wp_remote_retrieve_response_code( $this->anywhere()->request( 'PUT', 'https://store.example/a', [], 3 ) ) );
		$this->assertSame( 'PUT', $GLOBALS['rs_requests'][1]['method'] );

		rs_reset();
		$GLOBALS['rs_http'] = [ su_answer( 308, [ 'Location' => 'http://10.0.0.5/' ] ) ];

		$this->assertInstanceOf( WP_Error::class, $this->guard( [ 'store.example' => [ '93.184.216.34' ] ] )->request( 'PUT', 'https://store.example/a', [], 3 ) );
		$this->assertCount( 1, $GLOBALS['rs_requests'] );
	}
}
