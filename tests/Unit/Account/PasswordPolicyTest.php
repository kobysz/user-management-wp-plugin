<?php
/**
 * Testy reguł hasła.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use PortalUsers\Domain\Account\PasswordPolicy;

final class PasswordPolicyTest extends TestCase {

	public function test_a_password_shorter_than_the_minimum_is_refused(): void {
		$this->assertSame( PasswordPolicy::ERROR_SHORT, PasswordPolicy::check( str_repeat( 'a', 11 ), 'jan@szpital.pl' ) );
		$this->assertSame( '', PasswordPolicy::check( str_repeat( 'a', 12 ), 'jan@szpital.pl' ) );
	}

	public function test_a_password_equal_to_the_email_is_refused_regardless_of_letter_case(): void {
		// Hasło równe adresowi zna każdy, kto widział zgłoszenie; `Jan@Szpital.pl` to to samo hasło.
		$this->assertSame( PasswordPolicy::ERROR_EMAIL, PasswordPolicy::check( 'jan.nowak@szpital.pl', 'Jan.Nowak@Szpital.PL' ) );
	}

	public function test_length_is_counted_in_characters_not_bytes(): void {
		// 12 znaków polskich to więcej niż 12 bajtów; `strlen()` wpuściłoby krótsze hasło.
		$this->assertSame( '', PasswordPolicy::check( 'ąćęłńóśźżąćę', 'jan@szpital.pl' ) );
		$this->assertSame( PasswordPolicy::ERROR_SHORT, PasswordPolicy::check( 'ąćęłńóśźżąć', 'jan@szpital.pl' ) );
	}

	public function test_length_is_checked_before_equality_with_the_email(): void {
		// Krótki adres równy hasłu: obowiązuje pierwsza bramka, jak dotąd w `PasswordSetup`.
		$this->assertSame( PasswordPolicy::ERROR_SHORT, PasswordPolicy::check( 'a@b.pl', 'a@b.pl' ) );
	}
}
