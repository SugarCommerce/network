<?php
/**
 * A stand-in for core's wp_privacy_anonymize_ip(), loaded in a separate
 * process only.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

/**
 * Says it was asked.
 *
 * @param string $ip_addr The address.
 *
 * @return string
 */
function wp_privacy_anonymize_ip( $ip_addr ) {
	return 'core:' . $ip_addr;
}
