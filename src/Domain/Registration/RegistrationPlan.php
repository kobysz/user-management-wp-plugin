<?php
/**
 * Plan renderowania formularza rejestracji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Registration;

use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldSource;
use PortalUsers\Domain\Fields\FieldState;

/**
 * Łączy macierz (co widać i co wymagane) ze źródłem definicji pól (w jakiej
 * kolejności). Kolejność pól w formularzu jest decyzją klienta podjętą w ACF,
 * więc plan zawsze idzie za źródłem, nie za kolejnością kluczy w konfiguracji.
 */
final class RegistrationPlan {

	/**
	 * Konstruktor.
	 *
	 * @param FieldMatrix $pruned Macierz przycięta do pól ze źródła.
	 * @param FieldSource $fields Źródło definicji pól.
	 */
	public function __construct(
		private FieldMatrix $pruned,
		private FieldSource $fields
	) {}

	/**
	 * Pola do wyrenderowania dla profesji, w kolejności ze źródła. Pola ukryte
	 * oraz pola bez wpisu w macierzy są pomijane.
	 *
	 * @param string $professionKey Klucz profesji.
	 * @return array<string, FieldState> Klucz pola => stan (widoczne lub wymagane).
	 */
	public function fieldsFor( string $professionKey ): array {
		$plan = array();

		foreach ( array_keys( $this->fields->fields() ) as $fieldKey ) {
			$state = $this->pruned->stateFor( $professionKey, (string) $fieldKey );

			if ( FieldState::Hidden !== $state ) {
				$plan[ (string) $fieldKey ] = $state;
			}
		}

		return $plan;
	}

	/**
	 * Klucze pól wymaganych, w kolejności ze źródła.
	 *
	 * @param string $professionKey Klucz profesji.
	 * @return array<int, string>
	 */
	public function requiredFor( string $professionKey ): array {
		$required = array();

		foreach ( $this->fieldsFor( $professionKey ) as $fieldKey => $state ) {
			if ( FieldState::Required === $state ) {
				$required[] = $fieldKey;
			}
		}

		return $required;
	}
}
