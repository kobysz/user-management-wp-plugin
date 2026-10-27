<?php
/**
 * Bramka antyspamu rejestracji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Spam\IpAddress;
use PortalUsers\Domain\Spam\SpamRules;
use PortalUsers\Domain\Spam\SpamVerdict;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Honeypot, pułapka czasowa i dwa progi prób: hojny per adres IP (szpital za jednym adresem
 * wyjściowym nie może zostać odcięty) i ciasny per adres e-mail (bot zgadujący adresy nie przechodzi).
 * Bramka stoi PRZED walidacją, żeby odpowiedź nie zdradzała botowi nic o polach formularza.
 *
 * Znacznik czasu z formularza jest podpisany (patrz stamp()). Jawny, niepodpisany znacznik bot
 * podstawiałby dowolny i pułapka czasowa przestałaby istnieć.
 */
final class SpamGuard {

	/**
	 * Punkt rozszerzenia (Turnstile, hCaptcha): filtr dostaje SpamVerdict, tablicę żądania i adres e-mail.
	 * Może zamienić przepustkę na odmowę. Odmowy na przepustkę NIE zmieni: bramka zachowuje odmowę
	 * własnych reguł niezależnie od tego, co zwrócił filtr. UWAGA: filtr jest wołany także przy odmowie,
	 * więc filtr z zewnętrzną usługą (captcha) ma wyjść od razu, gdy `$verdict->passed()` jest fałszem,
	 * inaczej zapłaci za odpytanie usługi o każdego bota.
	 */
	public const FILTER = 'pusers_registration_spam_check';

	/**
	 * Akcja zapisywana w logu zdarzeń przy przekroczeniu progu.
	 */
	public const LOG_ACTION = 'registration_blocked';

	/**
	 * Maksymalny wiek znacznika czasu formularza, w sekundach. Starszy jest traktowany jak niepoprawny,
	 * co bez żadnego stanu ogranicza ponowne użycie podpisanego znacznika.
	 */
	public const STAMP_MAX_AGE = DAY_IN_SECONDS;

	private const STAMP_SCOPE   = 'pusers_t|';
	private const BUCKET_IP     = 'registration_ip:';
	private const BUCKET_EMAIL  = 'registration_email:';
	private const BUCKET_LOGGED = 'registration_email_logged:';

	/**
	 * Wartość znacznika czasu do wstawienia w formularz: moment i jego podpis.
	 *
	 * @param int $loadedAt Moment załadowania formularza (uniksowy).
	 */
	public static function stamp( int $loadedAt ): string {
		return $loadedAt . '.' . wp_hash( self::STAMP_SCOPE . $loadedAt );
	}

