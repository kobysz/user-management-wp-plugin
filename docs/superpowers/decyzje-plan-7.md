# Decyzje i ustalenia Planu 7 (eksport CSV i RODO)

Zapis z wykonania `docs/superpowers/plans/2026-10-29-portal-users-eksport-rodo.md` metodą subagent-driven: osiem zadań, osiem recenzji zadaniowych, pięć rund poprawek w zadaniach, recenzja całej gałęzi (opus), jedna fala poprawek po niej i jedna re-recenzja zakresowa. Ledger wykonania był git-ignorowany i został usunięty; to jest to, co z niego zostaje.

Bramki na drzewie do scalenia: unit **261** (596 asercji), integration **1058** (3924), PHPStan level max czysto, PHPCS zero zgłoszeń. Wejście planu: unit 248, integration 982 — Plan 7 dołożył 13 testów jednostkowych i 76 integracyjnych.

Zakres: dwie klasy domenowe (`CsvCell`, `CsvDocument`), filtr kontekstu logu (`ContextFilter`), odczyt i anonimizacja logu zdarzeń oraz kolejki maili, eksport CSV listy wniosków, eksporter i eraser danych osobowych rdzenia WordPressa. Import i backfill stanów przeszły do Planu 8 (decyzja właściciela z 2026-10-29), dawny Plan 8 stał się Planem 9.

---

## 1. Co zostaje do Twojej decyzji

Cztery rzeczy z tego planu i jedna zaległa.

### 1.1. Retencja wierszy kolejki trzymających żywy sekret

Snapshot maila rodzaju `confirmation` zawiera **token potwierdzenia adresu ważny 48 godzin**, a `password_reset` — **klucz resetu hasła**. Oba leżą w tabeli z oknem retencji 90 dni i oba są czytelne na ekranie logu wysyłek. To nieunikniona konsekwencja wzorca „render treści przy zapisie", którego żąda specyfikacja, i nie jest eskalacją uprawnień — każdy, kto widzi ten ekran, ma `manage_options`. Nierozstrzygnięte zostaje ryzyko **danych w spoczynku**: kopia bazy zrobiona w okresie ważności tokenu daje działające linki do przejęcia konta.

Do rozstrzygnięcia: okno retencji w dniach dla tych dwóch rodzajów, albo czyszczenie samej kolumny `body` po wysyłce. **Dopóki nie odpowiesz, obowiązuje 90 dni dla wszystkich rodzajów.** Pozycja jest w backlogu.

### 1.2. Adres IP w wierszach logu z podmiotem `0` przeżywa żądanie usunięcia danych

To defekt mojego planu, **nie zamknięty w tej gałęzi**. `EventLog::anonymize_subject()` szuka po `subject_user_id`, a ścieżka odzyskiwania hasła i osłona antybotowa piszą do logu z podmiotem `0`, bo świadomie nie ustalają, czy adres należy do konta. Kontekst tych wierszy niesie IP oraz `email_hash` wyliczalny z adresu, więc wtyczka **umie** powiązać je z osobą, a eraser ich nie rusza. Dodatkowo `event_log` nie ma żadnego okna retencji.

Dwie naprawy, nie wykluczają się: anonimizować też wiersze z podmiotem `0`, których `email_hash` równa się skrótowi adresu z żądania, albo dać `event_log` okno retencji jak kolejce. Pozycja jest w backlogu z nazwanymi plikami.

### 1.3. Log wysyłek kłamie o mailu, który anulowano, a który dotarł

Wiersz w stanie `sending` w chwili żądania usunięcia jest już zajęty przez dispatchera, który trzyma treść w pamięci — mail więc wychodzi, i to jest poprawne. Eraser przestawia wiersz na `failed` z powodem „anulowano żądaniem usunięcia danych", a dispatcher kończy próbę przez `mark_sent()`, które przechodzi przez `finish()` i zapisuje **tylko** wiersz wciąż w stanie `sending` z tym samym numerem próby. Zapis nie trafia. Wiersz zostaje „nieudany / anulowany", choć adresat maila dostał.

Okno jest wąskie (jeden przebieg dispatchera) i naprawa kosztuje test na drugim połączeniu do bazy. Zapisane w backlogu, żeby następny czytelnik nie szukał błędu w dispatcherze.

### 1.4. `dedupe_key` zostaje po anonimizacji

