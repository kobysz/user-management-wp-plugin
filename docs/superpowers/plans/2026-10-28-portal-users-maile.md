# Szablony i kolejka maili — plan wdrożenia

> **Dla wykonawców agentowych:** WYMAGANA PODUMIEJĘTNOŚĆ: użyj superpowers:subagent-driven-development (zalecane) albo superpowers:executing-plans, by zrealizować ten plan zadanie po zadaniu. Kroki mają składnię pól wyboru (`- [ ]`) do odhaczania.

**Cel:** Redakcja edytuje temat i treść każdego maila wtyczki w panelu, a na pytanie „czy ten mail wyszedł" odpowiada ekran logu wysyłek — bo każdy mail idzie przez kolejkę, która zapisuje wynik, ponawia nieudane próby i nie wysyła nic dwa razy.

**Architektura:** Treść maila powstaje **w chwili zakolejkowania** (snapshot szablonu z podstawionymi zmiennymi) i ląduje w tabeli `pusers_mail_queue`. Dispatcher na cronie minutowym odzyskuje porzucone wiersze, zajmuje wiersz warunkowym `UPDATE`-em, woła `wp_mail()` i zapisuje wynik; nieudana wysyłka wraca po 60 i 300 sekundach, po trzeciej próbie zostaje `failed` z powodem. Idempotencję daje `UNIQUE` na kluczu odporności zbudowanym z rodzaju maila, konta i tego, co dla danego rodzaju czyni wysyłkę nową. Mail odzyskiwania hasła zostaje na **rdzeniowym** `retrieve_password()`; do kolejki wchodzi przez `pre_wp_mail` uzbrajane tylko na czas tego jednego wywołania. Podstawianie zmiennych, rodzaje maili, polityka ponawiania i opakowanie HTML są czystą domeną.

**Stos:** WordPress 6.5+, PHP 8.1+, ACF Pro (zależność twarda, sprawdzana w `Dependencies`), PHPUnit w kontenerze wp-env, PHPStan level max, PHPCS `WordPress-Core` + `WordPress-Extra` + `WordPress-Docs`.

