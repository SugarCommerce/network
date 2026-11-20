<?php
/**
 * Accept-Language
 *
 * Which language the visitor's browser asks for, read from the
 * `Accept-Language` header.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

use SugarCommerce\Network\Traits\AcceptLanguage\Matching;
use SugarCommerce\Network\Traits\AcceptLanguage\Options;
use SugarCommerce\Network\Traits\AcceptLanguage\Tags;

/**
 * AcceptLanguage Class
 *
 * The header is an ordered list with quality weights --
 * `de-AT,de;q=0.9,en;q=0.7` -- and the client writes it, so it is read
 * the way the standard says and held to it: a quality is 0 to 1, `q=0`
 * means "not this one", what is not shaped like a language tag is
 * dropped, and only the first {@see self::MAX_ENTRIES} entries of the
 * first {@see self::MAX_LENGTH} bytes are read.
 *
 * Every reader takes an optional `$server` last, a `$_SERVER`-shaped
 * array, so a queued job can hand over the request it saved:
 *
 *     AcceptLanguage::get_best_match( [ 'en', 'de', 'fr-CA' ], 'en' );
 *     AcceptLanguage::is_rtl();
 *     AcceptLanguage::get_primary( $saved_server );
 *
 * Reading the header is this class; choosing a language by it (Matching),
 * language tags on their own (Tags) and the lists to choose from
 * (Options) are traits, in src/Traits/AcceptLanguage/.
 *
 * @since 1.0.0
 */
final class AcceptLanguage {

	use Matching;
	use Options;
	use Tags;

	/**
	 * The most of the header read, in bytes.
	 *
	 * Browsers send well under a hundred. The rest of a longer one is
	 * ignored rather than parsed, sorted and matched on every call.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 1000;

	/**
	 * The most entries read from one header.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * Languages written right to left.
	 *
	 * `ku` is here for Sorani, as before; Kurmanji is written `ku-Latn`
	 * and is not.
	 *
	 * @var array<int, string>
	 */
	private const RTL_LANGUAGES = [
		'ar',  // Arabic.
		'he',  // Hebrew.
		'iw',  // Hebrew, the withdrawn code some systems still send.
		'fa',  // Persian.
		'ur',  // Urdu.
		'yi',  // Yiddish.
		'ji',  // Yiddish, the withdrawn code.
		'ps',  // Pashto.
		'sd',  // Sindhi.
		'ug',  // Uyghur.
		'ku',  // Kurdish (Sorani).
		'ckb', // Central Kurdish.
		'dv',  // Divehi.
		'syr', // Syriac.
	];

	/**
	 * Scripts written right to left.
	 *
	 * A script subtag decides on its own: `az-Arab` is right to left
	 * though Azerbaijani is not, and `ku-Latn` is not though Kurdish is.
	 *
	 * @var array<int, string>
	 */
	private const RTL_SCRIPTS = [ 'Arab', 'Hebr', 'Thaa', 'Syrc', 'Nkoo', 'Adlm', 'Rohg', 'Mand', 'Samr' ];

