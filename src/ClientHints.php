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
	 * Most brand entries {@see self::brands()} returns.
	 *
	 * A browser sends three or four, one of them a decoy. The header is the
	 * client's to pad, and what comes back ends up in logs and columns.
	 *
	 * @since 1.0.0
	 */
	public const MAX_BRANDS = 8;

	/**
	 * Brands that name a browser more precisely than "Chromium" or
	 * "Google Chrome" do, most specific first.
	 *
	 * Every Chromium browser lists "Chromium" as well as itself, so the
	 * first of these that is present is the browser.
	 */
	private const SPECIFIC_BRANDS = array(
		'Brave',
		'Microsoft Edge',
		'Opera',
		'Opera GX',
		'Vivaldi',
		'Samsung Internet',
		'YaBrowser',
		'DuckDuckGo',
	);

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
		// At least one real brand, not merely a header: anything can send
		// `Sec-CH-UA: x`, and a browser that sent only its decoy entry has
		// said nothing.
		return array() !== self::brands( $server );
	}

	/**
	 * The browser the brands name.
	 *
	 * The most specific one present — which is how Brave, which reads as
	 * Chrome in every user agent, gets named at all.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return string|null e.g. `Brave`, `Microsoft Edge`, `Google Chrome`.
	 */
	public static function browser( ?array $server = null ): ?string {
		$brands = self::brands( $server );

		foreach ( array( ...self::SPECIFIC_BRANDS, 'Google Chrome', 'Chromium' ) as $brand ) {
			if ( isset( $brands[ $brand ] ) ) {
				return $brand;
			}
		}

		return null;
	}

	/**
	 * Everything the client hints say, at once.
	 *
	 * @since 2.1.0
	 *
	 * @param array<string, mixed>|null $server `$_SERVER`-shaped array.
	 *
	 * @return array{available: bool, brands: array<string, string>, browser: string|null, platform: string|null, platform_version: string|null, windows_version: string|null, mobile: bool|null, model: string|null, architecture: string|null}
	 */
	public static function read( ?array $server = null ): array {
		return array(
			'available'        => self::available( $server ),
			'brands'           => self::brands( $server ),
			'browser'          => self::browser( $server ),
			'platform'         => self::platform( $server ),
			'platform_version' => self::platform_version( $server ),
			'windows_version'  => self::windows_version( $server ),
			'mobile'           => self::is_mobile( $server ),
			'model'            => self::model( $server ),
			'architecture'     => self::architecture( $server ),
		);
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
		$value = self::header( 'HTTP_SEC_CH_UA_MOBILE', $server );

		return null === $value ? null : '?1' === $value;
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
		// Printable characters only, and no more of them than a real
		// brand list runs to.
		$raw = (string) preg_replace( '/[^\x20-\x7E]/', '', Server::value( Server::get( $server ), 'HTTP_SEC_CH_UA' ) );
		$raw = mb_substr( trim( $raw ), 0, 512 );

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

			if ( count( $brands ) >= self::MAX_BRANDS ) {
				break;
			}
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
		$value = (string) preg_replace( '/[^\x20-\x7E]/', '', Server::value( Server::get( $server ), $key ) );
		$value = mb_substr( trim( $value ), 0, 64 );

		// One pair of quotes comes off, as sent. Not every quote: `""x""`
		// is not a value any browser sends, and should not read as one.
		$value = (string) preg_replace( '/^"|"$/', '', $value );

		return '' === $value ? null : $value;
	}
}
