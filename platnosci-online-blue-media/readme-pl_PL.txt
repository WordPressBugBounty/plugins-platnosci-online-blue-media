=== Autopay ===
Contributors: inspirelabs
Tags: woocommerce, bluemedia, autopay
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 5.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Autopay to moduł płatności umożliwiający realizację transakcji bezgotówkowych w sklepie opartym na platformie WordPress (WooCommerce).

== Description ==

Autopay to moduł płatności umożliwiający realizację transakcji bezgotówkowych w sklepie opartym na platformie WordPress (WooCommerce). Jeżeli jeszcze nie masz wtyczki, możesz ją pobrać [tutaj](https://github.com/bluepayment-plugin/autopay-payments/releases).

**Wtyczka płatnicza Autopay oferuje szereg funkcjonalności wspierających sprzedaż na Twoim sklepie:**

- Najpopularniejsze metody płatności w Polsce i Europie
  - Przelewy online ([Pay By Link](https://autopay.pl/baza-wiedzy/blog/ecommerce/platnosc-pay-by-link-na-czym-polega-i-co-mozesz-dzieki-niej-zyskac))
  - Szybkie przelewy bankowe
  - [BLIK](https://autopay.pl/rozwiazania/blik)
  - Visa Mobile
  - [Google Pay](https://autopay.pl/rozwiazania/google-pay)
  - [Apple Pay](https://autopay.pl/rozwiazania/apple-pay)
  - Płatności ratalne
  - Płatności zagraniczne
- Najpopularniejsze sposoby sprzedaży dla platformy WooCommerce
- kup jako gość / kup jak zarejestrowany użytkownik
- checkout krokowy lub checkout blokowy
- przetwarzanie płatności z przekierowaniem do zewnętrznej strony płatności lub pozostając bezpośrednio na sklepie (wybrane metody: karty, BLIK)
- wsparcie środowiska testowego (realizacja testowych transakcji w celu poprawnej instalacji i konfiguracji wtyczki)
- płatności odroczone i ratalne
- natywna integracja z Google Analytics 4 z poziomu wtyczki płatniczej Autopay
- automatyczna weryfikacja poprawności konfiguracji danych autoryzacyjnych we wtyczce
- wielojęzyczność – automatyczne dopasowanie do języka sklepu (EN, DE, IT, ES), a w przypadku innych języków – interfejs w języku angielskim
- możliwość ręcznej zmiany kolejności metod płatności Autopay w panelu WooCommerce metodą drag & drop

[Zarejestruj swój sklep!](https://autopay.pl/oferta/platnosci-online?utm_campaign=woocommerce&utm_source=woocommerce_description&utm_medium=offer_cta#kalkulator)


**Wymagania**

- WordPress – przetestowane na wersjach od 6.0 do 7.0
- Wtyczka WooCommerce – przetestowano na wersjach od 7.9.0 do 10.8.1

== Installation	 ==

Zainstaluj wtyczkę w panelu administracyjnym Wordpress:

1. Pobierz wtyczkę
2. Przejdź do zakładki Wtyczki > Dodaj nową a następnie wskaż pobrany plik instalacyjny.
3. Po zainstalowaniu wtyczki włącz moduł.
1. Przejdź do zakładki WooCommerce ➝ Ustawienia ➝ Płatności.
2. Wybierz Autopay, żeby przejść do konfiguracji.

## Skonfiguruj wtyczkę
Zaloguj się do panelu i przejdź do zakładki **Płatności** i odnajdź metodę **Autopay**. Wybierz **Konfiguruj**, by rozpocząć konfigurację wtyczki. Lub zaznacz odpowiednią opcję na przełączniku, by **włączyć** / **wyłączyć** działanie wtyczki na sklepie.

Jeżeli spotkałeś się z jakimś problemem podczas instalacji wtyczki, odwiedź naszą [sekcję FAQ.](https://developers.autopay.pl/online/wtyczki/woocommerce#najcz%C4%99%C5%9Bciej-zadawane-pytania)

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


== Screenshots ==

1. Widok pól do uzupełnienia
2. Dostępne metody płatności


== Changelog ==

### 5.0.4 (22.09.2026) ###
* Naprawiono: odpowiedź potwierdzająca ITN wysyłana do Autopay zawierała niepoprawny pseudo-atrybut XML `standalone=""`, przez co Autopay odrzucał odpowiedź z błędem parsowania XML ("Invalid XML pseudo-attribute 'standalone'"). Atrybut ten nie jest już generowany.

### 5.0.3 (17.09.2026) ###
* Naprawiono: poprawiono wykrywanie dostępności Apple Pay w checkout, eliminując puste sekcje Apple Pay na nieobsługiwanych urządzeniach i w przeglądarkach, również przy niestandardowej kolejności ładowania skryptów checkoutu.
* Naprawiono: poprawiono obsługę błędów Google Pay, aby niepełne dane konfiguracyjne nie zakłócały działania sekcji płatności w checkout.

### 5.0.2 (14.09.2026) ###
* Naprawiono: zadeklarowano zgodność z High-Performance Order Storage (HPOS); odczyt/zapis meta zamówienia w całej wtyczce przełączony na bezpieczne dla HPOS API meta zamówienia.
* Naprawiono: selektor kanałów płatności na stronie "Zapłać za zamówienie" (order-pay) — wcześniej wyświetlał się tam sam opis zamiast listy metod płatności.
* Naprawiono: płatność BLIK-0 na stronie order-pay przekierowuje teraz na stronę potwierdzenia zamówienia po rozpoczęciu płatności, zamiast przeładowywać stronę order-pay.
* Naprawiono: ponowna próba płatności (Google Pay, BLIK z przekierowaniem lub domyślny kanał) na stronie order-pay po nieudanej wcześniejszej próbie poprawnie przekierowuje teraz ponownie do bramki płatności, zamiast kończyć się cicho niepowodzeniem z nieopłaconym zamówieniem.
* Naprawiono: lista metod płatności na stronie order-pay mogła pozostać trwale ukryta z powodu błędu synchronizacji z własnym skryptem WooCommerce; lista wyświetla się teraz zawsze poprawnie.
* Naprawiono: zapytanie do API listy bramek było wykonywane przy każdym załadowaniu strony; teraz wywoływane jest tylko tam, gdzie faktycznie potrzebne, poprawiono też filtrowanie kanałów na stronie order-pay.
* Naprawiono: pozycję grupy portfeli Apple Pay / Google Pay i wewnętrzną konfigurację grup, przywróconą po wcześniejszym podziale typu grupy portfeli.
* Naprawiono: zapis ponownej próby płatności dla zamówienia już śledzonego wewnętrznie nie kończy się już cichym błędem — teraz aktualizuje istniejący rekord statusu zamiast próbować zduplikować wpis w bazie danych.
* Naprawiono: sprawdzanie minimalnej wersji PHP poprawnie wymusza teraz PHP 7.4 (rzeczywisty wymóg wtyczki); wcześniej sprawdzanie wymuszało tylko PHP 7.2, pozwalając na uruchomienie wtyczki — z potencjalnymi błędami — na nieobsługiwanych wersjach PHP.
* Naprawiono: unieważnianie cache listy bramek (wywoływane przy zmianie języka witryny) czyści teraz również cache obiektowy WordPressa, zapobiegając serwowaniu nieaktualnych danych kanałów płatności na hostingach z trwałym cache'em obiektowym (Redis/Memcached).
* Bezpieczeństwo: dane osobowe klienta (imię i nazwisko, e-mail, telefon, adres IP, numer konta bankowego) są teraz usuwane z logów debugowania ITN przed zapisem na dysk.
* Ulepszono: przekierowanie płatności ekspresowej wykrywane jest teraz automatycznie z meta zamówienia, gdy brak parametru w adresie URL, co zwiększa niezawodność przekierowania do bramki.
* Naprawiono: kilka wewnętrznych błędów diagnostyki/logowania znalezionych podczas wewnętrznego refaktoru klasy bramki (brak wyświetlania tytułu grupy kanałów płatności, błąd krytyczny przy zamówieniu zawierającym wyłącznie produkty wirtualne, nieprawidłowe typy danych zdarzeń GA4 oraz problem zgodności z PHP 7.4 dotyczący właściwości typowanych).

### 5.0.1 (22.07.2026) ###
* Naprawiono: luki XSS — wyjście we wszystkich szablonach administracyjnych i polach ustawień zostało poprawnie escapowane przy użyciu `esc_html_e()`, `esc_attr_e()`, `esc_url()` i `wp_kses_post()`.
* Naprawiono: ochrona CSRF — dodano weryfikację nonce w obsłudze płatności na stronie konta, edytorze CSS i importerze konfiguracji.
* Naprawiono: sanityzacja danych wejściowych — dodano `wp_unslash()` przed `sanitize_text_field()` we wszystkich miejscach odczytu `$_POST`/`$_GET`; kod BLIK walidowany jako dokładnie 6 cyfr.
* Naprawiono: bezpieczeństwo narzędzia Test Connection — dodano sprawdzenie `current_user_can('manage_woocommerce')`; poprzednio niezalogowani użytkownicy mogli inicjować testowe transakcje.
* Naprawiono: podatność open redirect — zastąpiono `wp_redirect()` przez `wp_safe_redirect()` z jawną listą dozwolonych hostów bramki płatności.
* Naprawiono: zabezpieczenie przed bezpośrednim dostępem do plików PHP — dodano `defined('ABSPATH') || exit` do wszystkich plików źródłowych.
* Naprawiono: wyjście zapisanego CSS sanityzowane przez `wp_strip_all_tags()`, zapobiegając wydostaniu się przez `</style>` w edytorze CSS.
* Naprawiono: wyjście dziennika debugowania — zastąpiono `serialize()` i `print_r()` przez `wp_json_encode()`.
* Naprawiono: polskie tłumaczenia wczytują się poprawnie — bundlowany plik `.mo` jest preferowany, rozwiązuje częściowy polski interfejs pod WordPress 6.7+ z JIT loading.
* Naprawiono: usunięto nadmiarowe wywołanie `load_plugin_textdomain()` z `compatibility.php`.
* Naprawiono: punkt wejścia wtyczki przywrócony do `bluemedia-woocommerce.php`; zaktualizowano pipeline CI.
* Naprawiono: przycisk reset (`bm_reset_order`) na stronie ustawień działa poprawnie.
* Naprawiono: atrybut class listy metod płatności jest poprawnie escapowany.

### 5.0.0 (06.07.2026) ###
* Dodano: płatność kartą przez widget Autopay na klasycznym i blokowym checkoucie.
* Fixed: PHP Deprecated notice on PHP 8.4+ during GA4 event handling for Google Pay payments.
* Updated: isolated GA4 Measurement Protocol library to version 0.1.6.
* Updated: isolated Guzzle dependency to version 7.12.3 to resolve security advisories.

### 4.9.3 (29.06.2026) ###
* Ulepszono: typografię panelu administracyjnego z użyciem lokalnych czcionek Open Sans i Roboto Condensed zgodnych z nowym systemem designu.
* Naprawiono: atrybucję transakcji w GA4 poprzez powiązanie zdarzeń purchase z danymi sesji użytkownika.
* Naprawiono: zabezpieczenia endpointu testu połączenia oraz typowanie HMAC i porównywanie sygnatur.

### 4.9.2 (22.06.2026) ###
* Dodano: opcję wyboru trybu działania Google Pay — przekierowanie na stronę Google Pay lub płatność bezpośrednio w sklepie
* Dodano: ustawienia dopasowania logo do kolorystyki checkoutu, w tym wybór wersji jasnej i ciemnej
* Ulepszono: style interfejsu w ustawieniach wtyczki i na checkoucie (checkboxy, przyciski)
* Ulepszono: widoczność i dostępność przycisków w stanie focus podczas nawigacji klawiaturą
* Naprawiono: błąd na stronach z edytorem blokowym, gdy lista metod płatności nie mogła zostać pobrana
* Naprawiono: wybór banku nie był zachowywany po odświeżeniu strony zamówienia na klasycznym checkoucie
* Naprawiono: zgodność z PHP 8.4 i 8.5 — wyeliminowano błędy powodujące komunikaty notice w nowszych wersjach PHP

### 4.9.1 (16.04.2026) ###
* Naprawa: status zamówienia z pola **Płatność rozpoczęta** (Zaawansowane → Statusy płatności) jest teraz ustawiany w momencie rozpoczęcia płatności przez klienta (standardowe przekierowanie, Google Pay oraz BLIK‑0). Wcześniej zamówienie zawsze otrzymywało status „Oczekujące na płatność”, niezależnie od tej konfiguracji.

### 4.9.0 (07.04.2026) ###
* Google Pay na checkoutcie jest zgodny z WooCommerce: metoda jest oferowana, gdy klient musi zaakceptować regulamin za pomocą checkboxa (ustawienia klasycznego checkoutu oraz checkout blokowy z włączoną opcją checkboxa w bloku regulaminu)
* Checkout blokowy: lista metod płatności uwzględnia te same zasady widoczności Google Pay co przy klasycznym checkoucie
* Klasyczny checkout: płynniejsza obsługa przycisku składania zamówienia we współpracy ze skryptami checkoutu Autopay

### 4.8.3 (09 March 2026) ###
* Analytics component - a new optional setting: "ITN SUCCESS triggering the event ‘Completion of transaction’ instead of order status"

### 4.8.2 (17 February 2026) ###
* Improved webhook processing logic for better integration with third-party plugins
* Adding a multilingual readme

### 4.8.1 (2 February 2026) ###
* Added support for additional currencies: USD (US Dollar) and GBP (British Pound).
* Improvements in ITN processing
* Updated library versions (php-ga4-mp, GuzzleHTTP)
* Fixed handling of unsupported currencies
* Fixed in the “Login during checkout” flow
* Minor fixes and improvements

### 4.8.0 (14 January 2026) ###
* Translations of the plugin into Spanish, Italian and German have been added.
* The ability to edit the name and description of payment methods in the administration panel and their presentation on the checkout page has been added.
* Support for saving and reading the display order of payment methods on the checkout page has been added.
* The frontend layer has been adjusted to present payment methods according to the configured order on checkout.
* Paywall v3 – better support for payment option grouping.
* The ‘Test Connection’ function has been expanded with additional verification of the shop configuration on the customer's side.

### 4.7.1 (20 November 2025) ###
* Changes to messages for test connection for the new supported version of PHP 8.3
* Changes to the text of the plugin configuration instructions
* Changes to the currency verification logic on the website
* Fix for test connection verification when sandbox mode is enabled for the administrator
* Fixed an issue with the Autopay plugin working in conjunction with other currency plugins

### 4.7.0 (18 August 2025) ###
* Added: gatewayList/v3 Added - integration with new endpoint
    * Details:
		* Extended configuration parameters
		* Advanced communication with payment gateways
		* Support for more payment options
* Improved: Test Connection - PHP 8.3 integration updated

[You can find all previous changes on Our Github.](https://github.com/bluepayment-plugin/autopay-payments/blob/main/changelog.txt).
