# Wygląd formularzy frontu na Bootstrapie 5 — plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sześć widoków frontu wtyczki `portal-users` wypisuje znacznik oparty na klasach Bootstrapa 5, z dwoma ustawieniami: układ (goły/karta) i doładowanie własnego arkusza dla skórki bez Bootstrapa.

**Architecture:** Jedno źródło klas (`FormClasses`) czytane przez wszystkie widoki i przez test arkusza. Wspólne opakowanie `pusers-front` wokół wyjścia każdego shortcode'u niesie zasięg CSS-a i tryb karty. Pola profilu dalej rysuje ACF — klasy wchodzą przez definicję pola, komunikat błędu dokłada się za renderem w istniejącym buforze. Dwa arkusze: kilkulinijkowy stały i zapasowy pod ustawieniem.

**Tech Stack:** PHP 8.1+, WordPress, ACF Pro, PHPUnit (suita jednostkowa i integracyjna w kontenerze wp-env), PHPStan level max, PHPCS (WordPress Coding Standards). Zero JavaScriptu, zero kroku budowania, zero zależności z CDN-u.

**Spec:** [docs/superpowers/specs/2026-10-31-wyglad-formularzy-bootstrap-design.md](../specs/2026-10-31-wyglad-formularzy-bootstrap-design.md)

## Global Constraints

- Teksty widoczne dla człowieka po polsku, przez `__()` z domeną `portal-users`, pisane dla operatora portalu.
- Dotychczasowe klasy `pusers-*` zostają na swoich elementach. Klasy Bootstrapa dokładane są **obok**. Filtr `pusers_form_classes` nie może usunąć ani dodać klas `pusers-*`.
- Znacznik wypisany przez ACF nie jest przepisywany ani podmieniany wyrażeniami regularnymi — wolno tylko **dokładać** elementy za nim.
- Pułapka na boty (`HONEYPOT`) i jej style inline zostają bez zmian w każdym widoku.
- Żadnego `wp_enqueue_script`, żadnego `was-validated`, żadnego zasobu spoza paczki wtyczki.
- Nowe pole logiczne na ekranie ustawień musi iść przez `SettingsScreen::checkbox()`.
- Bramki (uruchamiane ręcznie, nie przez skrypty Composera):
  - `vendor/bin/phpunit --testsuite unit`
  - `MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users <kontener-tests-cli> vendor/bin/phpunit -c phpunit-integration.xml.dist`
  - `vendor/bin/phpstan analyse --memory-limit=2G`
  - `vendor/bin/phpcs`
