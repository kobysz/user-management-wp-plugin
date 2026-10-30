# Aktualizacje i wydanie — plan wdrożenia (Plan 9)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wtyczka stojąca na wielu portalach sama wykrywa nowe wydanie na GitHubie i podstawia je pod mechanizm aktualizacji WordPressa, a wydanie powstaje z tagu bez ręcznego budowania paczki.

**Architecture:** Jedna nowa klasa `PortalUsers\Update\GitHubUpdater` pyta API GitHuba o najnowsze wydanie, buforuje odpowiedź (pozytywnie i negatywnie) i wstrzykuje wpis do transienta `update_plugins` — do `response`, gdy wydanie jest nowsze, albo do `no_update`, gdy nie jest. Rejestruje się na `init` i **poza** bramką ACF Pro. Dwa workflowy GitHub Actions zamykają obieg: CI pilnuje bramek na każdym pushu i pull requeście, a wydanie buduje paczkę z tagu, sprawdza zgodność wersji z nagłówkiem i wycina treść wydania z `CHANGELOG.md`. Poza tym plan zakłada dwie brakujące bramki wersji minimalnej PHP, których brak w Planie 8 kosztował cztery zadania zielonych bramek przy niedającym się sparsować kodzie.

**Tech Stack:** PHP 8.1, WordPress 6.5, `wp_remote_get` + transienty, GitHub Actions, `@wordpress/env` (przez `scripts/wp-env.cjs`), PHPUnit 9.6, PHPStan 1.11, WPCS 3.1.

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`, sekcje „Dystrybucja i aktualizacje" oraz „Wzorce przeniesione z `event-registration`". Zobowiązania uszczegóławia `docs/superpowers/backlog.md`, sekcja „Plan 9 — Aktualizacje i release".

**Wzorzec źródłowy:** `C:\Users\kuba\SynologyDrive\WebDevAI\event-registration` — `src/Update/GitHubUpdater.php`, `.github/workflows/release.yml`, `.github/workflows/ci.yml`, `.distignore`, `CHANGELOG.md`. Specyfikacja każe **powtórzyć te wzorce, nie wymyślać ich od nowa**, wraz z rozwiązanymi tam pułapkami.

---

## Rozstrzygnięcia przedwykonaniowe

Pięć rzeczy rozstrzygniętych przed pierwszym zadaniem, cztery z nich pomiarem. Zapisane tutaj, bo zmieniają treść zadań względem tego, co zapowiadał backlog.

### P1. PHPCompatibility NIE wchodzi do zależności — jest o dwie główne wersje PHP za wtyczką

Backlog proponował dwa domknięcia bramki wersji minimalnej, pierwsze przez `phpcompatibility/phpcompatibility-wp` w `require-dev` i wskazanie standardu w zestawie reguł. Pomiar na sondzie zainstalowanej obok projektu:

- Composer rozwiązuje `phpcompatibility/phpcompatibility-wp:^2.1` na `php-compatibility` **9.3.5 z 27 grudnia 2019**. Nowsze stabilne nie istnieje: dostępne są tylko `10.0.0-alpha1`, `10.0.0-alpha2` i gałęzie `dev-*`.
- W 117 sniffach tej wersji **żaden** nie wymienia `'8.1'` ani `'8.2'`. Standard nie wie o istnieniu PHP 8.0 i nowszych, więc konstrukcji „składnia dostępna dopiero od 8.2 przy podłodze 8.1" — czyli dokładnie błędu z Planu 8 — nie widzi z definicji.
- Na obecnej bazie kodu daje **jedno** znalezisko i jest ono fałszywe: `PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext` na `src/Domain/Mail/MailKind.php:38`, czyli na `match ( $this )` w metodzie enuma. Sniff powstał przed enumami (`grep -c T_ENUM` w jego pliku daje `0`) i nie uznaje metody enuma za kontekst obiektu. Każda kolejna metoda enuma używająca `$this` dołoży kolejne.

Dodanie tej zależności oznaczałoby więc: martwy pakiet, zero pokrycia podłogi, która nas interesuje, i rosnąca lista wyciszeń fałszywych trafień. **Nie dodajemy.** Zamiast tego Zadanie 1 stawia dwie bramki opisane w P2 i P3, a martwą linię `testVersion` z `phpcs.xml.dist` usuwa — dziś czyta się jak straż wersji minimalnej i nie strzeże niczego.

### P2. `phpVersion: 80100` w PHPStanie łapie API powyżej podłogi

Pomiar: sonda `src/Probe82.php` z wywołaniem `ini_parse_quantity()` (funkcja dodana w PHP 8.2). Bez zmiany konfiguracji PHPStan na poziomie max kończy `[OK] No errors`. Po dopisaniu `phpVersion: 80100` do `phpstan.neon.dist` ten sam przebieg zwraca `Function ini_parse_quantity not found.` i kod wyjścia 1.

To jest brakująca bramka na klasę błędu „funkcja nieistniejąca w 8.1", czyli na `mb_scrub()` odrzucone w Planie 8 — jedna linia konfiguracji, zero nowych zależności.

### P3. `phpVersion: 80100` NIE łapie składni powyżej podłogi — na to idzie `php -l` w kontenerze 8.1

Pomiar na dokładnej konstrukcji z Planu 8: sonda z `public const ALLOWED = array( AccountStatus::Pending->value );`. PHPStan z `phpVersion: 80100` kończy **`[OK] No errors`** — host parsuje plik PHP 8.2, a reguła o wyrażeniach stałych czytających enum nie istnieje w 1.x.

Dlatego potrzebne są **dwie** bramki, nie jedna: `phpVersion` na API i przebieg `php -l` po `src/`, `portal-users.php` i `uninstall.php` **w kontenerze 8.1**, bo tylko tam parser jest tym parserem, na którym stoi portal. Kilka sekund na 111 plików.

### P4. Pusta suita PHPUnit kończy się kodem 0, a PHPUnit 9.6 nie ma flagi, która to zmienia

Pomiar: `vendor/bin/phpunit --filter 'NieIstniejacaNazwaTestu'` wypisuje `No tests executed!` i kończy **kodem 0**. `phpunit --help` wymienia `--fail-on-incomplete`, `--fail-on-risky`, `--fail-on-skipped`, `--fail-on-warning` — i żadnej flagi o pustej suicie.

To zamyka pozycję backlogu „Pusta suita integracyjna i kod wyjścia": CI nie może polegać na samym kodzie wyjścia. Zadanie 5 dokłada do obu suit własne sprawdzenie liczby wykonanych testów z raportu JUnit.

### P5. Wartości, które musi potwierdzić właściciel — i co kosztuje pomyłka

Repozytorium **nie ma skonfigurowanego zdalnego**, więc nazwa repozytorium na GitHubie jest nieznana. Plan przyjmuje przez analogię do `kobysz/event-registration-wp-plugin`:

- `GitHubUpdater::REPO` = `kobysz/portal-users-wp-plugin`
- nagłówek `Update URI:` = `https://github.com/kobysz/portal-users-wp-plugin`

