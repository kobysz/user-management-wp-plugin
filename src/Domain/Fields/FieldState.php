<?php
/**
 * Stan pola formularza dla danej profesji.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Fields;

/**
 * Pole jest ukryte, widoczne albo wymagane. Wartość tekstowa jest formą zapisu w konfiguracji.
 */
enum FieldState: string {
	case Hidden   = 'hidden';
	case Visible  = 'visible';
	case Required = 'required';
}
