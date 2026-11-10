<?php
/**
 * Plugin Name:       Peakopay — Card-to-Card Payments for WooCommerce
 * Description:       درگاه پرداخت کارت به کارت/انتقال بانکی Peakopay برای ووکامرس. سفارش به صفحه پرداخت Peakopay (سرور خودمیزبان شما) منتقل می‌شود؛ پس از تطبیق تراکنش بانکی، سفارش به‌صورت خودکار تکمیل می‌شود. ۱۰۰٪ رایگان و متن‌باز — بدون سرویس پولی.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Peakopay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       peakopay
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   10.9
 *
 * @package Peakopay
 *
 * SAFETY RULES (why this file can never crash WordPress at load time):
 *  - This file defines constants + hook registrations ONLY. No WooCommerce
 *    class is referenced, no HTTP call is made, no table is queried while
 *    the plugin file is being loaded.
 *  - All application classes are required inside peakopay_boot(), which
 *    runs on "plugins_loaded" — at that point every active plugin (including
 *    WooCommerce) has been loaded, so class_exists() checks are reliable.
 *  - If WooCommerce is missing, the plugin shows an admin notice and stays
 *    completely inert.
 *
 * Peakopay is 100% free and open source (GPL-2.0-or-later). It works with a
 * self-hosted Peakopay server (also free, same project) — no paid service,
 * no subscription, no locked features.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('PEAKOPAY_VERSION', '1.2.0');
define('PEAKOPAY_PLUGIN_FILE', __FILE__);
define('PEAKOPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PEAKOPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PEAKOPAY_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * HPOS (High-Performance Order Storage) + Checkout Blocks compatibility
 * declarations. Fires only when WooCommerce itself boots, so FeaturesUtil
 * is guaranteed to exist.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', PEAKOPAY_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', PEAKOPAY_PLUGIN_FILE, true);
    }
});

/**
 * Translations (Persian ships as the source language; everything is
 * translatable via the "peakopay" text domain).
 */
function peakopay_load_textdomain(): void
{
    load_plugin_textdomain('peakopay', false, dirname(PEAKOPAY_PLUGIN_BASENAME) . '/languages');
}
add_action('init', 'peakopay_load_textdomain', 1);

/**
 * Boot the plugin after ALL plugins are loaded (WooCommerce included).
 * Safe to call multiple times (static guard); also callable directly in tests.
 */
function peakopay_boot(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    // ── WooCommerce missing/inactive → notice + stay inert (NO fatal) ──
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>Peakopay:</strong> '
                . esc_html__('افزونه ووکامرس فعال نیست. افزونه Peakopay فقط همراه ووکامرس کار می‌کند و تا پیش از فعال‌سازی آن، غیرفعال می‌ماند (هیچ خطایی ایجاد نمی‌کند).', 'peakopay')
                . '</p></div>';
        });
        return;
    }

    // ── WooCommerce is active → load application classes NOW ──
    require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-signature.php';
    require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-api.php';
    require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-gateway.php';
    require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-webhook.php';
    require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-privacy.php';

    // Register the gateway (WooCommerce instantiates it lazily).
    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'Peakopay_Gateway';
        return $gateways;
    });

    // Webhook endpoint: {site}/wc-api/peakopay
    add_action('woocommerce_api_peakopay', array('Peakopay_Webhook', 'handle'));

    // "Test connection" button in the settings page (admin only, nonce-checked).
    add_action('wp_ajax_peakopay_test_connection', 'peakopay_ajax_test_connection');

    // Checkout Blocks integration (classic checkout keeps working as before).
    add_action('woocommerce_blocks_loaded', static function (): void {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        require_once PEAKOPAY_PLUGIN_DIR . 'includes/class-peakopay-blocks.php';
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry): void {
            $registry->register(new Peakopay_Blocks_Support());
        });
    });

    // Privacy: policy suggestion + personal-data exporter (WP personal data tools).
    Peakopay_Privacy::register();
}
add_action('plugins_loaded', 'peakopay_boot', 20);

