package ir.peakopay.app

import ir.peakopay.app.sms.CardRuleParser
import ir.peakopay.app.sms.CardRuleParser.CardRule
import ir.peakopay.app.sms.SenderNorm
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * تست قرینه واقعی: همان JSON قاعده‌ای که CardSmsEngine::buildRule سرور (پنل)
 * می‌سازد، عیناً اینجا از فایل resource خوانده و با پیامک «بعدی» اجرا می‌شود —
 * دقیقاً همان کاری که گوشی بعد از همگام‌سازی پیکربندی می‌کند.
 * اگر سرور و اپ به یک نتیجه نرسند، این تست قرمز می‌شود.
 */
class CardRuleParityTest {

    private val json = Json { ignoreUnknownKeys = true }

    private fun loadRule(res: String): CardRule {
        val raw = javaClass.classLoader!!.getResourceAsStream(res)!!.readBytes().decodeToString()
        val obj = json.parseToJsonElement(raw).jsonObject
        return CardRule(
            id = 1,
            name = obj["name"]!!.jsonPrimitive.content,
            priority = obj["priority"]!!.jsonPrimitive.content.toInt(),
            rule = json.decodeFromJsonElement(CardRuleParser.Rule.serializer(), obj),
        )
    }

    // ─── بلو: قالب ساخته‌شده از نمونه «مهدي عزیز» باید پیامک «سارا عزیز» را هم بگیرد ───
    @Test fun blooServerRuleParsesNextSmsOnDevice() {
        val rule = loadRule("rule_bloo.json")
        // نام پرداخت‌کننده، مبلغ، مانده، ساعت و تاریخ متفاوت
        val next = "بلو\n\nواریز پول\n\n سارا عزیز، 7,500,000 ریال به حساب شما نشست.\n\n موجودی: 29,515,157 ریال\n\n۰۹:۰۵\n\n۱۴۰۵.۰۷.۰۱"
        val r = CardRuleParser.runRules(listOf(rule), next)
        assertTrue("rule must match next SMS (got: ${r.rejectReason})", r.ok)
        assertEquals(7_500_000L, r.amountRial)
        assertEquals("29515157", r.fields["balance"])
        assertEquals("واریز", r.fields["txn_type"])
        assertEquals("1405.07.01", r.fields["date"])
    }

    @Test fun blooServerRuleRejectsDifferentFormat() {
        val rule = loadRule("rule_bloo.json")
        val r = CardRuleParser.runRules(listOf(rule), "پرداخت قبض شما با موفقیت انجام شد\nمبلغ 3,000,000 ریال")
        assertFalse(r.ok)
    }

    // ─── بانک ملی: قالب «+» با ارقام متفاوت؛ برداشت «−» رد ───
    @Test fun melliServerRuleParsesNextSmsOnDevice() {
        val rule = loadRule("rule_melli.json")
        val next = "بانك ملي ايران\n\n45,000+\n\nحساب:68001\n\nمانده:153,220,934\n\n0701-09:41"
        val r = CardRuleParser.runRules(listOf(rule), next)
        assertTrue("rule must match next SMS (got: ${r.rejectReason})", r.ok)
        assertEquals(45_000L, r.amountRial)
        assertEquals("153220934", r.fields["balance"])
        // فیلد bank در قالب بدون انکر است → اولین ران حرفیِ متن = کل نام بانک
        assertEquals("بانک ملی ایران", r.fields["bank"])
    }

    @Test fun melliServerRuleRejectsDebitOnDevice() {
        val rule = loadRule("rule_melli.json")
        val r = CardRuleParser.runRules(listOf(rule), "بانك ملي ايران\n\n45,000-\n\nحساب:68001\n\nمانده:153,130,934\n\n0701-09:42")
        assertFalse(r.ok)
    }

    // ─── فرستنده: پیش‌شماره‌ها و فرمت‌های مختلف باید به یک شکل بیفتند ───
    @Test fun senderPrefixVariantsAllMatch() {
        val allowed = listOf(SenderNorm.normalize("+989121234567"))
        assertTrue(SenderNorm.matches("09121234567", allowed))
        assertTrue(SenderNorm.matches("+98 912 123 4567", allowed))
        assertTrue(SenderNorm.matches("00989121234567", allowed))
        assertTrue(SenderNorm.matches("989121234567", allowed))
        assertTrue(SenderNorm.matches("+98-9121234567", allowed))
    }

    @Test fun senderSubstringNeverMatches() {
        val allowed = listOf("912123456")
        // شماره متفاوت (رقم بیشتر) هرگز برابری کامل نمی‌شود
        assertFalse(SenderNorm.matches("9121234567", allowed))
        assertFalse(SenderNorm.matches("91212345", allowed))
        // «+98912123456» همان شماره ۹رقمی در فرمت بین‌المللی است → باید بگیرد
        assertTrue(SenderNorm.matches("+98912123456", allowed))
        // عدد کاملاً متفاوت
        assertFalse(SenderNorm.matches("+989121234560", allowed))
    }

    // ─── فرستنده حرفی (نام فرستنده به‌جای شماره کوتاه — مثل برخی فین‌تک‌ها) ───
    @Test fun alphanumericSenderNormalizedAndMatched() {
        assertEquals("BLOOIR", SenderNorm.normalize("BlooIr"))
        assertEquals("BLOOIR", SenderNorm.normalize(" bloo ir "))
        assertEquals("BLOO123", SenderNorm.normalize("Bloo-123"))
        // فارسی هم (بدون تغییر شکل — uppercase روی فارسی بی‌اثر است)
        assertEquals("بانکملی", SenderNorm.normalize("بانك ملي"))
        assertTrue(SenderNorm.matches("BlooIr", listOf("BLOOIR")))
        assertFalse(SenderNorm.matches("BlooIr2", listOf("BLOOIR")))
        // شماره‌ای مثل قبل — مسیر عددی دست‌نخورده
        assertEquals("9121234567", SenderNorm.normalize("+989121234567"))
        assertEquals("3000777", SenderNorm.normalize("3000777"))
    }
}
