<?php
/**
 * Testy: odczyt faktów o stanie konfiguracji dla listy startowej.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Account\Backfill;
use PortalUsers\Admin\SetupFactsReader;
use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\OptionStore;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

final class SetupFactsReaderTest extends WP_UnitTestCase {

	/**
	 * Rola domyślna sprzed testu.
	 *
	 * @var string
	 */
	private string $originalRole = 'subscriber';

	/**
	 * Moment wdrożenia: pięć dni temu.
	 *
	 * @var int
	 */
	private int $deployed = 0;

	public function set_up(): void {
		parent::set_up();
		$this->originalRole = (string) get_option( 'default_role' );
		$this->deployed     = time() - 5 * DAY_IN_SECONDS;
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		delete_option( OptionStore::OPTION_DEPLOYED_AT );
		Backfill::forget_count();
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( 'pusers_acf_pro_loaded' );
		update_option( 'default_role', $this->originalRole );
		Backfill::forget_count();
		parent::tear_down();
	}

	/**
	 * Konfiguruje grupę ACF z podanymi polami i wskazuje ją w ustawieniach.
	 *
	 * @param array<string, string> $fields Pola źródła (nazwa => etykieta).
	 */
	private function withSource( array $fields ): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static function () use ( $fields ): array {
				$out = array();
				foreach ( $fields as $name => $label ) {
					$out[] = array(
						'name'  => $name,
						'label' => $label,
						'type'  => 'text',
					);
				}
				return $out;
			}
		);
		$this->withSettings( array( 'acf_group_key' => 'group_abc' ) );
	}

	/**
	 * Zapisuje ustawienia, zachowując to, co już zapisane.
	 *
	 * @param array<string, mixed> $values Wartości do nadpisania.
	 */
	private function withSettings( array $values ): void {
		SettingsRepository::save( Settings::fromArray( array_merge( SettingsRepository::get()->toArray(), $values ) ) );
	}

	/**
	 * Tworzy opublikowaną stronę i zwraca jej identyfikator.
	 */
	private function page( string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
			)
		);
	}

	/**
	 * Wskazuje w ustawieniach trzy strony z shortcode'ami i stronę po zalogowaniu.
	 */
	private function withPages( int $login, int $register, int $reset, int $afterLogin = 0 ): void {
		$this->withSettings(
			array(
				'login_page_id'       => $login,
				'register_page_id'    => $register,
				'reset_page_id'       => $reset,
				'after_login_page_id' => $afterLogin,
			)
		);
	}

	/**
	 * Wdraża „pięć dni temu” i tworzy konta bez stanu sprzed wdrożenia.
	 */
	private function statelessAccounts( int $count ): void {
		OptionStore::write( OptionStore::OPTION_DEPLOYED_AT, array( Backfill::KEY_AT => $this->deployed ) );
		for ( $i = 0; $i < $count; $i++ ) {
			self::factory()->user->create( array( 'user_registered' => gmdate( 'Y-m-d H:i:s', $this->deployed - DAY_IN_SECONDS ) ) );
		}
		Backfill::forget_count();
	}

	public function test_swieza_instalacja_daje_fakty_niespelnione(): void {
		$facts = SetupFactsReader::read();

		$this->assertFalse( $facts->acfReadable );
		$this->assertFalse( $facts->anyAcfGroup );
		$this->assertFalse( $facts->groupSelected );
		$this->assertFalse( $facts->groupReadable );
		$this->assertFalse( $facts->groupHasFields );
		$this->assertFalse( $facts->anyProfession );
		$this->assertFalse( $facts->shortcodePagesPublished );
		$this->assertFalse( $facts->afterLoginPageSet );
		$this->assertFalse( $facts->notifyEmailsPresent );
		$this->assertFalse( $facts->rejectionReasonsPresent );
		$this->assertSame( 0, $facts->statelessAccounts );

		$this->assertTrue( $facts->matrixErrorFree, 'Bez profesji i bez macierzy raport nie ma o czym mówić — to krok macierzy pilnuje, że pusta macierz nie jest macierzą wypełnioną.' );
		$this->assertTrue( $facts->rejectionReasonsNeeded, 'Akceptacja kont jest domyślnie włączona, więc powody odrzucenia są potrzebne.' );
		$this->assertTrue( $facts->defaultRoleOk, 'Domyślna rola świeżej witryny (subscriber) nie blokuje rejestracji.' );
	}

	public function test_grupa_wskazana_i_czytelna_z_polami_daje_groupHasFields_true(): void {
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );

		$facts = SetupFactsReader::read();

		$this->assertTrue( $facts->acfReadable );
		$this->assertTrue( $facts->anyAcfGroup );
		$this->assertTrue( $facts->groupSelected );
		$this->assertTrue( $facts->groupReadable );
		$this->assertTrue( $facts->groupHasFields );
	}

	public function test_grupa_wskazana_ale_usunieta_z_acf_daje_groupReadable_false(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_inna' => 'Inna' ) );
		$this->withSettings( array( 'acf_group_key' => 'group_abc' ) );

		$facts = SetupFactsReader::read();

		$this->assertTrue( $facts->acfReadable, 'ACF odpowiada, tylko wskazanej grupy już w nim nie ma.' );
		$this->assertTrue( $facts->anyAcfGroup );
		$this->assertTrue( $facts->groupSelected );
		$this->assertFalse( $facts->groupReadable );
		$this->assertFalse( $facts->groupHasFields );
	}

	public function test_macierz_z_wymaganym_polem_poza_zrodlem_daje_matrixErrorFree_false(): void {
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter( 'pusers_acf_fields', static fn (): array => array() );
		$this->withSettings( array( 'acf_group_key' => 'group_abc' ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array(
					array(
						'key'             => 'lekarz',
						'label'           => 'Lekarz',
						'credential_type' => 'none',
					),
				)
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );

		$this->assertFalse(
			SetupFactsReader::read()->matrixErrorFree,
			'Pole wymagane w macierzy, którego nie ma w grupie, blokuje rejestrację profesji — krok macierzy nie może po tym świecić zielono.'
		);
	}

	public function test_dependenciesSatisfied_idzie_z_bramki_zaleznosci_nie_z_czytelnosci_grup(): void {
		// Darmowy ACF definiuje acf_get_field_groups(), więc konfiguracja daje się zmierzyć, ale
		// bramka wymaga ACF Pro. To dwa różne fakty i ten test pilnuje, że nie są jednym.
		$this->withSource( array( 'pwz' => 'Numer PWZ' ) );
		$facts = SetupFactsReader::read();
		$this->assertTrue( $facts->acfReadable );
		$this->assertFalse( $facts->dependenciesSatisfied, 'Sam czytelny spis grup nie znaczy, że zależność od ACF Pro jest spełniona.' );

		add_filter( 'pusers_acf_pro_loaded', '__return_true' );
		$this->assertTrue( SetupFactsReader::read()->dependenciesSatisfied );
	}

	public function test_bez_filtra_grup_i_bez_acf_pro_acfReadable_jest_false(): void {
		$this->assertFalse( function_exists( 'acf_get_field_groups' ), 'Środowisko testowe nie ma ACF Pro — gdyby miało, ten test nie sprawdzałby tego, o czym mówi.' );

		$this->assertFalse( SetupFactsReader::read()->acfReadable );
	}

	public function test_strona_w_stanie_draft_nie_liczy_sie_jako_opublikowana(): void {
		$this->withPages( $this->page(), $this->page(), $this->page( 'draft' ) );

		$this->assertFalse( SetupFactsReader::read()->shortcodePagesPublished );
	}

	public function test_strona_usunieta_nie_liczy_sie_jako_opublikowana(): void {
		$removed = $this->page();
		wp_delete_post( $removed, true );
		$this->withPages( $this->page(), $this->page(), $removed );

		$this->assertFalse( SetupFactsReader::read()->shortcodePagesPublished );
	}

	public function test_strona_po_zalogowaniu_jest_osobnym_faktem(): void {
		$this->withPages( $this->page(), $this->page(), $this->page() );

		$facts = SetupFactsReader::read();
		$this->assertTrue( $facts->shortcodePagesPublished, 'Trzy strony z shortcode’ami wystarczają, żeby krok stron był zrobiony.' );
		$this->assertFalse( $facts->afterLoginPageSet );

		$this->withSettings( array( 'after_login_page_id' => $this->page() ) );
		$this->assertTrue( SetupFactsReader::read()->afterLoginPageSet );
	}

	public function test_adresy_redakcji_i_powody_odrzucenia_czytane_z_ustawien(): void {
		$this->withSettings(
			array(
				'notify_emails'     => array( 'redakcja@example.test' ),
				'rejection_reasons' => array( 'Numer nie zgadza się z rejestrem' ),
			)
		);

		$facts = SetupFactsReader::read();
		$this->assertTrue( $facts->notifyEmailsPresent );
		$this->assertTrue( $facts->rejectionReasonsPresent );

		$this->withSettings( array( 'approval_enabled' => false ) );
		$this->assertFalse( SetupFactsReader::read()->rejectionReasonsNeeded, 'Przy wyłączonej akceptacji nikt nie odrzuca wniosków.' );
	}

	public function test_zablokowana_rola_domyslna_daje_defaultRoleOk_false(): void {
		update_option( 'default_role', 'editor' );

		$this->assertFalse( SetupFactsReader::read()->defaultRoleOk, 'Redaktor ma unfiltered_html, którego publiczny formularz nie może nadać — rejestracja jest wtedy wyłączona.' );
	}

	public function test_bez_kont_sprzed_wdrozenia_statelessAccounts_jest_zerem(): void {
		$this->assertSame( 0, SetupFactsReader::read()->statelessAccounts );
	}

	public function test_read_bez_kont_sprzed_wdrozenia_nie_liczy_zapytania(): void {
		$this->statelessAccounts( 3 );

		$this->assertSame( 0, SetupFactsReader::read( false )->statelessAccounts, 'Linia na ekranie ustawień nie może dokładać zapytania o konta bez stanu — robi je już BackfillNotice.' );
		$this->assertSame( 3, SetupFactsReader::read()->statelessAccounts );
	}
}
