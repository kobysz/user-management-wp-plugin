<?php
/**
 * Zmiana planowana dla jednego konta podczas importu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Import;

use PortalUsers\Domain\Account\AccountStatus;

/**
 * Niesie wynik planowania dla konta: co zapisać i jakie zastrzeżenia zgłosić. Nie zapisuje niczego sam.
 */
final class AccountChange {

	/**
	 * Profesja ze starego systemu nie ma odpowiednika w ustawieniach.
	 */
	public const ISSUE_PROFESSION_UNMAPPED = 'profession_unmapped';

	/**
	 * Konto nie ma żadnego wpisu profesji.
	 */
	public const ISSUE_PROFESSION_MISSING = 'profession_missing';

	/**
	 * Numer uprawnień nie przechodzi walidacji.
	 */
	public const ISSUE_CREDENTIAL_INVALID = 'credential_invalid';

	/**
	 * Stan konta nie ma odpowiednika w stanach tej wtyczki.
	 */
	public const ISSUE_STATUS_UNMAPPED = 'status_unmapped';

	/**
	 * Konto nie ma żadnego stanu.
	 */
	public const ISSUE_STATUS_MISSING = 'status_missing';

	/**
	 * Wartość pola nie nadaje się do zapisu.
	 */
	public const ISSUE_VALUE_UNUSABLE = 'value_unusable';

	/**
	 * Identyfikator konta.
	 *
	 * @var int
	 */
	public readonly int $userId;

	/**
	 * Pola do zapisania: klucz pola ACF => wartość już rzutowana na łańcuch.
	 *
	 * @var array<string, string>
	 */
	public readonly array $fields;

	/**
	 * Klucz profesji do ustawienia, albo null, gdy profesji nie zmieniamy.
	 *
	 * @var string|null
	 */
	public readonly ?string $professionKey;

	/**
	 * Stan konta do ustawienia, albo null, gdy stanu nie zmieniamy.
	 *
	 * @var AccountStatus|null
	 */
	public readonly ?AccountStatus $status;

	/**
	 * Kody zastrzeżeń: posortowane i bez duplikatów.
	 *
	 * @var list<string>
	 */
	public readonly array $issues;

	/**
	 * Konstruktor.
	 *
	 * @param int                   $userId        Identyfikator konta.
	 * @param array<string, string> $fields        Pola do zapisania; wartości już rzutowane na łańcuchy.
	 * @param ?string               $professionKey Klucz profesji albo null.
	 * @param ?AccountStatus        $status        Stan konta albo null.
	 * @param string[]              $issues        Kody zastrzeżeń; kolejność i duplikaty są ignorowane.
	 */
	public function __construct(
		int $userId,
		array $fields,
		?string $professionKey,
		?AccountStatus $status,
		array $issues
	) {
		$this->userId        = $userId;
		$this->fields        = $fields;
		$this->professionKey = $professionKey;
		$this->status        = $status;

		$unique = array_values( array_unique( $issues ) );
		sort( $unique );
		$this->issues = $unique;
	}

	/**
	 * Czy ta zmiana cokolwiek zapisze: pola, profesja albo stan.
	 *
	 * @return bool
	 */
	public function hasWrites(): bool {
		return array() !== $this->fields || null !== $this->professionKey || null !== $this->status;
	}

	/**
	 * Czy zgłoszono dane zastrzeżenie.
	 *
	 * @param string $code Kod zastrzeżenia.
	 * @return bool
	 */
	public function hasIssue( string $code ): bool {
		return in_array( $code, $this->issues, true );
	}
}
