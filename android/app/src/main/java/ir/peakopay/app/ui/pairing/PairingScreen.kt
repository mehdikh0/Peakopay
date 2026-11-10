package ir.peakopay.app.ui.pairing

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.ApiException
import ir.peakopay.app.work.HeartbeatWorker
import ir.peakopay.app.work.UploadWorker
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * صفحه همراه‌سازی: ورود کد کوتاه از پنل → دریافت اعتبارنامه →
 * صفحه «در انتظار تأیید مدیر» (خودکار هر ۵ ثانیه وضعیت را می‌پرسد).
 */
@Composable
fun PairingScreen(onPaired: () -> Unit, onCancel: (() -> Unit)? = null, addMode: Boolean = false) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val prefs = Prefs.get(context)

    // دور ۲۵ — حالت «افزودن فروشگاه»: سرور فعلی از قبل پر است و انصراف ممکن است
    var serverUrl by remember { mutableStateOf(if (addMode) prefs.serverUrl else "") }
    var code by remember { mutableStateOf("") }
    var deviceName by remember { mutableStateOf(Build.MODEL ?: "") }
    // دور ۲۳ — دو راه اتصال: کد همراه‌سازی یا ایمیل/رمز حساب پنل
    var mode by remember { mutableStateOf("code") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var pendingApproval by remember { mutableStateOf(false) }
    var smsPermissionAsked by remember { mutableStateOf(false) }
    var connTest by remember { mutableStateOf<String?>(null) }
    var connTesting by remember { mutableStateOf(false) }

    // ---- دسترسی پیامک (پس از جفت‌شدن) ----
    val smsPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }
    fun askSmsPermission() {
        if (smsPermissionAsked) return
        smsPermissionAsked = true
        val perms = buildList {
            add(Manifest.permission.RECEIVE_SMS)
            add(Manifest.permission.READ_SMS)
            if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
        }
        if (perms.any { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }) {
            smsPermission.launch(perms.toTypedArray())
        }
    }

    // ---- نظرسنجی وضعیت تأیید مدیر ----
    LaunchedEffect(pendingApproval) {
        if (!pendingApproval) return@LaunchedEffect
        val prefs = Prefs.get(context)
        val api = Api(prefs.serverUrl)
        while (isActive) {
            try {
                val hb = api.heartbeat(prefs.deviceId, prefs.deviceSecret)
                if (hb.device_status == "active") {
                    prefs.deviceStatus = "active"
                    askSmsPermission()
                    scheduleWork(context)
                    onPaired()
                    break
                }
            } catch (e: Exception) {
                // شبکه قطع است — ادامه نظرسنجی
            }
            delay(5000)
        }
    }

    fun doPair() {
        if (busy) return
        error = null
        val url = try {
            Api.normalizeUrl(serverUrl)
        } catch (e: Api.ApiException) {
            error = e.message
            return
        }
        val pairingCode = code.trim().uppercase()
        if (pairingCode.length < 4) {
            error = context.getString(R.string.pairing_error_code)
            return
        }
        busy = true
        scope.launch {
            try {
                val api = Api(url)
                val result = api.pair(
                    Api.PairRequest(
                        code = pairingCode,
                        device_name = deviceName.ifBlank { "دستگاه" },
                        model = Build.MODEL ?: "",
                        app_version = "1.0.0",
                        android_version = Build.VERSION.RELEASE ?: "",
                        // دور ۲۵ — افزودن فروشگاه: همین دستگاه به فروشگاه کد وصل شود
                        device_id = if (addMode && prefs.isPaired) prefs.deviceId else 0L,
                        device_secret = if (addMode && prefs.isPaired) prefs.deviceSecret else "",
                    ),
                )
                val prefs = Prefs.get(context)
                prefs.serverUrl = url
                prefs.deviceId = result.device_id
                prefs.deviceSecret = result.device_secret
                prefs.deviceName = deviceName
                prefs.storeName = result.store
                prefs.deviceStatus = result.status
                // بلافاصله پیکربندی کارت‌ها (فرستنده‌ها/قواعد) را بگیر تا خط لوله پیامک آماده شود
                try {
                    val cfg = api.deviceConfig(result.device_id, result.device_secret)
                    ir.peakopay.app.data.CardConfigStore.save(context, ir.peakopay.app.data.CardConfigStore.fromApi(cfg))
                } catch (e: Exception) {
                    // با اولین ضربان/باز شدن داشبورد دوباره تلاش می‌شود
                }
                busy = false
                if (addMode && result.store_id > 0) {
                    prefs.activeStoreId = result.store_id // مستقیم روی فروشگاه تازه‌اضافه
                }
                if (result.status == "active") {
                    askSmsPermission()
                    scheduleWork(context)
                    onPaired()
                } else {
                    pendingApproval = true
                }
            } catch (e: ApiException) {
                busy = false
                error = when (e.code) {
                    "invalid_code", "missing_code" -> context.getString(R.string.pairing_error_code)
                    // برای بقیه (شبکه/TLS/DNS/تایم‌اوت/پاسخ سرور) پیام کامل با علت واقعی نمایش داده می‌شود
                    else -> e.message
                }
            } catch (e: Exception) {
                busy = false
                error = context.getString(R.string.pairing_error_network)
            }
        }
    }

    /**
     * دور ۲۳ — ورود با حساب پنل (ایمیل/رمز): دستگاه بلافاصله فعال است و
     * همهٔ فروشگاه‌های حساب در اپ دیده می‌شوند (چندفروشگاهی).
     */
    fun doAccountLogin() {
        if (busy) return
        error = null
        val url = try {
            Api.normalizeUrl(serverUrl)
        } catch (e: Api.ApiException) {
            error = e.message
            return
        }
        val mail = email.trim().lowercase()
        if (mail.isEmpty() || password.isEmpty()) {
            error = context.getString(R.string.login_email_hint) + " و " + context.getString(R.string.login_password_hint) + " را وارد کنید."
            return
        }
        busy = true
        scope.launch {
            try {
                val api = Api(url)
                val result = api.accountLogin(
                    Api.LoginRequest(
                        email = mail,
                        password = password,
                        device_name = deviceName.ifBlank { "دستگاه" },
                        model = Build.MODEL ?: "",
                        app_version = "1.0.0",
                        android_version = Build.VERSION.RELEASE ?: "",
                        // دور ۲۵ — افزودن فروشگاه: همین دستگاه به این حساب ارتقا یابد
                        device_id = if (addMode && prefs.isPaired) prefs.deviceId else 0L,
                        device_secret = if (addMode && prefs.isPaired) prefs.deviceSecret else "",
                    ),
                )
                val prefs = Prefs.get(context)
                prefs.serverUrl = url
                prefs.deviceId = result.device_id
                prefs.deviceSecret = result.device_secret
                prefs.deviceName = deviceName
                prefs.deviceStatus = result.status
                prefs.deviceSource = "login"
                prefs.accountName = result.account.name
                prefs.accountEmail = mail
                val prevIds = prefs.cachedStores().map { it.id }.toSet()
                prefs.setCachedStores(result.stores)
                // «همهٔ فروشگاه‌ها» حذف شد — در حالت افزودن، فروشگاه تازه‌اضافه؛ وگرنه اولین فروشگاه
                val firstPick = (if (addMode) result.stores.firstOrNull { it.id !in prevIds } else null)
                    ?: result.stores.firstOrNull()
                prefs.activeStoreId = firstPick?.id ?: 0L
                prefs.storeName = firstPick?.name ?: result.account.name
                // پیکربندی کارت‌های همهٔ فروشگاه‌ها (با store_id) برای خط لوله پیامک
                try {
                    val cfg = api.deviceConfig(result.device_id, result.device_secret)
                    ir.peakopay.app.data.CardConfigStore.save(context, ir.peakopay.app.data.CardConfigStore.fromApi(cfg))
                } catch (e: Exception) {
                    // با اولین ضربان/باز شدن داشبورد دوباره تلاش می‌شود
                }
                busy = false
                if (result.status == "active") {
                    askSmsPermission()
                    scheduleWork(context)
                    onPaired()
                } else {
                    // سرور دستگاه‌های ورود حسابی را همیشه active می‌سازد — حالت پشتیبان
                    pendingApproval = true
                }
            } catch (e: ApiException) {
                busy = false
                error = when (e.code) {
                    "invalid_credentials", "missing_credentials", "no_account" -> context.getString(R.string.login_error_invalid)
                    "rate_limited" -> context.getString(R.string.login_error_rate)
                    else -> e.message
                }
            } catch (e: Exception) {
                busy = false
                error = context.getString(R.string.pairing_error_network)
            }
        }
    }

    Column(
        Modifier
            .fillMaxSize()
            .background(ir.peakopay.app.ui.theme.BgLavender)
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 20.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        // ─── هدر برند (مطابق طرح — پنل لوگو) ───
        Column(
            Modifier
                .fillMaxWidth()
                .background(ir.peakopay.app.ui.theme.DeepViolet)
                .padding(bottom = 26.dp)
                .statusBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Spacer(Modifier.height(26.dp))
            ir.peakopay.app.ui.common.BrandLogo(size = 72, glyph = 48)
            Text(
                "Peakopay",
                color = Color.White,
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
            )
            Text(
                stringResourceSafe(if (addMode) R.string.add_store_title else R.string.footer_tagline),
                color = Color(0xFFD9CCFB),
                style = MaterialTheme.typography.bodyMedium,
            )
        }
        Spacer(Modifier.height(22.dp))
        Text(
            when {
                addMode -> stringResourceSafe(R.string.add_store_subtitle)
                mode == "account" -> stringResourceSafe(R.string.login_account_desc)
                else -> stringResourceSafe(R.string.pairing_subtitle)
            },
            style = MaterialTheme.typography.bodyMedium,
            color = Color(0xFF5C6480),
        )
        Spacer(Modifier.height(18.dp))

        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
        ) {
            Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                // دور ۲۳ — انتخاب روش اتصال
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    androidx.compose.material3.FilterChip(
                        selected = mode == "code",
                        onClick = { mode = "code" },
                        label = { Text(stringResourceSafe(R.string.login_tab_code)) },
                        colors = androidx.compose.material3.FilterChipDefaults.filterChipColors(
                            containerColor = Color.White,
                            labelColor = Color(0xFF5C6480),
                            selectedContainerColor = ir.peakopay.app.ui.theme.LavenderChip,
                            selectedLabelColor = ir.peakopay.app.ui.theme.DeepViolet,
                        ),
                    )
                    androidx.compose.material3.FilterChip(
                        selected = mode == "account",
                        onClick = { mode = "account" },
                        label = { Text(stringResourceSafe(R.string.login_tab_account)) },
                        colors = androidx.compose.material3.FilterChipDefaults.filterChipColors(
                            containerColor = Color.White,
                            labelColor = Color(0xFF5C6480),
                            selectedContainerColor = ir.peakopay.app.ui.theme.LavenderChip,
                            selectedLabelColor = ir.peakopay.app.ui.theme.DeepViolet,
                        ),
                    )
                }
                OutlinedTextField(
                    value = serverUrl,
                    onValueChange = { serverUrl = it },
                    label = { Text(stringResourceSafe(R.string.server_url_hint)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    OutlinedButton(
                        onClick = {
                            connTest = null
                            connTesting = true
                            scope.launch {
                                try {
                                    connTest = Api.diagnose(serverUrl)
                                } catch (e: Exception) {
                                    connTest = "✗ خطای غیرمنتظره: " + (e.message ?: e.javaClass.simpleName)
                                }
                                connTesting = false
                            }
                        },
                        enabled = !connTesting && serverUrl.isNotBlank(),
                    ) {
                        Text(stringResourceSafe(R.string.test_connection))
                    }
                    if (connTesting) {
                        CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                    }
                }
                if (connTest != null) {
                    Text(
                        connTest!!,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(MaterialTheme.colorScheme.surfaceVariant, RoundedCornerShape(10.dp))
                            .padding(10.dp),
                    )
                }
                if (mode == "code") {
                    OutlinedTextField(
                        value = code,
                        onValueChange = { code = it.filter { c -> c.isLetterOrDigit() } },
                        label = { Text(stringResourceSafe(R.string.pairing_code_hint)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
                        modifier = Modifier.fillMaxWidth(),
                    )
                } else {
                    Text(
                        stringResourceSafe(R.string.login_account_desc),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    OutlinedTextField(
                        value = email,
                        onValueChange = { email = it },
                        label = { Text(stringResourceSafe(R.string.login_email_hint)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text(stringResourceSafe(R.string.login_password_hint)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                        visualTransformation = androidx.compose.ui.text.input.PasswordVisualTransformation(),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                OutlinedTextField(
                    value = deviceName,
                    onValueChange = { deviceName = it },
                    label = { Text(stringResourceSafe(R.string.device_name_hint)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )

                if (error != null) {
                    Text(
                        error!!,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }

                Button(
                    onClick = { if (mode == "account") doAccountLogin() else doPair() },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(16.dp),
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(
                        containerColor = ir.peakopay.app.ui.theme.DeepViolet,
                    ),
                ) {
                    if (busy) {
                        CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                    } else {
                        Text(
                            if (mode == "account") stringResourceSafe(R.string.login_btn)
                            else stringResourceSafe(R.string.pair_btn),
                        )
                    }
                }

                // دور ۲۵ — انصراف: بازگشت به فروشگاه قبلی بدون هیچ تغییری
                if (onCancel != null) {
                    Spacer(Modifier.height(8.dp))
                    OutlinedButton(
                        onClick = onCancel,
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        shape = RoundedCornerShape(16.dp),
                    ) {
                        Text(stringResourceSafe(R.string.add_store_cancel))
                    }
                }
            }
        }

        if (pendingApproval) {
            Spacer(Modifier.height(24.dp))
            Card(
                Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(24.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
            ) {
                Column(Modifier.padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 3.dp, color = ir.peakopay.app.ui.theme.DeepViolet)
                    Text(stringResourceSafe(R.string.pairing_pending_title), style = MaterialTheme.typography.titleMedium)
                    Text(
                        stringResourceSafe(R.string.pairing_pending_desc),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    OutlinedButton(onClick = {
                        scope.launch {
                            val prefs = Prefs.get(context)
                            try {
                                val api = Api(prefs.serverUrl)
                                val hb = api.heartbeat(prefs.deviceId, prefs.deviceSecret)
                                if (hb.device_status == "active") {
                                    prefs.deviceStatus = "active"
                                    askSmsPermission()
                                    scheduleWork(context)
                                    onPaired()
                                }
                            } catch (e: Exception) {
                                // شبکه قطع — نظرسنجی خودکار ادامه دارد
                            }
                        }
                    }) {
                        Text(stringResourceSafe(R.string.pairing_pending_refresh))
                    }
                }
            }
        }
    }
}

/** زمان‌بندی کارهای دوره‌ای پس از اتصال موفق. */
private fun scheduleWork(context: android.content.Context) {
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
}

@Composable
private fun stringResourceSafe(id: Int): String =
    androidx.compose.ui.res.stringResource(id)
