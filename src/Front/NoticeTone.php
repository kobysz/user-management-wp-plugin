<?php
/**
 * Ton komunikatu na stronie portalu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

/**
 * Czym jest komunikat dla czytającego: potwierdzeniem, informacją czy odmową.
 *
 * Ton wybiera MIEJSCE WYWOŁANIA, jawnie. Nie da się go policzyć z atrybutu `role` ani z tego,
 * w której metodzie notka powstaje: przed tą zmianą wygasły link potwierdzający jechał
 * z `role="status"`, czyli po ubraniu w kolory świeciłby na zielono.
 */
enum NoticeTone {

	case Success;
	case Info;
	case Danger;

	/**
	 * Rola ARIA. Sukces ogłoszony jako alert przerywa czytnikowi ekranu zdanie bez powodu.
	 */
	public function role(): string {
		return self::Danger === $this ? 'alert' : 'status';
	}

	/**
	 * Nazwa roli w mapie klas.
	 */
	public function classRole(): string {
		return match ( $this ) {
			self::Success => 'notice_success',
			self::Info    => 'notice_info',
			self::Danger  => 'notice_danger',
		};
	}
}
