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
 * Rodzaj `$kind` w tych filtrach to od Planu 5 CAŁA domena `MailKind`, nie trzy wartości, które znał
 * Plan 3: dochodzą `editorial_auto`, `application_received`, `approved`, `rejected` i `password_reset`.
 * Każdy ma tu swoją stałą `KIND_*`, bo obcy kod rozgałęzia się po stałych, a nie po enumie.
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

	/**
	 * Rodzaje wiadomości dla filtrów: jedna stała na każdy `MailKind`. Rodzaj bez stałej to gałąź,
	 * której nikt w obcym kodzie nie napisze — tak `editorial_auto` cicho omijałby filtr pisany pod
	 * `KIND_EDITORIAL`. Literały, bo PHP 8.1 nie przyjmuje `MailKind::Case->value` w wyrażeniu stałej;
	 * kompletność i zgodność z enumem pilnuje test iterujący po `MailKind::cases()`.
	 */
	public const KIND_CONFIRMATION         = 'confirmation';
	public const KIND_EDITORIAL            = 'editorial';
	public const KIND_EDITORIAL_AUTO       = 'editorial_auto';
	public const KIND_EXISTING             = 'existing_account';
	public const KIND_APPLICATION_RECEIVED = 'application_received';
	public const KIND_APPROVED             = 'approved';
	public const KIND_REJECTED             = 'rejected';
	public const KIND_PASSWORD_RESET       = 'password_reset';

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
	 * Klucz odporności niesie bieżącą godzinę UTC, więc jest ŚCIŚLEJSZY niż próg `may_send_to()` (ten
	 * przepuszcza kilka prób na godzinę) i taki ma być: właściciel adresu nie prosił o żadną z tych
	 * wiadomości, a osoba trzecia zgłaszająca cudzy adres nie ma go czym zasypać. Skutek, świadomy:
	 * w logu zostaje jeden wiersz na adres na godzinę, nie jeden na próbę. Zliczanie prób jest zadaniem
	 * licznika w `may_send_to()`; `event_log` jest logiem DECYZJI, a nie dziennikiem cudzych żądań —
	 * poszerzanie nieuwierzytelnionego zapisu do tabeli audytu ten projekt już raz przepłacił.
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
	 * Mówi wnioskodawcy, że adres jest potwierdzony, a wniosek czeka na decyzję redakcji.
	 *
	 * Adres potwierdza się raz, więc klucz niesie tylko rodzaj i konto: ponowne wywołanie nie zrodzi
	 * drugiego „czeka na decyzję". Mail nie idzie natychmiast — nikt nie czeka przy ekranie.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	public static function application_received( int $userId ): bool {
		return self::to_applicant( MailKind::ApplicationReceived, $userId, array(), MailKind::ApplicationReceived->value . ':' . $userId );
	}

	/**
	 * Informuje wnioskodawcę, że konto jest zatwierdzone. Woła to potwierdzenie adresu przy wyłączonej
	 * akceptacji oraz (od Planu 6) akcja zatwierdzenia w panelu.
	 *
	 * Klucz niesie minutę, bo idempotencja ma chronić przed podwójnym kliknięciem, a nie przed ponowną
	 * decyzją tygodnie później (zatwierdzenie po wcześniejszym odrzuceniu jest realną ścieżką).
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	public static function approved( int $userId ): bool {
		return self::to_applicant( MailKind::Approved, $userId, array(), MailKind::Approved->value . ':' . $userId . ':' . self::minute_bucket() );
	}

	/**
	 * Informuje wnioskodawcę o odrzuceniu wniosku. Powód przychodzi już rozstrzygnięty (wpisany ręcznie
	 * albo wybrany z ustawień); tu nikt go nie szuka.
	 *
	 * Klucz niesie minutę i skrót powodu: podwójne kliknięcie nie wyśle dwóch maili, a ponowne odrzucenie
	 * z INNYM powodem wyśle, bo człowiek ma wiedzieć, co się zmieniło.
	 *
	 * @param int    $userId Identyfikator użytkownika.
	 * @param string $reason Powód odrzucenia.
	 */
	public static function rejected( int $userId, string $reason ): bool {
		return self::to_applicant(
			MailKind::Rejected,
			$userId,
			array( 'powod_odrzucenia' => $reason ),
			MailKind::Rejected->value . ':' . $userId . ':' . self::minute_bucket() . ':' . substr( wp_hash( $reason ), 0, 16 )
		);
	}

	/**
	 * Wspólny tor maili do wnioskodawcy: konto i adres muszą istnieć, wiersz powstaje bez natychmiastowej wysyłki.
	 *
	 * @param MailKind              $kind      Rodzaj maila.
	 * @param int                   $userId    Identyfikator użytkownika.
	 * @param array<string, string> $extra     Zmienne od wołającego.
	 * @param string                $dedupeKey Klucz odporności (odbiorcę dopina kolejka).
	 */
	private static function to_applicant( MailKind $kind, int $userId, array $extra, string $dedupeKey ): bool {
		$user = get_userdata( $userId );
		if ( false === $user || '' === $user->user_email ) {
			return false;
		}

		return MailQueue::enqueue( $kind, $userId, $user->user_email, PlaceholderFactory::for_user( $userId, $extra ), $dedupeKey );
	}

	/**
	 * Kubełek minuty do kluczy odporności zatwierdzenia i odrzucenia (UTC, `YmdHi`). Filtr jest szwem
	 * testowym jednej decyzji i WEWNĘTRZNYM, nie punktem rozszerzenia: bez niego nie da się deterministycznie
	 * sprawdzić, że ta sama decyzja w tej samej minucie daje jeden wiersz, a w późniejszej drugi. Zwrócenie
	 * z niego stałej na prawdziwej witrynie sprawi, że każda następna decyzja tego samego rodzaju (a przy
	 * odrzuceniu z tym samym powodem) zostanie uznana za duplikat i wnioskodawca nigdy jej nie dostanie,
	 * choć panel pokaże, że mail wyszedł.
	 */
	private static function minute_bucket(): string {
		$bucket = apply_filters( 'pusers_internal_test_seam_minute_bucket', gmdate( 'YmdHi' ) );

		return is_string( $bucket ) ? $bucket : gmdate( 'YmdHi' );
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
