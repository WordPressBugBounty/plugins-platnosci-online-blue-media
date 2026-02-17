=== Autopay ===
Contributors: inspirelabs
Tags: woocommerce, bluemedia, autopay
Requires at least: 6.0
Tested up to: 6.9.1
Stable tag: 4.8.2
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Autopay is a payment module that enables cashless transactions in a shop based on the WordPress platform (WooCommerce).

== Description ==

Autopay is a payment module that enables cashless transactions in a shop based on the WordPress platform (WooCommerce). If you do not already have the plugin, you can download it [here](https://github.com/bluepayment-plugin/autopay-payments/releases).

**The Autopay payment plugin offers a range of functionalities to support sales on your shop:**

- The most popular payment methods in Poland and Europe
  - Online transfers ([Pay By Link](https://autopay.pl/baza-wiedzy/blog/ecommerce/platnosc-pay-by-link-na-czym-polega-i-co-mozesz-dzieki-niej-zyskac))
  - Fast bank transfers
  - [BLIK](https://autopay.pl/rozwiazania/blik)
  - Visa Mobile
  - [Google Pay](https://autopay.pl/rozwiazania/google-pay)
  - [Apple Pay](https://autopay.pl/rozwiazania/apple-pay)
  - Instalment payments
  - Recurring payments
  - International payments
- The most popular sales methods for the WooCommerce platform
- buy as a guest / buy as a registered user
- step checkout or block checkout
- payment processing with redirection to an external payment page or remaining directly on the shop (selected methods: cards, BLIK)
- test environment support (implementation of test transactions for correct installation and configuration of the plug-in)
- deferred and instalment payments
- native integration into Google Analytics 4 from within the Autopay payment plug-in
- automatic verification of the correct configuration of authorization data in the plug-in
- multilingual support – automatic adaptation to the store language (EN,
  DE, IT, ES); for other languages, the interface is displayed in English
- ability to manually change the order of Autopay payment methods in the
  WooCommerce panel using drag & drop

[Register your shop!](https://autopay.pl/oferta/platnosci-online?utm_campaign=woocommerce&utm_source=woocommerce_description&utm_medium=offer_cta#kalkulator)


**Requirements for installing the plug-in**

- WordPress - tested on versions `6.0` to `6.9.1`
- WooCommerce plugin - tested on versions `7.9.0` to `10.5.1`
- PHP version min. `7.4`

== Installation	 ==

Install the plugin via the WordPress admin panel:

1. Download the plugin.
2. Go to the Plugins > Add New tab and then select the downloaded installation file.
3. After installing the plugin, activate the module.
4. Go to the WooCommerce ➝ Settings ➝ Payments tab.
5. Select Autopay to proceed to the configuration.

## Configure the plugin
Log in to the dashboard and go to the **Payments** tab and find the **Autopay** method. Select **Configure** to start configuring the plugin. Or select the appropriate option on the toggle to **enable** / **disable** the operation of the plugin on your shop.

If you encountered a problem while installing the plugin, visit our [FAQ section.](https://developers.autopay.pl/online/wtyczki/woocommerce#najcz%C4%99%C5%9Bciej-zadawane-pytania)

### Authentication

The ‘Authentication’ tab will allow you to enter your Autopay account credentials into the plugin, as well as determine whether you want Autopay payments to work on a test or production environment.
1. **Test environment**.
	- set to **yes** - This is used to test the integration and configuration of the Autopay plugin on your shop. In the test environment, the payer will not be charged for any purchases and you will not receive payment for any sales. Transactions will only be virtual. Remember never to send transactions for transactions paid in test mode!
	- set to **no** - The plugin runs on a production environment. In other words, transactions and payments are really happening. The payer is financially charged for the purchase and the merchant receives funds from Autopay for the sales made.
2. **Service ID** - This is the ID of your Autopay account. You will find it when you log into your account, select ‘Service Settings’ from the menu and then for the ‘Service Technical Configuration’ section click on the ‘Select’ button. The service ID is the value of the ‘Service ID’.
3. **Configuration key (hash)** - This is the value dedicated to your site in your Autopay account. You will find it when you log into your account, select ‘**Site Settings**’ from the menu and then for the ‘Site Technical Configuration’ section click on the ‘Select’ button. It is signed as Configuration key (hash)
> Test environment a Service identifier and Configuration key (hash)
The values of the Service ID and Configuration Key are different for the test and production environment. If you have set up a new Autopay account and do not yet have access to the test environment you can obtain it [by sending an access request](https://developers.autopay.pl/kontakt?utm_campaign=help&utm_source=woocommerce_documentation&utm_medium=text_link).
>
> Select the verification category, fill in your details and in the body of the message provide the id of your current service and request the creation of a test environment for your shop.

== Screenshots ==

1. View of the fields to be completed
2. Payment methods available


== Changelog ==

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
