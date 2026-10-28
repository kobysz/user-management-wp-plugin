# Logowanie na froncie — plan wdrożenia

> **Dla wykonawców agentowych:** WYMAGANA PODUMIEJĘTNOŚĆ: użyj superpowers:subagent-driven-development (zalecane) albo superpowers:executing-plans, by zrealizować ten plan zadanie po zadaniu. Kroki mają składnię pól wyboru (`- [ ]`) do odhaczania.

**Cel:** Lekarz loguje się na własnej stronie portalu i wchodzi tylko wtedy, gdy redakcja zatwierdziła jego konto; każdy inny stan dostaje komunikat mówiący prawdę o tym, na co czeka — a człowiek, który hasła nie zna, nie dowie się z portalu nawet tego, czy dany adres ma konto.

**Architektura:** Brama stanu konta wchodzi na filtr `authenticate` z priorytetem **40**, czyli **za każdym** rdzeniowym wyrokiem (rdzeń trzyma `wp_authenticate_username_password` i `wp_authenticate_email_password` na 20, a `wp_signon()` dokłada z wnętrza swojego ciała `wp_authenticate_cookie` na 30 — `wp-includes/user.php:107`). Dzięki temu brama nie jest osiągalna dla nikogo, kto hasła nie zna — nie trzeba udawać komunikatu „błędne hasło", żeby nie ujawnić istnienia konta, bo do bramy się po prostu nie dochodzi. Strona logowania jest shortcode'em wtyczki, żądanie idzie przez `admin-post.php` i `wp_signon()`. Odzyskiwanie hasła opiera się na **rdzeniowym** mechanizmie (`retrieve_password()`, `check_password_reset_key()`, `reset_password()`) z linkiem skierowanym na stronę wtyczki; drugiego magazynu tokenów nie powstaje. `wp-login.php` zostaje czynne dla redakcji.

**Stos:** WordPress 6.5+, PHP 8.1+, ACF Pro (zależność twarda, sprawdzana w `Dependencies`), PHPUnit w kontenerze wp-env, PHPStan level max, PHPCS `WordPress-Core` + `WordPress-Extra` + `WordPress-Docs`.

