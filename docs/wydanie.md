# Wydanie wtyczki Portal Users

Wydanie powstaje z tagu `vX.Y.Z` wypchniętego do repozytorium na GitHubie. Workflow
`.github/workflows/release.yml` sprawdza zgodność wersji, przechodzi bramki jakości, wycina
treść wydania z `CHANGELOG.md`, buduje paczkę i tworzy wydanie z assetem `portal-users.zip`.
Aktualizator w portalach (`src/Update/GitHubUpdater.php`) zobaczy to wydanie w czasie do około
doby: składają się na to bufor wtyczki (12 h) i własny odstęp rdzenia WordPressa.

**Pierwsza instalacja na portalu idzie z assetu `portal-users.zip`** — nie z „Source code
(zip)" generowanego przez GitHuba. Tamto archiwum rozpakowuje się do katalogu z nazwą
repozytorium i wersją, i nie ma w nim katalogu `vendor/`, więc wtyczka tylko pokaże komunikat
o brakującym autoloaderze, a późniejsze aktualizacje wylądowałyby w drugim katalogu.

## Zanim powstanie pierwsze wydanie

Trzy rzeczy do potwierdzenia. Żadnej nie sprawdzi za Ciebie test: testy pilnują tylko tego,
żeby wszystkie miejsca w kodzie mówiły **to samo**, a nie żeby mówiły **prawdę**.

1. **Nazwa repozytorium.** `GitHubUpdater::REPO` i nagłówek `Update URI:` w `portal-users.php`
   wskazują dziś `kobysz/portal-users-wp-plugin`. Jeżeli repozytorium nazywa się inaczej,
   trzeba zmienić oba miejsca (test spójności wydania zapali, gdy się rozjadą). Nazwa
   wskazująca nieistniejące repozytorium daje 404, bufor negatywny i **ciszę**: administrator
   widzi „brak aktualizacji", a poprawka nie dojeżdża na żaden portal.
2. **Repozytorium musi być publiczne.** Adres `browser_download_url` assetu w repozytorium
   prywatnym wymaga uwierzytelnienia, którego mechanizm aktualizacji WordPressa nie ma —
   wtyczka ogłosiłaby dostępną aktualizację, a pobranie skończyłoby się błędem. Prywatne
   repozytorium wymaga osobnego mechanizmu tokenu i nie jest zbudowane.
3. **Repozytorium zdalne.** Dopóki projekt nie ma zdalnego na GitHubie, oba workflowy są
   plikami, które nigdy się nie odpaliły. Pierwszy przebieg będzie też pierwszym sprawdzeniem
   tych kroków, których nie dało się uruchomić lokalnie (montaż paczki `rsync` + `zip`).

## Kroki wydania

1. **Zielone wszystkie bramki.** Lokalnie, bo skrypty Composera nie działają na maszynie
   deweloperskiej (`vendor/bin` nie ma nakładek `.bat`):

   ```bash
   vendor/bin/phpcs -q
   vendor/bin/phpstan analyse --memory-limit=2G
   vendor/bin/phpunit
   MSYS2_ARG_CONV_EXCL='*' docker exec -w /var/www/html/wp-content/plugins/portal-users <kontener-tests-cli> vendor/bin/phpunit -c phpunit-integration.xml.dist
   composer lint:syntax
   ```

2. **Wpis w `CHANGELOG.md`.** Nowa sekcja na górze, pod `## [Unreleased]`, w postaci dokładnie
   `## [x.y.z] - RRRR-MM-DD`. Treść dla człowieka obsługującego portal — ta sekcja publikuje się
   dosłownie jako opis wydania i pokazuje się w panelu pod „Szczegóły wtyczki". Bez sekcji dla
   tagu workflow zatrzyma wydanie.

3. **Podniesienie `Version:`** w nagłówku `portal-users.php`. Tylko trzy cyfry, bez prereleasów:
   `0.2.0-beta1` zatrzyma wydanie na bramce formatu, a wcześniej zapali test nagłówka.

