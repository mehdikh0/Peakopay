package ir.peakopay.app.sms

import kotlinx.serialization.Serializable

/**
 * قاعده تشخیص پیامک بانکی — همان ساختار config/sms-rules.json سرور.
 * قواعد از assets/default_sms_rules.json خوانده می‌شوند؛ برای افزودن بانک جدید
 * فقط همان فایل داده را تغییر دهید (کد دست نمی‌زند).
 */
@Serializable
data class BankRule(
    val id: String,
    val bank: String,
    val senders: List<String> = emptyList(),
    val deposit_keywords: List<String> = emptyList(),
    val reject_keywords: List<String> = emptyList(),
    val amount_patterns: List<String> = emptyList(),
    val card_patterns: List<String> = emptyList(),
    val reference_patterns: List<String> = emptyList(),
)

@Serializable
data class RulesFile(val rules: List<BankRule> = emptyList())

/** نتیجه تجزیه یک پیامک. */
data class ParsedSms(
    val bank: String,
    val sender: String,
    val amountRial: Long,
    val destLast4: String,
    val reference: String,
    val rawText: String,
    val occurredAtIso: String,
    val rawHash: String,
)
