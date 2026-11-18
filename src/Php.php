<?php
/**
 * PHP
 *
 * What the PHP the site runs on allows: its version, limits, extensions
 * and functions.
 *
 * @package SugarCommerce\Network
 * @since   1.0.0
 * @author  ArrayPress
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Network;

/**
 * Php Class
 *
 * These decide whether a plugin refuses to activate, or warns before an
 * import or an upload, so a wrong answer is a plugin that will not run
 * where it would have been fine, or one that starts what it cannot
 * finish. Limits come back in bytes, never the `256M` shorthand the ini
 * file holds: compared as a number, `'256M'` is 256, smaller than every
 * real limit.
 *
 * @since 1.0.0
 */
final class Php {

	/**
	 * The PHP version: `8.3.12`.
	 *
	 * @return string
	 */
	public static function version(): string {
		return PHP_VERSION;
	}

	/**
	 * Whether PHP is at least a version, compared as a version (8.10 is
	 * after 8.9).
	 *
	 * @param string $required The lowest version that will do.
	 *
	 * @return bool
	 */
	public static function meets_version( string $required ): bool {
		return version_compare( PHP_VERSION, $required, '>=' );
	}

	/**
	 * The memory limit, in bytes.
	 *
	 * @return int -1 for no limit.
	 */
	public static function memory_limit(): int {
		return self::bytes( (string) ini_get( 'memory_limit' ) );
	}

	/**
	 * The most seconds a script may run.
	 *
	 * @return int 0 for no limit, which is what the command line has.
	 */
	public static function max_execution_time(): int {
		return (int) ini_get( 'max_execution_time' );
	}

	/**
	 * The largest file PHP accepts in an upload, in bytes.
	 *
	 * @return int
	 */
	public static function upload_max_filesize(): int {
		return self::bytes( (string) ini_get( 'upload_max_filesize' ) );
	}

	/**
	 * The largest request body PHP accepts, in bytes.
	 *
	 * @return int 0 for no limit.
	 */
	public static function post_max_size(): int {
		return self::bytes( (string) ini_get( 'post_max_size' ) );
	}

	/**
	 * The most input variables PHP reads from one request.
	 *
	 * A settings form with more fields than this loses the rest, silently.
	 *
	 * @return int
	 */
	public static function max_input_vars(): int {
		return (int) ini_get( 'max_input_vars' );
	}

	/**
	 * Whether an extension is loaded: `curl`, `intl`.
	 *
	 * @param string $extension The extension.
	 *
	 * @return bool
	 */
	public static function has_extension( string $extension ): bool {
		return extension_loaded( $extension );
	}

	/**
	 * The extensions from a list that are not loaded, in the order asked.
	 *
	 * @param array<int, string> $extensions The extensions needed.
	 *
	 * @return array<int, string> Empty when all are there.
	 */
	public static function missing_extensions( array $extensions ): array {
		return array_values( array_filter( $extensions, static fn( $extension ): bool => ! self::has_extension( (string) $extension ) ) );
	}

	/**
	 * Whether a function exists and is not switched off in
	 * `disable_functions` -- hosts commonly switch off `exec`,
	 * `set_time_limit` and `proc_open`.
	 *
	 * @param string $name The function.
	 *
	 * @return bool
	 */
	public static function has_function( string $name ): bool {
		if ( ! function_exists( $name ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );

		return ! in_array( strtolower( $name ), $disabled, true );
	}

	/**
	 * Whether PHP accepts file uploads at all.
	 *
	 * @return bool
	 */
	public static function uploads_enabled(): bool {
		return filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Whether `allow_url_fopen` is on.
	 *
	 * @return bool
	 */
	public static function url_fopen_enabled(): bool {
		return filter_var( ini_get( 'allow_url_fopen' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Whether this much memory is still free under the limit.
	 *
	 * @param int|string $required Bytes, or shorthand: `64M`.
	 *
	 * @return bool True when there is no limit.
	 */
	public static function has_memory( int|string $required ): bool {
		$limit = self::memory_limit();

		if ( -1 === $limit ) {
			return true;
		}

		$required = is_string( $required ) ? self::bytes( $required ) : $required;

		return $limit - memory_get_usage( true ) >= $required;
	}

	/**
	 * Bytes from an ini value, as WordPress reads them.
	 *
	 * @param string $value `128M`, `1G`, `-1`.
	 *
	 * @return int
	 */
	private static function bytes( string $value ): int {
		return (int) wp_convert_hr_to_bytes( $value );
	}
}