**Koszt pomyłki:** aktualizator pyta o wydania repozytorium, którego nie ma, dostaje 404, zapisuje bufor negatywny i milczy. Administrator widzi „brak aktualizacji" bez żadnego ostrzeżenia, a poprawka nie dojeżdża na żaden portal. Dlatego oba miejsca wiąże test spójności (Zadanie 4), a procedura wydania (Zadanie 6) każe potwierdzić nazwę przed pierwszym tagiem.

Druga rzecz do potwierdzenia: **repozytorium musi być publiczne.** `browser_download_url` assetu wydania w repozytorium prywatnym wymaga uwierzytelnienia, którego updater WordPressa nie ma — pobranie paczki skończyłoby się 404 po tym, jak wtyczka ogłosi dostępną aktualizację. Prywatne repozytorium wymaga osobnego mechanizmu tokenu i nie jest w zakresie tego planu.

Trzecia: dopóki repozytorium nie ma zdalnego na GitHubie, **oba workflowy są bezczynne** — to pliki w drzewie, które nigdy się nie odpalą. Plan dostarcza mechanizm; pierwsze wydanie jest czynnością właściciela.

---

## Global Constraints

- PHP 8.1 lub nowszy, WordPress 6.5 lub nowszy, single site.
- Wersja ma **jedno źródło**: nagłówek `Version:` w `portal-users.php`. Żadnej stałej z wersją.
- Format wersji to **dokładnie `x.y.z`**, bez prereleasów. Tag wydania to `vx.y.z`. Te same trzy cyfry obowiązują w nagłówku, w teście `PluginTest::test_real_header_has_semver` (regex `^\d+\.\d+\.\d+$`) i w bramce workflow wydania.
- ACF Pro jest zależnością twardą wtyczki, ale **aktualizator rejestruje się poza tą bramką**: aktualizacja jest drogą naprawy portalu, więc nie może zależeć od wtyczki, której zepsute wydanie właśnie naprawiamy.
- Aktualizator rejestruje się na `init`, nie na `admin_init` — inaczej cron i WP-CLI nie widzą aktualizacji.
- `src/Domain/**` nie zna WordPressa (pilnuje tego `tests/Unit/Architecture/DomainPurityTest.php`). Aktualizator trafia do `src/Update`, nie do domeny.
- Wszystkie napisy dla człowieka przez `__()` / `esc_html__()` z domeną `portal-users`; językiem bazowym jest polski.
- Bramki, zielone po każdym zadaniu: `composer test:unit`, suita integracyjna w kontenerze, `composer analyse`, `composer lint`, a od Zadania 1 także `composer lint:syntax`.
- Komunikaty commitów kończą się linią `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Każdy przebieg suity użytkowników ma jawny limit; `'number' => 0` jest w tym projekcie zabronione.

## Review Focus

Pięć klas wejścia i trybów porażki, których specyfikacja dotyka, a które same z siebie nie trafiłyby do żadnego testu. Każda ma przypisany test w zadaniu, które jest właścicielem kodu.

1. **Nazwa katalogu wewnątrz paczki ZIP decyduje o katalogu instalacji.** Jeśli korzeń archiwum nazywa się inaczej niż katalog wtyczki (`portal-users`), WordPress rozpakuje aktualizację do **nowego** katalogu, a stara wtyczka zostanie w swoim — nieaktywna. Portal straci wtyczkę w trakcie aktualizacji. Test: Zadanie 6 sprawdza, że katalog montażowy w workflow jest dosłownie slugiem wtyczki.
2. **Tag niebędący `x.y.z` nie może zostać zgłoszony jako aktualizacja.** `version_compare( 'release-2', '0.1.0', '>' )` jest prawdą, więc tag pomyłkowy skierowałby wszystkie portale na przypadkowy asset. Test: Zadanie 3 odrzuca tag niepasujący do wzorca, zanim cokolwiek porówna.
3. **Wpis w buforze przeżywa aktualizację wtyczki.** Transient zapisany starszą wersją ma kształt tablicy sprzed zmiany; nowy kod czytający brakujący klucz kończy ostrzeżeniem albo błędem w żądaniu panelu. Test: Zadanie 3 odrzuca wpis buforowy o nieznanym kształcie i traktuje go jak brak bufora.
4. **Wydanie dokładające zdarzenie crona albo tabelę musi podnieść `Migrations::DB_VERSION`.** Aktualizacja w miejscu **nie odpala hooka aktywacji**; harmonogramy zakłada `Migrations::run()`, wołane z `maybe_upgrade()` na `plugins_loaded`. Nowa klasa crona bez podniesienia wersji schematu nigdy się nie zaplanuje na portalu, który już działał. Test: Zadanie 2 odkrywa klasy w `src/Cron/` i żąda, by każda była zaplanowana po `Migrations::run()`.
5. **Job CI, którego suita nie wykonała ani jednego testu, musi być czerwony.** Zmierzone w P4: pusty przebieg kończy się kodem 0. Test: Zadanie 5 czyta liczbę testów z raportu JUnit i przerywa job przy zerze.

---

### Zadanie 1: Dwie bramki wersji minimalnej PHP

Zamyka pozycję backlogu „Żadna bramka nie pilnuje wersji minimalnej PHP, a `testVersion` w `phpcs.xml.dist` jest martwe", w wariancie rozstrzygniętym w P1–P3.

**Files:**
- Modify: `phpstan.neon.dist` (dopisanie `phpVersion`)
- Modify: `phpcs.xml.dist` (usunięcie martwej linii `testVersion`)
- Modify: `composer.json` (skrypt `lint:syntax`)
- Create: `scripts/lint-syntax.sh`

**Interfaces:**
- Consumes: nic.
- Produces: polecenie `composer lint:syntax` — bramka składni na PHP 8.1, używana przez Zadanie 5 w CI.

- [ ] **Krok 1: Sonda API — udowodnij, że bramki dziś nie ma**

Utwórz tymczasowy plik `src/Probe82.php` z klasą `PortalUsers\Probe82` i metodą, która woła `ini_parse_quantity( '1M' )` (funkcja dodana w PHP 8.2). Plik ma mieć docblock zgodny z WPCS, żeby nie mieszać znaleziskom.

Uruchom: `composer analyse`
Oczekiwane: `[OK] No errors` — bramki nie ma.

- [ ] **Krok 2: Dopisz `phpVersion` i zobacz czerwono**

W `phpstan.neon.dist`, w bloku `parameters`, dopisz:

```neon
    phpVersion: 80100
