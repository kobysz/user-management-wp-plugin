<?php
/**
 * Planer importu: decyduje, co import zrobi z jednym kontem.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Import;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Credentials\Normalizer;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Domain\Profession\ProfessionCollection;

/**
 * Z metadanych źródłowych jednego konta wylicza zmianę, którą import by wprowadził. Niczego nie
 * zapisuje i nie czyta z bazy: podgląd, raport i zapis opierają się na tym jednym wyniku.
 */
final class ImportPlanner {

	/**
	 * Konstruktor.
	 *
	 * @param ImportMapping        $mapping     Mapowanie kluczy źródłowych.
	 * @param ProfessionCollection $professions Profesje skonfigurowane we wtyczce.
	 * @param FieldMatrix          $matrix      Macierz widoczności pól według profesji.
	 * @param ValidatorRegistry    $validators  Walidatory numerów uprawnień.
	 */
	public function __construct(
		private readonly ImportMapping $mapping,
		private readonly ProfessionCollection $professions,
		private readonly FieldMatrix $matrix,
		private readonly ValidatorRegistry $validators
	) {}

	/**
	 * Przelicza jedno konto.
	 *
	 * Kolejność: profesja, pola (z przycięciem macierzą), numer uprawnień, stan. Wartości
	 * nieskalarne są pomijane z zastrzeżeniem, puste po trim nie są zapisywane, a numer
	 * uprawnień jest zapisywany w postaci znormalizowanej niezależnie od wyniku walidacji.
	 *
	 * @param int                  $userId     Identyfikator konta.
	 * @param array<string, mixed> $sourceMeta Klucz źródłowy => wartość taka, jak przyszła z bazy.
	 * @return AccountChange
	 */
	public function plan( int $userId, array $sourceMeta ): AccountChange {
		$issues = array();

		$profession = $this->planProfession( $sourceMeta, $issues );
		$fields     = $this->planFields( $sourceMeta, $profession, $issues );

		if ( null !== $profession ) {
			$fields = $this->checkCredential( $profession, $fields, $issues );
		}

		return new AccountChange(
			$userId,
			$fields,
			$profession?->key,
			$this->planStatus( $sourceMeta, $issues ),
			$issues
		);
	}

	/**
	 * Ustala profesję konta albo zgłasza, dlaczego się nie udało. Bez skonfigurowanego klucza
	 * profesji mapowanie o nią nie pyta, więc nie ma czego rozstrzygać ani zgłaszać.
	 *
	 * @param array<string, mixed> $sourceMeta Metadane źródłowe.
	 * @param string[]             $issues     Zbierane kody zastrzeżeń.
	 * @return Profession|null
	 */
	private function planProfession( array $sourceMeta, array &$issues ): ?Profession {
		if ( '' === $this->mapping->professionKey() ) {
			return null;
		}

		$value = $this->usableText( $sourceMeta, $this->mapping->professionKey(), $issues );

		if ( null === $value ) {
			$issues[] = AccountChange::ISSUE_PROFESSION_MISSING;
			return null;
		}

		$key        = $this->mapping->professionFor( $value );
		$profession = null === $key ? null : $this->professions->get( $key );

		if ( null === $profession ) {
			$issues[] = AccountChange::ISSUE_PROFESSION_UNMAPPED;
		}

		return $profession;
	}

