package ir.peakopay.app.sms

import java.security.MessageDigest
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * تجزیه‌گر پیامک بانکی — قرارداد یکسان با SmsParser سرور:
 *  - فقط پیامک‌های «واریز» (deposit) پذیرفته می‌شوند؛
 *  - کلیدواژه‌های رد (برداشت/خرید/…) اگر پیدا شوند، پیامک نادیده گرفته می‌شود؛
 *  - مبلغ با الگوهای هر بانک استخراج و اگر تومان بود ×۱۰ می‌شود (تبدیل صریح)؛
 *  - اگر هیچ قاعده‌ای مطابقت نکند، پیامک هرگز ارسال نمی‌شود.
 */
class SmsParser(private val rules: List<BankRule>) {

    /** null یعنی: این پیامک بانکی واریز نیست — ارسال نشود. */
    fun parse(body: String, sender: String, receivedAtEpochMs: Long): ParsedSms? {
        val text = body.replace("\u200c", " ").trim() // نیم‌فاصله → فاصله برای الگوها
        if (text.isEmpty()) return null

        for (rule in rules) {
            if (rule.senders.isNotEmpty() && rule.senders.none { s -> sender.contains(s, ignoreCase = true) }) {
                continue
            }
            // کلیدواژه‌های رد مقدم هستند — پیامک خرید/برداشت هرگز واریز حساب نمی‌شود
            if (rule.reject_keywords.any { text.contains(it) }) continue
            if (rule.deposit_keywords.none { text.contains(it) }) continue

            val amount = extractAmount(text, rule) ?: continue
            val last4 = extractCardLast4(text, rule)
            val reference = extractReference(text, rule)

            return ParsedSms(
                bank = rule.bank,
                sender = sender,
                amountRial = amount,
                destLast4 = last4,
                reference = reference,
                rawText = body.take(1600),
                occurredAtIso = isoOffset(receivedAtEpochMs),
                rawHash = sha256(body),
            )
        }
        return null
    }

    /** استخراج مبلغ — الگوها گروه اول را می‌گیرند؛ تومان → ریال (×۱۰) صریح. */
    private fun extractAmount(text: String, rule: BankRule): Long? {
        for (pattern in rule.amount_patterns) {
            val m = safeRegex(pattern).find(text) ?: continue
            val digits = (m.groupValues.getOrNull(1) ?: "").filter { it.isDigit() }
            if (digits.isEmpty()) continue
            val value = digits.toLongOrNull() ?: continue
            if (value <= 0) continue
            val unit = (m.groupValues.getOrNull(2) ?: "").trim()
            return if (unit == "تومان" || unit == "Toman" || unit == "t") value * 10 else value
        }
        return null
    }

    /** ۴ رقم آخر کارت مقصد از الگوی کارت (مثل 6219-86••-••••-0019). */
    private fun extractCardLast4(text: String, rule: BankRule): String {
        for (pattern in rule.card_patterns) {
            val m = safeRegex(pattern).find(text) ?: continue
            val capture = m.groupValues.getOrNull(1) ?: continue
            val digits = capture.filter { it.isDigit() }
            if (digits.length >= 4) return digits.takeLast(4)
        }
        return ""
    }

    private fun extractReference(text: String, rule: BankRule): String {
        for (pattern in rule.reference_patterns) {
            val m = safeRegex(pattern).find(text) ?: continue
            val ref = (m.groupValues.getOrNull(1) ?: "").trim()
            if (ref.isNotEmpty()) return ref
        }
        return ""
    }

    /** ISO-8601 با اختلاف ساعت — قرارداد زمانی پیکو پی (بدون ابهام). */
    private fun isoOffset(epochMs: Long): String =
        OffsetDateTime.ofInstant(java.time.Instant.ofEpochMilli(epochMs), ZoneId.systemDefault())
            .format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)

    private fun sha256(s: String): String =
        MessageDigest.getInstance("SHA-256").digest(s.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }

    companion object {
        /** الگوها با پرچم DOTALL پیش‌کامپایل می‌شوند؛ خطای الگو = نادیده گرفتن همان الگو. */
        private fun safeRegex(pattern: String): Regex = try {
            Regex(pattern, setOf(RegexOption.DOT_MATCHES_ALL, RegexOption.IGNORE_CASE))
        } catch (e: Exception) {
            Regex("(?!)") // هرگز مطابقت نمی‌کند
        }
    }
}