Anonimizacja nadpisuje adresata, temat, treść i `last_error`, ale **nie** `dedupe_key`. Klucz niesie identyfikator użytkownika i minutę, nie adres ani treść, więc sam nie wydaje danych osobowych — ale jest trwałym śladem „ten użytkownik dostał maila tego rodzaju w tej minucie". Zostawiłem go, bo wyczyszczenie klucza rozbiłoby ochronę przed duplikatem dla maili wysyłanych **po** anonimizacji (konto po usunięciu danych dalej istnieje i dalej może dostać maila). Jeżeli uznasz, że ślad ma zniknąć, to jedna kolumna więcej w UPDATE i świadoma rezygnacja z dedupe dla kont po erasure.

### 1.5. Zaległe z Planów 3 i 6, dalej otwarte

Cztery zachowania ACF Pro, na których stoi most `ProfileSync` (kontener testowy ich nie weryfikuje, bo nie ma w nim ACF Pro), zaległa kontrola `true_false` z Planu 3, oraz masowa korekta powodów na zakładce „Odrzucone", która mailuje każdego zaznaczonego lekarza. Opisane w `decyzje-plan-6.md` 1.1–1.3; Plan 7 ich nie dotknął.

---

## 2. Co ten plan odkrył w kodzie, którego nie dotykał

Cztery rzeczy, naprawione tutaj, bo pierwszy konsument odsłonił je dopiero teraz.

- **Stronicowanie wniosków bez rozstrzygnika** (R15). `ApplicationRepository::query()` sortowało tylko po `registered DESC`. Przy kontach z tej samej sekundy rejestracji kolejność między osobnymi zapytaniami `LIMIT/OFFSET` jest **nieokreślona**, więc wiersz mógł się powtórzyć albo zniknąć między stronami. Ekran pokazuje jedną stronę i tego nie widział; eksport chodzi po wszystkich, więc plik dawałby jednego lekarza dwa razy, a innego wcale — cicho. Naprawione: `orderby` to teraz `registered DESC, ID DESC`.
- **`requeue_failed()` pozwalało cofnąć anonimizację** (R13). Przycisk „ponów" na ekranie logu wysyłek wracał do kolejki każdy wiersz `failed` — w tym ten, który eraser właśnie anulował. Redaktor w dobrej wierze kliknąłby i wtyczka próbowałaby wysłać maila do literalnego napisu-zastępnika. Dodane `AND anonymized = 0`.
- **`last_error` wiersza `sent` trzymał adres lekarza** (R14). Znalazłem to sam, czytając końcowe zapytanie; ani brief, ani żaden recenzent tego nie widział. Wiersz `sent` może legalnie trzymać błąd wcześniejszej próby (próba 1 pada i zapisuje, próba 2 wychodzi), a odmowy SMTP rutynowo cytują adresata: `550 5.1.1 <jan@example.com>: Recipient address rejected`. Anonimizacja nadpisywała trzy kolumny i pomijała czwartą. Teraz `last_error` jest nadpisywany zawsze: `NULL` dla wiersza `sent`, marker dla pozostałych.
- **`EventLog::write()` przemilczał porażkę zapisu.** Zwracał `void` i ignorował wynik `$wpdb->insert()`, więc rozstrzygnięcie wniosku mogło pójść mailem i zostać zgłoszone redaktorowi jako sukces, nie zostawiając śladu w audycie. Teraz zwraca `bool`, a `Decision` ma własny kod `RESULT_LOG_FAILED` i własne ostrzeżenie na ekranie.

---

## 3. Gdzie mój plan się mylił

### 3.1. Eraser mógł zgłosić sukces, zostawiając dane (R12 → R23)

Najpoważniejszy błąd tego planu, i mój dwukrotnie. Ledger zapisał rozstrzygnięcie R12: „`done` wynika z tego, czy **zostały** wiersze z `anonymized = 0`". Mój brief Zadania 8 polecił potem dokładnie odwrotnie: „`done` jest prawdą, gdy ten przebieg zanonimizował zero wierszy". Wykonawca zbudował to, o co poprosiłem; poprosiłem o regułę, którą sam wcześniej wycofałem. Recenzent całej gałęzi znalazł rozbieżność, porównując ledger z kodem.

