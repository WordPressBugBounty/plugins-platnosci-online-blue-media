# Instrukcja dla wtyczki WooCommerce: bramka płatności Autopay

## Podstawowe informacje

Autopay to moduł płatności umożliwiający realizację transakcji bezgotówkowych w sklepie opartym na platformie WordPress (WooCommerce). Jeżeli jeszcze nie masz wtyczki, możesz ją pobrać [tutaj](https://github.com/bluepayment-plugin/autopay-payments/releases).

## Co oferuje wtyczka płatnicza Autopay?

Wtyczka płatnicza Autopay oferuje szereg funkcjonalności wspierających sprzedaż na Twoim sklepie:
- Najpopularniejsze metody płatności w Polsce i Europie
	- Przelewy online ([Pay By Link](https://autopay.pl/baza-wiedzy/blog/ecommerce/platnosc-pay-by-link-na-czym-polega-i-co-mozesz-dzieki-niej-zyskac))
	- Szybkie przelewy bankowe
	- [BLIK](https://autopay.pl/rozwiazania/blik)
	- Visa Mobile
	- [Google Pay](https://autopay.pl/rozwiazania/google-pay)
	- [Apple Pay](https://autopay.pl/rozwiazania/apple-pay)
	- Płatności ratalne
	- Płatności cykliczne
	- Płatności zagraniczne
- Najpopularniejsze sposoby sprzedaży dla platformy WooCommerce
- kup jako gość / kup jak zarejestrowany użytkownik
- checkout krokowy lub checkout blokowy
- przetwarzanie płatności z przekierowaniem do zewnętrznej strony płatności lub pozostając bezpośrednio na sklepie (wybrane metody: karty, BLIK)
- wsparcie środowiska testowego (realizacja testowych transakcji w celu poprawnej instalacji i konfiguracji wtyczki)
- płatności odroczone i ratalne
- natywna integracja z Google Analytics 4 z poziomu wtyczki płatniczej Autopay
- automatyczna weryfikacja poprawności konfiguracji danych autoryzacyjnych we wtyczce

[Zarejestruj swój sklep!](https://autopay.pl/oferta/platnosci-online?utm_campaign=woocommerce&utm_source=woocommerce_description&utm_medium=offer_cta#kalkulator)

## Instalacja

### Wymagania do instalacji wtyczki

- WordPress – przetestowane na wersjach od `6.0` do `6.8`
- Wtyczka WooCommerce – przetestowano na wersjach od `7.9.0` do `9.8.1`
- PHP w wersji min. `7.4`

### Pobierz z Wordpress.org

Platforma [Wordpress](https://pl.wordpress.org/plugins/platnosci-online-blue-media/) skupia różnego rodzaju rozszerzenia kompatybilne ze stronami zbudowanymi w oparciu o WordPress / WooCommerce.
W przypadku wtyczki płatniczej Autopay, na stronie [https://wordpress.org](https://wordpress.org) znajdują się różne (oficjalne i nieoficjalne) wersje wtyczek. Najnowszą wersję wtyczki tworzonej bezpośrednio przez Autopay możesz znaleźć w [marketplacie Wordpress](https://pl.wordpress.org/plugins/platnosci-online-blue-media/).

## Skonfiguruj wtyczkę
Zaloguj się do panelu i przejdź do zakładki **Płatności** i odnajdź metodę **Autopay**. Wybierz **Konfiguruj**, by rozpocząć konfigurację wtyczki. Lub zaznacz odpowiednią opcję na przełączniku, by **włączyć** / **wyłączyć** działanie wtyczki na sklepie.

Jeżeli spotkałeś się z jakimś problemem podczas instalacji wtyczki odwiedź naszą [sekcję FAQ.](https://developers.autopay.pl/online/wtyczki/woocommerce#najcz%C4%99%C5%9Bciej-zadawane-pytania)

### Uwierzytelnianie

Zakładka "Uwierzytelnianie" umożliwi Ci wprowadzenie danych dostępowych Twojego konta w Autopay do wtyczki, a także ustalenie, czy płatności Autopay mają działać na środowisku testowym czy produkcyjnym.
1. **Środowisko testowe**
	- ustawione na **tak** - Służy do przetestowania integracji i konfiguracji wtyczki Autopay na Twoim sklepie. Na środowisku testowym płatnik nie zostanie obciążony za żaden zakup, a Ty nie otrzymasz wpłaty za żadną sprzedaż. Transakcje będą jedynie wirtualne. Pamiętaj, aby nigdy nie wysyłać transakcji za transakcje opłacone w trybie testowym!
	- ustawione na **nie** - Wtyczka działa na środowisku produkcyjnym. Innymi słowy, transakcje i płatności odbywają się naprawdę. Płatnik zostaje obciążony finansowo za zaku, a sprzedawca otrzymuje środki od Autopay za prowadzoną sprzedaż.
2. **Identyfikator serwisu** - Jest to identyfikator Twojego konta Autopay. Znajdziesz go po zalogowaniu się na swoje konto, wybierz z menu "Ustawienia serwisu" a następnie dla sekcji "Konfiguracja techniczna serwisu" kliknij na guzik "Wybierz". ID serwisu to wartość "Identyfikatora serwisu"
3. **Klucz konfiguracyjny (hash)** - Jest to wartość dedykowana dla Twojej strony na Twoim koncie Autopay. Znajdziesz go po zalogowaniu się na swoje konto, wybierz z menu "**Ustawienia serwisu**", a następnie dla sekcji "Konfiguracja techniczna serwisu" kliknij na guzik "Wybierz". Podpisany jest jako Klucz konfiguracyjny (hash)
> Środowisko testowe a Identyfikator serwisu i Klucz konfiguracyjny (hash)
Wartości Identyfikatora serwisu oraz Klucza konfiguracyjnego są różne dla środowiska testowego i produkcyjnego. Jeżeli założyłeś nowe konto Autopay i nie masz jeszcze dostępu do środowiska testowego możesz je uzyskać [wysyłając prośbę o dostęp](https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_documentation&utm_medium=text_link).
>
> Wybierz kategorię weryfikacje, uzupełnij dane, a w treści wiadomości podaj id swojego obecnego serwisu i poproś o utworzenie środowiska testowego dla Twojego sklepu.

### Powiadomienia o statusie transakcji

Autopay komunikuje się z wtyczką za pomocą ITN (Instant Transaction Notification) - czyli specjalnych wiadomości zawierających obecny status rozpoczętej płatności (np. oczekuje na dokonanie płatności, zakup został poprawnie opłacony). W przypadku wystąpienia problemu z połączeniem się Autopay do sklepu, statusy płatności oraz zamówień nie będą aktualizowane. Przykładowo, klient może dokonać płatności za zamówienie, ale sklep nadal będzie wskazywał, że płatność nie została rozpoczęta.

### Ustawienia płatności

Metody płatności mogą być wyświetlane na Twoim sklepie na kilka różnych sposób - w zależności od tego jak sklep został zbudowany lub co osobiście preferujesz, uważasz za bardziej skuteczne. Każdy taki sposób określamy mianem "Trybu płatności". Poniższa tabela pomoże Ci zapoznać się z oferowanymi trybami i dokonać wyboru takiego, które najlepiej pasuje do Twojego sklepu.
- **Przenieś na stronę płatności Autopay** - Na liście metod pojawi się jeden guzik przekierowujący klienta do strony płatności hostowanej przez Autopay, gdzie płatnik zobaczy pełną listę dostępnych metod płatności. Tryb ten jest minimalistyczny i jednocześnie w najmniejszym stopniu ingeruje kod Twojego sklepu. Jeżeli inne tryby sprawiają pewne trudności na stronie lub nie wyświetlają się w poprawny sposób na Twoim sklepie, warto skorzystać z tego trybu.
- **Wyświetl każdą dostępną metodę osobno** - Na liście pojawi się dedykowany guzik dla każdej z dostępnych metod płatności. Płatnik dowie się więc z jakich dokładnie metod może skorzystać, już na stronie Twoje sklepu. Po wybraniu metody, płatnik zostanie przekierowany bezpośrednio na stronę, na której będzie mógł sfinalizować płatność. Na przykład klikając na metodę "BLIK" przeniesiony zostanie na stronę BLIK i poproszony o podanie kod wygnerowanego w swoim banku.
	- **Tryb płatności BLIK** - Jest to osobne ustawienie aktywne tylko jeżeli uruchomiony zostanie tryb "Wyświetl każdą dostępną metodę osobno" i dotyczy sposobu opłacania zamówienia z wykorzystaniem metody płatności "BLIK":
		- "przenieś na stronę BLIK" - po wyborze metody płatności BLIK płatnik zostanie przeniesiony na stronę BLIK i poproszony o podanie kodu płatności oraz zatwierdzenie go w aplikacji. Dodatkowo, płatnik może zapamiętać nasz sklep na urządzeniu, z którego korzysta. Sprawi to, że w przyszłości przy płatności BLIK na naszym sklepie i tym samym urządzeniu nie będzie już pytany o podanie kodu BLIK, a w jego aplikacji bankowej prośba o potwierdzenie płatności pojawi się od razu po przekierowaniu go na stronę BLIK.
		- "wprowadź kod BLIK bezpośrednio na sklepie" - po wyborze metody BLIK płatnikowi wyświetli się dedykowane pole na Twoim sklepie, w które będzie mógł wpisać kod BLIK. Płatnik nie zostanie nigdzie przekierowywany, płatność odbędzie się bezpośrednio na Twoim sklepie.
		  Konfiguracja statusów płatności wpływa bezpośrednio na to jak przebiega zamówienie na Twoim sklepie.
	- **Płatność rozpoczęta** - Proces płatności właśnie się rozpoczął - oznacza to, że płatnik wybrał i zatwierdził konkretną metodę płatności. Zamówienie zostało już utworzone w Twoim sklepie. Płatność nie osiągnęła jeszcze swojego finalnego statusu. Ustaw status zamówienia, który odpowiada temu opisowi.
	- **Płatność zatwierdzona** - Płatność rozpoczęta przez płatnika powiodła się. Ty jako sprzedawca otrzymasz za nią środki od Autopay. Ustaw status zamówienia, który odpowiada temu opisowi.
	- **Płatność zatwierdzona dla koszyka zawierającego tylko produkty wirtualne** - Płatność rozpoczęta przez płatnika powiodła się. Ty jako sprzedawca otrzymasz za nią środki od Autopay. Jest to status dedykowany dla sprzedaży produktów cyfrowych - umożliwiający zlecenie natychmiastowej wysyłki zamówienia do płatnika. Ustaw status zamówienia, który odpowiada temu opisowi.
	- **Płatność nieudana** - Rozpoczęta przez płatnika płatność nie powiodła się. Ty jako sprzedawca nie otrzymasz środków od Autopay. Ustaw status zamówienia, który odpowiada temu opisowi.

### Analityka
Wtyczka Autopay umożliwia wysyłanie bezpośrednio do Google Analytics informacji o dokonaniu płatności. Umożliwia to m.in. śledzenie konwersji sprzedażowej w ramach platformy Google Analytics. Komunikacja z Google Analytics jest opcjonalną funkcją wtyczki i nie jest wymagana do poprawnego działania wtyczki.
Aby nawiązać komunikację wtyczki z kontem Google Analytics należy podać poprawne dane konta Google Analytics.

**UWAGA!** Wtyczka rejestruje zdarzenie "purchase" w oparciu o osiągnięcie przez zamówienie statusu "Completed". Możesz zmienić status wyswalający zdarzenie w Google Analytics korzystając z opcji **Status zamówienia wyzwalający zdarzenie “Zakończenie transakcji”**.

Po połączeniu z wtyczką, w koncie Google Analytics pojawią się następujące zdarzenia:

| Nazwa zdarzenia | Klucz zdarzenia | Opis |
| --------------- | --------------- | ---- |
| Wyświetl produkt na liście | `view_item_list` |  Wyzwalane dla każdego produktu, który znajduje się na liście i jest widoczny dla klienta podczas przeglądania strony. |
| Zobacz szczegóły produktu | `view_item` | Wyzwalane, gdy użytkownik odwiedzi stronę określonego produktu. Wyzwalane podczas wyświetlania/ładowania strony. |
| Kliknij na produkt | `add_to_cart` | Wyzwalane, gdy użytkownik doda produkt do koszyka. |
| Usuń produkt z koszyka | `remove_from_cart` | Wyzwalane, gdy użytkownik usunie produkt z koszyka. |
| Rozpocznij proces realizacji zamówienia | `begin_checkout` | Wyzwalane, gdy użytkownik przejdzie do kasy. |
| Wypełnione dane zamówienia | `set_checkout_option` | Wyzwalane, gdy użytkownik uzupełnił dane zamówienia. |
| Wybór metody płatności | `checkout_progress` | Wyzwalane, gdy użytkownik przeszedł do drugiego kroku zamówienia (wybór metody płatności).|
| Zakończenie transakcji | `purchase` | Wyzwalane po pomyślnym zakończeniu transakcji. Wysyłane jest po stronie serwera, by transakcja została oznaczona, nawet jeśli klient nie powrócił do strony z podziękowaniem. |

Dane wymagane do połączenia wtyczki z Twoim kontem Google Analytics to:
- **Identyfikator pomiaru**, który znajdziesz w Google Analytics:
	1. Zaloguj się do panelu Google Analytics i klnij do "Administrator" w lewym dolnym rogu.
	2. W sekcji "Zbieranie i modyfikowanie danych" kliknij "Strumienie danych".
	3.  Kliknij nazwę strumienia danych.
		4.Twój identyfikator pomiaru znajduje się w prawym górnym rogu (np. G-QCX4K9GSPC).
- **Identyfikator strumienia danych**, który znajdziesz w Google Anlytics:
	1. Zaloguj się do panelu Google Analytics i kliknij "Administracja".
	2. W sekcji "Usługi" kliknij "Strumienie danych".
	3.  Kliknij nazwę strumienia danych.
	4.  Skopiuj identyfikator strumienia danych z pola "Szczegóły strumienia".
- **Tajny klucz API**, który znajdziesz w Google Analytics:
	1. Przejdź do "Administrator" w lewym dolnym rogu.
	2. W sekcji „Usługi” kliknij „Strumienie danych”.
	3. Kliknij nazwę strumienia danych.
	4. Następnie kliknij "Utwórz" w sekcji "Measurement Protocol"

### Ustawienia zaawansowane
Ta sekcja wtyczki służy rozwiązywaniu problemów z konfiguracją wtyczki. Jeżeli Twoja wtyczka działa poprawnie, nie ma potrzeby abyś korzystał z tej sekcji.

**Tryb debudowania** - Uruchamia tryb rozwiązywania błędów na Twoim sklepie. Włączaj go tylko po konsultacji ze wsparciem technicznym Autopay. Tryb ten umożliwia zbieranie szczegółowych informacji dotyczących działania Twojej wtyczki na sklepie, które następnie przesyłane są do deweloperów Autopay pracujących nad rozwiązaniem zgłoszonego przez Ciebie problemu.

**Tryb Sandbox dla Zalogowanego Administratora** - Umożliwia on aby użytkownik zalogowany na Twoim sklepie jako administrator, korzystał z wtyczki Autopay w trybie testowym; podczas gdy pozostali klienci będą cały czas korzystać z płatności Autopay w trybie produkcyjnym.

**Pokazuj metody płatności Autopay na sklepie tylko zalogowanym administratorom** - Tryb umożliwia dostęp do płatności Autopay na Twoim sklepie jedynie użytkownikom zalogowanym jako administrator. Pozostali klienci nie będą mieli dostępu do przeprowadzenia płatności metodami Autopay.

**Wyświetl ekran odliczania przed przekierowaniem w celu zwiększenia kompatybilności** - Ustawienie pomoże pomóc rozwiązać problemy wywołane instalacją na sklepie wybranych wtyczek od innych dostawców (np. wtyczek do analityki, wtyczek do realizacji zamówień kurierskich, itp.).

**Tryb kompatybilności z wtyczkami trzecimi, które przeładowują fragmenty checkout** - Tryb zgodności z wtyczkami innych firm umożliwiający przeładowanie elementów kodu checkoutu.

**Alternatywny produkcyjny adres startu transakcji** - opcja umożliwia skorzystanie z innego adresu rozpoczęcia transakcji, jeżeli taki zostanie uzgodniony z Autopay.

**Zamień domyślny adres potwierdzenia zamówienia** - opcja umożliwia skorzystanie z innego adresu potwierdzenia transakcji, jeżeli taki zostanie uzgodniony z Autopay.

**Nadaj własne stylowanie CSS** - Umożliwia wgranie własnych stylów CSS dla wyświetlania listy metod Autopay na Twoim sklepie. Może być wykorzystany przez pracownika wsparcia technicznego Autopay, aby przygotować kod CSS specjalnie dla Twojego sklepu. Lub, jeżeli jesteś ekspertem w kodowaniu front-end, możesz wykorzystać tę funkcję samodzielnie, aby zmienić style CSS.

## Najczęściej zadawane pytania

### Co to są ITN i czy zostały poprawnie skonfigurowane?
ITN (z ang. Instant Payment Notification) to komunikat wysyłany do Twojego sklepu przez Autopay każdorazowo w sytuacji, gdy status transakcji się zmieni. Wykorzystanie ITN umożliwia sklepowi odpowiednią obsługę zamówienia (n.p., wysłanie zamówienia dopiero, kiedy zostanie opłacone; zablokowanie zwrotu środków do zamówienia, które nie zostało jeszcze opłacone; itp.).
Konfigurację ITN sprawdzić na dwa sposoby: bezpośrednio we wtyczce; oraz na swoim koncie Autopay.
Konfiguracja we wtyczce polega na przeprowadzeniu automatycznego testu w zakładce "Uwierzytelnianie" (wymaga zainstalowania wtyczki Autopay).

Jeżeli chcesz upewnić się, że konfiguracja ITN przebiegła poprawnie po stronie konta Autopay:
1. Upewnij się, że w [produkcyjny portalu administracyjnym](https://portal.autopay.eu/panel) i/lub [testowym portalu administracyjnym](https://testportal.autopay.eu/panel) poniższe pola zawierają poprawne adresy sklepu.
2. Konfiguracja adresu powrotu do płatności `{URL Twojego sklepu}/?bm_gateway_return`
3. Przykład: `https://moj-sklep.com/?bm_gateway_return`
4. Konfiguracja adresu, na który jest wysyłany ITN `{URL Twojego sklepu}/?wc-api=wc_gateway_bluemedia`
5. Przykład: `https://moj-sklep.com/?wc-api=wc_gateway_bluemedia`

### Czy można włączać i wyłączać jedynie wybrane metody płatności?

Niestety, w przypadku Płatności dla WooCommerce nie ma takiej możliwości. Jeżeli metody Autopay są uruchomione na sklepie, to wszystkie dostępne dla merchanta pojawią się również na sklepie.

### Jak włączyć BLIK 0 (wpisanie kodu BLIK bezpośrednio na stronie sklepu, bez przekierowania płatnika na stronę BLIK)?

Aby włączyć tzn. BLIK 0 (wpisywanie kody BLIK bezpośrednio na stronie sklepu, bez konieczności przekierowania płatnika) należy wejść do konfiguracji wtyczki, wybrać sekcję Ustawienia. Następnie wybrać tryb płatności "Wyświetl każdą dostępną metodę osobno" i w ramach "Trybu płatności BLIK" wybrać "wprowadź kod BLIK bezpośrednio na sklepie".

### Czy można dodać inną walutę?

Tak, wtyczka Autopay od wersji `4.1.26` umożliwia dodania innej waluty niż polski złoty. Należy jednak pamiętać, że walutę tę musisz mieć również skonfigurowaną w ramach Twojego konta Autopay. Co zazwyczaj wiąże się z posiadaniem oddzielnych danych uwierzytelniających.
Walutę wspieraną na Twoim koncie Autopay możesz sprawdzić w konfiguracji serwisu w [Portalu](https://portal.autopay.eu/portal).
Aby dodać do swojego konta Autopay kolejną walutę, należy skontaktować się z nami przez [formularz](https://developers.autopay.pl/kontakt).

### Jak zlecać zwroty (z poziomu sklepu czy portalu Autopay)?

Aktualnie zwroty należy zlecać z poziomu portalu Autopay. Zaloguj się do [Portalu](https://portal.autopay.eu/portal) i wejdź w zakładkę "Transakcje", po czym kliknij "Zleć zwrot" w szczegółach zwracanej transakcji.

### Czy jest możliwość umieszczenia samego BLIK-a na whitelabel (wyświetlenia metody BLIK bezpośrednio na liście dostępnych w sklepie metod płatności)?

Niestety nie ma takiej możliwości. Wtyczka umożliwia jedynie:
- wyświetlenie wszystkich dostępnych metod płatności bezpośrednio na liście metod (w tym również BLIK)
  albo
- wyświetlenie jednego zbiorczego przycisku "Zapłać", który po przekierowaniu przekierowuje na dedykowaną stronę Autopay zawierającą listę wszystkich dostępnych dla płatnika metod płatności

### Podczas konfiguracji wtyczki w ustawieniach płatności zamiast wyświetlić listę dostępnych metod płatności pojawia mi się komunikat - brak dostępnych metod płatności dla tej waluty - co mam zrobić?

Dla wybranej przez Ciebie waluty nie ma dostępnych żadnych metod płatności. Skontaktuj się z nami z wykorzystaniem [tego formularza](https://developers.autopay.pl/kontakt) i poproś zespół Autopay o sprawdzenie konfiguracji Twojego konta.


## Zrzuty ekranu

<figure>
  <img
  src="assets/img/screenshot_1.jpg"
  alt="Widok pól do uzupełnienia">
  <figcaption>Widok pól do uzupełnienia</figcaption>
</figure>

<figure>
  <img
  src="assets/img/screenshot_2.jpg"
  alt="Dostępne metody płatności">
  <figcaption>Dostępne metody płatności</figcaption>
</figure>
