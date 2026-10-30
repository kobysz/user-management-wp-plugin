# Decyzje i ustalenia Planu 8 (import i backfill stanów)

Zapis z wykonania `docs/superpowers/plans/2026-10-29-portal-users-import-backfill.md` metodą subagent-driven: dziewięć zadań, dziewięć recenzji zadaniowych, czternaście rund poprawek w zadaniach, recenzja całej gałęzi (opus) z werdyktem **„niegotowa"**, jedna fala poprawek i jedna re-recenzja zakresowa. Ledger wykonania był git-ignorowany i został usunięty; to jest to, co z niego zostaje.

Bramki na drzewie do scalenia: unit **336** (812 asercji), integration **1188** (4587), PHPStan level max czysto, PHPCS zero zgłoszeń, `php -l` na **PHP 8.1** po wszystkich 111 plikach `src/` bez porażki. Wejście planu: unit 261, integration 1058 — Plan 8 dołożył 75 testów jednostkowych i 130 integracyjnych.

Zakres: pięć klas domenowych importu (`MetaKeyStat`, `ImportMapping`, `AccountChange`, `ImportReport`, `ImportPlanner`), skaner kluczy `user_meta`, pamięć mapowania i podglądu, przebieg importu w trybie próbnym i z zapisem, ekran panelu z raportem i bramką odcisku, oraz backfill stanów dla kont sprzed wdrożenia.

---

## 1. Co zostaje do Twojej decyzji

Sześć rzeczy. Pierwsze dwie są ważne.

### 1.1. Import nie ma budżetu czasu i nie da się go wznowić

Cały przebieg idzie w jednym żądaniu POST z panelu, a `WP_User_Query` z `fields => 'ID'` nie podgrzewa cache'u metadanych, więc na każde konto wypada jedno `get_user_meta()`. Przy kilku tysiącach kont domyślny limit czasu PHP przerywa przebieg w połowie. Ponieważ podgląd jest **zużywany przed** przebiegiem (i to jest celowe — inaczej dwa kliknięcia dawałyby dwa wiersze audytu za jedną decyzję), administrator zostaje wtedy z częścią kont zapisanych, bez raportu i bez komunikatu.

Naprawiłem **połowę**: przed przebiegiem powstaje wiersz `import_started` z aktorem i listą kluczy źródłowych, więc przerwanie zostawia ślad, kto co uruchomił. Wznawiania **nie** budowałem — to funkcja własnej wielkości, a specyfikacja o nią nie prosiła. **Do rozstrzygnięcia:** przebieg ograniczony czasem, kontynuowany od ostatniej strony przy następnym POST, albo zadanie w harmonogramie. Pozycja jest w backlogu.

### 1.2. Mapowanie klucza rdzenia WordPressa poszerza „członkostwo" na cały portal

Po naprawie krytyka import rusza stan tylko kontom, które noszą co najmniej jeden zmapowany klucz źródłowy — bo konto bez żadnego z nich nigdy nie było w starej wtyczce. Ale skan **celowo** pokazuje też klucze rdzenia (`first_name`, `last_name`, `description`), żebyś widział, co na portalu faktycznie jest. Administrator, który zmapuje pole ACF na `first_name`, czyni „członkiem" każde konto z imieniem — i przy zapasie „oczekujący" degraduje w ten sposób konta zatwierdzone przez samą wtyczkę.

Od naprawy **nie jest to już ciche**: `status_missing` trafił na listę poważnych zastrzeżeń, więc tryb próbny pokazuje takiemu administratorowi liczbę w tysiącach i ostrzeżenie **przed** zapisem. Trzeba świadomie zmapować klucz rdzenia, wybrać zapas inny niż „zostaw bez stanu" i przejść obok głośnego ostrzeżenia. **Do rozstrzygnięcia:** czy skan ma przestać pokazywać klucze rdzenia, czy formularz ma ich nie przyjmować jako źródła, czy zostaje jak jest. Moja decyzja z Zadania 4 — że skan pokazuje, co jest, a nie naszą opinię o tym — jest tym, co otwiera tę drogę, i jej kosztu wtedy nie przewidziałem.

