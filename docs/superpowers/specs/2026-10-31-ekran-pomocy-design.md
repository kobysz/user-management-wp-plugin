# Ekran pomocy z listą startową — projekt

Rozszerzenie wtyczki `portal-users` poza zakres specyfikacji
[2026-10-26](2026-10-26-wtyczka-zarzadzania-uzytkownikami-design.md), która ekranu pomocy nie
przewidywała. Plany 1–9 scalone do `main`, wydanie 0.2.0 opublikowane.

## Po co to jest

Pierwsza instalacja 0.2.0 na żywym WordPressie pokazała lukę: administrator otworzył *Portal:
profesje*, dodał profesję i stanął przed komunikatem „Profesja „lekarz” nie ma wiersza w macierzy
pól”, którego na tym ekranie nie da się usunąć. Właściwa przyczyna leżała dwa kroki wcześniej —
nie była wskazana grupa pól ACF. Wtyczka umie powiedzieć, **co jest nie tak**; nie umie powiedzieć,
**od czego zacząć** ani **w jakiej kolejności**.

Dokument `docs/konfiguracja.md` powstał jako odpowiedź, ale `.distignore` wyklucza katalog `docs`
z paczki wydania: na portalu tego pliku nie ma. Instrukcja musi dojechać w kodzie wtyczki.

## Kto czyta i po co przychodzi

Ustalone z właścicielem produktu:

- **Dzień pierwszy.** Administrator albo webdev, który stronę stawia. Pyta „od czego zacząć”.
- **Potem, na żywym portalu.** Osoba mniej doświadczona, która wtyczki nie stawiała. Przychodzi
  z dwoma pytaniami: „jak dodać albo zmienić pole w formularzu” i „jak weryfikować i zatwierdzać
  użytkowników”. Na żadne z nich lista startowa nie odpowiada — przy skończonej konfiguracji
  świeci zielono i milczy.

Poziom czytelności: ktoś, kto zna panel WordPressa i widział ACF-a, ale nie wie, że macierz pól
istnieje. Wszystkie trzy zadania mają być czytelne dla tej osoby; właściciel nie wskazał
priorytetu między nimi.

Uwaga z kodu: każdy ekran wtyczki wymaga `manage_options`, więc osoba weryfikująca konta jest na
portalu administratorem, nie redaktorem. Specyfikacja tego nie zmienia.

## Cele

1. Ekran **Portal: pomoc** pod menu *Użytkownicy*, z pełną instrukcją w trzech częściach:
   uruchomienie od zera, zmiana formularza na żywym portalu, obsługa wniosków.
2. **Lista startowa** na górze tego ekranu: osiem kroków, przy każdym stan policzony z bieżącej
   konfiguracji i odnośnik do właściwego ekranu. Po skończonej konfiguracji zwija się do jednej
   linii i schodzi z drogi.
3. Jedna linia na ekranie *Portal: ustawienia* prowadząca do pomocy, dopóki konfiguracja nie jest
   skończona — bez niej ekran pomocy jest ekranem, którego nikt nie znajdzie.

## Czego ten ekran NIE robi (anty-cele)

- **Nie jest drugim raportem spójności.** Lista startowa mówi, gdzie jesteś; raport mówi, co jest
  nie tak. Lista nie powtarza tekstów problemów — pokazuje stan kroku i odsyła do raportu.
- **Nie jest kreatorem.** Zero formularzy, zero zapisu, zero nowych opcji, zero zmian schematu.
  Ekran tylko czyta. Kto chce coś zmienić, idzie na ekran, który za to odpowiada.
- **Nie zastępuje dokumentacji ACF.** Opisuje, czego wtyczka od grupy pól wymaga, nie jak się ACF-a
  używa.
- **Porażka, choćby wszystko działało:** ekran, którego nikt nie znajduje; lista świecąca zielono
  przy zablokowanej rejestracji; treść, która rozjechała się z zachowaniem wtyczki. Ostatnie jest
  najgroźniejsze, bo nieodkrywalne — stąd wymóg testu wiążącego treść z `docs/konfiguracja.md`.

## Ograniczenia

- **Treść w stringach PHP**, przez `__()`, w dedykowanych klasach. Nie w markdownie: `docs` nie
  jedzie w paczce, a parsera markdowna wtyczka nie ma i nie dokłada pierwszej zależności
  produkcyjnej (`composer.json` wymaga dziś tylko `php >= 8.1`; pierwsza zależność uruchamia
  znane ryzyko niezakotwiczonych wzorców `.distignore`).
- **Rejestracja poza bramką zależności.** `Menu::register()` i wszystkie pozostałe ekrany wiszą na
  `pusers_register_features`, który odpala się tylko przy spełnionych zależnościach. Ekran pomocy
  rejestruje swoją podstronę bezpośrednio w `Plugin::boot()`, jak brama logowania i strażnik sesji:
  bez ACF Pro pod *Użytkownikami* nie ma dziś nic, a to jest moment, w którym instrukcja jest
  najpotrzebniejsza. Części listy startowej, które czytają ACF, muszą wtedy mówić „nie można
  sprawdzić”, a nie „nie zrobione”.
- **Czystość domeny.** Wyliczanie stanu kroków trafia do `src/Domain/` i nie wolno mu wywołać ani
  jednej funkcji WordPressa (pilnuje `tests/Unit/Architecture/DomainPurityTest.php`). Fakty
  wymagające WordPressa — czy strona jest opublikowana, jaka jest rola domyślna, ile jest kont bez
  stanu — podaje warstwa panelu, dokładnie tak jak `MatrixScreen::issues()` dopina dziś regułę
  o roli domyślnej do wyniku `ConsistencyChecker`.
