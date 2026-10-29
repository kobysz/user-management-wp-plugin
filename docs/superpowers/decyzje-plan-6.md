# Decyzje i ustalenia Planu 6 (ekran wniosków)

Zapis z wykonania `docs/superpowers/plans/2026-10-29-portal-users-ekran-wnioskow.md` metodą subagent-driven: osiem zadań, osiem recenzji zadaniowych, cztery rundy poprawek, recenzja całej gałęzi i jedna fala po niej. Ledger wykonania był git-ignorowany i został usunięty; to jest to, co z niego zostaje.

Bramki na drzewie do scalenia: unit **248** (560 asercji), integration **982** (3595), PHPStan level max czysto, PHPCS zero zgłoszeń. Wejście planu: unit 248, integration 873 — Plan 6 dołożył 109 testów integracyjnych.

---

## 1. Co zostaje do Twojej decyzji

Pięć rzeczy, których nie rozstrzygam sam.

### 1.1. Masowa korekta powodów na zakładce „Odrzucone"

Po odwróceniu rozstrzygnięcia R6 (patrz 3.1) redaktor może zaznaczyć kilka kont **już odrzuconych**, wpisać jeden powód i kliknąć „Odrzuć". Każde z nich dostanie ten powód i **każdy lekarz dostanie maila**. Wcześniej taka akcja nie robiła nic. Pola zaznaczania renderują się w każdym wierszu, bez względu na zakładkę, więc droga jest otwarta.

Wymaga świadomego zaznaczenia i wpisania powodu, a każda zmiana zostawia własny wiersz w logu zdarzeń. Uznałem to za akceptowalne: alternatywą jest odebranie redakcji masowej korekty, czyli inne ograniczenie uderzające w człowieka. **Jeżeli wolisz, by korekta działała tylko pojedynczo, to jedna bramka w `handle_decide()`.**

### 1.2. Cztery zachowania ACF Pro, których nie da się tu sprawdzić

Most ACF → metadane wtyczki (`src/Admin/ProfileSync.php`) stoi na czterech założeniach o ACF Pro, których **kontener testowy nie weryfikuje**, bo ACF Pro w nim nie ma (źródłem definicji pól jest filtr `pusers_acf_fields`):

- ACF wczytuje wartości na `user-edit.php` przez filtr `acf/load_value` z `post_id` w postaci `user_<id>`;
- przy zapisie wpisuje pod nazwę nieprzedrostkowaną dokładnie wczytaną wartość, a pole puste jako `''`;
- `acf/save_post` odpala się **po** zapisie pól konta;
- `$field['name']` zgadza się z kluczami `AcfFieldSource::fields()`.

Wszystkie cztery są udokumentowanym zachowaniem ACF. Żadne nie jest tu przetestowane. **Do sprawdzenia na dev z żywym ACF Pro**, obok zaległej kontroli `true_false` z Planu 3.

### 1.3. Trwała rozbieżność po wyczyszczeniu pola przez administratora

Osłona z 3.3 nie pozwala, by pusta wartość z ACF nadpisała dane lekarza. Skutek uboczny jest trwały: gdy administrator wyczyści pole, `user-edit.php` pokazuje je **puste na zawsze**, a ekran wniosków, mail do redakcji i przyszły eksport pokazują **starą wartość**. Nikt o tej rozbieżności nie mówi.

Zdjęcie osłony wymaga najpierw potwierdzenia 1.2, a potem zasiania metadanej ACF przy odczycie. Do tego czasu zachowanie jest zapisane w komentarzu przy osłonie i w teście.

### 1.4. Komunikat „Odrzucono wniosków", gdy mail po korekcie nie wyszedł

Korekta powodu, której mail nie wyszedł, liczy się do kubełka „Odrzucono" i dostaje osobne ostrzeżenie o niewysłanym mailu. Rzecz istotna jest powiedziana, ale napis jest nieprecyzyjny. Kosmetyka, jedna stała.

### 1.5. Data na liście to data rejestracji, nie zgłoszenia

Kolumna nazywa się „Data rejestracji", bo dostępna wartość to `user_registered`. Moment przejścia w `pending` — czyli faktyczne złożenie wniosku — nie jest nigdzie zapisany. Konto potwierdzone dwa miesiące po rejestracji pokaże datę rejestracji. Gdyby redakcja potrzebowała kolejki po dacie złożenia wniosku, trzeba dodać metadaną ustawianą przy potwierdzeniu adresu.

---

## 2. Co ten plan odkrył w kodzie, którego nie dotykał

Dwie luki istniejące przed Planem 6, obie wymagane przez specyfikację i obie zamknięte w tym planie.