**Specyfikacja:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`

**Wzorce do przeniesienia:** `C:\Users\kuba\SynologyDrive\WebDevAI\event-registration` — sekcja specyfikacji „Wzorce przeniesione z `event-registration`" nazywa kolejkę mailową wprost: „render treści przy zapisie, idempotencja przez unikalny indeks, dispatcher z odzyskiwaniem zawieszonych wysyłek i retry, ekran kolejki z akcją »Wznów«. Daje wymagany log wysyłek." Pliki źródłowe wzorca: `src/Mail/MailQueue.php`, `src/Mail/Dispatcher.php`, `src/Persistence/MailQueueRepository.php`, `src/Domain/Mail/{TemplateRenderer,Placeholders,RetryPolicy}.php`. **Powtarzamy te wzorce, nie wymyślamy ich od nowa.**

**Stan wejściowy:** `main`, commit `41ab108`. Unit **222**, integration **686**, `DomainPurityTest` **35**, PHPStan `[OK] No errors`, PHPCS zero zgłoszeń.

## Ograniczenia globalne

- Warstwa `src/Domain/**` nie wykonuje **żadnych** wywołań WordPressa; pilnuje tego `DomainPurityTest` białą listą na `token_get_all()`. Strażnik sprawdza **także komentarze** wzorcem `/(?<![A-Za-z0-9_])(?:wordpress|wpdb|wp_[a-z_]+)/i` — w pliku domeny nie wolno napisać w komentarzu `wp_mail` ani „WordPress".
- Wszystkie teksty widoczne dla użytkownika przez `__()` / `esc_html__()` z domeną `portal-users`. **Zakaz `_n()`** — język źródłowy jest polski, katalogu `.po` nie ma, więc gettext zastosowałby angielską regułę dwóch form. Liczbę stawiaj na końcu zdania po dwukropku.
- Nazwy kluczy opcji, tabel, hooków crona i rodzajów maili **tylko** ze stałych; zakaz literałów w drugim miejscu.
- Nowe metody w `src/Persistence/**` i `src/Mail/**` nazywamy `snake_case` (jak `Migrations::maybe_upgrade()`, `EventLog::write()`, `Mailer::may_send_to()`). Czysta domena zostaje przy `camelCase` istniejących klas (`Settings::fromArray()`).
- Opcje zapisywane z `autoload = false` (`OptionStore::write()` już to robi).
- Każde SQL kolejki stoi **wyłącznie** w `MailQueueRepository`. Żadna inna klasa nie dotyka `$wpdb` w sprawie maili.
- Wszystkie znaczniki czasu w tabeli kolejki są w UTC (`gmdate( 'Y-m-d H:i:s' )` albo `current_time( 'mysql', true )`), jak `created_at` w `event_log`.
- Niczego nie kasujemy nieodwracalnie poza cronem retencji, który kasuje **wyłącznie** wiersze `sent` i `failed` starsze niż okno retencji.
- Zapis w panelu: `Request::guard()` (uprawnienia → nonce) → akcja → `Request::redirect()` (PRG).

## Co ten plan konsumuje z Planów 1–4

Sygnatury sprawdzone w kodzie na `main`, commit `41ab108`:

```php
PortalUsers\Domain\Account\AccountStatus — enum string: email_unconfirmed|pending|approved|rejected
PortalUsers\Domain\Config\Settings — readonly: approvalEnabled, loginPageId, registerPageId, resetPageId,
    afterLoginPageId, notifyEmails, rejectionReasons, acfGroupKey, …; fromArray(array):self, toArray():array
PortalUsers\Domain\Credentials\{CredentialType, ValidatorRegistry} // ValidatorRegistry->for(CredentialType):?CredentialValidator
PortalUsers\Domain\Profession\Profession — readonly: key, label, credentialType, credentialField, registryUrl, notifyEmail
PortalUsers\Persistence\SettingsRepository::get(): Settings
PortalUsers\Persistence\ProfessionRepository::get(): ProfessionCollection    // ->get(string):?Profession
PortalUsers\Persistence\FieldMatrixRepository::get()                          // ->visibleFields(string $professionKey): array<int,string>
PortalUsers\Persistence\AccountStatusRepository::get( int ): ?AccountStatus
PortalUsers\Persistence\EventLog::write( string $action, int $subjectUserId, int $actorUserId, array $context ): void
PortalUsers\Persistence\Migrations::{DB_VERSION=2, TABLES, OPTION_DB_VERSION, table(string):string, run():void, maybe_upgrade():void}
PortalUsers\Persistence\OptionStore::{OPTION_SETTINGS, …, read(string):array, write(string,array):bool, delete(string):bool, all():array}
PortalUsers\Persistence\UserMeta::{STATUS, PROFESSION, ACF_PREFIX, …}
PortalUsers\Front\Mailer::{FILTER_BODY='pusers_mail_body', FILTER_SUBJECT='pusers_mail_subject',
    KIND_CONFIRMATION='confirmation', KIND_EDITORIAL='editorial', KIND_EXISTING='existing_account',
    NO_RECIPIENTS_LOG_ACTION, INVALID_RECIPIENT_LOG_ACTION, PURPOSE_RESEND, PURPOSE_ATTEMPT,
    confirmation(int,string):bool, editorial(int):bool, existing_account(int):bool, may_send_to(string,string):bool}
PortalUsers\Front\PageUrl::{for(int):string, existing(int):string}
PortalUsers\Front\RegistrationHandler::ACF_META_PREFIX
PortalUsers\Front\Confirmation::{confirm(...), base_url():string}
PortalUsers\Front\PasswordReset::{register():void, mail_title(mixed):mixed, mail_message(mixed,string,string):mixed}
PortalUsers\Cron\PurgeUnconfirmed::{HOOK, register():void, schedule():void, unschedule():void}   // wzorzec crona
PortalUsers\Admin\Menu::{CAPABILITY='manage_options', PARENT='users.php', SLUG_SETTINGS, SLUG_PROFESSIONS, SLUG_MATRIX, url(string,array):string}
PortalUsers\Admin\Request::{NONCE_FIELD, NOTICE_ARG, NOTICE_SAVED, guard(string):void, redirect(string,string,array):void, notice():string}
PortalUsers\Plugin::{boot(string):void, load_features():void}   // hook `pusers_register_features`
```

**Zweryfikowane w źródłach rdzenia** (kontener wp-env, WordPress 6.9):

- `wp-includes/pluggable.php:233` — `$pre_wp_mail = apply_filters( 'pre_wp_mail', null, $atts ); if ( null !== $pre_wp_mail ) { return $pre_wp_mail; }`. Filtr stoi **przed** jakimkolwiek przetwarzaniem odbiorców, a zwrot różny od `null` zastępuje całą wysyłkę i staje się wynikiem `wp_mail()`. To jedyny szew, którym da się przenieść rdzeniowy mail do kolejki, nie dotykając rdzenia.
- `wp-includes/user.php:3471` — `retrieve_password()` woła `apply_filters( 'retrieve_password_notification_email', … )` i dopiero potem **jedno** `wp_mail()`. Nie ma w nim przełącznika „nie wysyłaj": dlatego przechwytujemy na `pre_wp_mail`, a nie tam.
- `wp-includes/user.php:3439` — `if ( ! $message ) { return true; }` **przed** złożeniem wiadomości. Filtr `retrieve_password_message`, który oddałby pusty łańcuch, nie zatrzymuje wysyłki — rdzeń wyśle mail z pustą treścią. Szablon z pustą treścią nie może więc dojechać do rdzenia (patrz Task 7).
- Rdzeń nie rejestruje harmonogramu krótszego niż `hourly`. Przebieg minutowy wymaga własnego wpisu przez filtr `cron_schedules`.

## Co ten plan świadomie zostawia

- **Ekran wniosków, zatwierdzanie i odrzucanie z panelu, przycisk zdejmowania blokady** — Plan 6. Ten plan dowozi **maile** `approved` i `rejected` wraz z szablonami i metodami `Mailer::approved()` / `Mailer::rejected()`; Plan 6 je tylko woła ze swoich akcji. Powód, dla którego nie czekają na Plan 6: cała maszyneria (szablon, zmienne, klucz odporności, log) jest dla nich identyczna jak dla pozostałych maili, a Plan 6 musiałby ją odtworzyć dla powodu odrzucenia.
- **Eksport i anonimizacja logu wysyłek** — Plan 7. Tabela kolejki **od początku** ma kolumny `user_id` i `anonymized`, żeby Plan 7 miał po czym szukać wierszy konta i co w nich oznaczać; ten plan ich nie używa poza zapisem `user_id`.
- **Nadpisywanie szablonów plikiem w motywie.** Specyfikacja zostawia mechanizm szablonów wykonawcy; dowozimy edycję w panelu plus filtry `FILTER_SUBJECT` / `FILTER_BODY`, które Plan 3 już wystawił. Szablonów plikowych nie ma — YAGNI, a dwa źródła prawdy o treści maila to pierwszy rozjazd przy pierwszej zmianie.
- **Mail o zmianie hasła.** Specyfikacja mówi wprost: „Mail o zmianie hasła zostaje standardowy, WordPressowy." Nie przechwytujemy go i nie logujemy.
- **Układy HTML, grafika, wersja tekstowa jako `multipart/alternative`.** Specyfikacja żąda „tekst zwykły w prostym opakowaniu HTML"; dowozimy dokładnie to.

## Review Focus

Pięć klas wejścia, które specyfikacja zakłada, a które uderzą w człowieka, jeśli nikt ich nie przypnie testem. Test każdej jest przypisany do zadania, które posiada kod:

1. **Literówka w szablonie: `{nieznany_klucz}`.** Admin edytuje treść w panelu i myli nazwę zmiennej. Nieznany placeholder zostaje w treści **dosłownie** — pusty łańcuch zamieniłby literówkę w cichą dziurę w mailu, której nikt nie zauważy. → Task 1, Task 9.
2. **Admin czyści temat albo treść jednego rodzaju.** Odwrót jest **per pole** na wartość domyślną, nie na pusty mail: wyczyszczony temat nie może wysłać wiadomości bez tematu. → Task 2.
3. **`wp_mail()` pada, bo SMTP nie odpowiada.** Po trzech próbach wiersz zostaje `failed` z czytelnym powodem, ekran logu go pokazuje, a „Wznów" wraca go do kolejki. Bez tego odpowiedź na „czy mail wyszedł" brzmi „nie wiem". → Task 6, Task 10.
4. **Dwa przebiegi dispatchera jednocześnie** (cron minutowy i zdarzenie natychmiastowe po zakolejkowaniu). Lekarz nie może dostać dwóch linków potwierdzających; `claim()` warunkowym `UPDATE`-em przepuszcza jeden przebieg. → Task 3, Task 6.
5. **Obca wtyczka wysyła swojego maila w chwili, gdy przechwytywanie rdzeniowego maila jest uzbrojone.** Przechwycenie zabrałoby jej wysyłkę i zapisało cudzą treść w naszym logu. Przechwytujemy wyłącznie wiadomość na adres uzbrojonego konta. → Task 7.

## Struktura plików

| Plik | Odpowiedzialność |
|---|---|
| `src/Domain/Mail/MailKind.php` | Osiem rodzajów maili jako enum string. Jedno źródło nazw rodzajów i listy zmiennych dostępnych w każdym z nich. |
| `src/Domain/Mail/MailTemplate.php` | Para temat + treść jako obiekt wartości, z pytaniem o pustkę każdego pola osobno. |
| `src/Domain/Mail/Placeholders.php` | Niemutowalna mapa zmienna → wartość. Przeniesione z wzorca. |
| `src/Domain/Mail/TemplateRenderer.php` | Podstawianie `{klucz}` jednym przebiegiem; nieznany klucz zostaje dosłownie. Przeniesione z wzorca. |
| `src/Domain/Mail/HtmlBody.php` | Tekst zwykły → proste opakowanie HTML: eskejp, łamanie linii, nic więcej. |
| `src/Domain/Mail/RetryPolicy.php` | Trzy próby, opóźnienia 60 s i 300 s. Przeniesione z wzorca. |
| `src/Domain/Mail/QueueStatus.php` | Cztery stany wiersza kolejki jako enum string. |
| `src/Mail/DefaultTemplates.php` | Domyślny temat i treść każdego rodzaju, z placeholderami. Przenosi treści, które dziś stoją wewnątrz `Mailer`. |
| `src/Persistence/MailTemplateRepository.php` | Opcja `pusers_mail_templates`: nadpisania admina z odwrotem **per pole** na domyślne. |
| `src/Mail/PlaceholderFactory.php` | Zmienne konta: dane, profesja, numer uprawnień z wynikiem walidacji, nazwa i adres portalu, odnośniki, każde pole zgłoszenia. |
| `src/Mail/MailQueue.php` | Render szablonu i wstawienie wiersza. Właściciel filtrów `FILTER_SUBJECT` / `FILTER_BODY` i klucza odporności. |
| `src/Mail/Dispatcher.php` | Odzysk porzuconych, zajęcie wiersza, `wp_mail()` w opakowaniu HTML, wynik albo ponowienie. |
| `src/Mail/CoreCapture.php` | Uzbrajane przechwytywanie `pre_wp_mail` dla jednego rdzeniowego maila odzyskiwania hasła. |
| `src/Persistence/MailQueueRepository.php` | Całe SQL kolejki: wstawienie z `INSERT IGNORE`, wymagalne, zajęcie, wyniki, odzysk, retencja, listowanie, wznowienie. |
| `src/Cron/DispatchMail.php` | Harmonogram minutowy, zdarzenie natychmiastowe i wpis w `cron_schedules`. |
| `src/Cron/PurgeMailLog.php` | Dzienne czyszczenie logu wysyłek po 90 dniach. |
| `src/Admin/MailTemplatesScreen.php` | Podstrona „Portal: maile": temat i treść każdego rodzaju plus spis dostępnych zmiennych. |
| `src/Admin/MailLogScreen.php` | Podstrona „Portal: log wysyłek": lista z filtrem stanu, szczegóły do czytania, akcja „Wznów". |
| `src/Persistence/Migrations.php` | **Modyfikacja:** tabela `mail_queue` i harmonogramy dwóch nowych cronów. `DB_VERSION` rośnie trzy razy: **3** w Tasku 3 (tabela), **4** w Tasku 6, **5** w Tasku 10 (po jednym nowym kroku aktualizacji). |
| `src/Persistence/OptionStore.php` | **Modyfikacja:** opcja szablonów w stałych i w `all()`. |
| `src/Front/Mailer.php` | **Modyfikacja:** zamiast `wp_mail()` renderuje szablon i kolejkuje; dochodzą trzy nowe rodzaje. |
| `src/Front/Confirmation.php` | **Modyfikacja:** po potwierdzeniu adresu mail do wnioskodawcy. |
| `src/Front/PasswordReset.php` | **Modyfikacja:** temat i treść z szablonu; uzbrojenie przechwytywania. |
| `src/Admin/Menu.php` | **Modyfikacja:** dwa nowe slugi. |
| `src/Plugin.php`, `portal-users.php` | **Modyfikacja:** rejestracja ekranów i cronów; harmonogramy przy aktywacji i deaktywacji. |

---

## Task 1: Czysta domena maila

Siedem małych klas bez jednego wywołania WordPressa. Cztery z nich są przeniesieniem z `event-registration` — czytaj wzorzec, zanim napiszesz swoje.

**Pliki:**
- Utwórz: `src/Domain/Mail/MailKind.php`, `src/Domain/Mail/MailTemplate.php`, `src/Domain/Mail/Placeholders.php`, `src/Domain/Mail/TemplateRenderer.php`, `src/Domain/Mail/HtmlBody.php`, `src/Domain/Mail/RetryPolicy.php`, `src/Domain/Mail/QueueStatus.php`
- Test: `tests/Unit/Mail/MailKindTest.php`, `tests/Unit/Mail/TemplateRendererTest.php`, `tests/Unit/Mail/HtmlBodyTest.php`, `tests/Unit/Mail/RetryPolicyTest.php`

**Interfejsy:**
- Konsumuje: nic.
- Oddaje:

```php
enum MailKind: string {
    case Confirmation        = 'confirmation';        // potwierdzenie adresu, do wnioskodawcy
    case Editorial           = 'editorial';           // nowy wniosek czeka na decyzję, do redakcji
    case EditorialAuto       = 'editorial_auto';      // konto zatwierdzone automatycznie, do redakcji, informacyjny
    case ExistingAccount     = 'existing_account';    // ktoś zgłosił istniejący adres, do właściciela konta
    case ApplicationReceived = 'application_received';// wniosek przyjęty do weryfikacji, do wnioskodawcy
    case Approved            = 'approved';            // konto zatwierdzone, do wnioskodawcy
    case Rejected            = 'rejected';            // wniosek odrzucony z powodem, do wnioskodawcy
    case PasswordReset       = 'password_reset';      // odzyskiwanie hasła, do właściciela konta
    /** @return list<string> Nazwy zmiennych bez klamr, dostępne w tym rodzaju. */
    public function variables(): array;
}
final class MailTemplate {
    public function __construct( public readonly string $subject, public readonly string $body );
    public function subjectIsEmpty(): bool;   // trim() === ''
    public function bodyIsEmpty(): bool;
    public function isEmpty(): bool;          // oba pola puste
    public static function fromArray( array $raw ): self;   // klucze 'subject', 'body'; brak albo nie-tekst => ''
    public function toArray(): array;
}
final class Placeholders {
    public function __construct( array $values = array() );
    public function with( string $key, string $value ): self;  // nowy obiekt, bieżący nietknięty
    public function has( string $key ): bool;
    public function get( string $key ): string;
    public function toArray(): array;
}
final class TemplateRenderer {
    public static function render( string $template, Placeholders $values ): string;
}
final class HtmlBody {
    public static function wrap( string $text ): string;
}
final class RetryPolicy {
    public const MAX_ATTEMPTS = 3;
    public static function next( int $attempts ): ?int;  // sekundy do kolejnej próby; null = koniec prób
}
enum QueueStatus: string { case Queued = 'queued'; case Sending = 'sending'; case Sent = 'sent'; case Failed = 'failed'; }
```

Zmienne per rodzaj. Wspólne dla wszystkich: `email`, `login`, `nazwa_konta`, `profesja`, `numer_uprawnien`, `wynik_walidacji`, `nazwa_portalu`, `adres_portalu`, `link_logowania`. Dodatkowo: `Confirmation` → `link_potwierdzenia`; `Editorial`, `EditorialAuto` → `link_wniosku`, `pola_zgloszenia`; `Rejected` → `powod_odrzucenia`; `PasswordReset` → `link_hasla`. Rodzaje `ExistingAccount`, `ApplicationReceived`, `Approved` mają tylko wspólne.

- [ ] **Krok 1: Napisz padające testy**

```php
// TemplateRendererTest
public function test_known_placeholders_are_substituted(): void {
    $out = TemplateRenderer::render( 'Witaj {nazwa_konta}, konto {email}.', new Placeholders( array( 'nazwa_konta' => 'Jan', 'email' => 'jan@szpital.pl' ) ) );
    $this->assertSame( 'Witaj Jan, konto jan@szpital.pl.', $out );
}

