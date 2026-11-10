<?php
/**
 * Peakopay — privacy (WP personal data tools).
 *
 * What the plugin stores and why (also shown as a privacy-policy suggestion
 * in Settings → Privacy):
 *   - Order meta (_peakopay_*: payment token, amount in Rial, verification
 *     flag, bank name, tracking reference, timestamps) — the audit trail for
 *     each payment. Kept as long as the order exists; removed with the order.
 *   - Nothing else. No tracking, no analytics, no third-party calls except
 *     the merchant's OWN self-hosted Peakopay server (base_url setting).
 *
 * For a payment the plugin sends to that server: order id/key, amount,
 * currency, customer name and billing phone — the minimum needed to render
 * the payment page and match the bank transfer.
 *
 * Exporter: includes the plugin's order meta in the customer data export.
 * Eraser: nothing of the customer's personal data is stored by the plugin
 * beyond order meta (WooCommerce erases orders itself); payment audit meta
 * is retained for accounting — documented, not silently destroyed.
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_Privacy')) {
    return; // never declare twice
}

final class Peakopay_Privacy
{
    /** متاهای شخصی/پرداختی افزونه روی سفارش (برای export). */
    const META_KEYS = array(
        '_peakopay_token'        => 'شناسه نشست پرداخت',
        '_peakopay_amount_rial'  => 'مبلغ نشست (ریال)',
        '_peakopay_paid'         => 'تأیید پرداخت',
        '_peakopay_reference'    => 'کد رهگیری تراکنش',
        '_peakopay_bank'         => 'بانک',
        '_peakopay_created_at'   => 'زمان ساخت نشست',
    );

    /** ثبت hookها (یک‌بار، در peakopay_boot). */
    public static function register()
    {
        add_action('admin_init', array(__CLASS__, 'add_privacy_policy_content'));
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
    }

    /** پیشنهاد متن سیاست حریم خصوصی (Settings ← Privacy ← Policy Guide). */
    public static function add_privacy_policy_content()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('Peakopay', self::policy_text());
    }

    /** @return string */
    private static function policy_text()
    {
        return '<h2>Peakopay (پرداخت کارت به کارت)</h2><p>'
            . __('برای پرداخت کارت به کارت، افزونه Peakopay اطلاعات حداقلی سفارش (شماره سفارش، مبلغ، نام و شماره تلفن صورتحساب) را به سرور خودمیزبان Peakopay همین فروشگاه ارسال می‌کند تا صفحه پرداخت و تطبیق واریز ساخته شود. این سرور متعلق به خود فروشنده است و داده‌ای به هیچ سرویس ثالث دیگری فرستاده نمی‌شود.', 'peakopay')
            . '</p><p>'
            . __('افزونه روی سفارش، سوابق پرداخت (شناسه نشست، مبلغ، بانک، کد رهگیری و وضعیت تأیید) را ذخیره می‌کند؛ این سوابق تا زمانی که سفارش موجود است نگه داشته می‌شوند و بخشی از گزارش مالی فروشگاه هستند.', 'peakopay')
            . '</p>';
    }

    /** افزودن exporter متای Peakopay به ابزار «خروج داده شخصی» وردپرس. */
    public static function register_exporter(array $exporters)
    {
        $exporters['peakopay-order-meta'] = array(
            'exporter_friendly_name' => __('Peakopay — سوابق پرداخت سفارش', 'peakopay'),
            'callback'               => array(__CLASS__, 'export'),
        );
        return $exporters;
    }

    /**
     * Export متای پرداخت Peakopay برای همه سفارش‌های یک ایمیل مشتری.
     *
     * @param string $email ایمیل مشتری
     * @param int    $page   صفحه batch (برای LIMIT)
     * @return array{data: array, done: bool}
     */
    public static function export($email, $page = 1)
    {
        $data = array();
        $done = true;

        if (!function_exists('wc_get_orders')) {
            return array('data' => $data, 'done' => $done);
        }

        $pageSize = 50;
        $orders = wc_get_orders(array(
            'billing_email' => $email,
            'limit'   => $pageSize,
            'page'    => max(1, (int) $page),
            'orderby' => 'ID',
            'order'   => 'ASC',
        ));

        foreach ($orders as $order) {
            if (!($order instanceof WC_Order)) {
                continue;
            }
            $group = array(
                'group_id'    => 'peakopay-order-' . $order->get_id(),
                'group_label' => __('Peakopay — پرداخت سفارش', 'peakopay'),
                'item_id'     => 'order-' . $order->get_id(),
                'data'        => array(),
            );
            foreach (self::META_KEYS as $key => $label) {
                $value = $order->get_meta($key);
                if ((string) $value !== '') {
                    $group['data'][] = array('name' => $label, 'value' => (string) $value);
                }
            }
            if (!empty($group['data'])) {
                $data[] = $group;
            }
        }

        $done = count($orders) < $pageSize;
        return array('data' => $data, 'done' => $done);
    }
}