- Każde zadanie kończy się commitem; komunikat commita kończy się linią `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Review Focus

Pięć wejść, których specyfikacja wprost nie opisuje, a które zaboli człowieka. Test każdego z nich jest dopisany do zadania, które jest właścicielem kodu.

1. **Filtr `pusers_form_classes` oddaje śmieci** (nie-tablicę, tablicę z wartością niebędącą napisem, klasę z cudzysłowem). Oczekiwane: wtyczka ignoruje złą wartość i używa swojej, a nic nie trafia do HTML-a bez `esc_attr()`. → Zadanie 1.
2. **Zapisany układ ma nieznaną wartość** (ręczna edycja opcji, pozostałość po starszej wersji). Oczekiwane: `goły`, bez błędu. → Zadanie 2.
3. **Pole ACF typu, którego nie ubieramy** (repeater, galeria). Oczekiwane: renderuje się jak dziś, bez klas, bez błędu. → Zadanie 5.
4. **Dwa shortcode'y wtyczki na jednej stronie.** Oczekiwane: dwa opakowania `pusers-front`, arkusz dołączony raz. → Zadanie 8.
5. **Widok, który oddał samą notkę, bez formularza** (rejestracja niedostępna, wygasły link). Oczekiwane: notka też jest w `pusers-front` i też dostaje arkusz. → Zadanie 3 (opakowanie) i Zadanie 8 (arkusz).

---

## File Structure

**Nowe:**
- `src/Front/FormClasses.php` — mapa rola => klasy Bootstrapa, filtr, sanityzacja.
- `src/Front/FormWrapper.php` — opakowanie `pusers-front`, tryb karty.
- `src/Front/FormStyles.php` — rejestracja i dwuwarstwowe ładowanie arkuszy.
- `src/Front/NoticeTone.php` — enum tonu notki.
- `src/Front/Notice.php` — wypisanie notki i listy błędów jako okienka `alert`.
- `src/Domain/Config/FormLayout.php` — enum układu.
- `assets/css/pusers-front.css` — arkusz stały (samo ostrzeżenie).
- `assets/css/pusers-front-zapasowy.css` — arkusz zapasowy.
- `tests/Unit/Front/FormClassesTest.php`, `tests/Unit/Front/StylesheetCoverageTest.php`
- `tests/Unit/Config/FormLayoutTest.php`
- `tests/Integration/Front/FormWrapperTest.php`, `tests/Integration/Front/FormStylesTest.php`

**Zmieniane:**
- `src/Domain/Config/Settings.php` — dwa nowe pola.
- `src/Admin/SettingsScreen.php` — dwa nowe wiersze tabeli.
- `src/Front/RegistrationForm.php`, `LoginForm.php`, `PasswordReset.php`, `PasswordSetup.php`, `Confirmation.php` — opakowanie, klasy, notki, błędy pól.
- `src/Front/AcfFields.php` — klasy w definicji pola.
- `src/Plugin.php` (albo miejsce, w którym wtyczka rejestruje front — ustalić przy zadaniu 8) — rejestracja `FormStyles`.
- `docs/konfiguracja.md`, `CHANGELOG.md`.

---

### Task 1: Mapa klas

**Files:**
- Create: `src/Front/FormClasses.php`
- Test: `tests/Unit/Front/FormClassesTest.php`

**Interfaces:**
- Produces: `FormClasses::FILTER` (`'pusers_form_classes'`); `FormClasses::defaults(): array<string, string>`; `FormClasses::for( string $role ): string` — zwraca **same klasy Bootstrapa**, bez `pusers-*` (te widoki dopisują same); `FormClasses::attr( string $role, string ...$extra ): string` — gotowa, zescapowana zawartość atrybutu `class` ze sklejonych klas.

Role (klucze), dokładnie te i tylko te: `wrapper` (pusty napis), `wrapper_card` (`card`), `card_body` (`card-body`), `form` (pusty), `field` (`mb-3`), `label` (`form-label`), `control` (`form-control`), `select` (`form-select`), `check` (`form-check`), `check_input` (`form-check-input`), `check_label` (`form-check-label`), `button` (`btn btn-primary`), `notice_success` (`alert alert-success`), `notice_info` (`alert alert-info`), `notice_danger` (`alert alert-danger`), `notice_list` (`mb-0 ps-3`), `invalid` (`is-invalid`), `invalid_feedback` (`invalid-feedback d-block`), `warning_feedback` (`form-text pusers-warning-feedback`).

- [ ] **Step 1: Write the failing test**

```php
public function test_zwraca_klasy_domyslne(): void;            // for('control') === 'form-control'
public function test_filtr_podmienia_role(): void;             // filtr zmienia 'control' => 'input-xl'
public function test_filtr_nie_tablica_jest_ignorowany(): void; // filtr zwraca 'nope' => domyślne
public function test_filtr_wartosc_nie_napis_jest_ignorowana(): void; // ['control' => 42] => 'form-control'
public function test_filtr_nie_moze_dodac_nowej_roli(): void;  // ['cokolwiek' => 'x'] => for('cokolwiek') === ''
public function test_atrybut_jest_zescapowany(): void;         // filtr ['control' => 'a"onclick=x'] => brak '"' w attr()
public function test_atrybut_sklej_a_klasy_wlasne(): void;     // attr('field', 'pusers-field') zawiera obie, rozdzielone spacją
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter FormClassesTest`
Expected: FAIL — klasa `FormClasses` nie istnieje.

- [ ] **Step 3: Implement `FormClasses` in `src/Front/FormClasses.php`**

`defaults()` zwraca stałą tablicę ról. `for()` nakłada `apply_filters( 'pusers_form_classes', self::defaults() )`, odrzuca wynik niebędący tablicą, bierze z niego wyłącznie klucze obecne w `defaults()` i wyłącznie wartości typu `string`; reszta wraca do wartości domyślnej. `attr()` skleja klasy spacjami i przepuszcza przez `esc_attr()`.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --testsuite unit --filter FormClassesTest` → PASS
Run: `vendor/bin/phpstan analyse --memory-limit=2G` i `vendor/bin/phpcs` → bez błędów.