public function test_an_unknown_placeholder_stays_in_the_text_verbatim(): void {
    // Literówka admina ma być WIDOCZNA w mailu. Pusty łańcuch zamieniłby ją w cichą dziurę.
    $this->assertSame( 'Numer: {numer_uprawnen}', TemplateRenderer::render( 'Numer: {numer_uprawnen}', new Placeholders( array( 'numer_uprawnien' => '1234567' ) ) ) );
}

public function test_a_substituted_value_is_not_expanded_again(): void {
    // Jeden przebieg: wartość zawierająca {klucz} nie jest rozwijana, więc dane z formularza nie sterują szablonem.
    $out = TemplateRenderer::render( '{a}', new Placeholders( array( 'a' => '{b}', 'b' => 'TAJNE' ) ) );
    $this->assertSame( '{b}', $out );
}

public function test_an_empty_value_removes_the_placeholder(): void {
    $this->assertSame( 'Numer: ', TemplateRenderer::render( 'Numer: {numer_uprawnien}', new Placeholders( array( 'numer_uprawnien' => '' ) ) ) );
}

// HtmlBodyTest
public function test_special_characters_are_escaped(): void {
    $this->assertStringContainsString( 'Jan &amp; Anna &lt;b&gt;', HtmlBody::wrap( 'Jan & Anna <b>' ) );
    $this->assertStringNotContainsString( '<b>', HtmlBody::wrap( 'Jan & Anna <b>' ) );
}

public function test_line_breaks_survive_as_markup(): void {
    // Mail złożony z linii musi zostać mailem złożonym z linii, nie jednym akapitem.
    $this->assertStringContainsString( "pierwsza<br />\ndruga", HtmlBody::wrap( "pierwsza\ndruga" ) );
}

public function test_the_wrapper_is_a_complete_document(): void {
    $html = HtmlBody::wrap( 'tekst' );
    $this->assertStringStartsWith( '<!DOCTYPE html>', $html );
    $this->assertStringEndsWith( '</html>', trim( $html ) );
}

// RetryPolicyTest
public function test_delays_grow_and_then_run_out(): void {
    $this->assertSame( 60, RetryPolicy::next( 1 ) );
    $this->assertSame( 300, RetryPolicy::next( 2 ) );
    $this->assertNull( RetryPolicy::next( RetryPolicy::MAX_ATTEMPTS ) );
    $this->assertNull( RetryPolicy::next( 99 ) );
}

// MailKindTest
public function test_every_kind_offers_the_shared_variables(): void {
    foreach ( MailKind::cases() as $kind ) {
        foreach ( array( 'email', 'nazwa_portalu', 'link_logowania' ) as $shared ) {
            $this->assertContains( $shared, $kind->variables(), $kind->value );
        }
    }
}

public function test_the_reason_variable_belongs_to_the_rejection_only(): void {
    $this->assertContains( 'powod_odrzucenia', MailKind::Rejected->variables() );
    $this->assertNotContains( 'powod_odrzucenia', MailKind::Approved->variables() );
}

public function test_the_three_kinds_that_already_have_a_public_filter_value_keep_it(): void {
    // Wartości 'confirmation', 'editorial', 'existing_account' są od Planu 3 CZĘŚCIĄ KONTRAKTU
    // filtrów pusers_mail_body / pusers_mail_subject. Zmiana którejkolwiek zepsułaby cudzy kod.
    $this->assertSame( 'confirmation', MailKind::Confirmation->value );
    $this->assertSame( 'editorial', MailKind::Editorial->value );
    $this->assertSame( 'existing_account', MailKind::ExistingAccount->value );
}
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**

Uruchom: `vendor/bin/phpunit --testsuite unit --filter 'MailKindTest|TemplateRendererTest|HtmlBodyTest|RetryPolicyTest'`
Oczekiwane: FAIL, „Class … not found".

- [ ] **Krok 3: Zaimplementuj siedem klas**

`TemplateRenderer::render()` — `preg_replace_callback( '/\{([a-z0-9_]+)\}/i', … )` z jednym przebiegiem; gdy `preg_replace_callback()` zwróci `null`, oddaj szablon nietknięty (treść ma dojechać nawet przy błędzie wyrażenia). `HtmlBody::wrap()` — `htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' )`, potem `nl2br()`, a wokół minimalny dokument z `meta charset` i jedną regułą `font-family`/`font-size` w `style`; żadnych obrazków, tabel ani kolorów. `RetryPolicy::next()` — mapa `array( 1 => 60, 2 => 300 )` i `?? null`.

- [ ] **Krok 4: Uruchom testy i strażnika domeny**

Uruchom: `vendor/bin/phpunit --testsuite unit`
Oczekiwane: PASS. `DomainPurityTest` rośnie o siedem plików i **musi** przechodzić — jeśli pada, w pliku domeny jest wywołanie albo komentarz z WordPressem.

- [ ] **Krok 5: Commit**

---

## Task 2: Domyślne szablony i ich nadpisania

Treści, które dziś stoją wewnątrz `Mailer`, przenoszą się do jednego miejsca i dostają placeholdery. Admin nadpisuje je w panelu (Task 9), ale **per pole**: wyczyszczony temat wraca na domyślny, a nie wysyła maila bez tematu.

**Pliki:**
- Utwórz: `src/Mail/DefaultTemplates.php`, `src/Persistence/MailTemplateRepository.php`
- Modyfikuj: `src/Persistence/OptionStore.php`
- Test: `tests/Integration/Mail/MailTemplateRepositoryTest.php`

**Interfejsy:**
- Konsumuje: `MailKind`, `MailTemplate`, `OptionStore::{read, write, delete}`.
- Oddaje:

```php
final class DefaultTemplates {
    public static function for( MailKind $kind ): MailTemplate;   // zawsze NIEPUSTE oba pola
}
final class MailTemplateRepository {
    public static function get( MailKind $kind ): MailTemplate;        // nadpisanie z odwrotem per pole
    public static function override( MailKind $kind ): MailTemplate;   // samo nadpisanie; puste pola = brak
    public static function save( MailKind $kind, MailTemplate $template ): void;  // puste pole USUWA nadpisanie
}
OptionStore::OPTION_MAIL_TEMPLATES = 'pusers_mail_templates';
```

Domyślne treści: przenieś dosłownie teksty z `Mailer::confirmation()`, `Mailer::editorial()` (wariant z decyzją → `Editorial`, wariant informacyjny → `EditorialAuto`) i `Mailer::existing_account()`, zamieniając wartości wstawiane dziś konkatenacją na placeholdery (`{profesja}`, `{email}`, `{numer_uprawnien}`, `{pola_zgloszenia}`, `{link_wniosku}`, `{link_potwierdzenia}`). Dla `ApplicationReceived`, `Approved`, `Rejected`, `PasswordReset` napisz treści nowe, w tym samym rzeczowym tonie; `Rejected` musi nieść `{powod_odrzucenia}`, a `Approved` i `ApplicationReceived` — `{link_logowania}`. Treść `PasswordReset` przenieś z `PasswordReset::mail_message()`, z `{link_hasla}` w miejscu odnośnika i `{login}` w miejscu nazwy konta.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_without_an_override_the_default_is_returned(): void {
    $default = DefaultTemplates::for( MailKind::Confirmation );
    $this->assertSame( $default->toArray(), MailTemplateRepository::get( MailKind::Confirmation )->toArray() );
}

public function test_an_override_replaces_only_the_field_it_sets(): void {
    MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto gotowe', '' ) );
    $this->assertSame( 'Konto gotowe', MailTemplateRepository::get( MailKind::Approved )->subject );
    $this->assertSame( DefaultTemplates::for( MailKind::Approved )->body, MailTemplateRepository::get( MailKind::Approved )->body );
}

public function test_clearing_a_field_restores_the_default_instead_of_sending_an_empty_mail(): void {
    MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto gotowe', 'Treść.' ) );
    MailTemplateRepository::save( MailKind::Approved, new MailTemplate( '   ', 'Treść.' ) );
    $this->assertSame( DefaultTemplates::for( MailKind::Approved )->subject, MailTemplateRepository::get( MailKind::Approved )->subject );
    $this->assertTrue( MailTemplateRepository::override( MailKind::Approved )->subjectIsEmpty() );
}

public function test_every_kind_has_a_non_empty_default(): void {
    foreach ( MailKind::cases() as $kind ) {
        $this->assertFalse( DefaultTemplates::for( $kind )->isEmpty(), $kind->value );
        $this->assertFalse( DefaultTemplates::for( $kind )->subjectIsEmpty(), $kind->value );
        $this->assertFalse( DefaultTemplates::for( $kind )->bodyIsEmpty(), $kind->value );
    }
}

public function test_every_placeholder_used_in_a_default_is_declared_by_its_kind(): void {
    // Szablon domyślny niosący zmienną, której rodzaj nie zna, wyszedłby do lekarza z klamrami w treści.
    foreach ( MailKind::cases() as $kind ) {
        $template = DefaultTemplates::for( $kind );
        preg_match_all( '/\{([a-z0-9_]+)\}/i', $template->subject . ' ' . $template->body, $m );
        foreach ( array_unique( $m[1] ) as $used ) {
            $this->assertContains( $used, $kind->variables(), $kind->value . ' => {' . $used . '}' );
        }
    }
}

