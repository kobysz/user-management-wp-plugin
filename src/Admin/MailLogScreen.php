<?php
/**
 * Ekran logu wysyłek maili: lista kolejki, szczegóły tylko do czytania i wznowienie nieudanego wpisu.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\QueueStatus;
use PortalUsers\Persistence\MailQueueRepository;

/**
 * Widok nad tabelą kolejki, która jest zarazem logiem wysyłek: odpowiada na pytanie „czy ten mail wyszedł".
 *
 * Szczegóły są celowo bez formularza: log jest materiałem dowodowym, nie edytowalnym rekordem. Jedyna
 * zmiana to „Wznów" dla wiersza `failed`; ekran tylko resetuje wiersz, a wysyła go cron w następnym
 * przebiegu (nie wołamy dispatchera z żądania panelu).
 */
final class MailLogScreen {

	public const ACTION_REQUEUE    = 'pusers_requeue_mail';
	public const PER_PAGE          = 20;
	public const NOTICE_REQUEUED   = 'requeued';
	public const NOTICE_NOT_FAILED = 'not_failed';

	private const ARG_STATUS = 'status';
	private const ARG_PAGE   = 'paged';
	private const ARG_ACTION = 'action';
	private const ARG_ID     = 'id';
	private const VIEW       = 'view';

	/**
	 * Pole formularza „Wznów" z identyfikatorem wiersza.
	 */
	private const FIELD_ID = 'pusers_mail_id';

	/**
	 * Długość powodu błędu w liście; pełny tekst jest w szczegółach.
	 */
	private const ERROR_EXCERPT = 80;

