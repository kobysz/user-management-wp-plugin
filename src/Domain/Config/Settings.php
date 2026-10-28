<?php
/**
 * Ustawienia ogólne wtyczki jako niezmienne dane.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Ustawienia ogólne po normalizacji. Klasa nie zna miejsca zapisu: dostaje
 * surową tablicę, zwraca wartości w dopuszczalnych granicach, a braki i śmieci
 * zastępuje wartościami domyślnymi, żeby zepsuta konfiguracja nie zatrzymała wtyczki.
 *
 * Właściwość $deleteDataOnUninstall jest jedynym źródłem decyzji, czy
 * odinstalowanie wtyczki kasuje dane.
 */
final class Settings {

	/**
	 * Adres e-mail: część lokalna z dozwolonych znaków (bez przecinka, średnika, nawiasów ostrokątnych
	 * i spacji, które rozbijałyby listę odbiorców), kropki tylko między znakami; domena z etykiet
	 * liter, cyfr i myślników, co najmniej dwie etykiety.
	 */
	private const EMAIL_PATTERN = '/^[a-z0-9!#$%&\x27*+\/=?^_`{|}~\x80-\xff-]+(?:\.[a-z0-9!#$%&\x27*+\/=?^_`{|}~\x80-\xff-]+)*@[a-z0-9\x80-\xff](?:[a-z0-9\x80-\xff-]*[a-z0-9\x80-\xff])?(?:\.[a-z0-9\x80-\xff](?:[a-z0-9\x80-\xff-]*[a-z0-9\x80-\xff])?)+$/';

	private const DEFAULT_TOKEN_HOURS = 48;
	private const DEFAULT_PURGE_DAYS  = 7;
	private const DEFAULT_RATE_LIMIT  = 50;
	private const DEFAULT_TIME_TRAP   = 3;
	private const DEFAULT_EMAIL_LIMIT = 3;

	// Progi logowania i odzyskiwania hasła są wysokie świadomie: szpital za jednym adresem
	// wyjściowym nie może zostać odcięty przy domyślnej konfiguracji.
	private const DEFAULT_LOGIN_LIMIT         = 200;
	private const DEFAULT_LOGIN_ACCOUNT_LIMIT = 10;
	private const DEFAULT_RESET_LIMIT         = 50;
	private const DEFAULT_RESET_EMAIL_LIMIT   = 3;

	/**
	 * Konstruktor.
	 *
	 * @param bool               $approvalEnabled        Czy konta wymagają akceptacji redakcji.
	 * @param int                $loginPageId            Identyfikator strony logowania (0 = brak).
	 * @param int                $registerPageId         Identyfikator strony rejestracji (0 = brak).
	 * @param int                $resetPageId            Identyfikator strony resetu hasła (0 = brak).
	 * @param int                $afterLoginPageId       Strona po zalogowaniu konta bez uprawnień redakcyjnych (0 = strona główna).
	 * @param array<int, string> $notifyEmails           Adresy redakcji powiadamiane o zgłoszeniach.
	 * @param int                $confirmationTokenHours Ważność tokenu potwierdzenia, w godzinach.
	 * @param int                $unconfirmedPurgeDays   Po ilu dniach usuwać niepotwierdzone konta.
	 * @param int                $rateLimitPerHour       Limit zgłoszeń z jednego adresu na godzinę.
	 * @param int                $registrationLimitPerEmailPerHour Limit prób rejestracji tego samego adresu e-mail na godzinę.
	 * @param int                $loginLimitPerHour      Limit nieudanych logowań z jednego adresu IP na godzinę.
	 * @param int                $loginLimitPerAccountPerHour Limit nieudanych logowań na jedno konto na godzinę.
	 * @param int                $resetLimitPerHour      Limit żądań odzyskania hasła z jednego adresu IP na godzinę.
	 * @param int                $resetLimitPerEmailPerHour Limit żądań odzyskania hasła na jeden adres e-mail na godzinę.
	 * @param int                $timeTrapSeconds        Minimalny czas wypełniania formularza (0 = wyłączone).
	 * @param string             $trustedProxyHeader     Nazwa zaufanego nagłówka serwera z adresem klienta.
	 * @param array<int, string> $rejectionReasons       Gotowe powody odrzucenia zgłoszenia.
	 * @param bool               $deleteDataOnUninstall  Czy odinstalowanie kasuje dane.
	 * @param string             $acfGroupKey            Klucz grupy pól ACF.
	 * @param string             $termsText              Treść regulaminu (pusta = regulamin nieskonfigurowany).
	 * @param string             $termsConsentField      Klucz pola ACF ze zgodą na regulamin (puste = nie wskazano).
	 */
	public function __construct(
		public readonly bool $approvalEnabled,
		public readonly int $loginPageId,
		public readonly int $registerPageId,
		public readonly int $resetPageId,
		public readonly int $afterLoginPageId,
		public readonly array $notifyEmails,
		public readonly int $confirmationTokenHours,
		public readonly int $unconfirmedPurgeDays,
		public readonly int $rateLimitPerHour,
		public readonly int $registrationLimitPerEmailPerHour,
		public readonly int $loginLimitPerHour,
		public readonly int $loginLimitPerAccountPerHour,
		public readonly int $resetLimitPerHour,
		public readonly int $resetLimitPerEmailPerHour,
		public readonly int $timeTrapSeconds,
		public readonly string $trustedProxyHeader,
		public readonly array $rejectionReasons,
		public readonly bool $deleteDataOnUninstall,
		public readonly string $acfGroupKey,
		public readonly string $termsText,
		public readonly string $termsConsentField
	) {}

