<?php
/**
 * Choosing among the languages a site has, by what the visitor asked for.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\AcceptLanguage;

/**
 * Choosing among the languages a site has, by what the visitor asked for.
 *
 * @since 1.0.0
 */
trait Matching {

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
}
