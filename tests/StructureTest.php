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
}
