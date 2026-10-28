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
use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Mail\MailQueue;
use PortalUsers\Mail\PlaceholderFactory;
use PortalUsers\Persistence\AccountStatusRepository;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

/**
 * Zdarzenia rejestracji zamienione na wiersze kolejki. Żadne z nich nie woła `wp_mail()`: treść powstaje
 * w chwili zdarzenia (snapshot szablonu), a wysyłką zajmuje się dispatcher. Dzięki temu odpowiedź
 * użytkownika nie czeka na serwer poczty, a na pytanie „czy ten mail wyszedł" odpowiada log wysyłek.
 *
 * Temat i treść bierze `MailQueue` z szablonu rodzaju i przepuszcza przez `FILTER_SUBJECT` / `FILTER_BODY`.
 * Stałe `KIND_*` zostają literałami równymi wartościom `MailKind`: PHP 8.1 nie przyjmuje
 * `MailKind::Confirmation->value` w wyrażeniu stałej, a wyrzucenie stałych zepsułoby kontrakt filtrów
 * z Planu 3. Zgodność literałów z enumem pilnuje test jednostkowy.
 *
 * Odbiorcy redakcji pochodzą WYŁĄCZNIE z ustawień wtyczki (lista globalna, nadpisana adresem profesji).
 * Adres administratora witryny nie jest tu nigdy źródłem: nie zawsze główny administrator odpowiada za akceptację.
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
	 * Kolejkuje użytkownikowi link potwierdzający adres.
	 *
	 * Klucz odporności niesie skrót odnośnika, bo ponowna wysyłka (funkcja Planu 3) generuje NOWY token:
	 * klucz bez tokenu zablokowałby ją na zawsze. Mail idzie natychmiast — człowiek czeka przy ekranie,
	 * a bez tego odnośnika nie ma jak wejść na konto.
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $link   Pełny odnośnik z tokenem.
	 */
	public static function confirmation( int $userId, string $link ): bool {
		$user = get_userdata( $userId );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		return MailQueue::enqueue(
			MailKind::Confirmation,
			$userId,
			$user->user_email,
			PlaceholderFactory::for_user( $userId, array( 'link_potwierdzenia' => $link ) ),
			MailKind::Confirmation->value . ':' . $userId . ':' . substr( wp_hash( $link ), 0, 16 ),
			true
		);
	}

	/**
	 * Powiadamia redakcję o potwierdzeniu adresu. Gdy wniosek czeka na decyzję, idzie rodzaj `editorial`
	 * (wezwanie do decyzji); gdy konto jest już zatwierdzone — `editorial_auto`, czysto informacyjny.
	 *
	 * O rodzaju decyduje STAN konta, nie samo ustawienie akceptacji: stan jest tym, co o koncie
	 * rozstrzyga, a ustawienie mogło się zmienić między zgłoszeniem a potwierdzeniem adresu.
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

		$kind   = AccountStatus::Pending === AccountStatusRepository::get( $userId ) ? MailKind::Editorial : MailKind::EditorialAuto;
		$values = PlaceholderFactory::for_user( $userId );
		// Adres redakcji potwierdza się raz, więc w kluczu wystarczy rodzaj i konto; odbiorcę dopina kolejka.
		$dedupeKey = $kind->value . ':' . $userId;

		// Osobna wiadomość do każdego odbiorcy: w `To:` jednej wiadomości każdy widziałby adresy pozostałych.
		$queued = true;
		foreach ( $recipients as $recipient ) {
			$queued = MailQueue::enqueue( $kind, $userId, $recipient, $values, $dedupeKey ) && $queued;
		}

		return $queued;
	}

	/**
	 * Informuje właściciela istniejącego konta, że ktoś próbował zarejestrować jego adres. Konto się nie zmienia.
	 *
	 * Klucz odporności niesie bieżącą godzinę UTC: najwyżej jeden taki mail na godzinę, zgodnie z progiem
	 * `may_send_to()`. Osoba trzecia zgłaszająca cudzy adres nie zasypie właściciela.
	 *
	 * @param int $userId Identyfikator istniejącego użytkownika.
	 */
	public static function existing_account( int $userId ): bool {
		$user = get_userdata( $userId );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		return MailQueue::enqueue(
			MailKind::ExistingAccount,
			$userId,
			$user->user_email,
			PlaceholderFactory::for_user( $userId ),
			MailKind::ExistingAccount->value . ':' . $userId . ':' . gmdate( 'YmdH' )
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
}
