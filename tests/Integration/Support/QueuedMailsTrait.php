<?php
/**
 * Wspólny pomocnik testów: maile wtyczki czytane z kolejki, w kształcie argumentów `wp_mail()`.
 */

declare( strict_types=1 );

namespace PortalUsers\Tests\Integration\Support;

use PortalUsers\Persistence\MailQueueRepository;

/**
 * Od Planu 5 zdarzenie nie woła `wp_mail()`, tylko wstawia wiersz do kolejki. Testy, które do tej pory
 * przechwytywały wysyłkę filtrem `pre_wp_mail`, czytają ten sam mail stąd — z tą samą ASERCJĄ, tylko
 * z innego źródła. Kształt (`to`, `subject`, `message`) jest zachowany, żeby przepisanie testów dotknęło
 * źródła, a nie treści, której pilnują.
 */
trait QueuedMailsTrait {

	/**
	 * Identyfikator wiersza, od którego `mails()` liczy; podnosi go `forget_mails()`.
	 */
	private int $mailsAfterId = 0;

	/**
	 * Zakolejkowane maile, najstarszy naprzód, od ostatniego `forget_mails()`.
	 *
	 * UWAGA przy czytaniu asercji: `to` jest tu SKŁADANE z kolumny `recipient`, więc zawsze ma jeden
	 * element. Asercja „każdy redaktor dostaje osobną wiadomość i nie widzi adresów pozostałych" nie
	 * może już paść przez wielu odbiorców w jednym `To:` — ten defekt objawiłby się brakującym
	 * WIERSZEM, nie dłuższą tablicą. Łapią go `MailerTest::test_editorial_queues_a_separate_row_for_every_recipient`
	 * i `ConfirmationTest::test_all_configured_global_recipients_are_notified`, które liczą wiersze.
	 *
	 * @return list<array{to: list<string>, subject: string, message: string, kind: string}>
	 */
	private function mails(): array {
		$out = array();
		foreach ( array_reverse( MailQueueRepository::paginate( '', 200, 0 ) ) as $row ) {
			if ( (int) $row['id'] <= $this->mailsAfterId ) {
				continue;
			}
			$out[] = array(
				'to'      => array( (string) $row['recipient'] ),
				'subject' => (string) $row['subject'],
				'message' => (string) $row['body'],
				'kind'    => (string) $row['kind'],
			);
		}

		return $out;
	}

	/**
	 * Przestawia granicę na bieżący koniec kolejki: dalsze `mails()` widzą tylko nowe wiersze.
	 *
	 * Odpowiednik dawnego `$this->mails = array()`. Wierszy nie kasujemy: kolejka jest też logiem
	 * wysyłek, a test ma sprawdzać, co doszło, nie przepisywać historię.
	 */
	private function forget_mails(): void {
		$newest = MailQueueRepository::paginate( '', 1, 0 );
		if ( array() !== $newest ) {
			$this->mailsAfterId = (int) $newest[0]['id'];
		}
	}
}
