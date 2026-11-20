<?php
/**
 * Referrer Utility Class
 *
 * Provides utility functions for HTTP referrer detection, validation,
 * and analysis for traffic sources and security.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Traits\Referrer\Analysis;
use SugarCommerce\Network\Traits\Referrer\Cleaning;
use SugarCommerce\Network\Traits\Referrer\Hosts;
use SugarCommerce\Network\Traits\Referrer\Lookups;
use SugarCommerce\Network\Traits\Referrer\Options;
use SugarCommerce\Network\Traits\Referrer\Query;

/**
 * Referrer Class
 *
 * Core operations for working with HTTP referrer headers.
 *
 * The rules -- which hosts are a search engine, what a click ID means, what
 * beats what -- are the ones the JavaScript package @flarecart/referrer uses,
 * so a visit is filed the same way whichever side saw it. The tables are in
 * Sources and Campaigns; analyse() is that package's analyse().
 *
 * One trait per job, in src/Traits/Referrer/: the whole visit
 * (Analysis), one URL's answers (Lookups), making input safe to keep
 * (Cleaning), hosts and registered domains (Hosts), the landing page's
 * tags (Query), and the lists a condition picks from (Options).
 */
class Referrer {

	use Analysis;
	use Cleaning;
	use Hosts;
	use Lookups;
	use Options;
	use Query;

	/**
	 * The channels a source is filed under.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	public const CHANNELS = [ 'search', 'social', 'email', 'ai', 'paid', 'campaign', 'referral', 'direct' ];

	/**
	 * The longest referrer accepted.
	 *
	 * Browsers send far less; anything longer is padding aimed at a column.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const MAX_URL_LENGTH = 2048;

	/**
	 * The longest tag kept.
	 *
	 * A campaign name fits; an instruction to an AI agent does not.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const TAG_MAX = 60;

	/**
	 * The longest landing path kept.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	public const LANDING_MAX = 120;

	/**
	 * Two-label public suffixes, where the registered domain is the third
	 * label from the end rather than the second.
	 *
	 * Not the full Public Suffix List -- that is several thousand entries and
	 * a dependency that has to be kept current. This is the set that covers
	 * the countries this library already tracks search engines for, which is
	 * where its referrers actually come from. A suffix that is not listed
	 * falls back to the last two labels, which is right for the great
	 * majority of domains.
	 *
	 * @var string[]
	 */
	protected const COMPOUND_SUFFIXES = [
		'co.uk',
		'org.uk',
		'me.uk',
		'ac.uk',
		'gov.uk',
		'net.uk',
		'sch.uk',
		'com.au',
		'net.au',
		'org.au',
		'edu.au',
		'gov.au',
		'co.nz',
		'net.nz',
		'org.nz',
		'govt.nz',
		'co.za',
		'org.za',
		'co.jp',
		'ne.jp',
		'or.jp',
		'ac.jp',
		'go.jp',
		'co.in',
		'net.in',
		'org.in',
		'gov.in',
		'ac.in',
		'com.br',
		'net.br',
		'org.br',
		'gov.br',
		'com.mx',
		'org.mx',
		'com.ar',
		'com.tr',
		'gov.tr',
		'com.sa',
		'com.sg',
		'com.hk',
		'com.tw',
		'com.cn',
		'net.cn',
		'org.cn',
		'gov.cn',
		'co.kr',
		'or.kr',
		'com.pl',
		'com.es',
		'com.co',
		'co.il',
		'com.my',
		'com.ph',
		'co.th',
		'in.th',
		'ac.th',
		'co.id',
		'com.vn',
	];

	/**
	 * The identifiers this library used before its sources had display names.
	 *
	 * get_search_engine(), get_social_platform() and get_source() return a
	 * lower-case identifier, and those are stored: in order meta, in saved
	 * conditions. Most fall out of the name ("Google" is `google`). These two
	 * do not, and renaming them would orphan what is already saved.
	 *
	 * @since 1.1.0
	 * @var array<string, string> Name => identifier.
	 */
	protected const LEGACY_SLUGS = [
		'X'       => 'twitter',
		'You.com' => 'you',
	];

	/**
	 * Identifiers that were renamed, and what they are now.
	 *
	 * ChatGPT was listed as the search engine `searchgpt`. It is an AI
	 * assistant called `chatgpt` now, and a condition saved against the old
	 * name should go on matching.
	 *
	 * @since 1.1.0
	 * @var array<string, string> Old => current.
	 */
	protected const RENAMED_SLUGS = [
		'searchgpt' => 'chatgpt',
	];

	/**
	 * Get the current HTTP referrer.
	 *
	 * @return string|null The referrer URL or null if not available.
	 */
	public static function get(): ?string {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return null;
		}

		// esc_url_raw rather than a text sanitizer: a referrer is a URL, and
		// this is the one that knows what that means -- it drops schemes that
		// are not http(s) and percent-encodes what has no business being
		// unencoded. Everything downstream parses this value.
		$referrer = esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );

		return '' !== $referrer ? $referrer : null;
	}
}
