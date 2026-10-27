<?php
/**
 * Potwierdzenie adresu e-mail tokenem i przejście stanu konta.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Przebieg: konto powstaje jako `email_unconfirmed`, `issue()` daje token do linku w mailu, a w metadanych
 * zostaje wyłącznie jego skrót z terminem ważności. Poprawny token przenosi konto w `pending` (akceptacja
 * włączona) albo prosto w `approved` (wyłączona) i jest zużywany. Przejście robi się WYŁĄCZNIE ze stanu
 * `email_unconfirmed`: ponowne kliknięcie starego linku nie cofa decyzji redakcji.
 */
final class Confirmation {

	public const QUERY_USER    = 'pusers_uid';
	public const QUERY_TOKEN   = 'pusers_token';
	public const ACTION_RESEND = 'pusers_resend';

	/**
	 * Argument adresu z wynikiem potwierdzenia (wartości RESULT_*), po przekierowaniu.
	 */
	public const RESULT_ARG = 'pusers_confirmed';

	/**
	 * Argument adresu po ponownej wysyłce.
	 */
	public const RESENT_ARG = 'pusers_resent';

	public const NONCE_ACTION = 'pusers_resend';
	public const NONCE_FIELD  = 'pusers_resend_nonce';
	public const EMAIL_FIELD  = 'pusers_resend_email';

	public const RESULT_OK      = 'ok';
	public const RESULT_EXPIRED = 'expired';
	public const RESULT_INVALID = 'invalid';
	public const RESULT_ALREADY = 'already';

	/**
	 * Zdarzenie w logu: adres potwierdzony, konto zmieniło stan.
	 */
	public const LOG_ACTION = 'account_confirmed';

	private const BUCKET_RESEND_IP = 'resend_ip:';

	/**
	 * Podpina potwierdzenie pod utworzenie konta, adres z tokenem i formularz ponownej wysyłki.
	 */
	public static function register(): void {
		add_action( RegistrationHandler::REGISTERED_HOOK, array( self::class, 'on_registered' ) );
		add_action( 'template_redirect', array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_RESEND, array( self::class, 'handle_resend' ) );
		add_action( 'admin_post_' . self::ACTION_RESEND, array( self::class, 'handle_resend' ) );
	}

	/**
	 * Po utworzeniu konta wysyła link potwierdzający.
	 *
	 * @param mixed $userId Identyfikator nowego użytkownika.
	 */
	public static function on_registered( mixed $userId ): void {
		if ( ! is_int( $userId ) || $userId <= 0 ) {
			return;
		}

		self::send_link( $userId );
	}

	/**
	 * Wystawia token: zwraca jawny (do linku), zapisuje wyłącznie skrót i termin ważności.
	 * Kolejne wywołanie nadpisuje poprzedni skrót, więc poprzedni token przestaje działać.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	public static function issue( int $userId ): string {
		$token = bin2hex( random_bytes( 32 ) );

		update_user_meta( $userId, UserMeta::CONFIRM_HASH, self::hash( $token ) );
		update_user_meta( $userId, UserMeta::CONFIRM_EXPIRES, time() + SettingsRepository::get()->confirmationTokenHours * HOUR_IN_SECONDS );

		return $token;
	}

	/**
	 * PRÓBUJE potwierdzić adres: przy poprawnym tokenie ZMIENIA stan konta, zużywa token i wysyła mail do redakcji
	 * (nie jest czystym sprawdzeniem). Zwraca, czy potwierdzenie się powiodło.
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $token  Token jawny z linku.
	 */
	public static function try_confirm( int $userId, string $token ): bool {
		return self::RESULT_OK === self::confirm( $userId, $token );
	}

