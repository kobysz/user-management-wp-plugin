# Rejestracja na froncie — plan wdrożenia

> **Dla wykonawców agentowych:** WYMAGANA PODUMIEJĘTNOŚĆ: użyj superpowers:subagent-driven-development (zalecane) albo superpowers:executing-plans, by zrealizować ten plan zadanie po zadaniu. Kroki mają składnię pól wyboru (`- [ ]`) do odhaczania.

**Cel:** Gość portalu wypełnia jedną stronę rejestracji z polami zależnymi od wybranej profesji, dostaje maila z linkiem potwierdzającym, a po kliknięciu jego konto czeka na decyzję redakcji — i nic z tego nie da się obejść podrobionym żądaniem ani zalać botem.

**Architektura:** Pola renderuje ACF Pro, formularz i żądanie należą do wtyczki. Macierz profesja × pole z Planu 2 jest jedynym źródłem prawdy o widoczności i wymagalności, a serwer nie ufa przeglądarce: przed walidacją macierz jest **przycinana do pól, które źródło naprawdę zawiera**, więc wpis wymagający pola nieobecnego w ACF nie blokuje rejestracji. Stan konta żyje w metadanej użytkownika i przechodzi `niepotwierdzony_mail → oczekujący → zatwierdzony`, a przy wyłączonej akceptacji skraca się do `niepotwierdzony_mail → zatwierdzony`.

**Stos:** WordPress 6.5+, PHP 8.1+, ACF Pro (zależność twarda, sprawdzana w `Dependencies`), PHPUnit w kontenerze wp-env, PHPStan level max, PHPCS `WordPress-Core` + `WordPress-Extra` + `WordPress-Docs`.

