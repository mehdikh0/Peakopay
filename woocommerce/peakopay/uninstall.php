<?php
/**
 * Peakopay — پاک‌سازی هنگام حذف افزونه.
 *
 * فقط تنظیمات افزونه حذف می‌شود؛ داده‌های سفارش (متاها) و لاگ‌ها
 * برای سلامت سوابق مالی حفظ می‌شوند.
 *
 * @package Peakopay
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// تنظیمات درگاه
delete_option('woocommerce_peakopay_settings');

// متا نشست‌ها روی سفارش‌ها حذف نمی‌شود: سوابق مرجوعی/حسابداری باید بمانند.
