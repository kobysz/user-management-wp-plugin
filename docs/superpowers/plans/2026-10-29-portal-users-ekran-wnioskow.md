# Ekran wniosków: zatwierdzanie i odrzucanie — plan wdrożenia

> **Dla wykonawców agentowych:** WYMAGANA PODSKILL: użyj superpowers:subagent-driven-development (zalecane) albo superpowers:executing-plans, żeby wykonać ten plan zadanie po zadaniu. Kroki mają składnię pól wyboru (`- [ ]`) do odhaczania.

**Cel:** redakcja dostaje w panelu listę wniosków z zakładkami i licznikami, czyta wniosek bez możliwości jego edycji i rozstrzyga go — zatwierdza albo odrzuca z powodem, który jedzie do użytkownika mailem i zostaje w logu zdarzeń.

**Architektura:** trzy nowe pliki czystej domeny orzekają o faktach, których nie wolno liczyć dwa razy — dozwolone przejścia stanów, werdykt numeru uprawnień i odnośnik do rejestru. Repozytorium wniosków odpowiada na pytania listy (liczniki, strona, filtr) jednym zapytaniem na pytanie. Serwis `Decision` jest jedynym miejscem, w którym konto zmienia stan z panelu: zapisuje stan, **sprawdza odczytem zwrotnym, że zapis wszedł**, dopiero potem wysyła maila i pisze do logu. Ekran jest cienki: renderuje i woła serwis.

**Stos:** WordPress 6.5+, PHP 8.1+, ACF Pro, PSR-4 z autoloadem Composera, PHPUnit (dwie suity: `phpunit.xml.dist` i `phpunit-integration.xml.dist`), PHPStan level max, PHPCS (WordPress).

**Spec:** `docs/superpowers/specs/2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md`, sekcja „Ekran wniosków w panelu" oraz „Pozostałe funkcje pierwszej wersji".

