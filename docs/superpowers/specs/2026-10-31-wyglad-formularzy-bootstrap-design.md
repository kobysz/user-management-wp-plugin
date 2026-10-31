# Wygląd formularzy frontu na Bootstrapie 5 — projekt

Rozszerzenie wtyczki `portal-users` poza zakres specyfikacji
[2026-10-26](2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md), która wyglądem formularzy
się nie zajmowała. Wydanie 0.2.1 opublikowane, `main` czyste.

## Po co to jest

Pierwsze wstawienie `[portal_users_registration]` na żywą stronę pokazało, że wtyczka wypisuje
formularz bez żadnego wyglądu: etykieta i pole stoją w jednej linii, inputy mają różne szerokości,
przycisk jest domyślnym przyciskiem przeglądarki. Na domyślnej skórce WordPressa wygląda to na
awarię, a nie na formularz rejestracji portalu medycznego.

Wszystkie skórki, które właściciel robi pod te portale, stoją na **Bootstrapie 5**. Formularz,
który nosi klasy Bootstrapa, nie potrzebuje od wtyczki ani jednej linii CSS-a: łapie go Bootstrap
skórki i dziedziczy jej kolory, promienie, odstępy i typografię. To jest cała idea tego projektu —
wygląd ma pochodzić ze skórki, nie z wtyczki.

Osobny przypadek to skórka bez Bootstrapa. Dziś wtyczka trafia wyłącznie na portale właściciela,
ale ma trafić także do klienta z własną stroną. Taki portal dostaje opcję doładowania wyglądu.

## Cele

1. Wyjście wszystkich sześciu shortcode'owych widoków frontu — rejestracja, logowanie, prośba
   o reset hasła, ustawienie nowego hasła z linku, ustawienie hasła po potwierdzeniu adresu,
   ponowna wysyłka linku potwierdzającego — opiera się na klasach Bootstrapa 5.
2. Komunikaty — notki, błędy pól i miękkie ostrzeżenia — korzystają z bootstrapowych wzorców
   (`alert`, `is-invalid`, `invalid-feedback`), a błąd pola jest widoczny **przy polu** wszędzie
   tam, gdzie wtyczka wie, którego pola dotyczy.
3. Ustawienie **układu**: goły stos albo karta, jedno dla wszystkich widoków.
4. Ustawienie **doładowania wyglądu**: arkusz z paczki dla skórki, która Bootstrapa nie ma.
5. Na skórce bootstrapowej wtyczka nie ładuje ŻADNEGO własnego CSS-a poza kilkoma linijkami
   miękkiego ostrzeżenia, którego w Bootstrapie nie ma.

## Czego ten projekt NIE robi (anty-cele)

- **Nie dotyka ekranów w panelu.** Panel to WP admin; Bootstrapa tam nie ma i nie będzie.
  Zmiana w `src/Admin/` ogranicza się do dwóch nowych wierszy na ekranie ustawień.
- **Nie dokłada JavaScriptu.** Walidacja jest i zostaje serwerowa. Bootstrapowe `is-invalid` jest
  tu wyłącznie ubraniem stanu, który przyszedł z serwera po przeładowaniu strony. Nie włączamy
  `was-validated`, nie ruszamy `novalidate` w formularzu rejestracji, nie ładujemy
  `bootstrap.bundle.js` — żaden z tych widoków nie ma komponentu, który by go potrzebował.
- **Nie ładuje niczego z CDN-u.** Portal medyczny nie może wysyłać adresu IP odwiedzającego do
  obcego serwera, zanim ten cokolwiek kliknie. Wszystko, co wtyczka ładuje, jedzie z paczki.
- **Nie wstawia Reboota ani całego Bootstrapa.** Operator, który włącza doładowanie, żeby naprawić
  formularz, nie może w zamian dostać przestawionej typografii nagłówka i stopki portalu.
- **Nie dokłada kroku budowania.** Repozytorium nie ma i nie dostaje kompilacji Sassa ani niczego,
  co trzeba uruchomić przed wydaniem paczki.
- **Nie przepisuje znacznika, który wypisało ACF.** Bufor renderu pola służy do **dołożenia**
  komunikatu obok tego, co ACF wypisało, nigdy do podmiany jego HTML-a wyrażeniami regularnymi.
- **Nie zmienia zachowania żadnego formularza.** Te same pola, te same nazwy w żądaniu, ta sama
  walidacja, te same decyzje, te same teksty komunikatów. Zmienia się znacznik, miejsce, w którym
  komunikat stoi, i jego ton (patrz „Notki”) — nie to, co wtyczka robi ze zgłoszeniem.
