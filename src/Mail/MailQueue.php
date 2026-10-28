<?php
/**
 * Render szablonu maila i wstawienie wiersza do kolejki.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Mail;

defined( 'ABSPATH' ) || exit;

use PortalUsers\Domain\Mail\MailKind;
use PortalUsers\Domain\Mail\Placeholders;
use PortalUsers\Domain\Mail\TemplateRenderer;
use PortalUsers\Front\Mailer;
use PortalUsers\Persistence\MailQueueRepository;
use PortalUsers\Persistence\MailTemplateRepository;

/**
 * Jedyna droga maila wtyczki do kolejki. Treść powstaje TUTAJ, w chwili zdarzenia, i ląduje w wierszu
 * jako snapshot: dispatcher nie potrzebuje potem ani konta, ani ustawień, ani szablonu, a redakcja
 * edytująca szablon nie przepisuje historii maili, które już poszły.
 *
 * Właściciel filtrów `Mailer::FILTER_SUBJECT` i `Mailer::FILTER_BODY` (kontrakt z Planu 3 bez zmian)
 * oraz klucza odporności: odbiorcę dopina sam, bo powiadomienie redakcji idzie osobną wiadomością do
 * każdego adresu i bez odbiorcy w kluczu drugi redaktor nigdy nie dostałby wiersza.
 */
final class MailQueue {

	/**
	 * Zdarzenie opróżniające kolejkę od razu, dla maili, na które człowiek czeka przy ekranie.
	 *
	 * Nazwa stoi tu, a nie w klasie crona: kolejka zna samą nazwę, nie klasę, która ją nasłuchuje,
	 * więc wstawienie wiersza nie zależy od tego, czy harmonogram jest w ogóle zarejestrowany.
	 */
	public const DISPATCH_HOOK = 'pusers_dispatch_mail_now';

	/**
	 * Limit długości klucza odporności, równy szerokości kolumny `dedupe_key` (patrz docblok
	 * `MailQueueRepository::insert()`). Dłuższy klucz MySQL obciąłby po cichu, bo wpdb zdejmuje tryby
	 * ścisłe, i dwa różne klucze o wspólnych 191 znakach zderzyłyby się na indeksie UNIQUE.
	 */
	private const DEDUPE_KEY_MAX = 191;

