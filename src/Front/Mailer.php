<?php
/**
 * Wiadomości e-mail rejestracji: potwierdzenie adresu, powiadomienie redakcji, próba rejestracji na istniejący adres.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\FieldMatrixRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Treści są minimalne i funkcjonalne. Każda przechodzi przez `FILTER_BODY` (i temat przez `FILTER_SUBJECT`),
 * więc kolejny plan podmienia szablony bez ruszania zdarzeń, które maile wywołują.
 *
 * Odbiorcy redakcji pochodzą WYŁĄCZNIE z ustawień wtyczki (lista globalna, nadpisana adresem profesji).
 * Adres administratora witryny nie jest tu nigdy źródłem: nie zawsze główny administrator odpowiada za akceptację.
 *
 * Liczby stawiamy po dwukropku na końcu zdania, bez `_n()`: językiem źródłowym jest polski, katalogu
 * tłumaczeń nie ma, więc gettext zastosowałby angielską regułę dwóch form.
 */
final class Mailer {

	/**
	 * Filtr treści wiadomości; argumenty: treść, rodzaj (KIND_*), kontekst (tablica).
	 */
	public const FILTER_BODY = 'pusers_mail_body';

	/**
	 * Filtr tematu wiadomości; argumenty: temat, rodzaj (KIND_*), kontekst (tablica).
	 */
	public const FILTER_SUBJECT = 'pusers_mail_subject';

	public const KIND_CONFIRMATION = 'confirmation';
	public const KIND_EDITORIAL    = 'editorial';
	public const KIND_EXISTING     = 'existing_account';

	/**
	 * Zdarzenie w logu: powiadomienie redakcji nie miało komu iść.
	 */
	public const NO_RECIPIENTS_LOG_ACTION = 'mail_no_recipients';

	/**
	 * Zdarzenie w logu: adres redakcji profesji jest ustawiony, ale niepoprawny.
	 */
	public const INVALID_RECIPIENT_LOG_ACTION = 'mail_invalid_profession_recipient';

	/**
	 * Cel maila w kubełku limitu: ponowna wysyłka i mail o próbie rejestracji mają NIEZALEŻNE liczniki,
	 * żeby osoba trzecia zgłaszająca cudzy adres nie wyczerpała właścicielowi ponownej wysyłki.
	 */
	public const PURPOSE_RESEND  = 'resend';
	public const PURPOSE_ATTEMPT = 'attempt';

	private const BUCKET_MAIL_TO = 'mail_to:';

	/**
	 * Wysyła użytkownikowi link potwierdzający adres.
	 *
	 * Treść mówi, że po kliknięciu trzeba USTAWIĆ HASŁO: zgłoszenie o hasło nie pyta, a konto bez
	 * ustawionego hasła nie ma danych logowania, więc człowiek musi wiedzieć, że link jest jedyną
	 * drogą do konta. Ostrzeżenie „jeżeli to nie Ty zakładałeś konto” ZOSTAJE: samo zgłoszenie
	 * cudzego adresu jest nadal możliwe, choć nie daje już dostępu do konta.
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $link   Pełny odnośnik z tokenem.
	 */
	public static function confirmation( int $userId, string $link ): bool {
		$user = get_userdata( $userId );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		$body = implode(
			"\n",
			array(
				__( 'Dziękujemy za rejestrację. Potwierdź adres e-mail i ustaw hasło do konta, otwierając odnośnik:', 'portal-users' ),
				$link,
				'',
				__( 'Hasła nie ustawia się w formularzu zgłoszenia: ustawisz je dopiero po otwarciu tego odnośnika.', 'portal-users' ),
				'',
				__( 'Jeżeli to nie Ty zakładałeś konto, zignoruj tę wiadomość.', 'portal-users' ),
			)
		);

		return self::send(
			$user->user_email,
			__( 'Potwierdź adres e-mail', 'portal-users' ),
			$body,
			self::KIND_CONFIRMATION,
			array(
				'user_id' => $userId,
				'link'    => $link,
			)
		);
	}

