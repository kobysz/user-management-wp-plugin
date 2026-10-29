<?php
/**
 * Raport skanu importu: liczniki i próbki kont dla każdego zastrzeżenia.
 *
 * @package PortalUsers
 */

declare( strict_types=1 );

namespace PortalUsers\Domain\Import;

/**
 * Obiekt niemutowalny z punktu widzenia wywołującego: każda zmiana zwraca nową instancję.
 */
final class ImportReport {

	/**
	 * Najwyżej tyle identyfikatorów kont trafia do próbki jednego zastrzeżenia.
	 */
	public const SAMPLE_LIMIT = 10;

	/**
	 * Liczba przeskanowanych kont.
	 *
	 * @var int
	 */
	private int $scanned = 0;

	/**
	 * Liczba kont, dla których planowany jest co najmniej jeden zapis.
	 *
	 * @var int
	 */
	private int $changed = 0;

	/**
	 * Liczba nieudanych zapisów.
	 *
	 * @var int
	 */
	private int $writeFailures = 0;

	/**
	 * Identyfikatory kont z nieudanym zapisem w kolejności dopisania, do SAMPLE_LIMIT.
	 *
	 * @var list<int>
	 */
	private array $writeFailureSample = array();

	/**
	 * Liczniki zastrzeżeń: kod => liczba kont.
	 *
	 * @var array<string, int>
	 */
	private array $issues = array();

	/**
	 * Próbki kont dla zastrzeżeń: kod => identyfikatory w kolejności dopisania.
	 *
	 * @var array<string, list<int>>
	 */
	private array $samples = array();

	/**
	 * Pusty raport bez przeskanowanych kont.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self();
	}

	/**
	 * Zwraca nowy raport z uwzględnioną zmianą konta; poprzedni się nie zmienia.
	 *
	 * @param AccountChange $change Zmiana zaplanowana dla konta.
	 * @return self
	 */
	public function with( AccountChange $change ): self {
		$next = clone $this;
		++$next->scanned;

		if ( $change->hasWrites() ) {
			++$next->changed;
		}

		foreach ( $change->issues as $code ) {
			$next->issues[ $code ] = ( $next->issues[ $code ] ?? 0 ) + 1;

			if ( count( $next->samples[ $code ] ?? array() ) < self::SAMPLE_LIMIT ) {
				$next->samples[ $code ][] = $change->userId;
			}
		}

		return $next;
	}

	/**
	 * Zwraca nowy raport z jedną porażką zapisu; konto nie przelicza się na zmienione.
	 *
	 * Identyfikator trafia do próbki, dopóki ta nie osiągnie SAMPLE_LIMIT; licznik rośnie zawsze.
	 *
	 * @param int $userId Identyfikator konta, którego zapis się nie udał.
	 * @return self
	 */
	public function withWriteFailure( int $userId ): self {
		$next = clone $this;
		++$next->writeFailures;

		if ( count( $next->writeFailureSample ) < self::SAMPLE_LIMIT ) {
			$next->writeFailureSample[] = $userId;
		}

		return $next;
	}

	/**
	 * Liczba przeskanowanych kont.
	 *
	 * @return int
	 */
	public function scanned(): int {
		return $this->scanned;
	}

	/**
	 * Liczba kont, dla których planowany jest zapis.
	 *
	 * @return int
	 */
	public function changed(): int {
		return $this->changed;
	}

	/**
	 * Liczba kont z danym zastrzeżeniem.
	 *
	 * @param string $code Kod zastrzeżenia.
	 * @return int
	 */
	public function issueCount( string $code ): int {
		return $this->issues[ $code ] ?? 0;
	}

	/**
	 * Pierwsze identyfikatory kont z danym zastrzeżeniem, w kolejności dopisania do raportu.
	 *
	 * @param string $code Kod zastrzeżenia.
	 * @return list<int>
	 */
	public function issueSample( string $code ): array {
		return $this->samples[ $code ] ?? array();
	}

	/**
	 * Liczba nieudanych zapisów.
	 *
	 * @return int
	 */
	public function writeFailures(): int {
		return $this->writeFailures;
	}

	/**
	 * Pierwsze identyfikatory kont z nieudanym zapisem, w kolejności dopisania do raportu.
	 *
	 * @return list<int>
	 */
	public function writeFailureSample(): array {
		return $this->writeFailureSample;
	}

	/**
	 * Raport w postaci tablicy, którą czyta późniejsze zapisywanie stanu.
	 *
	 * Zastrzeżenia i próbki pojawiają się tylko dla kodów z co najmniej jednym wystąpieniem,
	 * oba w kolejności kluczy (ksort). Próbka porażek jest pusta, gdy porażek nie było.
	 *
	 * @return array{scanned: int, changed: int, write_failures: int, issues: array<string, int>, samples: array<string, list<int>>, write_failure_sample: list<int>}
	 */
	public function toArray(): array {
		$issues = $this->issues;
		ksort( $issues );

		$samples = array();

		foreach ( array_keys( $issues ) as $code ) {
			$samples[ $code ] = $this->samples[ $code ];
		}

		return array(
			'scanned'              => $this->scanned,
			'changed'              => $this->changed,
			'write_failures'       => $this->writeFailures,
			'issues'               => $issues,
			'samples'              => $samples,
			'write_failure_sample' => $this->writeFailureSample,
		);
	}
}
