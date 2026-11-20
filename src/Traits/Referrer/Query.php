<?php
/**
 * The landing page's query: its UTM tags and click ID.
 *
 * @package SugarCommerce\Network
 * @since   1.1.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Traits\Referrer;

use SugarCommerce\Network\Referrer\Campaigns;

/**
 * The landing page's query: its UTM tags and click ID.
 *
 * @since 1.1.0
 */
trait Query {

	/**
	 * The landing page's query, from a query or a URL.
	 *
	 * Where a parameter is repeated the first one counts, which is what a
	 * browser's URLSearchParams gives and the opposite of parse_str().
	 *
	 * @since 1.1.0
	 *
	 * @param array $input The `query` (a string, or an array such as $_GET) or the `url`. See analyse().
	 *
	 * @return array<string, string> Parameter => value; empty when there are none.
	 */
	public static function params_from( array $input ): array {
		$query = $input['query'] ?? null;

		if ( is_string( $query ) ) {
			return self::parse_query( ltrim( $query, '?' ) );
		}

		if ( ! is_array( $query ) ) {
			return self::query_of( (string) ( $input['url'] ?? '' ) );
		}

		$params = [];

		foreach ( $query as $name => $value ) {
			// `?utm_source[]=a&utm_source[]=b` arrives as an array.
			$first = is_array( $value ) ? reset( $value ) : $value;

			if ( is_scalar( $first ) ) {
				$params[ (string) $name ] = (string) $first;
			}
		}

		return $params;
	}

	/**
	 * The UTM tags, each cleaned to a tag.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, string> $params The landing page's query. See params_from().
	 *
	 * @return array{source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null}
	 */
	public static function read_utm( array $params ): array {
		$medium = self::to_tag( $params['utm_medium'] ?? null );

		return [
			'source'   => self::to_tag( $params['utm_source'] ?? null ),
			'medium'   => null !== $medium ? mb_strtolower( $medium ) : null,
			'campaign' => self::to_tag( $params['utm_campaign'] ?? null ),
			'term'     => self::to_tag( $params['utm_term'] ?? null ),
			'content'  => self::to_tag( $params['utm_content'] ?? null ),
		];
	}

	/**
	 * The first click ID on the landing page.
	 *
	 * Which parameter it was and the network it means, never its value: the
	 * value identifies one click, and there is no reason to keep it.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, string> $params The landing page's query. See params_from().
	 *
	 * @return array{param: string, channel: string, network: string}|null
	 */
	public static function find_click_id( array $params ): ?array {
		foreach ( Campaigns::CLICK_IDS as [ $param, $channel, $network ] ) {
			if ( '' !== (string) ( $params[ $param ] ?? '' ) || '' !== (string) ( $params[ strtolower( $param ) ] ?? '' ) ) {
				return [
					'param'   => $param,
					'channel' => $channel,
					'network' => $network,
				];
			}
		}

		return null;
	}

	/**
	 * A URL's query, as parameter => value.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url A URL or a path.
	 *
	 * @return array<string, string>
	 */
	protected static function query_of( string $url ): array {
		$url   = (string) preg_replace( '/#.*$/s', '', $url );
		$start = strpos( $url, '?' );

		return false !== $start ? self::parse_query( substr( $url, $start + 1 ) ) : [];
	}

	/**
	 * A query string, as parameter => value.
	 *
	 * Not parse_str(): that keeps the last of a repeated parameter, rewrites
	 * dots and spaces in names to underscores, and builds arrays out of
	 * square brackets. A browser does none of those, and the two have to
	 * read the same link the same way.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query The query string, without its `?`.
	 *
	 * @return array<string, string>
	 */
	protected static function parse_query( string $query ): array {
		$params = [];

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$name  = urldecode( $parts[0] );

			if ( ! array_key_exists( $name, $params ) ) {
				$params[ $name ] = urldecode( $parts[1] ?? '' );
			}
		}

		return $params;
	}
}
