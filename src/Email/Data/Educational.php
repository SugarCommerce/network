<?php
/**
 * Educational Domain Data
 *
 * Constants for educational domain patterns.
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
 * Class Educational
 *
 * Educational domain pattern constants.
 */
final class Educational {

	/**
	 * Educational domain patterns.
	 *
	 * Pattern types:
	 * - .edu    = US standard, also used by many countries as .edu.xx
	 * - .ac.xx  = Academic (UK, Japan, Korea, etc.)
	 * - .sch.xx = Schools (primary/secondary education)
	 * - .k12.xx = K-12 education (US-style naming)
	 */
	public const array PATTERNS = [
		// United States
		// Uses .edu directly

		// United Kingdom
		'.ac.uk',
		'.sch.uk',

		// Europe
		'.ac.at',
		'.ac.be',
		'.edu.pl',
		'.edu.gr',
		'.edu.it',
		'.edu.es',
		'.edu.pt',
		'.edu.ru',
		'.edu.ua',
		'.edu.tr',
		'.k12.tr',

		// Asia - East
		'.ac.jp',
		'.ac.kr',
		'.ac.cn',
		'.edu.cn',
		'.ac.tw',
		'.edu.tw',
		'.edu.hk',

		// Asia - Southeast
		'.ac.th',
		'.ac.id',
		'.sch.id',
		'.edu.sg',
		'.edu.my',
		'.edu.ph',
		'.edu.vn',

		// Asia - South
		'.ac.in',
		'.ac.bd',
		'.ac.lk',
		'.ac.np',
		'.edu.pk',

		// Middle East
		'.ac.il',
		'.k12.il',
		'.ac.ir',
		'.sch.ir',
		'.ac.ae',
		'.edu.ae',
		'.sch.ae',
		'.edu.sa',
		'.sch.sa',
		'.edu.qa',

		// Oceania
		'.ac.nz',
		'.sch.nz',
		'.edu.au',

		// Africa
		'.ac.za',
		'.sch.za',
		'.ac.ke',
		'.sch.ke',
		'.ac.tz',
		'.ac.ug',
		'.ac.zw',
		'.ac.rw',
		'.edu.ng',
		'.sch.ng',
		'.edu.gh',
		'.edu.eg',

		// Latin America
		'.edu.mx',
		'.edu.br',
		'.edu.ar',
		'.edu.cl',
		'.edu.co',
		'.edu.pe',
		'.edu.ve',
		'.edu.ec',
		'.k12.ec',
		'.edu.bo',

		// Catch-all patterns (must be last)
		'.edu.',
		'.ac.',
		'.sch.',
		'.k12.',
	];
}
