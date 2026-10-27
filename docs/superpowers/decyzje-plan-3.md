# Plan 3 (rejestracja na froncie) — decyzje podjęte bez pytania Cię

Wykonanie planu nie zatrzymuje się na pytaniach do człowieka: rozstrzygam i
zapisuję. Przez dziewięć zadań zapadło **73** takich rozstrzygnięć. Poniżej te,
które zmieniają to, co dostajesz, albo które warto żebyś zweryfikował. Pełna
lista z uzasadnieniami i kosztem pomyłki jest w dzienniku wykonania
(`.superpowers/sdd/2026-10-26-portal-users-rejestracja/progress.md`, wpisy
`Ruling:`).

## 1. Rzeczy, które zobaczysz od razu i możesz chcieć zmienić

**Lekarz loguje się adresem e-mail, nie loginem.** Formularz nie ma pola
„nazwa użytkownika": `user_login` jest **losowy i nieodgadywalny**. Początkowo
wyprowadzałem go z części lokalnej adresu, ale `anna.nowak` daje się odgadnąć
z imienia i nazwiska, a `wp-login.php` i odzyskiwanie hasła rozróżniają „nie ma
takiego loginu" od „złe hasło" — czyli ktokolwiek sprawdzi, kto ma konto na
portalu medycznym. WordPress przyjmuje logowanie adresem, więc lekarz nic nie
traci.

**Konsekwencja, o której musisz wiedzieć: lista użytkowników w panelu jest
nieczytelna po loginie.** `display_name` też jest ustawione na tę losową
wartość — z tego samego powodu, tylko mocniejszego, bo nazwa wyświetlana trafia
do archiwów autora, komentarzy i RSS-a. Prawdziwe imię i nazwisko redakcja widzi
w polach ACF na ekranie wniosków, czyli **za uprawnieniem**.

**Potwierdzone przez właściciela projektu (2026-10-27): losowe `display_name`
zostaje.** Nieczytelna lista użytkowników w panelu jest przyjętym kosztem — to
nie usterka i nie należy jej zgłaszać jako znaleziska.

**Adres e-mail jest polem wtyczki, nie polem ACF.** Stoi zaraz po wyborze
profesji, jest **zawsze wymagany** i nie podlega macierzy widoczności. Gdyby był
polem ACF, macierz mogłaby go ukryć dla danej profesji, czyli pozwolić na
rejestrację bez adresu — co dla konta WordPressa jest niemożliwe, bo
`wp_insert_user()` adresu wymaga.

**Profesja z zepsutą konfiguracją numeru PWZ wypada z listy — nie blokuje
rejestracji wszystkim.** Recenzent chciał zamykać formularz globalnie, gdy
którakolwiek profesja wymagająca numeru ma pole poza źródłem albo nie ustawione
jako wymagane. Odrzuciłem: literówka w konfiguracji studenta zablokowałaby
wtedy rejestrację **lekarzom**. Profesja, której wymogu nie da się wyegzekwować,
po prostu nie jest oferowana; gdy wypadną wszystkie, pojawia się komunikat
o braku profesji, a przyczynę widzisz w raporcie spójności.

**Nieczytelna grupa ACF zamyka rejestrację w całości (fail-closed).** Portal,
któremu zniknęła grupa pól, ma przestać przyjmować zgłoszenia, a nie przyjmować
lekarzy bez numeru PWZ. Spójne z Planem 2, który przy nieczytelnej grupie
odrzuca zapis macierzy.

**Pola kontenerowe ACF są poza obsługą.** `group`, `repeater`,
`flexible_content`, `clone`, `tab`, `message`, `accordion` — specyfikacja
wymienia dopuszczone typy i nie ma ich na liście. Wpis macierzy dla kontenera
staje się martwym wierszem **z ostrzeżeniem**, żebyś wiedział, że nic nie robi.
Jeśli któryś portal używa `group` w grupie rejestracyjnej, trzeba ją rozpłaszczyć.

