package ir.peakopay.app.ui.wizard

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.RulesRepository
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.service.PeakopayService
import ir.peakopay.app.ui.pairing.PairingScreen
import ir.peakopay.app.ui.status.StatusRow
import ir.peakopay.app.util.Fa
import ir.peakopay.app.util.Permissions
import kotlinx.coroutines.launch

/**
 * ویزارد راه‌اندازی اولیه — ۱۰ گام:
 * خوش‌آمد → سرور → همراه‌سازی → مجوز پیامک → مجوز اعلان → سرویس پس‌زمینه →
 * باتری → تست دریافت پیامک → تست اتصال → جمع‌بندی سلامت.
 */
@Composable
fun SetupWizard(onFinish: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()
    var step by remember { mutableStateOf(1) }

    val stepTitles = listOf(
        "", "خوش‌آمد", "سرور پیکو پی", "همراه‌سازی دستگاه", "مجوز پیامک", "مجوز اعلان‌ها",
        "سرویس پس‌زمینه", "باتری و پس‌زمینه", "تست دریافت پیامک", "تست اتصال", "جمع‌بندی",
    )

    fun next() {
        if (step >= 10) {
            prefs.wizardDone = true
            onFinish()
        } else step += 1
    }

    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        // ── هدر بنفش گام‌ها (مطابق طرح) ──
        Column(
            Modifier
                .fillMaxWidth()
                .background(ir.peakopay.app.ui.theme.DeepViolet)
                .statusBarsPadding()
                .padding(horizontal = 20.dp, vertical = 12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                ir.peakopay.app.ui.common.BrandLogo(size = 40, glyph = 27)
                Column {
                    Text(
                        "Peakopay",
                        color = androidx.compose.ui.graphics.Color.White,
                        fontWeight = FontWeight.Bold,
                        style = MaterialTheme.typography.titleLarge,
                    )
                    Text(
                        "راه‌اندازی — گام " + Fa.digits(step.toString()) + " از ۱۰: " + stepTitles[step],
                        color = androidx.compose.ui.graphics.Color(0xFFD9CCFB),
                        style = MaterialTheme.typography.labelMedium,
                    )
                }
            }
            LinearProgressIndicator(
                progress = { step / 10f },
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(6.dp)),
                color = androidx.compose.ui.graphics.Color.White,
                trackColor = androidx.compose.ui.graphics.Color(0x33FFFFFF),
            )
        }

        // گام ۳ خودش PairingScreen را دارد که اسکرول‌پذیر است — تودرتویی اسکرول
        // باعث کرش «infinity maximum height constraints» می‌شود؛ فقط بقیه گام‌ها اسکرول.
        val contentScroll = rememberScrollState()
        Column(
            Modifier
                .weight(1f)
                .then(if (step == 3) Modifier else Modifier.verticalScroll(contentScroll))
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            when (step) {
                1 -> WelcomeStep(onNext = { next() })
                2 -> ServerStep(onNext = { next() })
                3 -> PairStep(onDone = { next() }, onSkip = { next() })
                4 -> SmsPermStep(onNext = { next() })
                5 -> NotifPermStep(onNext = { next() })
                6 -> ServiceStep(onNext = { next() })
                7 -> BatteryStep(onNext = { next() })
                8 -> TestSmsStep(onNext = { next() })
                9 -> TestConnStep(onNext = { next() })
                10 -> FinalStep(onFinish = {
                    prefs.wizardDone = true
                    if (prefs.serviceEnabled) PeakopayService.start(context)
                    onFinish()
                })
            }
        }

        if (step in 1..2) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                TextButton(onClick = { prefs.wizardDone = true; onFinish() }) { Text("رد کردن (بدون ویزارد)") }
                TextButton(onClick = { if (step > 1) step -= 1 }) { Text(if (step > 1) "قبلی" else "") }
            }
        }
    }
}

@Composable
private fun StepCard(title: String, body: String, extra: @Composable () -> Unit = {}) {
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
    ) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Text(body, style = MaterialTheme.typography.bodyMedium)
            extra()
        }
    }
}

@Composable
private fun WelcomeStep(onNext: () -> Unit) {
    StepCard(
        "به پیکو پی خوش آمدید 👋",
        "این اپ گوشی فروشنده است: پیامک واریز بانکی را می‌خواند، برای سرور می‌فرستد و سفارش ووکامرس به‌صورت خودکار تأیید می‌شود.\n\nدر ۱۰ گام کوتاه همه‌چیز را آماده می‌کنیم تا هیچ تراکنشی از دست نرود. قبل از شروع، «کد همراه‌سازی» را از پنل مدیریت (دستگاه‌های همراه) آماده کنید.",
    ) {
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("شروع") }
    }
}

@Composable
private fun ServerStep(onNext: () -> Unit) {
    StepCard(
        "۱) آدرس سرور",
        "آدرس پنل پیکو پی را دارید؟ مثلاً pay.example.com — در گام بعد واردش می‌کنیم و سلامتش را می‌سنجیم. (اگر هنوز سرور نصب نکرده‌اید، اول راهنمای cPanel را تمام کنید.)",
    ) {
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه — آماده‌ام") }
    }
}

