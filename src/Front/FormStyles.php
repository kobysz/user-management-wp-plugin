<?php
/**
 * Arkusze stylów formularzy frontu i ich ładowanie.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Front;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Plugin;
use WP_Post;

/**
 * Dwa arkusze i dwie warstwy ładowania.
 *
 * Arkusz STAŁY jedzie zawsze, gdy widok wtyczki się pojawił: niesie to, czego w Bootstrapie nie
 * ma (miękkie ostrzeżenie, odstęp etykiety ACF-a). Arkusz ZAPASOWY tylko przy włączonym
 * ustawieniu — na skórce bootstrapowej byłby drugą kopią wyglądu walczącą z pierwszą.
 *
 * Warstwa pierwsza (`template_redirect` + `has_shortcode`) wpuszcza styl do `<head>`, zanim
 * cokolwiek się wyrenderuje. Warstwa druga (`enqueue()` z renderu) pokrywa formularz wstawiony
 * widżetem, blokiem albo z szablonu, gdzie pierwsza nie ma czego sprawdzić — tam styl trafi do
 * stopki, czyli widok mignie nieubrany, ale ubrany będzie. `wp_enqueue_style()` jest
 * idempotentne, więc obie warstwy razem dają jeden arkusz.
 */
final class FormStyles {

	public const HANDLE          = 'pusers-front';
	public const HANDLE_FALLBACK = 'pusers-front-fallback';

	/**
	 * Podpina pierwszą warstwę.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
	}

	/**
	 * Strona, której treść niesie któryś z shortcode'ów wtyczki, dostaje arkusze w `<head>`.
	 */
	public static function on_template_redirect(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		foreach ( array( RegistrationForm::SHORTCODE, LoginForm::SHORTCODE, PasswordReset::SHORTCODE ) as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
				return;
			}
		}
	}

	/**
	 * Dokłada arkusze. Idempotentne, więc wolno je wołać z każdego widoku.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, self::url( 'pusers-front.css' ), array(), Plugin::version() );

		if ( SettingsRepository::get()->formStylesEnabled ) {
			wp_enqueue_style( self::HANDLE_FALLBACK, self::url( 'pusers-front-zapasowy.css' ), array( self::HANDLE ), Plugin::version() );
		}
	}

	/**
	 * Adres pliku w paczce wtyczki.
	 *
	 * @param string $file Nazwa pliku w `assets/css/`.
	 */
	private static function url( string $file ): string {
		return plugins_url( 'assets/css/' . $file, Plugin::plugin_file() );
	}
}
