<?php
/**
 * Role-Based Email Data
 *
 * Constants for role-based email prefixes and related patterns.
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
 * Class Roles
 *
 * Role-based email constants.
 */
final class Roles {

	/**
	 * Role-based email prefixes.
	 */
	public const array PREFIXES = [
		'admin',
		'administrator',
		'info',
		'support',
		'sales',
		'contact',
		'hello',
		'help',
		'marketing',
		'noreply',
		'no-reply',
		'postmaster',
		'webmaster',
		'abuse',
		'billing',
	];
}
