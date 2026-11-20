<?php
/**
 * Tracking Parameter Utilities
 *
 * Removes campaign and click-tracking parameters from URLs.
 *
 * @package SugarCommerce\Network
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Referrer;

/**
 * Class Tracking
 *
 * Strips advertising and analytics parameters from URLs so they can be stored
 * or displayed in canonical form.
 *
 * Every name below is vendor-namespaced. Generic parameters — `s`, `v`, `ref`,
 * `type`, `from` — are deliberately excluded: they carry tracking on some sites
 * and application state on others, and a global list cannot tell the two apart.
 * Pass those to $extra per-site when you know the context.
 */
class Tracking {

	/**
	 * Exact parameter names to remove.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public const array PARAMS = [

		// Google Ads & Analytics.
		'gclid',
		'gclsrc',
		'gbraid',
		'wbraid',
		'gad_source',
		'gad_campaignid',
		'srsltid',
		'dclid',
		'gdfms',
		'gdffi',
		'gdftrk',
		'_ga',
		'_gl',

		// Microsoft Advertising.
		'msclkid',

		// Meta.
		'fbclid',
		'fb_action_ids',
		'fb_action_types',
		'fb_source',
		'fb_ref',

		// Instagram.
		'igshid',
		'igsh',

		// X / Twitter.
		'twclid',
		'ref_src',
		'ref_url',

		// TikTok.
		'ttclid',
		'tt_medium',
		'tt_content',

		// LinkedIn.
		'li_fat_id',

		// Pinterest.
		'epik',

		// Snapchat.
		'ScCid',

		// Reddit.
		'rdt_cid',

		// Yandex.
		'yclid',
		'ymclid',
		'_openstat',

		// Adobe.
		's_kwcid',
		'ef_id',

		// Mailchimp.
		'mc_cid',
		'mc_eid',
		'mc_tc',

		// Marketo.
		'mkt_tok',

		// Klaviyo.
		'_kx',

		// Drip.
		'__s',

		// Vero.
		'vero_id',
		'vero_conv',

		// Omeda.
		'oly_anon_id',
		'oly_enc_id',

		// Constant Contact.
		'cc_medium',

		// Hubspot.
		'hsCtaTracking',

		// Amazon.
		'pd_rd_i',
		'pd_rd_r',
		'pd_rd_w',
		'pd_rd_wg',
		'pf_rd_i',
		'pf_rd_m',
		'pf_rd_p',
		'pf_rd_r',
		'pf_rd_s',
		'pf_rd_t',

		// eBay.
		'mkcid',
		'mkevt',
		'mkrid',
		'campid',
		'toolid',

		// Impact.
		'irclickid',
		'irgwc',

		// Awin.
		'awc',

		// Rakuten.
		'ranMID',
		'ranEAID',
		'ranSiteID',

		// Wicked Reports.
		'wickedid',
	];

	/**
	 * Parameter family prefixes to remove.
	 *
	 * Matched against the start of the parameter name.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public const array PREFIXES = [
		'utm_',      // Google, and the de facto standard.
		'pk_',       // Piwik.
		'mtm_',      // Matomo.
		'matomo_',   // Matomo.
		'piwik_',    // Piwik.
		'hsa_',      // HubSpot Ads.
		'_hs',       // HubSpot (_hsenc, _hsmi).
		'__hs',      // HubSpot (__hstc, __hssc, __hsfp).
		'stm_',      // Various email platforms.
	];

	/**
	 * Remove tracking parameters from a URL.
	 *
	 * @param string $url   URL to clean.
	 * @param array  $extra Additional parameter names to remove.
	 * @param array  $keep  Parameter names to preserve even if they would match.
	 *
	 * @return string Cleaned URL, or the input unchanged when it has no query.
	 * @since 1.0.0
	 */
	public static function strip( string $url, array $extra = [], array $keep = [] ): string {
		// remove_query_arg() iterates the array, so an empty one returns $url as-is.
		return remove_query_arg( self::matches( $url, $extra, $keep ), $url );
	}

	/**
	 * Check whether a URL carries any tracking parameter.
	 *
	 * @param string $url   URL to inspect.
	 * @param array  $extra Additional parameter names to count as tracking.
	 * @param array  $keep  Parameter names to ignore.
	 *
	 * @return bool True when at least one tracking parameter is present.
	 * @since 1.0.0
	 */
	public static function has( string $url, array $extra = [], array $keep = [] ): bool {
		return ! empty( self::matches( $url, $extra, $keep ) );
	}

	/**
	 * Extract the tracking parameters from a URL.
	 *
	 * Use this to record attribution before calling strip().
	 *
	 * @param string $url   URL to inspect.
	 * @param array  $extra Additional parameter names to count as tracking.
	 * @param array  $keep  Parameter names to ignore.
	 *
	 * @return array Parameter name => value, in the order they appear.
	 * @since 1.0.0
	 */
	public static function extract( string $url, array $extra = [], array $keep = [] ): array {
		$params = self::query( $url );
		$found  = [];

		foreach ( self::matches( $url, $extra, $keep ) as $name ) {
			$found[ $name ] = is_array( $params[ $name ] ) ? '' : (string) $params[ $name ];
		}

		return $found;
	}

	/**
	 * Find the tracking parameter names present in a URL.
	 *
	 * @param string $url   URL to inspect.
	 * @param array  $extra Additional parameter names to count as tracking.
	 * @param array  $keep  Parameter names to ignore.
	 *
	 * @return array Matching parameter names.
	 * @since 1.0.0
	 */
	private static function matches( string $url, array $extra, array $keep ): array {
		$params = self::query( $url );

		if ( empty( $params ) ) {
			return [];
		}

		$exact = array_merge( self::PARAMS, $extra );
		$found = [];

		foreach ( array_keys( $params ) as $name ) {
			if ( in_array( $name, $keep, true ) ) {
				continue;
			}

			if ( in_array( $name, $exact, true ) || self::has_prefix( $name ) ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * Check a parameter name against the family prefixes.
	 *
	 * @param string $name Parameter name.
	 *
	 * @return bool True when the name belongs to a tracking family.
	 * @since 1.0.0
	 */
	private static function has_prefix( string $name ): bool {
		foreach ( self::PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse a URL's query string into an array.
	 *
	 * @param string $url URL to parse.
	 *
	 * @return array Parsed parameters, empty when there is no query string.
	 * @since 1.0.0
	 */
	private static function query( string $url ): array {
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( empty( $query ) ) {
			return [];
		}

		wp_parse_str( $query, $params );

		return is_array( $params ) ? $params : [];
	}
}
