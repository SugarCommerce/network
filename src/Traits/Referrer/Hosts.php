<?php
/**
 * A URL's host, and the registered domain it belongs to.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

/**
 * A URL's host, and the registered domain it belongs to.
 *
 * @since 1.1.0
 */
trait Hosts {

	/**
	 * A URL's host, lower case, without `www.`.
	 *
	 * The form every table is matched against.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL.
	 *
	 * @return string|null e.g. `reddit.com`, or null when it is not a URL.
	 */
	public static function host_of( string $url ): ?string {
		$host = self::parse_host( $url );
		$host = null !== $host ? self::without_www( $host ) : '';

		return '' !== $host ? $host : null;
	}

	/**
	 * The registered part of a host: `shop.example.co.uk` is `example.co.uk`.
	 *
	 * Knows the common two-label suffixes, not the full Public Suffix List;
	 * fine for grouping and display, not for a security decision.
	 *
	 * @since 1.1.0
	 *
	 * @param string $host A host.
	 *
	 * @return string The registered domain.
	 */
	public static function root_domain( string $host ): string {
		$host = self::without_www( ltrim( strtolower( $host ), '.' ) );

		// An address has no registered domain, and its last two numbers are
		// not one: 192.168.1.10 and 10.0.1.10 would both be "1.10", and so
		// each other's own site.
		if ( str_starts_with( $host, '[' ) || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		$parts = explode( '.', $host );
		$count = count( $parts );

		if ( $count < 3 ) {
			return $host;
		}

		// Taking the last two labels gives "co.uk" for every site in Britain,
		// which groups the whole country together. Where the last two are a
		// known compound suffix, take three.
		$suffix = $parts[ $count - 2 ] . '.' . $parts[ $count - 1 ];

		if ( in_array( $suffix, self::COMPOUND_SUFFIXES, true ) ) {
			return $parts[ $count - 3 ] . '.' . $suffix;
		}

		return $suffix;
	}

	/**
	 * The host of the site's own address, given as a URL or as a bare host.
	 *
	 * @since 1.1.0
	 *
	 * @param string $site `https://shop.example/` or `shop.example`.
	 *
	 * @return string|null
	 */
	protected static function site_host( string $site ): ?string {
		$site = trim( $site );
		$host = self::host_of( $site );

		if ( null === $host && 1 === preg_match( '/^[a-z0-9.-]+$/i', $site ) ) {
			$host = self::without_www( $site );
		}

		return '' !== (string) $host ? $host : null;
	}

	/**
	 * A URL's host exactly as it would be requested: lower case, `www.` kept.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL.
	 *
	 * @return string|null Null when there is no host, or it is not one.
	 */
	protected static function parse_host( string $url ): ?string {
		$url = trim( $url );

		// A browser reads `https:\\host` and `https:/host` as `https://host`,
		// and a backslash anywhere before the path as a slash. parse_url()
		// does neither, and would name a different host than the one the
		// link actually goes to.
		if ( 1 === preg_match( '/^(https?):[\/\\\\]*(.*)$/is', $url, $found ) ) {
			$url = strtolower( $found[1] ) . '://' . str_replace( '\\', '/', $found[2] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- one consistent parser, with or without WordPress loaded.
		$host = parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host || 1 === preg_match( '/[\s<>^|%]/', $host ) ) {
			return null;
		}

		// Square brackets are an IPv6 address or they are nothing.
		if ( str_contains( $host, '[' ) && ( 1 !== preg_match( '/^\[([0-9a-f:.]+)\]$/i', $host, $found ) || false === filter_var( $found[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) ) {
			return null;
		}

		// An internationalised name is requested in its ASCII form.
		if ( 1 === preg_match( '/[^\x20-\x7E]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) && '' !== $ascii ? $ascii : $host;
		}

		return strtolower( $host );
	}

	/**
	 * A host with any leading `www.` removed.
	 *
	 * @param string $domain The host.
	 *
	 * @return string
	 */
	protected static function without_www( string $domain ): string {
		return (string) preg_replace( '/^www\./i', '', strtolower( $domain ) );
	}

	/**
	 * Get current domain.
	 *
	 * @return string Current domain.
	 */
	protected static function get_current_domain(): string {
		$site_url = (string) get_option( 'siteurl' );

		return self::parse_host( $site_url ) ?? '';
	}
}
