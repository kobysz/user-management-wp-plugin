<?php
/**
 * Walidacja zgłoszenia rejestracyjnego.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Submission;

use PortalUsers\Domain\Credentials\CredentialResult;
use PortalUsers\Domain\Credentials\Normalizer;
use PortalUsers\Domain\Credentials\ValidatorRegistry;
use PortalUsers\Domain\Fields\FieldMatrix;
use PortalUsers\Domain\Fields\SubmissionFilter;
use PortalUsers\Domain\Profession\ProfessionCollection;

/**
 * Spina profesje, macierz pól i walidatory numerów. Brak numeru w polu
 * wymaganym jest błędem twardym; zły numer tylko ostrzeżeniem dla redakcji.
 */
final class SubmissionValidator {

	/**
	 * Konstruktor.
	 *
	 * @param ProfessionCollection $professions Skonfigurowane profesje.
	 * @param FieldMatrix          $matrix      Macierz profesja x pole.
	 * @param ValidatorRegistry    $validators  Walidatory numerów uprawnień.
	 */
	public function __construct(
		private ProfessionCollection $professions,
		private FieldMatrix $matrix,
		private ValidatorRegistry $validators
	) {}

	/**
	 * Waliduje zgłoszenie.
	 *
	 * Wartości w `values()` mogą być nietekstowe (tablice, liczby, wartości logiczne),
	 * więc sanityzacja przy zapisie należy do warstwy zapisu, nie do walidatora.
	 *
	 * @param string               $professionKey      Klucz wybranej profesji.
	 * @param string               $credentialFieldKey Klucz pola z numerem uprawnień.
	 * @param array<string, mixed> $values             Wartości z żądania: pole => wartość.
	 * @return SubmissionResult
	 */
	public function validate( string $professionKey, string $credentialFieldKey, array $values ): SubmissionResult {
		$profession = $this->professions->get( $professionKey );
		if ( null === $profession ) {
			return new SubmissionResult( array( SubmissionResult::PROFESSION_KEY => 'unknown_profession' ), array() );
		}

		$kept   = ( new SubmissionFilter( $this->matrix ) )->strip( $professionKey, $values );
		$errors = array();

		foreach ( $this->matrix->requiredFields( $professionKey ) as $fieldKey ) {
			if ( self::isEmpty( $kept[ $fieldKey ] ?? null ) ) {
				$errors[ $fieldKey ] = 'required';
			}
		}

		$warning = '';
		$raw     = $kept[ $credentialFieldKey ] ?? null;
		$checker = $this->validators->for( $profession->credentialType );

		if ( null !== $checker && ! self::isEmpty( $raw ) ) {
			if ( is_string( $raw ) ) {
				$result = $checker->validate( $raw );
				if ( $result->isValid() ) {
					$kept[ $credentialFieldKey ] = $result->normalized();
				} else {
					$cleaned = Normalizer::clean( $raw );
					// Same separatory: zachowaj wpisaną treść, redakcja ma widzieć, co użytkownik wpisał.
					$kept[ $credentialFieldKey ] = '' === $cleaned ? $raw : $cleaned;
					$warning                     = $result->code();

					// Numer pusty po normalizacji to brak numeru, a nie zły numer.
					if ( 'empty' === $result->code() && in_array( $credentialFieldKey, $this->matrix->requiredFields( $professionKey ), true ) ) {
						$errors[ $credentialFieldKey ] = 'required';
					}
				}
			} else {
				$warning = CredentialResult::INVALID_FORMAT;
			}
		}

		return new SubmissionResult( $errors, $kept, $warning );
	}

	/**
	 * Czy wartość to brak. Zamknięta lista braków: `[]`, `['']`, `''`, `'   '`, `null`
	 * (oraz tablica złożona wyłącznie z takich braków).
	 *
	 * Wartości fałszywe, w tym `'0'`, `0`, `0.0` i `false`, NIE są brakiem: zero jest legalną
	 * wartością (np. lata praktyki równe 0) i spełnia `required`. Za to, by odznaczone pole
	 * logiczne (checkbox, ACF `true_false` z ukrytym inputem `0`) wysyłało wartość pustą, a nie
	 * `'0'`, odpowiada warstwa formularza. Świadoma decyzja, przypięta testem.
	 *
	 * @param mixed $value Wartość z żądania.
	 * @return bool
	 */
	private static function isEmpty( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::isEmpty( $item ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		return false;
	}
}
