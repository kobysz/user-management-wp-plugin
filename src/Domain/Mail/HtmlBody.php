<?php
/**
 * Opakowanie tekstowej treści maila w minimalny dokument HTML.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Zamienia tekst szablonu na prosty dokument HTML.
 *
 * Szablony są tekstem, nie HTML-em, więc wszystko jest escapowane, a podziały linii wracają
 * jako `<br />`. Celowo bez obrazków, tabel i kolorów: mail ma być czytelny w każdym kliencie.
 */
final class HtmlBody {

	/**
	 * Opakowuje tekst w dokument HTML.
	 *
	 * Kolejność ma znaczenie: najpierw escape, potem `nl2br()`, inaczej wstawione `<br />`
	 * zostałyby zamienione na widoczny tekst.
	 *
	 * @param string $text Treść po podstawieniu placeholderów.
	 */
	public static function wrap( string $text ): string {
		$escaped = nl2br( htmlspecialchars( $text, \ENT_QUOTES, 'UTF-8' ) );

		return "<!DOCTYPE html>\n"
			. "<html lang=\"pl\">\n"
			. "<head>\n"
			. "<meta charset=\"UTF-8\" />\n"
			. "<style>body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; }</style>\n"
			. "</head>\n"
			. "<body>\n"
			. $escaped . "\n"
			. "</body>\n"
			. "</html>\n";
	}
}