	/**
	 * Ustawienia domyślne.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return new self(
			true,
			0,
			0,
			0,
			0,
			array(),
			self::DEFAULT_TOKEN_HOURS,
			self::DEFAULT_PURGE_DAYS,
			self::DEFAULT_RATE_LIMIT,
			self::DEFAULT_EMAIL_LIMIT,
			self::DEFAULT_LOGIN_LIMIT,
			self::DEFAULT_LOGIN_ACCOUNT_LIMIT,
			self::DEFAULT_RESET_LIMIT,
			self::DEFAULT_RESET_EMAIL_LIMIT,
			self::DEFAULT_TIME_TRAP,
			'',
			array(),
			false,
			'',
			'',
			''
		);
	}

	/**
	 * Buduje ustawienia z zapisanej tablicy. Brakujące lub nieprawidłowe wartości
	 * zastępowane są domyślnymi, liczby dociskane do dopuszczalnych granic.
	 *
	 * @param array<string, mixed> $raw Zapisane ustawienia.
	 * @return self
	 */
	public static function fromArray( array $raw ): self {
		$d = self::defaults();

		return new self(
			self::flag( $raw, 'approval_enabled', $d->approvalEnabled ),
			self::integer( $raw, 'login_page_id', $d->loginPageId, 0 ),
			self::integer( $raw, 'register_page_id', $d->registerPageId, 0 ),
			self::integer( $raw, 'reset_page_id', $d->resetPageId, 0 ),
			self::integer( $raw, 'after_login_page_id', $d->afterLoginPageId, 0 ),
			self::emails( $raw['notify_emails'] ?? array() ),
			self::integer( $raw, 'confirmation_token_hours', $d->confirmationTokenHours, 1 ),
			self::integer( $raw, 'unconfirmed_purge_days', $d->unconfirmedPurgeDays, 1 ),
			self::integer( $raw, 'rate_limit_per_hour', $d->rateLimitPerHour, 1 ),
			self::integer( $raw, 'registration_limit_per_email_per_hour', $d->registrationLimitPerEmailPerHour, 1 ),
			self::positive( $raw, 'login_limit_per_hour', $d->loginLimitPerHour ),
			self::positive( $raw, 'login_limit_per_account_per_hour', $d->loginLimitPerAccountPerHour ),
			self::positive( $raw, 'reset_limit_per_hour', $d->resetLimitPerHour ),
			self::positive( $raw, 'reset_limit_per_email_per_hour', $d->resetLimitPerEmailPerHour ),
			self::integer( $raw, 'time_trap_seconds', $d->timeTrapSeconds, 0 ),
			self::headerName( self::text( $raw, 'trusted_proxy_header' ) ),
			self::texts( $raw['rejection_reasons'] ?? array() ),
			self::flag( $raw, 'delete_data_on_uninstall', $d->deleteDataOnUninstall ),
			self::text( $raw, 'acf_group_key' ),
			self::text( $raw, 'terms_text' ),
			self::text( $raw, 'terms_consent_field' )
		);
	}

