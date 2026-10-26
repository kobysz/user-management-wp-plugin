<?php
/**
 * Odsiew wartości pól ukrytych dla profesji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Fields;

/**
 * Serwer nie ufa przeglądarce: pole ukryte dla wybranej profesji jest
 * odrzucane, nawet jeśli przyszło w żądaniu.
 */
final class SubmissionFilter {

	/**
	 * Konstruktor.
	 *
	 * @param FieldMatrix $matrix Macierz profesja x pole.
	 */
	public function __construct( private FieldMatrix $matrix ) {}

	/**
	 * Zostawia tylko wartości pól widocznych dla profesji, z zachowaniem kolejności.
	 *
	 * @param string               $professionKey Klucz profesji.
	 * @param array<string, mixed> $values        Wartości z żądania: pole => wartość.
	 * @return array<string, mixed>
	 */
	public function strip( string $professionKey, array $values ): array {
		$kept = array();

		foreach ( $values as $fieldKey => $value ) {
			if ( FieldState::Hidden !== $this->matrix->stateFor( $professionKey, (string) $fieldKey ) ) {
				$kept[ $fieldKey ] = $value;
			}
		}

		return $kept;
	}
}