Skutki potwierdzone na kodzie: oba repozytoria **pomijają w sumie** wiersz, którego `UPDATE` zwrócił `false`, więc trwale padający zapis daje w przebiegu 1 liczbę N-1, a w przebiegu 2 zero → `done = true`, wiersz z danymi osobowymi zostaje, a rdzeń mówi administratorowi, że usuwanie się skończyło. Przy niedostępnej tabeli `SELECT` zwraca `false`, pętla nie wykonuje się ani raz i `done` jest prawdą już w **pierwszym** przebiegu — eraser nie robi nic i nic nie mówi. Dodatkowo `MAX_PASSES` był w produkcji nieosiągalny, więc jedyny komunikat mówiący prawdę o awarii nigdy nie mógł się pokazać.

Naprawione w fali: `done` to wyłącznie `0 === remaining()`, gdzie `remaining()` liczy dwa `COUNT(*)` po `anonymized = 0`, a **padający COUNT liczy się jako jeden pozostały wiersz** (bez tego niedostępna tabela wciąż dawałaby sukces w pierwszym przebiegu). Pętlę zamyka `MAX_PASSES`.

### 3.2. Powód odrzucenia nie był eksportowany (R19)

Dla lekarza z odrzuconym wnioskiem powód jest najistotniejszym faktem, jaki o nim trzymamy: decyzja o jego osobie, w jego własnych metadanych. Co czyni z tego defekt, a nie przeoczenie: Zadanie 8 nakazywało erazerowi **kasować** tę metadaną, więc bez poprawki wtyczka usuwałaby wartość, do której nigdy się nie przyznała. Dodane do grupy konta, tylko gdy niepuste.

### 3.3. „Stan i czas zostają nietknięte" było prawdą tylko dla wysłanych (R11)

Powiedziałem wykonawcy, że anonimizacja nie rusza `status`, `sent_at` i `attempts`. Dla wiersza już wysłanego to prawda. Dla wiersza, który nigdy nie wyszedł, dawało dwa złe skutki: wiersz zostawał `queued`, więc dispatcher próbowałby wysłać wiadomość do literalnego napisu-zastępnika, a — gorzej dla człowieka — ostrzeżenie o stojącej kolejce z Planu 5 mierzy zaległość najstarszego wiersza, więc zanonimizowany, nigdy niewysyłalny wiersz kazałby panelowi **wiecznie** ostrzegać, że kolejka stoi. Anonimizacja anuluje więc wiersz, który nie wyszedł: `failed` plus bezosobowy marker.

### 3.4. Strażnik czystości domeny kontra nazwa sniffa (R1)

`CsvDocument` musi wołać `fopen`/`fclose` na buforze w pamięci, co PHPCS zgłasza regułą, której **nazwa zawiera słowo zakazane przez strażnika czystości domeny w komentarzu**. Wykonawcy został goły `// phpcs:ignore`, który tłumi na tej linii wszystko. Przeniosłem to do `phpcs.xml.dist` jako dwa wykluczenia zawężone do `src/Domain/Export/*` — i sprawdziłem zawężenie sam, wrzucając jednorazową klasę z `fopen()` do `src/Domain/Account/` (ostrzeżenie padło, klasę usunąłem). Ten sam zderzak wymusił w Planie 6 `preg_match` zamiast `parse_url`.

---

## 4. Gdzie myliłem się w poleceniach dla wykonawców

- **„Jedenaście miejsc woła `EventLog::write()`"** — jest **29** w `src/`, policzone przeze mnie po fakcie. Rozstrzygnięcie było niezależne od liczby (reaguje tylko `Decision`), ale liczba w briefie była wymyślona, nie zmierzona.
- **Brief Zadania 8 sprzeczny z moim własnym ledgerem** — opisane w 3.1. Jedyne miejsce w całym ledgerze, gdzie zapis i kod się rozjechały.
- **Mój opis wyścigu anonimizacji z wysyłką (pozycja B4 fali)** był nieprawdziwy: napisałem, że `mark_sent()` przestawia anulowany wiersz na „wysłany". Wykonawca sprawdził kod — zapis nie trafia, a wiersz zostaje „nieudany / anulowany", choć mail dotarł. To **gorszy** fakt niż mój; backlog ma wersję prawdziwą (1.3 wyżej).
- **Brief Zadania 6 kazał mi domknąć zobowiązanie z Zadania 1**: skoro `render()` rzuca wyjątkiem, uchwyt pobierania nie może wysłać nagłówków pliku, gdy budowa dokumentu padła. W fali wyszło, że `wp_die` musi stać **przed** jakimkolwiek `echo`, bo inaczej dane osobowe trafiają do strony panelu.

