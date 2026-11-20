<?php
/**
 * Language tags on their own: their standard spelling, parts, direction and native name.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\AcceptLanguage;

/**
 * Language tags on their own: their standard spelling, parts, direction and native name.
 *
 * @since 1.0.0
 */
trait Tags {

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
}
