package ir.peakopay.app

import ir.peakopay.app.db.OutboxStatus
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * ماشین وضعیت صف ارسال — دور ۱۵ (تراکنش آفلاین).
 *
 * این تست‌ها تضمین می‌کنند که:
 *  - وضعیت‌های قبلی (سطرهای قدیمی دیتابیس) تغییری نکرده‌اند؛
 *  - شکست «موقت» ارسال، تراکنشِ آفلاین را pending_offline نگه می‌دارد؛
 *  - جریان آنلاین همان رفتار قبلی (failed قابل تلاش مجدد) را دارد؛
 *  - حالت‌های پایانی هرگز دوباره ارسال نمی‌شوند.
 */
class OutboxStatusTest {

    // ── وضعیت‌های قبلی دست‌نخورده‌اند (تغییر نام = شکست سطرهای قدیمی DB) ──
    @Test fun legacyStatesUnchanged() {
        assertEquals("pending", OutboxStatus.PENDING)
        assertEquals("sending", OutboxStatus.SENDING)
        assertEquals("sent", OutboxStatus.SENT)
        assertEquals("failed", OutboxStatus.FAILED)
        assertEquals("rejected", OutboxStatus.REJECTED)
    }

    // ── وضعیت جدید آفلاین ──
    @Test fun offlineStateIsNewAndDistinct() {
        assertEquals("pending_offline", OutboxStatus.PENDING_OFFLINE)
        assertFalse(OutboxStatus.PENDING_OFFLINE == OutboxStatus.PENDING)
    }

    // ── شکست موقت: تراکنش آفلاین در همان وضعیت می‌ماند (مشخصات دور ۱۵) ──
    @Test fun tempFailureKeepsOfflineState() {
        assertEquals(
            OutboxStatus.PENDING_OFFLINE,
            OutboxStatus.afterTempFailure(OutboxStatus.PENDING_OFFLINE),
        )
    }

    // ── شکست موقت: جریان آنلاین مثل قبل failed می‌شود (قابل تلاش مجدد) ──
    @Test fun tempFailureOnlinePathUsesFailed() {
        assertEquals(OutboxStatus.FAILED, OutboxStatus.afterTempFailure(OutboxStatus.PENDING))
        assertEquals(OutboxStatus.FAILED, OutboxStatus.afterTempFailure(OutboxStatus.FAILED))
        // حتی برای وضعیت‌های ناشناخته/قدیمی رفتار محافظه‌کارانه = failed قابل تلاش
        assertEquals(OutboxStatus.FAILED, OutboxStatus.afterTempFailure("sending"))
    }

    // ── وضعیت‌های قابل ارسال: هر سه وضعیت صف + نگه‌داشتن آفلاین پس از شکست ──
    @Test fun retryableSetPicksUpAllQueuedStates() {
        assertTrue(OutboxStatus.RETRYABLE.contains(OutboxStatus.PENDING))
        assertTrue(OutboxStatus.RETRYABLE.contains(OutboxStatus.PENDING_OFFLINE))
        assertTrue(OutboxStatus.RETRYABLE.contains(OutboxStatus.FAILED))
    }

    // ── حالت‌های پایانی هرگز دوباره ارسال نمی‌شوند (بدون تکرار پرداخت) ──
    @Test fun terminalStatesNeverRetried() {
        assertFalse(OutboxStatus.RETRYABLE.contains(OutboxStatus.SENT))
        assertFalse(OutboxStatus.RETRYABLE.contains(OutboxStatus.REJECTED))
        assertFalse(OutboxStatus.RETRYABLE.contains(OutboxStatus.SENDING))
    }
}
