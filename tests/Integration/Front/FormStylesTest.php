<?php
/**
 * Testy ładowania arkuszy formularzy frontu.
 *
 * Arkusz stały jedzie zawsze, gdy widok się pojawił (niesie to, czego w Bootstrapie nie ma).
 * Arkusz zapasowy — tylko przy włączonym ustawieniu, bo na skórce bootstrapowej byłby drugą
 * kopią wyglądu, która walczy z pierwszą.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\FormStyles;
use PortalUsers\Front\LoginForm;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class FormStylesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		ProfessionRepository::save(
			ProfessionCollection::fromArray(
				array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ) )
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		foreach ( array( FormStyles::HANDLE, FormStyles::HANDLE_FALLBACK ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		parent::tear_down();
	}

	/**
	 * Włącza doładowanie arkusza zapasowego.
	 */
	private function enableFallback(): void {
		$raw                        = SettingsRepository::get()->toArray();
		$raw['form_styles_enabled'] = '1';
		SettingsRepository::save( Settings::fromArray( $raw ) );
	}

	public function test_arkusz_staly_wchodzi_gdy_widok_sie_pojawil(): void {
		RegistrationForm::render();

		$this->assertTrue( wp_style_is( FormStyles::HANDLE, 'enqueued' ) );
	}

	public function test_arkusz_zapasowy_nie_wchodzi_przy_wylaczonym_ustawieniu(): void {
		RegistrationForm::render();

		$this->assertFalse( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
	}

	public function test_arkusz_zapasowy_wchodzi_przy_wlaczonym_ustawieniu(): void {
		$this->enableFallback();

		RegistrationForm::render();

		$this->assertTrue( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
	}

	public function test_dwa_widoki_na_stronie_daja_jeden_arkusz(): void {
		$this->enableFallback();

		$html = RegistrationForm::render() . LoginForm::render();

		$this->assertSame( 2, substr_count( $html, 'pusers-front' ), 'Dwa widoki to dwa opakowania.' );
		$this->assertTrue( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
		$this->assertCount( 1, array_keys( wp_styles()->queue, FormStyles::HANDLE_FALLBACK, true ), 'Arkusz ma stać w kolejce raz.' );
	}

	public function test_widok_z_sama_notka_tez_dostaje_arkusz(): void {
		// Rejestracja niedostępna: zostaje sama notka i ona też ma być ubrana.
		ProfessionRepository::save( ProfessionCollection::fromArray( array() ) );
		$this->enableFallback();

		RegistrationForm::render();

		$this->assertTrue( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
	}

	public function test_strona_bez_widoku_nie_dostaje_niczego(): void {
		$this->assertFalse( wp_style_is( FormStyles::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
	}

	public function test_strona_z_shortcodem_w_tresci_dostaje_arkusz_przed_renderem(): void {
		// Pierwsza warstwa: decyzja zapada na template_redirect, więc styl zdąży do <head>.
		$this->enableFallback();
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[' . RegistrationForm::SHORTCODE . ']',
			)
		);
		// `has_shortcode()` pyta najpierw, czy shortcode w ogóle istnieje.
		RegistrationForm::register();
		$this->go_to( get_permalink( $page ) );

		FormStyles::on_template_redirect();
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_style_is( FormStyles::HANDLE_FALLBACK, 'enqueued' ) );
	}
}