/**
 * AJAX: test the connection to the Peakopay server from the settings page.
 * Reads UNSAVED form values first, falls back to saved settings.
 */
function peakopay_ajax_test_connection(): void
{
    check_ajax_referer('peakopay_test_connection', 'security');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('دسترسی لازم را ندارید.', 'peakopay')));
    }

    $baseUrl = isset($_POST['base_url']) ? untrailingslashit(trim(sanitize_text_field(wp_unslash($_POST['base_url'])))) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked above
    $storeId = isset($_POST['store_id']) ? trim(sanitize_text_field(wp_unslash($_POST['store_id']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    $apiKey  = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification

    $settings = get_option('woocommerce_peakopay_settings', array());
    if ($baseUrl === '') {
        $baseUrl = untrailingslashit(trim((string) ($settings['base_url'] ?? '')));
    }
    if ($storeId === '') {
        $storeId = trim((string) ($settings['store_id'] ?? ''));
    }
    if ($apiKey === '') {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
    }

    if ($baseUrl === '' || $storeId === '' || $apiKey === '') {
        wp_send_json_error(array('message' => __('ابتدا آدرس سرور، شناسه فروشگاه و کلید API را وارد کنید.', 'peakopay')));
    }

    $res = Peakopay_API::test_connection($baseUrl, $storeId, $apiKey);
    if ($res['ok']) {
        $data = (array) ($res['data'] ?? array());
        // چک‌لیست کامل وضعیت — همه اتصال‌ها در یک نگاه
        $lines   = array();
        $lines[] = sprintf(
            /* translators: 1: store name, 2: server version */
            __('✅ اتصال برقرار است — فروشگاه «%1$s»، نسخه سرور %2$s.', 'peakopay'),
            (string) ($data['store'] ?? __('نامشخص', 'peakopay')),
            (string) ($data['version'] ?? '؟')
        );
        $unit    = ($data['currency'] ?? '') === 'IRT' ? __('تومان', 'peakopay') : __('ریال', 'peakopay');
        // translators: %1$s = unit label, %2$s = currency code
        $lines[] = sprintf(__('واحد پول فروشگاه: %1$s (%2$s)', 'peakopay'), $unit, (string) ($data['currency'] ?? '—'));
        if (isset($data['cards'])) {
            // translators: %1$s = active card count, %2$s = registered SMS sender count
            $lines[] = sprintf(__('کارت‌های فعال: %1$s — فرستنده‌های پیامک ثبت‌شده: %2$s', 'peakopay'), (string) $data['cards'], (string) $data['senders']);
        }
        if (isset($data['devices'])) {
            // translators: %s = active Android device count
            $lines[] = sprintf(__('دستگاه‌های اندروید فعال: %s', 'peakopay'), (string) $data['devices']);
        }
        if (!empty($data['test_mode'])) {
            $lines[] = __('⚠ سرور در حالت تست است — پرداخت‌های واقعی ثبت نمی‌شوند.', 'peakopay');
        }
        wp_send_json_success(array('message' => implode("\n", $lines)));
    }
    $errCode = (string) ($res['error']['code'] ?? '');
    $hint    = '';
    if ($errCode === 'unauthorized' || $errCode === 'http_error') {
        $hint = "\n" . __('(شناسه فروشگاه/کلید API و آدرس سرور را دقیق بررسی کنید — بدون / انتها.)', 'peakopay');
    }
    wp_send_json_error(array('message' => '❌ ' . ((string) ($res['error']['message'] ?? __('اتصال برقرار نشد.', 'peakopay'))) . $hint));
}

/** Settings link on the Plugins page. */
add_filter('plugin_action_links_' . PEAKOPAY_PLUGIN_BASENAME, static function (array $links): array {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=peakopay');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('تنظیمات', 'peakopay') . '</a>');
    return $links;
});
