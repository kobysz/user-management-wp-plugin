<?php
/**
 * Testy widoku formularza rejestracji.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Front;

use PortalUsers\Domain\Config\Settings;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Front\AcfFields;
use PortalUsers\Front\RegistrationForm;
use PortalUsers\Front\RegistrationHandler;
use PortalUsers\Front\SpamGuard;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Tests\Integration\Support\AcfRenderRecorder;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Support/acf-render-stub.php';

final class RegistrationFormTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		AcfRenderRecorder::$fields = array();
		add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
		add_filter(
			'pusers_acf_fields',
			static fn (): array => array(
				array( 'name' => 'pwz', 'label' => 'Numer PWZ', 'type' => 'text' ),
				array( 'name' => 'zgoda', 'label' => 'Regulamin', 'type' => 'true_false' ),
				array( 'name' => 'newsletter', 'label' => 'Newsletter', 'type' => 'true_false' ),
			)
		);
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
		$this->professions(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				array( 'key' => 'pielegniarka', 'label' => 'Pielęgniarka' ),
			)
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array( 'pwz' => 'required', 'zgoda' => 'required', 'newsletter' => 'visible' ),
					'pielegniarka' => array( 'pwz' => 'hidden', 'zgoda' => 'required', 'newsletter' => 'visible' ),
				)
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pusers_acf_groups' );
		remove_all_filters( 'pusers_acf_fields' );
		remove_all_filters( RegistrationForm::OLD_INPUT_FILTER );
		wp_dequeue_script( RegistrationForm::SCRIPT_HANDLE );
		wp_deregister_script( RegistrationForm::SCRIPT_HANDLE );
		parent::tear_down();
	}

	/**
	 * @param list<array<string, string>> $items Profesje.
	 */
	private function professions( array $items ): void {
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );
	}

	/**
	 * @return array<string, array<string, string>> Mapa przekazana skryptowi.
	 */
	private function localisedMap(): array {
		$data = wp_scripts()->get_data( RegistrationForm::SCRIPT_HANDLE, 'data' );
		$this->assertIsString( $data );
		$this->assertSame( 1, preg_match( '/pusersRegistration = (\{.*\});/s', $data, $m ) );
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded );
		return $decoded['map'];
	}

	public function test_profession_select_is_rendered_before_any_acf_field(): void {
		$html = RegistrationForm::render();

		$select = strpos( $html, 'name="pusers_profession"' );
		$field  = strpos( $html, 'class="acf-stub"' );
		$this->assertNotFalse( $select );
		$this->assertNotFalse( $field );
		$this->assertLessThan( $field, $select );
	}

	public function test_select_offers_every_configured_profession_and_nothing_else(): void {
		$html = RegistrationForm::render();

		preg_match_all( '/<option value="([^"]*)"/', $html, $m );
		$this->assertSame( array( '', 'lekarz', 'pielegniarka' ), $m[1] );
	}

	public function test_form_carries_a_nonce_a_honeypot_and_a_load_timestamp(): void {
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/name="pusers_nonce" value="([^"]+)"/', $html, $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], RegistrationForm::NONCE_ACTION ) );
		$this->assertStringContainsString( 'name="' . RegistrationForm::HONEYPOT . '"', $html );
		$this->assertSame( 1, preg_match( '/name="' . RegistrationForm::TIMESTAMP . '" value="((\d+)\.[0-9a-f]{32})"/', $html, $t ) );
		$this->assertEqualsWithDelta( time(), (int) $t[2], 5 );
		$this->assertSame( SpamGuard::stamp( (int) $t[2] ), $t[1], 'Znacznik jest podpisany, nie goły.' );
	}

	public function test_honeypot_is_not_visible_to_a_human(): void {
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/<input type="([a-z]+)" id="' . RegistrationForm::HONEYPOT . '"/', $html, $m ) );
		$this->assertSame( 'text', $m[1], 'Honeypot ma być zwykłym polem tekstowym, nie type="hidden".' );
		$this->assertMatchesRegularExpression( '/<div style="[^"]*left:-9999px[^"]*"[^>]*>\s*<label for="' . RegistrationForm::HONEYPOT . '">/', $html );
	}

	public function test_the_honeypot_does_not_look_like_a_field_autofill_knows(): void {
		// `autocomplete="off"` ignoruje część menedżerów haseł, a nazwa `pusers_website` z etykietą
		// „Strona WWW” była dla nich magnesem: wypełniona pułapka odcinała człowieka bez wskazówki,
		// a przy kolejnej próbie to samo autouzupełnianie wypełniało ją znowu.
		$html = RegistrationForm::render();

		foreach ( array( 'website', 'url', 'email', 'name', 'phone', 'address' ) as $magnet ) {
			$this->assertStringNotContainsString( $magnet, RegistrationForm::HONEYPOT, 'Nazwa pułapki nie może nazywać znanej danej.' );
		}
		$this->assertSame( 1, preg_match( '#<label for="' . RegistrationForm::HONEYPOT . '">([^<]*)</label>#', $html, $label ) );
		$this->assertStringContainsString( 'Nie wypełniaj', $label[1], 'Etykieta ma wprost mówić, żeby pola nie wypełniać.' );

		$this->assertSame( 1, preg_match( '#<input type="text" id="' . RegistrationForm::HONEYPOT . '"[^>]*>#', $html, $input ) );
		$this->assertStringContainsString( 'tabindex="-1"', $input[0], 'Pułapka nie może się łapać tabulatorem.' );
		$this->assertStringContainsString( 'autocomplete="off"', $input[0] );
		$this->assertMatchesRegularExpression( '/aria-hidden="true"/', $html );
	}

	public function test_profession_labels_are_escaped(): void {
		$this->professions( array( array( 'key' => 'xss', 'label' => '<script>alert(1)</script>' ) ) );
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'xss' => array( 'pwz' => 'visible' ) ) ) );

		$html = RegistrationForm::render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_hidden_field_of_a_profession_is_absent_from_the_localised_map(): void {
		RegistrationForm::render();

		$map = $this->localisedMap();
		$this->assertSame( array( 'pwz' => 'required', 'zgoda' => 'required', 'newsletter' => 'visible' ), $map['lekarz'] );
		$this->assertSame( array( 'zgoda' => 'required', 'newsletter' => 'visible' ), $map['pielegniarka'] );
	}

	public function test_submitted_values_are_repopulated_after_a_failed_submission(): void {
		add_filter(
			RegistrationForm::OLD_INPUT_FILTER,
			static fn (): array => array(
				'profession' => 'pielegniarka',
				'fields'     => array( 'zgoda' => '1', 'newsletter' => '0' ),
			)
		);

		$html = RegistrationForm::render();

		$this->assertMatchesRegularExpression( '/<option value="pielegniarka" selected=\'selected\'>/', $html );
		$values = array();
		foreach ( AcfRenderRecorder::$fields as $field ) {
			$values[ $field['name'] ] = $field['value'];
		}
		$this->assertSame( '1', $values['pusers_fields[zgoda]'] );
		$this->assertSame( '0', $values['pusers_fields[newsletter]'] );
		$this->assertNull( $values['pusers_fields[pwz]'] );
	}

	public function test_password_is_never_repopulated(): void {
		add_filter(
			RegistrationForm::OLD_INPUT_FILTER,
			static fn (): array => array(
				'profession'      => 'lekarz',
				'password'        => 'Sekret-123!',
				'password_repeat' => 'Sekret-123!',
				'pusers_password' => 'Sekret-123!',
				'fields'          => array( 'zgoda' => '1' ),
			)
		);

		$html = RegistrationForm::render();

		$this->assertStringNotContainsString( 'Sekret-123!', $html );
		$this->assertSame( 1, preg_match( '/<input type="password" id="pusers_password"[^>]*>/', $html, $m ) );
		$this->assertStringNotContainsString( 'value=', $m[0] );
	}

	public function test_shortcode_renders_nothing_but_a_notice_when_no_profession_is_configured(): void {
		$this->professions( array() );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_registration_is_closed_when_the_field_group_is_unreadable(): void {
		// Matryca wymaga PWZ lekarza, ale grupa zniknęła: przycięcie pustą listą zniosłoby ten wymóg,
		// więc formularz nie może się pojawić.
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'pusers_profession', $html );
		$this->assertStringNotContainsString( 'group_abc', $html, 'Komunikat publiczny nie ujawnia konfiguracji.' );
		$this->assertFalse( RegistrationForm::is_available() );
	}

	public function test_registration_is_closed_when_no_group_is_selected(): void {
		SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => '' ) ) );

		$this->assertStringNotContainsString( '<form', RegistrationForm::render() );
		$this->assertFalse( RegistrationForm::is_available() );
	}

	public function test_plan_keeps_the_credential_requirement_for_a_readable_source(): void {
		$this->assertTrue( RegistrationForm::is_available() );
		$plan = RegistrationForm::plan();
		$this->assertNotNull( $plan );
		$this->assertSame( array( 'pwz', 'zgoda' ), $plan->requiredFor( 'lekarz' ) );
		$this->assertSame( array( 'zgoda' ), $plan->requiredFor( 'pielegniarka' ) );
	}

	public function test_plan_refuses_to_exist_when_the_source_is_unreadable(): void {
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );

		$this->assertNull( RegistrationForm::plan() );
		$this->assertSame( array(), RegistrationForm::offered_professions() );
	}

	public function test_context_is_null_for_a_profession_withdrawn_from_the_offer(): void {
		$this->professions(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'zniklo' ),
				array( 'key' => 'student', 'label' => 'Student' ),
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'zniklo' => 'required' ), 'student' => array( 'zgoda' => 'required' ) ) ) );

		$this->assertNull( RegistrationForm::context_for( 'lekarz' ) );
		$this->assertNotNull( RegistrationForm::context_for( 'student' ) );
	}

	public function test_context_is_null_for_an_unknown_profession_and_an_unreadable_group(): void {
		$this->assertNull( RegistrationForm::context_for( 'wrozka' ) );

		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		$this->assertNull( RegistrationForm::context_for( 'lekarz' ) );
	}

	public function test_context_for_an_offered_profession_carries_the_credential_requirement_and_matching_fields(): void {
		$context = RegistrationForm::context_for( 'lekarz' );

		$this->assertNotNull( $context );
		$this->assertSame( 'lekarz', $context->profession->key );
		$this->assertSame( array( 'pwz', 'zgoda' ), $context->plan->requiredFor( 'lekarz' ) );

		$planned = array_keys( $context->plan->fieldsFor( 'lekarz' ) );
		$offered = array_keys( $context->fields->fromRequest( array( AcfFields::INPUT => array( 'pwz' => 'x', 'zgoda' => '1', 'newsletter' => '1', 'obce' => '1' ) ) ) );
		$this->assertSame( $planned, $offered );
	}

	public function test_context_reads_the_field_definitions_once_across_plan_and_fields(): void {
		$calls = 0;
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ) use ( &$calls ): array {
				++$calls;
				return $raw;
			},
			30
		);

		$context = RegistrationForm::context_for( 'lekarz' );
		$this->assertNotNull( $context );
		$context->plan->requiredFor( 'lekarz' );
		$context->fields->booleanKeys();
		ob_start();
		$context->fields->render( 'pwz', true, null );
		ob_end_clean();

		$this->assertSame( 1, $calls );
	}

	public function test_render_reads_the_field_definitions_once(): void {
		$calls = 0;
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ) use ( &$calls ): array {
				++$calls;
				return $raw;
			},
			30
		);

		RegistrationForm::render();

		$this->assertSame( 1, $calls, 'Render i ACF muszą czytać jedną migawkę definicji pól.' );
	}

	public function test_profession_whose_credential_field_left_the_source_is_not_offered(): void {
		$this->professions(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'numer_pwz' ),
				array( 'key' => 'student', 'label' => 'Student' ),
			)
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'numer_pwz' => 'required' ), 'student' => array( 'zgoda' => 'required' ) ) ) );

		$html = RegistrationForm::render();

		preg_match_all( '/<option value="([^"]+)"/', $html, $m );
		$this->assertSame( array( 'student' ), $m[1] );
		$this->assertSame( array( 'student' ), array_keys( RegistrationForm::offered_professions() ) );
		$this->assertSame( array( 'student' ), array_keys( $this->localisedMap() ) );
	}

	public function test_profession_whose_credential_field_is_only_visible_is_not_offered(): void {
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array( 'pwz' => 'visible', 'zgoda' => 'required' ),
					'pielegniarka' => array( 'zgoda' => 'required' ),
				)
			)
		);

		preg_match_all( '/<option value="([^"]+)"/', RegistrationForm::render(), $m );
		$this->assertSame( array( 'pielegniarka' ), $m[1] );
	}

	public function test_profession_with_a_properly_configured_credential_is_offered_beside_one_without(): void {
		$this->professions(
			array(
				array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
				array( 'key' => 'student', 'label' => 'Student' ),
			)
		);

		preg_match_all( '/<option value="([^"]+)"/', RegistrationForm::render(), $m );
		$this->assertSame( array( 'lekarz', 'student' ), $m[1] );
	}

	public function test_registration_closes_when_every_profession_is_filtered_out(): void {
		$this->professions( array( array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'zniklo' ) ) );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertFalse( RegistrationForm::is_available() );
	}

	public function test_profession_key_and_field_key_are_escaped_in_attributes(): void {
		$this->professions( array( array( 'key' => 'a"b', 'label' => 'X' ) ) );
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => 'c"d', 'label' => 'Y', 'type' => 'text' );
				return $raw;
			},
			20
		);
		FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'a"b' => array( 'c"d' => 'visible' ) ) ) );

		$html = RegistrationForm::render();

		$this->assertStringContainsString( '<option value="a&quot;b"', $html );
		$this->assertStringContainsString( 'data-pusers-field="c&quot;d"', $html );
		$this->assertStringNotContainsString( 'value="a"b"', $html );
		$this->assertStringNotContainsString( 'data-pusers-field="c"d"', $html );
	}

	public function test_browser_required_is_set_only_for_a_field_every_profession_requires(): void {
		RegistrationForm::render();

		$required = array();
		foreach ( AcfRenderRecorder::$fields as $field ) {
			$required[ $field['name'] ] = $field['required'];
		}
		// zgoda: wymagana u obu profesji. pwz: wymagana tylko u lekarza, więc nie może blokować pielęgniarki.
		$this->assertSame( 1, $required['pusers_fields[zgoda]'] );
		$this->assertSame( 0, $required['pusers_fields[pwz]'] );
		$this->assertSame( 0, $required['pusers_fields[newsletter]'] );
	}

	public function test_script_is_loaded_only_when_the_form_is_actually_rendered(): void {
		$this->assertFalse( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ) );

		$this->professions( array() );
		RegistrationForm::render();
		$this->assertFalse( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ), 'Komunikat zamiast formularza nie ładuje skryptu.' );

		$this->professions( array( array( 'key' => 'lekarz', 'label' => 'Lekarz' ) ) );
		RegistrationForm::render();
		$this->assertTrue( wp_script_is( RegistrationForm::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_email_field_follows_the_profession_and_precedes_every_acf_field(): void {
		$html = RegistrationForm::render();

		$select = strpos( $html, 'name="pusers_profession"' );
		$email  = strpos( $html, 'name="' . RegistrationForm::EMAIL . '"' );
		$field  = strpos( $html, 'class="acf-stub"' );
		$this->assertNotFalse( $email );
		$this->assertLessThan( $email, $select );
		$this->assertLessThan( $field, $email );
	}

	public function test_email_field_is_marked_required(): void {
		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/<input type="email"[^>]*name="pusers_email"[^>]*>/', $html, $m ) );
		$this->assertStringContainsString( 'required="required"', $m[0] );
	}

	public function test_email_field_is_not_governed_by_the_matrix(): void {
		// Kolizja nazw jest możliwa: pole ACF może nazywać się tak jak stała adresu. Pola ACF idą do
		// kontenera pusers_fields[...], więc w żądaniu się nie zderzają, ale macierz nie może
		// ukryć ani zdjąć wymagalności pola konta.
		add_filter(
			'pusers_acf_fields',
			static function ( array $raw ): array {
				$raw[] = array( 'name' => RegistrationForm::EMAIL, 'label' => 'Email', 'type' => 'text' );
				return $raw;
			},
			20
		);
		FieldMatrixRepository::save(
			FieldMatrix::fromArray(
				array(
					'lekarz'       => array( RegistrationForm::EMAIL => 'hidden', 'pwz' => 'required' ),
					'pielegniarka' => array( RegistrationForm::EMAIL => 'visible', 'zgoda' => 'required' ),
				)
			)
		);

		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match_all( '/<input type="email"[^>]*name="pusers_email"[^>]*>/', $html, $m ) );
		$this->assertCount( 1, $m[0], 'Pole adresu konta ma wystąpić dokładnie raz.' );
		$this->assertStringContainsString( 'required="required"', $m[0][0] );
	}

	public function test_email_is_repopulated_but_the_password_is_not(): void {
		add_filter(
			RegistrationForm::OLD_INPUT_FILTER,
			static fn (): array => array(
				'email'    => 'jan@example.com',
				'password' => 'Sekret-123!',
			)
		);

		$html = RegistrationForm::render();

		$this->assertSame( 1, preg_match( '/<input type="email"[^>]*>/', $html, $m ) );
		$this->assertStringContainsString( 'value="jan@example.com"', $m[0] );
		$this->assertStringNotContainsString( 'Sekret-123!', $html );
		$this->assertSame( 1, preg_match( '/<input type="password" id="pusers_password"[^>]*>/', $html, $p ) );
		$this->assertStringNotContainsString( 'value=', $p[0] );
	}

	public function test_email_is_escaped_when_repopulated(): void {
		add_filter( RegistrationForm::OLD_INPUT_FILTER, static fn (): array => array( 'email' => '"><script>x</script>' ) );

		$this->assertStringNotContainsString( '<script>x</script>', RegistrationForm::render() );
	}

	public function test_register_exposes_the_shortcode(): void {
		RegistrationForm::register();

		$this->assertTrue( shortcode_exists( RegistrationForm::SHORTCODE ) );
		$this->assertStringContainsString( 'name="pusers_profession"', do_shortcode( '[' . RegistrationForm::SHORTCODE . ']' ) );
	}

	public function test_the_form_page_is_excluded_from_page_caching(): void {
		// Strona z nonce'em i podpisanym znacznikiem czasu zapisana w cache na dłużej niż dobę
		// odpowiada każdemu tym samym martwym nonce'em, a komunikat „odśwież stronę” nie może pomóc,
		// bo odświeżenie zwraca tę samą stronę z cache. Rejestracja jest wtedy trwale niemożliwa.
		$fired = 0;
		add_action(
			'litespeed_control_set_nocache',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		RegistrationForm::render();
		$this->assertSame( 1, $fired, 'Render shortcode\'u musi wyłączyć stronę z cache.' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );

		// Ten sam zestaw co po zgłoszeniu: jedno miejsce, bez dwóch kopii.
		$fired = 0;
		RegistrationHandler::no_cache();
		$this->assertSame( 1, $fired );

		remove_all_actions( 'litespeed_control_set_nocache' );
	}

	public function test_the_unavailability_notice_is_excluded_from_caching_too(): void {
		// Bez czytelnej grupy ACF render oddaje komunikat, nie formularz. Strona zapisana w cache
		// w tej chwili pokazywałaby „rejestracja niedostępna” także po naprawieniu konfiguracji.
		remove_all_filters( 'pusers_acf_groups' );
		add_filter( 'pusers_acf_groups', static fn (): array => array() );
		$fired = 0;
		add_action(
			'litespeed_control_set_nocache',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$html = RegistrationForm::render();

		$this->assertStringContainsString( 'pusers-notice', $html );
		$this->assertSame( 1, $fired );
		remove_all_actions( 'litespeed_control_set_nocache' );
	}
}
