package ir.peakopay.app.work

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.NetworkType
import androidx.work.WorkerParameters
import ir.peakopay.app.data.CardConfigStore
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api

/**
 * ضربان دوره‌ای: اطلاع‌رسانی «دستگاه زنده است» به سرور + همگام‌سازی وضعیت
 * (مثلاً مدیر دستگاه را تأیید یا غیرفعال کرده باشد).
 */
class HeartbeatWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val prefs = Prefs.get(applicationContext)
        if (!prefs.isPaired) return Result.success()

        return try {
            val api = Api(prefs.serverUrl)
            val hb = api.heartbeat(prefs.deviceId, prefs.deviceSecret)
            prefs.deviceStatus = hb.device_status
            if (hb.store.name.isNotEmpty()) prefs.storeName = hb.store.name
            prefs.lastSyncAt = System.currentTimeMillis()
            // سینک پیکربندی کارت‌ها/فرستنده‌ها/قواعد پارسر وقتی سرور نسخه جدید دارد
            val remoteVer = hb.sms_config_version ?: 0L
            if (remoteVer > 0 && remoteVer != CardConfigStore.localVersion(applicationContext)) {
                try {
                    val cfg = api.deviceConfig(prefs.deviceId, prefs.deviceSecret)
                    CardConfigStore.save(applicationContext, CardConfigStore.fromApi(cfg))
                } catch (e: Exception) {
                    // سینک بعدی (ضربان بعدی) دوباره می‌کوشد — پردازش پیامک با کش قبلی ادامه می‌یابد
                }
            }
            // اگر دستگاه فعال شد، صف معلق را بلافاصله تخلیه کن
            if (hb.device_status == "active") UploadWorker.enqueueNow(applicationContext)
            Result.success()
        } catch (e: Exception) {
            Result.retry() // اینترنت/سرور — دفعه بعد
        }
    }

    companion object {
        fun constraints() = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        /**
         * ضربان فوری (یک‌بارمصرف): وقتی پیامکی به دلیل فرستنده/قالبِ ناشناخته
         * نادیده گرفته شد صدا زده می‌شود — احتمالاً پیکربندی کش‌شده قدیمی است
         * (ادمین فرستنده/قالب تازه ساخته و هنوز به گوشی نرسیده).
         */
        fun enqueueNow(context: Context) {
            androidx.work.WorkManager.getInstance(context).enqueueUniqueWork(
                "heartbeat_now",
                androidx.work.ExistingWorkPolicy.KEEP,
                androidx.work.OneTimeWorkRequestBuilder<HeartbeatWorker>()
                    .setConstraints(constraints())
                    .build(),
            )
        }
    }
}
