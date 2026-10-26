# Wtyczka WordPress: zarządzanie użytkownikami portali medycznych

Dokument projektowy — 2026-10-26

## Intencja i powód

Firma prowadzi wiele portali kierowanych do pracowników ochrony zdrowia. Na każdym powtarza się ten sam układ: treść dostępna wyłącznie po zalogowaniu, a konto zakłada osoba, której uprawnienia zawodowe trzeba sprawdzić, zanim dostanie dostęp.

Dotychczas używano gotowych wtyczek członkowskich (WP-Members i podobne). Nie pokrywają dwóch rzeczy, które na tych portalach są kluczowe:

1. **Zależność pól od profesji.** Lekarz podaje numer PWZ. Pielęgniarka ma własny numer, w innym formacie. Diagnosta jeszcze inny. Student albo przedstawiciel firmy farmaceutycznej nie ma żadnego. Formularz musi pokazywać i wymagać właściwego pola w zależności od wybranej profesji.
2. **Walidacja numerów uprawnień.** Numer PWZ lekarza da się sprawdzić matematycznie, zanim trafi do redakcji.

Do tego dochodzi brak możliwości rozwoju — gotowa wtyczka kończy się tam, gdzie kończy się jej lista opcji.

Dlatego powstaje własna wtyczka: wielokrotnego użytku na kolejnych portalach, rozwijana w miarę potrzeb.

## Cele

- Akceptacja kont włączana i wyłączana z panelu.
- Powiadomienia mailowe do redakcji o nowym wniosku, na adresy konfigurowane w samej wtyczce (nie `admin_email`), z opcjonalnym rozbiciem per profesja.
- Własne strony logowania, rejestracji i odzyskiwania hasła, wskazywane w ustawieniach.
- Pola rejestracji definiowane w panelu, z zależnościami od profesji.
- Walidacja numerów uprawnień zawodowych, przed wysłaniem wniosku do redakcji.
- Potwierdzenie adresu e-mail przed trafieniem wniosku na listę redakcji.
- Ekran wniosków, na którym administrator weryfikuje dane i zatwierdza albo odrzuca z podaniem powodu.
- Odporność na boty: limit prób, honeypot, pułapka czasowa.
- Eksport użytkowników do CSV, log zdarzeń, licznik oczekujących wniosków w menu panelu.
- Import danych zawodowych z wtyczki członkowskiej, która stała na portalu wcześniej.
- Aktualizacja wtyczki z wydań GitHuba, bez ręcznego wgrywania ZIP-a na każdym portalu.

## Cele negatywne (czego nie robimy)

