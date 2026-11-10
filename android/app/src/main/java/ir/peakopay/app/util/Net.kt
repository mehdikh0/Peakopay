package ir.peakopay.app.util

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities

/**
 * بررسی یک‌باره اتصال اینترنت (رویدادمحور — هیچ polling شبکه‌ای انجام نمی‌شود).
 *
 * همگام‌سازی خودکار صف با محدودیت NetworkType.CONNECTED در WorkManager انجام
 * می‌شود (به‌محض وصل‌شدن اینترنت اجرا می‌شود)؛ این بررسی فقط برای «برچسب‌گذاری»
 * وضعیت هنگام رخداد (دریافت پیامک / رندر کارت صف) است.
 */
object Net {
    fun isOnline(context: Context): Boolean {
        return try {
            val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
                ?: return false
            val network = cm.activeNetwork ?: return false
            val caps = cm.getNetworkCapabilities(network) ?: return false
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } catch (e: Exception) {
            // محافظ‌شده: هر خطای سیستم اتصال = آفلاین فرض می‌شود (تراکنش در صف
            // pending_offline می‌ماند و WorkManager بعداً ارسال می‌کند)
            false
        }
    }
}