	/**
	 * Languages' names in themselves, by tag.
	 *
	 * Regional entries only where the name differs from the language's
	 * own: Brazilian and European Portuguese are spelt and read
	 * differently enough that a reader looks for the one they speak.
	 *
	 * @var array<string, string>
	 */
	private const ENDONYMS = [
		'af'      => 'Afrikaans',
		'ar'      => 'العربية',
		'bg'      => 'Български',
		'bn'      => 'বাংলা',
		'ca'      => 'Català',
		'cs'      => 'Čeština',
		'cy'      => 'Cymraeg',
		'da'      => 'Dansk',
		'de'      => 'Deutsch',
		'el'      => 'Ελληνικά',
		'en'      => 'English',
		'en-GB'   => 'English (UK)',
		'en-US'   => 'English (US)',
		'es'      => 'Español',
		'es-419'  => 'Español (Latinoamérica)',
		'es-MX'   => 'Español (México)',
		'et'      => 'Eesti',
		'eu'      => 'Euskara',
		'fa'      => 'فارسی',
		'fi'      => 'Suomi',
		'fil'     => 'Filipino',
		'fr'      => 'Français',
		'fr-CA'   => 'Français (Canada)',
		'ga'      => 'Gaeilge',
		'gl'      => 'Galego',
		'he'      => 'עברית',
		'hi'      => 'हिन्दी',
		'hr'      => 'Hrvatski',
		'hu'      => 'Magyar',
		'id'      => 'Bahasa Indonesia',
		'is'      => 'Íslenska',
		'it'      => 'Italiano',
		'ja'      => '日本語',
		'ko'      => '한국어',
		'lt'      => 'Lietuvių',
		'lv'      => 'Latviešu',
		'ms'      => 'Bahasa Melayu',
		'mt'      => 'Malti',
		'nb'      => 'Norsk bokmål',
		'nl'      => 'Nederlands',
		'no'      => 'Norsk',
		'pl'      => 'Polski',
		'pt'      => 'Português',
		'pt-BR'   => 'Português (Brasil)',
		'pt-PT'   => 'Português (Portugal)',
		'ro'      => 'Română',
		'ru'      => 'Русский',
		'sk'      => 'Slovenčina',
		'sl'      => 'Slovenščina',
		'sr'      => 'Српски',
		'sv'      => 'Svenska',
		'sw'      => 'Kiswahili',
		'ta'      => 'தமிழ்',
		'th'      => 'ไทย',
		'tr'      => 'Türkçe',
		'uk'      => 'Українська',
		'ur'      => 'اردو',
		'vi'      => 'Tiếng Việt',
		'zh'      => '中文',
		'zh-CN'   => '中文（简体）',
		'zh-Hans' => '中文（简体）',
		'zh-Hant' => '中文（繁體）',
		'zh-HK'   => '中文（香港）',
		'zh-TW'   => '中文（台灣）',
	];

	/**
	 * The raw header, sanitized.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in. Null reads the
	 *                           live request.
	 *
	 * @return string|null Null when absent or empty.
	 */
	public static function get_header( ?array $server = null ): ?string {
		$header = Server::header( Server::get( $server ), 'Accept-Language' );

		// It arrives from the client. A well-formed one holds only letters,
		// digits and `,;=.-*`, all of which survive this.
		$header = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $header ) : trim( strip_tags( $header ) );