**Specyfikacja:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`

**Wymogi przeniesione:** `docs/superpowers/backlog.md`, sekcje „Plan 3" oraz „Plany 3 i 4 — granulacja limitu prób" — **wiążące**.

## Ograniczenia globalne

- Warstwa `src/Domain/**` nie wykonuje **żadnych** wywołań WordPressa; pilnuje tego `DomainPurityTest` białą listą na `token_get_all()`.
- Wszystkie teksty widoczne dla użytkownika przez `__()` / `_e()` / `esc_html__()` z domeną `portal-users`. **Zakaz `_n()`** — język źródłowy jest polski, katalogu `.po` nie ma, więc gettext zastosowałby angielską regułę dwóch form i wypisał „2 błędów". Liczbę stawiaj na końcu zdania po dwukropku.
- Kolejność bramek w każdym handlerze żądania: **uprawnienie → nonce → metoda → akcja → PRG**. Dla ścieżek publicznych uprawnienia nie ma, więc: **nonce → metoda → antyspam → akcja → PRG**.
- Opcje zapisywane z `autoload = false`.
- Każdy nowy klucz ustawień dochodzi do `Settings` addytywnie; `Settings::fromArray()` dopełnia brakujące domyślnymi, więc zapisane konfiguracje się nie poruszą.
- Nazwy kluczy metadanych i opcji **tylko** ze stałych; zakaz literałów w drugim miejscu.
- Niczego nie kasujemy nieodwracalnie poza cronem czyszczącym konta `niepotwierdzony_mail` i poza bramkowanym odinstalowaniem z Planu 2.

## Co ten plan konsumuje z Planów 1 i 2

Sygnatury sprawdzone w kodzie na `main`, commit `70119ea`:

```php
PortalUsers\Domain\Submission\SubmissionValidator::__construct( ProfessionCollection, FieldMatrix, ValidatorRegistry )
PortalUsers\Domain\Submission\SubmissionValidator::validate( string $professionKey, string $credentialFieldKey, array $values ): SubmissionResult
PortalUsers\Domain\Submission\SubmissionResult::{PROFESSION_KEY='__profession', isValid():bool, errors():array, values():array, errorFor(string):string, credentialWarning():string}
PortalUsers\Domain\Submission\SubmissionFilter::{__construct(FieldMatrix), strip(string,array):array}
PortalUsers\Domain\Fields\FieldMatrix::{fromArray(array):self, stateFor(string,string):FieldState, visibleFields(string):array, requiredFields(string):array, toArray():array}
PortalUsers\Domain\Fields\FieldState — enum string: hidden|visible|required
PortalUsers\Domain\Account\AccountStatus — enum string: email_unconfirmed|pending|approved|rejected; tryFromStored(?string):?self
PortalUsers\Domain\Account\LoginGate::decide(?AccountStatus):LoginDecision
PortalUsers\Domain\Profession\{Profession,ProfessionCollection} — Profession ma $key,$label,$credentialType,$registryUrl,$notifyEmail,$credentialField
PortalUsers\Domain\Credential\ValidatorRegistry::for(CredentialType):?CredentialValidator
PortalUsers\Domain\Config\Settings — readonly: approvalEnabled, loginPageId, registerPageId, resetPageId, notifyEmails, confirmationTokenHours, unconfirmedPurgeDays, rateLimitPerHour, timeTrapSeconds, trustedProxyHeader, rejectionReasons, deleteDataOnUninstall, acfGroupKey
PortalUsers\Persistence\{SettingsRepository,ProfessionRepository,FieldMatrixRepository}::{get(),save()}
PortalUsers\Persistence\Migrations::{TABLES=['event_log','terms_versions'], table(string):string, DB_VERSION, OPTION_DB_VERSION}
PortalUsers\Acf\AcfFieldSource::{__construct(string $groupKey), fields():array<string,string>, groupExists():bool}
PortalUsers\Admin\ProfessionsScreen::USER_META = 'pusers_profession'
```

Ekrany podpinają się na akcji `pusers_register_features`; `Plugin::load_features()` odpala ją tylko, gdy `Dependencies::satisfied()`.

## Co ten plan świadomie zostawia

- **Logowanie, przekierowania po zalogowaniu, ukrycie paska administracyjnego, odzyskiwanie hasła** — Plan 4. Ten plan nie dotyka `wp-login.php` ani bramy logowania; `LoginGate` zostaje nieskonsumowany.
- **Szablony maili i ich zmienne** — Plan 5. Ten plan wysyła maile o **minimalnej, funkcjonalnej** treści (link potwierdzający, powiadomienie redakcji), żeby przebieg domykał się sam; Plan 5 podmieni treści, nie zdarzenia.
- **Ekran wniosków, zatwierdzanie i odrzucanie** — Plan 6. Ten plan doprowadza konto do stanu `oczekujący` i nic więcej z nim nie robi.
- **Eksport i usuwanie danych osobowych, anonimizacja logu** — Plan 7. Ten plan **pisze** do logu zdarzeń i wiąże konto z wersją regulaminu, ale nie obsługuje żądań użytkownika.
- **Import z poprzedniej wtyczki i backfill przy aktywacji** — osobny plan. Ten plan nie nadaje stanu kontom istniejącym.
- **Progi limitu dla logowania i odzyskiwania hasła** — Plan 4. Ten plan dodaje do ustawień **jeden** próg (per adres e-mail przy rejestracji) i reużywa istniejącego progu per adres IP.

## Struktura plików

| Plik | Odpowiedzialność |
|---|---|
| `src/Persistence/UserMeta.php` | Jedyny dom nazw metadanych użytkownika. |
| `src/Persistence/AccountStatusRepository.php` | Odczyt i zapis stanu konta. |
| `src/Persistence/EventLog.php` | Zapis wiersza do tabeli `event_log`. |
| `src/Persistence/TermsRepository.php` | Archiwum wersji regulaminu i bieżąca wersja. |
| `src/Domain/Fields/FieldMatrix.php` | **Modyfikacja:** przycinanie macierzy do pól obecnych w źródle. |
| `src/Domain/Registration/RegistrationPlan.php` | Co wyrenderować dla profesji: pola w kolejności źródła wraz ze stanem. |
| `src/Domain/Registration/BooleanNormalizer.php` | Odznaczone pole logiczne → wartość pusta. |
| `src/Domain/Spam/IpAddress.php` | Wybór adresu IP ze źródeł żądania, z zaufanym nagłówkiem. |
| `src/Domain/Spam/SpamVerdict.php` | Wynik oceny zgłoszenia: powód albo przepustka. |
| `src/Domain/Spam/SpamRules.php` | Pułapka czasowa, honeypot, progi — bez WordPressa. |
| `src/Front/RateLimiter.php` | Liczniki prób i blokada czasowa na transientach. |
| `src/Front/SpamGuard.php` | Spięcie reguł z licznikami, logiem i punktem rozszerzenia. |
| `src/Front/AcfFields.php` | Renderowanie pól przez ACF i odczyt ich wartości z żądania. |
| `src/Front/RegistrationForm.php` | Element wstawiany w edytorze, render formularza, powtórne wypełnienie po błędzie. |
| `src/Front/RegistrationHandler.php` | Obsługa żądania: bramki, walidacja, utworzenie konta, PRG. |
| `src/Front/Confirmation.php` | Token, endpoint potwierdzenia, ponowna wysyłka, przejście stanu. |
| `src/Front/Mailer.php` | Dwa maile tego planu, treść minimalna, nadpisywalna filtrem. |
| `src/Cron/PurgeUnconfirmed.php` | Harmonogram i czyszczenie kont `niepotwierdzony_mail`. |
| `src/Domain/Account/PurgeSelection.php` | Które konta kwalifikują się do usunięcia — bez WordPressa. |

## Review Focus

Pięć klas wejścia, których specyfikacja wymaga, a które najłatwiej przeoczyć. Każda ma przypisany test w zadaniu, które posiada dany kod.

1. **Wymagane pole nieobecne w grupie ACF** — rejestracja profesji **musi przechodzić**, nie blokować się; to anty-cel nazwany w specyfikacji najważniejszym (Task 2).
2. **Odznaczona wymagana zgoda** — ACF wysyła ukryte `'0'`, które `SubmissionValidator` uznaje za wartość obecną; musi zostać odrzucona jako brak (Task 3).
3. **Profesja spoza kolekcji w podrobionym żądaniu** — żadne konto nie powstaje (Task 6).
4. **Szpital za jednym adresem IP** — wiele rejestracji z różnych adresów e-mail z jednego IP nie może być ucięte przed progiem per adres e-mail (Task 5).
5. **Link potwierdzający użyty dwa razy albo po terminie** — brak cofnięcia stanu `zatwierdzony` do `oczekujący` i czytelny komunikat (Task 7).

---

### Task 1: Jeden dom nazw metadanych i stan konta

**Pliki:**
- Utwórz: `src/Persistence/UserMeta.php`, `src/Persistence/AccountStatusRepository.php`
- Zmodyfikuj: `src/Admin/ProfessionsScreen.php` (stała `USER_META` przestaje być własną nazwą)
- Test: `tests/Integration/Persistence/AccountStatusRepositoryTest.php`, `tests/Unit/Persistence/UserMetaTest.php`

**Interfejsy:**
- Konsumuje: `AccountStatus`
- Produkuje:
  - `UserMeta::{PROFESSION='pusers_profession', STATUS='pusers_status', CONFIRM_HASH='pusers_confirm_hash', CONFIRM_EXPIRES='pusers_confirm_expires', TERMS_VERSION='pusers_terms_version', CONSENT_AT='pusers_consent_at', all(): list<string>}`
  - `AccountStatusRepository::{get(int $userId): ?AccountStatus, set(int $userId, AccountStatus $status): void}`

`PROFESSION` ma wartość **dokładnie** taką jak dzisiejsza `ProfessionsScreen::USER_META`, bo w bazie klientów ta metadana już istnieje. `ProfessionsScreen::USER_META` zostaje jako stała, ale jej wartością staje się `UserMeta::PROFESSION` — żaden konsument nie musi się zmieniać, a nazwa ma jedno źródło.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Unit/Persistence/UserMetaTest.php
public function test_profession_key_keeps_the_value_already_present_in_client_databases(): void {
    $this->assertSame( 'pusers_profession', UserMeta::PROFESSION );
}

public function test_all_lists_every_declared_key_and_nothing_else(): void {
    $declared = array_values( ( new \ReflectionClass( UserMeta::class ) )->getConstants() );
    $declared = array_filter( $declared, 'is_string' );
    $this->assertSame( array_values( $declared ), UserMeta::all() );
}

// tests/Integration/Persistence/AccountStatusRepositoryTest.php
public function test_status_round_trips_through_user_meta(): void { /* set(Pending) → get() === AccountStatus::Pending */ }

