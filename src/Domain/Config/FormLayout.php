<?php
/**
 * Układ formularzy frontu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Config;

/**
 * Jak formularze stoją na stronie portalu.
 *
 * `Plain` niczego nie narzuca: formularz zajmuje tyle szerokości, ile da mu skórka. `Card`
 * stawia go w karcie Bootstrapa, wyśrodkowanej i ograniczonej szerokością.
 *
 * Wartość przychodzi z zapisanej opcji, więc nieznana wraca do `Plain` — układ, który nie
 * narzuca niczego, jest jedynym bezpiecznym domyślnym na nieznanej skórce.
 */
enum FormLayout: string {

	case Plain = 'goly';
	case Card  = 'karta';

	/**
	 * Przypadek z zapisanej wartości; nieznana i każda wartość niebędąca napisem dają `Plain`.
	 *
	 * @param mixed $value Wartość z zapisu.
	 */
	public static function fromValue( mixed $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Plain ) : self::Plain;
	}
}
