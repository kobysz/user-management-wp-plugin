# Backlog — wymogi przeniesione z Planu 1

Ten dokument przenosi zobowiązania, które powstały w trakcie realizacji Planu 1 i muszą być spełnione w kolejnych planach. Każda pozycja ma powód — bez niego łatwo ją zakwalifikować jako kosmetykę i pominąć.

Źródło: recenzje zadaniowe i recenzja całej gałęzi Planu 1, 2026-10-26.

---

## Plan 2 — Ustawienia w panelu

### Typ numeru uprawnień jako lista wyboru, nigdy pole tekstowe

`Profession::fromArray()` degraduje nierozpoznany `credential_type` do `CredentialType::None`. Jest to zamierzone (zepsuta albo starsza konfiguracja nie może zabić rejestracji), ale ma skutek: **literówka cicho zdejmuje wymóg numeru**. Wartość `physician_pw` zamiast `physician_pwz` sprawia, że lekarz zarejestruje się bez weryfikacji numeru PWZ, bez błędu i bez ostrzeżenia dla redakcji.

Ekran ustawień musi podawać typ numeru jako zamkniętą listę wyboru. To usuwa ryzyko u źródła.

### Walidacja spójności profesja ↔ macierz przy zapisie ustawień

W Planie 1 nie istnieje żadna warstwa sprawdzająca, czy dwie konfiguracje — lista profesji i macierz profesja × pole — mówią to samo. Domena z założenia przyjmuje każdą tablicę, jaką dostanie. Dwa rozjazdy prowadzą do cichego wyłączenia weryfikacji:

1. **Profesja ma typ numeru, a jej pole numeru jest w macierzy ukryte albo nieustawione.** `SubmissionValidator` czyta numer z wartości **po odsiewie**, więc zgłoszenie przejdzie całkowicie bez numeru — bez błędu i bez ostrzeżenia.
2. **Profesja istnieje w kolekcji, ale nie ma wiersza w macierzy.** `requiredFields()` zwraca pustą listę, `strip()` odsiewa wszystko, a zgłoszenie jest „poprawne" z pustymi wartościami.

Zapis ustawień musi odrzucić albo wyraźnie oznaczyć oba przypadki.

Uwaga: `Profession::requiresCredential()` istnieje, ale **nie jest konsultowane w walidacji** — o wymagalności decyduje wyłącznie macierz. To świadomy wybór (macierz jest jednym źródłem prawdy), ale oznacza, że spójność musi pilnować warstwa konfiguracji.

### Pola obecne w ACF, a nieobecne w macierzy — pokazywane jako „nieskonfigurowane"

`SubmissionFilter::strip()` odsiewa pola nieobecne w macierzy, nie tylko jawnie ukryte. Administrator, który doda pole w ACF i zapomni ustawić je w macierzy, straci wpisaną przez użytkownika wartość **bez śladu**.

Edytor macierzy musi wyliczać wszystkie pola z grupy ACF i oznaczać te bez wpisu ostrzeżeniem.

W domenie jawny `Hidden` i domyślny `Hidden` są dziś nieodróżnialne. Gdyby rozróżnienie było potrzebne, właściwym miejscem jest `FieldMatrix::isConfigured( string $profession, string $field ): bool`. **Nie dodawać jej, dopóki Plan 2 albo 3 faktycznie jej nie użyje.**

### `uninstall.php` i bramka usuwania danych

Domyślnie wtyczka nie kasuje danych przy usunięciu. Kasowanie wyłącznie po jawnym włączeniu opcji albo ustawieniu stałej — wzorzec z `event-registration`. Opcja należy do ekranu ustawień, więc powstaje razem z nim.

---

## Plan 3 — Rejestracja na froncie

### Odznaczone pole logiczne musi wysyłać wartość pustą, nie `'0'`

**To najważniejsza pozycja w tym dokumencie.**

