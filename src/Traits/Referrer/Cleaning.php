<?php
/**
 * Making what a visitor sent safe to keep: the referrer, a tag, a landing path.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

/**
 * Making what a visitor sent safe to keep: the referrer, a tag, a landing path.
 *
 * @since 1.1.0
 */
trait Cleaning {

	/**
	 * A referrer that is safe to store and to show as a link.
	 *
	 * http or https, with a host, valid UTF-8, no control characters, not
	 * absurdly long. The characters that would break out of an `href="…"`
	 * are percent-encoded rather than dropped, so the URL still works.
	 *
	 * Anything else comes back empty, and the visit counts as direct: a
	 * `javascript:` or `data:` "referrer" surviving into an admin table is a
	 * stored attack the moment someone clicks it. Invalid UTF-8 is refused
	 * rather than repaired, because a database column or a JSON encoder
	 * further on will refuse it less politely.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $referrer The Referer header.
	 *
	 * @return string The URL, or '' when it is not usable.
	 */
	public static function safe_url( ?string $referrer ): string {
		$value = trim( (string) $referrer );

		if ( '' === $value || ! mb_check_encoding( $value, 'UTF-8' ) || mb_strlen( $value ) > self::MAX_URL_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/^https?:/i', $value ) || null === self::parse_host( $value ) ) {
			return '';
		}

		return (string) preg_replace_callback(
			'/["\'<>`\\\\ ]/',
			static fn( array $found ): string => sprintf( '%%%02X', ord( $found[0] ) ),
			$value
		);
	}

	/**
	 * A UTM value as a tag.
	 *
	 * Letters, digits and `-_.+ ` only, runs of anything else as one space,
	 * at most TAG_MAX characters. What arrives is typed by whoever made the
	 * link, then stored against orders, shown in dashboards and read back by
	 * agents; a tag has room for a campaign name and not for an instruction.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $value As sent.
	 *
	 * @return string|null The tag, or null when nothing is left.
	 */
	public static function to_tag( ?string $value ): ?string {
		$tag = (string) $value;

		// The pattern below is Unicode-aware, and returns nothing at all for
		// a string that is not valid UTF-8.
		if ( ! mb_check_encoding( $tag, 'UTF-8' ) ) {
			$tag = mb_scrub( $tag, 'UTF-8' );
		}

		$tag = (string) preg_replace( '/[^\p{L}\p{N}\-_.+ ]+/u', ' ', $tag );
		$tag = trim( (string) preg_replace( '/\s+/', ' ', $tag ) );
		$tag = trim( mb_substr( $tag, 0, self::TAG_MAX ) );

		return '' !== $tag ? $tag : null;
	}

	/**
	 * A landing page as a path that is safe to keep.
	 *
	 * No query string or fragment -- either can carry a token or an email
	 * address -- and at most LANDING_MAX characters.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $landing A URL or a path, as the page sent it.
	 *
	 * @return string|null e.g. `/products/prism`, or null for nothing usable.
	 */
	public static function landing_path( ?string $landing ): ?string {
		$landing = str_replace( [ "\t", "\r", "\n" ], '', trim( (string) $landing ) );

		if ( '' === $landing ) {
			return null;
		}

		// `//host/path` is a URL without its scheme, not a path.
		if ( 1 === preg_match( '/^[\/\\\\]{2}/', $landing ) ) {
			$landing = 'https:' . $landing;
		}

		if ( 1 === preg_match( '/^https?:/i', $landing ) ) {
			if ( null === self::parse_host( $landing ) ) {
				return null;
			}

			// The path starts at the first slash after the host.
			$landing = (string) preg_replace( '/^https?:[\/\\\\]*[^\/\\\\?#]*/i', '', $landing );
		}

		$path     = str_replace( '\\', '/', (string) preg_replace( '/[?#].*$/s', '', $landing ) );
		$segments = [];

		// Resolved the way a browser would, so `/a/../b` is kept as `/b`. A
		// browser reads `%2e` as a dot here too.
		foreach ( explode( '/', ltrim( $path, '/' ) ) as $segment ) {
			$dots = str_ireplace( '%2e', '.', $segment );

			if ( '..' === $dots ) {
				array_pop( $segments );
			} elseif ( '.' !== $dots ) {
				$segments[] = $segment;
			}
		}

		$path = '/' . implode( '/', $segments );
		$path = (string) preg_replace_callback(
			'/[\x00-\x20"<>^`{}\x7F-\xFF]/',
			static fn( array $found ): string => rawurlencode( $found[0] ),
			$path
		);

		return substr( $path, 0, self::LANDING_MAX );
	}
}
