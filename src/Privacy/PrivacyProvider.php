<?php
/**
 * Integracja z narzędziami danych osobowych WordPressa: eksporter i eraser.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Privacy;

use PortalUsers\Acf\AcfFieldSource;
use PortalUsers\Admin\VerdictLabels;
use PortalUsers\Domain\Application\CredentialVerdict;
use PortalUsers\Persistence\EventLog;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\Migrations;
use PortalUsers\Persistence\ProfessionRepository;
use PortalUsers\Persistence\SettingsRepository;
use PortalUsers\Persistence\UserMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Wydaje dane osobowe konta przez standardowy mechanizm eksportu rdzenia.
 *
 * Treść maili NIE wychodzi w eksporcie: snapshot rodzaju `confirmation` niesie żywy token
 * potwierdzenia adresu, a `password_reset` klucz resetu hasła, więc plik eksportu (który ląduje
 * na dysku i w kopiach zapasowych) wydawałby działające linki do przejęcia konta. O wnioskodawcy
 * treść nie mówi niczego, czego nie wydają już metadane konta; wartością logu wysyłek jest fakt
 * wysłania. Dlatego `MailQueueRepository::for_user()` kolumny `body` nie czyta w ogóle.
 */
final class PrivacyProvider {

	public const SLUG     = 'portal-users';
	public const PER_PAGE = 50;

	/**
	 * Górna granica przebiegów erasera. Rdzeń woła eraser, aż dostanie `done`, a `done` czeka, aż konto nie
	 * ma już wierszy z `anonymized = 0`; przy trwałej awarii zapisu wiersz zostaje i pętla wracałaby w
	 * nieskończoność. Lepiej skończyć i powiedzieć prawdę.
	 */
	public const MAX_PASSES = 10;

	/**
	 * Metadane konta kasowane przez eraser: dane osobowe i jednorazowe sekrety. Wartości pól zgłoszenia
	 * (przedrostek `UserMeta::ACF_PREFIX`) kasowane są osobno, bo ich klucze zależą od grupy ACF.
	 */
	public const ERASE_META = array(
		UserMeta::REGISTRATION_IP,
		UserMeta::REJECTION_REASON,
		UserMeta::CONFIRM_HASH,
		UserMeta::CONFIRM_EXPIRES,
		UserMeta::PASSWORD_TICKET_HASH,
		UserMeta::PASSWORD_TICKET_EXPIRES,
	);

	/**
	 * Metadane, które eraser świadomie zostawia. Lista dokumentuje decyzję i pilnuje jej w teście.
	 *
	 * STATUS: brama logowania wpuszcza konta BEZ zapisanego stanu (żeby nie zamknąć poza portalem
	 * administratora dodanego ręcznie), więc skasowanie stanu zmieniłoby konto odrzucone albo oczekujące
	 * w konto, które się loguje. PROFESSION: bez niej konto przestaje być wnioskiem i znika z panelu.
	 * TERMS_VERSION i CONSENT_AT: zapis zgody zostaje na podstawie obowiązku jej wykazania.
	 */
	public const RETAIN_META = array(
		UserMeta::STATUS,
		UserMeta::PROFESSION,
		UserMeta::TERMS_VERSION,
		UserMeta::CONSENT_AT,
	);

