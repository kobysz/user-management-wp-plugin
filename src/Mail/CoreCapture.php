<?php
/**
 * Przechwycenie maila rdzeniowego na `pre_wp_mail` i przepisanie go do kolejki wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Persistence\EventLog;

/**
 * Jedna droga maila RDZENIOWEGO do kolejki wtyczki, bez odbierania rdzeniowi mechanizmu.
 *
 * Powód istnienia: odzyskiwanie hasła stoi na `retrieve_password()`, bo drugiego magazynu tokenów
 * ta wtyczka nie buduje (decyzja Planu 4, której Plan 5 nie odwraca). `retrieve_password()` nie ma
 * przełącznika „nie wysyłaj": po filtrach treści woła `wp_mail()` BEZWARUNKOWO. Jedynym szwem,
 * którym ten mail wchodzi do kolejki bez tykania rdzenia, jest `pre_wp_mail` — filtr stojący PRZED
 * jakąkolwiek obróbką odbiorców, którego niepuste (nie-`null`) zwrócenie zastępuje całą wysyłkę
 * i staje się wynikiem `wp_mail()`.
 *
 * UZBROJENIE JEST WĄSKIE I JEDNORAZOWE. `pre_wp_mail` widzi KAŻDY mail żądania — cudzych wtyczek
 * też — więc przechwytywanie:
 *
 * 1. przepuszcza wszystko, dopóki wołający go nie uzbroi (`arm()`), czyli domyślnie nie robi nic;
 * 2. przepuszcza wiadomość, której odbiorca NIE jest adresem uzbrojonego konta ALBO której treść
 *    nie jest DOKŁADNIE tą, którą uzbrojenie wyrenderowało. Te dwie zapory są powodem, dla którego
 *    ta klasa jest bezpieczna: bez pierwszej mail obcej wtyczki wysłany w tym samym żądaniu
 *    zostałby jej zabrany, a jego treść wylądowałaby w naszym logu wysyłek; bez drugiej
 *    przechwytywanie byłoby zakresowane ADRESEM, nie wiadomością, więc uzbrojenie, które przeżyło
 *    (ścieżka `wp-login.php` nie ma naszego `finally`), zabrałoby następną wiadomość na ten sam
 *    adres — i, przy kluczu odporności już obecnym w bazie, PORZUCIŁOby ją, oddając `true`;
 * 3. rozbraja się po JEDNEJ przechwyconej wiadomości, żeby następny mail WordPressa w tym samym
 *    żądaniu (choćby powiadomienie o zmianie hasła, które specyfikacja każe zostawić rdzeniowym)
 *    już nas nie dotyczył.
 *
 * Uzbrojenie żyje JEDNO ŻĄDANIE i mieszka w prywatnym polu statycznym — nie w opcji ani
 * transiencie: zapisane przeżyłoby żądanie, w którym rdzeń do `wp_mail()` nie doszedł, i czekało
 * na cudzy mail. Wołający domyka to `disarm()` w `finally` (patrz `PasswordReset::handle_request()`).
 *
 * PRIORYTET JEST DOKŁADNIE 10 i nie wolno go podnosić. Rejestracja w `Plugin::boot()` stawia ten
 * callback przed wszystkim, co podpina się później na tym samym priorytecie (`WP_Hook` sortuje
 * kubełki priorytetów, a w kubełku zachowuje kolejność rejestracji), więc wyższy priorytet nic nie
 * kupuje, a wyprzedzałby filtr obcej wtyczki chcącej wysyłkę ZABLOKOWAĆ — zakolejkowalibyśmy wtedy
 * mail, którego ta wtyczka nie przepuściła.
 *
 * CZEGO WIERSZ KOLEJKI NIE NIESIE: nagłówków (`headers`), załączników (`attachments`) ani
 * osadzonych zasobów (`embeds`). Kolejka wozi temat i treść tekstową, bo tyle wysyła dispatcher,
 * więc wtyczka, która dopisała temu mailowi `Reply-To` przez `retrieve_password_notification_email`,
 * traci je — i traci je CICHO. Stąd zapora na treść jest też zaporą zakresu: wtyczka, która ten
 * mail przerabia, zwykle ruszy i treść, a wtedy przechwytywanie go nie dotyka i wysyła go rdzeń
 * ze wszystkim, co dopisała.
 */