public function test_account_without_a_stored_status_reads_as_null(): void { /* get() na świeżym koncie === null */ }

public function test_unrecognised_stored_value_reads_as_null_and_does_not_throw(): void {
    update_user_meta( $id, UserMeta::STATUS, 'cokolwiek' );
    $this->assertNull( AccountStatusRepository::get( $id ) );
}

public function test_professions_screen_constant_points_at_the_shared_name(): void {
    $this->assertSame( UserMeta::PROFESSION, ProfessionsScreen::USER_META );
}
```

Test nierozpoznanej wartości jest nośny: `LoginGate::decide( null )` **wpuszcza**, więc gdyby odczyt rzucał wyjątek albo zwracał zmyślony stan, konto z uszkodzoną metadaną albo zostałoby zamknięte poza portalem, albo wpuszczone bez weryfikacji.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

Oczekiwane: FAIL — klasy nie istnieją.

- [ ] **Krok 3: Zaimplementuj `UserMeta` i `AccountStatusRepository`**

`get()` czyta metadaną i przepuszcza przez `AccountStatus::tryFromStored()`, więc nieznana wartość daje `null` bez wyjątku. `all()` zwraca wartości stałych w kolejności deklaracji — służy Planowi 7 i bramkowanemu odinstalowaniu, żeby nikt nie wypisywał tych nazw po raz drugi.

- [ ] **Krok 4: Podmień stałą w `ProfessionsScreen`**

- [ ] **Krok 5: Obie suity, PHPStan, PHPCS**

Oczekiwane: PASS, zero zgłoszeń. Liczba testów ekranu profesji **nie może spaść** — jeśli spadła, podmiana stałej coś zepsuła.

- [ ] **Krok 6: Commit**

```bash
git add src/Persistence/UserMeta.php src/Persistence/AccountStatusRepository.php src/Admin/ProfessionsScreen.php tests/
git commit -m "feat: jeden dom nazw metadanych uzytkownika i repozytorium stanu konta"
```

---

### Task 2: Przycięcie macierzy i plan renderowania

**Pliki:**
- Zmodyfikuj: `src/Domain/Fields/FieldMatrix.php`
- Utwórz: `src/Domain/Registration/RegistrationPlan.php`
- Test: `tests/Unit/Fields/FieldMatrixTest.php` (istniejący), `tests/Unit/Registration/RegistrationPlanTest.php`

**Interfejsy:**
- Konsumuje: `FieldMatrix`, `FieldState`, `FieldSource`
- Produkuje:
  - `FieldMatrix::prunedTo( array $fieldKeys ): self` — nowa macierz bez wpisów dla pól nieobecnych na liście; **nigdy nie dodaje wpisu i nigdy nie podnosi stanu**.
  - `RegistrationPlan::{__construct(FieldMatrix $pruned, FieldSource $fields), fieldsFor(string $professionKey): array<string, FieldState>, requiredFor(string $professionKey): list<string>}`

`fieldsFor()` zwraca pola **w kolejności ze źródła**, pominąwszy stan `hidden`, bo kolejność pól w formularzu jest kolejnością, w jakiej klient ułożył je w ACF.

To zadanie realizuje punkt 1 z „Review Focus" i wiążący wymóg backlogu „formularz nie może twardo wymagać pola, którego nie renderuje". Rozwiązaniem jest **przycięcie macierzy przed podaniem jej walidatorowi**, nie zmiana walidatora: ta sama przycięta macierz karmi `SubmissionValidator` i `SubmissionFilter`, więc odsiew i walidacja nie mogą się rozjechać.

- [ ] **Krok 1: Napisz testy**

```php
public function test_pruning_drops_entries_for_fields_absent_from_the_list(): void {
    $m = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'znikle' => 'required' ) ) );
    $p = $m->prunedTo( array( 'pwz' ) );
    $this->assertSame( array( 'pwz' ), $p->requiredFields( 'lekarz' ) );
    $this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'znikle' ) );
}

public function test_pruning_never_adds_an_entry_or_raises_a_state(): void {
    $m = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'visible' ) ) );
    $p = $m->prunedTo( array( 'pwz', 'miasto' ) );
    $this->assertSame( FieldState::Visible, $p->stateFor( 'lekarz', 'pwz' ) );
    $this->assertSame( FieldState::Hidden, $p->stateFor( 'lekarz', 'miasto' ) );
    $this->assertSame( array(), $p->requiredFields( 'lekarz' ) );
}

public function test_pruning_leaves_the_original_untouched(): void { /* $m->requiredFields('lekarz') nadal zawiera 'znikle' */ }

public function test_a_required_field_missing_from_the_source_does_not_block_registration(): void {
    // Macierz wymaga 'znikle', źródło go nie ma. Walidator na przyciętej macierzy
    // przyjmuje zgłoszenie bez tego pola.
    $pruned = FieldMatrix::fromArray( array( 'lekarz' => array( 'pwz' => 'required', 'znikle' => 'required' ) ) )
        ->prunedTo( array( 'pwz' ) );
    $v = new SubmissionValidator( $professions, $pruned, new ValidatorRegistry() );
    $r = $v->validate( 'lekarz', 'pwz', array( 'pwz' => '5425740' ) );
    $this->assertTrue( $r->isValid() );
}

public function test_plan_lists_visible_and_required_fields_in_source_order_and_omits_hidden(): void { /* źródło: miasto, pwz, zgoda; macierz: pwz=required, miasto=visible, zgoda=hidden → ['miasto'=>Visible,'pwz'=>Required] */ }

