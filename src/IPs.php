<?php
/**
 * IPs Utility Class
 *
 * Provides utility functions for working with multiple IP addresses,
 * including bulk validation, filtering, and anonymization operations.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * IPs Class
 *
 * Bulk operations for working with multiple IP addresses.
 */
class IPs {

	/**
	 * Validate multiple IP addresses.
	 *
	 * @param array $ips Array of IP addresses to validate.
	 *
	 * @return array Array with IPs as keys and validation results as values.
	 */
	public static function validate( array $ips ): array {
		$results = [];
		foreach ( $ips as $ip ) {
			$ip             = trim( $ip );
			$results[ $ip ] = IP::is_valid( $ip );
		}

		return $results;
	}

	/**
	 * Filter and return only valid IP addresses.
	 *
	 * @param array $ips Array of IP addresses to filter.
	 *
	 * @return array Array of valid IP addresses.
	 */
	public static function filter( array $ips ): array {
		$valid = [];
		foreach ( $ips as $ip ) {
			$ip = trim( $ip );
			if ( IP::is_valid( $ip ) ) {
				$valid[] = $ip;
			}
		}

		return $valid;
	}

	/**
	 * Anonymize multiple IP addresses.
	 *
	 * @param array $ips Array of IP addresses to anonymize.
	 *
	 * @return array Array of anonymized IP addresses.
	 */
	public static function anonymize( array $ips ): array {
		$anonymized = [];
		foreach ( $ips as $ip ) {
			$result = IP::anonymize( $ip );
			if ( $result !== null ) {
				$anonymized[] = $result;
			}
		}

		return $anonymized;
	}

	/**
	 * Remove duplicate IP addresses from an array.
	 *
	 * @param array $ips Array of IP addresses.
	 *
	 * @return array Array of unique IP addresses.
	 */
	public static function remove_duplicates( array $ips ): array {
		$unique = [];
		foreach ( $ips as $ip ) {
			$ip = trim( $ip );
			if ( IP::is_valid( $ip ) && ! in_array( $ip, $unique, true ) ) {
				$unique[] = $ip;
			}
		}

		return $unique;
	}

	/**
	 * Get only IPv4 addresses.
	 *
	 * @param array $ips Array of IP addresses.
	 *
	 * @return array Array of IPv4 addresses.
	 */
	public static function filter_ipv4( array $ips ): array {
		$filtered = [];
		foreach ( $ips as $ip ) {
			if ( IP::is_valid_ipv4( $ip ) ) {
				$filtered[] = $ip;
			}
		}

		return $filtered;
	}

	/**
	 * Get only IPv6 addresses.
	 *
	 * @param array $ips Array of IP addresses.
	 *
	 * @return array Array of IPv6 addresses.
	 */
	public static function filter_ipv6( array $ips ): array {
		$filtered = [];
		foreach ( $ips as $ip ) {
			if ( IP::is_valid_ipv6( $ip ) ) {
				$filtered[] = $ip;
			}
		}

		return $filtered;
	}

	/**
	 * Filter IP addresses by CIDR range.
	 *
	 * @param array  $ips     Array of IP addresses.
	 * @param string $range   CIDR range to filter by.
	 * @param bool   $keep_matches Whether to include (true) or exclude (false) matches.
	 *
	 * @return array Filtered IP addresses.
	 */
	public static function filter_by_range( array $ips, string $range, bool $keep_matches = true ): array {
		$filtered = [];

		foreach ( $ips as $ip ) {
			$in_range = IP::is_in_range( $ip, $range );
			if ( ( $keep_matches && $in_range ) || ( ! $keep_matches && ! $in_range ) ) {
				$filtered[] = $ip;
			}
		}

		return $filtered;
	}

	/**
	 * Filter IP addresses by multiple CIDR ranges.
	 *
	 * @param array $ips     Array of IP addresses.
	 * @param array $ranges  Array of CIDR ranges.
	 * @param bool  $keep_matches Whether to include (true) or exclude (false) matches.
	 *
	 * @return array Filtered IP addresses.
	 */
	public static function filter_by_ranges( array $ips, array $ranges, bool $keep_matches = true ): array {
		$filtered = [];

		foreach ( $ips as $ip ) {
			$matches = IP::is_match( $ip, $ranges );
			if ( ( $keep_matches && $matches ) || ( ! $keep_matches && ! $matches ) ) {
				$filtered[] = $ip;
			}
		}

		return $filtered;
	}
}