**Zobowiązania odziedziczone:** `docs/superpowers/decyzje-plan-5.md` §7 („Dla Planu 6") i `docs/superpowers/backlog.md`.

## Ograniczenia globalne

- WordPress 6.5 lub nowszy, PHP 8.1 lub nowszy. **Stała enuma w wyrażeniu stałej (`MailKind::Case->value` w `const`) to PHP 8.2** — na 8.1 jest fatalem. Nie używać.
- ACF Pro jest zależnością twardą wtyczki, ale **ekran wniosków rejestruje się poza bramką zależności** (uzasadnienie w Zadaniu 4).
- Single site. Bez hardkodowania założeń blokujących późniejsze multisite.
- Polski jako język bazowy, **każdy** tekst widoczny dla człowieka przez `__()`/`esc_html__()` z domeną `portal-users`.
- `src/Domain/**` nie wolno dotknąć WordPressa ani `$wpdb` — pilnuje tego `tests/Unit/Architecture/DomainPurityTest.php`, który rekurencyjnie wchodzi w nowe katalogi. Domena zwraca **kody**, nie komunikaty.
- Dostęp do ekranu wniosków i ustawień: `Menu::CAPABILITY` (`manage_options`). Wtyczka nie nadaje żadnej roli nowych zdolności.
- Każde żądanie zmieniające stan przechodzi przez `Request::guard( string $action )` (nonce + uprawnienie) i kończy się `Request::redirect()`, nigdy renderem po POST.
- Zapytania własne wyłącznie przez `$wpdb->prepare()`. Przy `%i` (identyfikator) nazwa tabeli pochodzi z `Migrations::table()`, nigdy z żądania.
- Bramki przed każdym commitem: obie suity testów, PHPStan, PHPCS. **PHPStan uruchamiać z `--memory-limit=1G`** — domyślne 128 MB kontenera nie wystarcza na to drzewo i analiza kończy się „Child process error: PHPStan process crashed", co wygląda jak błąd kodu, a nie jest.

Polecenia w kontenerze (`d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1`), z katalogu wtyczki:

```bash
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpunit
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpunit -c phpunit-integration.xml.dist
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpstan analyse --no-progress --memory-limit=1G
MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users d6b96ffdf9dcb4e21bf488edc5ade53d-tests-cli-1 vendor/bin/phpcs -q
```

**Dwa przebiegi suity integracyjnej naraz dzielą jedną bazę i dają fałszywe błędy.** Nigdy nie uruchamiać dwóch równolegle.

Stan wejściowy: unit 234 testy (524 asercje), integration 873 (3230). Każde zadanie te liczby podnosi.

## Review Focus

Pięć klas wejścia, których specyfikacja nie opisuje, a które uderzą w człowieka. Każda ma przypisany test w zadaniu, które jest właścicielem kodu.

1. **Dwa kliknięcia „Zatwierdź" na tym samym wniosku** (dwóch redaktorów, podwójne wysłanie formularza, odświeżenie strony). Oczekiwane: drugie rozstrzygnięcie nie zmienia nic, nie wysyła drugiego maila i nie dokłada drugiego wiersza do logu — kolejka maili dedupuje po kluczu, ale log zdarzeń nie dedupuje niczego sam. Test w Zadaniu 6.
2. **Zapis stanu, który nie wszedł** (metadane niedostępne, wartość odrzucona przez filtr). Oczekiwane: żaden mail nie wychodzi, a redakcja widzi komunikat o porażce. Mail „konto zatwierdzone" dla konta, które dalej nie wpuszcza, jest gorszy od braku maila. Test w Zadaniu 6.
3. **Profesja usunięta z ustawień po rejestracji.** Oczekiwane: wiersz dalej się renderuje i daje się rozstrzygnąć, kolumna numeru mówi „nie dotyczy", odnośnika do rejestru nie ma. Wniosek, którego nie da się tknąć, bo klient skasował profesję, to konto zamknięte na zawsze. Testy w Zadaniach 2, 4 i 6.
4. **Zatwierdzenie konta w stanie `email_unconfirmed`** (odnośnik ze starego maila do redakcji, ręcznie podmieniony parametr). Oczekiwane: odmowa. Nikt, kto nie dowiódł władzy nad adresem, nie dostaje możliwości logowania — to ta sama dziura, którą zamknęła decyzja właściciela z 2026-10-27. Testy w Zadaniach 1 i 6.
5. **Konto usunięte między renderem listy a akcją masową.** Oczekiwane: akcja pomija brakujące konta, rozstrzyga pozostałe i mówi ile pominęła. Test w Zadaniu 7.

---

## Struktura plików

**Czysta domena** (bez WordPressa, testy jednostkowe):

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Domain/Account/StatusTransition.php` | które przejścia stanu wolno zrobić z panelu |
| `src/Domain/Application/ApplicationRow.php` | niezmienny wiersz wniosku (dane, nie zachowanie) |
| `src/Domain/Application/CredentialVerdict.php` | **jedno** źródło werdyktu numeru uprawnień: kod, nie tekst |
| `src/Domain/Application/RegistryLink.php` | adres wyszukiwarki rejestru z wklejonym numerem |

**Dane i serwisy:**

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Persistence/ApplicationRepository.php` | odpowiedzi dla listy: liczniki, strona, jeden wiersz |
| `src/Account/Decision.php` | jedyne miejsce rozstrzygania wniosku z panelu |

**Panel:**

| Plik | Odpowiedzialność |
| --- | --- |
| `src/Admin/ApplicationsScreen.php` | podstrona, zakładki, liczniki, filtr, tabela, obsługa akcji |
| `src/Admin/ApplicationDetails.php` | panel szczegółów, tylko do czytania |
| `src/Admin/VerdictLabels.php` | kody werdyktu → polskie teksty (i18n po stronie panelu) |
| `src/Admin/ProfileSync.php` | most ACF → metadane wtyczki plus audyt zmiany numeru |

**Modyfikowane:** `src/Persistence/UserMeta.php`, `src/Front/RegistrationHandler.php`, `src/Mail/PlaceholderFactory.php`, `src/Admin/Menu.php`, `src/Plugin.php`, `docs/superpowers/backlog.md`.

**Czego ten plan NIE robi:** przycisku zdejmującego blokadę limitu prób (Plan 4 przypisał go tutaj). Blokada żyje w pięciu klasach `src/Front/**` pod własnymi stałymi kubełków, z których dwie są prywatne, a zablokowany bywa adres IP, który nie ma wiersza na żadnym ekranie — to osobny podsystem z własnym rejestrem kubełków, nie akcja przy wniosku. Idzie do backlogu w Zadaniu 8 z nazwami wszystkich pięciu klas, żeby nie zdryfował po raz drugi bez śladu.

---

### Zadanie 1: Domena — przejścia stanów, werdykt numeru, odnośnik do rejestru

**Pliki:**
- Utwórz: `src/Domain/Account/StatusTransition.php`
- Utwórz: `src/Domain/Application/CredentialVerdict.php`
- Utwórz: `src/Domain/Application/RegistryLink.php`
- Modyfikuj: `src/Mail/PlaceholderFactory.php` (metoda `validation_result()`, ok. linii 139-160)
- Test: `tests/Unit/Account/StatusTransitionTest.php`
- Test: `tests/Unit/Application/CredentialVerdictTest.php`
- Test: `tests/Unit/Application/RegistryLinkTest.php`

**Interfejsy:**
- Konsumuje: `AccountStatus` (enum: `EmailUnconfirmed`, `Pending`, `Approved`, `Rejected`), `Profession` (`$key`, `$label`, `$credentialType`, `$registryUrl`, `$credentialField`), `CredentialType` (z przypadkiem `None`), `ValidatorRegistry::for( CredentialType ): ?CredentialValidator`, `CredentialResult::{EMPTY, INVALID_FORMAT, INVALID_CHECKSUM, INVALID_CHAMBER}`.
- Produkuje:
  - `StatusTransition::decidable( AccountStatus $status ): bool`
  - `StatusTransition::allowed( AccountStatus $from, AccountStatus $to ): bool`
  - `CredentialVerdict::{NOT_APPLICABLE = 'not_applicable', MISSING = 'missing', VALID = 'valid'}`
  - `CredentialVerdict::for( ?Profession $profession, string $number ): string` — zwraca jedną z trzech stałych wyżej albo kod z `CredentialResult`
  - `CredentialVerdict::isWarning( string $code ): bool` — prawda dla `MISSING` i dla każdego kodu nieprawidłowości, fałsz dla `VALID` i `NOT_APPLICABLE`
  - `RegistryLink::PLACEHOLDER = '{numer}'`
  - `RegistryLink::for( string $registryUrl, string $number ): string` — pusty napis, gdy odnośnika nie ma

- [ ] **Krok 1: Napisz testy jednostkowe `StatusTransition`**

```php
public function test_rozstrzygac_wolno_tylko_po_potwierdzeniu_adresu(): void {
	$this->assertFalse( StatusTransition::decidable( AccountStatus::EmailUnconfirmed ) );
	$this->assertTrue( StatusTransition::decidable( AccountStatus::Pending ) );
	$this->assertTrue( StatusTransition::decidable( AccountStatus::Approved ) );
	$this->assertTrue( StatusTransition::decidable( AccountStatus::Rejected ) );
}

public function test_przejscia_dozwolone_i_zabronione(): void {
	$this->assertTrue( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Approved ) );
	$this->assertTrue( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Rejected ) );
	$this->assertTrue( StatusTransition::allowed( AccountStatus::Rejected, AccountStatus::Approved ) );
	$this->assertTrue( StatusTransition::allowed( AccountStatus::Approved, AccountStatus::Rejected ) );
	// Konto bez potwierdzonego adresu: zatwierdzenie dałoby logowanie komuś, kto adresu nie dowiódł.
	$this->assertFalse( StatusTransition::allowed( AccountStatus::EmailUnconfirmed, AccountStatus::Approved ) );
	$this->assertFalse( StatusTransition::allowed( AccountStatus::EmailUnconfirmed, AccountStatus::Rejected ) );
	// Cel inny niż rozstrzygnięcie nie jest ruchem panelu.
	$this->assertFalse( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::Pending ) );
	$this->assertFalse( StatusTransition::allowed( AccountStatus::Pending, AccountStatus::EmailUnconfirmed ) );
}
```

- [ ] **Krok 2: Napisz testy jednostkowe `CredentialVerdict`**

Przypadki, każdy z asercją na dokładny kod: profesja `null` → `NOT_APPLICABLE`; `CredentialType::None` → `NOT_APPLICABLE`; profesja z typem numeru, ale z pustym `credentialField` → `NOT_APPLICABLE`; numer pusty przy wymaganym typie → `MISSING`; numer poprawny `'5425740'` dla PWZ lekarza → `VALID`; numer `'5425741'` (ta sama cyfra kontrolna zepsuta) → `CredentialResult::INVALID_CHECKSUM`; numer `'abc'` → `CredentialResult::INVALID_FORMAT`; `isWarning()` prawdą dla `MISSING`, `INVALID_FORMAT`, `INVALID_CHECKSUM`, `INVALID_CHAMBER` i fałszem dla `VALID`, `NOT_APPLICABLE`.

- [ ] **Krok 3: Napisz testy jednostkowe `RegistryLink`**

```php
public function test_numer_wchodzi_w_miejsce_znacznika(): void {
	$this->assertSame(
		'https://rejestr.nil.org.pl/?pwz=5425740',
		RegistryLink::for( 'https://rejestr.nil.org.pl/?pwz={numer}', '5425740' )
	);
}

public function test_adres_bez_znacznika_zostaje_bez_zmian(): void {
	$this->assertSame( 'https://nipip.pl/weryfikacja-pwz', RegistryLink::for( 'https://nipip.pl/weryfikacja-pwz', '0201234P' ) );
}

public function test_numer_jest_kodowany_do_adresu(): void {
	$this->assertSame( 'https://x.pl/?q=a%20b%26c', RegistryLink::for( 'https://x.pl/?q={numer}', 'a b&c' ) );
}

public function test_brak_adresu_brak_numeru_i_obcy_schemat_daja_pustke(): void {
	$this->assertSame( '', RegistryLink::for( '', '5425740' ) );
	$this->assertSame( '', RegistryLink::for( 'https://x.pl/?q={numer}', '' ) );
	$this->assertSame( '', RegistryLink::for( 'javascript:alert(1)', '5425740' ) );
	$this->assertSame( '', RegistryLink::for( 'ftp://x.pl/{numer}', '5425740' ) );
}
```

- [ ] **Krok 4: Uruchom testy i potwierdź porażkę**

Komenda unit (patrz Ograniczenia globalne) z `--filter 'StatusTransition|CredentialVerdict|RegistryLink'`.
Oczekiwane: błąd „Class ... not found".

- [ ] **Krok 5: Napisz trzy klasy domeny**

`StatusTransition` i `RegistryLink` — ciała wynikają z testów. `RegistryLink::for()` dopuszcza wyłącznie schematy `http` i `https` (porównanie po `parse_url()`, nie po `str_starts_with()` na surowym napisie), a numer wstawia przez `rawurlencode()`.

`CredentialVerdict::for()` powtarza kolejność bramek z `PlaceholderFactory::validation_result()` — profesja/typ/pole, potem pusty numer, potem brak walidatora, potem walidacja — ale zwraca **kod**: `$result->isValid() ? self::VALID : $result->code()`.

- [ ] **Krok 6: Przepisz `PlaceholderFactory::validation_result()` na `CredentialVerdict`**

Metoda przestaje sama orzekać i tłumaczy tylko kod na dotychczasowe **dwa** słowa: `VALID` → `poprawny`, `MISSING` → `brak numeru`, `NOT_APPLICABLE` → `nie dotyczy`, każdy inny kod → `niepoprawny`.

**Rozróżnienia `invalid_format` / `invalid_checksum` NIE wolno wprowadzać do maila** — komentarz nad tą metodą tłumaczy dlaczego (maszynowy żargon po angielsku w polskim mailu, a następny ruch człowieka jest ten sam). Rozróżnienie należy do ekranu z Zadania 4. Komentarz zaktualizuj: wskazuje teraz `CredentialVerdict` jako źródło faktu.

- [ ] **Krok 7: Uruchom testy i potwierdź przejście**

Unit z filtrem z Kroku 4: PASS. Następnie **cała** suita integracyjna — `tests/Integration/Mail/PlaceholderFactoryTest.php` i `tests/Integration/Front/MailerTest.php` przypinają dotychczasowe teksty maila i muszą przejść bez zmiany. Jeżeli któryś wymaga zmiany, refaktor zmienił treść maila: wróć do Kroku 6.

- [ ] **Krok 8: Uruchom PHPStan, PHPCS i `DomainPurityTest`**

`DomainPurityTest` musi zobaczyć nowy katalog `src/Domain/Application` i orzec o nim czysto.

- [ ] **Krok 9: Commit**

```bash
git add src/Domain/Account/StatusTransition.php src/Domain/Application src/Mail/PlaceholderFactory.php tests/Unit/Account/StatusTransitionTest.php tests/Unit/Application
git commit -m "feat: domena ekranu wnioskow - przejscia stanow, werdykt numeru, odnosnik do rejestru"
```

---

### Zadanie 2: Repozytorium wniosków

**Pliki:**
- Utwórz: `src/Domain/Application/ApplicationRow.php`
- Utwórz: `src/Persistence/ApplicationRepository.php`
- Test: `tests/Integration/Persistence/ApplicationRepositoryTest.php`

**Interfejsy:**
- Konsumuje: `UserMeta::{STATUS, PROFESSION}`, `AccountStatus::tryFromStored( ?string ): ?self`, `RegistrationHandler::ACF_META_PREFIX`, `Profession::$credentialField`, `ProfessionRepository::get()`.
- Produkuje:
  - `ApplicationRow` — konstruktor promujący, wyłącznie `public readonly`: `int $userId`, `string $login`, `string $email`, `string $displayName`, `string $professionKey`, `string $credentialNumber`, `string $registeredAtUtc`, `AccountStatus $status`
  - `ApplicationRepository::counts(): array<string, int>` — klucze to wartości `AccountStatus`, **każdy z czterech obecny**, zero gdy pusto
  - `ApplicationRepository::total( AccountStatus $status, string $professionKey = '' ): int`
  - `ApplicationRepository::paginate( AccountStatus $status, string $professionKey, int $perPage, int $offset ): array<int, ApplicationRow>`
  - `ApplicationRepository::one( int $userId ): ?ApplicationRow`

- [ ] **Krok 1: Napisz testy integracyjne**

Nazwy i asercje:

```php
public function test_liczniki_maja_wszystkie_cztery_stany_takze_zerowe(): void
// Trzy konta pending, jedno approved, zero rejected → array( 'email_unconfirmed' => 0, 'pending' => 3, 'approved' => 1, 'rejected' => 0 )
// assertSame na całej tablicy: brakujący klucz wywala zakładkę z licznikiem na ekranie.

public function test_konto_bez_stanu_wtyczki_nie_jest_wnioskiem(): void
// Użytkownik bez metadanej pusers_status (administrator dodany ręcznie) nie wchodzi ani do counts(), ani do paginate().

public function test_stan_nierozpoznany_nie_zaklamuje_licznika(): void
// pusers_status = 'cokolwiek' → nie liczy się do żadnej zakładki i nie wywraca counts().

public function test_filtr_profesji_zwiezla_liste(): void
// Dwa pending lekarze, jeden pending pielegniarka → paginate( Pending, 'lekarz', 20, 0 ) daje 2 wiersze, total() daje 2.

public function test_strona_i_kolejnosc_po_dacie_rejestracji_malejaco(): void
// Trzy konta o rozną user_registered → paginate( Pending, '', 2, 0 ) daje dwa najnowsze w kolejności malejącej, offset 2 daje najstarsze.

public function test_wiersz_niesie_numer_uprawnien_z_metadanej_z_przedrostkiem(): void
// Profesja z credentialField 'pwz', metadana pusers_acf_pwz = '5425740' → $row->credentialNumber === '5425740'.

public function test_profesja_usunieta_z_ustawien_nie_gubi_wiersza(): void
// Konto z pusers_profession = 'znikla' → wiersz jest, professionKey === 'znikla', credentialNumber === ''.
// Review Focus 3.

public function test_one_zwraca_null_dla_konta_bez_stanu_i_dla_nieistniejacego(): void
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

Komenda integracyjna z `--filter ApplicationRepository`. Oczekiwane: „Class ... not found".

- [ ] **Krok 3: Zaimplementuj `ApplicationRow`**

Czysty obiekt danych, bez metod. Plik idzie do `src/Domain/Application`, więc **żadnego wywołania WordPressa** — wiersz składa repozytorium.

- [ ] **Krok 4: Zaimplementuj `ApplicationRepository`**

`counts()` to **jedno** zapytanie, nie cztery (ekran logu maili ma pięć `COUNT` na render i jest to pozycja backlogu — nie powtarzać):

```sql
SELECT meta_value, COUNT(*) AS c FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value
```

Wynik przepuść przez `AccountStatus::tryFromStored()`, zsumuj do tablicy zainicjowanej czterema zerami; wartości nierozpoznane odpadają.

`paginate()` i `total()` przez `WP_User_Query` z `meta_query` (`relation => 'AND'`, klucz stanu zawsze, klucz profesji tylko gdy `'' !== $professionKey`), `orderby => 'registered'`, `order => 'DESC'`, `number`/`offset`; `total()` czyta `get_total()` z zapytania o zerowym `number`. Numer uprawnień dociąga się z `pusers_acf_<credentialField>` profesji; przy profesji nieznanej albo bez pola numeru zostaje pustym napisem.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**

- [ ] **Krok 6: Bramki i commit**

```bash
git add src/Domain/Application/ApplicationRow.php src/Persistence/ApplicationRepository.php tests/Integration/Persistence/ApplicationRepositoryTest.php
git commit -m "feat: repozytorium wnioskow - liczniki jednym zapytaniem, filtr profesji, strona"
```

---

### Zadanie 3: Rejestracja zapisuje adres IP

**Pliki:**
- Modyfikuj: `src/Persistence/UserMeta.php`
- Modyfikuj: `src/Front/RegistrationHandler.php` (blok zapisu metadanych, ok. linii 473-484)
- Test: `tests/Integration/Front/RegistrationHandlerTest.php`
- Test: `tests/Unit/Persistence/UserMetaTest.php`

**Dlaczego to zadanie istnieje:** specyfikacja żąda, by panel szczegółów pokazywał „adres IP rejestracji". Dziś **nie ma go nigdzie** — `SpamGuard` zapisuje IP wyłącznie przy odmowie z progu, a wiersz logu `registration_created` niesie profesję i ostrzeżenie o numerze, bez adresu. Panel z Zadania 5 nie ma czego odczytać.

**Interfejsy:**
- Produkuje:
  - `UserMeta::REGISTRATION_IP = 'pusers_registration_ip'`, wypełniane przy udanej rejestracji
  - `UserMeta::REJECTION_REASON = 'pusers_rejection_reason'` — **sama stała, bez zapisu.** Wypełnia ją Zadanie 6, ale czyta ją już Zadanie 5 (panel szczegółów), które jest wcześniej; stała zadeklarowana dopiero w Zadaniu 6 wywaliłaby PHPStan na panelu.

- [ ] **Krok 1: Napisz testy**

```php
// tests/Unit/Persistence/UserMetaTest.php — dopisz REGISTRATION_IP do istniejącej asercji kompletności
// (test wylicza wszystkie klucze; nowa stała musi w nim być, inaczej all() ją gubi i Plan 7 nie wyczyści).

// tests/Integration/Front/RegistrationHandlerTest.php
public function test_rejestracja_zapisuje_adres_ip_zgloszenia(): void
// REMOTE_ADDR = '203.0.113.7' → get_user_meta( $id, UserMeta::REGISTRATION_IP, true ) === '203.0.113.7'

public function test_zapisany_adres_honoruje_naglowek_proxy_tylko_po_wlaczeniu(): void
// Z trustedProxyHeader pustym i nagłówkiem obecnym zapisuje REMOTE_ADDR;
// po wskazaniu nagłówka w ustawieniach zapisuje wartość z nagłówka.

public function test_brak_adresu_nie_zostawia_metadanej(): void
// REMOTE_ADDR usunięty z $_SERVER → metadanej nie ma (pusty napis to nie „adres").
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Dopisz stałe i zapis**

`UserMeta::REGISTRATION_IP` i `UserMeta::REJECTION_REASON` wchodzą też do `UserMeta::all()`. W `RegistrationHandler` adres bierz z `IpAddress::from( $_SERVER, $settings->trustedProxyHeader )` — tą samą drogą, co reszta wtyczki, żeby zaufany nagłówek miał jedną interpretację. Zapis obok `UserMeta::PROFESSION`, wyłącznie gdy adres nie jest pusty.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Persistence/UserMeta.php src/Front/RegistrationHandler.php tests/Integration/Front/RegistrationHandlerTest.php tests/Unit/Persistence/UserMetaTest.php
git commit -m "feat: rejestracja zapisuje adres IP zgloszenia do metadanych konta"
```

---

### Zadanie 4: Ekran wniosków — zakładki, liczniki, filtr, kolumny

**Pliki:**
- Utwórz: `src/Admin/ApplicationsScreen.php`
- Utwórz: `src/Admin/VerdictLabels.php`
- Modyfikuj: `src/Admin/Menu.php` (dopisz `SLUG_APPLICATIONS`)
- Modyfikuj: `src/Plugin.php` (rejestracja ekranu)
- Modyfikuj: `src/Mail/PlaceholderFactory.php` (`link_wniosku`, ok. linii 103)
- Test: `tests/Integration/Admin/ApplicationsScreenTest.php`
- Test: `tests/Integration/Mail/PlaceholderFactoryTest.php`
- Test: `tests/Integration/WiringTest.php`

**Decyzja o rejestracji poza bramką zależności.** Każdy inny ekran panelu wisi na `pusers_register_features`, czyli za `Dependencies::satisfied()`. Ten ekran rejestruje się **bezpośrednio na `admin_menu`**, jak `LoginGateway` i `SessionGuard` rejestrują się poza bramką. Powód: brama logowania działa bez ACF Pro i trzyma ludzi na zewnątrz, maile do redakcji już wyszły, a konta czekają w stanie `pending`. Gdyby ekran zniknął razem z ACF, awaria ACF zamykała portal dla każdego oczekującego lekarza bez żadnej drogi ratunku z panelu. Egzekwowanie zostaje, funkcje bramkuje się — rozstrzyganie wniosków jest po stronie egzekwowania. Panel szczegółów (Zadanie 5) jest jedynym miejscem, które naprawdę potrzebuje ACF, i sam mówi, gdy go nie ma.

**Interfejsy:**
- Konsumuje: `ApplicationRepository`, `CredentialVerdict`, `RegistryLink`, `ProfessionRepository::get()`, `Menu::{CAPABILITY, PARENT, url()}`, `Request::notice()`.
- Produkuje:
  - `Menu::SLUG_APPLICATIONS = 'pusers-applications'`
  - `ApplicationsScreen::PER_PAGE = 20`
  - `ApplicationsScreen::{ARG_STATUS = 'status', ARG_PROFESSION = 'profession', ARG_PAGE = 'paged', ARG_USER = 'user'}` (publiczne — Zadania 5 i 7 budują z nich adresy)
  - `ApplicationsScreen::detail_url( int $userId ): string`
  - `ApplicationsScreen::{register(), add_page(), render()}`
  - `VerdictLabels::text( string $code ): string` — **wyłącznie** tekst. O tym, czy werdykt jest ostrzeżeniem, orzeka `CredentialVerdict::isWarning()` z Zadania 1; druga metoda o tej nazwie w panelu rozjechałaby się z domeną przy pierwszym nowym kodzie walidatora.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_zakladki_pokazuja_liczniki_wszystkich_stanow(): void
// Render z trzema pending i jednym rejected → HTML zawiera 'pending' z „3" i 'rejected' z „1",
// a zakładka approved z „0" też jest obecna.

public function test_domyslna_zakladka_to_oczekujace(): void
// Render bez parametru status pokazuje wiersze kont pending i nie pokazuje approved.

public function test_filtr_profesji_zawezaj_tabele_i_zostaje_w_adresach_zakladek(): void
// ?profession=lekarz → w tabeli tylko lekarze, a odnośniki zakładek niosą profession=lekarz
// (inaczej kliknięcie zakładki po cichu gubi filtr).

public function test_kolumna_numeru_rozroznia_format_od_sumy_kontrolnej(): void
// Numer '5425741' (zła suma) → tekst o sumie kontrolnej; 'abc' → tekst o formacie.
// Dwa RÓŻNE napisy — asercja na oba, nie na samą obecność ostrzeżenia. Zobowiązanie z decyzje-plan-5.md §1.4.

public function test_profesja_bez_numeru_ma_pusta_kolumne_bez_odnosnika(): void
// CredentialType::None → brak tekstu werdyktu i brak <a> do rejestru.

public function test_odnosnik_do_rejestru_niesie_numer(): void
// registryUrl 'https://rejestr.nil.org.pl/?pwz={numer}' → href z 5425740, przepuszczony przez esc_url.

public function test_profesja_usunieta_z_ustawien_renderuje_wiersz(): void
// Review Focus 3: wiersz jest, kolumna profesji pokazuje surowy klucz, numer „nie dotyczy", bez odnośnika.

public function test_ekran_wymaga_uprawnienia(): void
// Użytkownik z rolą editor → render() nie wypisuje tabeli (albo kończy się odmową), administrator → wypisuje.

public function test_licznik_oczekujacych_jest_w_tytule_menu(): void
// Dwa konta pending → tytuł podstrony zawiera klasę 'awaiting-mod' i liczbę 2; zero pending → bez bąbelka.

public function test_ekran_rejestruje_sie_bez_acf(): void
// Bez spełnionej zależności (pusters_register_features nie odpala) podstrona i tak powstaje.
// Asercja na podpięcie admin_menu, nie na samo istnienie klasy.

public function test_numer_strony_poza_zakresem_nie_daje_pustej_tabeli(): void
// ?paged=999 przy jednej stronie wyników pokazuje stronę ostatnią, jak MailLogScreen.
```

W `tests/Integration/Mail/PlaceholderFactoryTest.php`:

```php
public function test_link_wniosku_wskazuje_ekran_wnioskow_nie_edycje_uzytkownika(): void
// Zmienna {link_wniosku} zawiera page=pusers-applications i user=<id>, i NIE zawiera 'user-edit.php'.
// Zobowiązanie z decyzje-plan-5.md §7.
```

W `tests/Integration/WiringTest.php`: asercja, że `ApplicationsScreen::register()` podpina `admin_menu` i że dzieje się to **poza** `pusers_register_features`.

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `VerdictLabels`**

Mapa kod → tekst, pięć wpisów: `VALID` → `poprawny`, `NOT_APPLICABLE` → `nie dotyczy`, `MISSING` → `brak numeru`, `INVALID_FORMAT` → `nieprawidłowy format numeru`, `INVALID_CHECKSUM` → `nieprawidłowa suma kontrolna`, `INVALID_CHAMBER` → `nieznana izba okręgowa`. Kod nieznany (nowy walidator, stary panel) → `nie udało się sprawdzić`, nigdy pusty napis ani surowy kod. Wszystko przez `__()`.

- [ ] **Krok 4: Zaimplementuj `ApplicationsScreen`**

`register()` podpina `add_page()` pod `admin_menu`. `add_page()` woła `add_submenu_page( Menu::PARENT, … )` z tytułem z licznikiem oczekujących (`<span class="awaiting-mod">`, jak licznik komentarzy rdzenia) — liczba z `ApplicationRepository::counts()`.

`render()`: `current_user_can( Menu::CAPABILITY )`, potem zakładki z licznikami (adres każdej zachowuje aktualny filtr profesji), formularz filtra profesji, tabela `wp-list-table`.

**Zakładek jest dokładnie trzy** — oczekujące, zatwierdzone, odrzucone — mimo że `counts()` zwraca cztery klucze. `email_unconfirmed` nie jest wnioskiem: redakcja nie została o nim powiadomiona, `StatusTransition::decidable()` go nie dopuszcza, a cron go po siedmiu dniach usuwa. Czwarty licznik istnieje, bo repozytorium nie ma prawa przemilczeć stanu, który w bazie jest; to ekran decyduje, których nie pokazuje. Kolumny w kolejności ze specyfikacji: użytkownik z adresem e-mail, profesja, numer uprawnień z werdyktem i odnośnikiem do rejestru, data rejestracji, akcje.

Nazwa kolumny daty to **„Data rejestracji"**, nie „Data zgłoszenia": dostępna wartość to `user_registered`, a konto potwierdzone dwa miesiące po rejestracji ma datę rejestracji, nie zgłoszenia. Nazwa ma mówić, co pokazuje.

Parametry żądania przez `absint()`/`sanitize_key()`, każde wyjście przez `esc_html()`/`esc_url()`/`esc_attr()`. Kolumna akcji na razie wypisuje odnośnik „Szczegóły" (`detail_url()`); przyciski rozstrzygnięcia dokłada Zadanie 7.

- [ ] **Krok 5: Przepnij `{link_wniosku}`**

W `PlaceholderFactory` zamień `admin_url( 'user-edit.php?user_id=' . $userId )` na `ApplicationsScreen::detail_url( $userId )`.

- [ ] **Krok 6: Zarejestruj ekran w `Plugin::boot()`**

`ApplicationsScreen::register()` **poza** listą `pusers_register_features`, z komentarzem powtarzającym uzasadnienie z nagłówka tego zadania (następny czytelnik zobaczy ekran poza bramką i zapyta dlaczego).

- [ ] **Krok 7: Uruchom testy i potwierdź przejście**

- [ ] **Krok 8: Bramki i commit**

```bash
git add src/Admin/ApplicationsScreen.php src/Admin/VerdictLabels.php src/Admin/Menu.php src/Plugin.php src/Mail/PlaceholderFactory.php tests/Integration/Admin/ApplicationsScreenTest.php tests/Integration/Mail/PlaceholderFactoryTest.php tests/Integration/WiringTest.php
git commit -m "feat: lista wnioskow z zakladkami, licznikami, filtrem profesji i werdyktem numeru"
```

---

### Zadanie 5: Panel szczegółów, tylko do czytania

**Pliki:**
- Utwórz: `src/Admin/ApplicationDetails.php`
- Modyfikuj: `src/Admin/ApplicationsScreen.php` (`render()` rozgałęzia się na widok szczegółów, gdy `ARG_USER` jest w żądaniu)
- Test: `tests/Integration/Admin/ApplicationDetailsTest.php`

**Interfejsy:**
- Konsumuje: `ApplicationRow`, `FieldMatrix::visibleFields( string $professionKey ): array`, `FieldMatrixRepository::get()`, `AcfFieldSource::fields(): array<string, string>` (nazwa pola → etykieta), `SettingsRepository::get()` (`$acfGroupKey`), `UserMeta::{TERMS_VERSION, CONSENT_AT, REGISTRATION_IP, REJECTION_REASON}`, `VerdictLabels`, `RegistryLink`.
- Produkuje: `ApplicationDetails::render( ApplicationRow $row ): void`.

**Uwaga o źródle prawdy pól:** widoczność bierze się z macierzy (`visibleFields()`), nie z tego, co leży w metadanych. Pole ukryte dla profesji **nie jest pokazywane, nawet gdy metadana istnieje** — macierz jest fail-closed i tak samo zachowuje się front oraz walidacja; panel nie może być trzecim, luźniejszym zdaniem.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_pokazuje_pola_widoczne_dla_profesji_z_etykietami_acf(): void
public function test_nie_pokazuje_pola_ukrytego_dla_profesji_nawet_gdy_metadana_istnieje(): void
public function test_pole_wielowartosciowe_schodzi_do_czytelnej_listy(): void
// Metadana z tablicą ('Szpital','Przychodnia') → tekst z oboma wartościami, nigdy 'a:2:{i:0;s:7:...'.
// Ten defekt wyszedł w Planie 5 (R34): odczyt zbiorczy metadanych NIE odserializowuje wartości.
public function test_pokazuje_znacznik_czasu_zgody_i_wersje_regulaminu(): void
public function test_pokazuje_adres_ip_rejestracji(): void
public function test_brak_zgody_i_brak_ip_nie_daja_pustych_wierszy_bez_wyjasnienia(): void
// Konto bez CONSENT_AT → tekst „nie zapisano", nie pusty wiersz.
public function test_pokazuje_powod_odrzucenia_dla_konta_odrzuconego(): void
public function test_panel_nie_zawiera_pola_edycyjnego(): void
// Asercja, że w HTML nie ma <input type="text"> ani <textarea> z wartościami pól wniosku:
// specyfikacja mówi „tylko do czytania", a edycja należy do ekranu użytkownika WordPressa.
public function test_odnosnik_do_standardowej_edycji_uzytkownika_jest_obecny(): void
// user-edit.php?user_id=<id> — stamtąd administrator poprawia dane.
public function test_bez_acf_panel_mowi_ze_pol_nie_pokaze(): void
// AcfFieldSource bez grupy (filtr pusers_acf_fields zwraca pustkę) → komunikat zamiast cichej pustki.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `ApplicationDetails::render()`**

Wartości czytaj **po nazwie** (`get_user_meta( $id, RegistrationHandler::ACF_META_PREFIX . $name, true )`), nie z tablicy odczytu zbiorczego — zbiorcza niesie napisy prosto z bazy, bez `maybe_unserialize()`. Tablicę zwijaj do jednej linii przecinkami. Każda wartość przez `esc_html()`.

- [ ] **Krok 4: Rozgałęź `ApplicationsScreen::render()`**

Obecność `ARG_USER` w żądaniu przełącza na widok szczegółów; `ApplicationRepository::one()` zwracające `null` daje komunikat „wniosku nie ma" i odnośnik powrotu do listy, nigdy pustą stronę.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**

- [ ] **Krok 6: Bramki i commit**

```bash
git add src/Admin/ApplicationDetails.php src/Admin/ApplicationsScreen.php tests/Integration/Admin/ApplicationDetailsTest.php
git commit -m "feat: panel szczegolow wniosku tylko do czytania, ze zgoda, IP i powodem odrzucenia"
```

---

### Zadanie 6: Serwis rozstrzygnięcia

**Pliki:**
- Utwórz: `src/Account/Decision.php`
- Test: `tests/Integration/Account/DecisionTest.php`

**Interfejsy:**
- Konsumuje: `AccountStatusRepository::{get, set}`, `StatusTransition`, `Mailer::{approved, rejected}`, `EventLog::write( string $action, int $subjectUserId, int $actorUserId, array $context ): void`.
- Produkuje:
  - `Decision::{RESULT_OK = 'ok', RESULT_NOT_FOUND = 'not_found', RESULT_NO_STATUS = 'no_status', RESULT_WRONG_STATE = 'wrong_state', RESULT_UNCHANGED = 'unchanged', RESULT_EMPTY_REASON = 'empty_reason', RESULT_STORAGE_FAILED = 'storage_failed', RESULT_MAIL_FAILED = 'mail_failed'}`
  - `Decision::{LOG_APPROVED = 'application_approved', LOG_REJECTED = 'application_rejected'}`
  - `Decision::REASON_MAX = 500`
  - `Decision::approve( int $userId, int $actorId ): string`
  - `Decision::reject( int $userId, int $actorId, string $reason ): string`

`UserMeta::REJECTION_REASON` jest już zadeklarowana (Zadanie 3); to zadanie zaczyna ją wypełniać.

**Dwie kopie powodu, z rozmysłem.** Powód trafia do metadanej konta (panel szczegółów pokazuje aktualny powód bez zapytania do logu) **i** do logu zdarzeń (specyfikacja: „Powód trafia do maila i do logu"; log jest zapisem nieusuwalnym, metadana jest nadpisywana przy ponownym odrzuceniu). To nie duplikacja jednego faktu, a dwa różne fakty: „dlaczego to konto jest dziś odrzucone" i „kto, kiedy i z jakim powodem je odrzucił".

**Kolejność w `approve()`/`reject()` jest wiążąca:** sprawdź istnienie konta → odczytaj stan → `StatusTransition` → (dla odrzucenia) sprawdź powód → zapisz stan → **odczytaj zwrotnie** → zapisz do logu → wyślij maila. Mail jako ostatni, bo wiadomość „konto zatwierdzone" dla konta, które dalej nie wpuszcza, jest szkodą, a brak maila po udanym zatwierdzeniu da się naprawić. `AccountStatusRepository::set()` zwraca `void` i nie mówi nic o powodzeniu — odczyt zwrotny jest jedynym dowodem.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_zatwierdzenie_zmienia_stan_pisze_log_i_wysyla_mail(): void
// Stan approved, wiersz event_log z akcją application_approved i actor_user_id = id administratora,
// wiersz w kolejce maili rodzaju approved.

public function test_odrzucenie_zapisuje_powod_w_metadanej_i_w_logu(): void
// context logu zawiera 'reason' => 'numer nieprawidłowy'; metadana REJECTION_REASON też.
// Zobowiązanie z decyzje-plan-5.md §7: „połowa mailowa jest zrobiona, logowa nie istnieje nigdzie".

public function test_drugie_zatwierdzenie_nie_robi_nic(): void
// Dwa wywołania approve() → RESULT_OK, potem RESULT_UNCHANGED; dokładnie JEDEN wiersz logu
// i JEDEN wiersz w kolejce. Review Focus 1.

public function test_zatwierdzenie_konta_bez_potwierdzonego_adresu_jest_odmawiane(): void
// Stan email_unconfirmed → RESULT_WRONG_STATE, stan bez zmian, zero wierszy logu, zero maili.
// Review Focus 4.

public function test_konto_bez_stanu_wtyczki_nie_jest_rozstrzygane(): void
// RESULT_NO_STATUS. Administrator dodany ręcznie nie jest wnioskiem i nie wolno mu nadać stanu
// tą drogą (brama logowania wpuszcza konta bez stanu — nadanie stanu odebrałoby mu dostęp).

public function test_nieistniejacy_uzytkownik_daje_not_found_bez_wyjatku(): void

public function test_odrzucenie_bez_powodu_jest_odmawiane(): void
// '' oraz "   \n\t" → RESULT_EMPTY_REASON, stan bez zmian, zero maili. Specyfikacja: „Odrzucenie wymaga powodu".

public function test_powod_jest_obcinany_i_pozbawiany_znacznikow(): void
// Powód z '<script>' → w metadanej i w logu bez znacznika; powód 600 znaków → 500.
// Review Focus 3. Asercja na DŁUGOŚĆ, nie na samą obecność.

public function test_nieudany_zapis_stanu_nie_wysyla_maila(): void
// Filtr na get_user_metadata udaje, że stan się nie zapisał → RESULT_STORAGE_FAILED,
// zero maili w kolejce, wiersz logu o porażce. Review Focus 2.

public function test_odmowa_kolejki_mailowej_nie_cofa_stanu(): void
// Mailer zwraca false → RESULT_MAIL_FAILED, ale stan ZOSTAJE zmieniony: stan jest prawdą,
// mail jest tylko powiadomieniem. Ekran powie o tym redakcji.

public function test_odrzucenie_konta_zatwierdzonego_jest_dozwolone(): void
// approved → rejected: redakcja może cofnąć zatwierdzenie; sesje gasi SessionGuard
// (podpięty pod pusers_account_status_changed), bez udziału tego serwisu.

public function test_profesja_usunieta_z_ustawien_nie_blokuje_rozstrzygniecia(): void
// Review Focus 3.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `Decision`**

Powód przez `sanitize_textarea_field()`, potem `trim()`, potem obcięcie do `REASON_MAX` przez `mb_substr()`.

**Pułapka z Planu 5:** `sanitize_textarea_field( 'Wiek < 30 lat' )` zwraca `Wiek &lt; 30 lat`, bo rdzeń woła `wp_pre_kses_less_than()` przed `wp_strip_all_tags()`. Dla powodu odrzucenia to zachowanie **akceptowane** (mail i panel i tak eskejpują wyjście), ale test ma je przypinać asercją, żeby nikt nie „naprawiał" tego później zmianą sanityzacji.

- [ ] **Krok 4: Uruchom testy i potwierdź przejście**

- [ ] **Krok 5: Bramki i commit**

```bash
git add src/Account/Decision.php tests/Integration/Account/DecisionTest.php
git commit -m "feat: serwis rozstrzygniecia wniosku - stan sprawdzany odczytem zwrotnym, powod do logu i metadanej"
```

---

### Zadanie 7: Akcje w panelu — pojedyncze i masowe, z powodem odrzucenia

**Pliki:**
- Modyfikuj: `src/Admin/ApplicationsScreen.php`
- Modyfikuj: `src/Admin/ApplicationDetails.php` (przyciski rozstrzygnięcia w widoku szczegółów)
- Test: `tests/Integration/Admin/ApplicationsScreenTest.php`

**Interfejsy:**
- Konsumuje: `Decision`, `Request::{guard, redirect, notice, NONCE_FIELD}`, `Settings::$rejectionReasons`, `SettingsRepository::get()`.
- Produkuje:
  - `ApplicationsScreen::ACTION_DECIDE = 'pusers_decide_applications'`
  - `ApplicationsScreen::{FIELD_USERS = 'pusers_users', FIELD_DECISION = 'pusers_decision', FIELD_REASON = 'pusers_reason', FIELD_REASON_PRESET = 'pusers_reason_preset'}`
  - `ApplicationsScreen::{DECIDE_APPROVE = 'approve', DECIDE_REJECT = 'reject'}`
  - `ApplicationsScreen::{NOTICE_DECIDED = 'decided', NOTICE_NEEDS_REASON = 'needs_reason', NOTICE_NOTHING_SELECTED = 'nothing_selected'}`
  - `ApplicationsScreen::handle_decide(): void` (podpięta pod `admin_post_pusers_decide_applications`)

**Powód przy akcji masowej.** Jeden powód na całą partię: pole powodu stoi nad tabelą i jest używane wyłącznie dla decyzji „odrzuć". Lista gotowych powodów pochodzi z `Settings::$rejectionReasons` (pole istnieje od Planu 2); wybór z listy wypełnia pole tekstowe, a administrator może je nadpisać. Odrzucenie bez powodu nie jedzie do serwisu wcale — ekran odbija je komunikatem, zanim cokolwiek się stanie.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_zatwierdzenie_pojedynczego_wniosku_z_listy(): void
public function test_akcja_masowa_zatwierdza_wszystkie_zaznaczone(): void
public function test_akcja_masowa_odrzuca_z_jednym_powodem_dla_calej_partii(): void

public function test_odrzucenie_bez_powodu_nie_dotyka_zadnego_konta(): void
// Trzy zaznaczone konta, puste pole powodu → wszystkie trzy dalej pending, komunikat NOTICE_NEEDS_REASON.
// Asercja na STAN KONT, nie tylko na komunikat.

public function test_powod_z_listy_gotowych_jest_akceptowany(): void
// Wybór presetu bez wpisywania własnego tekstu działa.

public function test_konto_usuniete_miedzy_renderem_a_akcja_jest_pomijane(): void
// Dwa zaznaczone, jedno usunięte → drugie rozstrzygnięte, komunikat niesie liczbę pominiętych.
// Review Focus 5.

public function test_konto_w_zlym_stanie_w_partii_nie_blokuje_pozostalych(): void
// Jedno email_unconfirmed w partii trzech → dwa rozstrzygnięte, jedno zgłoszone jako pominięte.

public function test_akcja_bez_nonce_jest_odrzucana(): void
public function test_akcja_bez_uprawnienia_jest_odrzucana(): void
// Rola editor → odmowa, zero zmian stanu.

public function test_pusty_wybor_daje_komunikat_nie_bialy_ekran(): void
public function test_identyfikatory_z_zadania_sa_oczyszczane(): void
// FIELD_USERS z 'abc', '0', '-5' → brak rozstrzygnięć, brak błędu PHP.
public function test_komunikat_mowi_gdy_mail_nie_wyszedl(): void
// Decision zwraca RESULT_MAIL_FAILED → komunikat osobny od sukcesu; konto jednak zatwierdzone.
// Plan 5 nauczył, że mail, który nie wyszedł po cichu, jest najgorszym wariantem.
public function test_po_akcji_nastepuje_przekierowanie_z_zachowaniem_zakladki_i_filtra(): void

public function test_wiersz_logu_niesie_identyfikator_zalogowanego_administratora(): void
// actor_user_id === id administratora, który kliknął, nigdy 0. Bez tego log nie odpowiada
// na pytanie „kto zatwierdził", czyli na jedyne, po co istnieje.
```

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `handle_decide()`**

`Request::guard( self::ACTION_DECIDE )` na wejściu. Identyfikatory przez `array_map( 'absint', … )` z odrzuceniem zer. Aktorem przekazywanym do `Decision` jest `get_current_user_id()` — **nigdy zero**: cała wartość wiersza logu `application_approved` polega na tym, że wykazuje, **kto** zatwierdził, a tego specyfikacja żąda wprost („Log zdarzeń — kto zatwierdził, kiedy, kto odrzucił i z jakim powodem"). Jeden z testów niżej przypina identyfikator aktora. Wynik każdego wywołania `Decision` zliczany po kodzie; przekierowanie przez `Request::redirect()` z licznikami w argumentach i zachowaniem `ARG_STATUS`/`ARG_PROFESSION`.

- [ ] **Krok 4: Dołóż formularz i przyciski**

Tabela dostaje pola wyboru i wybór decyzji; nad tabelą pole powodu (lista gotowych + tekst). W widoku szczegółów dwa przyciski na jedno konto, z tym samym nonce i tym samym punktem wejścia. Komunikaty z `Request::notice()` renderowane u góry ekranu.

- [ ] **Krok 5: Uruchom testy i potwierdź przejście**

- [ ] **Krok 6: Bramki i commit**

```bash
git add src/Admin/ApplicationsScreen.php src/Admin/ApplicationDetails.php tests/Integration/Admin/ApplicationsScreenTest.php
git commit -m "feat: zatwierdzanie i odrzucanie wnioskow, pojedynczo i masowo, z wymaganym powodem"
```

---

### Zadanie 8: Most ACF → metadane wtyczki i audyt zmiany numeru

**Pliki:**
- Utwórz: `src/Admin/ProfileSync.php`
- Modyfikuj: `src/Plugin.php` (rejestracja za bramką zależności)
- Modyfikuj: `docs/superpowers/backlog.md`
- Test: `tests/Integration/Admin/ProfileSyncTest.php`
- Test: `tests/Integration/WiringTest.php`

**Dlaczego to zadanie istnieje.** Specyfikacja mówi: „Edycja danych odbywa się na standardowym ekranie edycji użytkownika WordPressa, gdzie ACF renderuje te same pola". **Dziś te dwa miejsca nie dzielą danych.** Rejestracja zapisuje wartości jako `pusers_acf_<nazwa>` (przedrostek z Planu 3, broni przed kolizją z metadanymi rdzenia), a ACF na ekranie użytkownika zapisuje do swoich nazw: `<nazwa>` i `_<nazwa>`. Administrator poprawia numer PWZ na `user-edit.php`, a panel wniosków, mail do redakcji i przyszły eksport CSV **do końca świata pokazują wartość z rejestracji**. Most jest jednokierunkowy: ACF → metadane wtyczki.

Tą samą drogą realizuje się drugie zobowiązanie specyfikacji: „Zmiana numeru uprawnień przez administratora odnotowywana jest w logu".

**Interfejsy:**
- Konsumuje: `AcfFieldSource::fields()` (konstruktor bierze `string $groupKey`, czyli `SettingsRepository::get()->acfGroupKey`), `FieldMatrixRepository::get()`, `FieldMatrix::visibleFields()`, `UserMeta::PROFESSION`, `RegistrationHandler::ACF_META_PREFIX`, `ProfessionRepository::get()`, `EventLog::write()`.
- Produkuje:
  - `ProfileSync::{register(), on_acf_save( mixed $postId ): void}`
  - `ProfileSync::LOG_CREDENTIAL_CHANGED = 'credential_changed'`

**Szew i jego kolejność.** Hook `acf/save_post` z priorytetem **20**; ACF zapisuje na 10, więc wcześniejszy priorytet czytałby wartości sprzed zapisu. `profile_update` **nie nadaje się**: odpala się w `wp_update_user()`, czyli przed `edit_user_profile_update`, pod którym ACF dopiero zapisuje. W kontenerze testowym ACF Pro nie ma — test odpala hook sam (`do_action( 'acf/save_post', 'user_7' )`) po ustawieniu nieprzedrostkowanych metadanych, czyli dokładnie w stanie, jaki zostawia ACF.

- [ ] **Krok 1: Napisz testy integracyjne**

```php
public function test_zapis_acf_przenosi_wartosci_do_metadanych_wtyczki(): void
// Metadana 'pwz' = '5425740' + hook → 'pusers_acf_pwz' === '5425740'.

public function test_pole_ukryte_dla_profesji_nie_jest_przenoszone(): void
// Macierz jest fail-closed i tu także: wartość pola ukrytego nie wchodzi do metadanych wtyczki,
// nawet gdy ACF ją zapisał.

public function test_pole_spoza_grupy_rejestracji_nie_jest_przenoszone(): void

public function test_zmiana_numeru_uprawnien_trafia_do_logu_z_wartoscia_stara_i_nowa(): void
// Akcja credential_changed, actor_user_id = id administratora, context z 'old' i 'new'.

public function test_zapis_bez_zmiany_numeru_nie_pisze_do_logu(): void
// Ta sama wartość → zero nowych wierszy. Log, który rośnie przy każdym otwarciu profilu, jest bezużyteczny.

public function test_identyfikator_inny_niz_uzytkownik_jest_ignorowany(): void
// 'post_15', 'options', 42 → brak zapisów, brak błędu.

public function test_wartosc_wielowartosciowa_przenosi_sie_jako_lista(): void
// Tablica zostaje tablicą, nie napisem serializowanym.

public function test_wartosci_sa_sanityzowane_przy_przenoszeniu(): void
// '<script>alert(1)</script>' nie dojeżdża do metadanej wtyczki ze znacznikiem.

public function test_konto_bez_profesji_nie_jest_synchronizowane(): void
// Brak UserMeta::PROFESSION → nic się nie dzieje (nie ma macierzy, po której orzekać o widoczności).
```

W `tests/Integration/WiringTest.php`: `ProfileSync::register()` wisi na `pusers_register_features` (w przeciwieństwie do ekranu wniosków — bez ACF nie ma czego synchronizować).

- [ ] **Krok 2: Uruchom testy i potwierdź porażkę**

- [ ] **Krok 3: Zaimplementuj `ProfileSync`**

`on_acf_save()`: przyjmij tylko napis pasujący do `user_<cyfry>`; wyciągnij identyfikator; przerwij, gdy konto nie ma profesji. Pola do przeniesienia to przecięcie `AcfFieldSource::fields()` z `FieldMatrix::visibleFields( $professionKey )`. Wartość czytaj z nieprzedrostkowanej metadanej (`get_user_meta( $id, $name, true )`), sanityzuj (`sanitize_text_field()` dla skalarów, `array_map()` po tablicy) i zapisz pod `ACF_META_PREFIX . $name`. Dla pola numeru uprawnień profesji porównaj wartość starą z nową przed zapisem i przy różnicy napisz wiersz logu z `get_current_user_id()` jako aktorem.

- [ ] **Krok 4: Zarejestruj w `Plugin::boot()`**

- [ ] **Krok 5: Dopisz pozycje do backlogu**

Trzy wpisy, każdy z nazwą pliku albo stałej:

1. **Plan 7, kasowanie danych osobowych:** eraser musi czyścić `UserMeta::REGISTRATION_IP` i `UserMeta::REJECTION_REASON` — oba powstały w tym planie i oba są danymi osobowymi przy koncie. Wiersze `credential_changed` w logu zdarzeń niosą numer uprawnień w `context` (stary i nowy) i podlegają anonimizacji logu, nie usunięciu.
2. **Przycisk zdejmujący blokadę limitu prób** (specyfikacja, „Odporność na boty"; Plan 4 przypisał go Planowi 6, ten plan świadomie go nie robi). Potrzebny rejestr kubełków: `LoginHandler::{BUCKET_IP, BUCKET_USER, BUCKET_IDENTITY}`, `PasswordReset::{BUCKET_IP, BUCKET_EMAIL, BUCKET_SET_IP, BUCKET_SET_USER, BUCKET_LOG_IP}`, `Confirmation::BUCKET_RESEND_IP` (prywatna), `PasswordSetup::{BUCKET_SETPASS_IP, BUCKET_SETPASS_USER}` (prywatne), `Mailer::BUCKET_MAIL_TO` (prywatna). Zdejmowanie blokady z IP nie ma gdzie wisieć — żaden ekran nie ma wiersza na adres.
3. **Data na liście to data rejestracji, nie zgłoszenia** (Zadanie 4). Moment przejścia w `pending` nie jest nigdzie zapisany; gdyby redakcja potrzebowała kolejki po dacie złożenia wniosku, trzeba dodać metadaną ustawianą przy potwierdzeniu adresu.

- [ ] **Krok 6: Uruchom testy i potwierdź przejście**

- [ ] **Krok 7: Bramki i commit**

```bash
git add src/Admin/ProfileSync.php src/Plugin.php docs/superpowers/backlog.md tests/Integration/Admin/ProfileSyncTest.php tests/Integration/WiringTest.php
git commit -m "feat: most ACF do metadanych wtyczki oraz audyt zmiany numeru uprawnien"
```

---

## Czego ten plan świadomie nie rozstrzyga

- **Eksport CSV** (specyfikacja, „Pozostałe funkcje pierwszej wersji") — Plan 7, razem z eksporterem i eraserem danych osobowych; dzieli z nimi neutralizację CSV injection i mapowanie pól.
- **Backfill stanów przy aktywacji** i import ze starej wtyczki — Plan 7.
- **Sortowanie kolumn** listy. Specyfikacja wymaga filtra po profesji i zakładek, nie sortowania. Kolejność jest jedna: najnowsze na górze.
- **Stronicowanie panelu szczegółów** („następny wniosek") — wygoda, nie wymóg.