### 1.3. Specyfikacja oferuje przy imporcie **usunięcie** konta, czego ten plan nie zbudował

„Konta, których poprzednia wtyczka nie zatwierdziła, trafiają na listę wniosków **albo zostają usunięte**, zależnie od decyzji administratora przy imporcie." Import nigdy nie usuwa konta. Drogę przypadkową zamknąłem świadomie (mapowanie na `niepotwierdzony_mail` jest zabronione, bo cron kasuje konta w tym stanie), ale opcji świadomej nie dostarczyłem i sekcja odstępstw w planie tego nie nazwała. Gdybyś jej chciał, to osobne zadanie.

### 1.4. Ostrzeżenie o kontach bez stanu widać tylko na ekranach wtyczki

Świeży instalator ląduje na pulpicie albo na liście wtyczek i tam go nie zobaczy. Plan prosił o takie zawężenie; rozszerzenie na pulpit to decyzja produktowa.

### 1.5. Źródłowy klucz da się wybrać tylko ze skanu, a skan pokazuje pięćdziesiąt najczęstszych

Na portalu z większą liczbą kluczy rzadszego nie da się zmapować z panelu. Dwa domknięcia: pole tekstowe na dowolny klucz albo wyższy limit.

### 1.6. Przycisk backfillu jest dostępny bezterminowo

Plan dał bezterminowo, bo portal może importować w dwóch rzutach. Jeżeli ma gasnąć po pierwszym imporcie, to jedna bramka.

---

## 2. Co ten plan odkrył w kodzie, którego nie dotykał

- **Wtyczka nie dawała się sparsować na PHP 8.1, czyli na swojej własnej wersji minimalnej** (R16). `ImportMapping::ALLOWED_STATUSES` budowało wyrażenie stałe z `AccountStatus::Pending->value`, a odczyt wartości przypadku enuma w wyrażeniu stałym wymaga PHP 8.2. Zmierzone na obu stronach: kontener z 8.1.34 daje `Constant expression contains invalid operations`, host z 8.2.12 lintuje czysto. Na portalu z 8.1 wtyczka byłaby martwa w chwili wczytania pliku. Przeszło przez trzy zadania zielonych bramek, bo suita jednostkowa i PHPStan chodzą na hoście z 8.2, a żaden test integracyjny nie wczytał dotąd tej klasy.
- **`<config name="testVersion" value="8.1-"/>` w `phpcs.xml.dist` jest martwe.** PHPCompatibility nie jest zainstalowane: nie ma go w `vendor/`, nie ma w `phpcs -i`, nie ma w `composer.json`. Ta linia czyta się jak straż wersji minimalnej i nie strzeże niczego — dlatego błąd parsowania na 8.1 przeżył cztery zadania. Pozycja w backlogu.
- **`update_user_meta()` zwraca `false` także wtedy, gdy wartość jest identyczna** (R23 i kontekst). Zmierzone w `update_metadata()`: funkcja porównuje istniejącą wartość i wychodzi **przed** zapisem. Gołe `false` nie mówi więc nic — stąd przymus porównania przed zapisem i odczytu kontrolnego po.
- **`update_metadata()` przepuszcza wartość przez `wp_unslash()`**, więc `a\b` zapisywało się jako `ab`. Zmierzone: zapis, odczyt, `bool(false)` na porównaniu.
- **`esc_html()` jest idempotentne** (`_wp_specialchars` z wyłączonym podwójnym kodowaniem). Zmierzone, bo wcześniej zapisałem o tym nieprawdę.

---

## 3. Gdzie mój plan się mylił

### 3.1. Import mógł zamknąć portal przed wszystkimi, administratora włącznie (R37)

Najpoważniejszy defekt tego planu i mój własny. `ImportPlanner::planStatus()` zwracał zapasowy stan dla **każdego** konta, któremu brakowało zmapowanej wartości statusu, a `WP_User_Query` przebiegu nie miał żadnego filtra — ani `meta_query`, ani `date_query`, ani roli. Skutki, potwierdzone przeze mnie na kodzie przed naprawą:

- przy zapasie „oczekujący" każde konto zatwierdzone przez samą wtyczkę (nie nosi kluczy starej) dostawało `pending`, `SessionGuard` niszczył mu sesje, a brama odmawiała następnego logowania — **razem z kontem administratora**, więc odratowanie wymagałoby dostępu do bazy;
- przy zapasie „zatwierdzony" bez zmapowanego klucza statusu wszystkie konta portalu były zatwierdzane po cichu, w tym **odrzucone przez redaktora** i te, które nigdy nie potwierdziły adresu;
- lista poważnych zastrzeżeń pomijała `status_missing`, więc nie zapalało się nawet ostrzeżenie.

Bliźniaczy backfill w tym samym planie dostał starannie wyznaczoną granicę (brak stanu **i** rejestracja przed wdrożeniem) właśnie z tego powodu. Import nie miał ani jednej połowy tej reguły, bo mój brief opisał zapas, nie ograniczając, czyjego stanu wolno mu dotknąć. Naprawa: zapas obowiązuje tylko konto noszące co najmniej jeden zmapowany klucz źródłowy z użyteczną wartością skalarną; konto bez żadnego jest poza zakresem — bez stanu, bez pól i **bez jakichkolwiek zastrzeżeń** — liczone w „przeskanowanych" i w niczym więcej. Testy są na poziomie **portalu**, nie jednego konta.

### 3.2. Zapis formularza niszczył mapowanie, którego formularz nie mógł pokazać (R24)

Ekran składował przycięty widok, co zamieniało projekcję z odczytu w operację niszczącą. Poważny przypadek to nie usunięte pole, a **zła nazwa grupy ACF w ustawieniach**: `AcfFieldSource::fields()` nie zwraca wtedy nic, więc literówka plus jeden zapis niszczyły **całe** mapowanie pól, bez ostrzeżenia i bez niczego, czym to cofnąć.

### 3.3. „Pusty zapis nic nie zapisuje" zamykało administratora w pułapce (R27)

Reguła z mojego briefu, połączona ze scalaniem, odbierała jedyną czynność, której po złym mapowaniu chce się najbardziej: wyczyszczenia go. Pusty formularz przy niepustym mapowaniu jest teraz świadomym czyszczeniem.

### 3.4. „Odrzucaj klucze nie-stringowe" było niewykonalne (R6)

PHP zamienia `'1'` na `int 1` w kluczu tablicy, a stare wtyczki trzymają status jako `0`/`1` nieustannie. Reguła z briefu czyniłaby mapowanie bezużytecznym dla najczęstszego kształtu, jaki spotka.

### 3.5. `grant_approved()` miało zwracać `int` (R34)

Zwraca `array{ granted, not_granted, log_failed }`. Backfill, który po cichu traci wiersz audytu albo przemilcza konta, którym zapis nie wyszedł, to ten sam defekt, do którego raportowania zmusiliśmy import.

---

## 4. Gdzie myliłem się w poleceniach dla wykonawców

- **Trzy luki w briefie Zadania 1**: nie przypiąłem kształtu `issueSample()`, `toArray()` ani typu `$fields`. Wykonawca słusznie odmówił zgadywania.
- **Wyciszone ostrzeżenie ukrywało brakującą funkcję** (R2): `withWriteFailure()` brało identyfikator i wyrzucało go, a wykonawca uciszył ostrzeżenie gołym `phpcs:ignore`. Zadanie 6 loguje porażki jako „liczbę **i próbkę identyfikatorów**" — identyfikator był potrzebny.
- **Rozwiązanie zalecone przez recenzenta odrzuciłem** (R4): `mb_scrub()` wszedł w PHP 8.3, a wtyczka deklaruje 8.1. Wzięte dosłownie, byłoby fatalem na wspieranej wersji.
- **Konwencję odcisku ustaliłem dopiero po podpowiedzi recenzenta** (R19): gdyby jedna strona bramki liczyła odcisk z surowej opcji, a druga z przyciętej, bramka odmawiałaby **każdego** importu na zawsze, myląc się w bezpieczną stronę i wyglądając jak zagadka.
- **Dwie moje własne korekty**, zapisane w dzienniku: że `mb_convert_encoding` „usuwa" niepoprawne bajty (podmienia je na `?`), i że ścieżka backfillu przez zapas statusu działa (jest bezczynna, bo `isEmpty()` patrzy tylko na klucze źródłowe).
- **Przy naprawie krytyka ograniczyłem tylko status**, a wykonawca zapytał o profesję — z tym samym skutkiem: przy zmapowanym kluczu profesji raport na portalu z trzema tysiącami kont ogłaszałby „2800 kont bez profesji" i zapalał sztandar nad kontem administratora (R41).