- [ ] **Step 5: Commit**

```bash
git add src/Front/FormClasses.php tests/Unit/Front/FormClassesTest.php
git commit -m "feat: mapa klas formularzy frontu z filtrem"
```

---

### Task 2: Dwa ustawienia

**Files:**
- Create: `src/Domain/Config/FormLayout.php`, `tests/Unit/Config/FormLayoutTest.php`
- Modify: `src/Domain/Config/Settings.php`, `src/Admin/SettingsScreen.php`, `tests/Unit/Config/SettingsTest.php`, `docs/konfiguracja.md`

**Interfaces:**
- Produces: `FormLayout::Plain = 'goly'`, `FormLayout::Card = 'karta'`, `FormLayout::fromValue( mixed $value ): self` (nieznane => `Plain`); `Settings::$formLayout` (`FormLayout`), `Settings::$formStylesEnabled` (`bool`). Klucze w tablicy: `form_layout`, `form_styles_enabled`. Oba pola dochodzą **na końcu** konstruktora, po `$termsConsentField`.

- [ ] **Step 1: Write the failing test**

```php
// FormLayoutTest
public function test_nieznana_wartosc_daje_goly(): void;   // fromValue('karteczka'), fromValue(null), fromValue(7)
public function test_znane_wartosci(): void;               // 'goly' => Plain, 'karta' => Card

// SettingsTest (dopisać)
public function test_domyslny_uklad_to_goly(): void;
public function test_domyslnie_arkusz_nie_jest_doladowany(): void;
public function test_uklad_ze_smieci_wraca_do_golego(): void;  // fromArray(['form_layout' => 'xx'])
public function test_uklad_i_arkusz_przechodza_przez_toArray(): void;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --testsuite unit --filter "FormLayoutTest|SettingsTest"`
Expected: FAIL — brak enuma i brak właściwości.

- [ ] **Step 3: Implement**

Enum `FormLayout` (backed string). W `Settings`: dwa parametry konstruktora, wpis w `defaults()` (`FormLayout::Plain`, `false`), odczyt w `fromArray()` (`FormLayout::fromValue( $raw['form_layout'] ?? null )`, `self::flag( $raw, 'form_styles_enabled', $d->formStylesEnabled )`), zapis w `toArray()` (układ jako `->value`).

- [ ] **Step 4: Add the two rows to the settings screen**

W `SettingsScreen` dwa wiersze na końcu istniejącej tabeli: select układu (etykieta „Układ formularzy", opcje „Goły" i „Karta") i checkbox przez `SettingsScreen::checkbox()` (etykieta „Doładuj wygląd formularzy"). Opis pod checkboxem mówi wprost: włącz, jeżeli skórka portalu nie korzysta z Bootstrapa; przy skórce bootstrapowej zostaw wyłączone. Zapis obu wartości dopisać tam, gdzie ekran składa tablicę do `Settings::fromArray()`.

- [ ] **Step 5: Run the gates**

Run: `vendor/bin/phpunit --testsuite unit` → PASS
Run: integracja (komenda z Global Constraints) → PASS
Run: `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/phpcs` → bez błędów.

- [ ] **Step 6: Opisać oba ustawienia w `docs/konfiguracja.md`, Krok 6**

