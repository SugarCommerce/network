<?php
/**
 * An address on its way into storage.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\IP;

use SugarCommerce\Network\IP;
use PHPUnit\Framework\TestCase;

/**
 * Class SanitizeTest
 */
final class SanitizeTest extends TestCase {

	/**
	 * A whole address comes back trimmed; anything else is null.
	 */
	public function test_sanitize(): void {
		$this->assertSame( '203.0.113.9', IP::sanitize( '203.0.113.9' ) );
		$this->assertSame( '203.0.113.9', IP::sanitize( " 203.0.113.9\n" ) );
		$this->assertSame( '2001:db8::1', IP::sanitize( '2001:db8::1' ) );

		foreach ( [ '', '203.0.113', '203.0.113.9, 10.0.0.1', '203.0.113.0/24', 'localhost', hash( 'sha256', 'x' ), null, 2130706433, [ '203.0.113.9' ] ] as $value ) {
			$this->assertNull( IP::sanitize( $value ), var_export( $value, true ) );
		}
	}
}
