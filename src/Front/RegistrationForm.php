<?php
/**
 * Formularz rejestracji na stronie: shortcode i jego widok.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Domain\Registration\RegistrationPlan;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;

/**
 * Widok formularza; obsługa wysłanego żądania należy do osobnego kroku.
 *
 * Pole profesji stoi zawsze pierwsze. Bez JavaScriptu formularz pokazuje pola wszystkich profesji,
 * a serwer i tak odsiewa po macierzy; skrypt jedynie ukrywa pola niewybranej profesji.
 */
final class RegistrationForm {

	public const SHORTCODE    = 'portal_users_registration';
	public const NONCE_ACTION = 'pusers_register';
	public const FIELD_PREFIX = 'pusers_';
	public const HONEYPOT     = 'pusers_website';
	public const TIMESTAMP    = 'pusers_t';

	public const PROFESSION      = 'pusers_profession';
	public const PASSWORD        = 'pusers_password';
	public const PASSWORD_REPEAT = 'pusers_password_repeat';
	public const NONCE_FIELD     = 'pusers_nonce';

	/**
	 * Adres e-mail należy do wtyczki, nie do ACF (jak profesja i hasło): konto WordPressa go wymaga,
	 * a potwierdzenie adresu opiera się na tym, że wtyczka wie, gdzie on jest. Pole jest ZAWSZE
	 * wymagane i NIE podlega macierzy (macierz rządzi polami profilu z ACF, nie polami konta);
	 * nie umacierzać. HTML-owe `required` to wygoda, serwer sprawdza adres niezależnie.
	 */
	public const EMAIL = 'pusers_email';

	/**
	 * Uchwyt skryptu ukrywającego pola.
	 */
	public const SCRIPT_HANDLE = 'pusers-registration';

	/**
	 * Filtr dostarczający wartości do powtórnego wypełnienia formularza po nieudanym zgłoszeniu.
	 * Oczekiwany kształt: `array{profession?: string, email?: string, fields?: array<string, mixed>}`. Haseł tu
	 * się nie czyta nigdy, nawet jeśli ktoś je podeśle.
	 */
	public const OLD_INPUT_FILTER = 'pusers_registration_old_input';

	/**
	 * Podpina shortcode.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
	}

	/**
	 * Plan formularza: JEDYNE miejsce, które przycina macierz do pól źródła. Render i obsługa
	 * żądania mają korzystać z tego planu, żeby formularz i walidacja widziały to samo.
	 *
	 * UWAGA: nieczytelne źródło (`AcfFieldSource::groupExists()` fałszywe) daje plan bez żadnych
	 * wymagań, w tym bez wymogu numeru uprawnień. Wywołujący musi to sprawdzić (`is_available()`)
	 * i nie przyjmować zgłoszenia.
	 */
	public static function plan(): RegistrationPlan {
		return self::compose( self::source() );
	}

	/**
	 * Czy rejestracja może działać: grupa pól jest czytelna i są skonfigurowane profesje.
	 * Fail-closed: bez tego rejestracja nie jest przyjmowana.
	 */
	public static function is_available(): bool {
		return self::available( self::source() );
	}