Dwa akapity dla operatora portalu, w stylu reszty dokumentu. Nagłówków kroków nie ruszać — wiąże je `tests/Support/ConfigurationDoc.php`.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Config/FormLayout.php src/Domain/Config/Settings.php src/Admin/SettingsScreen.php tests/Unit/Config docs/konfiguracja.md
git commit -m "feat: ustawienia ukladu formularzy i doladowania arkusza"
```

---

### Task 3: Opakowanie `pusers-front`

**Files:**
- Create: `src/Front/FormWrapper.php`, `tests/Integration/Front/FormWrapperTest.php`
- Modify: `src/Front/RegistrationForm.php` (`render()`), `LoginForm.php` (`render()`), `PasswordReset.php` (`render()`), `PasswordSetup.php` (`render()`), `Confirmation.php` (`notice_html()` wyłącznie jeśli jest samodzielnym wyjściem shortcode'u — w przeciwnym razie zostaje w środku)

**Interfaces:**
- Consumes: `FormClasses::attr()` (Task 1), `Settings::$formLayout` (Task 2).
- Produces: `FormWrapper::wrap( string $html ): string` — zwraca pusty napis dla pustego wejścia; w trybie `Plain` jeden `div.pusers-front`; w trybie `Card` `div.pusers-front.card` z wewnętrznym `div.card-body`.

- [ ] **Step 1: Write the failing test**

```php
public function test_puste_wyjscie_nie_dostaje_opakowania(): void;
public function test_tryb_goly_daje_jeden_div(): void;         // class="pusers-front", bez 'card'
public function test_tryb_karta_dokłada_card_i_card_body(): void;
public function test_wyjscie_bez_formularza_tez_jest_opakowane(): void; // rejestracja niedostępna -> notka w pusers-front
public function test_kazdy_z_szesciu_widokow_ma_jedno_opakowanie(): void; // substr_count(..., 'pusers-front') === 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: integracja z `--filter FormWrapperTest`
Expected: FAIL — brak `FormWrapper`.

- [ ] **Step 3: Implement `FormWrapper::wrap()` and apply it in the five render entry points**

Opakowanie zakłada się **raz**, na samym wyjściu `render()` każdego shortcode'u — nie w metodach, które render składa. `PasswordSetup::render()` bywa doklejany do `RegistrationForm::render()`; opakowuje wtedy ta druga, więc wynik ma jedno opakowanie i jedną kartę.

- [ ] **Step 4: Run tests**

Run: integracja `--filter "FormWrapperTest|RegistrationFormTest|LoginFormTest|PasswordResetTest|PasswordSetupTest|ConfirmationTest"` → PASS

- [ ] **Step 5: Commit**

```bash
git add src/Front tests/Integration/Front
git commit -m "feat: wspolne opakowanie wyjscia shortcode'ow frontu"
```

---

### Task 4: Klasy w polach rysowanych przez wtyczkę

