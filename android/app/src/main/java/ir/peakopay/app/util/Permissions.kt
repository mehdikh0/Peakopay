package ir.peakopay.app.util

import android.Manifest
import android.app.Activity
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

/**
 * ممیزی و مدیریت مجوزها/تنظیمات پس‌زمینه — همه در یک جا.
 *
 * اصل: حداقلِ دسترسی. فقط RECEIVE_SMS (پیامک بانکی)، POST_NOTIFICATIONS (اعلان)
 * و تنظیمات رسمی باتری/پس‌زمینه. هیچ مجوز نامرتبطی (موقعیت، دوربین، مخاطبین،
 * Accessibility، Device Admin) درخواست نمی‌شود.
 */
object Permissions {

    const val SMS = Manifest.permission.RECEIVE_SMS
    const val NOTIFICATIONS = Manifest.permission.POST_NOTIFICATIONS

    /** وضعیت یک مجوز خطرناک: داده‌شده / رد قطعی (دیگر پرسیده نمی‌شود) */
    data class State(val granted: Boolean, val permanentlyDenied: Boolean)

    fun sms(context: Context): State = state(context, SMS, PrefsHack.smsAsked(context))

    /**
     * اعلان‌ها: قبل از اندروید ۱۳ مجوز جداگانه وجود ندارد (فقط تنظیم سیستمی)؛
     * کنترل واقعی روی ۱۳+ انجام می‌شود.
     */
    fun notifications(context: Context): State {
        if (Build.VERSION.SDK_INT < 33) return State(granted = true, permanentlyDenied = false)
        return state(context, NOTIFICATIONS, PrefsHack.notifAsked(context))
    }

    private fun state(context: Context, perm: String, askedBefore: Boolean): State {
        val granted = ContextCompat.checkSelfPermission(context, perm) == PackageManager.PERMISSION_GRANTED
        if (granted) return State(true, false)
        val activity = context as? Activity
        val rationale = activity?.let {
            ActivityCompat.shouldShowRequestPermissionRationale(it, perm)
        } ?: false
        // بعد از اولین رد + نبود rationale => «دیگر نپرس» — فقط باز کردن تنظیمات کمک می‌کند
        return State(false, askedBefore && !rationale)
    }

    /** آیا اپ از بهینه‌سازی باتری معاف است؟ (Unrestricted) */
    fun isIgnoringBatteryOptimizations(context: Context): Boolean {
        val pm = context.getSystemService(Context.POWER_SERVICE) as? PowerManager ?: return false
        return pm.isIgnoringBatteryOptimizations(context.packageName)
    }

    /** درخواست رسمی معافیت از بهینه‌سازی باتری (دیالوگ سیستمی اندروید) */
    fun requestIgnoreBatteryOptimizations(context: Context): Boolean = try {
        context.startActivity(
            Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + context.packageName))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
        true
    } catch (e: Exception) {
        false
    }

    /** فهرست «بهینه‌سازی باتری» سیستم (اگر دیالوگ مستقیم باز نشد) */
    fun openBatteryOptimizationList(context: Context): Boolean = try {
        context.startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        true
    } catch (e: Exception) {
        false
    }

    /** تنظیمات خود اپ (برای روشن‌کردن دستی مجوزها) */
    fun openAppSettings(context: Context) {
        context.startActivity(
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + context.packageName))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }

    /**
     * میانبر تنظیمات پس‌زمینه سازنده‌ها (به‌ترین تلاش — نام صفحه‌ها روی هر مدل فرق دارد).
     * خروجی: نام سازنده‌ای که صفحه‌اش باز شد یا null.
     */
    fun openOemBackgroundSettings(context: Context): String? {
        val intents = mapOf(
            "سامسونگ (محدودیت‌های پس‌زمینه)" to listOf(
                // Samsung Device care → Battery → Background usage limits
                Intent().setComponent(ComponentName("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity")),
                Intent().setComponent(ComponentName("com.samsung.android.sm_cn", "com.samsung.android.sm.battery.ui.BatteryActivity")),
            ),
            "شیائومی (خودراه‌اندازی)" to listOf(
                Intent().setComponent(ComponentName("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity")),
            ),
            "هواوی (محافظت از اپ)" to listOf(
                Intent().setComponent(ComponentName("com.huawei.systemmanager", "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity")),
            ),
            "OPPO/Realme (شروع خودکار)" to listOf(
                Intent().setComponent(ComponentName("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity")),
            ),
        )
        for ((label, candidates) in intents) {
            for (i in candidates) {
                try {
                    i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    context.startActivity(i)
                    return label
                } catch (e: Exception) {
                    // مدل بعدی
                }
            }
        }
        // بازگشت به تنظیمات عمومی باتری
        return if (openBatteryOptimizationList(context)) "بهینه‌سازی باتری سیستم" else null
    }

    /** ذخیره‌ی «اولین درخواست انجام شد» — برای تشخیص رد قطعی (بدون نشت به لایه UI) */
    object PrefsHack {
        fun smsAsked(context: Context): Boolean = ir.peakopay.app.data.Prefs.get(context).smsPermRequested
        fun notifAsked(context: Context): Boolean = ir.peakopay.app.data.Prefs.get(context).notifPermRequested
    }
}
