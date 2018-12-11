=== IDPay for Easy Digital Downloads (EDD) ===
Contributors: majidlotfinia, jazaali, imikiani
Tags: idpay, digital downloads, download, ecommerce, e-commerce, download, payment, gateway, edd
Stable tag: 1.2.1
Tested up to: 5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

IDPay payment gateway for Easy Digital Downloads

== Description ==

After installing and enabling this plugin, you have the ability of selling files, music, picture, book via [Easy Digital Downloads](https://wordpress.org/plugins/easy-digital-downloads) and [IDPay](https://idpay.ir) Payment gateway.

You can obtain an API Key by going to your [dashboard](https://idpay.ir/dashboard/web-services) in your IDPay [account](https://idpay.ir/user).

== Installation ==

After creating a web service on https://idpay.ir and getting an API Key, follow this instruction:

1. Activate plugin IDPay for Easy Digital Downloads.
2. Go to Downloads > Settings > Payment Gateways.
3. Check "IDPay" option in the Payment Gateways section.
4. Enter your API Key in "IDPay payment gateway" section.

After that, if a customer is going to purchase a downloadable product which is created by Easy Digital Downloads, The IDPay payment gateway will appear and she can pay with it.

== Changelog ==

= 1.2.1, December 11, 2018 =
* Load text domain.
* Check if 'ABSPATH' is defined.

= 1.2, December 11, 2018 =
* Plugin translation

= 1.1, November 20, 2018 =
* Save card number returned by the gateway
* [Coding Standards](https://codex.wordpress.org/WordPress_Coding_Standards)
* Bux fix.
* Refactor some function and hook names.
* Use wp_safe_remote_post() instead of curl.
* PHP documentations.

= 1.0, September 30, 2018 =
* First Release