	/**
	 * Powiadamia redakcję o potwierdzeniu adresu. Gdy akceptacja jest włączona, wiadomość wzywa do decyzji;
	 * gdy wyłączona, jest czysto informacyjna (konto jest już zatwierdzone).
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	public static function editorial( int $userId ): bool {
		$user = get_userdata( $userId );
		if ( false === $user ) {
			return false;
		}

		$professionKey = get_user_meta( $userId, UserMeta::PROFESSION, true );
		$professionKey = is_string( $professionKey ) ? $professionKey : '';
		$profession    = ProfessionRepository::get()->get( $professionKey );

		$recipients = self::editorial_recipients( $profession, $userId );
		if ( array() === $recipients ) {
			EventLog::write( self::NO_RECIPIENTS_LOG_ACTION, $userId, 0, array( 'profession' => $professionKey ) );
			return false;
		}

		$needsDecision = AccountStatus::Pending === AccountStatusRepository::get( $userId );
		$link          = $needsDecision ? self::application_link( $userId ) : '';

		$lines   = array();
		$lines[] = $needsDecision
			? __( 'Nowe konto potwierdziło adres e-mail i czeka na decyzję.', 'portal-users' )
			: __( 'Nowe konto potwierdziło adres e-mail i zostało zatwierdzone automatycznie (akceptacja jest wyłączona). Ta wiadomość ma charakter informacyjny.', 'portal-users' );
		$lines[] = '';
		$lines[] = __( 'Profesja:', 'portal-users' ) . ' ' . ( null === $profession ? $professionKey : $profession->label );
		$lines[] = __( 'Adres e-mail:', 'portal-users' ) . ' ' . $user->user_email;
		$lines[] = __( 'Numer uprawnień:', 'portal-users' ) . ' ' . self::credential_summary( $userId, $profession );
		$lines[] = '';
		$lines[] = __( 'Pola zgłoszenia:', 'portal-users' );
		foreach ( self::submitted_fields( $userId, FieldMatrixRepository::get()->visibleFields( $professionKey ) ) as $name => $value ) {
			$lines[] = '- ' . $name . ': ' . $value;
		}
		if ( '' !== $link ) {
			$lines[] = '';
			$lines[] = __( 'Wniosek:', 'portal-users' ) . ' ' . $link;
		}

		$subject = $needsDecision
			? __( 'Nowy wniosek o konto czeka na decyzję', 'portal-users' )
			: __( 'Nowe konto zatwierdzone automatycznie', 'portal-users' );

		return self::send(
			$recipients,
			$subject,
			implode( "\n", $lines ),
			self::KIND_EDITORIAL,
			array(
				'user_id'        => $userId,
				'needs_decision' => $needsDecision,
				'link'           => $link,
			)
		);
	}

	/**
	 * Informuje właściciela istniejącego konta, że ktoś próbował zarejestrować jego adres. Konto się nie zmienia.
	 *
	 * @param int $userId Identyfikator istniejącego użytkownika.
	 */
	public static function existing_account( int $userId ): bool {
		$user = get_userdata( $userId );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		$body = implode(
			"\n",
			array(
				__( 'Ktoś próbował zarejestrować konto z Twoim adresem e-mail, ale konto z tym adresem już istnieje.', 'portal-users' ),
				__( 'Nic się w nim nie zmieniło.', 'portal-users' ),
				'',
				__( 'Jeżeli to byłeś Ty, zaloguj się na istniejące konto. Jeżeli nie pamiętasz hasła, możesz je odzyskać:', 'portal-users' ),
				wp_lostpassword_url(),
				'',
				__( 'Jeżeli to nie Ty, zignoruj tę wiadomość. Nie musisz nic robić.', 'portal-users' ),
			)
		);

		return self::send(
			$user->user_email,
			__( 'Próba rejestracji na Twój adres e-mail', 'portal-users' ),
			$body,
			self::KIND_EXISTING,
			array( 'user_id' => $userId )
		);
	}

	/**
	 * Zlicza próbę wysłania maila na adres z formularza publicznego i mówi, czy wolno wysłać.
	 *
	 * Każdy cel (ponowna wysyłka, mail o próbie rejestracji) ma własny kubełek na adres i próg równy progowi
	 * rejestracji per adres e-mail. Liczniki są rozdzielone, bo wspólny pozwalałby osobie trzeciej zgłaszającej
	 * cudzy adres odebrać jego właścicielowi ponowną wysyłkę. Licznik rośnie tylko przy realnej próbie wysyłki (wołający liczy ją dopiero po ustaleniu, że mail pójdzie), więc cudze
	 * żądania nie odbierają właścicielowi budżetu.
	 *
	 * @param string $email   Adres odbiorcy.
	 * @param string $purpose Cel (PURPOSE_*): każdy cel ma własny licznik.
	 */
	public static function may_send_to( string $email, string $purpose ): bool {
		$normalised = strtolower( trim( $email ) );
		if ( '' === $normalised ) {
			return false;
		}

		return RateLimiter::hit( self::BUCKET_MAIL_TO . $purpose . ':' . $normalised ) <= SettingsRepository::get()->registrationLimitPerEmailPerHour;
	}

	/**
	 * Odbiorcy powiadomienia redakcji: adres profesji nadpisuje listę globalną.
	 *
	 * @param Profession|null $profession Profesja konta (null, gdy wycofana).
	 * @param int             $userId     Identyfikator użytkownika (do wpisu w logu).
	 * @return list<string>
	 */
	private static function editorial_recipients( ?Profession $profession, int $userId ): array {
		$own = null === $profession ? '' : sanitize_email( trim( $profession->notifyEmail ) );
		if ( '' !== $own && is_email( $own ) ) {
			return array( $own );
		}
		if ( null !== $profession && '' !== trim( $profession->notifyEmail ) ) {
			// Adres profesji jest ustawiony, ale niepoprawny (literówka): wniosek idzie na listę globalną i ktoś musi się o tym dowiedzieć.
			EventLog::write( self::INVALID_RECIPIENT_LOG_ACTION, $userId, 0, array( 'profession' => $profession->key ) );
		}

		$out = array();
		foreach ( SettingsRepository::get()->notifyEmails as $candidate ) {
			$clean = sanitize_email( $candidate );
			if ( '' !== $clean && is_email( $clean ) ) {
				$out[ strtolower( $clean ) ] = $clean;
			}
		}

		return array_values( $out );
	}

