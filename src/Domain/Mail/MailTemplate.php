<?php
/**
 * Temat i treść szablonu maila.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Temat i treść szablonu maila jako niezmienna para.
 */
final class MailTemplate {

	/**
	 * Tworzy szablon.
	 *
	 * @param string $subject Temat z placeholderami.
	 * @param string $body    Treść z placeholderami.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $body
	) {}

	/**
	 * Czy temat jest pusty (same białe znaki też się liczą jako pusty).
	 */
	public function subjectIsEmpty(): bool {
		return '' === trim( $this->subject );
	}

	/**
	 * Czy treść jest pusta (same białe znaki też się liczą jako pusta).
	 */
	public function bodyIsEmpty(): bool {
		return '' === trim( $this->body );
	}

	/**
	 * Czy oba pola są puste.
	 */
	public function isEmpty(): bool {
		return $this->subjectIsEmpty() && $this->bodyIsEmpty();
	}

	/**
	 * Odczytuje szablon z zapisanej tablicy; brak klucza albo wartość nietekstowa to pusty łańcuch.
	 *
	 * Zapis pochodzi z bazy, więc nie ufamy jego kształtowi: uszkodzony wpis ma dać pusty szablon,
	 * który warstwa wyżej zastąpi domyślnym, a nie błąd typu.
	 *
	 * @param array<mixed> $raw Zapisana tablica z kluczami `subject` i `body`.
	 */
	public static function fromArray( array $raw ): self {
		return new self(
			is_string( $raw['subject'] ?? null ) ? $raw['subject'] : '',
			is_string( $raw['body'] ?? null ) ? $raw['body'] : ''
		);
	}

	/**
	 * Zwraca tablicę do zapisu.
	 *
	 * @return array{subject: string, body: string}
	 */
	public function toArray(): array {
		return array(
			'subject' => $this->subject,
			'body'    => $this->body,
		);
	}
}
