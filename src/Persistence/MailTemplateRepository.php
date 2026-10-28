<?php
/**
 * Szablony maili: domyślne treści z nadpisaniami admina.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;
use PortalUsers\Mail\DefaultTemplates;

/**
 * Nadpisania leżą w jednej opcji `array<string, array{subject: string, body: string}>` kluczowanej `MailKind::value`.
 *
 * Nadpisanie działa per pole: wyczyszczony temat wraca na domyślny, zamiast wysyłać mail bez tematu.
 * Pustych pól się nie zapisuje, a rodzaj bez żadnego niepustego pola znika z opcji. Zapisany pusty
 * rekord zamroziłby treść na zawsze, bo późniejsza poprawka tekstu domyślnego nie dotarłaby
 * do portali, które „nadpisały" ją pustką.
 */
final class MailTemplateRepository {

	/**
	 * Szablon do wysyłki: nadpisanie tam, gdzie je ustawiono, domyślna treść w pozostałych polach.
	 *
	 * @param MailKind $kind Rodzaj maila.
	 */
	public static function get( MailKind $kind ): MailTemplate {
		$default  = DefaultTemplates::for( $kind );
		$override = self::override( $kind );

		return new MailTemplate(
			$override->subjectIsEmpty() ? $default->subject : $override->subject,
			$override->bodyIsEmpty() ? $default->body : $override->body
		);
	}

	/**
	 * Samo nadpisanie, bez odwrotu na domyślne: ekran admina po tym rozpoznaje, czy pokazać wartość,
	 * czy tylko podpowiedź z domyślnym tekstem. Pole bez nadpisania jest puste.
	 *
	 * @param MailKind $kind Rodzaj maila.
	 */
	public static function override( MailKind $kind ): MailTemplate {
		$stored = OptionStore::read( OptionStore::OPTION_MAIL_TEMPLATES )[ $kind->value ] ?? null;

		return MailTemplate::fromArray( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Zapisuje nadpisanie rodzaju; pole puste (także same białe znaki) usuwa swoje nadpisanie.
	 *
	 * Pole niepuste zapisujemy tak, jak je podano: treść maila ma prawo kończyć się nową linią.
	 *
	 * @param MailKind     $kind     Rodzaj maila.
	 * @param MailTemplate $template Wpisane pola; puste oznaczają „użyj domyślnego".
	 */
	public static function save( MailKind $kind, MailTemplate $template ): void {
		$all    = OptionStore::read( OptionStore::OPTION_MAIL_TEMPLATES );
		$record = array();
		if ( ! $template->subjectIsEmpty() ) {
			$record['subject'] = $template->subject;
		}
		if ( ! $template->bodyIsEmpty() ) {
			$record['body'] = $template->body;
		}

		if ( array() === $record ) {
			unset( $all[ $kind->value ] );
		} else {
			$all[ $kind->value ] = $record;
		}

		if ( array() === $all ) {
			OptionStore::delete( OptionStore::OPTION_MAIL_TEMPLATES );
			return;
		}
		OptionStore::write( OptionStore::OPTION_MAIL_TEMPLATES, $all );
	}
}
