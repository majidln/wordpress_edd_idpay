=== IDPay for Easy Digital Downloads (EDD) ===
Contributors: majidlotfinia, jazaali
Tags: idpay, آیدی پی
Stable tag: trunk
Tested up to: 5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

فروش کالای دیجیتال در وردپرس با درگاه پرداخت آیدی پی

== Description ==

با نصب و فعال کردن این افزونه، امکان فروش کالای دیجیتال مثل فایل، موسیقی، عکس، کتاب و ... از طریق [درگاه پرداخت آیدی پی](https://idpay.ir) و افزونه [Easy Digital Downloads](https://wordpress.org/plugins/easy-digital-downloads/) فراهم خواهد شد.

== Installation ==

بعد از ایجاد وب سرویس در سایت [آیدی پی](https://idpay.ir) و دریافت API Key، مراحل زیر را انجام دهید:

1. فعال کردن افزونه
2. رفتن به صفحه Downloads > Settings > Payment Gateways
3. فعال کردن آیدی پی در بخش Payment Gateways
4. وارد کردن API Key در بخش تنظیمات درگاه پرداخت آیدی پی

== Changelog ==

= 1.1, November 20, 2018 =
* ذخیره کردن شماره کارت که توسط گیت وی برگردانده می شود
* [کدینگ استاندارد](https://codex.wordpress.org/WordPress_Coding_Standards)
* رفع باگ
* ری فاکتور کردن نام برخی توابع و هوک ها
* استفاده از use wp_safe_remote_post() به جای curl
* افزودن مستندات برای توابع پی.اچ.پی

= 1.0, September 30, 2018 =
* انتشار اولین نسخه از درگاه پرداخت آیدی پی برای افزونه Easy Digital Downloads
git commit -am ""