**Adresu IP rejestracji nie było nigdzie.** Specyfikacja żąda pokazania go w panelu. `SpamGuard` zapisywał IP wyłącznie przy odmowie z progu, a wiersz logu `registration_created` niósł profesję i ostrzeżenie o numerze — adresu nie. Panel nie miałby czego odczytać. Zadanie 3 dołożyło zapis.

**ACF i wtyczka nie dzieliły danych.** Specyfikacja mówi, że edycja odbywa się na ekranie użytkownika WordPressa, „gdzie ACF renderuje te same pola". Rejestracja zapisuje `pusers_acf_<nazwa>`, a ACF czyta i zapisuje `<nazwa>`. Administrator poprawiał numer PWZ, a lista wniosków i mail do redakcji do końca pokazywały wartość z rejestracji. Zadanie 8 położyło most w obie strony: filtr odczytu zasila ACF, a zapis wraca do metadanych wtyczki.

---

## 3. Gdzie mój plan się mylił

Cztery razy, z czego dwa razy kosztem danych albo pracy człowieka.

### 3.1. Redaktor nie mógł poprawić pomylonego powodu odrzucenia (R6, odwrócone jako R10)

Rozstrzygnąłem, że powtórne odrzucenie już odrzuconego konta nie robi nic — także wtedy, gdy redaktor **poprawia** pomylony powód. Uzasadniałem to spójnością: mail z pierwszym powodem już wyszedł, więc metadana i log zostają zgodne z tym, co wysłano.

Recenzja całej gałęzi rozbiła to argumentem, który sprawdziłem w źródle dosłownie. `Mailer::rejected()` buduje klucz odporności jako `rodzaj:userId:minuta:substr( wp_hash( $reason ), 0, 16 )`, a komentarz nad nim mówi: *„podwójne kliknięcie nie wyśle dwóch maili, a ponowne odrzucenie z INNYM powodem wyśle, bo człowiek ma wiedzieć, co się zmieniło"*. **Plan 5 zbudował mechanizm pod korektę powodu, a mój Plan 6 uczynił go nieosiągalnym.**

Gorzej: droga ucieczki, którą zakładałem, nie istniała. `pusers_rejection_reason` nie jest polem ACF ani polem rdzenia, więc `user-edit.php` jej nie pokazuje, a panel szczegółów konta odrzuconego nie rysował ani przycisku, ani pola powodu. Jedyną drogą w panelu było **zatwierdzić i odrzucić ponownie** — czyli wysłać lekarzowi fałszywy mail „konto zatwierdzone" i na chwilę wpuścić go na portal.

Odwrócone. Korekta powodu przechodzi jako osobna ścieżka (`Decision::amend_reason()`), która **nie dotyka stanu konta**: nadpisuje metadaną z odczytem zwrotnym, dopisuje wiersz logu z `previous_status => rejected` i wysyła maila. Powód identyczny z zapisanym dalej nie robi nic.

### 3.2. Most zapisywał tablice pod klucz, dla którego rejestracja ma spisany niezmiennik

Zadanie 8 kazało przenosić wartość wielowartościową „jako tablicę, nie napis serializowany", i test to przypinał. Tymczasem docblock `RegistrationHandler::stored_values()` mówi, że wartości do metadanych są **zawsze tekstowe**, bo zserializowana tablica w `wp_usermeta` wywala na `TypeError` każdego konsumenta deklarującego `string` przy `strict_types`. Jeden klucz metadanej miałby dwa kształty zależnie od tego, kto pisał ostatni, a `ApplicationRepository::meta_string()` zwraca `''` dla nie-napisu — więc profesja o wielowartościowym polu numeru po edycji przez administratora **po cichu straciłaby numer**. Most spłaszcza teraz tak samo jak rejestracja.

### 3.3. Most niszczył dane lekarza przy pierwszym zwykłym zapisie profilu (R9)

Mój brief kazał zrobić most jednokierunkowy i nie zauważył, że konta z rejestracji **nie mają** nieprzedrostkowanych metadanych, które ACF czyta. ACF renderował więc pola puste i przy zapisie wpisywał `''`; strażnik `metadata_exists()` zamykał tylko przypadek „ACF nie zapisał nic". ACF zapisuje na priorytecie 10, most na 20, więc w **tym samym żądaniu** numer PWZ i wszystkie pola zgłoszenia znikały, a log dostawał wpis „administrator zmienił numer na pusty". Dotyczyło to praktycznie każdego konta z formularza.

Zamknięte dwustronnie: filtr `acf/load_value` zasila ACF wartością z rejestracji, a osłona nie pozwala pustej wartości nadpisać niepustej.

### 3.4. Lista plików Zadania 4 była niekompletna

