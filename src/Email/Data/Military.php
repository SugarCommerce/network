<?php
/**
 * Military Domain Data
 *
 * Constants for military TLDs and domain patterns.
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
 * Class Military
 *
 * Military domain pattern constants.
 */
final class Military {

	/**
	 * Military TLDs.
	 */
	public const array TLDS = [
		'mil',
	];

	/**
	 * Known military domain patterns by country.
	 */
	public const array DOMAINS = [
		// United States
		'army.mil',
		'navy.mil',
		'af.mil',
		'marines.mil',
		'uscg.mil',
		'usmc.mil',
		'nga.mil',
		'disa.mil',
		'dla.mil',
		'nsa.mil',
		'pentagon.mil',

		// United Kingdom
		'mod.uk',
		'army.mod.uk',
		'royal-navy.mod.uk',
		'raf.mod.uk',
		'defence.gov.uk',

		// Canada
		'forces.gc.ca',
		'army.gc.ca',
		'navy.gc.ca',
		'rcaf-arc.gc.ca',
		'dnd-mdn.gc.ca',

		// Australia
		'defence.gov.au',
		'army.gov.au',
		'navy.gov.au',
		'airforce.gov.au',

		// Germany
		'bundeswehr.de',
		'bundeswehr.org',

		// France
		'defense.gouv.fr',
		'terre.defense.gouv.fr',
		'marine.defense.gouv.fr',
		'air.defense.gouv.fr',
		'gendarmerie.interieur.gouv.fr',

		// Italy
		'difesa.it',
		'esercito.difesa.it',
		'marina.difesa.it',
		'aeronautica.difesa.it',

		// Spain
		'defensa.gob.es',
		'ejercito.defensa.gob.es',
		'armada.defensa.gob.es',

		// Netherlands
		'defensie.nl',
		'mindef.nl',

		// Belgium
		'mil.be',
		'defence.be',

		// Poland
		'mon.gov.pl',
		'wp.mil.pl',

		// Norway
		'forsvaret.no',
		'mil.no',

		// Sweden
		'forsvarsmakten.se',
		'mil.se',

		// Denmark
		'forsvaret.dk',
		'fmn.dk',

		// Finland
		'mil.fi',
		'puolustusvoimat.fi',

		// Portugal
		'defesa.pt',
		'exercito.pt',
		'marinha.pt',

		// Greece
		'mod.mil.gr',
		'army.gr',
		'hellenicnavy.gr',
		'haf.gr',

		// Turkey
		'tsk.tr',
		'msb.gov.tr',

		// Israel
		'mod.gov.il',
		'idf.il',

		// India
		'mod.gov.in',
		'indianarmy.nic.in',
		'indiannavy.nic.in',
		'indianairforce.nic.in',

		// Japan
		'mod.go.jp',
		'gsdf.mod.go.jp',
		'msdf.mod.go.jp',
		'asdf.mod.go.jp',

		// South Korea
		'mnd.go.kr',
		'army.mil.kr',
		'navy.mil.kr',
		'airforce.mil.kr',

		// Brazil
		'defesa.gov.br',
		'eb.mil.br',
		'mar.mil.br',
		'fab.mil.br',

		// Mexico
		'sedena.gob.mx',
		'semar.gob.mx',

		// Argentina
		'mindef.gov.ar',
		'ejercito.mil.ar',
		'armada.mil.ar',

		// Chile
		'defensa.cl',
		'ejercito.cl',
		'armada.cl',

		// Colombia
		'mindefensa.gov.co',
		'ejercito.mil.co',
		'armada.mil.co',

		// New Zealand
		'nzdf.mil.nz',
		'army.mil.nz',
		'navy.mil.nz',
		'airforce.mil.nz',

		// South Africa
		'dod.mil.za',
		'sandf.mil.za',

		// Egypt
		'mod.gov.eg',
		'mmc.gov.eg',

		// Saudi Arabia
		'mod.gov.sa',
		'moda.gov.sa',

		// UAE
		'mod.gov.ae',

		// Singapore
		'mindef.gov.sg',
		'defence.gov.sg',

		// Thailand
		'mod.go.th',
		'rta.mi.th',

		// Indonesia
		'kemhan.go.id',
		'tni.mil.id',

		// Philippines
		'dnd.gov.ph',
		'army.mil.ph',
		'navy.mil.ph',

		// Pakistan
		'mod.gov.pk',
		'pak.army.mil.pk',

		// Taiwan
		'mnd.gov.tw',

		// Czech Republic
		'army.cz',
		'mocr.army.cz',

		// Austria
		'bundesheer.at',
		'bmlv.gv.at',

		// Switzerland
		'vtg.admin.ch',
		'armee.ch',

		// Ireland
		'military.ie',
		'defence.ie',

		// Romania
		'mapn.ro',
		'army.ro',

		// Hungary
		'honvedseg.hu',
		'hm.gov.hu',

		// Ukraine
		'mil.gov.ua',
		'mon.gov.ua',

		// NATO
		'nato.int',
		'shape.nato.int',
		'act.nato.int',
	];
}
