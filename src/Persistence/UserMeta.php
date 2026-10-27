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
 * Klasa trzyma stałe nazw metadanych oraz JEDEN przedrostek nazw (`ACF_PREFIX`) i nic więcej.
 * Test refleksyjny porównuje all() ze stałymi tekstowymi klasy poza tym przedrostkiem, więc
 * stała tekstowa innego rodzaju (np. wartość domyślna, etykieta) wywaliłaby go bez winy
 * w kodzie. Potrzebna stała, która nie jest nazwą ani przedrostkiem nazwy, należy do innej klasy.
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
	 * Przedrostek metadanych z wartościami pól ACF ze zgłoszenia: nazwa metadanej to przedrostek
	 * plus nazwa pola w grupie ACF. Nazw tych nie da się wymienić z góry — zależą od konfiguracji
	 * administratora — więc przedrostek jest jedynym, co można zapisać, i stoi tutaj, żeby było
	 * jedno miejsce, w którym się o nim czyta.
	 *
	 * NIE jest nazwą metadanej, więc nie ma go w `all()`. Przedrostek (zamiast listy zakazanych
	 * nazw) jest tym, co czyni kolizję z metadanymi WordPressa niemożliwą z konstrukcji; eksport
	 * i usuwanie danych osobowych (Plan 7) będzie potrzebował właśnie jego, bo tylko on opisuje
	 * cały zbiór.
	 */
	public const ACF_PREFIX = 'pusers_acf_';

	/**
	 * Nazwy metadanych wtyczki o stałych nazwach, w kolejności deklaracji. Każda nowa stała nazwy
	 * musi zostać dopisana tutaj.
	 *
	 * Konsumentem NIE jest odinstalowanie: `Uninstaller::run()` nie rusza metadanych użytkowników
	 * i jest to decyzja zapisana w jego docbloku (konto po usunięciu wtyczki zostaje, a z nim jego
	 * profil). Spis czyta dziś test refleksyjny, a potrzebować go będzie eksport i usuwanie danych
	 * osobowych (Plan 7); metadanych pól ACF nie obejmuje, bo ich nazwy zależą od konfiguracji
	 * (patrz `ACF_PREFIX`).
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
