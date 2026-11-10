<?php
/**
 * Peakopay — Checkout Blocks integration.
 *
 * Registers the gateway as a payment method for the WooCommerce Checkout
 * Block (the classic shortcode checkout keeps working unchanged through
 * Peakopay_Gateway). The client-side component is a small dependency-free
 * script (assets/js/peakopay-blocks.js) registered with the official
 * `wc-blocks-registry` API — no build step, no bundler.
 *
 * PHP 7.4 compatible.
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_Blocks_Support')) {
    return; // never declare twice
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Server-side registration of the "peakopay" payment method type.
 *
 * Security note: no payment data is handled in JavaScript. When the customer
 * places the order, the Store API calls Peakopay_Gateway::process_payment()
 * on the server exactly like the classic checkout — same verification rules.
 */
final class Peakopay_Blocks_Support extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = 'peakopay';

    /** @var array */
    protected $settings = array();

    public function initialize()
    {
        $this->settings = get_option('woocommerce_peakopay_settings', array());
    }

    public function is_active()
    {
        return filter_var($this->get_setting('enabled', 'no'), FILTER_VALIDATE_BOOLEAN);
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'peakopay-blocks';
        $src    = PEAKOPAY_PLUGIN_URL . 'assets/js/peakopay-blocks.js';

        $deps = array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities');
        wp_register_script($handle, $src, $deps, PEAKOPAY_VERSION, true);

        return array($handle);
    }

    public function get_payment_method_data()
    {
        return array(
            'title'       => (string) $this->get_setting('title', __('کارت به کارت (Peakopay)', 'peakopay')),
            'description' => (string) $this->get_setting('description', __('پس از ثبت سفارش، شماره کارت مقصد و مبلغ دقیق به شما نمایش داده می‌شود. پس از انتقال، تأیید به‌صورت خودکار انجام می‌شود.', 'peakopay')),
            'supports'    => array('products'),
        );
    }
}
