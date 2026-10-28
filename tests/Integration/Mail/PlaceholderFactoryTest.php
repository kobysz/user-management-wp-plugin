<?php
/**
 * Testy zmiennych szablonu maila budowanych z konta.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Mail;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Mail\PlaceholderFactory;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class PlaceholderFactoryTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SettingsRepository::save( Settings::fromArray( array() ) );
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'numer_pwz'     => 'required',
						'miejsce_pracy' => 'visible',
						'uwagi'         => 'visible',
						'sekret'        => 'hidden',
					),
				)
			)
		);
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'numer_pwz',
					),
					array(
						'key'   => 'pielegniarka',
						'label' => 'Pielęgniarka',
					),
				)
			)
		);
	}

	private function account( string $profession = 'lekarz', string $pwz = '5425740' ): int {
		$id = (int) self::factory()->user->create(
			array(
				'user_login'   => 'jan.kowalski',
				'user_email'   => 'jan@szpital.pl',
				'display_name' => 'Jan Kowalski',
			)
		);
		update_user_meta( $id, UserMeta::PROFESSION, $profession );
		if ( '' !== $pwz ) {
			update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'numer_pwz', $pwz );
		}
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'miejsce_pracy', 'Szpital Wojewódzki' );
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'uwagi', "Pierwsza linia\n  Druga linia" );
		update_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . 'sekret', 'TAJNE-DANE' );
		return $id;
	}

	public function test_account_and_site_variables_are_present(): void {
		$id     = $this->account();
		$values = PlaceholderFactory::for_user( $id );

		$this->assertSame( 'jan@szpital.pl', $values->get( 'email' ) );
		$this->assertSame( 'jan.kowalski', $values->get( 'login' ) );
		$this->assertSame( 'Jan Kowalski', $values->get( 'nazwa_konta' ) );
		$this->assertSame( 'Lekarz', $values->get( 'profesja' ) );
		$this->assertSame( home_url( '/' ), $values->get( 'adres_portalu' ) );
		$this->assertSame( home_url( '/' ), $values->get( 'link_logowania' ), 'Bez strony logowania w ustawieniach: strona główna.' );
		$this->assertSame( admin_url( 'user-edit.php?user_id=' . $id ), $values->get( 'link_wniosku' ) );
		$this->assertSame( wp_lostpassword_url(), $values->get( 'link_odzyskiwania' ) );
	}

	public function test_a_registration_field_is_available_as_pole_prefix(): void {
		// „a także dowolne pole rejestracji" — bez tego redakcja nie postawi w mailu numeru telefonu.
		$values = PlaceholderFactory::for_user( $this->account() );

		$this->assertSame( 'Szpital Wojewódzki', $values->get( 'pole_miejsce_pracy' ) );
		$this->assertSame( 'Pierwsza linia Druga linia', $values->get( 'pole_uwagi' ), 'Wartość w jednej linii.' );
		$this->assertSame(
			"- miejsce_pracy: Szpital Wojewódzki\n- numer_pwz: 5425740\n- uwagi: Pierwsza linia Druga linia",
			$values->get( 'pola_zgloszenia' ),
			'Lista posortowana po kluczu.'
		);
	}

	public function test_a_field_hidden_for_the_profession_is_not_exposed(): void {
		// Pole ukryte dla profesji nie należy do tego zgłoszenia; w mailu byłoby śmieciem albo wyciekiem.
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'numer_pwz'     => 'hidden',
						'miejsce_pracy' => 'visible',
					),
				)
			)
		);
		$values = PlaceholderFactory::for_user( $this->account() );

		$this->assertFalse( $values->has( 'pole_numer_pwz' ) );
		$this->assertFalse( $values->has( 'pole_sekret' ) );
		$this->assertStringNotContainsString( 'TAJNE-DANE', $values->get( 'pola_zgloszenia' ) );
		$this->assertStringNotContainsString( 'numer_pwz', $values->get( 'pola_zgloszenia' ) );
	}

	public function test_the_credential_number_ignores_the_matrix(): void {
		// Numer jest podstawą decyzji redakcji, a nie polem formularza: ukrycie pola go nie ukrywa.
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'numer_pwz' => 'hidden' ) ) ) );
		$values = PlaceholderFactory::for_user( $this->account() );

		$this->assertSame( '5425740', $values->get( 'numer_uprawnien' ) );
		$this->assertSame( 'poprawny', $values->get( 'wynik_walidacji' ) );
	}

	public function test_the_credential_result_is_recomputed_not_read(): void {
		// Walidator mógł się zmienić po rejestracji, więc wyniku zapisanego wtedy nie wolno czytać.
		$id = $this->account( 'lekarz', '1234567' );

		$this->assertSame( 'niepoprawny', PlaceholderFactory::for_user( $id )->get( 'wynik_walidacji' ) );
	}

	public function test_a_profession_without_a_credential_says_the_verdict_does_not_apply(): void {
		// Werdykt nie może zwijać dwóch różnych faktów do pustki: redakcja czyta go w mailu i musi
		// widzieć różnicę między „ta profesja numeru nie ma" a „numeru nie podano".
		$values = PlaceholderFactory::for_user( $this->account( 'pielegniarka' ) );

		$this->assertSame( '', $values->get( 'numer_uprawnien' ) );
		$this->assertSame( 'nie dotyczy', $values->get( 'wynik_walidacji' ) );
		$this->assertSame( 'Pielęgniarka', $values->get( 'profesja' ) );
	}

	public function test_a_profession_with_a_credential_but_no_stored_number_says_the_number_is_missing(): void {
		// To jest wniosek, który redakcja musi odrzucić albo dopytać: inny przypadek niż brak numeru w profesji.
		$values = PlaceholderFactory::for_user( $this->account( 'lekarz', '' ) );

		$this->assertSame( '', $values->get( 'numer_uprawnien' ) );
		$this->assertSame( 'brak numeru', $values->get( 'wynik_walidacji' ) );
	}

	public function test_a_withdrawn_profession_falls_back_to_its_key(): void {
		$values = PlaceholderFactory::for_user( $this->account( 'wycofana', '' ) );

		$this->assertSame( 'wycofana', $values->get( 'profesja' ) );
		$this->assertSame( '', $values->get( 'numer_uprawnien' ) );
		$this->assertSame( 'nie dotyczy', $values->get( 'wynik_walidacji' ), 'Po wycofaniu profesji nie wiadomo już, czego konto miało dowodzić.' );
	}

	public function test_extras_override_and_add(): void {
		$values = PlaceholderFactory::for_user(
			$this->account(),
			array(
				'powod_odrzucenia' => 'numer nieprawidłowy',
				'email'            => 'inny@szpital.pl',
			)
		);

		$this->assertSame( 'numer nieprawidłowy', $values->get( 'powod_odrzucenia' ) );
		$this->assertSame( 'inny@szpital.pl', $values->get( 'email' ), 'Wartość od wołającego wygrywa z wyliczoną.' );
	}

	public function test_a_missing_account_still_yields_site_variables(): void {
		// Konto usunięte cronem między zakolejkowaniem a renderem nie może wywrócić wysyłki.
		$values = PlaceholderFactory::for_user( 999999 );

		$this->assertNotSame( '', $values->get( 'nazwa_portalu' ) );
		$this->assertSame( '', $values->get( 'email' ) );
		$this->assertSame( '', $values->get( 'profesja' ) );
	}

	public function test_the_site_name_arrives_decoded(): void {
		update_option( 'blogname', 'Portal "Zdrowie" & Medycyna' );

		$this->assertSame( 'Portal "Zdrowie" & Medycyna', PlaceholderFactory::for_user( $this->account() )->get( 'nazwa_portalu' ) );
	}

	public function test_every_variable_of_every_kind_is_resolved_or_caller_supplied(): void {
		// Zmienna, której nic nie rozwiązuje, dotarłaby do lekarza jako dosłowne klamry.
		$callerSupplied = array( 'powod_odrzucenia', 'link_potwierdzenia', 'link_hasla' );
		$values         = PlaceholderFactory::for_user( $this->account() );

		foreach ( MailKind::cases() as $kind ) {
			foreach ( $kind->variables() as $name ) {
				if ( in_array( $name, $callerSupplied, true ) ) {
					continue;
				}
				$this->assertTrue( $values->has( $name ), $kind->value . ': ' . $name );
			}
		}
	}
}
