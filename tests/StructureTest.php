<?php
/**
 * Structure tests.
 *
 * Owner rule: a class over about 500 lines that does more than one job is a
 * slim class plus a trait per job (src/Traits/<Class>/<Job>.php), and
 * nothing in src/ is ever over 1,000 lines.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class StructureTest
 */
final class StructureTest extends TestCase {

	/** Never allowed. */
	private const int LIMIT = 1000;

	/** Worth a look: split it if it does more than one job. */
	private const int WARN = 500;

	public function test_no_file_in_src_is_over_the_limit(): void {
		$over = array();
		$long = array();

		foreach ( $this->files() as $path => $lines ) {
			if ( $lines > self::LIMIT ) {
				$over[] = "{$path} ({$lines} lines)";
			} elseif ( $lines > self::WARN ) {
				$long[] = "{$path} ({$lines} lines)";
			}
		}

		if ( array() !== $long ) {
			fwrite( STDERR, "\nOver " . self::WARN . " lines (one job each?):\n  " . implode( "\n  ", $long ) . "\n" );
		}

		$this->assertSame( array(), $over, 'Over ' . self::LIMIT . ' lines: split into a class and traits.' );
	}

	/**
	 * Every PHP file under src/, with its line count.
	 *
	 * @return array<string, int>
	 */
	private function files(): array {
		$src   = dirname( __DIR__ ) . '/src';
		$files = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$files[ substr( $file->getPathname(), strlen( $src ) + 1 ) ] = count( file( $file->getPathname() ) );
		}

		$this->assertNotSame( array(), $files, 'No PHP files found under src/.' );

		return $files;
	}

	/**
	 * Another package is named so the build can rename it.
	 *
	 * Strauss rewrites the namespaces of the packages a plugin bundles and
	 * nothing else. A string naming `SugarCommerce\…`, or a `use` of a package
	 * this one only suggests, is left unprefixed when that package is not
	 * bundled -- and with SugarCart active it then finds SugarCart's copy,
	 * at SugarCart's version, from inside another plugin. A package this one
	 * requires is named with `::class`; one it can do without through
	 * sugarcommerce/plugin's `Build::sibling()`.
	 *
	 * @return void
	 */
	public function test_other_packages_are_named_so_a_build_renames_them(): void {
		$root     = dirname( __DIR__ );
		$composer = (array) json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$own      = explode( '\\', (string) array_key_first( (array) ( $composer['autoload']['psr-4'] ?? [] ) ) )[1] ?? '';
		$allowed  = [ strtolower( $own ) => true ];

		foreach ( array_keys( (array) ( $composer['require'] ?? [] ) ) as $package ) {
			if ( str_starts_with( $package, 'sugarcommerce/' ) ) {
				$allowed[ str_replace( '-', '', substr( $package, 14 ) ) ] = true;
			}
		}

		$wrong = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$where = substr( $file->getPathname(), strlen( $root ) + 1 );

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}

				if ( T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( '/^.\\\\{0,2}SugarCommerce\\\\{1,2}[A-Za-z]/', $token[1] ) ) {
					$wrong[] = $where . ':' . $token[2] . ' names a class in a string: ' . $token[1];
					continue;
				}

				if ( in_array( $token[0], [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true )
					&& 1 === preg_match( '/^\\\\?SugarCommerce\\\\([A-Za-z0-9]+)/', $token[1], $match )
					&& ! isset( $allowed[ strtolower( $match[1] ) ] ) ) {
					$wrong[] = $where . ':' . $token[2] . ' names ' . $token[1] . ', a package composer.json does not require: ask Build::sibling()';
				}
			}
		}

		sort( $wrong );

		$this->assertSame( [], $wrong );
	}
}