```

Uruchom: `composer analyse`
Oczekiwane: `Function ini_parse_quantity not found.` w `src/Probe82.php`, kod wyjścia 1.

- [ ] **Krok 3: Druga sonda — składnia, której PHPStan nie złapie**

Zamień treść `src/Probe82.php` na klasę z wyrażeniem stałym czytającym przypadek enuma:

```php
public const ALLOWED = array( AccountStatus::Pending->value );
```

(z `use PortalUsers\Domain\Account\AccountStatus;`). To dokładna konstrukcja, która w Planie 8 przeszła przez cztery zadania.

Uruchom: `composer analyse`
Oczekiwane: `[OK] No errors` — potwierdzenie P3, że sama bramka PHPStana nie wystarcza. Sondy **nie usuwaj**, posłuży w Kroku 5.

- [ ] **Krok 4: Napisz skrypt bramki składni**

Utwórz `scripts/lint-syntax.sh`. Skrypt ma być w zwykłym `sh` (kontener nie ma gwarantowanego basha), milczeć przy sukcesie i wypisać błąd parsowania wraz z nazwą pliku przy porażce:

```sh
#!/bin/sh
# Bramka wersji minimalnej PHP: parser kontenera 8.1 jest tym parserem, na ktorym stoi portal.
# PHPStan z phpVersion lapie API powyzej podlogi, ale nie skladnie - zmierzone, patrz Plan 9, P3.
fail=0
for f in $( find src portal-users.php uninstall.php -name '*.php' ); do
	if ! php -l "$f" > /dev/null 2>&1; then
		php -l "$f"
		fail=1
	fi
done
exit "$fail"
```

W `composer.json`, w `scripts`, dopisz:

```json
"lint:syntax": "node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- sh scripts/lint-syntax.sh"
```

- [ ] **Krok 5: Uruchom bramkę składni na sondzie**

Uruchom: `composer lint:syntax`
Oczekiwane: `PHP Parse error` wskazujący `src/Probe82.php` i `Constant expression contains invalid operations`, kod wyjścia niezerowy.

- [ ] **Krok 6: Usuń sondę i potwierdź zielone**

Usuń `src/Probe82.php`.

Uruchom: `composer lint:syntax`
Oczekiwane: brak wyjścia, kod wyjścia 0.

- [ ] **Krok 7: Usuń martwą linię `testVersion`**

Z `phpcs.xml.dist` usuń `<config name="testVersion" value="8.1-"/>`. W jej miejsce wstaw komentarz mówiący, gdzie bramka wersji minimalnej teraz naprawdę jest: `phpVersion` w `phpstan.neon.dist` (API) i `composer lint:syntax` (składnia), oraz dlaczego nie ma tu PHPCompatibility — najnowsze stabilne wydanie standardu jest z 2019 roku i nie wie o PHP 8.1 ani 8.2.

Linię `<config name="minimum_wp_version" value="6.5"/>` **zostaw**: ta działa, bo sniffy WPCS same ją czytają.

- [ ] **Krok 8: Wszystkie bramki**

Uruchom: `composer lint`, `composer analyse`, `composer test:unit`, suitę integracyjną w kontenerze, `composer lint:syntax`
Oczekiwane: wszystkie zielone, PHPCS zero znalezisk.

- [ ] **Krok 9: Commit**

```bash
git add phpstan.neon.dist phpcs.xml.dist composer.json scripts/lint-syntax.sh
git commit -m "feat: bramki wersji minimalnej PHP - phpVersion w PHPStanie i php -l w kontenerze 8.1"
```

---

### Zadanie 2: `CHANGELOG.md`, jedna konwencja wersji i bramka zmian wymagających migracji

**Files:**
- Create: `CHANGELOG.md`
- Create: `tests/Unit/ChangelogTest.php`
- Modify: `tests/Integration/Persistence/MigrationsTest.php`

**Interfaces:**
- Consumes: nic.
- Produces: kształt `CHANGELOG.md`, od którego zależy wycinanie treści wydania w Zadaniu 6 — nagłówek wersji dosłownie `## [x.y.z] - RRRR-MM-DD`, pierwszym nagłówkiem sekcji jest `## [Unreleased]`.

- [ ] **Krok 1: Napisz testy kształtu changeloga**

W `tests/Unit/ChangelogTest.php` (zwykły `TestCase`, czyta plik, nie potrzebuje WordPressa):

```php
public function test_changelog_exists_and_starts_with_title(): void
public function test_changelog_declares_unreleased_section(): void
public function test_every_version_heading_has_the_agreed_shape(): void
public function test_no_version_heading_carries_a_prerelease_suffix(): void
```

