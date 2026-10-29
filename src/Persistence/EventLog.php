<?php
/**
 * Log zdarzeń wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Zapis do tabeli `event_log`. Nieudany zapis logu nie może zatrzymać obsługiwanego żądania,
 * więc metoda nie rzuca; porażkę zgłasza wartością zwracaną, żeby wywołujący, któremu log jest
 * dowodem (rozstrzygnięcie wniosku), mógł ją zobaczyć. Pozostali mogą wynik zignorować.
 */
final class EventLog {

	private const ACTION_MAX_LENGTH = 40;

	/**
	 * Dopisuje zdarzenie.
	 *
	 * @param string               $action        Nazwa zdarzenia (do 40 znaków; dłuższa jest obcinana).
	 * @param int                  $subjectUserId Użytkownik, którego zdarzenie dotyczy (0 = brak).
	 * @param int                  $actorUserId   Użytkownik wykonujący (0 = brak, np. anonimowy odwiedzający).
	 * @param array<string, mixed> $context       Dane dodatkowe, zapisywane jako JSON.
	 * @return bool True, gdy wiersz powstał.
	 */
	public static function write( string $action, int $subjectUserId, int $actorUserId, array $context ): bool {
		global $wpdb;

		$encoded = wp_json_encode( $context );

		$result = $wpdb->insert(
			Migrations::table( 'event_log' ),
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'actor_user_id'   => max( 0, $actorUserId ),
				'subject_user_id' => max( 0, $subjectUserId ),
				'action'          => substr( $action, 0, self::ACTION_MAX_LENGTH ),
				'context'         => false === $encoded ? '{}' : $encoded,
				'anonymized'      => 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d' )
		);

		return false !== $result;
	}
}
