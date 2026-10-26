# Portal Users — Plan 1: Fundament i domena

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować szkielet wtyczki, harness testów, migracje tabel i całą logikę domenową (walidatory numerów uprawnień, profesje, macierz profesja × pole, walidacja zgłoszenia, stany konta i brama logowania) pokrytą testami jednostkowymi — bez żadnego UI.

**Architecture:** `src/Domain/**` to czysty PHP bez WordPressa i bez `$wpdb`, testowany bez ładowania WP i pilnowany testem architektury. Adaptery WP siedzą w `src/Admin/`, `src/Frontend/`, `src/Persistence/`. Domena zwraca kody błędów, nigdy komunikatów dla użytkownika. Bootstrap wtyczki rejestruje autoloader, guard zależności i hook aktywacji — nic więcej.

**Tech Stack:** PHP 8.1, WordPress 6.5+, ACF Pro (zależność runtime, nie dotyczy Planu 1), Composer PSR-4 (`PortalUsers\`), PHPUnit 9.6, `wp-env` (Docker), PHPStan, WPCS.

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`

## Miejsce w roadmapie

Spec pokrywa kilka niezależnych podsystemów. Każdy dostaje własny plan, każdy kończy się działającym, testowalnym kawałkiem.

| Plan | Zakres |
|------|--------|
| **1 — Fundament i domena** (ten dokument) | szkielet, harness testów, migracje, walidatory numerów, profesje, macierz pól, walidacja zgłoszenia, stany konta |
| 2 — Ustawienia w panelu | CRUD profesji, macierz profesja × pole, odbiorcy maili, strony front, przełącznik akceptacji, progi antyspamu, powody odrzucenia, bramka uninstall |
| 3 — Rejestracja na froncie | render pól ACF + pole profesji, warunkowa widoczność w JS, antyspam, walidacja serwerowa, utworzenie konta, shortcode i blok |
| 4 — Potwierdzenie maila, logowanie, reset hasła | tokeny, cron czyszczący niepotwierdzone, brama `authenticate`, przekierowania, blokada `wp-admin`, własny reset hasła |
| 5 — Kolejka mailowa i szablony | szablony ze zmiennymi, kolejka + dispatcher + retry, log wysyłek z retencją, ekran kolejki |
| 6 — Panel wniosków | lista z zakładkami i filtrami, akcje masowe, szczegóły read-only, zatwierdzanie i odrzucanie z powodem, licznik w menu, link do rejestru |
| 7 — Eksport, RODO, import, aktywacja | eksport CSV, exporter/eraser danych osobowych, generyczny import z poprzedniej wtyczki, backfill stanów przy aktywacji |
| 8 — Aktualizacje i release | `GitHubUpdater`, workflow wydania z guardem wersji, `.distignore`, CI |

## Global Constraints

- Minimalne PHP: **8.1**. Minimalne WordPress: **6.5**.
- Namespace root: `PortalUsers\`, PSR-4, katalog `src/`.
- Slug wtyczki: `portal-users`. Prefiks funkcji, opcji i hooków: `pusers_`. Prefiks tabel: `{$wpdb->prefix}pusers_`.
- Text domain: `portal-users`. Język źródłowy: polski (msgid = polski literał).
- **Nazwa, namespace i prefiks to moja decyzja, nie zamawiającego** — do zmiany jednym przebiegiem przed Taskiem 1, nie później.
- `src/Domain/**` nie może zawierać ani jednego wywołania funkcji WordPressa ani `$wpdb`. Naruszenie = błąd, nie preferencja.
- Domena zwraca **kody błędów**, nie teksty. Tłumaczenie w warstwie prezentacji.
- Każdy plik PHP poza `src/Domain/` i `tests/` zaczyna się od `defined( 'ABSPATH' ) || exit;`.
- `declare( strict_types=1 );` w każdym pliku PHP.
- Wersja wtyczki ma **jedno źródło: nagłówek `Version:`** w pliku głównym. Nie hardkoduj jej w stałej klasy.
- Wersja schematu bazy w opcji `pusers_db_version`. Silnik tabel: InnoDB.
- Wszystkie stringi UI przez `__()` z domeną `portal-users`.
- **Host nie ma PHP.** Każde narzędzie PHP działa w kontenerze `wp-env`.

### Komendy referencyjne

```bash
# Zależności PHP (host, przez obraz Dockera; Git Bash wymaga wyłączenia przepisywania ścieżek)
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 install

# Środowisko
node scripts/wp-env.cjs start

# Testy jednostkowe (bez WordPressa)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit --testsuite unit

# Testy integracyjne (z WordPressem)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit -c phpunit-integration.xml.dist

# Analiza statyczna i styl
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpcs
```

**Nie używaj gołego `npx wp-env`** — na tej maszynie rozwiązuje się do przestarzałej pustej paczki i Node ma kwirk DNS. Wrapper `scripts/wp-env.cjs` skopiuj z `C:\Users\kuba\SynologyDrive\WebDevAI\event-registration\scripts\wp-env.cjs`. Porty: dev 8891 i tests 8892 są zajęte przez tamtą wtyczkę — użyj 8893 i 8894.

**Katalog repozytorium (`wp-plugin-user-management`) nie pokrywa się ze slugiem wtyczki (`portal-users`)**, więc `"plugins": ["."]` zamontowałoby ją pod złą nazwą. W `.wp-env.json` użyj `mappings` (`wp-content/plugins/portal-users` → `.`) z pustym `plugins`. Ścieżka `--env-cwd=wp-content/plugins/portal-users` z komend wyżej działa dzięki temu mapowaniu.

## Review Focus

Klasy wejść, których spec nie opisuje, a które uderzą w prawdziwego użytkownika. Każda ma test w zadaniu, które jest jej właścicielem.

1. **Numer uprawnień wklejony ze spacjami, myślnikami albo niewidocznym `&nbsp;`** — lekarz kopiuje numer z maila albo ze zdjęcia dokumentu. Bez normalizacji przed sprawdzeniem prawdziwy numer odpada jako błędny. (Task 3)
2. **Profesja usunięta z ustawień, gdy istnieją konta, które ją mają** — macierz i walidacja muszą znieść nieznany klucz profesji, zwracając „wszystko ukryte", a nie wyjątek. (Task 5)
3. **Pole usunięte z ACF, a zostało w macierzy** (i odwrotnie) — macierz nie może się wywalić na kluczu, którego nie ma w zestawie pól. (Task 5)
4. **Puste pole wielokrotne przychodzi jako pusta tablica, nie pusty string** — walidacja wymagalności musi uznać `[]`, `['']` i `'   '` za brak wartości, inaczej wymagany checkbox przechodzi bez zaznaczenia. (Task 6)
5. **Konto bez zapisanego stanu** — administrator dodany ręcznie w panelu po wdrożeniu nie ma stanu. Brama logowania musi go wpuścić, inaczej wtyczka zamyka właściciela portalu poza portalem. (Task 7)

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `portal-users.php` | Nagłówek wtyczki, guard `ABSPATH`, autoloader, guard ACF Pro, rejestracja hooków |
| `src/Plugin.php` | Punkt kompozycji: `boot()`, `version()` z nagłówka, ścieżki |
| `src/Dependencies.php` | Sprawdzenie ACF Pro, komunikat i blokada bootstrapu |
| `src/I18n.php` | `load_plugin_textdomain` na `init` |
| `src/Persistence/Migrations.php` | Tworzenie i wersjonowanie tabel |
| `src/Domain/Credentials/CredentialType.php` | Enum typów numeru uprawnień, w tym brak numeru |
| `src/Domain/Credentials/CredentialResult.php` | Wynik walidacji: poprawność, kod błędu, wartość znormalizowana |
| `src/Domain/Credentials/CredentialValidator.php` | Interfejs walidatora numeru |
| `src/Domain/Credentials/Normalizer.php` | Sprzątanie wklejonego numeru przed walidacją |
| `src/Domain/Credentials/Validators/PhysicianPwz.php` | PWZ lekarza: format + suma kontrolna mod 11 |
| `src/Domain/Credentials/Validators/NursePwz.php` | PWZ pielęgniarki/położnej: format 2+5+litera |
| `src/Domain/Credentials/Validators/DiagnosticianPwzdl.php` | PWZDL: cyfry bez zer wiodących |
| `src/Domain/Credentials/ValidatorRegistry.php` | Mapa typ numeru → walidator |
| `src/Domain/Profession/Profession.php` | Profesja jako dane: klucz, nazwa, typ numeru, rejestr, adres redakcji |
| `src/Domain/Profession/ProfessionCollection.php` | Zbiór profesji, odczyt po kluczu |
| `src/Domain/Fields/FieldState.php` | Enum: ukryte / widoczne / widoczne i wymagane |
| `src/Domain/Fields/FieldMatrix.php` | Macierz profesja × pole i odczyt stanów |
| `src/Domain/Fields/SubmissionFilter.php` | Odsiewanie wartości pól ukrytych dla danej profesji |
| `src/Domain/Submission/SubmissionValidator.php` | Walidacja zgłoszenia względem macierzy i walidatorów numeru |
| `src/Domain/Submission/SubmissionResult.php` | Błędy per pole + wartości znormalizowane |
| `src/Domain/Account/AccountStatus.php` | Enum stanów konta |
| `src/Domain/Account/LoginDecision.php` | Decyzja bramy: wpuścić albo kod odmowy |
| `src/Domain/Account/LoginGate.php` | Reguła wpuszczania na podstawie stanu |
| `tests/Unit/Architecture/DomainPurityTest.php` | Pilnuje czystości `src/Domain/**` |

---

## Task 1: Szkielet wtyczki i harness testów

**Files:**
- Create: `portal-users.php`, `src/Plugin.php`, `composer.json`, `phpunit.xml.dist`, `phpunit-integration.xml.dist`, `phpstan.neon.dist`, `phpcs.xml.dist`, `.wp-env.json`, `.editorconfig`, `.gitignore`
- Create: `tests/bootstrap-unit.php`, `tests/bootstrap-integration.php`
- Copy: `scripts/wp-env.cjs` z `C:\Users\kuba\SynologyDrive\WebDevAI\event-registration\scripts\wp-env.cjs`
- Test: `tests/Unit/PluginTest.php`, `tests/Unit/Architecture/DomainPurityTest.php`

**Interfaces:**
- Consumes: nic
- Produces: `PortalUsers\Plugin::boot(string $pluginFile): void`, `PortalUsers\Plugin::version(bool $fresh = false): string`, `PortalUsers\Plugin::plugin_file(): string`, `PortalUsers\Plugin::path(string $relative = ''): string`; działające suity `unit` i `integration`

- [ ] **Step 1: Utwórz `composer.json`**

Wzór: `composer.json` z `event-registration`, ze zmianami — `"name": "kuba/portal-users"`, PSR-4 `PortalUsers\` → `src/`, `PortalUsers\Tests\` → `tests/`, `platform.php` 8.1. Zachowaj `require-dev` (phpunit ^9.6, yoast/phpunit-polyfills ^1.1, phpstan ^1.11, szepeviktor/phpstan-wordpress ^1.3, wp-coding-standards/wpcs ^3.1, dealerdirect/phpcodesniffer-composer-installer ^1.0) i sekcję `scripts`.

- [ ] **Step 2: Utwórz `portal-users.php` z nagłówkiem wtyczki**

Nagłówek: `Plugin Name: Portal Users`, `Version: 0.1.0`, `Requires at least: 6.5`, `Requires PHP: 8.1`, `Text Domain: portal-users`, `Domain Path: /languages`, `License: GPL-2.0-or-later`. Po nagłówku: `defined( 'ABSPATH' ) || exit;`, warunkowy `require` autoloadera, `\PortalUsers\Plugin::boot( __FILE__ );`.

- [ ] **Step 3: Napisz test `tests/Unit/PluginTest.php`**

```php
public function test_version_is_read_from_plugin_header(): void {
    // Czyta z pliku-atrapy, nie z prawdziwego nagłówka — test jednostkowy nie ma WP.
    $this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Plugin::parse_version( $this->fixturePath() ) );
}

public function test_version_constant_does_not_exist(): void {
    $this->assertFalse( defined( 'PortalUsers\Plugin::VERSION' ) );
}
```

Fixture: plik z linią `* Version: 1.2.3` w nagłówku; asercja `'1.2.3'`.

- [ ] **Step 4: Uruchom test i upewnij się, że nie przechodzi**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit --testsuite unit`
Expected: FAIL — `Class "PortalUsers\Plugin" not found`

- [ ] **Step 5: Zaimplementuj `src/Plugin.php`**

Sygnatury: `public static function boot( string $pluginFile ): void`, `public static function version( bool $fresh = false ): string`, `public static function parse_version( string $file ): string`, `public static function plugin_file(): string`, `public static function path( string $relative = '' ): string`.

`parse_version()` czyta nagłówek regexem i jest jedynym miejscem bez WordPressa — `version()` woła `get_file_data` gdy WP jest dostępny, inaczej `parse_version()`. Cache statyczny, pomijany gdy `$fresh === true` (potrzebne w żądaniu aktualizacji, gdzie pliki na dysku są już nowe).

- [ ] **Step 6: Utwórz konfiguracje testów i narzędzi**

`phpunit.xml.dist` z suitą `unit` (`tests/Unit`, bootstrap `tests/bootstrap-unit.php`, bez WP). `phpunit-integration.xml.dist` z suitą `integration` (`tests/Integration`, bootstrap `tests/bootstrap-integration.php`, wymuszone locale `pl_PL`). `.wp-env.json` z portami 8893 i 8894. `phpstan.neon.dist`: level max, `szepeviktor/phpstan-wordpress`, ścieżki `src`. `phpcs.xml.dist`: WordPress ruleset, wykluczone `vendor`, `node_modules`, `tests`.

- [ ] **Step 7: Napisz test czystości domeny `tests/Unit/Architecture/DomainPurityTest.php`**

```php
public function test_domain_contains_no_wordpress_calls(): void {
    $denied = array( '$wpdb', 'add_action(', 'add_filter(', 'get_option(', 'update_option(',
        'wp_', 'esc_', '__(', '_e(', 'apply_filters(', 'do_action(', 'ABSPATH' );
    foreach ( $this->domainFiles() as $file ) {
        $src = file_get_contents( $file );
        foreach ( $denied as $needle ) {
            $this->assertStringNotContainsString( $needle, $src, basename( $file ) );
        }
    }
}
```

`domainFiles()` iteruje rekurencyjnie po `src/Domain` i zwraca pliki `.php`. Przy pustym katalogu test przechodzi — to poprawne, zacznie pilnować od Taska 3.

- [ ] **Step 8: Uruchom oba testy i upewnij się, że przechodzą**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit --testsuite unit`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add .
git commit -m "feat: szkielet wtyczki, harness testów i test czystości domeny"
```

---

## Task 2: Guard ACF Pro i i18n

**Files:**
- Create: `src/Dependencies.php`, `src/I18n.php`
- Create: `languages/.gitkeep`
- Modify: `portal-users.php`
- Test: `tests/Unit/DependenciesTest.php`, `tests/Integration/I18nTest.php`

**Interfaces:**
- Consumes: `Plugin::boot()`, `Plugin::path()`
- Produces: `Dependencies::missing( array $activePlugins, bool $acfProClassExists ): array` (lista kodów braków), `Dependencies::register(): void`, `I18n::register(): void`

- [ ] **Step 1: Napisz test `tests/Unit/DependenciesTest.php`**

```php
public function test_reports_acf_pro_missing_when_class_absent(): void {
    $this->assertSame( array( 'acf_pro' ), Dependencies::missing( array(), false ) );
}

public function test_reports_nothing_when_acf_pro_present(): void {
    $this->assertSame( array(), Dependencies::missing( array( 'advanced-custom-fields-pro/acf.php' ), true ) );
}

public function test_free_acf_is_not_enough(): void {
    $this->assertSame( array( 'acf_pro' ), Dependencies::missing( array( 'advanced-custom-fields/acf.php' ), false ) );
}
```

Czysta funkcja decyzyjna przyjmuje stan jako argumenty, żeby dała się przetestować bez WordPressa. `register()` jest cienkim adapterem, który te argumenty zbiera.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Run: `… phpunit --testsuite unit --filter DependenciesTest`
Expected: FAIL — klasa nie istnieje

- [ ] **Step 3: Zaimplementuj `src/Dependencies.php`**

Sygnatury: `public static function missing( array $activePlugins, bool $acfProClassExists ): array`, `public static function register(): void`, `public static function satisfied(): bool`.

`register()` na `admin_notices` wypisuje `notice notice-error` z treścią „Wtyczka Portal Users wymaga ACF Pro…" (przez `__()`), gdy `missing()` nie jest puste. Rozpoznanie ACF Pro: istnienie klasy `acf_pro` albo stałej `ACF_PRO`.

- [ ] **Step 4: Podepnij guard w `portal-users.php`**

`Plugin::boot()` rejestruje `Dependencies::register()` bezwarunkowo, a pozostałe hooki wtyczki tylko gdy `Dependencies::satisfied()`. Brak ACF Pro = widoczny komunikat i bezczynna wtyczka, nie fatal.

- [ ] **Step 5: Zaimplementuj `src/I18n.php` i napisz test integracyjny**

`I18n::register()` podpina `load_plugin_textdomain( 'portal-users', false, 'portal-users/languages' )` na `init`. Test integracyjny: po `do_action( 'init' )` `is_textdomain_loaded( 'portal-users' )` zwraca `true` albo katalog jest pusty — asercja na braku błędu i poprawnej ścieżce `Domain Path`.

- [ ] **Step 6: Uruchom testy jednostkowe i integracyjne**

Expected: PASS w obu suitach

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: guard zależności ACF Pro i ładowanie tłumaczeń"
```

---

## Task 3: Walidatory numerów uprawnień

**Files:**
- Create: `src/Domain/Credentials/CredentialType.php`, `CredentialResult.php`, `CredentialValidator.php`, `Normalizer.php`, `ValidatorRegistry.php`
- Create: `src/Domain/Credentials/Validators/PhysicianPwz.php`, `NursePwz.php`, `DiagnosticianPwzdl.php`
- Test: `tests/Unit/Credentials/PhysicianPwzTest.php`, `NursePwzTest.php`, `DiagnosticianPwzdlTest.php`, `NormalizerTest.php`, `ValidatorRegistryTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `enum CredentialType: string { case PhysicianPwz = 'physician_pwz'; case NursePwz = 'nurse_pwz'; case DiagnosticianPwzdl = 'diagnostician_pwzdl'; case None = 'none'; }`
  - `final class CredentialResult { public static function valid( string $normalized ): self; public static function invalid( string $code ): self; public function isValid(): bool; public function code(): string; public function normalized(): string; }`
  - `interface CredentialValidator { public function validate( string $raw ): CredentialResult; }`
  - `final class Normalizer { public static function clean( string $raw ): string; }`
  - `final class ValidatorRegistry { public function for( CredentialType $type ): ?CredentialValidator; }`
- Kody błędów: `empty`, `invalid_format`, `invalid_checksum`, `invalid_chamber`

- [ ] **Step 1: Napisz test `tests/Unit/Credentials/NormalizerTest.php`**

Pokrywa punkt 1 z Review Focus.

```php
public function test_strips_whitespace_dashes_and_nbsp(): void {
    $this->assertSame( '5425740', Normalizer::clean( " 542-57 40\u{00A0}" ) );
    $this->assertSame( '0201234P', Normalizer::clean( '02 01234 p' ) );
}

public function test_keeps_empty_string_empty(): void {
    $this->assertSame( '', Normalizer::clean( "  \u{00A0} " ) );
}
```

`clean()` usuwa białe znaki (w tym `\u{00A0}`), myślniki, kropki i ukośniki, a litery podnosi do wielkich.

- [ ] **Step 2: Napisz test `tests/Unit/Credentials/PhysicianPwzTest.php`**

```php
public function test_accepts_nil_reference_number(): void {
    // Przykład z dokumentacji NIL: cyfry 4,2,5,7,4,0 → suma 71 → 71 mod 11 = 5.
    $this->assertTrue( ( new PhysicianPwz() )->validate( '5425740' )->isValid() );
}

public function test_accepts_second_valid_number(): void {
    // 1,2,3,4,5,6 ważone 1..6 → 91 → 91 mod 11 = 3.
    $this->assertTrue( ( new PhysicianPwz() )->validate( '3123456' )->isValid() );
}

public function test_rejects_wrong_checksum(): void {
    $this->assertSame( 'invalid_checksum', ( new PhysicianPwz() )->validate( '1234567' )->code() );
}

public function test_rejects_number_whose_control_digit_would_be_ten(): void {
    // 4,3,0,0,0,0 ważone 1..6 → 10 → 10 mod 11 = 10, cyfra kontrolna nie mieści się
    // w jednym znaku, więc taki numer nie jest wydawany.
    $this->assertSame( 'invalid_checksum', ( new PhysicianPwz() )->validate( '1430000' )->code() );
}

public function test_rejects_leading_zero(): void {
    $this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '0425740' )->code() );
}

public function test_rejects_wrong_length(): void {
    $this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '542574' )->code() );
    $this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( '54257400' )->code() );
}

public function test_rejects_non_digits(): void {
    $this->assertSame( 'invalid_format', ( new PhysicianPwz() )->validate( 'abcdefg' )->code() );
}

public function test_rejects_empty(): void {
    $this->assertSame( 'empty', ( new PhysicianPwz() )->validate( '' )->code() );
}

public function test_normalizes_before_validating(): void {
    $result = ( new PhysicianPwz() )->validate( ' 542-5740 ' );
    $this->assertTrue( $result->isValid() );
    $this->assertSame( '5425740', $result->normalized() );
}
```

- [ ] **Step 3: Napisz test `tests/Unit/Credentials/NursePwzTest.php`**

```php
public function test_accepts_nurse_and_midwife_letters(): void {
    $this->assertTrue( ( new NursePwz() )->validate( '0201234P' )->isValid() );
    $this->assertTrue( ( new NursePwz() )->validate( '0201234A' )->isValid() );
}

public function test_accepts_chamber_bounds(): void {
    $this->assertTrue( ( new NursePwz() )->validate( '0101234P' )->isValid() );
    $this->assertTrue( ( new NursePwz() )->validate( '4501234P' )->isValid() );
}

public function test_rejects_chamber_out_of_range(): void {
    $this->assertSame( 'invalid_chamber', ( new NursePwz() )->validate( '0001234P' )->code() );
    $this->assertSame( 'invalid_chamber', ( new NursePwz() )->validate( '4601234P' )->code() );
}

public function test_rejects_wrong_letter(): void {
    $this->assertSame( 'invalid_format', ( new NursePwz() )->validate( '0201234X' )->code() );
}

public function test_rejects_missing_letter(): void {
    $this->assertSame( 'invalid_format', ( new NursePwz() )->validate( '0201234' )->code() );
}

public function test_accepts_lowercase_letter_and_normalizes_it(): void {
    $result = ( new NursePwz() )->validate( '0201234p' );
    $this->assertTrue( $result->isValid() );
    $this->assertSame( '0201234P', $result->normalized() );
}
```

- [ ] **Step 4: Napisz test `tests/Unit/Credentials/DiagnosticianPwzdlTest.php`**

```php
public function test_accepts_plain_digits(): void {
    $this->assertTrue( ( new DiagnosticianPwzdl() )->validate( '399' )->isValid() );
    $this->assertTrue( ( new DiagnosticianPwzdl() )->validate( '12345' )->isValid() );
}

public function test_strips_leading_zeros_per_kidl_convention(): void {
    $result = ( new DiagnosticianPwzdl() )->validate( '00399' );
    $this->assertTrue( $result->isValid() );
    $this->assertSame( '399', $result->normalized() );
}

public function test_rejects_zero_and_non_digits(): void {
    $this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( '0' )->code() );
    $this->assertSame( 'invalid_format', ( new DiagnosticianPwzdl() )->validate( '12a' )->code() );
}

public function test_rejects_empty(): void {
    $this->assertSame( 'empty', ( new DiagnosticianPwzdl() )->validate( '' )->code() );
}
```

- [ ] **Step 5: Napisz test `tests/Unit/Credentials/ValidatorRegistryTest.php`**

```php
public function test_maps_each_type_to_its_validator(): void {
    $registry = new ValidatorRegistry();
    $this->assertInstanceOf( PhysicianPwz::class, $registry->for( CredentialType::PhysicianPwz ) );
    $this->assertInstanceOf( NursePwz::class, $registry->for( CredentialType::NursePwz ) );
    $this->assertInstanceOf( DiagnosticianPwzdl::class, $registry->for( CredentialType::DiagnosticianPwzdl ) );
}

public function test_none_has_no_validator(): void {
    $this->assertNull( ( new ValidatorRegistry() )->for( CredentialType::None ) );
}
```

`CredentialType::None` zwracający `null` to nośny kontrakt: profesje bez numeru uprawnień (studenci, sponsorzy, firmy farmaceutyczne) są pełnoprawnym przypadkiem, nie brakiem danych.

- [ ] **Step 6: Uruchom testy i upewnij się, że nie przechodzą**

Run: `… phpunit --testsuite unit --filter Credentials`
Expected: FAIL — klasy nie istnieją

- [ ] **Step 7: Zaimplementuj enum, wynik, interfejs, normalizator i trzy walidatory**

Każdy walidator: `Normalizer::clean()` → sprawdzenie pustości (`empty`) → sprawdzenie formatu regexem (`invalid_format`) → sprawdzenia dodatkowe. `PhysicianPwz` liczy `K = (A·1 + B·2 + C·3 + D·4 + E·5 + F·6) mod 11` i porównuje z pierwszą cyfrą; reszta równa 10 nie może być cyfrą kontrolną, więc zwraca `invalid_checksum`. `NursePwz` sprawdza zakres izby 01–45 osobnym kodem `invalid_chamber`, żeby komunikat dla użytkownika dał się rozróżnić od złego formatu.

- [ ] **Step 8: Uruchom testy i upewnij się, że przechodzą, wraz z testem czystości domeny**

Run: `… phpunit --testsuite unit`
Expected: PASS, `DomainPurityTest` włącznie

- [ ] **Step 9: Commit**

```bash
git add .
git commit -m "feat: walidatory numerów PWZ, PWZP i PWZDL z normalizacją wejścia"
```

---

## Task 4: Profesje

**Files:**
- Create: `src/Domain/Profession/Profession.php`, `ProfessionCollection.php`
- Test: `tests/Unit/Profession/ProfessionTest.php`, `ProfessionCollectionTest.php`

**Interfaces:**
- Consumes: `CredentialType`
- Produces:
  - `final class Profession { public function __construct( public readonly string $key, public readonly string $label, public readonly CredentialType $credentialType, public readonly string $registryUrl = '', public readonly string $notifyEmail = '' ) {} public function requiresCredential(): bool; public static function fromArray( array $raw ): self; public function toArray(): array; }`
  - `final class ProfessionCollection { public static function fromArray( array $raw ): self; public function get( string $key ): ?Profession; public function has( string $key ): bool; public function all(): array; public function keys(): array; }`

- [ ] **Step 1: Napisz test `tests/Unit/Profession/ProfessionTest.php`**

```php
public function test_profession_without_credential_type_requires_no_number(): void {
    $p = new Profession( 'student', 'Student', CredentialType::None );
    $this->assertFalse( $p->requiresCredential() );
}

public function test_profession_with_credential_type_requires_a_number(): void {
    $p = new Profession( 'lekarz', 'Lekarz', CredentialType::PhysicianPwz, 'https://rejestr.nil.org.pl/' );
    $this->assertTrue( $p->requiresCredential() );
}

public function test_round_trips_through_array(): void {
    $raw = array(
        'key'             => 'pielegniarka',
        'label'           => 'Pielęgniarka',
        'credential_type' => 'nurse_pwz',
        'registry_url'    => 'https://nipip.pl/weryfikacja-pwz',
        'notify_email'    => 'redakcja@example.test',
    );
    $this->assertSame( $raw, Profession::fromArray( $raw )->toArray() );
}

public function test_unknown_credential_type_falls_back_to_none(): void {
    $p = Profession::fromArray( array( 'key' => 'x', 'label' => 'X', 'credential_type' => 'bzdura' ) );
    $this->assertSame( CredentialType::None, $p->credentialType );
}
```

Ostatni test pilnuje, żeby zepsuta albo starsza konfiguracja w opcjach nie wywaliła wtyczki — nieznany typ numeru degraduje się do „brak numeru".

- [ ] **Step 2: Napisz test `tests/Unit/Profession/ProfessionCollectionTest.php`**

```php
public function test_reads_profession_by_key(): void {
    $c = ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
        array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
    ) );
    $this->assertSame( 'Lekarz', $c->get( 'lekarz' )->label );
    $this->assertNull( $c->get( 'nie-ma' ) );
    $this->assertSame( array( 'lekarz', 'student' ), $c->keys() );
}

public function test_skips_entries_without_key(): void {
    $c = ProfessionCollection::fromArray( array( array( 'label' => 'Bez klucza' ) ) );
    $this->assertSame( array(), $c->keys() );
}
```

- [ ] **Step 3: Uruchom testy i upewnij się, że nie przechodzą**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 4: Zaimplementuj `Profession` i `ProfessionCollection`**

- [ ] **Step 5: Uruchom testy i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: model profesji z typem numeru uprawnień i adresem rejestru"
```

---

## Task 5: Macierz profesja × pole

**Files:**
- Create: `src/Domain/Fields/FieldState.php`, `FieldMatrix.php`, `SubmissionFilter.php`
- Test: `tests/Unit/Fields/FieldMatrixTest.php`, `SubmissionFilterTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `enum FieldState: string { case Hidden = 'hidden'; case Visible = 'visible'; case Required = 'required'; }`
  - `final class FieldMatrix { public static function fromArray( array $raw ): self; public function stateFor( string $professionKey, string $fieldKey ): FieldState; public function visibleFields( string $professionKey ): array; public function requiredFields( string $professionKey ): array; public function toArray(): array; }`
  - `final class SubmissionFilter { public function __construct( private FieldMatrix $matrix ) {} public function strip( string $professionKey, array $values ): array; }`

Reprezentacja w tablicy: `array<string professionKey, array<string fieldKey, string state>>`.

- [ ] **Step 1: Napisz test `tests/Unit/Fields/FieldMatrixTest.php`**

Testy 1, 2 i 3 pokrywają punkty 2 i 3 z Review Focus.

```php
private function matrix(): FieldMatrix {
    return FieldMatrix::fromArray( array(
        'lekarz'  => array( 'pwz' => 'required', 'miasto' => 'visible', 'pwzp' => 'hidden' ),
        'student' => array( 'pwz' => 'hidden', 'miasto' => 'visible', 'uczelnia' => 'required' ),
    ) );
}

public function test_reads_state_for_known_profession_and_field(): void {
    $this->assertSame( FieldState::Required, $this->matrix()->stateFor( 'lekarz', 'pwz' ) );
    $this->assertSame( FieldState::Visible, $this->matrix()->stateFor( 'lekarz', 'miasto' ) );
}

public function test_unknown_profession_hides_everything(): void {
    // Profesja usunięta z ustawień, a konta z nią zostały.
    $this->assertSame( FieldState::Hidden, $this->matrix()->stateFor( 'nie-ma', 'miasto' ) );
    $this->assertSame( array(), $this->matrix()->visibleFields( 'nie-ma' ) );
}

public function test_field_absent_from_matrix_is_hidden(): void {
    // Pole dodane w ACF, jeszcze nie ustawione w macierzy.
    $this->assertSame( FieldState::Hidden, $this->matrix()->stateFor( 'lekarz', 'nowe-pole' ) );
}

public function test_unknown_state_string_degrades_to_hidden(): void {
    $m = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'bzdura' ) ) );
    $this->assertSame( FieldState::Hidden, $m->stateFor( 'lekarz', 'pwz' ) );
}

public function test_visible_fields_include_required_ones(): void {
    $this->assertSame( array( 'pwz', 'miasto' ), $this->matrix()->visibleFields( 'lekarz' ) );
}

public function test_required_fields_exclude_merely_visible_ones(): void {
    $this->assertSame( array( 'pwz' ), $this->matrix()->requiredFields( 'lekarz' ) );
    $this->assertSame( array( 'uczelnia' ), $this->matrix()->requiredFields( 'student' ) );
}
```

Domyślna wartość „ukryte" przy każdym nieznanym wejściu jest nośnym inwariantem: brak konfiguracji nigdy nie odsłania pola, którego nie miało być widać.

- [ ] **Step 2: Napisz test `tests/Unit/Fields/SubmissionFilterTest.php`**

```php
public function test_drops_values_of_fields_hidden_for_the_profession(): void {
    $filter = new SubmissionFilter( $this->matrix() );
    $out    = $filter->strip( 'student', array( 'pwz' => '5425740', 'uczelnia' => 'UJ', 'miasto' => 'Kraków' ) );
    $this->assertSame( array( 'uczelnia' => 'UJ', 'miasto' => 'Kraków' ), $out );
}

public function test_drops_everything_for_unknown_profession(): void {
    $filter = new SubmissionFilter( $this->matrix() );
    $this->assertSame( array(), $filter->strip( 'nie-ma', array( 'miasto' => 'Kraków' ) ) );
}
```

Serwer nie ufa przeglądarce: pole ukryte dla danej profesji jest odsiewane, nawet jeśli przyszło w żądaniu.

- [ ] **Step 3: Uruchom testy i upewnij się, że nie przechodzą**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 4: Zaimplementuj `FieldState`, `FieldMatrix` i `SubmissionFilter`**

`visibleFields()` i `requiredFields()` zachowują kolejność kluczy z macierzy.

- [ ] **Step 5: Uruchom testy i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: macierz profesja x pole z domyslnym ukryciem i odsiewem wartosci"
```

---

## Task 6: Walidacja zgłoszenia

**Files:**
- Create: `src/Domain/Submission/SubmissionResult.php`, `SubmissionValidator.php`
- Test: `tests/Unit/Submission/SubmissionValidatorTest.php`

**Interfaces:**
- Consumes: `FieldMatrix`, `SubmissionFilter`, `ProfessionCollection`, `ValidatorRegistry`, `CredentialResult`
- Produces:
  - `final class SubmissionResult { public function isValid(): bool; public function errors(): array; public function values(): array; public function errorFor( string $fieldKey ): string; public function credentialWarning(): string; }` — `errors()` zwraca `array<string fieldKey, string code>`; błąd niezwiązany z konkretnym polem siedzi pod zarezerwowanym kluczem `__profession`; `errorFor()` dla pola bez błędu zwraca pusty string
  - `final class SubmissionValidator { public function __construct( private ProfessionCollection $professions, private FieldMatrix $matrix, private ValidatorRegistry $validators ) {} public function validate( string $professionKey, string $credentialFieldKey, array $values ): SubmissionResult; }`
- Kody błędów: `unknown_profession`, `required`, plus kody z `CredentialResult`

- [ ] **Step 1: Napisz test `tests/Unit/Submission/SubmissionValidatorTest.php`**

Test o pustych wartościach pokrywa punkt 4 z Review Focus. Test o miękkiej walidacji numeru jest nośnym wymaganiem specyfikacji.

```php
public function test_rejects_unknown_profession(): void {
    $r = $this->validator()->validate( 'nie-ma', 'pwz', array() );
    $this->assertFalse( $r->isValid() );
    $this->assertSame( 'unknown_profession', $r->errorFor( '__profession' ) );
}

public function test_reports_missing_required_field(): void {
    $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740' ) );
    $this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
}

public function test_treats_empty_array_empty_string_and_whitespace_as_missing(): void {
    foreach ( array( array(), array( '' ), '', '   ', null ) as $empty ) {
        $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740', 'zgoda' => $empty ) );
        $this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
    }
}

public function test_accepts_complete_submission(): void {
    $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740', 'zgoda' => '1' ) );
    $this->assertTrue( $r->isValid() );
}

public function test_invalid_credential_number_does_not_fail_the_submission(): void {
    // Walidacja numeru jest miękka: zła suma kontrolna przechodzi do redakcji
    // z ostrzeżeniem, bo lekarz z nietypowym numerem nie ma zostać odcięty.
    $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => '1234567', 'zgoda' => '1' ) );
    $this->assertTrue( $r->isValid() );
    $this->assertSame( 'invalid_checksum', $r->credentialWarning() );
}

public function test_missing_credential_number_is_a_hard_error_when_required(): void {
    $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'zgoda' => '1' ) );
    $this->assertSame( 'required', $r->errorFor( 'pwz' ) );
}

public function test_profession_without_credential_needs_no_number(): void {
    $r = $this->validator()->validate( 'student', 'pwz', array( 'uczelnia' => 'UJ', 'zgoda' => '1' ) );
    $this->assertTrue( $r->isValid() );
    $this->assertSame( '', $r->credentialWarning() );
}

public function test_values_are_stripped_of_hidden_fields_and_normalized(): void {
    $r = $this->validator()->validate( 'lekarz', 'pwz', array( 'pwz' => ' 542-5740 ', 'pwzp' => '0201234P', 'zgoda' => '1' ) );
    $this->assertArrayNotHasKey( 'pwzp', $r->values() );
    $this->assertSame( '5425740', $r->values()['pwz'] );
}
```

Fixture `validator()` buduje `ProfessionCollection` z `lekarz` (`physician_pwz`) i `student` (`none`) oraz `FieldMatrix` z `pwz` wymaganym dla lekarza i ukrytym dla studenta, `uczelnia` wymaganą dla studenta, `zgoda` wymaganą dla obu, `pwzp` ukrytym dla obu.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 3: Zaimplementuj `SubmissionResult`**

Dodatkowo do sygnatur z bloku Interfaces: `public function credentialWarning(): string` — kod ostrzeżenia walidacji numeru albo pusty string. Ostrzeżenie **nie** wpływa na `isValid()`.

- [ ] **Step 4: Zaimplementuj `SubmissionValidator::validate()`**

Kolejność: nieznana profesja → błąd i koniec; odsiew pól ukrytych przez `SubmissionFilter`; sprawdzenie wymagalności na `requiredFields()` z traktowaniem `[]`, `['']`, `''`, `'   '` i `null` jako braku; jeśli profesja ma typ numeru i pole numeru ma wartość — walidacja numeru, wynik znormalizowany trafia do `values()`, a kod błędu do ostrzeżenia, nie do błędów.

- [ ] **Step 5: Uruchom testy i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "feat: walidacja zgloszenia z miekka walidacja numeru uprawnien"
```

---

## Task 7: Stany konta i brama logowania

**Files:**
- Create: `src/Domain/Account/AccountStatus.php`, `LoginDecision.php`, `LoginGate.php`
- Test: `tests/Unit/Account/LoginGateTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `enum AccountStatus: string { case EmailUnconfirmed = 'email_unconfirmed'; case Pending = 'pending'; case Approved = 'approved'; case Rejected = 'rejected'; }` plus `public static function tryFromStored( ?string $raw ): ?self`
  - `final class LoginDecision { public function allowed(): bool; public function code(): string; }`
  - `final class LoginGate { public static function decide( ?AccountStatus $status ): LoginDecision; }`

- [ ] **Step 1: Napisz test `tests/Unit/Account/LoginGateTest.php`**

Pierwszy test pokrywa punkt 5 z Review Focus i jest nośnym inwariantem bezpieczeństwa dostępu.

```php
public function test_account_without_stored_status_is_allowed_in(): void {
    // Administrator dodany ręcznie w panelu po wdrożeniu nie ma stanu.
    // Brama musi go wpuścić, inaczej wtyczka zamyka właściciela portalu poza portalem.
    $this->assertTrue( LoginGate::decide( null )->allowed() );
}

public function test_approved_is_allowed_in(): void {
    $this->assertTrue( LoginGate::decide( AccountStatus::Approved )->allowed() );
}

public function test_each_other_status_is_denied_with_its_own_code(): void {
    $this->assertSame( 'email_unconfirmed', LoginGate::decide( AccountStatus::EmailUnconfirmed )->code() );
    $this->assertSame( 'pending', LoginGate::decide( AccountStatus::Pending )->code() );
    $this->assertSame( 'rejected', LoginGate::decide( AccountStatus::Rejected )->code() );
    foreach ( array( AccountStatus::EmailUnconfirmed, AccountStatus::Pending, AccountStatus::Rejected ) as $s ) {
        $this->assertFalse( LoginGate::decide( $s )->allowed() );
    }
}

public function test_allowed_decision_carries_no_code(): void {
    $this->assertSame( '', LoginGate::decide( AccountStatus::Approved )->code() );
}

public function test_unknown_stored_value_reads_as_no_status(): void {
    $this->assertNull( AccountStatus::tryFromStored( 'bzdura' ) );
    $this->assertNull( AccountStatus::tryFromStored( '' ) );
    $this->assertNull( AccountStatus::tryFromStored( null ) );
}
```

Osobny kod odmowy per stan jest wymagany przez spec: użytkownik ma wiedzieć, czy szukać maila, czy czekać na redakcję.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 3: Zaimplementuj `AccountStatus`, `LoginDecision` i `LoginGate`**

- [ ] **Step 4: Uruchom testy i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add .
git commit -m "feat: stany konta i brama logowania przepuszczajaca konta bez stanu"
```

---

## Task 8: Migracje tabel

**Files:**
- Create: `src/Persistence/Migrations.php`
- Modify: `portal-users.php` (hook aktywacji), `src/Plugin.php`
- Test: `tests/Integration/MigrationsTest.php`

**Interfaces:**
- Consumes: `Plugin::version()`
- Produces: `Migrations::DB_VERSION` (int), `Migrations::run(): void`, `Migrations::table( string $name ): string`, `Migrations::maybe_upgrade(): void`

Tabele tworzone w tym planie:

| Tabela | Kolumny |
|--------|---------|
| `{prefix}pusers_event_log` | `id` BIGINT AI PK, `created_at` DATETIME, `actor_user_id` BIGINT, `subject_user_id` BIGINT, `action` VARCHAR(40), `context` LONGTEXT (JSON), `anonymized` TINYINT(1) DEFAULT 0; indeksy na `subject_user_id` i `created_at` |
| `{prefix}pusers_terms_versions` | `id` BIGINT AI PK, `version_hash` CHAR(40) UNIQUE, `content` LONGTEXT, `created_at` DATETIME |

Kolumna `anonymized` istnieje od początku, bo spec wymaga anonimizacji logu przy żądaniu usunięcia danych, a nie jego kasowania (Plan 7). Tabela kolejki mailowej powstaje w Planie 5.

- [ ] **Step 1: Napisz test `tests/Integration/MigrationsTest.php`**

```php
public function test_creates_both_tables(): void {
    global $wpdb;
    Migrations::run();
    foreach ( array( 'event_log', 'terms_versions' ) as $name ) {
        $table = Migrations::table( $name );
        $this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
    }
}

public function test_stores_db_version(): void {
    Migrations::run();
    $this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
}

public function test_run_is_idempotent(): void {
    Migrations::run();
    Migrations::run();
    $this->assertSame( Migrations::DB_VERSION, (int) get_option( 'pusers_db_version' ) );
}

public function test_terms_version_hash_is_unique(): void {
    global $wpdb;
    Migrations::run();
    $table = Migrations::table( 'terms_versions' );
    $wpdb->insert( $table, array( 'version_hash' => str_repeat( 'a', 40 ), 'content' => 'x', 'created_at' => current_time( 'mysql', true ) ) );
    $wpdb->suppress_errors( true );
    $second = $wpdb->insert( $table, array( 'version_hash' => str_repeat( 'a', 40 ), 'content' => 'y', 'created_at' => current_time( 'mysql', true ) ) );
    $wpdb->suppress_errors( false );
    $this->assertFalse( (bool) $second );
}
```

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: FAIL — klasa nie istnieje

- [ ] **Step 3: Zaimplementuj `src/Persistence/Migrations.php`**

`run()` używa `dbDelta()` z `ENGINE=InnoDB` i `$wpdb->get_charset_collate()`, po czym zapisuje `pusers_db_version`. `table()` składa nazwę z `$wpdb->prefix . 'pusers_'`. `maybe_upgrade()` woła `run()` tylko gdy zapisana wersja jest niższa od `DB_VERSION`.

- [ ] **Step 4: Podepnij migracje w `portal-users.php`**

`register_activation_hook( __FILE__, array( Migrations::class, 'run' ) )` **w zasięgu pliku głównego**, nie wewnątrz innego hooka. Dodatkowo `maybe_upgrade()` na `plugins_loaded` — aktualizacja wtyczki przez mechanizm WordPressa nie odpala hooka aktywacji.

- [ ] **Step 5: Uruchom testy integracyjne i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 6: Uruchom PHPStan i PHPCS, popraw zgłoszenia**

Run: `… vendor/bin/phpstan analyse` oraz `… vendor/bin/phpcs`
Expected: brak błędów

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "feat: migracje tabel logu zdarzen i wersji regulaminu"
```

---

## Co ten plan świadomie zostawia

- Repozytoria czytające i zapisujące konfigurację z opcji — Plan 2. Domena w tym planie przyjmuje konfigurację jako tablice, co pozwala testować ją bez WordPressa.
- Każde UI, front i panel.
- Hooki `authenticate`, `wp_login_url`, przekierowania — Plan 4. Tutaj powstaje tylko czysta reguła decyzyjna.
- Tabela kolejki mailowej — Plan 5.
- `uninstall.php` i bramka usuwania danych — Plan 2, razem z ekranem ustawień, który ją włącza.
