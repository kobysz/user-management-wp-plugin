<?php
/**
 * Źródło definicji pól formularza rejestracji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Fields;

/**
 * Domena nie wie, skąd pochodzą definicje pól; pyta o nie przez ten interfejs,
 * a konkretną implementację dostarcza warstwa integracji ze źródłem definicji pól.
 */
interface FieldSource {

	/**
	 * Pola zdefiniowane w źródle, w kolejności ze źródła.
	 *
	 * @return array<string, string> Klucz pola => etykieta.
	 */
	public function fields(): array;
}
