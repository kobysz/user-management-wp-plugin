<?php
/**
 * Obsługa wysłanego formularza rejestracji: bramki i utworzenie konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Fields\SubmissionFilter;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Domain\Registration\BooleanNormalizer;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Spam\SpamVerdict;
use PortalUsers\Domain\Submission\SubmissionResult;
use PortalUsers\Domain\Submission\SubmissionValidator;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Kolejność bramek jest nienaruszalna:
 * nonce, metoda POST, antyspam, kontekst (`RegistrationForm::context_for()`), walidacja,
 * utworzenie konta, `SpamGuard::record_registration()`, przekierowanie (PRG).
 *
 * Antyspam stoi PRZED walidacją, żeby bot nie dostawał z odpowiedzi informacji o polach. Konto
 * powstaje w stanie `email_unconfirmed` z domyślną rolą witryny; mail potwierdzający i wersja
 * regulaminu należą do kolejnych kroków, które podpinają się pod akcję `pusers_account_registered`.
 */
final class RegistrationHandler {

	/**
	 * Akcja formularza (admin-post) i zarazem akcja nonce'a.
	 */
	public const ACTION = 'pusers_register';

	/**
	 * Akcja odpalana po utworzeniu konta; argument: identyfikator użytkownika.
	 */
	public const REGISTERED_HOOK = 'pusers_account_registered';

	/**
	 * Wpis w logu zdarzeń o utworzeniu konta.
	 */
	public const LOG_ACTION = 'registration_created';

	/**
	 * Argument adresu z jednorazowym kluczem błędów i wpisanych wartości (przeżywają PRG).
	 */
	public const FLASH_ARG = 'pusers_r';

	/**
	 * Argument adresu po udanej rejestracji.
	 */
	public const DONE_ARG = 'pusers_registered';

	private const FLASH_PREFIX = 'pusers_flash_';
	private const FLASH_TTL    = 10 * MINUTE_IN_SECONDS;

