# Changelog

Wszystkie istotne zmiany wtyczki Portal Users są zapisywane tutaj.

Format trzyma się [Keep a Changelog 1.1.0](https://keepachangelog.com/pl/1.1.0/),
a numeracja [wersjonowania semantycznego](https://semver.org/lang/pl/).

Trzy rzeczy, których nie widać z samego formatu:

- **Sekcja każdej wersji publikuje się dosłownie** jako treść wydania na GitHubie i pokazuje
  się w panelu WordPressa pod „Szczegóły wtyczki". Nagłówek sekcji musi mieć postać
  `## [x.y.z] - RRRR-MM-DD` — na tym stoi wycinanie treści przez workflow wydania.
- **Wpisy pisze się dla człowieka obsługującego portal**, nie dla programisty: co się zmieniło
  z punktu widzenia redakcji i lekarza, a nie które klasy doszły. Nazwy klas i plików zostają
  w historii gita.
- **Numery wersji są wyłącznie trzycyfrowe**, bez prereleasów. `Version:` w nagłówku wtyczki,
  test formatu wersji i bramka workflow wydania mówią to samo; `0.2.0-beta1` zatrzyma wydanie.

## [Unreleased]

### Added

- Wtyczka sama sprawdza wydania na GitHubie i podstawia nowe wersje pod mechanizm aktualizacji
  WordPressa. Pierwsze wgranie nadal ZIP-em; dalsze wersje pokazują się jak każda inna
  aktualizacja wtyczki, razem z opisem zmian.
- Bramka składni na PHP 8.1 w testach: kod niedający się wczytać na najstarszej wspieranej
  wersji PHP zatrzymuje się przed wydaniem, a nie na portalu.
