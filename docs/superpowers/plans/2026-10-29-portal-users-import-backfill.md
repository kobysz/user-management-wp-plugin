# Plan 8 — import z poprzedniej wtyczki i backfill stanów

> **Dla wykonawców agentowych:** WYMAGANA PODUMIEJĘTNOŚĆ: użyj `superpowers:subagent-driven-development` (zalecane) albo `superpowers:executing-plans`, żeby wykonać ten plan zadanie po zadaniu. Kroki mają składnię checkboxów (`- [ ]`).

**Cel:** generyczne narzędzie importu w panelu, które skanuje istniejące klucze `user_meta`, pozwala zmapować je na pola wtyczki, profesję i stan konta, liczy cały import w trybie próbnym i dopiero po raporcie zapisuje — plus backfill stanów dla kont istniejących na portalu przed wdrożeniem.

**Architektura:** cała logika przeliczania jednego konta siedzi w domenie bez WordPressa (`ImportMapping` → `ImportPlanner` → `AccountChange` → `ImportReport`), więc da się ją przetestować na gołych tablicach. Warstwa infrastruktury dokłada trzy rzeczy, których domena nie zna: skaner kluczy `user_meta` (`MetaKeyScanner`), pamięć mapowania w opcji (`ImportMappingRepository`) i przebieg po kontach z zapisem (`ImportRunner`). Panel (`ImportScreen`) jest cienki: trzy akcje `admin_post_` z tą samą bramką co reszta ekranów, a przycisk zapisu jest zamknięty bramką „najpierw raport dla tego mapowania".

