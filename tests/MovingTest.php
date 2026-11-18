<?php
/**
 * The calls that replace the old libraries' global helpers.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use SugarCommerce\Network\AcceptLanguage;
use SugarCommerce\Network\Disk;
use SugarCommerce\Network\Email;
use SugarCommerce\Network\Environment;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\Referrer;
use SugarCommerce\Network\Php;
use SugarCommerce\Network\Request;
use SugarCommerce\Network\Server;
use PHPUnit\Framework\TestCase;

/**
 * The README's "Moving from arraypress/wp-*" table, for the helpers.
 *
 * The old libraries each declared a few global functions. A global function
 * is the one thing Strauss cannot prefix, so two plugins bundling different
 * copies shared whichever loaded first -- and parse_email() in one plugin
 * handed back the other plugin's Email. This library declares none; each
 * row of the table is the static call that gives the same answer, and each
 * is checked here against what the helper promised.
 */
final class MovingTest extends TestCase {

	protected function setUp(): void {
		ru_reset_globals();
		ua_reset_server();
	}

	protected function tearDown(): void {
		ru_reset_globals();
		ua_reset_server();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['SERVER_SOFTWARE'] );
		env_reset();
	}

	/**
	 * No global helper is declared.
	 */
	public function test_no_global_helper_is_declared(): void {
		$helpers = [
			'get_user_ip',
			'anonymize_ip',
			'sanitize_ip_list',
			'get_user_agent',
			'get_referrer_url',
			'get_referrer_source',
			'get_referrer_utm_params',
			'is_referrer_from_search',
			'get_referrer_search_terms',
			'parse_email',
			'sanitize_email_list',
			'is_valid_email',
			'get_accept_language',
			'get_preferred_language',
			'accepts_language',
			'is_rtl_language',
		];

		foreach ( $helpers as $function ) {
			$this->assertFalse( function_exists( $function ), sprintf( '%s() is declared.', $function ) );
		}

		$this->assertSame( [], glob( dirname( __DIR__ ) . '/src/Functions.php' ) );
	}

	/**
	 * get_user_ip(), anonymize_ip(), sanitize_ip_list().
	 */
	public function test_the_ip_helpers(): void {
		$_SERVER['REMOTE_ADDR'] = '93.184.216.34';

		$this->assertSame( '93.184.216.34', IP::get() );
		$this->assertSame( '93.184.216.0', IP::anonymize( '93.184.216.34' ) );

		// sanitize_ip_list() returned text by default; the method returns a
		// list unless asked.
		$this->assertSame( "203.0.113.9\n198.51.100.0/24", IP::sanitize_pattern_list( "203.0.113.9\nnonsense\n198.51.100.0/24", true ) );
	}

	/**
	 * get_user_agent().
	 */
	public function test_the_agent_helper(): void {
		$this->assertSame( '', Request::agent() );

		$_SERVER['HTTP_USER_AGENT'] = 'curl/8.4.0';

		$this->assertSame( 'curl/8.4.0', Request::agent() );
	}

	/**
	 * The five referrer helpers, including the two that promised a string
	 * where the method may return null.
	 */
	public function test_the_referrer_helpers(): void {
		$url = 'https://www.google.com/search?q=felt+hats';

		$this->assertSame( 'google', Referrer::get_source( $url ) );
		$this->assertTrue( Referrer::is_search_engine( $url ) );
		$this->assertSame( 'felt hats', Referrer::get_search_terms( $url ) ?? '' );
		$this->assertSame( 'news', Referrer::get_utm_parameters( 'https://x.test/?utm_source=news' )['source'] );
		$this->assertSame(
			[ 'source', 'medium', 'campaign', 'term', 'content' ],
			array_keys( Referrer::get_utm_parameters( 'https://example.test/' ) )
		);

		$this->assertSame( '', Referrer::get() ?? '' );
		$this->assertSame( '', Referrer::get_search_terms( 'https://blog.test/' ) ?? '' );
		$this->assertSame( 'direct', Referrer::get_source() );

		$_SERVER['HTTP_REFERER'] = 'https://example.test/a';

		$this->assertSame( 'https://example.test/a', Referrer::get() ?? '' );
	}

	/**
	 * parse_email() and sanitize_email_list().
	 */
	public function test_the_email_helpers(): void {
		$this->assertSame( 'pat@example.com', (string) Email::parse( ' Pat@Example.com ' ) );
		$this->assertNull( Email::parse( 'nope' ) );
		$this->assertIsString( Email::sanitize_pattern_list( "*@example.com\n", true ) );
	}

	/**
	 * is_valid_email() and sanitize_email() from arraypress/email-validator.
	 */
	public function test_the_validator_helpers(): void {
		$this->assertTrue( Email::is_valid( 'user+tag@gmail.com' ) );
		$this->assertFalse( Email::is_valid( 'test@domain.123' ) );

		// The old sanitize_email() lower-cased and gave '' for nothing.
		$this->assertSame( 'test@example.com', Email::sanitize_email( '  TEST@EXAMPLE.COM  ' ) ?? '' );
		$this->assertSame( '', Email::sanitize_email( 'invalid' ) ?? '' );
	}

	/**
	 * get_accept_language(), get_preferred_language(), accepts_language()
	 * and is_rtl_language().
	 */
	public function test_the_accept_language_helpers(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ar-EG,en;q=0.4';

		$this->assertSame( 'ar-EG', AcceptLanguage::get_primary() );
		$this->assertSame( 'en', AcceptLanguage::get_best_match( [ 'fr', 'en' ], 'de' ) );
		$this->assertSame( 'de', AcceptLanguage::get_best_match( [ 'fr' ], 'de' ) );
		$this->assertTrue( AcceptLanguage::accepts( 'en-US' ) );
		$this->assertFalse( AcceptLanguage::accepts( 'en-US', true ) );
		$this->assertTrue( AcceptLanguage::is_rtl() );
	}

	/**
	 * Every wp-server-utils method has its new name.
	 */
	public function test_the_server_utils_names(): void {
		$renamed = [
			Server::class      => [ 'software', 'address', 'port', 'is_apache', 'is_nginx', 'is_litespeed', 'is_iis', 'supports_htaccess', 'has_mod_rewrite', 'supports_url_rewriting', 'supports_gzip', 'has_xsendfile', 'apache_modules', 'has_apache_module' ],
			Php::class         => [ 'version', 'meets_version', 'memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'max_input_vars', 'has_extension', 'missing_extensions', 'has_function', 'uploads_enabled', 'url_fopen_enabled', 'has_memory', 'max_upload_size' ],
			Disk::class        => [ 'space', 'has_space', 'temp_dir', 'is_windows', 'is_linux', 'is_macos' ],
			Environment::class => [ 'type', 'is_local', 'is_staging', 'is_development', 'is_production' ],
		];

		foreach ( $renamed as $class => $methods ) {
			foreach ( $methods as $method ) {
				$this->assertTrue( method_exists( $class, $method ), $class . '::' . $method . '() is missing.' );
			}
		}

		$this->assertSame(
			[ 'local', 'development', 'staging', 'production' ],
			[ Environment::LOCAL, Environment::DEVELOPMENT, Environment::STAGING, Environment::PRODUCTION ]
		);

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27.0';
		$this->assertSame( 'nginx/1.27.0', Server::software() );
	}
}