public function test_the_option_is_listed_for_uninstall(): void {
    $this->assertContains( OptionStore::OPTION_MAIL_TEMPLATES, OptionStore::all() );
}
```

- [ ] **Krok 2: Uruchom test, potwierdź pad**

Uruchom suitę integracyjną z `--filter MailTemplateRepositoryTest` (patrz „Bramki").
Oczekiwane: FAIL, „Class … not found".

- [ ] **Krok 3: Zaimplementuj domyślne i repozytorium**

Nadpisania trzymaj w jednej opcji jako `array<string, array{subject: string, body: string}>` kluczowanej `MailKind::value`. `save()` zapisuje tylko pola niepuste po `trim()`, a rodzaj bez żadnego niepustego pola **usuwa** z tablicy — inaczej opcja rosłaby o puste rekordy, a przyszła zmiana treści domyślnej nie dotarłaby do portali, które „nadpisały" ją pustką.

- [ ] **Krok 4: Uruchom obie suity, PHPStan, PHPCS**

- [ ] **Krok 5: Commit**

---

## Task 3: Tabela kolejki i jej repozytorium

Kolejka jest jednocześnie wymaganym przez specyfikację **logiem wysyłek**, więc wiersz zostaje po wysłaniu, a nie znika.

**Pliki:**
- Utwórz: `src/Persistence/MailQueueRepository.php`
- Modyfikuj: `src/Persistence/Migrations.php`
- Test: `tests/Integration/Persistence/MailQueueRepositoryTest.php`, `tests/Integration/MigrationsTest.php`

**Interfejsy:**
- Konsumuje: `QueueStatus`, `Migrations::table()`.
- Oddaje:

```php
Migrations::DB_VERSION = 3;   // nowa tabela
Migrations::TABLES = array( 'event_log', 'terms_versions', 'mail_queue' );

final class MailQueueRepository {
    /** @param array{created_at:string,scheduled_at:string,kind:string,dedupe_key:string,user_id:int,recipient:string,subject:string,body:string} $row */
    public static function insert( array $row ): bool;                  // true tylko gdy wiersz POWSTAŁ
    public static function find( int $id ): ?array;
    /** @return list<array<string, mixed>> */
    public static function due( string $nowUtc, int $limit ): array;    // queued, scheduled_at <= now, ORDER BY id ASC
    public static function claim( int $id, string $nowUtc ): bool;      // queued -> sending, attempts+1; true gdy zajęte
    public static function mark_sent( int $id, string $nowUtc ): void;
    public static function reschedule( int $id, string $scheduledAtUtc, string $error ): void;  // -> queued
    public static function mark_failed( int $id, string $error ): void;
    public static function recover_stale( string $thresholdUtc ): int;  // sending starsze niż próg -> queued
    public static function purge_old( string $beforeUtc ): int;         // kasuje sent i failed starsze niż próg
    /** @return list<array<string, mixed>> */
    public static function paginate( string $status, int $perPage, int $offset ): array;  // '' = wszystkie, ORDER BY id DESC
    public static function count_by_status( string $status ): int;      // '' = wszystkie
    public static function requeue_failed( int $id ): bool;             // failed -> queued, attempts = 0
}
```

Schemat (dopisz obok dwóch istniejących `dbDelta()` w `Migrations::run()`):

```sql
CREATE TABLE {$queue} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  kind VARCHAR(40) NOT NULL,
  dedupe_key VARCHAR(191) NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  recipient VARCHAR(191) NOT NULL,
  subject TEXT NOT NULL,
  body LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL,
  attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  anonymized TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY dispatch (status, scheduled_at),
  KEY user_id (user_id)
) ENGINE=InnoDB {$charset};
```

`VARCHAR(191)` na kluczu odporności i odbiorcy, nie 255: w `utf8mb4` indeks na 255 znakach nie wchodzi w limit 767 bajtów na starszych konfiguracjach. `anonymized` i `user_id` istnieją od pierwszej migracji, mimo że ten plan tylko zapisuje `user_id` — Plan 7 anonimizuje log wysyłek i musi mieć po czym szukać wierszy konta; adres odbiorcy się zmienia, identyfikator nie. Nie usuwać jako „nieużywanych" (dokładnie ten wzorzec co kolumna `anonymized` w `event_log`).

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_duplicate_dedupe_key_does_not_create_a_second_row(): void {
    $this->assertTrue( MailQueueRepository::insert( $this->row( 'klucz-a' ) ) );
    $this->assertFalse( MailQueueRepository::insert( $this->row( 'klucz-a' ) ) );
    $this->assertSame( 1, MailQueueRepository::count_by_status( '' ) );
}

public function test_only_the_first_claim_wins(): void {
    // Cron minutowy i zdarzenie natychmiastowe mogą trafić w ten sam wiersz. Lekarz nie może dostać
    // dwóch linków potwierdzających, więc zajęcie jest warunkowym UPDATE-em, nie odczytem i zapisem.
    MailQueueRepository::insert( $this->row( 'klucz-b' ) );
    $id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
    $this->assertTrue( MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) ) );
    $this->assertFalse( MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) ) );
    $this->assertSame( 1, (int) MailQueueRepository::find( $id )['attempts'] );
}

public function test_a_row_scheduled_in_the_future_is_not_due(): void {
    MailQueueRepository::insert( $this->row( 'klucz-c', gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
    $this->assertSame( array(), MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 ) );
}

public function test_a_stale_sending_row_returns_to_the_queue(): void {
    // Przebieg przerwany w połowie (timeout PHP) zostawiłby wiersz w stanie sending na zawsze.
    MailQueueRepository::insert( $this->row( 'klucz-d' ) );
    $id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
    MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
    $this->assertSame( 1, MailQueueRepository::recover_stale( gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
    $this->assertSame( QueueStatus::Queued->value, MailQueueRepository::find( $id )['status'] );
}

public function test_requeue_touches_failed_rows_only(): void {
    MailQueueRepository::insert( $this->row( 'klucz-e' ) );
    $id = (int) MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['id'];
    $this->assertFalse( MailQueueRepository::requeue_failed( $id ) );   // jeszcze queued
    MailQueueRepository::claim( $id, gmdate( 'Y-m-d H:i:s' ) );
    MailQueueRepository::mark_failed( $id, 'SMTP nie odpowiada' );
    $this->assertTrue( MailQueueRepository::requeue_failed( $id ) );
    $this->assertSame( 0, (int) MailQueueRepository::find( $id )['attempts'] );
}

public function test_retention_keeps_rows_still_waiting(): void {
    // Czyszczenie logu nie może zjeść maila, który czeka na wysyłkę od dawna (cron nie chodził).
    MailQueueRepository::insert( $this->row( 'klucz-f', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
    $this->assertSame( 0, MailQueueRepository::purge_old( gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
    $this->assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Queued->value ) );
}

// MigrationsTest: dopisz do istniejącego testu zgodności TABLES z bazą
public function test_the_mail_queue_table_exists_after_migration(): void { … }
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj migrację i repozytorium**

`insert()` → `INSERT IGNORE` przez `$wpdb->prepare()` z jawną listą kolumn; zwróć `1 === $wpdb->rows_affected` (nie `insert_id`, który przy pominiętym wierszu zostaje z poprzedniego zapisu). `claim()` → `UPDATE … SET status='sending', attempts=attempts+1, scheduled_at=%s WHERE id=%d AND status='queued'` i `1 === $wpdb->rows_affected`. `recover_stale()` używa `scheduled_at` jako znacznika zajęcia (tak jak wzorzec). Każde zapytanie z `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.*` i **uzasadnieniem po `--`**, jak w `Uninstaller`.

- [ ] **Krok 4: Uruchom bramki**

Oczekiwane: PASS. `MigrationsTest` musi potwierdzić, że `DB_VERSION` wzrosło — instalacja po aktualizacji wtyczki nie przechodzi aktywacji, więc tabela powstaje wyłącznie przez `maybe_upgrade()`.

- [ ] **Krok 5: Commit**

---

## Task 4: Zmienne szablonu

Specyfikacja: „Zmienne obejmują dane konta, profesję, numer uprawnień, powód odrzucenia, link do logowania, nazwę portalu, a także **dowolne pole rejestracji**."

**Pliki:**
- Utwórz: `src/Mail/PlaceholderFactory.php`
- Test: `tests/Integration/Mail/PlaceholderFactoryTest.php`

**Interfejsy:**
- Konsumuje: `Placeholders`, `SettingsRepository`, `ProfessionRepository`, `FieldMatrixRepository`, `ValidatorRegistry`, `PageUrl::for()`, `UserMeta::PROFESSION`, `RegistrationHandler::ACF_META_PREFIX`.
- Oddaje: `PlaceholderFactory::for_user( int $userId, array $extra = array() ): Placeholders` — `$extra` (mapa klucz → wartość, bez klamr) dokłada i **nadpisuje** zmienne, których fabryka nie zna z konta: `powod_odrzucenia`, `link_potwierdzenia`, `link_hasla`.

Zasady:
- `email`, `login`, `nazwa_konta` z obiektu użytkownika; konto nieistniejące daje zbiór z samymi zmiennymi portalu (nie wyjątek).
- `profesja` — etykieta profesji, a gdy profesja została wycofana z ustawień: jej klucz. Pusty klucz daje pusty łańcuch.
- `numer_uprawnien` — zapisana wartość pola numeru dla profesji; brak pola albo profesja bez numeru daje pusty łańcuch. `wynik_walidacji` — `poprawny` / `niepoprawny` / pusty, liczony **ponownie** na zapisanej wartości (walidator mógł się zmienić po rejestracji; tak samo robi dziś `Mailer::credential_summary()`).
- `nazwa_portalu` — `get_bloginfo( 'name' )` przez `wp_specialchars_decode( …, ENT_QUOTES )`, bo encje w treści tekstowej byłyby widoczne dosłownie. `adres_portalu` — `home_url( '/' )`.
- `link_logowania` — `PageUrl::for( $settings->loginPageId )`. `link_wniosku` — `admin_url( 'user-edit.php?user_id=' . $userId )` (do Planu 6, który wskaże ekran wniosków).
- `pole_<klucz>` dla **każdego** pola widocznego w macierzy dla profesji konta, wartość zwinięta do jednej linii. `pola_zgloszenia` — te same pola jako lista `- klucz: wartość`, posortowana po kluczu. Pola **ukryte** dla profesji nie trafiają do zbioru: dziś tę samą regułę trzyma `Mailer::submitted_fields()`.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_account_and_site_variables_are_present(): void { … assertSame( 'jan@szpital.pl', $values->get( 'email' ) ); … }

public function test_a_registration_field_is_available_as_pole_prefix(): void {
    // „a także dowolne pole rejestracji" — bez tego redakcja nie postawi w mailu numeru telefonu.
    $this->assertSame( 'Szpital Wojewódzki', $values->get( 'pole_miejsce_pracy' ) );
}

public function test_a_field_hidden_for_the_profession_is_not_exposed(): void {
    // Pole ukryte dla profesji nie należy do tego zgłoszenia; w mailu byłoby śmieciem albo wyciekiem.
    $this->assertFalse( $values->has( 'pole_numer_pwz' ) );
}

public function test_the_credential_result_is_recomputed_not_read(): void { … }

public function test_a_withdrawn_profession_falls_back_to_its_key(): void { … }

public function test_extras_override_and_add(): void {
    $values = PlaceholderFactory::for_user( $userId, array( 'powod_odrzucenia' => 'numer nieprawidłowy' ) );
    $this->assertSame( 'numer nieprawidłowy', $values->get( 'powod_odrzucenia' ) );
}

public function test_a_missing_account_still_yields_site_variables(): void {
    // Konto usunięte cronem między zakolejkowaniem a renderem nie może wywrócić wysyłki.
    $values = PlaceholderFactory::for_user( 999999 );
    $this->assertNotSame( '', $values->get( 'nazwa_portalu' ) );
    $this->assertSame( '', $values->get( 'email' ) );
}

public function test_the_site_name_arrives_decoded(): void {
    update_option( 'blogname', 'Portal "Zdrowie" & Medycyna' );
    $this->assertSame( 'Portal "Zdrowie" & Medycyna', PlaceholderFactory::for_user( $userId )->get( 'nazwa_portalu' ) );
}
```

