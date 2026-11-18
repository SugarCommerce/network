<?php
/**
 * Disk
 *
 * How much room the site has, where temporary files go, and which
 * operating system it runs on.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Disk Class
 *
 * For the check before an import, a backup or an export writes a large
 * file, and for a status screen.
 *
 * @since 1.0.0
 */
final class Disk {

	/**
	 * Space on the disk a folder is on, in bytes.
	 *
	 * @param string $dir The folder. The site's own by default.
	 *
	 * @return array{total: float, free: float, used: float, percent: float}|null
	 *         Null when the folder is missing or the host will not say.
	 *         `percent` is how full it is, 0 to 100.
	 */
	public static function space( string $dir = ABSPATH ): ?array {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$total = @disk_total_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warns; null is the answer.
		$free  = @disk_free_space( $dir );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $total || false === $free ) {
			return null;
		}

		return self::figures( (float) $total, (float) $free );
	}

	/**
	 * Whether a folder's disk has this much free.
	 *
	 * @param int|string $required Bytes, or shorthand: `1G`.
	 * @param string     $dir      The folder.
	 *
	 * @return bool False when the host will not say.
	 */
	public static function has_space( int|string $required, string $dir = ABSPATH ): bool {
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warns; false is the answer.

		if ( false === $free ) {
			return false;
		}

		$required = is_string( $required ) ? (int) wp_convert_hr_to_bytes( $required ) : $required;

		return $free >= $required;
	}

	/**
	 * Whether the server runs Windows.
	 *
	 * @return bool
	 */
	public static function is_windows(): bool {
		return 'Windows' === PHP_OS_FAMILY;
	}

	/**
	 * Whether the server runs Linux.
	 *
	 * @return bool
	 */
	public static function is_linux(): bool {
		return 'Linux' === PHP_OS_FAMILY;
	}

	/**
	 * Whether the server runs macOS.
	 *
	 * @return bool
	 */
	public static function is_macos(): bool {
		return 'Darwin' === PHP_OS_FAMILY;
	}

	/**
	 * The four figures from a total and what is free.
	 *
	 * A disk reporting no size at all -- some network and virtual mounts
	 * do -- is 0% full rather than a division by zero.
	 *
	 * @param float $total Bytes.
	 * @param float $free  Bytes.
	 *
	 * @return array{total: float, free: float, used: float, percent: float}
	 */
	private static function figures( float $total, float $free ): array {
		$used = $total - $free;

		return [
			'total'   => $total,
			'free'    => $free,
			'used'    => $used,
			'percent' => $total > 0 ? round( ( $used / $total ) * 100, 2 ) : 0.0,
		];
	}
}