final class CoreCapture {

	/**
	 * Zdarzenie w logu: wiadomość została przejęta z `wp_mail()`, ale kolejka wiersza nie przyjęła.
	 *
	 * Wiersz jest tu JEDYNYM śladem. Rdzeń zgłaszał awarię wysyłki błędem
	 * `retrieve_password_email_failure`, więc dopóki wołał `wp_mail()`, awaria była widoczna
	 * administratorowi; przejęta wysyłka zwraca `true` (odpowiedź dla człowieka NIE MOŻE się różnić,
	 * bo stałaby się czytnikiem „czy ten adres ma konto"), więc bez tego wiersza mail lekarza ginął
	 * bez śladu gdziekolwiek, a żądanie raportowało sukces.
	 */
	public const LOG_ACTION_NOT_QUEUED = 'mail_not_queued';

	/**
	 * Uzbrojenie bieżącego żądania albo `null`, gdy przechwytywanie jest rozbrojone.
	 *
	 * @var array{kind: MailKind, userId: int, email: string, dedupeKey: string, body: string}|null
	 */
	private static ?array $armed = null;

	/**
	 * Podpina przechwytywanie pod `pre_wp_mail`.
	 */
	public static function register(): void {
		add_filter( 'pre_wp_mail', array( self::class, 'capture' ), 10, 2 );
	}

	/**
	 * Uzbraja przechwytywanie na JEDNĄ wiadomość: tę o podanej treści i na podany adres.
	 *
	 * Woła to miejsce, które zna treść i okoliczności maila (filtr treści rdzenia), bo tylko ono
	 * potrafi zbudować klucz odporności; samo przechwytywanie na `pre_wp_mail` wie już za mało.
	 *
	 * @param MailKind $kind      Rodzaj maila dla wiersza kolejki i logu wysyłek.
	 * @param int      $userId    Konto, którego mail dotyczy.
	 * @param string   $email     Adres uzbrojonego konta; tylko wiadomość na ten adres jest przechwytywana.
	 * @param string   $dedupeKey Klucz odporności BEZ odbiorcy; odbiorcę dopina kolejka.
	 * @param string   $body      Treść, którą wołający oddał rdzeniowi; tylko ona jest przechwytywana.
	 */
	public static function arm( MailKind $kind, int $userId, string $email, string $dedupeKey, string $body ): void {
		self::$armed = array(
			'kind'      => $kind,
			'userId'    => $userId,
			'email'     => $email,
			'dedupeKey' => $dedupeKey,
			'body'      => $body,
		);
	}

	/**
	 * Rozbraja przechwytywanie. Wywołanie na rozbrojonym nic nie zmienia, więc wołający nie musi
	 * pytać o stan (stąd `finally` bez warunku).
	 */
	public static function disarm(): void {
		self::$armed = null;
	}

