# Decyzje Planu 9 — aktualizacje i wydanie

Trwały zapis z wykonania Planu 9 (`docs/superpowers/plans/2026-10-30-portal-users-aktualizacje-release.md`),
gałąź `plan-9-release`, wykonanie natywne. Dokument ma odpowiadać na pytania „dlaczego tak" bez
czytania historii gita i bez odtwarzania pomiarów.

## 1. Co czeka na decyzję właściciela

Jedna luka, której zadania nie zamknęły, i jedna czynność właściciela. Dwie pozostałe pozycje
tej sekcji zostały rozstrzygnięte zaraz po wykonaniu planu i są poniżej opisane jako zamknięte.

### ~~Nazwa repozytorium na GitHubie~~ — rozstrzygnięte, 2026-10-30

Właściciel podał adres: **`https://github.com/kobysz/user-management-wp-plugin.git`**.
Nazwa zgadnięta w planie (`kobysz/portal-users-wp-plugin`, przez analogię do
`kobysz/event-registration-wp-plugin`) była **błędna**: repozytorium nie nazywa się jak katalog
wtyczki. `GitHubUpdater::REPO` i nagłówek `Update URI:` trzymają prawdziwą nazwę, a test
spójności wydania pilnuje, że mówią to samo.

Dwa skutki tej rozbieżności, warte zapamiętania:

- **Katalog wtyczki (`portal-users`) ≠ nazwa repozytorium (`user-management-wp-plugin`).**
  Dlatego „Source code (zip)" z GitHuba rozpakowuje się do `user-management-wp-plugin-<wersja>/`
  i jest złym źródłem instalacji — instalować wyłącznie z assetu `portal-users.zip`, co mówi
  procedura wydania. Asset nazywa się od katalogu wtyczki, nie od repozytorium, i tak musi
  zostać: jego nazwa decyduje o katalogu instalacji.
- **Nagłówek `Update URI` dalej robi swoje.** Chroni generyczny slug `portal-users` przed
  podstawieniem aktualizacji obcej wtyczki o tej samej nazwie katalogu z wordpress.org — i jest
  to teraz tym ważniejsze, że nazwa repozytorium nie zdradza nazwy katalogu.

### ~~Repozytorium musi być publiczne~~ — sprawdzone, 2026-10-30

`GIT_TERMINAL_PROMPT=0 git ls-remote --heads <adres>` kończy się kodem 0 i **zerem gałęzi**:
repozytorium jest czytelne bez uwierzytelnienia (czyli publiczne) i jeszcze puste. To zamyka
ryzyko opisane niżej.

Dlaczego to było ryzyko: `browser_download_url` assetu w repozytorium prywatnym wymaga
uwierzytelnienia, którego mechanizm aktualizacji WordPressa nie ma. Wtyczka ogłosiłaby dostępną
aktualizację, a pobranie skończyłoby się błędem — stan gorszy od braku aktualizatora, bo
administrator widzi obietnicę, której system nie dowozi. Jeżeli repozytorium kiedyś stanie się
prywatne, aktualizacje przestaną działać i będą wymagały osobnego mechanizmu tokenu.

### Oba workflowy nie odpaliły się ani razu

Repozytorium nie ma zdalnego, więc CI i wydanie są plikami w drzewie. Pierwszy przebieg będzie
też pierwszym sprawdzeniem dwóch kroków, których nie dało się uruchomić lokalnie: montażu paczki
(`rsync --exclude-from` i `zip` — nie ma ich ani na hoście, ani w kontenerze testowym) oraz
publikacji wydania. Mitygacja w środku workflow: po montażu paczka jest sprawdzana na obecność
`portal-users.php` i `vendor/autoload.php`, a brak któregokolwiek przerywa wydanie z `::error::`.

### Luka: wywołanie funkcji istniejącej w PHP, ale nie na podłodze