- **Multisite.** Wtyczka celuje w single site. Kod nie może jednak hardkodować założeń, które uniemożliwiłyby dorobienie obsługi sieci.
- **Grupy i role per profesja.** Każde zatwierdzone konto dostaje to samo: możliwość zalogowania się. Profesja jest danymi, nie uprawnieniem.
- **Ochrona treści.** Portale mają już własne mechanizmy ograniczania dostępu do treści. Wtyczka tego nie dubluje.
- **Edycja własnego profilu na froncie.** Poza zakresem pierwszej wersji. Świadomie przyjęty skutek: użytkownik nie poprawi sobie danych, każda zmiana idzie przez panel.
- **Załączniki w formularzu rejestracji.** Nie rozpatrywane w pierwszej wersji. Upload od niezalogowanego to osobna klasa problemu: plik w `wp-content/uploads` leży pod publicznym URL-em, wchodzi do biblioteki mediów i do zakresu eksportu danych osobowych. Pole typu plik nie jest udostępniane w formularzu rejestracji.
- **Automatyczna weryfikacja numerów w rejestrach zawodowych.** Nie istnieje publiczne API (patrz „Weryfikacja numerów uprawnień").
- **Rejestracja w dwóch krokach.** Odrzucone na rzecz jednej strony z polami warunkowymi; do rozważenia w przyszłości, gdy liczba profesji i pól wyraźnie urośnie.
- **Nadawanie zdolności redaktorom.** Wnioski obsługują administratorzy. Osobie z redakcji, która ma akceptować konta, podnosi się rolę ręcznie.
- **Import/eksport ustawień, komendy WP-CLI, whitelist domen e-mail, shortcode ochrony treści.** Omówione, odłożone na później.

## Cele wrogie (co uznamy za porażkę, nawet gdy wtyczka działa)

- Lekarz z prawdziwym, ale nietypowym numerem nie może się zarejestrować, bo walidacja go odrzuciła.
- Lekarz nie może się zarejestrować, bo jego adres e-mail zajęło porzucone albo botowe zgłoszenie.
- Limit prób odcina od logowania cały szpital siedzący za jednym adresem IP, a bot z puli adresów przechodzi obok.
- Administrator traci dostęp do panelu, bo wtyczka uznała konto bez zapisanego stanu za niezatwierdzone.
- Wtyczka scrapuje strony izb zawodowych, przez co psuje się przy każdej ich przebudowie i stawia firmę w konflikcie z regulaminami tych serwisów.
- Konfiguracja opiera się o `admin_email`, więc na portalu z zewnętrzną redakcją powiadomienia lecą w złe miejsce.
- Lista profesji jest zakodowana, więc dołożenie fizjoterapeutów wymaga programisty.
- Redakcja nie wie o czekających wnioskach, bo nic jej o nich nie przypomina.

## Ograniczenia

- WordPress 6.5 lub nowszy, PHP 8.1 lub nowszy.
- **ACF Pro jest zależnością twardą.** Brak ACF Pro = wtyczka nie startuje i mówi dlaczego, zamiast rzucać błędy.
- **ACF `Conditional Logic` działa wyłącznie w obrębie jednej grupy pól** i nie obsługuje warunkowej wymagalności. To ograniczenie przesądza o modelu profesji (patrz niżej).
- Single site.
- Polski jako język bazowy, wszystkie teksty przez funkcje tłumaczeń z własną domeną.
- Wtyczka instalowana także na portalach, które już mają użytkowników i działającą wtyczkę członkowską.
- Maile trafiają w znacznej części na firmowe serwery pocztowe z agresywnym filtrowaniem.

## Profesje

Profesja jest **strukturą należącą do wtyczki**, nie zwykłym polem ACF zakładanym przez klienta.

Powód: wtyczka czyta profesję w pięciu miejscach — walidacja numeru uprawnień, warunkowa widoczność i wymagalność pól, filtr na liście wniosków, wybór odbiorców maila do redakcji, link do właściwego rejestru zawodowego. Każde z nich musi znać dopuszczalne wartości i to, co z nich wynika. Gdyby lista profesji żyła w ACF, a jej konsekwencje w ustawieniach wtyczki, dwie listy rozjechałyby się przy pierwszej zmianie.

W ustawieniach wtyczki klient utrzymuje listę profesji. Każda profesja ma:

- nazwę wyświetlaną i stały klucz,
- typ numeru uprawnień: PWZ lekarza, PWZ pielęgniarki/położnej, PWZDL diagnosty, **albo brak numeru**,
- adres rejestru do weryfikacji (albo pusty, gdy nie ma czego weryfikować),
- opcjonalny adres e-mail redakcji właściwej dla tej profesji.

**Profesje bez numeru uprawnień są pełnoprawnym przypadkiem** — studenci, sponsorzy, firmy farmaceutyczne. Dla takiej profesji pole numeru nie istnieje, kolumna na liście wniosków jest pusta, a maila do redakcji nie zawiera wyniku walidacji ani linku do rejestru. Numer nie jest strukturalnie obowiązkowy.

Lista profesji ma rosnąć bez programisty, więc walidatory numerów nie mogą być zakodowane po jednym na profesję — profesja wskazuje typ numeru, a typów jest skończona, znana liczba.

## Pola rejestracji

**ACF Pro odpowiada za definicje pól, ich renderowanie i zapis.** Klient zakłada pola w standardowym interfejsie ACF, w grupie pól z lokalizacją „User Form". Wtyczka nie buduje własnego edytora pól ani własnych typów pól poza numerami uprawnień.

**Zależności od profesji należą do wtyczki, nie do ACF.** ACF nie potrafi uzależnić pola od pola z innej grupy ani uczynić pola warunkowo wymaganym, a oba te zachowania są tu podstawą. Dlatego w ustawieniach wtyczki stoi **macierz profesja × pole**, w której każde pole dostaje jeden z trzech stanów:

- ukryte,
- widoczne,
- widoczne i wymagane.

Macierz jest jednym źródłem prawdy dla trzech warstw: ukrywania pól na froncie, walidacji po stronie serwera i widoku wniosku w panelu. Serwer nigdy nie ufa temu, co przyszło z przeglądarki — pole ukryte dla danej profesji jest przy zapisie ignorowane, nawet jeśli w żądaniu się pojawiło.

Pole profesji jest renderowane przez wtyczkę, nie przez ACF, i zawsze stoi jako pierwsze.

Klient zachowuje w ACF pełny zestaw typów pól poza uploadem plików: tekst, e-mail, liczba, select, radio, checkbox, pole wielokrotne.

## Przebieg rejestracji

Konto przechodzi przez cztery stany:

```
niepotwierdzony_mail → oczekujący → zatwierdzony
                                  ↘ odrzucony
```

1. **Rejestracja.** Jedna strona. Użytkownik wybiera profesję, co odsłania pola właściwe dla niej, i ustawia własne hasło. Konto powstaje w stanie `niepotwierdzony_mail`. Redakcja nie jest jeszcze powiadamiana.
2. **Potwierdzenie adresu.** Użytkownik dostaje maila z linkiem. Token ma termin ważności (propozycja: 48 godzin) i da się wysłać ponownie. Po kliknięciu konto przechodzi w `oczekujący`.
3. **Powiadomienie redakcji.** Mail do skonfigurowanych odbiorców: podsumowanie pól, numer uprawnień z wynikiem walidacji, link prosto do wniosku.
4. **Weryfikacja.** Administrator sprawdza dane — w praktyce porównuje numer z publiczną wyszukiwarką właściwej izby. Zatwierdza albo odrzuca, podając powód.
5. **Rozstrzygnięcie.** Zatwierdzenie: konto w stanie `zatwierdzony`, mail do użytkownika z linkiem do logowania. Odrzucenie: stan `odrzucony`, mail z powodem.

**Logowanie wpuszcza wyłącznie `zatwierdzony`.** Każdy inny stan daje własny komunikat, żeby użytkownik wiedział, czy ma szukać maila, czy czekać na redakcję.

**Konta bez zapisanego stanu są wpuszczane.** Brama logowania działa tylko na konta, którym wtyczka nadała stan. Inaczej administrator dodany ręcznie przez „Dodaj użytkownika" zostałby zamknięty poza portalem. Backfill przy aktywacji (patrz niżej) zapewnia, że konta istniejące wcześniej mają stan, więc „brak stanu" oznacza w praktyce konto założone w panelu już po wdrożeniu.

**Gdy akceptacja jest wyłączona** w ustawieniach: po potwierdzeniu adresu konto od razu przechodzi w `zatwierdzony`. Redakcja i tak dostaje maila o nowym użytkowniku, ale czysto informacyjnie, bez wezwania do działania. Późniejsze włączenie akceptacji nie rusza kont, które już istnieją.

### Zajęty adres e-mail i porzucone rejestracje

Konto WordPressa powstaje już w kroku pierwszym, więc adres jest zajęty od momentu wysłania formularza. Bez sprzątania porzucona albo botowa rejestracja blokowałaby adres prawdziwemu lekarzowi na zawsze, a zadeklarowana ścieżka naprawy danych („zarejestruj się od nowa") w ogóle by nie działała.

**Cron czyści konta w stanie `niepotwierdzony_mail`** starsze niż ustalony okres (propozycja: 7 dni, konfigurowalne). Usunięcie konta zwalnia adres.

Konta `odrzucone` nie są czyszczone automatycznie — odrzucenie jest decyzją redakcji i ma zostać udokumentowane. Administrator może usunąć takie konto ręcznie, co zwalnia adres pod ponowną rejestrację.

### Instalacja na działającym portalu

Przy aktywacji wtyczka nadaje stan `zatwierdzony` kontom, które poprzednia wtyczka członkowska uznawała za zatwierdzone. Nikt nie traci dostępu z powodu wdrożenia.

Konta, których poprzednia wtyczka nie zatwierdziła, nie dostają stanu `zatwierdzony` — trafiają na listę wniosków do przejrzenia albo zostają usunięte, zależnie od decyzji administratora przy imporcie.

## Import z poprzedniej wtyczki

Stare wtyczki członkowskie trzymają profesję, numer uprawnień, zgody i status akceptacji we własnych metadanych użytkownika. Bez przeniesienia tych danych redakcja nie zobaczy numeru PWZ lekarza zatwierdzonego przed wdrożeniem.

Wtyczka dostaje **narzędzie importu w panelu**, generyczne, nie wiązane z konkretną wtyczką źródłową:

1. Narzędzie skanuje istniejące klucze `user_meta` na portalu i pokazuje je z przykładowymi wartościami oraz liczbą kont, które je mają.
2. Administrator mapuje klucze źródłowe na pola ACF wtyczki, na profesję (wraz z mapowaniem wartości źródłowych na klucze profesji) i na stan konta.
3. **Tryb próbny** przelicza cały import bez zapisu i pokazuje raport: ile kont zostanie zmienionych, ile wartości profesji nie da się zmapować, ile numerów uprawnień nie przejdzie walidacji, które konta zostaną bez profesji.
4. Dopiero po raporcie import zapisuje dane, odnotowując operację w logu zdarzeń.

Generyczne mapowanie zamiast dedykowanego importera pod WP-Members: portale różnią się między sobą, a mapowanie i tak trzeba przejrzeć ręcznie przy każdym wdrożeniu.

## Weryfikacja numerów uprawnień

Ustalone w trakcie rozmowy, na podstawie oficjalnych i nieoficjalnych źródeł:

### Lekarze i lekarze dentyści — numer PWZ

Algorytm potwierdzony na stronie Naczelnej Izby Lekarskiej.

Numer ma postać `KABCDEF` — siedem cyfr, gdzie `K` jest cyfrą kontrolną, a `A`–`F` cyframi od 0 do 9. Numer nie zaczyna się od zera.

```
K = (A·1 + B·2 + C·3 + D·4 + E·5 + F·6) mod 11
```

Przykład z NIL: cyfry 4, 2, 5, 7, 4, 0 dają sumę 71, a 71 mod 11 = 5, więc poprawny numer to `5425740`.

Przypadek, którego NIL nie opisuje: gdy reszta wynosi 10, cyfra kontrolna nie mieści się w jednym znaku. Takie numery nie są wydawane, więc walidacja ma je traktować jako nieprawidłowe.

### Pielęgniarki i położne — numer PWZ

Brak sumy kontrolnej. Da się sprawdzić wyłącznie format: osiem znaków, czyli dwie cyfry oznaczające izbę okręgową (01–45), pięć cyfr numeru i litera `P` dla pielęgniarki albo `A` dla położnej. Przykład: `0201234P`.

Źródło jest nieoficjalne (biblioteka PHP `czachor/polish-id-validators`). **Do potwierdzenia na nipip.pl przed wdrożeniem.**

### Diagności laboratoryjni — numer PWZDL

Numer wyłącznie cyfrowy, zapisywany bez zer wiodących (`00399` zapisuje się jako `399`). Brak udokumentowanej sumy kontrolnej, więc tylko walidacja formatu.

### Walidacja jest miękka

Numer, który nie przechodzi walidacji, **nie blokuje rejestracji**. Formularz się wysyła, a wniosek trafia do redakcji z widocznym ostrzeżeniem i podświetlonym wierszem na liście. Decyzję podejmuje człowiek.

Powód: lekarz z literówką albo z numerem nietypowym nie ma zostać odcięty od portalu przez algorytm.

### Dlaczego weryfikacja nie jest automatyczna

Ani Naczelna Izba Lekarska, ani Naczelna Izba Pielęgniarek i Położnych, ani Krajowa Izba Diagnostów Laboratoryjnych nie publikują API. Dostępne są wyłącznie wyszukiwarki webowe. Scraping jest kruchy i wątpliwy wobec regulaminów tych serwisów. Dane NIL są dodatkowo odświeżane raz w miesiącu z izb okręgowych, więc nawet skuteczne odpytanie nie dawałoby pewności.

Zamiast tego: przy każdym wniosku **link otwierający właściwą wyszukiwarkę z wklejonym numerem**. Redaktor klika raz, zamiast kopiować numer między kartami. Weryfikacja zostaje ludzka, ale szybka.

Adresy wyszukiwarek, konfigurowane per profesja:
- lekarze: `rejestr.nil.org.pl`
- pielęgniarki i położne: `nipip.pl/weryfikacja-pwz`
- diagności: `kidl.org.pl/diagnostic/search`

## Strony na froncie

W ustawieniach wtyczki wskazuje się stronę logowania, rejestracji i odzyskiwania hasła. Formularz pojawia się na stronie przez element wstawiany w edytorze.

Odzyskiwanie hasła działa w pełnym, standardowym cyklu: formularz z adresem, mail z linkiem, formularz ustawienia nowego hasła. Treść maila podmieniana na własny szablon.

`wp-login.php` i `wp-admin` **zostają dostępne** jako zapasowe wejście dla redakcji. Dla kont bez uprawnień redakcyjnych:

- po zalogowaniu przekierowanie na wskazaną stronę, nie na kokpit,
- wejście w `wp-admin` bez uprawnień do edycji treści przekierowuje na front,
- pasek administracyjny ukryty.

## Ekran wniosków w panelu

Lista wniosków z zakładkami: oczekujące, zatwierdzone, odrzucone — z licznikami. Filtr po profesji. Akcje masowe: zatwierdź, odrzuć.

Kolumny: użytkownik z adresem e-mail, profesja, numer uprawnień z wynikiem walidacji i linkiem do rejestru (pusta dla profesji bez numeru), data zgłoszenia, akcje.

Panel szczegółów jest **tylko do czytania**: pokazuje wszystkie pola danego wniosku, znacznik czasu akceptacji regulaminu i adres IP rejestracji. Edycja danych odbywa się na standardowym ekranie edycji użytkownika WordPressa, gdzie ACF renderuje te same pola. Zmiana numeru uprawnień przez administratora odnotowywana jest w logu.

Odrzucenie wymaga powodu. Administrator wpisuje własny albo wybiera z gotowych (do skonfigurowania w ustawieniach, np. „numer nieprawidłowy", „dane niepełne"). Powód trafia do maila i do logu.

W menu panelu licznik oczekujących wniosków, podobny do licznika komentarzy.

**Dostęp:** ekran wniosków i ustawienia wymagają uprawnień administratora. Rola Redaktor w WordPressie nie widzi menu „Użytkownicy", a wtyczka nie nadaje jej dodatkowych zdolności — osobie z redakcji, która ma akceptować konta, podnosi się rolę ręcznie.

## Maile

**Do redakcji:** nowy wniosek o konto.

**Do użytkownika:** potwierdzenie adresu e-mail, wniosek przyjęty do weryfikacji, konto zatwierdzone, wniosek odrzucony z powodem, odzyskiwanie hasła.

Mail o zmianie hasła zostaje standardowy, WordPressowy.

**Odbiorcy w redakcji:** lista adresów w ustawieniach wtyczki, niezależna od `admin_email`. Opcjonalny override per profesja, żeby wnioski lekarzy mogły iść na inny adres niż wnioski pielęgniarek.

**Szablony:** temat i treść edytowalne w panelu. Zmienne obejmują dane konta, profesję, numer uprawnień, powód odrzucenia, link do logowania, nazwę portalu, a także dowolne pole rejestracji.

**Format:** tekst zwykły w prostym opakowaniu HTML. Maile transakcyjne nie potrzebują grafiki, a tekst rzadziej wpada w filtry.

**Dostarczalność:** dokumentacja wtyczki wymaga skonfigurowania wysyłki przez SMTP. W panelu log wysyłek, żeby dało się odpowiedzieć na pytanie „czy ten mail wyszedł".

## Odporność na boty

Boty uderzają w rejestrację na tych portalach regularnie.

- **Limit prób liczony per adres IP i per konto**, osobno dla rejestracji, logowania i odzyskiwania hasła. Progi konfigurowalne. Liczenie wyłącznie per IP odcięłoby cały szpital siedzący za jednym adresem, liczenie wyłącznie per konto przepuściłoby bota zgadującego adresy.
- **Źródło adresu IP:** `REMOTE_ADDR`, z opcją wskazania zaufanego nagłówka proxy. Nagłówek honorowany tylko wtedy, gdy administrator go włączył — inaczej limit da się obejść podaniem dowolnej wartości.
- Honeypot — pole niewidoczne dla człowieka, wypełniane przez boty.
- Pułapka czasowa — formularz wysłany szybciej niż trzy sekundy po załadowaniu uznawany za bota.
- Po przekroczeniu progu blokada czasowa, z możliwością zdjęcia jej z panelu.
- Przekroczenia zapisywane do logu.
- Punkt rozszerzenia pozwalający dołożyć Turnstile albo hCaptcha bez modyfikowania wtyczki.

## Dane osobowe

Przez wtyczkę przechodzą dane zawodowe, więc:

- **Zgody** zapisywane ze znacznikiem czasu i identyfikatorem wersji regulaminu. Treść regulaminu nie jest kopiowana do każdego konta — wtyczka trzyma archiwum wersji i wiąże konto z wersją. Zmiana regulaminu tworzy nową wersję.
- **Podpięcie pod WordPressowe mechanizmy eksportu i usuwania danych osobowych**, żeby wniosek użytkownika obsłużyć standardowym narzędziem.
- **Żądanie usunięcia danych anonimizuje log zdarzeń, nie kasuje go.** Wpis zostaje, dane osobowe w nim są zastępowane. Log służy do wykazania, kto i kiedy zatwierdził konto, więc jego usunięcie zniszczyłoby materiał potrzebny przy sporze. Zapisy zgód zostają na podstawie obowiązku wykazania zgody.
- **Log wysyłek maili** ma okres retencji (propozycja: 90 dni) i jest po nim czyszczony cronem.

## Pozostałe funkcje pierwszej wersji

- **Eksport użytkowników do CSV** wraz z polami rejestracji, z neutralizacją CSV injection.
- **Log zdarzeń** — kto zatwierdził, kiedy, kto odrzucił i z jakim powodem, kto zmienił numer uprawnień, co zrobił import.
- **Licznik oczekujących wniosków** w menu panelu.

## Dystrybucja i aktualizacje

Wtyczka stoi na wielu portalach i ma być rozwijana, więc poprawka walidatora musi dać się wypchnąć wszędzie bez ręcznego wgrywania ZIP-a.

**Repozytorium na GitHubie, aktualizacje z wydań.** Pierwsze wgranie ZIP-em, dalej wtyczka sama sprawdza wydania i podstawia się do mechanizmu aktualizacji WordPressa.

Mechanizm przenosimy z `event-registration` (patrz niżej) — jest tam sprawdzony w boju, wraz z rozwiązanymi pułapkami, których nie trzeba odkrywać po raz drugi.

## Wzorce przeniesione z `event-registration`

Istniejąca wtyczka firmy (`C:\Users\kuba\SynologyDrive\WebDevAI\event-registration`) rozwiązała już znaczną część tej samej infrastruktury. Nowa wtyczka ma **powtórzyć te wzorce, nie wymyślać ich od nowa**. Jej `CLAUDE.md` jest dla wykonawcy obowiązkową lekturą.

Do przeniesienia:

- **Aktualizator z GitHuba** (`src/Update/GitHubUpdater.php`) — wraz z trzema rozwiązanymi pułapkami: wpis w `no_update` jest obowiązkowy dla wtyczek spoza wordpress.org, rejestracja musi iść na `init`, a nie `admin_init` (inaczej cron i WP-CLI nie widzą aktualizacji), oraz negatywne buforowanie odpowiedzi przy limicie zapytań GitHuba.
- **Workflow wydania** (`.github/workflows/release.yml`) — guard zgodności wersji w nagłówku z tagiem, budowa paczki za `.distignore`, nazwa assetu zgodna ze stałą w aktualizatorze, treść wydania wyciągana z `CHANGELOG.md` i trafiająca także do okna „Szczegóły wtyczki".
- **Silnik warunkowej widoczności pól** — serwerowe atrybuty `data-*-when-*` na polach plus lustrzany silnik w JS, z czystą logiką warunków w domenie. Tu posłuży do macierzy profesja × pole.
- **Kolejka mailowa** — render treści przy zapisie, idempotencja przez unikalny indeks, dispatcher z odzyskiwaniem zawieszonych wysyłek i retry, ekran kolejki z akcją „Wznów". Daje wymagany log wysyłek.
- **Antyspam formularza publicznego** — honeypot, znacznik czasu, nonce, limit per IP na transientach, re-render błędów z zachowaniem wartości.
- **Eksport CSV** — BOM, neutralizacja CSV injection w komórkach danych, mapowanie schematu na kolumny.
- **Exporter i eraser danych osobowych** — z pułapką paginacji eraseru (anonimizacja usuwa wiersze ze zbioru, więc offset musi zostać na zerze).
- **Bramkowany uninstall** — domyślnie nie kasuje danych, kasuje tylko po jawnym włączeniu opcji albo ustawieniu stałej.

Do powtórzenia także konwencje projektu: PSR-4 z autoloadem Composera, `src/Domain/**` bez WordPressa i bez `$wpdb` (pilnowane testem architektury), domena zwracająca kody błędów a nie komunikaty, i18n na wszystkich stringach, wersja jako jedno źródło w nagłówku wtyczki, `wp-env` do testów, PHPStan i PHPCS w CI.

## Decyzje podjęte przez zamawiającego

- Rejestracja na jednej stronie, z polami doklejanymi warunkowo. Nie dwa kroki.
- Odrzucenie wymaga powodu i wysyła maila do użytkownika. Bez linku do poprawy danych i ponownego wysłania wniosku.
- Potwierdzenie adresu e-mail przed trafieniem wniosku do redakcji — tak.
- Błędna suma kontrolna nie blokuje rejestracji. Weryfikuje człowiek.
- `wp-login.php` zostaje jako zapasowe wejście dla redakcji.
- Istniejące konta, które poprzednia wtyczka miała za zatwierdzone, dostają przy aktywacji stan `zatwierdzony`.
- Pola dodawane w panelu, oparte o ACF Pro, który jest na wszystkich portalach.
- Limit prób jako ochrona przed botami.
- Zatwierdzone konto nie dostaje własnej roli ani grupy — tylko możliwość logowania.
- Są profesje bez numeru uprawnień (studenci, sponsorzy, firmy farmaceutyczne). Numer nie może być zawsze obowiązkowy.
- Niepotwierdzone konta czyści cron.
- Wnioski obsługują administratorzy. Redaktorowi, który ma dostać zadania akceptacji, podnosi się rolę ręcznie.
- Załączniki w formularzu rejestracji — nie w tej wersji.
- Dystrybucja przez repozytorium GitHub: pierwsze wgranie ZIP-em, dalej wtyczka sama czyta wydania.
- Z zaproponowanych rozszerzeń do pierwszej wersji wchodzą: eksport CSV, log zdarzeń, licznik w menu. Edycja profilu na froncie, import/eksport ustawień, WP-CLI, whitelist domen i shortcode ochrony treści — odłożone.
- Ustawienia dostępne pod menu wtyczki lub pod „Użytkownicy".

## Decyzje podjęte przeze mnie

Zamawiający ich nie zatwierdzał osobno — warto je sprawdzić przy czytaniu.

- **Profesja jest strukturą wtyczki**, nie polem ACF zakładanym przez klienta. Lista profesji w ustawieniach, każda z typem numeru, adresem rejestru i opcjonalnym adresem redakcji. Zamawiający zostawił tę decyzję mnie.
- **Zależności pól realizuje wtyczka, nie ACF** — macierz profesja × pole ze stanami ukryte / widoczne / widoczne i wymagane. Wymuszone ograniczeniem ACF: `Conditional Logic` nie przechodzi między grupami pól i nie obsługuje warunkowej wymagalności. Skutek dla klienta: reguły zależności ustawia się w ustawieniach wtyczki, nie w zakładce `Conditional Logic` w ACF.
- **Import danych ze starej wtyczki jako generyczne narzędzie mapowania** `user_meta` → pola, z trybem próbnym i raportem, zamiast dedykowanego importera pod WP-Members. Zamawiający zostawił tę decyzję mnie.
- Konta bez zapisanego stanu są wpuszczane, żeby nie zamknąć administratora poza portalem.
- Panel wniosków tylko do czytania; edycja przez standardowy ekran użytkownika WordPressa.
- Limit prób per IP i per konto, adres IP z `REMOTE_ADDR` z opcjonalnym zaufanym nagłówkiem proxy, po przekroczeniu blokada czasowa zdejmowalna z panelu.
- Regulamin jako archiwum wersji plus powiązanie konta z wersją, nie kopia treści przy każdym koncie.
- Log zdarzeń przy żądaniu usunięcia danych anonimizowany, nie kasowany. Zgody zostają. Log wysyłek z retencją 90 dni.
- Niepotwierdzone konta czyszczone po 7 dniach, konfigurowalnie.
- Termin ważności tokenu potwierdzenia adresu: 48 godzin.
- Pułapka czasowa: trzy sekundy.
- WordPress 6.5+, PHP 8.1+ jako minimalne wersje.
- ACF Pro jako zależność twarda, z komunikatem przy aktywacji zamiast cichej awarii.
- Single site, ale bez hardkodowania założeń blokujących późniejsze multisite.
- Polski jako język bazowy, wszystkie teksty przez funkcje tłumaczeń.
- Maile tekstowe, nie HTML-owe layouty.
- Ustawienia jako podstrona pod „Użytkownicy", obok listy wniosków — jedno miejsce, bez dokładania pozycji w menu głównym.
- Walidacja numeru pielęgniarki i diagnosty ogranicza się do formatu, bo nie istnieje znana suma kontrolna.

## Co zostaje do decyzji wykonawcy

- Struktura klas, sposób autoloadowania, nazewnictwo hooków i punktów rozszerzeń — w granicach konwencji przeniesionych z `event-registration`.
- Sposób przechowywania stanu konta: metadane użytkownika czy własna tabela. Wybór ma uwzględnić filtrowanie i sortowanie listy wniosków przy kilkuset kontach.
- Sposób osadzenia formularzy na stronie: shortcode, blok, albo oba.
- Przechowywanie logu zdarzeń, logu wysyłek i archiwum wersji regulaminu.
- Przechowywanie liczników limitu prób.
- Mechanizm szablonów maili i sposób nadpisywania ich z poziomu motywu.
- Sposób renderowania pól ACF na froncie, wraz ze stylowaniem pod motyw portalu.
- Struktura własnych typów pól ACF dla numerów uprawnień.
- Zakres testów. Walidatory numerów uprawnień to oczywisty kandydat na testy jednostkowe, bo algorytm jest zamknięty i ma znane przypadki brzegowe. Macierz profesja × pole to drugi — logika widoczności i wymagalności musi dać się przetestować bez WordPressa.

## Do potwierdzenia przed wdrożeniem

- Format numeru PWZ pielęgniarki i położnej — źródło jest nieoficjalne. Sprawdzić na nipip.pl.
- Czy KIDL publikuje jakąkolwiek regułę kontrolną dla PWZDL.
