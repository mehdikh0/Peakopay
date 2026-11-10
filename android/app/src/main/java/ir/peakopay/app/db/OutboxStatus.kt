package ir.peakopay.app.db

/**
 * وضعیت‌های صف ارسال (outbox) — تمام مقادیر به‌صورت رشته در ستون `status`.
 *
 * `pending_offline` (دور ۱۵): تراکنش در گوشی دریافت و parse شده اما هنوز با سرور
 * همگام نشده — یا چون هنگام دریافت پیامک اینترنت در دسترس نبود، یا چون ارسالِ
 * قبلی به‌صورت «موقت» شکست خورده (قطعی شبکه/خطای سرور). به‌محض اتصال اینترنت
 * دوباره ارسال می‌شود و هیچ تراکنشی به‌خاطر بی‌اینترنتی حذف نمی‌شود.
 *
 * وضعیت‌های قبلی دست‌نخورده باقی می‌مانند (سطرهای قدیمی دیتابیس همچنان معتبرند
 * و هیچ تغییری نام یا معنایشان را عوض نمی‌کند).
 */
object OutboxStatus {
    /** در صف — جریان آنلاینِ قبلی (پیامک هنگام اینترنتِ موجود). */
    const val PENDING = "pending"

    /** در صف — ذخیره‌شده به‌صورت آفلاین، هنوز همگام نشده. */
    const val PENDING_OFFLINE = "pending_offline"

    const val SENDING = "sending"
    const val SENT = "sent"
    const val FAILED = "failed"
    const val REJECTED = "rejected"

    /** وضعیت‌هایی که takeBatch برای ارسال برمی‌دارد (تا سقف تلاش). */
    val RETRYABLE = setOf(PENDING, PENDING_OFFLINE, FAILED)

    /**
     * وضعیت پس از یک شکستِ «موقت» ارسال (قطع اینترنت / 5xx سرور / دستگاه pending):
     * تراکنشِ آفلاین طبق مشخصات `pending_offline` می‌ماند تا دوباره تلاش شود؛
     * بقیه مثل رفتار قبلی `failed` می‌شوند (failed هم قابل ارسال مجدد است).
     */
    fun afterTempFailure(previous: String): String =
        if (previous == PENDING_OFFLINE) PENDING_OFFLINE else FAILED
}
