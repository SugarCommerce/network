<?php
/**
 * Classification: role addresses, providers, institutions, typos.
 *
 * These are the methods edd-tax-exemption grants exemptions from and
 * edd-fraud-filter scores on, so a wrong answer has consequences beyond a
 * failing assertion.
 *
 * @package SugarCommerce\Network
 */

declare( strict_types=1 );

namespace SugarCommerce\Network\Tests\Email;

use SugarCommerce\Network\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Class DetectionTest
 */
final class DetectionTest extends TestCase {

	/**
	 * Role-based local parts are recognised.
	 *
	 * @param string $address The address.
	 * @param bool   $expected Whether it is role-based.
	 */
	#[DataProvider( 'roleAddresses' )]
	public function test_role_addresses( string $address, bool $expected ): void {
		$this->assertSame( $expected, Email::parse( $address )->is_role_based() );
	}

	/**
	 * Addresses that are and are not role-based.
	 *
	 * @return array
	 */
	public static function roleAddresses(): array {
		return [
			'admin'          => [ 'admin@example.com', true ],
			'info'           => [ 'info@example.com', true ],
			'support'        => [ 'support@example.com', true ],
			'sales'          => [ 'sales@example.com', true ],
			'a person'       => [ 'dave@example.com', false ],
			'role with tag'  => [ 'admin+x@example.com', true ],
			'role substring' => [ 'administrator.jones@example.com', false ],
		];
	}

	/**
	 * Educational, government and military addresses.
	 *
	 * edd-tax-exemption grants exemptions on exactly these three.
	 *
	 * @param string $address The address.
	 * @param string $method  The method to call.
	 * @param bool   $expected The expected answer.
	 */
	#[DataProvider( 'institutions' )]
	public function test_institutional_addresses( string $address, string $method, bool $expected ): void {
		$this->assertSame( $expected, Email::parse( $address )->$method() );
	}

	/**
	 * Institutional addresses and their classification.
	 *
	 * @return array
	 */
	public static function institutions(): array {
		return [
			'us university'     => [ 'student@harvard.edu', 'is_educational', true ],
			'uk university'     => [ 'student@ox.ac.uk', 'is_educational', true ],
			'not educational'   => [ 'dave@gmail.com', 'is_educational', false ],
			'us government'     => [ 'clerk@irs.gov', 'is_government', true ],
			'uk government'     => [ 'clerk@hmrc.gov.uk', 'is_government', true ],
			'not government'    => [ 'dave@gmail.com', 'is_government', false ],
			'us military'       => [ 'soldier@army.mil', 'is_military', true ],
			'not military'      => [ 'dave@gmail.com', 'is_military', false ],
		];
	}

	/**
	 * A commercial lookalike is not an institution.
	 *
	 * "edu.com" is a business. Granting a tax exemption on a substring match
	 * would be a costly kind of wrong.
	 */
	public function test_lookalike_domains_are_not_institutions(): void {
		$this->assertFalse( Email::parse( 'dave@edu.com' )->is_educational() );
		$this->assertFalse( Email::parse( 'dave@notgov.com' )->is_government() );
		$this->assertFalse( Email::parse( 'dave@mil.com' )->is_military() );
	}

	/**
	 * Common consumer providers are recognised.
	 */
	public function test_common_providers(): void {
		$this->assertTrue( Email::parse( 'dave@gmail.com' )->is_common_provider() );
		$this->assertTrue( Email::parse( 'dave@outlook.com' )->is_common_provider() );
		$this->assertFalse( Email::parse( 'dave@arraypress.com' )->is_common_provider() );
	}

	/**
	 * Likely typos of common providers are flagged, with a suggestion.
	 */
	public function test_typo_detection_suggests_a_correction(): void {
		$email = Email::parse( 'dave@gmial.com' );

		$this->assertTrue( $email->has_typo() );
		$this->assertSame( 'gmail.com', $email->suggested_domain() );
		$this->assertSame( 'dave@gmail.com', $email->suggested_email() );
	}

	/**
	 * A correctly spelled domain is not flagged.
	 */
	public function test_a_correct_domain_is_not_a_typo(): void {
		$email = Email::parse( 'dave@gmail.com' );

		$this->assertFalse( $email->has_typo() );
		$this->assertNull( $email->suggested_domain() );
	}

	/**
	 * An unrelated domain is not "corrected" into something else.
	 *
	 * Suggesting a correction for a perfectly good company domain is worse
	 * than suggesting nothing.
	 */
	public function test_an_unrelated_domain_is_left_alone(): void {
		$this->assertFalse( Email::parse( 'dave@arraypress.com' )->has_typo() );
	}

	/**
	 * Providers that support subaddressing are known.
	 */
	public function test_subaddressing_support_is_known(): void {
		$this->assertTrue( Email::parse( 'dave@gmail.com' )->supports_subaddressing() );
	}

	/**
	 * The settings-field helpers return usable data.
	 *
	 * These came across from the older wp-email-utils, which existed to feed
	 * admin select fields.
	 */
	public function test_the_settings_helpers_return_options(): void {
		$plain = Email::get_role_prefixes();
		$this->assertNotEmpty( $plain );
		$this->assertContains( 'admin', $plain );

		$options = Email::get_role_prefixes( true );
		$this->assertArrayHasKey( 'value', $options[0] );
		$this->assertArrayHasKey( 'label', $options[0] );

		$providers = Email::get_common_providers();
		$this->assertContains( 'gmail.com', $providers );
	}

	/**
	 * The display form separates the parts.
	 */
	public function test_the_formatted_form(): void {
		$this->assertSame( 'dave @ example.com', Email::parse( 'dave@example.com' )->get_formatted() );
	}
}
