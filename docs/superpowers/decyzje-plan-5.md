# Plan 5 — decyzje podjęte w trakcie wykonania

Dziennik wykonania (`.superpowers/sdd/`) jest kasowany razem z workspace'em, więc rozstrzygnięcia przenoszę tutaj, wzorem Planów 3 i 4. Każde ma powód i koszt pomyłki — to jedyne miejsce, w którym decyzje podjęte za Ciebie są do przejrzenia i odwrócenia.

**Zakres:** gałąź `plan-5-maile`, 24 commity ponad `main` @ `3aaf1ca`. Dziesięć zadań, dziesięć recenzji zadaniowych, dziewięć rund poprawek, recenzja całej gałęzi i jedna fala po niej. Bramki na drzewie do scalenia: unit **234**, integration **873**, PHPStan level max czysto, PHPCS zero zgłoszeń.

---

## 1. Pięć rzeczy, które musisz rozstrzygnąć Ty

### 1.1. Snapshot maila niesie żywy token, a tabela trzyma go 90 dni

Kolejka renderuje treść w chwili zapisu — to wzorzec, którego specyfikacja żąda wprost („render treści przy zapisie"). Skutek: wiersz maila potwierdzającego zawiera **żywy token 48-godzinny**, a wiersz odzyskiwania hasła **klucz rdzenia WordPressa**. Oba są czytelne na ekranie logu wysyłek i leżą w tabeli z oknem 90 dni.

To **nie** jest podniesienie uprawnień: ekran wymaga `manage_options`, a specyfikacja mówi, że osobie z redakcji podnosi się rolę ręcznie — każdy, kto to widzi, i tak może zmienić hasło z `user-edit.php`. To decyzja o danych w spoczynku: **kopia bazy zrobiona w okresie ważności tokenu daje działające linki przejęcia konta.**

Pytanie dla Ciebie: czy rodzaje `confirmation` i `password_reset` mają mieć okno retencji liczone w dniach, a nie 90? Ich wartość dowodowa wygasa razem z tokenem. Do czasu odpowiedzi obowiązuje 90 dni dla wszystkich rodzajów.

### 1.2. Ostrzeżenie o stojącej kolejce jest za bramką ACF Pro, a dispatcher nie

Dispatcher, przechwytywanie maila odzyskiwania i cron retencji stoją **poza** sprawdzeniem ACF Pro — świadomie, bo kolejka musi się opróżniać, nawet gdy ACF zniknie. Ostrzeżenie o zalegającej kolejce stoi **za** tą bramką, jak pozostałe ekrany panelu.

Skutek: na portalu, na którym ACF Pro padnie **po** zakolejkowaniu maili i jednocześnie nie działa WP-Cron, maile stoją, a nikt nie dostaje ostrzeżenia. Okno jest wąskie (rejestracja i odzyskiwanie też są za bramką, więc bez ACF prawie nic nowego nie wchodzi do kolejki), ale to jedyna ścieżka, na której wraca problem „nikt się nie dowiaduje".

### 1.3. Ostrzeżenie może krzyknąć na zdrowym, ale zajętym portalu

Próg ostrzeżenia to 15 minut zaległości najstarszego wiersza, a dispatcher wysyła 20 maili na przebieg. Zaległość dłuższa niż około 300 wierszy stawia więc najstarszy termin ponad 15 minut w przeszłości, **choć kolejka opróżnia się normalnie** — i komunikat powie „kolejka nie jest opróżniana", wskazując WP-Cron jako najczęstszą przyczynę.

To nie regresja (przedtem był tam brak jakiejkolwiek informacji), a treść mówi „najczęstsza przyczyna", nie „przyczyna". Ale jeśli Twoje portale wysyłają maile pakietami po kilkaset, próg trzeba podnieść albo dołożyć warunek „i nic nie wyszło od dawna".

### 1.4. Redaktor nie widzi, dlaczego numer uprawnień nie przeszedł walidacji

Plan 3 wysyłał do redakcji surowy kod walidatora (`invalid_format`, `invalid_checksum`). Usunąłem go z maila (R17): to nieprzetłumaczony żargon maszynowy w polskiej wiadomości do człowieka, a specyfikacja stawia walidację jako miękką i oddaje weryfikację człowiekowi.

Dziś redaktor widzi `poprawny` albo `niepoprawny`. Siedmiocyfrowy numer oblewający **sumę kontrolną** to niemal zawsze literówka, a oblewający **format** to zwykle numer innego rodzaju — i te dwa przypadki wymagają od człowieka różnej reakcji. Rozróżnienie przeniosłem na ekran wniosków z Planu 6, który pokazuje wynik walidacji z linkiem do rejestru. **Trzeba Planu 6 z tego rozliczyć**, inaczej ta informacja nie wraca nigdzie.

### 1.5. Powtarzane sondowanie jednego adresu zostawia jeden ślad na godzinę

Mail „ktoś próbował zarejestrować Twój adres" ma klucz odporności z kubełkiem godzinowym, a wiersz w logu zdarzeń powstaje tylko wtedy, gdy mail poszedł (reguła Planu 3: „do logu trafia tylko wysłana wiadomość"). Skutek: trzy próby w jednej godzinie dają w audycie **jeden** ślad, nie trzy.