**Nowe ustawienie: pole zgody na regulamin** (`terms_consent_field`). Mój plan
zakładał, że wtyczka sama rozpozna, które pole logiczne jest zgodą — nie
rozpozna. Ten sam wzorzec, którym wskazujesz pole numeru PWZ.

**Domyślny limit rejestracji per IP: 50 na godzinę**, nie 10. Po zmianie
z wcześniejszej rundy **każde** żądanie zużywa pulę, w tym pomyłka walidacyjna
— przy dziesięciu szpital rejestrujący jedenastą osobę w godzinie dostawał
odmowę. Każdy adres e-mail ma osobny próg 3.

**Formularz ma ważność 24 godzin.** Po dobie otwartej karty użytkownik dostaje
komunikat „odśwież stronę" i **przepisuje formularz od nowa** — świadomie, bo
zapisywanie danych z żądań bez ważnego nonce'a otwierałoby botowi drogę do
zapychania bazy transientami rozmiaru żądania POST.

**Pierwsze czyszczenie niepotwierdzonych kont rusza dobę po aktualizacji**, nie
przy pierwszym żądaniu. Inaczej pierwsze wejście na stronę po wgraniu wtyczki
usunęłoby hurtowo cały zaległy stos, **zanim zobaczysz pole ustawienia**.
Zdarzenie zdjęte przez WP Crontrol **nie wraca** samo — samonaprawa jest
jednorazowa, przy aktualizacji, bo to jedyna nieodwracalna operacja we wtyczce
i musi mieć wyłącznik.

## 2. Osiem defektów mojego własnego planu

Każdy wrócił jako runda poprawek, nie jako pytanie do Ciebie:

1. **Brief kazał zlać „brak stanu konta" z „stanem nierozpoznanym" w `null`** —
   a `LoginGate::decide( null )` **wpuszcza**, bo konto dodane ręcznie nie może
   zostać zamknięte poza portalem. Uszkodzona metadana po nieudanej migracji
   wpuściłaby konto, które ma być zablokowane. Wymóg **był już w backlogu**
   z recenzji Planu 1 — mój brief mu przeczył.
2. **Plan nie rozstrzygał, czy adres e-mail i login są polami ACF** (patrz wyżej).
3. **Brief kazał brać adres IP ze skrajnie LEWEJ strony `X-Forwarded-For`** —
   czyli z wpisu w pełni podstawialnego przez klienta. Bot rotujący zmyślone
   adresy omijałby próg per IP.
4. **Mój szkic testu utrwalał błąd Critical**: `pusers_fields[pwz][]=5425740`
   (tablica, nie łańcuch) spełniał „wymagane", walidator dawał tylko miękkie
   ostrzeżenie, handler cicho pomijał pole — powstawało konto lekarza **bez
   numeru PWZ**. Teraz twardy błąd.
5. **Rozstrzygnięcie o „przeterminowanym formularzu" było nieosiągalne dla
   człowieka**: nonce (ważny ≤ doba) i znacznik czasu (przeterminowany > doba)
   mają **rozłączne** okna, więc człowiek z dobową kartą dostawał nagie 403,
   a gałąź zachowująca dane była osiągalna wyłącznie dla bota.
6. **„To samo ograniczenie" dla ponownej wysyłki i maila o próbie rejestracji**
   dało wspólny licznik: osoba trzecia wyczerpywała ofierze możliwość ponownej
   wysyłki.
7. **Zatwierdziłem regułę „rola musi być podzbiorem subscribera"**, która
   blokuje `contributor` — czyli zamkniętą rejestrację na portalu, którego
   administrator nie zrobił nic złego.
8. **Żądając zasilenia cache metadanych w cronie, osłabiłem własność
   bezpieczeństwa**, którą poprzednia recenzja wyróżniła: stan czytany
   bezpośrednio przed usunięciem konta.

## 3. Siedem razy, gdy obrona uderzała w człowieka, nie w bota

