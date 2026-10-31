# Konfiguracja wtyczki Portal Users

Dokument dla osoby, która wtyczkę właśnie zainstalowała. Kolejność kroków nie jest dowolna:
każdy krok karmi listę wyborów w następnym. Przeskoczenie dwóch pierwszych kończy się na
ekranie macierzy komunikatem „Profesja … nie ma wiersza w macierzy pól”, którego nie da się
usunąć na tym ekranie.

Wszystkie ekrany wtyczki stoją pod menu **Użytkownicy**.

**Ta sama instrukcja jest w panelu**, na ekranie *Użytkownicy → Portal: pomoc*, razem z listą
startową pokazującą, który krok jest już zrobiony, i z dwiema częściami, których ten dokument
nie ma: o zmianie formularza na żywym portalu i o obsłudze wniosków. Ten plik nie jedzie
w paczce wydania (`.distignore` wyklucza `docs`), więc na portalu jest tylko tamten ekran.
Nagłówki kroków w obu miejscach muszą się zgadzać — pilnuje tego test jednostkowy.

## Czego wtyczka wymaga

- WordPress 6.5, PHP 8.1 (paczka wymusza wersję PHP przy starcie, nie tylko ją deklaruje).
- **ACF Pro**, włączony. Bez niego nie ma z czego zbudować formularza rejestracji.
- Instalacja z assetu `portal-users.zip` z wydania, nie z „Source code (zip)” — patrz
  [wydanie.md](wydanie.md).

Aktywacja zakłada tabele wtyczki i trzy zdarzenia crona (czyszczenie niepotwierdzonych kont,
opróżnianie kolejki maili, retencja logu wysyłek) oraz zapisuje znacznik wdrożenia: moment, od
którego konto uznaje się za „sprzed wtyczki”.

## Raport spójności to lista kontrolna

Ekrany **Portal: macierz pól**, **Portal: profesje** i **Portal: ustawienia** wypisują raport
spójności konfiguracji, błędy przed ostrzeżeniami. Dopóki stoi w nim choć jeden **błąd**,
konfiguracja jest niedokończona — część błędów wyłącza rejestrację. Koniec konfiguracji poznasz
po tym, że raport jest pusty.

## Krok 1. Grupa pól w ACF Pro

*ACF → Grupy pól → Dodaj nową.* Pola tej grupy są formularzem rejestracji: wszystko poza adresem
e-mail i wyborem profesji, które wtyczka rysuje sama.

- **Reguły lokalizacji nie decydują o formularzu rejestracji.** Wtyczka czyta grupę wprost ze
  wskazania w ustawieniach i nie zgaduje jej z lokalizacji. Ustaw lokalizację na **Użytkownik**,
  bo od niej zależy co innego: czy redakcja zobaczy i poprawi te pola na ekranie edycji konta.
- **Pole numeru uprawnień (PWZ/PWZDL) musi być typu `text` albo `number`.** Pole wielowartościowe
  niesie wartość, której bramka rejestracji nie przyjmie; pole logiczne dałoby numer „1”.
- **Pole zgody na regulamin musi być typu „prawda/fałsz” (`true_false`).**
- Wtyczka **nie widzi** pól kontenerowych (`group`, `repeater`, `flexible_content`, `clone`) ani
  układowych (`tab`, `message`, `accordion`). Nie pokażą się w macierzy i nie trafią do formularza.
- Macierz i eksport trzymają **nazwę** pola, nie etykietę. Przemianowanie pola w ACF zostawia
  w macierzy „martwy wiersz” — etykietę można zmieniać swobodnie.

## Krok 2. Wskazanie grupy w ustawieniach

*Użytkownicy → Portal: ustawienia → Grupa pól ACF.* Wybierz grupę z listy, zapisz.

Dopóki stoi tu „— nie wybrano —”:

- macierz pól nie ma z czego zbudować wierszy,
- lista „Pole numeru” przy profesji jest pusta,
- **rejestracja jest wyłączona**: strona z formularzem pokaże „Rejestracja jest chwilowo
  niedostępna”.

## Krok 3. Profesje

*Użytkownicy → Portal: profesje.* Jedna pozycja na zawód, który portal obsługuje.

