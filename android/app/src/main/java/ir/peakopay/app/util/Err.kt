package ir.peakopay.app.util

import ir.peakopay.app.data.api.Api

/**
 * دور ۲۶ — پیام خطای قابل‌فهم برای اقدام‌های اپ:
 * اگر سرور قدیمی باشد (بدون endpoint جدید)، به‌جای خطای خام، راهنمای
 * به‌روزرسانی بستهٔ سرور نشان داده می‌شود (قابلیت‌های × حذف/بستن و
 * افزودن فروشگاه به سرور ۲.۱ به بعد نیاز دارند).
 */
object Err {
    fun friendly(e: Exception): String = when {
        e is Api.ApiException && (e.httpStatus == 404 || e.code == "not_found" || e.code == "bad_response") ->
            "این قابلیت روی سرور شما فعال نیست — بستهٔ سرور (cpanel) را به آخرین نسخه به‌روز کنید."
        else -> e.message ?: "خطای نامشخص"
    }
}
