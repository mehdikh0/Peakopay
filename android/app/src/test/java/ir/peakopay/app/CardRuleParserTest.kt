package ir.peakopay.app

import ir.peakopay.app.sms.CardRuleParser
import ir.peakopay.app.sms.CardRuleParser.CardRule
import ir.peakopay.app.sms.CardRuleParser.FieldDef
import ir.peakopay.app.sms.CardRuleParser.Rule
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * تست موتور پارسر — قرینه‌ی tests/unit/10_card_sms.php سمت سرور.
 * قانون = همان JSON اعلانی که پنل مدیریت می‌سازد (اسکلت ثابت + جای‌خالی).
 */
class CardRuleParserTest {

    private fun melatRule(priority: Int = 10, id: Long = 1): CardRule = CardRule(
        id = id, name = "قالب ملت", priority = priority,
        rule = Rule(
            v = 1, currency_default = "IRR",
            fields = listOf(
                FieldDef("txn_type", "(واریز|برداشت|خرید|پرداخت)"),
                FieldDef("card_last4", "[0-9]{4}", before = "کارت "),
                FieldDef("amount", "[0-9][0-9,\\.]{1,18}[0-9]", before = "مبلغ ", after = "ریال"),
                FieldDef("balance", "[0-9][0-9,\\.]{1,18}[0-9]", before = "مانده "),
                FieldDef("date", "[0-9]{4}/[0-9]{1,2}/[0-9]{1,2}"),
                FieldDef("time", "[0-9]{1,2}:[0-9]{2}"),
                FieldDef("reference", "[0-9]{3,20}", before = "پیگیری "),
            ),
            required = listOf("amount", "txn_type"),
        ),
    )

    private val melatSms = """
        واریز به کارت 1234
        مبلغ 25,000,000 ریال
        مانده 87,500,000 ریال
        تاریخ 1405/06/29 ساعت 14:32
        کد پیگیری 123456
    """.trimIndent()

    // ─── نرمال‌سازی متن ───
    @Test fun persianDigitsToLatin() =
        assertEquals("مبلغ 25000000", CardRuleParser.normalizeText("مبلغ ۲۵٬۰۰۰٬۰۰۰").replace(",", ""))
    @Test fun arabicDigitsToLatin() =
        assertEquals("123", CardRuleParser.normalizeText("١٢٣"))
    @Test fun arabicDecimalSeparator() =
        assertEquals("12.5", CardRuleParser.normalizeText("12٫5"))
    @Test fun arabicCommaToLatin() =
        assertEquals("25,000", CardRuleParser.normalizeText("25٬000"))
    @Test fun rtlMarksAndZwnjStripped() {
        val dirty = "سلام" + "\u200C" + "دنیا" + "\u200F" + " " + "\u200F" + "آزمون"
        assertEquals("سلام دنیا آزمون", CardRuleParser.normalizeText(dirty))
    }

    // ─── تشخیص ارز ───
    @Test fun rialDetected() = assertEquals("IRR", CardRuleParser.detectCurrency("مبلغ 100 ریال"))
    @Test fun tomanDetected() = assertEquals("IRT", CardRuleParser.detectCurrency("مبلغ 100 تومان"))
    @Test fun fallbackToDefault() = assertEquals("IRR", CardRuleParser.detectCurrency("بدون واحد"))
    @Test fun tomanConvertedToRial() = assertEquals(10_000_000L, CardRuleParser.toRial("1,000,000", "IRT"))
    @Test fun rialNotDoubled() = assertEquals(1_000_000L, CardRuleParser.toRial("1,000,000", "IRR"))

