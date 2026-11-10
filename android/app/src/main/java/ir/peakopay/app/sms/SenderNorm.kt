package ir.peakopay.app.sms

/**
 * نرمال‌سازی شماره ارسال‌کننده پیامک — الگوریتم یکسان با سرور (CardSmsEngine::normalizeSender).
 * digits-only + حذف 00 / 98 (وقتی منطقی) / صفر ابتدای شماره‌های طولانی.
 * مقایسه فقط برابری کامل است — هرگز substring matching.
 */
object SenderNorm {

    private val FA_TO_EN = mapOf(
        '۰' to '0', '۱' to '1', '۲' to '2', '۳' to '3', '۴' to '4',
        '۵' to '5', '۶' to '6', '۷' to '7', '۸' to '8', '۹' to '9',
        '٠' to '0', '١' to '1', '٢' to '2', '٣' to '3', '٤' to '4',
        '٥' to '5', '٦' to '6', '٧' to '7', '٨' to '8', '٩' to '9',
    )

    /** بازه ارقام فارسی/عربی داخل بلوک عربی — اینها «حرف» نیستند */
    private fun isArabicBlockDigit(c: Char): Boolean = c in '\u06F0'..'\u06F9' || c in '\u0660'..'\u0669'

    fun normalize(raw: String): String {
        // مثل سرور: اول ارقام فارسی/عربی → لاتین، بعد تصمیم حرفی/عددی
        val sb0 = StringBuilder(raw.length)
        for (c in raw) {
            val m = FA_TO_EN[c]
            when {
                m != null -> sb0.append(m)
                else -> sb0.append(c)
            }
        }
        val pre = sb0.toString()
        // فرستنده حرفی (الفبایی/الفبایی-عددی) — برخی بانک‌ها و فین‌تک‌ها به‌جای
        // شماره کوتاه «نام فرستنده» می‌فرستند (مثل BlooIr). حروف حفظ می‌شوند
        // (بزرگ/کوچک بی‌تفاوت) تا با پاک‌کردن ارقام گم نشوند. قرینه PHP.
        val hasLetter = pre.any {
            it in 'A'..'Z' || it in 'a'..'z' || (it in '\u0600'..'\u06FF' && !isArabicBlockDigit(it))
        }
        if (hasLetter) {
            // ي/ك عربی → ی/ک فارسی (مثل normalizeText سرور) + حذف جداکننده‌ها
            return pre
                .replace('\u064A', 'ی')
                .replace('\u0643', 'ک')
                .filter { it in 'A'..'Z' || it in 'a'..'z' || it in '0'..'9' || (it in '\u0600'..'\u06FF' && !isArabicBlockDigit(it)) }
                .uppercase()
        }
        val d = StringBuilder(pre.length)
        for (c in pre) {
            if (c in '0'..'9') d.append(c)
        }
        var s = d.toString()
        if (s.startsWith("00") && s.length > 4) s = s.substring(2)
        if (s.startsWith("98") && s.length > 8) s = s.substring(2)
        if (s.startsWith("0") && s.length > 10) s = s.substring(1)
        return s
    }

    /** آیا فرستنده در فهرست مجازِ کارت هست؟ (برابری کامل پس از نرمال‌سازی) */
    fun matches(sender: String, allowedNormalized: Collection<String>): Boolean {
        val n = normalize(sender)
        if (n.isEmpty()) return false
        return allowedNormalized.any { it == n }
    }
}
