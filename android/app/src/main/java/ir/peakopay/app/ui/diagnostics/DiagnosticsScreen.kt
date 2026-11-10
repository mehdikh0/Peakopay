package ir.peakopay.app.ui.diagnostics

import android.os.Build
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.data.CardConfigStore
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.service.PeakopayService
import ir.peakopay.app.sms.SenderNorm
import ir.peakopay.app.ui.theme.Danger
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.util.Fa
import ir.peakopay.app.util.Permissions

/**
 * فقط رخدادهایی که فرستنده‌شان در قالب کارت‌ها به‌عنوان «شماره ارسال‌کننده بانک»
 * ثبت شده است (مقایسه با همان نرمال‌سازی خط لوله پیامک). نسخه‌های جدید اصلاً
 * رخدادی برای شماره‌های دیگر ثبت نمی‌کنند؛ این فیلتر برای پاک‌سازی رخدادهای
 * قدیمیِ نسخه‌های قبل است (حریم خصوصی — دور ۲۱).
 */
internal fun registeredSenderEvents(
    events: List<Prefs.PipelineEvent>,
    registeredSenders: Collection<String>,
): List<Prefs.PipelineEvent> = events.filter { SenderNorm.matches(it.sender, registeredSenders) }

/**
 * صفحه عیب‌یابی — اطلاعات فنی برای پشتیبانی.
 * هیچ رازی (کلید دستگاه، توکن) و هیچ اطلاعات بانکی نمایش داده نمی‌شود.
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun DiagnosticsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)

    val appVersion = remember {
        try {
            val pi = context.packageManager.getPackageInfo(context.packageName, 0)
            (pi.versionName ?: "?") + " (" + (if (android.os.Build.VERSION.SDK_INT >= 28) pi.longVersionCode else pi.versionCode.toLong()) + ")"
        } catch (e: Exception) {
            "?"
        }
    }
    var battery by remember { mutableStateOf(Permissions.isIgnoringBatteryOptimizations(context)) }
    var sms by remember { mutableStateOf(Permissions.sms(context)) }
    var notif by remember { mutableStateOf(Permissions.notifications(context)) }
    // «رخدادهای اخیر» — فقط فرستنده‌های ثبت‌شده در قالب کارت‌ها نمایش داده می‌شوند
    val pipelineEvents = remember {
        registeredSenderEvents(
            prefs.pipelineEvents(),
            CardConfigStore.load(context).cards.flatMap { it.senders },
        )
    }

    val rows = listOf(
        "نسخه اندروید" to (Build.VERSION.RELEASE ?: "?") + " (API " + Build.VERSION.SDK_INT.toString() + ")",
        "سازنده / مدل دستگاه" to (Build.MANUFACTURER ?: "?") + " " + (Build.MODEL ?: ""),
        "نسخه اپ" to appVersion,
        "آدرس سرور" to (prefs.serverUrl.ifEmpty { "—" }),
        "وضعیت دستگاه" to when {
            !prefs.isPaired -> "جفت نشده"
            prefs.isActive -> "تأییدشده و فعال"
            else -> "در انتظار تأیید مدیر"
        },
        "مجوز پیامک" to if (sms.granted) "داده شده" else if (sms.permanentlyDenied) "رد شده (قطعی)" else "داده نشده",
        "مجوز اعلان‌ها" to if (Build.VERSION.SDK_INT < 33) "سیستمی (بدون مجوز جدا)" else if (notif.granted) "داده شده" else "داده نشده",
        "سرویس همیشه‌روشن" to if (PeakopayService.isRunning) "در حال اجرا" else "متوقف",
        "بهینه‌سازی باتری" to if (battery) "بدون محدودیت (معاف)" else "محدود",
        "آخرین پیامک بانکی" to (if (prefs.lastSmsReceivedAt > 0) Fa.timeLabel(prefs.lastSmsReceivedAt) else "هنوز پیامکی دریافت نشده"),
        "آخرین همگام‌سازی موفق" to (if (prefs.lastSyncAt > 0) Fa.timeLabel(prefs.lastSyncAt) else "—"),
        "آخرین خطای همگام‌سازی" to (prefs.lastSyncError.ifEmpty { "—" }),
    )

    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        ir.peakopay.app.ui.common.PurpleHeader(
            subtitle = "عیب‌یابی",
            onBack = onBack,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            rows.forEachIndexed { i, (k, v) ->
                Card(
                    Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(
                        containerColor = if (k == "آخرین خطای همگام‌سازی" && v != "—")
                            Danger.copy(alpha = 0.08f) else MaterialTheme.colorScheme.surface,
                    ),
                    elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                ) {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 14.dp, vertical = 10.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(k, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                        Text(
                            v,
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.padding(start = 12.dp),
                        )
                    }
                }
            }
            if (pipelineEvents.isNotEmpty()) {
                Text(
                    "رخدادهای اخیر",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.padding(top = 8.dp),
                )
                val kindLabel = mapOf(
                    "no_rule" to "نادیده — هیچ قالبی نگرفت",
                    "queued" to "به صف ارسال رفت",
                )
                pipelineEvents.forEach { ev ->
                    Card(
                        Modifier.fillMaxWidth(),
                        colors = CardDefaults.cardColors(
                            containerColor = when (ev.kind) {
                                "queued" -> Success.copy(alpha = 0.08f)
                                else -> Danger.copy(alpha = 0.08f)
                            },
                        ),
                        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                    ) {
                        Column(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp)) {
                            Row(
                                Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                            ) {
                                Text(
                                    kindLabel[ev.kind] ?: ev.kind,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = FontWeight.Medium,
                                    color = if (ev.kind == "queued") Success else Danger,
                                )
                                Text(
                                    Fa.timeLabel(ev.ts),
                                    style = MaterialTheme.typography.labelMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            Text(
                                "فرستنده: " + ev.sender,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            if (ev.detail.isNotEmpty()) {
                                Text(
                                    ev.detail,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                    }
                }
            }
            Text(
                "در گزارش به پشتیبانی، همین صفحه را اسکرین‌شات کنید. هیچ کلید یا اطلاعات بانکی در این صفحه وجود ندارد.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 4.dp),
            )
        }
    }
}