	/**
	 * Potwierdza adres i zwraca wynik (RESULT_*).
	 *
	 * Wynik „wygasł” i „już potwierdzony” zwracamy dopiero po zgodności skrótu: bez poprawnego tokenu
	 * odpowiedź jest zawsze ta sama i nie mówi nic o koncie.
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $token  Token jawny z linku.
	 */
	public static function confirm( int $userId, string $token ): string {
		if ( $userId <= 0 || '' === $token ) {
			return self::RESULT_INVALID;
		}

		$hash = get_user_meta( $userId, UserMeta::CONFIRM_HASH, true );
		if ( ! is_string( $hash ) || '' === $hash || ! hash_equals( $hash, self::hash( $token ) ) ) {
			return self::RESULT_INVALID;
		}

		// Decyzji redakcji (i każdego innego stanu niż niepotwierdzony) ten token nie ruszy.
		if ( AccountStatus::EmailUnconfirmed !== AccountStatusRepository::get( $userId ) ) {
			// Token niczego już nie otwiera, więc nie zostaje na koncie bezterminowo żywy.
			delete_user_meta( $userId, UserMeta::CONFIRM_HASH );
			delete_user_meta( $userId, UserMeta::CONFIRM_EXPIRES );
			return self::RESULT_ALREADY;
		}

		$expires = get_user_meta( $userId, UserMeta::CONFIRM_EXPIRES, true );
		if ( ! is_numeric( $expires ) || (int) $expires < time() ) {
			return self::RESULT_EXPIRED;
		}

		// Zużycie tokenu jest zarazem rezerwacją: delete_user_meta() zwraca true tylko jednemu z równoległych żądań.
		if ( ! delete_user_meta( $userId, UserMeta::CONFIRM_HASH, $hash ) ) {
			return self::RESULT_INVALID;
		}
		delete_user_meta( $userId, UserMeta::CONFIRM_EXPIRES );

		$next = SettingsRepository::get()->approvalEnabled ? AccountStatus::Pending : AccountStatus::Approved;
		AccountStatusRepository::set( $userId, $next );
		EventLog::write( self::LOG_ACTION, $userId, 0, array( 'status' => $next->value ) );
		Mailer::editorial( $userId );

		return self::RESULT_OK;
	}

