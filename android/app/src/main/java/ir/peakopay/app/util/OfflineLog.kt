package ir.peakopay.app.util

import android.util.Log

/**
 * لاگ‌های خط لوله تراکنش آفلاین (logcat، تگ PeakopayOffline).
 *
 * هیچ داده حساس بانکی لاگ نمی‌شود: نه متن پیامک، نه شماره کارت، نه مرجع تراکنش،
 * نه هیچ کلیدی — فقط شناسه محلی، بانک، مبلغ و دلیل خطا (کوتاه‌شده).
 */
object OfflineLog {
    private const val TAG = "PeakopayOffline"

    /** تراکنش بدون اینترنت در صف محلی ذخیره شد. */
    fun transactionSaved(localId: Long, bank: String, amountRial: Long) {
        Log.i(TAG, "OFFLINE_TRANSACTION_SAVED id=$localId bank=$bank amount=$amountRial")
    }

    /** پیامک تکراری (گیرنده دوبار شلیک شد/ری‌استارت) — فقط یک تراکنش ساخته می‌شود. */
    fun duplicateIgnored() {
        Log.i(TAG, "OFFLINE_DUPLICATE_IGNORED")
    }

    /** همگام‌سازی صف آفلاین شروع شد. */
    fun syncStarted(count: Int) {
        Log.i(TAG, "OFFLINE_SYNC_STARTED count=$count")
    }

    /** یک تراکنش آفلاین با موفقیت همگام شد (نتیجه سرور: matched/duplicate/…). */
    fun syncSuccess(localId: Long, result: String) {
        Log.i(TAG, "OFFLINE_SYNC_SUCCESS id=$localId result=$result")
    }

    /** شکست موقت (شبکه/سرور) — در صف می‌ماند و دوباره تلاش می‌شود. */
    fun syncRetry(count: Int, attempts: Int, reason: String) {
        Log.w(TAG, "OFFLINE_SYNC_RETRY count=$count attempts=$attempts reason=${reason.take(120)}")
    }

    /** شکست قطعی (مثلاً رد احراز هویت/امضا از سمت سرور). */
    fun syncFailed(reason: String) {
        Log.e(TAG, "OFFLINE_SYNC_FAILED reason=${reason.take(120)}")
    }
}