Nie łapie tego nic automatycznego — zmierzone, zob. sekcja 2. `mb_scrub()` (PHP 8.3) przy
podłodze 8.1 przechodzi PHPStana, przechodzi `php -l` i przeszłoby PHPCompatibility. Wyłapie to
wyłącznie wykonanie tej linii na 8.1, czyli suita integracyjna w kontenerze — a pokrycie `src/`
testami integracyjnymi jest wysokie, ale nie pełne. Do backlogu: PHPStan 2.x ma własne
sprawdzanie dostępności symboli wobec `phpVersion`; podniesienie `phpstan/phpstan` z `^1.11` to
osobna zmiana o własnym ryzyku.

## 2. Bramka wersji minimalnej PHP — co naprawdę działa

Backlog po Planie 8 zalecał dwa domknięcia. **Oba trzeba było zmierzyć, oba opisy były
nieścisłe.** Siedem przebiegów na sondach w `src/Probe82.php`:

| Sonda | Wynik |
|---|---|
| `ini_parse_quantity()` (8.2), bez i z `phpVersion: 80100` | `Function ini_parse_quantity not found.` w obu przypadkach — tej funkcji nie ma w tablicy sygnatur PHPStana 1.11, więc to przypadek, nie bramka |
| `array_is_list()` (8.1) przy `phpVersion: 80000` | `[OK] No errors` — brak sprawdzania dostępności funkcji |
| enumy i `readonly` w całym `src/` przy `phpVersion: 80000` | 21 błędów `Syntax error, unexpected T_CASE` / `expecting T_VARIABLE` |
| `final readonly class` (gramatyka 8.2), **bez** żadnej zmiany konfiguracji | `Readonly classes are supported only on PHP 8.2 and later.` |
| `const ALLOWED = array( AccountStatus::Pending->value )` (semantyka 8.2) | `[OK] No errors` |
| ta sama sonda pod `php -l` w kontenerze 8.1 | `Constant expression contains invalid operations`, kod wyjścia 1 |
| `mb_scrub()` (8.3), bez i z `phpVersion: 80100` | `[OK] No errors` w obu; `php -l` też zielono |

Wnioski:

1. **PHPStan już analizuje kod jako PHP 8.1** — bierze wersję z `config.platform.php`
   w `composer.json`. Dowodzi tego czwarty wiersz: host ma PHP 8.2, a mimo to klasa `readonly`
   jest zgłaszana. Dopisanie `phpVersion: 80100` niczego by nie dodało, byłoby drugim
   `testVersion`: wyglądałoby na straż, a powtarzałoby obowiązujący stan. Dlatego zamiast linii
   konfiguracji powstał **test** pinujący prawdziwy mechanizm — zgodność `Requires PHP`
   w nagłówku z `require.php` i `config.platform.php` (`tests/Unit/PhpFloorTest.php`). Usunięcie
   albo podniesienie `config.platform.php` przeniosłoby bramkę na wersję hosta po cichu.
2. **PHPStan sprawdza gramatykę, nie semantykę.** Ograniczenie zniesione w 8.2 dla wyrażeń
   stałych czytających enum jest semantyczne: parser 8.1 przyjmuje tę linię, a PHP odrzuca ją
   przy kompilacji. `nikic/php-parser` widzi tylko gramatykę. Dlatego bramką główną jest
   `php -l` w kontenerze 8.1 (`scripts/lint-syntax.sh`, `composer lint:syntax`) — ten sam parser
   i ten sam kompilator, na którym stoi portal.
3. **PHPCompatibility nie wraca.** Najnowsze stabilne `php-compatibility` to **9.3.5
   z 27 grudnia 2019** (dostępne są tylko `10.0.0-alpha1/2` i gałęzie `dev-*`). Żaden z jego 117
   sniffów nie wymienia `'8.1'` ani `'8.2'`. Na obecnym kodzie daje jedno znalezisko i jest ono
   fałszywe: `match ( $this )` w metodzie enuma (`src/Domain/Mail/MailKind.php:38`) —
   `grep -c T_ENUM` w pliku sniffa daje `0`. Dodanie go oznaczałoby martwy pakiet, zero pokrycia
   interesującej nas podłogi i rosnącą listę wyciszeń.
4. Wpis `<config name="testVersion" value="8.1-"/>` **usunięty** z `phpcs.xml.dist`; w jego
   miejscu stoi komentarz nazywający dwie prawdziwe bramki i tę jedną lukę.

## 3. Aktualizator — cztery pułapki i dwie bramki ponad wzorzec

