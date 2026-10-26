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
		 * @param array<string, mixed> $field Pole ACF.
		 */
		function acf_render_field_wrap( array $field ): void {
			\PortalUsers\Tests\Integration\Support\AcfRenderRecorder::$fields[] = $field;
			echo '<div class="acf-stub" data-name="' . esc_attr( (string) ( $field['name'] ?? '' ) ) . '"></div>';
		}
	}
}