    // ─── استخراج فیلدها ───
    @Test fun fullExtraction() {
        val r = CardRuleParser.runRules(listOf(melatRule()), melatSms)
        assertTrue("parse must succeed: " + r.rejectReason, r.ok)
        assertEquals(25_000_000L, r.amountRial)
        assertEquals("IRR", r.currency)
        assertEquals("واریز", r.fields["txn_type"])
        assertEquals("1234", r.fields["card_last4"])
        assertEquals("87,500,000", r.fields["balance"])
        assertEquals("1405/06/29", r.fields["date"])
        assertEquals("14:32", r.fields["time"])
        assertEquals("123456", r.fields["reference"])
        assertEquals("High", r.confidence)
    }
    @Test fun persianDigitSmsParsed() {
        val r = CardRuleParser.runRules(listOf(melatRule()),
            "واریز به کارت ۱۲۳۴\nمبلغ ۲۵٬۰۰۰٬۰۰۰ ریال\nمانده ۸۷٬۵۰۰٬۰۰۰ ریال\nتاریخ ۱۴۰۵/۰۶/۲۹ ساعت ۱۴:۳۲\nکد پیگیری ۱۲۳۴۵۶")
        assertTrue(r.ok)
        assertEquals(25_000_000L, r.amountRial)
        assertEquals("1234", r.fields["card_last4"])
    }
    @Test fun tomanSmsConverted() {
        val r = CardRuleParser.runRules(listOf(melatRule()),
            "واریز به کارت 1234\nمبلغ 3,500,000 تومان\nمانده 87,500,000 ریال")
        assertTrue(r.ok)
        assertEquals("IRT", r.currency)
        assertEquals(35_000_000L, r.amountRial) // ×۱۰
    }
    @Test fun withdrawalRejected() {
        val r = CardRuleParser.runRules(listOf(melatRule()),
            "برداشت از کارت 1234\nمبلغ 500,000 ریال\nمانده 87,000,000 ریال\nکد پیگیری 999999")
        assertFalse(r.ok)
    }
    @Test fun purchaseRejected() {
        val r = CardRuleParser.runRules(listOf(melatRule()),
            "خرید با کارت 1234\nمبلغ 500,000 ریال\nکد پیگیری 999999")
        assertFalse(r.ok)
    }
    @Test fun missingAmountFails() {
        val r = CardRuleParser.runRules(listOf(melatRule()), "پیامک بدون هیچ چیز")
        assertFalse(r.ok)
    }
    @Test fun malformedSmsRejected() {
        val r = CardRuleParser.runRules(listOf(melatRule()), "\$#@!٪٪ خراب")
        assertFalse(r.ok)
    }
    @Test fun emptySmsRejected() = assertFalse(CardRuleParser.runRules(listOf(melatRule()), "   ").ok)

    // ─── چند قالب به‌ازای کارت + اولویت ───
    @Test fun multipleRulesMostSpecificWins() {
        val loose = CardRule(id = 2, name = "قالب ساده", priority = 1, rule = Rule(
            fields = listOf(FieldDef("amount", "[0-9][0-9,\\.]{1,18}[0-9]", before = "مبلغ ")),
        ))
        val r = CardRuleParser.runRules(listOf(loose, melatRule()), melatSms) // ساده اول در لیست
        assertTrue(r.ok)
        assertEquals("قالب ملت", r.matchedRule?.name) // خاص‌تر برنده می‌شود نه اولین
    }
    @Test fun secondRuleUsedWhenFirstFails() {
        val weird = CardRule(id = 3, name = "قالب عجیب", priority = 1, rule = Rule(
            fields = listOf(FieldDef("amount", "[0-9]+", before = "SAI_VARIZ ")),
        ))
        val r = CardRuleParser.runRules(listOf(weird, melatRule()), melatSms)
        assertTrue(r.ok)
        assertEquals("قالب ملت", r.matchedRule?.name)
    }

    // ─── تنوع فرمت بانک‌ها (چیزی هاردکد نیست — قانون همان است، متن فرق دارد) ───
    @Test fun colonStyleBankFormat() {
        val r = CardRuleParser.runRules(listOf(melatRule()),
            "واریز به کارت 5678\nمبلغ: 7,700,000 ریال\nمانده: 87,500,000 ریال\nتاریخ: 1405/07/01\nکد پیگیری: 987654321")
        assertTrue(r.ok)
        assertEquals(7_700_000L, r.amountRial)
        assertEquals("5678", r.fields["card_last4"])
    }
    @Test fun enDigitsAndDotThousands() {
        val rule = CardRule(id = 1, name = "r", priority = 10, rule = Rule(fields = listOf(
            FieldDef("txn_type", "(واریز|برداشت)"),
            FieldDef("amount", "[0-9][0-9,\\.]{1,18}[0-9]", before = "Amount: ", transforms = listOf("strip_separators")),
        )))
        val r = CardRuleParser.runRules(listOf(rule), "واریز\nAmount: 12.500.000 RLS")
        assertTrue(r.ok)
        assertEquals(12_500_000L, r.amountRial)
    }

