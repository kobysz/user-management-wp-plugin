<?php
/**
 * Testy okienek komunikatów frontu.
 *
 * Ton notki pochodzi z jawnej decyzji miejsca wywołania, nie z atrybutu `role` ani z tego, gdzie
 * w kodzie notka stoi: przed tą zmianą wygasły link potwierdzający jechał z `role="status"`,
 * czyli po ubraniu w Bootstrapa świeciłby na zielono.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Front\Confirmation;
use PortalUsers\Front\Notice;
use PortalUsers\Front\NoticeTone;
use WP_UnitTestCase;

final class NoticeTest extends WP_UnitTestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	public function test_notka_sukcesu_ma_alert_success_i_role_status(): void {
		$html = Notice::html( 'Gotowe.', NoticeTone::Success );

		$this->assertStringContainsString( 'class="pusers-notice alert alert-success"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'Gotowe.', $html );
	}

	public function test_notka_neutralna_ma_alert_info_i_role_status(): void {
		$html = Notice::html( 'Do wiadomości.', NoticeTone::Info );

		$this->assertStringContainsString( 'alert alert-info', $html );
		$this->assertStringContainsString( 'role="status"', $html );
	}

	public function test_notka_odmowy_ma_alert_danger_i_role_alert(): void {
		$html = Notice::html( 'Nie udało się.', NoticeTone::Danger );

		$this->assertStringContainsString( 'alert alert-danger', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
	}

	public function test_pusty_komunikat_nie_rysuje_okienka(): void {
		// Puste okienko alertu to awaria najtrudniejsza do zgłoszenia.
		$this->assertSame( '', Notice::html( '', NoticeTone::Danger ) );
		$this->assertSame( '', Notice::with_list( '', array(), NoticeTone::Danger ) );
	}

	public function test_komunikat_jest_zescapowany(): void {
		$this->assertStringNotContainsString( '<b>', Notice::html( '<b>x</b>', NoticeTone::Info ) );
	}

	public function test_lista_bledow_i_notka_sa_w_jednym_okienku(): void {
		$html = Notice::with_list( 'Nie udało się przyjąć zgłoszenia.', array( 'Adres e-mail: zły', 'Numer PWZ: pusty' ), NoticeTone::Danger );

		$this->assertSame( 1, substr_count( $html, 'alert-danger' ), 'Notka i lista mają stać w jednym okienku.' );
		$this->assertStringContainsString( '<ul class="pusers-errors mb-0 ps-3">', $html );
		$this->assertStringContainsString( '<li>Adres e-mail: zły</li>', $html );
		$this->assertStringContainsString( 'Nie udało się przyjąć zgłoszenia.', $html );
	}

	public function test_lista_bez_notki_ma_wlasne_okienko(): void {
		$html = Notice::with_list( '', array( 'Numer PWZ: pusty' ), NoticeTone::Danger );

		$this->assertStringContainsString( 'alert alert-danger', $html );
		$this->assertStringContainsString( '<li>Numer PWZ: pusty</li>', $html );
		$this->assertStringNotContainsString( '<p', $html, 'Bez notki nie ma akapitu do pokazania.' );
	}

	public function test_wygasly_link_potwierdzajacy_jest_czerwony(): void {
		$_GET = array( Confirmation::RESULT_ARG => Confirmation::RESULT_EXPIRED );

		$html = Confirmation::notice_html();

		$this->assertStringContainsString( 'alert alert-danger', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
	}

	public function test_potwierdzony_adres_jest_zielony(): void {
		$_GET = array( Confirmation::RESULT_ARG => Confirmation::RESULT_OK );

		$html = Confirmation::notice_html();

		$this->assertStringContainsString( 'alert alert-success', $html );
		$this->assertStringContainsString( 'role="status"', $html );
	}
}
