<?php
/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Mail;

/**
 * Podstawianie placeholderów w szablonie maila.
 *
 * Jeden przebieg: wartość zawierająca {klucz} nie jest rozwijana ponownie, więc dane
 * z formularza nie sterują szablonem. Nieznany placeholder zostaje w treści dosłownie,
 * żeby literówka w szablonie była widoczna zamiast zamieniać się w pustkę.
 */
final class TemplateRenderer {

	/**
	 * Renderuje szablon, podstawiając znane placeholdery.
	 *
	 * @param string       $template Szablon z placeholderami w klamrach.
	 * @param Placeholders $values   Wartości do podstawienia.
	 */
	public static function render( string $template, Placeholders $values ): string {
		$rendered = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( array $matches ) use ( $values ): string {
				return $values->has( $matches[1] ) ? $values->get( $matches[1] ) : $matches[0];
			},
			$template
		);

		// Błąd wyrażenia nie może zgubić maila: lepiej wysłać szablon z klamrami niż nic.
		return null === $rendered ? $template : $rendered;
	}
}