Trzeci test wyciąga z pliku każdą linię pasującą do `/^## \[(?!Unreleased)/` i żąda, by cała pasowała do `/^## \[\d+\.\d+\.\d+\] - \d{4}-\d{2}-\d{2}$/`. Czwarty żąda, by żaden nagłówek nie zawierał `-alpha`, `-beta` ani `-rc` — format wersji jest jeden, bez prereleasów (Global Constraints).

- [ ] **Krok 2: Uruchom testy i zobacz porażkę**

Uruchom: `vendor/bin/phpunit --testsuite unit --filter ChangelogTest`
Oczekiwane: FAIL, plik `CHANGELOG.md` nie istnieje.

- [ ] **Krok 3: Napisz `CHANGELOG.md`**

Format Keep a Changelog 1.1.0 z odwołaniem do Semantic Versioning. Treść **po polsku**: ta sekcja jedzie dosłownie do opisu wydania na GitHubie i do okna „Szczegóły wtyczki" w panelu, a czyta ją polski administrator.

Nagłówek pliku ma powiedzieć trzy rzeczy, których nie widać z samego formatu: że sekcja każdej wersji publikuje się dosłownie jako treść wydania, że wpisy mają być pisane dla człowieka obsługującego portal, i że wersje są wyłącznie trzycyfrowe. Po nagłówku jedna sekcja `## [Unreleased]` — pierwsza sekcja wersji powstaje przy pierwszym wydaniu.

- [ ] **Krok 4: Uruchom testy i zobacz zielono**

Uruchom: `vendor/bin/phpunit --testsuite unit --filter ChangelogTest`
Oczekiwane: PASS, 4 testy.

- [ ] **Krok 5: Napisz test bramki migracji (Review Focus 4)**

W `tests/Integration/Persistence/MigrationsTest.php` dopisz:

```php
public function test_every_cron_class_is_scheduled_by_run(): void
```

Test **odkrywa** klasy, zamiast je wymieniać — inaczej nowa klasa crona przeszłaby obok testu tak samo, jak przechodzi obok aktywacji. Przebieg: wylistuj pliki `src/Cron/*.php`, złóż z nazw plików nazwy klas w przestrzeni `PortalUsers\Cron`, zostaw te, które mają stałą `HOOK` i publiczną metodę `schedule()`. Zdejmij z harmonogramu wszystkie te zdarzenia, wywołaj `Migrations::run()`, a potem dla każdego hooka żądaj, by `wp_next_scheduled( $hook )` nie było `false`. W komunikacie asercji podaj nazwę klasy i hooka, żeby porażka od razu mówiła, czego nie dopisano.

Test ma też sprawdzić, że lista odkrytych klas nie jest pusta — inaczej zmiana układu katalogów cicho wyłącza bramkę.

- [ ] **Krok 6: Uruchom test w kontenerze**

Oczekiwane: PASS. Jeśli jest czerwony, to znaczy, że któraś istniejąca klasa crona nie jest planowana przez `Migrations::run()` — wtedy naprawa idzie do `Migrations`, nie do testu.

- [ ] **Krok 7: Bramki i commit**

Uruchom wszystkie pięć bramek.

```bash
git add CHANGELOG.md tests/Unit/ChangelogTest.php tests/Integration/Persistence/MigrationsTest.php
git commit -m "feat: CHANGELOG w formacie Keep a Changelog i bramka planowania cronow przy migracji"
```

---

### Zadanie 3: Aktualizator z GitHuba

**Files:**
- Create: `src/Update/GitHubUpdater.php`
- Modify: `src/Plugin.php` (rejestracja na `init`, poza bramką ACF Pro)
- Create: `tests/Integration/Update/GitHubUpdaterTest.php`
- Modify: `tests/Integration/WiringTest.php`

**Interfaces:**
- Consumes: `Plugin::plugin_file()`, `Plugin::version( bool $fresh = false )`.
- Produces (potrzebne Zadaniom 4 i 6):
  - `GitHubUpdater::register(): void` — podpina oba filtry; wołane z `init`.
  - `GitHubUpdater::inject_update( mixed $transient ): mixed`
  - `GitHubUpdater::plugins_api( mixed $result, string $action, mixed $args ): mixed`
  - stałe prywatne: `REPO = 'kobysz/portal-users-wp-plugin'`, `ASSET_NAME = 'portal-users.zip'`, `CACHE_KEY = 'pusers_update_latest'`, `CACHE_TTL = 12 * HOUR_IN_SECONDS`, `CACHE_TTL_FAILURE = HOUR_IN_SECONDS`, `VERSION_PATTERN = '/^\d+\.\d+\.\d+$/'`.

- [ ] **Krok 1: Napisz testy — pułapki, nie szczęśliwa ścieżka**

W `tests/Integration/Update/GitHubUpdaterTest.php` (`WP_UnitTestCase`). Odpowiedzi GitHuba podstawiaj filtrem `pre_http_request`, zdejmowanym w `tearDown`; bufor czyść w `setUp` (`delete_transient`). Wersję zainstalowaną ustawiaj przez filtr na nagłówku albo przez realny nagłówek wtyczki — nie przez stałą, bo wersja ma jedno źródło.

```php
public function test_injects_update_when_release_is_newer(): void
public function test_registers_no_update_entry_when_up_to_date(): void
public function test_clears_stale_response_entry_when_up_to_date(): void
public function test_clears_stale_no_update_entry_when_newer_exists(): void
public function test_no_phantom_update_when_files_were_swapped_mid_request(): void
public function test_non_semver_tag_is_ignored(): void
public function test_cached_payload_of_unknown_shape_is_ignored(): void
public function test_release_without_matching_asset_is_ignored(): void
public function test_http_error_status_sets_negative_cache(): void
public function test_wp_error_sets_negative_cache(): void
public function test_negative_cache_prevents_second_http_call(): void
public function test_successful_payload_prevents_second_http_call(): void
public function test_non_object_transient_is_returned_unchanged(): void
public function test_plugins_api_returns_details_for_our_slug(): void
public function test_plugins_api_passes_through_for_other_action(): void
public function test_plugins_api_passes_through_for_other_slug(): void
```

