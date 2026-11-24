<?php
/**
 * Plugin Name: مدیریت لایسنس ریف‌ساید (رایگان)
 * Description: مدیریت موجودی و تحویل خودکار کدهای آماده پس از پرداخت سفارش ووکامرس.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * Author: Riffside
 * Author URI: https://riffside.ir/
 * Update URI: https://github.com/mamadflat/riffside-license-manager-free/
 * License: GPL-2.0-or-later
 * Text Domain: riffside-license-manager
 */

use Riffside\LicenseManager\Plugin;

defined('ABSPATH') || exit;

define('RSLM_FILE', __FILE__);
define('RSLM_PATH', __DIR__);
define('RSLM_VERSION', '2.0.0');

require_once RSLM_PATH . '/includes/Plugin.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
add_action('plugins_loaded', [Plugin::class, 'boot'], 25);
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', RSLM_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', RSLM_FILE, true);
    }
});
add_filter('plugin_action_links_' . plugin_basename(__FILE__), [Plugin::class, 'action_links']);