public function test_plan_omits_a_field_absent_from_the_matrix(): void { /* pole w źródle bez wpisu → nieobecne w fieldsFor() */ }
```

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `prunedTo()` i `RegistrationPlan`**

- [ ] **Krok 4: Suity, PHPStan, PHPCS** — `DomainPurityTest` musi przechodzić, oba pliki są w `src/Domain/**`.

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: przyciecie macierzy do pol zrodla i plan renderowania rejestracji"
```

---

### Task 3: Pola ACF w formularzu i odznaczone pole logiczne

**Pliki:**
- Utwórz: `src/Domain/Registration/BooleanNormalizer.php`, `src/Front/AcfFields.php`
- Test: `tests/Unit/Registration/BooleanNormalizerTest.php`, `tests/Integration/Front/AcfFieldsTest.php`

**Interfejsy:**
- Konsumuje: `AcfFieldSource`, `FieldState`
- Produkuje:
  - `BooleanNormalizer::{__construct(array $booleanFieldKeys), normalize(array $values): array}` — dla pól logicznych wartość `'0'`, `0`, `0.0` i `false` staje się `''`; wartości pozostałych pól **nietknięte**.
  - `AcfFields::{__construct(string $groupKey), booleanKeys(): list<string>, render(string $fieldKey, bool $required, mixed $value): void, fromRequest(array $raw): array}`

To zadanie realizuje punkt 2 z „Review Focus" i **najważniejszą pozycję backlogu**. Pole ACF typu `true_false` renderuje ukryty `<input value="0">` plus checkbox z `value="1"`, a `SubmissionValidator::isEmpty()` traktuje `'0'` jako **wartość obecną** — i słusznie, bo `0` jest legalną wartością liczbową dla pola „lata praktyki". Bez normalizacji **odznaczona wymagana zgoda spełniłaby `required`**, a zgoda jest jedynym polem, dla którego specyfikacja wymaga znacznika czasu i wersji regulaminu. Normalizacja zawęża się do pól, których typ ACF to `true_false` — stąd `booleanKeys()`.

`render()` deleguje do ACF (`acf_render_field_wrap()` albo równoważnego), bo specyfikacja oddaje ACF renderowanie i zapis pól; wtyczka kontroluje tylko nazwy w żądaniu, atrybut wymagalności i wartość początkową.

- [ ] **Krok 1: Napisz testy**

```php
public function test_unchecked_boolean_becomes_an_absent_value(): void {
    $n = new BooleanNormalizer( array( 'zgoda' ) );
    $this->assertSame( array( 'zgoda' => '' ), $n->normalize( array( 'zgoda' => '0' ) ) );
}

public function test_checked_boolean_survives(): void { /* '1' → '1' */ }

public function test_zero_in_a_field_that_is_not_boolean_survives(): void {
    $n = new BooleanNormalizer( array( 'zgoda' ) );
    $this->assertSame( array( 'lata' => '0' ), $n->normalize( array( 'lata' => '0' ) ) );
}

public function test_every_falsy_shape_of_an_unchecked_boolean_is_treated_alike(): void { /* 0, 0.0, false, '0' → '' */ }

public function test_unchecked_required_consent_fails_validation_after_normalisation(): void {
    // Dowód na styku: normalizacja + walidator z Planu 1.
    $values = ( new BooleanNormalizer( array( 'zgoda' ) ) )->normalize( array( 'zgoda' => '0' ) );
    $r = $validatorWithRequiredConsent->validate( 'lekarz', '', $values );
    $this->assertFalse( $r->isValid() );
    $this->assertSame( 'required', $r->errorFor( 'zgoda' ) );
}

// Integracja: typ pola czytany z ACF, nie zgadywany.
public function test_boolean_keys_come_from_the_acf_field_definitions(): void {
    add_filter( 'pusers_acf_fields', /* pola: zgoda typu true_false, pwz typu text */ );
    $this->assertSame( array( 'zgoda' ), ( new AcfFields( 'group_abc' ) )->booleanKeys() );
}

public function test_values_are_read_only_for_known_fields(): void { /* klucz spoza grupy w żądaniu jest pomijany */ }
```

Ostatni test jest nośny: `fromRequest()` czyta żądanie **białą listą** kluczy ze źródła, nigdy hurtem.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `BooleanNormalizer` i `AcfFields`**

`booleanKeys()` czyta typ pola z definicji ACF. Filtr `pusers_acf_fields` z Planu 2 zwraca dziś pary klucz → etykieta; jeśli nie przenosi typu, rozszerz **adapter**, nie testy: typ musi pochodzić ze źródła, bo lista pól logicznych wypisana ręcznie rozjedzie się przy pierwszym nowym polu zgody.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: odznaczone pole logiczne nie spelnia wymogu, pola czytane z definicji ACF"
```

---

### Task 4: Formularz rejestracji na stronie

**Pliki:**
- Utwórz: `src/Front/RegistrationForm.php`, `assets/js/registration.js`
- Test: `tests/Integration/Front/RegistrationFormTest.php`

**Interfejsy:**
- Konsumuje: `RegistrationPlan`, `AcfFields`, `SettingsRepository`, `ProfessionRepository`, `FieldMatrixRepository`, `AcfFieldSource`
- Produkuje:
  - `RegistrationForm::{SHORTCODE='portal_users_registration', NONCE_ACTION='pusers_register', FIELD_PREFIX='pusers_', HONEYPOT='pusers_website', TIMESTAMP='pusers_t', register(): void, render(): string, plan(): RegistrationPlan}`

Pole profesji renderuje wtyczka i **stoi zawsze jako pierwsze**. Dalej pola z `RegistrationPlan::fieldsFor()` dla każdej profesji, hasło i powtórzenie hasła, honeypot ukryty stylem, znacznik czasu załadowania, nonce. Bez JavaScriptu formularz pokazuje pola wszystkich profesji — serwer i tak odsiewa po macierzy, więc zgłoszenie pozostaje poprawne; `registration.js` jedynie **ukrywa** to, co nie należy do wybranej profesji, na podstawie mapy przekazanej przez `wp_localize_script`.

- [ ] **Krok 1: Napisz testy**