	/**
	 * Podpina eksporter pod filtr rdzenia.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Dopisuje eksporter wtyczki do listy rdzenia.
	 *
	 * @param array<string, mixed> $exporters Zarejestrowane eksportery.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[ self::SLUG ] = array(
			'exporter_friendly_name' => __( 'Portal użytkowników', 'portal-users' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Dopisuje eraser wtyczki do listy rdzenia.
	 *
	 * @param array<string, mixed> $erasers Zarejestrowane erasery.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[ self::SLUG ] = array(
			'eraser_friendly_name' => __( 'Portal użytkowników', 'portal-users' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Eksport danych konta o danym adresie. Grupy stałe wychodzą na stronie pierwszej, log i maile
	 * stronicują się po `PER_PAGE`; eksport kończy się, gdy obie kolekcje zwrócą mniej niż strona.
	 *
	 * @param string $email Adres e-mail z żądania.
	 * @param int    $page  Numer strony, od 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$data   = array();

		if ( 1 === $page ) {
			$data[] = self::account_group( $user->ID, (string) $user->user_registered );
			$data[] = self::consent_group( $user->ID );
			$data[] = self::fields_group( $user->ID );
		}

		$events = EventLog::for_subject( $user->ID, self::PER_PAGE, $offset );
		foreach ( $events as $event ) {
			$data[] = self::event_group( $event );
		}

		$mails = MailQueueRepository::for_user( $user->ID, self::PER_PAGE, $offset );
		foreach ( $mails as $mail ) {
			$data[] = self::mail_group( $mail );
		}

		return array(
			'data' => $data,
			'done' => count( $events ) < self::PER_PAGE && count( $mails ) < self::PER_PAGE,
		);
	}

	/**
	 * Usuwa dane osobowe konta o danym adresie, a zostawia stan, profesję i zapis zgody.
	 *
	 * Rdzeń woła tę metodę w pętli, aż dostanie `done === true`. Anonimizacja wyjmuje wiersze ze zbioru
	 * dopasowań, więc nie ma offsetu: `$page` służy tylko do przycięcia pętli. `done` znaczy „dla tego konta
	 * nie został żaden wiersz z `anonymized = 0`", a nie „ten przebieg niczego nie zmienił": repozytoria po
	 * cichu pomijają wiersz, którego UPDATE zawiódł, więc przebieg bez zmian bywa przebiegiem, który nie
	 * potrafi zapisać, i uznanie go za koniec zostawiłoby dane osobowe przy koncie z meldunkiem o sukcesie.
	 *
	 * @param string $email Adres e-mail z żądania.
	 * @param int    $page  Numer przebiegu, od 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$messages = array();
		if ( $page > self::MAX_PASSES ) {
			$messages[] = __( 'Nie udało się zanonimizować części wpisów logu zdarzeń lub wysyłek tego konta. Powtórz żądanie albo zgłoś sprawę administratorowi.', 'portal-users' );

			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => $messages,
				'done'           => true,
			);
		}

		$removed    = self::erase_meta( $user->ID );
		$anonymized = EventLog::anonymize_subject( $user->ID ) + MailQueueRepository::anonymize_user( $user->ID );

		// Informację o zachowanych danych niesie pierwszy przebieg; rdzeń zbiera komunikaty ze wszystkich.
		if ( $page <= 1 ) {
			$messages[] = __( 'Zostawiono stan konta, profesję oraz zapis zgody na regulamin (wersja i czas): stan decyduje o dostępie do portalu, a zgodę trzeba umieć wykazać. Pełne usunięcie konta to skasowanie użytkownika, czyli osobna operacja administratora.', 'portal-users' );
		}

		return array(
			'items_removed'  => $removed || $anonymized > 0,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => 0 === self::remaining( $user->ID ),
		);
	}

	/**
	 * Liczba wierszy konta, które nadal czekają na anonimizację, w logu zdarzeń i w kolejce razem.
	 *
	 * Zapytanie, które zawiodło (brak tabeli, błąd bazy), liczy się jako jeden niezanonimizowany wiersz:
	 * nie da się wtedy dowieść, że nic nie zostało, a „zero" z awarii zamknęłoby pętlę meldunkiem o sukcesie.
	 *
	 * @param int $userId Konto.
	 */
	private static function remaining( int $userId ): int {
		global $wpdb;

		$count = 0;
		foreach ( array(
			array( 'event_log', 'subject_user_id' ),
			array( 'mail_queue', 'user_id' ),
		) as [ $table, $column ] ) {
			$left   = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d AND anonymized = 0', Migrations::table( $table ), $column, $userId )
			);
			$count += null === $left ? 1 : (int) $left;
		}

