<?php
/**
 * Kroki konfiguracji wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Osiem kroków, które administrator wykonuje, żeby portal zaczął przyjmować zgłoszenia.
 *
 * Kolejność przypadków NIE jest dowolna: jest kolejnością kroków w `docs/konfiguracja.md`,
 * bo każdy krok karmi listę wyborów w następnym (bez wskazanej grupy pól macierz nie ma
 * z czego zbudować wierszy). Zgodność z dokumentem trzyma `SetupStepKeyTest`.
 */
enum SetupStepKey: string {
	case AcfGroup       = 'acf_group';
	case GroupSelected  = 'group_selected';
	case Professions    = 'professions';
	case Matrix         = 'matrix';
	case Pages          = 'pages';
	case Settings       = 'settings';
	case MailTemplates  = 'mail_templates';
	case LegacyAccounts = 'legacy_accounts';
}