Co musi pinować każdy z nietypowych:

- `test_registers_no_update_entry_when_up_to_date` — wpis w `no_update` jest **obowiązkowy** dla wtyczek spoza wordpress.org: bez obecności w `response` LUB `no_update` rdzeń uznaje wtyczkę za nieobsługującą aktualizacji i ukrywa przełącznik automatycznych aktualizacji. Asercja: `$result->no_update['portal-users/portal-users.php']` jest obiektem o polu `plugin` równym basename.
- `test_clears_stale_*` — po przejściu stanu stary wpis w drugiej tablicy musi zniknąć, inaczej nieaktualne „dostępna aktualizacja" przeżywa udaną aktualizację.
- `test_no_phantom_update_when_files_were_swapped_mid_request` — WordPress przelicza aktualizacje w tym samym żądaniu, w którym podmienił pliki, mając w pamięci wersję sprzed podmiany. Asercja: `inject_update()` czyta wersję przez `Plugin::version( true )`, więc po podmianie nagłówka w trakcie żądania wtyczka ląduje w `no_update`, nie w `response`.
- `test_non_semver_tag_is_ignored` (**Review Focus 2**) — odpowiedź z `tag_name: 'release-2'` nie daje żadnego wpisu w `response` ani w `no_update`, a `version_compare` nie jest nawet wołane.
- `test_cached_payload_of_unknown_shape_is_ignored` (**Review Focus 3**) — ustaw transient na `array( 'version' => '9.9.9' )` (bez `package`), wywołaj `inject_update()`: brak wpisu, brak ostrzeżenia PHP, a odpowiedź HTTP nie jest wołana więcej niż raz.
- `test_negative_cache_prevents_second_http_call` — licznik wywołań filtra `pre_http_request` po dwóch wywołaniach `inject_update()` równa się 1. To ochrona przed limitem zapytań GitHuba (60/h na adres bez uwierzytelnienia).

- [ ] **Krok 2: Uruchom testy i zobacz porażkę**

Oczekiwane: FAIL — klasa `PortalUsers\Update\GitHubUpdater` nie istnieje.

- [ ] **Krok 3: Napisz `GitHubUpdater`**

Przenieś wzorzec z `event-registration/src/Update/GitHubUpdater.php`, zmieniając przestrzeń nazw, stałe i nazwę wtyczki w `plugins_api`, i dokładając dwie rzeczy, których tam nie ma:

- **wzorzec wersji**: `fetch_latest()` po zdjęciu wiodącego `v` z `tag_name` sprawdza tag względem `VERSION_PATTERN` i przy niezgodności zapisuje bufor negatywny i zwraca `null` (Review Focus 2);
- **walidacja kształtu buforu**: prywatna metoda sprawdzająca, że wpis z transienta jest tablicą z wszystkimi czterema kluczami (`version`, `package`, `changelog`, `url`) i że każdy jest stringiem; wpis niepasujący traktuje się jak brak buforu i pyta GitHuba (Review Focus 3).

Reszta jak we wzorcu: `timeout` 10 s, nagłówki `Accept: application/vnd.github+json` i `User-Agent: portal-users-wp`, bufor pozytywny 12 h, negatywny 1 h, pusty string w transiencie jako zapamiętana porażka, `pick_asset()` po dosłownej nazwie assetu, `plugins_api` zwracający `sections.changelog` przepuszczony przez `wp_kses_post( wpautop( … ) )`.

Nazwa wtyczki w `plugins_api` zostaje **dosłowna** (`Portal Users`), bez `__()`: to nazwa własna z nagłówka `Plugin Name`, a nie napis do tłumaczenia. Opis wydania przychodzi z GitHuba i też nie przechodzi przez tłumaczenia.

- [ ] **Krok 4: Uruchom testy i zobacz zielono**

Oczekiwane: PASS, 16 testów.

- [ ] **Krok 5: Napisz test wpięcia**

W `tests/Integration/WiringTest.php` dopisz dwa testy:

```php
public function test_updater_registers_on_init(): void
public function test_updater_registers_even_without_acf_pro(): void
```

Pierwszy: po `do_action( 'init' )` filtr `pre_set_site_transient_update_plugins` ma podpiętą metodę aktualizatora (sprawdź `has_filter`), a `admin_init` nie jest do tego potrzebne. Drugi: z filtrem `pusers_acf_pro_loaded` zwracającym `false` rejestracja nadal zachodzi — aktualizacja jest drogą naprawy portalu i nie może zależeć od ACF Pro.

- [ ] **Krok 6: Wepnij aktualizator**

W `Plugin::boot()`, w sekcji rejestracji stojących **poza** `pusers_register_features`, dopisz `add_action( 'init', array( GitHubUpdater::class, 'register' ) );` wraz z komentarzem w stylu sąsiednich wpisów: dlaczego poza bramką ACF Pro (zepsute wydanie ACF nie może blokować aktualizacji, która je naprawia) i dlaczego na `init`, a nie `admin_init` (cron i WP-CLI).

- [ ] **Krok 7: Uruchom testy wpięcia**

Oczekiwane: PASS.

- [ ] **Krok 8: Bramki i commit**

Uruchom wszystkie pięć bramek. PHPStan na poziomie max przy `treatPhpDocTypesAsCertain: false` wymaga jawnych sprawdzeń typów na wszystkim, co przychodzi z `json_decode` i z filtrów.

```bash
git add src/Update/GitHubUpdater.php src/Plugin.php tests/Integration/Update/GitHubUpdaterTest.php tests/Integration/WiringTest.php
git commit -m "feat: aktualizacje z wydan GitHuba, poza bramka ACF Pro i na init"
```

---

### Zadanie 4: Nagłówek `Update URI`, `.distignore` i testy spójności paczki

**Files:**
- Modify: `portal-users.php` (nagłówek `Update URI`)
- Create: `.distignore`
- Create: `tests/Integration/Update/ReleaseConsistencyTest.php`
- Create: `tests/Unit/DistignoreTest.php`

