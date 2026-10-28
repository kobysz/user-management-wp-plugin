<?php
/**
 * Domyślne szablony wszystkich maili wtyczki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\MailTemplate;

/**
 * Treści domyślne w jednym miejscu, żeby admin widział w panelu dokładnie to, co wyszłoby bez nadpisania.
 *
 * Każdy szablon używa wyłącznie zmiennych, które deklaruje jego rodzaj (`MailKind::variables()`):
 * nieznana zmienna wyszłaby do odbiorcy jako dosłowne klamry. Liczb nie odmieniamy przez `_n()`:
 * językiem źródłowym jest polski, katalogu tłumaczeń nie ma.
 */
final class DefaultTemplates {

	/**
	 * Szablon domyślny danego rodzaju; temat i treść są zawsze niepuste.
	 *
	 * Tworzony przy każdym wywołaniu, bo `__()` zależy od języka bieżącego żądania.
	 *
	 * @param MailKind $kind Rodzaj maila.
	 */
	public static function for( MailKind $kind ): MailTemplate {
		return match ( $kind ) {
			MailKind::Confirmation        => self::confirmation(),
			MailKind::Editorial           => self::editorial(),
			MailKind::EditorialAuto       => self::editorial_auto(),
			MailKind::ExistingAccount     => self::existing_account(),
			MailKind::ApplicationReceived => self::application_received(),
			MailKind::Approved            => self::approved(),
			MailKind::Rejected            => self::rejected(),
			MailKind::PasswordReset       => self::password_reset(),
		};
	}

	/**
	 * Składa treść z linii, tak jak robił to `Mailer` przed szablonami.
	 *
	 * @param array<int, string> $lines Linie treści; pusty łańcuch to pusta linia.
	 */
	private static function lines( array $lines ): string {
		return implode( "\n", $lines );
	}

	/**
	 * Potwierdzenie adresu. Zdanie o haśle zostaje: zgłoszenie o hasło nie pyta, więc człowiek
	 * musi wiedzieć, że odnośnik jest jedyną drogą do konta. Ostrzeżenie „to nie Ty" też zostaje.
	 */
	private static function confirmation(): MailTemplate {
		return new MailTemplate(
			__( 'Potwierdź adres e-mail', 'portal-users' ),
			self::lines(
				array(
					__( 'Dziękujemy za rejestrację. Potwierdź adres e-mail i ustaw hasło do konta, otwierając odnośnik:', 'portal-users' ),
					'{link_potwierdzenia}',
					'',
					__( 'Hasła nie ustawia się w formularzu zgłoszenia: ustawisz je dopiero po otwarciu tego odnośnika.', 'portal-users' ),
					'',
					__( 'Jeżeli to nie Ty zakładałeś konto, zignoruj tę wiadomość.', 'portal-users' ),
				)
			)
		);
	}

	/**
	 * Powiadomienie redakcji, gdy wniosek czeka na decyzję.
	 */
	private static function editorial(): MailTemplate {
		return new MailTemplate(
			__( 'Nowy wniosek o konto czeka na decyzję', 'portal-users' ),
			self::lines(
				array(
					__( 'Nowe konto potwierdziło adres e-mail i czeka na decyzję.', 'portal-users' ),
					'',
					__( 'Profesja:', 'portal-users' ) . ' {profesja}',
					__( 'Adres e-mail:', 'portal-users' ) . ' {email}',
					__( 'Numer uprawnień:', 'portal-users' ) . ' {numer_uprawnien}',
					'',
					__( 'Pola zgłoszenia:', 'portal-users' ),
					'{pola_zgloszenia}',
					'',
					__( 'Wniosek:', 'portal-users' ) . ' {link_wniosku}',
				)
			)
		);
	}

	/**
	 * Powiadomienie redakcji, gdy akceptacja jest wyłączona: bez odnośnika do wniosku, bo nie ma czego rozstrzygać.
	 */
	private static function editorial_auto(): MailTemplate {
		return new MailTemplate(
			__( 'Nowe konto zatwierdzone automatycznie', 'portal-users' ),
			self::lines(
				array(
					__( 'Nowe konto potwierdziło adres e-mail i zostało zatwierdzone automatycznie (akceptacja jest wyłączona). Ta wiadomość ma charakter informacyjny.', 'portal-users' ),
					'',
					__( 'Profesja:', 'portal-users' ) . ' {profesja}',
					__( 'Adres e-mail:', 'portal-users' ) . ' {email}',
					__( 'Numer uprawnień:', 'portal-users' ) . ' {numer_uprawnien}',
					'',
					__( 'Pola zgłoszenia:', 'portal-users' ),
					'{pola_zgloszenia}',
				)
			)
		);
	}

