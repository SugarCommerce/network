<?php
/**
 * Email Provider Data
 *
 * Constants for common email provider domains, authoritative providers,
 * and providers that support subaddressing.
 *
 * @package     SugarCommerce\Network
 * @subpackage  Data
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Email\Data;

/**
 * Class Providers
 *
 * Email provider domain constants.
 */
final class Providers {

	/**
	 * Common free email provider domains.
	 */
	public const COMMON = [
		'gmail.com',
		'yahoo.com',
		'hotmail.com',
		'outlook.com',
		'aol.com',
		'icloud.com',
		'protonmail.com',
		'mail.com',
		'zoho.com',
		'live.com',
	];


	/**
	 * Email providers that support subaddressing (plus addressing).
	 */
	public const SUBADDRESS = [
		'gmail.com',
		'googlemail.com',
		'yahoo.com',
		'fastmail.com',
		'outlook.com',
		'hotmail.com',
		'protonmail.com',
	];
}