Dwa wypadki wykonawców, obsłużone bez rundy: `git checkout` zmiótł jednemu niezacommitowane zmiany w `ImportRunner.php`, które przepisał z pamięci (wskazałem to recenzentowi jako ryzyko; przeczytał plik na własnych prawach i nie znalazł rozbieżności), a drugi zostawił w drzewie roboczym pliki z CRLF, choć zapisane blobi były czyste — odświeżyłem je z indeksu.

---

## 5. Zachowania, które uderzyłyby w człowieka

- **Portal zamknięty przed wszystkimi po jednym kliknięciu**, administrator włącznie (3.1).
- **Konta odrzucone przez redaktora zatwierdzane po cichu**, razem z tymi, które nigdy nie potwierdziły adresu (3.1).
- **Całe mapowanie zniszczone przez literówkę w nazwie grupy ACF** (3.2).
- **Pusty skan czytany jako „nie ma czego mapować"**, gdy w rzeczywistości padło zapytanie do bazy (R14). Zapytanie liczące rzuca teraz wyjątkiem; zapytanie o próbki nie, bo klucz z nieznanymi przykładami jest wart więcej niż utrata całego skanu.
- **Źródłowe dane niewidoczne w skanie**, bo wykluczenie po całym przedrostku tabeli ukrywało każdy klucz `{prefix}s2member_*` — czyli dane wtyczki, od której portal właśnie odchodzi.
- **Konto z ukośnikiem w wartości raportowane jako trwała porażka zapisu** (R23), przy czym tryb próbny liczył je jako zmienione, a zapis jako nieudane — podgląd przeczył wynikowi.
- **Import zapisujący dane bez wiersza audytu** (R20), czyli bez jedynego zapisu tego, kto co zaimportował.
- **Dwa kliknięcia dające dwa wiersze audytu za jedną decyzję** (R29).
- **Konto z pustym stanem poza zasięgiem backfillu na zawsze** (R33): brama je wpuszcza, a `NOT EXISTS` go nie widzi.
- **Backfill cicho pomijający konta** przy złej arytmetyce przesunięcia strony (R36).

---

## 6. Testy, które nic nie dowodziły

- **Kolejność dopisania próbek** karmiona rosnąco (1..15): implementacja sortująca przeszłaby, a komentarz twierdził, że test dowodzi kolejności.
- **Czułość odcisku dowiedziona dla jednej sekcji z sześciu** (R7), a niedowiedzione były m.in. `statusValues` — czyli ta sekcja, której ciche pominięcie przepuszczałoby przez bramkę mapowanie, którego nikt nie przeliczył.
- **Test „numer pola ukrytego nie jest sprawdzany" używał profesji bez numeru** (R12), więc metoda wychodziła wcześniej i test przeszedłby też przy czytaniu surowej mapy źródłowej. Nowy test padł pod mutacją, a stary pod tą samą mutacją dalej przechodził — to jest dowód, że był bezzębny.
- **Test niemutowalności nie widział tablic, o które mu chodziło**: sprawdzał odbiorcę tylko przez dwa licznery, a zmiana, którą karmił, nie miała zastrzeżeń.
- **Granica wieku raportu obramowana, ale nie przypięta** (R32): mutacja `>=` na `>` przechodziła, bo nic nie stało dokładnie na granicy.
- **Eskejpy bez testu** (R28): wartość źródłowa idąca do atrybutu HTML (pochodząca z kluczy `user_meta` portalu, czyli z dowolnej wtyczki, jaka tam działała) i etykiety ACF oraz profesji — ich usunięcie zostawiało suitę zieloną.
- **Rozstrzyganie remisów w sortowaniu skanu jest niedowodliwe** i to zmierzone: mutacja `meta_key ASC` przechodzi nawet z trzecim remisującym kluczem, bo MySQL i tak zwraca wynik `GROUP BY` posortowany po kolumnie grupującej. Klauzula zostaje, bo czyni kolejność określoną, ale dziennik zapisuje, że nic jej nie dowodzi.
- **Test niezmienności przy pustym mapowaniu przechodził z niewłaściwego powodu** — przez bezpieczny zapas w danych testowych, nie przez regułę. Poszerzony na trzy zapasy przy naprawie krytyka.