	/**
	 * Próba rejestracji na adres, który ma już konto. Zdania „nic się nie zmieniło" i „nie musisz nic robić" zostają.
	 *
	 * Rodzaj nie deklaruje zmiennej z adresem odzyskiwania hasła, więc odsyłamy na stronę logowania,
	 * z której odzyskiwanie jest dostępne.
	 */
	private static function existing_account(): MailTemplate {
		return new MailTemplate(
			__( 'Próba rejestracji na Twój adres e-mail', 'portal-users' ),
			self::lines(
				array(
					__( 'Ktoś próbował zarejestrować konto z Twoim adresem e-mail, ale konto z tym adresem już istnieje.', 'portal-users' ),
					__( 'Nic się w nim nie zmieniło.', 'portal-users' ),
					'',
					__( 'Jeżeli to byłeś Ty, zaloguj się na istniejące konto:', 'portal-users' ),
					'{link_logowania}',
					__( 'Jeżeli nie pamiętasz hasła, możesz je odzyskać na stronie logowania.', 'portal-users' ),
					'',
					__( 'Jeżeli to nie Ty, zignoruj tę wiadomość. Nie musisz nic robić.', 'portal-users' ),
				)
			)
		);
	}

	/**
	 * Potwierdzenie przyjęcia zgłoszenia przez portal.
	 */
	private static function application_received(): MailTemplate {
		return new MailTemplate(
			__( 'Zgłoszenie zostało przyjęte', 'portal-users' ),
			self::lines(
				array(
					__( 'Adres e-mail został potwierdzony, a zgłoszenie konta w portalu {nazwa_portalu} czeka na decyzję redakcji.', 'portal-users' ),
					__( 'Powiadomimy Cię o decyzji w osobnej wiadomości. Do tego czasu logowanie nie jest możliwe.', 'portal-users' ),
					'',
					__( 'Strona logowania:', 'portal-users' ) . ' {link_logowania}',
				)
			)
		);
	}

	/**
	 * Zatwierdzenie konta.
	 */
	private static function approved(): MailTemplate {
		return new MailTemplate(
			__( 'Konto zostało zatwierdzone', 'portal-users' ),
			self::lines(
				array(
					__( 'Konto w portalu {nazwa_portalu} zostało zatwierdzone. Możesz się zalogować, używając adresu e-mail podanego przy rejestracji:', 'portal-users' ),
					'{link_logowania}',
					'',
					__( 'Jeżeli nie pamiętasz hasła, możesz je odzyskać na stronie logowania.', 'portal-users' ),
				)
			)
		);
	}

	/**
	 * Odrzucenie wniosku z podanym powodem.
	 */
	private static function rejected(): MailTemplate {
		return new MailTemplate(
			__( 'Zgłoszenie zostało odrzucone', 'portal-users' ),
			self::lines(
				array(
					__( 'Zgłoszenie konta w portalu {nazwa_portalu} zostało odrzucone.', 'portal-users' ),
					'',
					__( 'Powód:', 'portal-users' ) . ' {powod_odrzucenia}',
				)
			)
		);
	}

	/**
	 * Odzyskiwanie hasła. Zdanie „hasło samo się nie zmieni" zostaje: uspokaja osobę, która o nic nie prosiła.
	 */
	private static function password_reset(): MailTemplate {
		return new MailTemplate(
			__( 'Ustawienie nowego hasła', 'portal-users' ),
			self::lines(
				array(
					__( 'Ktoś poprosił o ustawienie nowego hasła do konta na tej witrynie.', 'portal-users' ),
					__( 'Konto:', 'portal-users' ) . ' {login}',
					'',
					__( 'Jeżeli to nie Ty, zignoruj tę wiadomość. Hasło samo się nie zmieni.', 'portal-users' ),
					'',
					__( 'Nowe hasło ustawisz, otwierając odnośnik:', 'portal-users' ),
					'{link_hasla}',
				)
			)
		);
	}
}
