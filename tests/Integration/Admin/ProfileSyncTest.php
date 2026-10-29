<?php
/**
 * Testy mostu ACF do metadanych wtyczki: przenoszenie po macierzy i audyt zmiany numeru uprawnień.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ProfileSync;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;

final class ProfileSyncTest extends WP_UnitTestCase {

	private int $admin = 0;

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
		$this->admin = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		ProfileSync::register();
	}

	/**
	 * Konto z profesją.
	 *
	 * @param string $profession Klucz profesji ('' = bez profesji).
	 */
	private function account( string $profession = 'lekarz' ): int {
		$id = (int) self::factory()->user->create();
		if ( '' !== $profession ) {
			update_user_meta( $id, UserMeta::PROFESSION, $profession );
		}
		return $id;
	}

	private function logCount( int $userId ): int {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE subject_user_id = %d AND action = %s", $userId, ProfileSync::LOG_CREDENTIAL_CHANGED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	/**
	 * @return list<object>
	 */
	private function logRows( int $userId ): array {
		global $wpdb;
		$table = Migrations::table( 'event_log' );
		return array_values( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE subject_user_id = %d AND action = %s ORDER BY id ASC", $userId, ProfileSync::LOG_CREDENTIAL_CHANGED ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela wtyczki, nazwa z Migrations::table().
	}

	public function test_zapis_acf_przenosi_wartosci_do_metadanych_wtyczki(): void {
		$id = $this->account();
		update_user_meta( $id, 'pwz', '5425740' );
		update_user_meta( $id, 'miejsca', 'Szpital Miejski' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( '5425740', get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true ) );
		$this->assertSame( 'Szpital Miejski', get_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', true ) );
	}

	public function test_hook_jest_podpiety_z_priorytetem_20(): void {
		ProfileSync::register();
		$this->assertSame( 20, has_action( 'acf/save_post', array( ProfileSync::class, 'on_acf_save' ) ) );
	}

	public function test_pole_ukryte_dla_profesji_nie_jest_przenoszone(): void {
		$id = $this->account();
		update_user_meta( $id, 'tajne', 'sekret' );
		update_user_meta( $id, 'pwz', '5425740' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'tajne' ) );
		$this->assertSame( '5425740', get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true ) );
	}

	public function test_pole_ukryte_nie_zmienia_istniejacej_wartosci_z_rejestracji(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'tajne', 'stare' );
		update_user_meta( $id, 'tajne', 'nowe' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( 'stare', get_user_meta( $id, UserMeta::ACF_PREFIX . 'tajne', true ) );
	}

	public function test_pole_spoza_grupy_rejestracji_nie_jest_przenoszone(): void {
		$id = $this->account();
		// Pole, które macierz pozwala widzieć, ale nie ma go w grupie rejestracji.
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'  => 'required',
						'obce' => 'visible',
					),
				)
			)
		);
		update_user_meta( $id, 'obce', 'x' );
		update_user_meta( $id, 'nickname', 'Zmieniony' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'obce' ) );
		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'nickname' ) );
	}

	public function test_zmiana_numeru_uprawnien_trafia_do_logu_z_wartoscia_stara_i_nowa(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );
		update_user_meta( $id, 'pwz', '1234567' );

		do_action( 'acf/save_post', 'user_' . $id );

		$rows = $this->logRows( $id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->admin, (int) $rows[0]->actor_user_id );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( '5425740', $context['old'] );
		$this->assertSame( '1234567', $context['new'] );
		$this->assertSame( '1234567', get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true ) );
	}

	public function test_zapis_bez_zmiany_numeru_nie_pisze_do_logu(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );
		update_user_meta( $id, 'pwz', '5425740' );
		update_user_meta( $id, 'miejsca', 'Inne miejsce' );

		do_action( 'acf/save_post', 'user_' . $id );
		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( 0, $this->logCount( $id ) );
	}

	public function test_identyfikator_inny_niz_uzytkownik_jest_ignorowany(): void {
		$id = $this->account();
		update_user_meta( $id, 'pwz', '5425740' );

		foreach ( array( 'post_15', 'options', 42, 'user_', 'user_abc', 'user_7x', ' user_' . $id, 'user_-3' ) as $postId ) {
			do_action( 'acf/save_post', $postId );
		}
		ProfileSync::on_acf_save( null );
		ProfileSync::on_acf_save( array( 'user_' . $id ) );

		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'pwz' ) );
		$this->assertSame( 0, $this->logCount( $id ) );
	}

	public function test_wartosc_wielowartosciowa_przenosi_sie_jako_lista(): void {
		$id = $this->account();
		update_user_meta( $id, 'miejsca', array( 'Szpital', '<b>Poradnia</b>' ) );

		do_action( 'acf/save_post', 'user_' . $id );

		$stored = get_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', true );
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'Szpital', 'Poradnia' ), $stored );
	}

	public function test_wartosci_sa_sanityzowane_przy_przenoszeniu(): void {
		$id = $this->account();
		update_user_meta( $id, 'miejsca', '<script>alert(1)</script>Szpital' );

		do_action( 'acf/save_post', 'user_' . $id );

		$stored = get_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', true );
		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( '<', $stored );
		$this->assertStringNotContainsString( 'script', $stored );
	}

	public function test_konto_bez_profesji_nie_jest_synchronizowane(): void {
		$id = $this->account( '' );
		update_user_meta( $id, 'pwz', '5425740' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'pwz' ) );
		$this->assertSame( 0, $this->logCount( $id ) );
	}

	public function test_brak_nieprzedrostkowanej_metadanej_nie_nadpisuje_wartosci_z_rejestracji(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', 'Z rejestracji' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( 'Z rejestracji', get_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', true ) );
	}

	public function test_pierwsze_wpisanie_numeru_tez_jest_zmiana_i_trafia_do_logu(): void {
		$id = $this->account();
		update_user_meta( $id, 'pwz', '5425740' );

		do_action( 'acf/save_post', 'user_' . $id );

		$rows = $this->logRows( $id );
		$this->assertCount( 1, $rows );
		$context = json_decode( (string) $rows[0]->context, true );
		$this->assertIsArray( $context );
		$this->assertSame( '', $context['old'] );
		$this->assertSame( '5425740', $context['new'] );
	}

	public function test_pusta_wartosc_z_acf_nie_nadpisuje_wartosci_z_rejestracji_i_nie_loguje(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', 'Szpital' );
		update_user_meta( $id, 'pwz', '' );
		update_user_meta( $id, 'miejsca', '' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( '5425740', get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true ) );
		$this->assertSame( 'Szpital', get_user_meta( $id, UserMeta::ACF_PREFIX . 'miejsca', true ) );
		$this->assertSame( 0, $this->logCount( $id ) );
	}

	public function test_filtr_odczytu_zasila_acf_wartoscia_z_rejestracji(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );

		$this->assertSame( '5425740', apply_filters( 'acf/load_value', '', 'user_' . $id, array( 'name' => 'pwz' ) ) );
	}

	public function test_filtr_odczytu_nie_rusza_pola_ukrytego_obcego_identyfikatora_ani_wlasnej_wartosci_acf(): void {
		$id = $this->account();
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'tajne', 'sekret' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '5425740' );

		$this->assertSame( '', apply_filters( 'acf/load_value', '', 'user_' . $id, array( 'name' => 'tajne' ) ) );
		$this->assertSame( 'x', apply_filters( 'acf/load_value', 'x', 'post_' . $id, array( 'name' => 'pwz' ) ) );
		$this->assertSame( 'x', apply_filters( 'acf/load_value', 'x', 'options', array( 'name' => 'pwz' ) ) );
		$this->assertSame( 'x', apply_filters( 'acf/load_value', 'x', 'user_' . $id, array( 'label' => 'brak nazwy' ) ) );

		update_user_meta( $id, 'pwz', '1111111' );
		$this->assertSame( '1111111', apply_filters( 'acf/load_value', '1111111', 'user_' . $id, array( 'name' => 'pwz' ) ) );
	}

	public function test_profesja_usunieta_z_ustawien_przenosi_pola_ale_nie_loguje(): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( array() ) );
		$id = $this->account();
		update_user_meta( $id, 'pwz', '5425740' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertSame( '5425740', get_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', true ) );
		$this->assertSame( 0, $this->logCount( $id ) );
	}

	public function test_niedostepna_grupa_acf_nic_nie_przenosi(): void {
		remove_all_filters( 'pusers_acf_groups' );
		$id = $this->account();
		update_user_meta( $id, 'pwz', '5425740' );

		do_action( 'acf/save_post', 'user_' . $id );

		$this->assertFalse( metadata_exists( 'user', $id, UserMeta::ACF_PREFIX . 'pwz' ) );
		$this->assertSame( 0, $this->logCount( $id ) );
	}
}
