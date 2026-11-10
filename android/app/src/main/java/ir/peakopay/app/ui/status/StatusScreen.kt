package ir.peakopay.app.ui.status

import android.Manifest
import android.app.Activity
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.service.PeakopayService
import ir.peakopay.app.ui.theme.Danger
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.util.Fa
import ir.peakopay.app.util.Permissions
import kotlinx.coroutines.launch

/**
 * صفحه «وضعیت پیکو پی» — داشبورد مجوزها و پس‌زمینه (فارسی/RTL).
 * هر مورد: ✓/✕ + دکمه فعال‌سازی متناسب (درخواست مجوز / دیالوگ باتری / سوییچ سرویس).
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun StatusScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var sms by remember { mutableStateOf(Permissions.sms(context)) }
    var notif by remember { mutableStateOf(Permissions.notifications(context)) }
    var battery by remember { mutableStateOf(Permissions.isIgnoringBatteryOptimizations(context)) }
    var serviceOn by remember { mutableStateOf(prefs.serviceEnabled) }
    var serviceRunning by remember { mutableStateOf(PeakopayService.isRunning) }
    var connTest by remember { mutableStateOf<String?>(null) }
    var connTesting by remember { mutableStateOf(false) }
    var oemOpened by remember { mutableStateOf<String?>(null) }
    var permDialog by remember { mutableStateOf<String?>(null) } // عنوان دیالوگ «تنظیمات»

    fun refresh() {
        sms = Permissions.sms(context)
        notif = Permissions.notifications(context)
        battery = Permissions.isIgnoringBatteryOptimizations(context)
        serviceRunning = PeakopayService.isRunning
    }

    val smsLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        prefs.smsPermRequested = true
        sms = Permissions.sms(context)
        if (granted) refresh()
    }
    val notifLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        prefs.notifPermRequested = true
        notif = Permissions.notifications(context)
        if (granted) refresh()
    }

    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        ir.peakopay.app.ui.common.PurpleHeader(
            subtitle = "وضعیت پیکو پی",
            onBack = onBack,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // ─── کارت قهرمان وضعیت دستگاه (مطابق طرح) ───
            Card(
                Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(22.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
            ) {
                Column(
                    Modifier.fillMaxWidth().padding(vertical = 22.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Box(
                        Modifier
                            .size(84.dp)
                            .background(Success.copy(alpha = 0.14f), CircleShape),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("✓", color = Success, style = MaterialTheme.typography.displayMedium, fontWeight = FontWeight.Bold)
                    }
                    Text(
                        if (prefs.deviceStatus == "active") stringResource(R.string.device_connected_title) else stringResource(R.string.device_status_pending),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        stringResource(
                            R.string.last_conn_fmt,
                            if (prefs.lastSyncAt > 0) Fa.timeLabel(prefs.lastSyncAt) + " امروز" else "—",
                        ),
                        style = MaterialTheme.typography.labelMedium,
                        color = Color(0xFF5C6480),
                    )
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Box(Modifier.size(8.dp).background(if (ir.peakopay.app.util.Net.isOnline(context)) Success else Danger, CircleShape))
                        Text(
                            stringResource(R.string.server_connection) + ": " +
                                stringResource(if (ir.peakopay.app.util.Net.isOnline(context)) R.string.online else R.string.offline),
                            style = MaterialTheme.typography.labelMedium,
                            color = Color(0xFF5C6480),
                        )
                    }
                }
            }
            // راهنمای بهینه‌سازی (مطابق طرح)
            Card(
                Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(18.dp),
                colors = CardDefaults.cardColors(containerColor = ir.peakopay.app.ui.theme.LavenderChip),
            ) {
                Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("💡", style = MaterialTheme.typography.bodyLarge)
                    Text(
                        stringResource(R.string.optimize_hint),
                        style = MaterialTheme.typography.bodySmall,
                        color = ir.peakopay.app.ui.theme.DeepViolet,
                    )
                }
            }
            StatusRow(
                title = "دریافت پیامک (SMS)",
                ok = sms.granted,
                okText = "فعال",
                badText = if (sms.permanentlyDenied) "غیرفعال — رد شده (از تنظیمات فعال کنید)" else "غیرفعال",
                hint = "برای دریافت خودکار پیامک واریز بانکی — بدون این مجوز هیچ تراکنشی پردازش نمی‌شود.",
                actionLabel = if (sms.permanentlyDenied) "باز کردن تنظیمات" else if (sms.granted) null else "فعال‌سازی",
                onAction = {
                    if (sms.permanentlyDenied) permDialog =
                        "مجوز پیامک از مسیر تنظیمات → مجوزها → پیامک → اجازه دادن فعال می‌شود."
                    else smsLauncher.launch(Permissions.SMS)
                },
            )

            StatusRow(
                title = "اعلان‌ها" + if (Build.VERSION.SDK_INT < 33) " (سیستمی)" else "",
                ok = notif.granted,
                okText = "فعال",
                badText = if (notif.permanentlyDenied) "غیرفعال — رد شده (از تنظیمات فعال کنید)" else "غیرفعال",
                hint = "اعلان «پرداخت تأیید شد» و وضعیت سرویس. روی اندروید ۱۳+ مجوز جداگانه دارد.",
                actionLabel = when {
                    notif.granted -> null
                    notif.permanentlyDenied -> "باز کردن تنظیمات"
                    Build.VERSION.SDK_INT < 33 -> "تنظیمات اعلان سامسونگ/سیستم"
                    else -> "فعال‌سازی"
                },
                onAction = {
                    when {
                        notif.permanentlyDenied -> permDialog = "اعلان‌ها را از تنظیمات → اعلان‌ها روشن کنید."
                        Build.VERSION.SDK_INT >= 33 -> notifLauncher.launch(Permissions.NOTIFICATIONS)
                        else -> Permissions.openAppSettings(context)
                    }
                },
            )

            StatusRow(
                title = "بهینه‌سازی باتری",
                ok = battery,
                okText = "بدون محدودیت",
                badText = "محدود (Doze ممکن است ارسال را کند کند)",
                hint = "برای اپ‌هایی که باید به‌موقع پاسخ بدهند، اندروید امکان معافیت رسمی می‌دهد. مصرف اپ بسیار کم است.",
                actionLabel = if (battery) null else "درخواست معافیت",
                onAction = {
                    if (!Permissions.requestIgnoreBatteryOptimizations(context)) {
                        Permissions.openBatteryOptimizationList(context)
                    }
                },
            )

            // ─── سرویس همیشه‌روشن ───
            Card(
                Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("سرویس همیشه‌روشن پیکو پی", fontWeight = FontWeight.Bold)
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                StatusDot(if (serviceOn && serviceRunning) "در حال اجرا" else "متوقف", if (serviceOn && serviceRunning) Success else Danger)
                            }
                        }
                        Switch(
                            checked = serviceOn,
                            onCheckedChange = { on ->
                                serviceOn = on
                                prefs.serviceEnabled = on
                                if (on) {
                                    PeakopayService.start(context)
                                } else {
                                    PeakopayService.stop(context)
                                }
                                serviceRunning = PeakopayService.isRunning
                            },
                        )
                    }
                    Text(
                        "برای تأیید لحظه‌ای پرداخت‌ها (مشتری در صفحه پرداخت منتظر است) توصیه می‌شود. خاموش‌کردن آن امن است؛ فقط تأییدها ممکن است تا ۱۵ دقیقه طول بکشند.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            StatusRow(
                title = "اتصال به سرور",
                // خروجی diagnose چندخطی است و با «۱) آدرس: …» شروع می‌شود؛
                // نشانه موفقیت جمله «✓ سرور در دسترس» در متن است (دور ۲۱ —
                // قبلاً startsWith("✓") بود و حتی در حالت سالم «مشکل دارد» می‌داد).
                ok = connTest?.contains("✓ سرور در دسترس") == true,
                okText = "متصل",
                badText = if (connTest == null) "بررسی نشده" else "مشکل دارد",
                hint = connTest ?: "آخرین بررسی اتصال به سرور پیکو پی.",
                hintLong = true,
                actionLabel = if (connTesting) null else "بررسی اتصال",
                extra = { if (connTesting) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) },
                onAction = {
                    connTesting = true
                    connTest = null
                    scope.launch {
                        try {
                            connTest = Api.diagnose(prefs.serverUrl)
                        } catch (e: Exception) {
                            connTest = "✗ خطای غیرمنتظره: " + (e.message ?: e.javaClass.simpleName)
                        }
                        connTesting = false
                    }
                },
            )

            // ─── راهنمای سازنده‌ها (سامسونگ و…) ───
            Card(
                Modifier.fillMaxWidth(),
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f)),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("گوشی‌های سامسونگ و چینی — تنظیمات اضافه", fontWeight = FontWeight.Bold)
                    Text(
                        "سامسونگ: تنظیمات ← Battery ← Background usage limits ← اپ را از \"Deep sleeping apps\" خارج کنید و در \"Never sleeping apps\" بگذارید. (نام دقیق گزینه‌ها بین نسخه‌های One UI کمی فرق می‌کند.)\n" +
                            "شیائومی/Redmi/Poco: تنظیمات ← Battery ← بدون هیچ محدودیتی + مجوز Autostart در Security Center.\n" +
                            "هواوی: Settings ← Battery ← App launch ← Manage manually + Run in background.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedButton(onClick = { oemOpened = Permissions.openOemBackgroundSettings(context) }) {
                            Text("باز کردن صفحه مربوطه (در صورت امکان)")
                        }
                    }
                    if (oemOpened != null) {
                        Text(
                            "باز شد: $oemOpened — اگر صفحه درستی نبود، از تنظیمات گوشی دستی پیدا کنید.",
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
            }

            OutlinedButton(onClick = { Permissions.openAppSettings(context) }, modifier = Modifier.fillMaxWidth()) {
                Text("باز کردن تنظیمات اندروید اپ")
            }

            if (prefs.lastSmsReceivedAt > 0) {
                Text(
                    "آخرین پیامک بانکی دریافت‌شده: " + Fa.timeLabel(prefs.lastSmsReceivedAt),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Spacer(Modifier.height(8.dp))
        }
    }

    // دیالوگ راهنمای فعال‌سازی دستی از تنظیمات
    if (permDialog != null) {
        AlertDialog(
            onDismissRequest = { permDialog = null },
            title = { Text("فعال‌سازی دستی") },
            text = { Text(permDialog ?: "") },
            confirmButton = {
                TextButton(onClick = {
                    permDialog = null
                    Permissions.openAppSettings(context)
                    refresh()
                }) { Text("باز کردن تنظیمات") }
            },
            dismissButton = { TextButton(onClick = { permDialog = null }) { Text("بعداً") } },
        )
    }

    LaunchedEffect(Unit) { refresh() }
}

/** یک ردیف وضعیت: عنوان + ✓/✕ + توضیح + دکمه اقدام */
@Composable
fun StatusRow(
    title: String,
    ok: Boolean,
    okText: String,
    badText: String,
    hint: String,
    hintLong: Boolean = false,
    actionLabel: String?,
    extra: @Composable () -> Unit = {},
    onAction: () -> Unit = {},
) {
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(title, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                StatusPill(ok, if (ok) okText else badText)
            }
            Text(
                hint,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = if (hintLong) Modifier
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f), RoundedCornerShape(8.dp))
                    .padding(8.dp) else Modifier,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                if (actionLabel != null) {
                    Button(onClick = onAction) { Text(actionLabel) }
                }
                extra()
            }
        }
    }
}

@Composable
private fun StatusPill(ok: Boolean, text: String) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        modifier = Modifier
            .background(
                if (ok) Success.copy(alpha = 0.12f) else Danger.copy(alpha = 0.12f),
                RoundedCornerShape(20.dp),
            )
            .padding(horizontal = 10.dp, vertical = 4.dp),
    ) {
        Text(if (ok) "✓" else "✕", color = if (ok) Success else Danger, fontWeight = FontWeight.Bold)
        Text(text, color = if (ok) Success else Danger, style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun StatusDot(text: String, color: Color) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        androidx.compose.foundation.Canvas(Modifier.size(8.dp)) {
            drawCircle(color)
        }
        Text(text, style = MaterialTheme.typography.labelMedium, color = color)
    }
}