---

## 7. Wszystkie rozstrzygnięcia

Pięć przedwykonaniowych (P) i czterdzieści dwa z wykonania (R), w kolejności, w jakiej je podjąłem. Każde w formie: co postanowiłem — dlaczego — co kosztuje, gdy się mylę. Dwa z nich (R4, R9) mają dopisane korekty, bo pomyliłem się w uzasadnieniu.

**P1.** Zadanie 7 wpina ekran w `Plugin.php` i dokłada wiersz w `WiringTest` — inaczej commituje ekran, którego nic nie rejestruje. **P2.** `Menu.php` dostaje tylko stałą; podstronę dodaje `add_page()` z własnego `register()`, jak każdy inny ekran. **P3.** Źródło pól budowane dokładnie jak w `MatrixScreen::source()`; bez tego przycinanie czytałoby złą grupę i cicho gubiło każde zmapowane pole. **P4.** Planer składany z `new ValidatorRegistry()` bez argumentów. **P5.** Wyszukiwanie normalizuje **argument**, nie tylko klucze zapisane — inaczej portal ze `Lekarz` w bazie raportowałby każde konto jako niezmapowane.

**R1.** Stały kształt `toArray()`; zmiana później oznacza dotknięcie trzech zadań. **R2.** Wyciszone ostrzeżenie ukrywało brakującą funkcję (4.). **R3.** Trzy z czterech drobiazgów do rundy, bo kosztują linijkę w pliku już edytowanym. **R4.** `mb_scrub()` odrzucone na rzecz `mb_convert_encoding` (PHP 8.3 kontra 8.1) — *korekta: podmienia bajty na `?`, nie usuwa ich, co jest lepsze, niż napisałem*. **R5.** `json_encode` wraca, a wyjątek reguły idzie do `phpcs.xml.dist`, zawężony do `src/Domain/Import/*`; własnoręczny serializer jest poprawny, ale wymaga od czytelnika dowodu bezkolizyjności. **R6.** Klucze liczbowe rzutowane, nie odrzucane (3.4). **R7.** Czułość odcisku dowiedziona dla jednej sekcji z sześciu (6.). **R8.** Kody stanów obcinane, ale nie zmieniane na małe litery — dokładność tego porównania trzyma poza zasięgiem stan kasowany cronem; obowiązek przeniesiony na formularz. **R9.** Niezmapowany klucz nie oskarża żadnego konta — *korekta: ścieżka backfillu przez sam zapas jest bezczynna, bo `isEmpty()` patrzy tylko na klucze źródłowe*. **R10.** Wartość nieskalarna zarabia oba kody, bo oba są prawdziwe i mówią różne rzeczy. **R11.** Numer pusty po normalizacji nie jest zapisywany i zarabia „niepoprawny" — reguła „nie nadpisuj pustką" bije „zapisz formę znormalizowaną". **R12.** Bezzębny test pola ukrytego do rundy (6.). **R13.** Nieosiągalna gałąź bez walidatora zostaje jako osłona dla pierwszego rodzaju numeru bez walidatora. **R14.** Padające zapytanie liczące rzuca wyjątkiem; zapytanie o próbki nie (5.). **R15.** Rozstrzyganie remisów do rundy, z **uczciwym** raportem, jeśli mutacja przeżyje — przeżyła. **R16.** Naprawa PHP 8.1 skierowana do właściciela pliku, nie do zablokowanego zadania (2.). **R17.** Ta naprawa wrzucona w zakres recenzji Zadania 5, żeby krytyk nie został bez miejsca recenzenta. **R18.** Asercja, która nie może paść, i nieprzetestowane przez repozytorium przycięcie profesji — do rundy. **R19.** Obie strony bramki liczą odcisk z obiektu zwróconego przez `get()` (4.). **R20.** Przemilczany wynik `EventLog::write()` do rundy; siódmy klucz `log_failed`. **R21.** `maybe_unserialize()` przyjęte — bez niego reguła o wartościach nieskalarnych jest bezczynna wobec prawdziwych danych. **R22.** Częściowy zapis przyjęty jako nieunikniony, bo kilku `update_user_meta()` nie spina transakcja; raport nie udaje sukcesu. **R23.** `wp_slash()`, zmierzone na stosie kontenera (5.). **R24.** Scalanie przy zapisie zamiast nadpisania przyciętym widokiem (3.2). **R25.** Wartości wysłane bez `sanitize_text_field` — muszą pasować do bazy bajt w bajt. **R26.** Limit pięćdziesięciu kluczy przyjęty jako ograniczenie, nie defekt (1.5). **R27.** Pusty zapis przy niepustym mapowaniu czyści (3.3). **R28.** Dwa eskejpy bez testu i wrogi POST nad istniejącym wpisem — do rundy. **R29.** Podgląd zużywany **przed** przebiegiem (5.). **R30.** Wynik w argumentach adresu przyjęty: podrabialny ręcznie, skutek kosmetyczny, wzorzec jak na innych ekranach. **R31.** Ręczne pole nonce przyjęte, bezpieczeństwo sprawdzone przeze mnie w `Request::guard()`. **R32.** Granica wieku, puste mapowanie i zacieśniony regex — do rundy. **R33.** Pusty stan liczy się jak brak stanu; niepusty nierozpoznany zostaje nietknięty, bo to czyjeś dane (5.). **R34.** `grant_approved()` zwraca trzy klucze (3.5). **R35.** `confirm()` na przycisku przyjęte — masowa zmiana stanu, której nic nie cofa automatycznie. **R36.** Arytmetyka przesunięcia strony po nieudanym nadaniu do rundy (5.). **R37.** Krytyk sprawdzony przeze mnie na kodzie przed naprawą (3.1). **R38.** Budżet czasu naprawiony **w połowie**: wiersz `import_started` wchodzi, wznawianie nie (1.1). **R39.** Trzy z czterech drobiazgów do fali; czwarty do właściciela (1.4). **R40.** Brak opcji usuwania zapisany właścicielowi, nie zbudowany (1.3). **R41.** Pytanie wykonawcy rozszerzyło naprawę krytyka na zastrzeżenia o profesji (4.). **R42.** Reszta po fali — mapowanie klucza rdzenia poszerza „członkostwo" — do backlogu, nie do drugiej fali, bo wymaga świadomego mapowania, nie jest już ciche i alternatywa psuje zamierzoną populację (1.2).

---

## 8. Co zostaje na później

W backlogu, sekcja Planu 8: brak budżetu czasu i wznawiania importu (1.1), mapowanie klucza rdzenia poszerzające członkostwo (1.2), niezbudowana opcja usuwania (1.3), zasięg ostrzeżenia (1.4), limit pięćdziesięciu kluczy (1.5), bezterminowość przycisku (1.6) oraz martwe `testVersion` bez PHPCompatibility (2.).

Zaległe z wcześniejszych planów, dalej otwarte: retencja wierszy kolejki z żywym sekretem, IP w wierszach logu z podmiotem `0`, log wysyłek kłamiący o anulowanym-ale-dostarczonym mailu, `dedupe_key` po anonimizacji, cztery zachowania ACF Pro do sprawdzenia na dev oraz kontrola `true_false` z Planu 3.

Plan 9 (aktualizacje i release) jest w backlogu, ale nie napisany.