	/**
	 * Zbiera pola do zapisu: przy rozpoznanej profesji tylko widoczne dla niej, inaczej wszystkie zmapowane.
	 *
	 * @param array<string, mixed> $sourceMeta Metadane źródłowe.
	 * @param Profession|null      $profession Rozpoznana profesja albo null.
	 * @param string[]             $issues     Zbierane kody zastrzeżeń.
	 * @return array<string, string>
	 */
	private function planFields( array $sourceMeta, ?Profession $profession, array &$issues ): array {
		$visible = null === $profession ? null : $this->matrix->visibleFields( $profession->key );
		$fields  = array();

		foreach ( $this->mapping->fields() as $fieldKey => $sourceKey ) {
			if ( null !== $visible && ! in_array( $fieldKey, $visible, true ) ) {
				continue;
			}

			$value = $this->usableText( $sourceMeta, $sourceKey, $issues );

			if ( null !== $value ) {
				$fields[ $fieldKey ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Sprawdza numer uprawnień rozpoznanej profesji i podmienia go na postać znormalizowaną.
	 *
	 * Walidacja jest miękka: zły numer wchodzi do pól i tylko dostaje zastrzeżenie. Numer, który po
	 * normalizacji jest pusty, nie jest zapisywany, bo import nie nadpisuje danych pustką; ta reguła
	 * wygrywa z zapisem postaci znormalizowanej, a administrator i tak dostaje zastrzeżenie o numerze.
	 *
	 * @param Profession            $profession Rozpoznana profesja.
	 * @param array<string, string> $fields     Zaplanowane pola, już po przycięciu macierzą.
	 * @param string[]              $issues     Zbierane kody zastrzeżeń.
	 * @return array<string, string>
	 */
	private function checkCredential( Profession $profession, array $fields, array &$issues ): array {
		$field = $profession->credentialField;

		if ( '' === $field || ! $profession->requiresCredential() || ! array_key_exists( $field, $fields ) ) {
			return $fields;
		}

		$clean = Normalizer::clean( $fields[ $field ] );

		if ( '' === $clean ) {
			unset( $fields[ $field ] );
			$issues[] = AccountChange::ISSUE_CREDENTIAL_INVALID;
			return $fields;
		}

		$fields[ $field ] = $clean;
		$validator        = $this->validators->for( $profession->credentialType );

		if ( null !== $validator && ! $validator->validate( $clean )->isValid() ) {
			$issues[] = AccountChange::ISSUE_CREDENTIAL_INVALID;
		}

		return $fields;
	}

	/**
	 * Ustala stan konta: zmapowany, brak (null) albo wynikający z fallbacku.
	 *
	 * @param array<string, mixed> $sourceMeta Metadane źródłowe.
	 * @param string[]             $issues     Zbierane kody zastrzeżeń.
	 * @return AccountStatus|null
	 */
	private function planStatus( array $sourceMeta, array &$issues ): ?AccountStatus {
		$configured = '' !== $this->mapping->statusKey();
		$value      = $configured ? $this->usableText( $sourceMeta, $this->mapping->statusKey(), $issues ) : null;

		if ( null === $value ) {
			// Klucz nieskonfigurowany znaczy, że mapowanie o stan nie pyta: fallback działa po cichu.
			if ( $configured ) {
				$issues[] = AccountChange::ISSUE_STATUS_MISSING;
			}

			return match ( $this->mapping->fallback() ) {
				ImportMapping::FALLBACK_PENDING  => AccountStatus::Pending,
				ImportMapping::FALLBACK_APPROVED => AccountStatus::Approved,
				default                          => null,
			};
		}

		$status = $this->mapping->statusFor( $value );

		if ( null === $status ) {
			$issues[] = AccountChange::ISSUE_STATUS_UNMAPPED;
		}

		return $status;
	}

	/**
	 * Czyta wartość źródłową jako tekst, jeśli nadaje się do użycia.
	 *
	 * Zwraca null dla braku klucza, wartości null, pustej po trim i nieskalarnej; ta ostatnia dodatkowo
	 * dostaje zastrzeżenie. Rzutowanie na tekst dotyczy wyłącznie skalarów (true daje "1", false daje "").
	 *
	 * @param array<string, mixed> $sourceMeta Metadane źródłowe.
	 * @param string               $sourceKey  Klucz źródłowy; pusty znaczy, że nic nie mapujemy.
	 * @param string[]             $issues     Zbierane kody zastrzeżeń.
	 * @return string|null
	 */
	private function usableText( array $sourceMeta, string $sourceKey, array &$issues ): ?string {
		if ( '' === $sourceKey || ! array_key_exists( $sourceKey, $sourceMeta ) ) {
			return null;
		}

		$value = $sourceMeta[ $sourceKey ];

		if ( null === $value ) {
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			$issues[] = AccountChange::ISSUE_VALUE_UNUSABLE;
			return null;
		}

		$text = (string) $value;

		return '' === trim( $text ) ? null : $text;
	}
}
