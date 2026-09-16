<?php
/**
 * User-Agent Client Hints.
 *
 * @package   SugarCommerce\Network
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Class ClientHints
 *
 * The replacement for user-agent sniffing, and the only way to answer
 * some questions at all.
 *
 * Chromium's UA reduction froze the user-agent string: the Windows
 * version, the macOS version, and the device model were all removed or
 * pinned. Windows 11 reports `Windows NT 10.0`, identical to Windows 10.
 * macOS reports 10.15.7 whatever it is running. Those are not gaps in
 * {@see UserAgent} that better patterns could close — the data is no
 * longer in the string.
 *
 * Client hints put it back, in headers, on request.
 *
 * **Low-entropy hints arrive automatically** — `Sec-CH-UA`,
 * `Sec-CH-UA-Mobile`, `Sec-CH-UA-Platform`.
 *
 * **High-entropy hints must be asked for.** Send an `Accept-CH` response
 * header naming what you want, and the browser includes it on subsequent
 * requests to your origin — so the first request never has them:
 *
 *   header( 'Accept-CH: ' . ClientHints::ACCEPT_CH );
 *
 * Only Chromium browsers send these. Firefox and Safari send nothing, so
 * every reader here returns null there and {@see UserAgent} remains the
 * fallback.
 *
 * @since 1.0.0
 */
final readonly class ClientHints {

	/**
	 * A reasonable `Accept-CH` value.
	 *
	 * Each hint you request is more fingerprinting surface, so ask only
	 * for what you will actually use.
	 */
	public const ACCEPT_CH = 'Sec-CH-UA-Platform-Version, Sec-CH-UA-Model, Sec-CH-UA-Full-Version-List, Sec-CH-UA-Arch';

	/**
	 * Whether this browser sends client hints at all.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return bool False for Firefox and Safari.
	 */
	public static function available( ?array $server = null ): bool {
		$server ??= $_SERVER;

		return '' !== trim( (string) ( $server['HTTP_SEC_CH_UA'] ?? '' ) );
	}

	/**
	 * The platform name.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null e.g. `Windows`, `macOS`, `Android`.
	 */
	public static function platform( ?array $server = null ): ?string {
		return self::header( 'HTTP_SEC_CH_UA_PLATFORM', $server );
	}

	/**
	 * The platform version.
	 *
	 * High-entropy: requires `Accept-CH`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null
	 */
	public static function platform_version( ?array $server = null ): ?string {
		return self::header( 'HTTP_SEC_CH_UA_PLATFORM_VERSION', $server );
	}

	/**
	 * Windows 10 or Windows 11, which the user agent cannot tell you.
	 *
	 * Windows 11 reports a platform version of 13.0.0 or above; Windows
	 * 10 reports 1.0.0 to 10.0.0. The boundary is Microsoft's, not a
	 * guess — the numbering deliberately jumped.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null `Windows 11`, `Windows 10`, or null when the
	 *                     hint is absent or the platform is not Windows.
	 */
	public static function windows_version( ?array $server = null ): ?string {
		if ( 'Windows' !== self::platform( $server ) ) {
			return null;
		}

		$version = self::platform_version( $server );

		if ( null === $version ) {
			return null;
		}

		$major = (int) explode( '.', $version )[0];

		return match ( true ) {
			$major >= 13 => 'Windows 11',
			$major >= 1  => 'Windows 10',
			default      => null,
		};
	}

	/**
	 * Whether the browser reports itself as mobile.
	 *
	 * More reliable than any user-agent heuristic, because the browser is
	 * answering rather than being guessed at.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return bool|null Null when the hint is absent.
	 */
	public static function is_mobile( ?array $server = null ): ?bool {
		$server ??= $_SERVER;
		$value    = trim( (string) ( $server['HTTP_SEC_CH_UA_MOBILE'] ?? '' ) );

		return '' === $value ? null : '?1' === $value;
	}

	/**
	 * The device model.
	 *
	 * High-entropy, and only populated on mobile. Empty on desktop.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null e.g. `Pixel 8`.
	 */
	public static function model( ?array $server = null ): ?string {
		return self::header( 'HTTP_SEC_CH_UA_MODEL', $server );
	}

	/**
	 * The CPU architecture.
	 *
	 * High-entropy: requires `Accept-CH`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null e.g. `x86`, `arm`.
	 */
	public static function architecture( ?array $server = null ): ?string {
		return self::header( 'HTTP_SEC_CH_UA_ARCH', $server );
	}

	/**
	 * The brand/version pairs from `Sec-CH-UA`.
	 *
	 * Includes deliberate decoy entries — "Not_A Brand" and similar —
	 * which browsers inject so that code cannot assume a fixed list. They
	 * are filtered out here.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return array<string, string> Brand => version.
	 */
	public static function brands( ?array $server = null ): array {
		$server ??= $_SERVER;
		$raw      = trim( (string) ( $server['HTTP_SEC_CH_UA'] ?? '' ) );

		if ( '' === $raw ) {
			return array();
		}

		$brands = array();

		// Format: "Chromium";v="131", "Not_A Brand";v="24"
		if ( 0 === preg_match_all( '/"([^"]+)";v="([^"]+)"/', $raw, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$brand = $match[1];

			// Skip the intentionally-nonsensical padding entries.
			if ( 1 === preg_match( '/not.?a.?brand/i', $brand ) ) {
				continue;
			}

			$brands[ $brand ] = $match[2];
		}

		return $brands;
	}

	/**
	 * Read and lightly validate a hint header.
	 *
	 * Values arrive as quoted strings, and are attacker-supplied like any
	 * header, so they are unquoted, length-capped, and stripped of
	 * anything that is not printable.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $key    `$_SERVER` key.
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null
	 */
	private static function header( string $key, ?array $server ): ?string {
		$server ??= $_SERVER;
		$value    = trim( (string) ( $server[ $key ] ?? '' ), " \t\"" );
		$value    = (string) preg_replace( '/[^\x20-\x7E]/', '', $value );

		return '' === $value ? null : mb_substr( $value, 0, 64 );
	}
}