	/**
	 * Zwraca ustawienia jako tablicę gotową do zapisu.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'approval_enabled'                      => $this->approvalEnabled,
			'login_page_id'                         => $this->loginPageId,
			'register_page_id'                      => $this->registerPageId,
			'reset_page_id'                         => $this->resetPageId,
			'after_login_page_id'                   => $this->afterLoginPageId,
			'notify_emails'                         => $this->notifyEmails,
			'confirmation_token_hours'              => $this->confirmationTokenHours,
			'unconfirmed_purge_days'                => $this->unconfirmedPurgeDays,
			'rate_limit_per_hour'                   => $this->rateLimitPerHour,
			'registration_limit_per_email_per_hour' => $this->registrationLimitPerEmailPerHour,
			'login_limit_per_hour'                  => $this->loginLimitPerHour,
			'login_limit_per_account_per_hour'      => $this->loginLimitPerAccountPerHour,
			'reset_limit_per_hour'                  => $this->resetLimitPerHour,
			'reset_limit_per_email_per_hour'        => $this->resetLimitPerEmailPerHour,
			'time_trap_seconds'                     => $this->timeTrapSeconds,
			'trusted_proxy_header'                  => $this->trustedProxyHeader,
			'rejection_reasons'                     => $this->rejectionReasons,
			'delete_data_on_uninstall'              => $this->deleteDataOnUninstall,
			'acf_group_key'                         => $this->acfGroupKey,
			'terms_text'                            => $this->termsText,
			'terms_consent_field'                   => $this->termsConsentField,
		);
	}

	/**
	 * Czyta pole logiczne; nierozpoznana wartość daje wartość domyślną.
	 *
	 * @param array<string, mixed> $raw     Zapisane ustawienia.
	 * @param string               $name    Nazwa pola.
	 * @param bool                 $fallback Wartość domyślna.
	 * @return bool
	 */
	private static function flag( array $raw, string $name, bool $fallback ): bool {
		if ( ! array_key_exists( $name, $raw ) ) {
			return $fallback;
		}
		$value = $raw[ $name ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 1 === $value || '1' === $value ) {
			return true;
		}
		if ( 0 === $value || '0' === $value || '' === $value ) {
			return false;
		}
		return $fallback;
	}

	/**
	 * Czyta liczbę całkowitą dociśniętą do dolnej granicy; wartość nienumeryczna
	 * daje wartość domyślną.
	 *
	 * @param array<string, mixed> $raw     Zapisane ustawienia.
	 * @param string               $name    Nazwa pola.
	 * @param int                  $fallback Wartość domyślna.
	 * @param int                  $min     Dolna granica.
	 * @return int
	 */
	private static function integer( array $raw, string $name, int $fallback, int $min ): int {
		$value = $raw[ $name ] ?? null;
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, (int) $value );
	}

	/**
	 * Czyta liczbę całkowitą dodatnią; zero, wartość ujemna i nienumeryczna dają wartość
	 * domyślną. Próg 0 zamknąłby logowanie dla wszystkich, więc nie jest dopuszczalny.
	 *
	 * @param array<string, mixed> $raw      Zapisane ustawienia.
	 * @param string               $name     Nazwa pola.
	 * @param int                  $fallback Wartość domyślna.
	 * @return int
	 */
	private static function positive( array $raw, string $name, int $fallback ): int {
		$value = $raw[ $name ] ?? null;
		if ( ! is_numeric( $value ) || (int) $value < 1 ) {
			return $fallback;
		}
		return (int) $value;
	}

	/**
	 * Czyta pole tekstowe z obciętymi białymi znakami; wartość nietekstowa to brak.
	 *
	 * @param array<string, mixed> $raw  Zapisane ustawienia.
	 * @param string               $name Nazwa pola.
	 * @return string
	 */
	private static function text( array $raw, string $name ): string {
		$value = $raw[ $name ] ?? '';
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Zostawia niepuste teksty z obciętymi białymi znakami.
	 *
	 * @param mixed $value Surowa lista.
	 * @return list<string>
	 */
	private static function texts( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}
		return $out;
	}

	/**
	 * Zostawia składniowo poprawne adresy e-mail, bez duplikatów, małymi literami.
	 *
	 * @param mixed $value Surowa lista.
	 * @return list<string>
	 */
	private static function emails( mixed $value ): array {
		$out = array();
		foreach ( self::texts( $value ) as $candidate ) {
			$email = strtolower( $candidate );
			if ( 1 === preg_match( self::EMAIL_PATTERN, $email ) ) {
				$out[ $email ] = $email;
			}
		}
		return array_values( $out );
	}

	/**
	 * Zamienia nazwę nagłówka HTTP na nazwę z tablicy serwera, np. x-forwarded-for
	 * na HTTP_X_FORWARDED_FOR. Pusta nazwa zostaje pusta.
	 *
	 * @param string $name Nazwa nagłówka.
	 * @return string
	 */
	private static function headerName( string $name ): string {
		$normalised = trim( (string) preg_replace( '/[^A-Z0-9]+/', '_', strtoupper( $name ) ), '_' );
		if ( '' === $normalised ) {
			return '';
		}
		return str_starts_with( $normalised, 'HTTP_' ) ? $normalised : 'HTTP_' . $normalised;
	}
}