@Composable
private fun PairStep(onDone: () -> Unit, onSkip: () -> Unit) {
    StepCard(
        "۲) همراه‌سازی دستگاه",
        "کد همراه‌سازی را از پنل (دستگاه‌های همراه ← ساخت کد) بگیرید و اینجا وارد کنید. پس از تأیید مدیر، ادامه می‌دهیم.",
    ) {
        if (Prefs.get(LocalContext.current).isPaired) {
            Text("✓ دستگاه همراه‌سازی شده است", color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.Bold)
            Button(onClick = onDone, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
        } else {
            Text("فرم زیر را پر کنید (همان صفحه همراه‌سازی):", style = MaterialTheme.typography.bodySmall)
        }
    }
    if (!Prefs.get(LocalContext.current).isPaired) {
        PairingScreen(onPaired = onDone)
    }
}

@Composable
private fun SmsPermStep(onNext: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    var state by remember { mutableStateOf(Permissions.sms(context)) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        prefs.smsPermRequested = true
        state = Permissions.sms(context)
    }
    StepCard(
        "۳) مجوز دریافت پیامک",
        "برای دریافت خودکار پیامک واریز بانکی، اندروید مجوز «SMS» می‌خواهد. پیکو پی فقط پیامک‌های واریز بانکی را پردازش می‌کند؛ هیچ پیامکی حذف/تغییر نمی‌یابد و محتوای پیامک‌های شخصی ارسال نمی‌شود.",
    ) {
        StatusRow(
            title = "مجوز پیامک", ok = state.granted,
            okText = "فعال", badText = if (state.permanentlyDenied) "رد شده — از تنظیمات" else "غیرفعال",
            hint = if (state.permanentlyDenied) "اندروید دیگر نمی‌پرسد؛ از تنظیمات ← مجوزها ← پیامک ← اجازه دهید." else "با زدن دکمه، دیالوگ سیستمی اندروید باز می‌شود.",
            actionLabel = when {
                state.granted -> null
                state.permanentlyDenied -> "باز کردن تنظیمات"
                else -> "اجازه دادن"
            },
            onAction = {
                if (state.permanentlyDenied) Permissions.openAppSettings(context)
                else launcher.launch(Permissions.SMS)
                state = Permissions.sms(context)
            },
        )
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun NotifPermStep(onNext: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    var state by remember { mutableStateOf(Permissions.notifications(context)) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        prefs.notifPermRequested = true
        state = Permissions.notifications(context)
    }
    StepCard(
        "۴) مجوز اعلان‌ها",
        "روی اندروید ۱۳ به بعد، اعلان «پرداخت تأیید شد» و وضعیت سرویس به مجوز جداگانه نیاز دارد.",
    ) {
        StatusRow(
            title = "اعلان‌ها", ok = state.granted,
            okText = "فعال", badText = if (state.permanentlyDenied) "رد شده — از تنظیمات" else "غیرفعال",
            hint = if (state.permanentlyDenied) "از تنظیمات ← اعلان‌ها ← پیکو پی ← روشن کنید." else "",
            actionLabel = when {
                state.granted -> null
                state.permanentlyDenied -> "باز کردن تنظیمات"
                else -> "اجازه دادن"
            },
            onAction = {
                if (state.permanentlyDenied) Permissions.openAppSettings(context)
                else launcher.launch(Permissions.NOTIFICATIONS)
                state = Permissions.notifications(context)
            },
        )
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun ServiceStep(onNext: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    StepCard(
        "۵) سرویس پس‌زمینه",
        "برای اینکه تأیید پرداخت در چند ثانیه انجام شود (مشتری منتظر است)، سرویس همیشه‌روشن با یک اعلان کم‌صدا روشن می‌شود. هر وقت بخواهید از صفحه «وضعیت» خاموشش کنید — بدون آن هم اپ کار می‌کند ولی تأییدها ممکن است کندتر باشند.",
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text(if (prefs.serviceEnabled) "روشن (پیشنهادی)" else "خاموش", fontWeight = FontWeight.Bold)
            androidx.compose.material3.Switch(checked = prefs.serviceEnabled, onCheckedChange = { prefs.serviceEnabled = it })
        }
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun BatteryStep(onNext: () -> Unit) {
    val context = LocalContext.current
    var battery by remember { mutableStateOf(Permissions.isIgnoringBatteryOptimizations(context)) }
    var oem by remember { mutableStateOf<String?>(null) }
    StepCard(
        "۶) باتری و پس‌زمینه",
        "اندروید برای صرفه‌جویی باتری، اپ‌های پس‌زمینه را می‌خواباند (Doze). برای اپ‌هایی که باید به‌موقع کار کنند، امکان «معافیت رسمی» وجود دارد — مصرف پیکو پی بسیار کم است.",
    ) {
        StatusRow(
            title = "بهینه‌سازی باتری", ok = battery, okText = "بدون محدودیت", badText = "محدود",
            hint = "دکمه زیر دیالوگ رسمی اندروید را باز می‌کند: «Allow» را انتخاب کنید.",
            actionLabel = if (battery) null else "درخواست معافیت",
            onAction = {
                if (!Permissions.requestIgnoreBatteryOptimizations(context)) {
                    Permissions.openBatteryOptimizationList(context)
                }
                battery = Permissions.isIgnoringBatteryOptimizations(context)
            },
        )
        OutlinedButton(onClick = { oem = Permissions.openOemBackgroundSettings(context) }, modifier = Modifier.fillMaxWidth()) {
            Text("گوشی سامسونگ/شیائومی/هواوی دارم")
        }
        if (oem != null) {
            Text("باز شد: $oem — تنظیمات «Background usage limits» / «Autostart» را چک کنید.", style = MaterialTheme.typography.bodySmall)
        }
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun TestSmsStep(onNext: () -> Unit) {
    val context = LocalContext.current
    var parsedInfo by remember { mutableStateOf<String?>(null) }
    val sample = "برداشت انجام شد؟ خیر — نمونه واریز: بانک سامان؛ کد پیگیری 12345، واریز به کارت ending 5673، مبلغ 1,250,000 ریال"
    StepCard(
        "۷) تست دریافت پیامک",
        "با یک پیامک نمونه، تجزیه‌گر پیکو پی را همین‌جا می‌سنجیم (بدون ارسال به سرور):",
    ) {
        OutlinedButton(onClick = {
            val parser = RulesRepository.parser(context)
            val r = parser.parse(sample, "SamanBank", System.currentTimeMillis())
            parsedInfo = if (r != null) {
                "✓ پیامک واریز شناخته شد:\nبانک: " + r.bank + "\nمبلغ: " + Fa.number(r.amountRial) + " ریال\nشماره کارت مقصد: ****" + r.destLast4
            } else {
                "✗ این نمونه واریز شناخته نشد — اگر واقعی بود ارسال نمی‌شد."
            }
        }, modifier = Modifier.fillMaxWidth()) { Text("اجرای تست با پیامک نمونه") }
        if (parsedInfo != null) {
            Text(
                parsedInfo!!,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f), RoundedCornerShape(8.dp))
                    .padding(10.dp),
            )
        }
        Text(
            "تست واقعی: از گوشی/بانک دیگری یک واریز کوچک انجام دهید؛ در صفحه «وضعیت»، «آخرین پیامک بانکی دریافت‌شده» باید به‌روز شود.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun TestConnStep(onNext: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()
    var testing by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<String?>(null) }
    StepCard(
        "۸) تست اتصال به سرور",
        "اتصال واقعی به سرور پیکو پی بررسی می‌شود (DNS، گواهی SSL، پاسخ /health):",
    ) {
        Button(
            onClick = {
                testing = true; result = null
                scope.launch {
                    try {
                        result = Api.diagnose(prefs.serverUrl)
                    } catch (e: Exception) {
                        result = "✗ خطای غیرمنتظره: " + (e.message ?: e.javaClass.simpleName)
                    }
                    testing = false
                }
            },
            enabled = !testing,
            modifier = Modifier.fillMaxWidth(),
        ) { if (testing) CircularProgressIndicator(Modifier.height(16.dp), strokeWidth = 2.dp) else Text("بررسی اتصال") }
        if (result != null) {
            Text(
                result!!,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f), RoundedCornerShape(8.dp))
                    .padding(10.dp),
            )
        }
        Button(onClick = onNext, modifier = Modifier.fillMaxWidth()) { Text("ادامه") }
    }
}

@Composable
private fun FinalStep(onFinish: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val sms = Permissions.sms(context)
    val notif = Permissions.notifications(context)
    val battery = Permissions.isIgnoringBatteryOptimizations(context)
    val okCount = listOf(sms.granted, notif.granted, battery, prefs.isPaired).count { it }
    StepCard(
        "۹) جمع‌بندی سلامت — " + Fa.digits(okCount.toString()) + " از ۴ آماده",
        "هرچه سبز است آماده است. موارد قرمز را می‌توانید بعداً از صفحه «وضعیت» فعال‌سازی کنید.",
    ) {
        StatusRow(title = "همراه‌سازی دستگاه", ok = prefs.isPaired, okText = "انجام شد", badText = "نشده", hint = "", actionLabel = null)
        StatusRow(title = "مجوز پیامک", ok = sms.granted, okText = "فعال", badText = "غیرفعال", hint = "", actionLabel = null)
        StatusRow(title = "اعلان‌ها", ok = notif.granted, okText = "فعال", badText = "غیرفعال", hint = "", actionLabel = null)
        StatusRow(title = "باتری", ok = battery, okText = "بدون محدودیت", badText = "محدود", hint = "", actionLabel = null)
        Button(onClick = onFinish, modifier = Modifier.fillMaxWidth()) { Text("پایان — شروع به کار") }
    }
}
