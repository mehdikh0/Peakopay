package ir.peakopay.app.util

import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/** ابزارهای نمایش فارسی: ارقام فارسی، جداکننده هزارگان فارسی، نمایش ریال/تومان. */
object Fa {

    private val faDigits = charArrayOf('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹')

    /** تبدیل ارقام لاتین به فارسی. */
    fun digits(text: String): String = buildString(text.length) {
        for (c in text) {
            append(if (c in '0'..'9') faDigits[c - '0'] else c)
        }
    }

    private val faSymbols = DecimalFormatSymbols(Locale.forLanguageTag("fa")).apply {
        groupingSeparator = '٬'
    }
    private val groupingFormat = DecimalFormat("#,###", faSymbols)

    /** عدد با جداکننده هزارگان فارسی و ارقام فارسی. */
    fun number(value: Long): String = digits(groupingFormat.format(value))

    /** نمایش مبلغ ریال به «تومان» خوانا (بدون تغییر مقدار واقعی — فقط نمایش). */
    fun rialAsToman(rial: Long): String = number(rial / 10)

    /** برچسب کامل مبلغ: «۲۵۰٬۰۰۰ تومان (۲٬۵۰۰٬۰۰۰ ریال)» — شفاف و بدون ابهام. */
    fun amountLabel(rial: Long): String =
        "${rialAsToman(rial)} تومان (${number(rial)} ریال)"

    fun bool(b: Boolean): String = if (b) "بله" else "خیر"

    fun timeLabel(epochMs: Long): String =
        digits(java.text.SimpleDateFormat("HH:mm:ss", Locale.forLanguageTag("fa")).format(java.util.Date(epochMs)))

    /** SHA-256 هگز — برای raw_hash پیامک و اثر انگشت تراکنش. */
    fun sha256(s: String): String {
        val md = java.security.MessageDigest.getInstance("SHA-256")
        return md.digest(s.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
    }

    /** ISO-8601 با اختلاف ساعت (قرارداد سرور) از epoch میلی‌ثانیه. */
    fun isoNow(epochMs: Long): String =
        java.time.Instant.ofEpochMilli(epochMs).toString()
}
