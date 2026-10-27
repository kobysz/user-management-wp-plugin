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
	 * własnych reguł niezależnie od tego, co zwrócił filtr.
	 */
	public const FILTER = 'pusers_registration_spam_check';

	/**
	 * Akcja zapisywana w logu zdarzeń przy odmowie.
	 */
	public const LOG_ACTION = 'registration_blocked';

	private const STAMP_SCOPE = 'pusers_t|';

	/**
	 * Wartość znacznika czasu do wstawienia w formularz: moment i jego podpis.
	 *
	 * @param int $loadedAt Moment załadowania formularza (uniksowy).
	 */
	public static function stamp( int $loadedAt ): string {
		return $loadedAt . '.' . wp_hash( self::STAMP_SCOPE . $loadedAt );
	}

	/**
	 * Sprawdza zgłoszenie.
	 *
	 * @param array<string, mixed> $server  Tablica serwera ($_SERVER).
	 * @param array<string, mixed> $request Dane żądania bez ukośników (pola z RegistrationForm::HONEYPOT i TIMESTAMP).
	 * @param string               $email   Adres e-mail ze zgłoszenia.
	 */
	public static function check( array $server, array $request, string $email ): SpamVerdict {
		$settings = SettingsRepository::get();
		$ip       = IpAddress::from( $server, $settings->trustedProxyHeader );

		$verdict = self::own_verdict( $settings->timeTrapSeconds, $settings->rateLimitPerHour, $settings->registrationLimitPerEmailPerHour, $ip, $request, $email );

		/**
		 * Punkt rozszerzenia antyspamu rejestracji.
		 *
		 * @param SpamVerdict          $verdict Werdykt własnych reguł wtyczki.
		 * @param array<string, mixed> $request Dane żądania.
		 * @param string               $email   Adres e-mail ze zgłoszenia.
		 */
		$filtered = apply_filters( 'pusers_registration_spam_check', $verdict, $request, $email );

		// Filtr może tylko zaostrzyć werdykt: odmowa własnych reguł zostaje, cokolwiek zwrócił.
		if ( $verdict->passed() && $filtered instanceof SpamVerdict && ! $filtered->passed() ) {
			$verdict = $filtered;
		}

		if ( ! $verdict->passed() ) {
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

		return $verdict;
	}

	/**
	 * Werdykt własnych reguł wtyczki, przed punktem rozszerzenia.
	 *
	 * @param int                  $timeTrapSeconds Minimalny czas wypełniania formularza.
	 * @param int                  $ipLimit         Próg prób na godzinę per adres IP.
	 * @param int                  $emailLimit      Próg prób na godzinę per adres e-mail.
	 * @param string               $ip              Adres klienta.
	 * @param array<string, mixed> $request         Dane żądania.
	 * @param string               $email           Adres e-mail ze zgłoszenia.
	 */
	private static function own_verdict( int $timeTrapSeconds, int $ipLimit, int $emailLimit, string $ip, array $request, string $email ): SpamVerdict {
		$honeypot = $request[ RegistrationForm::HONEYPOT ] ?? '';
		// Pole, które nie jest tekstem (np. tablica), też jest wypełnione: człowiek takiego nie wyśle.
		$filled = is_string( $honeypot ) ? $honeypot : 'x';

		$verdict = ( new SpamRules( $timeTrapSeconds ) )->check( $filled, self::loaded_at( $request[ RegistrationForm::TIMESTAMP ] ?? null ), time() );
		if ( ! $verdict->passed() ) {
			return $verdict;
		}

		if ( RateLimiter::hit( 'registration_ip:' . $ip ) > $ipLimit ) {
			return SpamVerdict::reject( SpamVerdict::IP_LIMIT );
		}

		$normalised = strtolower( trim( $email ) );
		if ( '' !== $normalised && RateLimiter::hit( 'registration_email:' . $normalised ) > $emailLimit ) {
			return SpamVerdict::reject( SpamVerdict::EMAIL_LIMIT );
		}

		return SpamVerdict::pass();
	}

	/**
	 * Moment załadowania formularza z podpisanego znacznika; 0 dla znacznika nieobecnego, w złym kształcie
	 * albo z niezgodnym podpisem (SpamRules traktuje 0 jak odesłanie zbyt szybkie).
	 *
	 * @param mixed $raw Wartość pola z żądania.
	 */
	private static function loaded_at( mixed $raw ): int {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d{1,10})\.([0-9a-f]{32})$/', $raw, $m ) ) {
			return 0;
		}

		return hash_equals( wp_hash( self::STAMP_SCOPE . $m[1] ), $m[2] ) ? (int) $m[1] : 0;
	}
}
