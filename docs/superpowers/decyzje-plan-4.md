# Plan 4 — logowanie na froncie: decyzje podjęte za Ciebie

Wykonanie Planu 4 metodą subagent-driven: dziesięć zadań, dziewiętnaście commitów, dziesięć niezależnych recenzji zadaniowych, siedem rund poprawek, jedna recenzja całej gałęzi i jedna fala poprawek po niej.

Ten dokument istnieje, bo w trakcie wykonania podjąłem **pięćdziesiąt trzy rozstrzygnięcia w Twoim imieniu**. Dziennik wykonania jest kasowany razem z workspace'em planu, więc decyzje przenoszę tutaj. Każda ma zapisane, **co kosztuje, jeśli jest zła** — to jedyny sposób, żebyś mógł cofnąć to, co cofnąć warto.

**Stan wejściowy:** `main` @ `408f8bd` — unit 214, integration 562.
**Stan wyjściowy:** unit **222** (471 asercji), integration **686** (2403 asercje), `DomainPurityTest` **35**, PHPStan level max `No errors`, PHPCS zero zgłoszeń, drzewo czyste. Plan 4 dodał 8 testów jednostkowych i **124 integracyjne**.

---

## 1. Pięć punktów, które są Twoją decyzją, nie moją

Te pięć rzeczy rozstrzygnąłem, żeby nie zatrzymywać pracy, ale każda jest decyzją właściciela i każda jest tania do odwrócenia.

### 1.1. Lekarz nie ma na froncie żadnej drogi do zmiany hasła bez maila

Pytałem Cię o to przed wykonaniem i odpowiedziałeś polecenjem wykonania planu, czyli decyzji nie podjąłeś. Plan przewidywał ten odwrót: realizujemy literę specyfikacji.

Specyfikacja każe przekierowywać konta bez uprawnień do edycji treści z **całego** `wp-admin` na front. To obejmuje `wp-admin/profile.php`, czyli jedyne miejsce, gdzie zalogowany lekarz mógłby dziś sam zmienić hasło albo adres e-mail. Po tym planie zmienia hasło **wyłącznie** przez odzyskiwanie, z linkiem mailem, a adresu e-mail nie zmienia w ogóle.

**Odwrócenie:** jedna linia wyjątku w `Redirects::block_admin()` (ale wtedy panel pokazuje lekarzowi cały ekran profilu WordPressa) albo własna strona „moje konto" na froncie — osobny plan.

### 1.2. Trzy żądania na godzinę mogą odciąć znanemu lekarzowi odzyskiwanie hasła

Domyślny próg odzyskiwania per adres to **3/h**. Ktokolwiek zna adres lekarza, może trzema żądaniami na godzinę trzymać go w nieskończoność poza samoobsługowym odzyskiwaniem; drogą zostaje redakcja.

