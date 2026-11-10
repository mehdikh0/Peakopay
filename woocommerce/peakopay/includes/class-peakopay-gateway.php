<?php
/**
 * Peakopay — درگاه پرداخت ووکامرس (WC_Payment_Gateway).
 *
 * جریان پرداخت:
 *   ۱) process_payment → ساخت نشست پرداخت در سرور Peakopay (با Idempotency-Key)
 *   ۲) انتقال مشتری به صفحه پرداخت Peakopay (کارت مقصد + مبلغ دقیق + شمارش معکوس)
 *   ۳) تطبیق تراکنش بانکی در سرور → وب‌هوک امضادار payment.paid → تکمیل خودکار سفارش
 *   ۴) fallback: هنگام بازگشت مشتری به صفحه تشکر، وضعیت یک‌بار استعلام می‌شود
 *
 * این فایل فقط زمانی require می‌شود که ووکامرس فعال باشد (peakopay_boot)
 * و خودش هم در برابر بارگذاری خارج از ووکامرس محافظت دارد.
 *
 * PHP 7.4 compatible — بدون never / match / union types / readonly.
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (class_exists('Peakopay_Gateway')) {
    return; // never declare twice
}
if (!class_exists('WC_Payment_Gateway')) {
    return; // WooCommerce is not running — never declare a broken subclass
}

class Peakopay_Gateway extends WC_Payment_Gateway
{
    /** @var string */
    public $id = 'peakopay';

    /** @var bool */
    public $has_fields = false;

    public function __construct()
    {
        $this->icon               = '';
        $this->method_title       = __('Peakopay (کارت به کارت)', 'peakopay');
        $this->method_description = __('پرداخت کارت به کارت با تطبیق خودکار تراکنش از طریق اپلیکیشن Peakopay. سفارش فقط با تأیید سرور Peakopay تکمیل می‌شود؛ دکمه «پرداخت کردم» هرگز مبنای تأیید نیست.', 'peakopay');
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = (string) $this->get_option('title', __('کارت به کارت (Peakopay)', 'peakopay'));
        $this->description = (string) $this->get_option('description', __('پس از ثبت سفارش، شماره کارت مقصد و مبلغ دقیق به شما نمایش داده می‌شود. پس از انتقال، تأیید به‌صورت خودکار انجام می‌شود.', 'peakopay'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_poll_status'), 10, 1);
    }

    // ------------------------------------------------------------ تنظیمات

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __('فعال‌سازی', 'peakopay'),
                'type'    => 'checkbox',
                'label'   => __('درگاه Peakopay فعال باشد', 'peakopay'),
                'default' => 'no',
            ),
            'title'       => array(
                'title'       => __('عنوان درگاه', 'peakopay'),
                'type'        => 'text',
                'description' => __('عنوانی که مشتری در صفحه پرداخت (checkout) می‌بیند.', 'peakopay'),
                'default'     => __('کارت به کارت (Peakopay)', 'peakopay'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('توضیحات درگاه', 'peakopay'),
                'type'        => 'textarea',
                'description' => __('توضیح کوتاه برای مشتری در صفحه پرداخت.', 'peakopay'),
                'default'     => __('پس از ثبت سفارش، شماره کارت مقصد و مبلغ دقیق به شما نمایش داده می‌شود. پس از انتقال وجه، سفارش به‌صورت خودکار تأیید می‌شود.', 'peakopay'),
                'desc_tip'    => true,
            ),
            'api'         => array(
                'title'       => __('اتصال به سرور Peakopay', 'peakopay'),
                'type'        => 'title',
                'description' => __('این مقادیر را از «مرحله ۶ نصب» پنل Peakopay (یا تنظیمات ← اتصال ووکامرس) کپی کنید. آدرس وب‌هوک که در پنل وارد می‌کنید:', 'peakopay') . ' <code>' . esc_html(home_url('/wc-api/peakopay/')) . '</code>',
            ),
            'base_url'    => array(
                'title'       => __('آدرس سرور Peakopay', 'peakopay'),
                'type'        => 'text',
                'description' => __('مثال:', 'peakopay') . ' https://pay.example.com — ' . __('بدون / انتها.', 'peakopay'),
                'placeholder' => 'https://pay.example.com',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'store_id'    => array(
                'title'       => __('شناسه فروشگاه (st_…)', 'peakopay'),
                'type'        => 'text',
                'default'     => '',
                'description' => __('شناسه عمومی فروشگاه در Peakopay.', 'peakopay'),
                'desc_tip'    => true,
            ),
            'api_key'     => array(
                'title'       => __('کلید API فروشگاه (hpk_…)', 'peakopay'),
                'type'        => 'password',
                'default'     => '',
                'description' => __('کلید فقط یک‌بار هنگام ساخت نمایش داده می‌شود؛ اگر آن را ندارید، از پنل Peakopay کلید جدید بسازید.', 'peakopay'),
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __('کلید امضای وب‌هوک (whsec_…)', 'peakopay'),
                'type'        => 'password',
                'default'     => '',
                'description' => __('برای اعتبارسنجی پیام‌های ارسالی سرور Peakopay به «آدرس فروشگاه/wc-api/peakopay».', 'peakopay'),
                'desc_tip'    => true,
            ),
            'options'     => array(
                'title' => __('تنظیمات پرداخت', 'peakopay'),
                'type'  => 'title',
            ),
            'amount_unit' => array(
                'title'       => __('واحد مبالغ فروشگاه', 'peakopay'),
                'type'        => 'select',
                'options'     => array(
                    'IRT' => __('تومان (IRT)', 'peakopay'),
                    'IRR' => __('ریال (IRR)', 'peakopay'),
                ),
                'default'     => 'IRT',
                'description' => __('واحدی که قیمت‌های ووکامرس شما در آن وارد شده‌اند. تبدیل به ریال شفاف است و مبلغ نمایش‌داده‌شده به مشتری دقیقاً برابر مبلغ تراکنش خواهد بود.', 'peakopay'),
                'desc_tip'    => true,
            ),
            'timeout'     => array(
                'title'       => __('مهلت پاسخ سرور (ثانیه)', 'peakopay'),
                'type'        => 'number',
                'default'     => '20',
                'description' => __('مهلت درخواست‌های ساخت پرداخت/استعلام به سرور Peakopay (۵ تا ۶۰).', 'peakopay'),
                'custom_attributes' => array('min' => '5', 'max' => '60', 'step' => '1'),
                'desc_tip'    => true,
            ),
            'debug'       => array(
                'title'   => __('ثبت لاگ دیباگ', 'peakopay'),
                'type'    => 'checkbox',
                'label'   => __('رویدادهای درگاه (ساخت پرداخت، وب‌هوک‌ها، خطاها) در لاگ ووکامرس ثبت شود', 'peakopay'),
                'default' => 'no',
                'description' => __('لاگ‌ها در WooCommerce ← Status ← Logs (فایل peakopay-…) قابل مشاهده‌اند و هیچ کلید محرمانه‌ای در آن‌ها ثبت نمی‌شود.', 'peakopay'),
                'desc_tip'    => true,
            ),
            'cancel_on_expire' => array(
                'title'   => __('انصراف سفارش منقضی', 'peakopay'),
                'type'    => 'checkbox',
                'label'   => __('اگر مهلت پرداخت تمام شود و وجهی نرسد، سفارش به «لغوشده» تغییر کند', 'peakopay'),
                'default' => 'yes',
            ),
            'test_mode_note' => array(
                'title'       => __('حالت تست', 'peakopay'),
                'type'        => 'title',
                'description' => __('اگر در پنل Peakopay «حالت تست» روشن باشد، تراکنش‌های تستی با برچسب «تست» ساخته می‌شوند و هرگز سفارش واقعی را تکمیل نمی‌کنند. برای آزمایش، از پنل Peakopay (ابزارهای تست) استفاده کنید.', 'peakopay'),
            ),
        );
    }

    /**
     * ذخیره تنظیمات با پاک‌سازی ورودی‌ها (Settings API ووکامرس + sanitize).
     */
    public function process_admin_options()
    {
        // sanitize قبل از ذخیره توسط ووکامرس
        foreach (array_keys($this->form_fields) as $key) {
            $postKey = 'woocommerce_' . $this->id . '_' . $key;
            if (!isset($_POST[$postKey])) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce verified by WooCommerce upstream
                continue;
            }
            $raw = wp_unslash($_POST[$postKey]); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
            if (is_array($raw)) {
                $raw = array_map('sanitize_text_field', $raw);
            } else {
                $raw = sanitize_text_field($raw);
            }
            if ($key === 'base_url') {
                $raw = untrailingslashit(esc_url_raw(trim((string) $raw)));
            } elseif ($key === 'store_id') {
                $raw = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $raw);
            } elseif ($key === 'api_key' || $key === 'webhook_secret') {
                $raw = preg_replace('/\s+/', '', (string) $raw);
            } elseif ($key === 'timeout') {
                $raw = (string) max(5, min(60, (int) $raw));
            }
            $_POST[$postKey] = $raw;
        }
        parent::process_admin_options();
    }

    /** نمایش فیلد «تست اتصال» در صفحه تنظیمات (خروجی کامل escape می‌شود). */
    public function admin_options()
    {
        parent::admin_options();
        $ajaxUrl  = esc_url(admin_url('admin-ajax.php'));
        $nonce    = wp_json_encode(wp_create_nonce('peakopay_test_connection'));
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('تست اتصال', 'peakopay'); ?></th>
                <td>
                    <button type="button" class="button button-secondary" id="peakopay-test-connection"><?php echo esc_html__('تست اتصال به Peakopay', 'peakopay'); ?></button>
                    <span id="peakopay-test-result" style="margin-inline-start:8px"></span>
                    <p class="description"><?php echo esc_html__('با کلیک روی دکمه، اتصال به سرور و اعتبار شناسه فروشگاه و کلید API (با مقادیر فرمِ ذخیره‌نشده) بررسی می‌شود.', 'peakopay'); ?></p>
                </td>
            </tr>
        </table>
        <?php // دور ۲۳ (چندفروشگاهی) — راهنمای اتصال فروشگاه دوم ?>
        <details class="peakopay-second-store" style="margin:8px 0 12px">
            <summary style="cursor:pointer"><strong><?php echo esc_html__('شعبه یا فروشگاه دوم دارید؟', 'peakopay'); ?></strong></summary>
            <div style="margin-top:10px;padding:0 4px">
                <p style="margin-top:0"><?php echo esc_html__('هر فروشگاه (سایت ووکامرس) به‌صورت مستقل به Peakopay وصل می‌شود و تراکنش‌ها، کارت‌ها و تنظیماتش کاملاً جدا از بقیه نگه داشته می‌شود. برای اتصال فروشگاه دوم:', 'peakopay'); ?></p>
                <ol style="margin-top:4px;list-style-position:inside">
                    <li><?php echo esc_html__('در پنل Peakopay (همان سرور فعلی) از بخش «فروشگاه‌ها» — که پس از افزودن فروشگاه دوم در منو ظاهر می‌شود — یک فروشگاه جدید بسازید.', 'peakopay'); ?></li>
                    <li><?php echo esc_html__('شناسه فروشگاه (st_…)، کلید API (hpk_…) و کلید امضای وب‌هوک (whsec_…) فروشگاه جدید را از همان صفحه کپی کنید — کلید API فقط همان یک‌بار نمایش داده می‌شود.', 'peakopay'); ?></li>
                    <li><?php echo esc_html__('افزونه Peakopay را روی سایت دوم نصب کنید و در تنظیماتش همین «آدرس سرور» + شناسه و کلیدهای فروشگاه دوم را وارد کنید.', 'peakopay'); ?></li>
                </ol>
                <p style="margin-bottom:4px"><?php echo esc_html__('نکته: کلیدهای هر فروشگاه فقط در سایت همان فروشگاه کار می‌کنند — کلید یک فروشگاه در سایت دیگر پذیرفته نمی‌شود (داده‌ها ایزوله‌اند).', 'peakopay'); ?></p>
            </div>
        </details>
        <script>
        (function () {
            'use strict';
            var btn = document.getElementById('peakopay-test-connection');
            var out = document.getElementById('peakopay-test-result');
            if (!btn) { return; }
            out.style.whiteSpace = 'pre-line';
            out.style.display = 'inline-block';
            btn.addEventListener('click', function () {
                out.textContent = 'در حال بررسی…';
                out.style.color = '';
                btn.disabled = true;
                var payload = {
                    action: 'peakopay_test_connection',
                    security: <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode of a nonce string ?>
                };
                ['base_url', 'store_id', 'api_key'].forEach(function (id) {
                    var el = document.getElementById('woocommerce_peakopay_' + id);
                    if (el) { payload[id] = el.value; }
                });
                fetch(<?php echo wp_json_encode($ajaxUrl); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- json-encoded URL ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(payload).toString()
                }).then(function (r) { return r.json(); })
                  .then(function (j) {
                      out.textContent = (j && j.data && j.data.message) ? j.data.message : 'پاسخ نامعتبر';
                      out.style.color = (j && j.success) ? 'green' : '#b32d2e';
                  })
                  .catch(function () { out.textContent = 'خطای شبکه'; out.style.color = '#b32d2e'; })
                  .finally(function () { btn.disabled = false; });
            });
        })();
        </script>
        <?php
    }

    // ------------------------------------------------------------ پرداخت

    /**
     * شروع پرداخت: ساخت نشست در سرور Peakopay و انتقال مشتری به صفحه پرداخت.
     *
     * @param int $order_id شناسه سفارش
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wc_add_notice(__('سفارش یافت نشد.', 'peakopay'), 'error');
            return array('result' => 'failure');
        }

        $baseUrl = untrailingslashit(trim((string) $this->get_option('base_url', '')));
        $storeId = trim((string) $this->get_option('store_id', ''));
        $apiKey  = trim((string) $this->get_option('api_key', ''));
        $timeout = max(5, min(60, (int) $this->get_option('timeout', 20)));

        if ($baseUrl === '' || $storeId === '' || $apiKey === '') {
            $this->log('gateway not configured (order #' . $order_id . ')', 'error');
            wc_add_notice(__('درگاه Peakopay به‌درستی تنظیم نشده است؛ لطفاً با پشتیبانی فروشگاه تماس بگیرید. (کد: PP-WOO-CONFIG)', 'peakopay'), 'error');
            return array('result' => 'failure');
        }

        $currency = ($this->get_option('amount_unit', 'IRT') === 'IRR') ? 'IRR' : 'IRT';
        // مبلغ دقیق و شفاف — بدون هیچ تغییر خاموش
        $amount   = (int) round((float) $order->get_total());

        $payload = array(
            'order_id'   => (string) $order->get_id(),
            'order_key'  => (string) $order->get_order_key(),
            'amount'     => $amount,
            'currency'   => $currency,
            'customer'   => array(
                'name'  => trim($order->get_formatted_billing_full_name()),
                'phone' => (string) $order->get_billing_phone(),
            ),
            'description'  => sprintf(__('سفارش #%s', 'peakopay'), $order->get_order_number()),
            'redirect_url' => esc_url_raw($this->get_return_url($order)),
        );
        // حالت تست: فقط وقتی سرور هم test_mode روشن دارد پذیرفته می‌شود.
        if ($this->get_option('test_mode', 'no') === 'yes') {
            $payload['test'] = true;
        }

        // تکرارناپذیری: سفارش + شماره تلاش → دکمه «پرداخت مجدد» نشست جدید نمی‌سازد
        $attempt = (int) $order->get_meta('_peakopay_attempt');
        $idemKey = 'woo-' . $order->get_id() . '-' . $order->get_order_key() . '-' . $attempt;

        $this->log('creating payment for order #' . $order_id . ' amount=' . $amount . ' ' . $currency);

        $res = Peakopay_API::create_payment($baseUrl, $storeId, $apiKey, $payload, $idemKey, $timeout);

        if (!$res['ok']) {
            $err = isset($res['error']) ? $res['error'] : array('code' => 'unknown', 'message' => __('خطای نامشخص', 'peakopay'));
            $this->log('create failed for order #' . $order_id . ' — ' . $err['code'] . ': ' . $err['message'], 'error');
            /* translators: %1$s: error message, %2$s: error code */
            $order->add_order_note(sprintf(__('Peakopay: ساخت پرداخت ناموفق بود — %1$s (%2$s)', 'peakopay'), $err['message'], $err['code']));
            /* translators: %s: error code */
            wc_add_notice(sprintf(__('در حال حاضر امکان شروع پرداخت نیست؛ لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید. (%s)', 'peakopay'), esc_html($err['code'])), 'error');
            return array('result' => 'failure');
        }

        $payment = (array) ($res['data']['payment'] ?? array());
        $url     = (string) ($payment['url'] ?? '');
        $token   = (string) ($payment['token'] ?? '');
        if ($url === '' || $token === '') {
            $this->log('incomplete response for order #' . $order_id, 'error');
            wc_add_notice(__('پاسخ سرور Peakopay کامل نبود؛ لطفاً دوباره تلاش کنید.', 'peakopay'), 'error');
            return array('result' => 'failure');
        }

        // ثبت اطلاعات روی سفارش (HPOS-safe)
        $order->update_meta_data('_peakopay_token', $token);
        $order->update_meta_data('_peakopay_amount_rial', (int) ($payment['amount_rial'] ?? 0));
        $order->update_meta_data('_peakopay_created_at', time());
        $order->update_status('pending', __('Peakopay: صفحه پرداخت برای مشتری ساخته شد.', 'peakopay'));
        $order->save();

        $this->log('payment created for order #' . $order_id . ' token=' . $token);

        return array(
            'result'   => 'success',
            'redirect' => $url,
        );
    }

    /**
     * fallback صفحه تشکر: وب‌هوک مسیر اصلی تأیید است؛ اینجا فقط یک استعلام
     * تک‌مصرفی انجام می‌شود تا اگر وب‌هوک دیر رسیده بود، سفارش بدون انتظار کامل شود.
     */
    public function thankyou_poll_status($order_id)
    {
        try {
            $order = wc_get_order((int) $order_id);
            if (!($order instanceof WC_Order) || !$order->has_status(array('pending', 'on-hold', 'failed'))) {
                return;
            }
            // یک بار در هر بازگشت مشتری — وب‌هوک منبع حقیقت است، این فقط تسریع است.
            if ((string) $order->get_meta('_peakopay_thankyou_polled') !== '') {
                return;
            }
            $order->update_meta_data('_peakopay_thankyou_polled', '1');
            $order->save();

            $token = (string) $order->get_meta('_peakopay_token');
            if ($token === '') {
                return;
            }
            $timeout = max(5, min(60, (int) $this->get_option('timeout', 20)));
            $res = Peakopay_API::get_payment(
                untrailingslashit(trim((string) $this->get_option('base_url', ''))),
                trim((string) $this->get_option('store_id', '')),
                trim((string) $this->get_option('api_key', '')),
                $token,
                $timeout
            );
            if ($res['ok'] && (string) ($res['data']['payment']['status'] ?? '') === 'paid') {
                Peakopay_Webhook::complete_order((int) $order_id, (array) $res['data']['payment'], (array) ($res['data']['transaction'] ?? array()), __('استعلام صفحه تشکر', 'peakopay'));
            }
        } catch (Throwable $e) {
            // صفحه تشکر هرگز به‌خاطر استعلام نشکند
            $this->log('thankyou poll failed: ' . $e->getMessage(), 'error');
        }
    }

    // ------------------------------------------------------------ کمکی

    /** لاگ امن ووکامرس (WooCommerce → Status → Logs، فایل peakopay-…). */
    public function log($message, $level = 'info')
    {
        if ($this->get_option('debug', 'no') !== 'yes' && $level === 'info') {
            return; // info فقط در حالت دیباگ
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level === 'error' ? 'error' : 'info', $message, array('source' => 'peakopay'));
        }
    }
}