```php
public function test_profession_select_is_rendered_before_any_acf_field(): void { /* pozycja selecta profesji < pozycja pierwszego pola */ }

public function test_select_offers_every_configured_profession_and_nothing_else(): void {}

public function test_form_carries_a_nonce_a_honeypot_and_a_load_timestamp(): void {}

public function test_honeypot_is_not_visible_to_a_human(): void { /* pole ma styl ukrywający, nie type="hidden" — bot wypełnia widoczne pola tekstowe */ }

public function test_field_labels_and_profession_labels_are_escaped(): void { /* etykieta '<script>alert(1)</script>' nie wychodzi dosłownie */ }

public function test_hidden_field_of_a_profession_is_absent_from_the_localised_map(): void {}

public function test_submitted_values_are_repopulated_after_a_failed_submission(): void { /* wartości z sesji błędu wracają do pól, hasła nigdy */ }

public function test_password_is_never_repopulated(): void {}

public function test_shortcode_renders_nothing_but_a_notice_when_no_profession_is_configured(): void { /* zamiast pustego formularza bez sensu */ }
```

Test niepowtarzania hasła jest nośny: powtórne wypełnienie po błędzie jest wygodą, która nie może odesłać hasła z powrotem do przeglądarki w HTML-u.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `RegistrationForm` i `registration.js`**

Skrypt ładuj wyłącznie wtedy, gdy element wstawiany w edytorze faktycznie wystąpił na stronie.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: formularz rejestracji z profesja jako pierwszym polem i honeypotem"
```

---

### Task 5: Antyspam — pułapki, limity, log

**Pliki:**
- Utwórz: `src/Domain/Spam/{IpAddress,SpamVerdict,SpamRules}.php`, `src/Front/{RateLimiter,SpamGuard}.php`, `src/Persistence/EventLog.php`
- Zmodyfikuj: `src/Domain/Config/Settings.php`, `src/Admin/SettingsScreen.php` (jeden nowy próg)
- Test: `tests/Unit/Spam/{IpAddressTest,SpamRulesTest}.php`, `tests/Integration/Front/SpamGuardTest.php`, `tests/Integration/Persistence/EventLogTest.php`

**Interfejsy:**
- Konsumuje: `Settings`, `Migrations::table('event_log')`
- Produkuje:
  - `Settings` **jeden** nowy klucz `registration_limit_per_email_per_hour` (domyślnie 3) i właściwość `$registrationLimitPerEmailPerHour`. Próg per adres IP to **istniejący** `rate_limit_per_hour`, którego etykieta w panelu brzmi już „Limit zgłoszeń z jednego adresu na godzinę" — nie dublujemy go nowym kluczem, bo stary stałby się polem, które administrator edytuje bez żadnego efektu. Plan 4 doda **własne** progi dla logowania i odzyskiwania hasła i nie będzie reużywał tego; wtedy warto rozważyć przemianowanie tego klucza na rejestracyjny, ale to zmiana ruszająca zapisane dane, więc nie tutaj.
  - `IpAddress::from( array $server, string $trustedHeader ): string` — przy pustym `$trustedHeader` **wyłącznie** `REMOTE_ADDR`.
  - `SpamVerdict::{pass(): self, reject(string $reason): self, passed(): bool, reason(): string}`; powody: `honeypot`, `too_fast`, `ip_limit`, `email_limit`.
  - `SpamRules::{__construct(int $timeTrapSeconds), check(string $honeypotValue, int $loadedAt, int $now): SpamVerdict}`
  - `RateLimiter::{hit(string $bucket): int, count(string $bucket): int, clear(string $bucket): void, BLOCK_MINUTES=60}`
  - `SpamGuard::{check(array $server, array $request, string $email): SpamVerdict, FILTER='pusers_registration_spam_check'}`
  - `EventLog::write(string $action, int $subjectUserId, int $actorUserId, array $context): void`

To zadanie realizuje punkt 4 z „Review Focus" i wiążący wymóg backlogu o granulacji. Specyfikacja nazywa anty-celem odcięcie szpitala siedzącego za jednym adresem wyjściowym, więc **próg per IP jest hojny, a próg per adres e-mail ciasny**, i oba muszą istnieć. `FILTER` jest punktem rozszerzenia dla Turnstile albo hCaptcha: filtr dostaje `SpamVerdict` i może go zamienić na odrzucenie, nie dotykając wtyczki.

- [ ] **Krok 1: Napisz testy**

```php
public function test_untrusted_proxy_header_is_ignored(): void {
    $server = array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' );
    $this->assertSame( '10.0.0.1', IpAddress::from( $server, '' ) );
}

public function test_trusted_header_is_honoured_when_the_administrator_named_it(): void { /* 'HTTP_X_FORWARDED_FOR' → '1.2.3.4' */ }

public function test_first_address_is_taken_from_a_comma_separated_chain(): void {}

public function test_trusted_header_with_a_nonsense_value_falls_back_to_remote_addr(): void {
    // Kierunek błędu: nie puszczamy limitu na zmyślonym adresie.
}

public function test_filled_honeypot_is_rejected(): void {}

public function test_form_sent_faster_than_the_configured_trap_is_rejected(): void { /* timeTrapSeconds=3, różnica 2 s → 'too_fast' */ }

public function test_form_sent_after_the_trap_passes(): void { /* różnica 3 s → pass */ }

public function test_missing_or_future_load_timestamp_is_rejected_as_too_fast(): void {}

public function test_many_registrations_from_one_ip_with_different_emails_are_not_blocked_before_the_ip_threshold(): void {
    // Review Focus 4: szpital za jednym NAT-em. Ustaw rate_limit_per_hour na 20
    // i registration_limit_per_email_per_hour na 3; dziewiętnaście RÓŻNYCH adresów
    // z jednego IP przechodzi, bo licznik per adres e-mail nie jest licznikiem per IP.
}

public function test_the_same_email_tried_over_the_email_threshold_is_rejected(): void { /* czwarta próba tego samego adresu → 'email_limit' */ }

public function test_exceeding_the_ip_threshold_is_rejected_and_written_to_the_event_log(): void {}