    // ─── امنیت: قانون مخرب/نامعتبر ───
    @Test fun badRuleJsonRejected() {
        org.junit.Assert.assertNull(CardRuleParser.parseRule("not json"))
        // parseRule null برمی‌گرداند؟ در پیاده‌سازی فعلی null → تست رفتار:
        val bad = CardRuleParser.parseRule("{")
        assertTrue(bad == null || bad.fields.isEmpty())
    }
    @Test fun oversizeSmsRejected() {
        assertFalse(CardRuleParser.runRules(listOf(melatRule()), "مبلغ 100 ریال " + "x".repeat(2000)).ok)
    }

    // ─── اثر انگشت ضدتکرار ───
    @Test fun fingerprintDeterministic() {
        val f1 = CardRuleParser.fingerprint(7, "30001234", 1000L, "REF1", "hash1")
        val f2 = CardRuleParser.fingerprint(7, "+9830001234", 1000L, "REF1", "hash1") // فرستانه نرمال = همان
        assertEquals(f1, f2)
        assertEquals(f1, CardRuleParser.fingerprint(7, "30001234", 1000L, "REF1", "hash1"))
    }
    @Test fun fingerprintDiffers() {
        val f1 = CardRuleParser.fingerprint(7, "30001234", 1000L, "REF1", "hash1")
        assertFalse(f1 == CardRuleParser.fingerprint(8, "30001234", 1000L, "REF1", "hash1")) // کارت دیگر
        assertFalse(f1 == CardRuleParser.fingerprint(7, "30001234", 2000L, "REF1", "hash1")) // مبلغ دیگر
        assertFalse(f1 == CardRuleParser.fingerprint(7, "30001234", 1000L, "REF2", "hash1")) // مرجع دیگر
        assertFalse(f1 == CardRuleParser.fingerprint(7, "30001234", 1000L, "REF1", "hash2")) // متن دیگر
    }
}

// ─── دور ۶: دقت پارسر (هم‌ترازی با PHP) ───
class CardRuleParserAccuracyTest {

    /** قانون با انکرهای بدون رقم — مثل خروجی buildRule سرور پس از اصلاحات دور ۶ */
    private fun accurateRule(): CardRuleParser.CardRule = CardRuleParser.CardRule(
        id = 1, name = "دقیق", priority = 10,
        rule = CardRuleParser.Rule(
            v = 1, currency_default = "IRR",
            fields = listOf(
                CardRuleParser.FieldDef("txn_type", "(واریز|برداشت|خرید|پرداخت|انتقال)", after = "به کارت"),
                CardRuleParser.FieldDef("card_last4", "[0-9*][0-9* \\-]{2,28}[0-9*]", before = "به کارت", after = "مبلغ", transforms = listOf("last4")),
                CardRuleParser.FieldDef("amount", "[0-9][0-9,\\.]{1,18}[0-9]", before = "مبلغ", after = "ریال", transforms = listOf("strip_separators")),
                CardRuleParser.FieldDef("balance", "[0-9][0-9,\\.]{1,18}[0-9]", before = "ریال\nمانده قابل اس", after = "ریال\nتاریخ", transforms = listOf("strip_separators")),
                CardRuleParser.FieldDef("date", "[0-9]{4}/[0-9]{1,2}/[0-9]{1,2}", before = "تاریخ"),
                CardRuleParser.FieldDef("time", "[0-9]{1,2}:[0-9]{2}", before = "ساعت"),
                CardRuleParser.FieldDef("reference", "[0-9]{3,20}", before = "رهگیری"),
            ),
            required = listOf("amount"),
        ),
    )

    @Test fun fullCardNumberYieldsLast4() {
        val r = CardRuleParser.runRules(listOf(accurateRule()),
            "واریز به کارت 6219-8612-3456-7856\nمبلغ: 25,000,000 ریال\nمانده قابل استفاده: 87,500,000 ریال\nتاریخ 1405/06/29 ساعت 14:32\nکد رهگیری 123456")
        assertTrue(r.ok)
        assertEquals("7856", r.fields["card_last4"])
        assertEquals(25_000_000L, r.amountRial)
        assertEquals("87500000", r.fields["balance"])// جداکننده‌ها با strip_separators حذف می‌شوند
    }

    @Test fun maskedCardYieldsLast4() {
        val r = CardRuleParser.runRules(listOf(accurateRule()),
            "واریز به کارت 6219-86**-****-7856\nمبلغ 100,000 ریال")
        assertTrue(r.ok)
        assertEquals("7856", r.fields["card_last4"])
    }

