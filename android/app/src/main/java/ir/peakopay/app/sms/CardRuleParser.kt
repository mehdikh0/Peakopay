package ir.peakopay.app.sms

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/**
 * پارسر پیامک به‌ازای کارت — همان فرمت اعلانی JSON سرور (CardSmsEngine).
 * قانون = اسکلت ثابت + جای‌خالی‌های داینامیک؛ هیچ کدی اجرا نمی‌شود،
 * فقط regex + تبدیل‌های مجاز. متن پیامک ورودی غیرمورد اعتماد است و
 * هرگز به‌عنوان کد ارزیابی نمی‌شود.
 */
object CardRuleParser {

    @Serializable
    data class FieldDef(
        val key: String,
        val pattern: String,
        val before: String = "",
        val after: String = "",
        val transforms: List<String> = emptyList(),
        val currency_from_text: Boolean = false,
    )

    @Serializable
    data class Rule(
        val v: Int = 1,
        val name: String = "",
        val priority: Int = 100,
        val currency_default: String = "IRR",
        val fields: List<FieldDef> = emptyList(),
        val required: List<String> = listOf("amount"),
    )

    @Serializable
    data class CardRule(
        val id: Long,
        val name: String,
        val priority: Int,
        val rule: Rule,
    )

    data class Result(
        val ok: Boolean,
        val matchedRule: CardRule? = null,
        val fields: Map<String, String> = emptyMap(),
        val amountRial: Long = 0,
        val currency: String = "IRR",
        val confidence: String = "None",
        val missing: List<String> = emptyList(),
        val rejectReason: String = "",
        val anchored: Int = 0,
    )

    private val json = Json { ignoreUnknownKeys = true }

    fun parseRule(raw: String): Rule? = try { json.decodeFromString(Rule.serializer(), raw) } catch (e: Exception) { null }

    private val FA_DIGITS = "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩"
    private val EN_DIGITS = "01234567890123456789"

    /** نرمال‌سازی متن: ارقام فارسی/عربی → لاتین، جداکننده‌ها، علائم RTL، فاصله‌ها. */
    fun normalizeText(text: String): String {
        val sb = StringBuilder(text.length)
        for (c in text) {
            val idx = FA_DIGITS.indexOf(c)
            when {
                idx >= 0 -> sb.append(EN_DIGITS[idx])
c == '\u066C' || c == '\u060C' -> sb.append(',') // جداکننده هزارگان فارسی/عربی
                c == '\u066B' -> sb.append('.') // ممیز اعشار عربی
                c == '\u064A' -> sb.append('ی') // ي عربی → ی فارسی (بانك ملي بانک ملی)
                c == '\u0643' -> sb.append('ک') // ك عربی → ک فارسی
                c == '\u200C' || c == '\u200F' || c == '\u200E' || c == '\u202A' || c == '\u202B' || c == '\u202C' || c == '\u202D' || c == '\u202E' -> sb.append(' ') // مثل سرور: → فاصله
                else -> sb.append(c)
            }
        }
        var t = sb.toString()
        t = t.replace("\u200B", " ").replace("\uFEFF", " ")
        t = t.replace(Regex("[ \\t\\u00A0]+"), " ")
        return t.trim()
    }

    fun detectCurrency(norm: String, default: String = "IRR"): String = when {
        norm.contains("تومان") -> "IRT"
        norm.contains("ریال") || norm.contains("ريال") -> "IRR"
        else -> default
    }

    fun toRial(digits: String, currency: String): Long {
        val n = digits.filter { it in '0'..'9' }.toLongOrNull() ?: 0L
        return if (currency == "IRT") n * 10 else n
    }

    /** اجرای همه قواعد کارت؛ برنده = بیشترین فیلد استخراج‌شده (سپس اولویت). */
    fun runRules(rules: List<CardRule>, text: String): Result {
        val norm = normalizeText(text)
        if (norm.isEmpty() || norm.length > 1600) {
            return Result(false, rejectReason = "پیامک خالی یا خیلی طولانی است.")
        }
        var best: Result? = null
        var bestScore = -1
        for (cr in rules) {
            val r = runRule(cr, norm)
            if (r.ok) {
                // فقط استخراج‌های انکرشده امتیاز دارند — قالب گشاد (bare) قالب دقیق را نمی‌بلعد
                val score = r.anchored * 100 - cr.priority
                if (score > bestScore) {
                    bestScore = score
                    best = r
                }
            }
        }
        return best ?: Result(false, missing = listOf("amount"), rejectReason = "هیچ قالبی از قالب‌های این کارت با پیامک مطابقت کامل نداشت.")
    }

        // انواع «کلیدواژه‌ای»: الگویشان خودش معنا دارد و بی‌انکر هم قابل اعتماد است
        // (الگوی txn_type لیست «واریز|برداشت|…» است — همین که بگیرد جهت تراکنش
        // مشخص می‌شود). انواع عددی فقط با انکر یا بدون هیچ انکری حدس آزاد می‌خورند.
        private val BARE_KEYWORD_TYPES = setOf("txn_type", "bank")

