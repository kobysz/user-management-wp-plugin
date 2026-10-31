<?php
/**
 * Wspólne opakowanie wyjścia shortcode'ów frontu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Config\FormLayout;
use PortalUsers\Persistence\SettingsRepository;

/**
 * Jeden `div` wokół całego wyjścia shortcode'u: notek i formularza razem.
 *
 * Opakowanie nie jest ozdobą. Niesie trzy rzeczy, których sam `<form>` unieść nie może:
 * zasięg arkusza zapasowego (notki stoją poza formularzem, a część widoków formularza nie ma
 * wcale), tryb karty (notka ma być W karcie, nie nad nią) oraz jedną kartę na widok, który
 * oddaje notkę i formularz naraz.
 *
 * Zakłada się je RAZ, na samym wyjściu `render()` shortcode'u — nigdy w metodach, z których
 * render składa swoje części.
 */
final class FormWrapper {

	/**
	 * Klasa opakowania; zarazem selektor zasięgu arkuszy wtyczki.
	 */
	public const CLASS_NAME = 'pusers-front';

	/**
	 * Opakowuje wyjście widoku. Puste wyjście zostaje puste: pusty `div` na stronie to tylko
	 * miejsce, w którym z odstępów karty robi się pudełko bez treści.
	 *
	 * @param string $html Wyjście widoku.
	 */
	public static function wrap( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		if ( FormLayout::Card !== SettingsRepository::get()->formLayout ) {
			return '<div class="' . FormClasses::attr( 'wrapper', self::CLASS_NAME ) . '">' . $html . '</div>';
		}

		return '<div class="' . FormClasses::attr( 'wrapper_card', self::CLASS_NAME ) . '">'
			. '<div class="' . FormClasses::attr( 'card_body' ) . '">' . $html . '</div></div>';
	}
}