- [ ] **Krok 2: Uruchom test, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj fabrykę**

Pola zgłoszenia czytaj jednym `get_user_meta( $userId )`, nie pętlą zapytań — tak jak `Mailer::submitted_fields()`, którego logikę to zadanie przejmuje.

- [ ] **Krok 4: Uruchom bramki**
- [ ] **Krok 5: Commit**

---

## Task 5: Kolejkowanie i przepisanie `Mailer`

Od tego zadania żaden mail wtyczki nie idzie przez `wp_mail()` z miejsca zdarzenia. `Mailer` renderuje szablon i wstawia wiersz; wysyłką zajmuje się Task 6.

**Pliki:**
- Utwórz: `src/Mail/MailQueue.php`
- Modyfikuj: `src/Front/Mailer.php`
- Test: `tests/Integration/Mail/MailQueueTest.php`, `tests/Integration/Front/MailerTest.php`

**Interfejsy:**
- Konsumuje: `MailKind`, `MailTemplate`, `Placeholders`, `TemplateRenderer`, `MailTemplateRepository`, `MailQueueRepository`, `DispatchMail::HOOK_NOW` (nazwa hooka jako stała; Task 6 dowozi klasę — do tego czasu użyj literału w **jednym** miejscu i zamień go na stałą w Tasku 6).
- Oddaje:

```php
final class MailQueue {
    public static function enqueue( MailKind $kind, int $userId, string $recipient, Placeholders $values, string $dedupeKey, bool $immediate = false ): bool;
    public static function enqueue_rendered( MailKind $kind, int $userId, string $recipient, string $subject, string $body, string $dedupeKey, bool $immediate = false ): bool;
}
Mailer::{application_received(int):bool, approved(int):bool, rejected(int,string):bool}   // treść w Tasku 8
```

`enqueue()` = szablon z `MailTemplateRepository::get()` → `TemplateRenderer::render()` oba pola → filtry → `enqueue_rendered()`. Filtry `FILTER_SUBJECT` i `FILTER_BODY` **przenoszą się tutaj** z `Mailer::send()` z **niezmienionym** kontraktem: `( $tresc, string $kind, array $context )`, gdzie `$kind` to `MailKind::value`, a `$context` niesie co najmniej `user_id` i zmienne szablonu pod kluczem `values`.

Klucz odporności dopina odbiorcę sam: `$dedupeKey . ':' . substr( wp_hash( strtolower( $recipient ) ), 0, 16 )`. Powód: powiadomienie redakcji idzie **osobną wiadomością do każdego adresu** (w jednym `To:` każdy widziałby pozostałych — reguła z Planu 3), więc bez odbiorcy w kluczu drugi adres nigdy nie dostałby maila.

Klucze bazowe per rodzaj — **to decyzja tego planu i nie wolno jej uprościć do „rodzaj plus konto"**:

| Rodzaj | Klucz bazowy | Dlaczego tak |
|---|---|---|
| `confirmation` | `confirmation:<userId>:<substr(wp_hash($link),0,16)>` | Ponowna wysyłka istnieje jako funkcja Planu 3 i generuje **nowy** token. Klucz bez tokenu zablokowałby ją na zawsze. |
| `editorial`, `editorial_auto` | `<rodzaj>:<userId>` | Adres potwierdza się raz. |
| `existing_account` | `existing_account:<userId>:<gmdate('YmdH')>` | Najwyżej jeden na godzinę, zgodnie z progiem `Mailer::may_send_to()`. Osoba trzecia zgłaszająca cudzy adres nie zasypie właściciela. |
| `application_received` | `application_received:<userId>` | Jak wyżej: po potwierdzeniu adresu, raz. |
| `approved`, `rejected` | `<rodzaj>:<userId>:<gmdate('YmdHi')>` (+ `:<substr(wp_hash($reason),0,16)>` dla `rejected`) | Idempotencja ma chronić od **podwójnego kliknięcia** w panelu, nie od ponownej decyzji. Konto odrzucone i zatwierdzone miesiąc później **musi** dostać drugi mail. |
| `password_reset` | `password_reset:<userId>:<substr(wp_hash($key),0,16)>` | Rdzeń generuje nowy klucz na każde żądanie. |

`$immediate = true` dla maili, na które człowiek czeka przy ekranie: `confirmation` i `password_reset`. Pozostałe idą następnym przebiegiem crona.

**Uprzedzenie o testach, które padną.** `tests/Integration/Front/MailerTest.php` przechwytuje wysyłki filtrem `pre_wp_mail` założonym w `set_up()` (zwraca `true` i zbiera `$atts` do `$this->mails`) i **wszystkie** jego testy czytają tę tablicę. Po tym zadaniu `Mailer` nie woła `wp_mail()`, więc tablica zostanie pusta i padnie kilkanaście testów naraz. **To jest oczekiwane i nie wolno tego „naprawić" przywróceniem `wp_mail()` w `Mailer`.** Przepisz je na odczyt wiersza kolejki, zachowując **każdą dotychczasową asercję o treści** — zwłaszcza te, które pilnują, że pole ukryte dla profesji (`sekret` → `TAJNE-DANE`) nie trafia do redakcji, że numer uprawnień dochodzi nawet przy ukrytym polu, i że filtr zwracający nie-tekst zostawia treść domyślną. Te testy są jedynym dowodem, że przepisanie niczego nie zgubiło.

- [ ] **Krok 1: Napisz padające testy**

