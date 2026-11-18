<?php
/**
 * Government Domain Data
 *
 * Constants for government domain patterns.
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
 * Class Government
 *
 * Government domain pattern constants.
 */
final class Government {

	/**
	 * Government domain patterns.
	 */
	public const PATTERNS = [
		// Generic
		'.gov',
		'.mil',

		// United States
		// Uses .gov directly

		// United Kingdom & Commonwealth
		'.gov.uk',
		'.gov.au',
		'.gov.nz',
		'.govt.nz',
		'.gov.ca',
		'.gov.ie',
		'.gov.za',
		'.gov.sg',
		'.gov.hk',
		'.gov.ph',
		'.gov.my',
		'.gov.in',
		'.gov.pk',
		'.gov.bd',
		'.gov.ng',
		'.gov.gh',
		'.gov.ke',

		// Spanish-speaking (gob = gobierno)
		'.gob.mx',
		'.gob.es',
		'.gob.ar',
		'.gob.cl',
		'.gob.pe',
		'.gob.co',
		'.gob.ve',
		'.gob.ec',
		'.gob.bo',
		'.gob.py',
		'.gob.uy',
		'.gob.pa',
		'.gob.cr',
		'.gob.gt',
		'.gob.hn',
		'.gob.sv',
		'.gob.ni',
		'.gob.do',
		'.gob.cu',
		'.gob.pr',

		// Portuguese-speaking
		'.gov.br',
		'.gov.pt',
		'.gov.ao',
		'.gov.mz',

		// French-speaking (gouv = gouvernement)
		'.gouv.fr',
		'.gouv.ca',
		'.gouv.be',
		'.gouv.ch',
		'.gouv.sn',
		'.gouv.ci',
		'.gouv.ml',
		'.gouv.bf',
		'.gouv.ne',
		'.gouv.tg',
		'.gouv.bj',
		'.gouv.mg',
		'.gouv.cd',
		'.gouv.cg',
		'.gouv.cm',

		// German-speaking
		'.gv.at',
		'.admin.ch',
		'.bund.de',

		// Other European
		'.overheid.nl',
		'.gov.be',
		'.gov.it',
		'.gov.pl',
		'.gov.cz',
		'.gov.hu',
		'.gov.ro',
		'.gov.bg',
		'.gov.ru',
		'.gov.ua',
		'.gov.tr',

		// Middle East
		'.gov.il',
		'.gov.ae',
		'.gov.sa',

		// Asia
		'.gov.cn',
		'.gov.jp',
		'.go.jp',
		'.gov.kr',
		'.go.kr',
		'.go.th',
		'.gov.vn',
		'.gov.id',
		'.go.id',

		// Catch-all patterns (must be last)
		'.gov.',
		'.gob.',
		'.gouv.',
		'.govt.',
		'.go.',
		'.gv.',
	];
}
