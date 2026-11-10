package ir.peakopay.app

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import androidx.work.Configuration
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.db.AppDb
import ir.peakopay.app.work.HeartbeatWorker
import ir.peakopay.app.work.UploadWorker
import java.util.concurrent.TimeUnit

/**
 * پیکو پی — کلاس اپلیکیشن.
 *
 * وابستگی‌ها به‌صورت سبک (بدون DI فریم‌ورک) از طریق singleton های اپ در دسترس‌اند.
 */
class PeakopayApp : Application(), Configuration.Provider {

    override fun onCreate() {
        super.onCreate()
        instance = this
        createNotificationChannels()
        scheduleBackgroundWork()
    }

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder().build()

    private fun createNotificationChannels() {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_SMS,
            getString(R.string.channel_sms),
            NotificationManager.IMPORTANCE_LOW,
        ).apply { description = getString(R.string.channel_sms_desc) }
        nm.createNotificationChannel(channel)

        // کانال‌های رویداد پرداخت — هر کدام صدای خودشان (دینگ برای فاکتور جدید/
        // پرداخت موفق، نُت نزولی برای انقضا، باس کوتاه برای ناموفق)
        val attrs = android.media.AudioAttributes.Builder()
            .setUsage(android.media.AudioAttributes.USAGE_NOTIFICATION)
            .setContentType(android.media.AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        fun soundChannel(id: String, nameRes: Int, raw: Int) {
            val ch = NotificationChannel(id, getString(nameRes), NotificationManager.IMPORTANCE_DEFAULT).apply {
                setSound(android.net.Uri.parse("android.resource://$packageName/$raw"), attrs)
                description = getString(nameRes)
            }
            nm.createNotificationChannel(ch)
        }
        soundChannel(CHANNEL_PAY_NEW, R.string.channel_pay_new, R.raw.notify_new)
        soundChannel(CHANNEL_PAY_PAID, R.string.channel_pay_paid, R.raw.notify_paid)
        soundChannel(CHANNEL_PAY_EXPIRED, R.string.channel_pay_expired, R.raw.notify_expired)
        soundChannel(CHANNEL_PAY_FAILED, R.string.channel_pay_failed, R.raw.notify_failed)
    }

    /** صف ارسال (هر ۱۵ دقیقه) + heartbeat (هر ۱۵ دقیقه) — فقط وقتی دستگاه جفت شده باشد. */
    private fun scheduleBackgroundWork() {
        if (Prefs.get(this).isPaired) {
            val wm = WorkManager.getInstance(this)
            wm.enqueueUniquePeriodicWork(
                "upload_queue",
                ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<UploadWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(UploadWorker.constraints())
                    .build(),
            )
            wm.enqueueUniquePeriodicWork(
                "heartbeat",
                ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<HeartbeatWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(HeartbeatWorker.constraints())
                    .build(),
            )
        }
    }

    companion object {
        const val CHANNEL_SMS = "peakopay_sms"
        const val CHANNEL_PAY_NEW = "peakopay_pay_new"
        const val CHANNEL_PAY_PAID = "peakopay_pay_paid"
        const val CHANNEL_PAY_EXPIRED = "peakopay_pay_expired"
        const val CHANNEL_PAY_FAILED = "peakopay_pay_failed"

        @Volatile
        private var instance: PeakopayApp? = null

        fun app(): PeakopayApp = requireNotNull(instance)

        fun db(context: Context): AppDb = AppDb.get(context)
    }
}