```php
// MailQueueTest
public function test_the_queued_row_holds_the_rendered_snapshot(): void {
    MailTemplateRepository::save( MailKind::Approved, new MailTemplate( 'Konto {email}', 'Wejdź: {link_logowania}' ) );
    MailQueue::enqueue( MailKind::Approved, $userId, 'jan@szpital.pl', $values, 'approved:' . $userId );
    $row = MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0];
    $this->assertSame( 'Konto jan@szpital.pl', $row['subject'] );
    $this->assertStringContainsString( 'Wejdź: http', $row['body'] );
}

public function test_editing_the_template_later_does_not_change_a_row_already_queued(): void {
    // Snapshot przy zapisie: dispatcher nie potrzebuje ustawień ani konta, a redakcja nie przepisuje
    // historii maili, które już poszły.
    … assertSame( 'Konto jan@szpital.pl', $rowAfterTemplateEdit['subject'] );
}

public function test_the_same_recipient_is_not_queued_twice_for_one_dedupe_key(): void { … }

public function test_two_recipients_of_one_notification_each_get_a_row(): void {
    // Bez odbiorcy w kluczu drugi adres redakcji nigdy nie dostałby wniosku.
    … assertSame( 2, MailQueueRepository::count_by_status( '' ) );
}

public function test_the_body_and_subject_filters_keep_the_contract_from_plan_three(): void {
    $seen = array();
    add_filter( Mailer::FILTER_BODY, static function ( $body, $kind, $context ) use ( &$seen ) {
        $seen = array( $kind, $context );
        return 'podmieniona treść';
    }, 10, 3 );
    MailQueue::enqueue( MailKind::Confirmation, $userId, 'jan@szpital.pl', $values, 'k' );
    $this->assertSame( 'confirmation', $seen[0] );
    $this->assertSame( $userId, $seen[1]['user_id'] );
    $this->assertSame( 'podmieniona treść', MailQueueRepository::due( gmdate( 'Y-m-d H:i:s' ), 10 )[0]['body'] );
}

public function test_an_invalid_recipient_is_refused_without_a_row(): void { … assertFalse( … ); assertSame( 0, … ); }

public function test_an_immediate_mail_schedules_a_dispatch_event(): void {
    MailQueue::enqueue( MailKind::Confirmation, $userId, 'jan@szpital.pl', $values, 'k', true );
    $this->assertNotFalse( wp_next_scheduled( DispatchMail::HOOK_NOW ) );
}

// MailerTest (istniejący plik): testy Planu 3 asertujące wysyłkę przez wp_mail ZMIENIAJĄ SIĘ
// na asercje o wierszu kolejki. To jedyne miejsce, gdzie ten plan przepisuje cudze testy.
public function test_confirmation_queues_a_row_for_the_account_address(): void { … }
public function test_a_resend_with_a_new_token_queues_a_second_row(): void { … }
public function test_editorial_without_recipients_writes_the_log_row_and_queues_nothing(): void {
    // Zachowanie Planu 3: brak odbiorców to zdarzenie w logu, nie cicha porażka.
    … assertSame( 0, MailQueueRepository::count_by_status( '' ) );
}
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**

- [ ] **Krok 3: Zaimplementuj `MailQueue` i przepisz `Mailer`**

`Mailer::send()` znika; zostają publiczne metody zdarzeń o **niezmienionych sygnaturach**. `Mailer::KIND_*` zostają jako publiczne stałe z **literałami** równymi wartościom enuma — PHP 8.1 nie pozwala użyć `MailKind::Confirmation->value` w wyrażeniu stałej (to dopiero 8.2), a wyrzucenie stałych zepsułoby kontrakt filtrów z Planu 3. Zgodność pilnuje test z Taska 1.

- [ ] **Krok 4: Uruchom bramki**

Oczekiwane: PASS. **Liczba testów integracyjnych nie może spaść** — przepisane testy `MailerTest` mają zmienić asercję, nie zniknąć.

- [ ] **Krok 5: Commit**

---

## Task 6: Dispatcher i crony

**Pliki:**
- Utwórz: `src/Mail/Dispatcher.php`, `src/Cron/DispatchMail.php`
- Modyfikuj: `src/Mail/MailQueue.php` (literał hooka → stała), `src/Plugin.php`, `portal-users.php`, `src/Persistence/Migrations.php`
- Test: `tests/Integration/Mail/DispatcherTest.php`, `tests/Integration/Cron/DispatchMailTest.php`, `tests/Integration/WiringTest.php`

**Interfejsy:**
- Konsumuje: `MailQueueRepository`, `RetryPolicy`, `HtmlBody`, `QueueStatus`.
- Oddaje:

```php
final class Dispatcher {
    public const STALE_AFTER   = 300;   // sekundy; po tyle wiersz `sending` uznajemy za porzucony
    public const DEFAULT_BATCH = 20;
    public static function run(): void;
}
final class DispatchMail {
    public const HOOK     = 'pusers_dispatch_mail';
    public const HOOK_NOW = 'pusers_dispatch_mail_now';
    public const SCHEDULE = 'pusers_minute';
    public static function register(): void;     // oba hooki + filtr cron_schedules
    public static function schedule(): void;
    public static function unschedule(): void;   // zdejmuje OBA hooki
}
```

`Dispatcher::run()`: `recover_stale( teraz − STALE_AFTER )` → `due( teraz, batch )` → dla każdego wiersza `claim()`, a gdy nie wyszło, **pomiń bez wysyłki** → `wp_mail()` → `mark_sent()` albo, gdy `RetryPolicy::next( attempts )` daje opóźnienie, `reschedule()`, inaczej `mark_failed()`. Rozmiar paczki przez filtr `pusers_mail_batch_size` z zabezpieczeniem przed wartością ≤ 0.

Wysyłka: powód porażki zbieraj nasłuchem `wp_mail_failed` dodawanym i **zdejmowanym wokół jednego wywołania**, a samo wywołanie otocz `try/catch/finally` — `wp_mail()` potrafi rzucić wyjątkiem z PHPMailera. Opakowanie HTML: `add_filter( 'wp_mail_content_type', … )` zwracający `text/html` zakładany **tuż przed** i zdejmowany w `finally`; treść przez `HtmlBody::wrap()`. Filtr zostawiony na stałe zmieniłby typ treści mailom innych wtyczek — to jest ta sama klasa błędu co nagłówek zostawiony na globalnym filtrze.

Harmonogram: `cron_schedules` dostaje wpis `pusers_minute` o interwale 60 s (rdzeń nie ma nic krótszego niż `hourly`). `register()` podpina `HOOK` **i** `HOOK_NOW` pod `Dispatcher::run()`; `schedule()` zakłada zdarzenie cykliczne **tylko gdy go nie ma** — jak `PurgeUnconfirmed::schedule()`, żeby administrator, który je zdjął, nie odzyskiwał go przy każdym żądaniu.

**Rejestracja stoi w `Plugin::boot()`, nie pod `pusers_register_features`.** Ten sam powód co brama logowania z Planu 4: wyłączone albo zepsute aktualizacją ACF Pro zatrzymałoby wysyłkę maili, które **już są w kolejce** — w tym linku potwierdzającego, bez którego lekarz nie wejdzie na konto. Dispatcher nie potrzebuje ACF do niczego: czyta wiersz i woła `wp_mail()`. Ekrany panelu (Taski 9 i 10) zostają za zależnością.

`Migrations::run()` dokłada `DispatchMail::schedule()` obok istniejącego `PurgeUnconfirmed::schedule()`, a `DB_VERSION` rośnie do **4** — nowy krok aktualizacji, nie nowa tabela. Bez podbicia wersji instalacja, która już przeszła migrację 3 w Tasku 3, **nigdy** nie zaplanowałaby dispatchera: `maybe_upgrade()` wchodzi tylko przy wersji niższej od bieżącej, a aktualizacja wtyczki nie odpala hooka aktywacji. Komentarz przy stałej wymienia kroki — dopisz swój, wzorem „(2: harmonogram czyszczenia kont)". `portal-users.php` dostaje `register_activation_hook` i `register_deactivation_hook` dla `DispatchMail`. **`PurgeMailLog` nie istnieje do Taska 10** — nie wołaj go tutaj, fatal zabije każde żądanie.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_a_due_row_is_sent_and_marked(): void { … assertSame( QueueStatus::Sent->value, … ); assertNotNull( $row['sent_at'] ); }

public function test_a_failing_send_is_rescheduled_then_fails_with_a_readable_reason(): void {
    // Lekarz nie dostał maila, bo SMTP nie odpowiada. Odpowiedź na „czy wyszedł" musi być w logu.
    add_filter( 'pre_wp_mail', static fn () => false );
    Dispatcher::run();  $this->assertSame( QueueStatus::Queued->value, … );   // próba 1 => ponowienie
    … // po trzeciej próbie:
    $this->assertSame( QueueStatus::Failed->value, $row['status'] );
    $this->assertNotSame( '', (string) $row['last_error'] );
}

public function test_a_throwing_send_does_not_abort_the_run(): void {
    // Wyjątek z PHPMailera nie może zostawić wiersza w stanie sending i zabić całej paczki.
    add_filter( 'pre_wp_mail', static function () { throw new \RuntimeException( 'nie ma gniazda' ); } );
    Dispatcher::run();
    $this->assertNotSame( QueueStatus::Sending->value, $row['status'] );
}

public function test_the_mail_leaves_as_html_and_the_content_type_is_restored(): void {
    $captured = null;
    add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$captured ) { $captured = $atts; return true; }, 10, 2 );
    Dispatcher::run();
    $this->assertStringContainsString( '<!DOCTYPE html>', $captured['message'] );
    $this->assertSame( 'text/plain', apply_filters( 'wp_mail_content_type', 'text/plain' ) );
}

public function test_a_row_already_claimed_by_another_run_is_skipped(): void { … }

public function test_the_body_in_the_queue_stays_plain_text(): void {
    // Opakowanie HTML zakłada dispatcher przy wysyłce; log wysyłek ma pokazywać czytelny tekst.
    $this->assertStringNotContainsString( '<', (string) MailQueueRepository::find( $id )['body'] );
}

// DispatchMailTest
public function test_the_minute_schedule_is_registered(): void {
    $this->assertSame( 60, wp_get_schedules()[ DispatchMail::SCHEDULE ]['interval'] );
}
public function test_scheduling_twice_does_not_duplicate_the_event(): void { … }
public function test_unschedule_clears_both_hooks(): void { … }

// WiringTest
public function test_the_dispatcher_is_registered_without_acf(): void {
    // Kolejka musi się opróżniać także wtedy, gdy ACF Pro jest wyłączone — inaczej link
    // potwierdzający zostaje w bazie, a lekarz nie ma jak wejść na konto.
    … assertNotFalse( has_action( DispatchMail::HOOK, … ) );
}
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj dispatchera, crona i podpięcia**
- [ ] **Krok 4: Uruchom bramki**
- [ ] **Krok 5: Commit**

---

## Task 7: Mail odzyskiwania hasła przez szablon

Mechanizm zostaje **rdzeniowy** — to decyzja Planu 4 i ten plan jej nie odwraca. Zmienia się tylko to, skąd bierze się treść i że wysyłka przechodzi przez kolejkę.

**Pliki:**
- Utwórz: `src/Mail/CoreCapture.php`
- Modyfikuj: `src/Front/PasswordReset.php`, `src/Plugin.php`
- Test: `tests/Integration/Mail/CoreCaptureTest.php`, `tests/Integration/Front/PasswordResetTest.php`

**Interfejsy:**
- Konsumuje: `MailQueue::enqueue_rendered()`, `MailKind::PasswordReset`, `MailTemplateRepository`, `PlaceholderFactory`, `TemplateRenderer`.
- Oddaje:

```php
final class CoreCapture {
    public static function register(): void;   // filtr pre_wp_mail, priorytet 10
    public static function arm( MailKind $kind, int $userId, string $email, string $dedupeKey ): void;
    public static function disarm(): void;
    public static function capture( mixed $return, array $atts ): mixed;
}
```

Jak to się składa:

1. `PasswordReset::mail_title()` i `mail_message()` **zachowują swoje dwa guardy w niezmienionej postaci**: brak klucza albo loginu → wejście nietknięte; brak wskazanej strony odzyskiwania → treść i temat rdzenia nietknięte. To nie jest ostrożność kosmetyczna: bez strony wtyczki odnośnik rdzenia (`wp-login.php?action=rp`) **działa**, a nasz nie, więc podmiana treści wysłałaby człowieka w ślepą uliczkę.
2. Za guardami `mail_message()` renderuje treść z `MailTemplateRepository::get( MailKind::PasswordReset )` ze zmiennymi z `PlaceholderFactory::for_user()` plus `link_hasla`, a `mail_title()` — temat z tego samego szablonu. Szablon, który po renderze dałby **pustą** treść, nie może dojechać do rdzenia: `wp-includes/user.php:3439` sprawdza `! $message` wcześniej i rdzeń wysłałby wiadomość z pustą treścią. Pusty render → oddaj wejście nietknięte.
3. `mail_message()` (jedyne miejsce, które zna klucz) **uzbraja** przechwytywanie: `CoreCapture::arm( MailKind::PasswordReset, $userId, $email, 'password_reset:' . $userId . ':' . substr( wp_hash( $key ), 0, 16 ) )`.
4. `CoreCapture::capture()` przepuszcza wejście nietknięte (`$return`), gdy nie jest uzbrojone **albo gdy odbiorca z `$atts['to']` nie jest adresem uzbrojonego konta**. Dopiero zgodny adres idzie do `enqueue_rendered()` z `$immediate = true`, po czym przechwytywanie się rozbraja i metoda zwraca `true` (wynik `wp_mail()` dla rdzenia).
5. `PasswordReset` rozbraja przechwytywanie w `finally` wokół `retrieve_password()` — gdy rdzeń z jakiegokolwiek powodu nie dojdzie do `wp_mail()`, uzbrojenie nie może zostać na następne żądanie.

Czego ten mechanizm **nie** dotyka: maila o zmianie hasła (`wp_password_change_notification`), który specyfikacja każe zostawić standardowym. Idzie poza uzbrojeniem, więc nie jest przechwytywany ani logowany.

Kolejność na filtrze: `CoreCapture` rejestruje się w `Plugin::boot()`, czyli **przed** harnessem testowym, który `MailerTest` i inne testy zakładają na `pre_wp_mail` w `set_up()`. Przy równym priorytecie 10 decyduje kolejność rejestracji (`WP_Hook` sortuje tylko kubełki priorytetów), więc nasz callback widzi wiadomość pierwszy. Nie podnoś priorytetu „na wszelki wypadek": przechwytywanie, które wyprzedza filtr obcej wtyczki chcącej **zablokować** wysyłkę, zakolejkowałoby mail, którego ta wtyczka nie przepuściła.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_the_recovery_mail_lands_in_the_queue_with_the_template_content(): void { … }

public function test_a_foreign_plugin_mail_sent_while_armed_is_not_captured(): void {
    // Przechwycenie zabrałoby cudzej wtyczce wysyłkę i zapisało jej treść w naszym logu.
    CoreCapture::arm( MailKind::PasswordReset, $userId, 'jan@szpital.pl', 'klucz' );
    $this->assertNull( CoreCapture::capture( null, array( 'to' => 'ktos@inny.pl', 'subject' => 'x', 'message' => 'y' ) ) );
    $this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
}

public function test_nothing_is_captured_when_not_armed(): void { … assertNull( … ); }

public function test_the_capture_disarms_itself_after_one_mail(): void {
    // Uzbrojenie, które zostaje, przechwyciłoby następny mail WordPressa w tym żądaniu.
    … assertNull( CoreCapture::capture( null, $sameAtts ) );
}

public function test_the_core_password_change_notification_is_left_alone(): void {
    wp_password_change_notification( get_userdata( $userId ) );
    $this->assertSame( 0, MailQueueRepository::count_by_status( '' ) );
}

// PasswordResetTest — regresja z Planu 4, która MUSI przeżyć ten plan
public function test_without_a_recovery_page_the_core_subject_and_body_stay_untouched(): void {
    … assertSame( 'temat rdzenia', PasswordReset::mail_title( 'temat rdzenia' ) );
    … assertSame( 'treść rdzenia', PasswordReset::mail_message( 'treść rdzenia', 'klucz', 'jan' ) );
}

public function test_an_empty_rendered_template_does_not_reach_core(): void {
    add_filter( Mailer::FILTER_BODY, static fn () => '   ' );
    $this->assertSame( 'treść rdzenia', PasswordReset::mail_message( 'treść rdzenia', 'klucz', 'jan' ) );
}

public function test_the_reset_link_still_points_at_the_plugin_page_with_both_arguments(): void { … }
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj przechwytywanie i przepisz dwie metody**

`CoreCapture::register()` woła `Plugin::boot()` — poza bramką ACF, jak dispatcher. Uzbrojenie trzymaj w prywatnym polu statycznym; nic nie zapisuj w opcjach ani transientach (uzbrojenie żyje jedno żądanie).

- [ ] **Krok 4: Uruchom bramki**

Oczekiwane: PASS. Testy `PasswordResetTest` z Planu 4 **wszystkie** muszą przechodzić: to one dowodzą, że mechanizm odzyskiwania został rdzeniowy.

- [ ] **Krok 5: Commit**

---

## Task 8: Trzy maile cyklu wniosku

Specyfikacja wymienia pięć maili do użytkownika. Po Planie 4 wtyczka wysyła dwa: potwierdzenie adresu i odzyskiwanie hasła. Dochodzą „wniosek przyjęty do weryfikacji", „konto zatwierdzone" i „wniosek odrzucony z powodem".

**Pliki:**
- Modyfikuj: `src/Front/Mailer.php`, `src/Front/Confirmation.php`
- Test: `tests/Integration/Front/MailerTest.php`, `tests/Integration/Front/ConfirmationTest.php`

**Interfejsy:**
- Konsumuje: `MailQueue::enqueue()`, `PlaceholderFactory`, `AccountStatusRepository::get()`, `SettingsRepository::get()`.
- Oddaje: `Mailer::{application_received( int $userId ): bool, approved( int $userId ): bool, rejected( int $userId, string $reason ): bool}`. Plan 6 woła `approved()` i `rejected()` ze swoich akcji w panelu; `rejected()` dostaje powód **już rozstrzygnięty** (wpisany ręcznie albo wybrany z `Settings::$rejectionReasons`) i sam go nie szuka.

`Confirmation::confirm()` po powiadomieniu redakcji wysyła wnioskodawcy:
- akceptacja **włączona** → `application_received` („wniosek czeka na decyzję redakcji"),
- akceptacja **wyłączona** → `approved` (konto jest już zatwierdzone, więc właściwym mailem jest ten z odnośnikiem do logowania; „czeka na decyzję" byłoby nieprawdą).

Rozstrzygaj po **stanie konta** odczytanym z repozytorium, nie po samym ustawieniu: stan jest tym, co o koncie decyduje, a ustawienie mogło się zmienić między zgłoszeniem a potwierdzeniem adresu. Tę samą regułę trzyma dziś `Mailer::editorial()` przez `$needsDecision`.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_after_confirming_the_address_a_pending_account_is_told_it_waits(): void {
    … assertSame( MailKind::ApplicationReceived->value, $row['kind'] );
}

public function test_with_approval_disabled_the_account_is_told_it_can_log_in(): void {
    … assertSame( MailKind::Approved->value, $row['kind'] );
    $this->assertStringContainsString( $loginPageUrl, (string) $row['body'] );
}

public function test_the_rejection_mail_carries_the_reason(): void {
    Mailer::rejected( $userId, 'numer nieprawidłowy' );
    $this->assertStringContainsString( 'numer nieprawidłowy', (string) $row['body'] );
}

public function test_a_double_click_on_approve_queues_one_mail_but_a_later_decision_queues_another(): void {
    // Idempotencja chroni od podwójnego kliknięcia, nie od ponownej decyzji miesiąc później.
    $this->assertTrue( Mailer::approved( $userId ) );
    $this->assertFalse( Mailer::approved( $userId ) );
    … // klucz z innej minuty:
    $this->assertTrue( Mailer::approved( $userId ) );
}

public function test_an_account_without_an_address_queues_nothing(): void { … }

public function test_confirming_twice_does_not_queue_a_second_application_mail(): void { … }
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj trzy metody i podpięcie w `Confirmation`**
- [ ] **Krok 4: Uruchom bramki**
- [ ] **Krok 5: Commit**

---

## Task 9: Ekran szablonów w panelu

Specyfikacja: „**Szablony:** temat i treść edytowalne w panelu."

**Pliki:**
- Utwórz: `src/Admin/MailTemplatesScreen.php`
- Modyfikuj: `src/Admin/Menu.php`, `src/Plugin.php`
- Test: `tests/Integration/Admin/MailTemplatesScreenTest.php`

**Interfejsy:**
- Konsumuje: `MailKind`, `MailTemplate`, `MailTemplateRepository`, `DefaultTemplates`, `Menu`, `Request`, `AdminPostingTrait` (w testach).
- Oddaje: `MailTemplatesScreen::{ACTION='pusers_save_mail_templates', register():void, render():void, handle_save():void}`, `Menu::SLUG_MAIL_TEMPLATES='pusers-mail-templates'`.

Ekran: osiem sekcji, po jednej na rodzaj, każda z polem tematu, polem treści i spisem zmiennych tego rodzaju. Pole puste = wartość domyślna; domyślną pokazuj atrybutem `placeholder`, żeby admin widział, co dostanie po wyczyszczeniu. Pod spisem zmiennych jedno zdanie o `{pole_<klucz>}` — tych nie da się wyliczyć, bo zależą od konfiguracji pól, a nie od rodzaju maila.

Sanityzacja: temat `sanitize_text_field()`, treść `sanitize_textarea_field()` (zachowuje łamania linii, zdejmuje znaczniki). Znaczniki w treści **nie są obsługiwane** — `HtmlBody::wrap()` eskejpuje wszystko, więc wpisany `<b>` dojechałby do lekarza dosłownie. Zdejmowanie ich przy zapisie mówi prawdę zamiast obiecywać HTML, którego nie ma.

Rejestracja idzie pod `pusers_register_features` (ekran panelu, zależność ACF Pro jest w porządku) i podpina **zarówno** `admin_menu` jak i `admin_post_{ACTION}`, wzorem `SettingsScreen`.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_saving_a_subject_and_body_stores_the_override(): void { … }

public function test_clearing_both_fields_restores_the_default(): void { … assertSame( DefaultTemplates::for( … )->toArray(), MailTemplateRepository::get( … )->toArray() ); }

public function test_a_save_without_a_nonce_is_refused(): void { … expectException( WPDieException::class ); }

public function test_a_save_without_the_capability_is_refused(): void { … }

public function test_a_typo_in_a_variable_name_is_saved_as_typed(): void {
    // Panel nie „naprawia" {numer_uprawnen} na {numer_uprawnien}: literówka ma zostać widoczna,
    // bo zgadywanie intencji admina w treści maila do lekarza jest gorsze niż klamry w mailu.
    … assertSame( 'Numer: {numer_uprawnen}', MailTemplateRepository::override( MailKind::Editorial )->body );
}

public function test_tags_are_stripped_from_the_body(): void { … assertStringNotContainsString( '<b>', … ); }

public function test_the_screen_lists_every_kind_and_its_variables(): void {
    $html = $this->render_screen();
    foreach ( MailKind::cases() as $kind ) {
        $this->assertStringContainsString( $kind->value, $html );
        foreach ( $kind->variables() as $variable ) {
            $this->assertStringContainsString( '{' . $variable . '}', $html );
        }
    }
}

public function test_multiline_bodies_survive_the_round_trip(): void {
    // Treść maila to linie. Zapis, który je zlepia, psuje każdy mail wtyczki naraz.
    … assertSame( "pierwsza\ndruga", MailTemplateRepository::override( MailKind::Approved )->body );
}
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj ekran**

Pola przez `esc_attr()` i `esc_textarea()`; nazwy pól z jednego prywatnego helpera (`pusers_tpl_<kind>_subject` / `_body`), nie z literałów rozsypanych po renderze i zapisie.

- [ ] **Krok 4: Uruchom bramki**
- [ ] **Krok 5: Commit**

---

## Task 10: Log wysyłek, „Wznów" i retencja

Specyfikacja: „W panelu log wysyłek, żeby dało się odpowiedzieć na pytanie »czy ten mail wyszedł«" oraz „Log wysyłek maili ma okres retencji (propozycja: 90 dni) i jest po nim czyszczony cronem."

**Pliki:**
- Utwórz: `src/Admin/MailLogScreen.php`, `src/Cron/PurgeMailLog.php`
- Modyfikuj: `src/Admin/Menu.php`, `src/Plugin.php`, `portal-users.php`, `src/Persistence/Migrations.php`
- Test: `tests/Integration/Admin/MailLogScreenTest.php`, `tests/Integration/Cron/PurgeMailLogTest.php`

**Interfejsy:**
- Konsumuje: `MailQueueRepository::{paginate, count_by_status, find, requeue_failed, purge_old}`, `QueueStatus`, `Menu`, `Request`.
- Oddaje:

```php
MailLogScreen::{ACTION_REQUEUE='pusers_requeue_mail', PER_PAGE=20, NOTICE_REQUEUED='requeued',
    NOTICE_NOT_FAILED='not_failed', register():void, render():void, handle_requeue():void}
