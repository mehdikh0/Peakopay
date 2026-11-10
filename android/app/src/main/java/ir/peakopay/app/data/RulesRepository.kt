package ir.peakopay.app.data

import android.content.Context
import ir.peakopay.app.sms.BankRule
import ir.peakopay.app.sms.RulesFile
import ir.peakopay.app.sms.SmsParser
import kotlinx.serialization.json.Json

/**
 * مخزن قواعد پیامک بانکی.
 * منبع: assets/default_sms_rules.json (همان فایل داده سرور) — قابل توسعه بدون تغییر کد.
 */
object RulesRepository {

    @Volatile
    private var cached: List<BankRule>? = null

    fun rules(context: Context): List<BankRule> {
        cached?.let { return it }
        val json = Json { ignoreUnknownKeys = true }
        val rules = try {
            val text = context.assets.open("default_sms_rules.json").bufferedReader().use { it.readText() }
            json.decodeFromString(RulesFile.serializer(), text).rules
        } catch (e: Exception) {
            emptyList()
        }
        cached = rules
        return rules
    }

    fun parser(context: Context): SmsParser = SmsParser(rules(context))
}
