<?php
/**
 * Reguły spójności konfiguracji: lista profesji, macierz pól i źródło definicji pól.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\FieldSource;
use PortalUsers\Domain\Fields\FieldState;
use PortalUsers\Domain\Profession\ProfessionCollection;

/**
 * Konfiguracja żyje w trzech miejscach i nic poza tą klasą nie sprawdza, czy
 * mówią to samo. Dwie ciche awarie, które reguły wychwytują: profesja z typem
 * numeru, której pole numeru jest w macierzy ukryte lub nieustawione (zgłoszenie
 * przechodzi wtedy bez numeru, bo walidator czyta wartości po odsiewie), oraz pole
 * ze źródła bez wpisu w macierzy (jest po cichu odsiewane przy rejestracji).
 */
final class ConsistencyChecker {

	/**
	 * Konstruktor.
	 *
	 * @param ProfessionCollection $professions Lista profesji.
	 * @param FieldMatrix          $matrix      Macierz profesja x pole.
	 * @param FieldSource          $fields      Źródło definicji pól.
	 */
	public function __construct(
		private ProfessionCollection $professions,
		private FieldMatrix $matrix,
		private FieldSource $fields
	) {}

	/**
	 * Wykryte problemy: błędy przed ostrzeżeniami, w obrębie wagi w kolejności wykrycia.
	 *
	 * @return array<int, SettingsIssue>
	 */
	public function issues(): array {
		$rows   = $this->matrix->toArray();
		$source = array_map( 'strval', array_keys( $this->fields->fields() ) );
		$found  = array();

		foreach ( $this->professions->all() as $profession ) {
			if ( ! array_key_exists( $profession->key, $rows ) ) {
				// Brak wiersza jest przyczyną pierwotną; pole numeru sprawdzimy po jego dodaniu.
				$found[] = SettingsIssue::error( 'profession_without_matrix_row', array( 'profession' => $profession->key ) );
				continue;
			}

			if ( $profession->requiresCredential() ) {
				$unset = '' === $profession->credentialField;

				if ( $unset || FieldState::Required !== $this->matrix->stateFor( $profession->key, $profession->credentialField ) ) {
					$found[] = SettingsIssue::error(
						'credential_field_not_required',
						array(
							'profession' => $profession->key,
							'field'      => $profession->credentialField,
							'reason'     => $unset ? SettingsIssue::REASON_CREDENTIAL_FIELD_UNSET : SettingsIssue::REASON_CREDENTIAL_FIELD_NOT_REQUIRED,
						)
					);
				}
			}

			foreach ( $source as $fieldKey ) {
				if ( ! array_key_exists( $fieldKey, $rows[ $profession->key ] ) ) {
					$found[] = SettingsIssue::warning(
						'field_not_configured',
						array(
							'profession' => $profession->key,
							'field'      => $fieldKey,
						)
					);
				}
			}
		}

		foreach ( $rows as $professionKey => $fields ) {
			$professionKey = (string) $professionKey;

			if ( ! $this->professions->has( $professionKey ) ) {
				$found[] = SettingsIssue::warning( 'matrix_profession_unknown', array( 'profession' => $professionKey ) );
			}

			foreach ( array_keys( $fields ) as $fieldKey ) {
				if ( in_array( (string) $fieldKey, $source, true ) ) {
					continue;
				}

				$context = array(
					'profession' => $professionKey,
					'field'      => (string) $fieldKey,
				);
				// Wymagane pole, którego formularz nigdy nie wyrenderuje: walidator zgłosi twardy błąd
				// "required" i profesja przestaje być rejestrowalna. Dotyczy tylko istniejącej profesji
				// (wiersz bez profesji nikogo nie blokuje); pozostałe stany tylko zaśmiecają.
				if ( $this->professions->has( $professionKey ) && FieldState::Required === $this->matrix->stateFor( $professionKey, (string) $fieldKey ) ) {
					$found[] = SettingsIssue::error( 'matrix_required_field_not_in_source', $context );
				} else {
					$found[] = SettingsIssue::warning( 'matrix_field_not_in_source', $context );
				}
			}
		}

		return array_merge(
			array_values( array_filter( $found, static fn ( SettingsIssue $i ): bool => SettingsIssue::ERROR === $i->severity ) ),
			array_values( array_filter( $found, static fn ( SettingsIssue $i ): bool => SettingsIssue::WARNING === $i->severity ) )
		);
	}
}