**Interfaces:**
- Consumes: stałe `GitHubUpdater::REPO` i `GitHubUpdater::ASSET_NAME` (Zadanie 3), czytane w testach refleksją.
- Produces: `.distignore` — lista wykluczeń, z której Zadanie 6 buduje paczkę.

- [ ] **Krok 1: Napisz test spójności nagłówka z aktualizatorem**

`tests/Integration/Update/ReleaseConsistencyTest.php` (integracyjny, bo `GitHubUpdater` ma `defined( 'ABSPATH' ) || exit` i nie da się go wczytać bez WordPressa; stałe prywatne czytaj przez `ReflectionClass::getConstant()`):

```php
public function test_update_uri_header_points_to_the_updater_repository(): void
public function test_asset_name_matches_the_plugin_slug(): void
```

Pierwszy czyta nagłówek `Update URI:` z `portal-users.php` i żąda, by równał się `https://github.com/` + `REPO`. Drugi żąda, by `ASSET_NAME` równało się nazwie katalogu wtyczki plus `.zip` — ta sama nazwa wraca w Zadaniu 6 jako nazwa budowanego archiwum.

- [ ] **Krok 2: Uruchom i zobacz porażkę**

Oczekiwane: FAIL — nagłówka `Update URI` nie ma.

- [ ] **Krok 3: Dopisz nagłówek**

W bloku nagłówka `portal-users.php`, po `Domain Path`, dodaj:

```
 * Update URI:        https://github.com/kobysz/portal-users-wp-plugin
```

Powód w komentarzu pod blokiem albo w docblocku: od WordPressa 5.8 ten nagłówek powstrzymuje rdzeń od podstawiania pod nasz katalog aktualizacji wtyczki o tym samym slugu z wordpress.org. Slug `portal-users` jest generyczny, a podmiana kodu wtyczki zarządzającej kontami na obcą wtyczkę o tej samej nazwie katalogu byłaby awarią, z której nie ma drogi powrotu z panelu.

- [ ] **Krok 4: Uruchom i zobacz zielono**

Oczekiwane: PASS, 2 testy.

- [ ] **Krok 5: Napisz test pokrycia `.distignore`**

`tests/Unit/DistignoreTest.php` (czyta pliki, bez WordPressa):

```php
public function test_distignore_covers_every_top_level_path(): void
public function test_distignore_does_not_exclude_shipped_paths(): void
```

Pierwszy: wylistuj wszystkie wpisy pierwszego poziomu repozytorium (pliki i katalogi, z kropkowymi, bez `.git`), odrzuć te z białej listy wysyłanych do paczki — `src`, `assets`, `languages`, `vendor`, `portal-users.php`, `uninstall.php`, `CHANGELOG.md` — i żądaj, by każdy pozostały był dopasowany przez któryś wzorzec z `.distignore`. Dopasowanie realizuj przez `fnmatch()` na wpisie i na wpisie z doklejonym `/`.

Drugi: żadna ścieżka z białej listy nie może być dopasowana przez wzorzec z `.distignore`.

Dzięki pierwszemu testowi nowy katalog deweloperski na pierwszym poziomie repozytorium zapala czerwono, zamiast cicho wyjechać do paczki z wydaniem. Biała lista w teście jest **jedynym** miejscem, gdzie zapada decyzja „to jedzie do paczki".

- [ ] **Krok 6: Uruchom i zobacz porażkę**

Oczekiwane: FAIL — `.distignore` nie istnieje.

- [ ] **Krok 7: Napisz `.distignore`**

Wyklucz wszystko, czego portal nie potrzebuje do działania: pliki i katalogi `git`, `.github`, `.superpowers`, `.distignore`, `node_modules`, `tests`, `docs`, `scripts`, `*.dist`, `phpunit*.xml*`, `.wp-env.json`, `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, `.editorconfig`, `.phpunit.result.cache`, `*.zip`.

`vendor/` **zostaje w paczce** — wydanie instaluje zależności produkcyjne (`composer install --no-dev`) i pakuje je razem z kodem, bo portal nie ma Composera. `languages/` zostaje, choć jest dziś pusty: językiem bazowym jest polski i nagłówek `Domain Path` go deklaruje.

- [ ] **Krok 8: Uruchom i zobacz zielono**

Oczekiwane: PASS, 2 testy. Jeżeli pierwszy świeci na jakimś wpisie, decyzja jest świadoma: albo wpis idzie do `.distignore`, albo na białą listę w teście.

- [ ] **Krok 9: Bramki i commit**

```bash
git add portal-users.php .distignore tests/Integration/Update/ReleaseConsistencyTest.php tests/Unit/DistignoreTest.php
git commit -m "feat: naglowek Update URI, lista wykluczen paczki i testy spojnosci wydania"
```

---

### Zadanie 5: Workflow CI

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: skrypty Composera `lint`, `analyse`, `test:unit`, `lint:syntax` (Zadanie 1), `scripts/wp-env.cjs`.
- Produces: nic dla kolejnych zadań.

- [ ] **Krok 1: Napisz workflow**

`on`: `push` na `main`, `pull_request`, `workflow_dispatch`. Podział jak we wzorcu: joby szybkie i pewne na każdym pushu, joby na Dockerze (wp-env) tylko na pull requeście i ręcznie — w GitHub Actions są ciężkie i chwiejne, a źródłem prawdy dla integracji jest przebieg lokalny przed pushem. Powód zapisz komentarzem na górze pliku.

Joby:

1. **`static-analysis`** (każde zdarzenie): `actions/checkout@v4`, `shivammathur/setup-php@v2` z `php-version: '8.1'`, `tools: composer:v2`, `coverage: none`; `composer install --prefer-dist --no-progress`; potem kroki `composer lint`, `composer analyse` i testy jednostkowe z bramką niepustej suity (Krok 2).

   `composer analyse`, nie gołe `vendor/bin/phpstan analyse` — na domyślnym limicie 128 MB PHPStan pada na równoległym workerze, a limitu nie da się podać parametrem neona w 1.x (`parameters.memoryLimit` daje `Invalid configuration`). Skrypt Composera trzyma `--memory-limit=2G`. Zapisz to komentarzem w kroku, inaczej ktoś „uprości" polecenie.

2. **`integration`** (`if: github.event_name != 'push'`): `actions/setup-node@v4` z `node-version: '20'`, `npm ci`, `composer install --prefer-dist --no-progress`, `node scripts/wp-env.cjs start`, potem suita integracyjna przez `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit -c phpunit-integration.xml.dist` z bramką niepustej suity, a na końcu krok `composer lint:syntax` (środowisko już stoi, więc bramka składni na 8.1 kosztuje sekundy).

3. **`integration-wp-floor`** (`if: github.event_name != 'push'`): to samo co `integration`, ale ze zmienną środowiskową `WP_ENV_CORE: WordPress/WordPress#6.5`. Zamyka pozycję backlogu „Macierz wersji WordPressa w CI": testy jadą dziś na najnowszym WordPressie z kontenera, a minimum wtyczki to 6.5, więc bez tego joba nie dowiemy się, że coś wymaga nowszego API. Jeżeli ref `#6.5` nie zestawi środowiska, użyj najnowszego tagu z gałęzi 6.5 (`#6.5.5`) i dopisz w komentarzu, dlaczego.