- **`manage_options`** jak pozostałe ekrany; render za `current_user_can()` z `wp_die( 403 )`.
- **Bez nowych plików assets.** Układ na klasach panelu WordPressa (`wrap`, `notice`, `widefat`,
  `description`). Styl wtyczki to dziś jeden plik JS i żadnego CSS; tak zostaje.
- **Bez zmiany `Migrations::DB_VERSION`** — brak tabel i zdarzeń crona.

## Lista startowa: osiem kroków i czym mierzy się stan

Kolejność i tytuły idą z `docs/konfiguracja.md`. Każdy krok ma stan **zrobione**,
**do zrobienia** albo **nie można sprawdzić** (brak ACF Pro) i odnośnik do ekranu, na którym się
go robi.

| Krok | Zrobione, gdy |
|---|---|
| 1. Grupa pól w ACF Pro | w ACF istnieje choć jedna grupa pól |
| 2. Wskazanie grupy w ustawieniach | grupa wskazana, istnieje i ma choć jedno obsługiwane pole |
| 3. Profesje | istnieje choć jedna profesja |
| 4. Macierz pól | brak błędów (`ERROR`) w raporcie spójności dotyczących macierzy i pól numeru |
| 5. Trzy strony portalu | strony logowania, rejestracji i resetu wskazane i opublikowane |
| 6. Pozostałe ustawienia | wpisany co najmniej jeden adres redakcji i brak błędu o roli domyślnej |
| 7. Szablony maili | **nie da się zmierzyć** — krok bez stanu, tylko odnośnik i zdanie, że treści domyślne działają |
| 8. Portal, który już działał | pokazuje się **tylko** wtedy, gdy są konta sprzed wdrożenia bez stanu |

Konfiguracja jest skończona, gdy kroki 1–6 są zrobione. Wtedy panel zwija się do jednej linii.

Dwie rzeczy, które mierzą się jako podpowiedź przy kroku, a nie jako jego niezrobienie: strona po
zalogowaniu (krok 5) i lista powodów odrzucenia, gdy akceptacja kont jest włączona (krok 6). Puste
nie blokują portalu, więc nie mogą trzymać listy na czerwono — ale administrator ma o nich
usłyszeć.

Linia na ekranie ustawień liczy wyłącznie kroki 1–6: nie wolno jej dokładać zapytania o konta bez
stanu, bo to robi już `BackfillNotice`.

## Treść instrukcji: trzy części

**A. Uruchomienie od zera** — osiem kroków w postaci długiej, to samo co w `docs/konfiguracja.md`:
czego wtyczka wymaga od grupy pól (typy pól numeru i zgody, pola nieobsługiwane, nazwa kontra
etykieta), co znaczą trzy stany macierzy, shortcode'y trzech stron, tabela ustawień z wartościami
domyślnymi, rola domyślna w *Ustawienia → Ogólne*.

**B. Zmiana formularza na żywym portalu** — część, której w `docs/konfiguracja.md` nie ma i którą
trzeba napisać. Dodanie pola to **dwa ekrany w narzuconej kolejności**: ACF, potem macierz. Zrobione
w połowie zostawia pole, którego formularz nie pokaże, albo — gdy pole przemianowano — martwy wiersz
w macierzy. Pole oznaczone w macierzy jako wymagane, którego nie ma w grupie, **blokuje rejestrację
tej profesji**. Przemianowanie pola w ACF jest dla wtyczki nowym polem; etykietę można zmieniać
swobodnie. Usunięcie pola wymaga jawnego sprzątnięcia martwego wiersza.

**C. Obsługa wniosków** — gdzie leży ekran wniosków i co znaczy bąbelek z liczbą, po co jest
odnośnik do rejestru zawodowego przy wniosku, jak wygląda zatwierdzenie i odrzucenie, skąd biorą się
powody odrzucenia, który mail po czym wychodzi i gdzie sprawdzić, że wyszedł.

**D. Najczęstsze komunikaty** — tabela „komunikat → przyczyna”, ta z `docs/konfiguracja.md`.

Ekran jest długi, więc na górze, pod listą startową, stoi spis odnośników do części A–D.

## Rozjazd treści z kodem: jak go pilnować

Test jednostkowy czyta `docs/konfiguracja.md`, wyciąga nagłówki `## Krok N. …` i żąda, by tytuły
kroków listy startowej były **dokładnie** tymi tytułami, w tej samej kolejności. Dopisanie kroku
w dokumencie bez dopisania go na ekranie (i odwrotnie) zapala test. Wzór istnieje:
`tests/Unit/ChangelogTest.php` i `tests/Unit/DistignoreTest.php` czytają dziś pliki repozytorium.

Pozostała treść nie jest wiązana testem — jej prawdziwość jest odpowiedzialnością autora zmiany.
Granica jest świadoma: wiązanie każdego akapitu dałoby testy, które pękają przy każdej korekcie
stylu, a tego nikt nie utrzyma.

## Co zostaje do decyzji wykonawcy

- Podział na klasy: ile ich i gdzie stoją, poza wymogiem, że wyliczanie stanu jest w `src/Domain/`
  i czyste, a odczyt faktów WordPressa w `src/Admin/`.
- Dokładne brzmienie każdego zdania instrukcji — poza tym, co ta specyfikacja nazywa wprost.
- Układ listy startowej (tabela czy lista) i sposób pokazania stanu.
- Czy część A powtarza tabelę ustawień w całości, czy tylko te pozycje, które domyślnie są puste.