	/**
	 * Renderuje szablon rodzaju, przepuszcza temat i treść przez filtry i kolejkuje wiersz.
	 *
	 * @param MailKind     $kind      Rodzaj maila (wskazuje szablon i zmienne).
	 * @param int          $userId    Konto, którego dotyczy mail (0, gdy żadne).
	 * @param string       $recipient Adres odbiorcy.
	 * @param Placeholders $values    Wartości zmiennych szablonu.
	 * @param string       $dedupeKey Klucz odporności BEZ odbiorcy; odbiorcę dopina ta klasa.
	 * @param bool         $immediate Czy wysyłkę odpalić od razu, nie czekając na crona.
	 * @return bool True tylko gdy wiersz POWSTAŁ.
	 */
	public static function enqueue( MailKind $kind, int $userId, string $recipient, Placeholders $values, string $dedupeKey, bool $immediate = false ): bool {
		$template = MailTemplateRepository::get( $kind );
		$subject  = TemplateRenderer::render( $template->subject, $values );
		$body     = TemplateRenderer::render( $template->body, $values );
		$context  = array(
			'user_id' => $userId,
			'values'  => $values->toArray(),
		);

		/**
		 * Temat wiadomości.
		 *
		 * @param string               $subject Temat po renderze szablonu.
		 * @param string               $kind    Rodzaj wiadomości (MailKind::value).
		 * @param array<string, mixed> $context Kontekst: `user_id` i zmienne szablonu pod `values`.
		 */
		$filteredSubject = apply_filters( Mailer::FILTER_SUBJECT, $subject, $kind->value, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_SUBJECT, która ma prefiks pusers_.
		/**
		 * Treść wiadomości.
		 *
		 * @param string               $body    Treść po renderze szablonu.
		 * @param string               $kind    Rodzaj wiadomości (MailKind::value).
		 * @param array<string, mixed> $context Kontekst: `user_id` i zmienne szablonu pod `values`.
		 */
		$filteredBody = apply_filters( Mailer::FILTER_BODY, $body, $kind->value, $context ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- nazwa ze stałej FILTER_BODY, która ma prefiks pusers_.

		// Obca wtyczka oddająca nie-tekst (np. null) nie może zostawić maila bez treści: wracamy na render.
		return self::enqueue_rendered(
			$kind,
			$userId,
			$recipient,
			is_string( $filteredSubject ) ? $filteredSubject : $subject,
			is_string( $filteredBody ) ? $filteredBody : $body,
			$dedupeKey,
			$immediate
		);
	}

	/**
	 * Kolejkuje gotową treść, bez szablonu i bez filtrów.
	 *
	 * Droga dla maila, którego treść powstała gdzie indziej (mail rdzeniowy przechwycony na `pre_wp_mail`).
	 *
	 * @param MailKind $kind      Rodzaj maila.
	 * @param int      $userId    Konto, którego dotyczy mail (0, gdy żadne).
	 * @param string   $recipient Adres odbiorcy.
	 * @param string   $subject   Gotowy temat.
	 * @param string   $body      Gotowa treść (tekst zwykły).
	 * @param string   $dedupeKey Klucz odporności BEZ odbiorcy; odbiorcę dopina ta klasa.
	 * @param bool     $immediate Czy wysyłkę odpalić od razu, nie czekając na crona.
	 * @return bool True tylko gdy wiersz POWSTAŁ.
	 */
	public static function enqueue_rendered( MailKind $kind, int $userId, string $recipient, string $subject, string $body, string $dedupeKey, bool $immediate = false ): bool {
		$address = sanitize_email( trim( $recipient ) );
		if ( '' === $address || ! is_email( $address ) ) {
			return false;
		}

		$subject = self::single_line( $subject );
		if ( '' === $subject && '' === trim( $body ) ) {
			// Nie ma czego wysłać: pusty mail byłby dla odbiorcy zagadką, a w logu wysyłek hałasem.
			return false;
		}

		$key = $dedupeKey . ':' . substr( wp_hash( strtolower( $address ) ), 0, 16 );
		if ( strlen( $key ) > self::DEDUPE_KEY_MAX ) {
			// Obcięty klucz zderzyłby się na indeksie z innym o tym samym przedrostku, `insert()`
			// oddałby false, a wołający odczytałby to jako „już w kolejce" i mail nigdy by nie wyszedł.
			// Odmowa tutaj jest widoczna w wyniku; cicha kolizja nie byłaby widoczna nigdzie.
			return false;
		}

		$now    = gmdate( 'Y-m-d H:i:s' );
		$queued = MailQueueRepository::insert(
			array(
				'created_at'   => $now,
				'scheduled_at' => $now,
				'kind'         => $kind->value,
				'dedupe_key'   => $key,
				'user_id'      => $userId,
				'recipient'    => $address,
				'subject'      => $subject,
				'body'         => $body,
			)
		);

		if ( $queued && $immediate ) {
			// Tylko gdy wiersz powstał: zdarzenie bez wiersza budziłoby dispatchera po nic.
			wp_schedule_single_event( time(), self::DISPATCH_HOOK );
		}

		return $queued;
	}

	/**
	 * Temat w jednej linii: każdy ciąg białych znaków na jedną spację, bez obramowania.
	 *
	 * Temat składa się z szablonu I z podstawionych wartości, a `{powod_odrzucenia}` wpisuje ręcznie
	 * człowiek, więc może nieść nową linię. Nowa linia w temacie dojeżdżającym do transportu poczty
	 * to wstrzyknięcie nagłówka, nie usterka kosmetyczna. Treść zostaje nietknięta: w niej nowe linie
	 * są tym, co autor szablonu napisał.
	 *
	 * @param string $subject Temat po renderze i po filtrze.
	 */
	private static function single_line( string $subject ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $subject ) );
	}
}