Dwa wypadki wykonawców, obsłużone bez rundy: commit podpisany innym modelem (poprawiłem sam wiadomość — commit szczytowy, gałąź lokalna, bez zdalnego) i `phpcbf` puszczony dwa razy po `src tests` (oba razy wycofane; zakres diffu sprawdzałem sam za każdym razem — brak wycieku przeformatowania w gałęzi).

---

## 5. Zachowania, które uderzyłyby w człowieka

- **Plik CSV z samym BOM-em czytałby się jako poprawny pusty eksport** (R2, R3), więc administrator wyciągnąłby wniosek, że nie ma wniosków. Dwie identyczne ciche drogi: padający `fopen` i `(string) stream_get_contents()`, które z `false` robi `''`. Obie zamknięte wyjątkiem. Zamknięcie jednej z dwóch byłoby najgorszym możliwym wynikiem — ledger twierdziłby, że cisza jest zamknięta.
- **Eraser zgłaszający sukces z danymi w bazie** (3.1).
- **Wieczne ostrzeżenie „kolejka nie jest opróżniana"** po anonimizacji niewysłanego wiersza (3.3).
- **Eksport dający jednego lekarza dwa razy, a innego wcale** (2., R15).
- **Przycisk „ponów", który cofa część anonimizacji** (2., R13).
- **Adres lekarza zostający w czwartej kolumnie** (2., R14).
- **Zanonimizowany wiersz bez wyjaśnienia** (R21) czytałby się w eksporcie danych osobowych jak awaria narzędzia, nie jak skutek wcześniejszego żądania usunięcia. Dostaje jawną parę „Zanonimizowano".
- **Logowanie przyznane kontu odrzuconemu.** Brama wpuszcza konta **bez** zapisanego stanu, więc skasowanie `pusers_status` w erazerze **dałoby** dostęp odrzuconemu lekarzowi. Dlatego `STATUS`, `PROFESSION`, `TERMS_VERSION` i `CONSENT_AT` są świadomie **zachowywane** — żądanie usunięcia nie jest żądaniem zmiany stanu konta ani wycofania zgody.

---

## 6. Testy, które nic nie dowodziły

- `test_porazka_logu_wygrywa_z_porazka_maila` **zakładał**, że dedupe maila sprawi, że drugie wysłanie padnie, i nigdy tego nie sprawdzał. Gdyby dedupe się zepsuł, test dalej przechodziłby na samym `RESULT_LOG_FAILED` i przestałby dowodzić reguły pierwszeństwa z własnej nazwy.
- `anonymize_subject()` nie miał pokrycia na wielu wierszach, a Zadanie 8 miało z tej liczby wyprowadzać moment zatrzymania pętli. Liczba, która kłamałaby systematycznie (zawsze 1), kończyłaby pętlę za wcześnie, **zostawiając dane osobowe w logu**, albo kręciłaby ją bez końca.
- Test eksportu CSV przechodził na pustym pliku, bo nie miał konta z profesją — spełniłby go dowolny plik z nagłówkami. Dodana asercja na konkretną wartość w treści.
- Test limitu przebiegów erazera nie sprawdzał `items_removed === false` na przebiegu ponad limit.
- Przywracanie `suppress_errors()` do wartości sztywnej `false` zamiast do poprzedniej; w teście wymuszającym porażkę zapisu `remove_filter` i to przywrócenie siedzą teraz w `finally`, więc wykonają się też po padniętej asercji w środku pętli.

---

## 7. Wszystkie rozstrzygnięcia

Dwa przedwykonaniowe (P) i dwadzieścia cztery z wykonania (R). Każde w formie: co postanowiłem — dlaczego — co kosztuje, gdy się mylę.

