<?php
/**
 * Ekran wniosków: zakładki ze stanami i licznikami, filtr profesji i tabela kont do rozstrzygnięcia.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Application\ApplicationRow;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Application\RegistryLink;
use PortalUsers\Persistence\ApplicationRepository;
use PortalUsers\Persistence\ProfessionRepository;

/**
 * Widok nad kontami z wtyczkowym stanem. Zakładek jest trzy, choć repozytorium liczy cztery stany:
 * `email_unconfirmed` nie jest wnioskiem (redakcja nie dostała o nim maila, nie da się go rozstrzygnąć,
 * a cron po siedmiu dniach go usuwa), więc to ekran decyduje, że go nie pokazuje.
 */
final class ApplicationsScreen {

	public const PER_PAGE       = 20;
	public const ARG_STATUS     = 'status';
	public const ARG_PROFESSION = 'profession';
	public const ARG_PAGE       = 'paged';
	public const ARG_USER       = 'user';

	/**
	 * Podpina podstronę. Rejestracja jest wołana z `Plugin::boot()` poza bramką zależności.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
	}

	/**
	 * Dodaje podstronę pod menu Użytkownicy; tytuł niesie bąbelek z liczbą oczekujących, jak licznik komentarzy rdzenia.
	 */
	public static function add_page(): void {
		$title   = __( 'Portal: wnioski', 'portal-users' );
		$pending = ApplicationRepository::counts()[ AccountStatus::Pending->value ] ?? 0;

		add_submenu_page(
			Menu::PARENT,
			$title,
			$pending > 0 ? $title . ' <span class="awaiting-mod">' . number_format_i18n( $pending ) . '</span>' : $title,
			Menu::CAPABILITY,
			Menu::SLUG_APPLICATIONS,
			array( self::class, 'render' )
		);
	}

	/**
	 * Adres szczegółów wniosku; z niego korzystają mail do redakcji i przyciski rozstrzygnięcia.
	 *
	 * @param int $userId Identyfikator konta.
	 */
	public static function detail_url( int $userId ): string {
		return Menu::url( Menu::SLUG_APPLICATIONS, array( self::ARG_USER => $userId ) );
	}