| Pole | Co wpisać |
|---|---|
| **Klucz** | małe litery, cyfry, myślniki, np. `lekarz`. Konta przechowują klucz, więc **po utworzeniu nie da się go zmienić**. |
| **Etykieta** | nazwa widoczna na formularzu, np. „Lekarz”. Można ją zmieniać. |
| **Rodzaj numeru** | *Numer PWZ lekarza*, *Numer PWZ pielęgniarki*, *Numer PWZDL diagnosty* albo *Bez numeru*. Decyduje, którą sumę kontrolną liczy walidator. |
| **Pole numeru** | pole z grupy z kroku 1, w które wpisuje się numer. Puste tylko przy *Bez numeru*. |
| **Adres rejestru** | strona rejestru zawodowego, w którym redakcja sprawdza numer. Pokazuje się przy wniosku jako odnośnik. |
| **Adres redakcji** | opcjonalny adres powiadomień tylko dla tej profesji. |

## Krok 4. Macierz pól

*Użytkownicy → Portal: macierz pól.* Wiersze to pola grupy, kolumny to profesje. Każda para
dostaje stan:

- **Ukryte** — formularz nie pokaże pola tej profesji i nie zapisze jego wartości.
- **Widoczne** — pole jest, ale wolno je zostawić puste.
- **Wymagane** — bez wypełnienia formularz nie przejdzie.

**Pole numeru uprawnień musi mieć stan „Wymagane”** dla każdej profesji, która numeru wymaga.
Inaczej raport zapali błąd, że profesja wymaga numeru, ale pole nie jest w macierzy wymagane.

Po zapisaniu macierzy gaśnie błąd „Profesja … nie ma wiersza w macierzy pól”.

**Martwe wiersze:** pole usunięte albo przemianowane w ACF zostaje w macierzy. Ekran pokazuje je
osobno i daje jawną akcję usunięcia wpisów. Macierz nie czyści się sama, żeby chwilowo wyłączona
albo przestawiona grupa nie wymazała konfiguracji.

## Krok 5. Trzy strony portalu

Utwórz i **opublikuj** trzy strony, każda z jednym shortcode'em:

```
[portal_users_registration]
[portal_users_login]
[portal_users_password_reset]
```

Potem wskaż je w *Portal: ustawienia*, razem ze **stroną po zalogowaniu**. Strona wskazana, ale
nieopublikowana albo w koszu, **nadal jest celem przekierowania**: `PageUrl::for()` oddaje jej
adres, bo `get_permalink()` działa też dla szkicu. Użytkownik trafia wtedy na stronę, której nie
zobaczy, i to jest gorsze niż brak wskazania. Ostrzeżenie pokazuje **ekran ustawień** przy tym
polu, a nie raport spójności na ekranie macierzy.

## Krok 6. Pozostałe ustawienia

Wartości domyślne są pracujące; ruszaj je świadomie.

| Ustawienie | Domyślnie | Kiedy zmieniać |
|---|---|---|
| Akceptacja kont | włączona | wyłącz, gdy portal ma wpuszczać każdego po potwierdzeniu adresu |
| Adresy redakcji | puste | **wpisz** — bez adresów nikt nie dowie się o nowym wniosku |
| Powody odrzucenia | puste | wpisz te, których redakcja używa; wybiera je z listy przy odrzuceniu |
| Ważność linku potwierdzającego | 48 godz. | |
| Usuwanie niepotwierdzonych kont | po 7 dniach | okno krótsze od ważności linku zapali ostrzeżenie, bardzo długie też |
| Limit zgłoszeń z jednego adresu IP | 50/godz. | podnieś, gdy użytkownicy wychodzą do internetu przez wspólny adres (szpital, uczelnia) |
| Minimalny czas wypełniania formularza | 3 s | |
| Nagłówek zaufanego proxy | puste | wypełnij **tylko** za proxy, które nadpisuje nagłówek własną wartością; pusto = adres połączenia |
| Treść regulaminu + pole zgody | puste | wpisz oba albo żadne — treść bez pola zgody zapali ostrzeżenie, że nie będzie czym wykazać zgody |
| Usuwanie danych przy odinstalowaniu | **wyłączone** | zostaw wyłączone, chyba że świadomie chcesz, by usunięcie wtyczki skasowało ustawienia, profesje, macierz, log zdarzeń i archiwum wersji regulaminu |

Osobno, poza wtyczką: *Ustawienia → Ogólne → Domyślna rola nowego użytkownika*. Rola
z uprawnieniem, którego publiczny formularz nie może nadać osobie zakładającej konto (np.
`unfiltered_html` roli *Redaktor*), **wyłącza rejestrację**. Raport powie, która to rola i które
uprawnienie.

## Krok 7. Szablony maili

