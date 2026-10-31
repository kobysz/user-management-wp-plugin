<?php
/**
 * Fakty o stanie konfiguracji wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Zestaw faktów, z których wylicza się stan kroków listy startowej.
 *
 * Każdy fakt jest wartością prostą, bo jego odczyt wymaga rdzenia, ACF albo repozytoriów
 * wtyczki, a reguły kroków muszą dać się sprawdzić bez żadnego z nich. Odczyt należy do
 * warstwy panelu (`PortalUsers\Admin\SetupFactsReader`); ta klasa jest jego wynikiem.
 *
 * `acfReadable` jest osobnym faktem od `anyAcfGroup`: wyłączone albo zepsute ACF Pro to NIE
 * to samo co brak grup pól. Pierwsze znaczy „nie można sprawdzić”, drugie „nie zrobione”.
 */
final class SetupFacts {

	/**
	 * Konstruktor.
	 *
	 * @param bool $acfReadable             Czy listę grup pól da się w ogóle odczytać (ACF Pro odpowiada).
	 * @param bool $anyAcfGroup             Czy istnieje choć jedna grupa pól.
	 * @param bool $groupSelected           Czy w ustawieniach wskazano grupę.
	 * @param bool $groupReadable           Czy wskazana grupa nadal istnieje.
	 * @param bool $groupHasFields          Czy wskazana grupa ma choć jedno obsługiwane pole.
	 * @param bool $anyProfession           Czy istnieje choć jedna profesja.
	 * @param bool $matrixErrorFree         Czy raport spójności nie ma błędów dotyczących macierzy i pól numeru.
	 * @param bool $shortcodePagesPublished Czy strony logowania, rejestracji i resetu są wskazane i opublikowane.
	 * @param bool $afterLoginPageSet       Czy strona po zalogowaniu jest wskazana i opublikowana.
	 * @param bool $notifyEmailsPresent     Czy wpisano choć jeden adres redakcji.
	 * @param bool $rejectionReasonsNeeded  Czy akceptacja kont jest włączona (bez niej nikt nie odrzuca wniosków).
	 * @param bool $rejectionReasonsPresent Czy wpisano choć jeden powód odrzucenia.
	 * @param bool $defaultRoleOk           Czy domyślna rola nowych kont nie blokuje rejestracji.
	 * @param int  $statelessAccounts       Liczba kont sprzed wdrożenia bez stanu.
	 */
	public function __construct(
		public readonly bool $acfReadable,
		public readonly bool $anyAcfGroup,
		public readonly bool $groupSelected,
		public readonly bool $groupReadable,
		public readonly bool $groupHasFields,
		public readonly bool $anyProfession,
		public readonly bool $matrixErrorFree,
		public readonly bool $shortcodePagesPublished,
		public readonly bool $afterLoginPageSet,
		public readonly bool $notifyEmailsPresent,
		public readonly bool $rejectionReasonsNeeded,
		public readonly bool $rejectionReasonsPresent,
		public readonly bool $defaultRoleOk,
		public readonly int $statelessAccounts
	) {}
}