Mechanizm przeniesiony z `event-registration` wraz z pułapkami, których nie trzeba odkrywać
drugi raz:

1. **Wpis w `no_update` jest obowiązkowy** dla wtyczek spoza wordpress.org. Bez obecności
   w `response` LUB `no_update` rdzeń uznaje wtyczkę za nieobsługującą aktualizacji
   (`update-supported = false`) i ukrywa przełącznik „Włącz automatyczne aktualizacje".
2. **Rejestracja na `init`, nie `admin_init`.** Na `admin_init` cron i WP-CLI nie widzą
   aktualizacji, więc automatyczne aktualizacje i `wp plugin update` przestają działać.
3. **Buforowanie negatywne.** Limit zapytań GitHuba bez uwierzytelnienia to 60 na godzinę na
   adres, a panel przelicza aktualizacje często; porażka jest zapamiętywana na godzinę.
4. **Świeży odczyt wersji z dysku.** WordPress przelicza aktualizacje w tym samym żądaniu,
   w którym podmienił pliki, mając w pamięci wersję sprzed podmiany. Bez `Plugin::version( true )`
   zaraz po udanej aktualizacji wisiałoby widmo „dostępna aktualizacja".

Ponad wzorzec doszły dwie bramki, obie z Review Focus planu:

- **Tag niepasujący do `x.y.z` jest odrzucany** przed jakimkolwiek porównaniem. Zmierzone:
  `version_compare( '0.2', '0.1.0', '>' )` i `version_compare( '2', '0.1.0', '>' )` są prawdą,
  więc tag pomyłkowy skierowałby wszystkie portale na przypadkowy asset. Uwaga na przykład,
  który krążył w pierwszej wersji planu: `version_compare( 'release-2', '0.1.0', '>' )` jest
  **fałszem** — człon mieszany sortuje się u `version_compare()` poniżej liczbowego. Bramka
  jest potrzebna, ale uzasadniają ją tagi `v0.2` i `v2`, nie `vrelease-2`.
- **Wpis buforowy o nieznanym kształcie jest traktowany jak brak buforu.** Transient przeżywa
  aktualizację wtyczki, więc nowy kod może trafić na kształt sprzed zmiany; czytanie go na ślepo
  kończyłoby się ostrzeżeniem w żądaniu panelu.

### Dlaczego aktualizator stoi poza bramką ACF Pro

Z najmocniejszego powodu w tej rodzinie decyzji (Plany 4–8 mają ich sześć): **aktualizacja jest
drogą naprawy portalu.** Gdyby aktualizator jechał za sprawdzeniem zależności, wydanie psujące
współpracę z ACF Pro odcinałoby portal od poprawki, która to naprawia — a przy zepsutym albo
wyłączonym ACF Pro administrator nie zobaczyłby nawet, że poprawka istnieje. Aktualizator nie
potrzebuje ACF do niczego: pyta API GitHuba i wstawia wpis do transienta.

### `stdClass`, nie dowolny `object`

Wzorzec sprawdzał `is_object( $transient )`. PHPStan na poziomie max nie przepuszcza dopisywania
właściwości dynamicznych do `object` (dwa błędy na `$transient->{$target}`), a rdzeń zawsze podaje
tu `stdClass` — `wp_update_plugins()` buduje `new stdClass`. Obiekt obcej klasy przepuszczamy
nietknięty: wtyczka nie zgłosi wtedy aktualizacji, co jest bezpieczniejsze od pisania właściwości
do cudzego obiektu o nieznanym kontrakcie. Pinuje to `test_foreign_object_transient_is_returned_unchanged`.

## 4. Wydanie — co pilnuje maszyna, a co człowiek

### Korzeń archiwum musi nazywać się jak katalog wtyczki

Najgroźniejszy tryb porażki tego planu. Nazwa katalogu w korzeniu ZIP-a decyduje o katalogu
instalacji: inna nazwa każe WordPressowi rozpakować aktualizację do **nowego** katalogu
i zostawić starą wtyczkę obok, nieaktywną — portal traci zarządzanie kontami w trakcie
aktualizacji. Workflow montuje w katalogu `portal-users`, a `ReleaseWorkflowTest` sprawdza, że
nazwa w pliku workflow jest dosłownie slugiem wtyczki.

