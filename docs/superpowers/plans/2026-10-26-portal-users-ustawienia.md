# Portal Users — Plan 2: Ustawienia w panelu

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dać administratorowi ekrany, na których konfiguruje profesje, zależności pól, odbiorców maili, strony front, akceptację kont i progi antyspamu — oraz warstwę, która tę konfigurację zapisuje, czyta i pilnuje jej spójności.

**Architecture:** Domena z Planu 1 przyjmuje konfigurację jako tablice i nic nie wie o jej przechowywaniu. Plan 2 dokłada dwie warstwy wokół niej: `src/Persistence/` (repozytoria czytające i zapisujące opcje) oraz `src/Admin/` (ekrany serwerowe). Reguły spójności konfiguracji idą do czystej domeny (`src/Domain/Config/`), żeby dały się przetestować bez WordPressa. Pola rejestracji pochodzą z wybranej grupy pól ACF, czytanej przez adapter za interfejsem domenowym — dzięki temu testy nie potrzebują ACF Pro w kontenerze.

**Tech Stack:** PHP 8.1, WordPress 6.5+, ACF Pro (odczyt grup i pól), Composer PSR-4 (`PortalUsers\`), PHPUnit 9.6, `wp-env` (Docker), PHPStan level max, PHPCS (WordPress-Core + Extra + Docs). **Bez builda JS** — ekrany są renderowane serwerowo.

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`
**Wymogi przeniesione z Planu 1:** `docs/superpowers/backlog.md` — sekcja „Plan 2" jest wiążąca.

## Miejsce w roadmapie

| Plan | Stan |
|------|------|
| 1 — Fundament i domena | **scalony do `main`** (27 commitów, unit 111, integration 19) |
| **2 — Ustawienia w panelu** (ten dokument) | — |
| 3 — Rejestracja na froncie | render pól ACF, warunkowa widoczność w JS, antyspam, walidacja serwerowa, utworzenie konta, shortcode i blok |
| 4 — Potwierdzenie maila, logowanie, reset hasła | tokeny, cron czyszczący, brama `authenticate`, przekierowania, blokada `wp-admin` |
| 5 — Kolejka mailowa i szablony | szablony ze zmiennymi, kolejka + dispatcher + retry, log wysyłek |
| 6 — Panel wniosków | lista z zakładkami, akcje masowe, szczegóły, licznik w menu |
| 7 — Eksport, RODO, import, aktywacja | CSV, exporter/eraser, import generyczny, backfill stanów |
| 8 — Aktualizacje i release | `GitHubUpdater`, workflow wydania, CI |

## Global Constraints

- Minimalne PHP: **8.1**. Minimalne WordPress: **6.5**.
- Namespace root: `PortalUsers\`, PSR-4, katalog `src/`. Slug: `portal-users`. Prefiks funkcji, opcji, hooków i nazw pól formularzy: `pusers_`. Prefiks tabel: `{$wpdb->prefix}pusers_`.
- Text domain: `portal-users`. Język źródłowy: polski (msgid = polski literał).
- `src/Domain/**` nie może zawierać ani jednego wywołania funkcji WordPressa ani `$wpdb` — **także w komentarzach**. Pilnuje tego `tests/Unit/Architecture/DomainPurityTest.php`, który od Planu 1 jest **allowlistą** na `token_get_all()`: przechodzi tylko funkcje wbudowane PHP i nazwy z namespace'u projektu. Komunikat podaje plik, linię i nazwę.
- Pliki w `src/Domain/` **nie** mają `defined( 'ABSPATH' ) || exit;`. Wszystkie pozostałe pliki PHP poza `tests/` mają.
- `declare( strict_types=1 );` w każdym pliku PHP.
- **Domena zwraca kody, nie teksty.** Tłumaczenie kodów na komunikaty należy do `src/Admin/`.
- Wszystkie stringi UI przez `__()` / `esc_html__()` z domeną `portal-users`.
- **Każdy zapis w panelu: sprawdzenie uprawnień, potem nonce, potem akcja, potem przekierowanie (PRG).** Kolejność nie jest dowolna.
- **Każde wyjście eskejpowane.** PHPCS ma `WordPress-Extra`, więc `WordPress.Security.EscapeOutput` to błąd, nie sugestia.
- Opcje zapisywane z `autoload = false` — żadna z nich nie jest potrzebna przy każdym żądaniu.
- Ekrany renderowane serwerowo. **Nie dokładaj webpacka, Reacta ani żadnego builda JS.**
- Host nie ma PHP. Narzędzia PHP tylko w kontenerze.

### Komendy referencyjne

```bash
# Zależności PHP (Git Bash wymaga wyłączenia przepisywania ścieżek)
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 install

# Środowisko (porty 8893 dev / 8894 tests)
node scripts/wp-env.cjs start

# Testy
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpunit -c phpunit-integration.xml.dist

# Analiza i styl
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpstan analyse --memory-limit=512M
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/portal-users -- vendor/bin/phpcs
```

**PHPStan zawsze z `--memory-limit=512M`** — na domyślnych 128 MB pada na równoległym workerze. `memoryLimit` **nie istnieje** jako parametr neona w PHPStanie 1.x; sprawdzone, nie próbuj. CI w Planie 8 musi wołać `composer analyse`.

**Nie używaj gołego `npx wp-env`** — wrapper `scripts/wp-env.cjs` jest obowiązkowy.

PHPCS (`Squiz.Commenting`) wymusza docblocki z `@param` i `@return` na każdej metodzie. PHPStan przy level max nie przepuszcza `mixed` z tablicy wejściowej bez jawnej konwersji — w Planie 1 wymusił to helper konwertujący w `Profession::text()`. **`tests/Integration/MigrationsTest.php` kasuje i odtwarza prawdziwe tabele** — nie uruchamiaj suity integracyjnej w trybie recenzji read-only.

## Stan po Planie 1 — co już istnieje

**Bootstrap.** `portal-users.php` sprawdza czytelność autoloadera (brak → komunikat w panelu i `return`), potem `Plugin::boot( __FILE__ )`, potem `register_activation_hook` na `Migrations::run` i `add_action( 'plugins_loaded', Migrations::maybe_upgrade )` — oba bezwarunkowo.

`Plugin::boot()` rejestruje `Dependencies::register()` i `I18n::register()` bezwarunkowo, a gating funkcjonalnej części odkłada na `plugins_loaded` → `Plugin::load_features()`, które przy spełnionych zależnościach wykonuje **`do_action( 'pusers_register_features' )`**. To jest punkt zaczepienia dla tego planu: ekrany panelu rejestrują się pod tym hookiem, nie w `boot()`.

**Dostępne klasy (sygnatury dokładne):**

```php
// src/
PortalUsers\Plugin::{boot(string):void, load_features():void, version(bool $fresh = false):string,
                     parse_version(string):string, plugin_file():string, path(string $relative = ''):string}
PortalUsers\Dependencies::{missing(array $activePlugins, bool $acfProClassExists):array,
                           satisfied():bool, register():void, render_notice():void}
PortalUsers\I18n::{TEXT_DOMAIN = 'portal-users', register():void, load():void, relative_languages_dir():string}
PortalUsers\Persistence\Migrations::{DB_VERSION = 1, table(string $name):string, run():void, maybe_upgrade():void}

// src/Domain/Credentials/
enum CredentialType: string { PhysicianPwz='physician_pwz', NursePwz='nurse_pwz',
                              DiagnosticianPwzdl='diagnostician_pwzdl', None='none' }
CredentialResult::{EMPTY='empty', INVALID_FORMAT='invalid_format', INVALID_CHECKSUM='invalid_checksum',
                   INVALID_CHAMBER='invalid_chamber', valid(string):self, invalid(string):self,
                   isValid():bool, code():string, normalized():string}
interface CredentialValidator { validate(string $raw):CredentialResult }
Normalizer::clean(string $raw):string
ValidatorRegistry::for(CredentialType):?CredentialValidator   // None → null, nośny kontrakt

// src/Domain/Profession/
Profession::__construct(string $key, string $label, CredentialType $credentialType,
                        string $registryUrl = '', string $notifyEmail = '')  // wszystkie readonly public
Profession::{requiresCredential():bool, fromArray(array):self, toArray():array}
ProfessionCollection::{fromArray(array):self, get(string):?Profession, has(string):bool,
                       all():array, keys():array}

// src/Domain/Fields/
enum FieldState: string { Hidden='hidden', Visible='visible', Required='required' }
FieldMatrix::{fromArray(array):self, stateFor(string $profession, string $field):FieldState,
              visibleFields(string):array, requiredFields(string):array, toArray():array}
SubmissionFilter::{__construct(FieldMatrix), strip(string $profession, array $values):array}

// src/Domain/Submission/
SubmissionResult::{PROFESSION_KEY='__profession', isValid():bool, errors():array, values():array,
                   errorFor(string):string, credentialWarning():string}
SubmissionValidator::{__construct(ProfessionCollection, FieldMatrix, ValidatorRegistry),
                      validate(string $profession, string $credentialField, array $values):SubmissionResult}

// src/Domain/Account/
enum AccountStatus: string { EmailUnconfirmed='email_unconfirmed', Pending='pending',
                             Approved='approved', Rejected='rejected' }
AccountStatus::tryFromStored(?string):?self
LoginDecision::{allow():self, deny(string $code):self, allowed():bool, code():string}
LoginGate::decide(?AccountStatus):LoginDecision
```

**Zachowania domeny, na których ten plan stoi:**

- `Profession::fromArray()` degraduje nierozpoznany `credential_type` do `CredentialType::None`, puste `label` zastępuje kluczem, przycina wartości, pomija wpisy bez używalnego klucza i wpisy nie-tablicowe. Zepsuta konfiguracja nie zabija rejestracji.
- `FieldMatrix` degraduje **każde** nieznane wejście do `FieldState::Hidden` (nieznana profesja, pole nieobecne, nierozpoznany stan, wpis nie-tablicowy, pusta macierz). Kierunek degradacji jest fail-closed.
- `SubmissionFilter::strip()` odsiewa pola ukryte **oraz pola nieobecne w macierzy**.
- Walidacja numeru uprawnień jest **miękka**: `invalid_checksum`, `invalid_format` i `invalid_chamber` trafiają wyłącznie do `credentialWarning()`, nigdy do `errors()`.

## Review Focus

Klasy wejść, których specyfikacja nie opisuje, a które uderzą w administratora albo w użytkownika. Każda ma test w zadaniu, które jest jej właścicielem.

1. **Zapis ustawień bez uprawnień albo bez nonce'a.** Każdy ekran tego planu zapisuje konfigurację, która decyduje o tym, kto wejdzie na portal. Zapis bez `current_user_can` to eskalacja, bez nonce'a to CSRF. (Taski 5, 6, 7 — każdy ma test odrzucenia obu przypadków)
2. **Usunięcie profesji, którą mają istniejące konta.** Konta wskazują klucz, którego nie ma w konfiguracji. Domena to zniesie (`get()` zwróci `null`), ale redakcja straci informację, kim jest ten użytkownik, a lista wniosków pokaże puste miejsce. Usunięcie musi powiedzieć, ilu kont dotyczy, zanim się wykona. (Task 6)
3. **Zmiana klucza istniejącej profesji.** Konta przechowują klucz, nie etykietę. Zmiana klucza odwiązuje wszystkie konta tej profesji — cicho. Klucz raz zapisanej profesji musi być niezmienialny (edycja zmienia etykietę, typ numeru, rejestr i adres redakcji, nigdy klucz). (Task 6)
4. **Strona wskazana jako strona logowania, rejestracji albo resetu została usunięta albo wrzucona do kosza.** Ustawienia nie mogą milczeć — administrator zobaczy pustą stronę na froncie i nie będzie wiedział dlaczego. (Taski 1 i 5)
5. **Grupa pól ACF wybrana w ustawieniach przestała istnieć albo zmieniła zestaw pól.** Macierz trzyma klucze pól; pole usunięte z ACF zostaje w macierzy jako martwy wiersz, a pole dodane w ACF nie ma wpisu i jest **po cichu odsiewane** przy rejestracji. Oba przypadki muszą być widoczne na ekranie macierzy. (Taski 3, 4 i 7)

---

## File Structure

| Plik | Odpowiedzialność |
|------|------------------|
| `src/Domain/Config/Settings.php` | Ustawienia ogólne jako dane: wartości domyślne, konwersja z i do tablicy, normalizacja progów i adresów |
| `src/Domain/Config/SettingsIssue.php` | Problem w konfiguracji: kod, kontekst, waga (błąd / ostrzeżenie) |
| `src/Domain/Config/ConsistencyChecker.php` | Reguły spójności: profesja ↔ macierz, pola ACF ↔ macierz |
| `src/Domain/Fields/FieldSource.php` | Interfejs źródła pól rejestracji (klucz → etykieta) — domena nie wie o ACF |
| `src/Acf/AcfFieldSource.php` | Implementacja `FieldSource` czytająca grupę pól ACF |
| `src/Acf/AcfGroupList.php` | Lista dostępnych grup pól ACF do wyboru w ustawieniach |
| `src/Persistence/OptionStore.php` | Jedyne miejsce czytające i zapisujące opcje wtyczki, z `autoload = false` |
| `src/Persistence/SettingsRepository.php` | `Settings` ↔ opcja `pusers_settings` |
| `src/Persistence/ProfessionRepository.php` | `ProfessionCollection` ↔ opcja `pusers_professions` |
| `src/Persistence/FieldMatrixRepository.php` | `FieldMatrix` ↔ opcja `pusers_field_matrix` |
| `src/Persistence/Uninstaller.php` | Usuwanie danych przy odinstalowaniu, za bramką |
| `src/Admin/Menu.php` | Rejestracja menu i podstron, jedna zdolność w jednym miejscu |
| `src/Admin/Request.php` | Wspólny guard zapisu: uprawnienia → nonce → PRG |
| `src/Admin/SettingsScreen.php` | Ekran ustawień ogólnych (Settings API) |
| `src/Admin/ProfessionsScreen.php` | Ekran profesji: lista, dodawanie, edycja, usuwanie |
| `src/Admin/MatrixScreen.php` | Ekran macierzy profesja × pole plus raport spójności |
| `src/Admin/IssueMessages.php` | Kody problemów → komunikaty po polsku |
| `uninstall.php` | Cienki guard `WP_UNINSTALL_PLUGIN` → `Uninstaller::run()` |

---

## Task 1: Ustawienia ogólne jako dane

**Files:**
- Create: `src/Domain/Config/Settings.php`
- Test: `tests/Unit/Config/SettingsTest.php`

**Interfaces:**
- Consumes: nic
- Produces:
  - `final class Settings` — wszystkie właściwości `public readonly`:
    `bool $approvalEnabled`, `int $loginPageId`, `int $registerPageId`, `int $resetPageId`,
    `array $notifyEmails` (lista `string`), `int $confirmationTokenHours`, `int $unconfirmedPurgeDays`,
    `int $rateLimitPerHour`, `int $timeTrapSeconds`, `string $trustedProxyHeader`,
    `array $rejectionReasons` (lista `string`), `bool $deleteDataOnUninstall`, `string $acfGroupKey`
  - `Settings::defaults(): self`
  - `Settings::fromArray( array $raw ): self`
  - `Settings::toArray(): array`
- Wartości domyślne pinowane specyfikacją: `approvalEnabled = true`, `confirmationTokenHours = 48`, `unconfirmedPurgeDays = 7`, `timeTrapSeconds = 3`, `rateLimitPerHour = 10`, `deleteDataOnUninstall = false`, `trustedProxyHeader = ''`, pozostałe puste albo `0`.

- [ ] **Step 1: Napisz test `tests/Unit/Config/SettingsTest.php`**

```php
public function test_defaults_match_the_specification(): void {
    $s = Settings::defaults();
    $this->assertTrue( $s->approvalEnabled );
    $this->assertSame( 48, $s->confirmationTokenHours );
    $this->assertSame( 7, $s->unconfirmedPurgeDays );
    $this->assertSame( 3, $s->timeTrapSeconds );
    $this->assertSame( 10, $s->rateLimitPerHour );
    $this->assertFalse( $s->deleteDataOnUninstall );
    $this->assertSame( '', $s->trustedProxyHeader );
    $this->assertSame( array(), $s->notifyEmails );
}

public function test_missing_keys_fall_back_to_defaults(): void {
    $s = Settings::fromArray( array( 'approval_enabled' => false ) );
    $this->assertFalse( $s->approvalEnabled );
    $this->assertSame( 48, $s->confirmationTokenHours );
}

public function test_round_trips_through_array(): void {
    $s = Settings::defaults();
    $this->assertSame( $s->toArray(), Settings::fromArray( $s->toArray() )->toArray() );
}

public function test_keeps_only_syntactically_valid_emails_and_drops_the_rest(): void {
    $s = Settings::fromArray( array( 'notify_emails' => array( 'a@example.test', 'nie-adres', '', 'b@example.test' ) ) );
    $this->assertSame( array( 'a@example.test', 'b@example.test' ), $s->notifyEmails );
}

public function test_deduplicates_and_trims_emails_case_insensitively(): void {
    $s = Settings::fromArray( array( 'notify_emails' => array( ' A@example.test ', 'a@example.test' ) ) );
    $this->assertSame( array( 'a@example.test' ), $s->notifyEmails );
}

public function test_clamps_numeric_settings_to_sane_ranges(): void {
    $s = Settings::fromArray( array(
        'confirmation_token_hours' => 0,
        'unconfirmed_purge_days'   => -5,
        'rate_limit_per_hour'      => 0,
        'time_trap_seconds'        => -1,
    ) );
    $this->assertSame( 1, $s->confirmationTokenHours );
    $this->assertSame( 1, $s->unconfirmedPurgeDays );
    $this->assertSame( 1, $s->rateLimitPerHour );
    $this->assertSame( 0, $s->timeTrapSeconds );
}

public function test_non_numeric_values_fall_back_to_defaults(): void {
    $s = Settings::fromArray( array( 'confirmation_token_hours' => 'bzdura', 'login_page_id' => array() ) );
    $this->assertSame( 48, $s->confirmationTokenHours );
    $this->assertSame( 0, $s->loginPageId );
}

public function test_page_ids_are_never_negative(): void {
    $this->assertSame( 0, Settings::fromArray( array( 'login_page_id' => -3 ) )->loginPageId );
}

public function test_rejection_reasons_are_trimmed_and_emptied_entries_dropped(): void {
    $s = Settings::fromArray( array( 'rejection_reasons' => array( ' numer nieprawidłowy ', '', '   ' ) ) );
    $this->assertSame( array( 'numer nieprawidłowy' ), $s->rejectionReasons );
}

public function test_trusted_proxy_header_is_normalised_to_a_header_name(): void {
    $this->assertSame( 'HTTP_X_FORWARDED_FOR', Settings::fromArray( array( 'trusted_proxy_header' => ' x-forwarded-for ' ) )->trustedProxyHeader );
}
```

Walidacja adresu e-mail w domenie jest **składniowa** (jest `@`, jest kropka w części domenowej, brak białych znaków) — `is_email` należy do WordPressa i do domeny nie wejdzie.

`timeTrapSeconds` ma dolną granicę `0` (zero = pułapka wyłączona), pozostałe progi `1`.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Run: `… phpunit --testsuite unit --filter SettingsTest`
Expected: FAIL — `Class "PortalUsers\Domain\Config\Settings" not found`

- [ ] **Step 3: Zaimplementuj `Settings`**

Klucze tablicowe są `snake_case` (jak w testach), właściwości `camelCase`. Konwersja wartości: liczby przez sprawdzenie `is_numeric` i rzutowanie z dociśnięciem do granicy, łańcuchy przez `trim`, listy przez filtrowanie i przeindeksowanie `array_values`.

- [ ] **Step 4: Uruchom testy jednostkowe (razem ze strażnikiem domeny) i upewnij się, że przechodzą**

Expected: PASS, `DomainPurityTest` włącznie

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Config/Settings.php tests/Unit/Config/SettingsTest.php
git commit -m "feat: ustawienia ogolne jako dane z wartosciami domyslnymi i normalizacja"
```

---

## Task 2: Warstwa opcji i repozytoria

**Files:**
- Create: `src/Persistence/OptionStore.php`, `src/Persistence/SettingsRepository.php`, `src/Persistence/ProfessionRepository.php`, `src/Persistence/FieldMatrixRepository.php`
- Test: `tests/Integration/Persistence/OptionStoreTest.php`, `tests/Integration/Persistence/RepositoriesTest.php`

**Interfaces:**
- Consumes: `Settings`, `ProfessionCollection`, `Profession`, `FieldMatrix`
- Produces:
  - `OptionStore::{OPTION_SETTINGS = 'pusers_settings', OPTION_PROFESSIONS = 'pusers_professions', OPTION_MATRIX = 'pusers_field_matrix'}`
  - `OptionStore::read( string $option ): array` — zawsze tablica; nie-tablica w opcji czyta się jako pusta
  - `OptionStore::write( string $option, array $value ): bool` — zapis z `autoload = false`
  - `OptionStore::delete( string $option ): bool`
  - `OptionStore::all(): array` — lista nazw opcji wtyczki (dla `Uninstaller`)
  - `SettingsRepository::{get(): Settings, save( Settings $settings ): bool}`
  - `ProfessionRepository::{get(): ProfessionCollection, save( ProfessionCollection $professions ): bool}`
  - `FieldMatrixRepository::{get(): FieldMatrix, save( FieldMatrix $matrix ): bool}`

- [ ] **Step 1: Napisz test `tests/Integration/Persistence/OptionStoreTest.php`**

```php
public function test_reads_missing_option_as_empty_array(): void {
    delete_option( OptionStore::OPTION_SETTINGS );
    $this->assertSame( array(), OptionStore::read( OptionStore::OPTION_SETTINGS ) );
}

public function test_reads_non_array_option_as_empty_array(): void {
    update_option( OptionStore::OPTION_SETTINGS, 'bzdura' );
    $this->assertSame( array(), OptionStore::read( OptionStore::OPTION_SETTINGS ) );
}

public function test_writes_with_autoload_disabled(): void {
    delete_option( OptionStore::OPTION_PROFESSIONS );
    OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'a' => 1 ) );
    global $wpdb;
    $autoload = $wpdb->get_var( $wpdb->prepare(
        "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
        OptionStore::OPTION_PROFESSIONS
    ) );
    $this->assertNotSame( 'yes', $autoload );
}

public function test_round_trips_nested_values(): void {
    $value = array( 'lekarz' => array( 'pwz' => 'required' ) );
    OptionStore::write( OptionStore::OPTION_MATRIX, $value );
    $this->assertSame( $value, OptionStore::read( OptionStore::OPTION_MATRIX ) );
}
```

Test `autoload` sprawdza **nie-`'yes'`**, a nie konkretną wartość — WordPress 6.6+ zapisuje tam również `'off'` i `'auto'`.

- [ ] **Step 2: Napisz test `tests/Integration/Persistence/RepositoriesTest.php`**

```php
public function test_settings_repository_returns_defaults_when_option_is_missing(): void {
    delete_option( OptionStore::OPTION_SETTINGS );
    $this->assertSame( Settings::defaults()->toArray(), SettingsRepository::get()->toArray() );
}

public function test_settings_round_trip_through_the_option(): void {
    SettingsRepository::save( Settings::fromArray( array( 'approval_enabled' => false, 'rate_limit_per_hour' => 25 ) ) );
    $loaded = SettingsRepository::get();
    $this->assertFalse( $loaded->approvalEnabled );
    $this->assertSame( 25, $loaded->rateLimitPerHour );
}

public function test_profession_repository_round_trip_preserves_order_and_fields(): void {
    $collection = ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz',
               'registry_url' => 'https://rejestr.nil.org.pl/', 'notify_email' => 'redakcja@example.test' ),
        array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
    ) );
    ProfessionRepository::save( $collection );
    $loaded = ProfessionRepository::get();
    $this->assertSame( array( 'lekarz', 'student' ), $loaded->keys() );
    $this->assertSame( CredentialType::PhysicianPwz, $loaded->get( 'lekarz' )->credentialType );
    $this->assertSame( 'redakcja@example.test', $loaded->get( 'lekarz' )->notifyEmail );
}

public function test_matrix_repository_round_trip(): void {
    $matrix = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'miasto' => 'visible' ) ) );
    FieldMatrixRepository::save( $matrix );
    $this->assertSame( array( 'pwz' ), FieldMatrixRepository::get()->requiredFields( 'lekarz' ) );
}

public function test_repositories_return_empty_structures_when_options_hold_garbage(): void {
    update_option( OptionStore::OPTION_PROFESSIONS, 'bzdura' );
    update_option( OptionStore::OPTION_MATRIX, 42 );
    $this->assertSame( array(), ProfessionRepository::get()->keys() );
    $this->assertSame( FieldState::Hidden, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
}
```

- [ ] **Step 3: Uruchom oba testy i upewnij się, że nie przechodzą**

Run: `… phpunit -c phpunit-integration.xml.dist --filter 'OptionStoreTest|RepositoriesTest'`
Expected: FAIL — klasy nie istnieją

- [ ] **Step 4: Zaimplementuj `OptionStore` i trzy repozytoria**

`OptionStore::write()` używa `update_option( $name, $value, false )`; przy pierwszym zapisie WordPress utworzy opcję z tym samym `autoload`. Repozytoria są cienkie: `get()` czyta tablicę i podaje ją do `fromArray()` domeny, `save()` bierze `toArray()` i oddaje do `write()`. **Żadnej logiki walidacji w repozytoriach** — to robi domena.

- [ ] **Step 5: Uruchom obie suity i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Persistence tests/Integration/Persistence
git commit -m "feat: warstwa opcji i repozytoria ustawien, profesji oraz macierzy"
```

---

## Task 3: Pole numeru w profesji, źródło pól i reguły spójności konfiguracji

**Files:**
- Modify: `src/Domain/Profession/Profession.php` (szósta właściwość `credentialField`)
- Create: `src/Domain/Fields/FieldSource.php`, `src/Domain/Config/SettingsIssue.php`, `src/Domain/Config/ConsistencyChecker.php`
- Test: `tests/Unit/Profession/ProfessionTest.php` (rozszerzenie), `tests/Integration/Persistence/RepositoriesTest.php` (rozszerzenie), `tests/Unit/Config/ConsistencyCheckerTest.php`

**Interfaces:**
- Consumes: `ProfessionCollection`, `FieldMatrix`, `FieldState`, `CredentialType`, `ProfessionRepository`
- Produces:
  - `Profession::__construct( string $key, string $label, CredentialType $credentialType, string $registryUrl = '', string $notifyEmail = '', string $credentialField = '' )` — szósty argument, `public readonly`, klucz tablicowy `credential_field`, **obecny w `toArray()`**
  - `interface FieldSource { public function fields(): array; }` — zwraca `array<string klucz, string etykieta>`, kolejność jak w źródle
  - `final class SettingsIssue` — `public readonly string $code`, `public readonly string $severity`, `public readonly array $context`; stałe `SettingsIssue::{ERROR = 'error', WARNING = 'warning'}`; `SettingsIssue::{error( string $code, array $context = [] ): self, warning( string $code, array $context = [] ): self}`
  - `final class ConsistencyChecker { public function __construct( private ProfessionCollection $professions, private FieldMatrix $matrix, private FieldSource $fields ) {} public function issues(): array; }` — zwraca listę `SettingsIssue`
- Kody problemów: `profession_without_matrix_row`, `credential_field_not_required`, `field_not_configured`, `matrix_field_not_in_source`, `matrix_profession_unknown`

**Dlaczego `Profession` rośnie o jedną właściwość:** każda profesja wskazuje **swoje** pole numeru — lekarz `pwz`, pielęgniarka `pwzp`. `ConsistencyChecker` musi wiedzieć, które pole sprawdzić, a `SubmissionValidator` z Planu 1 dostaje klucz pola numeru jako argument `validate()`, czyli ktoś musi mu go podać. `Profession::toArray()` zwraca stałą listę pięciu kluczy, więc **dodatkowy klucz w tablicy wejściowej zostałby zgubiony przy zapisie** — właściwość musi wejść do klasy, nie obok niej.

Zmiana jest addytywna: szósty argument z wartością domyślną, więc istniejące wywołania i testy z Planu 1 działają bez zmian.

- [ ] **Step 1: Napisz testy rozszerzające `tests/Unit/Profession/ProfessionTest.php`**

```php
public function test_credential_field_round_trips_through_array(): void {
    $raw = array(
        'key'              => 'lekarz',
        'label'            => 'Lekarz',
        'credential_type'  => 'physician_pwz',
        'registry_url'     => 'https://rejestr.nil.org.pl/',
        'notify_email'     => 'redakcja@example.test',
        'credential_field' => 'pwz',
    );
    $this->assertSame( $raw, Profession::fromArray( $raw )->toArray() );
}

public function test_credential_field_defaults_to_empty_and_is_trimmed(): void {
    $this->assertSame( '', Profession::fromArray( array( 'key' => 'x', 'label' => 'X' ) )->credentialField );
    $this->assertSame( 'pwz', Profession::fromArray( array( 'key' => 'x', 'label' => 'X', 'credential_field' => ' pwz ' ) )->credentialField );
}
```

Uwaga: pierwszy test pilnuje też **kolejności kluczy** w `toArray()` — `credential_field` idzie na końcu, za `notify_email`.

- [ ] **Step 2: Napisz test rozszerzający `tests/Integration/Persistence/RepositoriesTest.php`**

```php
public function test_profession_repository_preserves_the_credential_field(): void {
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
    ) ) );
    $this->assertSame( 'pwz', ProfessionRepository::get()->get( 'lekarz' )->credentialField );
}
```

Ten test padłby przed zmianą w `Profession` — `toArray()` nie zwracało tego klucza, więc zapis gubił wartość. To jest dowód, po co ta zmiana.

- [ ] **Step 3: Uruchom oba testy i upewnij się, że nie przechodzą**

Run: `… phpunit --testsuite unit --filter ProfessionTest` oraz `… phpunit -c phpunit-integration.xml.dist --filter RepositoriesTest`
Expected: FAIL — brak właściwości `credentialField`, a `toArray()` nie zwraca `credential_field`

- [ ] **Step 4: Dodaj `credentialField` do `Profession`**

Szósty argument konstruktora z domyślnym `''`, konwersja przez istniejący helper `text()` (robi `trim`), klucz `credential_field` w `fromArray()` i na końcu `toArray()`. Nie zmieniaj niczego innego w tej klasie.

- [ ] **Step 5: Uruchom obie suity i upewnij się, że przechodzą**

Expected: PASS — wszystkie testy `Profession` z Planu 1 również, bo zmiana jest addytywna

- [ ] **Step 6: Napisz test `tests/Unit/Config/ConsistencyCheckerTest.php`**

Testy 1, 3 i 4 pokrywają punkt 5 z sekcji „Review Focus".

```php
private function source( array $fields ): FieldSource {
    return new class( $fields ) implements FieldSource {
        public function __construct( private array $fields ) {}
        public function fields(): array { return $this->fields; }
    };
}

private function codes( ConsistencyChecker $checker ): array {
    return array_map( static fn ( SettingsIssue $i ): string => $i->code, $checker->issues() );
}

public function test_clean_configuration_reports_nothing(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array(
            array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
        ) ),
        FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'zgoda' => 'required' ) ) ),
        $this->source( array( 'pwz' => 'Numer PWZ', 'zgoda' => 'Regulamin' ) )
    );
    $this->assertSame( array(), $this->codes( $checker ) );
}

public function test_profession_without_a_matrix_row_is_an_error(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
        FieldMatrix::fromArray( array() ),
        $this->source( array( 'zgoda' => 'Regulamin' ) )
    );
    $this->assertSame( array( 'profession_without_matrix_row' ), $this->codes( $checker ) );
    $this->assertSame( SettingsIssue::ERROR, $checker->issues()[0]->severity );
    $this->assertSame( 'student', $checker->issues()[0]->context['profession'] );
}

public function test_profession_with_credential_type_whose_credential_field_is_not_required_is_an_error(): void {
    // Typ numeru ustawiony, ale pole numeru ukryte w macierzy: zgłoszenie przejdzie bez numeru,
    // bez błędu i bez ostrzeżenia dla redakcji.
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array(
            array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
        ) ),
        FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ),
        $this->source( array( 'pwz' => 'Numer PWZ' ) )
    );
    $this->assertSame( array( 'credential_field_not_required' ), $this->codes( $checker ) );
}

public function test_field_present_in_source_but_absent_from_a_matrix_row_is_a_warning(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
        FieldMatrix::fromArray( array( 'student' => array( 'zgoda' => 'required' ) ) ),
        $this->source( array( 'zgoda' => 'Regulamin', 'uczelnia' => 'Uczelnia' ) )
    );
    $issues = $checker->issues();
    $this->assertSame( array( 'field_not_configured' ), $this->codes( $checker ) );
    $this->assertSame( SettingsIssue::WARNING, $issues[0]->severity );
    $this->assertSame( 'uczelnia', $issues[0]->context['field'] );
    $this->assertSame( 'student', $issues[0]->context['profession'] );
}

public function test_matrix_field_missing_from_source_is_a_warning(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
        FieldMatrix::fromArray( array( 'student' => array( 'skasowane' => 'required' ) ) ),
        $this->source( array() )
    );
    $this->assertSame( array( 'matrix_field_not_in_source' ), $this->codes( $checker ) );
}

public function test_matrix_row_for_an_unknown_profession_is_a_warning(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array() ),
        FieldMatrix::fromArray( array( 'skasowana' => array( 'zgoda' => 'required' ) ) ),
        $this->source( array( 'zgoda' => 'Regulamin' ) )
    );
    $this->assertSame( array( 'matrix_profession_unknown' ), $this->codes( $checker ) );
}

public function test_profession_without_credential_type_needs_no_credential_field(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array( array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ) ) ),
        FieldMatrix::fromArray( array( 'student' => array( 'zgoda' => 'required' ) ) ),
        $this->source( array( 'zgoda' => 'Regulamin' ) )
    );
    $this->assertSame( array(), $this->codes( $checker ) );
}

public function test_issues_are_ordered_errors_before_warnings(): void {
    $checker = new ConsistencyChecker(
        ProfessionCollection::fromArray( array(
            array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
        ) ),
        FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ),
        $this->source( array( 'pwz' => 'Numer PWZ', 'zgoda' => 'Regulamin' ) )
    );
    $severities = array_map( static fn ( SettingsIssue $i ): string => $i->severity, $checker->issues() );
    $this->assertSame( array( SettingsIssue::ERROR, SettingsIssue::WARNING ), $severities );
}
```

- [ ] **Step 7: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 8: Zaimplementuj `FieldSource`, `SettingsIssue` i `ConsistencyChecker`**

`issues()` przechodzi: każda profesja (wiersz w macierzy? pole numeru wymagane? pola ze źródła bez wpisu?), potem każdy wiersz macierzy (profesja znana? pola istnieją w źródle?). Wynik sortowany tak, by błędy wyprzedzały ostrzeżenia, z zachowaniem kolejności w obrębie wagi.

`FieldSource` leży w `src/Domain/Fields/`, więc **nie wolno w nim wspomnieć ACF w żadnym komentarzu** — strażnik czystości domeny jest allowlistą i wyłapie nazwę funkcji, ale nazwa „ACF" w prozie też nie ma tu czego robić. Opisuj „źródło definicji pól".

- [ ] **Step 9: Uruchom testy jednostkowe ze strażnikiem i upewnij się, że przechodzą**

Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add src/Domain tests/Unit/Profession/ProfessionTest.php tests/Unit/Config tests/Integration/Persistence/RepositoriesTest.php
git commit -m "feat: pole numeru w profesji i reguly spojnosci konfiguracji jako czysta domena"
```

---

## Task 4: Adapter ACF — grupy i pola

**Files:**
- Create: `src/Acf/AcfGroupList.php`, `src/Acf/AcfFieldSource.php`
- Test: `tests/Integration/Acf/AcfFieldSourceTest.php`

**Interfaces:**
- Consumes: `FieldSource`
- Produces:
  - `AcfGroupList::groups(): array` — `array<string klucz_grupy, string tytuł>`; pusta tablica, gdy ACF nie jest dostępny
  - `AcfFieldSource::{__construct( string $groupKey ), fields(): array, groupExists(): bool}` — implementuje `FieldSource`

**Decyzja:** grupę pól rejestracji **wskazuje administrator** w ustawieniach (`Settings::$acfGroupKey`), a nie zgadujemy jej z reguł lokalizacji ACF. Reguły lokalizacji dla formularzy użytkownika są nieoczywiste, a jawny wybór usuwa pytanie „która grupa jest tą właściwą".

- [ ] **Step 1: Napisz test `tests/Integration/Acf/AcfFieldSourceTest.php`**

Pokrywa punkt 5 z „Review Focus" po stronie adaptera. ACF Pro **nie jest** w kontenerze testowym, więc test podstawia funkcje ACF filtrem — patrz Step 3.

```php
public function test_returns_empty_list_when_acf_is_unavailable(): void {
    $this->assertSame( array(), ( new AcfFieldSource( 'group_abc' ) )->fields() );
    $this->assertFalse( ( new AcfFieldSource( 'group_abc' ) )->groupExists() );
    $this->assertSame( array(), AcfGroupList::groups() );
}

public function test_maps_acf_fields_to_key_label_pairs_preserving_order(): void {
    add_filter( 'pusers_acf_fields', static fn (): array => array(
        array( 'name' => 'pwz', 'label' => 'Numer PWZ' ),
        array( 'name' => 'miasto', 'label' => 'Miasto' ),
    ) );
    $this->assertSame( array( 'pwz' => 'Numer PWZ', 'miasto' => 'Miasto' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
}

public function test_field_without_a_name_is_skipped(): void {
    add_filter( 'pusers_acf_fields', static fn (): array => array(
        array( 'label' => 'Bez nazwy' ),
        array( 'name' => 'zgoda', 'label' => 'Regulamin' ),
    ) );
    $this->assertSame( array( 'zgoda' => 'Regulamin' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
}

public function test_field_without_a_label_falls_back_to_its_name(): void {
    add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz' ) ) );
    $this->assertSame( array( 'pwz' => 'pwz' ), ( new AcfFieldSource( 'group_abc' ) )->fields() );
}

public function test_group_that_disappeared_reports_absent_and_yields_no_fields(): void {
    add_filter( 'pusers_acf_groups', static fn (): array => array() );
    $source = new AcfFieldSource( 'group_znikla' );
    $this->assertFalse( $source->groupExists() );
    $this->assertSame( array(), $source->fields() );
}
```

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 3: Zaimplementuj oba adaptery z szwami testowymi**

`AcfGroupList::groups()` czyta `acf_get_field_groups()` tylko gdy `function_exists`, mapuje na `key => title`, i przepuszcza wynik przez filtr **`pusers_acf_groups`**. `AcfFieldSource::fields()` czyta `acf_get_fields( $groupKey )` tylko gdy `function_exists` **i** `groupExists()`, i przepuszcza surową listę pól przez filtr **`pusers_acf_fields`** przed mapowaniem. `groupExists()` sprawdza obecność klucza w `AcfGroupList::groups()`.

Dwa filtry są jedynym sposobem przetestowania tego adaptera bez licencjonowanego ACF Pro w kontenerze. Udokumentuj je w docblokach jako szwy testowe i punkty rozszerzeń.

- [ ] **Step 4: Uruchom suitę integracyjną i upewnij się, że przechodzi**

Expected: PASS. Zdejmij filtry w `tear_down`, inaczej wyciekną na inne testy.

- [ ] **Step 5: Commit**

```bash
git add src/Acf tests/Integration/Acf
git commit -m "feat: adapter ACF dla grup i pol rejestracji, ze szwami testowymi"
```

---

## Task 5: Menu, guard zapisu i ekran ustawień ogólnych

**Files:**
- Create: `src/Admin/Menu.php`, `src/Admin/Request.php`, `src/Admin/SettingsScreen.php`, `src/Admin/IssueMessages.php`
- Modify: `src/Plugin.php` (rejestracja `Menu` pod `pusers_register_features`)
- Test: `tests/Integration/Admin/RequestTest.php`, `tests/Integration/Admin/SettingsScreenTest.php`

**Interfaces:**
- Consumes: `Settings`, `SettingsRepository`, `AcfGroupList`, `Plugin::load_features()` → `pusers_register_features`
- Produces:
  - `Menu::{CAPABILITY = 'manage_options', PARENT = 'users.php', SLUG_SETTINGS = 'pusers-settings', SLUG_PROFESSIONS = 'pusers-professions', SLUG_MATRIX = 'pusers-matrix', register(): void, url( string $slug, array $args = [] ): string}`
  - `Request::{guard( string $action ): void, redirect( string $slug, string $notice, array $args = [] ): void, notice(): string}` — `guard()` sprawdza **najpierw uprawnienia, potem nonce**, i przy niepowodzeniu kończy żądanie przez `wp_die()`
  - `IssueMessages::text( SettingsIssue $issue ): string`
- Pole nonce'a: `pusers_nonce`. Akcje zapisu: `pusers_save_settings`, `pusers_save_professions`, `pusers_save_matrix`.

- [ ] **Step 1: Napisz test `tests/Integration/Admin/RequestTest.php`**

Pokrywa punkt 1 z „Review Focus".

```php
public function test_guard_rejects_a_user_without_the_capability(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
    $_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
    $this->expectException( WPDieException::class );
    Request::guard( 'pusers_save_settings' );
}

public function test_guard_rejects_a_missing_nonce_even_for_an_administrator(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    unset( $_REQUEST['pusers_nonce'] );
    $this->expectException( WPDieException::class );
    Request::guard( 'pusers_save_settings' );
}

public function test_guard_rejects_a_nonce_issued_for_another_action(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    $_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_matrix' );
    $this->expectException( WPDieException::class );
    Request::guard( 'pusers_save_settings' );
}

public function test_guard_passes_for_an_administrator_with_a_matching_nonce(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    $_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
    Request::guard( 'pusers_save_settings' );
    $this->assertTrue( true ); // brak wyjątku jest wynikiem
}

public function test_capability_is_checked_before_the_nonce(): void {
    // Subskrybent z poprawnym nonce'em i subskrybent bez nonce'a muszą dostać to samo:
    // odmowę z powodu uprawnień, nie podpowiedź, że nonce był zły.
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
    $_REQUEST['pusers_nonce'] = wp_create_nonce( 'pusers_save_settings' );
    $first = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
    unset( $_REQUEST['pusers_nonce'] );
    $second = $this->captureDieMessage( static fn () => Request::guard( 'pusers_save_settings' ) );
    $this->assertSame( $first, $second );
}
```

`captureDieMessage()` to prywatny helper łapiący `WPDieException` i zwracający jej komunikat.

- [ ] **Step 2: Napisz test `tests/Integration/Admin/SettingsScreenTest.php`**

Testy 3 i 4 pokrywają punkt 4 z „Review Focus".

```php
public function test_settings_submenu_is_registered_under_users(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    do_action( 'pusers_register_features' );
    do_action( 'admin_menu' );
    global $submenu;
    $slugs = wp_list_pluck( $submenu['users.php'] ?? array(), 2 );
    $this->assertContains( Menu::SLUG_SETTINGS, $slugs );
}

public function test_saving_settings_persists_normalised_values(): void {
    $this->asAdminPosting( 'pusers_save_settings', array(
        'approval_enabled'    => '1',
        'notify_emails'       => "a@example.test\nnie-adres\nb@example.test",
        'rate_limit_per_hour' => '0',
    ) );
    SettingsScreen::handle_save();
    $saved = SettingsRepository::get();
    $this->assertTrue( $saved->approvalEnabled );
    $this->assertSame( array( 'a@example.test', 'b@example.test' ), $saved->notifyEmails );
    $this->assertSame( 1, $saved->rateLimitPerHour );
}

public function test_page_that_does_not_exist_is_reported_as_an_issue(): void {
    SettingsRepository::save( Settings::fromArray( array( 'login_page_id' => 999999 ) ) );
    $this->assertContains( 'page_missing', SettingsScreen::page_issues() );
}

public function test_page_in_the_trash_is_reported_as_an_issue(): void {
    $page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'trash' ) );
    SettingsRepository::save( Settings::fromArray( array( 'register_page_id' => $page ) ) );
    $this->assertContains( 'page_not_published', SettingsScreen::page_issues() );
}

public function test_published_page_reports_no_issue(): void {
    $page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
    SettingsRepository::save( Settings::fromArray( array( 'reset_page_id' => $page ) ) );
    $this->assertSame( array(), SettingsScreen::page_issues() );
}

public function test_rendered_screen_escapes_stored_values(): void {
    SettingsRepository::save( Settings::fromArray( array( 'notify_emails' => array( 'a@example.test' ) ) ) );
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    ob_start();
    SettingsScreen::render();
    $html = (string) ob_get_clean();
    $this->assertStringNotContainsString( '<script', $html );
    $this->assertStringContainsString( 'pusers_nonce', $html );
}
```

`asAdminPosting()` to prywatny helper: tworzy administratora, ustawia `$_POST`/`$_REQUEST` wraz z nonce'em dla podanej akcji.

- [ ] **Step 3: Uruchom oba testy i upewnij się, że nie przechodzą**

Expected: FAIL — klasy nie istnieją

- [ ] **Step 4: Zaimplementuj `Menu`, `Request`, `IssueMessages` i `SettingsScreen`**

`Menu::register()` podpina się na `admin_menu` i dodaje trzy podstrony pod `users.php` z `Menu::CAPABILITY`. `Request::guard()` robi `current_user_can` → `wp_die` z komunikatem o uprawnieniach, potem `check_admin_referer` dla przekazanej akcji; oba komunikaty muszą być identyczne (test 5 ze Step 1).

`SettingsScreen::page_issues(): array` zwraca kody: `page_missing`, gdy wskazane `ID` nie jest stroną; `page_not_published`, gdy status strony nie jest `publish`. Puste `ID` (wartość `0`) nie jest problemem — administrator po prostu jeszcze nie wybrał strony.

`SettingsScreen::render()` wypisuje formularz: przełącznik akceptacji, trzy listy wyboru stron (`wp_dropdown_pages`), pole adresów redakcji (po jednym w linii), progi antyspamu, nagłówek zaufanego proxy, powody odrzucenia, wybór grupy pól ACF (`AcfGroupList::groups()`) i bramkę usuwania danych przy odinstalowaniu. Ostrzeżenie przy bramce musi mówić wprost, co zostanie skasowane.

`SettingsScreen::handle_save()` woła `Request::guard( 'pusers_save_settings' )`, składa tablicę `snake_case` z `$_POST` przez `wp_unslash` i konkretne klucze (nigdy całe `$_POST`), podaje ją do `Settings::fromArray()`, zapisuje i przekierowuje (PRG).

- [ ] **Step 5: Podepnij `Menu` w `src/Plugin.php`**

W `load_features()`, pod hookiem `pusers_register_features`: `add_action( 'pusers_register_features', array( Menu::class, 'register' ) )` rejestrowane w `boot()`, albo wywołanie `Menu::register()` wprost z callbacku hooka. **Nie rejestruj ekranów poza tym hookiem** — bez ACF Pro konfiguracja pól nie ma sensu.

- [ ] **Step 6: Uruchom obie suity, PHPStan i PHPCS**

Expected: PASS, zero zgłoszeń. PHPCS z `WordPress-Extra` będzie pilnował eskejpowania — każde `echo` w `render()` musi być owinięte.

- [ ] **Step 7: Commit**

```bash
git add src/Admin src/Plugin.php tests/Integration/Admin
git commit -m "feat: menu, guard zapisu i ekran ustawien ogolnych"
```

---

## Task 6: Ekran profesji

**Files:**
- Create: `src/Admin/ProfessionsScreen.php`
- Test: `tests/Integration/Admin/ProfessionsScreenTest.php`

**Interfaces:**
- Consumes: `Menu`, `Request`, `ProfessionRepository`, `ProfessionCollection`, `Profession`, `CredentialType`, `AcfFieldSource`
- Produces: `ProfessionsScreen::{render(): void, handle_save(): void, handle_delete(): void, accounts_using( string $professionKey ): int}`

**Decyzja o kluczu:** klucz profesji jest **niezmienialny po utworzeniu**. Edycja zmienia etykietę, typ numeru, pole numeru, adres rejestru i adres redakcji — nigdy klucz. Konta przechowują klucz, nie etykietę, więc zmiana klucza odwiązałaby je po cichu.

**Decyzja o typie numeru:** lista wyboru z czterema wartościami `CredentialType`, nigdy pole tekstowe. Literówka w typie cicho zdejmuje weryfikację numeru — to wymóg z `docs/superpowers/backlog.md`.

- [ ] **Step 1: Napisz test `tests/Integration/Admin/ProfessionsScreenTest.php`**

Testy 2–5 pokrywają punkty 2 i 3 z „Review Focus".

```php
public function test_adding_a_profession_persists_it_with_the_selected_credential_type(): void {
    $this->asAdminPosting( 'pusers_save_professions', array(
        'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz',
            'credential_type' => 'physician_pwz', 'credential_field' => 'pwz',
            'registry_url' => 'https://rejestr.nil.org.pl/', 'notify_email' => 'redakcja@example.test' ),
    ) );
    ProfessionsScreen::handle_save();
    $saved = ProfessionRepository::get()->get( 'lekarz' );
    $this->assertSame( CredentialType::PhysicianPwz, $saved->credentialType );
    $this->assertSame( 'redakcja@example.test', $saved->notifyEmail );
}

public function test_unknown_credential_type_from_the_request_is_rejected_not_degraded(): void {
    // Degradacja do None jest poprawna przy czytaniu zepsutej konfiguracji,
    // ale przy zapisie z panelu oznaczałaby ciche wyłączenie weryfikacji numeru.
    $this->asAdminPosting( 'pusers_save_professions', array(
        'profession' => array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pw' ),
    ) );
    ProfessionsScreen::handle_save();
    $this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
}

public function test_editing_an_existing_profession_cannot_change_its_key(): void {
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
    ) ) );
    $this->asAdminPosting( 'pusers_save_professions', array(
        'original_key' => 'lekarz',
        'profession'   => array( 'key' => 'doktor', 'label' => 'Doktor', 'credential_type' => 'physician_pwz' ),
    ) );
    ProfessionsScreen::handle_save();
    $collection = ProfessionRepository::get();
    $this->assertSame( array( 'lekarz' ), $collection->keys() );
    $this->assertSame( 'Doktor', $collection->get( 'lekarz' )->label );
}

public function test_accounts_using_counts_users_with_that_profession(): void {
    $user = self::factory()->user->create();
    update_user_meta( $user, 'pusers_profession', 'lekarz' );
    $this->assertSame( 1, ProfessionsScreen::accounts_using( 'lekarz' ) );
    $this->assertSame( 0, ProfessionsScreen::accounts_using( 'student' ) );
}

public function test_deleting_a_profession_in_use_requires_explicit_confirmation(): void {
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz' ),
    ) ) );
    $user = self::factory()->user->create();
    update_user_meta( $user, 'pusers_profession', 'lekarz' );
    $this->asAdminPosting( 'pusers_save_professions', array( 'delete' => 'lekarz' ) );
    ProfessionsScreen::handle_delete();
    $this->assertNotNull( ProfessionRepository::get()->get( 'lekarz' ), 'Bez potwierdzenia profesja zostaje.' );

    $this->asAdminPosting( 'pusers_save_professions', array( 'delete' => 'lekarz', 'confirm_accounts' => '1' ) );
    ProfessionsScreen::handle_delete();
    $this->assertNull( ProfessionRepository::get()->get( 'lekarz' ) );
}

public function test_deleting_an_unused_profession_needs_no_confirmation(): void {
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'student', 'label' => 'Student', 'credential_type' => 'none' ),
    ) ) );
    $this->asAdminPosting( 'pusers_save_professions', array( 'delete' => 'student' ) );
    ProfessionsScreen::handle_delete();
    $this->assertNull( ProfessionRepository::get()->get( 'student' ) );
}

public function test_rendered_screen_offers_only_the_four_known_credential_types(): void {
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    ob_start();
    ProfessionsScreen::render();
    $html = (string) ob_get_clean();
    foreach ( CredentialType::cases() as $case ) {
        $this->assertStringContainsString( 'value="' . $case->value . '"', $html );
    }
    $this->assertStringNotContainsString( 'name="profession[credential_type]" type="text"', $html );
}
```

Klucz metadanej profesji konta — `pusers_profession` — jest tu ustalany po raz pierwszy i Plan 3 musi go użyć przy zapisie zgłoszenia.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasa nie istnieje

- [ ] **Step 3: Zaimplementuj `ProfessionsScreen`**

`handle_save()`: `Request::guard( 'pusers_save_professions' )`, odczyt konkretnych kluczy z `$_POST` przez `wp_unslash`, odrzucenie zapisu gdy `credential_type` nie jest jedną z wartości `CredentialType` (komunikat i PRG, bez zapisu), zachowanie `original_key` jako klucza przy edycji, zapis całej kolekcji.

`accounts_using()` liczy użytkowników przez `get_users` z `meta_key = 'pusers_profession'` i `meta_value` równym kluczowi, `fields => 'ID'`, `count_total`.

`handle_delete()`: gdy `accounts_using() > 0` i brak `confirm_accounts`, przekierowuje z komunikatem mówiącym **ilu kont** dotyczy usunięcie; z potwierdzeniem usuwa profesję z kolekcji. Usunięcie nie rusza metadanych kont — one zostają z kluczem, którego nie ma w konfiguracji, co `ConsistencyChecker` zgłosi jako `matrix_profession_unknown` albo pustą profesję na liście wniosków.

`render()`: tabela profesji z liczbą kont przy każdej, formularz dodawania i edycji, lista wyboru typu numeru z `CredentialType::cases()`, lista wyboru pola numeru z `AcfFieldSource`.

- [ ] **Step 4: Uruchom obie suity, PHPStan i PHPCS**

Expected: PASS, zero zgłoszeń

- [ ] **Step 5: Commit**

```bash
git add src/Admin/ProfessionsScreen.php tests/Integration/Admin/ProfessionsScreenTest.php
git commit -m "feat: ekran profesji z niezmiennym kluczem i potwierdzeniem usuniecia"
```

---

## Task 7: Ekran macierzy profesja × pole

**Files:**
- Create: `src/Admin/MatrixScreen.php`
- Test: `tests/Integration/Admin/MatrixScreenTest.php`

**Interfaces:**
- Consumes: `Menu`, `Request`, `FieldMatrixRepository`, `ProfessionRepository`, `SettingsRepository`, `AcfFieldSource`, `ConsistencyChecker`, `IssueMessages`, `FieldState`
- Produces: `MatrixScreen::{render(): void, handle_save(): void, checker(): ConsistencyChecker}`

- [ ] **Step 1: Napisz test `tests/Integration/Admin/MatrixScreenTest.php`**

Test 4 pokrywa punkt 5 z „Review Focus" po stronie ekranu.

```php
public function test_saving_the_matrix_persists_the_three_states(): void {
    $this->asAdminPosting( 'pusers_save_matrix', array(
        'matrix' => array( 'lekarz' => array( 'pwz' => 'required', 'miasto' => 'visible', 'pwzp' => 'hidden' ) ),
    ) );
    MatrixScreen::handle_save();
    $matrix = FieldMatrixRepository::get();
    $this->assertSame( FieldState::Required, $matrix->stateFor( 'lekarz', 'pwz' ) );
    $this->assertSame( FieldState::Visible, $matrix->stateFor( 'lekarz', 'miasto' ) );
    $this->assertSame( FieldState::Hidden, $matrix->stateFor( 'lekarz', 'pwzp' ) );
}

public function test_unknown_state_from_the_request_is_stored_as_hidden(): void {
    $this->asAdminPosting( 'pusers_save_matrix', array(
        'matrix' => array( 'lekarz' => array( 'pwz' => 'bzdura' ) ),
    ) );
    MatrixScreen::handle_save();
    $this->assertSame( FieldState::Hidden, FieldMatrixRepository::get()->stateFor( 'lekarz', 'pwz' ) );
}

public function test_screen_lists_every_field_from_the_source_including_unconfigured_ones(): void {
    add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
    add_filter( 'pusers_acf_fields', static fn (): array => array(
        array( 'name' => 'pwz', 'label' => 'Numer PWZ' ),
        array( 'name' => 'nowe', 'label' => 'Nowe pole' ),
    ) );
    SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'none' ),
    ) ) );
    FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required' ) ) ) );
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    ob_start();
    MatrixScreen::render();
    $html = (string) ob_get_clean();
    $this->assertStringContainsString( 'Nowe pole', $html );
    $this->assertStringContainsString( 'nieskonfigurowane', $html );
}

public function test_screen_reports_consistency_issues(): void {
    add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
    add_filter( 'pusers_acf_fields', static fn (): array => array( array( 'name' => 'pwz', 'label' => 'Numer PWZ' ) ) );
    SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
    ProfessionRepository::save( ProfessionCollection::fromArray( array(
        array( 'key' => 'lekarz', 'label' => 'Lekarz', 'credential_type' => 'physician_pwz', 'credential_field' => 'pwz' ),
    ) ) );
    FieldMatrixRepository::save( FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'hidden' ) ) ) );
    $codes = array_map( static fn ( $i ): string => $i->code, MatrixScreen::checker()->issues() );
    $this->assertContains( 'credential_field_not_required', $codes );
}

public function test_rendered_screen_carries_a_nonce_and_escapes_field_labels(): void {
    add_filter( 'pusers_acf_fields', static fn (): array => array(
        array( 'name' => 'x', 'label' => '<script>alert(1)</script>' ),
    ) );
    add_filter( 'pusers_acf_groups', static fn (): array => array( 'group_abc' => 'Rejestracja' ) );
    SettingsRepository::save( Settings::fromArray( array( 'acf_group_key' => 'group_abc' ) ) );
    wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
    ob_start();
    MatrixScreen::render();
    $html = (string) ob_get_clean();
    $this->assertStringContainsString( 'pusers_nonce', $html );
    $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
}
```

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasa nie istnieje

- [ ] **Step 3: Zaimplementuj `MatrixScreen`**

`checker()` składa `ConsistencyChecker` z repozytoriów i `AcfFieldSource` zbudowanego z `Settings::$acfGroupKey`.

`render()`: tabela, wiersze to pola ze **źródła** (nie z macierzy), kolumny to profesje, w każdej komórce lista wyboru z trzema stanami. Pole bez wpisu w macierzy dla danej profesji jest oznaczone słowem **nieskonfigurowane** przy liście wyboru. Nad tabelą raport z `checker()` przez `IssueMessages::text()`, błędy przed ostrzeżeniami. Pola obecne w macierzy, a nieobecne w źródle, pokazane osobno jako wiersze martwe, z możliwością usunięcia.

`handle_save()`: `Request::guard( 'pusers_save_matrix' )`, odczyt `matrix` z `$_POST` przez `wp_unslash`, zapis przez `FieldMatrix::fromArray()` — nieznany stan sam zdegraduje się do `Hidden`, co test 2 potwierdza. PRG.

- [ ] **Step 4: Uruchom obie suity, PHPStan i PHPCS**

Expected: PASS, zero zgłoszeń. Pamiętaj o zdjęciu filtrów ACF w `tear_down`.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/MatrixScreen.php tests/Integration/Admin/MatrixScreenTest.php
git commit -m "feat: ekran macierzy z raportem spojnosci i polami nieskonfigurowanymi"
```

---

## Task 8: Odinstalowanie za bramką

**Files:**
- Create: `uninstall.php`, `src/Persistence/Uninstaller.php`
- Modify: `src/Persistence/Migrations.php` (udostępnienie nazwy opcji wersji schematu)
- Test: `tests/Integration/Persistence/UninstallerTest.php`

**Interfaces:**
- Consumes: `OptionStore`, `Migrations`
- Produces:
  - `Migrations::OPTION_DB_VERSION = 'pusers_db_version'` — **publiczna** stała zamiast dzisiejszej prywatnej `OPTION`. `Uninstaller` musi znać tę nazwę, a plan zabrania hardkodowania nazw w drugim miejscu. Zmień prywatną stałą na publiczną i podmień jej użycia w `Migrations`; wartość zostaje ta sama, więc żadne dane się nie poruszą.
  - `Uninstaller::{OPTION_GATE = 'pusers_delete_data_on_uninstall', CONSTANT_GATE = 'PORTAL_USERS_DELETE_DATA_ON_UNINSTALL', should_delete_data(): bool, run(): void}`

**Nośny inwariant:** domyślnie `run()` **nie kasuje niczego**. Usunięcie wtyczki nie może zniszczyć wniosków i logu zdarzeń. Kasowanie wyłącznie przy włączonej opcji albo ustawionej stałej, przy czym stała ma priorytet nad opcją.

- [ ] **Step 1: Napisz test `tests/Integration/Persistence/UninstallerTest.php`**

```php
public function test_does_nothing_by_default(): void {
    Migrations::run();
    OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'x' => 1 ) );
    delete_option( Uninstaller::OPTION_GATE );
    Uninstaller::run();
    $this->assertNotSame( array(), OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
    $this->assertTrue( $this->tableExists( Migrations::table( 'event_log' ) ) );
}

public function test_gate_option_enables_deletion(): void {
    Migrations::run();
    OptionStore::write( OptionStore::OPTION_PROFESSIONS, array( 'x' => 1 ) );
    update_option( Uninstaller::OPTION_GATE, '1' );
    Uninstaller::run();
    $this->assertSame( array(), OptionStore::read( OptionStore::OPTION_PROFESSIONS ) );
    $this->assertFalse( $this->tableExists( Migrations::table( 'event_log' ) ) );
    $this->assertFalse( $this->tableExists( Migrations::table( 'terms_versions' ) ) );
    $this->assertFalse( get_option( 'pusers_db_version' ) );
}

public function test_deletion_removes_every_plugin_option(): void {
    update_option( Uninstaller::OPTION_GATE, '1' );
    foreach ( OptionStore::all() as $option ) {
        OptionStore::write( $option, array( 'x' => 1 ) );
    }
    Uninstaller::run();
    foreach ( OptionStore::all() as $option ) {
        $this->assertFalse( get_option( $option ), $option . ' powinna zniknąć' );
    }
}

public function test_should_delete_data_is_false_without_gate_and_true_with_option(): void {
    delete_option( Uninstaller::OPTION_GATE );
    $this->assertFalse( Uninstaller::should_delete_data() );
    update_option( Uninstaller::OPTION_GATE, '1' );
    $this->assertTrue( Uninstaller::should_delete_data() );
}
```

Testy kasują prawdziwe tabele — ten plik musi zdejmować filtry tabel tymczasowych i odtwarzać bazę w `tear_down` tak samo jak `MigrationsTest` z Planu 1 (`try/finally`, `Migrations::run()` na końcu). Przeczytaj tamten plik przed napisaniem tego.

- [ ] **Step 2: Uruchom test i upewnij się, że nie przechodzi**

Expected: FAIL — klasa nie istnieje

- [ ] **Step 3: Zaimplementuj `Uninstaller`**

`should_delete_data()`: stała zdefiniowana → jej wartość logiczna; w przeciwnym razie wartość opcji. `run()`: przy `false` natychmiastowy `return`; przy `true` usunięcie wszystkich opcji z `OptionStore::all()`, opcji bramki, opcji `pusers_db_version` oraz `DROP TABLE` dla obu tabel z `Migrations::table()`. **Nie hardkoduj nazw** — bierz je ze stałych i metod, które już istnieją.

Metadane użytkowników (`pusers_profession`, stan konta) **nie są** kasowane przez `run()` — konta są danymi WordPressa, nie wtyczki. Udokumentuj tę decyzję w docbloku.

- [ ] **Step 4: Utwórz `uninstall.php`**

Cienki plik w katalogu głównym: guard `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;`, warunkowy `require` autoloadera (bez niego `return`), wywołanie `Uninstaller::run()`.

- [ ] **Step 5: Uruchom obie suity, PHPStan i PHPCS**

Expected: PASS, zero zgłoszeń

- [ ] **Step 6: Commit**

```bash
git add uninstall.php src/Persistence/Uninstaller.php tests/Integration/Persistence/UninstallerTest.php
git commit -m "feat: odinstalowanie za bramka, domyslnie bez kasowania danych"
```

---

## Co ten plan świadomie zostawia

- **Renderowanie formularza rejestracji** i warunkowa widoczność pól na froncie — Plan 3. Ten plan produkuje konfigurację, nie jej użycie.
- **Zapis metadanej `pusers_profession`** przy rejestracji — Plan 3. Tutaj klucz jest tylko ustalony i odczytywany do liczenia kont.
- **Szablony maili i ich zmienne** — Plan 5. Ustawienia trzymają wyłącznie **odbiorców**.
- **Ekran wniosków, powody odrzucenia w akcji** — Plan 6. Tutaj powody są tylko konfigurowane.
- **Exporter i eraser danych osobowych** — Plan 7.
- **Weryfikacja rozpoznania ACF Pro na realnej instalacji** — pozycja otwarta z `docs/superpowers/backlog.md`; ten plan czyta ACF przez adapter z dwoma filtrami, więc testy jej nie potrzebują.
- **Przenoszenie metadanych kont przy usunięciu profesji** — świadomie nie. Konto zostaje z kluczem, którego nie ma w konfiguracji; `ConsistencyChecker` to zgłasza, a Plan 6 pokaże na liście wniosków. Automatyczna migracja metadanych przy usuwaniu konfiguracji byłaby operacją nieodwracalną ukrytą w kliknięciu.