	/**
	 * Podpina obsługę zgłoszenia (także dla niezalogowanych) i zasila powtórne wypełnienie formularza.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		// Nazwa filtra dosłownie (reguła prefiksów); to ta sama nazwa co RegistrationForm::OLD_INPUT_FILTER.
		add_filter( 'pusers_registration_old_input', array( self::class, 'old_input' ) );
	}

	/**
	 * Obsługuje zgłoszenie.
	 */
	public static function handle(): void {
		// 1. Nonce.
		$nonce = isset( $_POST[ RegistrationForm::NONCE_FIELD ] ) && is_string( $_POST[ RegistrationForm::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ RegistrationForm::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, RegistrationForm::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		// 2. Metoda POST (admin-post odpala się dla każdej metody).
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		// Dane żądania po wp_unslash (wymóg SpamGuard::check()); nonce sprawdzony wyżej.
		$unslashed = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request   = is_array( $unslashed ) ? $unslashed : array();
		$email     = self::text( $request, RegistrationForm::EMAIL );
		$key       = self::text( $request, RegistrationForm::PROFESSION );

		// 3. Antyspam, przed walidacją i przed jakimkolwiek odczytem pól.
		$verdict = SpamGuard::check( $_SERVER, $request, $email );
		if ( ! $verdict->passed() ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'notice'     => self::spam_notice( $verdict ),
				)
			);
			return;
		}

		// 4. Kontekst: JEDYNE wejście. Null znaczy: odrzuć zgłoszenie i nie twórz konta.
		$context = RegistrationForm::context_for( $key );
		$role    = self::default_role();
		if ( null === $context || null === $role ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'errors'     => null === $context
						? array( SubmissionResult::PROFESSION_KEY => 'unknown_profession' )
						: array( 'registration' => 'unavailable' ),
				)
			);
			return;
		}

		// 5. Walidacja, na macierzy z kontekstu (przyciętej do pól grupy), nigdy na surowej.
		$matrix     = $context->plan->matrix();
		$profession = $context->profession;
		$raw        = $context->fields->fromRequest( $request );
		$values     = ( new BooleanNormalizer( $context->fields->booleanKeys() ) )->normalize( $raw );
		$validator  = new SubmissionValidator( ProfessionCollection::fromArray( array( $profession->toArray() ) ), $matrix, new ValidatorRegistry() );
		$result     = $validator->validate( $profession->key, $profession->credentialField, $values );

		$password = self::text( $request, RegistrationForm::PASSWORD, false );
		$repeat   = self::text( $request, RegistrationForm::PASSWORD_REPEAT, false );
		$clean    = sanitize_email( $email );
		$errors   = array();
		if ( '' === $email || $clean !== $email || ! is_email( $clean ) ) {
			$errors[ RegistrationForm::EMAIL ] = 'email_invalid';
		} elseif ( false !== email_exists( $clean ) ) {
			$errors[ RegistrationForm::EMAIL ] = 'email_exists';
		}
		if ( '' === $password ) {
			$errors[ RegistrationForm::PASSWORD ] = 'password_missing';
		} elseif ( $password !== $repeat ) {
			$errors[ RegistrationForm::PASSWORD_REPEAT ] = 'password_mismatch';
		}
		$errors += $result->errors();

		// Błąd twardy i ostrzeżenie o tym samym polu opisują jeden fakt: ostrzeżenie ustępuje błędowi.
		$warnings = array();
		if ( '' !== $result->credentialWarning() && ! isset( $errors[ $profession->credentialField ] ) ) {
			$warnings[ $profession->credentialField ] = $result->credentialWarning();
		}

		if ( array() !== $errors ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'fields'     => self::clean_values( $raw ),
					'errors'     => $errors,
					'warnings'   => $warnings,
				)
			);
			return;
		}

		// 6. Utworzenie konta. Hasło idzie wyłącznie do wp_insert_user(): ani do flash, ani do metadanych.
		$userId = wp_insert_user(
			array(
				'user_login' => self::login_for( $clean ),
				'user_email' => $clean,
				'user_pass'  => $password,
				'role'       => $role,
			)
		);
		if ( is_wp_error( $userId ) ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'fields'     => self::clean_values( $raw ),
					'errors'     => array( RegistrationForm::EMAIL => 'existing_user_email' === $userId->get_error_code() ? 'email_exists' : 'account_failed' ),
				)
			);
			return;
		}

		update_user_meta( $userId, UserMeta::PROFESSION, $profession->key );
		AccountStatusRepository::set( $userId, AccountStatus::EmailUnconfirmed );
		// Odsiew po macierzy z kontekstu, potem sanityzacja: values() może nieść tablicę.
		$stored = ( new SubmissionFilter( $matrix ) )->strip( $profession->key, $result->values() );
		foreach ( self::clean_values( $stored ) as $name => $value ) {
			if ( ! self::may_store( $name ) ) {
				continue;
			}
			// Numer uprawnień jest tekstem; tablica w tym polu nie trafia do metadanych zserializowana.
			if ( $name === $profession->credentialField && ! is_string( $value ) ) {
				continue;
			}
			update_user_meta( $userId, $name, $value );
		}

		// 7. Licznik adresu e-mail rośnie WYŁĄCZNIE tu: po udanej walidacji i po przepustce z check().
		SpamGuard::record_registration( $clean );

		EventLog::write(
			self::LOG_ACTION,
			$userId,
			0,
			array(
				'profession'         => $profession->key,
				'credential_warning' => $result->credentialWarning(),
			)
		);

		/**
		 * Konto zostało utworzone (stan: email_unconfirmed). Podpinają się tu potwierdzenie adresu
		 * i powiązanie zgody z wersją regulaminu.
		 *
		 * @param int $userId Identyfikator nowego użytkownika.
		 */
		do_action( self::REGISTERED_HOOK, $userId ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej REGISTERED_HOOK, która ma prefiks pusers_.

		// 8. PRG.
		self::redirect( add_query_arg( self::DONE_ARG, '1', self::return_url() ) );
	}

	/**
	 * Zasila szew powtórnego wypełnienia formularza po nieudanym zgłoszeniu. Haseł nie ma w
	 * przechowywanych danych, więc nie ma jak ich oddać.
	 *
	 * @param mixed $input Dotychczasowa wartość filtra.
	 * @return array<mixed>
	 */
	public static function old_input( mixed $input ): array {
		$base  = is_array( $input ) ? $input : array();
		$flash = self::flash();
		if ( '' === $flash['profession'] && '' === $flash['email'] && array() === $flash['fields'] ) {
			return $base;
		}

		return array_merge(
			$base,
			array(
				'profession' => $flash['profession'],
				'email'      => $flash['email'],
				'fields'     => $flash['fields'],
			)
		);
	}

	/**
	 * Dane przekierowania z bieżącego adresu: błędy, ostrzeżenia, komunikat i wpisane wartości.
	 * Puste, gdy adres nie niesie ważnego klucza. Klucz jest losowy i wygasa, a dane nie zawierają haseł.
	 *
	 * @return array{profession: string, email: string, fields: array<string, mixed>, errors: array<string, string>, warnings: array<string, string>, notice: string}
	 */
	public static function flash(): array {
		$empty = array(
			'profession' => '',
			'email'      => '',
			'fields'     => array(),
			'errors'     => array(),
			'warnings'   => array(),
			'notice'     => '',
		);
		// Odczyt po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$token = isset( $_GET[ self::FLASH_ARG ] ) && is_string( $_GET[ self::FLASH_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FLASH_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return $empty;
		}
		$raw = get_transient( self::FLASH_PREFIX . $token );
		if ( ! is_array( $raw ) ) {
			return $empty;
		}

		$fields = array();
		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $name => $value ) {
				$fields[ (string) $name ] = $value;
			}
		}

		return array(
			'profession' => isset( $raw['profession'] ) && is_string( $raw['profession'] ) ? $raw['profession'] : '',
			'email'      => isset( $raw['email'] ) && is_string( $raw['email'] ) ? $raw['email'] : '',
			'fields'     => $fields,
			'errors'     => self::codes( $raw['errors'] ?? null ),
			'warnings'   => self::codes( $raw['warnings'] ?? null ),
			'notice'     => isset( $raw['notice'] ) && is_string( $raw['notice'] ) ? $raw['notice'] : '',
		);
	}

	/**
	 * Czy adres niesie znacznik udanej rejestracji.
	 */
	public static function completed(): bool {
		// Odczyt po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		return isset( $_GET[ self::DONE_ARG ] ) && '1' === $_GET[ self::DONE_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Tekst komunikatu dla kodu błędu, ostrzeżenia albo powodu odmowy.
	 *
	 * @param string $code Kod.
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case 'required':
				return __( 'To pole jest wymagane.', 'portal-users' );
			case 'unknown_profession':
				return __( 'Wybierz profesję z listy.', 'portal-users' );
			case 'email_invalid':
				return __( 'Podaj poprawny adres e-mail.', 'portal-users' );
			case 'email_exists':
				return __( 'Konto z tym adresem e-mail już istnieje.', 'portal-users' );
			case 'password_missing':
				return __( 'Podaj hasło.', 'portal-users' );
			case 'password_mismatch':
				return __( 'Hasła nie są takie same.', 'portal-users' );
			case 'unavailable':
				return __( 'Rejestracja dla wybranej profesji jest chwilowo niedostępna.', 'portal-users' );
			case 'stale_form':
				return __( 'Formularz jest nieaktualny. Odśwież stronę i wypełnij go ponownie.', 'portal-users' );
			case 'too_fast':
				return __( 'Formularz wysłano zbyt szybko. Poczekaj chwilę i spróbuj ponownie.', 'portal-users' );
			case 'too_many':
				return __( 'Zbyt wiele prób rejestracji. Spróbuj ponownie później.', 'portal-users' );
			case 'rejected':
				return __( 'Nie udało się przyjąć zgłoszenia.', 'portal-users' );
			case 'credential_warning':
				return __( 'Numer uprawnień wygląda na niepoprawny. Sprawdź go; redakcja i tak zweryfikuje zgłoszenie.', 'portal-users' );
			default:
				return __( 'Nie udało się utworzyć konta. Spróbuj ponownie później.', 'portal-users' );
		}
	}

	/**
	 * Zapisuje dane na czas przekierowania i odsyła na formularz z kluczem w adresie.
	 *
	 * @param array{profession?: string, email?: string, fields?: array<string, mixed>, errors?: array<string, string>, warnings?: array<string, string>, notice?: string} $data Dane do pokazania po powrocie.
	 */
	private static function back( array $data ): void {
		$token = wp_generate_password( 32, false, false );
		foreach ( array( 'profession', 'email' ) as $name ) {
			if ( isset( $data[ $name ] ) ) {
				$data[ $name ] = sanitize_text_field( $data[ $name ] );
			}
		}
		set_transient( self::FLASH_PREFIX . $token, $data, self::FLASH_TTL );
		self::redirect( add_query_arg( self::FLASH_ARG, $token, self::return_url() ) );
	}

	/**
	 * Przekierowuje i kończy żądanie. Przekierowanie anulowane filtrem `wp_redirect` nie kończy żądania
	 * (szew testowy), więc wywołujący po `back()` zawsze wraca.
	 *
	 * @param string $url Adres docelowy.
	 */
	private static function redirect( string $url ): void {
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}

	/**
	 * Strona formularza bez śladów poprzedniego przekierowania: strona, z której przyszło zgłoszenie,
	 * a bez niej strona rejestracji z ustawień albo strona główna.
	 */
	private static function return_url(): string {
		$referer = wp_get_referer();
		if ( false === $referer ) {
			$page    = SettingsRepository::get()->registerPageId;
			$link    = $page > 0 ? get_permalink( $page ) : false;
			$referer = false === $link ? home_url( '/' ) : $link;
		}

		return remove_query_arg( array( self::FLASH_ARG, self::DONE_ARG ), $referer );
	}

	/**
	 * Powód odmowy antyspamu jako kod komunikatu. Przeterminowany formularz dostaje własny komunikat
	 * (odśwież stronę), a honeypot i odmowy rozszerzeń zostają ogólne: bot nie uczy się, co go zdradziło.
	 *
	 * @param SpamVerdict $verdict Odmowa.
	 */
	private static function spam_notice( SpamVerdict $verdict ): string {
		switch ( $verdict->reason() ) {
			case SpamVerdict::STALE_FORM:
				return 'stale_form';
			case SpamVerdict::TOO_FAST:
				return 'too_fast';
			case SpamVerdict::IP_LIMIT:
			case SpamVerdict::EMAIL_LIMIT:
				return 'too_many';
			default:
				return 'rejected';
		}
	}

	/**
	 * Domyślna rola witryny albo null, gdyby rejestracja miała nadawać rolę administracyjną
	 * (zepsuta konfiguracja nie może zamienić publicznego formularza w kreator administratorów).
	 */
	private static function default_role(): ?string {
		$name = get_option( 'default_role' );
		$role = is_string( $name ) ? get_role( $name ) : null;
		if ( ! is_string( $name ) || null === $role || $role->has_cap( 'manage_options' ) || $role->has_cap( 'promote_users' ) || $role->has_cap( 'edit_users' ) ) {
			return null;
		}

		return $name;
	}

	/**
	 * Login z adresu e-mail: część lokalna, bez małpy (login jest publiczny, np. w adresie archiwum
	 * autora, a adres e-mail nie), z numerem, gdy zajęty.
	 *
	 * @param string $email Adres e-mail.
	 */
	private static function login_for( string $email ): string {
		$local = substr( sanitize_user( (string) strstr( $email, '@', true ), true ), 0, 40 );
		$base  = '' === $local ? 'user' : $local;
		$login = $base;
		$n     = 2;
		while ( false !== username_exists( $login ) ) {
			$login = $base . '-' . $n;
			++$n;
		}

		return $login;
	}

	/**
	 * Czy pole ACF wolno zapisać jako metadaną użytkownika: nazwa nie może trafić w metadane
	 * wtyczki, chronione ani w uprawnienia konta (pole o nazwie `wp_capabilities` nadałoby rolę).
	 *
	 * @param string $name Nazwa pola.
	 */
	private static function may_store( string $name ): bool {
		return '' !== $name
			&& ! in_array( $name, UserMeta::all(), true )
			&& ! is_protected_meta( $name, 'user' )
			&& 'session_tokens' !== $name
			&& ! str_ends_with( $name, 'capabilities' )
			&& ! str_ends_with( $name, 'user_level' );
	}

	/**
	 * Sanityzuje wartości pól: teksty przez `sanitize_text_field()`, tablice rekurencyjnie, liczby i
	 * wartości logiczne jako tekst. Nic innego nie przechodzi (obiekty, null).
	 *
	 * @param array<string, mixed> $values Wartości.
	 * @return array<string, mixed>
	 */
	private static function clean_values( array $values ): array {
		$out = array();
		foreach ( $values as $name => $value ) {
			$out[ (string) $name ] = self::clean( $value );
		}

		return $out;
	}

	/**
	 * Sanityzuje jedną wartość.
	 *
	 * @param mixed $value Wartość.
	 */
	private static function clean( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : sanitize_text_field( $key ) ] = self::clean( $item );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Pole tekstowe z żądania; wartość nietekstowa to brak.
	 *
	 * @param array<mixed> $request Żądanie po wp_unslash.
	 * @param string       $name    Nazwa pola.
	 * @param bool         $trim    Czy obciąć białe znaki (hasła nie obcinamy).
	 */
	private static function text( array $request, string $name, bool $trim = true ): string {
		$value = $request[ $name ] ?? '';
		if ( ! is_string( $value ) ) {
			return '';
		}

		return $trim ? trim( $value ) : $value;
	}

	/**
	 * Mapa pole => kod z danych przechowywanych; wpisy o złym kształcie są pomijane.
	 *
	 * @param mixed $raw Dane.
	 * @return array<string, string>
	 */
	private static function codes( mixed $raw ): array {
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $name => $code ) {
				if ( is_string( $code ) ) {
					$out[ (string) $name ] = $code;
				}
			}
		}

		return $out;
	}
}
