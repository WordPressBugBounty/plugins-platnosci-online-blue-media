=== Autopay ===
Contributors: inspirelabs
Tags: woocommerce, bluemedia, autopay
Requires at least: 6.0
Tested up to: 6.8.2
Stable tag: 4.6.4
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Autopay to moduł płatności umożliwiający realizację transakcji bezgotówkowych w sklepie opartym na platformie WordPress (WooCommerce).

== Opis ==

Autopay to moduł płatności umożliwiający realizację transakcji bezgotówkowych w sklepie opartym na platformie WordPress (WooCommerce). Jeżeli jeszcze nie masz wtyczki, możesz ją pobrać [tutaj](https://github.com/bluepayment-plugin/autopay-payments/releases).

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


Wymagania

- WordPress – przetestowane na wersjach od 6.0 do 6.8.2
- Wtyczka WooCommerce – przetestowano na wersjach od 8.1 do 10.0.4

== Installation	 ==

Zainstaluj wtyczkę w panelu administracyjnym Wordpress:

1. Pobierz wtyczkę
2. Przejdź do zakładki Wtyczki > Dodaj nową a następnie wskaż pobrany plik instalacyjny.
3. Po zainstalowaniu wtyczki włącz moduł.
1. Przejdź do zakładki WooCommerce ➝ Ustawienia ➝ Płatności.
2. Wybierz Autopay, żeby przejść do konfiguracji.

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


== Screenshots ==

1. Widok pól do uzupełnienia
2. Dostępne metody płatności


== Changelog ==

## [4.6.4] - 2025-07-28

### Added
- **Improved ITN status processing**
- **Full HPOS support**

### Fixed
- Test Connection module is now compatible with the ”Sandbox Mode for logged-in administrator” option
- In certain scenarios, REST API requests cause a fatal error in the multi-currency module

## [4.6.3] - 2025-05-21

### Fixed **Test Connection** - Improved compatibility with production environment.


## [4.6.2] - 2025-05-12

### Fixed **Test Connection** - Transaction test implementation on production mode.

## [4.6.1] - 2025-04-29

### Added
- **Product feed** Added support for Autopay Product ADS – enabled product feed generation and implementation of advertising tags required for the service.


## [4.6.0] - 2025-04-22

### Added
- **Test Connection – Module Diagnostic Tool** - A tool for verifying configuration and connectivity, allowing users to independently check if the module is working correctly and quickly verify the store’s setup. It helps ensure that all components required for processing payments are functioning as expected.
	Features:
		Checks the server environment, API connection, and payment configuration
		Includes tests for PHP, database, HTTPS, internet access, and WooCommerce/module versions
		Verifies availability of payment channels and transaction processing (e.g. BLIK, ITN notifications)
		Provides recommended guidance based on test results
		Option to download test logs

### Fixed
- **Classic checkout** - Autopay payment method description now includes the names of only those payment channels that are available in the store.
- **Multicurrency** - A critical error in the multicurrency module in some configurations.


## [4.5.1] - 2025-03-19

### Fixed
- **Checkout** - Validation and UI fixes for Blik field
- **Multicurrency** - Problems with currency detection in some scenarios
- **Debugger** - Debug mode optimizations

## [4.5.0] - 2025-02-26

### Added
- **Multi-currency Support** - We have added multi-currency support in a single Autopay plugin, allowing merchants to more easily manage transactions in different currencies.
	Benefits:
		Simplicity: Manage all payments in one plugin panel.
		Wider reach: Support for international transactions.
		Better customer experience: Ability to pay in local currency.


## [4.4.0] - 2024-11-06

### Changes
- **Simplified configuration for the merchant** - The number of configuration steps has been significantly reduced, making it quicker and easier to start using the plugin. A clear interface and a reduction in the amount of data required minimises the time required for configuration.

### Added
- **Support for BLIK-0 payments on the block checkout** - We have introduced support for BLIK-0 payments directly on the block checkout. Users can now use the fast and convenient BLIK-0 option without any additional work, increasing conversions and making it easier to finalise purchases.
- **Adaptation to the FunnelKit Funnel Builder plugin** - The plugin is now compatible with FunnelKit Funnel Builder, allowing for easy integration and the creation of advanced purchase funnel paths.

## [4.3.3] - 2024-08-01

### Added
- **Settings** - Added a new section: Services for You, aimed at supporting merchants in running an efficient store.

### Changes
- **Settings** - Redesigned the informational banner.

### Fixed
- **My Account Page** - Fixed the issue with redirecting to payment for the Blik-0 payment method on the My Account page.


## [4.3.2] - 2024-07-04

### Fixed
- **Checkout** - Fix purchasing process issues that can occur in specific configurations of the Merchant environments.

## [4.3.1] - 2024-06-24

### Added
- **Custom CSS Editor** - Now you can add your individual look to our Paywall, tailoring it to your customers' needs.
- **Option to Override Order Received URL** - Customize the URL customers are redirected to after placing an order.
- **GA4: Option to Change Conversion Order Status** - You decide when the conversion counts!

### Fixed
- **BLIK-0 Payment Status** - Payment status now updates properly.
- **WC Session Initialization** - Fixed initialization issues in some scenarios.
- **Redirect to Payment** - Resolved issues with redirecting to payment in various situations.
- **Custom Gateway URL Redirect** - Fixed issues with redirecting when using a custom gateway URL.
- **Custom Transaction Start Endpoint** - Now works correctly in different scenarios.
- **Translations** - Improved and updated GA4 field descriptions.

### Improved
- **Documentation** - Now more comprehensive and user-friendly!

We are excited to bring you these latest updates and improvements. Your feedback is invaluable and helps us continually enhance our products. Thank you for being with us!

## [4.3.0] - 2024-04-26
### Added
- Block payment (white label)
### Fixed
- Problems with redirection to payment in some scenarios

## [4.2.9] - 2024-04-02
### Fixed
- Styles
- Displaying description on non-whitelabel mode
- Order notes
- Minor fixes

### Added
- Option: alternative transaction start URL
- Improvement debugger

## [4.2.8] - 2024-02-13
### Updated
- Payment methods integration

### Fixed
- Blik-0 issues for some scenarios
- Apple Pay method visibility problem
- Styles
- Payment process on "My account" page
- Email payment link support

### Added
- Ability to migrate settings from 2.x and 3.x plugins

## [4.2.7] - 2024-01-18
### Fixed
- CSS fixes

### Added
- Block Editor support (express payment)##

## [4.2.6] - 2023-12-11
### Added
- Option: Compatibility mode with third-party plugins that reload checkout fragments

### Fixed
- CSS minor fixes

## [4.2.5] - 2023-11-28
### Added
- Show countdown screen before redirection to increase compatibility
- Minor changes in Admin Panel

### Fixed
- CSS compatibility issues

## [4.2.4] - 2023-11-05
### Added
- Debug mode improved

### Fixed
- Minor CSS fixes
- Redirect to payment loop issue for some scenarios

## [4.2.3] - 2023-10-19
### Added
- Ability to assign a separate status for virtual products
- Debug and testing new features

### Fixed
- Settings texts updates
- An order cannot be paid if there is only one payment method available to the partner
- Styles

## [4.2.2] - 2023-10-02
### Fixed
- Translations

## [4.2.1] - 2023-09-29
### Fixed
- Translations
- Blik: problem with code starting with "0"
- Improved payment method selection UI
- Fatal error during a page update in a specific scenario

## [4.2.0] - 2023-08-31
### Added
- Rebranding

## [4.1.26] - 2023-08-24
### Added
- Blik-0 support

### Fixed
- Minor fixes
- Styles

## [4.1.25] - 2023-08-03
### Fixed
- Blik redirect fix

## [4.1.24] - 2023-07-28
### Added
- New bank list styles
- New module: Preview payment methods in Admin Panel

## [4.1.23] - 2023-06-22
### Added
- Improved checkout UI

### Fixed
- Styles
- Minor fixes

## [4.1.21] - 2023-05-08
### Fixed
- Show log only on demand


[You can find all previous changes on Our Github.](https://github.com/bluepayment-plugin/autopay-payments/blob/main/changelog.txt).
