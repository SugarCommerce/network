<?php
/**
 * Country resolver facade.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Country\Source;
use SugarCommerce\Network\Country\Sources\BunnyCDN;
use SugarCommerce\Network\Country\Sources\Callback;
use SugarCommerce\Network\Country\Sources\Cloudflare;
use SugarCommerce\Network\Country\Sources\CloudFront;
use SugarCommerce\Network\Country\Sources\Fastly;
use SugarCommerce\Network\Country\Sources\Generic;
use SugarCommerce\Network\Country\Sources\ServerGeoIP;
use SugarCommerce\Network\Models\Country\Result;

/**
 * Class Country
 *
 * Static facade. Resolves the visitor's country from request-
 * level signals — CDN headers (Cloudflare, CloudFront, Fastly,
 * BunnyCDN), server-level GeoIP modules, generic
 * `X-Country-Code` headers, and any custom sources the caller
 * has registered.
 *
 * Sources are tried in registration order. The first hit wins.
 * Default order tries the high-confidence vendor sources first,
 * then medium, then low.
 *
 * Typical use:
 *
 *   use SugarCommerce\Network\Country;
 *
 *   $cc = Country::resolve();              // 'GB' or '' on miss
 *   $r  = Country::resolve_detailed();     // Result with country/source/confidence
 *
 *   // Register a custom resolver (e.g. local MaxMind GeoLite2):
 *   Country::add_source( new \SugarCommerce\Network\Country\Sources\Callback(
 *       fn( $server ) => MyGeoIP::lookup( $server['REMOTE_ADDR'] ?? '' ),
 *       'maxmind_local',
 *       'high'
 *   ) );
 *
 * @since 1.0.0
 */
final class Country {

	/**
	 * Registered sources, in priority order. Lazily initialised
	 * to the default set on first access.
	 *
	 * @var Source[]|null
	 */
	private static $sources = null;

	/**
	 * What the two filters' names start with.
	 *
	 * @var string
	 */
	private static string $prefix = 'visitor';

	/**
	 * Name the filters after your plugin.
	 *
	 * The filters are `{prefix}_country_sources` and
	 * `{prefix}_country_result` -- `visitor_country_sources` and
	 * `visitor_country_result` until this is called. Each plugin that
	 * bundles its own copy of this library has its own registry, and
	 * calling this gives it its own filters too, so one plugin forcing a
	 * country for its tests does not force it for another.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix e.g. `myplugin`. Anything but letters, digits
	 *                       and underscores is dropped; empty restores the
	 *                       default.
	 *
	 * @return void
	 */
	public static function use_prefix( string $prefix ): void {
		$prefix       = (string) preg_replace( '/[^A-Za-z0-9_]/', '', $prefix );
		self::$prefix = '' === $prefix ? 'visitor' : $prefix;
	}

	/**
	 * The name of one of the filters.
	 *
	 * @since 1.0.0
	 *
	 * @param string $which `sources` or `result`.
	 *
	 * @return string e.g. `visitor_country_result`.
	 */
	public static function hook( string $which ): string {
		return self::$prefix . '_country_' . $which;
	}

	/**
	 * Resolve the visitor's country code.
	 *
	 * Convenience accessor for the common case — returns the
	 * country code as a plain string. Use `resolve_detailed()`
	 * when you need to know which source resolved or how
	 * trustworthy the answer is.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $server `$_SERVER`-shaped array. Defaults
	 *                           to the live `$_SERVER` superglobal
	 *                           — the parameter exists for testability.
	 *
	 * @return string Two-letter ISO-3166 alpha-2 code, or empty
	 *                string when no source resolved.
	 */
	public static function resolve( ?array $server = null ): string {
		return self::resolve_detailed( $server )->get_country();
	}

	/**
	 * Resolve the visitor's country code with full provenance.
	 *
	 * Always returns a Result — `has_country()` reports whether
	 * resolution succeeded. The empty Result still carries the
	 * `to_array()` shape so logging / serialisation code can
	 * treat hits and misses uniformly.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $server `$_SERVER`-shaped array.
	 *
	 * @return Result
	 */
	public static function resolve_detailed( ?array $server = null ): Result {
		$server = Server::get( $server );

		/**
		 * Filter the sources consulted for this request: `{prefix}_country_sources`.
		 *
		 * The registry is process-wide; this is per-request, which is what a
		 * site wants when the answer should depend on the request itself --
		 * trusting a header only on the front end, say, or skipping a paid
		 * lookup for a logged-in administrator.
		 *
		 * @since 1.1.0
		 *
		 * @param Source[] $sources The source chain, in order.
		 * @param array    $server  The server array being read.
		 */
		$sources = apply_filters( self::hook( 'sources' ), self::get_sources(), $server );

		$result = Result::none();

		foreach ( $sources as $source ) {
			if ( ! $source instanceof Source ) {
				continue;
			}

			$country = $source->resolve( $server );

			if ( $country !== null ) {
				$result = new Result( $country, $source->get_name(), $source->get_confidence() );
				break;
			}
		}

		/**
		 * Filter the resolved result: `{prefix}_country_result`.
		 *
		 * The last word on what country this request is from. Useful for
		 * forcing a country while testing a geo-gated flow, and for the sites
		 * that have to override detection for a known office IP.
		 *
		 * Returning anything that is not a Result leaves the resolved one
		 * alone, rather than fataling on the next page load.
		 *
		 * @since 1.1.0
		 *
		 * @param Result $result The resolved result, or an empty one.
		 * @param array  $server The server array that was read.
		 */
		$filtered = apply_filters( self::hook( 'result' ), $result, $server );

		return $filtered instanceof Result ? $filtered : $result;
	}

	/**
	 * Append a source to the priority chain. Tried after every
	 * existing source, so lower-priority than the defaults.
	 *
	 * Use `prepend_source()` to insert at the head of the chain
	 * (i.e. take precedence over the bundled CDN sources).
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source Source to register.
	 *
	 * @return void
	 */
	public static function add_source( Source $source ): void {
		self::get_sources();
		self::$sources[] = $source;
	}

	/**
	 * Insert a source at the head of the chain — runs before the
	 * bundled CDN / server sources.
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source Source to register.
	 *
	 * @return void
	 */
	public static function prepend_source( Source $source ): void {
		self::get_sources();
		array_unshift( self::$sources, $source );
	}

	/**
	 * Replace the entire source chain.
	 *
	 * @since 1.0.0
	 *
	 * @param Source[] $sources Ordered list of sources.
	 *
	 * @return void
	 */
	public static function set_sources( array $sources ): void {
		self::$sources = array_values( array_filter( $sources, static fn( $s ) => $s instanceof Source ) );
	}

	/**
	 * Reset to the default source chain.
	 *
	 * Useful in tests, or when a downstream caller has been
	 * mucking with the registry and wants a clean slate.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sources = self::default_sources();
	}

	/**
	 * Get the active source chain — initialises to the default
	 * set on first access.
	 *
	 * @since 1.0.0
	 *
	 * @return Source[]
	 */
	public static function get_sources(): array {
		if ( self::$sources === null ) {
			self::$sources = self::default_sources();
		}

		return self::$sources;
	}

	/**
	 * Default source chain — tried in this order. Vendor-specific
	 * sources first (most trustworthy), then server-level, then
	 * generic.
	 *
	 * @since 1.0.0
	 *
	 * @return Source[]
	 */
	private static function default_sources(): array {
		return [
			new Cloudflare(),
			new CloudFront(),
			new Fastly(),
			new BunnyCDN(),
			new ServerGeoIP(),
			new Generic(),
		];
	}
}
