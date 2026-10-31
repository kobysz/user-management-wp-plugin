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
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Domain\Registration\RegistrationPlan;
use PortalUsers\Domain\Submission\SubmissionResult;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use WP_Post;

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

	/**
	 * Nazwa pułapki na boty. Celowo NIE zawiera żadnego słowa, które rozpoznają heurystyki
	 * autouzupełniania (`website`, `url`, `email`, `name`, `phone`, `address`): poprzednia nazwa
	 * `pusers_website` z etykietą „Strona WWW” była magnesem dla menedżerów haseł, a `autocomplete="off"`
	 * część z nich ignoruje. Wypełniona pułapka to dowód bota i zgłoszenie dostaje odmowę bez
	 * zachowania wpisanych pól, więc człowiek, któremu menedżer haseł wypełnił to pole, wpadał
	 * w pętlę bez wskazówki. Odmagnesowanie pułapki jest jedyną naprawą, która nie otwiera botowi
	 * z ważnym nonce'em drogi zapisu do magazynu.
	 */
	public const HONEYPOT  = 'pusers_pole_kontrolne';
	public const TIMESTAMP = 'pusers_t';

	public const PROFESSION  = 'pusers_profession';
	public const NONCE_FIELD = 'pusers_nonce';

	/**
	 * Adres e-mail należy do wtyczki, nie do ACF (jak profesja): konto WordPressa go wymaga,
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
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
	}

	/**
	 * Wyłącza z cache stronę, której treść niesie shortcode formularza — PRZED renderem.
	 *
	 * Wywołanie z samego shortcode'u (patrz `render()`) pracuje w `the_content`, czyli po
	 * rozpoczęciu wysyłki treści: `headers_sent()` jest wtedy prawdą, więc `nocache_headers()`
	 * wraca od razu i nagłówek LiteSpeed też nie wychodzi. Zostają `DONOTCACHEPAGE` i hook,
	 * czytane przez wtyczki cache późno — a cache PRZED PHP (CDN, reverse proxy), który
	 * obchodzi się wyłącznie nagłówkami, nie jest wtedy zablokowany wcale i może zapisać stronę
	 * formularza z martwym nonce'em.
	 *
	 * `template_redirect` jest najpóźniejszym momentem, w którym zapytanie główne jest już znane,
	 * a wyjście jeszcze nie ruszyło. Wywołanie w shortcode'ie ZOSTAJE jako druga warstwa: pokrywa
	 * umieszczenie formularza poza treścią wpisu (widżet, szablon, blok), gdzie tej treści nie ma
	 * czego sprawdzać — tam nagłówki i tak mogą nie zdążyć, ale `DONOTCACHEPAGE` zdąży.
	 */
	public static function on_template_redirect(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		RegistrationHandler::no_cache();
	}

	/**
	 * Plan formularza: JEDYNE miejsce, które przycina macierz do pól źródła. Render i obsługa
	 * żądania mają korzystać z tego planu, żeby formularz i walidacja widziały to samo.
	 *
	 * Fail-closed: null, gdy rejestracja jest niedostępna (grupa nieczytelna albo żadna profesja
	 * nie jest oferowana). Plan z nieczytelnego źródła nie miałby żadnych wymagań, w tym wymogu
	 * numeru uprawnień, więc go nie zwracamy. Obsługa żądania musi też odrzucić zgłoszenie
	 * wskazujące profesję spoza `offered_professions()`.
	 */
	public static function plan(): ?RegistrationPlan {
		$state = self::state();
		return null === $state ? null : $state['plan'];
	}

	/**
	 * Profesje oferowane w formularzu: profesja z typem numeru uprawnień, której pola numeru nie
	 * ma w źródle albo nie jest dla niej wymagane w macierzy, wypada (wymogu nie da się wyegzekwować),
	 * a pozostałe rejestrują się dalej. Zgłoszenie z profesją spoza tej listy trzeba odrzucić.
	 *
	 * @return array<string, Profession> Klucz profesji => profesja; pusta, gdy rejestracja niedostępna.
	 */
	public static function offered_professions(): array {
		$state = self::state();
		return null === $state ? array() : $state['professions'];
	}

	/**
	 * JEDYNE wejście, którym ma się posługiwać obsługa wysłanego zgłoszenia. Przyjmuje klucz profesji
	 * ze zgłoszenia i zwraca null, gdy rejestracja jest niedostępna ALBO ta profesja nie jest
	 * oferowana. Samo null z `plan()` nie wystarcza: plan istnieje także dla profesji wycofanej z
	 * oferty (jej `requiredFor()` po prostu nie zawiera pola numeru), więc zgłoszenie z taką profesją
	 * przeszłoby bez numeru uprawnień. Plan i `AcfFields` w wyniku pochodzą z jednej instancji źródła.
	 *
	 * @param string $professionKey Klucz profesji ze zgłoszenia.
	 */
	public static function context_for( string $professionKey ): ?RegistrationContext {
		$state = self::state();
		if ( null === $state || ! isset( $state['professions'][ $professionKey ] ) ) {
			return null;
		}

		return new RegistrationContext( $state['professions'][ $professionKey ], $state['plan'], new AcfFields( $state['source'] ) );
	}

	/**
	 * Czy rejestracja może działać. Fail-closed: bez tego rejestracja nie jest przyjmowana.
	 */
	public static function is_available(): bool {
		return null !== self::state();
	}

	/**
	 * Zwraca formularz albo komunikat o niedostępności rejestracji.
	 *
	 * Strona z shortcode'em jest wyłączana z cache'owania: niesie nonce, podpisany znacznik czasu
	 * i komunikaty potwierdzenia adresu, a zapisana w cache podaje każdemu odwiedzającemu ten sam
	 * nonce — po dobie martwy (patrz `RegistrationHandler::no_cache()`).
	 *
	 * To DRUGA warstwa: pierwsza, z nagłówkami, jedzie przed renderem z `on_template_redirect()`.
	 * Tutaj pokrywamy umieszczenie formularza poza treścią wpisu, gdzie tamta nie zadziała.
	 * Wywołanie jest idempotentne, więc oba razem dają jeden zestaw.
	 *
	 * Ustawienie hasła po potwierdzeniu adresu dzieje się na TEJ SAMEJ stronie i ZASTĘPUJE
	 * formularz rejestracji: człowiek, który właśnie potwierdził adres, ma ustawić hasło do konta,
	 * które już ma, a nie dostać drugi formularz zgłoszenia.
	 *
	 * Zastępuje go jednak tylko wtedy, gdy strona hasła ma po co (patrz `PasswordSetup::takes_over()`).
	 * Znacznik w adresie jest niesekretny, więc trafia tu także ktoś, kto chciał się
	 * zarejestrować; jemu komunikat o wygasłym linku nie może zabrać formularza zgłoszenia.
	 *
	 * Całość wychodzi w jednym opakowaniu (patrz `FormWrapper`): notki i formularz razem, żeby
	 * arkusz wtyczki i tryb karty obejmowały cały widok, a nie sam formularz.
	 */
	public static function render(): string {
		FormStyles::enqueue();

		return FormWrapper::wrap( self::render_contents() );
	}

	/**
	 * Treść widoku bez opakowania (patrz `render()`).
	 */
	private static function render_contents(): string {
		RegistrationHandler::no_cache();

		$password = PasswordSetup::render();
		if ( '' === $password ) {
			return Confirmation::notice_html() . self::render_form();
		}

		return PasswordSetup::takes_over() ? $password : $password . self::render_form();
	}

	/**
	 * Formularz albo komunikat po zgłoszeniu (bez komunikatów potwierdzenia adresu).
	 */
	private static function render_form(): string {
		$state = self::state();
		if ( null === $state ) {
			return Notice::html( __( 'Rejestracja jest chwilowo niedostępna. Spróbuj ponownie później.', 'portal-users' ), NoticeTone::Danger );
		}
		if ( RegistrationHandler::completed() ) {
			return Notice::html( __( 'Dziękujemy za zgłoszenie. Jeżeli wszystko się zgadza, wysłaliśmy na podany adres e-mail wiadomość z dalszymi instrukcjami.', 'portal-users' ), NoticeTone::Success ) . Confirmation::resend_form();
		}

		$source      = $state['source'];
		$plan        = $state['plan'];
		$professions = $state['professions'];
		$old         = self::old_input();

		// Mapa profesja => pole => stan; brak pola w mapie oznacza ukryte. Tylko dla skryptu.
		$map = array();
		foreach ( $professions as $profession ) {
			$map[ $profession->key ] = array();
			foreach ( $plan->fieldsFor( $profession->key ) as $fieldKey => $fieldState ) {
				$map[ $profession->key ][ $fieldKey ] = $fieldState->value;
			}
		}

		self::enqueue_script( $map );

		$acf      = new AcfFields( $source );
		$flash    = RegistrationHandler::flash();
		$errors   = $flash['errors'];
		$warnings = $flash['warnings'];

		$html  = '<form class="' . FormClasses::attr( 'form', 'pusers-registration', 'pusers-form' ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate="novalidate">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( RegistrationHandler::ACTION ) . '" />';
		$html .= self::render_feedback( $source->fields() );

		$professionError = self::error_for( $errors, array( self::PROFESSION, SubmissionResult::PROFESSION_KEY ) );
		$html           .= '<div class="' . FormClasses::attr( 'field', 'pusers-field-profession' ) . '"><label class="' . FormClasses::attr( 'label' ) . '" for="' . esc_attr( self::PROFESSION ) . '">' . esc_html__( 'Profesja', 'portal-users' ) . '</label> ';
		$html           .= '<select class="' . self::control_class( 'select', '' !== $professionError ) . '" id="' . esc_attr( self::PROFESSION ) . '" name="' . esc_attr( self::PROFESSION ) . '" required="required">';
		$html           .= '<option value="">' . esc_html__( '— wybierz —', 'portal-users' ) . '</option>';
		foreach ( $professions as $profession ) {
			$html .= '<option value="' . esc_attr( $profession->key ) . '"' . selected( $old['profession'], $profession->key, false ) . '>' . esc_html( $profession->label ) . '</option>';
		}
		$html .= '</select>' . self::feedback( $professionError ) . '</div>';

		// Adres e-mail: pole konta, zawsze wymagane, poza macierzą (patrz EMAIL).
		$html      .= '<div class="' . FormClasses::attr( 'field', 'pusers-field-email' ) . '"><label class="' . FormClasses::attr( 'label' ) . '" for="' . esc_attr( self::EMAIL ) . '">' . esc_html__( 'Adres e-mail', 'portal-users' ) . '</label> ';
		$emailError = self::error_for( $errors, array( self::EMAIL ) );
		$html      .= '<input class="' . self::control_class( 'control', '' !== $emailError ) . '" type="email" id="' . esc_attr( self::EMAIL ) . '" name="' . esc_attr( self::EMAIL ) . '" value="' . esc_attr( $old['email'] ) . '" autocomplete="email" required="required" />' . self::feedback( $emailError ) . '</div>';

		foreach ( array_keys( $source->fields() ) as $fieldKey ) {
			$fieldKey = (string) $fieldKey;
			if ( ! self::appears_anywhere( $map, $fieldKey ) ) {
				continue;
			}
			$error   = self::error_for( $errors, array( $fieldKey, RegistrationHandler::ACF_ERROR_PREFIX . $fieldKey ) );
			$warning = array_key_exists( $fieldKey, $warnings ) ? RegistrationHandler::message_for( 'credential_warning' ) : '';
			ob_start();
			// Atrybut required po stronie przeglądarki tylko, gdy pole wymagają wszystkie profesje:
			// inaczej blokowałby profesje, którym pole jest niepotrzebne (zwłaszcza bez JavaScriptu).
			$acf->render( $fieldKey, self::required_everywhere( $map, $fieldKey ), $old['fields'][ $fieldKey ] ?? null, '' !== $error );
			// Komunikat DOKŁADAMY za tym, co wypisało ACF — jego znacznika nie ruszamy.
			$html .= '<div class="' . FormClasses::attr( 'field', 'pusers-field' ) . '" data-pusers-field="' . esc_attr( $fieldKey ) . '">' . (string) ob_get_clean()
				. self::feedback( $error ) . self::warning( $warning ) . '</div>';
		}

		// Pola hasła tu NIE MA i mieć nie może: hasło ustawia dopiero ten, kto otworzył link
		// potwierdzający z własnej skrzynki (patrz `PasswordSetup`). Hasło w zgłoszeniu pozwalało
		// osobie trzeciej założyć konto na cudzy adres z własnym hasłem i zachować do niego dostęp
		// po zatwierdzeniu wniosku przez redakcję.

		// Honeypot: zwykłe pole tekstowe (bot wypełnia widoczne pola), ukryte dla człowieka stylem.
		// Nazwa i etykieta są odmagnesowane: nie nazywają żadnej znanej danej, a etykieta wprost mówi
		// pola nie wypełniać — człowiek, któremu czytnik ekranu albo awaria CSS to pole pokaże, wie, co zrobić.
		$html .= '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
		$html .= '<label for="' . esc_attr( self::HONEYPOT ) . '">' . esc_html__( 'Nie wypełniaj tego pola.', 'portal-users' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( self::HONEYPOT ) . '" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off" /></div>';

		$html .= '<input type="hidden" name="' . esc_attr( self::TIMESTAMP ) . '" value="' . esc_attr( SpamGuard::stamp( time() ) ) . '" />';
		$html .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$html .= '<p><button class="' . FormClasses::attr( 'button' ) . '" type="submit">' . esc_html__( 'Zarejestruj się', 'portal-users' ) . '</button></p>';

		return $html . '</form>';
	}

	/**
	 * Komunikaty po nieudanym zgłoszeniu: odmowa antyspamu, błędy pól i miękkie ostrzeżenia.
	 *
	 * @param array<string, string> $labels Etykiety pól źródła: klucz => etykieta.
	 */
	private static function render_feedback( array $labels ): string {
		$flash   = RegistrationHandler::flash();
		$fields  = $labels;
		$labels  = array_merge(
			$labels,
			array(
				self::PROFESSION                 => __( 'Profesja', 'portal-users' ),
				self::EMAIL                      => __( 'Adres e-mail', 'portal-users' ),
				SubmissionResult::PROFESSION_KEY => __( 'Profesja', 'portal-users' ),
			)
		);
		$message = '' === $flash['notice'] ? '' : RegistrationHandler::message_for( $flash['notice'] );

		$items = array();
		foreach ( $flash['errors'] as $field => $code ) {
			$label   = self::error_label( (string) $field, $labels, $fields );
			$items[] = ( '' === $label ? '' : $label . ': ' ) . RegistrationHandler::message_for( $code );
		}
		foreach ( array_keys( $flash['warnings'] ) as $field ) {
			$items[] = ( $labels[ $field ] ?? $field ) . ': ' . RegistrationHandler::message_for( 'credential_warning' );
		}

		return Notice::with_list( $message, $items, NoticeTone::Danger );
	}

	/**
	 * Klasa kontrolki rysowanej przez wtyczkę, z `is-invalid` dla pola odrzuconego.
	 *
	 * @param string $role    Rola kontrolki w mapie klas.
	 * @param bool   $invalid Czy pole zostało odrzucone.
	 */
	private static function control_class( string $role, bool $invalid ): string {
		if ( ! $invalid ) {
			return FormClasses::attr( $role );
		}

		// Kolejność ma znaczenie dla czytającego kod źródła strony: najpierw klasa kontrolki,
		// potem stan.
		return esc_attr( trim( FormClasses::for( $role ) . ' ' . FormClasses::for( 'invalid' ) ) );
	}

	/**
	 * Komunikat błędu pod polem; pusty kod nie rysuje nic.
	 *
	 * @param string $message Treść komunikatu.
	 */
	private static function feedback( string $message ): string {
		return '' === $message ? '' : '<div class="' . FormClasses::attr( 'invalid_feedback' ) . '">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Miękkie ostrzeżenie pod polem. NIE jest błędem: zgłoszenie z nim przechodzi, więc nie
	 * wolno mu nosić `is-invalid` ani czerwieni.
	 *
	 * @param string $message Treść ostrzeżenia.
	 */
	private static function warning( string $message ): string {
		return '' === $message ? '' : '<div class="' . FormClasses::attr( 'warning_feedback' ) . '">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Komunikat błędu dla pierwszego pasującego klucza; pusty, gdy pole błędu nie ma.
	 *
	 * @param array<string, string> $errors Błędy: klucz pola => kod.
	 * @param array<int, string>    $keys   Klucze, pod którymi może siedzieć błąd tego pola.
	 */
	private static function error_for( array $errors, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $errors[ $key ] ) ) {
				return RegistrationHandler::message_for( $errors[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Nazwa pola przy komunikacie błędu. Błąd pola ACF nazwanego jak pole konta ma klucz
	 * z przedrostkiem (`acf:`) i bez jego zdjęcia człowiek czytałby „To pole jest wymagane.”
	 * bez nazwy pola. Etykieta takiego błędu pochodzi ze ŹRÓDŁA pól, nie z etykiet pól konta:
	 * pod tym samym kluczem siedzą dwa różne pola, a chodzi o to z ACF.
	 *
	 * @param string                $field  Klucz błędu.
	 * @param array<string, string> $labels Etykiety pól źródła i pól konta.
	 * @param array<string, string> $fields Etykiety samych pól źródła.
	 */
	private static function error_label( string $field, array $labels, array $fields ): string {
		$prefix = RegistrationHandler::ACF_ERROR_PREFIX;
		if ( 0 !== strpos( $field, $prefix ) ) {
			return $labels[ $field ] ?? '';
		}
		$name = substr( $field, strlen( $prefix ) );

		return $fields[ $name ] ?? $name;
	}

	/**
	 * Źródło pól wybranej grupy ACF.
	 */
	private static function source(): AcfFieldSource {
		return new AcfFieldSource( SettingsRepository::get()->acfGroupKey );
	}

	/**
	 * Stan rejestracji budowany z JEDNEJ instancji źródła: plan, źródło i oferowane profesje
	 * patrzą na tę samą migawkę. Null, gdy rejestracja nie może działać (fail-closed).
	 *
	 * @return array{source: AcfFieldSource, plan: RegistrationPlan, professions: array<string, Profession>}|null
	 */
	private static function state(): ?array {
		$source = self::source();
		if ( ! $source->groupExists() ) {
			return null;
		}

		$plan        = self::compose( $source );
		$fields      = $source->fields();
		$professions = array();
		foreach ( ProfessionRepository::get()->all() as $profession ) {
			if ( self::can_enforce_credential( $profession, $plan, $fields ) ) {
				$professions[ $profession->key ] = $profession;
			}
		}
		if ( array() === $professions ) {
			return null;
		}

		return array(
			'source'      => $source,
			'plan'        => $plan,
			'professions' => $professions,
		);
	}

	/**
	 * Czy wymóg numeru uprawnień da się wyegzekwować. Profesja bez typu numeru zawsze tak; profesja
	 * z typem numeru tylko, gdy jej pole numeru istnieje w źródle i jest dla niej wymagane w macierzy.
	 *
	 * @param Profession            $profession Profesja.
	 * @param RegistrationPlan      $plan       Plan.
	 * @param array<string, string> $fields     Pola źródła.
	 */
	private static function can_enforce_credential( Profession $profession, RegistrationPlan $plan, array $fields ): bool {
		if ( ! $profession->requiresCredential() ) {
			return true;
		}
		$field = $profession->credentialField;

		// Warunek na pusty klucz jest jawny celowo (brak pola numeru to osobny, nazwany przypadek).
		return '' !== $field
			&& array_key_exists( $field, $fields )
			&& ( $plan->fieldsFor( $profession->key )[ $field ] ?? null ) === FieldState::Required;
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