	/**
	 * Zwraca formularz albo komunikat o niedostępności rejestracji.
	 */
	public static function render(): string {
		$source = self::source();
		if ( ! self::available( $source ) ) {
			return '<p class="pusers-notice">' . esc_html__( 'Rejestracja jest chwilowo niedostępna. Spróbuj ponownie później.', 'portal-users' ) . '</p>';
		}

		$professions = ProfessionRepository::get()->all();
		$plan        = self::compose( $source );
		$old         = self::old_input();

		// Mapa profesja => pole => stan; brak pola w mapie oznacza ukryte. Tylko dla skryptu.
		$map = array();
		foreach ( $professions as $profession ) {
			$map[ $profession->key ] = array();
			foreach ( $plan->fieldsFor( $profession->key ) as $fieldKey => $state ) {
				$map[ $profession->key ][ $fieldKey ] = $state->value;
			}
		}

		self::enqueue_script( $map );

		$acf  = new AcfFields( SettingsRepository::get()->acfGroupKey );
		$html = '<form class="pusers-registration" method="post" novalidate="novalidate">';

		$html .= '<p class="pusers-field-profession"><label for="' . esc_attr( self::PROFESSION ) . '">' . esc_html__( 'Profesja', 'portal-users' ) . '</label> ';
		$html .= '<select id="' . esc_attr( self::PROFESSION ) . '" name="' . esc_attr( self::PROFESSION ) . '" required="required">';
		$html .= '<option value="">' . esc_html__( '— wybierz —', 'portal-users' ) . '</option>';
		foreach ( $professions as $profession ) {
			$html .= '<option value="' . esc_attr( $profession->key ) . '"' . selected( $old['profession'], $profession->key, false ) . '>' . esc_html( $profession->label ) . '</option>';
		}
		$html .= '</select></p>';

		// Adres e-mail: pole konta, zawsze wymagane, poza macierzą (patrz EMAIL).
		$html .= '<p class="pusers-field-email"><label for="' . esc_attr( self::EMAIL ) . '">' . esc_html__( 'Adres e-mail', 'portal-users' ) . '</label> ';
		$html .= '<input type="email" id="' . esc_attr( self::EMAIL ) . '" name="' . esc_attr( self::EMAIL ) . '" value="' . esc_attr( $old['email'] ) . '" autocomplete="email" required="required" /></p>';

		foreach ( array_keys( $source->fields() ) as $fieldKey ) {
			$fieldKey = (string) $fieldKey;
			if ( ! self::appears_anywhere( $map, $fieldKey ) ) {
				continue;
			}
			ob_start();
			// Atrybut required po stronie przeglądarki tylko, gdy pole wymagają wszystkie profesje:
			// inaczej blokowałby profesje, którym pole jest niepotrzebne (zwłaszcza bez JavaScriptu).
			$acf->render( $fieldKey, self::required_everywhere( $map, $fieldKey ), $old['fields'][ $fieldKey ] ?? null );
			$html .= '<div class="pusers-field" data-pusers-field="' . esc_attr( $fieldKey ) . '">' . (string) ob_get_clean() . '</div>';
		}

		$html .= '<p><label for="' . esc_attr( self::PASSWORD ) . '">' . esc_html__( 'Hasło', 'portal-users' ) . '</label> ';
		$html .= '<input type="password" id="' . esc_attr( self::PASSWORD ) . '" name="' . esc_attr( self::PASSWORD ) . '" autocomplete="new-password" required="required" /></p>';
		$html .= '<p><label for="' . esc_attr( self::PASSWORD_REPEAT ) . '">' . esc_html__( 'Powtórz hasło', 'portal-users' ) . '</label> ';
		$html .= '<input type="password" id="' . esc_attr( self::PASSWORD_REPEAT ) . '" name="' . esc_attr( self::PASSWORD_REPEAT ) . '" autocomplete="new-password" required="required" /></p>';

		// Honeypot: zwykłe pole tekstowe (bot wypełnia widoczne pola), ukryte dla człowieka stylem.
		$html .= '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
		$html .= '<label for="' . esc_attr( self::HONEYPOT ) . '">' . esc_html__( 'Strona WWW', 'portal-users' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( self::HONEYPOT ) . '" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off" /></div>';

		$html .= '<input type="hidden" name="' . esc_attr( self::TIMESTAMP ) . '" value="' . esc_attr( (string) time() ) . '" />';
		$html .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$html .= '<p><button type="submit">' . esc_html__( 'Zarejestruj się', 'portal-users' ) . '</button></p>';

		return $html . '</form>';
	}

	/**
	 * Źródło pól wybranej grupy ACF.
	 */
	private static function source(): AcfFieldSource {
		return new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
	}

	/**
	 * Czy źródło jest czytelne i są profesje.
	 *
	 * @param AcfFieldSource $source Źródło pól.
	 */
	private static function available( AcfFieldSource $source ): bool {
		return $source->groupExists() && array() !== ProfessionRepository::get()->all();
	}

	/**
	 * Składa plan z macierzy przyciętej do pól źródła. Jedyne wywołanie `prunedTo()` we froncie.
	 *
	 * @param AcfFieldSource $source Źródło pól.
	 */
	private static function compose( AcfFieldSource $source ): RegistrationPlan {
		$pruned = FieldMatrixRepository::get()->prunedTo( array_map( 'strval', array_keys( $source->fields() ) ) );
		return new RegistrationPlan( $pruned, $source );
	}

	/**
	 * Wartości do powtórnego wypełnienia; hasła nie są czytane w ogóle.
	 *
	 * @return array{profession: string, email: string, fields: array<string, mixed>}
	 */
	private static function old_input(): array {
		// Nazwa filtra wpisana dosłownie (reguła prefiksów); stała OLD_INPUT_FILTER służy testom i dokumentacji.
		$raw    = apply_filters( 'pusers_registration_old_input', array() );
		$result = array(
			'profession' => '',
			'email'      => '',
			'fields'     => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $result;
		}
		if ( isset( $raw['profession'] ) && is_string( $raw['profession'] ) ) {
			$result['profession'] = $raw['profession'];
		}
		if ( isset( $raw['email'] ) && is_string( $raw['email'] ) ) {
			$result['email'] = $raw['email'];
		}
		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $key => $value ) {
				$result['fields'][ (string) $key ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Czy pole występuje w planie którejkolwiek profesji.
	 *
	 * @param array<string, array<string, string>> $map      Mapa profesja => pole => stan.
	 * @param string                               $fieldKey Klucz pola.
	 */
	private static function appears_anywhere( array $map, string $fieldKey ): bool {
		foreach ( $map as $fields ) {
			if ( isset( $fields[ $fieldKey ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Czy pole jest wymagane u każdej profesji.
	 *
	 * @param array<string, array<string, string>> $map      Mapa profesja => pole => stan.
	 * @param string                               $fieldKey Klucz pola.
	 */
	private static function required_everywhere( array $map, string $fieldKey ): bool {
		foreach ( $map as $fields ) {
			if ( ( $fields[ $fieldKey ] ?? '' ) !== FieldState::Required->value ) {
				return false;
			}
		}
		return array() !== $map;
	}

	/**
	 * Ładuje skrypt wraz z mapą; wołane wyłącznie przy faktycznym renderze formularza.
	 *
	 * @param array<string, array<string, string>> $map Mapa profesja => pole => stan.
	 */
	private static function enqueue_script( array $map ): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/registration.js', Plugin::plugin_file() ),
			array(),
			Plugin::version(),
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'pusersRegistration',
			array(
				'professionField' => self::PROFESSION,
				'map'             => $map,
			)
		);
	}
}