Zostawiłem to świadomie (R20): specyfikacja czyni `event_log` logiem **decyzji**, zliczanie prób należy do limitera, a rozszerzanie nieuwierzytelnionego zapisu do tabeli audytu to defekt, który ten projekt zapłacił już w Planie 4. Liczniki limitera widzą wszystkie próby — ale w audycie ich nie ma.

---

## 2. Sześć razy, kiedy mylił się plan, który sam napisałem

1. **Lista zmiennych zapomniała o linku odzyskiwania** (R8). Mail o istniejącym koncie ma od Planu 3 test asertujący obecność `wp_lostpassword_url()`. Bez zmiennej ten test padłby w Tasku 5, a właściciel konta, do którego ktoś próbował się zarejestrować, stracił jedyną podaną w mailu drogę odzyskania hasła. Zgłosił to wykonawca; sprawdziłem i potwierdziłem.
2. **Plan kazał dokładać supresje phpcs, które nic nie wyciszają** (R13). `WordPress.DB.DirectDatabaseQuery` nie jest w zestawie reguł tego projektu — sprawdziłem: `phpcs --sniffs=…` odpowiada `No sniffs were registered`. Jedenaście z dwunastu adnotacji w nowym pliku było fałszywym zdaniem o tym, co sprawdza narzędzie.
3. **Plan twierdził, że bramka `if ( ! $message )` stoi przed filtrem treści** (Task 7). Stoi za nim (`user.php:3436` kontra `:3439`), więc rdzeń nie wysyła pustego maila, tylko nie wysyła nic i zgłasza sukces. Wniosek dla implementacji ten sam, uzasadnienie w planie błędne. Wyłapał wykonawca.
4. **Plan podpisywał weryfikacje rdzenia jako „WordPress 6.9"**. Kontener ma **7.1.3**. Same twierdzenia o numerach linii były sprawdzane w tym kontenerze, więc są prawdziwe — etykieta nie.
5. **Plan zostawił dług „literał hooka zamienimy na stałą w Tasku 6"** (R1). Test Taska 5 odwoływał się przy tym do klasy, która powstaje dopiero w Tasku 6. Nazwę hooka przejęła kolejka — dokładnie tak, jak robi to wtyczka, z której specyfikacja każe przenieść ten wzorzec.
6. **Plan odrzucił część tekstową maila jako YAGNI** (R30). Mylił się: wiadomość wyłącznie HTML bez części alternatywnej to rozpoznawany sygnał spamowy na dokładnie tych filtrach, o których mówią Ograniczenia specyfikacji, a uzasadnieniem dla tekstu zwykłego jest tam „tekst rzadziej wpada w filtry". Koszt naprawy: trzy linie w już istniejącym `try`.

## 3. Dwa razy, kiedy mylił się mój własny wyrok