	/**
	 * Odnośnik do wniosku w panelu (do czasu ekranu wniosków: edycja użytkownika).
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	private static function application_link( int $userId ): string {
		return admin_url( 'user-edit.php?user_id=' . $userId );
	}

	/**
	 * Numer uprawnień z wynikiem walidacji, ponownie liczonym na zapisanej wartości.
	 *
	 * @param int             $userId     Identyfikator użytkownika.
	 * @param Profession|null $profession Profesja konta.
	 */
	private static function credential_summary( int $userId, ?Profession $profession ): string {
		if ( null === $profession || CredentialType::None === $profession->credentialType || '' === $profession->credentialField ) {
			return __( 'nie dotyczy', 'portal-users' );
		}

		$stored = get_user_meta( $userId, RegistrationHandler::ACF_META_PREFIX . $profession->credentialField, true );
		$number = is_string( $stored ) ? $stored : '';
		if ( '' === $number ) {
			return __( 'brak', 'portal-users' );
		}

		$validator = ( new ValidatorRegistry() )->for( $profession->credentialType );
		if ( null === $validator ) {
			return $number;
		}
		$result = $validator->validate( $number );

		return $result->isValid()
			? $number . ' (' . __( 'wynik walidacji: poprawny', 'portal-users' ) . ')'
			: $number . ' (' . __( 'wynik walidacji: niepoprawny', 'portal-users' ) . ', ' . $result->code() . ')';
	}

	/**
	 * Zapisane pola zgłoszenia (metadane z przedrostkiem pól ACF) jako nazwa => wartość w jednej linii.
	 *
	 * Tylko pola widoczne w macierzy dla profesji wnioskodawcy: ukryte nie trafiają do redakcji.
	 *
	 * @param int                $userId        Identyfikator użytkownika.
	 * @param array<int, string> $visibleFields Pola widoczne dla profesji.
	 * @return array<string, string>
	 */
	private static function submitted_fields( int $userId, array $visibleFields ): array {
		$out     = array();
		$prefix  = RegistrationHandler::ACF_META_PREFIX;
		$all     = get_user_meta( $userId );
		$visible = array_flip( $visibleFields );
		foreach ( is_array( $all ) ? $all : array() as $key => $values ) {
			$key = (string) $key;
			if ( ! str_starts_with( $key, $prefix ) || ! is_array( $values ) ) {
				continue;
			}
			$name = substr( $key, strlen( $prefix ) );
			if ( ! isset( $visible[ $name ] ) ) {
				continue;
			}
			$value = $values[0] ?? '';

			$out[ $name ] = is_string( $value ) ? trim( (string) preg_replace( '/\s+/', ' ', $value ) ) : '';
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Wysyła wiadomość tekstową, przepuszczając temat i treść przez filtry.
	 *
	 * @param string|list<string>  $to      Odbiorca albo odbiorcy.
	 * @param string               $subject Temat domyślny.
	 * @param string               $body    Treść domyślna.
	 * @param string               $kind    Rodzaj wiadomości (KIND_*).
	 * @param array<string, mixed> $context Kontekst dla filtrów.
	 */
	private static function send( string|array $to, string $subject, string $body, string $kind, array $context ): bool {
		/**
		 * Treść wiadomości. Plan szablonów podmienia ją tu, bez ruszania zdarzeń.
		 *
		 * @param string               $body    Treść domyślna.
		 * @param string               $kind    Rodzaj wiadomości.
		 * @param array<string, mixed> $context Kontekst.
		 */
		$filteredBody = apply_filters( self::FILTER_BODY, $body, $kind, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_BODY, która ma prefiks pusers_.
		/**
		 * Temat wiadomości.
		 *
		 * @param string               $subject Temat domyślny.
		 * @param string               $kind    Rodzaj wiadomości.
		 * @param array<string, mixed> $context Kontekst.
		 */
		$filteredSubject = apply_filters( self::FILTER_SUBJECT, $subject, $kind, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_SUBJECT, która ma prefiks pusers_.

		// Osobna wiadomość do każdego odbiorcy: w `To:` jednej wiadomości każdy widziałby adresy pozostałych.
		$sent = true;
		foreach ( (array) $to as $recipient ) {
			$ok   = wp_mail(
				$recipient,
				is_string( $filteredSubject ) ? $filteredSubject : $subject,
				is_string( $filteredBody ) ? $filteredBody : $body
			);
			$sent = $ok && $sent;
		}

		return $sent;
	}
}