	/**
	 * Wypisuje ekran.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		// Obecność argumentu użytkownika przełącza na szczegóły, także gdy wniosku nie ma: pusta strona nie powie, co poszło nie tak.
		if ( '' !== self::query_arg( self::ARG_USER ) ) {
			$row = ApplicationRepository::one( absint( self::query_arg( self::ARG_USER ) ) );
			if ( null === $row ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Portal: wnioski', 'portal-users' ) . '</h1><p>' . esc_html__( 'Nie ma takiego wniosku.', 'portal-users' ) . '</p>';
				echo '<p><a href="' . esc_url( Menu::url( Menu::SLUG_APPLICATIONS ) ) . '">' . esc_html__( '← Wróć do listy wniosków', 'portal-users' ) . '</a></p></div>';
				return;
			}
			ApplicationDetails::render( $row );
			return;
		}

		$status     = self::current_status();
		$profession = self::current_profession();
		$total      = ApplicationRepository::total( $status, $profession );
		$pages      = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged      = min( $pages, max( 1, absint( self::query_arg( self::ARG_PAGE ) ) ) );
		$rows       = ApplicationRepository::paginate( $status, $profession, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: wnioski', 'portal-users' ) . '</h1>';

		self::render_tabs( $status, $profession );
		self::render_filter( $status, $profession );
		self::render_table( $rows );

		if ( $pages > 1 ) {
			// paginate_links() zwraca już oczyszczony znacznik.
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( self::ARG_PAGE, '%#%', self::list_url( $status, $profession ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Zakładki z licznikami; adres każdej niesie aktualny filtr profesji, inaczej kliknięcie zakładki po cichu go gubi.
	 *
	 * @param AccountStatus $current    Wybrany stan.
	 * @param string        $profession Wybrana profesja albo ''.
	 */
	private static function render_tabs( AccountStatus $current, string $profession ): void {
		$counts = ApplicationRepository::counts();

		echo '<ul class="subsubsub">';
		$first = true;
		foreach ( self::tabs() as $status ) {
			echo $first ? '<li>' : '<li> | ';
			$first = false;
			printf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a></li>',
				esc_url( self::list_url( $status, $profession ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( self::status_label( $status ) ),
				esc_html( number_format_i18n( $counts[ $status->value ] ?? 0 ) )
			);
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * Formularz filtra profesji. GET, bo to odczyt widoku; wybrany stan jedzie w ukrytym polu, żeby filtr go nie zmieniał.
	 *
	 * @param AccountStatus $status     Wybrany stan.
	 * @param string        $profession Wybrana profesja albo ''.
	 */
	private static function render_filter( AccountStatus $status, string $profession ): void {
		echo '<form method="get" action="' . esc_url( admin_url( Menu::PARENT ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::SLUG_APPLICATIONS ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::ARG_STATUS ) . '" value="' . esc_attr( $status->value ) . '" />';
		echo '<label for="pusers-profession-filter">' . esc_html__( 'Profesja', 'portal-users' ) . '</label> ';
		echo '<select id="pusers-profession-filter" name="' . esc_attr( self::ARG_PROFESSION ) . '">';
		echo '<option value="">' . esc_html__( 'Wszystkie', 'portal-users' ) . '</option>';
		foreach ( ProfessionRepository::get()->all() as $item ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $item->key ),
				selected( $profession, $item->key, false ),
				esc_html( $item->label )
			);
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filtruj', 'portal-users' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Tabela wniosków.
	 *
	 * @param array<int, ApplicationRow> $rows Wiersze strony.
	 */
	private static function render_table( array $rows ): void {
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Użytkownik', 'portal-users' ),
			__( 'Profesja', 'portal-users' ),
			__( 'Numer uprawnień', 'portal-users' ),
			// Nazwa mówi, co pokazuje wartość: `user_registered` to data rejestracji, a nie zgłoszenia
			// (konto potwierdzone dwa miesiące po rejestracji ma datę sprzed dwóch miesięcy).
			__( 'Data rejestracji', 'portal-users' ),
			__( 'Akcje', 'portal-users' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Brak wniosków.', 'portal-users' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			self::render_row( $row );
		}
		echo '</tbody></table>';
	}

	/**
	 * Wiersz tabeli.
	 *
	 * @param ApplicationRow $row Wniosek.
	 */
	private static function render_row( ApplicationRow $row ): void {
		// Profesja wycofana z ustawień zostaje w koncie: wiersz się renderuje, a jedyną etykietą jest klucz.
		$profession = '' === $row->professionKey ? null : ProfessionRepository::get()->get( $row->professionKey );

		echo '<tr class="pusers-application-row">';
		echo '<td><strong>' . esc_html( $row->displayName ) . '</strong><br />' . esc_html( $row->email ) . '</td>';
		echo '<td>' . esc_html( null === $profession ? $row->professionKey : $profession->label ) . '</td>';

		$code = CredentialVerdict::for( $profession, $row->credentialNumber );
		echo '<td>';
		// Profesja, która numeru nie ma, zostawia komórkę pustą; profesja usunięta z ustawień pokazuje „nie dotyczy".
		if ( null === $profession || CredentialVerdict::NOT_APPLICABLE !== $code ) {
			if ( '' !== $row->credentialNumber ) {
				echo esc_html( $row->credentialNumber ) . '<br />';
			}
			$class = CredentialVerdict::isWarning( $code ) ? 'pusers-verdict pusers-verdict-warning' : 'pusers-verdict';
			echo '<span class="' . esc_attr( $class ) . '">' . esc_html( VerdictLabels::text( $code ) ) . '</span>';

			$link = null === $profession ? '' : RegistryLink::for( $profession->registryUrl, $row->credentialNumber );
			if ( '' !== $link ) {
				echo ' <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sprawdź w rejestrze', 'portal-users' ) . '</a>';
			}
		}
		echo '</td>';

		echo '<td>' . esc_html( '' === $row->registeredAtUtc ? '' : get_date_from_gmt( $row->registeredAtUtc, 'Y-m-d H:i' ) ) . '</td>';
		echo '<td><a href="' . esc_url( self::detail_url( $row->userId ) ) . '">' . esc_html__( 'Szczegóły', 'portal-users' ) . '</a></td>';
		echo '</tr>';
	}

	/**
	 * Stany, które mają zakładkę.
	 *
	 * @return array<int, AccountStatus>
	 */
	private static function tabs(): array {
		return array( AccountStatus::Pending, AccountStatus::Approved, AccountStatus::Rejected );
	}

	/**
	 * Nazwa stanu w panelu.
	 *
	 * @param AccountStatus $status Stan.
	 */
	private static function status_label( AccountStatus $status ): string {
		return match ( $status ) {
			AccountStatus::Pending          => __( 'Oczekujące', 'portal-users' ),
			AccountStatus::Approved         => __( 'Zatwierdzone', 'portal-users' ),
			AccountStatus::Rejected         => __( 'Odrzucone', 'portal-users' ),
			AccountStatus::EmailUnconfirmed => __( 'Niepotwierdzone', 'portal-users' ),
		};
	}

	/**
	 * Wybrany stan z adresu; brak albo wartość spoza zakładek znaczy „oczekujące".
	 */
	private static function current_status(): AccountStatus {
		$status = AccountStatus::tryFrom( sanitize_key( self::query_arg( self::ARG_STATUS ) ) );
		return null !== $status && in_array( $status, self::tabs(), true ) ? $status : AccountStatus::Pending;
	}

	/**
	 * Wybrana profesja z adresu albo ''.
	 */
	private static function current_profession(): string {
		return sanitize_key( self::query_arg( self::ARG_PROFESSION ) );
	}

	/**
	 * Adres listy dla stanu i filtra.
	 *
	 * @param AccountStatus $status     Stan.
	 * @param string        $profession Profesja albo ''.
	 */
	private static function list_url( AccountStatus $status, string $profession ): string {
		$args = array( self::ARG_STATUS => $status->value );
		if ( '' !== $profession ) {
			$args[ self::ARG_PROFESSION ] = $profession;
		}
		return Menu::url( Menu::SLUG_APPLICATIONS, $args );
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
}
