package ir.peakopay.app.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.util.Fa
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * سرویس همیشه‌روشن (اختیاری — از صفحه «وضعیت» قابل خاموش‌کردن است).
 *
 * چرا واقعاً لازم است: مشتری در صفحه پرداخت منتظر تأیید است؛ این سرویس فرایند اپ
 * را زنده نگه می‌دارد تا دریافت پیامک بانکی ← تطبیق ← تأیید سفارش در چند ثانیه
 * انجام شود حتی اگر سازنده گوشی (سامسونگ/شیائومی/…) فرایندهای پس‌زمینه را
 * به‌شدت محدود کند.
 *
 * نوع سرویس روی اندروید ۱۴+: specialUse (با توضیح subtype در منیفست).
 * بدون این سرویس هم اپ کار می‌کند (SMS broadcast + WorkManager)؛ فقط تأییدها
 * کندتر (تا ۱۵ دقیقه در بدترین حالت Doze) انجام می‌شوند.
 */
class PeakopayService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()
        isRunning = true
        createChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val prefs = Prefs.get(this)
        if (!prefs.isPaired || !prefs.serviceEnabled) {
            stopSelf()
            return START_NOT_STICKY
        }
        startInForeground(prefs.storeName.ifEmpty { "پیکو پی" }, "در حال اتصال…")
        heartbeatLoop()
        paymentsWatchLoop()
        return START_STICKY // اگر سیستم کشت، دوباره بساز (recovery)
    }

    /**
     * پایش رویدادهای پرداخت (هر ۶۰ ثانیه): فاکتور جدید ← دینگ؛ تأیید ← دینگ دینگ؛
     * انقضا/ناموفق ← صدای متناسب. اولین اجرا فقط وضعیت را می‌خواند (بدون اعلان).
     */
    private fun paymentsWatchLoop() {
        scope.launch {
            while (isActive) {
                val prefs = Prefs.get(this@PeakopayService)
                if (!prefs.isPaired || !prefs.serviceEnabled) break
                try {
                    val events = Api(prefs.serverUrl).paymentEvents(prefs.deviceId, prefs.deviceSecret).events
                    if (events.isNotEmpty()) {
                        val seen = prefs.payEventsSeen()
                        if (!prefs.payEventsInit) {
                            events.forEach { seen[it.id.toString()] = it.status }
                            prefs.payEventsInit = true
                        } else {
                            for (e in events) {
                                val key = e.id.toString()
                                val old = seen[key]
                                if (old == null) {
                                    if (e.status == "pending") notifyEvent(e, "new")
                                    seen[key] = e.status
                                } else if (old != e.status) {
                                    when (e.status) {
                                        "paid" -> notifyEvent(e, "paid")
                                        "expired" -> notifyEvent(e, "expired")
                                        "failed" -> notifyEvent(e, "failed")
                                    }
                                    seen[key] = e.status
                                }
                            }
                        }
                        prefs.setPayEventsSeen(seen)
                    }
                    prefs.lastSyncAt = System.currentTimeMillis()
                } catch (e: Exception) {
                    // شبکه/سرور — چرخه بعدی دوباره می‌کوشد
                }
                delay(60_000L)
            }
        }
    }

    private fun notifyEvent(e: ir.peakopay.app.data.api.Api.PayEvent, kind: String) {
        val ctx = applicationContext
        if (Build.VERSION.SDK_INT >= 33 &&
            androidx.core.content.ContextCompat.checkSelfPermission(ctx, android.Manifest.permission.POST_NOTIFICATIONS) !=
            android.content.pm.PackageManager.PERMISSION_GRANTED
        ) return
        val suffix = if (e.is_test) " " + ctx.getString(ir.peakopay.app.R.string.test_suffix) else ""
        val (channel, title, text) = when (kind) {
            "new" -> Triple(
                ir.peakopay.app.PeakopayApp.CHANNEL_PAY_NEW,
                ctx.getString(ir.peakopay.app.R.string.notif_new_payment),
                "سفارش #" + Fa.digits(e.order_id) + " — " + Fa.amountLabel(e.amount_rial) + suffix,
            )
            "paid" -> Triple(
                ir.peakopay.app.PeakopayApp.CHANNEL_PAY_PAID,
                ctx.getString(ir.peakopay.app.R.string.notif_paid),
                "سفارش #" + Fa.digits(e.order_id) + " — " + Fa.amountLabel(e.amount_rial) + suffix,
            )
            "expired" -> Triple(
                ir.peakopay.app.PeakopayApp.CHANNEL_PAY_EXPIRED,
                ctx.getString(ir.peakopay.app.R.string.notif_expired),
                "سفارش #" + Fa.digits(e.order_id) + " — " + Fa.amountLabel(e.amount_rial) + suffix,
            )
            else -> Triple(
                ir.peakopay.app.PeakopayApp.CHANNEL_PAY_FAILED,
                ctx.getString(ir.peakopay.app.R.string.notif_failed),
                "سفارش #" + Fa.digits(e.order_id) + suffix,
            )
        }
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val n = NotificationCompat.Builder(this, channel)
            .setSmallIcon(ir.peakopay.app.R.drawable.ic_notification)
            .setContentTitle(title + suffix)
            .setContentText(text)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()
        // شناسه یکتا به‌ازای پرداخت+نوع — بدون اعلان تکراری
        nm.notify(("pay_" + e.id + "_" + kind).hashCode(), n)
    }

    private fun heartbeatLoop() {
        scope.launch {
            var failure = 0
            while (isActive) {
                val prefs = Prefs.get(this@PeakopayService)
                if (!prefs.isPaired || !prefs.serviceEnabled) break
                try {
                    val api = Api(prefs.serverUrl)
                    val hb = api.heartbeat(prefs.deviceId, prefs.deviceSecret)
                    prefs.deviceStatus = hb.device_status
                    if (hb.store.name.isNotEmpty()) prefs.storeName = hb.store.name
                    prefs.lastSyncAt = System.currentTimeMillis()
                    prefs.lastSyncError = ""
                    failure = 0
                    val statusText = when (hb.device_status) {
                        "active" -> "متصل و فعال ✓ (آخرین همگام‌سازی: " + Fa.timeLabel(prefs.lastSyncAt) + ")"
                        "pending" -> "در انتظار تأیید مدیر…"
                        else -> "غیرفعال — از پنل بررسی کنید"
                    }
                    updateNotification(prefs.storeName.ifEmpty { "پیکو پی" }, statusText)
                } catch (e: Exception) {
                    failure++
                    prefs.lastSyncError = "سرویس: " + (e.message?.take(120) ?: "خطای نامشخص")
                    updateNotification(
                        prefs.storeName.ifEmpty { "پیکو پی" },
                        "قطع از سرور — تلاش مجدد شماره " + Fa.digits(failure.toString()) + "…",
                    )
                }
                delay(if (failure == 0) 90_000L else 30_000L)
            }
            stopSelf()
        }
    }

    private fun startInForeground(title: String, text: String) {
        val n = buildNotification(title, text)
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIF_ID, n)
        }
    }

    private fun updateNotification(title: String, text: String) {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.notify(NOTIF_ID, buildNotification(title, text))
    }

    private fun buildNotification(title: String, text: String): Notification =
        NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(text)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .build()

    private fun createChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val ch = NotificationChannel(
                CHANNEL,
                "سرویس پیکو پی",
                NotificationManager.IMPORTANCE_LOW,
            ).apply { description = "وضعیت همیشگی اتصال برای دریافت سریع تراکنش‌ها" }
            nm.createNotificationChannel(ch)
        }
    }

    override fun onDestroy() {
        isRunning = false
        scope.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    companion object {
        const val CHANNEL = "peakopay_service"
        const val NOTIF_ID = 200

        @Volatile
        var isRunning: Boolean = false
            private set

        /** شروع امن سرویس (خودش بررسی می‌کند جفت/فعال/روشن باشد؛ در onStartCommand هم گارد هست) */
        fun start(context: Context) {
            val prefs = Prefs.get(context)
            if (!prefs.isPaired || !prefs.serviceEnabled) return
            if (isRunning) return
            try {
                ContextCompat.startForegroundService(context, Intent(context, PeakopayService::class.java))
            } catch (e: Exception) {
                // مثلاً اپ در پس‌زمینه روی برخی اندرویدها — WorkManager همچنان کار می‌کند
            }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, PeakopayService::class.java))
        }
    }
}