		return $count;
	}

	/**
	 * Kasuje metadane z `ERASE_META` i wszystkie wartości pól zgłoszenia, także te ukryte dziś w macierzy.
	 *
	 * @param int $userId Konto.
	 * @return bool Czy cokolwiek faktycznie usunięto.
	 */
	private static function erase_meta( int $userId ): bool {
		$keys = self::ERASE_META;
		foreach ( array_keys( (array) get_user_meta( $userId ) ) as $key ) {
			if ( str_starts_with( (string) $key, UserMeta::ACF_PREFIX ) ) {
				$keys[] = (string) $key;
			}
		}

		$removed = false;
		foreach ( $keys as $key ) {
			if ( delete_user_meta( $userId, $key ) ) {
				$removed = true;
			}
		}

		return $removed;
	}

	/**
	 * Grupa „konto": stan, profesja, numer uprawnień z werdyktem, data rejestracji i adres IP.
	 *
	 * @param int    $userId     Konto.
	 * @param string $registered Data rejestracji (UTC).
	 * @return array<string, mixed>
	 */
	private static function account_group( int $userId, string $registered ): array {
		$professionKey = self::meta_text( $userId, UserMeta::PROFESSION );
		$profession    = '' === $professionKey ? null : ProfessionRepository::get()->get( $professionKey );

		$pairs = array(
			self::pair( __( 'Stan konta', 'portal-users' ), self::account_status_label( self::meta_text( $userId, UserMeta::STATUS ) ) ),
			// Profesja usunięta z ustawień zostaje w koncie; surowy klucz jest wtedy jedyną informacją.
			self::pair( __( 'Profesja', 'portal-users' ), null === $profession ? $professionKey : $profession->label ),
		);

		$number = null === $profession || '' === $profession->credentialField
			? ''
			: self::meta_text( $userId, UserMeta::ACF_PREFIX . $profession->credentialField );
		if ( '' !== $number ) {
			$verdict = VerdictLabels::text( CredentialVerdict::for( $profession, $number ) );
			$pairs[] = self::pair( __( 'Numer uprawnień', 'portal-users' ), trim( $number . ' ' . $verdict ) );
		}

		$pairs[] = self::pair( __( 'Data rejestracji', 'portal-users' ), $registered );
		$pairs[] = self::pair( __( 'Adres IP rejestracji', 'portal-users' ), self::meta_text( $userId, UserMeta::REGISTRATION_IP ) );

		// Powód odrzucenia to decyzja o osobie zapisana w jej koncie, więc żądanie dostępu ją obejmuje;
		// konto, którego nie odrzucono, nie dostaje pustego wiersza.
		$reason = self::meta_text( $userId, UserMeta::REJECTION_REASON );
		if ( '' !== $reason ) {
			$pairs[] = self::pair( __( 'Powód odrzucenia', 'portal-users' ), $reason );
		}

		return self::group( 'pusers-account', __( 'Konto na portalu', 'portal-users' ), 'pusers-account', $pairs );
	}

	/**
	 * Grupa „zgoda": znacznik czasu i wersja regulaminu.
	 *
	 * @param int $userId Konto.
	 * @return array<string, mixed>
	 */
	private static function consent_group( int $userId ): array {
		return self::group(
			'pusers-consent',
			__( 'Zgoda na regulamin', 'portal-users' ),
			'pusers-consent',
			array(
				self::pair( __( 'Zgoda udzielona', 'portal-users' ), self::meta_text( $userId, UserMeta::CONSENT_AT ) ),
				self::pair( __( 'Wersja regulaminu', 'portal-users' ), self::meta_text( $userId, UserMeta::TERMS_VERSION ) ),
			)
		);
	}

	/**
	 * Grupa „pola zgłoszenia": wszystkie zapisane metadane z przedrostkiem, także te, które macierz
	 * dziś ukrywa dla profesji. Żądanie dostępu dotyczy tego, co zapisane, nie tego, co panel pokazuje.
	 *
	 * Rejestracja eksportera stoi poza bramką zależności ACF (patrz `Plugin::boot()`), więc źródło pól
	 * może być puste: wtedy etykietą jest nazwa metadanej, co jest lepsze niż niewydanie danych.
	 *
	 * @param int $userId Konto.
	 * @return array<string, mixed>
	 */
	private static function fields_group( int $userId ): array {
		$labels = ( new AcfFieldSource( SettingsRepository::get()->acfGroupKey ) )->fields();

		$pairs = array();
		$meta  = get_user_meta( $userId );
		foreach ( is_array( $meta ) ? $meta : array() as $key => $values ) {
			if ( ! str_starts_with( (string) $key, UserMeta::ACF_PREFIX ) ) {
				continue;
			}
			$name    = substr( (string) $key, strlen( UserMeta::ACF_PREFIX ) );
			$pairs[] = self::pair( $labels[ $name ] ?? $name, self::text( is_array( $values ) ? ( $values[0] ?? '' ) : '' ) );
		}

		return self::group( 'pusers-fields', __( 'Pola zgłoszenia', 'portal-users' ), 'pusers-fields', $pairs );
	}

	/**
	 * Wpis logu zdarzeń.
	 *
	 * @param array{id: int, created_at: string, action: string, actor_user_id: int, context: array<string, mixed>, anonymized: bool} $event Wiersz logu.
	 * @return array<string, mixed>
	 */
	private static function event_group( array $event ): array {
		$pairs = array(
			self::pair( __( 'Czas zdarzenia', 'portal-users' ), $event['created_at'] ),
			// Nazwa zdarzenia zostaje surowa: to identyfikator, a jego tłumaczenie rozjechałoby się przy zmianie nazwy akcji.
			self::pair( __( 'Zdarzenie', 'portal-users' ), $event['action'] ),
			self::pair( __( 'Wykonawca (identyfikator)', 'portal-users' ), (string) $event['actor_user_id'] ),
			self::pair( __( 'Szczegóły', 'portal-users' ), array() === $event['context'] ? '' : (string) wp_json_encode( $event['context'], JSON_UNESCAPED_UNICODE ) ),
		);
		if ( $event['anonymized'] ) {
			$pairs[] = self::anonymized_pair();
		}

		return self::group( 'pusers-event-log', __( 'Log zdarzeń konta', 'portal-users' ), 'pusers-event-log-' . $event['id'], $pairs );
	}

	/**
	 * Wpis logu wysyłek: rodzaj, adresat, temat, status i czas wysłania. Bez treści.
	 *
	 * @param array{id: int, kind: string, recipient: string, subject: string, status: string, created_at: string, sent_at: ?string, anonymized: bool} $mail Wiersz kolejki.
	 * @return array<string, mixed>
	 */
	private static function mail_group( array $mail ): array {
		$pairs = array(
			self::pair( __( 'Rodzaj', 'portal-users' ), $mail['kind'] ),
			self::pair( __( 'Adresat', 'portal-users' ), $mail['recipient'] ),
			self::pair( __( 'Temat', 'portal-users' ), $mail['subject'] ),
			self::pair( __( 'Status', 'portal-users' ), self::queue_status_label( $mail['status'] ) ),
			self::pair( __( 'Utworzono', 'portal-users' ), $mail['created_at'] ),
			self::pair( __( 'Wysłano', 'portal-users' ), $mail['sent_at'] ?? '' ),
		);
		if ( $mail['anonymized'] ) {
			$pairs[] = self::anonymized_pair();
		}

		return self::group( 'pusers-mail-queue', __( 'Wiadomości wysłane do konta', 'portal-users' ), 'pusers-mail-queue-' . $mail['id'], $pairs );
	}

	/**
	 * Para wyjaśniająca zastępcze napisy w wierszu: bez niej wiersz po wcześniejszym żądaniu usunięcia
	 * danych czytałby się jak awaria eksportu. Wiersz zwykły jej nie ma.
	 *
	 * @return array{name: string, value: string}
	 */
	private static function anonymized_pair(): array {
		return self::pair( __( 'Zanonimizowano', 'portal-users' ), __( 'Tak, dane tego wpisu zostały usunięte na wcześniejsze żądanie.', 'portal-users' ) );
	}

	/**
	 * Etykieta stanu konta dla czytelnika (lekarza, nie administratora); nieznany kod zostaje surowy.
	 *
	 * @param string $code Zapisany stan.
	 */
	private static function account_status_label( string $code ): string {
		return match ( $code ) {
			'email_unconfirmed' => __( 'Adres e-mail niepotwierdzony', 'portal-users' ),
			'pending'           => __( 'Oczekuje na zatwierdzenie', 'portal-users' ),
			'approved'          => __( 'Zatwierdzone', 'portal-users' ),
			'rejected'          => __( 'Odrzucone', 'portal-users' ),
			default             => $code,
		};
	}

	/**
	 * Etykieta statusu wiadomości w kolejce; nieznany kod zostaje surowy.
	 *
	 * @param string $code Status wiersza kolejki.
	 */
	private static function queue_status_label( string $code ): string {
		return match ( $code ) {
			'queued'  => __( 'W kolejce do wysłania', 'portal-users' ),
			'sending' => __( 'W trakcie wysyłania', 'portal-users' ),
			'sent'    => __( 'Wysłana', 'portal-users' ),
			'failed'  => __( 'Wysyłka nieudana', 'portal-users' ),
			default   => $code,
		};
	}

	/**
	 * Grupa w kształcie wymaganym przez rdzeń.
	 *
	 * @param string                                         $groupId Identyfikator grupy.
	 * @param string                                         $label   Etykieta grupy.
	 * @param string                                         $itemId  Identyfikator pozycji.
	 * @param array<int, array{name: string, value: string}> $pairs   Pary nazwa-wartość.
	 * @return array<string, mixed>
	 */
	private static function group( string $groupId, string $label, string $itemId, array $pairs ): array {
		return array(
			'group_id'    => $groupId,
			'group_label' => $label,
			'item_id'     => $itemId,
			'data'        => $pairs,
		);
	}

	/**
	 * Para nazwa-wartość.
	 *
	 * @param string $name  Nazwa.
	 * @param string $value Wartość.
	 * @return array{name: string, value: string}
	 */
	private static function pair( string $name, string $value ): array {
		return array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * Metadana jako tekst; brak i wartość nietekstowa dają pusty napis.
	 *
	 * @param int    $userId Konto.
	 * @param string $key    Klucz metadanej.
	 */
	private static function meta_text( int $userId, string $key ): string {
		return self::text( get_user_meta( $userId, $key, true ) );
	}

	/**
	 * Wartość metadanej jako tekst; lista (checkbox, wielokrotny select) schodzi do wartości
	 * rozdzielonych przecinkiem.
	 *
	 * @param mixed $value Wartość.
	 */
	private static function text( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( static fn ( $item ): string => is_scalar( $item ) ? (string) $item : '', $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