**Files:**
- Modify: `src/Front/RegistrationForm.php` (profesja, adres e-mail, przycisk), `LoginForm.php` (identyfikator, hasło, „Zapamiętaj mnie", przycisk), `PasswordReset.php` (adres e-mail, nowe hasło, oba przyciski), `PasswordSetup.php` (hasło, przycisk), `Confirmation.php` (adres e-mail, przycisk)
- Modify: odpowiadające pliki w `tests/Integration/Front/`

**Interfaces:**
- Consumes: `FormClasses::attr()`.

- [ ] **Step 1: Write the failing tests**

Dla każdego z sześciu widoków jeden test: wypisany znacznik zawiera `class="pusers-form …"` na `<form>`, `form-label` na każdej etykiecie, `form-control`/`form-select` na każdej kontrolce, `btn btn-primary` na przycisku, a „Zapamiętaj mnie" w logowaniu — `form-check`, `form-check-input`, `form-check-label`. Osobna asercja: pułapka na boty **nie** dostała żadnej klasy i dalej ma swoje style inline.

- [ ] **Step 2: Run tests to verify they fail**

Run: integracja `--filter "RegistrationFormTest|LoginFormTest|PasswordResetTest|PasswordSetupTest|ConfirmationTest"` → FAIL

- [ ] **Step 3: Implement**

Zamiana dotychczasowych `<p class="pusers-field-*">` na `<div>` z klasami `pusers-field-* mb-3` tam, gdzie akapit był tylko opakowaniem pola; etykiety i kontrolki dostają klasy z `FormClasses::attr()`. Nazwy pól, `id`, `autocomplete`, `required`, `minlength` i `novalidate` zostają bez zmian.

- [ ] **Step 4: Run tests** → PASS; plus `phpcs` i `phpstan`.

- [ ] **Step 5: Commit**

```bash
git add src/Front tests/Integration/Front
git commit -m "feat: klasy bootstrapa w polach rysowanych przez wtyczke"
```

---

### Task 5: Klasy w polach profilu z ACF

**Files:**
- Modify: `src/Front/AcfFields.php`, `src/Front/RegistrationForm.php`
- Modify: `tests/Integration/Front/AcfFieldsTest.php`

**Interfaces:**
- Consumes: `FormClasses::for()`.
- Produces: `AcfFields::render( string $fieldKey, bool $required, mixed $value, bool $invalid = false ): void` — przed `acf_render_field_wrap()` ustawia `$field['class']` i `$field['wrapper']['class']` zależnie od typu pola.

Przypisanie typu do roli: `text`, `email`, `url`, `number`, `password`, `textarea`, `date_picker`, `time_picker`, `date_time_picker` => `control`; `select` => `select`; `true_false` => `check_input`; każdy inny typ => **brak klasy kontrolki**. `wrapper.class` zawsze dostaje klasę roli `field`.

- [ ] **Step 1: Write the failing test**

Atrapa `tests/Integration/Support/acf-render-stub.php` zapisuje tablicę pola — asercje czytają ją, nie HTML.

```php
public function test_pole_tekstowe_dostaje_form_control(): void;
public function test_select_dostaje_form_select(): void;
public function test_true_false_dostaje_form_check_input(): void;
public function test_typ_spoza_listy_nie_dostaje_klasy_kontrolki(): void; // 'repeater' => class pusta, render nie rzuca
public function test_opakowanie_zawsze_dostaje_mb_3(): void;
public function test_invalid_dokłada_is_invalid_do_klasy_kontrolki(): void;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: integracja `--filter AcfFieldsTest` → FAIL

- [ ] **Step 3: Implement**

W `AcfFields::render()` wyliczyć rolę z `$this->source->fieldTypes()[ $fieldKey ]`, złożyć klasę kontrolki (plus `is-invalid` gdy `$invalid`), dopisać `wrapper.class`. Założenie, że ACF przenosi `$field['class']` na kontrolkę, a `$field['wrapper']['class']` na opakowanie, zapisać komentarzem nad tym kodem — testy go nie pilnują, bo ACF-a w środowisku testowym nie ma.

- [ ] **Step 4: Etykieta pola profilu dostaje `form-label`**

Wybrać jedną z dwóch dróg (wymuszenie klasy przez definicję albo wygaszenie etykiety ACF-a i własna etykieta z `for` wskazującym `$field['id']`, ustawiony przez definicję). Wymóg: etykieta związana z polem, gwiazdka pola wymaganego nie ginie. Test asertuje na zapisanej definicji pola.

- [ ] **Step 5: Run tests** → PASS; `phpstan`, `phpcs`.

- [ ] **Step 6: Commit**

```bash
git add src/Front/AcfFields.php src/Front/RegistrationForm.php tests/Integration/Front/AcfFieldsTest.php
git commit -m "feat: klasy bootstrapa w polach profilu z ACF"
```

---

### Task 6: Notki jako okienka `alert`

**Files:**
- Create: `src/Front/NoticeTone.php`, `src/Front/Notice.php`
- Modify: `RegistrationForm.php` (`render_feedback()`, dwie notki w `render_form()`), `LoginForm.php` (`result_notice()`, `render_logged_in()`), `PasswordReset.php` (`notice()`), `PasswordSetup.php` (`render()`), `Confirmation.php` (`notice_html()`)
- Modify: `tests/Integration/Front/*`

**Interfaces:**
- Consumes: `FormClasses::attr()`.
- Produces: `NoticeTone::Success|Info|Danger`; `Notice::html( string $message, NoticeTone $tone ): string`; `Notice::with_list( string $message, array $items, NoticeTone $tone ): string` — jedno okienko: akapit (pominięty, gdy `$message` pusty) i `<ul>` pod nim.

Ton dobiera **każde miejsce wywołania jawnie**; mapa kod => ton nie mieszka w `Notice`. Rola HTML idzie za tonem: `Danger` => `role="alert"`, pozostałe => `role="status"`. Dotychczasowa klasa `pusers-notice` zostaje na elemencie.

- [ ] **Step 1: Write the failing test**

```php
public function test_notka_sukcesu_ma_alert_success_i_role_status(): void;
public function test_notka_odmowy_ma_alert_danger_i_role_alert(): void;
public function test_lista_bledow_i_notka_sa_w_jednym_okienku(): void;     // jedno 'alert-danger' w wyjściu
public function test_lista_bez_notki_ma_wlasne_okienko(): void;
public function test_wygasly_link_potwierdzajacy_jest_czerwony(): void;    // Confirmation: expired => alert-danger, role=alert
public function test_potwierdzony_adres_jest_zielony(): void;              // Confirmation: ok => alert-success
```

- [ ] **Step 2: Run tests to verify they fail** → integracja `--filter "ConfirmationTest|RegistrationFormTest|LoginFormTest|PasswordResetTest|PasswordSetupTest"`

- [ ] **Step 3: Implement**

`render_feedback()` scala notkę i listę przez `Notice::with_list()`. Teksty komunikatów i kody zostają co do znaku — zmienia się wyłącznie obudowa i ton.

- [ ] **Step 4: Run tests** → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Front tests/Integration/Front
git commit -m "feat: notki frontu jako okienka alert z jawnym tonem"
```

---

### Task 7: Błędy przy polach

**Files:**
- Modify: `RegistrationForm.php`, `PasswordReset.php`, `PasswordSetup.php`
- Modify: `tests/Integration/Front/RegistrationFormTest.php`, `PasswordResetTest.php`, `PasswordSetupTest.php`, `LoginFormTest.php`

**Interfaces:**
- Consumes: `FormClasses::attr()`, `AcfFields::render( …, bool $invalid )` (Task 5), `Notice` (Task 6).

- [ ] **Step 1: Write the failing tests**

```php
// Rejestracja
public function test_pole_konta_z_bledem_dostaje_is_invalid_i_komunikat(): void; // e-mail
public function test_pole_profilu_z_bledem_dostaje_is_invalid_i_komunikat(): void; // klucz 'acf:'
public function test_miekkie_ostrzezenie_nie_uzywa_is_invalid(): void;             // pusers-warning-feedback
public function test_komunikat_przy_polu_jest_tym_samym_tekstem_co_w_liscie(): void;
// Hasła i reset
public function test_za_krotkie_haslo_oznacza_pole_hasla(): void;      // kod 'short', oba widoki hasła
public function test_zly_adres_oznacza_pole_adresu(): void;            // prośba o reset
// Logowanie — test bezpieczeństwa
public function test_nieudane_logowanie_nie_oznacza_zadnego_pola(): void; // brak 'is-invalid' w całym wyjściu
```

- [ ] **Step 2: Run tests to verify they fail** → integracja

- [ ] **Step 3: Implement**

Dla pól konta klasa i `invalid-feedback` wchodzą wprost do znacznika. Dla pól profilu `is-invalid` idzie przez `AcfFields::render()`, a `invalid-feedback` dokłada się **za** buforem renderu, wewnątrz `div.pusers-field` — bufor już tam jest. Klucz błędu z przedrostkiem `acf:` rozplątać tak, jak robi to `error_label()`. Komunikat przy polu bierze się z tej samej `message_for()` co lista, bez drugiej mapy tekstów.

Test logowania opatrzyć komentarzem: to jest test bezpieczeństwa, nie wyglądu — `RESULT_BAD` jest świadomie nieokreślony i oznaczenie pola ujawniałoby, która połowa poświadczeń pasuje.

- [ ] **Step 4: Run tests** → PASS; `phpstan`, `phpcs`.

- [ ] **Step 5: Commit**

```bash
git add src/Front tests/Integration/Front
git commit -m "feat: bledy walidacji widoczne przy polach"
```

---

### Task 8: Dwa arkusze i ich ładowanie

**Files:**
- Create: `assets/css/pusers-front.css`, `assets/css/pusers-front-zapasowy.css`, `src/Front/FormStyles.php`, `tests/Integration/Front/FormStylesTest.php`, `tests/Unit/Front/StylesheetCoverageTest.php`
- Modify: miejsce rejestracji frontu (to samo, w którym rejestrują się shortcode'y)

**Interfaces:**
- Consumes: `FormClasses::defaults()`, `Settings::$formStylesEnabled`.
- Produces: `FormStyles::register(): void` (podpina `template_redirect`); `FormStyles::enqueue(): void` — idempotentne, wołane z każdego `render()`; uchwyty `pusers-front` (stały) i `pusers-front-fallback` (zapasowy, tylko przy włączonym ustawieniu).

- [ ] **Step 1: Write the failing tests**

```php
// FormStylesTest (integracja)
public function test_arkusz_staly_wchodzi_zawsze_gdy_widok_sie_pojawil(): void;
public function test_arkusz_zapasowy_nie_wchodzi_przy_wylaczonym_ustawieniu(): void;
public function test_arkusz_zapasowy_wchodzi_przy_wlaczonym_ustawieniu(): void;
public function test_dwa_shortcody_na_stronie_daja_jeden_arkusz(): void;  // i dwa 'pusers-front'
public function test_widok_z_sama_notka_tez_dostaje_arkusz(): void;
public function test_strona_bez_shortcodu_nie_dostaje_niczego(): void;

// StylesheetCoverageTest (jednostkowy)
public function test_arkusz_zapasowy_ma_regule_dla_kazdej_roli(): void;
public function test_arkusz_zapasowy_nie_wychodzi_poza_pusers_front(): void; // każdy selektor zawiera '.pusers-front'
public function test_arkusz_staly_opisuje_wylacznie_ostrzezenie(): void;
```

`StylesheetCoverageTest` czyta `FormClasses::defaults()`, bierze z każdej wartości pierwszą klasę i sprawdza, że występuje w arkuszu zapasowym; role o pustej wartości pomija.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the two stylesheets**

Stały: wyłącznie `.pusers-front .pusers-warning-feedback` (kolor odróżnialny od błędu, nieudający go). Zapasowy: reguły dla wszystkich klas z mapy, każdy selektor zamknięty w `.pusers-front`, stany `:focus`, `:disabled`, `.is-invalid`, odstępy dla `ul.acf-radio-list` i `ul.acf-checkbox-list`. Bez resetu, bez `body`, bez `*`. Szerokość karty z przedziału 500–650 px.

- [ ] **Step 4: Implement `FormStyles`**

Warstwa pierwsza: na `template_redirect` sprawdzić `has_shortcode()` treści zapytanego wpisu dla trzech shortcode'ów i podpiąć `wp_enqueue_scripts` — styl wchodzi do `<head>`. Warstwa druga: `FormStyles::enqueue()` wołane z każdego `render()`; dla widoku spoza treści wpisu trafi do stopki. `wp_enqueue_style()` jest idempotentne, więc obie warstwy dają jeden arkusz. Wersja z `Plugin::version()`, wzorem `RegistrationForm::enqueue_script()`.

- [ ] **Step 5: Run the full gates** → wszystkie cztery, PASS.

- [ ] **Step 6: Commit**

```bash
git add assets/css src/Front tests
git commit -m "feat: arkusz staly i zapasowy dla formularzy frontu"
```

---

### Task 9: Sprawdzenie na żywo i wpis do CHANGELOG-a

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Obejrzeć trzy strony na skórce bootstrapowej**

Rejestracja, logowanie, reset. Oba układy, doładowanie wyłączone. Formularz ma dziedziczyć kolory i typografię skórki; z wtyczki nie wchodzi żaden CSS poza arkuszem stałym.

- [ ] **Step 2: Obejrzeć te same strony na skórce bez Bootstrapa**

Z doładowaniem wyłączonym — jak dziś, nic nie jest zepsute. Z włączonym — formularz porządny, a nagłówek, menu i stopka portalu nie drgnęły.

- [ ] **Step 3: Wywołać stan błędu w rejestracji**

Puste pole wymagane i zły adres e-mail: okienko z listą u góry, czerwone pola, komunikaty pod nimi. Numer uprawnień z błędną sumą kontrolną: żółte ostrzeżenie, zgłoszenie przechodzi.

- [ ] **Step 4: Write the CHANGELOG entry**

Pod `## [Unreleased]`, w `### Dodane` i `### Zmienione`, dla operatora portalu: formularze korzystają z wyglądu skórki opartej na Bootstrapie; doszły dwa ustawienia (układ, doładowanie wyglądu) z jednym zdaniem, kiedy się je włącza; błędy pokazują się teraz przy polach.

- [ ] **Step 5: Run the full gates once more** → wszystkie cztery, PASS.

- [ ] **Step 6: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: wpis o wygladzie formularzy frontu"
```