1. **R10 → R14.** Przyjąłem, że `requeue_failed()` może czyścić powód poprzedniej porażki („stary błąd obok wiersza w kolejce myli"). Recenzent doszedł niezależnie do wniosku przeciwnego i miał mocniejszy argument: specyfikacja czyni tę tabelę **logiem wysyłek**, a powód porażki jest w wierszu najbardziej informacyjną rzeczą; kolumna stanu stoi obok i czyta się jako „nie udało się, ponawiamy". Odwróciłem.
2. **Dyspozycja Taska 6 kazała mierzyć zaległość po `created_at`** (R33). Wykonawca pokazał mutacją, że moja wersja **kłamie**: „Wznów" na starym wierszu nie rusza `created_at`, więc zdrowa kolejka dostawałaby ostrzeżenie „termin minął przed 43200 minutami". Dodatkowo `MIN(scheduled_at)` z równością na statusie jest pełnym prefiksem indeksu i baza rozwiązuje je bez czytania wierszy — czyli koszt, który nie może rosnąć akurat na kolejce, która stoi. Przyjąłem odstępstwo.

Do tego jedna pomyłka w moim briefie: twierdziłem, że test z Taska 1 przypina stałe `Mailer::KIND_*` do enuma. Przypinał tylko wartości enuma. Wykonawca dołożył prawdziwy test.

## 4. Pięć zachowań, które uderzyłyby w człowieka

1. **Mail odzyskiwania hasła mógł zniknąć bez śladu, a żądanie raportowało „wysłano"** (R23). `capture()` odrzucał wynik kolejkowania i zawsze zwracał sukces, a komentarz obok obiecywał, że awaria będzie widoczna administratorowi — co było prawdą tylko dopóki wysyłał rdzeń.
2. **Awaria bazy przy kolejkowaniu była nierozróżnialna od duplikatu** (R28). Strona rejestracji mówiła lekarzowi „sprawdź skrzynkę" dla maila, który nie istniał nigdzie: ani wiersza kolejki, ani wiersza logu. To jedyne miejsce w gałęzi, gdzie pytanie „czy ten mail wyszedł" nie miało żadnej odpowiedzi — czyli dokładnie to, dla czego specyfikacja żąda logu wysyłek.
3. **Adres redakcji dłuższy niż 191 znaków** przechodził `is_email()`, był odrzucany przez kolejkę, a redakcja nigdy nie dowiadywała się, że lekarz złożył wniosek.
4. **Każdy stan konta inny niż `pending` dostawał mail „konto zostało zatwierdzone, możesz się zalogować"** (R25) — w tym konto odrzucone i `null` z nieudanego odczytu metadanej. Brakujący mail jest odwracalny, fałszywe zatwierdzenie nie.
5. **Domyślny mail do redakcji przestał mówić, czy numer uprawnień się waliduje** (R16) — w wiadomości, której jedynym celem jest podanie człowiekowi rzeczy do ręcznej weryfikacji. Zasłaniał to przepisany test, który asertował na szablonie zapisanym przez sam test.

A także dwa, które wyszły z tej gałęzi jako nowe ryzyko i zostały domknięte w fali poprawek: **wysyłka zależna od WP-Cron, o której nikt się nie dowiaduje, gdy cron padnie** (R29), i **powolna, ale udana wysyłka uznawana za porzuconą, bo próg odzysku był równy limitowi czasu PHPMailera** (R27) — lekarz dostawał ten sam link dwa razy.

## 5. Testy bez zębów, złapane w trakcie

Plan 4 zebrał czternaście takich przypadków; tutaj było ich siedem, co jest poprawą, ale nie zerem.

1. **Przepisany test asertował na szablonie, który sam zapisał** — napis asercji przeżył, przedmiot się zmienił, a regresja treści domyślnej schowała się za tym. Najcenniejsze trafienie tego planu.
2. **Próg odzysku zawieszonych wierszy**: cała suita przechodziła także po **usunięciu** linii progu i po **odwróceniu znaku**, a odwrócony znak wraca do kolejki wiersze trzymane przez żywy przebieg (R21).
3. **Test rejestracji ekranu porównywał stałą z samą sobą**, obiecując nazwą rejestrację. Zastąpiony, a potem jego następca okazał się przechodzić również przy pustym `register()`, bo hooki były już podpięte bootem wtyczki — domknięte dowodem mutacyjnym.
4. **`RetryPolicy::MAX_ATTEMPTS` nie było konsumowane przez żaden kod produkcyjny**, a jego test przechodził dla dowolnej wartości ≥ 3: podniesienie stałej bez dopisania opóźnień dałoby ciche skrócenie liczby prób.
5. **Moje własne rozstrzygnięcie R8 było niezapięte asercją** w pliku, w którym je wprowadzono — zamknięte dopiero w Tasku 5.
6. **Test o koncie bez adresu** przechodził tylko gałąź „konto nie istnieje", nigdy gałęzi „konto bez adresu", choć nazwa obiecywała drugą.
7. **Test skrócenia komunikatu błędu asertował samą nieobecność** długiego ciągu, więc przeszedłby też dla kolumny, która nie renderuje nic.

---

## 6. Wszystkie 35 rozstrzygnięć

Każde w formie *co — dlaczego — koszt pomyłki*. R1–R7 to skan konfliktów przed wykonaniem, R27–R35 to recenzja całej gałęzi i fala po niej.

| # | Co rozstrzygnąłem | Koszt pomyłki |
|---|---|---|
| R1 | Nazwę hooka natychmiastowej wysyłki posiada kolejka, nie klasa crona | jedna zmiana nazwy |
| R2 | Task 5 nie tworzy trzech nowych metod maila nawet jako zaślepek | Task 8 podaje sygnatury sam |
| R3 | `RetryPolicy::next()` dostaje liczbę prób **po** zajęciu wiersza | dwie albo cztery próby zamiast trzech |
| R4 | Numer uprawnień ignoruje widoczność w macierzy, pola zgłoszenia ją respektują | pada test Planu 3 albo wycieka pole ukryte |
| R5 | Filtr maila odzyskiwania odnajduje konto przez login; brak konta = wejście nietknięte | mail idzie treścią rdzenia i nie trafia do logu |
| R6 | Minutowy kubełek klucza odporności czytany przez wewnętrzny szew testowy | jeden filtr, którego nikt poza testami nie woła |
| R7 | Filtr zwracający nie-tekst zostawia wartość domyślną (gwarancja Planu 3) | obca wtyczka wstawia mail z pustą treścią |
| R8 | `existing_account` dostaje zmienną `link_odzyskiwania` | właściciel konta traci drogę odzyskania hasła |
| R9 | Białe znaki w wyrenderowanym **temacie** zwijane po filtrze | nowy wiersz w temacie = wstrzyknięcie nagłówka |
| R10 | *(odwrócone przez R14)* | — |
| R11 | `claim()` oddaje numer próby; zapisy wyniku warunkowe na statusie **i** numerze | wiersz wisi w `sending` i mail wychodzi z opóźnieniem jednego cyklu |
| R12 | Klucz odporności trzymany w granicy indeksu 191 znaków | ucięty klucz koliduje i mail nie idzie nigdy |
| R13 | Tylko supresje phpcs, które coś wyciszają | nowy plik odstaje stylem od dwóch starych |
| R14 | `requeue_failed()` **zachowuje** powód porażki (odwraca R10) | ekran musi opisać kolumnę tak, by nie sugerowała bieżącej awarii |
| R15 | `claim()` zwraca `null`, gdy odczyt licznika nie dał liczby dodatniej | wąskie okno na dublowaną wysyłkę |
| R16 | Werdykt walidacji wraca do **domyślnej** treści maila do redakcji, test asertuje na niej | jedna linia więcej w mailu |
| R17 | Surowy kod walidatora nie wraca do maila | redaktor nie wie, czy to zła długość, czy przestawione cyfry |
| R18 | Stała `KIND_*` na każdy z ośmiu rodzajów, test iteruje po enumie | pięć stałych, których nikt nie użyje |
| R19 | `wynik_walidacji` może być pusty w gałęzi „konta już nie ma" | mail dla usuniętego konta pokazałby etykietę bez wartości |
| R20 | Ślad sondowania adresu zostaje przy regule Planu 3 | jeden ślad w audycie na godzinę zamiast trzech |
| R21 | Dwa testy na **użycie** odzysku w dispatcherze, nie tylko na metodę | dwa krótkie testy więcej |
| R22 | Filtr typu treści na `PHP_INT_MAX`, nie nagłówkiem | wtyczka SMTP wymuszająca tekst przestaje to robić dla naszych maili |
| R23 | Odrzucone kolejkowanie zostawia wiersz w logu zdarzeń | jeden wiersz logu na nieudane kolejkowanie |
| R24 | Przechwytywanie wymaga zgodności **treści**, nie tylko adresu | mail zmodyfikowany przez obcą wtyczkę nie trafia do logu |
| R25 | Jawne dopasowanie `pending`/`approved`, każdy inny stan = żaden mail | wnioskodawca nie dostaje maila i pyta redakcję |
| R26 | Cron retencji poza bramką ACF Pro | log czyszczony na instalacji poza tym bezczynnej |
| R27 | `STALE_AFTER` 300 → **900**, musi przekraczać limit czasu transportu | porzucony wiersz czeka 15 minut zamiast 5 |
| R28 | Awaria wstawienia rozróżnialna od duplikatu i zapisana w logu | kilka wierszy logu na rzadkie awarie |
| R29 | Ostrzeżenie o stojącej kolejce **w tej fali**, nie w Planie 7 | jedna klasa i jedno zapytanie na wejście w panel |
| R30 | Część tekstowa maila wraca (odwraca decyzję planu) | nieco większa wiadomość |
| R31 | Goły `<` przywracany w treści szablonu po sanityzacji | znacznik i tak nie działa, zostaje zeskejpowany przy wysyłce |
| R32 | Dwie pozycje danych osobowych idą do backlogu Planu 7 | Plan 7 odkrywa je sam albo nie odkrywa wcale |
| R33 | Zaległość mierzona po `scheduled_at`, nie `created_at` (odstępstwo przyjęte) | ostrzeżenie patrzy na termin, nie na wiek wiersza |
| R34 | Pole wielowartościowe dojeżdża jako lista; defekt był gorszy, niż go opisano | — (korekta ustalenia) |
| R35 | Temat szablonu dalej psuje goły `<`; `CoreCapture` loguje też odmowę z odporności | jeden mylący wiersz w logu w sytuacji nieosiągalnej |

---

## 7. Co zostaje na później

Zapisane w `docs/superpowers/backlog.md` pod Planem 7, plus pozycje poniżej.

**Dla Planu 6** (ekran wniosków):
- Powód odrzucenia musi trafić do `event_log` — specyfikacja mówi „Powód trafia do maila i do logu", a połowa mailowa jest zrobiona, logowa nie istnieje nigdzie i nic jej tu nie wymusza.
- Rozróżnienie `invalid_format` / `invalid_checksum` na ekranie wniosków (patrz 1.4).
- `{link_wniosku}` wskazuje dziś `user-edit.php`; ekran wniosków ma przejąć ten adres.

**Dla Planu 7** (RODO): anonimizacja logu wysyłek musi nadpisać `recipient`, `subject` **i** `body`, nie tylko ustawić `anonymized = 1` — treść maila redakcyjnego niesie imię, adres, profesję, numer uprawnień, wynik walidacji i każde widoczne pole zgłoszenia. Do tego pytanie dla eksportera: czy treści z kolejki są danymi do wydania, czy własną dokumentacją administratora.

**Drobne, świadomie odłożone:** próg ostrzeżenia równy progowi odzysku (patrz 1.3); ostrzeżenie za bramką ACF (1.2); temat szablonu psujący goły `<`; `sanitize_textarea_field()` usuwający `<` razem z tekstem, gdy dalej w linii stoi `>`; wiersz ginący w pętli `sending` przy fatalu PHP jest niewidoczny dla ostrzeżenia i dla odzysku; pięć zapytań `COUNT` na render zakładek; brak „Wznów" w widoku szczegółów; brak testu wyścigu na dwóch połączeniach.

**Poza zakresem Planu 5, ale warte wiedzy:** `.gitattributes` deklaruje `* text=auto eol=lf`, a wszystkie bloby w repozytorium — także z Planów 1–4 — trzymają CRLF. Martwe supresje `WordPress.DB.DirectDatabaseQuery` zostały w `TermsRepository.php` i `Uninstaller.php` z Planu 1. Ani `uninstall.php`, ani `Uninstaller` nie zdejmują harmonogramów cronów — wszystkie trzy polegają na hooku deaktywacji, co przy ręcznym usunięciu plików zostawia osierocone zdarzenie. Dokumentacji konfiguracji SMTP, której żąda sekcja „Dostarczalność", nie ma nigdzie — należy do Planu 8.
