<?php
/**
 * Teksty werdyktu o numerze uprawnień dla panelu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Credentials\CredentialResult;

/**
 * Tłumaczy kody domeny na język człowieka. Celowo bez pytania „czy to ostrzeżenie": to rozstrzyga
 * `CredentialVerdict::isWarning()`, a druga taka metoda w panelu rozjechałaby się z domeną przy
 * pierwszym nowym kodzie walidatora.
 */
final class VerdictLabels {

	/**
	 * Tekst werdyktu; kod nieznany (nowy walidator, stary panel) daje zawsze jakiś tekst, nigdy pusty napis ani surowy kod.
	 *
	 * @param string $code Kod z `CredentialVerdict::for()`.
	 */
	public static function text( string $code ): string {
		return match ( $code ) {
			CredentialVerdict::VALID           => __( 'poprawny', 'portal-users' ),
			CredentialVerdict::NOT_APPLICABLE  => __( 'nie dotyczy', 'portal-users' ),
			CredentialVerdict::MISSING         => __( 'brak numeru', 'portal-users' ),
			CredentialResult::INVALID_FORMAT   => __( 'nieprawidłowy format numeru', 'portal-users' ),
			CredentialResult::INVALID_CHECKSUM => __( 'nieprawidłowa suma kontrolna', 'portal-users' ),
			CredentialResult::INVALID_CHAMBER  => __( 'nieznana izba okręgowa', 'portal-users' ),
			default                            => __( 'nie udało się sprawdzić', 'portal-users' ),
		};
	}
}
