# Eksport CSV i dane osobowe — plan wdrożenia

> **Dla wykonawców agentowych:** WYMAGANA PODSKILL: użyj superpowers:subagent-driven-development (zalecane) albo superpowers:executing-plans, żeby wykonać ten plan zadanie po zadaniu. Kroki mają składnię pól wyboru (`- [ ]`) do odhaczania.

**Cel:** administrator pobiera wnioski do CSV bez ryzyka, że komórka wykona się w arkuszu, a żądanie dostępu albo usunięcia danych osobowych obsługuje się standardowym narzędziem WordPressa — przy czym log zdarzeń zostaje jako dowód, kto i kiedy zatwierdził konto.

**Architektura:** składanie CSV i reguła, co wolno zostawić w zanonimizowanym wierszu logu, to czysta domena — jedyne dwie decyzje w tym planie, których nie wolno liczyć dwa razy. Nad nimi dwa repozytoria dostają po dwie metody (czytanie dla eksportu, anonimizacja dla usunięcia), a `PrivacyProvider` jest cienkim adapterem do haków rdzenia. Osobno, poza RODO: `EventLog::write()` przestaje przemilczać porażkę zapisu, bo cały ten plan opiera się na logu jako dowodzie.

**Stos:** WordPress 6.5+, PHP 8.1+, ACF Pro, PSR-4 z autoloadem Composera, PHPUnit (dwie suity), PHPStan level max, PHPCS (WordPress).

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`, sekcje „Dane osobowe" i „Pozostałe funkcje pierwszej wersji".

**Zobowiązania odziedziczone:** `docs/superpowers/backlog.md`, sekcja „Plan 7"; `docs/superpowers/decyzje-plan-6.md` §8.

**Czego ten plan NIE robi:** narzędzia importu z poprzedniej wtyczki ani backfillu stanów przy aktywacji. Oba należą do osobnego planu (dawny „Plan 8 — Aktualizacje i release" przesuwa się na Plan 9), bo dzielą mapowanie metadanych starej wtyczki i oba są operacjami wdrożeniowymi, nie bieżącą obsługą danych. Decyzja właściciela z 2026-10-29.

## Ograniczenia globalne

- WordPress 6.5 lub nowszy, PHP 8.1 lub nowszy. **Stała enuma w wyrażeniu stałej (`Enum::Case->value` w `const`) to PHP 8.2** — na 8.1 jest fatalem.
- Single site, ale bez hardkodowania założeń blokujących późniejsze multisite.
- Polski jako język bazowy, **każdy** tekst widoczny dla człowieka przez `__()`/`esc_html__()` z domeną `portal-users`.
- `src/Domain/**` nie wolno dotknąć WordPressa ani `$wpdb`, nie ma w nich linii `defined( 'ABSPATH' ) || exit;`, i zwracają **kody**, nie komunikaty. Pilnuje tego `tests/Unit/Architecture/DomainPurityTest.php`. **Funkcje wewnętrzne PHP są w domenie dozwolone** (strażnik allowlistuje `get_defined_functions()['internal']`), więc `fopen`, `fputcsv` i `rawurlencode` wolno w niej wywołać. **Słowa „WordPress" nie wolno użyć nawet w komentarzu** w tym katalogu — strażnik ma na to własny przypadek testowy.
- Dostęp do eksportu CSV wymaga `Menu::CAPABILITY` (`manage_options`). Każde żądanie zmieniające stan albo wydające dane przechodzi przez `Request::guard( string $action )` (uprawnienie + nonce **wyłącznie z `$_POST`**), a potem przez jawne sprawdzenie metody POST z `wp_die( …, 405 )` — wzór w `src/Admin/MailLogScreen.php::handle_requeue()`.
- Zapytania własne wyłącznie przez `$wpdb->prepare()`. Nazwy tabel wtyczki z `Migrations::table()`, nigdy z żądania. Sniff `WordPress.DB.DirectDatabaseQuery` **nie jest** w tym zestawie reguł — nie dokładać dla niego supresji.
- **`Migrations::DB_VERSION` zostaje na 5.** Ten plan nie dodaje ani tabeli, ani kolumny: `anonymized` istnieje w `event_log` i w `mail_queue` od pierwszej migracji (`src/Persistence/Migrations.php:75` i `:111`), a `user_id` w `mail_queue` też.
- Pliki zapisywane z końcami linii **LF** (cały indeks repozytorium jest LF). Po zapisie sprawdź, że sekwencje w rodzaju `\b` stoją w pliku jako dwuznak, nie jako bajt sterujący: `grep -c $'\x08' <plik>` ma dać zero. W poprzednim planie `\b` wylądowało jako backspace i cicho unieważniło asercję.
- Bramki przed każdym commitem: obie suity, PHPStan, PHPCS.

```bash
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpunit
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpunit -c phpunit-integration.xml.dist
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 composer analyse
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpcs -q
```

**Wołaj `composer analyse`, nie gołego `phpstan analyse`** — skrypt niesie `--memory-limit=2G`, a na domyślnych 128 MB analiza pada na równoległym workerze z komunikatem, który wygląda jak błąd kodu.

**Dwa przebiegi suity integracyjnej naraz dzielą jedną bazę i dają fałszywe błędy.** Nigdy nie uruchamiać dwóch równolegle.

Stan wejściowy: unit **248** testów (560 asercji), integration **982** (3595).

## Review Focus

Pięć klas wejścia, których specyfikacja nie opisuje, a które uderzą w człowieka. Każda ma przypisany test w zadaniu, które jest właścicielem kodu.

1. **Eraser usuwa metadaną stanu konta i tym samym WPUSZCZA konto na portal.** Brama logowania wpuszcza konta **bez** zapisanego stanu — tak zdecydowano, żeby nie zamknąć poza portalem administratora dodanego ręcznie. Usunięcie `pusers_status` przy żądaniu RODO zmieniłoby więc konto odrzucone w konto, które się loguje. Oczekiwane: eraser stanu **nie** rusza i mówi, że go zachował. Test w Zadaniu 8.
2. **Eksport wydaje żywy token.** Wiersz kolejki rodzaju `confirmation` niesie token potwierdzenia adresu ważny 48 godzin, a `password_reset` klucz resetu hasła z rdzenia. Oczekiwane: treść maila nie wychodzi w eksporcie w ogóle. Test w Zadaniu 7.
3. **Wartość pola zaczynająca się od `=`, `+`, `-` albo `@` wykonuje się po otwarciu CSV w arkuszu**, a wartość z przecinkiem, cudzysłowem albo znakiem nowej linii rozsypuje wiersz. Oczekiwane: komórka jest martwa i zostaje jedną komórką. Testy w Zadaniu 1.
4. **Eraser zapętla się, bo WordPress woła go do `done === true`.** Anonimizacja usuwa wiersze ze zbioru dopasowań, więc przesuwanie offsetu pomija resztę, a nieprzesuwanie przy stałym zbiorze kręci się bez końca. Oczekiwane: offset zostaje na zerze, a zbiór maleje. Test w Zadaniu 8.
5. **Żądanie na adres, do którego nie ma konta** (literówka, konto usunięte wcześniej). Oczekiwane: `done === true`, zero zmian, zero błędów PHP. Testy w Zadaniach 7 i 8.

---

## Struktura plików

**Czysta domena** (bez platformy, testy jednostkowe):

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Domain/Export/CsvCell.php` | neutralizacja jednej komórki; jedno miejsce reguły |
| `src/Domain/Export/CsvDocument.php` | składanie dokumentu: BOM, nagłówek, wiersze |
| `src/Domain/Privacy/ContextFilter.php` | co wolno zostawić w zanonimizowanym kontekście wiersza logu |

**Dane:**

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Persistence/EventLog.php` (modyfikacja) | `write()` zwraca `bool`; czytanie i anonimizacja po podmiocie |
| `src/Persistence/MailQueueRepository.php` (modyfikacja) | czytanie i anonimizacja po koncie |

**Eksport i RODO:**

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Export/UserCsvExport.php` | wiersze wniosków + pola ACF → tekst CSV |
| `src/Admin/ExportHandler.php` | bramki, nagłówki pobierania, strumień |
| `src/Privacy/PrivacyProvider.php` | adapter do haków eksportu i usuwania rdzenia |

**Modyfikowane dodatkowo:** `src/Account/Decision.php`, `src/Admin/ApplicationsScreen.php`, `src/Plugin.php`, `docs/superpowers/backlog.md`.

---

### Zadanie 1: Domena CSV

**Pliki:**
- Utwórz: `src/Domain/Export/CsvCell.php`
- Utwórz: `src/Domain/Export/CsvDocument.php`
- Test: `tests/Unit/Export/CsvCellTest.php`
- Test: `tests/Unit/Export/CsvDocumentTest.php`

**Interfejsy:**
- Produkuje:
  - `CsvCell::DANGEROUS = array( '=', '+', '-', '@', "\t", "\r" )`
  - `CsvCell::neutralize( string $value ): string`
  - `CsvDocument::BOM = "\xEF\xBB\xBF"`
  - `CsvDocument::__construct( array<int, string> $headers )`
  - `CsvDocument::addRow( array<int, string> $cells ): void`
  - `CsvDocument::render(): string`

**Wzorzec do powtórzenia, nie do wymyślenia:** `C:\Users\kuba\SynologyDrive\WebDevAI\event-registration\src\Admin\RegistrationsExporter.php` — BOM na początku, nagłówek **bez** neutralizacji (to etykiety autora i tłumaczeń, nie dane użytkownika), każda komórka danych przez neutralizację, separator domyślny `fputcsv`.

- [ ] **Krok 1: Napisz testy jednostkowe `CsvCell`**

```php
public function test_komorka_zaczynajaca_sie_od_znaku_formuly_dostaje_apostrof(): void {
	$this->assertSame( "'=1+1", CsvCell::neutralize( '=1+1' ) );
	$this->assertSame( "'+48 600 100 200", CsvCell::neutralize( '+48 600 100 200' ) );
	$this->assertSame( "'-5", CsvCell::neutralize( '-5' ) );
	$this->assertSame( "'@SUM(A1)", CsvCell::neutralize( '@SUM(A1)' ) );
	$this->assertSame( "'\tpoczatek tabulatorem", CsvCell::neutralize( "\tpoczatek tabulatorem" ) );
}

public function test_zwykla_wartosc_zostaje_bez_zmian(): void {
	$this->assertSame( 'Szpital Miejski', CsvCell::neutralize( 'Szpital Miejski' ) );
	$this->assertSame( '', CsvCell::neutralize( '' ) );
	// Znak niebezpieczny w ŚRODKU wartości nie jest formułą i nie wolno go ruszać.
	$this->assertSame( 'a=b', CsvCell::neutralize( 'a=b' ) );
	$this->assertSame( 'tel. +48', CsvCell::neutralize( 'tel. +48' ) );
}
```

- [ ] **Krok 2: Napisz testy jednostkowe `CsvDocument`**

Nazwy i asercje:

```php
public function test_dokument_zaczyna_sie_od_bom(): void
// assertStringStartsWith( CsvDocument::BOM, … )

public function test_naglowek_nie_jest_neutralizowany(): void
// Nagłówek '=suma' zostaje '=suma': to etykieta autora, nie dane użytkownika.

public function test_komorki_danych_sa_neutralizowane(): void
// Wiersz z '=1+1' daje w wyjściu "'=1+1".

public function test_wartosc_z_przecinkiem_cudzyslowem_i_nowa_linia_zostaje_jedna_komorka(): void
// Wiersz array( 'a,b', 'on rzekl "nie"', "pierwsza\ndruga" ) → po sparsowaniu wyjścia
// przez str_getcsv (bez BOM) wychodzą DOKŁADNIE trzy komórki o tych wartościach.
// Asercja na liczbę komórek i na ich wartości, nie na obecność fragmentu.

public function test_dokument_bez_wierszy_ma_sam_naglowek(): void

public function test_liczba_komorek_niezgodna_z_naglowkiem_jest_bledem(): void
// addRow() z inną liczbą komórek niż nagłówek rzuca InvalidArgumentException:
// rozjechany wiersz w CSV jest trudniejszy do zauważenia niż wyjątek.
```

- [ ] **Krok 3: Uruchom testy i potwierdź porażkę**

Komenda unit z `--filter 'CsvCell|CsvDocument'`. Oczekiwane: „Class ... not found".

- [ ] **Krok 4: Zaimplementuj obie klasy**

`CsvDocument::render()` składa dokument w pamięci przez `fopen( 'php://temp', 'r+' )` i `fputcsv()`, a na końcu dokleja BOM z przodu. **Żadnego WordPressa i żadnego słowa „WordPress" w komentarzach** — to `src/Domain/**`.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**

- [ ] **Krok 6: Bramki, `DomainPurityTest` i commit**

`DomainPurityTest` musi zobaczyć nowy katalog `src/Domain/Export` i orzec o nim czysto.

```bash
git add src/Domain/Export tests/Unit/Export
git commit -m "feat: domena CSV - neutralizacja komorki i skladanie dokumentu z BOM"
```

---

### Zadanie 2: `EventLog::write()` przestaje przemilczać porażkę

**Pliki:**
- Modyfikuj: `src/Persistence/EventLog.php`
- Modyfikuj: `src/Account/Decision.php`
- Modyfikuj: `src/Admin/ApplicationsScreen.php`
- Test: `tests/Integration/Account/DecisionTest.php`
- Test: `tests/Integration/Admin/ApplicationsScreenTest.php`

**Dlaczego to zadanie jest w tym planie.** Cały plan opiera się na logu zdarzeń jako dowodzie: anonimizujemy go zamiast kasować właśnie po to, by wykazał, kto i kiedy zatwierdził konto. Tymczasem `write()` zwraca `void` i ignoruje wynik `$wpdb->insert()`. `Decision::approve()`/`reject()` piszą log **po** udanym zapisie stanu i **przed** mailem, więc porażka zapisu logu zostawia rozstrzygnięcie wysłane mailem i zgłoszone redaktorowi jako sukces, a po audycie nie ma śladu. Pozycja z backlogu.

**Interfejsy:**
- Zmienia: `EventLog::write( string $action, int $subjectUserId, int $actorUserId, array $context ): bool` — `true`, gdy wiersz powstał.
- Produkuje: `Decision::RESULT_LOG_FAILED = 'log_failed'`.

**Rozstrzygnięcie o kolejności skutków:** porażka logu **nie cofa** stanu i **nie wstrzymuje** maila. Stan jest prawdą, a lekarz ma prawo wiedzieć, że jego konto rozstrzygnięto. `Decision` zwraca wtedy `RESULT_LOG_FAILED` niezależnie od wyniku maila — jeden kod zwracany nie może nieść dwóch porażek, a brak śladu w audycie jest poważniejszy niż brak maila, o którym da się dowiedzieć od lekarza.

**Zakres zmiany u pozostałych wywołujących:** sygnatura zmienia się dla wszystkich, ale **reaguje na nią wyłącznie `Decision`**. Pozostałe miejsca (rejestracja, powiązanie regulaminu, odporność na boty, most ACF, brama logowania, odzyskiwanie hasła) ignorują wynik tak jak dotąd — żadne z nich nie ma czego wycofać, a przerobienie ich wszystkich w tym planie rozlałoby zadanie na pół wtyczki. Nie dodawaj im obsługi.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Account/DecisionTest.php
public function test_porazka_zapisu_logu_daje_wlasny_kod_a_stan_i_mail_zostaja(): void
// Filtr na `query` $wpdb albo filtr blokujący insert do tabeli event_log sprawia, że wiersz nie powstaje.
// Asercje: wynik === Decision::RESULT_LOG_FAILED; stan konta JEST zmieniony na approved;
// w kolejce JEST mail rodzaju approved; w event_log NIE MA wiersza application_approved.

public function test_udany_zapis_logu_dalej_daje_ok(): void
// Kontrola negatywna: bez filtra wynik to RESULT_OK.

// tests/Integration/Admin/ApplicationsScreenTest.php
public function test_komunikat_mowi_gdy_rozstrzygniecie_nie_trafilo_do_logu(): void
// Osobny tekst i klasa notice-warning; konto JEST rozstrzygnięte. Asercja na stan konta, nie tylko na napis.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zmień `EventLog::write()` na `bool`**

Zwracaj `false`, gdy `$wpdb->insert()` zwróci `false`, i `true` w przeciwnym razie. Zachowanie przy przekroczeniu `ACTION_MAX_LENGTH` i przy niedostępnej tabeli zostaje takie, jakie jest; zmienia się tylko to, że wywołujący może je zobaczyć.

- [ ] **Krok 4: `Decision` reaguje**

W `approve()`, `reject()` i `amend_reason()` wynik zapisu logu decyduje o kodzie zwracanym. Mail wyślij **mimo** porażki logu, zgodnie z rozstrzygnięciem wyżej.

- [ ] **Krok 5: Ekran liczy i mówi**

`RESULT_LOG_FAILED` dostaje własny kubełek i własny komunikat: rozstrzygnięcie weszło, ale nie zostało odnotowane w logu. Klasa `notice-warning`, nie `notice-success`.

- [ ] **Krok 6: Uruchom testy i potwierdź przejście**

- [ ] **Krok 7: Bramki i commit**

```bash
git add src/Persistence/EventLog.php src/Account/Decision.php src/Admin/ApplicationsScreen.php tests/Integration/Account/DecisionTest.php tests/Integration/Admin/ApplicationsScreenTest.php
git commit -m "fix: zapis do logu zdarzen zglasza porazke, rozstrzygniecie jej nie przemilcza"
```

---

### Zadanie 3: Domena anonimizacji kontekstu logu

**Pliki:**
- Utwórz: `src/Domain/Privacy/ContextFilter.php`
- Test: `tests/Unit/Privacy/ContextFilterTest.php`

**Po co osobna klasa.** Wiersz logu zostaje po żądaniu usunięcia danych, bo wykazuje decyzję. Reguła „co w nim wolno zostawić" jest jedną decyzją i musi dać się przetestować bez bazy. Kontekst wierszy tej wtyczki niesie dziś: `ip`, `reason`, `old`/`new` (numer uprawnień), `profession`, `version` (skrót regulaminu), `status`, `previous_status`, `target_status`, `credential_warning`.

**Interfejsy:**
- Produkuje:
  - `ContextFilter::KEEP = array( 'status', 'previous_status', 'target_status' )`
  - `ContextFilter::REMOVED_KEY = 'removed'`
  - `ContextFilter::anonymize( array<string, mixed> $context ): array<string, mixed>`

**Reguła:** zostają wyłącznie klucze z `KEEP` — stany konta, czyli to, czego dowodzi wiersz. Każdy inny klucz **odpada**, a pod `REMOVED_KEY` wchodzi posortowana lista nazw kluczy, które usunięto. Nazwy kluczy nie są danymi osobowymi, a audyt musi widzieć, że coś w tym wierszu było.

**Dlaczego `profession` i `version` odpadają:** profesja to dana zawodowa o osobie, a skrót wersji regulaminu wiąże konto z dokumentem; żadna z nich nie jest potrzebna, by wykazać, kto i kiedy podjął decyzję. `credential_warning` odpada, bo mówi o numerze uprawnień tej osoby.

- [ ] **Krok 1: Napisz testy jednostkowe**

```php
public function test_zostaja_wylacznie_stany_konta(): void {
	$out = ContextFilter::anonymize(
		array(
			'status'          => 'approved',
			'previous_status' => 'pending',
			'ip'              => '203.0.113.7',
			'reason'          => 'numer nieprawidłowy',
			'old'             => '5425740',
			'new'             => '5425741',
			'profession'      => 'lekarz',
		)
	);

	$this->assertSame( 'approved', $out['status'] );
	$this->assertSame( 'pending', $out['previous_status'] );
	$this->assertArrayNotHasKey( 'ip', $out );
	$this->assertArrayNotHasKey( 'reason', $out );
	$this->assertArrayNotHasKey( 'old', $out );
	$this->assertArrayNotHasKey( 'new', $out );
	$this->assertArrayNotHasKey( 'profession', $out );
	$this->assertSame( array( 'ip', 'new', 'old', 'profession', 'reason' ), $out[ ContextFilter::REMOVED_KEY ] );
}

public function test_kontekst_bez_danych_osobowych_nie_dostaje_listy_usunietych(): void
// array( 'status' => 'approved' ) → brak klucza REMOVED_KEY: puste pole mówiłoby, że coś usunięto.

public function test_pusty_kontekst_zostaje_pusty(): void

public function test_klucz_o_nazwie_removed_w_wejsciu_nie_podszywa_sie_pod_liste(): void
// Kontekst z własnym kluczem 'removed' → trafia na listę usuniętych, a nie nadpisuje jej.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**
- [ ] **Krok 3: Zaimplementuj `ContextFilter`**
- [ ] **Krok 4: Uruchom testy i potwierdź przejście**
- [ ] **Krok 5: Bramki (w tym `DomainPurityTest`) i commit**

```bash
git add src/Domain/Privacy tests/Unit/Privacy
git commit -m "feat: regula anonimizacji kontekstu wiersza logu zdarzen"
```

---

### Zadanie 4: Log zdarzeń — czytanie i anonimizacja po podmiocie

**Pliki:**
- Modyfikuj: `src/Persistence/EventLog.php`
- Test: `tests/Integration/Persistence/EventLogTest.php`

**Interfejsy:**
- Konsumuje: `ContextFilter::anonymize()`, `Migrations::table( 'event_log' )`.
- Produkuje:
  - `EventLog::for_subject( int $userId, int $limit, int $offset ): array<int, array{id: int, created_at: string, action: string, actor_user_id: int, context: array<string, mixed>, anonymized: bool}>` — od najnowszego
  - `EventLog::anonymize_subject( int $userId ): int` — liczba wierszy zmienionych

**Co anonimizacja zmienia, a czego nie:** przepuszcza `context` przez `ContextFilter`, ustawia `anonymized = 1` i **nie rusza** `action`, `created_at`, `actor_user_id` ani `subject_user_id`. Identyfikator podmiotu zostaje, bo bez niego wiersz przestaje dowodzić czegokolwiek o konkretnym koncie, a sam identyfikator po usunięciu konta nie wskazuje już osoby.

**Idempotencja jest wymagana:** wiersz już zanonimizowany nie może przy powtórnym wywołaniu dostać listy usuniętych kluczy od nowa ani zostać policzony drugi raz. Powód: WordPress woła eraser w pętli (patrz Zadanie 8).

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_write_zwraca_prawde_i_wiersz_jest_czytelny_po_podmiocie(): void
public function test_for_subject_zwraca_od_najnowszego_i_stronicuje(): void
public function test_for_subject_nie_widzi_wierszy_innego_konta(): void
public function test_for_subject_odserializowuje_kontekst_do_tablicy(): void
// context w bazie jest tekstem; metoda ma zwrócić tablicę, nie napis JSON.

public function test_anonymize_subject_czysci_kontekst_zostawia_dowod_decyzji(): void
// Wiersz application_rejected z 'reason' i 'ip' → po anonimizacji: brak 'reason' i 'ip',
// lista usuniętych kluczy obecna, `action`, `created_at` i `actor_user_id` BEZ ZMIAN, anonymized = 1.

public function test_anonymize_subject_jest_idempotentne(): void
// Dwa wywołania: drugie zwraca 0 i nie zmienia wiersza. Asercja na liczbę zwracaną ORAZ na treść wiersza.

public function test_anonymize_subject_nie_rusza_wierszy_innego_konta(): void
public function test_anonymize_subject_dla_konta_bez_wierszy_zwraca_zero(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**
- [ ] **Krok 3: Zaimplementuj obie metody**

Idempotencję oprzyj na kolumnie `anonymized` — `UPDATE … WHERE subject_user_id = %d AND anonymized = 0`, a liczbę zwracaną weź z wyniku zapytania. Kontekst każdego wiersza przepuść przez `ContextFilter` **w PHP**, nie w SQL.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**
- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Persistence/EventLog.php tests/Integration/Persistence/EventLogTest.php
git commit -m "feat: log zdarzen czyta sie po podmiocie i anonimizuje bez utraty dowodu decyzji"
```

---

### Zadanie 5: Kolejka maili — czytanie i anonimizacja po koncie

**Pliki:**
- Modyfikuj: `src/Persistence/MailQueueRepository.php`
- Test: `tests/Integration/Persistence/MailQueueRepositoryTest.php`

**Interfejsy:**
- Produkuje:
  - `MailQueueRepository::ANONYMIZED_PLACEHOLDER = '(dane usunięte na żądanie)'`
  - `MailQueueRepository::for_user( int $userId, int $limit, int $offset ): array<int, array{id: int, kind: string, recipient: string, subject: string, status: string, created_at: string, sent_at: ?string, anonymized: bool}>` — od najnowszego, **bez kolumny `body`**
  - `MailQueueRepository::anonymize_user( int $userId ): int`

**Dlaczego `for_user()` nie zwraca `body`:** metoda powstaje dla eksportu danych osobowych, a treść maila nie wychodzi w eksporcie w ogóle (uzasadnienie w Zadaniu 7). Kolumna, której nikt nie czyta, nie ma po co być w sygnaturze — a gdyby była, pierwszy nieuważny wywołujący wydałby żywy token.

**Co anonimizacja nadpisuje:** `recipient`, `subject` **i** `body` wchodzą na `ANONYMIZED_PLACEHOLDER`, `anonymized` na `1`. Bez zmian zostają `kind`, `created_at`, `sent_at`, `status`, `attempts` — w logu wysyłek wartością jest to, że mail wyszedł, nie jego treść. Pozycja z backlogu mówi wprost: samo postawienie flagi nie anonimizuje niczego, bo dane zostają w wierszu.

**Idempotencja jak w Zadaniu 4**, z tego samego powodu.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_for_user_zwraca_od_najnowszego_i_stronicuje(): void
public function test_for_user_nie_zwraca_tresci_maila(): void
// Asercja: żaden zwrócony wiersz nie ma klucza 'body'. To jest ta asercja, która pilnuje Review Focus 2.
public function test_for_user_nie_widzi_wierszy_innego_konta(): void

public function test_anonymize_user_nadpisuje_adresata_temat_i_tresc(): void
// Wiersz rodzaju confirmation z tokenem w treści → po anonimizacji żadna z trzech kolumn
// nie zawiera tokenu, a `kind`, `sent_at` i `status` są bez zmian. Asercja na SUROWY wiersz z bazy.

public function test_anonymize_user_jest_idempotentne(): void
public function test_anonymize_user_dla_konta_bez_wierszy_zwraca_zero(): void
public function test_anonymizacja_nie_psuje_odpornosci_na_duplikaty(): void
// `dedupe_key` zostaje nietknięty: nadpisanie go pozwoliłoby wysłać ten sam mail drugi raz.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**
- [ ] **Krok 3: Zaimplementuj obie metody**
- [ ] **Krok 4: Uruchom testy i potwierdź przejście**
- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Persistence/MailQueueRepository.php tests/Integration/Persistence/MailQueueRepositoryTest.php
git commit -m "feat: kolejka maili czyta sie po koncie i anonimizuje nadpisujac adresata, temat i tresc"
```

---

### Zadanie 6: Eksport wniosków do CSV

**Pliki:**
- Utwórz: `src/Export/UserCsvExport.php`
- Utwórz: `src/Admin/ExportHandler.php`
- Modyfikuj: `src/Admin/ApplicationsScreen.php` (przycisk w formularzu listy)
- Modyfikuj: `src/Plugin.php` (rejestracja uchwytu)
- Test: `tests/Integration/Export/UserCsvExportTest.php`
- Test: `tests/Integration/Admin/ExportHandlerTest.php`
- Test: `tests/Integration/WiringTest.php`

**Interfejsy:**
- Konsumuje: `CsvDocument` (**nie** `CsvCell` — neutralizację robi dokument w `addRow()`; wywołanie `CsvCell::neutralize()` jeszcze raz w eksporcie dałoby dwa apostrofy przed wartością), `ApplicationRepository::{paginate, total}`, `ApplicationRow`, `CredentialVerdict::for()`, `VerdictLabels::text()`, `ProfessionRepository::get()`, `AcfFieldSource::fields()`, `UserMeta::{ACF_PREFIX, CONSENT_AT, TERMS_VERSION, REGISTRATION_IP}`, `Menu::{CAPABILITY, SLUG_APPLICATIONS}`, `Request::guard()`, `ApplicationsScreen::{ARG_STATUS, ARG_PROFESSION}`.
- Produkuje:
  - `UserCsvExport::BATCH = 200`
  - `UserCsvExport::for_filter( AccountStatus $status, string $professionKey ): string`
  - `ExportHandler::{ACTION = 'pusers_export_users', register(), handle()}`
  - `ExportHandler::filename( AccountStatus $status ): string` — `wnioski-<stan>-<data>.csv`

**Kolumny, w tej kolejności:** login, adres e-mail, nazwa wyświetlana, stan, profesja (etykieta albo surowy klucz, gdy profesji nie ma w ustawieniach), numer uprawnień, werdykt numeru (tekst z `VerdictLabels`), data rejestracji, znacznik zgody, wersja regulaminu, adres IP rejestracji, a potem **po jednej kolumnie na pole grupy ACF**, w kolejności z `AcfFieldSource::fields()`, z etykietą pola jako nagłówkiem.

**Rozstrzygnięcia:**

1. **Eksport honoruje filtr ekranu** — zakładkę i profesję, z których administrator kliknął. Pobranie zawsze całej bazy, gdy na ekranie widać jedną zakładkę, jest zaskoczeniem, a nie wygodą.
2. **Eksportowane są pola grupy ACF, nie tylko widoczne dla profesji.** Macierz rządzi tym, co pokazuje panel i co przyjmuje formularz; plik dla administratora ma pokazać **co jest zapisane**, bo inaczej dane przyjęte przed zmianą macierzy znikają bez śladu. Kolumny są te same dla wszystkich wierszy, więc konto bez danego pola ma komórkę pustą.
3. **Separator domyślny `fputcsv`, czyli przecinek, plus BOM** — tak jak we wzorcu z `event-registration`. Koszt: polski Excel przy podwójnym kliknięciu może potrzebować kreatora importu. Jednolitość z drugą wtyczką firmy jest warta więcej niż jedno kliknięcie, a zmiana separatora to jedna stała, gdy redakcja zgłosi, że przeszkadza.
4. **Pobieranie idzie przez `admin_post_`**, nie przez render ekranu: plik nie może mieć przed sobą ani bajtu wyjścia panelu.

- [ ] **Krok 1: Napisz testy integracyjne `UserCsvExport`**

```php
public function test_naglowek_ma_kolumny_stale_i_pola_acf_w_kolejnosci_grupy(): void
public function test_wiersz_niesie_dane_konta_i_wartosci_pol(): void
public function test_wartosc_pola_z_formula_jest_zneutralizowana(): void
// Pole o wartości '=2+2' → w pliku "'=2+2". Review Focus 3.
public function test_pole_wielowartosciowe_jest_jedna_komorka(): void
// Metadana z listą po przecinkach zostaje jedną komórką (sparsuj wyjście, policz komórki).
public function test_eksport_honoruje_zakladke_i_filtr_profesji(): void
public function test_profesja_usunieta_z_ustawien_nie_wywala_eksportu(): void
// Review Focus 5: wiersz jest, kolumna profesji niesie surowy klucz.
public function test_grupa_acf_niedostepna_daje_plik_z_samymi_kolumnami_stalymi(): void
// Review Focus 5: bez ACF eksport dalej działa, tylko bez kolumn pól.
public function test_konto_bez_zgody_i_bez_ip_ma_komorki_puste_nie_brakujace(): void
// Liczba komórek w wierszu równa liczbie kolumn nagłówka.
public function test_eksport_obejmuje_wszystkie_strony_wynikow(): void
// 250 kont przy BATCH = 200 → 250 wierszy danych. Bez tego testu pętla po stronach
// mogłaby po cichu urwać się na pierwszej partii.
```

- [ ] **Krok 2: Napisz testy integracyjne `ExportHandler`**

```php
public function test_zadanie_bez_nonce_jest_odrzucane(): void
public function test_zadanie_bez_uprawnienia_jest_odrzucane(): void
// Rola editor → odmowa. Obie asercje na WPDieException, nie na treść pliku.
public function test_zadanie_przez_get_jest_odrzucane(): void
public function test_odpowiedz_ma_naglowki_pobierania_i_tresc_csv(): void
// Przechwyć wyjście; asercja na BOM na początku i na nazwę pliku w nagłówku.
public function test_nazwa_pliku_niesie_stan_i_date(): void
```

- [ ] **Krok 3: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 4: Zaimplementuj `UserCsvExport`**

Pętla po stronach z `ApplicationRepository::paginate()` w partiach po `BATCH`, aż strona wróci krótsza niż `BATCH`.

- [ ] **Krok 5: Zaimplementuj `ExportHandler`**

`Request::guard( self::ACTION )`, potem sprawdzenie metody POST z `wp_die( …, 405 )`, potem nagłówki (`Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename=…`), potem treść i `exit`. Nagłówki wypisuj przez `header()`; nazwę pliku przez `sanitize_file_name()`.

- [ ] **Krok 6: Przycisk na ekranie i rejestracja uchwytu**

Przycisk „Pobierz CSV" w formularzu listy, niosący aktualne `ARG_STATUS` i `ARG_PROFESSION` przez `ApplicationsScreen::render_return_fields()`. `ExportHandler::register()` wisi tam, gdzie `ApplicationsScreen` — **poza** bramką zależności ACF, z tego samego powodu: eksport jest potrzebny także wtedy, gdy ACF leży.

- [ ] **Krok 7: Uruchom testy i potwierdź przejście**
- [ ] **Krok 8: Bramki i commit**

```bash
git add src/Export src/Admin/ExportHandler.php src/Admin/ApplicationsScreen.php src/Plugin.php tests/Integration/Export tests/Integration/Admin/ExportHandlerTest.php tests/Integration/WiringTest.php
git commit -m "feat: eksport wnioskow do CSV z neutralizacja formul, za nonce i uprawnieniem"
```

---

### Zadanie 7: Eksporter danych osobowych

**Pliki:**
- Utwórz: `src/Privacy/PrivacyProvider.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Privacy/PrivacyProviderExportTest.php`
- Test: `tests/Integration/WiringTest.php`

**Interfejsy:**
- Konsumuje: `EventLog::for_subject()`, `MailQueueRepository::for_user()`, `UserMeta::*`, `AcfFieldSource::fields()`, `ProfessionRepository::get()`, `CredentialVerdict::for()`.
- Produkuje:
  - `PrivacyProvider::SLUG = 'portal-users'`
  - `PrivacyProvider::PER_PAGE = 50`
  - `PrivacyProvider::{register(), register_exporter( array $exporters ): array}`
  - `PrivacyProvider::export( string $email, int $page = 1 ): array{data: array<int, array<string, mixed>>, done: bool}`

**Pięć grup w eksporcie:** konto (stan, profesja, numer uprawnień z werdyktem, data rejestracji, adres IP rejestracji), zgoda (znacznik czasu i skrót wersji regulaminu), pola zgłoszenia (wszystkie metadane z przedrostkiem, z etykietami tam, gdzie grupa ACF je zna), log zdarzeń (po `PER_PAGE`), maile (po `PER_PAGE`).

**Rozstrzygnięcie, które backlog nazwał pytaniem otwartym: treść maila NIE wychodzi w eksporcie.** Eksport niesie o każdym mailu rodzaj, adresata, temat, status i czas wysłania — nie `body`. Trzy powody, w tej kolejności:

1. Snapshot rodzaju `confirmation` zawiera **żywy token potwierdzenia adresu**, a `password_reset` **klucz resetu hasła z rdzenia**. Plik eksportu powstaje na dysku, bywa pobierany przez pomyłkę na cudzym ekranie i trafia do kopii zapasowych; wydawanie w nim działających linków do przejęcia konta jest szkodą, której żądanie dostępu nie wymaga.
2. Treść maila do redakcji **nie niesie o wnioskodawcy żadnego faktu**, którego eksport nie wydaje już z metadanych konta: pola zgłoszenia, profesja, numer i werdykt są w grupie „konto" i „pola zgłoszenia".
3. To, co w logu wysyłek jest wartością, to fakt wysłania — rodzaj, czas i adresat. Backlog mówi to samo o anonimizacji.

**Koszt, przyjęty świadomie:** jeżeli redakcja odrzuciła konto, a potem powód poprawiła, treść **pierwszego** maila niosła powód, którego nie ma już ani w metadanej, ani w eksporcie. Temat maila w eksporcie zostaje, więc ślad po wiadomości jest; samego starego powodu żądający nie dostanie. Przy żądaniu, które wprost o to pyta, administrator ma log zdarzeń — tam wszystkie powody są.

**Stronicowanie:** grupy stałe (konto, zgoda, pola) wychodzą wyłącznie na stronie pierwszej; log i maile stronicują się po `PER_PAGE` z offsetem `( $page - 1 ) * PER_PAGE`. `done` jest prawdą, gdy **obie** kolekcje zwróciły mniej niż `PER_PAGE`.

**Adres bez konta:** `get_user_by( 'email', … )` zwracające `false` daje `array( 'data' => array(), 'done' => true )`. Bez wyjątku i bez zapytań.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_eksporter_jest_zarejestrowany_pod_wlasnym_kluczem(): void
// Filtr wp_privacy_personal_data_exporters niesie SLUG z nazwą i callbackiem.

public function test_eksport_niesie_stan_profesje_i_numer_z_werdyktem(): void
public function test_eksport_niesie_znacznik_zgody_i_wersje_regulaminu(): void
public function test_eksport_niesie_wszystkie_pola_zgloszenia_takze_ukryte_dla_profesji(): void
// Pole przestawione na „ukryte" po zgłoszeniu dalej wychodzi: żądanie dostępu dotyczy tego,
// co zapisane, nie tego, co panel dziś pokazuje.

public function test_eksport_niesie_wiersze_logu_zdarzen(): void
public function test_eksport_niesie_metadane_maili_bez_tresci(): void
// Wiersz confirmation z tokenem 'TOKEN-ZYWY-48H' w treści → cały zrzut eksportu
// (serializowany do napisu) NIE zawiera tego napisu, a zawiera rodzaj i temat.
// To jest asercja pilnująca Review Focus 2.

public function test_adres_bez_konta_daje_puste_dane_i_done(): void
public function test_stronicowanie_konczy_sie_done_gdy_obie_kolekcje_sie_wyczerpia(): void
// PER_PAGE + 1 wierszy logu: strona 1 daje done === false, strona 2 done === true
// i wiersz, którego nie było na stronie pierwszej. Asercja na identyfikatory wierszy, nie na liczbę.
public function test_grupy_stale_wychodza_tylko_na_pierwszej_stronie(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**
- [ ] **Krok 3: Zaimplementuj `PrivacyProvider::export()` i rejestrację**

Każda grupa to wpis z `group_id`, `group_label`, `item_id` i `data` jako lista par `name`/`value`, zgodnie z kontraktem rdzenia. Etykiety grup przez `__()`.

- [ ] **Krok 4: Zarejestruj w `Plugin::boot()`**

`PrivacyProvider::register()` **poza** bramką zależności ACF: żądanie RODO ma termin ustawowy i nie może czekać na naprawę wtyczki pól. Grupa pól zgłoszenia renderuje się wtedy bez etykiet, z nazwami metadanych — i tak jest lepiej niż nie wydać danych wcale. Komentarz w kodzie ma to mówić.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**
- [ ] **Krok 6: Bramki i commit**

```bash
git add src/Privacy/PrivacyProvider.php src/Plugin.php tests/Integration/Privacy tests/Integration/WiringTest.php
git commit -m "feat: eksporter danych osobowych - konto, zgoda, pola, log i maile bez tresci"
```

---

### Zadanie 8: Eraser danych osobowych

**Pliki:**
- Modyfikuj: `src/Privacy/PrivacyProvider.php`
- Modyfikuj: `docs/superpowers/backlog.md`
- Test: `tests/Integration/Privacy/PrivacyProviderEraseTest.php`

**Interfejsy:**
- Konsumuje: `EventLog::anonymize_subject()`, `MailQueueRepository::anonymize_user()`, `UserMeta::*`, `AcfFieldSource::fields()`.
- Produkuje:
  - `PrivacyProvider::{register_eraser( array $erasers ): array}`
  - `PrivacyProvider::erase( string $email, int $page = 1 ): array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}`
  - `PrivacyProvider::ERASE_META = array( UserMeta::REGISTRATION_IP, UserMeta::REJECTION_REASON, UserMeta::CONFIRM_HASH, UserMeta::CONFIRM_EXPIRES, UserMeta::PASSWORD_TICKET_HASH, UserMeta::PASSWORD_TICKET_EXPIRES )`
  - `PrivacyProvider::RETAIN_META = array( UserMeta::STATUS, UserMeta::PROFESSION, UserMeta::TERMS_VERSION, UserMeta::CONSENT_AT )`

**Co eraser usuwa:** metadane z `ERASE_META` oraz **wszystkie** wartości pól zgłoszenia (metadane z przedrostkiem `UserMeta::ACF_PREFIX`). Dodatkowo anonimizuje log zdarzeń i kolejkę maili.

**Co eraser ZOSTAWIA, i dlaczego każde osobno:**

- **`UserMeta::STATUS` — usunięcie tej metadanej WPUŚCIŁOBY konto na portal.** Brama logowania przepuszcza konta bez zapisanego stanu, bo tak zdecydowano, żeby nie zamknąć poza portalem administratora dodanego ręcznie przez „Dodaj użytkownika". Skasowanie stanu przy żądaniu RODO zmieniłoby konto odrzucone albo oczekujące w konto, które się loguje. To najpoważniejsza pułapka tego zadania.
- **`UserMeta::PROFESSION`** — bez profesji konto przestaje być wnioskiem: nie renderuje się na liście, a macierz pól jest fail-closed, więc panel nie pokaże o nim nic. Administrator straciłby każdy ślad, że takie konto istnieje.
- **`UserMeta::TERMS_VERSION` i `UserMeta::CONSENT_AT`** — specyfikacja mówi wprost: „Zapisy zgód zostają na podstawie obowiązku wykazania zgody".

**Dlatego `items_retained` jest prawdą zawsze, gdy konto istnieje**, a `messages` niesie jedno zdanie: co zostało i że pełne usunięcie konta to skasowanie użytkownika, czyli osobna operacja administratora. Eraser, który milczy o tym, co zachował, kłamie przez przemilczenie.

**Pułapka stronicowania, wprost z Review Focus 4:** WordPress woła `erase()` w pętli, aż dostanie `done === true`. Anonimizacja **usuwa wiersze ze zbioru dopasowań**, więc offset **zostaje na zerze** — przesuwanie go o `PER_PAGE` pomijałoby resztę wierszy przy kontach z więcej niż `PER_PAGE` zdarzeniami. `$page` przyjmujemy, bo rdzeń go podaje, i **nie używamy** go do offsetu. `done` jest prawdą, gdy liczba wierszy zanonimizowanych w tym przebiegu jest mniejsza niż `PER_PAGE`.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_eraser_jest_zarejestrowany_pod_wlasnym_kluczem(): void

public function test_eraser_NIE_usuwa_stanu_konta(): void
// Konto `rejected` po erase(): metadana stanu DALEJ istnieje i DALEJ ma wartość 'rejected'.
// Asercja dodatkowa: AccountStatusRepository::get() nie zwraca null — bo null znaczy
// „konto bez stanu", czyli wpuszczane przez bramę logowania. Review Focus 1.

public function test_eraser_usuwa_ip_powod_odrzucenia_i_sekrety_jednorazowe(): void
public function test_eraser_usuwa_wszystkie_wartosci_pol_zgloszenia(): void
// Także pole dziś ukryte dla profesji: dane są danymi niezależnie od macierzy.
public function test_eraser_zostawia_zgode_i_profesje_i_mowi_o_tym(): void
// items_retained === true oraz komunikat niepusty. Asercja na OBIE rzeczy.
public function test_eraser_anonimizuje_log_zdarzen_i_kolejke(): void
// Po erase(): wiersz logu bez 'reason', wiersz kolejki bez treści, oba z anonymized = 1.

public function test_eraser_konczy_petle_a_nie_kreci_sie_bez_konca(): void
// PER_PAGE + 5 wierszy logu. Wołaj erase() w pętli jak rdzeń, maksymalnie 10 razy,
// i asertuj: pętla kończy się na done === true po co najwyżej dwóch przebiegach,
// a po niej ZERO wierszy tego konta ma anonymized = 0. Review Focus 4.

public function test_eraser_nie_rusza_danych_innego_konta(): void
public function test_adres_bez_konta_daje_done_i_zero_zmian(): void
// items_removed === false, items_retained === false, done === true. Review Focus 5.
public function test_powtorzony_eraser_nie_zglasza_usuniecia_drugi_raz(): void
// Drugie wywołanie: items_removed === false (nie było czego usunąć), done === true.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**
- [ ] **Krok 3: Zaimplementuj `erase()` i rejestrację erasera**
- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Zaktualizuj backlog**

Trzy rzeczy:
1. **Wykreśl z sekcji „Plan 7" to, co ten plan zrobił**, i zostaw to, czego nie: pozycję o retencji wiersza kolejki z żywym sekretem (decyzja właściciela) oraz pozycję o backfillu stanów, która przechodzi do planu importu.
2. **Przenumeruj:** sekcja „Plan 8 — Aktualizacje i release" staje się „Plan 9", a import z backfillem to Plan 8. Jedno zdanie na początku obu sekcji, skąd ta zmiana.
3. **Dopisz pozycję dla planu importu:** eksport CSV i eksporter danych osobowych czytają pola z grupy ACF; import, który wpisze wartości pod metadane spoza grupy, będzie dla obu niewidoczny.

- [ ] **Krok 6: Bramki i commit**

```bash
git add src/Privacy/PrivacyProvider.php docs/superpowers/backlog.md tests/Integration/Privacy/PrivacyProviderEraseTest.php
git commit -m "feat: eraser danych osobowych - usuwa pola i sekrety, zostawia stan konta i zgode"
```

---

## Czego ten plan świadomie nie rozstrzyga

- **Retencja wiersza kolejki z żywym sekretem** — czy maile rodzaju `confirmation` i `password_reset` mają okno liczone w dniach zamiast w 90 dniach, albo czy po wysłaniu ma być czyszczona sama kolumna `body`. Decyzja właściciela, zapisana w backlogu od Planu 5. Ten plan jej nie przesądza: nie wydaje treści w eksporcie i nadpisuje ją przy żądaniu usunięcia, ale wiersz niewzruszony żadnym żądaniem dalej leży 90 dni.
- **Sekcja polityki prywatności** (`wp_add_privacy_policy_content`) — specyfikacja jej nie wymaga.
- **Usunięcie konta użytkownika** przy żądaniu RODO. Eraser czyści dane wtyczki; skasowanie konta jest operacją administratora i rdzeń ma do niej własne narzędzie.
- **Anonimizacja adresu IP zamiast usunięcia** (`wp_privacy_anonymize_ip()`). Adres rejestracji usuwamy w całości: do wykazania decyzji nie służy, a zanonimizowany i tak nie odpowiada na żadne pytanie redakcji.