*Użytkownicy → Portal: szablony maili.* Osiem wiadomości, wszystkie z treścią domyślną — portal
działa bez dotykania ich:

*Potwierdzenie adresu e-mail*, *Zgłoszenie do redakcji*, *Zgłoszenie do redakcji (konto
zatwierdzone automatycznie)*, *Rejestracja na istniejący adres*, *Wniosek przyjęty*, *Konto
zatwierdzone*, *Wniosek odrzucony*, *Odzyskiwanie hasła*.

Przejrzyj je i podmień na język portalu. Każdy szablon ma do dyspozycji tylko zmienne swojego
rodzaju; ekran je wypisuje.

Wysyłka idzie kolejką, nie w trakcie żądania. *Portal: log wysyłek* pokazuje, co wyszło, co czeka
i co padło.

## Krok 8. Portal, który już działał

Tylko dla portalu z kontami sprzed wtyczki — *Użytkownicy → Import danych*:

1. **Import z poprzedniej wtyczki.** Zmapuj metadane starej wtyczki na pola i stany, zapisz
   mapowanie, uruchom **tryb próbny** (nic nie zapisuje, liczy co by zmienił), przejrzyj raport
   i dopiero wtedy zapisz import. Raport starszy niż godzina albo z innego mapowania jest
   odrzucany.
2. **Nadanie stanu kontom sprzed wdrożenia.** Konto bez stanu nie jest objęte żadną regułą bramy
   logowania i wchodzi na portal niezależnie od tego, czy ktoś je zatwierdził. Przycisk nadaje
   „zatwierdzone” **wszystkim** takim kontom i jest dla portalu, który stanu nie trzymał. Portal,
   który trzymał — niech użyje mapowania.

## Sprawdzenie na koniec

1. Raport spójności pusty na ekranie macierzy.
2. Strona rejestracji pokazuje formularz (nie „Rejestracja jest chwilowo niedostępna”), a zestaw
   pól zmienia się po wybraniu profesji.
3. Zgłoszenie testowe: mail z linkiem przychodzi, wniosek pojawia się w *Portal: wnioski*
   z bąbelkiem przy nazwie w menu, redakcja dostaje powiadomienie.
4. Zatwierdzenie konta: użytkownik dostaje mail i wchodzi na portal. Odrzucenie: mail z powodem
   z listy.
5. *Portal: log wysyłek* bez wierszy „padło”.

## Najczęstsze komunikaty

| Komunikat | Przyczyna |
|---|---|
| „Profesja … nie ma wiersza w macierzy pól” | krok 4 niewykonany dla tej profesji; gdy jednocześnie macierz mówi „Wybranej grupy pól nie wskazano”, prawdziwą przyczyną jest krok 2 |
| „Wybranej grupy pól nie wskazano albo nie istnieje już w ACF” | krok 2 niewykonany albo grupa usunięta lub przemianowana w ACF |
| „Wybrana grupa pól nie zawiera żadnych pól” | grupa wskazana, ale pusta — albo ma wyłącznie typy, których wtyczka nie obsługuje |
| „Profesja … wymaga numeru uprawnień, ale …” | brak pola numeru przy profesji albo pole bez stanu „Wymagane” w macierzy |
| „… wskazane pole … jest w ACF typu …, którego wtyczka nie przyjmuje jako pola numeru” | numerem musi być pole `text` albo `number` |
| „Macierz profesji … oznacza jako wymagane pole …, którego nie ma w wybranej grupie pól” | pole zniknęło z ACF, a w macierzy zostało wymagane. Rejestracja **nie** jest zablokowana: `RegistrationForm::compose()` przycina macierz do pól źródła i wymóg przestaje obowiązywać, więc zgłoszenia przechodzą bez tej danej. Dlatego to błąd, a nie ostrzeżenie — luka jest cicha. Popraw macierz albo wróć polem do grupy |
| „Rejestracja jest wyłączona: domyślna rola …” | *Ustawienia → Ogólne*: rola z niebezpiecznym uprawnieniem albo rola, której na witrynie nie ma |
| „Rejestracja jest chwilowo niedostępna” (na stronie portalu) | brak wskazanej grupy pól, brak choć jednej profesji **albo — najczęściej — żadnej profesji nie da się zaoferować**, bo każda wymaga numeru, którego pola nie ma w grupie lub nie jest dla niej wymagane w macierzy (`can_enforce_credential()`). Przy wysyłaniu formularza ten sam komunikat daje też zablokowana rola domyślna |
