# Wydanie wtyczki Portal Users

Wydanie powstaje z tagu `vX.Y.Z` wypchniętego do repozytorium na GitHubie. Workflow
`.github/workflows/release.yml` sprawdza zgodność wersji, wycina treść wydania
z `CHANGELOG.md`, buduje paczkę i tworzy wydanie z assetem `portal-users.zip`.
Aktualizator w portalach (`src/Update/GitHubUpdater.php`) widzi to wydanie w ciągu
najwyżej dwunastu godzin — tyle trzyma bufor odpowiedzi.

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

   Test `MigrationsTest::test_every_cron_class_is_scheduled_by_run` pilnuje, że każda klasa
   z `src/Cron/` jest planowana przez `run()`. Nie pilnuje i nie może pilnować, czy stała
   została podniesiona przy tym wydaniu.

5. **Commit, tag, push tagu:**

   ```bash
   git commit -am "release: 0.2.0"
   git tag v0.2.0
   git push origin main --follow-tags
   ```

6. **Sprawdź wydanie na GitHubie**: czy ma asset `portal-users.zip` i czy opis to treść sekcji
   z changeloga. Potem na jednym portalu: „Wtyczki → Sprawdź ponownie" i czy aktualizacja się
   pokazuje. Bufor trzyma odpowiedź dwanaście godzin, więc do natychmiastowego sprawdzenia
   trzeba usunąć transient `pusers_update_latest`.

## Czego ta wtyczka nie wydaje

- **`readme.txt`** — dystrybucja nie idzie przez wordpress.org, więc plik w formacie katalogu
  wtyczek nie ma odbiorcy.
- **Pliku `.pot`** — językiem bazowym jest polski, a katalog `languages/` jest pusty do
  pierwszego tłumaczenia na inny język. Nagłówek `Domain Path` już go deklaruje.

## Co sprawdza workflow, a czego nie

Sprawdza: format wersji w nagłówku i w tagu osobno, ich zgodność, obecność sekcji changeloga
dla tagu, obecność pliku głównego i autoloadera w zmontowanej paczce.

Nie sprawdza: czy repozytorium w `Update URI` istnieje, czy jest publiczne, czy `DB_VERSION`
zostało podniesione, i czy paczka działa na docelowym portalu. Montaż paczki (`rsync` z listą
wykluczeń i `zip`) nie dał się uruchomić lokalnie — ani host, ani kontener testowy nie mają
tych narzędzi — więc pierwszy przebieg workflow jest jego pierwszym sprawdzeniem. Zawartość
listy wykluczeń pilnuje `tests/Unit/DistignoreTest.php`.