Menu::SLUG_MAIL_LOG = 'pusers-mail-log';
PurgeMailLog::{HOOK='pusers_purge_mail_log', RETENTION_DAYS=90, register():void, schedule():void, unschedule():void, run():void}
```

Lista: kolumny data utworzenia, rodzaj, odbiorca, temat, stan, liczba prób, powód ostatniej porażki (skrócony). Filtr po stanie z zakładkami i licznikami, `ORDER BY id DESC`, 20 na stronę. Szczegóły pod `?action=view&id=N` są **tylko do czytania** — temat i treść w `<pre>` przez `esc_html()` — bo to samo założenie co panel wniosków: log jest materiałem dowodowym, nie formularzem.

„Wznów" tylko dla wiersza `failed`: `admin-post` → `Request::guard()` → `requeue_failed()` → PRG. Ekran **nie woła dispatchera** — resetuje wiersz, a cron złapie go w następnym przebiegu. Próba wznowienia wiersza w innym stanie kończy się powiadomieniem, nie zmianą: wiersz `sent` wznowiony po cichu wysłałby lekarzowi drugi raz to samo.

Retencja: dziennie, kasuje `sent` i `failed` starsze niż `RETENTION_DAYS` liczone od `created_at`, z nadpisaniem przez filtr `pusers_mail_log_retention_days` (zabezpiecz przed wartością ≤ 0). Harmonogram zakłada `Migrations::run()` obok dwóch istniejących, a `DB_VERSION` rośnie do **5** — z tego samego powodu co w Tasku 6: portal, który przeszedł już migrację 4, inaczej nie dostanie tego crona nigdy. `portal-users.php` dostaje parę hooków aktywacji i deaktywacji dla `PurgeMailLog`. Wiersze `queued` i `sending` **zostają niezależnie od wieku** — to maile, które jeszcze nie wyszły; skasowane po cichu zniknęłyby razem z linkiem potwierdzającym, na który ktoś czeka. Przebieg **nie pisze** nic do `event_log`: `PurgeUnconfirmed` loguje usunięcia, bo konto jest nieodtwarzalne, a dzienny wiersz o skasowanych logach zapchałby tabelę, która jest śladem decyzji redakcji o zatwierdzaniu kont.

- [ ] **Krok 1: Napisz padające testy**

```php
public function test_the_list_shows_a_failed_row_with_its_reason(): void { … }