### Format wersji w trzech miejscach

Pozycja backlogu zamknięta mechanicznie: `PluginTest::test_real_header_has_semver`,
`ChangelogTest` i bramka workflow mówią to samo — dokładnie `x.y.z`, bez prereleasów.
`ReleaseWorkflowTest::test_workflow_version_guard_uses_the_agreed_version_pattern` sprawdza
obecność tego wzorca w pliku workflow, więc rozluźnienie bramki bez ruszania testów zapala test.

Bramka sprawdza format **osobno dla nagłówka i dla tagu**, zanim je porówna: wyciągnięcie samych
cyfr z nagłówka przepuściłoby `0.2.0-beta1` jako `0.2.0`.

### `sed`, nie `grep -oP`

Wzorzec wyciągał wersję przez `grep -oP 'Version:\s*\K[0-9.]+'`. PCRE jest w GNU grep, ale nie
w busyboksie — a kontener testowy ma busybox, na którym `grep -oP` oddaje **pustą wartość**
i wypisuje pomoc. Wersję wyciąga więc POSIX-owy `sed`, który działa tak samo na runnerze
i w kontenerze, więc tę linię dało się sprawdzić przed tagiem: oddaje `0.1.0` z prawdziwego
nagłówka i `0.2.0-beta1` z atrapy, a bramka formatu to drugie odrzuca.

### Wycięcie sekcji changeloga

Sprawdzone w kontenerze na atrapie z trzema sekcjami: sekcja kończy się na następnym nagłówku
`## [`, obce sekcje nie wchodzą, a brak sekcji dla tagu daje pusty plik i zatrzymuje wydanie
komunikatem „dopisz wpis przed tagowaniem".

### Jedyny krok, którego nie wymusi żadna bramka

**Podniesienie `Migrations::DB_VERSION`, gdy wydanie dokłada tabelę albo zdarzenie crona.**
Aktualizacja w miejscu nie odpala hooka aktywacji; tabele i harmonogramy zakłada
`Migrations::run()`, wołane przez `maybe_upgrade()` tylko wtedy, gdy zapisana wersja schematu
jest niższa od stałej. Pominięcie jest ciche i nie pokaże się w testach ręcznych, bo na nowych
instalacjach wszystko działa.

`MigrationsTest::test_every_cron_class_is_scheduled_by_run` pilnuje części, którą da się
pilnować: **odkrywa** klasy z `src/Cron/` (nie wypisuje ich z pamięci — lista ręczna
przepuściłaby nową klasę dokładnie tak, jak przepuszcza ją aktualizacja w miejscu) i żąda, by
każda była zaplanowana po `run()`. Sprawdzone w obie strony: po zakomentowaniu
`PurgeMailLog::schedule()` test wskazuje klasę i hooka po nazwie.

### Pusta suita kończy się kodem 0

Zmierzone: `vendor/bin/phpunit --filter 'NieIstniejacaNazwaTestu'` wypisuje `No tests executed!`,
zapisuje raport `<testsuites/>` i kończy **kodem 0**; `phpunit --help` wymienia
`--fail-on-incomplete`, `--fail-on-risky`, `--fail-on-skipped` i `--fail-on-warning`, i żadnej
flagi o pustej suicie. Dlatego każdy krok CI z PHPUnit ma własne sprawdzenie liczby wykonanych
testów z raportu JUnit. Bramka sprawdzona w obie strony: na pustym raporcie kończy kodem 1,
na realnym przebiegu wypisuje liczbę testów i kończy kodem 0.

### Macierz wersji WordPressa

Pozycja backlogu zamknięta jobem `integration-wp-floor`, który przestawia rdzeń zmienną
`WP_ENV_CORE: WordPress/WordPress#6.5` — czyli na minimum wtyczki. Składnia refa potwierdzona
w README `@wordpress/env`. Job chodzi na pull requeście i ręcznie, jak reszta jobów na Dockerze.

## 5. Skrypty Composera nie działają na maszynie deweloperskiej

