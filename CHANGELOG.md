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

- Formularze na stronie portalu — rejestracja, logowanie i odzyskiwanie hasła — korzystają teraz
  z wyglądu skórki opartej na Bootstrapie 5. Na takiej skórce wtyczka nie dokłada własnych
  stylów: formularz przejmuje jej kolory, czcionki i zaokrąglenia, więc wygląda jak reszta
  portalu, a nie jak wklejony obcy element.
- Dwa nowe ustawienia w *Portal: ustawienia*. **Układ formularzy** wybiera między gołym
  (formularz na całą szerokość treści) a kartą (wyśrodkowane pudełko z ramką i cieniem);
  dotyczy wszystkich trzech stron naraz. **Doładuj wygląd formularzy** jest dla portali, których
  skórka Bootstrapa nie ma: wtyczka dokłada wtedy własny, niewielki arkusz obejmujący wyłącznie
  formularze — nagłówek, menu i stopka zostają nietknięte. Domyślnie wyłączone.

### Changed

- Błąd w formularzu rejestracji widać teraz **przy polu**, którego dotyczy: pole robi się
  czerwone, a komunikat staje pod nim. Lista nad formularzem zostaje, bo zgłoszenie odrzucone
  w całości (nieaktualny formularz, zbyt wiele prób) nie ma pola, przy którym mogłoby stanąć.
  Ostrzeżenie o numerze uprawnień, które nie blokuje zgłoszenia, jest osobno i na żółto.
  Formularz logowania świadomie **nie** oznacza żadnego pola: komunikat nie mówi i nie będzie
  mówił, czy zły był adres, czy hasło.
- Komunikaty na stronie portalu są teraz kolorowane według tego, czym są: zielone potwierdzenia,
  czerwone odmowy. Przy okazji wygasły i nieważny link potwierdzający przestały być ogłaszane
  czytnikowi ekranu jako komunikat o powodzeniu.

### Fixed

- Raport spójności mówił, że pole oznaczone w macierzy jako wymagane, którego nie ma w wybranej
  grupie pól, blokuje rejestrację tej profesji. Nie blokuje: formularz pomija takie pole i
  przestaje go wymagać, a zgłoszenia przechodzą bez tej danej. Komunikat mówi to teraz wprost —
  razem z tym, że dlatego właśnie trzeba go naprawić, bo luka jest cicha. Zachowanie wtyczki
  bez zmian; ściąga „komunikat → przyczyna” na ekranie pomocy poprawiona tak samo.
- Ściąga komunikatów cytowała jeden komunikat w brzmieniu, którego wtyczka nigdy nie wypisuje
  („Macierz zawiera pole …” zamiast „Macierz profesji … zawiera pole …”). Kto szukał po tekście
  z ekranu, tego wiersza nie znajdował.

## [0.2.1] - 2026-10-31

Wtyczka dostaje własną instrukcję w panelu. Pierwsza konfiguracja 0.2.0 na żywym portalu
pokazała, że kolejność kroków jest wymuszona przepływem danych — bez wskazanej grupy pól
ACF macierz nie ma z czego powstać, a dodana profesja od razu zgłasza błąd — ale nic w
panelu tej kolejności nie mówiło. Teraz mówi, razem z listą, która sama sprawdza, co jest
już zrobione.

Nic w zachowaniu rejestracji, akceptacji ani logowania się nie zmienia. Konfiguracja
portali działających na 0.2.0 zostaje nietknięta.

### Added

- Ekran *Użytkownicy → Portal: pomoc* z instrukcją obsługi wtyczki w panelu: uruchomienie
  od zera krok po kroku, zmiana formularza rejestracji na działającym portalu, weryfikacja
  i akceptacja użytkowników oraz ściąga „komunikat → przyczyna”.
- Lista startowa na górze tego ekranu pokazuje, który z ośmiu kroków konfiguracji jest już
  zrobiony, a który został, i prowadzi do ekranu, na którym się go wykonuje. Po skończonej
  konfiguracji zwija się do jednej linii. Ekran ustawień, dopóki konfiguracja nie jest
  skończona, odsyła do tej listy.
- Ekran pomocy działa także wtedy, gdy ACF Pro jest wyłączony albo nieobecny — razem
  z ekranem wniosków jest wtedy jedynym widocznym ekranem wtyczki i mówi, czego brakuje.
  Kroki, których bez ACF Pro nie da się zmierzyć, pokazują „nie można sprawdzić”.

## [0.2.0] - 2026-10-30

Pierwsze wydanie. Wtyczka przejmuje rejestrację, akceptację i logowanie kont na portalach
medycznych: zamiast zakładania kont ręcznie i ekranów `wp-login.php` redakcja dostaje wnioski
z numerem prawa wykonywania zawodu sprawdzonym sumą kontrolną i rozstrzyga je z jednego ekranu.