	/**
	 * Rozpoznaje na stronie rejestracji odnośnik z maila, potwierdza i przekierowuje z wynikiem,
	 * żeby token nie został w adresie po odświeżeniu.
	 */
	public static function handle(): void {
		// Odczyt odnośnika z maila: token jest sekretem żądania, nonce nie ma tu sensu.
		if ( ! isset( $_GET[ self::QUERY_USER ], $_GET[ self::QUERY_TOKEN ] ) || ! is_string( $_GET[ self::QUERY_USER ] ) || ! is_string( $_GET[ self::QUERY_TOKEN ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$userId = absint( wp_unslash( $_GET[ self::QUERY_USER ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_TOKEN ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = self::confirm( $userId, $token );

		nocache_headers();
		self::redirect( add_query_arg( self::RESULT_ARG, $result, self::base_url() ) );
	}

	/**
	 * Ponowna wysyłka linku. Odpowiedź jest ZAWSZE ta sama: nie zdradza, czy adres ma konto, czy czeka ono
	 * na potwierdzenie, ani czy próg prób został przekroczony. Licznik rośnie tak samo dla każdego adresu.
	 */
	public static function handle_resend(): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- to jest weryfikacja nonce'a.
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::redirect( add_query_arg( RegistrationHandler::FAILED_ARG, RegistrationHandler::STALE, self::base_url() ) );
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		$raw   = isset( $_POST[ self::EMAIL_FIELD ] ) && is_string( $_POST[ self::EMAIL_FIELD ] ) ? sanitize_email( trim( wp_unslash( $_POST[ self::EMAIL_FIELD ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = is_email( $raw ) ? $raw : '';

		if ( '' !== $email && self::within_ip_limit() ) {
			$user = get_user_by( 'email', $email );
			// Licznik adresu rośnie tylko przy REALNEJ wysyłce: liczony bezwarunkowo pozwalałby osobie trzeciej
			// wyczerpać właścicielowi ponowną wysyłkę cudzymi żądaniami, a odpowiedź i tak jest zawsze ta sama.
			// Atakującego ogranicza próg per IP.
			if ( false !== $user && AccountStatus::EmailUnconfirmed === AccountStatusRepository::get( $user->ID ) && Mailer::may_send_to( $email, Mailer::PURPOSE_RESEND ) ) {
				self::send_link( $user->ID );
			}
		}

		self::redirect( add_query_arg( self::RESENT_ARG, '1', self::base_url() ) );
	}

	/**
	 * Komunikat o wyniku (potwierdzenia, ponownej wysyłki albo rejestracji) wraz z formularzem ponownej
	 * wysyłki tam, gdzie może się przydać. Pusty, gdy adres nie niesie żadnego znacznika.
	 */
	public static function notice_html(): string {
		// Odczyt po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$result = isset( $_GET[ self::RESULT_ARG ] ) && is_string( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resent = isset( $_GET[ self::RESENT_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stale  = isset( $_GET[ RegistrationHandler::FAILED_ARG ] ) && RegistrationHandler::STALE === $_GET[ RegistrationHandler::FAILED_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Przedawniony nonce (także formularza ponownej wysyłki): komunikat „odśwież stronę” pokazuje formularz rejestracji,
		// a tu dokładamy drogę powrotu do ponownej wysyłki.
		if ( $stale ) {
			return self::resend_form();
		}

		if ( $resent ) {
			return '<p class="pusers-notice">' . esc_html( self::message_for( 'resent' ) ) . '</p>';
		}
		if ( '' === $result ) {
			return '';
		}

		$message = '<p class="pusers-notice" role="status">' . esc_html( self::message_for( $result ) ) . '</p>';

		return in_array( $result, array( self::RESULT_EXPIRED, self::RESULT_INVALID ), true ) ? $message . self::resend_form() : $message;
	}

	/**
	 * Formularz ponownej wysyłki linku.
	 */
	public static function resend_form(): string {
		return '<form class="pusers-resend" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESEND ) . '" />'
			. wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false )
			. '<label>' . esc_html__( 'Adres e-mail', 'portal-users' ) . ' <input type="email" name="' . esc_attr( self::EMAIL_FIELD ) . '" required /></label> '
			. '<button type="submit">' . esc_html__( 'Wyślij link ponownie', 'portal-users' ) . '</button>'
			. '</form>';
	}

	/**
	 * Tekst komunikatu dla wyniku.
	 *
	 * @param string $code Wynik (RESULT_*) albo `resent`.
	 */
	public static function message_for( string $code ): string {
		switch ( $code ) {
			case self::RESULT_OK:
				return SettingsRepository::get()->approvalEnabled
					? __( 'Adres e-mail został potwierdzony. Redakcja rozpatrzy zgłoszenie; dostaniesz informację o decyzji.', 'portal-users' )
					: __( 'Adres e-mail został potwierdzony. Konto jest aktywne.', 'portal-users' );
			case self::RESULT_EXPIRED:
				return __( 'Link potwierdzający wygasł. Poproś o nowy.', 'portal-users' );
			case self::RESULT_ALREADY:
				return __( 'To konto zostało już rozpatrzone. Ten link nie jest już potrzebny.', 'portal-users' );
			case 'resent':
				return __( 'Jeżeli na ten adres czeka konto do potwierdzenia, wysłaliśmy nowy link.', 'portal-users' );
			default:
				return __( 'Link potwierdzający jest nieprawidłowy albo został już użyty.', 'portal-users' );
		}
	}

	/**
	 * Wystawia nowy token i wysyła link.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	private static function send_link( int $userId ): void {
		$token = self::issue( $userId );
		$link  = add_query_arg(
			array(
				self::QUERY_USER  => $userId,
				self::QUERY_TOKEN => $token,
			),
			self::base_url()
		);
		Mailer::confirmation( $userId, $link );
	}

	/**
	 * Skrót tokenu zapisywany w metadanych. Token ma 256 bitów losowości, więc zwykły SHA-256 wystarcza.
	 *
	 * @param string $token Token jawny.
	 */
	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Próg per adres IP dla ponownej wysyłki (ten sam próg godzinny co dla rejestracji). Bez ustalonego
	 * adresu klienta progu IP się nie liczy, jak w SpamGuard; zostaje próg per adres e-mail.
	 */
	private static function within_ip_limit(): bool {
		$settings = SettingsRepository::get();
		$ip       = IpAddress::from( $_SERVER, $settings->trustedProxyHeader );

		return '' === $ip || RateLimiter::hit( self::BUCKET_RESEND_IP . $ip ) <= $settings->rateLimitPerHour;
	}

	/**
	 * Strona rejestracji z ustawień albo strona główna.
	 */
	private static function base_url(): string {
		$page = SettingsRepository::get()->registerPageId;
		$link = $page > 0 ? get_permalink( $page ) : false;

		return false === $link ? home_url( '/' ) : $link;
	}

	/**
	 * Przekierowuje i kończy żądanie (przekierowanie anulowane filtrem `wp_redirect` nie kończy go: szew testowy).
	 *
	 * @param string $url Adres docelowy.
	 */
	private static function redirect( string $url ): void {
		if ( wp_safe_redirect( $url ) ) {
			exit;
		}
	}
}
