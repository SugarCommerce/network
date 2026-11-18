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
 * @since 1.0.0
 */
final class AcceptLanguage {

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
	 * Whether a language is accepted.
	 *
	 * A visitor who sent `en-GB` accepts English, and so `en-US`, unless
	 * $exact asks for that tag and no other.
	 *
	 * @param string     $language The language: `en`, `en-US`.
	 * @param bool       $exact    Only this exact tag.
	 * @param array|null $server   `$_SERVER`, or a stand-in.
	 *
	 * @return bool
	 */
	public static function accepts( string $language, bool $exact = false, ?array $server = null ): bool {
		$languages = self::parse( null, $server );
		$language  = self::normalize( $language );

		if ( '' === $language ) {
			return false;
		}

		if ( isset( $languages[ $language ] ) ) {
			return true;
		}

		if ( $exact ) {
			return false;
		}

		$base = self::extract_language( $language );

		foreach ( array_keys( $languages ) as $accepted ) {
			if ( self::extract_language( (string) $accepted ) === $base ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The quality the visitor gave a language.
	 *
	 * @param string     $language The language.
	 * @param array|null $server   `$_SERVER`, or a stand-in.
	 *
	 * @return float|null Null when it was not offered: nought is a real
	 *                    quality with a meaning of its own.
	 */
	public static function get_quality( string $language, ?array $server = null ): ?float {
		return self::parse( null, $server )[ self::normalize( $language ) ] ?? null;
	}

	/**
	 * The best of the languages a site has, for this visitor.
	 *
	 * An exact tag anywhere in the header beats a base-language match, so
	 * a visitor sending `en-GB,en-US;q=0.9` gets `en-GB` over `en-US`.
	 * Then `en-AU` is served by `en-GB` rather than the fallback. The
	 * answer is the caller's own spelling of it.
	 *
	 * @param array<int, string> $available The languages there are.
	 * @param string|null        $fallback  When nothing fits.
	 * @param array|null         $server    `$_SERVER`, or a stand-in.
	 *
	 * @return string|null
	 */
	public static function get_best_match( array $available, ?string $fallback = null, ?array $server = null ): ?string {
		return self::best( self::parse( null, $server ), $available ) ?? $fallback;
	}

	/**
	 * The language to serve: what the visitor chose, else what their
	 * browser asks for, else the fallback.
	 *
	 * A choice the visitor made -- `?lang=de` from a picker, the cookie
	 * that remembers it, an account's setting -- beats the browser,
	 * because someone who picked German on one page and got English on the
	 * next would think the picker was broken. Each is matched as one tag
	 * (`de-AT` is served by `de`); anything that is not a string, a tag or
	 * on offer is skipped, so `$_GET` and `$_COOKIE` values can be handed
	 * over as they come.
	 *
	 * @param array<array-key, mixed> $preferences Tags the visitor gave, most
	 *                                             authoritative first.
	 * @param array<int, string>      $available   The languages there are.
	 * @param string|null             $fallback    When nothing fits.
	 * @param array|null              $server      `$_SERVER`, or a stand-in.
	 *
	 * @return string|null
	 */
	public static function pick( array $preferences, array $available, ?string $fallback = null, ?array $server = null ): ?string {
		foreach ( $preferences as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}

			$tag = self::normalize( $tag );

			if ( '' === $tag || '*' === $tag || ! self::is_tag( $tag ) ) {
				continue;
			}

			$match = self::best( [ $tag => 1.0 ], $available );

			if ( null !== $match ) {
				return $match;
			}
		}

		return self::get_best_match( $available, $fallback, $server );
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
	 * Whether a language tag is written right to left.
	 *
	 * A script subtag decides when there is one: `az-Arab` is, `ku-Latn`
	 * is not.
	 *
	 * @param string $code Any tag: `ar`, `fa-IR`, `pa-Arab`.
	 *
	 * @return bool
	 */
	public static function is_rtl_language( string $code ): bool {
		$code = self::normalize( $code );

		if ( '' === $code ) {
			return false;
		}

		$script = self::subtags( $code )['script'];

		if ( null !== $script ) {
			return in_array( $script, self::RTL_SCRIPTS, true );
		}

		return in_array( self::extract_language( $code ), self::RTL_LANGUAGES, true );
	}

	/**
	 * A tag in BCP 47's conventional case.
	 *
	 * Language lower, script title, region upper, the rest lower:
	 * `ZH_hant_tw` is `zh-Hant-TW`, `es-419` stays, `en-us-POSIX` is
	 * `en-US-posix`. An underscore is read as a hyphen.
	 *
	 * @param string $code The tag.
	 *
	 * @return string Empty for nothing.
	 */
	public static function normalize( string $code ): string {
		$code = str_replace( '_', '-', trim( $code ) );

		if ( '' === $code ) {
			return '';
		}

		$parts  = explode( '-', $code );
		$out    = [ strtolower( (string) array_shift( $parts ) ) ];
		$script = false;
		$region = false;
		$tail   = false;

		foreach ( $parts as $part ) {
			$length = strlen( $part );

			if ( $tail ) {
				$out[] = strtolower( $part );
				continue;
			}

			if ( 1 === $length ) {
				// A singleton: an extension or private use, all lower case.
				$tail  = true;
				$out[] = strtolower( $part );
			} elseif ( ! $script && ! $region && 3 === $length && ctype_alpha( $part ) ) {
				// An extended language: `zh-yue`.
				$out[] = strtolower( $part );
			} elseif ( ! $script && ! $region && 4 === $length && ctype_alpha( $part ) ) {
				$script = true;
				$out[]  = ucfirst( strtolower( $part ) );
			} elseif ( ! $region && ( ( 2 === $length && ctype_alpha( $part ) ) || ( 3 === $length && ctype_digit( $part ) ) ) ) {
				$region = true;
				$out[]  = strtoupper( $part );
			} else {
				$region = true;
				$out[]  = strtolower( $part );
			}
		}

		return implode( '-', $out );
	}

	/**
	 * The language part of a tag: `en` from `en-US`.
	 *
	 * @param string $locale The tag.
	 *
	 * @return string
	 */
	public static function extract_language( string $locale ): string {
		return strtolower( explode( '-', str_replace( '_', '-', $locale ) )[0] );
	}

	/**
	 * The region part of a tag: `US` from `en-US`, `TW` from `zh-Hant-TW`.
	 *
	 * @param string $locale The tag.
	 *
	 * @return string|null Null when there is none; a script is not one.
	 */
	public static function extract_region( string $locale ): ?string {
		return self::subtags( self::normalize( $locale ) )['region'];
	}

	/**
	 * A language's name in itself: "Deutsch", "日本語", "Português (Brasil)".
	 *
	 * In itself, because the person looking for German in a language
	 * picker is the person who cannot read the English word for it. The
	 * table covers the common languages and the regional variants whose
	 * names differ; anything else is asked of ext-intl when it is loaded,
	 * then the base language's name, then the tag. Only a well-formed tag
	 * is echoed back, so a tag from a query string cannot turn up on the
	 * page as the "name" of a language.
	 *
	 * @param string $locale The tag, any case, `-` or `_`.
	 *
	 * @return string Empty for something that is not a tag.
	 */
	public static function endonym( string $locale ): string {
		$tag = self::normalize( $locale );

		if ( isset( self::ENDONYMS[ $tag ] ) ) {
			return self::ENDONYMS[ $tag ];
		}

		if ( 1 !== preg_match( '/^[a-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $tag ) ) {
			return '';
		}

		if ( class_exists( \Locale::class ) ) {
			$icu  = str_replace( '-', '_', $tag );
			$name = \Locale::getDisplayName( $icu, $icu );

			// ICU answers a tag it does not know with the tag itself.
			if ( is_string( $name ) && '' !== $name && strtolower( $name ) !== strtolower( $icu ) ) {
				return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
			}
		}

		return self::ENDONYMS[ self::extract_language( $tag ) ] ?? $tag;
	}

	/**
	 * Common languages and locales for a select.
	 *
	 * The same list as conditions' `Options\Request::get_languages()`;
	 * keep the two in step.
	 *
	 * @param bool $as_options `[ [ 'value' => 'en', 'label' => 'English' ], ... ]`
	 *                         rather than `[ 'en' => 'English' ]`.
	 *
	 * @return array
	 */
	public static function get_common_languages( bool $as_options = false ): array {
		$languages = [
			'en'    => __( 'English', 'sugarcommerce' ),
			'en-US' => __( 'English (US)', 'sugarcommerce' ),
			'en-GB' => __( 'English (UK)', 'sugarcommerce' ),
			'es'    => __( 'Spanish', 'sugarcommerce' ),
			'es-ES' => __( 'Spanish (Spain)', 'sugarcommerce' ),
			'es-MX' => __( 'Spanish (Mexico)', 'sugarcommerce' ),
			'fr'    => __( 'French', 'sugarcommerce' ),
			'fr-FR' => __( 'French (France)', 'sugarcommerce' ),
			'fr-CA' => __( 'French (Canada)', 'sugarcommerce' ),
			'de'    => __( 'German', 'sugarcommerce' ),
			'de-DE' => __( 'German (Germany)', 'sugarcommerce' ),
			'de-AT' => __( 'German (Austria)', 'sugarcommerce' ),
			'it'    => __( 'Italian', 'sugarcommerce' ),
			'pt'    => __( 'Portuguese', 'sugarcommerce' ),
			'pt-BR' => __( 'Portuguese (Brazil)', 'sugarcommerce' ),
			'pt-PT' => __( 'Portuguese (Portugal)', 'sugarcommerce' ),
			'nl'    => __( 'Dutch', 'sugarcommerce' ),
			'ru'    => __( 'Russian', 'sugarcommerce' ),
			'ja'    => __( 'Japanese', 'sugarcommerce' ),
			'zh'    => __( 'Chinese', 'sugarcommerce' ),
			'zh-CN' => __( 'Chinese (Simplified)', 'sugarcommerce' ),
			'zh-TW' => __( 'Chinese (Traditional)', 'sugarcommerce' ),
			'ko'    => __( 'Korean', 'sugarcommerce' ),
			'ar'    => __( 'Arabic', 'sugarcommerce' ),
			'hi'    => __( 'Hindi', 'sugarcommerce' ),
			'tr'    => __( 'Turkish', 'sugarcommerce' ),
			'pl'    => __( 'Polish', 'sugarcommerce' ),
			'sv'    => __( 'Swedish', 'sugarcommerce' ),
			'da'    => __( 'Danish', 'sugarcommerce' ),
			'no'    => __( 'Norwegian', 'sugarcommerce' ),
			'fi'    => __( 'Finnish', 'sugarcommerce' ),
			'el'    => __( 'Greek', 'sugarcommerce' ),
			'he'    => __( 'Hebrew', 'sugarcommerce' ),
			'th'    => __( 'Thai', 'sugarcommerce' ),
			'vi'    => __( 'Vietnamese', 'sugarcommerce' ),
			'id'    => __( 'Indonesian', 'sugarcommerce' ),
			'ms'    => __( 'Malay', 'sugarcommerce' ),
			'cs'    => __( 'Czech', 'sugarcommerce' ),
			'hu'    => __( 'Hungarian', 'sugarcommerce' ),
			'ro'    => __( 'Romanian', 'sugarcommerce' ),
			'uk'    => __( 'Ukrainian', 'sugarcommerce' ),
		];

		return $as_options ? self::to_options( $languages ) : $languages;
	}

	/**
	 * Common languages without regions, for a select.
	 *
	 * @param bool $as_options `value`/`label` pairs rather than a map.
	 *
	 * @return array
	 */
	public static function get_base_languages( bool $as_options = false ): array {
		$languages = [
			'en' => __( 'English', 'sugarcommerce' ),
			'es' => __( 'Spanish', 'sugarcommerce' ),
			'fr' => __( 'French', 'sugarcommerce' ),
			'de' => __( 'German', 'sugarcommerce' ),
			'it' => __( 'Italian', 'sugarcommerce' ),
			'pt' => __( 'Portuguese', 'sugarcommerce' ),
			'nl' => __( 'Dutch', 'sugarcommerce' ),
			'ru' => __( 'Russian', 'sugarcommerce' ),
			'ja' => __( 'Japanese', 'sugarcommerce' ),
			'zh' => __( 'Chinese', 'sugarcommerce' ),
			'ko' => __( 'Korean', 'sugarcommerce' ),
			'ar' => __( 'Arabic', 'sugarcommerce' ),
			'hi' => __( 'Hindi', 'sugarcommerce' ),
			'tr' => __( 'Turkish', 'sugarcommerce' ),
			'pl' => __( 'Polish', 'sugarcommerce' ),
			'sv' => __( 'Swedish', 'sugarcommerce' ),
			'da' => __( 'Danish', 'sugarcommerce' ),
			'no' => __( 'Norwegian', 'sugarcommerce' ),
			'fi' => __( 'Finnish', 'sugarcommerce' ),
			'el' => __( 'Greek', 'sugarcommerce' ),
			'he' => __( 'Hebrew', 'sugarcommerce' ),
			'th' => __( 'Thai', 'sugarcommerce' ),
			'vi' => __( 'Vietnamese', 'sugarcommerce' ),
			'id' => __( 'Indonesian', 'sugarcommerce' ),
			'ms' => __( 'Malay', 'sugarcommerce' ),
			'cs' => __( 'Czech', 'sugarcommerce' ),
			'hu' => __( 'Hungarian', 'sugarcommerce' ),
			'ro' => __( 'Romanian', 'sugarcommerce' ),
			'uk' => __( 'Ukrainian', 'sugarcommerce' ),
		];

		return $as_options ? self::to_options( $languages ) : $languages;
	}

	/**
	 * The best of $available for languages already parsed.
	 *
	 * An exact tag anywhere in the list first, then a base language.
	 *
	 * @param array<string, float> $accepted  Languages, best first.
	 * @param array<int, string>   $available The languages there are.
	 *
	 * @return string|null The caller's own spelling, or null.
	 */
	private static function best( array $accepted, array $available ): ?string {
		if ( [] === $accepted || [] === $available ) {
			return null;
		}

		$offered = [];

		foreach ( $available as $lang ) {
			$offered[ self::normalize( (string) $lang ) ] = (string) $lang;
		}

		foreach ( array_keys( $accepted ) as $lang ) {
			if ( isset( $offered[ $lang ] ) ) {
				return $offered[ $lang ];
			}
		}

		foreach ( array_keys( $accepted ) as $lang ) {
			$base = self::extract_language( (string) $lang );

			foreach ( $offered as $normalized => $original ) {
				if ( self::extract_language( (string) $normalized ) === $base ) {
					return $original;
				}
			}
		}

		return null;
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

	/**
	 * A map as `value`/`label` pairs.
	 *
	 * @param array<string, string> $items The map.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function to_options( array $items ): array {
		$options = [];

		foreach ( $items as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => $label,
			];
		}

		return $options;
	}
}