	/**
	 * Podpina podstronę oraz obsługę wznowienia.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_REQUEUE, array( self::class, 'handle_requeue' ) );
	}

	/**
	 * Dodaje podstronę logu pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Portal: log wysyłek', 'portal-users' ),
			__( 'Portal: log wysyłek', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_MAIL_LOG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Wznawia nieudany wpis: uprawnienia, nonce, metoda, reset wiersza, przekierowanie. Wpis w innym
	 * stanie zostaje nietknięty (UPDATE w repozytorium jest warunkowy), a odpowiedzią jest powiadomienie:
	 * wznowiony po cichu wiersz `sent` wysłałby lekarzowi ten sam mail drugi raz.
	 */
	public static function handle_requeue(): void {
		Request::guard( self::ACTION_REQUEUE );

		// Zmiana stanu, więc GET (admin_post_* odpala się dla każdej metody) odpada.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		$id = isset( $_POST[ self::FIELD_ID ] ) && is_string( $_POST[ self::FIELD_ID ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() na początku handle_requeue().
			? absint( wp_unslash( $_POST[ self::FIELD_ID ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- j.w.
			: 0;

		Request::redirect(
			Menu::SLUG_MAIL_LOG,
			$id > 0 && MailQueueRepository::requeue_failed( $id ) ? self::NOTICE_REQUEUED : self::NOTICE_NOT_FAILED
		);
	}

	/**
	 * Wypisuje ekran: szczegóły wpisu albo listę.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: log wysyłek', 'portal-users' ) . '</h1>';

		if ( self::VIEW === self::query_arg( self::ARG_ACTION ) ) {
			self::render_detail( absint( self::query_arg( self::ARG_ID ) ) );
		} else {
			self::render_notice();
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Powiadomienie po wznowieniu.
	 */
	private static function render_notice(): void {
		$notice = Request::notice();
		if ( self::NOTICE_REQUEUED === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Wpis wrócił do kolejki; mail zostanie wysłany w najbliższym przebiegu crona.', 'portal-users' ) . '</p></div>';
		} elseif ( self::NOTICE_NOT_FAILED === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Wznowić można tylko wpis nieudany. Ten wpis ma inny stan albo nie istnieje, więc nic nie zmieniono.', 'portal-users' ) . '</p></div>';
		}
	}

	/**
	 * Zakładki ze stanami i licznikami oraz tabela z paginacją.
	 */
	private static function render_list(): void {
		$status = self::current_status();
		$total  = MailQueueRepository::count_by_status( $status );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $pages, max( 1, absint( self::query_arg( self::ARG_PAGE ) ) ) );
		$rows   = MailQueueRepository::paginate( $status, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		self::render_tabs( $status );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Utworzono', 'portal-users' ),
			__( 'Rodzaj', 'portal-users' ),
			__( 'Odbiorca', 'portal-users' ),
			__( 'Temat', 'portal-users' ),
			__( 'Stan', 'portal-users' ),
			__( 'Próby', 'portal-users' ),
			__( 'Powód ostatniej porażki', 'portal-users' ),
			__( 'Akcje', 'portal-users' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Brak wpisów.', 'portal-users' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			self::render_row( $row );
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Powód zostaje przy wpisie po wznowieniu i po udanej ponownej próbie jako ślad: przy wpisie, który nie jest nieudany, opisuje wcześniejszą próbę, nie obecny stan.', 'portal-users' ) . '</p>';

		if ( $pages > 1 ) {
			// paginate_links() zwraca już oczyszczony znacznik.
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( self::ARG_PAGE, '%#%', self::list_url( $status ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	/**
	 * Zakładki: wszystkie oraz po jednym stanie, każda z licznikiem na końcu (po dwukropku).
	 *
	 * @param string $current Wybrany stan albo ''.
	 */
	private static function render_tabs( string $current ): void {
		$tabs = array( '' => __( 'Wszystkie', 'portal-users' ) );
		foreach ( QueueStatus::cases() as $status ) {
			$tabs[ $status->value ] = self::status_label( $status );
		}

		echo '<ul class="subsubsub">';
		$first = true;
		foreach ( $tabs as $value => $label ) {
			echo $first ? '<li>' : '<li> | ';
			$first = false;
			printf(
				'<a href="%1$s"%2$s>%3$s</a></li>',
				esc_url( self::list_url( (string) $value ) ),
				(string) $value === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label . ': ' . MailQueueRepository::count_by_status( (string) $value ) )
			);
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * Wiersz listy.
	 *
	 * @param array<string, mixed> $row Wiersz kolejki.
	 */
	private static function render_row( array $row ): void {
		$id     = (int) ( is_numeric( $row['id'] ?? null ) ? $row['id'] : 0 );
		$status = QueueStatus::tryFrom( self::text( $row, 'status' ) );

		echo '<tr class="pusers-mail-row">';
		echo '<td>' . esc_html( self::local_time( self::text( $row, 'created_at' ) ) ) . '</td>';
		echo '<td><code>' . esc_html( self::text( $row, 'kind' ) ) . '</code></td>';
		echo '<td>' . esc_html( self::text( $row, 'recipient' ) ) . '</td>';
		echo '<td><a href="' . esc_url(
			Menu::url(
				Menu::SLUG_MAIL_LOG,
				array(
					self::ARG_ACTION => self::VIEW,
					self::ARG_ID     => $id,
				)
			)
		) . '">' . esc_html( self::text( $row, 'subject' ) ) . '</a></td>';
		echo '<td>' . esc_html( null === $status ? self::text( $row, 'status' ) : self::status_label( $status ) ) . '</td>';
		echo '<td>' . esc_html( self::text( $row, 'attempts' ) ) . '</td>';
		echo '<td>' . esc_html( self::error_text( $row, true ) ) . '</td>';
		echo '<td>';
		if ( QueueStatus::Failed === $status ) {
			self::render_requeue_form( $id );
		}
		echo '</td></tr>';
	}

	/**
	 * Formularz „Wznów" jednego wiersza. Nonce bez atrybutu `id`, bo wp_nonce_field() powtórzyłoby go w każdym wierszu.
	 *
	 * @param int $id Identyfikator wiersza.
	 */
	private static function render_requeue_form( int $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REQUEUE ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( Request::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( self::ACTION_REQUEUE ) ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_ID ) . '" value="' . esc_attr( (string) $id ) . '" />';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Wznów', 'portal-users' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Szczegóły wpisu, tylko do czytania: bez formularza, temat i treść w <pre> przez esc_html().
	 *
	 * @param int $id Identyfikator wiersza.
	 */
	private static function render_detail( int $id ): void {
		$row = $id > 0 ? MailQueueRepository::find( $id ) : null;

		if ( null === $row ) {
			echo '<p>' . esc_html__( 'Nie znaleziono wpisu.', 'portal-users' ) . '</p>';
			self::render_back_link();
			return;
		}

		$status = QueueStatus::tryFrom( self::text( $row, 'status' ) );
		$meta   = array(
			__( 'Utworzono', 'portal-users' ) => self::local_time( self::text( $row, 'created_at' ) ),
			__( 'Rodzaj', 'portal-users' )    => self::text( $row, 'kind' ),
			__( 'Odbiorca', 'portal-users' )  => self::text( $row, 'recipient' ),
			__( 'Stan', 'portal-users' )      => null === $status ? self::text( $row, 'status' ) : self::status_label( $status ),
			__( 'Próby', 'portal-users' )     => self::text( $row, 'attempts' ),
			__( 'Wysłano', 'portal-users' )   => '' === self::text( $row, 'sent_at' ) ? '—' : self::local_time( self::text( $row, 'sent_at' ) ),
			__( 'Powód ostatniej porażki', 'portal-users' ) => self::error_text( $row, false ),
		);

		self::render_back_link();
		echo '<table class="widefat striped"><tbody>';
		foreach ( $meta as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' === $value ? '—' : $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Temat', 'portal-users' ) . '</h2><pre>' . esc_html( self::text( $row, 'subject' ) ) . '</pre>';
		echo '<h2>' . esc_html__( 'Treść', 'portal-users' ) . '</h2><pre>' . esc_html( self::text( $row, 'body' ) ) . '</pre>';
	}

	/**
	 * Link powrotu do listy.
	 */
	private static function render_back_link(): void {
		echo '<p><a href="' . esc_url( Menu::url( Menu::SLUG_MAIL_LOG ) ) . '">' . esc_html__( '← Wróć do listy', 'portal-users' ) . '</a></p>';
	}

	/**
	 * Powód ostatniej porażki z oznaczeniem, czy to stan obecny. Wznowienie zostawia `last_error` jako ślad,
	 * więc wiersz `queued` albo `sent` może go mieć: bez przedrostka wyglądałby na psujący się teraz.
	 *
	 * @param array<string, mixed> $row     Wiersz kolejki.
	 * @param bool                 $shorten Czy skrócić tekst do listy.
	 */
	private static function error_text( array $row, bool $shorten ): string {
		$error = self::text( $row, 'last_error' );
		if ( '' === $error ) {
			return '';
		}
		if ( $shorten && mb_strlen( $error ) > self::ERROR_EXCERPT ) {
			$error = mb_substr( $error, 0, self::ERROR_EXCERPT ) . '…';
		}

		return QueueStatus::Failed->value === self::text( $row, 'status' )
			? $error
			: __( 'Wcześniejsza próba:', 'portal-users' ) . ' ' . $error;
	}

	/**
	 * Nazwa stanu w panelu.
	 *
	 * @param QueueStatus $status Stan wpisu.
	 */
	private static function status_label( QueueStatus $status ): string {
		return match ( $status ) {
			QueueStatus::Queued  => __( 'Oczekujące', 'portal-users' ),
			QueueStatus::Sending => __( 'Wysyłane', 'portal-users' ),
			QueueStatus::Sent    => __( 'Wysłane', 'portal-users' ),
			QueueStatus::Failed  => __( 'Nieudane', 'portal-users' ),
		};
	}

	/**
	 * Czas UTC z bazy w strefie witryny.
	 *
	 * @param string $utc Czas w UTC (`Y-m-d H:i:s`).
	 */
	private static function local_time( string $utc ): string {
		return '' === $utc ? '' : get_date_from_gmt( $utc, 'Y-m-d H:i' );
	}

	/**
	 * Wybrany stan z adresu; nieznana wartość znaczy „wszystkie".
	 */
	private static function current_status(): string {
		$status = self::query_arg( self::ARG_STATUS );
		return null === QueueStatus::tryFrom( $status ) ? '' : $status;
	}

	/**
	 * Adres listy dla stanu.
	 *
	 * @param string $status Stan albo ''.
	 */
	private static function list_url( string $status ): string {
		return Menu::url( Menu::SLUG_MAIL_LOG, '' === $status ? array() : array( self::ARG_STATUS => $status ) );
	}

	/**
	 * Argument zapytania jako tekst; pusty, gdy go nie ma. Odczyt nic nie zmienia, więc nonce nie obowiązuje.
	 *
	 * @param string $name Nazwa argumentu.
	 */
	private static function query_arg( string $name ): string {
		if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Odczyt filtra widoku, nic nie zmienia.
			return '';
		}
		return sanitize_text_field( wp_unslash( $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- j.w.
	}

	/**
	 * Wartość kolumny jako tekst.
	 *
	 * @param array<string, mixed> $row Wiersz kolejki.
	 * @param string               $key Kolumna.
	 */
	private static function text( array $row, string $key ): string {
		$value = $row[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}
}