- **P1.** Wymuszona porażka zapisu logu powstaje przez `add_filter( 'query', … )` (sprawdzone: filtr jest w `class-wpdb.php:2230`, wewnątrz `$wpdb->query()`, którego używa `insert()`), a **nie** przez skasowanie albo przemianowanie tabeli. DDL w MySQL nie jest transakcyjny, więc skasowana tabela ucieka rollbackowi `WP_UnitTestCase` i psuje każdy późniejszy test w procesie. Koszt pomyłki: test zatruwający resztę suity i dzień zrzucony na niewinny kod.
- **P2.** `ExportHandler::handle()` kończy `if ( apply_filters( 'pusers_export_exit', true ) ) { exit; }`, filtr nazwany stałą i udokumentowany jako szew testowy. Gołe `exit` z briefu czyni niemożliwym test ścieżki udanej, którego ten sam brief żąda, a projekt ma już dokładnie ten kształt w `Request::redirect()`. Koszt: jeden filtr, którego produkcja nie zmienia.
- **R1.** Goły `// phpcs:ignore` zamieniony na dwa wykluczenia w `phpcs.xml.dist` zawężone do `src/Domain/Export/*`. Goły ignore tłumi na linii każdą regułę, więc następne realne naruszenie zniknęłoby bez śladu. Koszt: realne wywołanie systemu plików w `src/Domain/Export/` przestaje być zgłaszane.
- **R2.** `render()` rzuca wyjątkiem, gdy `fopen()` padnie, zamiast zwracać sam BOM. Wynik, który kłamie cicho, jest gorszy od głośnej porażki, a klasa już rzuca w `addRow()`. Koszt: eksport zwraca 500 w sytuacji wymagającej wyczerpania pamięci.
- **R3.** Runda 3 otwarta, bo **moje polecenie z rundy 2 było za węskie**, nie bo wykonawca coś pominął: dwie linie niżej `(string) stream_get_contents()` dawało tę samą ciszę. Koszt: jedna tania runda na zatwierdzonym zadaniu.
- **R4.** Wyniki `fputcsv()` i `rewind()` zostają niesprawdzane. Na buforze w pamięci `fputcsv` pada praktycznie tylko przy wyczerpaniu pamięci, co kończy się fatalem, a `rewind` na świeżym `php://temp` nie ma trybu porażki. Koszt: zapis, który cicho uciąłby dokument, dałby krótki plik zamiast wyjątku.
- **R5.** Runda poprawek na teście zakładającym dedupe bez asercji (sekcja 6) i na przywracaniu `suppress_errors`.
- **R6.** Napis „Odrzucono wniosków", gdy padnie zapis logu przy **korekcie** powodu, zostaje. Rozróżnienie wymagałoby osobnego kodu dla każdej kombinacji ścieżki i porażki, a jeden kod zwracany nie uniesie dwóch faktów; redaktor i tak dostaje ostrzeżenie o luce w audycie. Koszt: korekta z padniętym logiem jest raportowana jako odrzucenie.
- **R7.** Zduplikowany pomocnik filtra `query` w dwóch plikach testowych zostaje zduplikowany; trzecia kopia jest sygnałem do wyciągnięcia.
- **R8.** Wiadomość commita podpisaną innym modelem poprawiłem sam, bez otwierania rundy. Jednolinijkowa poprawka metadanych nie jest warta dispatchu i miejsca recenzenta. Koszt: SHA jednego commita różni się od tego w raporcie wykonawcy (uprzedziłem recenzenta).
- **R9.** Runda na dwóch pozycjach **tylko testowych**, z których pierwsza jest nośna dla **późniejszego** zadania (brak pokrycia wielu wierszy w `anonymize_subject()`, z którego Zadanie 8 miało wyprowadzać zatrzymanie pętli). Najtaniej zamknąć przed tym, co na tym stanie.
- **R10.** `anonymize_subject()` zwraca wiersze **zmienione**; w sekwencyjnej pętli, którą faktycznie wykonuje narzędzie prywatności rdzenia, wybrane i zmienione są tożsame. Koszt: liczby rozeszłyby się tylko przy dwóch erazerach działających jednocześnie dla jednego podmiotu, czemu i tak zapobiega warunkowy UPDATE. (Wycofane przez R12 — poprawność pętli nie opiera się już na tej liczbie wcale.)
- **R11.** **Poprawiam własne rozstrzygnięcie:** anonimizacja anuluje wiersz, który nigdy nie wyszedł (`failed` + bezosobowy marker), a wiersz `sent` zachowuje stan i czas. W logu wysyłek wartością jest fakt, że mail wyszedł, więc wiersz, który nie wyszedł i nigdy nie wyjdzie, musi to mówić, zamiast udawać, że czeka. Koszt: jedna kolumna więcej w zapisie i anulowany wiersz widoczny jako „nieudany".
- **R12.** Milcząco ignorowane `false` z `$wpdb->query()` zostaje, a problem rozwiązuje Zadanie 8: eraser wyprowadza `done` z tego, czy **zostały** wiersze z `anonymized = 0`, nie z tego, ile właśnie zmienił. Koszt: jeden tani COUNT na przebieg. (**Nie wykonane w Zadaniu 8 — zob. R23.**)
- **R13.** `requeue_failed()` ożywia tylko wiersze z `anonymized = 0`, zamknięte tutaj, a nie odłożone do ekranu, bo defekt stworzyła moja własna poprawka. Koszt: wiersz anulowany erasure nie da się już wysłać — co jest zachowaniem zamierzonym.
- **R14.** `last_error` nadpisywany dla **każdego** wiersza: `NULL` dla `sent`, marker dla reszty (sekcja 2). Koszt: wiersz wysłany traci tekst diagnostyczny przejściowej awarii, na wierszu, którego treść i tak już nie ma.
- **R15.** Rozstrzygnik stronicowania naprawiony tutaj, nie odłożony jako „plik innego planu" (sekcja 2). Koszt: jeden klucz sortowania więcej w zapytaniu, którego używa też ekran.
- **R16.** Kolumna „Stan" w CSV dla administratora trzyma **surowy kod** (`pending`), nie przetłumaczoną etykietę. Plik idzie do arkuszy i dalszego przetwarzania; kod jest stabilny, etykieta zmieni się przy pierwszej poprawce tłumaczenia i rozwali komuś filtry. Udokumentowane w docbloku.
- **R17.** `ExportHandler::headers()` zostaje publiczny, a test czyta zwróconą listę, nie nagłówki faktycznie wysłane — w SAPI CLI `headers_list()` jest puste, więc innej drogi nie ma, a publiczna metoda jest uczciwszym szwem niż refleksja.
- **R18.** `flatten` zostaje zduplikowany w dwóch kopiach (eksport i panel szczegółów); wyciąganie do domeny przy dwóch jest przedwczesne.
- **R19.** `UserMeta::REJECTION_REASON` **musi** być eksportowany — defekt mojego briefu (3.2). Koszt: jedna para więcej w grupie konta, wydawana tylko gdy niepusta.
- **R20.** Eksport dla podmiotu **tłumaczy** stan konta i status maila, inaczej niż CSV dla administratora z R16 — adresat jest inny, rdzeń renderuje to jako raport dla osoby, a `pending` nie mówi lekarzowi nic. Mapa prywatna w `PrivacyProvider`, z `default => $code`, żeby kod spoza enuma wyszedł surowy, nie pusty. `action` zdarzenia zostaje surowe: to identyfikator, nie proza. Koszt: etykieta i kod mogą się rozejść po zmianie tłumaczenia.
- **R21.** Zanonimizowane wiersze dostają jawną parę „Zanonimizowano" (sekcja 5).
- **R22.** Puste pary dla konta bez zgody i bez IP **zostają**. Brak wartości też jest odpowiedzią dla żądającego; pominięcie wiersza kazałoby mu zgadywać, czy danej nie ma, czy jej nie sprawdzono.
- **R23.** **R12 nigdy nie zostało wykonane, i to mój błąd podwójnie** (3.1). Koszt, gdyby weszło na produkcję: eraser RODO zgłaszający sukces z danymi zostawionymi w bazie.
- **R24.** Fala poprawek obejmuje wszystkie cztery pozycje „Important" recenzji całej gałęzi plus pięć pozycji triage'u przed scaleniem i sześć tanich porządków, jednym dispatchem, w kolejności priorytetów recenzenta.

---

## 8. Co zostaje na później

W backlogu, sekcja „Plan 7 — Eksport i RODO: co zostało po realizacji": retencja sekretów (1.1), IP w wierszach z podmiotem `0` (1.2), wyścig anonimizacji z wysyłką (1.3). W sekcji „Plan 8 — Import i backfill stanów": backfill stanów przy aktywacji oraz ostrzeżenie, że eksport, eksporter i eraser widzą **tylko** pola z grupy ACF — import zapisujący poza grupą będzie dla wszystkich trzech niewidoczny. Drobiazgi z tego planu dopisane do sekcji „Świadomie odłożone drobiazgi".

Plan 8 (import i backfill stanów) i Plan 9 (aktualizacje i release) są w backlogu, ale nie napisane.