Przepięcie `{link_wniosku}` złamało asercje w `ConfirmationTest` i `MailerTest`, których brief nie wymieniał. Nowe asercje są ściślejsze niż poprzednie (przypinają slug ekranu i identyfikator, wcześniej tylko `user_id=`).

---

## 4. Gdzie myliłem się w poleceniach dla wykonawców

**Kazałem przepuścić identyfikatory przez `array_map( 'absint', … )`.** `absint('-5')` zwraca **5**. Zniekształcony identyfikator rozstrzygnąłby wniosek **innego lekarza**, zamiast zostać odrzucony. Nie jest to dziura w uprawnieniach — żądanie jest za nonce'em i uprawnieniem — ale ekran działałby na koncie, którego nikt nie wskazał. Wykonawca odmówił wykonania i dołożył `ctype_digit`. Recenzja całej gałęzi nazwała to najlepszą rzeczą w tej gałęzi.

**Twierdziłem, że strażnik czystości domeny nie czyta komentarzy.** Czyta: jego własne dane testowe mają przypadek `'komentarz o WordPressie'` jako oczekiwane naruszenie. Dlatego `parse_url()` naprawdę nie był w domenie dostępny — supresja PHPCS wymaga wpisania nazwy sniffa, a ta zawiera słowo „WordPress". Wykonawca Zadania 1 miał rację w tej połowie, ja nie.

**Sformułowałem zakaz dzielenia `ApplicationsScreen` tak, że re-recenzja uznała dodanie wymaganej przeze mnie metody za naruszenie.** Błąd w brzmieniu, nie w wykonaniu.

---

## 5. Zachowania, które uderzyłyby w człowieka

Pięć, poza 3.1 i 3.3:

1. **Panel ukrywał dane, których lekarz naprawdę podał**, nie mówiąc, że coś ukrywa. Widoczność bierze się ze stanu macierzy **dziś**; przestawienie pola na „ukryte" kasowało je ze wszystkich dawnych wniosków w panelu, a specyfikacja mówi „pokazuje wszystkie pola danego wniosku". Panel liczy teraz wartości spoza macierzy i wypisuje ich liczbę, nie rozluźniając reguły widoczności.
2. **Zatwierdzenie konta bez potwierdzonego adresu** byłoby ponownym otwarciem dziury z 27 października. Odcięte na czterech niezależnych poziomach: w domenie, w serwisie, w partii i w renderze przycisków.
3. **Nieudany zapis stanu wysyłałby maila „konto zatwierdzone"** dla konta, które dalej nie wpuszcza. `Decision::store()` dowodzi zapisu odczytem zwrotnym, bo `AccountStatusRepository::set()` zwraca `void` i nie mówi nic o powodzeniu.
4. **Mail, który nie wyszedł, nie mógł być przemilczany.** `RESULT_MAIL_FAILED` ma osobny komunikat: konto jest rozstrzygnięte, ale lekarz niepowiadomiony.
5. **Ekran wniosków rejestruje się poza bramką ACF Pro.** Gdyby znikał razem z ACF, awaria ACF zamykałaby portal dla każdego oczekującego lekarza bez drogi ratunku z panelu — przy czym brama logowania działa bez ACF i trzyma ludzi na zewnątrz, a maile do redakcji już wyszły.

---

## 6. Testy, które nic nie dowodziły

Pięć złapanych, każdy przed scaleniem:

1. **`\b` w wyrażeniu regularnym wylądowało w pliku jako literalny bajt backspace**, co unieważniało asercję `assertDoesNotMatchRegularExpression`. Zmierzyłem zasięg per commit: bajt istniał **wyłącznie** w jednym commicie i zniknął w następnym; skan całego drzewa nie znalazł żadnego innego.
2. **Test śmieciowych identyfikatorów nie pokazywał, że `-5` nie trafia w konto 5** — czyli dokładnie tej szkody, którą wywołałoby moje polecenie z punktu 4.
3. **Test „panel tylko do czytania" przechodził trywialnie**, gdyby `preg_replace` zwróciło `null`, a pole danych wstawione **wewnątrz** formularza rozstrzygnięcia przechodziło niezauważone. Zamknięte zamkniętą białą listą nazw kontrolek, z dowodem mutacyjnym.
4. **Asercja konwersji strefy czasowej była tożsamością** — test liczył oczekiwanie tą samą funkcją, którą wołał kod, a witryna testowa chodzi w UTC. Kolumna „Data rejestracji" nie miała żadnego testu. Teraz oba przypinają literał przy `Europe/Warsaw`.
5. **`total()` wczytywał wszystkie dopasowane konta**, bo `'number' => 0` nie znaczy „zero wierszy", tylko „bez limitu" (`class-wp-user-query.php:684` wymaga `> 0`), a komentarz twierdził odwrotnie. Wynik był poprawny, więc żaden test tego nie łapał.

