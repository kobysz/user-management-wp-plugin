<?php
/**
 * Okienka komunikatów na stronie portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

/**
 * Jedno miejsce, które składa notkę. Klasa `pusers-notice` zostaje na elemencie: mogą być na
 * niej zaczepione style portalu.
 *
 * Pusty komunikat nie rysuje NICZEGO — puste okienko alertu to awaria najtrudniejsza do
 * zgłoszenia, bo nie ma czego zacytować.
 */
final class Notice {

	/**
	 * Notka z jednym zdaniem.
	 *
	 * @param string     $message Treść (tekst, nie HTML).
	 * @param NoticeTone $tone    Ton komunikatu.
	 */
	public static function html( string $message, NoticeTone $tone ): string {
		if ( '' === $message ) {
			return '';
		}

		return '<p class="' . FormClasses::attr( $tone->classRole(), 'pusers-notice' ) . '" role="' . esc_attr( $tone->role() ) . '">'
			. esc_html( $message ) . '</p>';
	}

	/**
	 * Notka i lista pozycji w JEDNYM okienku.
	 *
	 * Lista jest rodzeństwem akapitu, nie jego zawartością (`<ul>` nie wchodzi do `<p>`), więc
	 * okienko jest elementem blokowym obejmującym oba. Lista bez notki dostaje okienko sama:
	 * zgłoszenie odrzucone na polach nie zawsze ma zdanie wstępne.
	 *
	 * @param string             $message Treść notki (pusta = bez akapitu).
	 * @param array<int, string> $items   Pozycje listy (tekst, nie HTML).
	 * @param NoticeTone         $tone    Ton komunikatu.
	 */
	public static function with_list( string $message, array $items, NoticeTone $tone ): string {
		if ( '' === $message && array() === $items ) {
			return '';
		}

		$html = '<div class="' . FormClasses::attr( $tone->classRole(), 'pusers-notice' ) . '" role="' . esc_attr( $tone->role() ) . '">';
		if ( '' !== $message ) {
			$html .= '<p class="mb-2">' . esc_html( $message ) . '</p>';
		}
		if ( array() !== $items ) {
			$html .= '<ul class="' . FormClasses::attr( 'notice_list', 'pusers-errors' ) . '">';
			foreach ( $items as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html . '</div>';
	}
}