- **Porażka, choćby wyglądało dobrze:** urwany skrypt ukrywający pola niewybranej profesji; pułapka
  na boty, która przestała być niewidoczna; komunikat o nieudanym logowaniu, który po tej zmianie
  mówi, czy zły był login, czy hasło.

## Ograniczenia

- **Pól profilu wtyczka nie rysuje.** `AcfFields::render()` oddaje je ACF-owi przez
  `acf_render_field_wrap()`. Wtyczka steruje definicją pola, którą tam podaje — i tylko tym.
  Dlatego klasa inputa i klasa opakowania muszą wejść przez tę definicję.
- **ACF-owego CSS-a na froncie nie ma.** Wtyczka nie woła `acf_form_head()`, więc `acf-field`,
  `acf-label` i `acf-input` to na stronie gołe `div`-y bez żadnych reguł. To dobrze: nic nie
  walczy z Bootstrapem. To też znaczy, że cały wygląd pola musi przyjść z klas, które dokładamy.
- **Klasy `pusers-*` zostają.** `pusers-field` z atrybutem `data-pusers-field` jest uchwytem
  skryptu `assets/js/registration.js`, który ukrywa pola niewybranej profesji; `pusers-registration`,
  `pusers-login`, `pusers-notice` i reszta mogą być zaczepione w CSS-ie któregoś portalu.
  Klasy bootstrapowe **dokładamy obok**, żadnej nie podmieniamy.
- **Pułapka na boty i znacznik czasu zostają nietknięte.** Honeypot ma swoje style inline i ma
  pozostać poza widokiem; żadna klasa Bootstrapa nie może go pokazać.
- **Nie każde pole ACF da się ubrać** — patrz „Zasięg po typach pól ACF”.
- **W środowisku testowym nie ma ACF-a.** `tests/Integration/Support/acf-render-stub.php` zapisuje
  tablicę pola i wypisuje atrapę. To wyznacza, czego testy mogą dowieść — patrz „Testy”.
- **Teksty po polsku, dla operatora portalu**, w tym etykiety dwóch nowych ustawień i ich opisy.

## Opakowanie: `pusers-front`

Każdy z sześciu widoków wypisuje całe swoje wyjście — notki **i** formularz — wewnątrz jednego
`<div class="pusers-front">`. To jest nowy element i jest potrzebny z trzech powodów:

1. **Zasięg arkusza zapasowego.** Notki stoją dziś prawie wszędzie poza `<form>`, a część widoków
   (rejestracja niedostępna, „Dziękujemy za zgłoszenie”, wygasły link) nie ma formularza w ogóle.
   Selektor zamknięty w samym formularzu nie ubrałby ani jednej z nich.
2. **Karta.** Klasy `card` i `card-body` siadają na opakowaniu, nie na formularzu. Dzięki temu
   notka stoi **w karcie**, razem z formularzem, a nie w pełnej szerokości nad wyśrodkowanym
   pudełkiem. Widok, który oddaje notkę i formularz naraz (rejestracja po zgłoszeniu, reset hasła),
   daje **jedną** kartę, nie dwie.
3. **Warunek ładowania.** Strona, która wyrenderowała samą notkę, też ma ją ubraną.

Formularz wewnątrz zachowuje swoją klasę `pusers-form` oraz dotychczasową `pusers-<nazwa>`.

## Mapa klas

Jedno miejsce w kodzie, z którego wszystkie widoki biorą klasy — i jedyna lista, którą musi
pokryć arkusz zapasowy.

| Rola | Klasy |
| --- | --- |
| opakowanie widoku | `pusers-front` |
| opakowanie w trybie „karta" | `pusers-front card`, wewnątrz `card-body` |
| formularz | `pusers-form` + dotychczasowa `pusers-<nazwa>` |
| opakowanie pola | `mb-3` (+ `pusers-field` tam, gdzie dziś jest) |
| etykieta | `form-label` |
| input tekstowy, e-mail, hasło, liczba, URL | `form-control` |
| textarea | `form-control` |
| select | `form-select` |
| opakowanie pola wyboru (tylko pola rysowane przez wtyczkę) | `form-check` |
| checkbox rysowany przez wtyczkę | `form-check-input` |
| etykieta pola wyboru rysowanego przez wtyczkę | `form-check-label` |
| przycisk wysyłki | `btn btn-primary` |
| notka pozytywna | `alert alert-success` |
| notka neutralna | `alert alert-info` |
| notka o odmowie | `alert alert-danger` |
| lista błędów w notce | `mb-0 ps-3` |
| pole z błędem | `is-invalid` |
| komunikat błędu pod polem | `invalid-feedback` |
| miękkie ostrzeżenie pod polem | `form-text pusers-warning-feedback` |

