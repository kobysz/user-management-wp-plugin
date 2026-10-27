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
 * powstaje w stanie `email_unconfirmed` z domyślną rolą witryny; mail potwierdzający (`Confirmation`)
 * i wersja regulaminu podpinają się pod akcję `pusers_account_registered`. Zgłoszenie na adres, który
 * ma już konto, dostaje TĘ SAMĄ odpowiedź co zgłoszenie przyjęte, żeby formularz nie wyliczał kont.
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
	 * Wpis w logu zdarzeń o wysłanym mailu z powodu zgłoszenia na istniejący adres.
	 */
	public const EXISTING_LOG_ACTION = 'registration_existing_email';

	/**
	 * Neutralny znacznik w adresie: „zgłoszenie nie przeszło, jest co pokazać”. Nie niesie żadnego
	 * identyfikatora danych; klucz do nich jedzie w ciasteczku (adres wyciekałby referrerem, historią i logami).
	 */
	public const FAILED_ARG = 'pusers_failed';

	/**
	 * Ciasteczko z jednorazowym kluczem błędów i wpisanych wartości (przeżywają PRG).
	 */
	public const COOKIE = 'pusers_flash';

	/**
	 * Szew wysyłania ciasteczka (testy go przechwytują i zwracają false); argumenty: nazwa, wartość, opcje.
	 */
	public const COOKIE_FILTER = 'pusers_registration_send_cookie';

	/**
	 * Argument adresu po udanej rejestracji.
	 */
	public const DONE_ARG = 'pusers_registered';

	private const FLASH_PREFIX = 'pusers_flash_';
	private const FLASH_TTL    = 10 * MINUTE_IN_SECONDS;

	/**
	 * Wartość znacznika w adresie po odmowie nonce'a (bez danych w magazynie).
	 */
	public const STALE = 'stale';

	/**
	 * Przedrostek metadanych z wartościami pól ACF: kolizja z metadaną WordPressa jest wtedy niemożliwa z konstrukcji.
	 * Ekran wniosków czyta wartości z tym przedrostkiem.
	 */
	public const ACF_META_PREFIX = 'pusers_acf_';

	/**
	 * Minimalna długość hasła (znaków).
	 */
	public const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Uprawnienia, z którymi rola domyślna nie może być nadawana przez publiczny formularz.
	 *
	 * Guard istnieje WYŁĄCZNIE dla roli nietypowej albo podmienionej, więc argument „domyślne role
	 * WordPressa tego nie mają” nie ma tu wartości: w jedynym przypadku, w którym lista pracuje,
	 * rola jest własna. Lista zakazów obejmuje zarządzanie kontami, wykonanie i aktualizację kodu,
	 * nieprzefiltrowany HTML, wyprowadzenie danych oraz uprawnienia sieciowe.
	 *
	 * Uprawnienia do treści (`edit_posts`, `publish_posts`, `upload_files`) NIE są tu wymienione:
	 * rejestracja musi działać na portalu z rolą domyślną `subscriber`, `contributor` albo `author`.
	 */
	private const DANGEROUS_CAPABILITIES = array(
		// Zarządzanie kontami: samorejestrujące się konto nie może awansować siebie ani zakładać kont.
		'manage_options',
		'promote_users',
		'edit_users',
		'create_users',
		'delete_users',
		'add_users',
		'remove_users',
		// Lista kont to w portalu medycznym lista adresów e-mail wszystkich lekarzy: wyciek sam w sobie.
		'list_users',
		// Wykonanie kodu: instalacja, aktualizacja, podmiana i usuwanie wtyczek, motywów oraz rdzenia.
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'delete_plugins',
		'update_plugins',
		'install_themes',
		'edit_themes',
		'switch_themes',
		'delete_themes',
		'update_themes',
		'update_core',
		'edit_files',
		// Wgranie pliku dowolnego typu (także PHP) to wykonanie kodu równie dobre jak edycja wtyczki.
		'unfiltered_upload',
		// Skrypt na całej witrynie: nieprzefiltrowany HTML w treści oraz widżet HTML w personalizacji.
		'unfiltered_html',
		'edit_theme_options',
		// Wyprowadzenie i zniszczenie danych osobowych: eksport dotyczy danych WSZYSTKICH użytkowników.
		'import',
		'export',
		'export_others_personal_data',
		'erase_others_personal_data',
		'delete_site',
		// Sieć (multisite): wszystko powyżej, tylko na wszystkich witrynach naraz.
		'manage_network',
		'manage_network_options',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_users',
		'manage_sites',
	);

	/**
	 * Zdarzenie w logu: zgłoszenie odrzucone, bo domyślna rola witryny nie może być nadana.
	 */
	public const ROLE_REFUSAL_LOG_ACTION = 'registration_refused_default_role';

	/**
	 * Opcja z momentem ostatniego wpisu o odmowie guarda roli. Opcja, nie transient: przy niedostępnym
	 * magazynie transientów flaga nie działałaby, a wiersz na każde żądanie zapchałby tabelę audytu,
	 * która jest śladem decyzji redakcji o zatwierdzaniu kont.
	 */
	public const ROLE_REFUSAL_OPTION = 'pusers_role_refusal_logged_at';

	/**
	 * Przedrostek klucza błędu pola ACF, które nazywa się jak pole konta (kolizja kluczy).
	 */
	public const ACF_ERROR_PREFIX = 'acf:';

	private const MAX_ITEMS       = 50;
	private const MAX_VALUE_BYTES = 1000;
	private const MAX_KEY_BYTES   = 100;
	private const MAX_TOTAL_BYTES = 10000;
	private const MAX_DEPTH       = 2;

	/**
	 * Odczytane w tym żądaniu dane (klucz => dane): transient znika przy pierwszym odczycie, a formularz czyta go kilka razy.
	 *
	 * @var array<string, array{profession: string, email: string, fields: array<string, mixed>, errors: array<string, string>, warnings: array<string, string>, notice: string}>
	 */
	private static array $read = array();

	/**
	 * Podpina obsługę zgłoszenia (także dla niezalogowanych) i zasila powtórne wypełnienie formularza.
	 */
	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
		// Nazwa filtra dosłownie (reguła prefiksów); to ta sama nazwa co RegistrationForm::OLD_INPUT_FILTER.
		add_filter( 'pusers_registration_old_input', array( self::class, 'old_input' ) );
	}

	/**
	 * Strona po zgłoszeniu (udanym albo nie) niesie dane osobowe z magazynu, więc nie może trafić do
	 * żadnego cache'a stron; ciasteczko z kluczem kasujemy tu, zanim wyjdą nagłówki (z treści strony
	 * `setcookie()` już nie poleci).
	 */
	public static function on_template_redirect(): void {
		// Odczyt po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		if ( ! isset( $_GET[ self::FAILED_ARG ] ) && ! isset( $_GET[ self::DONE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- stała uznawana przez wtyczki cache.
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		do_action( 'litespeed_control_set_nocache', 'pusers registration' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook cudzej wtyczki (LiteSpeed Cache).

		// Kasujemy przy OBU znacznikach: po nieudanym zgłoszeniu dane właśnie zostały odczytane (transient
		// zniknął), a po udanej rejestracji nikt ich już nie przeczyta i martwe ciasteczko żyłoby 10 minut.
		// Znacznik `stale` NIE kasuje: przy nim nie zapisaliśmy żadnych danych, a podrzucony komuś odnośnik
		// z `?pusers_failed=stale` wyrzucałby mu wpisane wartości, których jeszcze nie zobaczył.
		$failed = isset( $_GET[ self::FAILED_ARG ] ) && self::STALE !== $_GET[ self::FAILED_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_COOKIE[ self::COOKIE ] ) && ( $failed || isset( $_GET[ self::DONE_ARG ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request_path = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : null;
			self::send_cookie( '', time() - YEAR_IN_SECONDS, is_string( $request_path ) && '' !== $request_path ? $request_path : '/' );
		}
	}

	/**
	 * Obsługuje zgłoszenie.
	 */
	public static function handle(): void {
		// 1. Nonce. Nonce i podpisany znacznik czasu starzeją się razem, więc człowiek z kartą otwartą od doby
		// trafia właśnie tu: dostaje komunikat „odśwież stronę” przez PRG, nie 403. Nic z żądania nie jest zapisywane.
		$nonce = isset( $_POST[ RegistrationForm::NONCE_FIELD ] ) && is_string( $_POST[ RegistrationForm::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ RegistrationForm::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, RegistrationForm::NONCE_ACTION ) ) {
			self::redirect( add_query_arg( self::FAILED_ARG, self::STALE, self::return_url() ) );
			return;
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

		// 3. Antyspam, przed walidacją i przed jakimkolwiek odczytem pól. Pól z żądania nie zapisujemy:
		// kontekst (a z nim lista pól ze źródła) jeszcze nie istnieje, a dane bota nie mają trafiać do magazynu.
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
		if ( is_user_logged_in() || null === $context || null === $role ) {
			if ( is_user_logged_in() ) {
				$refusal = array( 'registration' => 'logged_in' );
			} elseif ( null === $context ) {
				$refusal = array( SubmissionResult::PROFESSION_KEY => 'unknown_profession' );
			} else {
				$refusal = array( 'registration' => 'registration_closed' );
				// Odwiedzający widzi „rejestracja chwilowo niedostępna”; administrator musi mieć skąd
				// się dowiedzieć, dlaczego. Drugim kanałem jest raport spójności w panelu.
				self::report_role_refusal();
			}
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'errors'     => $refusal,
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
		}
		// Adres, który ma już konto, NIE jest błędem walidacji: odpowiedź ma być identyczna jak dla adresu nowego
		// (inaczej formularz wyliczałby, kto ma konto w portalu medycznym). Rozstrzyga o tym dopiero krok 6.
		$existingId = isset( $errors[ RegistrationForm::EMAIL ] ) ? false : email_exists( $clean );
		if ( '' === $password ) {
			$errors[ RegistrationForm::PASSWORD ] = 'password_missing';
		} elseif ( mb_strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			$errors[ RegistrationForm::PASSWORD ] = 'password_short';
		} elseif ( $password !== $repeat ) {
			$errors[ RegistrationForm::PASSWORD_REPEAT ] = 'password_mismatch';
		}
		// Błąd pola ACF o nazwie pola wtyczki nie ginie: dostaje klucz z przedrostkiem.
		foreach ( $result->errors() as $field => $code ) {
			$errors[ isset( $errors[ $field ] ) ? self::ACF_ERROR_PREFIX . $field : $field ] = $code;
		}

		// Profesja wymagająca numeru uprawnień nie dostaje konta bez numeru. Miękka walidacja dotyczy numerów
		// prawdziwych, ale nietypowych (zła suma, separatory); wartość o złym typie (tablica) nie ma czego
		// weryfikować redakcji, więc to błąd twardy.
		$credentialField = $profession->credentialField;
		if ( $profession->requiresCredential() && '' !== $credentialField && ! isset( $errors[ $credentialField ] ) ) {
			$number = $result->values()[ $credentialField ] ?? null;
			if ( ! is_string( $number ) ) {
				$errors[ $credentialField ] = null === $number ? 'required' : 'invalid_type';
			} elseif ( '' === trim( $number ) ) {
				$errors[ $credentialField ] = 'required';
			}
		}

		// Błąd twardy i ostrzeżenie o tym samym polu opisują jeden fakt: ostrzeżenie ustępuje błędowi.
		$warnings = array();
		if ( '' !== $result->credentialWarning() && ! isset( $errors[ $credentialField ] ) ) {
			$warnings[ $credentialField ] = $result->credentialWarning();
		}

		if ( array() !== $errors ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'fields'     => self::flash_values( $raw ),
					'errors'     => $errors,
					'warnings'   => $warnings,
				)
			);
			return;
		}

		// 6a. Adres ma już konto: odpowiedź jest TA SAMA co dla zgłoszenia przyjętego (przekierowanie z DONE_ARG),
		// konto nie powstaje i nic się w istniejącym nie zmienia. Właściciel dostaje mail o próbie, pod tym samym
		// progiem godzinnym co ponowna wysyłka. Licznika rejestracji adresu (record_registration) tu nie ruszamy:
		// o odmowie decyduje próg maili, a odpowiedź formularza nie zmienia się nigdy.
		if ( false !== $existingId ) {
			self::acknowledge_existing( (int) $existingId, $clean );
			self::redirect( add_query_arg( self::DONE_ARG, '1', self::return_url() ) );
			return;
		}

		// 6. Utworzenie konta. Hasło idzie wyłącznie do wp_insert_user(): ani do flash, ani do metadanych.
		// Login jest losowy i nieodgadywalny, a display_name (widoczny publicznie) to ten sam login: imię i nazwisko redakcja czyta z pól ACF.
		$login  = self::random_login();
		$userId = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $clean,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => $login,
			)
		);
		if ( is_wp_error( $userId ) && 'existing_user_email' === $userId->get_error_code() ) {
			// Wyścig: adres zajęto między sprawdzeniem a zapisem. Odpowiedź jak dla adresu istniejącego.
			$raceId = email_exists( $clean );
			if ( false !== $raceId ) {
				self::acknowledge_existing( (int) $raceId, $clean );
			}
			self::redirect( add_query_arg( self::DONE_ARG, '1', self::return_url() ) );
			return;
		}
		if ( is_wp_error( $userId ) ) {
			self::back(
				array(
					'profession' => $key,
					'email'      => $email,
					'fields'     => self::flash_values( $raw ),
					'errors'     => array( RegistrationForm::EMAIL => 'account_failed' ),
				)
			);
			return;
		}

		update_user_meta( $userId, UserMeta::PROFESSION, $profession->key );
		AccountStatusRepository::set( $userId, AccountStatus::EmailUnconfirmed );
		// Odsiew po macierzy z kontekstu, potem sanityzacja: values() może nieść tablicę, a do metadanych
		// wchodzi wyłącznie tekst (patrz stored_values()). Wartości pól ACF zapisujemy z przedrostkiem,
		// więc kolizja z jakąkolwiek metadaną WordPressa jest niemożliwa z konstrukcji.
		$stored = ( new SubmissionFilter( $matrix ) )->strip( $profession->key, $result->values() );
		foreach ( self::stored_values( $stored ) as $name => $value ) {
			update_user_meta( $userId, self::ACF_META_PREFIX . $name, $value );
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
	 * Obsługa zgłoszenia na adres, który ma już konto: mail do właściciela o próbie, pod progiem godzinnym
	 * wspólnym z ponowną wysyłką. Nic w koncie się nie zmienia. Do logu trafia tylko wysłana wiadomość.
	 *
	 * @param int    $existingId Identyfikator istniejącego użytkownika.
	 * @param string $email      Adres ze zgłoszenia.
	 */
	private static function acknowledge_existing( int $existingId, string $email ): void {
		// Licznik rejestracji adresu rośnie tak samo jak przy adresie wolnym, więc próg (także ustawiony na 1) trafia
		// obie odpowiedzi symetrycznie i nie wyliczają kont.
		SpamGuard::record_registration( $email );
		if ( Mailer::may_send_to( $email, Mailer::PURPOSE_ATTEMPT ) && Mailer::existing_account( $existingId ) ) {
			EventLog::write( self::EXISTING_LOG_ACTION, $existingId, 0, array() );
		}
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
		// Odczyt po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje. Bez znacznika w adresie nic nie czytamy.
		if ( ! isset( $_GET[ self::FAILED_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $empty;
		}
		// Odmowa nonce'a: komunikat „odśwież stronę” bez żadnych danych w magazynie.
		if ( self::STALE === $_GET[ self::FAILED_ARG ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array_merge( $empty, array( 'notice' => 'stale_form' ) );
		}
		// Znacznik jest, a danych nie ma (ciasteczko zablokowane, wygasłe, cudze): formularz jest pusty, ale mówi, że zgłoszenie nie przeszło.
		$lost  = array_merge( $empty, array( 'notice' => 'lost' ) );
		$token = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return $lost;
		}
		if ( isset( self::$read[ $token ] ) ) {
			return self::$read[ $token ];
		}
		$raw = get_transient( self::FLASH_PREFIX . $token );
		// Jednorazowość: dane znikają przy pierwszym odczycie (wynik zostaje w pamięci tego żądania); ciasteczko kasuje on_template_redirect().
		delete_transient( self::FLASH_PREFIX . $token );
		if ( ! is_array( $raw ) ) {
			return $lost;
		}

		$fields = array();
		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			foreach ( $raw['fields'] as $name => $value ) {
				$fields[ (string) $name ] = $value;
			}
		}

		self::$read[ $token ] = array(
			'profession' => isset( $raw['profession'] ) && is_string( $raw['profession'] ) ? $raw['profession'] : '',
			'email'      => isset( $raw['email'] ) && is_string( $raw['email'] ) ? $raw['email'] : '',
			'fields'     => $fields,
			'errors'     => self::codes( $raw['errors'] ?? null ),
			'warnings'   => self::codes( $raw['warnings'] ?? null ),
			'notice'     => isset( $raw['notice'] ) && is_string( $raw['notice'] ) ? $raw['notice'] : '',
		);

		return self::$read[ $token ];
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
			case 'password_missing':
				return __( 'Podaj hasło.', 'portal-users' );
			case 'password_short':
				return sprintf(
					/* translators: %d: minimalna liczba znaków hasła. */
					__( 'Hasło musi mieć co najmniej %d znaków.', 'portal-users' ),
					self::MIN_PASSWORD_LENGTH
				);
			case 'invalid_type':
				return __( 'Podaj wartość jako tekst.', 'portal-users' );
			case 'logged_in':
				return __( 'Jesteś już zalogowany, więc nie możesz założyć kolejnego konta.', 'portal-users' );
			case 'registration_closed':
				return __( 'Rejestracja jest chwilowo niedostępna.', 'portal-users' );
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
			case 'lost':
				return __( 'Zgłoszenie nie zostało przyjęte, a wpisanych danych nie udało się zachować. Wypełnij formularz ponownie.', 'portal-users' );
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

		// Klucz jedzie w ciasteczku ograniczonym do strony formularza, nie w adresie.
		$url  = self::return_url();
		$path = wp_parse_url( $url, PHP_URL_PATH );
		self::send_cookie( $token, time() + self::FLASH_TTL, is_string( $path ) && '' !== $path ? $path : '/' );
		self::redirect( add_query_arg( self::FAILED_ARG, '1', $url ) );
	}

	/**
	 * Wysyła (albo, przy pustej wartości i dacie z przeszłości, kasuje) ciasteczko z kluczem danych.
	 *
	 * @param string $value   Wartość.
	 * @param int    $expires Moment wygaśnięcia.
	 * @param string $path    Ścieżka ciasteczka.
	 */
	private static function send_cookie( string $value, int $expires, string $path ): void {
		$options = array(
			'expires'  => $expires,
			'path'     => $path,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		/**
		 * Szew wysyłania ciasteczka; zwrócenie false pomija `setcookie()`.
		 *
		 * @param bool                 $send    Czy wysłać.
		 * @param string               $name    Nazwa ciasteczka.
		 * @param string               $value   Wartość.
		 * @param array<string, mixed> $options Opcje.
		 */
		if ( apply_filters( 'pusers_registration_send_cookie', true, self::COOKIE, $value, $options ) && ! headers_sent() ) {
			setcookie( self::COOKIE, $value, $options );
		}
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

		return remove_query_arg( array( self::FAILED_ARG, self::DONE_ARG ), $referer );
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
	 * Stan domyślnej roli witryny: nazwa z opcji, pierwsze uprawnienie niebezpieczne (albo `''`, gdy
	 * żadnego nie ma ani nie ma samej roli) i czy rejestracja jest z tego powodu wyłączona.
	 *
	 * Publiczna, bo panel pokazuje przyczynę odmowy w raporcie spójności i musi ją czytać dokładnie
	 * tam, gdzie bramka ją rozstrzyga; dwie kopie reguły rozjechałyby się przy pierwszej zmianie listy.
	 * Rola domyślna jest pojęciem WordPressa, więc reguła nie może mieszkać w `src/Domain`.
	 *
	 * @return array{role: string, capability: string, blocked: bool}
	 */
	public static function default_role_state(): array {
		$option = get_option( 'default_role' );
		$name   = is_string( $option ) ? $option : '';
		$role   = '' === $name ? null : get_role( $name );
		if ( null === $role ) {
			return array(
				'role'       => $name,
				'capability' => '',
				'blocked'    => true,
			);
		}
		foreach ( self::DANGEROUS_CAPABILITIES as $capability ) {
			if ( $role->has_cap( $capability ) ) {
				return array(
					'role'       => $name,
					'capability' => $capability,
					'blocked'    => true,
				);
			}
		}

		return array(
			'role'       => $name,
			'capability' => '',
			'blocked'    => false,
		);
	}

	/**
	 * Domyślna rola witryny albo null, gdyby rejestracja miała nadać rolę z uprawnieniem niebezpiecznym
	 * albo rolę, której nie ma (zepsuta konfiguracja nie może zamienić publicznego formularza
	 * w kreator administratorów).
	 */
	private static function default_role(): ?string {
		$state = self::default_role_state();

		return $state['blocked'] ? null : $state['role'];
	}

	/**
	 * Zapisuje w logu zdarzeń odmowę guarda roli, z nazwą uprawnienia, NAJWYŻEJ RAZ NA GODZINĘ: usterka
	 * konfiguracji trwa do interwencji administratora, a wiersz na każde żądanie zapchałby tabelę audytu
	 * (ślad decyzji redakcji o zatwierdzaniu kont, który przeżywa nawet żądanie usunięcia danych osobowych).
	 */
	private static function report_role_refusal(): void {
		$last = get_option( self::ROLE_REFUSAL_OPTION, 0 );
		if ( is_numeric( $last ) && time() - (int) $last < HOUR_IN_SECONDS ) {
			return;
		}
		update_option( self::ROLE_REFUSAL_OPTION, time(), false );

		$state = self::default_role_state();
		EventLog::write(
			self::ROLE_REFUSAL_LOG_ACTION,
			0,
			0,
			array(
				'role'       => $state['role'],
				'capability' => $state['capability'],
				'reason'     => '' === $state['capability'] ? 'role_unknown' : 'dangerous_capability',
			)
		);
	}

	/**
	 * Losowy, nieodgadywalny login. Login z adresu byłby przewidywalny z imienia i nazwiska, a
	 * wp-login.php odróżnia „nie ma takiego loginu” od „złe hasło”.
	 */
	private static function random_login(): string {
		do {
			$login = 'u' . strtolower( wp_generate_password( 14, false, false ) );
		} while ( false !== username_exists( $login ) );

		return $login;
	}

	/**
	 * Wartości do metadanych: sanityzowane i ZAWSZE tekstowe. Tablica (wielokrotny wybór, ale też
	 * tablica podrzucona w polu tekstowym) jedzie jako lista rozdzielana przecinkami: zserializowana
	 * tablica w `wp_usermeta` wywaliłaby na `TypeError` każdego późniejszego konsumenta deklarującego
	 * `string` przy `strict_types`, a w zapytaniach po metadanych jest nieczytelna.
	 *
	 * @param array<string, mixed> $values Wartości po odsiewie macierzą.
	 * @return array<string, string>
	 */
	private static function stored_values( array $values ): array {
		$out = array();
		foreach ( $values as $name => $value ) {
			$out[ (string) $name ] = self::text_value( $value );
		}

		return $out;
	}

	/**
	 * Jedna wartość jako sanityzowany tekst; tablica rekurencyjnie, z pominięciem pustych gałęzi.
	 *
	 * @param mixed $value Wartość.
	 */
	private static function text_value( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return self::scalar_text( $value );
		}
		$parts = array();
		foreach ( $value as $item ) {
			$text = self::text_value( $item );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Wartość nietablicowa jako tekst: teksty przez `sanitize_text_field()`, liczby i wartości logiczne
	 * jako tekst. Nic innego nie przechodzi (obiekty, null).
	 *
	 * @param mixed $value Wartość.
	 */
	private static function scalar_text( mixed $value ): string {
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
	 * Wartości na czas przekierowania: ta sama sanityzacja, ale pod twardym limitem rozmiaru, bo
	 * struktura ląduje w `wp_options` (transient). Tablice ZOSTAJĄ tablicami: wracają do formularza
	 * jako wartość początkowa pola wielokrotnego wyboru.
	 *
	 * Limit jest BAJTOWY, nie znakowy (polska litera to dwa bajty, emoji cztery), obejmuje też KLUCZE
	 * tablic i CAŁKOWITY rozmiar struktury: bez tego jedno zgłoszenie z ważnym nonce'em zapisywało
	 * transient rozmiaru całego żądania, do `post_max_size`.
	 *
	 * @param array<string, mixed> $values Wartości z żądania.
	 * @return array<string, mixed>
	 */
	private static function flash_values( array $values ): array {
		$budget = self::MAX_TOTAL_BYTES;
		$out    = array();
		foreach ( array_slice( $values, 0, self::MAX_ITEMS, true ) as $name => $value ) {
			if ( $budget <= 0 ) {
				break;
			}
			$key         = self::cut( sanitize_text_field( (string) $name ), self::MAX_KEY_BYTES );
			$budget     -= strlen( $key );
			$out[ $key ] = self::limited( $value, 0, $budget );
		}

		return $out;
	}

	/**
	 * Jedna wartość pod limitem rozmiaru.
	 *
	 * @param mixed $value  Wartość.
	 * @param int   $depth  Głębokość zagnieżdżenia (0 to wartość pola).
	 * @param int   $budget Pozostały budżet bajtów; każda zapisana wartość i każdy klucz go pomniejszają.
	 */
	private static function limited( mixed $value, int $depth, int &$budget ): mixed {
		if ( ! is_array( $value ) ) {
			$text    = self::cut( self::scalar_text( $value ), min( self::MAX_VALUE_BYTES, $budget ) );
			$budget -= strlen( $text );

			return $text;
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return array();
		}

		$out = array();
		foreach ( array_slice( $value, 0, self::MAX_ITEMS, true ) as $key => $item ) {
			if ( $budget <= 0 ) {
				break;
			}
			// Klucz liczbowy też zajmuje miejsce w zapisanej strukturze, ale nie podlega sanityzacji.
			$clean         = is_int( $key ) ? $key : self::cut( sanitize_text_field( (string) $key ), self::MAX_KEY_BYTES );
			$budget       -= strlen( (string) $clean );
			$out[ $clean ] = self::limited( $item, $depth + 1, $budget );
		}

		return $out;
	}

	/**
	 * Obcina tekst do zadanej liczby BAJTÓW (nie znaków), nie rozcinając znaku UTF-8 w połowie.
	 *
	 * @param string $text  Tekst.
	 * @param int    $bytes Limit bajtów.
	 */
	private static function cut( string $text, int $bytes ): string {
		return $bytes <= 0 ? '' : mb_strcut( $text, 0, $bytes );
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