    fun runRule(cr: CardRule, normText: String): Result {
        val rule = cr.rule
        if (rule.v != 1) return Result(false, matchedRule = cr, rejectReason = "نسخه قالب پشتیبانی نمی‌شود.")
        val extracted = HashMap<String, String>()
        var ctxHits = 0
        var ctxTries = 0
        var anchored = 0 // فیلدهای گرفته‌شده با انکر متن ثابت (نه حدس bare)
        val missing = ArrayList<String>()
        for (f in rule.fields) {
            var value: String? = null
            var usedBare = false
            // بین انکر و مقدار، جداکننده‌های رایج tolerate می‌شوند: «مبلغ: 25,000,000» با انکر «مبلغ» هم می‌گیرد
            if (f.before.isNotEmpty()) {
                val rx = Regex(Regex.escape(f.before) + "[\\s:؛،.\\-]*(" + f.pattern + ")")
                value = rx.find(normText)?.groupValues?.get(1)
            }
            if (value == null && f.after.isNotEmpty()) {
                val rx = Regex("(" + f.pattern + ")[\\s:؛،.\\-]*" + Regex.escape(f.after))
                value = rx.find(normText)?.groupValues?.get(1)
            }
            val canBare = (f.before.isEmpty() && f.after.isEmpty()) || f.key in BARE_KEYWORD_TYPES
            if (value == null && canBare) {
                // حدس لخت فقط برای انواع کلیدواژه‌ای یا فیلد بدون هیچ انکری —
                // اگر انکرِ عددی نگیرد، حدس آزاد ممنوع است (وگرنه «45,000-» برداشتِ
                // بانک ملی هم مبلغ گرفته می‌شد).
                value = Regex("(" + f.pattern + ")").find(normText)?.groupValues?.get(1)
                usedBare = value != null // حدس بدون انکر — در رتبه‌بندی کم‌وزن
            }
            if (value != null && !usedBare && (f.before.isNotEmpty() || f.after.isNotEmpty())) {
                anchored++
            }
            if (value == null) {
                if (f.key in rule.required) missing.add(f.key)
                continue
            }
            if (f.before.isNotEmpty() || f.after.isNotEmpty()) {
                ctxTries++
                // context hit: the value is unique in text (context pinned it)
                if (Regex(Regex.escape(value)).findAll(normText).count() <= 1) ctxHits++
            }
            var v = value
            for (t in f.transforms) {
                when (t) {
                    "strip_separators" -> v = v!!.replace(",", "").replace(".", "")
                    "normalize_digits" -> v = normalizeText(v!!)
                    "last4" -> { // از ران کامل شماره کارت، چهار رقم آخر
                        val digits = v!!.filter { it in '0'..'9' }
                        v = if (digits.length > 4) digits.takeLast(4) else digits
                    }
                }
            }
            extracted[f.key] = v!!.trim().take(60)
        }
        if (missing.isNotEmpty()) {
            return Result(false, matchedRule = cr, fields = extracted, missing = missing, rejectReason = "فیلدهای الزامی پیدا نشد: " + missing.joinToString("، "), anchored = anchored)
        }
        val txnType = extracted["txn_type"] ?: ""
        if (txnType.contains("برداشت") || txnType.contains("خرید")) {
            return Result(false, matchedRule = cr, fields = extracted, missing = listOf("txn_type"), rejectReason = "تراکنش واریز نیست (برداشت/خرید).", anchored = anchored)
        }
        val amountDigits = (extracted["amount"] ?: "").filter { it in '0'..'9' }
        val amountVal = amountDigits.toLongOrNull() ?: 0L
        if (amountDigits.isEmpty() || amountVal <= 0L) {
            return Result(false, matchedRule = cr, fields = extracted, missing = listOf("amount"), rejectReason = "مبلغ نامعتبر است.", anchored = anchored)
        }
        val currency = detectCurrency(normText, rule.currency_default)
        val rial = toRial(amountDigits, currency)
        val confidence = if (ctxTries == 0 || ctxHits.toFloat() / ctxTries >= 0.7f) "High" else "Medium"
        return Result(true, matchedRule = cr, fields = extracted, amountRial = rial, currency = currency, confidence = confidence, anchored = anchored)
    }

    /** اثر انگشت قطعی برای جلوگیری از ارسال دوباره (SHA-256). */
    fun fingerprint(cardId: Long, sender: String, amountRial: Long, reference: String, rawHash: String): String {
        val input = "$cardId|${SenderNorm.normalize(sender)}|$amountRial|${reference.trim()}|$rawHash"
        val md = java.security.MessageDigest.getInstance("SHA-256")
        return md.digest(input.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
    }
}
