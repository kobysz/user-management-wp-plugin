# Ekran pomocy z listą startową — plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ekran *Portal: pomoc* pod menu Użytkownicy z listą startową ośmiu kroków liczoną z bieżącej konfiguracji i pełną instrukcją w czterech częściach.

**Architecture:** Wyliczanie stanu kroków jest czystą domeną (`src/Domain/Config/`) nad jawnym zestawem faktów; odczyt faktów z WordPressa i ACF robi warstwa panelu (`src/Admin/SetupFactsReader`), dokładnie jak `MatrixScreen::issues()` dopina dziś regułę o roli domyślnej do wyniku `ConsistencyChecker`. Ekran rejestruje swoją podstronę bezpośrednio w `Plugin::boot()`, poza hookiem `pusers_register_features`, bo bez ACF Pro pod *Użytkownikami* nie ma dziś żadnego ekranu. Treść instrukcji to stringi `__()` w czterech klasach pod `src/Admin/Help/`.

**Tech Stack:** PHP 8.1, WordPress 6.5, PHPUnit (suita jednostkowa bez WordPressa, integracyjna na wp-env), PHPCS (WordPress), PHPStan level max.

**Spec:** [docs/superpowers/specs/2026-10-31-ekran-pomocy-design.md](../specs/2026-10-31-ekran-pomocy-design.md)

## Global Constraints

- Treść instrukcji w stringach PHP przez `__()` z domeną `portal-users`; bez markdowna i bez pierwszej zależności produkcyjnej (`composer.json` wymaga dziś tylko `php >= 8.1`).
- `src/Domain/` nie wywołuje ani jednej funkcji WordPressa i nie nosi guardu `defined( 'ABSPATH' ) || exit;` — pilnuje `tests/Unit/Architecture/DomainPurityTest.php`.
- Ekran pomocy rejestruje się **poza** `pusers_register_features`; pozostałe ekrany zostają za tą bramką bez zmian.
- Uprawnienie `manage_options` (`Menu::CAPABILITY`); render za `current_user_can()` z `wp_die( …, '', array( 'response' => 403 ) )`.
- Bez nowych plików w `assets/`; układ na klasach panelu WordPressa (`wrap`, `notice`, `widefat`, `description`).
- Bez zmiany `Migrations::DB_VERSION`, bez nowych tabel, zdarzeń crona i opcji — ekran tylko czyta.
- Linia na ekranie ustawień liczy wyłącznie kroki 1–6 i nie wolno jej wołać zapytania o konta bez stanu.
- Lista startowa nie powtarza tekstów problemów raportu spójności; odsyła do niego odnośnikiem.

## Review Focus

- **Brak ACF Pro.** `acf_get_fields()` i `acf_get_field_groups()` nie istnieją; ekran pomocy musi się wyrenderować i powiedzieć „nie można sprawdzić” przy krokach 1, 2 i 4, a nie paść fatalem — to jedyny ekran widoczny w tej sytuacji. Test w Zadaniu 3.
- **Konto bez `manage_options`.** Render kończy się `wp_die` z kodem 403, nie wyciekiem instrukcji. Test w Zadaniu 3.
- **Strona wskazana, ale nieopublikowana albo w koszu.** Krok 5 musi być „do zrobienia”, nie „zrobione”. Test w Zadaniu 2.
- **Grupa pól wskazana, ale usunięta z ACF po wskazaniu.** Krok 2 „do zrobienia”, a krok 4 nie świeci zielono na podstawie starej macierzy. Test w Zadaniu 2.
- **Dopisanie kroku do `docs/konfiguracja.md` bez dopisania go na ekranie** (i odwrotnie). Test w Zadaniu 1 — mapowanie nagłówków dokumentu na przypadki enuma wymaga świadomej edycji, jak `SCHEMA_SURFACE` w `MigrationsTest`.

---

## Struktura plików

**Tworzone:**