4. **Jeżeli wydanie dokłada tabelę albo zdarzenie crona — podnieś `Migrations::DB_VERSION`.**
   To jedyny krok, którego nie wymusi żadna bramka, a jego pominięcie jest ciche.

   Dlaczego: **aktualizacja w miejscu nie odpala hooka aktywacji.** Tabele i harmonogramy
   zakłada `Migrations::run()`, wołane przez `maybe_upgrade()` na `plugins_loaded` **tylko
   wtedy**, gdy zapisana wersja schematu jest niższa od stałej. Bez podniesienia stałej nowe
   zdarzenie nie powstanie na żadnym działającym portalu — a na nowych instalacjach będzie
   działać, więc błąd nie pokaże się w testach ręcznych.

   Pilnują tego dwa testy. `MigrationsTest::test_every_cron_class_is_scheduled_by_run` żąda,
   by każda klasa z `src/Cron/` była planowana przez `run()`.
   `MigrationsTest::test_schema_surface_is_pinned_to_db_version` wiąże odcisk powierzchni
   schematu (definicje tabel plus zestaw hooków cronów) z numerem wersji: zmiana schematu bez
   podniesienia stałej daje niezgodny odcisk, a podniesienie stałej bez dopisania odcisku —
   brak klucza. Oba warianty są czerwone, więc tego kroku nie da się już pominąć po cichu;
   wymaga natomiast świadomej edycji tablicy `SCHEMA_SURFACE` w teście.

5. **Commit, tag, push tagu:**

   ```bash
   git commit -am "release: 0.2.0"
   git tag -a v0.2.0 -m "0.2.0"
   git push origin main --follow-tags
   ```

   **Tag musi być adnotowany** (`-a`). `git push --follow-tags` wypycha wyłącznie tagi
   adnotowane — sprawdzone na atrapie repozytorium: tag lekki został w lokalnym repozytorium
   i trzeba go było wypchnąć osobno. Tag lekki nie dojdzie więc na GitHuba, `release.yml`
   nigdy się nie odpali, a procedura wykonana co do litery nie da wydania. Jeżeli tag już
   powstał bez `-a`, wypchnij go jawnie: `git push origin v0.2.0`.

6. **Sprawdź wydanie na GitHubie**: czy ma asset `portal-users.zip` i czy opis to treść sekcji
   z changeloga. Potem na jednym portalu: *Pulpit → Aktualizacje → Sprawdź ponownie* i czy
   aktualizacja się pokazuje.

   Na pokazanie się aktualizacji składają się **dwa** odstępy: bufor wtyczki (12 h) i własny
   odstęp rdzenia (`wp_update_plugins()` — 12 h na żądaniach frontu, krócej na ekranach
   panelu). W najgorszym razie to około doby, nie dwanaście godzin. Przycisk „Sprawdź
   ponownie" czyści bufor rdzenia, ale **nie** nasz, więc do natychmiastowego sprawdzenia
   trzeba usunąć transient `pusers_update_latest`:

   ```bash
   wp transient delete pusers_update_latest
   ```

## Czego ta wtyczka nie wydaje

- **`readme.txt`** — dystrybucja nie idzie przez wordpress.org, więc plik w formacie katalogu
  wtyczek nie ma odbiorcy.
- **Pliku `.pot`** — językiem bazowym jest polski, a katalog `languages/` jest pusty do
  pierwszego tłumaczenia na inny język. Nagłówek `Domain Path` już go deklaruje.

## Co sprawdza workflow, a czego nie

Sprawdza: format wersji w nagłówku i w tagu osobno oraz ich zgodność, obecność sekcji
changeloga dla tagu, bramki jakości (PHPCS, PHPStan, suita jednostkowa z własnym sprawdzeniem
liczby wykonanych testów), obecność pliku głównego i autoloadera w zmontowanej paczce oraz to,
że korzeń archiwum nazywa się dokładnie jak katalog wtyczki.

Nie sprawdza: czy repozytorium w `Update URI` istnieje, czy jest publiczne, czy paczka działa
na docelowym portalu, i **nie uruchamia suity integracyjnej** — ta wymaga Dockera i wp-env,
więc jej miejscem jest CI na pull requeście, a krok 1 tej procedury każe mieć ją zieloną.
Punkt o `DB_VERSION` jest teraz pilnowany: odcisk powierzchni schematu jest przypisany do
numeru wersji w `MigrationsTest::test_schema_surface_is_pinned_to_db_version`, więc zmiana
tabeli albo zestawu cronów bez podniesienia stałej zapala test.

Montaż paczki (`rsync` z listą wykluczeń i `zip`) nie dał się uruchomić lokalnie — ani host,
ani kontener testowy nie mają tych narzędzi — więc pierwszy przebieg workflow jest jego
pierwszym sprawdzeniem. Mitygacje stoją w samym workflow: asercje na zmontowanej paczce
i asercja korzenia archiwum po `zip`. Zawartość listy wykluczeń pilnuje
`tests/Unit/DistignoreTest.php`, razem z plikami, które workflow tworzy w trakcie wydania.