    @Test fun builtOnA_runsOnB_differentDigitsAndFormat() {
        // B: بدون دونقطه، ارقام متفاوت، کارت متفاوت
        val r = CardRuleParser.runRules(listOf(accurateRule()),
            "واریز به کارت 5859-8711-2244-9017\nمبلغ 8,450,000 ریال\nمانده قابل استفاده 12,300,000 ریال\nتاریخ 1405/07/01 ساعت 09:05\nکد رهگیری 88776655")
        assertTrue(r.ok)
        assertEquals("9017", r.fields["card_last4"])
        assertEquals(8_450_000L, r.amountRial)
        assertEquals("12300000", r.fields["balance"]!!.replace(Regex("[^0-9]"), ""))
        assertEquals("88776655", r.fields["reference"])
        assertEquals("1405/07/01", r.fields["date"])
    }

    @Test fun colonAfterAnchorTolerated() {
        // انکر «مبلغ» + «: » بین انکر و مقدار
        val r = CardRuleParser.runRules(listOf(accurateRule()), "واریز به کارت 1111\nمبلغ: 3,000 ریال")
        assertTrue(r.ok)
        assertEquals(3_000L, r.amountRial)
    }

    @Test fun anchoredRuleBeatsLooseBareRule() {
        val loose = CardRuleParser.CardRule(id = 9, name = "گشاد", priority = 1, rule = CardRuleParser.Rule(
            fields = listOf(CardRuleParser.FieldDef("amount", "[0-9][0-9,\\.]{1,18}[0-9]", transforms = listOf("strip_separators"))),
        ))
        val msg = "واریز به کارت 1111\nمبلغ 5,000 ریال\nمانده 9,000 ریال"
        val r = CardRuleParser.runRules(listOf(loose, accurateRule()), msg)
        assertTrue(r.ok)
        assertEquals("دقیق", r.matchedRule?.name) // انکرشده مقدم بر گشاد با اولویت بهتر
    }

    @Test fun tomanOnRialBuiltRuleDetectedFromText() {
        val r = CardRuleParser.runRules(listOf(accurateRule()), "واریز به کارت 1111\nمبلغ 2,000,000 تومان")
        assertTrue(r.ok)
        assertEquals("IRT", r.currency)
        assertEquals(20_000_000L, r.amountRial)
    }

    // ─── دور ۷: حروف عربی ي/ك (فرمت بانک ملی) ───
    @Test fun arabicYehKafNormalized() {
        assertEquals("بانک ملی ایران", CardRuleParser.normalizeText("بانك ملي ايران"))
    }

    // ─── دور ۹: بانک ملی واقعی — مبلغ فقط با علامت + و انکر بعدی ───
    private fun melliRule(): CardRule = CardRule(
        id = 7, name = "ملی — قالب ۱", priority = 10,
        rule = Rule(
            v = 1, currency_default = "IRR",
            fields = listOf(
                FieldDef("amount", "[0-9][0-9,\\.]{0,18}[0-9]", before = "", after = "+\n\nحساب"),
                FieldDef("balance", "[0-9][0-9,\\.]{0,18}[0-9]", before = "مانده:"),
            ),
            required = listOf("amount"),
        ),
    )

    @Test fun melliCreditWithPlusSign() {
        val r = CardRuleParser.runRules(listOf(melliRule()),
            "بانك ملي ايران\n\n111,798,088+\n\nحساب:68001\n\nمانده:153,175,934\n\n0630-13:17")
        assertTrue(r.ok)
        assertEquals(111_798_088L, r.amountRial)
    }

    @Test fun melliDebitWithMinusSignNotAmount() {
        val r = CardRuleParser.runRules(listOf(melliRule()),
            "بانك ملي ايران\n\n45,000-\n\nحساب:68001\n\nمانده:153,130,934\n\n0701-09:42")
        // علامت − : انکرِ «+» نمی‌گیرد و حدس آزاد برای نوع عددی ممنوع است → رد
        assertFalse(r.ok)
    }

    @Test fun withdrawalStillRejectedViaBareKeyword() {
        // txn_type کلیدواژه‌ای است: حدس آزاد مجاز → «برداشت» گرفته می‌شود → رد
        // (انکرِ «به کارت» در «از کارت» نمی‌گیرد، اما bare برای نوع کلیدواژه‌ای مجاز است)
        val r = CardRuleParser.runRules(listOf(accurateRule()),
            "برداشت از کارت 1234\nمبلغ 1,000,000 ریال\nمانده 2,000,000 ریال\nتاریخ 1405/07/03 ساعت 12:00")
        assertFalse(r.ok)
    }
}