Mapa przechodzi przez filtr `pusers_form_classes` (rola => klasy). Portal na czymś innym niż
Bootstrap podmienia ją u siebie, zamiast łatać wtyczkę. Filtr nie może dodać ani usunąć klas
`pusers-*` — one są kontraktem z własnym skryptem wtyczki i z zasięgiem arkusza.

**Miękkie ostrzeżenie** (`credential_warning`: numer uprawnień nie przechodzi sumy kontrolnej, ale
zgłoszenie i tak idzie do redakcji) nie ma w Bootstrapie swojej klasy. `is-invalid` byłoby
kłamstwem — nic nie jest odrzucone. Dlatego własna klasa `pusers-warning-feedback`, i to jedyna
reguła CSS, którą wtyczka musi mieć **zawsze**, także na skórce bootstrapowej.

## Zasięg po typach pól ACF

Klasa wchodzi przez definicję pola, więc zakres wyznacza to, dokąd definicja sięga.

- **Ubierane:** `text`, `email`, `url`, `number`, `password`, `textarea`, `select`, `date_picker`
  i pokrewne jednokontrolkowe — `form-control` albo `form-select` na kontrolce, `mb-3` na
  opakowaniu.
- **`true_false`:** ACF rysuje jeden checkbox; definicja sięga do jego klasy, więc dostaje
  `form-check-input`. Opakowania `form-check` **nie ma** — struktura ACF-a (osobny `div.acf-label`)
  nie jest tą, której Bootstrap oczekuje, a wymuszenie jej znaczyłoby przepisywanie znacznika ACF-a.
  Wygląd: natywny checkbox w stylu Bootstrapa, etykieta nad nim, nie obok. To dotyczy także pola
  zgody na regulamin.
- **`radio`, `checkbox` (listy opcji):** ACF rysuje `<ul class="acf-radio-list">`, a definicja nie
  sięga do inputów w pozycjach listy. Te pola **zostają jak dziś** — natywne kontrolki, bez klas
  Bootstrapa. Arkusz zapasowy daje im tylko odstępy. To jest świadomy brak, nie usterka.
- **Reszta typów** (repeater, galeria, WYSIWYG i tak dalej) renderuje się jak dziś.

Jeżeli któryś portal będzie tych list potrzebował ładniejszych, droga jest jedna i leży poza tym
projektem: wtyczka przestaje oddawać te typy ACF-owi i rysuje je sama.

## Dwa ustawienia

Oba na ekranie *Portal: ustawienia*, jako dwa wiersze na końcu istniejącej tabeli — bez nowego
nagłówka sekcji, bo `SettingsScreen::render()` to jedna płaska `form-table` i rozbijanie jej
wychodzi poza ten projekt.

1. **Układ formularzy** — `goły` (domyślnie) albo `karta`.
   - *goły*: opakowanie nie narzuca szerokości ani tła, widok zajmuje całą kolumnę treści skórki.
   - *karta*: opakowanie dostaje `card` i `card-body`, ograniczoną szerokość i wyśrodkowanie.
   - Pola zawsze jedno pod drugim. Dwóch kolumn nie robimy: wtyczka nie wie, które pole jest
     krótkie, a zgadywanie po typie ACF rozsypie się na pierwszym dłuższym selekcie.
   - Jedno ustawienie dla wszystkich widoków. Bez atrybutu shortcode'u, bez różnicowania
     per stronę — decyzja właściciela.
2. **Doładuj wygląd formularzy** — wyłączone domyślnie.
   - Włączone: wtyczka ładuje własny arkusz z paczki, i tylko tam, gdzie wypisała wyjście
     któregoś z shortcode'ów (także samą notkę).
   - Wyłączone na skórce bez Bootstrapa znaczy brzydki formularz i to jest **przyjęta cena**.
     Opis przy ustawieniu ma to powiedzieć wprost, żeby operator nie szukał awarii.

Dodanie pola do `Settings` dotyka konstruktora, `defaults()`, odczytu z tablicy i zapisu na ekranie
ustawień. Pole logiczne musi iść przez `SettingsScreen::checkbox()` — inaczej nie da się go
wyłączyć (odznaczony checkbox nic nie wysyła, ukryte pole z jawnym `0` to naprawia). Oba ustawienia
trafiają też do `docs/konfiguracja.md` (Krok 6) i tym samym na ekran pomocy; bramki wiążą te treści
z kodem, więc pominięcie ich zapali `ConfigurationDoc`.

## Dwa arkusze

