<?php
/**
 * Testy eksportera danych osobowych: grupy, stronicowanie i to, czego eksport nie wydaje.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Privacy;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;
use PortalUsers\Privacy\PrivacyProvider;
use WP_UnitTestCase;
use WP_User;

final class PrivacyProviderExportTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
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
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array(
					'name'  => 'pwz',
					'label' => 'Numer PWZ',
				),
				array(
					'name'  => 'zainteresowania',
					'label' => 'Zainteresowania',
				),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
	}

	/**
	 * Konto z kompletem metadanych wtyczki.
	 */
	private function account(): WP_User {
		$id = (int) self::factory()->user->create( array( 'user_email' => 'lekarz@example.com' ) );
		update_user_meta( $id, UserMeta::STATUS, 'pending' );
		update_user_meta( $id, UserMeta::PROFESSION, 'lekarz' );
		update_user_meta( $id, UserMeta::CONSENT_AT, '2026-10-01 10:00:00' );
		update_user_meta( $id, UserMeta::TERMS_VERSION, 'v3' );
		update_user_meta( $id, UserMeta::REGISTRATION_IP, '203.0.113.7' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'pwz', '1234567' );
		update_user_meta( $id, UserMeta::ACF_PREFIX . 'zainteresowania', 'kardiologia' );
		return get_userdata( $id );
	}

	/**
	 * Wstawia mail do kolejki konta.
	 */
	private function mail( int $userId, string $kind, string $subject, string $body, string $key ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		MailQueueRepository::insert(
			array(
				'created_at'   => $now,
				'scheduled_at' => $now,
				'kind'         => $kind,
				'dedupe_key'   => $key,
				'user_id'      => $userId,
				'recipient'    => 'lekarz@example.com',
				'subject'      => $subject,
				'body'         => $body,
			)
		);
	}

	/**
	 * Grupy eksportu o danym identyfikatorze.
	 *
	 * @param array<int, array<string, mixed>> $data    Wpisy eksportu.
	 * @param string                           $groupId Identyfikator grupy.
	 * @return array<int, array<string, mixed>>
	 */
	private function groups( array $data, string $groupId ): array {
		return array_values( array_filter( $data, static fn ( array $g ): bool => $groupId === $g['group_id'] ) );
	}

	/**
	 * Pary nazwa => wartość jednej grupy.
	 *
	 * @param array<string, mixed> $group Grupa.
	 * @return array<string, string>
	 */
	private function pairs( array $group ): array {
		$out = array();
		foreach ( $group['data'] as $pair ) {
			$out[ (string) $pair['name'] ] = (string) $pair['value'];
		}
		return $out;
	}

	public function test_eksporter_jest_zarejestrowany_pod_wlasnym_kluczem(): void {
		PrivacyProvider::register();
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( PrivacyProvider::SLUG, $exporters );
		$this->assertNotSame( '', $exporters[ PrivacyProvider::SLUG ]['exporter_friendly_name'] );
		$this->assertIsCallable( $exporters[ PrivacyProvider::SLUG ]['callback'] );
	}

	public function test_eksport_niesie_stan_profesje_i_numer_z_werdyktem(): void {
		$user   = $this->account();
		$result = PrivacyProvider::export( 'lekarz@example.com' );

		$groups = $this->groups( $result['data'], 'pusers-account' );
		$this->assertCount( 1, $groups );
		$pairs = $this->pairs( $groups[0] );
		$this->assertContains( 'pending', $pairs );
		$this->assertContains( 'Lekarz', $pairs );
		$this->assertContains( $user->user_registered, $pairs );
		$this->assertContains( '203.0.113.7', $pairs );
		$number = array_values( array_filter( $pairs, static fn ( string $v ): bool => str_starts_with( $v, '1234567' ) ) );
		$this->assertCount( 1, $number );
		$this->assertNotSame( '1234567', $number[0], 'Numer niesie werdykt obok siebie.' );
	}

	public function test_eksport_niesie_znacznik_zgody_i_wersje_regulaminu(): void {
		$this->account();
		$result = PrivacyProvider::export( 'lekarz@example.com' );

		$groups = $this->groups( $result['data'], 'pusers-consent' );
		$this->assertCount( 1, $groups );
		$pairs = $this->pairs( $groups[0] );
		$this->assertContains( '2026-10-01 10:00:00', $pairs );
		$this->assertContains( 'v3', $pairs );
	}

	public function test_eksport_niesie_wszystkie_pola_zgloszenia_takze_ukryte_dla_profesji(): void {
		$user = $this->account();
		// Macierz ukrywa „zainteresowania" dla lekarza: panel ich już nie pokazuje, ale są zapisane.
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz' => array(
						'pwz'             => 'required',
						'zainteresowania' => 'hidden',
					),
				)
			)
		);
		update_user_meta( $user->ID, UserMeta::ACF_PREFIX . 'nieznane_pole', 'wartosc-bez-etykiety' );

		$result = PrivacyProvider::export( 'lekarz@example.com' );

		$groups = $this->groups( $result['data'], 'pusers-fields' );
		$this->assertCount( 1, $groups );
		$pairs = $this->pairs( $groups[0] );
		$this->assertSame( 'kardiologia', $pairs['Zainteresowania'] ?? null, 'Pole ukryte w macierzy dalej wychodzi, z etykietą z grupy ACF.' );
		$this->assertSame( '1234567', $pairs['Numer PWZ'] ?? null );
		$this->assertSame( 'wartosc-bez-etykiety', $pairs['nieznane_pole'] ?? null, 'Pole bez etykiety wychodzi pod nazwą metadanej.' );
	}

	public function test_eksport_niesie_wiersze_logu_zdarzen(): void {
		$user = $this->account();
		EventLog::write( 'application_approved', $user->ID, 1, array( 'powod' => 'komplet' ) );

		$result = PrivacyProvider::export( 'lekarz@example.com' );

		$groups = $this->groups( $result['data'], 'pusers-event-log' );
		$this->assertCount( 1, $groups );
		$this->assertStringContainsString( 'application_approved', (string) wp_json_encode( $groups[0] ) );
		$this->assertStringContainsString( 'komplet', (string) wp_json_encode( $groups[0] ) );
	}

	public function test_eksport_niesie_metadane_maili_bez_tresci(): void {
		$user = $this->account();
		$this->mail( $user->ID, 'confirmation', 'Potwierdź adres', 'Kliknij: https://example.com/?token=TOKEN-ZYWY-48H', 'k1' );

		$result = PrivacyProvider::export( 'lekarz@example.com' );

		$dump = serialize( $result );
		$this->assertCount( 1, $this->groups( $result['data'], 'pusers-mail-queue' ) );
		$this->assertStringContainsString( 'confirmation', $dump );
		$this->assertStringContainsString( 'Potwierdź adres', $dump );
		$this->assertStringNotContainsString( 'TOKEN-ZYWY-48H', $dump, 'Treść maila (token potwierdzenia) nie wychodzi w eksporcie.' );
	}

	public function test_adres_bez_konta_daje_puste_dane_i_done(): void {
		$result = PrivacyProvider::export( 'nikt@example.com' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_stronicowanie_konczy_sie_done_gdy_obie_kolekcje_sie_wyczerpia(): void {
		$user = $this->account();
		for ( $i = 0; $i <= PrivacyProvider::PER_PAGE; $i++ ) {
			EventLog::write( 'zdarzenie_' . $i, $user->ID, 1, array() );
		}

		$first  = PrivacyProvider::export( 'lekarz@example.com', 1 );
		$second = PrivacyProvider::export( 'lekarz@example.com', 2 );

		$this->assertFalse( $first['done'] );
		$this->assertTrue( $second['done'] );

		$ids = fn ( array $result ): array => array_column( $this->groups( $result['data'], 'pusers-event-log' ), 'item_id' );
		$this->assertCount( PrivacyProvider::PER_PAGE, $ids( $first ) );
		$this->assertCount( 1, $ids( $second ) );
		$this->assertSame( array(), array_intersect( $ids( $first ), $ids( $second ) ), 'Strona druga niesie wiersz, którego nie było na pierwszej.' );
	}

	public function test_done_czeka_na_wyczerpanie_maili_gdy_log_juz_sie_skonczyl(): void {
		$user = $this->account();
		for ( $i = 0; $i <= PrivacyProvider::PER_PAGE; $i++ ) {
			$this->mail( $user->ID, 'confirmation', 'Temat ' . $i, 'x', 'k' . $i );
		}

		$first  = PrivacyProvider::export( 'lekarz@example.com', 1 );
		$second = PrivacyProvider::export( 'lekarz@example.com', 2 );

		$this->assertFalse( $first['done'], 'Log jest krótki, ale maile się nie skończyły.' );
		$this->assertTrue( $second['done'] );
	}

	public function test_grupy_stale_wychodza_tylko_na_pierwszej_stronie(): void {
		$user = $this->account();
		for ( $i = 0; $i <= PrivacyProvider::PER_PAGE; $i++ ) {
			EventLog::write( 'zdarzenie_' . $i, $user->ID, 1, array() );
		}

		$first  = PrivacyProvider::export( 'lekarz@example.com', 1 );
		$second = PrivacyProvider::export( 'lekarz@example.com', 2 );

		foreach ( array( 'pusers-account', 'pusers-consent', 'pusers-fields' ) as $groupId ) {
			$this->assertCount( 1, $this->groups( $first['data'], $groupId ), $groupId . ' na stronie pierwszej' );
			$this->assertCount( 0, $this->groups( $second['data'], $groupId ), $groupId . ' na stronie drugiej' );
		}
	}
}