`SubmissionValidator::isEmpty()` traktuje jako brak wartości wyłącznie: pustą tablicę, tablicę z pustymi stringami, pusty string, same białe znaki i `null`. Wartości fałszywe — `'0'`, `0`, `0.0`, `false` — są **wartością obecną** i spełniają `required`. Jest to zamierzone, bo `0` jest legalną wartością liczbową (pole „lata praktyki" z wartością 0), i jest przypięte testem.

Problem: pole ACF typu `true_false` renderuje ukryty input z `value="0"` plus checkbox z `value="1"`. Przy domyślnym renderze ACF **odznaczona wymagana zgoda spełni `required`** — a zgoda jest jedynym polem, dla którego specyfikacja wymaga znacznika czasu i wersji regulaminu.

Warstwa formularza musi albo nie wysyłać ukrytego zera, albo mapować je na wartość pustą przed walidacją. Jeśli Plan 3 użyje `acf_render_field()` bez tej korekty, wymóg pęknie po cichu.

### Formularz nie może twardo wymagać pola, którego nie renderuje

`FieldMatrix::requiredFields( $profesja )` zwraca wszystko, co ma w macierzy stan `required` — także pole, którego **nie ma już w wybranej grupie ACF**. Taki wpis powstaje bez złej woli: administrator ma otwarty ekran macierzy, w tym czasie ktoś zmienia nazwę pola w ACF albo przełącza grupę, administrator zapisuje, a macierz dostaje `required` dla pola, którego formularz nigdy nie wyrenderuje. `SubmissionValidator` zgłasza wtedy twardy błąd `required` dla niewidocznego pola i **rejestracja całej profesji staje się niemożliwa** — dokładnie ten anty-cel, który specyfikacja nazywa najważniejszym („lekarz z prawdziwym numerem nie może się zarejestrować").

Ekran macierzy odcina drogę zapisu (Plan 2 nie przyjmuje kluczy pól spoza źródła, gdy źródło da się przeczytać), a `ConsistencyChecker` zgłasza taki wpis jako `matrix_field_not_in_source`. Nie zamyka to jednak przypadku, w którym wpis już w macierzy jest, a grupa zmieniła się później. Dlatego **warstwa formularza musi przeciąć ten stan sama**: pole wymagane, którego formularz nie renderuje, nie może blokować zgłoszenia. Dwa dopuszczalne wyjścia — pominąć takie pole przy walidacji (zgłoszenie przechodzi, redakcja weryfikuje) albo wyrenderować je, skoro jest wymagane. Czego **nie** wolno: zostawić twardego `required` dla pola, którego nie ma w formularzu.

### Sanityzacja wartości przy zapisie do metadanych jest obowiązkowa

`SubmissionResult::values()` może zawierać wartości nietekstowe. Tablica w polu numeru zostaje bez zmian — świadomie, bo zerowanie kasowałoby dowód tego, co użytkownik wpisał. Bez sanityzacji taka wartość wejdzie do metadanych zserializowana, a każdy późniejszy konsument deklarujący `string` przy `strict_types` wywali `TypeError`.

### Komunikat `required` wyklucza ostrzeżenie `empty`

Gdy `errorFor( pole ) === 'required'`, interfejs **nie** pokazuje osobno ostrzeżenia `empty` — to ten sam fakt opisany dwa razy. Takie zgłoszenie i tak nie dociera do redakcji, bo `isValid()` jest fałszywe.

### Wtyczka nie jest auto-aktywowana w środowisku dev

`.wp-env.json` montuje wtyczkę przez `mappings` z pustym `plugins`, bo katalog repozytorium (`wp-plugin-user-management`) nie pokrywa się ze slugiem (`portal-users`). Przy pierwszym ręcznym teście na dev (port 8893) trzeba ją włączyć ręcznie.

---

## Plany 3 i 4 — granulacja limitu prób

**Plan 2 zrealizował ten wymóg tylko częściowo i nie zapisał tego nigdzie — stąd ta pozycja.**

Specyfikacja wymaga liczenia prób **per adres IP i per konto, osobno dla rejestracji, logowania i odzyskiwania hasła, z progami konfigurowalnymi**, i nazywa anty-celem odcięcie szpitala siedzącego za jednym adresem wyjściowym. Ustawienia z Planu 2 mają natomiast **jeden** próg `rate_limit_per_hour` z etykietą „Limit zgłoszeń z jednego adresu na godzinę" (`src/Admin/SettingsScreen.php`), czyli jedną granulację: per IP, wspólną dla wszystkich trzech ścieżek.

Nie jest to mina — `Settings::fromArray()` dopełnia brakujące klucze domyślnymi, więc dosypanie kolejnych progów jest zmianą addytywną i nie poruszy zapisanych konfiguracji. Ale ścieżka, która wprowadzi własne liczenie prób, musi **dodać brakujące progi do ustawień**, a nie oprzeć się na tym jednym: pojedynczy licznik per IP przy rejestracji z jednego szpitala zadziała dokładnie jak anty-cel.

## Plan 4 — Logowanie

### Brama logowania NIE ISTNIEJE i do jej powstania wtyczka nie egzekwuje stanu konta

> **ZAMKNIĘTE w Planie 4** (scalonym do `main`). Bramę egzekwuje `src/Front/LoginGateway.php`, podpięta poza bramką zależności ACF; `src/Front/SessionGuard.php` gasi sesje przy przejściu w stan nie-zatwierdzony, a `src/Front/RestUsers.php` zamyka ujawnianie kont przez REST. Zapis niżej zostaje jako opis wymogów, z których Plan 4 był rozliczony — nie jako stan dzisiejszy.

**To była najpoważniejsza otwarta pozycja po Planie 3.** `LoginGate::decide()` istnieje od Planu 1, ma testy i **nie ma ani jednego konsumenta** — w `src/` nie ma żadnego podpięcia pod `authenticate` ani `wp_authenticate`. Skutek: konto utworzone w stanie `email_unconfirmed` **loguje się normalnie**, czyli specyfikacja („logowanie wpuszcza wyłącznie `zatwierdzony`") nie jest po Planie 3 egzekwowana w ogóle.

Drugi, poważniejszy skutek: **to przewraca całą pracę Planów 3 nad nieujawnianiem kont.** Plan 3 zamknął enumerację w formularzu rejestracji (zgłoszenie na istniejący adres zwraca dokładnie odpowiedź przyjętego), ale atakujący omija to dwoma żądaniami: wysyła zgłoszenie na adres kandydata z **własnym** hasłem, potem próbuje się zalogować. Wejście znaczy „adres był wolny", komunikat o błędnym haśle znaczy „adres jest zajęty". Dwa żądania, ustawienia domyślne, pewność całkowita.

Plan 4 ma więc dwa wymogi, oba wiążące:

1. **Podpiąć bramę** i odmawiać wejścia kontu bez stanu `zatwierdzony`.
2. **Odmowa dla `email_unconfirmed` musi brzmieć IDENTYCZNIE jak błędne hasło.** Inaczej brama sama staje się sondą: „to konto czeka na potwierdzenie adresu" mówi atakującemu, że adres jest zajęty. Rozróżnienie komunikatów, którego chce specyfikacja („każdy inny stan daje własny komunikat, żeby użytkownik wiedział, czy ma szukać maila"), jest **bezpieczne tylko dla stanów po potwierdzeniu adresu** — czyli `oczekujący`, `zatwierdzony`, `odrzucony`. Dla `niepotwierdzony_mail` własny komunikat ujawnia istnienie konta komuś, kto hasła nie zna.

### Punkt końcowy REST `wp/v2/users` ujawnia powstanie konta

Niezależnie od formularza i bramy: rdzeń WordPressa odpowiada na `/wp-json/wp/v2/users/<id>` kodem `rest_user_invalid_id` (404) dla nieistniejącego identyfikatora i `rest_user_cannot_view` (403) dla istniejącego, więc „czy pojawił się nowy wiersz użytkownika" czyta się **bez uwierzytelnienia**. Dla portalu medycznego to ta sama własność, którą Plan 3 zamykał w formularzu. Zamknąć ten punkt dla anonima (albo zawęzić do kont z opublikowanymi wpisami) razem z bramą logowania, bo osobno jedno bez drugiego niczego nie daje.

### Adapter stanu konta MUSI odróżnić brak klucza od wartości nierozpoznanej

`AccountStatus::tryFromStored()` zwraca `null` zarówno dla brakującego klucza, jak i dla wartości nierozpoznanej (`'Approved'`, `'Pending'`, `' pending'` — enum jest wrażliwy na wielkość liter i nie przycina spacji). `LoginGate::decide( null )` **wpuszcza**, bo konto bez stanu to konto założone ręcznie w panelu, a brama nie może zamknąć administratora poza portalem.

Złożenie obu zachowań daje fail-open: zepsuta wartość w metadanych — po ręcznej edycji bazy albo nieudanej migracji — wpuści konto, które powinno być zablokowane.

Rozróżnienie jest faktem warstwy przechowywania, nie domeny: `tryFromStored( ?string ): ?self` nie ma czym go wyrazić, bo nie wie, czy klucz istniał. Adapter czytający metadane musi:

- brak klucza → brak stanu → wpuszczamy,
- klucz obecny, wartość nierozpoznana → **odmowa** plus wpis do logu zdarzeń, nigdy „brak stanu".

Zachowanie domeny jest przypięte testem `LoginGateTest::test_unrecognised_stored_value_is_allowed_in_by_deliberate_decision`, żeby nikt go nie „naprawił" w drugą stronę bez zmiany adaptera.

**Do rozważenia przy projektowaniu Planu 4:** czy `LoginGate::decide()` nie powinno przyjmować trzeciego stanu („wartość obecna, nierozpoznana"), żeby odmowa i wpis do logu były decyzją domeny, a nie dyscypliną adaptera. Wymagałoby to rozszerzenia sygnatury `tryFromStored()`.

**Stan po Planie 3:** rozróżnienie jest już **obserwowalne** — `AccountStatusRepository::isUnrecognised( int $userId ): bool` zwraca prawdę, gdy metadana jest obecna i niepusta, ale nierozpoznana (także gdy nie jest tekstem), a fałsz przy braku metadanej i przy wartości pustej. Plan 3 celowo **nie** wykonuje odmowy ani wpisu do logu, bo brama logowania nie należy do niego. Plan 4 ma obowiązek tę metodę skonsultować **przed** wywołaniem `LoginGate::decide()` i przy prawdzie odmówić wejścia oraz zapisać wpis do logu zdarzeń — przekazanie w tej sytuacji `null` do bramy wpuszcza konto, które powinno być zablokowane. Pierwsza wersja briefu Taska 1 w Planie 3 kazała zlepić oba stany w `null` i wykonawca to wyłapał; ta pozycja backlogu była jedynym miejscem, które o wymogu pamiętało.

---

## Plan 7 — Eksport i RODO: co zostało po realizacji

Plan 7 zrobił eksport CSV, eksporter i eraser danych osobowych oraz anonimizację logu zdarzeń i kolejki maili. Import i backfill stanów przeszły do Planu 8 (osobna sekcja niżej), bo dzielą mapowanie metadanych starej wtyczki. Zostaje tu jedna pozycja, która czeka na decyzję właściciela.

### Wiersz kolejki trzyma żywy sekret jednorazowy — decyzja o retencji należy do właściciela

Snapshot rodzaju `confirmation` zawiera **token potwierdzenia adresu ważny 48 godzin**, a snapshot `password_reset` — **klucz resetu hasła wygenerowany przez rdzeń**. Oba leżą w tabeli z oknem retencji 90 dni i oba są czytelne w panelu na ekranie logu wysyłek. Jest to nieunikniona konsekwencja wzorca „render treści przy zapisie", którego żąda specyfikacja, i **nie jest** eskalacją uprawnień: każdy, kto widzi ten ekran, ma już `manage_options`. Nie została natomiast nigdzie zapisana decyzja o danych **w spoczynku**: kopia bazy zrobiona w okresie ważności tokenu daje działające linki do przejęcia konta.

Do rozstrzygnięcia przez właściciela, nie przez wykonawcę: czy te dwa rodzaje maili mają mieć okno retencji liczone w **dniach** zamiast w 90 dniach — albo czy po wysyłce ma być czyszczona sama kolumna `body`? Pierwsze jest tańsze, drugie zostawia dłuższy ślad „czy ten mail wyszedł". Dopóki odpowiedzi nie ma, obowiązuje 90 dni dla wszystkich rodzajów.

---

## Plan 8 — Import i backfill stanów

Ta sekcja jest nowa: import z poprzedniej wtyczki i backfill stanów przy aktywacji wydzielono z Planu 7 (decyzja właściciela z 2026-10-29), a dawny Plan 8 (Aktualizacje i release) stał się Planem 9.

### Backfill stanów przy aktywacji

Konta istniejące na portalu przed wdrożeniem nie mają stanu. Dziś nie powoduje to utraty dostępu, bo brama logowania nie jest podłączona (Plan 4), ale po jej podłączeniu backfill musi już działać.

### Eksport CSV i eksporter danych osobowych widzą tylko pola z grupy ACF

Oba czytają pola przez grupę ACF wskazaną w ustawieniach (`AcfFieldSource`) i metadane z przedrostkiem `UserMeta::ACF_PREFIX`. Import, który wpisze wartości pod metadane spoza grupy albo bez przedrostka, będzie dla obu **niewidoczny**: eksport CSV nie da ich w kolumnach, a eksporter i eraser danych osobowych ich nie wydadzą ani nie skasują. Import musi zapisywać wartości pod `UserMeta::ACF_PREFIX . <nazwa pola z grupy>` albo rozszerzyć oba narzędzia razem z importem.

---

## Plan 9 — Aktualizacje i release

Numer zmieniony: dawniej Plan 8; import i backfill stanów zajęły Plan 8 (zob. wyżej).

### CI woła `composer analyse`, nie gołe `phpstan analyse`

PHPStan na domyślnym limicie 128 MB pada na równoległym workerze. Limit nie istnieje jako parametr neona w PHPStanie 1.x — sprawdzone, `parameters.memoryLimit` daje `Invalid configuration`. Działa wyłącznie jako flaga CLI; `composer.json` ma skrypt `analyse` z `--memory-limit=2G`.

### Zgodność formatu wersji w trzech miejscach

`PluginTest::test_real_header_has_semver`, regex w `Plugin::parse_version()` i guard wersji w workflow wydania muszą mówić to samo o prereleasach. Dziś regex to `^\d+\.\d+\.\d+$`, więc `0.2.0-beta1` w nagłówku zrobi test czerwonym. Rozstrzygnąć przed pierwszym wydaniem prerelease.

### Macierz wersji WordPressa w CI

Testy jadą na najnowszym WordPressie z kontenera (7.1), a minimum wtyczki to 6.5. Bez macierzy wersji albo przypięcia 6.5 nie dowiemy się, że coś wymaga nowszego API.

### Pusta suita integracyjna i kod wyjścia

Sprawdzić, czy PHPUnit kończy się kodem 0 przy pustej suicie — zanim CI zacznie na tym polegać.

---

## Zostawione przez Plan 6

### Przycisk zdejmujący blokadę limitu prób

Specyfikacja („Odporność na boty") go wymaga; Plan 4 przypisał go Planowi 6, a Plan 6 świadomie go nie robi. Potrzebny jest rejestr kubełków limitu: `LoginHandler::{BUCKET_IP, BUCKET_USER, BUCKET_IDENTITY}`, `PasswordReset::{BUCKET_IP, BUCKET_EMAIL, BUCKET_SET_IP, BUCKET_SET_USER, BUCKET_LOG_IP}`, `Confirmation::BUCKET_RESEND_IP` (stała prywatna), `PasswordSetup::{BUCKET_SETPASS_IP, BUCKET_SETPASS_USER}` (prywatne), `Mailer::BUCKET_MAIL_TO` (prywatna). Zdejmowanie blokady z adresu IP nie ma gdzie wisieć: żaden ekran nie ma wiersza na adres.

### Data na liście wniosków to data rejestracji, nie zgłoszenia

Moment przejścia konta w `pending` nie jest nigdzie zapisany. Gdyby redakcja potrzebowała kolejki po dacie złożenia wniosku, trzeba dodać metadaną ustawianą przy potwierdzeniu adresu (`Confirmation`). Zob. Zadanie 4 Planu 6.

---

## Otwarte punkty specyfikacji

Nie do rozstrzygnięcia z kodu, wymagają weryfikacji w źródłach:

- **Format numeru PWZ pielęgniarki i położnej** — osiem znaków: dwie cyfry izby (01–45), pięć cyfr, litera `P` albo `A`. Źródło nieoficjalne (biblioteka PHP `czachor/polish-id-validators`). Potwierdzić na nipip.pl.
- **Czy KIDL publikuje jakąkolwiek regułę kontrolną dla PWZDL** — dziś walidowany jest tylko format (cyfry bez zer wiodących, do 9 znaków).
- **Rozpoznanie ACF Pro** — warunki pokrywają gałęzie 5.x i 6.x, ale nie zostały zweryfikowane na realnej instalacji ACF Pro. Fałszywy negatyw oznacza wtyczkę trwale bezczynną na portalu, który ACF Pro ma. Sprawdzić przed pierwszym wdrożeniem.
- **Renderowanie pól przez ACF** (dochodzi w Planie 3) — w środowisku testowym nie ma ACF Pro, więc **cała** integracja jest sprawdzana przez filtry (`pusers_acf_groups`, `pusers_acf_fields`, `pusers_acf_field_types`) i atrapę `acf_render_field_wrap`. Testy dowodzą więc naszej strony umowy, nie zachowania ACF. Przed pierwszym wdrożeniem sprawdzić na żywym ACF Pro trzy rzeczy, w tej kolejności.

**(1) Czy w żądaniu przychodzi NAZWA pola, czy jego KLUCZ.** To jest najważniejsze i najbardziej prawdopodobne miejsce awarii. `acf_render_field_wrap()` przepuszcza pole przez `acf_prepare_field()`, a ta funkcja **przebudowuje** atrybut `name` z prefiksu i **klucza** pola (`"{$prefix}[{$key}]"`), nie z jego nazwy. Definicja, którą wtyczka podaje ACF-owi, nadal nosi `key`, więc na żywym ACF najbardziej prawdopodobny wynik to input `pusers_fields[field_abc123]`, podczas gdy `AcfFields::fromRequest()` filtruje żądanie **białą listą nazw**. Skutek: formularz wysyła wszystko, wtyczka nie czyta **nic**, i dzieje się to **bez żadnego błędu** — użytkownik widzi „pola wymagane" dla pól, które wypełnił. Jeśli przychodzi klucz, `fromRequest()` musi mapować klucz → nazwa przez definicje, a nie filtrować po nazwie.

**(2) Czy pole typu `true_false` renderuje ukryte zero** tak, jak zakłada `BooleanNormalizer`. Pomyłka tutaj znaczy odznaczoną zgodę spełniającą wymóg, czyli dokładnie awarię, przed którą normalizacja ma chronić.

**(3) Czy przełącznik `true_false` działa bez `acf_form_head()`.** `acf_render_field_wrap()` jest funkcją ekranu edycji; na froncie bez zasobów `acf/input` pole wyrenderuje się, ale może zostać bez swojego JavaScriptu.

Powód, dla którego to wszystko jest otwarte: w środowisku testowym **nie ma ACF Pro**, więc cała integracja jest sprawdzana przez filtry (`pusers_acf_groups`, `pusers_acf_fields`, `pusers_acf_field_types`) i atrapę `acf_render_field_wrap`. Testy dowodzą naszej strony umowy, nie zachowania ACF.

---

## Zaparkowane po fali poprawek Planu 1

Trzy pozycje zostały zaparkowane z rozstrzygnięciem po jedynej fali poprawek — proces nie przewiduje drugiej rundy.

### `Migrations::table_exists()` a `lower_case_table_names=1`

Porównanie wyniku `SHOW TABLES LIKE` z nazwą dosłownie. Przy `lower_case_table_names=1` (typowe na Windowsie i macOS) i prefiksie z wielkimi literami baza zwróci nazwę małymi literami, weryfikacja nigdy nie przejdzie, wersja schematu się nie zapisze — i migracja będzie się powtarzać przy każdym żądaniu.

Rdzeń WordPressa stosuje to samo porównanie, więc ryzyko jest znikome. **Wiedzieć o tym, jeśli ktoś zgłosi „migracja chodzi przy każdym żądaniu" na lokalnym środowisku.**

### Strażnik czystości domeny nie widzi wywołań przez łańcuch

Allowlista na `token_get_all()` nie wykrywa funkcji przekazanych jako łańcuch do `call_user_func` albo `array_map` (np. `array_map( 'get_option', ... )`), ani wywołań dynamicznych `$f()`. Druga, mniejsza luka: stała przed dwukropkiem zaraz po `(` albo `,` jest pomijana jak argument nazwany, więc `case WP_CONST:` w takiej pozycji przeszłoby.

W obecnej bazie kodu żadna z tych konstrukcji nie występuje. Wyłapywane są natomiast `$wpdb`, `ABSPATH`, wzmianki w komentarzach i wszystkie wywołania bezpośrednie. **Gdy `src/Domain` urośnie w Planach 2–7, warto dołożyć wykrywanie łańcuchów przekazywanych do `array_map`/`call_user_func`.**

Uwaga uboczna: lista funkcji wbudowanych zależy od rozszerzeń PHP załadowanych w kontenerze testowym. Brak rozszerzenia sprawi, że strażnik zaświeci czerwono, nie zielono — czyli myli się w bezpieczną stronę.

### Komentarz przy `phpcs:ignore` w `Plugin::parse_version()`

Wyciszenie jest uzasadnione i wąskie (jedna linia, pełny kod sniffa, powód w komentarzu): `parse_version()` musi działać także wtedy, gdy WordPress nie jest załadowany, więc `WP_Filesystem` i `get_file_data()` nie wchodzą w grę.

Sam komentarz mówi jednak, że reguła „celuje w adresy zdalne", a sniff `WordPress.WP.AlternativeFunctions` zaleca `WP_Filesystem` także dla plików lokalnych. Dokładniejsze sformułowanie: „`WP_Filesystem` niedostępny bez WordPressa". Kosmetyka.

### Brak testu „jedna tabela jest, drugiej brak"

Weryfikacja przed zapisem wersji schematu używa `&&` na obu tabelach, więc przypadek jest pokryty w kodzie, ale nie w teście.

---

## Świadomie odłożone drobiazgi

Te pozycje przeszły triage recenzji końcowej jako „mogą zostać". Spisane, żeby nie wracały jako odkrycia:

- Normalizacja numeru nie obejmuje U+2060 (word joiner), U+200E/U+200F (znaczniki kierunku), U+FF0D (pełnoszerokościowy myślnik), U+FE63, U+2043. Skutkiem jest ostrzeżenie, nie odmowa, więc cel wrogi nie jest realizowany. Jeden znak w klasie, gdy przyjdzie realny raport.
- `CredentialResult::invalid()` i `LoginDecision::deny()` przyjmują dowolny string jako kod — nic nie wiąże wyniku z zamkniętą listą kodów.
- `ValidatorRegistry::for()` tworzy nową instancję walidatora przy każdym wywołaniu. Walidatory są bezstanowe; nie opierać się na tożsamości obiektu.
- Powtarzalność szkieletu trzech walidatorów numerów (około sześciu linii na klasę) zostaje świadomie — klasa bazowa sprzęgłaby trzy niezależne reguły urzędowe, które zmieniają się osobno.
- Duplikat klucza profesji cicho nadpisuje wcześniejszy wpis; klucz numeryczny staje się w `all()` kluczem `int`.
- `treatPhpDocTypesAsCertain: false` osłabia „level max"; katalog `tests/` nie jest analizowany przez PHPStan.
- Aktywacja sieciowa w multisite utworzy tabele tylko dla bieżącej witryny. Multisite jest jawnym celem negatywnym specyfikacji; `$wpdb->prefix` nie blokuje późniejszego dorobienia.
- Brak testów `Plugin::version()`, `path()`, `plugin_file()` i gałęzi `$fresh` — ta ostatnia będzie podstawą aktualizatora w Planie 8.

### Z recenzji całej gałęzi Planu 5 (2026-10-29)

- **Próg ostrzeżenia o stojącej kolejce równa się progowi odzysku** (900 s w obu miejscach), a ostrzeżenie mierzy tylko zaległość najstarszego wiersza. Zaległość dłuższa niż około 300 wierszy (15 przebiegów po 20) daje ostrzeżenie „kolejka nie jest opróżniana" na portalu, który opróżnia ją normalnie. Domknięcie: dodać warunek „i nic nie wyszło od dawna" (`MAX(sent_at)`) albo odsunąć próg od `Dispatcher::STALE_AFTER`, tak jak `STALE_AFTER` odsunięto od limitu czasu PHPMailera.
- **Ostrzeżenie o stojącej kolejce rejestruje się za bramką ACF Pro**, a dispatcher, przechwytywanie maila odzyskiwania i cron retencji — poza nią. Na portalu, na którym ACF padnie po zakolejkowaniu maili i jednocześnie nie działa WP-Cron, maile stoją bez żadnego ostrzeżenia. Okno wąskie, bo rejestracja i odzyskiwanie też są za bramką.
- **Wiersz ginący w pętli `sending` jest niewidoczny dla wszystkiego.** Fatal PHP (nie `Throwable`) zostawia wiersz w `sending`; `recover_stale()` wraca go do kolejki, następny przebieg zajmuje od nowa, a ostrzeżenie filtruje po `status = 'queued'`, więc go nie widzi. Licznik prób rośnie tylko wtedy, gdy dispatcher dojdzie do zapisu wyniku.
- **Temat szablonu maila dalej zamienia goły `<` na `&lt;`** (`sanitize_text_field`). Naprawione tylko w treści; temat to jedna linia i operator porównania jest w nim rzadkością.
- **`sanitize_textarea_field()` usuwa `<` razem z tekstem, gdy dalej w linii stoi `>`** — „Wiek < 30 > nie" traci trzy słowa, bo `wp_pre_kses_less_than()` przepuszcza taki fragment do `wp_strip_all_tags()`. Ekran ostrzega, że znaczniki są zdejmowane, ale docblock mówi o niższej cenie, niż jest.
- **Brak testu wyścigu `claim()` na dwóch połączeniach do bazy.** Gwarancję daje warunkowy UPDATE, który wymusza baza; prawdziwy test wymagałby drugiego połączenia i uśpienia, więc byłby chwiejny.
