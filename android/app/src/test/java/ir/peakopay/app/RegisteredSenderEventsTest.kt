package ir.peakopay.app

import ir.peakopay.app.data.Prefs.PipelineEvent
import ir.peakopay.app.ui.diagnostics.registeredSenderEvents
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * فیلتر «رخدادهای اخیر» صفحه عیب‌یابی — فقط فرستنده‌های ثبت‌شده در قالب کارت‌ها
 * به‌عنوان شماره ارسال‌کننده بانک نمایش داده می‌شوند (حریم خصوصی — دور ۲۱).
 */
class RegisteredSenderEventsTest {

    private fun ev(sender: String, kind: String = "queued") =
        PipelineEvent(ts = 1L, kind = kind, sender = sender, detail = "")

    @Test fun unregisteredSenderIsFilteredOut() {
        val out = registeredSenderEvents(
            listOf(ev("+989121234567"), ev("30001234")),
            listOf("30001234"),
        )
        assertEquals(listOf(ev("30001234")), out)
    }

    @Test fun persianDigitsAndPrefixesNormalizeBeforeMatch() {
        val out = registeredSenderEvents(
            listOf(ev("۳۰۰۰۱۲۳۴"), ev("+9830001234"), ev("009830001234")),
            listOf("30001234"),
        )
        assertEquals(3, out.size)
    }

    @Test fun noSubstringMatching() {
        // «300012» ثبت‌شده است ولی «30001234» نیست → فیلتر می‌شود
        val out = registeredSenderEvents(listOf(ev("30001234")), listOf("300012"))
        assertEquals(emptyList<PipelineEvent>(), out)
    }

    @Test fun emptyRegisteredListShowsNothing() {
        // قالب/کارتی ثبت نشده → هیچ رخدادی نمایش داده نمی‌شود
        assertEquals(
            emptyList<PipelineEvent>(),
            registeredSenderEvents(listOf(ev("30001234"), ev("Bank")), emptyList()),
        )
    }

    @Test fun legacyIgnoredSenderEventsOfNowRegisteredSenderKept() {
        // رخداد قدیمی از نسخه قبل — اگر فرستنده الان ثبت‌شده باشد مشکلی ندارد
        val out = registeredSenderEvents(
            listOf(ev("30001234", kind = "ignored_sender"), ev("99999", kind = "ignored_sender")),
            listOf("30001234"),
        )
        assertEquals(listOf(ev("30001234", kind = "ignored_sender")), out)
    }
}
