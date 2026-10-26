<?php
/**
 * Nazwy metadanych użytkownika należących do wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Jedyne miejsce, w którym zapisane są nazwy metadanych użytkownika.
 *
 * Klasa trzyma WYŁĄCZNIE stałe nazw metadanych i nic więcej. Test refleksyjny porównuje
 * all() ze wszystkimi stałymi tekstowymi klasy, więc stała tekstowa innego rodzaju
 * (np. wartość domyślna, prefiks, etykieta) wywaliłaby go bez winy w kodzie. Potrzebna
 * stała, która nie jest nazwą metadanej, należy do innej klasy.
 *
 * PROFESSION istnieje już w bazach klientów — jej wartość nie może się zmienić.
 */
final class UserMeta {

	public const PROFESSION      = 'pusers_profession';
	public const STATUS          = 'pusers_status';
	public const CONFIRM_HASH    = 'pusers_confirm_hash';
	public const CONFIRM_EXPIRES = 'pusers_confirm_expires';
	public const TERMS_VERSION   = 'pusers_terms_version';
	public const CONSENT_AT      = 'pusers_consent_at';

	/**
	 * Nazwy wszystkich metadanych wtyczki, w kolejności deklaracji; korzysta z nich
	 * odinstalowanie. Każda nowa stała musi zostać dopisana tutaj.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::PROFESSION,
			self::STATUS,
			self::CONFIRM_HASH,
			self::CONFIRM_EXPIRES,
			self::TERMS_VERSION,
			self::CONSENT_AT,
		);
	}
}
