package ir.peakopay.app.data

import android.content.Context
import ir.peakopay.app.sms.CardRuleParser
import ir.peakopay.app.sms.SenderNorm
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/**
 * پیکربندی کارت‌ها (فرستنده‌های مجاز + قواعد پارسر) که بعد از همراه‌سازی از سرور
 * گرفته می‌شود و در حافظه خصوصی اپ کش می‌شود تا بدون اینترنت هم خط لوله پیامک
 * کار کند. هیچ شماره کارت کامل یا رازی در این پیکربندی نیست (فقط last4).
 */
object CardConfigStore {

    @Serializable
    data class CardCfg(
        val id: Long,
        val label: String = "",
        val bank: String = "",
        val last4: String = "",
        val enabled: Boolean = true,
        val senders: List<String> = emptyList(),
        val rules: List<RuleCfg> = emptyList(),
        val updated_at: Long = 0,
        /** دور ۲۳ — فروشگاهِ صاحب کارت (۰ = سرور قدیمی تک‌فروشگاهی) */
        val store_id: Long = 0,
        val store_name: String = "",
    )

    @Serializable
    data class RuleCfg(
        val id: Long,
        val name: String,
        val priority: Int,
        val rule: CardRuleParser.Rule,
        val version: Long = 0,
    )

    @Serializable
    data class Config(
        val config_version: Long = 0,
        val cards: List<CardCfg> = emptyList(),
    )

    private val json = Json { ignoreUnknownKeys = true }

    fun load(context: Context): Config = try {
        val raw = context.getSharedPreferences("card_config", Context.MODE_PRIVATE).getString("cfg", null)
        if (raw !== null) json.decodeFromString(Config.serializer(), raw) else Config()
    } catch (e: Exception) {
        Config()
    }

    fun save(context: Context, cfg: Config) {
        context.getSharedPreferences("card_config", Context.MODE_PRIVATE)
            .edit().putString("cfg", json.encodeToString(Config.serializer(), cfg)).apply()
    }

    fun localVersion(context: Context): Long = load(context).config_version

    /** تبدیل پاسخ API به مدل کش محلی. */
    fun fromApi(p: ir.peakopay.app.data.api.Api.DeviceConfigPayload): Config = Config(
        config_version = p.config_version,
        cards = p.cards.map { c ->
            CardCfg(
                id = c.id, label = c.label, bank = c.bank, last4 = c.last4, enabled = c.enabled,
                senders = c.senders,
                store_id = c.store_id, store_name = c.store_name,
                rules = c.rules.map { RuleCfg(id = it.id, name = it.name, priority = it.priority, rule = it.rule, version = it.version) },
            )
        },
    )

    /** کارتِ تطبیق‌یافته با فرستنده (فعال + فرستنده در فهرست مجاز) — null یعنی نادیده‌گرفتن. */
    fun cardForSender(context: Context, sender: String): CardCfg? {
        val cfg = load(context)
        return cfg.cards.firstOrNull { it.enabled && SenderNorm.matches(sender, it.senders) }
    }

    fun rulesOf(card: CardCfg): List<CardRuleParser.CardRule> =
        card.rules.map { CardRuleParser.CardRule(id = it.id, name = it.name, priority = it.priority, rule = it.rule) }

    fun clear(context: Context) {
        context.getSharedPreferences("card_config", Context.MODE_PRIVATE).edit().clear().apply()
    }
}