**Specyfikacja:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`

**Wymogi przeniesione:** `docs/superpowers/backlog.md`, sekcje „Plan 4 — Logowanie" oraz „Plany 3 i 4 — granulacja limitu prób" — **wiążące**.

## Ograniczenia globalne

- Warstwa `src/Domain/**` nie wykonuje **żadnych** wywołań WordPressa; pilnuje tego `DomainPurityTest` białą listą na `token_get_all()`.
- Wszystkie teksty widoczne dla użytkownika przez `__()` / `esc_html__()` z domeną `portal-users`. **Zakaz `_n()`** — język źródłowy jest polski, katalogu `.po` nie ma, więc gettext zastosowałby angielską regułę dwóch form. Liczbę stawiaj na końcu zdania po dwukropku.
- Kolejność bramek w handlerze żądania publicznego: **nonce → metoda → antyspam → próg → akcja → PRG**.
- Każdy nowy klucz ustawień dochodzi do `Settings` addytywnie; `Settings::fromArray()` dopełnia brakujące domyślnymi, więc zapisane konfiguracje się nie poruszą.
- Nazwy kluczy metadanych i opcji **tylko** ze stałych; zakaz literałów w drugim miejscu.
- Przekierowania wyłącznie przez `wp_safe_redirect()`; każdy adres z żądania przez `wp_validate_redirect()`.
- Kubełek progu kluczowany kontem powstaje **wyłącznie dla konta, które udało się odnaleźć**. Kubełek na niesprawdzony łańcuch z żądania to droga do zapychania `wp_options` transientami.
- Opcje zapisywane z `autoload = false`.
- Niczego nie kasujemy nieodwracalnie.

## Co ten plan konsumuje z Planów 1–3

Sygnatury sprawdzone w kodzie na `main`, commit `dccc737`:

```php
PortalUsers\Domain\Account\AccountStatus — enum string: email_unconfirmed|pending|approved|rejected; tryFromStored(?string):?self
PortalUsers\Domain\Account\LoginGate::decide( ?AccountStatus ): LoginDecision
PortalUsers\Domain\Account\LoginDecision::{allow():self, deny(string):self, allowed():bool, code():string}
PortalUsers\Domain\Spam\IpAddress::from( array $server, string $trustedHeader ): string
PortalUsers\Domain\Spam\SpamVerdict::{pass():self, reject(string):self, passed():bool, reason():string}
PortalUsers\Domain\Config\Settings — readonly: approvalEnabled, loginPageId, registerPageId, resetPageId,
    notifyEmails, confirmationTokenHours, unconfirmedPurgeDays, rateLimitPerHour,
    registrationLimitPerEmailPerHour, timeTrapSeconds, trustedProxyHeader, rejectionReasons,
    deleteDataOnUninstall, acfGroupKey, termsText, termsConsentField; fromArray(array):self, toArray():array
PortalUsers\Persistence\SettingsRepository::{get():Settings, save(Settings):void}
PortalUsers\Persistence\AccountStatusRepository::{get(int):?AccountStatus, isUnrecognised(int):bool, set(int,AccountStatus):void}
PortalUsers\Persistence\EventLog::write( string $action, int $subjectUserId, int $actorUserId, array $context ): void
PortalUsers\Persistence\UserMeta::{STATUS, CONFIRM_HASH, CONFIRM_EXPIRES, PASSWORD_TICKET_HASH,
    PASSWORD_TICKET_EXPIRES, TERMS_VERSION, CONSENT_AT, PROFESSION, ACF_PREFIX}
PortalUsers\Front\RateLimiter::{BLOCK_MINUTES=60, FAILURE_LOG_ACTION, hit(string):int, count(string):int,
    clear(string):void, once_per_window(string,string):bool, reset_failure_report():void}
PortalUsers\Front\RegistrationHandler::{no_cache():void, reset_no_cache():void, random_password():string}
PortalUsers\Front\Confirmation::base_url():string        // wzorzec „adres strony z ustawień albo home_url"
PortalUsers\Front\PasswordSetup::{MIN_LENGTH=12, RESULT_SHORT='short', RESULT_EMAIL='email', LOG_ACTION='password_set'}
PortalUsers\Front\RegistrationForm::{SHORTCODE, NONCE_ACTION, NONCE_FIELD, HONEYPOT, EMAIL}
PortalUsers\Front\Mailer — wysyłka maili wtyczki (treść minimalna, Plan 5 podmieni szablony)
PortalUsers\Plugin::{boot(string):void, load_features():void}   // hook `pusers_register_features`
```

Nowe części podpinają się na akcji `pusers_register_features`; `Plugin::load_features()` odpala ją tylko, gdy `Dependencies::satisfied()`.

**Zweryfikowane w źródłach rdzenia** (kontener wp-env, WordPress 6.9):

- `wp-includes/default-filters.php:516-519` — `authenticate`: `wp_authenticate_username_password` (20), `wp_authenticate_email_password` (20), `wp_authenticate_application_password` (20), `wp_authenticate_spam_check` (99). **Ta lista jest NIEPEŁNA** i plan pierwotnie wyciągnął z niej zły wniosek („priorytet 30 jest za wyrokiem rdzenia"): piąty callback dokłada `wp_signon()` z wnętrza swojego ciała — `wp-includes/user.php:107` — `add_filter( 'authenticate', 'wp_authenticate_cookie', 30, 3 )`. Dlatego brama stoi na **40**, patrz Task 3.
- `wp-includes/user.php` — filtr `wp_authenticate_user` odpala się **przed** `wp_check_password()`. Dlatego brama **nie** wchodzi tam: odmowa spod tego filtra trafia do kogoś, kto hasła nie zna.
- `wp-admin/admin-post.php` — woła `do_action( 'admin_init' )` **przed** rozdzieleniem akcji, a komentarz w `wp-admin/admin.php:174` mówi wprost „It runs on admin-ajax.php and admin-post.php as well". Odcięcie `wp-admin` na `admin_init` bez wyjątku zabiłoby **własne** handlery wtyczki dla zalogowanego nie-redaktora.
- `wp-includes/vars.php` — `$pagenow` bierze się z `$_SERVER['PHP_SELF']` (nie z danych klienta), więc nadaje się na rozpoznanie `admin-post.php`.

## Co ten plan świadomie zostawia

- **Szablony maili i ich zmienne** — Plan 5. Ten plan wysyła maila odzyskiwania o **minimalnej, funkcjonalnej** treści przez `Mailer`, tak jak Plan 3 robił z potwierdzeniem; Plan 5 podmieni treść, nie zdarzenie.
- **Ekran wniosków, zatwierdzanie i odrzucanie, przycisk zdejmowania blokady** — Plan 6. Ten plan **czyta** stan konta i unieważnia sesje przy jego zmianie, ale sam stanu nie zmienia; `RateLimiter::clear()` już istnieje i czeka na przycisk.
- **Backfill stanów dla kont istniejących przed wdrożeniem** — Plan 7. Do jego powstania konto bez stanu jest wpuszczane (świadomie, patrz specyfikacja, wiersz 117), więc brama nie zamyka nikogo poza portalem; po Planie 7 „brak stanu" będzie oznaczać wyłącznie konto założone ręcznie w panelu.
- **Edycja profilu przez użytkownika** (zmiana adresu, hasła z panelu). Specyfikacja każe przekierowywać konta bez uprawnień redakcyjnych z `wp-admin` na front, co obejmuje `profile.php`. Skutek: lekarz zmienia hasło **wyłącznie** przez odzyskiwanie hasła. Patrz „Punkt do decyzji właściciela" niżej.
- **Dwuskładnikowe uwierzytelnianie, Turnstile, hCaptcha.** Specyfikacja żąda tylko **punktu rozszerzenia**; ten plan wystawia go filtrem przy formularzu logowania, a samych rozwiązań nie dowozi.

## Punkt do decyzji właściciela

Specyfikacja (sekcja „Strony na froncie") każe przekierowywać konta bez uprawnień do edycji treści z `wp-admin` na front. To obejmuje `wp-admin/profile.php`, czyli jedyne miejsce, w którym zalogowany lekarz mógłby dziś sam zmienić hasło albo adres e-mail. Po tym planie zmiana hasła przez zalogowanego lekarza idzie **przez odzyskiwanie hasła** (wylogowanie nie jest potrzebne, ale link przychodzi mailem).

Plan realizuje literę specyfikacji. Jeśli ma być inaczej, do decyzji są dwie drogi: wyjątek dla `profile.php` (najtaniej, ale panel pokazuje wtedy lekarzowi cały ekran profilu WordPressa) albo własna strona „moje konto" na froncie (osobny plan). **Nie zgaduj — zapytaj właściciela przed Taskiem 7.**

## Review Focus

Pięć klas wejścia, które specyfikacja zakłada, a które przy zacieśnianiu uderzą w człowieka. Test każdej z nich jest przypisany do zadania, które posiada kod:

1. **Zalogowany nie-redaktor wysyła własny formularz wtyczki** (ustawienie hasła, rejestracja, logowanie) — żądanie idzie przez `admin-post.php`, gdzie odpala się `admin_init`. Odcięcie `wp-admin` musi je przepuścić, inaczej plan wyłącza funkcje Planu 3. → Task 7.
2. **Próg logowania zużywany przez logowania UDANE** — szpital za jednym adresem wyjściowym odcięty po kilkudziesięciu normalnych wejściach. Licznik rośnie **tylko na porażkach**. → Task 6.
3. **`redirect_to` wskazujący obcy host** — otwarte przekierowanie z zaufanej domeny portalu medycznego. → Task 5 i Task 6.
4. **Zalogowany odwiedza stronę logowania** — formularz dla zalogowanego albo pętla przekierowań. Strona pokazuje notkę i odnośnik dalej. → Task 5.
5. **Redaktor wchodzi przez `wp-login.php` i `wp-admin`** — specyfikacja trzyma je jako wejście zapasowe dla redakcji; przekierowanie na front albo ukryty pasek odcięłoby redakcję od pracy. → Task 7.

## Struktura plików

| Plik | Odpowiedzialność |
|---|---|
| `src/Domain/Account/PasswordPolicy.php` | Reguły hasła jako czysta reguła: minimalna długość, zakaz hasła równego adresowi. Jedno źródło dla ustawiania hasła z biletu (Plan 3) i z odzyskiwania (ten plan). |
| `src/Front/LoginGateway.php` | Brama na `authenticate` (priorytet 40). Czyta stan konta, konsultuje `isUnrecognised()`, oddaje `WP_Error` z własnym kodem, pisze do logu. Tłumaczy kody na komunikaty. |
| `src/Front/SessionGuard.php` | Unieważnia sesje konta, które przestało być `zatwierdzony`. Nasłuchuje akcji zmiany stanu. |
| `src/Front/PageUrl.php` | Jedno źródło prawdy o adresie strony wskazanej w ustawieniach, z odwrotem na stronę główną. Zastępuje regułę zapisaną dziś wewnątrz `Confirmation::base_url()`. |
| `src/Front/LoginForm.php` | Shortcode i widok formularza logowania; notka dla zalogowanego; pułapka na boty; punkt rozszerzenia dla CAPTCHY. |
| `src/Front/LoginHandler.php` | Obsługa wysłanego formularza: progi, `wp_signon()`, jeden komunikat ogólny dla złych danych, PRG. |
| `src/Front/Redirects.php` | Przekierowanie po zalogowaniu, odcięcie `wp-admin` dla nie-redaktorów, ukrycie paska, podmiana adresów `wp_login_url()` / `wp_lostpassword_url()` / `wp_registration_url()`. |
| `src/Front/PasswordReset.php` | Odzyskiwanie hasła: formularz z adresem, wysyłka linku przez rdzeniowe `retrieve_password()`, formularz nowego hasła, `reset_password()`. |
| `src/Front/RestUsers.php` | Zamyka `wp/v2/users` dla niezalogowanych, żeby 404 kontra 403 nie zdradzało powstania konta. |
| `src/Persistence/AccountStatusRepository.php` | **Modyfikacja:** `set()` odpala akcję zmiany stanu. |
| `src/Front/PasswordSetup.php` | **Modyfikacja:** reguły hasła czyta z `PasswordPolicy`, nie z własnych stałych. |
| `src/Front/Confirmation.php` | **Modyfikacja:** `base_url()` woła `PageUrl::for()` zamiast nieść własną kopię reguły. |
| `src/Domain/Config/Settings.php`, `src/Admin/SettingsScreen.php` | **Modyfikacja:** pięć nowych kluczy (cztery progi i strona po zalogowaniu). |
| `src/Plugin.php` | **Modyfikacja:** rejestracja nowych części pod `pusers_register_features`. |

---

## Task 1: Reguły hasła jako czysta reguła domeny

Plan 3 trzyma minimalną długość i zakaz hasła równego adresowi **wewnątrz** `PasswordSetup`. Odzyskiwanie hasła potrzebuje tych samych reguł; druga kopia rozjedzie się przy pierwszej zmianie progu.

**Pliki:**
- Utwórz: `src/Domain/Account/PasswordPolicy.php`
- Modyfikuj: `src/Front/PasswordSetup.php`
- Test: `tests/Unit/Account/PasswordPolicyTest.php`

**Interfejsy:**
- Oddaje: `PasswordPolicy::{MIN_LENGTH=12, ERROR_SHORT='short', ERROR_EMAIL='email', check( string $password, string $email ): string}` — zwraca kod błędu albo `''`, gdy hasło spełnia reguły. Kolejność sprawdzeń: najpierw długość, potem równość adresowi (tak jak dziś w `PasswordSetup`).
- Konsumuje: nic.

- [ ] **Krok 1: Napisz padający test**

```php
public function test_a_password_shorter_than_the_minimum_is_refused(): void {
    $this->assertSame( PasswordPolicy::ERROR_SHORT, PasswordPolicy::check( str_repeat( 'a', 11 ), 'jan@szpital.pl' ) );
    $this->assertSame( '', PasswordPolicy::check( str_repeat( 'a', 12 ), 'jan@szpital.pl' ) );
}

public function test_a_password_equal_to_the_email_is_refused_regardless_of_letter_case(): void {
    // Hasło równe adresowi zna każdy, kto widział zgłoszenie; `Jan@Szpital.pl` to to samo hasło.
    $this->assertSame( PasswordPolicy::ERROR_EMAIL, PasswordPolicy::check( 'jan.nowak@szpital.pl', 'Jan.Nowak@Szpital.PL' ) );
}

public function test_length_is_counted_in_characters_not_bytes(): void {
    // 12 znaków polskich to więcej niż 12 bajtów; `strlen()` wpuściłoby krótsze hasło.
    $this->assertSame( '', PasswordPolicy::check( 'ąćęłńóśźżąćę', 'jan@szpital.pl' ) );
    $this->assertSame( PasswordPolicy::ERROR_SHORT, PasswordPolicy::check( 'ąćęłńóśźżąć', 'jan@szpital.pl' ) );
}
```

- [ ] **Krok 2: Uruchom test, potwierdź pad**

Uruchom: `vendor/bin/phpunit --testsuite unit --filter PasswordPolicyTest`
Oczekiwane: FAIL, „Class PasswordPolicy not found".

- [ ] **Krok 3: Zaimplementuj `PasswordPolicy::check()`**

Czysty PHP: `mb_strlen()` do długości, `strtolower()` do porównania. Żadnego wywołania WordPressa — `DomainPurityTest` tego nie wpuści.

- [ ] **Krok 4: Przepisz `PasswordSetup` na politykę**

`PasswordSetup::MIN_LENGTH` **usuwamy**, a nie zostawiamy jako alias: stała w dwóch miejscach to dokładnie ten rozjazd, który to zadanie zamyka. Zamień dwa sprawdzenia w `handle()` na jedno wywołanie `PasswordPolicy::check()` i odwzoruj kody na istniejące `RESULT_SHORT` / `RESULT_EMAIL`, żeby komunikaty i adresy wynikowe się nie zmieniły. Po zmianie `grep -rn "PasswordSetup::MIN_LENGTH" src/ tests/` musi oddać **zero** trafień.

- [ ] **Krok 5: Uruchom obie suity**

Uruchom: `vendor/bin/phpunit --testsuite unit` oraz suitę integracyjną (osobny config, patrz „Bramki").
Oczekiwane: PASS, bez spadku liczby testów. Istniejące testy `PasswordSetupTest` dla krótkiego hasła i hasła równego adresowi **muszą dalej przechodzić** — to one dowodzą, że przepisanie nic nie zmieniło.

- [ ] **Krok 6: Commit**

---

## Task 2: Pięć nowych kluczy ustawień

Specyfikacja żąda progów liczonych per adres IP **i** per konto, **osobno dla rejestracji, logowania i odzyskiwania hasła**. Dziś istnieją dwa progi rejestracyjne. Dochodzą cztery progi i jedna strona.

Wartości domyślne są **wysokie świadomie**: specyfikacja nazywa odcięcie szpitala siedzącego za jednym adresem wyjściowym celem wrogim, a próg, który odcina człowieka przy domyślnej konfiguracji, jest usterką, nie zabezpieczeniem.

**Pliki:**
- Modyfikuj: `src/Domain/Config/Settings.php`, `src/Admin/SettingsScreen.php`
- Test: `tests/Unit/Config/SettingsTest.php`, `tests/Integration/Admin/SettingsScreenTest.php`

**Interfejsy:**
- Oddaje: nowe właściwości `Settings` i klucze tablicy:

| Klucz | Właściwość | Domyślnie | Znaczenie |
|---|---|---|---|
| `login_limit_per_hour` | `$loginLimitPerHour` | `200` | Nieudane logowania z jednego adresu IP na godzinę. |
| `login_limit_per_account_per_hour` | `$loginLimitPerAccountPerHour` | `10` | Nieudane logowania na jedno konto na godzinę. |
| `reset_limit_per_hour` | `$resetLimitPerHour` | `50` | Żądania odzyskania hasła z jednego adresu IP na godzinę. |
| `reset_limit_per_email_per_hour` | `$resetLimitPerEmailPerHour` | `3` | Żądania odzyskania hasła na jeden adres e-mail na godzinę (lustro progu rejestracji). |
| `after_login_page_id` | `$afterLoginPageId` | `0` | Strona, na którą idzie konto bez uprawnień redakcyjnych po zalogowaniu; `0` znaczy strona główna. |

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_new_keys_fall_back_to_defaults_when_absent(): void {
    // Zapisane konfiguracje nie znają nowych kluczy; dopełnienie musi zadziałać, inaczej
    // aktualizacja wtyczki zeruje progi i odcina ludzi.
    $settings = Settings::fromArray( array() );

    $this->assertSame( 200, $settings->loginLimitPerHour );
    $this->assertSame( 10, $settings->loginLimitPerAccountPerHour );
    $this->assertSame( 50, $settings->resetLimitPerHour );
    $this->assertSame( 3, $settings->resetLimitPerEmailPerHour );
    $this->assertSame( 0, $settings->afterLoginPageId );
}

public function test_a_threshold_of_zero_or_below_falls_back_to_the_default(): void {
    // Próg 0 znaczyłby „nikt nigdy", czyli zamknięte logowanie dla wszystkich.
    $this->assertSame( 200, Settings::fromArray( array( 'login_limit_per_hour' => 0 ) )->loginLimitPerHour );
    $this->assertSame( 200, Settings::fromArray( array( 'login_limit_per_hour' => -5 ) )->loginLimitPerHour );
}

public function test_the_round_trip_through_to_array_preserves_new_keys(): void {
    $settings = Settings::fromArray( array( 'login_limit_per_hour' => 77, 'after_login_page_id' => 12 ) );

    $this->assertSame( 77, Settings::fromArray( $settings->toArray() )->loginLimitPerHour );
    $this->assertSame( 12, Settings::fromArray( $settings->toArray() )->afterLoginPageId );
}
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Dołóż właściwości i dopełnianie w `Settings`**

Wzoruj się na `registrationLimitPerEmailPerHour`: ten sam kształt sanityzacji (liczba całkowita dodatnia, inaczej domyślna) i ta sama kolejność w konstruktorze, `fromArray()` i `toArray()`.

- [ ] **Krok 4: Dołóż pola na ekranie ustawień**

Pięć pól w `SettingsScreen` z etykietami: „Limit nieudanych logowań z jednego adresu na godzinę", „Limit nieudanych logowań na jedno konto na godzinę", „Limit żądań odzyskania hasła z jednego adresu na godzinę", „Limit żądań odzyskania hasła na jeden adres e-mail na godzinę", „Strona po zalogowaniu". Pole strony to lista stron, jak trzy istniejące; cztery progi to pola liczbowe.

Pod progiem per konto postaw opis: „Blokada jest czasowa (60 minut) i redakcja może ją zdjąć." Administrator musi wiedzieć, że ten próg daje się wykorzystać do czasowego zablokowania znanego konta — to świadomy koszt wymogu specyfikacji, nie przeoczenie.

- [ ] **Krok 5: Test integracyjny zapisu**

Zapis przez ekran ustawień zachowuje pięć nowych wartości, a pusty formularz nie zeruje progów.

- [ ] **Krok 6: Uruchom bramki i commit**

---

## Task 3: Brama logowania podpięta za sprawdzeniem hasła

**To najpoważniejsza otwarta pozycja po Planie 3.** `LoginGate::decide()` istnieje od Planu 1 i nie ma ani jednego konsumenta, więc konto w stanie `niepotwierdzony_mail` loguje się normalnie.

**Pliki:**
- Utwórz: `src/Front/LoginGateway.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Front/LoginGatewayTest.php`

**Interfejsy:**
- Oddaje:

```php
PortalUsers\Front\LoginGateway::{
    PRIORITY = 40,
    LOG_ACTION = 'login_denied',
    CODE_UNCONFIRMED = 'pusers_email_unconfirmed',
    CODE_PENDING     = 'pusers_pending',
    CODE_REJECTED    = 'pusers_rejected',
    CODE_UNRECOGNISED = 'pusers_status_unrecognised',
    register(): void,
    gate( mixed $user ): mixed,            // WP_User|WP_Error|null -> WP_User|WP_Error|null
    message_for( string $code ): string
}
```

- Konsumuje: `LoginGate::decide()`, `AccountStatusRepository::{get,isUnrecognised}`, `EventLog::write()`.

**Dlaczego priorytet 40, a nie filtr `wp_authenticate_user`.** Backlog żąda, by odmowa dla `niepotwierdzony_mail` brzmiała **identycznie** jak błędne hasło, bo inaczej brama sama staje się sondą ujawniającą, że adres jest zajęty. Filtr `wp_authenticate_user` odpala się **przed** `wp_check_password()` (sprawdzone w `wp-includes/user.php`), więc odmowa spod niego trafia do każdego, kto zna sam adres — i wtedy udawanie komunikatu byłoby jedyną obroną, a obrona oparta na zgodności dwóch łańcuchów psuje się przy pierwszej zmianie tekstu.

Na priorytecie 40 brama dostaje `$user` **już po każdym** wyroku rdzenia: albo `WP_Error` (hasło złe — oddajemy bez dotykania), albo `WP_User` (hasło dobre albo ważne ciasteczko sesji). Kto hasła nie zna, do bramy nie dochodzi, więc każdy stan może mieć **własny, prawdziwy** komunikat — czego chce specyfikacja w wierszu 115 („każdy inny stan daje własny komunikat, żeby użytkownik wiedział, czy ma szukać maila"). **Rozstrzygnięcie: wymóg backlogu o identycznym komunikacie realizujemy konstrukcją, nie treścią** — jest zamknięty mocniej, niż żądał, i litera specyfikacji wraca w całości.

Priorytet 40 obejmuje też `wp_authenticate_application_password` (rdzeń trzyma go na 20), więc konto bez stanu `zatwierdzony` nie wejdzie także po hasło aplikacyjne, czyli do REST.

**Dlaczego 40, a nie 30 — poprawka wniesiona recenzją całej gałęzi.** Lista z `default-filters.php` nie jest całą listą: `wp_signon()` dokłada z wnętrza swojego ciała `wp_authenticate_cookie` na priorytecie **30** (`wp-includes/user.php:107`). Ciało tego uwierzytelniacza wychodzi wcześnie **tylko** dla `WP_User`, a przy pustym loginie **i** pustym haśle woła `wp_validate_auth_cookie()` i oddaje `new WP_User( $user_id )`. Brama rejestruje się przy ładowaniu wtyczki, więc **wewnątrz** priorytetu 30 szłaby pierwsza: widziałaby rdzeniowy `empty_username`, słusznie oddawała go nietkniętego, a uwierzytelniacz ciasteczka podnosiłby ten błąd do `WP_User`, którego brama już nie ogląda — i `wp_signon()` wystawiałoby na niego świeże ciasteczko. Konto **bez zatwierdzenia**, mające ważne ciasteczko sesji, odnawiałoby je bez końca pustym `POST`-em na `wp-login.php` (specyfikacja zostawia je otwarte dla redakcji, a rdzeniowa akcja logowania nonce'a nie ma), z „zapamiętaj mnie" włącznie. Czterdzieści stoi za weryfikatorami hasła (20), za uwierzytelniaczem ciasteczka (30) i przed `wp_authenticate_spam_check` (99). Pilnuje tego `test_an_unapproved_account_holding_a_valid_cookie_cannot_renew_its_session_with_an_empty_form`, który na priorytecie 30 **pada**.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_an_approved_account_logs_in(): void

public function test_an_account_with_no_status_logs_in(): void
// Konto dodane ręcznie w panelu; odmowa zamknęłaby właściciela portalu poza portalem.

public function test_each_state_before_approval_gets_its_own_error_code(): void
// email_unconfirmed -> CODE_UNCONFIRMED, pending -> CODE_PENDING, rejected -> CODE_REJECTED.

public function test_an_unrecognised_stored_status_is_refused_and_logged(): void
// Metadana po ręcznej edycji bazy: 'Approved', ' pending', 'cokolwiek'. isUnrecognised() daje prawdę,
// a przekazanie null do LoginGate::decide() WPUSZCZA — czyli fail-open. Odmowa plus wpis do logu.

public function test_the_gate_does_not_touch_a_core_error(): void
// Na priorytecie 40 $user bywa WP_Error ze złym hasłem; brama oddaje go bez zmian
// i NIE pisze do logu, bo to nie jej odmowa.

public function test_the_gate_is_unreachable_without_the_correct_password(): void
// Sedno projektu: wp_authenticate() ze ZŁYM hasłem dla konta email_unconfirmed oddaje
// kod rdzenia (incorrect_password), nigdy CODE_UNCONFIRMED. Gdyby brama siedziała na
// wp_authenticate_user, ten test padłby — i o to w nim chodzi.

public function test_every_code_has_a_message_and_no_message_is_orphaned(): void
// Mapa komunikatów pokrywa cztery kody i nic ponad nie.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj `gate()`**

Kolejność: `$user instanceof WP_User` (inaczej oddaj bez zmian) → `isUnrecognised()` → `LoginGate::decide( get() )`. Odmowa oddaje `WP_Error` z kodem i komunikatem z `message_for()`; przy odmowie leci `EventLog::write( self::LOG_ACTION, $user->ID, 0, array( 'code' => … ) )`.

Komunikaty (polskie, przez `__()`):
- `CODE_UNCONFIRMED` — „Adres e-mail tego konta nie został potwierdzony. Sprawdź skrzynkę; link potwierdzający przyszedł przy rejestracji."
- `CODE_PENDING` — „Zgłoszenie czeka na rozpatrzenie przez redakcję. Dostaniesz maila z decyzją."
- `CODE_REJECTED` — „Zgłoszenie zostało odrzucone. Powód przyszedł mailem."
- `CODE_UNRECOGNISED` — „Konto wymaga uwagi redakcji. Napisz do nas." Treść **nie** mówi o uszkodzonej metadanej: to informacja o wnętrzu instalacji, a dla człowieka bezużyteczna. Przyczynę widzi redakcja w logu zdarzeń.

- [ ] **Krok 4: Zarejestruj w `Plugin::boot()`**

Dołóż `add_action( 'pusers_register_features', array( LoginGateway::class, 'register' ) );`.

- [ ] **Krok 5: Uruchom bramki**

- [ ] **Krok 6: Commit**

---

## Task 4: Utrata stanu „zatwierdzony" unieważnia sesje

Brama działa na **uwierzytelnianiu**. Konto zalogowane przed odrzuceniem ma ciasteczko sesji i chodzi po portalu dalej, aż ciasteczko wygaśnie — do dwóch tygodni przy „zapamiętaj mnie". Dla portalu, którego cała treść jest za logowaniem, to znaczy, że odrzucenie nie odbiera dostępu.

**To moje rozstrzygnięcie, nie wymóg wprost ze specyfikacji.** Specyfikacja mówi, że zatwierdzone konto dostaje „możliwość zalogowania się", a treść jest dostępna wyłącznie po zalogowaniu — brama, która nie odbiera dostępu już przyznanego, realizuje to tylko połowicznie. Koszt pomyłki: jedna akcja i jeden nasłuch; gdyby okazało się niepotrzebne, usuwa się jednym commitem.

**Pliki:**
- Utwórz: `src/Front/SessionGuard.php`
- Modyfikuj: `src/Persistence/AccountStatusRepository.php`, `src/Plugin.php`
- Test: `tests/Integration/Front/SessionGuardTest.php`

**Interfejsy:**
- Oddaje: akcję `pusers_account_status_changed` z argumentami `( int $userId, ?AccountStatus $old, AccountStatus $new )`, odpalaną w `AccountStatusRepository::set()` **po** zapisie; oraz `SessionGuard::{LOG_ACTION='sessions_destroyed', register():void, on_status_changed( int $userId, ?AccountStatus $old, AccountStatus $new ):void}`.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_losing_the_approved_status_destroys_every_session_of_that_account(): void
// Konto zatwierdzone, dwie sesje, stan zmieniony na rejected -> zero sesji.

public function test_gaining_the_approved_status_destroys_nothing(): void
// Zatwierdzenie nie może wylogować kogoś, kto już wszedł (np. przy wyłączonej akceptacji
// stan idzie email_unconfirmed -> approved w jednym przebiegu).

public function test_only_the_subject_account_loses_its_sessions(): void
// Redaktor odrzucający wniosek NIE może wylecieć z panelu w połowie pracy.

public function test_setting_the_same_status_twice_destroys_nothing(): void
// Zapis idempotentny: ponowne `set()` tą samą wartością nie jest zmianą stanu.

public function test_the_action_carries_the_previous_status(): void
// Nasłuch musi umieć odróżnić „straciło zatwierdzenie" od „nigdy go nie miało".
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Odpal akcję w `set()`**

Przeczytaj stan **przed** zapisem, zapisz, a potem odpal akcję tylko wtedy, gdy wartość naprawdę się zmieniła. Repozytorium samo sesji nie rusza: warstwa przechowywania nie wylogowuje ludzi, ona ogłasza fakt.

- [ ] **Krok 4: Zaimplementuj nasłuch**

`SessionGuard::on_status_changed()` niszczy sesje przez `WP_Session_Tokens::get_instance( $userId )->destroy_all()` wyłącznie gdy `AccountStatus::Approved === $old && AccountStatus::Approved !== $new`, i pisze wpis do logu z aktorem `get_current_user_id()`.

- [ ] **Krok 5: Zarejestruj w `Plugin::boot()` i uruchom bramki**

- [ ] **Krok 6: Commit**

---

## Task 5: Strona logowania — shortcode i widok

**Pliki:**
- Utwórz: `src/Front/LoginForm.php`, `src/Front/PageUrl.php`
- Modyfikuj: `src/Plugin.php`, `src/Front/Confirmation.php`
- Test: `tests/Integration/Front/LoginFormTest.php`, `tests/Integration/Front/PageUrlTest.php`

**Interfejsy:**
- Oddaje:

```php
PortalUsers\Front\LoginForm::{
    SHORTCODE = 'portal_users_login',
    NONCE_ACTION = 'pusers_login',
    NONCE_FIELD = 'pusers_login_nonce',
    IDENTITY = 'pusers_login_identity',
    PASSWORD = 'pusers_login_password',
    REMEMBER = 'pusers_login_remember',
    REDIRECT = 'pusers_login_redirect',
    HONEYPOT = 'pusers_pole_zwrotne',
    RESULT_ARG = 'pusers_login_result',
    EXTRA_FIELDS_ACTION = 'pusers_login_form_fields',
    register(): void,
    render(): string,
    base_url(): string
}
```

- Oddaje też: `PortalUsers\Front\PageUrl::for( int $pageId ): string` — adres strony wskazanej w ustawieniach albo `home_url( '/' )`, gdy identyfikator jest zerowy lub strona nie istnieje.
- Konsumuje: `SettingsRepository::get()->loginPageId`, `RegistrationHandler::no_cache()`, `PageUrl::for()`.

**Reguła „skąd się bierze adres strony z ustawień" dostaje JEDNO miejsce.** Dziś żyje w `Confirmation::base_url()`; ten plan potrzebuje jej dla strony logowania (tu) i strony resetu (Task 8), czyli bez wyodrębnienia powstałyby **cztery** kopie. Rozjazd dwóch źródeł prawdy o adresie tępiłem na gałęzi Planu 3 od Taska 3, a `Confirmation::base_url()` zmieniło widoczność na publiczną właśnie po to, żeby `PasswordSetup` nie robiło kopii. Przerób `Confirmation::base_url()` na wywołanie `PageUrl::for( …->registerPageId )` — zachowanie ma zostać identyczne, czego dowodzą istniejące testy `ConfirmationTest` i `PasswordSetupTest`: **żaden z nich nie może zmienić wyniku**.

**Czego ten formularz NIE ma: pułapki czasowej.** Specyfikacja wymienia ją w sekcji „Odporność na boty" przy formularzu, który człowiek wypełnia ręcznie. Logowanie wypełnia menedżer haseł i wysyła w ułamku sekundy — pułapka trzech sekund odcięłaby każdego, kto używa menedżera, czyli dokładnie ostrożniejszą część lekarzy. Na tej gałęzi dziesięć razy już obrona trafiła w człowieka zamiast w bota i za każdym razem przyczyną było zacieśnianie. Pułapka na boty (`HONEYPOT`) zostaje, bo nic nie kosztuje; próg prób zostaje, bo działa na porażkach. **Nie dokładaj pułapki czasowej, nawet gdy wygląda na brakującą symetrię wobec rejestracji.**

Nazwa pułapki jest odmagnesowana, tak jak w `RegistrationForm`: żadnego `website`, `url`, `email`, `name`, `phone`, `address` — menedżery haseł wypełniają takie pola wbrew `autocomplete="off"`, a wypełniona pułapka to odmowa.

`EXTRA_FIELDS_ACTION` to punkt rozszerzenia z specyfikacji: `do_action()` wewnątrz formularza, pod który Turnstile albo hCaptcha dołoży swoje pole bez modyfikowania wtyczki. Weryfikacja idzie filtrem w Tasku 6.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_logged_in_visitor_sees_a_note_and_a_link_forward_not_the_form(): void
// Review Focus 4. Formularz logowania dla zalogowanego to albo zagadka, albo pętla.
// Notka mówi, kto jest zalogowany, i daje odnośnik dalej oraz wylogowania.

public function test_the_form_carries_a_nonce_a_honeypot_and_no_time_trap(): void
// Obecność pułapki czasowej jest tu BŁĘDEM, nie brakiem — test pilnuje jej nieobecności.

public function test_a_redirect_from_the_query_pointing_at_another_host_is_dropped(): void
// Review Focus 3. ?pusers_login_redirect=https://zly.example/ nie może wyjść w HTML-u
// jako wartość pola. Zostaje adres własny albo nic.

public function test_a_relative_redirect_from_the_query_survives(): void
// Druga połowa: obrona nie może zjeść poprawnego ?pusers_login_redirect=/artykul/.

public function test_the_page_sends_no_cache_headers(): void
// Strona z formularzem i komunikatem po PRG nie może wylądować w cache dla kolejnego gościa.

public function test_the_extension_point_fires_inside_the_form(): void

public function test_a_page_id_of_zero_or_a_deleted_page_falls_back_to_the_home_page(): void
// PageUrlTest. Strona usunięta z panelu nie może dać pustego adresu: przekierowanie
// na pusty łańcuch zostawia człowieka na stronie, z której przyszedł, bez komunikatu.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj widok**

Adres z żądania przez `wp_validate_redirect( $raw, '' )`; puste znaczy „bez przekierowania". Komunikat wyniku czytany z `RESULT_ARG` po PRG, bez nonce'a (odczyt nic nie zmienia). Zakaz cache przez istniejące `RegistrationHandler::no_cache()` — **wołane**, nie kopiowane; wzór podpięcia (hook przed renderem plus druga warstwa w shortcode) jest w `RegistrationForm`.

- [ ] **Krok 4: Zarejestruj i uruchom bramki**

- [ ] **Krok 5: Commit**

---

## Task 6: Obsługa wysłanego formularza logowania

**Pliki:**
- Utwórz: `src/Front/LoginHandler.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Front/LoginHandlerTest.php`

**Interfejsy:**
- Oddaje:

```php
PortalUsers\Front\LoginHandler::{
    ACTION = 'pusers_login',
    LOG_ACTION = 'login_failed',
    SPAM_FILTER = 'pusers_login_spam_check',
    RESULT_BAD = 'bad',
    RESULT_TOO_MANY = 'too_many',
    RESULT_STALE = 'stale',
    BUCKET_IP = 'login_ip:',
    BUCKET_USER = 'login_user:',
    register(): void,
    handle(): void,
    message_for( string $code ): string
}
```

- Konsumuje: `LoginForm` (nazwy pól), `RateLimiter::{hit,count}`, `IpAddress::from()`, `Settings::{loginLimitPerHour,loginLimitPerAccountPerHour,trustedProxyHeader}`, `LoginGateway::message_for()`, `EventLog::write()`.

**Licznik rośnie TYLKO na porażkach.** Review Focus 2: przychodnia za jednym adresem wyjściowym generuje dziesiątki udanych logowań dziennie; próg zużywany przez każde żądanie odciąłby ją przy normalnej pracy. Plan 3 przeszedł dokładnie tę pomyłkę przy rejestracji i próg trzeba było potem podnosić pięciokrotnie.

**Kubełek per konto powstaje wyłącznie dla konta odnalezionego.** Klucz z niesprawdzonego łańcucha z żądania pozwoliłby botowi wysypać do `wp_options` dowolną liczbę transientów. Gdy adresu nie da się przypisać do konta, liczy się tylko kubełek IP.

**Jeden komunikat ogólny dla złych danych.** Nieznany adres i złe hasło dostają **ten sam** tekst: „Nieprawidłowy adres e-mail lub hasło." Rdzeń na `wp-login.php` rozróżnia te dwa przypadki (`invalid_username` kontra `incorrect_password`), czyli sam enumeruje konta; na **naszej** stronie tego nie powtarzamy. Komunikaty stanu z `LoginGateway` przechodzą **własną** treścią, bo do nich dochodzi tylko ktoś, kto podał poprawne hasło.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_an_unknown_address_and_a_wrong_password_give_the_SAME_message(): void
// Gdyby się różniły, dwa żądania czytałyby „czy ten adres ma konto na portalu".

public function test_a_state_denial_passes_through_with_its_own_message(): void
// Poprawne hasło + konto pending -> komunikat o oczekiwaniu, nie komunikat ogólny.

public function test_a_successful_login_does_not_consume_the_threshold(): void
// Review Focus 2. Po progu udanych logowań licznik stoi na zerze.

public function test_a_failed_login_consumes_both_the_ip_and_the_account_bucket(): void

public function test_a_failed_login_for_an_unknown_address_creates_no_account_bucket(): void
// Zliczanie po surowym łańcuchu z żądania to zapychanie magazynu.

public function test_exhausting_the_account_threshold_does_not_block_another_account_from_the_same_address(): void

public function test_exhausting_the_ip_threshold_refuses_before_the_password_is_checked(): void
// Próg stoi PRZED wp_signon(), inaczej nie blokuje niczego.

public function test_a_filled_honeypot_is_refused_without_checking_the_password(): void

public function test_a_missing_or_stale_nonce_gives_the_stale_result(): void

public function test_a_get_request_is_refused(): void

public function test_the_redirect_after_a_successful_login_never_leaves_the_site(): void
// Review Focus 3, druga strona: pole formularza też jest danymi z żądania.

public function test_a_refusal_writes_one_log_entry_with_no_password_in_it(): void
// W logu nie wolno zapisać hasła ani jego skrótu; adres e-mail wyłącznie skrótem,
// tak jak w cronie czyszczącym.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj `handle()`**

Kolejność bramek: nonce → metoda (`POST`) → pułapka na boty i filtr `SPAM_FILTER` → próg per IP → odnalezienie konta (`get_user_by( 'email' )`, a gdy to nie adres, `get_user_by( 'login' )`) → próg per konto, jeśli konto odnalezione → `wp_signon()` → na porażce podbij oba kubełki i wpisz do logu → PRG na `LoginForm::base_url()` z `RESULT_ARG`, a na sukcesie `wp_safe_redirect()` na adres z `login_redirect`.

Hasło **nie podlega sanityzacji** (ucięłaby znaki) — tylko `wp_unslash()`, dokładnie jak w `PasswordSetup`; powód zapisz w komentarzu, inaczej ktoś „naprawi" to `sanitize_text_field()`.

`LoginHandler::message_for()` obsługuje **wyłącznie** własne kody (`RESULT_BAD`, `RESULT_TOO_MANY`, `RESULT_STALE`) i dla wszystkiego innego pyta `LoginGateway::message_for()`. Druga mapa komunikatów stanu rozjechałaby się z pierwszą przy pierwszej zmianie tekstu.

- [ ] **Krok 4: Zarejestruj pod `admin_post_nopriv_` i `admin_post_`**

Oba warianty, jak w Planie 3: zalogowany też może wysłać ten formularz (otwarta karta, powrót z historii).

- [ ] **Krok 5: Uruchom bramki**

- [ ] **Krok 6: Commit**

---

## Task 7: Przekierowania, odcięcie panelu i pasek administracyjny

Specyfikacja, sekcja „Strony na froncie": dla kont bez uprawnień redakcyjnych po zalogowaniu przekierowanie na wskazaną stronę, wejście w `wp-admin` bez uprawnień do edycji treści przekierowuje na front, pasek administracyjny ukryty. `wp-login.php` i `wp-admin` **zostają** dla redakcji.

**Przed tym zadaniem rozstrzygnij z właścicielem punkt o `profile.php`** (patrz „Punkt do decyzji właściciela"). Jeśli odpowiedzi nie ma, realizuj literę specyfikacji i zapisz to w raporcie.

**Pliki:**
- Utwórz: `src/Front/Redirects.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Front/RedirectsTest.php`

**Interfejsy:**
- Oddaje:

```php
PortalUsers\Front\Redirects::{
    CAPABILITY = 'edit_posts',
    register(): void,
    after_login( string $redirectTo, string $requested, mixed $user ): string,   // filtr login_redirect
    block_admin(): void,                                                        // akcja admin_init
    hide_admin_bar( bool $show ): bool,                                         // filtr show_admin_bar
    login_url( string $url, string $redirect, bool $forceReauth ): string,
    lostpassword_url( string $url, string $redirect ): string,
    register_url( string $url ): string,
    after_login_url(): string
}
```

- Konsumuje: `Settings::{afterLoginPageId,loginPageId,registerPageId,resetPageId}`, `PageUrl::for()` (Task 5) — `after_login_url()` nie powtarza reguły odwrotu na stronę główną.

**`CAPABILITY` to `edit_posts`, nie rola.** Reguła po roli pękła już w Planie 3 (wymóg „podzbiór subscribera" zamykał rejestrację portalom z rolą `contributor`). Uprawnienie do edycji treści jest tym, co specyfikacja naprawdę nazywa — „bez uprawnień do edycji treści".

**Odcięcie panelu musi przepuścić `admin-post.php` i `admin-ajax.php`.** Review Focus 1, i to jest w tym zadaniu rzecz, która najpewniej zepsuje Plan 3: `wp-admin/admin-post.php` woła `do_action( 'admin_init' )` **przed** rozdzieleniem akcji (sprawdzone w źródle; komentarz rdzenia w `wp-admin/admin.php:174` mówi to wprost). Przekierowanie bez wyjątku zabiłoby ustawianie hasła, rejestrację i logowanie dla każdego zalogowanego nie-redaktora. Warunki wyjścia z `block_admin()`:

- `wp_doing_ajax()` — żądania `admin-ajax.php`,
- `'admin-post.php' === $GLOBALS['pagenow']` — żądania własnych handlerów (`$pagenow` bierze się z `$_SERVER['PHP_SELF']`, nie z danych klienta),
- `wp_doing_cron()`,
- `current_user_can( self::CAPABILITY )` — redakcja.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_plugin_handler_under_admin_post_still_runs_for_a_logged_in_non_editor(): void
// Review Focus 1. Konto subscribera, żądanie POST na admin-post.php z akcją wtyczki:
// handler MUSI się wykonać. Ten test pada, gdy block_admin() nie ma wyjątku —
// i jest jedynym, który łapie zabicie funkcji Planu 3.

public function test_an_ajax_request_from_a_non_editor_is_not_redirected(): void

public function test_an_editor_reaches_wp_admin_and_keeps_the_admin_bar(): void
// Review Focus 5. Specyfikacja trzyma wp-admin jako wejście zapasowe redakcji.

public function test_a_non_editor_opening_an_admin_screen_lands_on_the_after_login_page(): void

public function test_the_after_login_page_falls_back_to_the_home_page_when_unset(): void
// after_login_page_id = 0 i strona usunięta z panelu: przekierowanie nie może
// wyjść puste ani wskazać strony logowania (pętla).

public function test_an_editor_logging_in_keeps_the_core_destination(): void
// Redaktor po zalogowaniu idzie do kokpitu, jak dotąd.

public function test_a_non_editor_logging_in_goes_to_the_indicated_page_not_the_dashboard(): void

public function test_a_requested_destination_pointing_outside_the_site_is_dropped(): void

public function test_the_core_login_and_lostpassword_urls_point_at_the_plugin_pages(): void
// Odnośniki rdzenia (np. z maila odzyskiwania) prowadzą na strony wtyczki,
// a gdy strona nie jest wskazana w ustawieniach, zostaje adres rdzenia — nie pusty.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj**

Pasek: `show_admin_bar` na `false` wyłącznie dla kont bez `CAPABILITY`. Adresy: każdy z filtrów oddaje adres strony z ustawień, a przy braku wskazania — wartość wejściową, nigdy pusty łańcuch.

- [ ] **Krok 4: Zarejestruj i uruchom bramki**

- [ ] **Krok 5: Commit**

---

## Task 8: Odzyskiwanie hasła — żądanie linku

Specyfikacja: „Odzyskiwanie hasła działa w pełnym, standardowym cyklu: formularz z adresem, mail z linkiem, formularz ustawienia nowego hasła. Treść maila podmieniana na własny szablon."

**Mechanizm jest rdzeniowy.** `retrieve_password()` generuje klucz, zapisuje go w `user_activation_key` i wysyła maila; filtry `retrieve_password_title` i `retrieve_password_message` podmieniają treść, a `lostpassword_url` (Task 7) kieruje odnośniki na naszą stronę. Drugiego magazynu tokenów nie budujemy — to ta sama zasada, którą Plan 3 zapisał przy zgubionym bilecie („drugiego mechanizmu nie budujemy").

**Pliki:**
- Utwórz: `src/Front/PasswordReset.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Front/PasswordResetTest.php`

**Interfejsy:**
- Oddaje:

```php
PortalUsers\Front\PasswordReset::{
    SHORTCODE = 'portal_users_password_reset',
    ACTION_REQUEST = 'pusers_reset_request',
    NONCE_REQUEST = 'pusers_reset_request',
    EMAIL_FIELD = 'pusers_reset_email',
    HONEYPOT = 'pusers_pole_odwrotne',
    RESULT_ARG = 'pusers_reset_result',
    KEY_ARG = 'pusers_reset_key',
    USER_ARG = 'pusers_reset_user',
    RESULT_SENT = 'sent',
    RESULT_TOO_MANY = 'too_many',
    RESULT_STALE = 'stale',
    LOG_ACTION_REQUEST = 'password_reset_requested',
    BUCKET_IP = 'reset_ip:',
    BUCKET_EMAIL = 'reset_email:',
    register(): void,
    render(): string,
    handle_request(): void,
    base_url(): string,
    message_for( string $code ): string
}
```

- Konsumuje: `Settings::{resetPageId, resetLimitPerHour, resetLimitPerEmailPerHour}`, `RateLimiter`, `IpAddress::from()`, `PageUrl::for()`, `Mailer`, `EventLog::write()`.

**Odpowiedź jest ta sama niezależnie od istnienia adresu.** `RESULT_SENT` wychodzi zawsze: „Jeśli konto o tym adresie istnieje, wysłaliśmy na nie link." Rdzeń sam zwraca `invalid_email`, gdy adresu nie zna — tego błędu **nie** pokazujemy, bo to ta sama enumeracja, którą Plan 3 zamknął w rejestracji, a Task 6 na stronie logowania.

**Kubełek per adres kluczowany skrótem adresu**, nie adresem jawnym: klucz transientu trafia do `wp_options`, a adres e-mail lekarza jest daną osobową. Wzór jest w Planie 3 (`wp_hash( strtolower( $email ) )`).

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_known_and_an_unknown_address_get_the_SAME_answer(): void

public function test_a_known_address_receives_a_mail_whose_link_points_at_the_plugin_page(): void
// Odnośnik musi nieść KEY_ARG i USER_ARG i prowadzić na stronę z ustawień,
// nie na wp-login.php?action=rp.

public function test_an_unknown_address_sends_no_mail(): void
// Ta sama ODPOWIEDŹ nie znaczy ta sama praca: maila do nikogo nie wysyłamy.

public function test_exhausting_the_email_threshold_does_not_block_another_address_from_the_same_ip(): void

public function test_exhausting_the_ip_threshold_refuses_before_any_mail_is_sent(): void

public function test_the_email_bucket_key_does_not_contain_the_address_in_the_clear(): void

public function test_a_filled_honeypot_is_refused_and_sends_no_mail(): void

public function test_a_missing_nonce_gives_the_stale_result(): void

public function test_the_log_entry_carries_no_address_in_the_clear(): void
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj formularz i handler**

Kolejność bramek jak w Tasku 6. Po progach wołaj `retrieve_password( $email )`; jego `WP_Error` **pochłoń** i oddaj `RESULT_SENT`. Treść maila przez filtry rdzenia, minimalna i funkcjonalna (Plan 5 podmieni).

Shortcode oddaje jeden z dwóch widoków: formularz adresu albo — gdy adres niesie `KEY_ARG` i `USER_ARG` — formularz nowego hasła z Taska 9. Wzór rozdzielenia widoków po argumencie adresu jest w `PasswordSetup`; zrób to **tak samo** i pamiętaj o lekcji z Planu 3: argument w adresie jest **niesekretny**, więc widok hasła nie może zabrać strony człowiekowi, który trafił tu z pustym albo martwym kluczem — taki człowiek dostaje formularz adresu i komunikat, nigdy ślepy zaułek.

- [ ] **Krok 4: Zarejestruj i uruchom bramki**

- [ ] **Krok 5: Commit**

---

## Task 9: Odzyskiwanie hasła — ustawienie nowego

**Pliki:**
- Modyfikuj: `src/Front/PasswordReset.php`, `src/Plugin.php`
- Test: `tests/Integration/Front/PasswordResetTest.php`

**Interfejsy:**
- Oddaje: `PasswordReset::{ACTION_SET='pusers_reset_set', NONCE_SET='pusers_reset_set', PASSWORD_FIELD='pusers_reset_password', RESULT_DONE='done', RESULT_INVALID='invalid', RESULT_SHORT, RESULT_EMAIL, LOG_ACTION_SET='password_reset', BUCKET_SET_IP='resetset_ip:', BUCKET_SET_USER='resetset_user:', handle_set():void}`.
- Konsumuje: `PasswordPolicy::check()` (Task 1), `check_password_reset_key()`, `reset_password()`.

**Progi ustawiania hasła czytają WARTOŚCI progów odzyskiwania**: `BUCKET_SET_IP` bierze `resetLimitPerHour`, `BUCKET_SET_USER` bierze `resetLimitPerEmailPerHour`. **Kubełki** są osobne od kubełków z Taska 8 (wspólny pozwalałby jednym przebiegiem wyczerpać budżet drugiego i mieszałby dwa niezależne progi), ale **wartości** nie dublujemy nowymi kluczami ustawień — to samo rozstrzygnięcie, które Plan 3 podjął dla ustawiania hasła z biletu. Osobne wartości dla każdego kroku stoją w backlogu jako granulacja progów i tu ich nie ruszamy.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_valid_key_sets_the_new_password_and_the_old_one_stops_working(): void

public function test_the_key_works_only_once(): void
// reset_password() czyści user_activation_key; drugie wysłanie tego samego odnośnika odmawia.

public function test_a_key_belonging_to_another_account_is_refused(): void

public function test_an_expired_key_is_refused(): void

public function test_the_password_rules_are_the_SAME_as_for_the_ticket_from_registration(): void
// Oba miejsca wołają PasswordPolicy; test porównuje wynik dla hasła krótkiego
// i dla hasła równego adresowi. Druga kopia reguł pada tu natychmiast.

public function test_the_threshold_is_counted_per_ip_and_per_account(): void

public function test_setting_a_password_destroys_other_sessions_of_that_account(): void
// Odzyskiwanie hasła jest tym, czego używa człowiek, któremu ktoś przejął konto.
// Nowe hasło, które nie wyrzuca cudzej sesji, nie odbiera dostępu.

public function test_a_refusal_from_the_password_rules_keeps_the_key_usable(): void
// Zbyt krótkie hasło nie może spalić odnośnika: to byłaby odmowa trafiająca w człowieka,
// który nic złego nie zrobił. Klucz zużywa się WYŁĄCZNIE przy udanym ustawieniu.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj `handle_set()`**

Kolejność: nonce → metoda → progi → `check_password_reset_key()` → `PasswordPolicy::check()` → `reset_password()` → zniszczenie pozostałych sesji → wpis do logu → PRG z `RESULT_DONE`.

Reguły hasła sprawdzaj **po** sprawdzeniu klucza, ale ich odmowa **nie** zużywa klucza. Hasło bez sanityzacji, tylko `wp_unslash()`.

- [ ] **Krok 4: Uruchom bramki**

- [ ] **Krok 5: Commit**

---

## Task 10: `wp/v2/users` zamknięty dla niezalogowanych

Rdzeń odpowiada na `/wp-json/wp/v2/users/<id>` kodem `rest_user_invalid_id` (404) dla identyfikatora nieistniejącego i `rest_user_cannot_view` (403) dla istniejącego, więc „czy pojawił się nowy wiersz użytkownika" czyta się **bez uwierzytelnienia**. Backlog: zamknąć razem z bramą, bo osobno jedno bez drugiego niczego nie daje.

**Pliki:**
- Utwórz: `src/Front/RestUsers.php`
- Modyfikuj: `src/Plugin.php`
- Test: `tests/Integration/Front/RestUsersTest.php`

**Interfejsy:**
- Oddaje: `RestUsers::{FILTER_PUBLIC='pusers_rest_users_public', register():void, restrict( array $endpoints ): array}` na filtrze `rest_endpoints`.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_an_anonymous_request_gets_the_same_answer_for_an_existing_and_a_missing_account(): void
// Sedno: dwa żądania, dwa identyfikatory (jeden istnieje, drugi nie), ten sam kod odpowiedzi.

public function test_a_logged_in_editor_still_reaches_the_users_route(): void
// Panel i blokowy edytor czytają ten punkt; odcięcie zalogowanych zepsułoby panel.

public function test_the_me_route_is_untouched(): void
// /wp/v2/users/me dotyczy wyłącznie zalogowanego i nic nie ujawnia.

public function test_a_portal_can_reopen_the_route_with_the_filter(): void
// Portal, którego motyw czyta autorów anonimowo, musi mieć wyłącznik bez forka wtyczki.
```

- [ ] **Krok 2: Uruchom, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj `restrict()`**

Filtr `rest_endpoints` odpala się przy składaniu trasy, **po** rozpoznaniu użytkownika, więc `is_user_logged_in()` jest tam wiarygodne — i właśnie to pilnuje drugi test. Zalogowany i portal z włączonym `FILTER_PUBLIC` dostają tablicę bez zmian.

- [ ] **Krok 4: Zarejestruj i uruchom bramki**

- [ ] **Krok 5: Commit**

---

## Bramki

Po każdym zadaniu, **każda osobno, po ostatniej zmianie plików**:

```bash
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/phpcs
```

Suita integracyjna ma **osobny** config i chodzi w kontenerze wp-env (bootstrap hosta nie ma `/wordpress-phpunit`):

```bash
docker exec -w /var/www/html/wp-content/plugins/portal-users <kontener-tests-cli> vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Nazwę kontenera daje `docker ps` (sufiks `-tests-cli-1`). **`--testsuite integration` nie istnieje** — wypisuje „No tests executed!", co wygląda na sukces.

Na koniec `git status --porcelain` musi być pusty.

**Stan wejściowy (`main`, commit `dccc737`):** unit **214**, integration **562**, PHPStan `[OK] No errors`, PHPCS zero zgłoszeń, `DomainPurityTest` **35**.

## Standard dowodowy

Każda zmiana zachowania ma dowód mutacyjny: podmiana potwierdzona **`diff`em wobec kopii** (nie `grep`em na wzorzec — dwa razy na gałęzi Planu 3 podmiana w ogóle nie weszła, a `grep` pokazywał wzorzec w oryginalnych liniach), przywrócenie `cmp`em.

Pytanie do każdego testu, który piszesz: **padłby, gdyby usunąć kod, który sprawdza?** W Planie 3 trzynaście testów przechodziło bez mechanizmu, który miały dowodzić. Dwa najgorsze dotyczyły rdzenia naprawy, a jeden przypinał **wartość będącą błędem** jako wymaganie. Test sprawdzający relację („ścieżka ciasteczka pokrywa adres, pod który wysyła się formularz") jest odporny na to, czym harness adresuje strony; test sprawdzający wartość nie jest.

Trzy testy tego planu mają wymóg mocniejszy — **pokaż, że padają na kodzie sprzed zmiany**, bo przy złym projekcie przechodzą:

- `test_the_gate_is_unreachable_without_the_correct_password` (Task 3) — na priorytecie 20 albo na filtrze `wp_authenticate_user` musi paść.
- `test_a_plugin_handler_under_admin_post_still_runs_for_a_logged_in_non_editor` (Task 7) — bez wyjątku w `block_admin()` musi paść.
- `test_a_successful_login_does_not_consume_the_threshold` (Task 6) — przy liczniku podbijanym przed `wp_signon()` musi paść.

## Samoprzegląd

**1. Pokrycie specyfikacji.** „Strony na froncie", akapit o logowaniu → Taski 5, 6; akapit o odzyskiwaniu hasła → Taski 8, 9; trzy punkty o kontach bez uprawnień redakcyjnych → Task 7. „Przebieg rejestracji", wiersz 115 („logowanie wpuszcza wyłącznie `zatwierdzony`") i wiersz 117 („konta bez stanu są wpuszczane") → Task 3. „Odporność na boty", progi dla logowania i odzyskiwania → Task 2, liczenie → Taski 6, 8, 9; punkt rozszerzenia dla CAPTCHY → Task 5 (pole) i Task 6 (weryfikacja). Backlog „Plan 4": brama → Task 3, komunikat nieujawniający → Task 3 konstrukcją i Task 6 treścią, `wp/v2/users` → Task 10, nierozpoznany stan → Task 3. Backlog „granulacja limitu prób" → Task 2.

**Luki świadome:** szablony maili (Plan 5), ekran wniosków i przycisk zdejmowania blokady (Plan 6), backfill stanów (Plan 7), edycja profilu na froncie (punkt do decyzji właściciela). Dwuskładnikowe uwierzytelnianie: specyfikacja żąda punktu rozszerzenia, nie implementacji — punkt jest w Tasku 5.

**2. Spójność typów.** `PasswordPolicy::check()` zwraca `string` (kod albo `''`) w obu konsumentach — Task 1 i Task 9. Kody odmowy `LoginGateway` są łańcuchami i przechodzą przez `WP_Error`, więc `LoginHandler::message_for()` pyta `LoginGateway::message_for()`, a nie trzyma drugiej mapy. Akcja `pusers_account_status_changed` niesie `?AccountStatus` jako stan poprzedni w obu miejscach (Task 4). `PageUrl::for( int ): string` ma jedną sygnaturę dla czterech wywołań (`Confirmation`, `LoginForm`, `PasswordReset`, `Redirects`), a `Settings::$resetLimitPerEmailPerHour` nazywa się tak samo w Tasku 2, 8 i 9.

**Poprawki wniesione samoprzeglądem:** nazwa właściwości progu w Tasku 8 była skrócona i niezgodna z Taskiem 2; Task 9 wprowadzał dwa kubełki, nie mówiąc, z której wartości progu czytają; strona logowania i strona resetu rodziły trzecią i czwartą kopię reguły adresu strony, więc doszedł `PageUrl` i przeróbka `Confirmation::base_url()`; `LoginHandler::message_for()` nie miał zapisane, że deleguje stany do `LoginGateway`.

**3. Proporcja.** Dziesięć zadań, z czego trzy (1, 2, 10) są małe. Taski 8 i 9 dzielą jeden plik i jedną suitę testów, bo dzielą widok strony; rozdzielenie ich na dwa pliki dałoby dwa shortcode'y i drugie źródło prawdy o adresie strony resetu. Gdyby plan miał być krótszy, naturalnym cięciem jest wyjęcie Tasków 8–9 do Planu 4b — Taski 1–7 i 10 domykają logowanie same, a odzyskiwanie hasła działa do tego czasu przez `wp-login.php?action=lostpassword`, które zostaje czynne.