`assets/css/` — nowy katalog, dziś jest tylko `assets/js/`.

- **Arkusz stały**, ładowany zawsze tam, gdzie widok się pojawił: wyłącznie reguły
  `pusers-warning-feedback`. Kilka linijek. To jedyny CSS, który dostaje skórka bootstrapowa.
- **Arkusz zapasowy**, ładowany tylko przy włączonym ustawieniu:
  - **Definiuje wyłącznie klasy z mapy.** Nie jest Bootstrapem i nie udaje kompletności.
  - **Każdy selektor zamknięty w `.pusers-front`.** Nic nie wychodzi poza widok: ani na nagłówek,
    ani na stopkę, ani na treść strony. Żadnego `*`, żadnego resetu, żadnego `body`.
  - **Bez Reboota, bez siatki, bez zmiennych Bootstrapa.** Wygląd ma być porządny, nie identyczny
    z Bootstrapem.
  - Pokrywa stany, nie tylko spoczynek: `:focus`, `:disabled`, `is-invalid`.
  - Daje odstępy listom opcji ACF-a, których klasy nie obejmują.
  - Napisany ręcznie, trzymany w repozytorium jako gotowy plik. Żadnej kompilacji.

Ryzyko przyjęte świadomie: kto dopisze do mapy nową klasę, musi dopisać regułę tutaj. Zamyka to
test, który czyta mapę klas i sprawdza, że arkusz ma regułę dla każdej roli — rozjazd ma być
czerwony w bramce, a nie na cudzym portalu.

### Kiedy arkusze wchodzą

Dwie warstwy, tym samym wzorcem, którym wtyczka wyłącza stronę z cache'owania:

1. `template_redirect` + `has_shortcode()` na treści zapytanego wpisu — styl wchodzi do `<head>`,
   zanim cokolwiek się wyrenderuje. To pokrywa normalny przypadek: shortcode w treści strony.
2. Awaryjnie `wp_enqueue_style()` z samego renderu — trafi do stopki, czyli widok mignie
   nieubrany, ale **będzie** ubrany. To pokrywa formularz wstawiony widżetem, blokiem albo
   z szablonu, gdzie pierwsza warstwa nie ma czego sprawdzić.

Wywołania są idempotentne, więc obie warstwy razem dają jeden arkusz.

## Notki

Dziś wszystkie notki noszą jedną klasę `pusers-notice`, a atrybut `role` bywa nieprawdziwy:
`Confirmation::notice_html()` daje `role="status"` także dla wygasłego i nieważnego linku. Ton
notki musi pochodzić z **jawnej mapy kod => ton** (sukces, neutralny, odmowa), jednej dla całej
wtyczki, a nie z roli ani z miejsca w kodzie. Tam, gdzie dzisiejsza rola kłamie, poprawiamy ją
razem z tonem: notka o odmowie dostaje `role="alert"`, notka o sukcesie `role="status"`. To nie
jest zmiana zachowania — komunikat i decyzja zostają te same, zmienia się to, co ogłasza czytnik
ekranu i jaki kolor widzi człowiek.

Lista błędów (`<ul class="pusers-errors">`) jest dziś rodzeństwem notki i pojawia się także bez
niej. `<ul>` nie zmieści się w `<p>`, więc: notka i lista, gdy występują razem, scalają się
w **jedno** okienko `alert alert-danger` — akapit i lista pod nim. Lista bez notki dostaje własne
okienko. Ten jeden kawałek to restrukturyzacja znacznika, nie samo doklejenie klas.

## Błędy przy polach

Druga warstwa obok notki: pole, które ma błąd, dostaje `is-invalid`, a pod nim staje
`invalid-feedback` z tym samym komunikatem. Miękkie ostrzeżenie — `pusers-warning-feedback`.

Pola oznacza się **tylko tam, gdzie wtyczka wie, którego pola dotyczy błąd**:

- **Rejestracja** — `RegistrationHandler::flash()` niesie błędy z kluczem pola. Pełne oznaczanie,
  także pól profilu z ACF.
- **Ustawienie hasła** (z linku i po potwierdzeniu adresu) — kod `short` wskazuje pole hasła
  jednoznacznie i niczego nie ujawnia. Oznaczamy.
- **Prośba o reset hasła** — kod mówiący o niepoprawnym adresie wskazuje pole adresu. Oznaczamy.
- **Logowanie** — **nie oznaczamy niczego.** `RESULT_BAD` jest świadomie nieokreślony: nie mówi,
  czy zły był login, czy hasło. Oznaczenie pola byłoby ujawnieniem, która połowa poświadczeń
  pasuje, czyli regresem bezpieczeństwa przebranym za wygląd. Zostaje sam alert.