**Stos:** PHP 8.1, WordPress 6.5+, ACF Pro jako zależność twarda, PHPUnit (suita `unit` bez WordPressa i `integration` w kontenerze), PHPStan level max, PHPCS (WordPress Coding Standards).

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`, sekcje „Import z poprzedniej wtyczki", „Instalacja na działającym portalu" i „Przebieg rejestracji" (akapit o kontach bez stanu).

**Zobowiązania wcześniejszych planów:** `docs/superpowers/backlog.md`, sekcja „Plan 8 — Import i backfill stanów"; `docs/superpowers/decyzje-plan-7.md` 1.5 i 8.

## Global Constraints

- WordPress **6.5+**, PHP **8.1+**. `declare( strict_types=1 );` w każdym pliku.
- Wszystkie teksty widoczne dla człowieka przez funkcje tłumaczeń z domeną **`portal-users`**. Język bazowy polski.
- `src/Domain/**` nie wolno wołać niczego z WordPressa ani słowa „WordPress" użyć w komentarzu — pilnuje tego `DomainPurityTest`, który czyta też komentarze.
- Każdy ekran i każda akcja zapisu przechodzi `Request::guard( <ACTION> )` (uprawnienie `manage_options` → nonce → POST), tak jak `MatrixScreen` i `ExportHandler`.
- `$wpdb` wyłącznie przez `prepare()` z `%i` dla nazw tabel; nazwy tabel wtyczki przez `Migrations::table()`.
- `WP_User_Query` **nigdy** z `'number' => 0` — to znaczy „bez limitu" i hydratuje wszystkie konta. Do liczenia: `fields => 'ID'`, `number => 1`, potem `get_total()`.
- Bramki, wszystkie cztery, przed każdym commitem:
  - `vendor/bin/phpunit`
  - `MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpunit -c phpunit-integration.xml.dist`
  - `vendor/bin/phpstan analyse --memory-limit=2G`
  - `vendor/bin/phpcs -q`
- Dwa przebiegi suity integracyjnej jednocześnie dzielą jedną bazę i dają fałszywe błędy — nigdy równolegle.
- Każdy commit kończy się linią `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Rozstrzygnięcie o backfillu — odstępstwo od jednej linii specyfikacji

Specyfikacja mówi w dwóch miejscach dwie rzeczy, których **nie da się pogodzić przy aktywacji**:

- „Przy aktywacji wtyczka nadaje stan `zatwierdzony` kontom, które poprzednia wtyczka członkowska uznawała za zatwierdzone."
- „Konta, których poprzednia wtyczka nie zatwierdziła, **nie dostają** stanu `zatwierdzony`."

W chwili aktywacji wtyczka nie wie, który klucz `user_meta` trzymał status poprzedniej wtyczki — mapowanie powstaje dopiero w narzędziu importu, czyli **po** aktywacji. Nadanie `zatwierdzony` wszystkim kontom przy aktywacji złamałoby drugie zdanie i dałoby dostęp do portalu kontom, które stara wtyczka odrzuciła albo trzymała w poczekalni.

**Rozstrzygnięcie:** aktywacja nie nadaje żadnego stanu. Zapisuje znacznik wdrożenia (`pusers_deployed_at`) i tyle. Nikt nie traci dostępu, bo brama logowania z Planu 4 celowo wpuszcza konta bez stanu (`LoginGate::decide( null )`) — to już działa i jest przetestowane. Backfill jest **świadomym kliknięciem w panelu**, w dwóch postaciach:

1. **Przez import** — administrator mapuje klucz statusu starej wtyczki na nasze stany i stan wchodzi z danymi, tak jak chce specyfikacja.
2. **Przyciskiem awaryjnym** — „nadaj `zatwierdzony` wszystkim kontom istniejącym przed wdrożeniem", gdy stara wtyczka nie trzymała statusu w metadanych albo administrator świadomie chce zatwierdzić wszystkich. Jedno kliknięcie, z liczbą kont w treści przycisku i wpisem w logu zdarzeń.

**Do decyzji właściciela, nieblokujące:** czy ten przycisk ma być dostępny bezterminowo, czy tylko do pierwszego importu. Plan daje bezterminowo, bo portal może importować w dwóch rzutach.

## Review Focus

Pięć rzeczy, których specyfikacja nie opisuje, a które uderzą w człowieka. Każda ma swój test w zadaniu, które jest jej właścicielem.

1. **Mapowanie statusu na `email_unconfirmed` oddaje zaimportowane konta cronowi, który je USUWA** po 7 dniach (`PurgeUnconfirmed`). Import ma nie być narzędziem kasowania kont: mapowanie przyjmuje tylko `pending`, `approved`, `rejected` (Zadanie 2).
2. **`update_user_meta()` zwraca `false` także wtedy, gdy wartość jest identyczna** — sprawdzone w `wp-includes/meta.php`, w `update_metadata()`: przy jednym istniejącym wierszu równym nowej wartości funkcja zwraca `false` **przed** zapisem. Raport, który tego nie rozróżnia, pokaże „0 zmienionych" przy udanym imporcie albo „zaimportowano" przy padniętym zapisie (Zadanie 6).
3. **Administrator poprawia mapowanie po raporcie i klika „Importuj"** — zapis szedłby według mapowania, którego nikt nie przeliczył. Raport jest wiązany odciskiem mapowania i przedawnia się (Zadanie 8).
4. **Stara wtyczka trzyma w metadanej tablicę albo wartość serializowaną** (zgody, listy). `(string) array` daje ostrzeżenie PHP i zapisuje napis `Array` jako numer PWZ. Wartości nieskalarne są pomijane i policzone (Zadanie 3).
5. **Import nie może blokować się na niepoprawnym numerze uprawnień** — walidacja jest w specyfikacji miękka, weryfikuje człowiek. Numer wchodzi znormalizowany, z adnotacją w raporcie, a nie jest odrzucany (Zadanie 3).

---

## Struktura plików

**Domena (bez WordPressa):**
- `src/Domain/Import/MetaKeyStat.php` — jeden wiersz skanu: nazwa klucza, liczba kont, przykładowe wartości (obcięte).
- `src/Domain/Import/ImportMapping.php` — konfiguracja mapowania plus jej odcisk; jedyne miejsce, które wie, jakie stany wolno zmapować.
- `src/Domain/Import/AccountChange.php` — co import zrobi z jednym kontem i jakie zastrzeżenia ma do jego danych.
- `src/Domain/Import/ImportReport.php` — suma zastrzeżeń i liczników po całym przebiegu.
- `src/Domain/Import/ImportPlanner.php` — przeliczenie jednego konta: profesja, pola, numer uprawnień, stan.

**Infrastruktura:**
- `src/Persistence/MetaKeyScanner.php` — skan kluczy `user_meta` z licznikami i próbkami.
- `src/Persistence/ImportMappingRepository.php` — mapowanie w opcji, przycięte do aktualnych pól i profesji.
- `src/Import/ImportRunner.php` — przebieg po kontach: tryb próbny i zapis.
- `src/Account/Backfill.php` — znacznik wdrożenia, liczba kont bez stanu, nadanie `zatwierdzony`.

**Panel:**
- `src/Admin/ImportScreen.php` — podstrona narzędzia: tabela skanu, formularz mapowania, raport, zapis, przycisk backfillu.
- `src/Admin/BackfillNotice.php` — ostrzeżenie na ekranach wtyczki, dopóki są konta bez stanu.

**Modyfikowane:**
- `src/Persistence/OptionStore.php` — trzy nowe stałe opcji i wpisy w `all()`.
- `src/Admin/Menu.php` — `SLUG_IMPORT` i podstrona.
- `src/Plugin.php` — rejestracja `ImportScreen` i `BackfillNotice`.
- `portal-users.php` — `register_activation_hook` dla znacznika wdrożenia.
- `docs/superpowers/backlog.md` — wykreślenie dostarczonych pozycji Planu 8.

---

## Zadanie 1: Wartości domenowe skanu i raportu

**Pliki:**
- Utwórz: `src/Domain/Import/MetaKeyStat.php`
- Utwórz: `src/Domain/Import/AccountChange.php`
- Utwórz: `src/Domain/Import/ImportReport.php`
- Test: `tests/Unit/Import/MetaKeyStatTest.php`
- Test: `tests/Unit/Import/AccountChangeTest.php`
- Test: `tests/Unit/Import/ImportReportTest.php`

**Interfejsy:**
- Produkuje: `MetaKeyStat::fromRow( string $key, int $accounts, array $samples ): self` z publicznymi `readonly` `$key`, `$accounts`, `$samples` oraz stałymi `SAMPLE_LIMIT = 3` i `SAMPLE_MAX_LENGTH = 60`.
- Produkuje: `AccountChange::__construct( int $userId, array $fields, ?string $professionKey, ?AccountStatus $status, array $issues )` z `readonly` właściwościami o tych nazwach, metodami `hasWrites(): bool` i `hasIssue( string $code ): bool` oraz stałymi kodów: `ISSUE_PROFESSION_UNMAPPED = 'profession_unmapped'`, `ISSUE_PROFESSION_MISSING = 'profession_missing'`, `ISSUE_CREDENTIAL_INVALID = 'credential_invalid'`, `ISSUE_STATUS_UNMAPPED = 'status_unmapped'`, `ISSUE_STATUS_MISSING = 'status_missing'`, `ISSUE_VALUE_UNUSABLE = 'value_unusable'`.
- Produkuje: `ImportReport::empty(): self`, `with( AccountChange $change ): self` (niemutujące), `scanned(): int`, `changed(): int`, `issueCount( string $code ): int`, `issueSample( string $code ): array`, `writeFailures(): int`, `withWriteFailure( int $userId ): self`, `toArray(): array`, stała `SAMPLE_LIMIT = 10`.
- Konsumuje: `PortalUsers\Domain\Account\AccountStatus`.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Unit/Import/MetaKeyStatTest.php
public function test_probki_sa_obcinane_do_limitu_i_do_dlugosci(): void
// fromRow( 'wpmem_reg', 12, array( 'a', 'b', 'c', 'd' ) ) → count( samples ) === 3
// wartość 80 znaków → długość 60 + '…' na końcu (mb_substr, nie substr: polskie znaki)
public function test_wartosc_wielolinijkowa_jest_splaszczana_do_jednej_linii(): void
// "ala\nma\tkota" → 'ala ma kota' (bez znaków sterujących, bo wiersz idzie do tabeli w panelu)
public function test_duplikaty_probek_sa_usuwane_a_kolejnosc_zachowana(): void

// tests/Unit/Import/AccountChangeTest.php
public function test_brak_zapisow_gdy_nie_ma_ani_pol_ani_profesji_ani_stanu(): void
// hasWrites() === false dla ( 7, array(), null, null, array( ISSUE_PROFESSION_MISSING ) )
public function test_sam_stan_liczy_sie_jako_zapis(): void
// hasWrites() === true dla ( 7, array(), null, AccountStatus::Approved, array() )
public function test_kody_zastrzezen_sa_posortowane_i_bez_duplikatow(): void

// tests/Unit/Import/ImportReportTest.php
public function test_liczy_przeskanowane_i_zmienione_osobno(): void
// trzy konta, z czego jedno bez zapisów → scanned() === 3, changed() === 2
public function test_probka_zastrzezenia_urywa_sie_na_limicie_a_licznik_rosnie_dalej(): void
// 15 kont z ISSUE_PROFESSION_UNMAPPED → issueCount() === 15, count( issueSample() ) === 10
public function test_with_nie_zmienia_poprzedniego_raportu(): void
// $a = ImportReport::empty(); $b = $a->with( $change ); $a->scanned() === 0
public function test_porazka_zapisu_nie_liczy_sie_jako_zmiana(): void
// withWriteFailure( 7 ) → writeFailures() === 1, changed() bez zmiany
public function test_to_array_niesie_wszystkie_liczniki_i_probki(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

Uruchom: `vendor/bin/phpunit --filter 'MetaKeyStatTest|AccountChangeTest|ImportReportTest'`
Oczekiwane: FAIL, „Class not found".

- [ ] **Krok 3: Zaimplementuj trzy klasy**

`MetaKeyStat::fromRow()` normalizuje próbki: zamiana każdego ciągu znaków białych (w tym `\n`, `\t`) na jedną spację, `trim`, odrzucenie pustych, usunięcie duplikatów z zachowaniem kolejności, obcięcie `mb_substr` do `SAMPLE_MAX_LENGTH` z dopisanym `…`, obcięcie listy do `SAMPLE_LIMIT`.

`ImportReport` trzyma liczniki w prywatnych tablicach i zwraca nową instancję z każdego `with()` / `withWriteFailure()`.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Domain/Import tests/Unit/Import
git commit -m "feat: wartosci domenowe skanu metadanych i raportu importu"
```

---

## Zadanie 2: `ImportMapping` — konfiguracja mapowania i jej odcisk

**Pliki:**
- Utwórz: `src/Domain/Import/ImportMapping.php`
- Test: `tests/Unit/Import/ImportMappingTest.php`

**Interfejsy:**
- Produkuje: `ImportMapping::fromArray( array $raw ): self`, `toArray(): array`, `fields(): array` (klucz pola ACF → źródłowy klucz `user_meta`), `professionKey(): string`, `professionFor( string $sourceValue ): ?string`, `statusKey(): string`, `statusFor( string $sourceValue ): ?AccountStatus`, `fallback(): string`, `sourceKeys(): array`, `isEmpty(): bool`, `hash(): string`, `prunedTo( array $fieldKeys, array $professionKeys ): self`.
- Produkuje stałe: `FALLBACK_NONE = 'none'`, `FALLBACK_PENDING = 'pending'`, `FALLBACK_APPROVED = 'approved'`, `ALLOWED_STATUSES` (lista wartości `AccountStatus`, które mapowanie przyjmuje).
- Konsumuje: `AccountStatus` z Planu 1.

**Rozstrzygnięcie o dopuszczalnych stanach.** `ALLOWED_STATUSES` to **wyłącznie** `pending`, `approved`, `rejected`. `email_unconfirmed` jest wykluczony, bo `PurgeUnconfirmed` **usuwa** konta w tym stanie po skonfigurowanym okresie — mapowanie, które by go dopuszczało, zamieniłoby import w narzędzie kasowania kont, bez słowa o tym w raporcie. Wartość `email_unconfirmed` w mapowaniu jest przy wczytaniu **odrzucana**, nie poprawiana na inny stan.

**Rozstrzygnięcie o `fallback`.** `fallback` mówi, co zrobić z kontem, dla którego źródło nie ma użytecznej wartości statusu. `FALLBACK_NONE` zostawia konto bez stanu (brama wpuszcza, konto nie podlega regułom — stan wyjściowy portalu). `FALLBACK_PENDING` kieruje je na listę wniosków. `FALLBACK_APPROVED` zatwierdza. Nieznana wartość przy wczytaniu daje `FALLBACK_NONE`: najmniej robi.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Unit/Import/ImportMappingTest.php
public function test_mapowanie_na_stan_niepotwierdzonego_maila_jest_odrzucane(): void
// statusValues => array( 'new' => 'email_unconfirmed', 'ok' => 'approved' )
// statusFor( 'new' ) === null; statusFor( 'ok' ) === AccountStatus::Approved
// toArray() nie niesie pary 'new'
public function test_nieznany_kod_stanu_jest_odrzucany(): void
// 'cokolwiek' → statusFor() === null
public function test_nieznany_fallback_daje_none(): void
public function test_source_keys_sa_unikalne_i_posortowane(): void
// dwa pola na ten sam klucz źródłowy + klucz profesji + klucz statusu → jedna lista bez duplikatów
public function test_is_empty_gdy_nic_nie_zmapowano(): void
// pusta tablica → isEmpty() === true; samo pole → false
public function test_odcisk_zalezy_od_tresci_a_nie_od_kolejnosci_kluczy(): void
// dwa mapowania o tych samych parach w innej kolejności → hash() identyczny
// zmiana jednej wartości → hash() inny
public function test_pruned_to_usuwa_pola_i_profesje_ktorych_nie_ma(): void
// fields: 'pwz' => 'wpmem_pwz', 'nieistniejace' => 'x'; prunedTo( array( 'pwz' ), array( 'lekarz' ) )
// → fields() ma tylko 'pwz'; professionValues bez wartości celujących w usuniętą profesję
public function test_wartosci_zrodlowe_sa_porownywane_po_obcieciu_i_bez_wielkosci_liter(): void
// professionValues => array( 'Lekarz' => 'lekarz' ); professionFor( '  lekarz ' ) === 'lekarz'
public function test_to_array_i_from_array_robia_peina_runde(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `ImportMapping`**

`fromArray()` sanityzuje: odrzuca pary o kluczach lub wartościach nie-stringach, przepuszcza stany tylko z `ALLOWED_STATUSES`, normalizuje klucze wartości źródłowych przez `trim` + `mb_strtolower` (żeby `Lekarz` i `lekarz` z bazy trafiły w ten sam wpis). `hash()` to `sha1` z `wp_json_encode`-owalnej, posortowanej po kluczach postaci `toArray()` — kolejność w tablicy nie może zmieniać odcisku. Normalizacja i sortowanie są w domenie, więc bez funkcji WordPressa: użyj `json_encode` z `JSON_UNESCAPED_UNICODE`.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Domain/Import/ImportMapping.php tests/Unit/Import/ImportMappingTest.php
git commit -m "feat: konfiguracja mapowania importu z odciskiem i zamknieta lista stanow"
```

---

## Zadanie 3: `ImportPlanner` — przeliczenie jednego konta

**Pliki:**
- Utwórz: `src/Domain/Import/ImportPlanner.php`
- Test: `tests/Unit/Import/ImportPlannerTest.php`

**Interfejsy:**
- Produkuje: `ImportPlanner::__construct( ImportMapping $mapping, ProfessionCollection $professions, FieldMatrix $matrix, ValidatorRegistry $validators )` i `plan( int $userId, array $sourceMeta ): AccountChange`, gdzie `$sourceMeta` to `array<string, mixed>` — klucz źródłowy → wartość z bazy, jaka przyszła.
- Konsumuje: `ImportMapping` i `AccountChange` z Zadań 1-2; `ProfessionCollection::get( string $key ): ?Profession` i `Profession::{requiresCredential(): bool, credentialType: CredentialType, credentialField: string}` z Planu 2; `FieldMatrix::visibleFields( string $professionKey ): array` z Planu 2; `ValidatorRegistry::for( CredentialType $type ): ?CredentialValidator`, `CredentialValidator::validate( string $raw ): CredentialResult`, `CredentialResult::isValid(): bool` i `Normalizer::clean( string $raw ): string` z Planu 3. Brak walidatora dla rodzaju (`for()` zwraca `null`) znaczy „nie ma czego sprawdzić" — wartość wchodzi bez zastrzeżenia.

**Reguły, których sygnatura nie przesądza:**

1. **Wartość nieskalarna** (tablica, obiekt) jest pomijana, konto dostaje `ISSUE_VALUE_UNUSABLE`. Skalary idą przez `(string)`; `true` → `'1'`, `false` → `''`.
2. **Wartość pusta po `trim` nie jest zapisywana.** Import nie nadpisuje danych pustką — ta sama reguła, która w Planie 6 ratowała dane lekarza przed mostem ACF.
3. **Profesja:** `professionFor()` daje `null` dla wartości nieznanej → `ISSUE_PROFESSION_UNMAPPED`, `professionKey` zostaje `null`. Brak klucza albo wartość pusta → `ISSUE_PROFESSION_MISSING`. Wartość zmapowana na klucz, którego nie ma w `ProfessionCollection`, też daje `ISSUE_PROFESSION_UNMAPPED`.
4. **Przycięcie pól macierzą:** gdy profesja jest rozpoznana, zapisywane są tylko pola widoczne dla niej (`visibleFields()`) — macierz jest właścicielem tego, które pola profesja w ogóle ma. Gdy profesja jest nierozpoznana, zapisywane są **wszystkie** zmapowane pola: dane są prawdziwe, a profesję administrator poprawi później.
5. **Numer uprawnień:** gdy rozpoznana profesja wymaga numeru, wartość z jej `credentialField` przechodzi przez `Normalizer::clean()` i walidator; **zapisywana jest postać znormalizowana niezależnie od wyniku**, a wynik niepoprawny daje `ISSUE_CREDENTIAL_INVALID`. Walidacja jest miękka — weryfikuje człowiek.
6. **Stan:** `statusFor()` daje `null` dla wartości nieznanej → `ISSUE_STATUS_UNMAPPED` i `status` zostaje `null` (nigdy nie zgadujemy). Brak klucza albo wartość pusta → `ISSUE_STATUS_MISSING` i stan z `fallback()` (`FALLBACK_NONE` → `null`).

- [ ] **Krok 1: Napisz testy**

```php
// tests/Unit/Import/ImportPlannerTest.php
public function test_wartosc_tablicowa_jest_pomijana_i_oznaczona(): void
// sourceMeta => array( 'wpmem_pwz' => array( '1234567' ) )
// fields === array(); hasIssue( ISSUE_VALUE_UNUSABLE ) === true; bez ostrzeżenia PHP
public function test_wartosc_pusta_nie_jest_zapisywana(): void
// '   ' → fields() bez tego klucza
public function test_numer_niepoprawny_wchodzi_znormalizowany_i_jest_oznaczony(): void
// profesja 'lekarz' (PhysicianPwz), 'wpmem_pwz' => ' 12 34 567 '
// fields()['pwz'] === '1234567'; hasIssue( ISSUE_CREDENTIAL_INVALID ) zgodnie z sumą kontrolną
public function test_numer_poprawny_nie_daje_zastrzezenia(): void
public function test_profesja_nieznana_zostawia_klucz_pusty_i_zapisuje_wszystkie_pola(): void
public function test_pole_ukryte_dla_rozpoznanej_profesji_nie_jest_zapisywane(): void
// macierz: 'pwz' ukryte dla 'student' → fields() bez 'pwz', pozostałe pola są
public function test_stan_nieznany_nie_jest_zgadywany(): void
// statusValues bez wpisu dla 'dziwne' → status === null, hasIssue( ISSUE_STATUS_UNMAPPED )
public function test_brak_stanu_w_zrodle_bierze_fallback(): void
// fallback FALLBACK_PENDING → status === AccountStatus::Pending, hasIssue( ISSUE_STATUS_MISSING )
public function test_fallback_none_zostawia_konto_bez_stanu(): void
public function test_konto_bez_zadnych_danych_nie_ma_zapisow(): void
// hasWrites() === false
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `ImportPlanner::plan()`**

Kolejność: wartości źródłowe → profesja → pola (z przycięciem macierzą) → numer uprawnień → stan. Kody zastrzeżeń zbierane do jednej listy, przekazywanej konstruktorowi `AccountChange`, który je sortuje.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Domain/Import/ImportPlanner.php tests/Unit/Import/ImportPlannerTest.php
git commit -m "feat: planer importu przelicza jedno konto bez zapisu"
```

---

## Zadanie 4: `MetaKeyScanner` — skan kluczy `user_meta`

**Pliki:**
- Utwórz: `src/Persistence/MetaKeyScanner.php`
- Test: `tests/Integration/Persistence/MetaKeyScannerTest.php`

**Interfejsy:**
- Produkuje: `MetaKeyScanner::scan( int $limit = self::MAX_KEYS ): array` zwracające `list<MetaKeyStat>` posortowane malejąco po liczbie kont, a przy równej liczbie alfabetycznie po kluczu; stałe `MAX_KEYS = 50`, `SAMPLE_PER_KEY = 3`.
- Konsumuje: `MetaKeyStat::fromRow()` z Zadania 1; `UserMeta::ACF_PREFIX` i `UserMeta::all()` z Planu 1.

**Rozstrzygnięcie o wykluczeniach.** Skan pomija: klucze z przedrostkiem `pusers_` (nasze własne, w tym `UserMeta::ACF_PREFIX`), klucze zaczynające się od `_` (prywatne i cienie ACF), oraz klucze zaczynające się od przedrostka tabel (`$wpdb->prefix` — `wp_capabilities`, `wp_user_level`). Wszystko inne jest pokazywane, w tym `first_name` i `last_name`: administrator ma zobaczyć, co na portalu jest, a nie naszą opinię o tym.

**Rozstrzygnięcie o koszcie.** Licznik kont to jedno `GROUP BY` po `usermeta`, a próbki to jedno zapytanie na pokazywany klucz — przy `MAX_KEYS = 50` to 51 zapytań na żądanie uruchomione ręcznie z panelu. Nie jest to tanie i nie musi być: skan jest akcją administratora, nie czymś na każdym wczytaniu strony.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Persistence/MetaKeyScannerTest.php
public function test_liczy_konta_a_nie_wiersze(): void
// jedno konto z dwoma wierszami tego samego klucza → accounts === 1
public function test_pomija_klucze_wtyczki_i_prywatne_i_systemowe(): void
// konta z 'pusers_status', 'pusers_acf_pwz', '_acf_shadow', $wpdb->prefix . 'capabilities', 'wpmem_pwz'
// → w wyniku jest tylko 'wpmem_pwz'
public function test_sortuje_po_liczbie_kont_a_przy_rownej_alfabetycznie(): void
public function test_limit_obcina_liste(): void
// pięć kluczy, scan( 2 ) → dwa wiersze, te najliczniejsze
public function test_probki_pochodza_z_tego_klucza_i_jest_ich_najwyzej_trzy(): void
public function test_pusty_portal_daje_pusta_liste(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `MetaKeyScanner::scan()`**

Liczniki: `SELECT meta_key, COUNT(DISTINCT user_id) AS accounts FROM %i WHERE meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s GROUP BY meta_key ORDER BY accounts DESC, meta_key ASC LIMIT %d` na `$wpdb->usermeta`, z `$wpdb->esc_like()` dla przedrostków. Próbki: po jednym `SELECT DISTINCT meta_value … WHERE meta_key = %s LIMIT %d` na klucz, wyniki przez `MetaKeyStat::fromRow()`.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Persistence/MetaKeyScanner.php tests/Integration/Persistence/MetaKeyScannerTest.php
git commit -m "feat: skaner kluczy user_meta z licznikami kont i probkami"
```

---

## Zadanie 5: Pamięć mapowania i podgląd

**Pliki:**
- Utwórz: `src/Persistence/ImportMappingRepository.php`
- Modyfikuj: `src/Persistence/OptionStore.php`
- Test: `tests/Integration/Persistence/ImportMappingRepositoryTest.php`
- Test: `tests/Integration/Persistence/UninstallerTest.php`

**Interfejsy:**
- Produkuje: `ImportMappingRepository::get(): ImportMapping` (przycięte do aktualnych pól ACF i profesji), `save( ImportMapping $mapping ): bool`, `preview(): ?array` zwracające `array{ hash: string, report: array, at: int }`, `save_preview( ImportMapping $mapping, ImportReport $report ): bool`, `clear_preview(): bool`.
- Produkuje stałe w `OptionStore`: `OPTION_IMPORT_MAPPING = 'pusers_import_mapping'`, `OPTION_IMPORT_PREVIEW = 'pusers_import_preview'`, `OPTION_DEPLOYED_AT = 'pusers_deployed_at'` — wszystkie trzy dopisane do `OptionStore::all()`.
- Konsumuje: `OptionStore::{read,write,delete}` z Planu 2; `AcfFieldSource::fields()` z Planu 2; `ProfessionRepository::get()` z Planu 2; `FieldMatrixRepository::get()` z Planu 2.

**Dlaczego przycinanie przy odczycie.** Mapowanie wskazuje pola ACF i klucze profesji, które administrator może w międzyczasie usunąć. `FieldMatrixRepository` rozwiązuje to samo tak samo, więc `get()` robi `prunedTo( array_keys( ( new AcfFieldSource( … ) )->fields() ), ProfessionRepository::get()->keys() )`. Zapisane dane zostają nietknięte — przycięcie dotyczy tylko tego, co zwraca odczyt.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Persistence/ImportMappingRepositoryTest.php
public function test_zapis_i_odczyt_robia_runde(): void
public function test_odczyt_przycina_pola_ktorych_nie_ma_w_acf(): void
// zapisz mapowanie z polem 'nieistniejace'; get()->fields() bez niego; opcja w bazie dalej je ma
public function test_odczyt_przycina_profesje_ktorych_nie_ma_w_ustawieniach(): void
public function test_brak_opcji_daje_puste_mapowanie(): void
// get()->isEmpty() === true
public function test_podglad_niesie_odcisk_mapowania_i_czas(): void
// save_preview( $mapping, $report ); preview()['hash'] === $mapping->hash(); ['at'] > 0
public function test_czyszczenie_podgladu_usuwa_opcje(): void

// tests/Integration/Persistence/UninstallerTest.php — dopisz do istniejącego testu
public function test_odinstalowanie_usuwa_opcje_importu(): void
// trzy nowe opcje ustawione; Uninstaller::run(); get_option() każdej === false
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Dopisz stałe do `OptionStore` i zaimplementuj repozytorium**

Trzy stałe plus trzy wpisy w `all()`. Docblock przy `all()` już mówi, że każda nowa opcja musi tam trafić — bez tego odinstalowanie zostawia śmieci.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Persistence/ImportMappingRepository.php src/Persistence/OptionStore.php tests/Integration/Persistence/ImportMappingRepositoryTest.php tests/Integration/Persistence/UninstallerTest.php
git commit -m "feat: mapowanie importu i jego podglad w opcjach wtyczki"
```

---

## Zadanie 6: `ImportRunner` — tryb próbny i zapis

**Pliki:**
- Utwórz: `src/Import/ImportRunner.php`
- Test: `tests/Integration/Import/ImportRunnerTest.php`

**Interfejsy:**
- Produkuje: `ImportRunner::dry_run( ImportMapping $mapping ): ImportReport`, `apply( ImportMapping $mapping, int $actorUserId ): ImportReport`, stałe `BATCH = 200`, `LOG_APPLIED = 'import_applied'`, `LOG_WRITE_FAILED = 'import_write_failed'`.
- Konsumuje: `ImportPlanner` z Zadania 3; `ImportReport` z Zadania 1; `UserMeta::{ACF_PREFIX,PROFESSION}` z Planu 1; `AccountStatusRepository::set( int, AccountStatus ): void` z Planu 4; `EventLog::write( string, int, int, array ): bool` z Planu 7.

**Rozstrzygnięcie o porażce zapisu (Review Focus 2).** `update_user_meta()` zwraca `false` również wtedy, gdy wartość jest **identyczna** z zapisaną — sprawdzone w `update_metadata()` w `wp-includes/meta.php`. Dlatego przed każdym zapisem pola wartość jest porównywana z `get_user_meta( …, true )`: równa → pomijana (konto nie liczy się jako zmienione), różna → zapis i jedno odczytanie na potwierdzenie. Rozbieżność po zapisie liczy się jako `withWriteFailure()` i konto **nie** jest raportowane jako zmienione. Odczyty są tanie: `get_user_meta()` dla konta idzie z cache'u metadanych.

**Rozstrzygnięcie o logu.** Zapis daje **jeden** wiersz `LOG_APPLIED` na przebieg, z aktorem i licznikami z `ImportReport::toArray()` plus listą użytych kluczy źródłowych. Jeden wiersz na konto dałby przy imporcie ośmiuset lekarzy osiemset wierszy, a przypisanie odpowiedzialności („kto zatwierdził te konta") niesie już wiersz zbiorczy. **Tryb próbny nie pisze do logu** — to podgląd, nie operacja. Jeśli były porażki zapisu, powstaje drugi wiersz `LOG_WRITE_FAILED` z liczbą i próbką identyfikatorów. Kontekst obu wierszy nie niesie danych osobowych, tylko liczby i nazwy kluczy; podmiotem jest `0`, bo przebieg dotyczy wielu kont.

**Rozstrzygnięcie o obchodzeniu kont.** `WP_User_Query` z `fields => 'ID'`, `number => BATCH`, `orderby => 'ID'`, `order => 'ASC'`, `paged` rosnącym — nigdy `number => 0`. Sortowanie po `ID` jest stabilne między stronami, bo import nie zmienia identyfikatorów (lekcja z Planu 7: `registered DESC` bez rozstrzygnika gubił i dublował wiersze).

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Import/ImportRunnerTest.php
public function test_tryb_probny_nie_zapisuje_nic(): void
// trzy konta z danymi źródłowymi; dry_run(); żadne pusers_* meta nie istnieje; event_log pusty
public function test_tryb_probny_raportuje_te_same_liczby_co_zapis(): void
// dry_run()->toArray() === apply()->toArray() dla tego samego stanu portalu
public function test_zapis_wpisuje_pola_profesje_i_stan(): void
// pusers_acf_pwz, pusers_profession, AccountStatusRepository::get() === Approved
public function test_wartosc_identyczna_nie_liczy_sie_jako_zmiana_ani_jako_porazka(): void
// konto już ma pusers_acf_pwz === '1234567'; apply(); changed() === 0, writeFailures() === 0
public function test_padniety_zapis_jest_zgloszony_a_konto_nie_jest_zmienione(): void
// filtr 'query' przepisuje UPDATE/INSERT do usermeta dla tego konta tak, by nie trafił
// writeFailures() === 1; changed() === 0; w event_log wiersz LOG_WRITE_FAILED
// remove_filter i przywrócenie suppress_errors w bloku finally
public function test_zapis_zostawia_jeden_wiersz_w_logu_z_aktorem_i_licznikami(): void
// dokładnie jeden wiersz LOG_APPLIED; actor_user_id === $actorId; kontekst bez adresów e-mail
public function test_przebieg_obchodzi_wszystkie_konta_ponad_jedna_partie(): void
// BATCH + 5 kont → scanned() === BATCH + 5, każde ze zmienionym stanem
public function test_konta_bez_zadnych_danych_zrodlowych_sa_skanowane_ale_nie_zmieniane(): void
public function test_puste_mapowanie_nie_rusza_zadnego_konta(): void
// isEmpty() === true → scanned() === 0 i żadnego zapisu
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `ImportRunner`**

Jedna prywatna metoda obchodzi konta i składa raport, przyjmując domknięcie zapisujące albo nic — tryb próbny i zapis różnią się wyłącznie tym domknięciem i wpisem do logu. Dane źródłowe dla konta: `get_user_meta( $id )` raz, potem wybór kluczy z `$mapping->sourceKeys()` i pierwsza wartość z każdego wiersza.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Import/ImportRunner.php tests/Integration/Import/ImportRunnerTest.php
git commit -m "feat: przebieg importu w trybie probnym i z zapisem, z rozpoznaniem porazki zapisu"
```

---

## Zadanie 7: `ImportScreen` — skan, mapowanie i render

**Pliki:**
- Utwórz: `src/Admin/ImportScreen.php`
- Modyfikuj: `src/Admin/Menu.php`
- Test: `tests/Integration/Admin/ImportScreenTest.php`

**Interfejsy:**
- Produkuje: `ImportScreen::{register(),add_page(),handle_save(),render()}`, stałe `ACTION_SAVE = 'pusers_save_import_mapping'`, `NOTICE_SAVED_MAPPING = 'import_mapping_saved'`, `NOTICE_NOTHING_MAPPED = 'import_nothing_mapped'`.
- Produkuje: `Menu::SLUG_IMPORT = 'pusers-import'` i podstronę „Import danych" pod `users.php`.
- Konsumuje: `MetaKeyScanner::scan()` z Zadania 4; `ImportMappingRepository::{get,save}` z Zadania 5; `Request::{guard,redirect,notice}` i `Menu::url()` z Planu 2; `AcfFieldSource::fields()`, `ProfessionRepository::get()`.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Admin/ImportScreenTest.php
public function test_tabela_skanu_pokazuje_klucz_liczbe_kont_i_probki(): void
// konto z 'wpmem_pwz' => '1234567'; render() zawiera 'wpmem_pwz', '1' i '1234567'
public function test_probki_sa_eskejpowane(): void
// wartość '<script>alert(1)</script>' → w wyjściu '&lt;script&gt;', nigdy surowego tagu
public function test_formularz_oferuje_tylko_dozwolone_stany(): void
// render() zawiera 'approved', 'pending', 'rejected' i NIE zawiera 'email_unconfirmed'
public function test_zapis_mapowania_zapisuje_i_przekierowuje(): void
// handle_save() z POST; ImportMappingRepository::get()->fields() ma wpis; notice NOTICE_SAVED_MAPPING
public function test_zapis_pustego_mapowania_daje_wlasny_komunikat(): void
// NOTICE_NOTHING_MAPPED, nic nie zapisane
public function test_zapis_bez_uprawnien_jest_odrzucony(): void
// użytkownik bez manage_options → wp_die, mapowanie w bazie bez zmian
public function test_zapis_bez_nonce_jest_odrzucony(): void
public function test_zapis_metoda_get_jest_odrzucony(): void
public function test_strona_wisi_pod_uzytkownikami(): void
// add_page() → submenu pod Menu::PARENT ze slugiem Menu::SLUG_IMPORT
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj ekran i podstronę**

Render w trzech częściach: tabela skanu (klucz, liczba kont, próbki, wszystko przez `esc_html()`), formularz mapowania (select pola ACF → select klucza źródłowego; klucz profesji plus pary wartość → profesja; klucz statusu plus pary wartość → stan, wyłącznie z `ImportMapping::ALLOWED_STATUSES`; wybór `fallback`), i miejsce na raport, które Zadanie 8 wypełni. Komunikaty przez `Request::notice()`, zapis przez `Request::redirect()` — tak jak `MatrixScreen`.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Admin/ImportScreen.php src/Admin/Menu.php tests/Integration/Admin/ImportScreenTest.php
git commit -m "feat: ekran importu ze skanem kluczy i formularzem mapowania"
```

---

## Zadanie 8: Raport przed zapisem i bramka odcisku

**Pliki:**
- Modyfikuj: `src/Admin/ImportScreen.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Admin/ImportScreenTest.php`
- Test: `tests/Integration/WiringTest.php`

**Interfejsy:**
- Produkuje: `ImportScreen::{handle_preview(),handle_apply()}`, stałe `ACTION_PREVIEW = 'pusers_preview_import'`, `ACTION_APPLY = 'pusers_apply_import'`, `NOTICE_PREVIEW_READY = 'import_preview_ready'`, `NOTICE_STALE_PREVIEW = 'import_stale_preview'`, `NOTICE_APPLIED = 'import_applied'`, `PREVIEW_MAX_AGE = 3600`.
- Konsumuje: `ImportRunner::{dry_run,apply}` z Zadania 6; `ImportMappingRepository::{preview,save_preview,clear_preview}` z Zadania 5.

**Rozstrzygnięcie o bramce (Review Focus 3).** Specyfikacja mówi „dopiero po raporcie import zapisuje dane". Bramka ma trzy warunki i wszystkie trzy są sprawdzane w `handle_apply()`: podgląd **istnieje**, jego `hash` **równa się** odciskowi aktualnie zapisanego mapowania, a jego `at` jest **młodszy** niż `PREVIEW_MAX_AGE`. Każdy niespełniony daje `NOTICE_STALE_PREVIEW` i **żadnego zapisu**. Po udanym zapisie podgląd jest czyszczony: ten sam raport nie może autoryzować drugiego przebiegu.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Admin/ImportScreenTest.php — dopisz
public function test_raport_po_trybie_probnym_pokazuje_liczby_i_przyciski(): void
// handle_preview(); render() zawiera liczbę kont do zmiany i przycisk ACTION_APPLY
public function test_zapis_bez_raportu_jest_odrzucony(): void
// handle_apply() bez poprzedniego preview → NOTICE_STALE_PREVIEW, żadne pusers_* meta nie powstało
public function test_zmiana_mapowania_po_raporcie_uniewaznia_zapis(): void
// preview dla mapowania A; zapisz mapowanie B; handle_apply() → NOTICE_STALE_PREVIEW, zero zapisów
public function test_przedawniony_raport_uniewaznia_zapis(): void
// podgląd z at = time() - PREVIEW_MAX_AGE - 1 → NOTICE_STALE_PREVIEW
public function test_udany_zapis_czysci_podglad_i_drugi_klik_juz_nie_przechodzi(): void
// handle_apply() → NOTICE_APPLIED i dane w bazie; drugie handle_apply() → NOTICE_STALE_PREVIEW
public function test_podglad_i_zapis_maja_te_same_bramki_co_zapis_mapowania(): void
// brak uprawnień, brak nonce i metoda GET odrzucone dla obu akcji

// tests/Integration/WiringTest.php — dopisz
public function test_ekran_importu_jest_podpiety(): void
// remove_all_actions dla 'pusers_register_features'; Plugin::load_features();
// has_action( 'admin_post_' . ImportScreen::ACTION_APPLY ) !== false dla wszystkich trzech akcji
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj uchwyty i render raportu**

`handle_preview()` liczy `dry_run()` dla zapisanego mapowania i zapisuje podgląd. `handle_apply()` sprawdza bramkę, woła `apply()` z `get_current_user_id()` jako aktorem, czyści podgląd. Render raportu: liczba przeskanowanych i do zmiany, licznik każdego zastrzeżenia z jego próbką identyfikatorów jako linkami do profilu konta, i ostrzeżenie `notice-warning`, gdy raport mówi o kontach bez profesji albo o niepoprawnych numerach.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Admin/ImportScreen.php src/Plugin.php tests/Integration/Admin/ImportScreenTest.php tests/Integration/WiringTest.php
git commit -m "feat: raport przed zapisem importu i bramka odcisku mapowania"
```

---

## Zadanie 9: Backfill stanów i znacznik wdrożenia

**Pliki:**
- Utwórz: `src/Account/Backfill.php`
- Utwórz: `src/Admin/BackfillNotice.php`
- Modyfikuj: `src/Admin/ImportScreen.php`
- Modyfikuj: `src/Plugin.php`
- Modyfikuj: `portal-users.php`
- Modyfikuj: `docs/superpowers/backlog.md`
- Test: `tests/Integration/Account/BackfillTest.php`
- Test: `tests/Integration/Admin/BackfillNoticeTest.php`

**Interfejsy:**
- Produkuje: `Backfill::record_activation(): void`, `deployed_at(): int`, `stateless_count(): int`, `grant_approved( int $actorUserId ): int`, stałe `BATCH = 200`, `LOG_GRANTED = 'backfill_approved'`.
- Produkuje: `BackfillNotice::{register(),render()}`.
- Produkuje: `ImportScreen::ACTION_BACKFILL = 'pusers_backfill_approved'`, `NOTICE_BACKFILL_DONE = 'backfill_done'`, `handle_backfill()`.
- Konsumuje: `OptionStore::OPTION_DEPLOYED_AT` z Zadania 5; `AccountStatusRepository::set()`; `UserMeta::STATUS`; `EventLog::write()`; `Menu::{SLUG_IMPORT,url}`.

**Rozstrzygnięcie o granicy czasowej.** `stateless_count()` i `grant_approved()` biorą wyłącznie konta bez metadanej `UserMeta::STATUS` **zarejestrowane przed** `deployed_at()`. Konto bez stanu założone później to konto dodane ręcznie w panelu — brama celowo je wpuszcza i backfill nie ma go dotykać. Zapytanie: `WP_User_Query` z `meta_query` `array( 'key' => UserMeta::STATUS, 'compare' => 'NOT EXISTS' )` i `date_query` `array( 'before' => gmdate( 'Y-m-d H:i:s', $deployedAt ), 'inclusive' => false, 'column' => 'user_registered' )` — `WP_User_Query` buduje `WP_Date_Query` na kolumnie `user_registered` (sprawdzone w `class-wp-user-query.php:756`). Liczenie przez `fields => 'ID'`, `number => 1`, `get_total()`.

**Rozstrzygnięcie o znaczniku.** `record_activation()` zapisuje `OPTION_DEPLOYED_AT` **tylko gdy opcji nie ma**. Aktywacja po deaktywacji nie przesuwa granicy — inaczej konta zarejestrowane przez wtyczkę w międzyczasie wpadłyby w zakres backfillu.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Integration/Account/BackfillTest.php
public function test_aktywacja_zapisuje_znacznik_tylko_raz(): void
// record_activation(); $first = deployed_at(); record_activation(); deployed_at() === $first
public function test_liczy_tylko_konta_bez_stanu_sprzed_wdrozenia(): void
// konto bez stanu sprzed wdrożenia, konto bez stanu po wdrożeniu, konto ze stanem sprzed
// → stateless_count() === 1
public function test_nadanie_zatwierdzonego_rusza_tylko_konta_z_zakresu(): void
// grant_approved( $actorId ) === 1; konto po wdrożeniu dalej bez stanu
public function test_nadanie_zostawia_jeden_wiersz_w_logu_z_aktorem_i_liczba(): void
// dokładnie jeden wiersz LOG_GRANTED, actor_user_id === $actorId, kontekst niesie liczbę
public function test_powtorne_nadanie_nic_nie_robi(): void
// grant_approved() drugi raz → 0 i żadnego nowego wiersza w logu
public function test_nadanie_bez_znacznika_wdrozenia_nie_rusza_niczego(): void
// deployed_at() === 0 → grant_approved() === 0 (brak granicy = brak zakresu)
public function test_obchodzi_konta_ponad_jedna_partie(): void
// BATCH + 3 konta bez stanu sprzed wdrożenia → grant_approved() === BATCH + 3

// tests/Integration/Admin/BackfillNoticeTest.php
public function test_ostrzezenie_pokazuje_sie_gdy_sa_konta_bez_stanu(): void
// render() zawiera liczbę i link do Menu::SLUG_IMPORT
public function test_ostrzezenia_nie_ma_gdy_wszystkie_konta_maja_stan(): void
public function test_przycisk_backfillu_jest_na_ekranie_importu_z_liczba_kont(): void
// ImportScreen::render() zawiera ACTION_BACKFILL i liczbę kont
public function test_backfill_z_ekranu_ma_te_same_bramki(): void
// brak uprawnień, brak nonce, GET → odrzucone, żadne konto nie dostaje stanu
public function test_uchwyt_backfillu_i_ostrzezenie_sa_podpiete(): void
// remove_all_actions( 'pusers_register_features' ); Plugin::load_features();
// has_action( 'admin_post_' . ImportScreen::ACTION_BACKFILL ) !== false
// has_action( 'admin_notices' ) niesie BackfillNotice::render
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `Backfill`, ostrzeżenie i uchwyt**

`grant_approved()` chodzi po identyfikatorach partiami i woła `AccountStatusRepository::set()`. Jeden wiersz `LOG_GRANTED` na przebieg, tylko gdy liczba jest większa od zera. `ImportScreen::register()` z Zadania 7 dostaje czwartą akcję `admin_post_` dla `ACTION_BACKFILL`. Ostrzeżenie `BackfillNotice` renderuje się na stronach wtyczki (`admin_notices` z warunkiem na slug), klasa `notice-warning`, z treścią mówiącą, że konta bez stanu nie podlegają regułom bramy, i linkiem do narzędzia importu.

- [ ] **Krok 4: Podłącz aktywację i rejestrację**

`register_activation_hook( __FILE__, array( \PortalUsers\Account\Backfill::class, 'record_activation' ) );` w `portal-users.php`, obok pozostałych haków aktywacji. `BackfillNotice::register` dopisany do `pusers_register_features` w `Plugin.php`.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**

- [ ] **Krok 6: Wykreśl dostarczone pozycje z backlogu**

W `docs/superpowers/backlog.md`, sekcja „Plan 8 — Import i backfill stanów": pozycja o backfillu stanów przy aktywacji zostaje **przekreślona** z jednozdaniowym dopisem, że aktywacja zapisuje znacznik, a stan nadaje import albo przycisk (rozstrzygnięcie z nagłówka tego planu). Pozycja o polach spoza grupy ACF zostaje **przekreślona**: import zapisuje pod `UserMeta::ACF_PREFIX . <klucz pola z grupy>`, więc eksport, eksporter i eraser je widzą. Dopisz nową pozycję: decyzja właściciela o bezterminowości przycisku backfillu.

- [ ] **Krok 7: Bramki i commit**

```bash
git add src/Account/Backfill.php src/Admin/BackfillNotice.php src/Admin/ImportScreen.php src/Plugin.php portal-users.php docs/superpowers/backlog.md tests/Integration/Account/BackfillTest.php tests/Integration/Admin/BackfillNoticeTest.php
git commit -m "feat: backfill stanow dla kont sprzed wdrozenia i znacznik aktywacji"
```
