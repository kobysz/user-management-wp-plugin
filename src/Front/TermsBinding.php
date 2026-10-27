<?php
/**
 * Powiązanie konta z wersją regulaminu, którą użytkownik zaakceptował.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\TermsRepository;
use PortalUsers\Persistence\UserMeta;
use Throwable;

/**
 * Podpina się pod `pusers_account_registered`, czyli po utworzeniu konta. Konto już istnieje, a użytkownik
 * dostaje odpowiedź, więc nic stąd nie może wywrócić żądania ani zablokować rejestracji:
 *
 * - brak skonfigurowanego regulaminu albo pola zgody to niedokończona konfiguracja administratora: konto powstaje bez
 *   powiązania, a stan ten pokazuje raport spójności (bez wpisu w logu przy każdej rejestracji);
 * - informacja „zgoda udzielona” pochodzi z metadanej pola zgody zapisanej przez RegistrationHandler z wartości
 *   ZNORMALIZOWANEJ (`'1'` albo pusto), nigdy z surowego żądania; zgoda nieudzielona (lub ukryta dla profesji,
 *   więc niezapisana) nie tworzy powiązania;
 * - nieudany zapis wersji kończy się wpisem w logu zdarzeń i BRAKIEM powiązania: znacznik czasu zgody bez wersji,
 *   albo wersja, której nie ma w archiwum, byłyby fałszywym dowodem. Log wskazuje konto do uzupełnienia.
 */
final class TermsBinding {

	/**
	 * Wpis w logu: konto powiązane z wersją regulaminu.
	 */
	public const LOG_BOUND = 'terms_bound';

	/**
	 * Wpis w logu: powiązania nie utworzono, bo nie udało się zapisać wersji regulaminu.
	 */
	public const LOG_FAILED = 'terms_binding_failed';

	/**
	 * Podpina powiązanie pod utworzenie konta.
	 */
	public static function register(): void {
		add_action( RegistrationHandler::REGISTERED_HOOK, array( self::class, 'on_registered' ) );
	}

	/**
	 * Wiąże nowe konto z wersją regulaminu, o ile zgoda została udzielona.
	 *
	 * @param mixed $userId Identyfikator nowego użytkownika.
	 */
	public static function on_registered( mixed $userId ): void {
		if ( ! is_int( $userId ) || $userId <= 0 ) {
			return;
		}

		try {
			self::bind( $userId );
		} catch ( Throwable $e ) {
			// Konto istnieje: awaria nie może wywrócić żądania.
			self::log( self::LOG_FAILED, $userId, array( 'reason' => 'exception' ) );
		}
	}

	/**
	 * Właściwe powiązanie.
	 *
	 * @param int $userId Identyfikator użytkownika.
	 */
	private static function bind( int $userId ): void {
		$settings = SettingsRepository::get();
		if ( '' === $settings->termsText ) {
			return; // Regulamin nieskonfigurowany: rejestracja działa, konto bez powiązania.
		}
		if ( '' === $settings->termsConsentField ) {
			return; // Stan konfiguracji, nie zdarzenie: widzi go raport spójności (MatrixScreen::issues()), log nie puchnie.
		}

		// Wartość zapisał handler po normalizacji; prawdą jest wyłącznie '1'.
		$consent = get_user_meta( $userId, RegistrationHandler::ACF_META_PREFIX . $settings->termsConsentField, true );
		if ( '1' !== $consent ) {
			return;
		}

		$hash = TermsRepository::ensureVersion( $settings->termsText );
		if ( '' === $hash ) {
			self::log( self::LOG_FAILED, $userId, array( 'reason' => 'version_not_stored' ) );
			return;
		}

		update_user_meta( $userId, UserMeta::TERMS_VERSION, $hash );
		update_user_meta( $userId, UserMeta::CONSENT_AT, gmdate( 'Y-m-d H:i:s' ) );
		self::log( self::LOG_BOUND, $userId, array( 'version' => $hash ) );
	}

	/**
	 * Wpis w logu zdarzeń; sam log nie rzuca, ale i tak nie zawierzamy mu żądania.
	 *
	 * @param string               $action  Nazwa zdarzenia.
	 * @param int                  $userId  Użytkownik.
	 * @param array<string, mixed> $context Dane dodatkowe.
	 */
	private static function log( string $action, int $userId, array $context ): void {
		try {
			EventLog::write( $action, $userId, 0, $context );
		} catch ( Throwable $e ) {
			unset( $e ); // Nic więcej do zrobienia: konto istnieje, odpowiedź idzie.
		}
	}
}