`vendor/bin` nie ma windowsowych nakładek `.bat` (zależności zainstalowano z kontenera), więc
`composer lint` i `composer analyse` kończą się `'phpcs' is not recognized as an internal or
external command`. `composer install` na hoście nie regeneruje nakładek („Nothing to install").

Skrypty Composera zostają **interfejsem CI** — tam biegną na Linuksie i tam backlog ich wyraźnie
wymaga (`composer analyse` trzyma `--memory-limit=2G`, bez którego PHPStan pada na równoległym
workerze, a limitu nie da się podać parametrem neona w 1.x). Lokalnie bramki uruchamia się przez
`vendor/bin/*`; wypisuje to procedura wydania. Do backlogu: regeneracja nakładek `.bat`.

## 6. Recenzja całej gałęzi i fala poprawek

Recenzja na opusie: **„gotowe do scalenia tak, gotowe do otagowania pierwszego wydania nie"** —
zero Critical, pięć Important, jedenaście Minor. Trzy twierdzenia recenzenta sprawdziłem sam,
zanim cokolwiek poprawiłem; wszystkie trzy się potwierdziły, a jedno z nich przewróciło moją
własną przesłankę:

| Twierdzenie | Pomiar |
|---|---|
| `version_compare( 'release-2', '0.1.0', '>' )` jest **fałszem** | potwierdzone: `false`. Prawdą są `'0.2'` i `'2'` |
| Rdzeń gasi `update-supported` dla wtyczki nieobecnej w `response` i `no_update` | potwierdzone w `class-wp-plugins-list-table.php`: przypisanie `true` tylko w tych dwóch gałęziach, `false` w `elseif`, a na tej wartości wisi przełącznik automatycznych aktualizacji |
| `git push --follow-tags` nie wypycha tagów lekkich | potwierdzone na atrapie repozytorium: po `--follow-tags` w zdalnym stał tylko tag adnotowany |

### Poprawione (jedna fala, każda poprawka z testem, który najpierw był czerwony)

- **Bramka składni przechodziła, gdy nie sprawdziła ani jednego pliku.** `for f in $( find … )`
  przy nieistniejącym `src` nie wykonywał ciała pętli i skrypt kończył zerem — zmierzone
  w kontenerze. To ta sama klasa porażki, którą Review Focus 5 zamknął dla PHPUnita,
  zostawiona otwartą w **jedynej pełnej bramce podłogi PHP**. Teraz brak celu i pusty zbiór
  plików są błędem, a skrypt wypisuje licznik („sprawdzonych plikow 119").
- **Nieudane sprawdzenie gasiło przełącznik automatycznych aktualizacji.** Przy 403 z limitem,
  braku sieci, tagu w obcym formacie albo **pomyłce w nazwie repozytorium** wtyczka nie trafiała
  do żadnej z dwóch tablic, więc rdzeń uznawał ją za nieobsługującą aktualizacji — trwale
  i bez komunikatu, dokładnie wbrew docblockowi tej samej metody. Teraz porażka zapisuje wpis
  do `no_update` z pustym adresem paczki; wpis o już znanej aktualizacji zostaje nietknięty,
  żeby porażka nie zabrała administratorowi widoku aktualizacji, o której wtyczka wie.
- **Wydanie nie przechodziło żadnej bramki jakości.** Tag z brudnej gałęzi publikował paczkę,
  którą portale z włączonym przełącznikiem zainstalowałyby same, a CI na push leci tylko po
  `main` i bez jobów integracyjnych. Workflow wydania uruchamia teraz PHPCS, PHPStana i suitę
  jednostkową (z bramką niepustej suity) **przed** zbudowaniem paczki. Suity integracyjnej tam
  nie ma — wymaga Dockera; jej miejsce to CI na pull requeście i krok 1 procedury.
- **Korzeń archiwum był założony, nie sprawdzony.** Test pinował literał `STAGE="portal-users"`
  w pliku, więc zostałby zielony po zmianie sposobu pakowania (`cd "$STAGE" && zip ../x.zip .`).
  Po `zip` stoi teraz asercja `unzip -Z1 | head -1` — to jedyne ogniwo montażu, którego nie da
  się sprawdzić przed tagiem, a zły korzeń zabiera portalowi zarządzanie kontami w trakcie
  aktualizacji.
- **`RELEASE_BODY.md` jechał w każdej wydanej paczce.** Plik powstaje w trakcie wydania, więc
  test pokrycia pierwszego poziomu go nie widział — na maszynie deweloperskiej nie istnieje.
  `DistignoreTest` ma teraz osobną listę plików tworzonych przez workflow.
- **Review Focus 4 dostał mechaniczny pin.** Przypisany test pilnował „`run()` planuje wszystkie
  crony", a nie „`DB_VERSION` podniesione razem ze zmianą schematu" — co dokumentacja wcześniej
  przyznawała, twierdząc, że pilnować się tego nie da. Da się:
  `test_schema_surface_is_pinned_to_db_version` liczy odcisk z definicji `CREATE TABLE` w źródle
  `Migrations` plus posortowanych hooków cronów i wiąże go z numerem wersji. Sprawdzone w obie
  strony: zmiana typu kolumny `attempts` bez podniesienia stałej zapala test.
- **Fałszywa przesłanka `release-2`** poprawiona w planie, w komentarzu kodu i w komentarzach
  testów; doszedł test tagu jednoczłonowego (`v2`), bo to on naprawdę przechodziłby porównanie.
- **Procedura wydania nie wypchnęłaby tagu** (`git tag` bez `-a` plus `--follow-tags`), myliła
  ekran („Wtyczki" zamiast „Pulpit → Aktualizacje"), obiecywała dwanaście godzin zamiast około
  doby (dwa niezależne odstępy: nasz bufor i odstęp rdzenia) i nie mówiła, że pierwsza
  instalacja idzie z assetu, a nie z „Source code (zip)" — to ostatnie kończyłoby się wtyczką
  bez `vendor/` w katalogu o innej nazwie.

### Odłożone świadomie (po przegradowaniu po skutku)

- **Wzorce w `.distignore` nie są umocowane do pierwszego poziomu**, więc `tests`, `docs`,
  `scripts`, `composer.json` i `*.dist` wycinałyby te nazwy także z wnętrza `src/` i `assets/`.
  Dziś nie trafia nic — sprawdzone. Umocowanie wiodącym ukośnikiem zmienia semantykę wykluczeń
  tuż przed pierwszym wydaniem i mogłoby wyciąć coś potrzebnego, więc idzie do backlogu.
- **Akcje przypięte do ruchomych tagów** (`actions/checkout@v4` itd.) — decyzja o modelu
  zagrożeń należy do właściciela; do backlogu.
- **Bufor to transient bloga pod hookiem transientu sieci** — bez znaczenia przy single site,
  który jest jawnym celem negatywnym specyfikacji; na multisite każda podstrona pytałaby
  GitHuba osobno.
- **`wpautop()` nie renderuje Markdowna**, więc okno „Szczegóły wtyczki" pokaże `###` i `-`
  dosłownie. Tak samo jest we wzorcu; obietnica „publikuje się dosłownie" jest tu dosłowna
  także co do znaczników.
- **Brak wymuszenia odświeżenia buforu** z panelu. Oba TTL-e są ograniczone, więc nic nie
  blokuje się na stałe; procedura każe usunąć transient.
- **Bramka niepustej suity wymaga tylko `>= 1` testu**, więc przebieg jednego testu z 1221 też
  przeszedłby. Pin liczby testów byłby kruchy przy każdym nowym teście.
- **Odkrywanie klas crona nie wchodzi w podkatalogi `src/Cron/`** i pomija klasę bez metody
  `schedule()`. Dziś katalog jest płaski, a klasa crona bez `schedule()` nie istnieje.

## 7. Czego ten plan nie zbudował

- **`readme.txt`** — dystrybucja nie idzie przez wordpress.org, plik w formacie katalogu wtyczek
  nie ma odbiorcy.
- **Plik `.pot`** — językiem bazowym jest polski, katalog `languages/` jest pusty do pierwszego
  tłumaczenia na inny język.
- **Mechanizm tokenu dla repozytorium prywatnego** — zob. sekcja 1.
- **Weryfikacja montażu paczki lokalnie** — brak `rsync` i `zip` na hoście i w kontenerze.