public function test_extension_point_can_turn_a_pass_into_a_rejection(): void {
    add_filter( SpamGuard::FILTER, fn() => SpamVerdict::reject( 'turnstile' ) );
}

public function test_extension_point_cannot_turn_a_rejection_into_a_pass(): void {
    // Wtyczka zewnętrzna nie może rozbroić honeypota ani limitu.
}

public function test_event_log_row_lands_with_action_context_and_timestamp(): void {}
```

Dwa ostatnie testy punktu rozszerzenia są nośne: filtr, który potrafi **zdjąć** odrzucenie, zamienia punkt rozszerzenia w obejście antyspamu.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj domenę, potem `RateLimiter`, `SpamGuard`, `EventLog`**

Liczniki na transientach z oknem godzinowym; `clear()` istnieje dla przyszłego przycisku w panelu z Planu 6. Testy ekranu ustawień z Planu 2 **nie mogą spaść** po dodaniu dwóch pól — jeśli spadły, zapis zeruje coś, czego nie przysłano.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: antyspam rejestracji - honeypot, pulapka czasowa, progi per IP i per adres"
```

---

### Task 6: Obsługa zgłoszenia i utworzenie konta

**Pliki:**
- Utwórz: `src/Front/RegistrationHandler.php`
- Test: `tests/Integration/Front/RegistrationHandlerTest.php`

**Interfejsy:**
- Konsumuje: `RegistrationForm`, `AcfFields`, `BooleanNormalizer`, `RegistrationPlan`, `SubmissionValidator`, `SubmissionFilter`, `SpamGuard`, `AccountStatusRepository`, `UserMeta`, `EventLog`, `Mailer` (Task 7), `TermsRepository` (Task 8)
- Produkuje:
  - `RegistrationHandler::{ACTION='pusers_register', register(): void, handle(): void}`

Kolejność bramek **nienaruszalna**: nonce → metoda POST → antyspam → walidacja → utworzenie konta → PRG. Antyspam **przed** walidacją, żeby bot nie dostawał informacji o polach. Konto powstaje w stanie `email_unconfirmed`, z rolą domyślną witryny (`get_option( 'default_role' )`) — specyfikacja nie definiuje grup, więc zatwierdzeni dostają wyłącznie możliwość logowania.

Wartości zapisywane do metadanych **po odsiewie** `SubmissionFilter::strip()` i po sanityzacji; wymóg backlogu o sanityzacji jest wiążący, bo `values()` może nieść tablicę i każdy późniejszy konsument deklarujący `string` przy `strict_types` wywaliłby `TypeError`.

To zadanie realizuje punkt 3 z „Review Focus".

- [ ] **Krok 1: Napisz testy**

```php
public function test_a_valid_submission_creates_an_account_in_the_unconfirmed_state(): void {}

public function test_profession_outside_the_collection_creates_no_account(): void {
    // Review Focus 3: podrobione żądanie.
    $before = count_users()['total_users'];
    /* POST z profession='nie-ma-takiej' */
    $this->assertSame( $before, count_users()['total_users'] );
}

public function test_a_field_hidden_for_the_chosen_profession_is_not_saved_even_when_present_in_the_request(): void {
    // Serwer nie ufa przeglądarce.
}

public function test_missing_nonce_creates_no_account(): void {}
public function test_a_get_request_creates_no_account(): void {}
public function test_a_submission_rejected_by_the_spam_guard_creates_no_account_and_is_not_validated(): void {}

public function test_an_invalid_submission_creates_no_account_and_redirects_back_with_errors(): void {}

public function test_an_email_already_registered_creates_no_second_account_and_says_so(): void {}

public function test_values_are_sanitised_before_they_reach_user_meta(): void {
    // Tablica w polu numeru nie wchodzi do metadanych zserializowana.
}

public function test_credential_warning_does_not_block_the_submission(): void {
    // Walidacja numeru jest miękka: zła suma kontrolna przechodzi, redakcja zweryfikuje.
    $this->assertNotSame( 0, $createdUserId );
}

public function test_required_error_does_not_also_surface_an_empty_warning_for_the_same_field(): void {
    // Wymóg backlogu: ten sam fakt opisany raz.
}

public function test_registration_is_written_to_the_event_log(): void {}
```

Test miękkiej walidacji numeru jest nośny: twarda blokada na sumie kontrolnej to dokładnie anty-cel nazwany w specyfikacji najważniejszym, a decyzja zamawiającego brzmi „redaktor zweryfikuje".

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `RegistrationHandler`**

Walidator i filtr buduj **na macierzy przyciętej** przez `FieldMatrix::prunedTo()` do pól z `AcfFieldSource::fields()`; inaczej wraca mina z punktu 1 „Review Focus". Błędy i wpisane wartości na czas przekierowania trzymaj tak, by przeżyły PRG i nie wyciekły do innego użytkownika; hasła nie przenoś.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: obsluga zgloszenia rejestracji z bramkami i kontem w stanie niepotwierdzonym"
```

---

### Task 7: Potwierdzenie adresu i przejście stanu

**Pliki:**
- Utwórz: `src/Front/Confirmation.php`, `src/Front/Mailer.php`
- Test: `tests/Integration/Front/ConfirmationTest.php`, `tests/Integration/Front/MailerTest.php`

**Interfejsy:**
- Konsumuje: `AccountStatusRepository`, `UserMeta`, `SettingsRepository`, `EventLog`, `ProfessionRepository`
- Produkuje:
  - `Confirmation::{QUERY_USER='pusers_uid', QUERY_TOKEN='pusers_token', ACTION_RESEND='pusers_resend', register(): void, issue(int $userId): string, verify(int $userId, string $token): bool, handle(): void, handle_resend(): void}`
  - `Mailer::{confirmation(int $userId, string $link): bool, editorial(int $userId): bool, FILTER_BODY='pusers_mail_body'}`

`issue()` zwraca token jawny do wstawienia w link, a w metadanych zostawia **wyłącznie jego skrót** i termin ważności liczony z `Settings::$confirmationTokenHours`. Porównanie przez `hash_equals`. Po udanym potwierdzeniu konto przechodzi w `pending`, a przy **wyłączonej** akceptacji prosto w `approved` — i w obu razach redakcja dostaje maila, w drugim czysto informacyjnie.

Treść maili jest minimalna i przechodzi przez `FILTER_BODY`, więc Plan 5 podmieni szablony bez ruszania zdarzeń.

To zadanie realizuje punkt 5 z „Review Focus".

- [ ] **Krok 1: Napisz testy**

```php
public function test_only_the_token_hash_is_stored(): void {
    $token = Confirmation::issue( $id );
    $this->assertNotSame( $token, get_user_meta( $id, UserMeta::CONFIRM_HASH, true ) );
    $this->assertNotEmpty( get_user_meta( $id, UserMeta::CONFIRM_HASH, true ) );
}

