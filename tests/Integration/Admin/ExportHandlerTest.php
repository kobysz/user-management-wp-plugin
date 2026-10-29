<?php
/**
 * Testy uchwytu eksportu: bramki (nonce, uprawnienie, metoda) i odpowiedź z plikiem CSV.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Admin;

use PortalUsers\Admin\ApplicationsScreen;
use PortalUsers\Admin\ExportHandler;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Export\CsvDocument;
use PortalUsers\Persistence\UserMeta;
use WP_UnitTestCase;
use WPDieException;

final class ExportHandlerTest extends WP_UnitTestCase {

	use AdminPostingTrait;

	private string $originalMethod = 'GET';

	public function set_up(): void {
		parent::set_up();
		$this->originalMethod = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		// Szew testowy: bez tego `exit` zabiłby przebieg phpunita.
		add_filter( ExportHandler::FILTER_EXIT, '__return_false' );
	}

	public function tear_down(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = $this->originalMethod;
		parent::tear_down();
	}

	/**
	 * Dokłada do żądania pole, które ekran niesie bez prefiksu (`status`, `profession`).
	 *
	 * @param string $name  Nazwa pola.
	 * @param string $value Wartość.
	 */
	private function withPlainField( string $name, string $value ): void {
		$_POST[ $name ] = wp_slash( $value );
		$_REQUEST       = $_POST;
	}

	/**
	 * Wywołuje uchwyt i zwraca to, co wyszło na wyjście.
	 */
	private function run_handler(): string {
		ob_start();
		try {
			ExportHandler::handle();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Uchwyt ma odmówić, a żadna treść pliku nie może wyjść.
	 *
	 * @param int $expectedCode Oczekiwany kod odpowiedzi.
	 */
	private function assertRefused( int $expectedCode ): void {
		$caught = null;
		ob_start();
		try {
			ExportHandler::handle();
		} catch ( WPDieException $e ) {
			$caught = $e;
		}
		$out = (string) ob_get_clean();

		$this->assertInstanceOf( WPDieException::class, $caught, 'Uchwyt miał odmówić.' );
		$this->assertStringNotContainsString( CsvDocument::BOM, $out, 'Treść pliku nie może wyjść przy odmowie.' );
		$this->assertStringNotContainsString( 'Adres e-mail', $out, 'Nagłówek pliku nie może wyjść przy odmowie.' );
		$this->assertSame( $expectedCode, $caught->getCode() );
	}

	public function test_zadanie_bez_nonce_jest_odrzucane(): void {
		$this->asAdminPosting( ExportHandler::ACTION, array() );
		unset( $_POST['pusers_nonce'], $_REQUEST['pusers_nonce'] );

		$this->assertRefused( 403 );
	}

	public function test_zadanie_bez_uprawnienia_jest_odrzucane(): void {
		$this->asRolePosting( 'editor', ExportHandler::ACTION, array() );

		$this->assertRefused( 403 );
	}

	public function test_zadanie_przez_get_jest_odrzucane(): void {
		$this->asAdminPosting( ExportHandler::ACTION, array() );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertRefused( 405 );
	}

	public function test_odpowiedz_ma_naglowki_pobierania_i_tresc_csv(): void {
		$id = (int) self::factory()->user->create( array( 'user_login' => 'jan_kowalski' ) );
		update_user_meta( $id, UserMeta::STATUS, 'approved' );
		update_user_meta( $id, UserMeta::PROFESSION, 'student' );
		$other = (int) self::factory()->user->create( array( 'user_login' => 'oczekujacy' ) );
		update_user_meta( $other, UserMeta::STATUS, 'pending' );
		update_user_meta( $other, UserMeta::PROFESSION, 'student' );
		$this->asAdminPosting( ExportHandler::ACTION, array() );
		$this->withPlainField( ApplicationsScreen::ARG_STATUS, 'approved' );

		$out = $this->run_handler();

		$this->assertStringStartsWith( CsvDocument::BOM, $out );
		$this->assertStringContainsString( 'jan_kowalski', $out );
		$this->assertStringNotContainsString( 'oczekujacy', $out, 'Eksport honoruje zakładkę z żądania.' );

		$headers = ExportHandler::headers( AccountStatus::Approved );
		$this->assertContains( 'Content-Type: text/csv; charset=utf-8', $headers );
		$this->assertContains( 'Content-Disposition: attachment; filename="' . ExportHandler::filename( AccountStatus::Approved ) . '"', $headers );
	}

	public function test_stan_spoza_zakladek_zawsze_daje_oczekujace(): void {
		$id = (int) self::factory()->user->create( array( 'user_login' => 'niepotwierdzony' ) );
		update_user_meta( $id, UserMeta::STATUS, 'email_unconfirmed' );
		$this->asAdminPosting( ExportHandler::ACTION, array() );
		$this->withPlainField( ApplicationsScreen::ARG_STATUS, 'email_unconfirmed' );

		$out = $this->run_handler();

		$this->assertStringNotContainsString( 'niepotwierdzony', $out, 'Niepotwierdzone nie są wnioskiem i nie wychodzą w pliku.' );
	}

	public function test_nazwa_pliku_niesie_stan_i_date(): void {
		$this->assertSame( 'wnioski-approved-' . wp_date( 'Y-m-d' ) . '.csv', ExportHandler::filename( AccountStatus::Approved ) );
		$this->assertSame( 'wnioski-pending-' . wp_date( 'Y-m-d' ) . '.csv', ExportHandler::filename( AccountStatus::Pending ) );
	}
}
