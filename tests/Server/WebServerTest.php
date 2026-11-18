<?php
/**
 * Which web server this is, and what it can do.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Server;

use SugarCommerce\Network\Cloudflare;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What answers here decides whether a plugin writes .htaccess rules that
 * nginx will never read, or tells somebody to add rules by hand on a
 * server that would have accepted the file.
 */
final class WebServerTest extends TestCase {

	protected function setUp(): void {
		env_reset();
		vc_reset_filters();
	}

	protected function tearDown(): void {
		env_reset();
		vc_reset_filters();
	}

	/**
	 * A stand-in request with this server software.
	 *
	 * @param string $software SERVER_SOFTWARE.
	 * @param array  $more     Anything else.
	 *
	 * @return array
	 */
	private function on( string $software, array $more = [] ): array {
		return [ 'SERVER_SOFTWARE' => $software ] + $more;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function software(): array {
		return [
			'apache'    => [ 'Apache/2.4.62 (Unix)', 'is_apache' ],
			'nginx'     => [ 'nginx/1.27.0', 'is_nginx' ],
			'litespeed' => [ 'LiteSpeed', 'is_litespeed' ],
			'iis'       => [ 'Microsoft-IIS/10.0', 'is_iis' ],
		];
	}

	/**
	 * @param string $software The header.
	 * @param string $method   The check that should answer true.
	 */
	#[DataProvider( 'software' )]
	public function test_it_recognises_the_server( string $software, string $method ): void {
		$server = $this->on( $software );

		$this->assertTrue( Server::$method( $server ), $software . ' was not recognised' );
		$this->assertSame( $software, Server::software( $server ) );

		foreach ( [ 'is_apache', 'is_nginx', 'is_litespeed', 'is_iis' ] as $other ) {
			if ( $other !== $method ) {
				$this->assertFalse( Server::$other( $server ), $other . ' claimed ' . $software );
			}
		}
	}

	/**
	 * Each call reads the request it is given -- there is no cache for
	 * the first request to decide the rest.
	 */
	public function test_nothing_is_cached_between_calls(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27.0';
		$this->assertTrue( Server::is_nginx() );

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		$this->assertTrue( Server::is_apache() );
		$this->assertFalse( Server::is_nginx() );

		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertSame( '', Server::software() );
	}

	/**
	 * Local runs Flywheel, which is nginx under another name.
	 */
	public function test_flywheel_is_nginx(): void {
		$this->assertTrue( Server::is_nginx( $this->on( 'Flywheel/5.0' ) ) );
		$this->assertFalse( Server::is_apache( $this->on( 'Flywheel/5.0' ) ) );
	}

	/**
	 * An unknown server claims nothing.
	 */
	public function test_an_unknown_server_answers_no_to_everything(): void {
		$server = $this->on( 'Caddy/2.8.4' );

		foreach ( [ 'is_apache', 'is_nginx', 'is_litespeed', 'is_iis', 'supports_url_rewriting', 'has_xsendfile', 'supports_htaccess' ] as $check ) {
			$this->assertFalse( Server::$check( $server ), $check . ' claimed Caddy' );
		}

		$this->assertNull( Server::apache_modules( $server ) );
	}

	/**
	 * Markup in the server's name is not passed on.
	 */
	public function test_the_name_is_sanitized(): void {
		$this->assertSame( 'nginx', Server::software( $this->on( '<b>nginx</b>' ) ) );
		$this->assertSame( '', Server::software( [ 'SERVER_SOFTWARE' => [ 'nginx' ] ] ) );
	}

	/**
	 * mod_rewrite from Apache's own list when PHP can see it.
	 */
	public function test_mod_rewrite_from_the_module_list(): void {
		$apache = $this->on( 'Apache/2.4.62 (Unix)' );

		$GLOBALS['ru_apache_modules'] = [ 'core', 'mod_rewrite' ];
		$this->assertTrue( Server::has_mod_rewrite( $apache ) );
		$this->assertTrue( Server::supports_htaccess( $apache ) );
		$this->assertTrue( Server::supports_url_rewriting( $apache ) );
		$this->assertSame( [ 'core', 'mod_rewrite' ], Server::apache_modules( $apache ) );
		$this->assertTrue( Server::has_apache_module( 'core', $apache ) );
		$this->assertFalse( Server::has_apache_module( 'mod_headers', $apache ) );

		$GLOBALS['ru_apache_modules'] = [ 'core' ];
		$this->assertFalse( Server::has_mod_rewrite( $apache ) );
		$this->assertFalse( Server::supports_htaccess( $apache ) );
		$this->assertFalse( Server::supports_url_rewriting( $apache ) );

		// Not Apache: the list is not asked.
		$GLOBALS['ru_apache_modules'] = [ 'mod_rewrite' ];
		$this->assertFalse( Server::has_mod_rewrite( $this->on( 'nginx' ) ) );
		$this->assertFalse( Server::supports_htaccess( $this->on( 'nginx' ) ) );
		$this->assertNull( Server::apache_modules( $this->on( 'nginx' ) ) );
	}

	/**
	 * Under PHP-FPM the list cannot be read, and WordPress's own rules
	 * set a variable instead -- renamed after an internal redirect.
	 */
	public function test_mod_rewrite_from_the_variable_when_the_list_is_hidden(): void {
		$apache = $this->on( 'Apache/2.4.62 (Unix)' );

		$this->assertFalse( Server::has_mod_rewrite( $apache ), 'Nothing says it is on.' );
		$this->assertNull( Server::apache_modules( $apache ) );
		$this->assertFalse( Server::has_apache_module( 'mod_rewrite', $apache ) );

		$this->assertTrue( Server::has_mod_rewrite( $apache + [ 'HTTP_MOD_REWRITE' => 'On' ] ) );
		$this->assertTrue( Server::has_mod_rewrite( $apache + [ 'REDIRECT_HTTP_MOD_REWRITE' => 'On' ] ) );
		$this->assertFalse( Server::has_mod_rewrite( $apache + [ 'HTTP_MOD_REWRITE' => 'Off' ] ) );

		putenv( 'HTTP_MOD_REWRITE=On' );
		$this->assertTrue( Server::has_mod_rewrite( $apache ) );
		putenv( 'HTTP_MOD_REWRITE' );

		putenv( 'REDIRECT_HTTP_MOD_REWRITE=On' );
		$this->assertTrue( Server::has_mod_rewrite( $apache ) );
	}

	/**
	 * nginx and LiteSpeed rewrite without a module to ask about; IIS
	 * needs its URL Rewrite module.
	 */
	public function test_rewriting_elsewhere(): void {
		$this->assertTrue( Server::supports_url_rewriting( $this->on( 'nginx/1.27.0' ) ) );
		$this->assertTrue( Server::supports_url_rewriting( $this->on( 'LiteSpeed' ) ) );
		$this->assertFalse( Server::supports_url_rewriting( $this->on( 'Microsoft-IIS/10.0' ) ) );
		$this->assertTrue( Server::supports_url_rewriting( $this->on( 'Microsoft-IIS/10.0', [ 'IIS_UrlRewriteModule' => '7.2' ] ) ) );
		$this->assertFalse( Server::supports_htaccess( $this->on( 'nginx/1.27.0' ) ) );
	}

	/**
	 * X-Sendfile: Apache with the module, LiteSpeed always, nginx only
	 * when the site says so.
	 */
	public function test_xsendfile(): void {
		$apache = $this->on( 'Apache/2.4' );

		$this->assertFalse( Server::has_xsendfile( $apache ), 'Unknown modules.' );

		$GLOBALS['ru_apache_modules'] = [ 'mod_xsendfile' ];
		$this->assertTrue( Server::has_xsendfile( $apache ) );
		$GLOBALS['ru_apache_modules'] = [ 'mod_rewrite' ];
		$this->assertFalse( Server::has_xsendfile( $apache ) );

		$this->assertTrue( Server::has_xsendfile( $this->on( 'LiteSpeed' ) ) );

		$nginx = $this->on( 'nginx/1.27.0' );
		$this->assertFalse( Server::has_xsendfile( $nginx ) );

		add_filter( 'server_nginx_xsendfile', static fn(): bool => true );
		$this->assertFalse( Server::has_xsendfile( $nginx ), 'The old unprefixed filter is not read.' );

		add_filter( 'sugarcommerce_nginx_xsendfile', static fn(): bool => true );
		$this->assertTrue( Server::has_xsendfile( $nginx ) );
	}

	/**
	 * The server's address, only when it is one.
	 */
	public function test_the_servers_address(): void {
		$this->assertSame( '10.0.0.5', Server::address( [ 'SERVER_ADDR' => '10.0.0.5' ] ) );
		$this->assertSame( '::1', Server::address( [ 'SERVER_ADDR' => ' ::1 ' ] ) );

		$fallback = gethostbyname( gethostname() ?: 'localhost' );

		$this->assertSame( $fallback, Server::address( [ 'SERVER_ADDR' => '<script>' ] ) );
		$this->assertSame( $fallback, Server::address( [] ) );
	}

	/**
	 * A port is 1 to 65535; anything else is the scheme's default.
	 *
	 * @param array $server The request.
	 * @param int   $port   The answer.
	 */
	#[DataProvider( 'ports' )]
	public function test_the_port_is_validated( array $server, int $port ): void {
		$this->assertSame( $port, Server::port( $server ) );
	}

	/**
	 * @return array<string, array{0: array, 1: int}>
	 */
	public static function ports(): array {
		return [
			'a port'           => [ [ 'SERVER_PORT' => '8080' ], 8080 ],
			'the highest'      => [ [ 'SERVER_PORT' => '65535' ], 65535 ],
			'the lowest'       => [ [ 'SERVER_PORT' => '1' ], 1 ],
			'none'             => [ [], 80 ],
			'none over HTTPS'  => [ [ 'HTTPS' => 'on' ], 443 ],
			'HTTPS off'        => [ [ 'HTTPS' => 'off' ], 80 ],
			'nought'           => [ [ 'SERVER_PORT' => '0' ], 80 ],
			'too high'         => [ [ 'SERVER_PORT' => '65536', 'HTTPS' => '1' ], 443 ],
			'negative'         => [ [ 'SERVER_PORT' => '-80' ], 80 ],
			'words'            => [ [ 'SERVER_PORT' => '80abc' ], 80 ],
			'a list'           => [ [ 'SERVER_PORT' => [ '8080' ] ], 80 ],
		];
	}

	/**
	 * gzip is a question about PHP, which has zlib here.
	 */
	public function test_gzip(): void {
		$this->assertSame( function_exists( 'gzencode' ) || function_exists( 'gzcompress' ), Server::supports_gzip() );
	}

	/**
	 * Cloudflare's headers are anyone's to send; is_cloudflare() trusted
	 * them and is not ported. Cloudflare::is_active() asks who connected.
	 */
	public function test_a_spoofed_cloudflare_header_is_not_cloudflare(): void {
		$this->assertFalse( method_exists( Server::class, 'is_cloudflare' ) );

		$spoofed = [
			'REMOTE_ADDR'           => '198.51.100.7',
			'HTTP_CF_RAY'           => '8a1b2c3d4e5f6789-LHR',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
		];

		$this->assertFalse( ( new Cloudflare( IP::trust() ) )->is_active( $spoofed ) );
		$this->assertTrue( ( new Cloudflare( IP::trust() ) )->is_active( [ 'REMOTE_ADDR' => '173.245.48.1' ] + $spoofed ) );
	}
}
