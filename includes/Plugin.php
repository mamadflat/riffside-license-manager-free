<?php
namespace Riffside\LicenseManager;

defined('ABSPATH') || exit;

final class Plugin {
    public const PRODUCT_URL = 'https://riffside.ir/plugins/riffside-license-manager/';

    public static function activate() {
        $error = self::compatibility_error();
        if ($error !== '') wp_die(esc_html($error));

        try {
            self::load_prepaid();
            \YHLM\Plugin::setup();
        } catch (\RuntimeException $error) {
            wp_die(esc_html($error->getMessage()));
        }
        self::endpoint();
        flush_rewrite_rules(false);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('yhlm_cleanup');
    }

    public static function boot() {
        $error = self::compatibility_error();
        if ($error !== '') {
            self::notice($error);
            return;
        }
        self::load_prepaid();
        try {
            \YHLM\Plugin::setup();
            \YHLM\Plugin::boot();
        } catch (\RuntimeException $error) {
            self::notice($error->getMessage());
            add_filter('woocommerce_is_purchasable', static function ($purchasable, $product) {
                return \YHLM\Store::enabled($product) ? false : $purchasable;
            }, 30, 2);
            add_action('admin_menu', [\YHLM\Admin::class, 'menu']);
        }

        add_action('init', [self::class, 'endpoint'], 5);
        add_filter('woocommerce_get_query_vars', [self::class, 'query_vars']);
        add_action('woocommerce_account_licenses_endpoint', [self::class, 'render_prepaid']);
        add_filter('woocommerce_account_menu_items', [\YHLM\Customer::class, 'menu']);
        add_action('init', [self::class, 'refresh_routes'], 30);
    }

    private static function compatibility_error() {
        if (!class_exists('WooCommerce') || !extension_loaded('openssl')) {
            return self::text('ووکامرس و OpenSSL برای مدیریت لایسنس لازم‌اند.', 'License Manager requires WooCommerce and OpenSSL.');
        }
        if ((class_exists(\YHLM\Plugin::class, false) && !defined('RSLM_PREPAID_LOADED')) || function_exists('ahrari_license_products')) {
            return self::text(
                'یک افزونهٔ مدیریت لایسنس سازگار روی این سایت فعال است. برای جایگزینی، ابتدا افزونهٔ قبلی را غیرفعال کن.',
                'A related license manager is already active. Deactivate the previous plugin before replacing it.'
            );
        }
        if (get_option('ahrari_license_schema')) {
            return self::text(
                'این فروشگاه سابقهٔ صدور لایسنس دامنه دارد. برای حفظ دسترسی خریداران به کلید و دانلود، بستهٔ پرو را نصب کن.',
                'This store has an existing domain-license ledger. Reinstall the Pro package to maintain customer access.'
            );
        }
        return '';
    }

    private static function load_prepaid() {
        if (defined('RSLM_PREPAID_LOADED')) return;
        define('RSLM_PREPAID_LOADED', true);
        foreach (['crypto', 'import', 'store', 'orders', 'customer', 'admin', 'plugin'] as $component) {
            require_once RSLM_PATH . '/prepaid/includes/class-' . $component . '.php';
        }
    }

    private static function notice($message) {
        add_action('admin_notices', static function () use ($message) {
            if (current_user_can('manage_woocommerce')) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        });
    }

    public static function endpoint() {
        add_rewrite_endpoint('licenses', EP_ROOT | EP_PAGES);
    }

    public static function query_vars($vars) {
        $vars['licenses'] = 'licenses';
        return $vars;
    }

    public static function refresh_routes() {
        if (add_option('riffside_license_manager_routes_v2', 1, '', false)) {
            flush_rewrite_rules(false);
        }
    }

    public static function render_prepaid() {
        if (class_exists(\Riffside\Account\Appearance::class) && !\Riffside\Account\Appearance::enabled('licenses')) return;
        if (defined('RSLM_PREPAID_READY')) \YHLM\Customer::account();
    }

    public static function action_links($links) {
        return [
            'rslm_manage' => '<a href="' . esc_url(admin_url('admin.php?page=yhlm')) . '">' . esc_html(self::text('مدیریت لایسنس', 'Manage licenses')) . '</a>',
            'rslm_upgrade' => '<a href="' . esc_url(self::PRODUCT_URL) . '">' . esc_html(self::text('ارتقا به پرو', 'Upgrade to Pro')) . '</a>',
        ] + $links;
    }

    private static function text($persian, $english) {
        return strpos(determine_locale(), 'fa') === 0 ? $persian : $english;
    }
}