To najczęstszy tryb awarii na tej gałęzi i każdy raz powstał z **zacieśniania**
czegoś. Specyfikacja nazywa „uczciwy użytkownik nie może się zarejestrować"
najważniejszym anty-celem, więc każdy wypadek odwracałem:

| Co | Kogo odcinało |
|---|---|
| Próg per IP 10/h | szpital za wspólnym NAT-em, od jedenastej osoby |
| Opis pola zaufanego nagłówka | portal za CDN-em: **cały** ruch w jednym kubełku |
| Licznik per adres zużywany przez błędy walidacji | człowieka, który trzy razy pomylił pole, na godzinę, bez drogi odzyskania |
| Wspólny kubełek wysyłki i próby rejestracji | lekarza czekającego na link, przez osobę trzecią |
| Licznik wysyłki rosnący przed odnalezieniem konta | to samo, powtarzalnie i z zewnątrz |
| Reguła „podzbiór subscribera" | **wszystkich** na portalu z rolą `contributor` |
| Brak adresu IP wrzucany do jednego kubełka | wszystkich naraz, przy niepoprawnym `REMOTE_ADDR` |

## 4. Co świadomie odłożone

**Brama logowania NIE JEST PODPIĘTA.** `LoginGate::decide()` nie ma konsumenta
w kodzie, więc konto z niepotwierdzonym adresem **loguje się normalnie**. To
zakres Planu 4 i stoi w backlogu z dwoma wymogami: podpiąć bramę **oraz**
sprawić, by odmowa dla niepotwierdzonego adresu brzmiała **identycznie** jak
błędne hasło — inaczej własny komunikat sam staje się sondą ujawniającą konto
komuś, kto nie zna hasła. Razem z tym trzeba zamknąć `wp/v2/users` dla
anonimowych, bo 404 kontra 403 też zdradza istnienie konta.

**Pierwsza rzecz do sprawdzenia przed wdrożeniem, ręcznie, na dev z żywym ACF
Pro:** `acf_prepare_field()` przebudowuje atrybut `name` pola z prefiksu
i **klucza**, nie z nazwy, a wtyczka filtruje przychodzące pola białą listą
**nazw**. Jeśli to prawda, formularz wysyła wszystko, wtyczka czyta **nic** —
bez żadnego błędu, z komunikatem „pole wymagane" nad polami faktycznie
wypełnionymi. Cała integracja z ACF jest w testach sprawdzana przez atrapy, więc
dowodzą naszej strony umowy, nie zachowania ACF. Drugie do sprawdzenia tam samo:
czy `true_false` renderuje ukryte zero tak, jak zakłada normalizacja — pomyłka
daje odznaczoną zgodę spełniającą wymóg.

**Pięć nazw metadanych** (`STATUS`, `CONFIRM_HASH`, `CONFIRM_EXPIRES`,
`TERMS_VERSION`, `CONSENT_AT`) nie jest przypiętych testem. Dziś nieszkodliwe,
bo nie ma ich w żadnej bazie; po pierwszym wdrożeniu nazwy są zamrożone, a nic
tego nie pilnuje. Koszt naprawy: pięć asercji.

## 5. Jedna rzecz o jakości dowodów

Jedenaście testów w tym planie przechodziło **bez mechanizmu, który miały
dowodzić** — większość z moich własnych briefów. Jedenasty znalazł wykonawca
u **siebie**, bo mutacja przeżyła. Stąd stałe pytanie do każdego testu: padłby,
gdyby usunąć kod, który sprawdza?

Z tego wyszedł wniosek dla całego projektu: **dowód mutacyjny wymaga
potwierdzenia, że linia jest ZMIENIONA**, nie że wzorzec jest obecny. Dwa razy
na tej gałęzi podmiana w ogóle nie weszła, `grep` pokazywał wzorzec w
**oryginalnych** liniach, a zielona suita wyglądała na „test bez zębów".
Właściwym sprawdzeniem jest `diff` wobec kopii przed uruchomieniem testów.
