package ir.peakopay.app.work

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import ir.peakopay.app.data.Prefs
import java.util.concurrent.TimeUnit

/** پس از ری‌استارت گوشی، کارهای پس‌زمینه دوباره زمان‌بندی می‌شوند. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        if (!Prefs.get(context).isPaired) return

        val wm = WorkManager.getInstance(context)
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
        UploadWorker.enqueueNow(context)
        // سرویس همیشه‌روشن هم (اگر روشن است) دوباره بالا بیاید — BOOT_COMPLETED
        // یکی از استثناهای مجاز شروع FGS در پس‌زمینه است.
        val prefs = Prefs.get(context)
        if (prefs.serviceEnabled && prefs.isActive) {
            ir.peakopay.app.service.PeakopayService.start(context)
        }
    }
}
