# Riffside License Manager Free

[Download the installable ZIP](https://github.com/mamadflat/riffside-license-manager-free/releases/latest) · [فارسی](#راهنمای-فارسی)

Deliver codes you already have after WooCommerce confirms payment. Import codes for a product or variation, track inventory and deliveries, and let buyers retrieve their codes under **My account → My licenses**.

## Install

1. Download the `riffside-license-manager-free-*.zip` attachment from the latest GitHub release.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin** and activate the ZIP.
3. Enable prepaid code delivery on the product or variation. Open **WooCommerce → License Manager → Bulk import** to add codes.

If you use Riffside Account, also enable the purchased licenses section in its settings.

Requires WordPress 6.5+, WooCommerce 9.0+, PHP 7.4+, and OpenSSL.

## Upgrade to Pro

Pro adds domain-bound license issuance, activation management and plugin updates. Use **Upgrade to Pro** in the plugin or visit the Riffside product page to contact the sales team. After purchase, upload the Pro ZIP, choose **Replace current with uploaded**, and enter your key under **WooCommerce → License Manager → Activate Pro**. Both editions use the same plugin folder and retain your prepaid code inventory and delivery history.

[Pro product page on Riffside](https://riffside.ir/plugins/riffside-license-manager/)

## راهنمای فارسی

نسخهٔ رایگان کدهایی را که از قبل داری، پس از تأیید پرداخت ووکامرس خودکار تحویل می‌دهد. برای هر محصول یا تنوع می‌توانی موجودی جداگانه وارد کنی و گزارش تحویل را ببینی. خریدار به کدهایش از «حساب کاربری ← لایسنس‌های من» دسترسی دارد.

1. فایل `riffside-license-manager-free-*.zip` را از [آخرین انتشار گیت‌هاب](https://github.com/mamadflat/riffside-license-manager-free/releases/latest) بگیر.
2. در وردپرس، از «افزونه‌ها ← افزودن افزونه ← بارگذاری افزونه» فایل را نصب و فعال کن.
3. تحویل کد آماده را در محصول یا تنوع فعال کن. از «ووکامرس ← مدیریت لایسنس ← ورود گروهی» کدها را وارد کن.

اگر از افزونهٔ پنل کاربری ریف‌ساید استفاده می‌کنی، نمایش «لایسنس‌های آمادهٔ خریداری‌شده» را در تنظیمات پنل فعال کن.

وردپرس ۶.۵، ووکامرس ۹.۰ و PHP ۷.۴ یا بالاتر و افزونهٔ PHP OpenSSL لازم‌اند.

برای صدور لایسنس وابسته به دامنه، مدیریت فعال‌سازی و ارائهٔ به‌روزرسانی افزونه، دکمهٔ «ارتقا به پرو» را بزن و از صفحهٔ محصول برای خرید تماس بگیر. بعد از خرید، فایل ZIP پرو را بارگذاری و جایگزین نسخهٔ فعلی کن. سپس کلید را از «ووکامرس ← مدیریت لایسنس ← فعال‌سازی پرو» وارد کن. موجودی کدهای آماده و تاریخچهٔ تحویل هنگام ارتقا حفظ می‌شوند.

[صفحهٔ خرید پرو در ریف‌ساید](https://riffside.ir/plugins/riffside-license-manager/)

## Build and license

Run `php tools/build.php` with PHP Zip enabled to create the installable ZIP and its SHA-256 checksum in `dist/`. Use `php tools/build.php <output-directory>` to choose another destination.

[GPL-2.0-or-later](LICENSE.txt)