Zostawiłem trójkę, bo **każde** żądanie wysyła prawdziwy mail na skrzynkę ofiary — wyższy próg znaczy więcej niezamówionej poczty u lekarza, a bombardowanie skrzynki specyfikacja nazywa szkodą wprost. Odmowa jest przy tym **prawdziwa** („zbyt wiele żądań, spróbuj później"), nie cicha, więc człowiek wie, na co czeka. Jedyna alternatywa bez tej wady — cicha odmowa dla ofiary — byłaby gorsza: lekarz czekałby na mail, który nie nadchodzi.

**Odwrócenie:** jedna liczba w ustawieniach (`Limit żądań odzyskania hasła na jeden adres e-mail na godzinę`).

### 1.3. Konto odrzucone czyta „możesz się teraz zalogować"

Po udanym ustawieniu hasła strona mówi „Hasło zostało ustawione. Możesz się teraz zalogować." — także kontu `oczekujący` albo `odrzucony`. Zdanie jest prawdziwe o haśle, a prawdę o stanie konta mówi brama przy próbie logowania. Sprawdzanie stanu konta na stronie resetu postawiłoby logikę bramy w trzecim miejscu.

**Odwrócenie:** skrócenie zdania do „Hasło zostało ustawione." — ale wtedy 95% ludzi (konta zatwierdzone) traci wskazówkę, co robić dalej.

### 1.4. Przed backfillem Planu 7 musisz rozstrzygnąć dwie rzeczy

To zalecenie recenzji całej gałęzi i jest ważniejsze, niż wygląda.

Brama odmawia logowania **po samym stanie konta**, bez wyjątku dla uprawnień. Po tym planie `SessionGuard` dodatkowo niszczy sesje przy **każdym** przejściu w stan inny niż `zatwierdzony`. Backfill Planu 7, który zapisze stan nie-`zatwierdzony` na konto z `manage_options`, **zamknie właścicielowi portalu wejście i wyrzuci go z trwającej sesji** — wyjście wyłącznie przez WP-CLI albo bazę. Specyfikacja nazywa to celem wrogim.

Do rozstrzygnięcia: albo wyjątek dla uprawnień w bramie jako obrona w głąb, albo jawna i testowana reguła backfillu „nigdy nie nadawaj stanu nie-zatwierdzonego kontu z uprawnieniami". Ścieżkę ratunkową (`wp user meta delete`) trzeba udokumentować w obu wariantach.

### 1.5. Tabeli `event_log` nic nie czyści

W całej wtyczce nie ma ani crona, ani polityki retencji dla `event_log`. Ten plan dołożył **budżety na pisarza** (odmowy odzyskiwania i odmowy REST mają limit wierszy na adres IP na okno), ale to nie jest retencja: logowania, rejestracje i wiersze awarii limitera dalej rosną bez ograniczenia. Specyfikacja przewiduje retencję dla logu **wysyłek maili** (90 dni), o logu zdarzeń nie mówi nic — bo log zdarzeń ma służyć do wykazania, kto i kiedy zatwierdził konto.

Do rozstrzygnięcia: polityka całotabelowa. To pozycja backlogu, nie tego planu.

---

## 2. Pięć razy, gdy plan sam się mylił

To najważniejsza część dokumentu. Plan 4 pisałem ja i **pięć razy jego własna treść była błędna** — za każdym razem wyszło to z czytania źródeł rdzenia WordPressa, nie z pamięci, i za każdym razem wyłapał to ktoś inny niż autor.

### 2.1. Priorytet 30 **nie** zakrywał REST po haśle aplikacyjnym (R8)

Plan twierdził: „Priorytet 30 obejmuje też `wp_authenticate_application_password`, więc konto bez stanu `approved` nie wejdzie także po hasło aplikacyjne, czyli do REST". **Fałsz.** `wp_validate_application_password` siedzi na `determine_current_user` (`wp-includes/default-filters.php:522`) i woła `wp_authenticate_application_password()` **wprost z własnego ciała**, nigdy przez `apply_filters( 'authenticate' )`. Jedynymi wołającymi `wp_authenticate()` są `wp_signon()` i XML-RPC.

Waga jest wyższa, niż wyglądała: ciasteczko sesji wygasa, hasło aplikacyjne jest poświadczeniem **bezterminowym**. Konto zatwierdzone, które wystawiło hasło aplikacyjne i zostało potem odrzucone, czytałoby REST bez końca.

Domknięte drugim zaczepem na `rest_authentication_errors` (priorytet 110, za rdzeniowymi 90 i 100), stosującym **tę samą** decyzję do każdego uwierzytelnionego żądania REST.

### 2.2. Priorytet 30 **nie** był „za wyrokiem rdzenia" (R18)

To ta sama klasa błędu co 2.1, o warstwę głębiej, i przeżyła dlatego, że weryfikacja przeczytała tylko `default-filters.php`. Poza czterema wywołaniami z `:516-519` istnieje **piąte**, dokładane z wnętrza `wp_signon()`:

```
wp-includes/user.php:107:  add_filter( 'authenticate', 'wp_authenticate_cookie', 30, 3 );
```

`wp_authenticate_cookie()` wychodzi wcześnie **tylko** dla `WP_User`; przy pustym loginie **i** pustym haśle woła `wp_validate_auth_cookie()` i zwraca `new WP_User( $user_id )`. Brama, rejestrowana przy ładowaniu wtyczki, idzie **wewnątrz** priorytetu 30 pierwsza: widzi rdzeniowy `empty_username`, słusznie oddaje go nietkniętego — a potem uwierzytelniacz ciasteczka podnosi ten błąd do `WP_User`, którego brama już nie ogląda, i `wp_signon()` wystawia na niego świeże ciasteczko.

**Dziura:** konto **bez zatwierdzenia**, mające ważne ciasteczko sesji, odnawiało je bez końca pustym POST-em na `wp-login.php` (które specyfikacja celowo zostawia otwarte dla redakcji, bez nonce'a), z „zapamiętaj mnie" włącznie — do czternastu dni, odnawialne bez limitu. Zdanie „sesja kiedyś wygaśnie" przestawało być prawdziwe.

Naprawione priorytetem **40**: za weryfikatorami hasła (20), za uwierzytelniaczem ciasteczka (30), przed `wp_authenticate_spam_check` (99). Plan i docblok klasy poprawione, żeby następny czytelnik nie powtórzył tej samej częściowej weryfikacji.

**Morał dla przyszłych planów:** zanim ustalisz priorytet zaczepu, zrób `grep -rn "add_filter( '<hook>'" wp-includes/`, a nie czytaj pliku domyślnych filtrów.

### 2.3. Kolejność bramek w `handle_set()` dawała osobie trzeciej cudzy budżet (R6)

Plan pisał „progi → `check_password_reset_key()`", czyli kubełek per konto **przed** weryfikacją klucza — a `USER_ARG` jest niesprawdzonym łańcuchem z adresu. Osoba trzecia podmieniłaby identyfikator i wyczerpała właścicielowi budżet. Przeczyło to ograniczeniu globalnemu tego samego planu.

### 2.4. Próg 3/h na **wpisywanie hasła** zamykał lekarza po dwóch literówkach (R2)

Plan kazał kubełkowi per konto na kroku ustawiania hasła czytać wartość progu **per adres** (3). Ta trójka jest skalibrowana na wysyłkę maili, nie na wpisywanie hasła w formularz, do którego człowiek ma już ważny klucz. Zmienione na `resetLimitPerHour` (50).

### 2.5. Sprzeczność między dwoma miejscami planu i jedna liczba w moim własnym rulingu

Brief Taska 2 kazał wzorować sanityzację progów na `registrationLimitPerEmailPerHour`, które przycina do 1 — a jego własny test wymagał, by 0 i -5 dawały wartość domyślną. Wykonawca rozwiązał to osobnym pomocnikiem i to było właściwe.

Przy R12 napisałem, że naprawa zdejmie „dwa z trzech" tłumień PHPCS. Wykonawca **zmierzył**: są dwa, nie trzy, więc zdejmuje dokładnie jedno. Moja liczba była założeniem, nie pomiarem.

---

## 3. Dwa razy, gdy mój własny ruling był zły

### 3.1. Kazałem logować odmowy i otworzyłem nieograniczony zapis do bazy (R17)

W porządkach po Tasku 8 kazałem, żeby odmowa na formularzu odzyskiwania pisała wiersz do logu — bo przy zawsze jednakowej odpowiedzi człowiek, któremu menedżer haseł wypełnił pułapkę, słyszy „wysłaliśmy link", nic nie dostaje i **nie zostawia śladu**, który administrator mógłby znaleźć.

Nie przemyślałem, gdzie ten wiersz stanie. Wiersz trafił do bramki nonce'a, czyli **przed** pułapkę i **przed** próg per IP: anonimowy POST bez nonce'a pisał wiersz na żądanie, a po wyczerpaniu progu odmowa `ip_limit` pisała wiersz **dalej, dla każdego kolejnego żądania**. Skutek podwójny — nieograniczony wzrost tabeli i **zasypanie dokładnie tego sygnału**, dla którego wiersz dokładałem.

Potem podałem mechanikę naprawy, która **też nie działała**: kazałem czytać licznik progu bez podbijania, a bramka nonce'a wychodzi **przed** `hit()`, więc ten licznik stoi na zerze i „w budżecie" jest prawdą zawsze. Wykonawca to wykazał i dał budżetowi **własny** licznik podbijany przez sam zapis. Jego rozwiązanie jest lepsze od mojego rulingu.

### 3.2. Zabroniłem kodu, którego nie dowodzi test — i zmieniłem zdanie (R7 → R16)

Postawiłem wymóg: test niszczenia sesji przy resecie hasła ma pokazać, czy jawne `destroy_all()` jest potrzebne, a **jeśli test przechodzi bez niego — nie dokładać kodu, którego nic nie dowodzi**. Test przeszedł bez niego (ciasteczko rdzenia niesie fragment skrótu hasła, więc `reset_password()` unieważnia cudze ciasteczko ubocznie), a wykonawca uczciwie kodu nie dołożył.

Recenzent wniósł kontrargument, którego nie miałem: bez tej linii całe odebranie dostępu wisi na **czterech znakach** skrótu hasła, a przy kolizji tego fragmentu ocalały wiersz tokenu przechodzi weryfikację i cudze ciasteczko dalej działa. Do tego `SessionGuard` **już** woła dokładnie to samo, więc bez tej linii wtyczka unieważnia sesje przy zmianie stanu, a przy zmianie hasła nie.

Odwróciłem: `destroy_all()` wraca, z komentarzem mówiącym wprost, że test tej linii nie dowodzi i dlaczego stoi. Standard dowodowy ma zapobiegać **testom**, które nic nie dowodzą, a nie zakazywać obrony w głąb z zapisanym uzasadnieniem.

---

## 4. Jedenaście razy, gdy obrona trafiała w człowieka zamiast w bota

Na gałęzi Planu 3 było tego dziesięć przypadków i wszystkie wyszły z zacieśniania. Plan 4 trzy z nich **uprzedził z góry** (brak pułapki czasowej na logowaniu, wyjątki `admin_init` dla `admin-post.php`, licznik rosnący tylko na porażkach) — i to były dobre decyzje. Reszta wyszła w trakcie:

| Co trafiało w człowieka | Jak zamknięte |
|---|---|
| Próg 3/h na wpisywanie nowego hasła: dwie literówki = godzina blokady z ważnym kluczem w ręku | R2 — próg 50 |
| Odmowa stanu konta zużywająca próg: lekarz czekający na redakcję po kilku odświeżeniach czyta „zbyt wiele prób" zamiast „zgłoszenie czeka" | R11 — odmowa stanu nie zużywa progu ani nie pisze drugiego wiersza |
| Zalogowany odsyłany po zalogowaniu na stronę logowania („jesteś już zalogowany" zamiast portalu) | R13 |
| Wylogowany redaktor z `/wp-admin/edit.php` tracący cel po jednej literówce w haśle | R23 — cel przeżywa odmowę |
| Lekarz, który pomyli hasło dziewięć razy i za dziesiątym wejdzie, odchodzi z licznikiem na dziewięciu | R24/F7 — udane logowanie zeruje licznik per konto |
| …to samo dla konta, którego login jest adresem innym niż e-mail — **dziura, którą wprowadziła moja własna fala poprawek** | R24 — zerowanie objęło oba kubełki |
| Strona po udanym resecie mówiąca „możesz się teraz zalogować" **nad formularzem żądania kolejnego linku**, bez odnośnika do logowania | poprawka w Tasku 9 |
| Odmowa z reguł hasła spalająca link odzyskiwania | wymóg planu, dowiedziony testem |
| Administrator wskazujący stronę **roboczą** jako stronę po zalogowaniu: brak ostrzeżenia, a każdy nie-redaktor trafia na stronę, której nie zobaczy | R22 |
| Pusty komunikat błędu przy logowaniu (`WP_Error` bez treści) | odwrót w `refuse()` |
| Powódź wierszy odmowy zasypująca jeden wiersz, który administrator ma znaleźć | R17 |

---

## 5. Czternaście testów, które nic nie dowodziły

Plan 4 niósł wymóg: każda zmiana zachowania ma dowód mutacyjny potwierdzony **`diff`em** (nie `grep`em) i przywrócenie `cmp`em, a trzy testy miały wymóg mocniejszy — **paść na złym projekcie**, nie tylko pod mutacją. To się opłaciło:

- **Brama na priorytecie 30 vs 40** — dowód mutacyjny pokazał test padający; bez niego sama stała nie dowodziłaby niczego.
- **Priorytet 20 wbrew planowi NIE psuje bramy** — wykonawca zgłosił, że jego mutacja **przeszła**, zamiast udawać dowód. Przy równym priorytecie rdzeń idzie pierwszy (`default-filters.php` ładuje się w `wp-settings.php:154`, wtyczki w `:582`, a `WP_Hook` dopina na koniec listy priorytetu). Sprawdziłem sam. Zastąpione asercją na relację.
- **Ratyfikowane odstępstwo w Tasku 6 nie było przypięte niczym** — suita była zielona przy **obu** odczytach, na jednej spornej rzeczy w całym diffie. Dołożone asercje wykluczają wariant literalny.
- **Test niszczenia sesji** przechodził bez mechanizmu, który miał dowodzić (patrz 3.2).
- **Test atomowości, „nic ponad" w mapie komunikatów, umiejscowienie opisu progu, filtr tytułu maila przypięty do angielskiej lokalizacji, asercja tautologiczna, `assertStringContainsString` przechodzące na pustym łańcuchu** — każde złapane i albo naprawione, albo odłożone z zapisanym powodem.

Dwa testy, które **wykonawcy znaleźli u siebie sami**, zasługują na osobną wzmiankę:

- Jawne **hasło** jechało do filtra rozszerzenia `pusers_login_spam_check`, czyli do wtyczki CAPTCHY, która typowo przekazuje otrzymane dane usłudze zewnętrznej. Usunięte z asercją i dowodem.
- Przy niewskazanej albo usuniętej stronie resetu filtry przepisywałyby **działający** mail rdzenia na odnośnik do strony głównej — regresja wobec czystego WordPressa.

---

## 6. Wszystkie rozstrzygnięcia

Pięćdziesiąt trzy, w kolejności podejmowania. Numerowane te, które wprost zmieniały plan.

**Przed wykonaniem (przelot wstępny po planie):** R1 jednakowe wartości `RESULT_SHORT`/`RESULT_EMAIL` co w polityce i w `PasswordSetup` · R2 próg ustawiania hasła z `resetLimitPerHour`, nie z progu adresowego · R3 komunikat wyniku logowania renderuje Task 6, nie Task 5 (inaczej PHPStan wywala nieistniejącą klasę) · R4 Task 8 nie rozdziela widoków po żywym kluczu · R5 litera specyfikacji w punkcie o `profile.php` · R6 kolejność bramek w `handle_set()` z progiem per konto **za** weryfikacją klucza · R7 wymóg dowodu na niszczenie sesji.

**Task 2:** zdanie o 60-minutowej blokadzie zostaje w obecnym brzmieniu (mechanizm istnieje, przycisk należy do Planu 6).

**Task 3:** R8 domknięcie REST drugim zaczepem · R9 rejestracja bramy bezwarunkowo, poza `Dependencies::satisfied()` · minor o pustym komunikacie wchodzi do rundy razem z blokującymi · poprawienie testu, nie kodu, gdy rdzeń zwraca `true` zamiast `null` · hasło aplikacyjne nie jest unieważniane, tylko jego użycie odmawiane · konto bez zatwierdzenia nie dosięga przez REST niczego.

**Task 4:** `SessionGuard` rejestruje się jak brama, poza zależnością · gałąź nieosiągalna przez repozytorium zostaje (publiczny zaczep, test woła nasłuch wprost) · zmiana nazw parametrów na `$oldStatus`/`$newStatus` (PHPCS).

**Task 5:** R10 `LoginForm::ACTION` tymczasowe, znika w Tasku 6 · `PageUrl::for()` zachowuje dawną semantykę dla strony roboczej i w koszu.

**Task 6:** R11 ratyfikacja odstępstwa (odmowa stanu nie zużywa progu) · asercje przypinające R11 obowiązkowe · cztery minory do tej samej rundy.

**Task 7:** R12 `login_url` niesie adres docelowy · R13 `after_login()` nie honoruje żądania na stronę logowania · R14 `mixed` plus straż `is_string()` w sygnaturze z briefu · sześć minorów do tej samej rundy · rozszerzenie R14 na typ zwracany · dwie decyzje wykonawcy poza rulingami (walidacja u producenta, dopisanie brakującej akcji do rejestru) · **korekta mojej liczby tłumień PHPCS**.

**Task 8:** niekonsumowanie `Mailer` (rdzeń wysyła mail, `send()` jest prywatne) · R15 próg adresowy zostaje na 3/h · sześć minorów do Taska 9, nie do osobnej rundy · wiersz logu przy odmowie tylko w `PasswordReset`.

**Task 9:** rozstrzygnięcie eksperymentu R7 · R16 odwrócenie połowy R7 · R17 budżet wierszy logu (**mój błąd**) · cztery minory do tej samej rundy · dwie poddecyzje przy `BUCKET_LOG_IP` · zmiana trzech asercji `limiterOptions()` · `RESULT_DONE` zostaje.

**Task 10:** `RestUsers` rejestruje się z `boot()` (wybór wykonawcy, uzasadniony) · usunięcie dla obcych także podtras `application-passwords` (wyciek potwierdzony w źródłach).

**Po recenzji całej gałęzi:** R18 priorytet 40 · R19 sesje giną przy każdym przejściu w stan nie-zatwierdzony · R20 formularz logowania przestaje być wyrocznią o istnieniu konta · R21 budżet wierszy dla odmowy REST · R22 ostrzeżenie o stronie po zalogowaniu · R23 cel przeżywa nieudaną próbę · M5 do fali (zerowanie progu po udanym logowaniu) · M1–M4, M6 do backlogu · przyjęcie triażu 33 minorów w całości · trzy decyzje wykonawcy fali · **R24 domknięcie trzech artefaktów własnej fali poza regułą „jedna fala"** — ten commit nie dostał własnego oka recenzji, przeczytałem diff i uruchomiłem bramki sam.

---

## 7. Backlog po tym planie

Z triażu 33 odłożonych minorów: **16 do backlogu**, 11 odrzuconych jako niewarte trzymania, 6 naprawionych przed scaleniem.

**Warte zrobienia przy następnym otwarciu tych plików:**
- oba producenty `pusers_login_redirect` na `rawurlencode()` w jednym commicie, z testem na cel zawierający `&` (dziś cel traci ogon za pierwszym `&`)
- `finish_with_link()` wypuszcza żywy klucz resetu do adresu; rdzeń przenosi klucz do ciasteczka `wp-resetpass-*` i czyści adres
- `src/Front/PasswordReset.php` ma 893 wiersze i nosi obie połowy odzyskiwania — szew jest czysty, podzielić **przed** trzecim przepływem na tej stronie
- prezenter komunikatów reguł hasła w `src/Front` kluczowany po `PasswordPolicy::ERROR_*`, wspólny dla `PasswordSetup` i `PasswordReset` (dziś kod odmowy jest jednoźródłowy, teksty podwojone)
- mail odzyskiwania przez `Mailer::FILTER_BODY` — **przekazać Planowi 5**, inaczej szablony dostaną dwa szwy
- mail odzyskiwania nie nazywa portalu, a firma prowadzi wiele portali; dziś to regresja wobec czystego WordPressa — też Plan 5
- jedna wspólna definicja „nie ujawniamy, czy adres ma konto", którą cytują wszystkie cztery powierzchnie; dziś każda argumentuje to własnym docblokiem i właśnie dlatego jedna się rozjechała
- wspólny pomocnik `log_refusal()` dla `LoginGateway`, `LoginHandler` i `PasswordReset` — wzór R17 jako reguła projektu

**Do sprawdzenia:**
- czy `/?author=N` odpowiada inaczej dla konta istniejącego i nieistniejącego — recenzent oznaczył to jako **pytanie, nie znalezisko**, i nie zweryfikował w kontenerze. Jeśli tak, Task 10 zamknął pół wyroczni.
- `wp rest user list` przez WP-CLI jest objęte zamknięciem tras (każde złożenie tabeli tras bez rozpoznanego użytkownika) — jedno zdanie do dokumentacji obok `pusers_rest_users_public`

**Twoje własne zadanie, którego nie da się zautomatyzować:** sprawdzić na dev z żywym ACF Pro, czy `true_false` renderuje ukryte zero tak, jak zakłada normalizacja. Pomyłka tam daje niezaznaczoną zgodę na regulamin spełniającą wymóg.
