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
 * 2. przepuszcza wiadomość, której odbiorca NIE jest adresem uzbrojonego konta. To zapora, która
 *    tu najbardziej waży: bez niej mail obcej wtyczki wysłany w tym samym żądaniu zostałby jej
 *    zabrany, a jego treść wylądowałaby w naszym logu wysyłek;
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
 */
final class CoreCapture {

	/**
	 * Uzbrojenie bieżącego żądania albo `null`, gdy przechwytywanie jest rozbrojone.
	 *
	 * @var array{kind: MailKind, userId: int, email: string, dedupeKey: string}|null
	 */
	private static ?array $armed = null;

	/**
	 * Podpina przechwytywanie pod `pre_wp_mail`.
	 */
	public static function register(): void {
		add_filter( 'pre_wp_mail', array( self::class, 'capture' ), 10, 2 );
	}

	/**
	 * Uzbraja przechwytywanie na JEDNĄ wiadomość do podanego adresu.
	 *
	 * Woła to miejsce, które zna treść i okoliczności maila (filtr treści rdzenia), bo tylko ono
	 * potrafi zbudować klucz odporności; samo przechwytywanie na `pre_wp_mail` wie już za mało.
	 *
	 * @param MailKind $kind      Rodzaj maila dla wiersza kolejki i logu wysyłek.
	 * @param int      $userId    Konto, którego mail dotyczy.
	 * @param string   $email     Adres uzbrojonego konta; tylko wiadomość na ten adres jest przechwytywana.
	 * @param string   $dedupeKey Klucz odporności BEZ odbiorcy; odbiorcę dopina kolejka.
	 */
	public static function arm( MailKind $kind, int $userId, string $email, string $dedupeKey ): void {
		self::$armed = array(
			'kind'      => $kind,
			'userId'    => $userId,
			'email'     => $email,
			'dedupeKey' => $dedupeKey,
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
	 * jest uzbrojone albo gdy odbiorca nie jest adresem uzbrojonego konta. Odbiorcy nietekstowego
	 * (`wp_mail()` przyjmuje też tablicę adresów) nie rozpoznajemy świadomie: uzbrojono JEDEN adres,
	 * a wiadomość do wielu odbiorców tym mailem nie jest.
	 *
	 * Po przechwyceniu zwraca `true`, czyli „wysłano" w rozumieniu `wp_mail()`, także wtedy, gdy
	 * wiersz nie powstał. Najczęstsza przyczyna odmowy kolejki to klucz odporności, który JUŻ jest
	 * w bazie, czyli ten sam mail czeka na wysyłkę — `false` kazałoby wtedy rdzeniowi zgłosić
	 * `retrieve_password_email_failure` człowiekowi, do którego mail i tak pojedzie.
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

		$to = $atts['to'] ?? '';
		if ( ! is_string( $to ) || ! self::same_address( $to, $armed['email'] ) ) {
			return $return;
		}

		$subject = isset( $atts['subject'] ) && is_string( $atts['subject'] ) ? $atts['subject'] : '';
		$message = isset( $atts['message'] ) && is_string( $atts['message'] ) ? $atts['message'] : '';

		// Treść jest już gotowa (złożył ją filtr treści rdzenia), więc idzie do kolejki jako
		// snapshot, bez szablonu i bez filtrów — te przeszła po drodze.
		MailQueue::enqueue_rendered( $armed['kind'], $armed['userId'], $armed['email'], $subject, $message, $armed['dedupeKey'], true );

		// Rozbrojenie BEZWARUNKOWE, także po odmowie kolejki: uzbrojenie, które zostaje, zabrałoby
		// wysyłkę następnej wiadomości tego żądania.
		self::disarm();

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
