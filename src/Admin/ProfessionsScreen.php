<?php
/**
 * Ekran profesji: lista, dodawanie, edycja i usuwanie.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Admin;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Domain\Credentials\CredentialType;
use PortalUsers\Domain\Profession\Profession;
use PortalUsers\Domain\Profession\ProfessionCollection;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use WP_User_Query;

/**
 * Ekran profesji renderowany serwerowo. Konta przechowują w metadanej `pusers_profession`
 * klucz profesji (nie etykietę), dlatego klucz jest niezmienialny po utworzeniu.
 */
final class ProfessionsScreen {

	/**
	 * Akcja nonce'a wspólna dla zapisu i usuwania oraz akcja admin-post usuwania.
	 */
	public const ACTION        = 'pusers_save_professions';
	public const ACTION_DELETE = 'pusers_delete_profession';

	/**
	 * Metadana konta z kluczem profesji. Plan 3 (rejestracja) musi użyć dokładnie tego klucza.
	 */
	public const USER_META = 'pusers_profession';

	public const NOTICE_DELETED          = 'profession_deleted';
	public const NOTICE_INVALID_TYPE     = 'profession_invalid_type';
	public const NOTICE_INVALID_KEY      = 'profession_invalid_key';
	public const NOTICE_INVALID_EMAIL    = 'profession_invalid_email';
	public const NOTICE_CONFIRM_ACCOUNTS = 'profession_confirm_accounts';

	private const FIELD_PREFIX = 'pusers_';

	/**
	 * Pola profesji przyjmowane z żądania.
	 */
	private const PROFESSION_FIELDS = array( 'key', 'label', 'credential_type', 'credential_field', 'registry_url', 'notify_email' );

