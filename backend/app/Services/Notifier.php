<?php
/**
 * Peakopay — Optional notifications (email + Telegram).
 *
 * Best-effort with short timeouts; a notification failure NEVER blocks a
 * payment. Events: payment paid, unmatched transaction, device paired.
 */
declare(strict_types=1);

final class Notifier
{
    public static function paymentPaid(array $payment): void
    {
        // دور ۲۳ — تنظیمات اعلان از فروشگاهِ صاحب پرداخت
        Helpers::withStore((int) ($payment['store_id'] ?? 0), static function () use ($payment): void {
            if (!Setting::bool('notify_event_paid', true)) {
                return;
            }
            $amount = Helpers::money((int) $payment['amount_rial']);
            self::dispatch('payment.paid', "✅ پرداخت جدید تأیید شد\nسفارش: {$payment['order_id']}\nمبلغ: {$amount}\nکد پیگیری پرداخت: {$payment['token']}");
        });
    }

    public static function transactionUnmatched(array $txn): void
    {
        Helpers::withStore((int) ($txn['store_id'] ?? 0), static function () use ($txn): void {
            if (!Setting::bool('notify_event_unmatched', true)) {
                return;
            }
            $amount = Helpers::money((int) $txn['amount_rial']);
            self::dispatch('transaction.unmatched', "⚠️ تراکنش بدون تطبیق\nمبلغ: {$amount}\nبانک: {$txn['bank']}\nمرجع: {$txn['reference']}\nدر پنل مدیریت بررسی کنید.");
        });
    }

    public static function devicePaired(array $device, ?int $storeId = null): void
    {
        if ($storeId !== null) {
            Helpers::withStore($storeId, static fn() => self::devicePairedScoped($device));
            return;
        }
        self::devicePairedScoped($device);
    }

    private static function devicePairedScoped(array $device): void
    {
        if (!Setting::bool('notify_event_device', false)) {
            return;
        }
        self::dispatch('device.paired', "📱 دستگاه جدید «{$device['name']}» در انتظار تأیید است.\nاز پنل مدیریت → دستگاه‌ها آن را تأیید کنید.");
    }

    /**
     * دور ۲۹ — رویداد امنیتی (مثل بازنشانی 2FA توسط مدیر): ایمیل مستقیم به
     * کاربر + ثبت در جدول notifications. مجرای محلی — بدون سرویس بیرونی.
     */
    public static function securityEmail(string $to, string $subject, string $body, int $storeId = 0): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $ok = self::sendEmail($to, $subject, $body);
        try {
            DB::insert('notifications', [
                'store_id'   => $storeId > 0 ? $storeId : null,
                'channel'    => 'email',
                'event'      => 'security.2fa',
                'message'    => mb_substr($subject . ' — ' . $to, 0, 490),
                'status'     => $ok ? 'sent' : 'failed',
                'created_at' => DB::now(),
                'sent_at'    => $ok ? DB::now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warn('security notification record failed: ' . $e->getMessage());
        }
        return $ok;
    }

    private static function dispatch(string $event, string $message): void
    {
        if (Setting::bool('notify_email_enabled') && Setting::get('notify_email') !== '') {
            self::record(self::sendEmail(Setting::get('notify_email'), 'پیکو پی — ' . $event, $message), 'email', $event, $message);
        }
        if (Setting::bool('notify_telegram_enabled') && Setting::get('telegram_bot_token') !== '' && Setting::get('telegram_chat_id') !== '') {
            self::record(self::sendTelegram($message), 'telegram', $event, $message);
        }
    }

    private static function record(bool $ok, string $channel, string $event, string $message): void
    {
        try {
            DB::insert('notifications', [
                'store_id'   => (int) (Helpers::currentStoreId() ?? 0),
                'channel'    => $channel,
                'event'      => $event,
                'message'    => mb_substr($message, 0, 490),
                'status'     => $ok ? 'sent' : 'failed',
                'created_at' => DB::now(),
                'sent_at'    => $ok ? DB::now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warn('notification record failed: ' . $e->getMessage());
        }
    }

    private static function sendEmail(string $to, string $subject, string $body): bool
    {
        $headers = "From: Peakopay <no-reply@" . (parse_url(Config::baseUrl(), PHP_URL_HOST) ?: 'localhost') . ">\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
        try {
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function sendTelegram(string $text): bool
    {
        $token = Setting::get('telegram_bot_token');
        $chat  = Setting::get('telegram_chat_id');
        try {
            if (!function_exists('curl_init')) {
                return false;
            }
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $chat, 'text' => $text]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $json = json_decode((string) $raw, true);
            return is_array($json) && ($json['ok'] ?? false) === true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