- **Ponowna wysyłka linku** — bez błędów pól; sam alert.

Dwa miejsca, w których to jest nieoczywiste:

- **Pola konta** (profesja, adres e-mail, hasło) wtyczka rysuje sama — tu jest łatwo.
- **Pola profilu** rysuje ACF. Klasa `is-invalid` wchodzi przez definicję pola (dołączana do
  `$field['class']`), a `invalid-feedback` dokłada się **za** tym, co ACF wypisał, wewnątrz
  opakowania `pusers-field`. Render idzie już przez bufor (`ob_start()` w
  `RegistrationForm::render_form()`), więc jest gdzie to wstawić — dokładamy, nie podmieniamy.
  Klucz błędu pola ACF nosi przedrostek `acf:`; `error_label()` już to rozplątuje i to jest wzór
  do naśladowania.

Bootstrapowy `invalid-feedback` jest ukryty, dopóki nie stoi jako rodzeństwo elementu
z `is-invalid`. Przy znaczniku ACF-a (input siedzi w `div.acf-input`) ten warunek nie będzie
spełniony, więc komunikat przy polu profilu dostaje `d-block`. Czego nie wolno: dokładać
formularzowi `was-validated`, bo wtedy przeglądarka pomaluje na zielono wszystkie pola, których
serwer wcale nie zatwierdził.

## Testy

Bramki bez zmian: `vendor/bin/phpunit`, integracja w kontenerze `-tests-cli-1`, PHPStan level max,
PHPCS. ACF-a w środowisku testowym **nie instalujemy** — to zmiana infrastruktury niewspółmierna
do zadania, a atrapa i tak byłaby zapisem naszych założeń o ACF-ie, nie jego zachowaniem.

Wymagane:

1. Dla każdego z sześciu widoków: test na obecność klas w wypisanym znaczniku — opakowanie,
   formularz, pola rysowane przez wtyczkę, przycisk.
2. Dla pól profilu: test na **kształt definicji** przekazanej do `acf_render_field_wrap()` —
   atrapa ją zapisuje. Asercja dotyczy `class`, `wrapper.class` i `required`.
3. Test wiążący mapę klas z arkuszem zapasowym: każda rola ma regułę.
4. Oba stany ustawienia układu, oba stany ustawienia doładowania — w tym to, że przy wyłączonym
   doładowaniu nie wchodzi nic poza arkuszem stałym.
5. Logowanie: test, że żadne pole nie dostaje `is-invalid` po nieudanym logowaniu. To jest test
   bezpieczeństwa, nie wyglądu, i ma mieć komentarz, który to mówi.

Założenie, którego testy nie pilnują i które trzeba zapisać w kodzie komentarzem: ACF przenosi
`$field['class']` na kontrolkę, a `$field['wrapper']['class']` na opakowanie. Jeśli ACF to zmieni,
formularz zrobi się brzydki — nie zepsuty.

## Co zostaje do decyzji wykonawcy

- **Gdzie mieszka mapa klas.** Jedna klasa, stałe, tablica — byle jedno źródło, czytane przez
  wszystkie widoki i przez test arkusza.
- **Jak etykieta pola ACF dostaje `form-label`.** ACF rysuje etykietę sam, w `div.acf-label`,
  razem z gwiazdką pola wymaganego. Dwie drogi: wymusić klasę przez definicję pola, albo wygasić
  etykietę ACF-a i wypisać własną (wtedy `for` musi wskazać identyfikator, który ACF nada
  inputowi — i trzeba go ustawić przez definicję, żeby był znany). Wymóg: etykieta jest związana
  z polem, a gwiazdka pola wymaganego nie ginie.
- **Szerokość karty.** Rząd 500–650 px wygląda dobrze; dokładna wartość należy do wykonawcy.
- **Kolor miękkiego ostrzeżenia.** Ma czytelnie odróżniać się od błędu i nie udawać go.
- **Nazwy uchwytów stylów i nazwy kluczy ustawień**, zgodnie z konwencją wtyczki.

## Gotowe, kiedy

Formularze wyglądają dobrze na skórce bootstrapowej, gdzie wtyczka ładuje tylko kilka linijek
ostrzeżenia; na skórce bez Bootstrapa z włączonym doładowaniem wyglądają porządnie, a reszta
strony nie drgnęła; z wyłączonym — wyglądają jak dziś i nic nie jest zepsute. Bramki zielone.
Dwa ustawienia opisane w `docs/konfiguracja.md`. Wpis w `CHANGELOG.md` pod `## [Unreleased]`,
napisany dla operatora portalu.
