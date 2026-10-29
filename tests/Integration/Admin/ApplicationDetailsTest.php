<?php
/**
 * Testy panelu szczegółów wniosku: pola z macierzy, zgoda, IP, powód odrzucenia i brak pól edycyjnych.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ApplicationDetails;
use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\Menu;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ApplicationRepository;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class ApplicationDetailsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'              => 'lekarz',
						'label'            => 'Lekarz',
						'credential_type'  => 'physician_pwz',
						'credential_field' => 'pwz',
					),
				)
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'     => 'required',
						'miejsca' => 'visible',
						'tajne'   => 'hidden',
					),
				)
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		$this->withAcf();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Udostępnia grupę ACF z trzema polami.
	 */
	private function withAcf(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'Numer PWZ',
				),
				array(
					'name'  => 'miejsca',
					'label' => 'Miejsca pracy',
				),
				array(
					'name'  => 'tajne',
					'label' => 'Pole tajne',
				),
			)
		);
	}

	/**
	 * Konto lekarza w podanym stanie.
	 *
	 * @param string $status Surowy stan.
	 */
	private function account( string $status = 'pending' ): int {
		$id = (int) self::factory()->user->create();
		update_user_meta( $id, UserMeta::STATUS, $status );
		update_user_meta( $id, UserMeta::PROFESSION, 'lekarz' );
		return $id;
	}

	/**
	 * Panel dla konta.
	 *
	 * @param int $id Identyfikator konta.
	 */
	private function panel( int $id ): string {
		$row = ApplicationRepository::one( $id );
		$this->assertNotNull( $row );
		ob_start();
		try {
			ApplicationDetails::render( $row );
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Ekran wniosków wołany z argumentem użytkownika.
	 *
	 * @param string $user Wartość argumentu.
	 */
	private function screen( string $user ): string {
		$_GET = array( ApplicationsScreen::ARG_USER => $user );
		ob_start();
		try {
			ApplicationsScreen::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	public function test_pokazuje_pola_widoczne_dla_profesji_z_etykietami_acf(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', 'Szpital Miejski' );

		$html = $this->panel( $id );

		$this->assertStringContainsString( 'Numer PWZ', $html );
		$this->assertStringContainsString( '5425740', $html );
		$this->assertStringContainsString( 'Miejsca pracy', $html );
		$this->assertStringContainsString( 'Szpital Miejski', $html );
	}

	public function test_nie_pokazuje_pola_ukrytego_dla_profesji_nawet_gdy_metadana_istnieje(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'tajne', 'WARTOSC-UKRYTEGO-POLA' );

		$html = $this->panel( $id );

		$this->assertStringContainsString( '5425740', $html, 'Pole widoczne ma być w panelu.' );
		$this->assertStringNotContainsString( 'WARTOSC-UKRYTEGO-POLA', $html );
		$this->assertStringNotContainsString( 'Pole tajne', $html );
	}

	public function test_pole_wielowartosciowe_schodzi_do_czytelnej_listy(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', array( 'Szpital', 'Przychodnia' ) );

		$html = $this->panel( $id );

		$this->assertStringContainsString( 'Szpital, Przychodnia', $html );
		$this->assertStringNotContainsString( 'a:2:{', $html );
	}

	public function test_pokazuje_znacznik_czasu_zgody_i_wersje_regulaminu(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::CONSENT_AT, '2026-10-01 10:30:00' );
		update_user_meta( $id, UserMeta::TERMS_VERSION, 'hash-regulaminu-abc' );

		$html = $this->panel( $id );

		$this->assertStringContainsString( get_date_from_gmt( '2026-10-01 10:30:00', 'Y-m-d H:i' ), $html );
		$this->assertStringContainsString( 'hash-regulaminu-abc', $html );
	}

	public function test_pokazuje_adres_ip_rejestracji(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::REGISTRATION_IP, '203.0.113.7' );

		$this->assertStringContainsString( '203.0.113.7', $this->panel( $id ) );
	}

	public function test_brak_zgody_i_brak_ip_nie_daja_pustych_wierszy_bez_wyjasnienia(): void {
		$html = $this->panel( $this->account() );

		// Trzy wiersze: wersja regulaminu, zgoda i IP; każdy musi mówić, że niczego nie zapisano.
		$this->assertSame( 3, substr_count( $html, 'nie zapisano' ) );
		$this->assertDoesNotMatchRegularExpression( '#<td>\s*</td>#', $html );
	}

	public function test_pokazuje_powod_odrzucenia_dla_konta_odrzuconego(): void {
		$id = $this->account( 'rejected' );
		update_user_meta( $id, UserMeta::REJECTION_REASON, 'Numer nie figuruje w rejestrze' );

		$this->assertStringContainsString( 'Numer nie figuruje w rejestrze', $this->panel( $id ) );
	}

	public function test_powod_odrzucenia_nie_pojawia_sie_dla_konta_oczekujacego(): void {
		$id = $this->account( 'pending' );
		update_user_meta( $id, UserMeta::REJECTION_REASON, 'Stary powod' );

		$this->assertStringNotContainsString( 'Stary powod', $this->panel( $id ) );
	}

	public function test_panel_nie_zawiera_pola_edycyjnego(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );

		$html = $this->panel( $id );

		$this->assertStringContainsString( '5425740', $html );
		$this->assertDoesNotMatchRegularExpression( '/<(input|textarea|select|form)\b/i', $html );
	}

	public function test_odnosnik_do_standardowej_edycji_uzytkownika_jest_obecny(): void {
		$id = $this->account();

		$this->assertStringContainsString( 'user-edit.php?user_id=' . $id, $this->panel( $id ) );
	}

	public function test_bez_acf_panel_mowi_ze_pol_nie_pokaze(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', 'Szpital Miejski' );

		$html = $this->panel( $id );

		$this->assertStringContainsString( 'grupa pól ACF jest niedostępna', $html );
		$this->assertStringNotContainsString( 'Szpital Miejski', $html );
	}

	public function test_ekran_bez_takiego_wniosku_daje_komunikat_i_powrot_do_listy(): void {
		$html = $this->screen( '999999' );

		$this->assertStringContainsString( 'Nie ma takiego wniosku', $html );
		$this->assertStringContainsString( 'page=' . Menu::SLUG_APPLICATIONS, $html );
	}

	public function test_ekran_z_argumentem_uzytkownika_pokazuje_szczegoly(): void {
		$id = $this->account();

		$this->assertStringContainsString( 'user-edit.php?user_id=' . $id, $this->screen( (string) $id ) );
	}
}
