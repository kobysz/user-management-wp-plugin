<?php
/**
 * Wynik walidacji zgłoszenia.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Submission;

/**
 * Błędy twarde, wartości po odsiewie i normalizacji oraz miękkie ostrzeżenie
 * o numerze uprawnień. Ostrzeżenie nie wpływa na ważność zgłoszenia.
 */
final class SubmissionResult {

	/**
	 * Klucz błędu niezwiązanego z żadnym polem.
	 */
	public const PROFESSION_KEY = '__profession';

	/**
	 * Konstruktor.
	 *
	 * @param array<string, string> $errors            Kody błędów: pole => kod.
	 * @param array<string, mixed>  $values            Wartości po odsiewie i normalizacji.
	 * @param string                $credentialWarning Kod ostrzeżenia o numerze albo pusty string.
	 */
	public function __construct(
		private array $errors,
		private array $values,
		private string $credentialWarning = ''
	) {}

	/**
	 * Czy zgłoszenie nie ma błędów twardych.
	 *
	 * @return bool
	 */
	public function isValid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Kody błędów twardych.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Wartości po odsiewie pól ukrytych i normalizacji numeru.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Kod błędu pola albo pusty string.
	 *
	 * @param string $fieldKey Klucz pola.
	 * @return string
	 */
	public function errorFor( string $fieldKey ): string {
		return $this->errors[ $fieldKey ] ?? '';
	}

	/**
	 * Kod ostrzeżenia o numerze uprawnień albo pusty string.
	 *
	 * @return string
	 */
	public function credentialWarning(): string {
		return $this->credentialWarning;
	}
}
