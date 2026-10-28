<?php
/**
 * Rodzaj maila wysyłanego przez wtyczkę.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Rodzaj maila: każdy ma własny szablon i własny zestaw zmiennych.
 *
 * Wartości `confirmation`, `editorial` i `existing_account` są od Planu 3 częścią publicznego
 * kontraktu filtrów `pusers_mail_body` i `pusers_mail_subject`, więc nie wolno ich zmieniać.
 */
enum MailKind: string {

	case Confirmation        = 'confirmation';
	case Editorial           = 'editorial';
	case EditorialAuto       = 'editorial_auto';
	case ExistingAccount     = 'existing_account';
	case ApplicationReceived = 'application_received';
	case Approved            = 'approved';
	case Rejected            = 'rejected';
	case PasswordReset       = 'password_reset';

	/**
	 * Zmienne dostępne w szablonie tego rodzaju, bez klamr.
	 *
	 * Wspólny trzon plus dodatki per rodzaj. `match` bez gałęzi domyślnej jest zamierzone:
	 * nowy rodzaj bez decyzji o zmiennych kończy się błędem, a nie cichym pustym zestawem.
	 *
	 * @return list<string>
	 */
	public function variables(): array {
		$extra = match ( $this ) {
			self::Confirmation                   => array( 'link_potwierdzenia' ),
			self::Editorial, self::EditorialAuto => array( 'link_wniosku', 'pola_zgloszenia' ),
			self::Rejected                       => array( 'powod_odrzucenia' ),
			self::PasswordReset                  => array( 'link_hasla' ),
			self::ExistingAccount, self::ApplicationReceived, self::Approved => array(),
		};

		return array_merge(
			array(
				'email',
				'login',
				'nazwa_konta',
				'profesja',
				'numer_uprawnien',
				'wynik_walidacji',
				'nazwa_portalu',
				'adres_portalu',
				'link_logowania',
			),
			$extra
		);
	}
}
