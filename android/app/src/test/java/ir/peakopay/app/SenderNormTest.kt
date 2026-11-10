package ir.peakopay.app

import ir.peakopay.app.sms.SenderNorm
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** نرمال‌سازی فرستنده — الگوریتم یکسان با سرور (PHP CardSmsEngine). */
class SenderNormTest {
    @Test fun bareServiceNumber() = assertEquals("30001234", SenderNorm.normalize("30001234"))
    @Test fun plus98ServiceNumber() = assertEquals("30001234", SenderNorm.normalize("+9830001234"))
    @Test fun doubleZeroServiceNumber() = assertEquals("30001234", SenderNorm.normalize("009830001234"))
    @Test fun landlineLeadingZero() = assertEquals("2112345678", SenderNorm.normalize("02112345678"))
    @Test fun landlinePlus98() = assertEquals("2112345678", SenderNorm.normalize("+982112345678"))
    @Test fun persianDigits() = assertEquals("30001234", SenderNorm.normalize("۳۰۰۰۱۲۳۴"))
    @Test fun dashesAndSpaces() = assertEquals("9121234567", SenderNorm.normalize("0912 123-4567"))
    @Test fun shortCodeUntouched() = assertEquals("5000", SenderNorm.normalize("5000"))
    @Test fun differentSendersNeverCollapse() {
        assertFalse(SenderNorm.normalize("30001234") == SenderNorm.normalize("30001235"))
        assertFalse(SenderNorm.normalize("2112345678") == SenderNorm.normalize("3112345678"))
    }
    @Test fun exactMatchOnly_noSubstring() {
        // "300012" نباید با "30001234" برابر بیفتد (بدون substring matching)
        assertFalse(SenderNorm.matches("30001234", listOf("300012")))
        assertTrue(SenderNorm.matches("+9830001234", listOf("30001234")))
        assertTrue(SenderNorm.matches("009830001234", listOf("30001234")))
        assertFalse(SenderNorm.matches("", listOf("30001234")))
    }
    @Test fun multipleSendersOneCard() {
        val allowed = listOf("30001234", "30005678", "2112345678")
        assertTrue(SenderNorm.matches("30001234", allowed))
        assertTrue(SenderNorm.matches("+9830005678", allowed))
        assertTrue(SenderNorm.matches("02112345678", allowed))
        assertFalse(SenderNorm.matches("99999", allowed))
    }
}