	/**
	 * Filtr `pre_wp_mail`: przepisuje uzbrojoną wiadomość do kolejki zamiast wysyłki.
	 *
	 * Zwraca wejście NIETKNIĘTE (czyli oddaje wysyłkę rdzeniowi i pozostałym filtrom), gdy nic nie
	 * jest uzbrojone, gdy odbiorca nie jest adresem uzbrojonego konta albo gdy treść nie jest tą,
	 * którą uzbrojenie wyrenderowało. Odbiorcy nietekstowego (`wp_mail()` przyjmuje też tablicę
	 * adresów) nie rozpoznajemy świadomie: uzbrojono JEDEN adres, a wiadomość do wielu odbiorców
	 * tym mailem nie jest.
	 *
	 * Niezgodność treści ustępuje w BEZPIECZNĄ stronę: wtyczka, która przerobiła wiadomość filtrem
	 * `retrieve_password_notification_email`, dostaje ją wysłaną przez rdzeń — ze swoimi nagłówkami
	 * i załącznikami, których kolejka i tak by nie przewiozła — a my jej po prostu nie logujemy.
	 *
	 * Rozbrojenie idzie PRZED kolejkowaniem: `enqueue_rendered()` zakłada zdarzenie crona, a cudzy
	 * callback na hookach harmonogramu potrafi zawołać `wp_mail()`. Wejście z wnętrza kolejkowania
	 * do jeszcze uzbrojonego przechwytywania trafiłoby na ten sam klucz odporności, dostałoby
	 * odmowę i zostałoby połknięte z wynikiem `true`.
	 *
	 * Po przechwyceniu zwraca `true`, czyli „wysłano" w rozumieniu `wp_mail()`, także wtedy, gdy
	 * wiersz nie powstał — odpowiedź dla człowieka musi być ta sama niezależnie od losu maila
	 * (reguła 1 z `PasswordReset`), więc odmowa kolejki idzie do LOGU, nie do odpowiedzi. Kolejka
	 * odmawia z pięciu powodów i tylko jeden z nich (klucz odporności już w bazie, czyli ten sam
	 * mail już czeka na wysyłkę) jest niegroźny; pozostałe — adres nie-`is_email()`, adres dłuższy
	 * od kolumny, oba pola puste, klucz dłuższy od kolumny i awaria zapisu — znaczą mail porzucony
	 * i muszą zostawić ślad (patrz `LOG_ACTION_NOT_QUEUED`).
	 *
	 * @param mixed                $return Wartość od poprzednich filtrów (`null` = nikt nie przejął wysyłki).
	 * @param array<string, mixed> $atts   Argumenty `wp_mail()`: `to`, `subject`, `message`, `headers`, `attachments`.
	 * @return mixed `true` po przechwyceniu albo wejście nietknięte.
	 */
	public static function capture( mixed $return, array $atts ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- nazwa `$return` jest ta sama, którą rdzeń nosi w dokumentacji `pre_wp_mail`; inna kazałaby czytającemu zgadywać, że to ten sam argument.
		$armed = self::$armed;
		if ( null === $armed ) {
			return $return;
		}

		$to      = $atts['to'] ?? '';
		$message = isset( $atts['message'] ) && is_string( $atts['message'] ) ? $atts['message'] : '';
		if ( ! is_string( $to ) || ! self::same_address( $to, $armed['email'] ) || $message !== $armed['body'] ) {
			return $return;
		}

		self::disarm();

		$subject = isset( $atts['subject'] ) && is_string( $atts['subject'] ) ? $atts['subject'] : '';

		// Treść jest już gotowa (złożył ją filtr treści rdzenia), więc idzie do kolejki jako
		// snapshot, bez szablonu i bez filtrów — te przeszła po drodze.
		$queued = MailQueue::enqueue_rendered( $armed['kind'], $armed['userId'], $armed['email'], $subject, $message, $armed['dedupeKey'], true );
		if ( ! $queued ) {
			// Adresu jawnego w wierszu NIE MA (ta sama higiena, co w logu żądań o link); rodzaj
			// i konto wystarczą, żeby administrator znalazł, czyj mail przepadł. Zapis jest
			// OGRANICZONY, bo uzbrojenie powstaje wyłącznie na ścieżce odzyskiwania hasła, a ta
			// stoi za progami per adres IP i per adres e-mail — nieograniczonego `INSERT`-u do
			// tabeli audytu ta wtyczka już raz przepłaciła i drugi raz nie płaci.
			EventLog::write(
				self::LOG_ACTION_NOT_QUEUED,
				$armed['userId'],
				0,
				array( 'kind' => $armed['kind']->value )
			);
		}

		return true;
	}

	/**
	 * Czy dwa adresy to ten sam adres. Porównanie bez wielkości liter i bez obramowania białymi
	 * znakami, bo odbiorcę do `wp_mail()` wpisuje wołający, a uzbrojony adres pochodzi z konta.
	 *
	 * @param string $one Adres z wiadomości.
	 * @param string $two Adres uzbrojonego konta.
	 */
	private static function same_address( string $one, string $two ): bool {
		return strtolower( trim( $one ) ) === strtolower( trim( $two ) );
	}
}