public function test_a_correct_token_moves_the_account_to_pending(): void {}

public function test_a_wrong_token_changes_nothing(): void {}

public function test_an_expired_token_changes_nothing_and_says_the_link_expired(): void {
    // confirmationTokenHours=48, termin w przeszłości.
}

public function test_a_token_used_twice_does_not_move_an_approved_account_back_to_pending(): void {
    // Review Focus 5: ponowne kliknięcie nie cofa decyzji redakcji.
}

public function test_with_approval_disabled_confirmation_moves_the_account_straight_to_approved(): void {}

public function test_editors_are_notified_on_the_transition_in_both_approval_modes(): void {}

public function test_notification_goes_to_the_configured_recipients_not_to_the_site_admin(): void {
    // Powód istnienia tej wtyczki: nie zawsze główny administrator za to odpowiada.
}

public function test_profession_specific_recipient_overrides_the_global_list(): void {
    // Profession::$notifyEmail
}

public function test_resend_issues_a_new_token_and_invalidates_the_previous_one(): void {}

public function test_resend_is_rate_limited(): void {}

public function test_resend_does_not_reveal_whether_the_address_exists(): void {
    // Ten sam komunikat dla adresu istniejącego i nie.
}

public function test_mail_body_can_be_replaced_through_the_filter(): void {}
```

Test o odbiorcach jest nośny wobec intencji zamawiającego; test o nieujawnianiu istnienia adresu zamyka wyliczanie kont przez ponowną wysyłkę.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `Confirmation` i `Mailer`**

Endpoint potwierdzenia rozpoznawaj po parametrach żądania na stronie rejestracji i kończ **przekierowaniem** z komunikatem, żeby token nie został w adresie po odświeżeniu.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: potwierdzenie adresu tokenem, przejscie stanu i powiadomienie redakcji"
```

---

### Task 8: Zgoda powiązana z wersją regulaminu

**Pliki:**
- Utwórz: `src/Persistence/TermsRepository.php`
- Zmodyfikuj: `src/Domain/Config/Settings.php`, `src/Admin/SettingsScreen.php` (treść regulaminu), `src/Front/RegistrationHandler.php` (zapis powiązania)
- Test: `tests/Integration/Persistence/TermsRepositoryTest.php`

**Interfejsy:**
- Konsumuje: `Migrations::table('terms_versions')`, `SettingsRepository`, `UserMeta`
- Produkuje:
  - `Settings` nowy klucz `terms_text` i właściwość `$termsText`
  - `TermsRepository::{currentHash(): string, ensureVersion(string $content): string, contentFor(string $hash): ?string}`

Specyfikacja wymaga, by zgoda była zapisywana ze znacznikiem czasu i identyfikatorem wersji regulaminu, a treść nie była kopiowana do każdego konta: wtyczka trzyma archiwum wersji i wiąże konto z wersją. Tabela `terms_versions` istnieje od Planu 1 i **nikt do niej dotąd nie pisał** — bez tego zadania wymóg zgód zostałby wdrożony pozornie, bo konto wiązałoby się z wersją, której nigdy nie powstała.

`ensureVersion()` liczy skrót treści i dopisuje wiersz tylko wtedy, gdy takiego skrótu jeszcze nie ma, więc zmiana regulaminu tworzy nową wersję, a zapis bez zmiany nie mnoży wierszy.

- [ ] **Krok 1: Napisz testy**

```php
public function test_saving_the_same_content_twice_creates_one_version(): void {}

public function test_changing_the_content_creates_a_new_version_and_keeps_the_old_one(): void {
    // Konto zarejestrowane wcześniej musi dalej wskazywać na tekst, który podpisał użytkownik.
}

public function test_registration_binds_the_account_to_the_current_version_and_a_timestamp(): void {}

public function test_content_can_be_read_back_for_an_old_hash(): void {}

public function test_registration_without_configured_terms_stores_no_version_and_still_succeeds(): void {
    // Portal bez regulaminu nie może przestać przyjmować rejestracji.
}
```