	/**
	 * Sprawdza zgłoszenie PRZED walidacją: honeypot, pułapka czasowa, próg IP i próg adresu e-mail.
	 *
	 * Każde żądanie zużywa licznik per adres IP, także to z wypełnionym honeypotem albo za szybkie: to
	 * dowód bota i po przekroczeniu progu dostaje on blokadę czasową zamiast generować ruch bez końca.
	 * Gdy nie da się ustalić adresu klienta (brak poprawnego REMOTE_ADDR), progu IP się NIE liczy:
	 * ruch bez adresu zlany w jeden kubełek odciąłby cały portal; honeypot, pułapka i próg e-mail działają.
	 * Licznik adresu e-mail check() tylko CZYTA (patrz record_registration()). Do logu zdarzeń trafia
	 * wyłącznie przekroczenie progu, raz w oknie na próg; odmowa poniżej progu i odmowa rozszerzenia
	 * nie piszą nic, żeby bot nie zapchał tabeli audytowej.
	 *
	 * @param array<string, mixed> $server  Tablica serwera ($_SERVER).
	 * @param array<string, mixed> $request Dane żądania bez ukośników (pola z RegistrationForm::HONEYPOT i TIMESTAMP).
	 * @param string               $email   Adres e-mail ze zgłoszenia.
	 */
	public static function check( array $server, array $request, string $email ): SpamVerdict {
		$settings = SettingsRepository::get();
		$ip       = IpAddress::from( $server, $settings->trustedProxyHeader );
		$ipCount  = '' === $ip ? 0 : RateLimiter::hit( self::BUCKET_IP . $ip );

		if ( $ipCount > $settings->rateLimitPerHour ) {
			$verdict = SpamVerdict::reject( SpamVerdict::IP_LIMIT );
			if ( $settings->rateLimitPerHour + 1 === $ipCount ) {
				self::log_limit( $verdict, $ip );
			}
		} else {
			$verdict = self::own_verdict( $settings->timeTrapSeconds, $settings->registrationLimitPerEmailPerHour, $ip, $request, $email );
		}

		/**
		 * Punkt rozszerzenia antyspamu rejestracji (pełny opis przy stałej SpamGuard::FILTER, nazwa hooka:
		 * pusers_registration_spam_check). Wołany także przy odmowie własnych reguł; może tylko zaostrzyć werdykt.
		 *
		 * @param SpamVerdict          $verdict Werdykt własnych reguł wtyczki.
		 * @param array<string, mixed> $request Dane żądania.
		 * @param string               $email   Adres e-mail ze zgłoszenia.
		 */
		$filtered = apply_filters( self::FILTER, $verdict, $request, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER, która ma prefiks pusers_.

		// Filtr może tylko zaostrzyć werdykt: odmowa własnych reguł zostaje, cokolwiek zwrócił.
		if ( $verdict->passed() && $filtered instanceof SpamVerdict && ! $filtered->passed() ) {
			$verdict = $filtered;
		}

		return $verdict;
	}

	/**
	 * Zlicza rejestrację adresu e-mail w progu per adres.
	 *
	 * Wolno ją wołać WYŁĄCZNIE po udanej walidacji zgłoszenia i po przepustce z check(). Licznik istnieje,
	 * żeby bot nie zgadywał adresów, a pomyłka walidacyjna (błędne hasło, nieudana captcha) nie jest
	 * zgadywaniem, tylko człowiekiem, któremu coś nie wyszło; liczona wcześniej odcięłaby uczciwego
	 * użytkownika od własnego adresu na godzinę, bez drogi odzyskania. Zgłoszenie nieważne nie zakłada
	 * konta, więc nie ma czego ograniczać.
	 *
	 * @param string $email Adres e-mail zarejestrowanego zgłoszenia.
	 */
	public static function record_registration( string $email ): void {
		$normalised = self::normalise( $email );
		if ( '' !== $normalised ) {
			RateLimiter::hit( self::BUCKET_EMAIL . $normalised );
		}
	}

	/**
	 * Werdykt reguł (honeypot, pułapka czasowa, próg e-mail) dla żądania, które mieści się w progu IP.
	 *
	 * @param int                  $timeTrapSeconds Minimalny czas wypełniania formularza.
	 * @param int                  $emailLimit      Próg rejestracji na godzinę per adres e-mail.
	 * @param string               $ip              Adres klienta.
	 * @param array<string, mixed> $request         Dane żądania.
	 * @param string               $email           Adres e-mail ze zgłoszenia.
	 */
	private static function own_verdict( int $timeTrapSeconds, int $emailLimit, string $ip, array $request, string $email ): SpamVerdict {
		$honeypot = $request[ RegistrationForm::HONEYPOT ] ?? '';
		// Pole, które nie jest tekstem (np. tablica), też jest wypełnione: człowiek takiego nie wyśle.
		// Białe znaki obcinamy: autouzupełnianie przeglądarki potrafi dorzucić spację uczciwemu użytkownikowi.
		$filled = is_string( $honeypot ) ? trim( $honeypot ) : 'x';

		list( $loadedAt, $stale ) = self::read_stamp( $request[ RegistrationForm::TIMESTAMP ] ?? null );
		$verdict                  = ( new SpamRules( $timeTrapSeconds ) )->check( $filled, $loadedAt, time() );
		if ( ! $verdict->passed() ) {
			return $stale && SpamVerdict::TOO_FAST === $verdict->reason() ? SpamVerdict::reject( SpamVerdict::STALE_FORM ) : $verdict;
		}

		$normalised = self::normalise( $email );
		if ( '' === $normalised || RateLimiter::count( self::BUCKET_EMAIL . $normalised ) < $emailLimit ) {
			return SpamVerdict::pass();
		}

		$verdict = SpamVerdict::reject( SpamVerdict::EMAIL_LIMIT );
		// Wiersz w logu raz na okno licznika adresu (okno znacznika jest związane z oknem licznika).
		if ( RateLimiter::once_per_window( self::BUCKET_LOGGED . $normalised, self::BUCKET_EMAIL . $normalised ) ) {
			self::log_limit( $verdict, $ip );
		}
		return $verdict;
	}

	/**
	 * Adres e-mail w postaci kubełka.
	 *
	 * @param string $email Adres e-mail.
	 */
	private static function normalise( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Zapisuje w logu przekroczenie progu.
	 *
	 * @param SpamVerdict $verdict Odmowa z powodu progu.
	 * @param string      $ip      Adres klienta.
	 */
	private static function log_limit( SpamVerdict $verdict, string $ip ): void {
		EventLog::write(
			self::LOG_ACTION,
			0,
			0,
			array(
				'reason' => $verdict->reason(),
				'ip'     => $ip,
			)
		);
	}

	/**
	 * Moment załadowania formularza z podpisanego znacznika. Moment 0 (SpamRules traktuje go jak odesłanie
	 * zbyt szybkie) oznacza znacznik nieobecny, w złym kształcie, z niezgodnym podpisem albo przeterminowany.
	 * Przeterminowanie (poprawny podpis, wiek ponad STAMP_MAX_AGE) jest zgłaszane osobno, bo ma własny powód
	 * odmowy: uczciwy użytkownik z kartą otwartą od doby (albo ze stroną w długim cache) musi wiedzieć, że
	 * wystarczy odświeżyć formularz, a botowi ten wiek i tak jest znany; przy podróbkach przyczynę ukrywamy.
	 *
	 * @param mixed $raw Wartość pola z żądania.
	 * @return array{0: int, 1: bool} Moment załadowania i flaga przeterminowania.
	 */
	private static function read_stamp( mixed $raw ): array {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d{1,10})\.([0-9a-f]{32})$/', $raw, $m ) ) {
			return array( 0, false );
		}
		if ( ! hash_equals( wp_hash( self::STAMP_SCOPE . $m[1] ), $m[2] ) ) {
			return array( 0, false );
		}

		$loadedAt = (int) $m[1];
		return $loadedAt < time() - self::STAMP_MAX_AGE ? array( 0, true ) : array( $loadedAt, false );
	}
}