Wymagania: WordPress 6.5, PHP 8.1 i ACF Pro — bez ACF Pro wtyczka nic nie podpina i mówi o tym
w panelu. Instalacja z assetu `portal-users.zip` dołączonego do wydania, nie z „Source code
(zip)" generowanego przez GitHuba.

### Added

- **Rejestracja z weryfikacją uprawnień zawodowych.** Numer jest sprawdzany sumą kontrolną,
  nie samą długością: PWZ lekarza, PWZ pielęgniarki i położnej, PWZDL diagnosty
  laboratoryjnego. Numer z błędną cyfrą kontrolną nie przechodzi formularza.
- **Trzy strony portalu zamiast ekranów WordPressa**, wstawiane shortcode'ami:
  `[portal_users_registration]`, `[portal_users_login]`, `[portal_users_password_reset]`.
  Strony po logowaniu i po rejestracji wskazuje się w ustawieniach.
- **Potwierdzenie adresu e-mail przed wnioskiem.** Konto bez potwierdzenia nie trafia do
  redakcji, a po ustawionej liczbie dni jest usuwane automatycznie.
- **Ekran wniosków** (*Użytkownicy → Portal: wnioski*) z licznikiem oczekujących przy nazwie
  menu, filtrami i szczegółami zgłoszenia. Rozstrzygnięcie to zatwierdzenie albo odrzucenie
  z powodem z przygotowanej listy; powód trafia do maila do wnioskodawcy. Przy numerze stoi
  link do rejestru, więc sprawdzenie zgłoszenia to jedno kliknięcie.
- **Akceptację można wyłączyć.** Portal, który nie chce moderacji kont, zaznacza jedno pole
  w ustawieniach i rejestracja kończy się działającym kontem.
- **Profesje definiowane w panelu** (*Portal: profesje*) i **macierz pól**
  (*Portal: macierz pól*): dla każdej profesji ustawia się osobno, które pola rejestracji są
  widoczne i które wymagane. Pola pochodzą z grupy ACF Pro, więc dołożenie pola nie wymaga
  programisty.
- **Maile pod kontrolą redakcji**: treści edytowalne w panelu (*Portal: szablony maili*)
  z podstawieniami, adresaci powiadomienia o nowym wniosku z ustawień, kolejka ponawiająca
  nieudane wysyłki i log wysyłek (*Portal: log wysyłek*) czyszczony automatycznie.
- **Logowanie zależne od stanu konta.** Konto oczekujące, odrzucone albo niepotwierdzone nie
  wchodzi na portal i dostaje komunikat mówiący, co dalej, a nie „nieprawidłowe hasło".
- **Ochrona formularzy**: limity prób osobno na adres IP, na adres e-mail i na konto, pułapka
  czasowa na boty, wymuszona polityka haseł oraz obsługa nagłówka zaufanego proxy dla portali
  stojących za CDN-em.
- **Zgoda na regulamin** z treścią ustawianą w panelu, zapisywana przy koncie.
- **Dziennik zdarzeń**: kto i kiedy rozstrzygnął wniosek i co się stało z każdą wysyłką.
  Zapis zostaje na portalu i daje się pokazać przy sporze.
- **Eksport wniosków do CSV** z ekranu wniosków, z danymi aktualnie wybranej zakładki.
- **Obsługa żądań RODO**: dane wtyczki wchodzą do wbudowanego w WordPressa eksportu i
  usuwania danych osobowych, więc żądanie dostępu albo usunięcia obsługuje się jednym
  narzędziem panelu.
- **Import z poprzedniej wtyczki** (*Import danych*): skan kluczy metadanych w bazie,
  mapowanie ich na stany kont i profesje, plan zmian do przejrzenia przed wykonaniem i raport
  po wykonaniu. Import nie zmienia niczego, czego wcześniej nie pokazał na liście.
- **Uzupełnienie stanów kont sprzed wdrożenia.** Konta założone przed wtyczką nie mają stanu;
  panel pokazuje ich liczbę i pozwala nadać stan jednym przyciskiem, nie ruszając kont, które
  wtyczka już obsługuje.
- **Aktualizacje z GitHuba.** Nowe wersje pokazują się w *Pulpit → Aktualizacje* jak każda
  inna wtyczka, razem z opisem zmian. Pierwsze wgranie zostaje ręczne, z paczki ZIP.

### Security

- **Deinstalacja domyślnie nie kasuje niczego.** Usunięcie wtyczki zostawia wnioski i dziennik
  zdarzeń; dane wtyczki znikają tylko wtedy, gdy administrator sam to zaznaczy w ustawieniach.
  Metadane kont nie są kasowane nigdy — konto jest danymi WordPressa, nie wtyczki.
- Eksport CSV wydaje dane osobowe wszystkich kont z zakładki, więc stoi za tą samą bramką co
  zapis: uprawnienie, nonce i żądanie POST. Adres z danymi nie wchodzi do historii przeglądarki
  ani do logów serwera.