		return '' !== $header ? $header : null;
	}

	/**
	 * The best-ranked language: `en-GB`, `de`.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return string|null
	 */
	public static function get_primary( ?array $server = null ): ?string {
		$languages = self::parse( null, $server );

		return [] === $languages ? null : (string) array_key_first( $languages );
	}

	/**
	 * The best-ranked language without its region: `en`.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return string|null
	 */
	public static function get_primary_language( ?array $server = null ): ?string {
		$primary = self::get_primary( $server );

		return null === $primary ? null : self::extract_language( $primary );
	}

	/**
	 * The best-ranked language's region: `GB`, `419`.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return string|null Null when there is none.
	 */
	public static function get_primary_region( ?array $server = null ): ?string {
		$primary = self::get_primary( $server );

		return null === $primary ? null : self::extract_region( $primary );
	}

	/**
	 * The header as languages against qualities, best first.
	 *
	 * Equal qualities keep the order they were sent in. The same tag sent
	 * twice keeps the last quality given.
	 *
	 * @param string|null $header The header to read. Null reads the request's.
	 * @param array|null  $server `$_SERVER`, or a stand-in, when $header is null.
	 *
	 * @return array<string, float>
	 */
	public static function parse( ?string $header = null, ?array $server = null ): array {
		$header = $header ?? self::get_header( $server );

		if ( null === $header || '' === trim( $header ) ) {
			return [];
		}

		$languages = [];

		foreach ( self::entries( $header ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$q = 1.0;

			if ( str_contains( $part, ';' ) ) {
				[ $part, $parameters ] = explode( ';', $part, 2 );

				// Parameter names are case-insensitive (RFC 9110 5.6.6), so
				// `Q=` counts too.
				if ( preg_match( '/q=([0-9.]+)/i', $parameters, $matches ) ) {
					$q = (float) $matches[1];
				}
			}

			// A quality is 0 to 1 (RFC 9110 12.4.2). Left alone, `q=99`
			// outranks every well-formed entry and becomes the primary
			// language, a preference the sender does not get to assert.
			$q    = max( 0.0, min( 1.0, $q ) );
			$lang = self::normalize( $part );

			// `q=0` is "not this one": kept, accepts() said yes to a
			// language the visitor refused. And a "language" of `<b>en</b>`
			// or `../x` is something a caller would print or use as a file
			// name on the strength of it having come out of a parser.
			if ( '' !== $lang && $q > 0.0 && self::is_tag( $lang ) ) {
				$languages[ $lang ] = $q;
			}
		}

		arsort( $languages );

		return $languages;
	}

	/**
	 * Every accepted language, best first, without qualities.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return array<int, string>
	 */
	public static function get_all( ?array $server = null ): array {
		return array_map( 'strval', array_keys( self::parse( null, $server ) ) );
	}

	/**
	 * Every accepted language without its region, once each, best first.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return array<int, string> `[ 'en', 'de' ]`.
	 */
	public static function get_languages( ?array $server = null ): array {
		$languages = [];

		foreach ( self::get_all( $server ) as $locale ) {
			$lang = self::extract_language( $locale );

			if ( '' !== $lang && ! in_array( $lang, $languages, true ) ) {
				$languages[] = $lang;
			}
		}

		return $languages;
	}

	/**
	 * Whether the visitor's best-ranked language is written right to left.
	 *
	 * @param array|null $server `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function is_rtl( ?array $server = null ): bool {
		$primary = self::get_primary( $server );

		return null !== $primary && self::is_rtl_language( $primary );
	}

	/**
	 * The entries of a header, as many as are read.
	 *
	 * A header longer than {@see self::MAX_LENGTH} is cut at the last
	 * comma inside it, so a tag cut in half is not read as another tag.
	 *
	 * @param string $header The header.
	 *
	 * @return array<int, string>
	 */
	private static function entries( string $header ): array {
		if ( strlen( $header ) > self::MAX_LENGTH ) {
			$header = substr( $header, 0, self::MAX_LENGTH );
			$comma  = strrpos( $header, ',' );
			$header = false === $comma ? '' : substr( $header, 0, $comma );
		}

		return array_slice( explode( ',', $header, self::MAX_ENTRIES + 1 ), 0, self::MAX_ENTRIES );
	}

	/**
	 * The script and region of a normalized tag.
	 *
	 * @param string $tag A tag from normalize().
	 *
	 * @return array{script: string|null, region: string|null}
	 */
	private static function subtags( string $tag ): array {
		$found = [
			'script' => null,
			'region' => null,
		];

		foreach ( array_slice( explode( '-', $tag ), 1 ) as $part ) {
			if ( 1 === strlen( $part ) ) {
				break;
			}

			if ( null === $found['script'] && 1 === preg_match( '/^[a-z]{3}$/D', $part ) ) {
				continue; // An extended language.
			}

			if ( null === $found['script'] && 1 === preg_match( '/^[A-Z][a-z]{3}$/D', $part ) ) {
				$found['script'] = $part;
			} elseif ( null === $found['region'] && 1 === preg_match( '/^(?:[A-Z]{2}|[0-9]{3})$/D', $part ) ) {
				$found['region'] = $part;
				break;
			} else {
				break;
			}
		}

		return $found;
	}

	/**
	 * Whether a string has the shape of a language tag.
	 *
	 * Letters, digits and hyphens in subtags of one to eight characters
	 * (RFC 5646), or the wildcard.
	 *
	 * @param string $tag The candidate.
	 *
	 * @return bool
	 */
	private static function is_tag( string $tag ): bool {
		return (bool) preg_match( '/^(?:\*|[a-z0-9]{1,8}(?:-[a-z0-9]{1,8})*)$/i', $tag );
	}
}
