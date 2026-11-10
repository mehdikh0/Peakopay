package ir.peakopay.app.work

import android.app.NotificationManager
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.app.NotificationCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import ir.peakopay.app.PeakopayApp
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.ApiException
import ir.peakopay.app.db.AppDb
import ir.peakopay.app.db.TxRecordEntity
import kotlinx.coroutines.flow.first
import java.util.concurrent.TimeUnit
import android.Manifest
import android.os.Build
import androidx.core.content.ContextCompat

/**
 * ارسال صف تراکنش‌ها به سرور پیکو پی.
 *
 * استراتژی:
 *  - دسته‌های حداکثر ۲۵تایی (حد سرور)؛
 *  - تلاش مجدد نمایی (اینترنت قطع/خطای سرور)؛
 *  - نتیجه هر آیتم در تاریخچه ثبت می‌شود و اعلان کوتاه برای تطبیق موفق؛
 *  - اگر دستگاه pending باشد صف دست‌نخورده می‌ماند (پس از تأیید مدیر ارسال می‌شود).
 */
class UploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val prefs = Prefs.get(applicationContext)
        if (!prefs.isPaired) return Result.success()

        val db = AppDb.get(applicationContext)
        val outbox = db.outboxDao()
        val records = db.txRecordDao()

        val api = Api(prefs.serverUrl)

        // آیتم‌هایی که بعد از ۱۰ تلاش مانده‌اند حداکثر یک‌بار در روز دوباره
        // واجد شرط می‌شوند (حذف نمی‌شوند — فقط تلاش‌ها فاصله می‌گیرند).
        val staleCutoff = System.currentTimeMillis() - 24L * 3600 * 1000

        while (true) {
            val batch = outbox.takeBatch(staleCutoff)
            if (batch.isEmpty()) break

            if (batch.any { it.status == ir.peakopay.app.db.OutboxStatus.PENDING_OFFLINE }) {
                ir.peakopay.app.util.OfflineLog.syncStarted(batch.size)
            }

            try {
                val response = api.uploadTransactions(
                    prefs.deviceId,
                    prefs.deviceSecret,
                    batch.map {
                        Api.TxUpload(
                            bank = it.bank,
                            sender = it.sender,
                            amount_rial = it.amount_rial,
                            occurred_at = it.occurred_at,
                            dest_last4 = it.dest_last4,
                            reference = it.reference,
                            raw_text = it.raw_text,
                            rawHash = it.raw_hash,
                            card_id = it.card_id,
                            fingerprint = it.fingerprint,
                        )
                    },
                )

                for (r in response.results) {
                    val item = batch.getOrNull(r.index) ?: continue
                    // برای لاگ: این آیتم از حالت آفلاین می‌آمد؟
                    val wasOffline = item.status == ir.peakopay.app.db.OutboxStatus.PENDING_OFFLINE
                    when (r.result) {
                        "matched", "matched_pending" -> {
                            outbox.markSent(item.id)
                            records.insert(
                                TxRecordEntity(
                                    outboxId = item.id,
                                    bank = item.bank,
                                    amount_rial = item.amount_rial,
                                    reference = item.reference,
                                    result = r.result,
                                    order_id = r.order_id,
                                    payment_status = r.payment_status,
                                ),
                            )
                            notifyMatched(item.amount_rial, r.order_id)
                            if (wasOffline) ir.peakopay.app.util.OfflineLog.syncSuccess(item.id, r.result)
                        }
                        "duplicate" -> {
                            // قبلاً ثبت شده — موفق. سرور با اثر انگشت یکسان این را
                            // تشخیص می‌دهد و هرگز پرداخت دوباره‌ای ایجاد نمی‌شود.
                            outbox.markSent(item.id)
                            if (wasOffline) ir.peakopay.app.util.OfflineLog.syncSuccess(item.id, "duplicate")
                        }
                        "unmatched", "received" -> {
                            outbox.markSent(item.id)
                            records.insert(
                                TxRecordEntity(
                                    outboxId = item.id,
                                    bank = item.bank,
                                    amount_rial = item.amount_rial,
                                    reference = item.reference,
                                    result = r.result,
                                    order_id = r.order_id,
                                    payment_status = r.payment_status,
                                    // «چرا تطبیق نخورد» — دلیل فارسی سرور (اگر باشد)
                                    message = r.message,
                                ),
                            )
                            if (wasOffline) ir.peakopay.app.util.OfflineLog.syncSuccess(item.id, r.result)
                        }
                        "rejected" -> {
                            outbox.markRejected(item.id, r.message ?: "رد شد")
                            if (wasOffline) ir.peakopay.app.util.OfflineLog.syncFailed("rejected: " + (r.message ?: ""))
                        }
                        else -> outbox.markAttempt(item.id, ir.peakopay.app.db.OutboxStatus.FAILED, r.message ?: "نتیجه نامشخص", System.currentTimeMillis())
                    }
                }
                prefs.lastSyncAt = System.currentTimeMillis()
                prefs.lastSyncError = ""
            } catch (e: ApiException) {
                val reason = e.message?.take(140) ?: "خطای نامشخص"
                prefs.lastSyncError = "همگام‌سازی: " + reason
                val retryable = when {
                    // دستگاه هنوز تأیید نشده — صف می‌ماند، بعداً دوباره
                    e.code == "device_pending" || e.httpStatus == 403 -> true
                    // خطای شبکه/سرور — backoff نمایی
                    e.code == "network" || e.code == "bad_response" || e.httpStatus >= 500 -> true
                    else -> false
                }
                if (retryable) {
                    // شکست «موقت»: تراکنش‌ها در صف می‌مانند. آفلاین‌ها طبق مشخصات
                    // pending_offline می‌مانند؛ retry_count (attempts) و
                    // last_attempt_at ثبت می‌شوند. هیچ چیزی حذف نمی‌شود.
                    val now = System.currentTimeMillis()
                    for (item in batch) {
                        outbox.markAttempt(item.id, ir.peakopay.app.db.OutboxStatus.afterTempFailure(item.status), reason, now)
                    }
                    ir.peakopay.app.util.OfflineLog.syncRetry(
                        batch.size,
                        (batch.maxOfOrNull { it.attempts } ?: 0) + 1,
                        reason,
                    )
                    return Result.retry()
                }
                // رد قطعی (امضا/احراز) — تلاش بی‌فایده است
                ir.peakopay.app.util.OfflineLog.syncFailed(reason)
                return Result.failure()
            }
        }

        // پاک‌سازی تاریخچه قدیمی (بیش از ۳۰ روز)
        records.purgeOlderThan(System.currentTimeMillis() - 30L * 24 * 3600 * 1000)

        return Result.success()
    }

    private fun notifyMatched(amountRial: Long, orderId: String?) {
        if (!notificationAllowed()) return
        val nm = applicationContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val title = applicationContext.getString(R.string.notification_matched)
        val text = if (orderId != null) {
            "سفارش #$orderId — ${ir.peakopay.app.util.Fa.amountLabel(amountRial)}"
        } else {
            ir.peakopay.app.util.Fa.amountLabel(amountRial)
        }
        val notification = NotificationCompat.Builder(applicationContext, PeakopayApp.CHANNEL_SMS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(text)
            .setAutoCancel(true)
            .build()
        nm.notify(("match_" + System.currentTimeMillis()).hashCode(), notification)
    }

    private fun notificationAllowed(): Boolean =
        Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    companion object {
        fun constraints() = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        /** اجرای فوری (بعد از دریافت پیامک). */
        fun enqueueNow(context: Context) {
            WorkManager.getInstance(context).enqueueUniqueWork(
                "upload_now",
                ExistingWorkPolicy.APPEND_OR_REPLACE,
                OneTimeWorkRequestBuilder<UploadWorker>()
                    .setConstraints(constraints())
                    .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                    .build(),
            )
        }
    }
}