- [ ] **Krok 2: Bramka niepustej suity (Review Focus 5)**

Każdy krok uruchamiający PHPUnit dodaje `--log-junit` i osobne sprawdzenie liczby testów — pomiar z P4 mówi, że pusta suita kończy się kodem 0, a PHPUnit 9.6 nie ma flagi, która to zmienia:

```yaml
      - name: Testy jednostkowe
        run: |
          composer test:unit -- --log-junit junit-unit.xml
          php -r '$x = simplexml_load_file( "junit-unit.xml" ); $n = (int) $x->testsuite["tests"]; if ( $n < 1 ) { fwrite( STDERR, "Suita nie wykonala zadnego testu\n" ); exit( 1 ); } echo "Wykonanych testow: $n\n";'
```

Ten sam wzór dla obu jobów integracyjnych, z własną nazwą pliku raportu. Dopisz `junit-*.xml` do `.gitignore` **oraz do `.distignore`** — bez drugiego wpisu test `DistignoreTest::test_distignore_covers_every_top_level_path` z Zadania 4 czerwienieje, gdy raport leży w katalogu po lokalnym przebiegu, i raport wyjechałby do paczki z wydaniem.

- [ ] **Krok 3: Sprawdź plik jako YAML**

Nie ma tu uruchomienia end-to-end (repozytorium nie ma zdalnego, P5), więc weryfikacja jest dwuczęściowa: po pierwsze każde polecenie z workflow uruchom **ręcznie u siebie** i zanotuj wynik; po drugie sprawdź, że plik parsuje się jako YAML i ma oczekiwane joby. `js-yaml` jest w `node_modules` (zależność przechodnia `@wordpress/env`) — sprawdzone.

Uruchom:

```bash
node -e "const j=require('js-yaml').load(require('fs').readFileSync('.github/workflows/ci.yml','utf8')); console.log(Object.keys(j.jobs).join(', '))"
```

Oczekiwane: `static-analysis, integration, integration-wp-floor`, bez błędu parsowania.

- [ ] **Krok 4: Bramka niepustej suity — udowodnij, że działa**

Uruchom lokalnie: `composer test:unit -- --filter 'NieIstniejacaNazwaTestu' --log-junit junit-probe.xml`, a potem sprawdzenie z Kroku 2 na tym raporcie.
Oczekiwane: PHPUnit kończy kodem 0, a sprawdzenie wypisuje `Suita nie wykonala zadnego testu` i kończy kodem 1. Usuń `junit-probe.xml`.

- [ ] **Krok 5: Bramki i commit**

```bash
git add .github/workflows/ci.yml .gitignore
git commit -m "ci: bramki statyczne na kazdym pushu, integracja i podloga WordPressa 6.5 na pull requescie"
```

---

### Zadanie 6: Workflow wydania i procedura wydania

**Files:**
- Create: `.github/workflows/release.yml`
- Create: `docs/wydanie.md`
- Create: `tests/Integration/Update/ReleaseWorkflowTest.php`

**Interfaces:**
- Consumes: `.distignore` (Zadanie 4), `GitHubUpdater::ASSET_NAME` i `REPO` (Zadanie 3), kształt `CHANGELOG.md` (Zadanie 2).
- Produces: wydanie na GitHubie z assetem o nazwie `ASSET_NAME`.

- [ ] **Krok 1: Napisz testy spójności workflow**

`tests/Integration/Update/ReleaseWorkflowTest.php` — testy czytają YAML jako tekst i pinują trzy wartości, które muszą zgadzać się z kodem PHP:

```php
public function test_workflow_builds_asset_named_like_the_updater_constant(): void
public function test_workflow_stages_into_a_directory_named_like_the_plugin_slug(): void
public function test_workflow_version_guard_uses_the_agreed_version_pattern(): void
```

Pierwszy żąda, by treść `release.yml` zawierała `GitHubUpdater::ASSET_NAME` (czytane refleksją) — inaczej aktualizator szuka assetu, którego wydanie nie ma, i milczy.

Drugi (**Review Focus 1**) żąda, by workflow montował paczkę w katalogu o nazwie dosłownie równej katalogowi wtyczki: w pliku musi stać przypisanie `STAGE="portal-users"`, gdzie nazwa pochodzi z `basename( dirname( Plugin::plugin_file() ) )`. Korzeń archiwum o innej nazwie każe WordPressowi rozpakować aktualizację do nowego katalogu i zostawić starą, nieaktywną wtyczkę obok.

Trzeci żąda, by w pliku stał wzorzec `[0-9]+\.[0-9]+\.[0-9]+` — ten sam format, którego pilnuje `PluginTest::test_real_header_has_semver` i `ChangelogTest`. To mechaniczne domknięcie pozycji backlogu „Zgodność formatu wersji w trzech miejscach": test czerwienieje, gdy ktoś rozluźni bramkę w workflow, nie ruszając testu.