Dwa dowody mutacyjne wykonałem sam, bo wykonawcy nie mieli czerwonego przebiegu: dla osłony pustej wartości i dla testu składu formularza.

---

## 7. Wszystkie rozstrzygnięcia

| # | Co rozstrzygnięto | Koszt pomyłki |
| --- | --- | --- |
| P1 | Nie przerabiać refleksyjnego testu `UserMeta::all()` na listę wpisaną z ręki | osłabienie mocniejszego testu |
| P2 | Obsługa akcji jak w `MailLogScreen`: `admin_post_*`, guard, potem kontrola POST | niespójność dwóch siostrzanych ekranów |
| R1 | `RegistryLink` zostaje na `preg_match`, nie `parse_url` (uzasadnienie wykonawcy błędne, kod ściślejszy) | kształt adresu, którego żadna z bramek nie przewidziała, trafia do `esc_url()` |
| R2 | `UserMeta::ACF_PREFIX` w warstwie Persistence, nie stała z `Front` | żadny: stałe są aliasami |
| R3 | Edycja dwóch asercji maila poza listą plików briefu jest słuszna | — (asercje są ściślejsze) |
| R4 | Runda poprawek na trzy drobiazgi, bo dwa chronią moje rozstrzygnięcia | jedno dodatkowe okrążenie |
| R5 | Brak bramki `$actorId <= 0` w `Decision` | wiersz audytu z aktorem 0 i nic tego nie łapie |
| R6 | Powtórne odrzucenie z poprawionym powodem odmawiane | **odwrócone jako R10** |
| R7 | Runda poprawek na cztery drobiazgi, w tym dwa bezzębne testy | jedno dodatkowe okrążenie |
| R8 | Odrzucenie werdyktu „naruszono zakaz zmiany" | 22 linie i jedna metoda publiczna więcej |
| R9 | Osłona pustej wartości plus filtr odczytu; koszt opisany błędnie, poprawiony | świadome czyszczenie pola nie przechodzi tą drogą |
| R10 | **R6 odwrócone**: korekta powodu przechodzi jako osobna ścieżka bez zmiany stanu | masowa korekta na zakładce „Odrzucone" (patrz 1.1) |

---

## 8. Co zostaje na później

Zapisane w `docs/superpowers/backlog.md`:

- **Plan 7, dane osobowe:** eraser musi czyścić `UserMeta::REGISTRATION_IP` i `UserMeta::REJECTION_REASON`; wiersze `credential_changed` niosą numer uprawnień w kontekście i podlegają anonimizacji logu, nie usunięciu.
- **Plan 7:** `EventLog::write()` zwraca `void` i ignoruje nieudany `$wpdb->insert`, więc porażka zapisu logu po udanej zmianie stanu zostawia rozstrzygnięcie wysłane i zgłoszone jako sukces, bez śladu audytu.
- **Przycisk zdejmujący blokadę limitu prób** — świadomie poza Planem 6, z wyliczonymi nazwami pięciu klas i jedenastu stałych kubełków, z których cztery są prywatne.
- **Data zgłoszenia** zamiast daty rejestracji (patrz 1.5).

**Drobne, świadomie odłożone:** `ApplicationsScreen` ma ok. 590 linii i jest sprzężony dwukierunkowo z `ApplicationDetails` (wydzielenie obsługi żądania **po** scaleniu, nie razem z nim); `status_label()` skopiowany w dwóch klasach; liczniki zakładek ignorują filtr profesji, więc zakładka może pokazywać inną liczbę niż tabela; klasa `pusers-verdict-warning` nie maluje niczego, bo wtyczka nie ma arkusza stylów, choć specyfikacja żąda „podświetlonego wiersza"; `counts()` liczy się dwa razy na render i raz na każde żądanie panelu; `NOT_APPLICABLE` zwija „ta profesja numeru nie ma" z „nie wiemy już, czego wymagała"; fikstura testowa używa nieistniejącej wartości `'pwz'` jako typu numeru; dwa miejsca z JS-em w treści strony padną przy pierwszej polityce CSP.

**Nieaktualne zapisy poprawione przy okazji:** `decyzje-plan-5.md:142` twierdziło, że wszystkie bloby w repozytorium trzymają CRLF — `git ls-files --eol` pokazuje, że **cały** indeks i drzewo robocze są LF (jedyne dwa wyjątki to puste pliki `.gitkeep`). Sekcja „Plan 4 — Logowanie" w backlogu czytała się jak twierdzenie, że brama logowania nie istnieje, choć Plan 4 jest scalony; dostała zdanie o tym, czym została zamknięta.