public function test_the_status_tabs_count_rows(): void { … }

public function test_requeue_returns_a_failed_row_to_the_queue(): void {
    … assertSame( QueueStatus::Queued->value, MailQueueRepository::find( $id )['status'] );
}

public function test_requeue_refuses_a_row_that_was_already_sent(): void {
    // Wznowienie wysłanego maila wysłałoby go drugi raz. Odpowiedź to powiadomienie, nie zmiana.
    … assertSame( QueueStatus::Sent->value, MailQueueRepository::find( $id )['status'] );
}

public function test_requeue_without_a_nonce_or_capability_is_refused(): void { … }

public function test_the_detail_view_escapes_the_stored_body(): void {
    // Treść pochodzi z szablonu, który edytuje człowiek; w panelu nie wolno jej wykonać.
    … assertStringNotContainsString( '<script>', $html );
}

public function test_the_detail_view_has_no_editing_controls(): void { … }

// PurgeMailLogTest
public function test_rows_older_than_the_retention_window_are_deleted(): void { … }

public function test_a_row_still_waiting_is_kept_regardless_of_age(): void {
    // Cron nie chodził przez pół roku; mail nadal ma wyjść, nie zniknąć.
    … assertSame( 1, MailQueueRepository::count_by_status( QueueStatus::Queued->value ) );
}

public function test_the_run_writes_nothing_to_the_event_log(): void { … }

public function test_the_retention_filter_cannot_be_set_to_zero_days(): void { … }
```

- [ ] **Krok 2: Uruchom testy, potwierdź pad**
- [ ] **Krok 3: Zaimplementuj ekran i cron**

Tabelę renderuj ręcznie (`wp-list-table` nie jest autoloadowane i wymaga `require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` przed deklaracją klasy plus `set_current_screen()` w testach). Wtyczka nie ma dziś ani jednej podklasy `WP_List_Table`, a `ProfessionsScreen` i `MatrixScreen` renderują swoje tabele same — trzymamy ten wzorzec.

- [ ] **Krok 4: Uruchom bramki**
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

Nazwę kontenera daje `docker ps` (sufiks `-tests-cli-1`). **`--testsuite integration` nie istnieje** — wypisuje „No tests executed!", co wygląda na sukces. Dwa równoległe przebiegi suity integracyjnej dzielą jedną bazę i dają fałszywe błędy — nigdy nie uruchamiaj dwóch naraz.

Na koniec `git status --porcelain` musi być pusty.

## Samoprzegląd planu

**1. Pokrycie specyfikacji.** Sekcja „Maile": odbiorcy redakcji → istnieją od Planu 2 (ustawienia) i Planu 3 (`Mailer::editorial_recipients()`), bez zmian. Pięć maili do użytkownika → Taski 2, 5, 7, 8. Mail do redakcji → Task 2 (dwa warianty jako osobne rodzaje). „Mail o zmianie hasła zostaje standardowy" → Task 7, pilnowane testem. Szablony edytowalne w panelu ze zmiennymi → Taski 2, 4, 9. Format „tekst zwykły w prostym opakowaniu HTML" → Task 1 (`HtmlBody`), zakładane przy wysyłce w Tasku 6. Dostarczalność, log wysyłek w panelu → Taski 3, 6, 10. Sekcja „Dane osobowe", retencja 90 dni → Task 10; anonimizacja logu wysyłek → Plan 7, kolumny `user_id` i `anonymized` gotowe w Tasku 3. Sekcja „Wzorce przeniesione": kolejka mailowa w całości (render przy zapisie, `INSERT IGNORE` pod `UNIQUE`, odzysk zawieszonych, retry, „Wznów") → Taski 1, 3, 5, 6, 10.

**Luki świadome:** ekran wniosków i wołanie `Mailer::approved()` / `rejected()` z panelu (Plan 6); eksport i anonimizacja logu wysyłek (Plan 7); szablony plikowe w motywie (odrzucone, patrz „Co ten plan świadomie zostawia").

**2. Przegląd kroków.** Każdy krok kodu ma sygnaturę i plik; ciała funkcji pojawiają się tylko tam, gdzie testy ich nie wyznaczają (schemat tabeli, `claim()`, składanie przechwytywania w Tasku 7). Kroki weryfikacji mają komendę i oczekiwany wynik.

**3. Zgodność typów i kolejności.** `MailKind` jedno źródło rodzajów; `Mailer::KIND_*` zostają literałami pinowanymi testem — sprawdzone w kontenerze na PHP 8.1.34: `const X = K::A->value;` daje `Fatal error: Constant expression contains invalid operations` (właściwości enuma w wyrażeniach stałych to dopiero PHP 8.2, a `composer.json` pinuje platformę na 8.1). `MailTemplate` ten sam obiekt w Taskach 2, 5, 7, 9. Repozytoria stoją w `src/Persistence/**` razem z `SettingsRepository` i `ProfessionRepository`; `snake_case` w nowych metodach zgodnie z `Migrations::maybe_upgrade()`.

Trzy zadania modyfikują `Migrations`: Task 3 (tabela, wersja 3), Task 6 (harmonogram dispatchera, wersja 4), Task 10 (harmonogram retencji, wersja 5). Każde podbija wersję, bo `maybe_upgrade()` wchodzi wyłącznie przy wersji niższej od bieżącej — krok dołożony bez podbicia nigdy nie wykonałby się na portalu, który przeszedł poprzednią migrację. Task 6 **nie wolno** wołać `PurgeMailLog::schedule()`: ta klasa powstaje dopiero w Tasku 10.

`DispatchMail::HOOK_NOW` używane w Tasku 5 jako literał i zamieniane na stałą w Tasku 6 — jedyny świadomy dług między zadaniami, wskazany wprost w interfejsach Taska 5.

**4. Review Focus.** Pięć klas wejścia wyżej, każda z przypisanym testem w zadaniu, które posiada kod. Szósta, która nie jest nowa, ale którą ten plan mógłby złamać: „brak strony odzyskiwania → treść rdzenia nietknięta" z Planu 4, przypięta ponownie w Tasku 7.

**5. Proporcja.** Dziesięć zadań, większość plików poniżej 150 linii. Największe bloki kodu w planie to schemat tabeli i testy — treści szablonów i ciała metod pisze wykonawca.
