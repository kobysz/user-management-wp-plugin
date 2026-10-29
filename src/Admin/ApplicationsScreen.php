<?php
/**
 * Ekran wniosków: zakładki ze stanami i licznikami, filtr profesji i tabela kont do rozstrzygnięcia.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Account\Decision;
use PortalUsers\Domain\Account\AccountStatus;
use PortalUsers\Domain\Application\ApplicationRow;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Domain\Application\RegistryLink;
use PortalUsers\Persistence\ApplicationRepository;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;

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

	public const ACTION_DECIDE       = 'pusers_decide_applications';
	public const FIELD_USERS         = 'pusers_users';
	public const FIELD_DECISION      = 'pusers_decision';
	public const FIELD_REASON        = 'pusers_reason';
	public const FIELD_REASON_PRESET = 'pusers_reason_preset';
	public const DECIDE_APPROVE      = 'approve';
	public const DECIDE_REJECT       = 'reject';

	public const NOTICE_DECIDED          = 'decided';
	public const NOTICE_NEEDS_REASON     = 'needs_reason';
	public const NOTICE_NOTHING_SELECTED = 'nothing_selected';

	// Liczniki wyniku partii jadą w adresie przekierowania, bo po PRG to jedyne, co ekran ma o wyniku.
	private const ARG_DECISION       = 'pusers_decision_done';
	private const ARG_DONE           = 'pusers_done';
	private const ARG_MAIL_FAILED    = 'pusers_mail_failed';
	private const ARG_LOG_FAILED     = 'pusers_log_failed';
	private const ARG_UNCHANGED      = 'pusers_unchanged';
	private const ARG_REASON_UPDATED = 'pusers_reason_updated';
	private const ARG_SKIPPED        = 'pusers_skipped';

	/**
	 * Podpina podstronę i obsługę rozstrzygnięcia. Rejestracja jest wołana z `Plugin::boot()` poza bramką zależności.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_DECIDE, array( self::class, 'handle_decide' ) );
	}

	/**
	 * Rozstrzyga zaznaczone wnioski: uprawnienia i nonce, metoda, dane z żądania, wywołanie serwisu, przekierowanie.
	 *
	 * Odrzucenie bez powodu odbijane jest tu, zanim jakiekolwiek konto zostanie ruszone: partia nie może się
	 * rozstrzygnąć do połowy. Reszty (stan, mail, log) pilnuje `Decision`; ekran tylko zlicza kody wyniku.
	 */
	public static function handle_decide(): void {
		Request::guard( self::ACTION_DECIDE );

		// Zmiana stanu, więc GET (admin_post_* odpala się dla każdej metody) odpada.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}

		$back     = self::return_args();
		$decision = self::post_text( self::FIELD_DECISION );
		$ids      = self::post_ids();

		if ( array() === $ids || ! in_array( $decision, array( self::DECIDE_APPROVE, self::DECIDE_REJECT ), true ) ) {
			Request::redirect( Menu::SLUG_APPLICATIONS, self::NOTICE_NOTHING_SELECTED, $back );
			return;
		}

		$reason = '';
		if ( self::DECIDE_REJECT === $decision ) {
			$reason = self::posted_reason();
			if ( '' === $reason ) {
				Request::redirect( Menu::SLUG_APPLICATIONS, self::NOTICE_NEEDS_REASON, $back );
				return;
			}
		}

		$actor  = get_current_user_id();
		$counts = array(
			self::ARG_DONE           => 0,
			self::ARG_MAIL_FAILED    => 0,
			self::ARG_LOG_FAILED     => 0,
			self::ARG_UNCHANGED      => 0,
			self::ARG_REASON_UPDATED => 0,
			self::ARG_SKIPPED        => 0,
		);
		foreach ( $ids as $id ) {
			$result = self::DECIDE_APPROVE === $decision ? Decision::approve( $id, $actor ) : Decision::reject( $id, $actor, $reason );
			switch ( $result ) {
				case Decision::RESULT_OK:
					++$counts[ self::ARG_DONE ];
					break;
				case Decision::RESULT_MAIL_FAILED:
					// Konto jest rozstrzygnięte, ale lekarz nie dostał maila: liczy się do obu, bo redakcja musi to wiedzieć.
					++$counts[ self::ARG_DONE ];
					++$counts[ self::ARG_MAIL_FAILED ];
					break;
				case Decision::RESULT_LOG_FAILED:
					// Stan konta zmieniony i mail poszedł, ale w logu nie ma śladu: liczy się do obu, bo audyt ma lukę.
					++$counts[ self::ARG_DONE ];
					++$counts[ self::ARG_LOG_FAILED ];
					break;
				case Decision::RESULT_UNCHANGED:
					++$counts[ self::ARG_UNCHANGED ];
					break;
				case Decision::RESULT_REASON_UPDATED:
					// Sukces, nie pominięcie: redaktor poprawił powód, a lekarz dostał mail z nowym.
					++$counts[ self::ARG_REASON_UPDATED ];
					break;
				default:
					++$counts[ self::ARG_SKIPPED ];
			}
		}

		Request::redirect(
			Menu::SLUG_APPLICATIONS,
			self::NOTICE_DECIDED,
			array_merge( $back, $counts, array( self::ARG_DECISION => $decision ) )
		);
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

		self::render_notice();
		self::render_tabs( $status, $profession );
		self::render_filter( $status, $profession );
		self::render_decision_form( $rows, $status, $profession );

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
	 * Formularz rozstrzygnięcia: pole powodu nad tabelą, w tabeli pola wyboru. POST do admin-post, nigdy do tego ekranu.
	 *
	 * @param array<int, ApplicationRow> $rows       Wiersze strony.
	 * @param AccountStatus              $status     Wybrany stan.
	 * @param string                     $profession Wybrana profesja albo ''.
	 */
	private static function render_decision_form( array $rows, AccountStatus $status, string $profession ): void {
		if ( array() === $rows ) {
			self::render_table( $rows, false );
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pusers-decision-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DECIDE ) . '" />';
		// Zakładka i filtr wracają w POST-cie, żeby redaktor po akcji wylądował tam, skąd kliknął.
		echo '<input type="hidden" name="' . esc_attr( self::ARG_STATUS ) . '" value="' . esc_attr( $status->value ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( self::ARG_PROFESSION ) . '" value="' . esc_attr( $profession ) . '" />';
		wp_nonce_field( self::ACTION_DECIDE, Request::NONCE_FIELD );

		self::render_reason_field();
		echo '<p><label for="pusers-decision">' . esc_html__( 'Zaznaczone wnioski:', 'portal-users' ) . '</label> ';
		echo '<select id="pusers-decision" name="' . esc_attr( self::FIELD_DECISION ) . '">';
		echo '<option value="' . esc_attr( self::DECIDE_APPROVE ) . '">' . esc_html__( 'Zatwierdź', 'portal-users' ) . '</option>';
		echo '<option value="' . esc_attr( self::DECIDE_REJECT ) . '">' . esc_html__( 'Odrzuć (wymaga powodu)', 'portal-users' ) . '</option>';
		echo '</select> <button type="submit" class="button button-primary">' . esc_html__( 'Zastosuj', 'portal-users' ) . '</button></p>';

		self::render_table( $rows, true );
		echo '</form>';
	}

	/**
	 * Pole powodu odrzucenia: lista gotowych powodów z ustawień wypełnia pole tekstowe, które można nadpisać.
	 * Używane przez listę i przez panel szczegółów, żeby oba miejsca zachowywały się tak samo.
	 */
	public static function render_reason_field(): void {
		$presets = SettingsRepository::get()->rejectionReasons;

		echo '<p class="pusers-reason"><label for="pusers-reason">' . esc_html__( 'Powód odrzucenia (używany tylko przy odrzuceniu)', 'portal-users' ) . '</label><br />';
		if ( array() !== $presets ) {
			echo '<select id="pusers-reason-preset" name="' . esc_attr( self::FIELD_REASON_PRESET ) . '">';
			echo '<option value="">' . esc_html__( '— gotowy powód —', 'portal-users' ) . '</option>';
			foreach ( $presets as $preset ) {
				echo '<option value="' . esc_attr( $preset ) . '">' . esc_html( $preset ) . '</option>';
			}
			echo '</select><br />';
		}
		echo '<textarea id="pusers-reason" name="' . esc_attr( self::FIELD_REASON ) . '" rows="3" cols="60" maxlength="' . esc_attr( (string) Decision::REASON_MAX ) . '"></textarea></p>';
		// Wybór z listy tylko wypełnia pole; serwer czyta pole tekstowe, a gotowy powód jest jego zapasem.
		echo '<script>( function () { var p = document.getElementById( "pusers-reason-preset" ), t = document.getElementById( "pusers-reason" ); if ( p && t ) { p.addEventListener( "change", function () { if ( p.value ) { t.value = p.value; } } ); } }() );</script>';
	}

	/**
	 * Ukryte pola z zakładką i filtrem z adresu bieżącego ekranu, żeby po akcji redaktor wrócił tam, skąd kliknął.
	 * Pola, których adres nie niósł, nie są wypisywane: nikt nie wskazał zakładki, więc nie wymyślamy jej.
	 */
	public static function render_return_fields(): void {
		$status = AccountStatus::tryFrom( sanitize_key( self::query_arg( self::ARG_STATUS ) ) );
		if ( null !== $status && in_array( $status, self::tabs(), true ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::ARG_STATUS ) . '" value="' . esc_attr( $status->value ) . '" />';
		}
		$profession = self::current_profession();
		if ( '' !== $profession ) {
			echo '<input type="hidden" name="' . esc_attr( self::ARG_PROFESSION ) . '" value="' . esc_attr( $profession ) . '" />';
		}
	}

	/**
	 * Komunikat po rozstrzygnięciu; niesie liczby, bo "gotowe" przy pominiętych kontach byłoby nieprawdą.
	 */
	private static function render_notice(): void {
		$notice = Request::notice();

		if ( self::NOTICE_NOTHING_SELECTED === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nie zaznaczono żadnego wniosku albo decyzji. Nic nie zmieniono.', 'portal-users' ) . '</p></div>';
		} elseif ( self::NOTICE_NEEDS_REASON === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Odrzucenie wymaga powodu. Żaden wniosek nie został zmieniony.', 'portal-users' ) . '</p></div>';
		} elseif ( self::NOTICE_DECIDED === $notice ) {
			$done        = absint( self::query_arg( self::ARG_DONE ) );
			$mail_failed = absint( self::query_arg( self::ARG_MAIL_FAILED ) );
			$log_failed  = absint( self::query_arg( self::ARG_LOG_FAILED ) );
			$unchanged   = absint( self::query_arg( self::ARG_UNCHANGED ) );
			$updated     = absint( self::query_arg( self::ARG_REASON_UPDATED ) );
			$skipped     = absint( self::query_arg( self::ARG_SKIPPED ) );

			$lines = array();
			if ( $done > 0 ) {
				$lines[] = self::DECIDE_REJECT === self::query_arg( self::ARG_DECISION )
					/* translators: %s: liczba wniosków. */
					? sprintf( __( 'Odrzucono wniosków: %s.', 'portal-users' ), number_format_i18n( $done ) )
					/* translators: %s: liczba wniosków. */
					: sprintf( __( 'Zatwierdzono wniosków: %s.', 'portal-users' ), number_format_i18n( $done ) );
			}
			if ( $updated > 0 ) {
				/* translators: %s: liczba wniosków. */
				$lines[] = sprintf( __( 'Poprawiono powód odrzucenia: %s.', 'portal-users' ), number_format_i18n( $updated ) );
			}
			if ( $mail_failed > 0 ) {
				/* translators: %s: liczba wniosków. */
				$lines[] = sprintf( __( 'Mail do wnioskodawcy nie wyszedł (wnioski: %s). Konta są rozstrzygnięte, ale trzeba powiadomić ich inaczej.', 'portal-users' ), number_format_i18n( $mail_failed ) );
			}
			if ( $log_failed > 0 ) {
				/* translators: %s: liczba wniosków. */
				$lines[] = sprintf( __( 'Rozstrzygnięcia weszły, ale nie zostały odnotowane w logu (wnioski: %s). Konta są rozstrzygnięte, lecz w audycie brakuje po nich śladu.', 'portal-users' ), number_format_i18n( $log_failed ) );
			}
			if ( $unchanged > 0 ) {
				$lines[] = self::DECIDE_REJECT === self::query_arg( self::ARG_DECISION )
					/* translators: %s: liczba wniosków. */
					? sprintf( __( 'Konta już były odrzucone (wnioski: %s), a powód jest identyczny z zapisanym; nic nie zmieniono.', 'portal-users' ), number_format_i18n( $unchanged ) )
					/* translators: %s: liczba wniosków. */
					: sprintf( __( 'Konta były już zatwierdzone (wnioski: %s), nic nie zmieniono.', 'portal-users' ), number_format_i18n( $unchanged ) );
			}
			if ( $skipped > 0 ) {
				/* translators: %s: liczba wniosków. */
				$lines[] = sprintf( __( 'Pominięto wniosków: %s (konto usunięte, w niedozwolonym stanie albo błąd zapisu).', 'portal-users' ), number_format_i18n( $skipped ) );
			}
			if ( array() === $lines ) {
				$lines[] = __( 'Nic nie zmieniono.', 'portal-users' );
			}

			$class = $mail_failed > 0 || $log_failed > 0 || $unchanged > 0 || $skipped > 0 || ( 0 === $done && 0 === $updated ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
			foreach ( $lines as $line ) {
				echo '<p>' . esc_html( $line ) . '</p>';
			}
			echo '</div>';
		}
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
	 * @param array<int, ApplicationRow> $rows       Wiersze strony.
	 * @param bool                       $selectable Czy tabela stoi w formularzu rozstrzygnięcia i ma pola wyboru.
	 */
	private static function render_table( array $rows, bool $selectable ): void {
		$span = $selectable ? 6 : 5;
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		if ( $selectable ) {
			echo '<td class="check-column"><input type="checkbox" id="pusers-select-all" onclick="document.querySelectorAll( \'.pusers-select\' ).forEach( function ( c ) { c.checked = this.checked; }, this );" />'
				. '<label class="screen-reader-text" for="pusers-select-all">' . esc_html__( 'Zaznacz wszystkie', 'portal-users' ) . '</label></td>';
		}
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
			echo '<tr><td colspan="' . esc_attr( (string) $span ) . '">' . esc_html__( 'Brak wniosków.', 'portal-users' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			self::render_row( $row, $selectable );
		}
		echo '</tbody></table>';
	}

	/**
	 * Wiersz tabeli.
	 *
	 * @param ApplicationRow $row        Wniosek.
	 * @param bool           $selectable Czy wiersz ma pole wyboru.
	 */
	private static function render_row( ApplicationRow $row, bool $selectable ): void {
		// Profesja wycofana z ustawień zostaje w koncie: wiersz się renderuje, a jedyną etykietą jest klucz.
		$profession = '' === $row->professionKey ? null : ProfessionRepository::get()->get( $row->professionKey );

		echo '<tr class="pusers-application-row">';
		if ( $selectable ) {
			printf(
				'<th scope="row" class="check-column"><input type="checkbox" class="pusers-select" name="%1$s[]" value="%2$d" aria-label="%3$s" /></th>',
				esc_attr( self::FIELD_USERS ),
				absint( $row->userId ),
				esc_attr( $row->displayName )
			);
		}
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
	 * Identyfikatory z żądania: tylko napisy, przez absint, bez zer i powtórek. Wejście spoza tablicy daje pustą listę.
	 *
	 * @return array<int, int>
	 */
	private static function post_ids(): array {
		if ( ! isset( $_POST[ self::FIELD_USERS ] ) || ! is_array( $_POST[ self::FIELD_USERS ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() na początku handle_decide().
			return array();
		}
		// Tylko napisy z samych cyfr: element-tablica dałby absint() == 1, a '-5' dałoby 5, czyli cudze konto.
		$raw = array_filter( wp_unslash( $_POST[ self::FIELD_USERS ] ), static fn ( $v ): bool => is_string( $v ) && ctype_digit( $v ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- j.w.; absint niżej.
		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}

	/**
	 * Pole POST jako tekst; pusty, gdy go nie ma albo nie jest napisem.
	 *
	 * @param string $name Nazwa pola.
	 */
	private static function post_text( string $name ): string {
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() na początku handle_decide().
			return '';
		}
		return sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- j.w.
	}

	/**
	 * Powód z żądania: pole tekstowe, a gdy puste, gotowy powód z ustawień. Wartość spoza listy ustawień jest ignorowana,
	 * żeby preset nie był drugą, niesprawdzaną drogą na dowolny tekst.
	 */
	private static function posted_reason(): string {
		$text = trim( self::post_text( self::FIELD_REASON ) );
		if ( '' !== $text ) {
			return $text;
		}
		$preset = trim( self::post_text( self::FIELD_REASON_PRESET ) );
		return in_array( $preset, SettingsRepository::get()->rejectionReasons, true ) ? $preset : '';
	}

	/**
	 * Zakładka i filtr z żądania zapisu, w kształcie argumentów przekierowania.
	 *
	 * @return array<string, string>
	 */
	private static function return_args(): array {
		$args   = array();
		$status = AccountStatus::tryFrom( sanitize_key( self::post_text( self::ARG_STATUS ) ) );
		if ( null !== $status && in_array( $status, self::tabs(), true ) ) {
			$args[ self::ARG_STATUS ] = $status->value;
		}
		$profession = sanitize_key( self::post_text( self::ARG_PROFESSION ) );
		if ( '' !== $profession ) {
			$args[ self::ARG_PROFESSION ] = $profession;
		}
		return $args;
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