	/**
	 * Podpina podstronę oraz obsługę zapisu i usuwania.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
	}

	/**
	 * Dodaje podstronę profesji pod menu Użytkownicy.
	 */
	public static function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Portal: profesje', 'portal-users' ),
			__( 'Portal: profesje', 'portal-users' ),
			Menu::CAPABILITY,
			Menu::SLUG_PROFESSIONS,
			array( self::class, 'render' )
		);
	}

	/**
	 * Zapisuje profesję (nową albo edytowaną): uprawnienia, nonce, metoda, walidacja, zapis, przekierowanie.
	 */
	public static function handle_save(): void {
		Request::guard( self::ACTION );
		self::require_post();

		$collection = ProfessionRepository::get();
		$posted     = self::posted_profession();
		$original   = self::posted( 'original_key' );
		$original   = null === $original ? '' : sanitize_text_field( $original );

		if ( '' !== $original ) {
			// Edycja: klucz pochodzi z zapisanej profesji, nigdy z żądania.
			$current = $collection->get( $original );
			if ( null === $current ) {
				Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_INVALID_KEY );
				return;
			}
			$key = $original;
		} else {
			$current = null;
			$key     = isset( $posted['key'] ) ? sanitize_key( $posted['key'] ) : '';
			if ( '' === $key || $collection->has( $key ) ) {
				Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_INVALID_KEY );
				return;
			}
		}

		// Typ numeru to zamknięta lista: literówka zdjęłaby po cichu weryfikację numeru,
		// więc zapis jest odrzucany, a nie degradowany do braku numeru (tak robi czytanie).
		if ( isset( $posted['credential_type'] ) ) {
			$type = CredentialType::tryFrom( sanitize_text_field( $posted['credential_type'] ) );
		} else {
			$type = $current?->credentialType;
		}
		if ( null === $type ) {
			Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_INVALID_TYPE );
			return;
		}

		// Pole nieobecne w żądaniu zachowuje zapisaną wartość; pole puste ją czyści.
		$email = isset( $posted['notify_email'] ) ? trim( $posted['notify_email'] ) : ( $current->notifyEmail ?? '' );
		if ( '' !== $email && '' === sanitize_email( $email ) ) {
			Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_INVALID_EMAIL );
			return;
		}

		$profession = new Profession(
			$key,
			isset( $posted['label'] ) ? sanitize_text_field( $posted['label'] ) : ( $current->label ?? '' ),
			$type,
			isset( $posted['registry_url'] ) ? esc_url_raw( trim( $posted['registry_url'] ) ) : ( $current->registryUrl ?? '' ),
			sanitize_email( $email ),
			isset( $posted['credential_field'] ) ? sanitize_text_field( $posted['credential_field'] ) : ( $current->credentialField ?? '' )
		);

		// Zapisujemy całą kolekcję: pozostałe profesje przechodzą bez zmian, a edytowana zachowuje miejsce.
		$items = array();
		foreach ( $collection->all() as $existing ) {
			$items[] = $existing->key === $key ? $profession->toArray() : $existing->toArray();
		}
		if ( null === $current ) {
			$items[] = $profession->toArray();
		}

		// Zapis bez zmian zwraca false z update_option(), więc wyniku nie interpretujemy.
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );

		Request::redirect( Menu::SLUG_PROFESSIONS, Request::NOTICE_SAVED );
	}

	/**
	 * Usuwa profesję. Gdy używają jej konta, wymaga jawnego potwierdzenia. Metadane kont
	 * zostają nietknięte: ConsistencyChecker zgłosi klucz, którego nie ma już w konfiguracji.
	 * Operacja destrukcyjna, więc ma własną akcję nonce'a: nonce zapisu profesji jej nie autoryzuje.
	 */
	public static function handle_delete(): void {
		Request::guard( self::ACTION_DELETE );
		self::require_post();

		$key        = self::posted( 'delete' );
		$key        = null === $key ? '' : sanitize_text_field( $key );
		$collection = ProfessionRepository::get();
		if ( '' === $key || ! $collection->has( $key ) ) {
			Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_INVALID_KEY );
			return;
		}

		$accounts = self::accounts_using( $key );
		if ( $accounts > 0 && '1' !== self::posted( 'confirm_accounts' ) ) {
			Request::redirect(
				Menu::SLUG_PROFESSIONS,
				self::NOTICE_CONFIRM_ACCOUNTS,
				array(
					'profession' => $key,
				)
			);
			return;
		}

		$items = array();
		foreach ( $collection->all() as $existing ) {
			if ( $existing->key !== $key ) {
				$items[] = $existing->toArray();
			}
		}
		ProfessionRepository::save( ProfessionCollection::fromArray( $items ) );

		Request::redirect( Menu::SLUG_PROFESSIONS, self::NOTICE_DELETED );
	}

	/**
	 * Liczba kont przypisanych do profesji o danym kluczu.
	 *
	 * @param string $professionKey Klucz profesji.
	 */
	public static function accounts_using( string $professionKey ): int {
		$query = new WP_User_Query(
			array(
				'meta_key'    => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- liczenie kont wg profesji, rzadka operacja administracyjna.
				'meta_value'  => $professionKey, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Wypisuje ekran profesji.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nie masz uprawnień do wykonania tej operacji.', 'portal-users' ), '', array( 'response' => 403 ) );
		}

		$collection = ProfessionRepository::get();

		echo '<div class="wrap"><h1>' . esc_html__( 'Portal: profesje', 'portal-users' ) . '</h1>';
		ConsistencyNotice::render();
		self::render_notices( $collection );
		self::render_table( $collection );

		$editing = self::editing( $collection );
		echo '<h2>' . esc_html( null === $editing ? __( 'Dodaj profesję', 'portal-users' ) : __( 'Edytuj profesję', 'portal-users' ) ) . '</h2>';
		self::render_form( $editing );
		echo '</div>';
	}

	/**
	 * Powiadomienia po zapisie; potwierdzenie usunięcia niesie formularz z liczbą kont.
	 *
	 * @param ProfessionCollection $collection Bieżące profesje.
	 */
	private static function render_notices( ProfessionCollection $collection ): void {
		$notice = Request::notice();
		$texts  = array(
			Request::NOTICE_SAVED      => array( 'success', __( 'Profesja została zapisana.', 'portal-users' ) ),
			self::NOTICE_DELETED       => array( 'success', __( 'Profesja została usunięta.', 'portal-users' ) ),
			self::NOTICE_INVALID_TYPE  => array( 'error', __( 'Nie zapisano profesji: wybierz rodzaj numeru z listy.', 'portal-users' ) ),
			self::NOTICE_INVALID_KEY   => array( 'error', __( 'Nie zapisano: klucz profesji jest pusty, zajęty albo profesja nie istnieje.', 'portal-users' ) ),
			self::NOTICE_INVALID_EMAIL => array( 'error', __( 'Nie zapisano profesji: adres e-mail redakcji jest nieprawidłowy.', 'portal-users' ) ),
		);
		if ( isset( $texts[ $notice ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $texts[ $notice ][0] ) . '"><p>' . esc_html( $texts[ $notice ][1] ) . '</p></div>';
		}

		if ( self::NOTICE_CONFIRM_ACCOUNTS !== $notice ) {
			return;
		}
		// Odczyt parametrów powiadomienia po przekierowaniu: nic nie zmienia, więc nonce nie obowiązuje.
		$key    = isset( $_GET['profession'] ) && is_string( $_GET['profession'] ) ? sanitize_text_field( wp_unslash( $_GET['profession'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = $collection->get( $key );
		if ( null === $target ) {
			return;
		}
		// Liczba kont jest przeliczana teraz, a nie brana z adresu: komunikat nie może kłamać o zasięgu usunięcia.
		$accounts = self::accounts_using( $key );

		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: etykieta profesji. */
				__( 'Do profesji „%s” przypisano konta. Po usunięciu konta zachowają klucz profesji, którego nie ma już w konfiguracji.', 'portal-users' ),
				$target->label
			)
		) . ' ' . esc_html(
			sprintf(
				/* translators: 1: etykieta profesji, 2: liczba kont. */
				__( 'Liczba kont przypisanych do profesji „%1$s”: %2$d.', 'portal-users' ),
				$target->label,
				$accounts
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION_DELETE );
		printf(
			'<input type="hidden" name="%1$s" value="%2$s" /><input type="hidden" name="%3$s" value="1" />',
			esc_attr( self::name( 'delete' ) ),
			esc_attr( $key ),
			esc_attr( self::name( 'confirm_accounts' ) )
		);
		submit_button( __( 'Usuń mimo to', 'portal-users' ), 'delete', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Tabela profesji z liczbą kont przy każdej.
	 *
	 * @param ProfessionCollection $collection Bieżące profesje.
	 */
	private static function render_table( ProfessionCollection $collection ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Klucz', 'portal-users' ), __( 'Etykieta', 'portal-users' ), __( 'Rodzaj numeru', 'portal-users' ), __( 'Pole numeru', 'portal-users' ), __( 'Konta', 'portal-users' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $collection->all() ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nie zdefiniowano jeszcze żadnej profesji.', 'portal-users' ) . '</td></tr>';
		}
		foreach ( $collection->all() as $profession ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$d</td><td><a href="%6$s">%7$s</a> ',
				esc_html( $profession->key ),
				esc_html( $profession->label ),
				esc_html( self::type_label( $profession->credentialType ) ),
				esc_html( $profession->credentialField ),
				absint( self::accounts_using( $profession->key ) ),
				esc_url( Menu::url( Menu::SLUG_PROFESSIONS, array( 'edit' => $profession->key ) ) ),
				esc_html__( 'Edytuj', 'portal-users' )
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			self::form_header( self::ACTION_DELETE );
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::name( 'delete' ) ), esc_attr( $profession->key ) );
			submit_button( __( 'Usuń', 'portal-users' ), 'link-delete', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formularz dodawania albo edycji. W edycji klucz jest tylko do odczytu i nie decyduje o zapisie.
	 *
	 * @param Profession|null $profession Edytowana profesja albo null przy dodawaniu.
	 */
	private static function render_form( ?Profession $profession ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_header( self::ACTION );
		if ( null !== $profession ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::name( 'original_key' ) ), esc_attr( $profession->key ) );
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'key', __( 'Klucz', 'portal-users' ), $profession->key ?? '', null !== $profession, __( 'Małe litery, cyfry i myślniki. Konta przechowują klucz, więc po utworzeniu nie można go zmienić.', 'portal-users' ) );
		self::text_row( 'label', __( 'Etykieta', 'portal-users' ), $profession->label ?? '', false, '' );
		self::credential_type_row( $profession );
		self::credential_field_row( $profession );
		self::text_row( 'registry_url', __( 'Adres rejestru', 'portal-users' ), $profession->registryUrl ?? '', false, __( 'Strona rejestru zawodowego, w którym redakcja sprawdza numer.', 'portal-users' ), 'url' );
		self::text_row( 'notify_email', __( 'Adres redakcji', 'portal-users' ), $profession->notifyEmail ?? '', false, __( 'Opcjonalny adres powiadomień dla tej profesji.', 'portal-users' ), 'email' );
		echo '</tbody></table>';

		submit_button( null === $profession ? __( 'Dodaj profesję', 'portal-users' ) : __( 'Zapisz profesję', 'portal-users' ) );
		echo '</form>';
	}

	/**
	 * Wiersz z listą wyboru rodzaju numeru: cztery wartości CredentialType, bez pola tekstowego.
	 *
	 * @param Profession|null $profession Edytowana profesja albo null.
	 */
	private static function credential_type_row( ?Profession $profession ): void {
		$name = self::profession_name( 'credential_type' );
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">', esc_attr( $name ), esc_html__( 'Rodzaj numeru', 'portal-users' ) );
		foreach ( CredentialType::cases() as $case ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $case->value ),
				selected( null !== $profession && $profession->credentialType === $case, true, false ),
				esc_html( self::type_label( $case ) )
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * Wiersz z listą pól grupy ACF, w których profesja podaje numer. Zapisana wartość spoza
	 * listy (np. grupa zmieniona w ustawieniach) pozostaje na liście, żeby edycja jej nie wyczyściła.
	 *
	 * @param Profession|null $profession Edytowana profesja albo null.
	 */
	private static function credential_field_row( ?Profession $profession ): void {
		$fields  = ( new AcfFieldSource( SettingsRepository::get()->acfGroupKey ) )->fields();
		$current = null === $profession ? '' : $profession->credentialField;
		if ( '' !== $current && ! array_key_exists( $current, $fields ) ) {
			$fields[ $current ] = $current;
		}

		$name = self::profession_name( 'credential_field' );
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">', esc_attr( $name ), esc_html__( 'Pole numeru', 'portal-users' ) );
		printf( '<option value="">%s</option>', esc_html__( '— brak —', 'portal-users' ) );
		foreach ( $fields as $key => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $key ),
				selected( $current, (string) $key, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';
	}

	/**
	 * Wiersz z polem jednowierszowym.
	 *
	 * @param string $field       Nazwa pola profesji.
	 * @param string $label       Etykieta.
	 * @param string $value       Wartość.
	 * @param bool   $locked      Czy tylko do odczytu.
	 * @param string $description Opis pod polem (może być pusty).
	 * @param string $type        Typ pola HTML.
	 */
	private static function text_row( string $field, string $label, string $value, bool $locked, string $description, string $type = 'text' ): void {
		$name = self::profession_name( $field );
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="%1$s" name="%1$s" value="%4$s"%5$s />',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$locked ? ' readonly="readonly"' : ''
		);
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Pola ukryte formularza: akcja admin-post i nonce.
	 *
	 * @param string $action Wartość pola `action`.
	 */
	private static function form_header( string $action ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action, Request::NONCE_FIELD );
	}

	/**
	 * Profesja wybrana do edycji z adresu strony albo null.
	 *
	 * @param ProfessionCollection $collection Bieżące profesje.
	 */
	private static function editing( ProfessionCollection $collection ): ?Profession {
		// Wybór wiersza do edycji: odczyt, nic nie zmienia, więc nonce nie obowiązuje.
		if ( ! isset( $_GET['edit'] ) || ! is_string( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		return $collection->get( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Polska nazwa rodzaju numeru.
	 *
	 * @param CredentialType $type Rodzaj numeru.
	 */
	private static function type_label( CredentialType $type ): string {
		return match ( $type ) {
			CredentialType::PhysicianPwz       => __( 'Numer PWZ lekarza', 'portal-users' ),
			CredentialType::NursePwz           => __( 'Numer PWZ pielęgniarki', 'portal-users' ),
			CredentialType::DiagnosticianPwzdl => __( 'Numer PWZDL diagnosty', 'portal-users' ),
			CredentialType::None               => __( 'Bez numeru', 'portal-users' ),
		};
	}

	/**
	 * Odrzuca żądanie inne niż POST. Wywoływane po guardzie, żeby nieuprawniony GET dostał 403.
	 */
	private static function require_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'Ta operacja wymaga żądania POST.', 'portal-users' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Nazwa pola formularza z prefiksem.
	 *
	 * @param string $key Klucz pola.
	 */
	private static function name( string $key ): string {
		return self::FIELD_PREFIX . $key;
	}

	/**
	 * Nazwa pola profesji w tablicy `pusers_profession[...]`.
	 *
	 * @param string $field Nazwa pola profesji.
	 */
	private static function profession_name( string $field ): string {
		return self::name( 'profession' ) . '[' . $field . ']';
	}

	/**
	 * Wartość pola z żądania albo null, gdy pola nie przesłano. Nonce i uprawnienia
	 * sprawdza Request::guard() na początku obsługi.
	 *
	 * @param string $key Klucz pola.
	 */
	private static function posted( string $key ): ?string {
		$name = self::name( $key );
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return null;
		}
		return (string) wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w obsłudze żądania; wartość czyszczą wywołujący.
	}

	/**
	 * Pola profesji z żądania: tylko znane klucze i tylko wartości tekstowe; brakujące nie występują w wyniku.
	 *
	 * @return array<string, string>
	 */
	private static function posted_profession(): array {
		$name = self::name( 'profession' );
		if ( ! isset( $_POST[ $name ] ) || ! is_array( $_POST[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() w obsłudze żądania.
			return array();
		}
		$raw = wp_unslash( $_POST[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() w obsłudze żądania; wartości czyszczą wywołujący.
		$out = array();
		foreach ( self::PROFESSION_FIELDS as $field ) {
			if ( isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) ) {
				$out[ $field ] = $raw[ $field ];
			}
		}
		return $out;
	}
}