Ostatni test pilnuje kierunku błędu: brak skonfigurowanego regulaminu to niedokończona konfiguracja administratora, nie powód, by odciąć rejestrację.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `TermsRepository`, dodaj pole ustawień, zapisz powiązanie w handlerze**

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: archiwum wersji regulaminu i powiazanie zgody z wersja"
```

---

### Task 9: Cron czyszczący konta niepotwierdzone

**Pliki:**
- Utwórz: `src/Domain/Account/PurgeSelection.php`, `src/Cron/PurgeUnconfirmed.php`
- Zmodyfikuj: `portal-users.php` (harmonogram przy aktywacji, zdjęcie przy deaktywacji)
- Test: `tests/Unit/Account/PurgeSelectionTest.php`, `tests/Integration/Cron/PurgeUnconfirmedTest.php`

**Interfejsy:**
- Konsumuje: `AccountStatusRepository`, `UserMeta`, `SettingsRepository`, `EventLog`
- Produkuje:
  - `PurgeSelection::{__construct(int $purgeDays), qualifies(?AccountStatus $status, int $registeredAt, int $now): bool}`
  - `PurgeUnconfirmed::{HOOK='pusers_purge_unconfirmed', register(): void, schedule(): void, unschedule(): void, run(): void}`

Konto WordPressa powstaje już przy wysłaniu formularza, więc adres jest zajęty od tej chwili. Bez czyszczenia porzucona albo botowa rejestracja zablokowałaby adres prawdziwemu lekarzowi **na zawsze**, a zadeklarowana w specyfikacji ścieżka naprawy danych („zarejestruj się od nowa") w ogóle by nie działała. Okres bierzemy z `Settings::$unconfirmedPurgeDays`.

Decyzja o zakresie jest wąska celowo: kasowane są **wyłącznie** konta w stanie `email_unconfirmed`. Konta `rejected` zostają, bo odrzucenie jest udokumentowaną decyzją redakcji; konta **bez stanu** zostają, bo to administratorzy dodani ręcznie w panelu.

- [ ] **Krok 1: Napisz testy**

```php
public function test_unconfirmed_account_older_than_the_window_qualifies(): void {}
public function test_unconfirmed_account_inside_the_window_does_not_qualify(): void {}
public function test_rejected_account_never_qualifies(): void {}
public function test_approved_and_pending_accounts_never_qualify(): void {}
public function test_account_without_a_status_never_qualifies(): void {
    // Administrator dodany ręcznie nie może zostać skasowany cronem.
}

public function test_run_deletes_only_qualifying_accounts_and_frees_the_address(): void {
    // Po przebiegu adres daje się zarejestrować ponownie.
}

public function test_run_writes_each_deletion_to_the_event_log(): void {}

public function test_activation_schedules_the_hook_and_deactivation_removes_it(): void {}

public function test_run_deletes_nothing_when_no_account_qualifies(): void {}
```

Test konta bez stanu jest najważniejszy w tym zadaniu: cron kasujący konta to jedyna operacja nieodwracalna w tym planie, a pomyłka zabrałaby administratorowi dostęp do własnego portalu.

- [ ] **Krok 2: Uruchom testy i potwierdź, że padają**

- [ ] **Krok 3: Zaimplementuj `PurgeSelection`, potem `PurgeUnconfirmed`**

Decyzję „kasować czy nie" trzyma domena, żeby dała się przetestować bez bazy; `run()` tylko wybiera kandydatów, pyta domenę i kasuje. Rejestracja harmonogramu w `portal-users.php` na poziomie pliku, obok istniejącego `register_activation_hook`.

- [ ] **Krok 4: Suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

```bash
git commit -m "feat: cron czyszczacy konta z niepotwierdzonym adresem zwalnia zajete adresy"
```

---

## Autoprzegląd

**1. Pokrycie specyfikacji.** Sekcja „Pola rejestracji" → Taski 2, 3, 4. „Przebieg rejestracji" kroki 1-2 → Taski 6, 7; krok 3 (powiadomienie redakcji) → Task 7; kroki 4-5 → Plan 6. „Zajęty adres e-mail i porzucone rejestracje" → Task 9. „Strony na froncie", akapit o rejestracji → Task 4; logowanie i odzyskiwanie hasła → Plan 4. „Odporność na boty" → Task 5, bez progów logowania i odzyskiwania (Plan 4) i bez przycisku zdejmowania blokady (Plan 6, `RateLimiter::clear()` już jest). „Dane osobowe", zgody → Task 8; eksport, usuwanie i anonimizacja → Plan 7. „Walidacja jest miękka" → Task 6. Luka świadoma: specyfikacja wspomina log wysyłek maili z retencją 90 dni — ten plan pisze do `event_log`, a osobnego logu wysyłek nie tworzy; należy do Planu 5, który posiada wysyłkę.

**2. Przegląd kroków.** Każdy krok kodu ma sygnaturę i plik; ciał funkcji nie ma nigdzie, bo testy i sygnatury wyznaczają jedno rozsądne rozwiązanie. Trzy miejsca niosą algorytm, którego sygnatura nie wyznacza, i tam stoi jedno zdanie: przycięcie macierzy przed walidacją (Task 6), skrót tokenu zamiast tokenu (Task 7), podział decyzji na domenę i wykonanie (Task 9).

**3. Spójność nazw.** `UserMeta::PROFESSION` z Taska 1 jest konsumowane przez Taski 6, 7, 9 pod tą samą nazwą; `FieldMatrix::prunedTo()` z Taska 2 przez Task 6; `SpamVerdict` z Taska 5 przez Task 6 i przez punkt rozszerzenia; `AccountStatus` pochodzi z Planu 1 i nie jest tu redefiniowane. `RegistrationForm::NONCE_ACTION` i `RegistrationHandler::ACTION` mają **tę samą wartość** `pusers_register` — to jedna akcja admin-post, formularz ją wystawia, handler jej pilnuje.

**4. Review Focus.** Pięć klas wejścia ma przypisane testy: pole wymagane nieobecne w ACF (Task 2), odznaczona zgoda (Task 3), profesja spoza kolekcji (Task 6), szpital za jednym IP (Task 5), link użyty dwa razy albo po terminie (Task 7).

**5. Proporcja.** Specyfikacja ma 342 linie, plan około dwa razy tyle — sprawdziłem, czy to nadmiar, i zostawiam świadomie. Żaden blok kodu nie jest implementacją: to nazwy testów z asercjami, a dziewięć zadań po kilkanaście testów daje tę objętość samo. Proza poza testami niesie wyłącznie rozstrzygnięcia, których wykonawca nie podejmie sam, i powód każdego z nich; wycięcie powodów skróciłoby dokument i odebrało mu to, co chroni przed cichym pominięciem wymogu, jak przy progu per adres e-mail wyżej.

## Przekazanie do wykonania

Plan gotowy i zapisany. Zadań jest dziewięć, mocno zazębionych interfejsami: Task 6 konsumuje wynik czterech wcześniejszych, a pomyłka w bramkach albo w przycięciu macierzy kończy się albo kontem wpuszczonym bez weryfikacji numeru, albo zablokowaną rejestracją całej profesji. Dlatego **zalecam metodę subagent-driven**: świeży wykonawca na zadanie i świeża recenzja przed następnym.