- [ ] **Krok 2: Uruchom i zobacz porażkę**

Oczekiwane: FAIL — `.github/workflows/release.yml` nie istnieje.

- [ ] **Krok 3: Napisz workflow wydania**

`on: push: tags: ['v*']`, `permissions: contents: write`, jeden job na `ubuntu-latest`. Kroki, kolejno:

1. `actions/checkout@v4`.
2. `shivammathur/setup-php@v2` z `php-version: '8.1'`, `tools: composer:v2`, `coverage: none`.
3. **Bramka zgodności wersji z tagiem**: wyciągnij `Version:` z `portal-users.php`, porównaj z `${GITHUB_REF_NAME#v}`, przerwij z `::error::` przy różnicy. Oba sprawdź wzorcem trzycyfrowym — tag `v0.2` albo nagłówek `0.2.0-beta1` ma zatrzymać wydanie, nie przejść.
4. **Wycięcie sekcji `CHANGELOG.md`** do `RELEASE_BODY.md` przez `awk` z igłą `## [<tag>]`, kończące przy następnym `## [`. Pusty wynik to błąd z czytelnym komunikatem („dopisz wpis przed tagowaniem"), nie ciche wydanie bez opisu.
5. `composer install --no-dev --optimize-autoloader --prefer-dist --no-progress`.
6. **Budowa archiwum**: katalog montażowy o nazwie `portal-users`, `rsync -a --exclude-from=.distignore --exclude "$STAGE" ./ "$STAGE/"`, `zip -rq portal-users.zip "$STAGE"`.
7. `softprops/action-gh-release@v2` z `files: portal-users.zip` i `body_path: RELEASE_BODY.md`.

Bez kroków `npm ci` i `npm run build`: ta wtyczka nie ma budowania frontu, `assets/js/registration.js` jedzie do paczki tak, jak leży. Odnotuj to komentarzem, bo wzorzec źródłowy te kroki ma.

- [ ] **Krok 4: Uruchom testy i zobacz zielono**

Oczekiwane: PASS, 3 testy.

- [ ] **Krok 5: Sprawdź montaż paczki lokalnie**

Workflow nie da się odpalić (brak zdalnego), ale jego najbardziej zawodny krok da się sprawdzić u siebie. Wykonaj ręcznie, w katalogu tymczasowym poza repozytorium: `rsync` z `--exclude-from=.distignore` do katalogu `portal-users`, a potem obejrzyj zawartość.
Oczekiwane: w korzeniu `portal-users/` są `portal-users.php`, `uninstall.php`, `src/`, `assets/`, `CHANGELOG.md` i `vendor/`; nie ma `tests/`, `docs/`, `scripts/`, `.github/`, `node_modules/` ani plików `*.dist`. Katalogu `languages/` może nie być: jest na dysku, ale pusty, a git nie wersjonuje pustych katalogów — w świeżym klonie (czyli i w wydaniu) nie istnieje. To nie jest błąd, dopóki nie ma w nim żadnego pliku tłumaczeń.

Jeśli `rsync` nie jest dostępny na maszynie deweloperskiej, odnotuj to w raporcie i sprawdź listę wykluczeń testem z Zadania 4 — ale nie zgłaszaj kroku jako wykonanego.

Sprawdź też, że plik parsuje się jako YAML i ma jeden job:

```bash
node -e "const j=require('js-yaml').load(require('fs').readFileSync('.github/workflows/release.yml','utf8')); console.log(Object.keys(j.jobs).join(', '))"
```

- [ ] **Krok 6: Napisz procedurę wydania**

`docs/wydanie.md`, krótko i wykonalnie. Co musi w niej być:

- **Zanim powstanie pierwsze wydanie**: potwierdzić nazwę repozytorium (`GitHubUpdater::REPO` i nagłówek `Update URI` muszą wskazywać istniejące repozytorium — test spójności pilnuje tylko tego, że mówią to samo, nie tego, że to prawda) oraz że repozytorium jest **publiczne**, bo asset wydania w prywatnym nie da się pobrać bez uwierzytelnienia, którego updater WordPressa nie ma.
- **Lista kroków wydania**: zielone wszystkie pięć bramek → wpis w `CHANGELOG.md` jako `## [x.y.z] - RRRR-MM-DD`, pisany dla człowieka obsługującego portal → podniesienie `Version:` w nagłówku → **jeśli wydanie dokłada tabelę albo zdarzenie crona, podniesienie `Migrations::DB_VERSION`** → commit → tag `vx.y.z` → push tagu.
- **Dlaczego punkt o `DB_VERSION` jest w procedurze** (Review Focus 4): aktualizacja w miejscu nie odpala hooka aktywacji, a harmonogramy i tabele zakłada `Migrations::run()` wołane z `maybe_upgrade()` tylko wtedy, gdy zapisana wersja schematu jest niższa od stałej. Bez podniesienia stałej nowe zdarzenie nie powstanie na żadnym działającym portalu. Test z Zadania 2 pilnuje, że każda klasa crona jest planowana przez `run()`; stałą trzeba podnieść ręcznie.
- **Czego ta wtyczka nie wydaje**: nie ma `readme.txt` ani pliku `.pot` — dystrybucja nie idzie przez wordpress.org, a językiem bazowym jest polski, więc katalog tłumaczeń jest pusty do pierwszego tłumaczenia na inny język.
- **Stan dzisiejszy**: repozytorium nie ma zdalnego, więc oba workflowy są plikami, które nie odpaliły się ani razu. Pierwszy przebieg będzie też pierwszym sprawdzeniem.

- [ ] **Krok 7: Bramki i commit**

Uruchom wszystkie pięć bramek.

```bash
git add .github/workflows/release.yml docs/wydanie.md tests/Integration/Update/ReleaseWorkflowTest.php
git commit -m "feat: workflow wydania z tagu i procedura wydania"
```