- `src/Domain/Config/SetupStepKey.php` — enum ośmiu kroków w kolejności z dokumentu.
- `src/Domain/Config/SetupFacts.php` — zestaw faktów o konfiguracji, same wartości skalarne.
- `src/Domain/Config/SetupStep.php` — krok z wyliczonym stanem i kodami podpowiedzi.
- `src/Domain/Config/SetupChecklist.php` — reguły: fakty → stany kroków.
- `src/Admin/SetupFactsReader.php` — odczyt faktów z WordPressa, ACF i repozytoriów wtyczki.
- `src/Admin/HelpScreen.php` — podstrona, tytuły kroków, panel listy startowej, spis części.
- `src/Admin/Help/SetupGuide.php`, `FormChanges.php`, `Applications.php`, `Messages.php` — części A–D treści.
- `tests/Support/ConfigurationDoc.php` — parser nagłówków `## Krok N. …` z `docs/konfiguracja.md` plus mapowanie numeru kroku na przypadek enuma; wspólny dla suity jednostkowej i integracyjnej (`autoload-dev` mapuje `PortalUsers\Tests\` na `tests/`, a suita jednostkowa zbiera tylko `tests/Unit/*Test.php`, więc plik nie jest testem).
- `tests/Unit/Config/SetupChecklistTest.php`, `tests/Unit/Config/SetupStepKeyTest.php`.
- `tests/Integration/Admin/SetupFactsReaderTest.php`, `tests/Integration/Admin/HelpScreenTest.php`.

**Modyfikowane:**

- `src/Admin/Menu.php` — stała `SLUG_HELP`.
- `src/Admin/MatrixScreen.php:492` — `source()` z `private` na `public` (czytelnik faktów potrzebuje tego samego źródła pól, które widzi raport).
- `src/Plugin.php:144-172` — rejestracja `HelpScreen` nad blokiem `pusers_register_features`, z komentarzem o powodzie.
- `src/Admin/SettingsScreen.php` — linia z odnośnikiem do pomocy przy nieskończonej konfiguracji.
- `tests/Integration/WiringTest.php` — `HelpScreen` podpięty pod `admin_menu` i **nie** pod hook zależności.
- `tests/Integration/Admin/SettingsScreenTest.php` — obecność i brak linii.
- `CHANGELOG.md` — wpis w `## [Unreleased]`.
- `docs/konfiguracja.md` — zdanie o ekranie pomocy w panelu.

---

### Task 1: Domena listy startowej

**Files:**
- Create: `src/Domain/Config/SetupStepKey.php`, `src/Domain/Config/SetupFacts.php`, `src/Domain/Config/SetupStep.php`, `src/Domain/Config/SetupChecklist.php`
- Create: `tests/Support/ConfigurationDoc.php`
- Test: `tests/Unit/Config/SetupChecklistTest.php`, `tests/Unit/Config/SetupStepKeyTest.php`

**Interfaces:**
- Consumes: `PortalUsers\Domain\Profession\ProfessionCollection` (tylko gdyby okazała się potrzebna — reguły poniżej używają samych wartości logicznych, więc nie jest).
- Produces:
  - `enum SetupStepKey: string` z przypadkami, w tej kolejności: `AcfGroup = 'acf_group'`, `GroupSelected = 'group_selected'`, `Professions = 'professions'`, `Matrix = 'matrix'`, `Pages = 'pages'`, `Settings = 'settings'`, `MailTemplates = 'mail_templates'`, `LegacyAccounts = 'legacy_accounts'`.
  - `final class SetupFacts` — konstruktor z promocją, wszystkie `public readonly`: `bool $acfReadable`, `bool $anyAcfGroup`, `bool $groupSelected`, `bool $groupReadable`, `bool $groupHasFields`, `bool $anyProfession`, `bool $matrixErrorFree`, `bool $shortcodePagesPublished`, `bool $afterLoginPageSet`, `bool $notifyEmailsPresent`, `bool $rejectionReasonsNeeded`, `bool $rejectionReasonsPresent`, `bool $defaultRoleOk`, `int $statelessAccounts`.
  - `final class SetupStep` — stałe `DONE = 'done'`, `TODO = 'todo'`, `UNKNOWN = 'unknown'`, `INFO = 'info'`; konstruktor `public readonly SetupStepKey $key, public readonly string $status, public readonly array $hints` (`list<string>`); stałe kodów podpowiedzi `HINT_AFTER_LOGIN_PAGE_UNSET = 'after_login_page_unset'`, `HINT_REJECTION_REASONS_EMPTY = 'rejection_reasons_empty'`.
  - `final class SetupChecklist` — `__construct( private SetupFacts $facts )`, `public function steps(): array` (`list<SetupStep>`), `public function isComplete(): bool`.
  - `PortalUsers\Tests\Support\ConfigurationDoc::headings(): array<int, string>` — numer kroku => treść nagłówka bez przedrostka `## Krok N. `, w kolejności z pliku.
  - `PortalUsers\Tests\Support\ConfigurationDoc::STEPS` — `array<int, SetupStepKey>`, numer kroku => przypadek enuma. Mapowanie trzyma kod testowy, nie produkcyjny: dokument jest źródłem prawdy, a zmiana tej tablicy ma być świadoma, jak `SCHEMA_SURFACE` w `MigrationsTest`. Czyta je także test integracyjny z Zadania 3, więc nie może mieszkać w stałej jednego testu.

- [ ] **Step 1: Napisz `tests/Unit/Config/SetupStepKeyTest.php`**

```php
public function test_dokument_ma_dokladnie_kroki_z_enuma_w_tej_samej_kolejnosci(): void {
    $this->assertSame( array( 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( ConfigurationDoc::headings() ), 'docs/konfiguracja.md musi mieć osiem nagłówków „## Krok N.” numerowanych po kolei.' );
    $this->assertSame( array( 1, 2, 3, 4, 5, 6, 7, 8 ), array_keys( ConfigurationDoc::STEPS ) );
    $this->assertSame( array_values( ConfigurationDoc::STEPS ), SetupStepKey::cases(), 'Kolejność przypadków SetupStepKey musi być kolejnością kroków w dokumencie.' );
}

public function test_kazdy_naglowek_dokumentu_ma_swoj_przypadek_enuma(): void {
    foreach ( ConfigurationDoc::headings() as $number => $title ) {
        $this->assertArrayHasKey( $number, ConfigurationDoc::STEPS, 'Krok ' . $number . ' („' . $title . '”) nie ma przypadku w SetupStepKey ani wpisu w STEPS.' );
        $this->assertNotSame( '', $title );
    }
}
```

- [ ] **Step 2: Uruchom i potwierdź czerwone**

Run: `vendor/bin/phpunit --filter SetupStepKeyTest`
Expected: FAIL — brak klasy `SetupStepKey` i `ConfigurationDoc`.

- [ ] **Step 3: Zaimplementuj `SetupStepKey` i `ConfigurationDoc::headings()`**

`ConfigurationDoc` czyta plik przez `dirname( __DIR__, 2 ) . '/docs/konfiguracja.md'` i wyciąga nagłówki wyrażeniem `/^## Krok (\d+)\. (.+)$/`, po linii, z `rtrim( $line, "\r" )` — tak jak robi to `tests/Unit/ChangelogTest.php`. Stała `STEPS` wymienia osiem par numer => przypadek `SetupStepKey`, w kolejności 1–8.

- [ ] **Step 4: Uruchom i potwierdź zielone**

Run: `vendor/bin/phpunit --filter SetupStepKeyTest`
Expected: PASS

- [ ] **Step 5: Napisz `tests/Unit/Config/SetupChecklistTest.php`**

Pomocnik budujący fakty z nadpisaniem pojedynczych pól (świeża instalacja jako podstawa: wszystko `false`, `statelessAccounts` 0, `acfReadable` `true`). Przypadki:

```
test_swieza_instalacja_ma_szesc_krokow_do_zrobienia
  → statusy kroków 1-6 = TODO, krok MailTemplates = INFO, LegacyAccounts nieobecny, isComplete() false

test_bez_acf_pro_kroki_zalezne_od_acf_sa_niemozliwe_do_sprawdzenia
  → acfReadable false ⇒ AcfGroup, GroupSelected, Matrix = UNKNOWN; isComplete() false

test_pelna_konfiguracja_jest_skonczona
  → wszystkie fakty spełnione ⇒ kroki 1-6 = DONE, isComplete() true

test_krok_macierzy_nie_jest_zrobiony_bez_profesji
  → anyProfession false, matrixErrorFree true ⇒ Matrix = TODO

test_blad_macierzy_trzyma_krok_na_czerwono
  → matrixErrorFree false ⇒ Matrix = TODO, isComplete() false

test_zablokowana_rola_domyslna_trzyma_krok_ustawien_na_czerwono
  → defaultRoleOk false ⇒ Settings = TODO, isComplete() false

test_brak_strony_po_zalogowaniu_jest_podpowiedzia_nie_bledem_kroku
  → shortcodePagesPublished true, afterLoginPageSet false ⇒ Pages = DONE z hintem HINT_AFTER_LOGIN_PAGE_UNSET

test_puste_powody_odrzucenia_sa_podpowiedzia_tylko_przy_wlaczonej_akceptacji
  → rejectionReasonsNeeded true + present false ⇒ hint przy Settings; needed false ⇒ brak hintu

test_konta_sprzed_wdrozenia_pokazuja_osmy_krok
  → statelessAccounts 3 ⇒ LegacyAccounts obecny ze statusem TODO; 0 ⇒ nieobecny

test_szablony_maili_nigdy_nie_blokuja_ukonczenia
  → MailTemplates = INFO przy każdym zestawie faktów i nie wpływa na isComplete()
```

- [ ] **Step 6: Uruchom i potwierdź czerwone**

Run: `vendor/bin/phpunit --filter SetupChecklistTest`
Expected: FAIL — brak `SetupFacts`, `SetupStep`, `SetupChecklist`.

- [ ] **Step 7: Zaimplementuj `SetupFacts`, `SetupStep` i `SetupChecklist`**

Reguły stanów, w kolejności kroków:

| Krok | Reguła |
|---|---|
| `AcfGroup` | `! acfReadable` → UNKNOWN; `anyAcfGroup` → DONE; inaczej TODO |
| `GroupSelected` | `! acfReadable` → UNKNOWN; `groupSelected && groupReadable && groupHasFields` → DONE; inaczej TODO |
| `Professions` | `anyProfession` → DONE; inaczej TODO |
| `Matrix` | `! acfReadable` → UNKNOWN; `anyProfession && matrixErrorFree` → DONE; inaczej TODO |
| `Pages` | `shortcodePagesPublished` → DONE; inaczej TODO. Hint `HINT_AFTER_LOGIN_PAGE_UNSET`, gdy `! afterLoginPageSet` |
| `Settings` | `notifyEmailsPresent && defaultRoleOk` → DONE; inaczej TODO. Hint `HINT_REJECTION_REASONS_EMPTY`, gdy `rejectionReasonsNeeded && ! rejectionReasonsPresent` |
| `MailTemplates` | zawsze INFO, bez podpowiedzi |
| `LegacyAccounts` | obecny w `steps()` **tylko** gdy `statelessAccounts > 0`, wtedy TODO |

`isComplete()`: każdy z kroków `AcfGroup`, `GroupSelected`, `Professions`, `Matrix`, `Pages`, `Settings` ma status DONE. UNKNOWN nie jest DONE.

- [ ] **Step 8: Uruchom pełną suitę jednostkową**

Run: `vendor/bin/phpunit`
Expected: PASS, w tym `DomainPurityTest` (nowe pliki domeny bez guardu ABSPATH i bez nazw spoza projektu).

- [ ] **Step 9: Commit**

```bash
git add src/Domain/Config tests/Support tests/Unit/Config
git commit -m "feat: domena listy startowej konfiguracji"
```

---

### Task 2: Odczyt faktów z panelu

**Files:**
- Create: `src/Admin/SetupFactsReader.php`
- Modify: `src/Admin/MatrixScreen.php:492` (`source()` na `public`)
- Test: `tests/Integration/Admin/SetupFactsReaderTest.php`

**Interfaces:**
- Consumes: `SetupFacts` z Zadania 1; `AcfGroupList::groups()`, `MatrixScreen::source()`, `MatrixScreen::issues()`, `SettingsScreen::page_issues()`, `SettingsRepository::get()`, `ProfessionRepository::get()->all()`, `RegistrationHandler::default_role_state()`, `Backfill::stateless_count()`.
- Produces: `SetupFactsReader::read( bool $withLegacyAccounts = true ): SetupFacts`.

- [ ] **Step 1: Napisz test integracyjny**

Wzór ekranów panelu: `WP_UnitTestCase`, `pusers_acf_groups` i `pusers_acf_fields` podstawiane filtrem (ACF Pro nie ma w środowisku testowym), `remove_all_filters()` w `tearDown`.

```
test_swieza_instalacja_daje_fakty_niespelnione
test_grupa_wskazana_i_czytelna_z_polami_daje_groupHasFields_true
test_grupa_wskazana_ale_usunieta_z_acf_daje_groupReadable_false
  → ustaw acf_group_key na klucz, którego filtr grup nie zwraca
test_bez_filtra_grup_i_bez_acf_pro_acfReadable_jest_false
test_strona_w_stanie_draft_nie_liczy_sie_jako_opublikowana
  → trzy strony utworzone, jedna jako 'draft' ⇒ shortcodePagesPublished false
test_strona_usunieta_nie_liczy_sie_jako_opublikowana
  → id wskazuje na nieistniejący post ⇒ shortcodePagesPublished false
test_strona_po_zalogowaniu_jest_osobnym_faktem
test_adresy_redakcji_i_powody_odrzucenia_czytane_z_ustawien
test_zablokowana_rola_domyslna_daje_defaultRoleOk_false
  → default_role ustawiona na 'editor' (ma unfiltered_html)
test_bez_kont_sprzed_wdrozenia_statelessAccounts_jest_zerem
test_read_bez_kont_sprzed_wdrozenia_nie_liczy_zapytania
  → read( false ) zwraca statelessAccounts 0 nawet przy istniejących kontach bez stanu
```

- [ ] **Step 2: Uruchom i potwierdź czerwone**

Run: `MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users <kontener-tests-cli> vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SetupFactsReaderTest`
Expected: FAIL — brak `SetupFactsReader`.

- [ ] **Step 3: Zaimplementuj `SetupFactsReader::read()`**

Odwzorowanie faktów na źródła:

| Fakt | Źródło |
|---|---|
| `acfReadable` | `function_exists( 'acf_get_field_groups' ) \|\| array() !== AcfGroupList::groups()` — filtr grup jest szwem testowym i musi wystarczać za obecność ACF |
| `anyAcfGroup` | `array() !== AcfGroupList::groups()` |
| `groupSelected` | `'' !== SettingsRepository::get()->acfGroupKey` |
| `groupReadable` | `MatrixScreen::source()->groupExists()` |
| `groupHasFields` | `array() !== MatrixScreen::source()->fields()` |
| `anyProfession` | `array() !== ProfessionRepository::get()->all()` |
| `matrixErrorFree` | brak w `MatrixScreen::issues()` problemu o wadze `SettingsIssue::ERROR` i kodzie `profession_without_matrix_row`, `credential_field_not_required`, `credential_field_wrong_type` albo `matrix_required_field_not_in_source` |
| `shortcodePagesPublished` | `loginPageId`, `registerPageId`, `resetPageId` wszystkie `> 0` i żaden nie ma wpisu w `SettingsScreen::page_issues()` |
| `afterLoginPageSet` | `afterLoginPageId > 0` i brak wpisu `after_login_page_id` w `page_issues()` |
| `notifyEmailsPresent` | `array() !== $settings->notifyEmails` |
| `rejectionReasonsNeeded` | `$settings->approvalEnabled` |
| `rejectionReasonsPresent` | `array() !== $settings->rejectionReasons` |
| `defaultRoleOk` | `! RegistrationHandler::default_role_state()['blocked']` |
| `statelessAccounts` | `$withLegacyAccounts ? Backfill::stateless_count() : 0` |

Jedna instancja źródła pól na wywołanie (`MatrixScreen::source()` zwraca nową), żeby `groupReadable` i `groupHasFields` opisywały tę samą migawkę.

- [ ] **Step 4: Uruchom i potwierdź zielone**

Run: jak w kroku 2
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Admin/SetupFactsReader.php src/Admin/MatrixScreen.php tests/Integration/Admin/SetupFactsReaderTest.php
git commit -m "feat: odczyt faktow konfiguracji dla listy startowej"
```

---

### Task 3: Ekran pomocy i panel listy startowej

**Files:**
- Create: `src/Admin/HelpScreen.php`
- Modify: `src/Admin/Menu.php` (stała `SLUG_HELP = 'pusers-help'`), `src/Plugin.php:144-172`
- Test: `tests/Integration/Admin/HelpScreenTest.php`, `tests/Integration/WiringTest.php`

**Interfaces:**
- Consumes: `SetupChecklist`, `SetupStep`, `SetupStepKey` (Zadanie 1), `SetupFactsReader::read()` (Zadanie 2), `Menu::PARENT`, `Menu::CAPABILITY`, `Menu::url()`.
- Produces: `HelpScreen::register(): void`, `HelpScreen::add_page(): void`, `HelpScreen::render(): void`, `HelpScreen::title( SetupStepKey $key ): string`, `HelpScreen::setup_incomplete(): bool`.

- [ ] **Step 1: Napisz test integracyjny**

```
test_ekran_wymaga_uprawnienia
  → wp_set_current_user konta 'subscriber', render() ⇒ WPDieException
test_bez_acf_pro_ekran_sie_renderuje_i_mowi_ze_nie_moze_sprawdzic
  → brak filtra grup, render ⇒ zawiera tytuł ekranu i frazę o niemożliwości sprawdzenia, bez fatala
test_lista_startowa_wypisuje_kroki_w_kolejnosci_dokumentu
  → pozycje tytułów w HTML rosną zgodnie z ConfigurationDoc::headings()
test_tytul_kazdego_kroku_jest_naglowkiem_z_dokumentu
  → dla każdej pary ( $numer => $key ) z ConfigurationDoc::STEPS:
     HelpScreen::title( $key ) === ConfigurationDoc::headings()[ $numer ]
     (bez pliku .mo __() zwraca string źródłowy — pilnuje tego I18nTest)
test_skonczona_konfiguracja_zwija_liste_do_jednej_linii
  → pełna konfiguracja ⇒ HTML zawiera linię o skończonej konfiguracji i nie zawiera ośmiu wierszy kroków
test_osmy_krok_pokazuje_sie_tylko_przy_kontach_bez_stanu
test_panel_odsyla_do_raportu_spojnosci_zamiast_powtarzac_jego_tresci
  → przy błędzie macierzy HTML zawiera odnośnik page=pusers-matrix i nie zawiera tekstu problemu
test_setup_incomplete_jest_prawda_przy_swiezej_instalacji_i_falsz_przy_pelnej
test_podstrona_rejestruje_sie_pod_users_php
  → po add_page() submenu 'users.php' zawiera slug Menu::SLUG_HELP
```

W `WiringTest`:

```
test_ekran_pomocy_stoi_poza_bramka_zaleznosci
  → has_action( 'admin_menu', array( HelpScreen::class, 'add_page' ) ) !== false
     oraz has_action( 'pusers_register_features', array( HelpScreen::class, 'register' ) ) === false
```

- [ ] **Step 2: Uruchom i potwierdź czerwone**

Run: `… vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'HelpScreenTest|WiringTest'`
Expected: FAIL — brak `HelpScreen`.

- [ ] **Step 3: Zaimplementuj `HelpScreen` i podepnij go w `Plugin::boot()`**

`register()` podpina `add_page()` pod `admin_menu`. `add_page()` woła `add_submenu_page()` z `Menu::PARENT`, tytułem `__( 'Portal: pomoc', 'portal-users' )` i slugiem `Menu::SLUG_HELP`. `title()` to `match` po `SetupStepKey` z ośmioma literałami `__()` — treść nagłówków z `docs/konfiguracja.md`, bez przedrostka `Krok N. `.

Panel listy startowej: tabela z klasą `widefat`, wiersz na krok, w wierszu status słowem (`Zrobione`, `Do zrobienia`, `Nie można sprawdzić`, `Do przejrzenia`), tytuł kroku jako odnośnik do ekranu, na którym krok się wykonuje (`Menu::url()`; krok 1 prowadzi do `edit.php?post_type=acf-field-group`), podpowiedzi jako `<p class="description">`. Przy `isComplete()` zamiast tabeli jedna linia o skończonej konfiguracji.

W `Plugin::boot()` rejestracja stoi nad blokiem `add_action( 'pusers_register_features', … )`, razem z komentarzem w stylu sąsiednich: bez ACF Pro `Menu::register()` nie odpala i pod *Użytkownikami* nie ma żadnego ekranu, a to jest moment, w którym instrukcja jest najpotrzebniejsza.

- [ ] **Step 4: Uruchom i potwierdź zielone**

Run: jak w kroku 2
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Admin/HelpScreen.php src/Admin/Menu.php src/Plugin.php tests/Integration/Admin/HelpScreenTest.php tests/Integration/WiringTest.php
git commit -m "feat: ekran pomocy z lista startowa konfiguracji"
```

---

### Task 4: Treść instrukcji

**Files:**
- Create: `src/Admin/Help/SetupGuide.php`, `src/Admin/Help/FormChanges.php`, `src/Admin/Help/Applications.php`, `src/Admin/Help/Messages.php`
- Modify: `src/Admin/HelpScreen.php` (spis odnośników i wywołanie czterech części)
- Test: `tests/Integration/Admin/HelpScreenTest.php`

**Interfaces:**
- Produces: w każdej z czterech klas `public const ANCHOR = string` oraz `public static function heading(): string` i `public static function render(): void`.

- [ ] **Step 1: Dopisz testy treści**

```
test_spis_odnosnikow_prowadzi_do_kotwic_ktore_istnieja
  → dla każdej z czterech klas HTML zawiera href="#ANCHOR" i id="ANCHOR"
test_czesc_o_zmianie_formularza_ostrzega_przed_polem_wymaganym_poza_grupa
  → HTML zawiera zdanie o zablokowaniu rejestracji profesji
test_czesc_o_zmianie_formularza_mowi_ze_przemianowanie_pola_jest_nowym_polem
test_czesc_o_wnioskach_nazywa_ekran_wnioskow_i_odnosnik_do_rejestru
test_tabela_komunikatow_wymienia_komunikat_o_braku_wiersza_w_macierzy
test_render_nie_wypuszcza_surowego_skryptu
  → HTML nie zawiera '<script'
```

- [ ] **Step 2: Uruchom i potwierdź czerwone**

Run: `… --filter HelpScreenTest`
Expected: FAIL — brak klas części.

- [ ] **Step 3: Napisz treść czterech części**

Zakres każdej części ustala specyfikacja (sekcja „Treść instrukcji: trzy części”, plus tabela komunikatów jako część D). Część A, kroki i tabela ustawień — z `docs/konfiguracja.md`. Części B i C są nowe i nie mają odpowiednika w dokumencie; pisane od zera według specyfikacji. Wszystkie stringi przez `__()`/`esc_html__()`, znaczniki poza stringami tłumaczonymi.

- [ ] **Step 4: Uruchom i potwierdź zielone**

Run: `… --filter HelpScreenTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Help src/Admin/HelpScreen.php tests/Integration/Admin/HelpScreenTest.php
git commit -m "feat: tresc instrukcji na ekranie pomocy"
```

---

### Task 5: Droga do pomocy i dokumentacja

**Files:**
- Modify: `src/Admin/SettingsScreen.php`, `CHANGELOG.md`, `docs/konfiguracja.md`
- Test: `tests/Integration/Admin/SettingsScreenTest.php`

**Interfaces:**
- Consumes: `HelpScreen::setup_incomplete()` i `Menu::SLUG_HELP` z Zadania 3.

- [ ] **Step 1: Dopisz testy do `SettingsScreenTest`**

```
test_linia_do_pomocy_pokazuje_sie_przy_nieskonczonej_konfiguracji
  → HTML zawiera page=pusers-help
test_linia_do_pomocy_znika_przy_skonczonej_konfiguracji
  → pełna konfiguracja ⇒ HTML nie zawiera page=pusers-help
```

- [ ] **Step 2: Uruchom i potwierdź czerwone**

Run: `… --filter SettingsScreenTest`
Expected: FAIL

- [ ] **Step 3: Dopisz linię na ekranie ustawień**

Jedno zdanie z odnośnikiem, nad raportem spójności, tylko gdy `HelpScreen::setup_incomplete()`.

- [ ] **Step 4: Uruchom bramki w całości**

```bash
vendor/bin/phpcs -q
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/phpunit
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users <kontener-tests-cli> vendor/bin/phpunit -c phpunit-integration.xml.dist
composer lint:syntax
```

Expected: zero błędów PHPCS, PHPStan czysto na level max, obie suity zielone.

- [ ] **Step 5: Dopisz `CHANGELOG.md` i zdanie w `docs/konfiguracja.md`**

W `## [Unreleased]` sekcja `### Added` z wpisem dla operatora: ekran *Użytkownicy → Portal: pomoc* z instrukcją konfiguracji i listą startową pokazującą, który krok jest zrobiony. W `docs/konfiguracja.md`, na początku, zdanie: ta sama instrukcja jest w panelu na ekranie *Portal: pomoc*, razem z częściami o zmianie formularza i obsłudze wniosków, których ten dokument nie ma.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/SettingsScreen.php tests/Integration/Admin/SettingsScreenTest.php CHANGELOG.md docs/konfiguracja.md
git commit -m "feat: droga do ekranu pomocy z ustawien"
```
