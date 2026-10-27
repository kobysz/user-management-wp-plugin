<?php
/**
 * Atrapa renderera pól ACF dla testów integracyjnych.
 *
 * ACF Pro jest licencjonowany i nie ma go w środowisku testowym, więc atrapa zapisuje
 * pole, które wtyczka przekazałaby do ACF, zamiast je renderować.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Support {

	/**
	 * Rejestr wywołań atrapy.
	 */
	final class AcfRenderRecorder {

		/**
		 * Pola przekazane do atrapy w kolejności wywołań.
		 *
		 * @var list<array<string, mixed>>
		 */
		public static array $fields = array();
	}
}

namespace {

	if ( ! function_exists( 'acf_render_field_wrap' ) ) {
		/**
		 * Atrapa `acf_render_field_wrap()`.
		 *
		 * Odtwarza nazywanie pola z `acf_prepare_field()`, bo tylko ono decyduje, pod jakim
		 * indeksem wartość wraca w żądaniu. ACF Pro robi to w tej kolejności:
		 *
		 *     if ( $field['key'] )    { $field['name'] = $field['key']; }
		 *     if ( $field['prefix'] ) { $field['name'] = "{$field['prefix']}[{$field['name']}]"; }
		 *
		 * czyli NADPISUJE nazwę kluczem i dopiero potem owija ją prefiksem. Atrapa honorująca
		 * nazwę ustawioną przez wtyczkę dowodziłaby kontraktu, którego ACF nie ma.
		 *
		 * Oba warunki są DOSŁOWNIE warunkami ACF: czysta prawdziwość, nie `isset()` ani
		 * `is_string()`. Napis `'0'` jest falsy, więc nie nazywa inputu, a klucz liczbowy jest
		 * truthy i nazywa go. Zaślepka pytająca inaczej zgadzałaby się z naszym kodem zamiast
		 * z ACF i zasłaniałaby jego błąd — zamieniłaby suitę w lustro. Nazwa powstaje
		 * interpolacją, jak w ACF, więc klucz niebędący napisem dociera do żądania jako napis.
		 *
		 * @param array<string, mixed> $field Pole ACF.
		 */
		function acf_render_field_wrap( array $field ): void {
			if ( $field['key'] ?? null ) {
				$field['name'] = $field['key'];
			}
			if ( $field['prefix'] ?? null ) {
				$field['name'] = "{$field['prefix']}[{$field['name']}]";
			}
			\PortalUsers\Tests\Integration\Support\AcfRenderRecorder::$fields[] = $field;
			echo '<div class="acf-stub" data-name="' . esc_attr( (string) ( $field['name'] ?? '' ) ) . '"></div>';
		}
	}
}
