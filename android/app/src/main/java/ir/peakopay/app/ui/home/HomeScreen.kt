package ir.peakopay.app.ui.home

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Email
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.AccountOverview
import ir.peakopay.app.data.api.Api.AccountStoreView
import ir.peakopay.app.data.api.Api.AccountTxRow
import ir.peakopay.app.data.api.Api.StoreStatus
import ir.peakopay.app.db.AppDb
import ir.peakopay.app.db.TxRecordEntity
import ir.peakopay.app.ui.common.AmberIcon
import ir.peakopay.app.ui.common.AmberTile
import ir.peakopay.app.ui.common.BrandFooter
import ir.peakopay.app.ui.common.ClockIcon
import ir.peakopay.app.ui.common.ColumnsIcon
import ir.peakopay.app.ui.common.GreenIcon
import ir.peakopay.app.ui.common.GreenTile
import ir.peakopay.app.ui.common.HeroIncomeCard
import ir.peakopay.app.ui.common.HourglassIcon
import ir.peakopay.app.ui.common.MenuRow
import ir.peakopay.app.ui.common.NavGray
import ir.peakopay.app.ui.common.PurpleHeader
import ir.peakopay.app.ui.common.RedIcon
import ir.peakopay.app.ui.common.RedTile
import ir.peakopay.app.ui.common.StatCard
import ir.peakopay.app.ui.common.StatIconTile
import ir.peakopay.app.ui.common.StatusLine
import ir.peakopay.app.ui.common.StoreCard
import ir.peakopay.app.ui.common.StoreSelectorRow
import ir.peakopay.app.ui.theme.VioletBright
import ir.peakopay.app.ui.theme.BgLavender
import ir.peakopay.app.ui.theme.Danger
import ir.peakopay.app.ui.theme.DeepViolet
import ir.peakopay.app.ui.theme.LavenderChip
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.ui.theme.TextPrimary
import ir.peakopay.app.ui.theme.TextSecondary
import ir.peakopay.app.ui.theme.Warning
import ir.peakopay.app.util.Err
import ir.peakopay.app.util.Fa
import ir.peakopay.app.util.Net
import ir.peakopay.app.work.UploadWorker
import android.widget.Toast
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * پوستهٔ اصلی اپ (دور ۲۴ مرحلهٔ ۳ — مطابق اسکرین‌شات مرجع):
 * هدر بنفش (لوگو + Peakopay + زیرعنوان + زنگوله) با پایه‌های کناری،
 * داشبورد (انتخاب فروشگاه، وضعیت، کارت درآمد، چهار کارت آمار، فروشگاه‌ها)
 * و **منوی پایین سفید**: داشبورد · تراکنش‌ها · پیامک‌ها · تنظیمات.
 */
enum class MainTab { DASHBOARD, TRANSACTIONS, SMS, SETTINGS }

/** فیلترهای تراکنش — برچسب‌ها مطابق طرح. */
private val TX_FILTERS = listOf(
    "all" to R.string.filter_all,
    "paid" to R.string.filter_processed,
    "unmatched" to R.string.filter_review,
    "pending" to R.string.filter_pending,
    "expired" to R.string.filter_expired,
)

private fun statusLabel(s: String): String = when (s) {
    "paid", "matched", "confirmed" -> "موفق"
    "pending", "verifying", "matched_pending" -> "در انتظار"
    "expired" -> "منقضی شده"
    "failed" -> "ناموفق"
    "unmatched" -> "نیاز به بررسی"
    "received" -> "دریافت شد"
    "rejected" -> "رد شد"
    "duplicate" -> "تکراری"
    "dismissed" -> "نادیده گرفته شد"
    else -> s
}

private fun statusColor(s: String): Color = when (s) {
    "paid", "matched", "confirmed" -> Success
    "pending", "verifying", "matched_pending" -> Warning
    "expired", "failed", "rejected" -> Danger
    "unmatched" -> VioletBright
    "dismissed" -> Color(0xFF6C6690)
    else -> Color(0xFF5C6480)
}

private fun statusGlyph(s: String): String = when (s) {
    "paid", "matched", "confirmed" -> "✓"
    "pending", "verifying", "matched_pending" -> "⏳"
    "expired", "failed", "rejected" -> "✕"
    "unmatched" -> "؟"
    "dismissed" -> "−"
    else -> "•"
}

/** «امروز - ۱۴:۳۵» / «دیروز - ۱۸:۴۰» — زمان سرور (UTC) به زمان محلی. */
private fun serverTimeLabel(s: String): String {
    if (s.isEmpty()) return ""
    return try {
        val fmt = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss").withZone(java.time.ZoneOffset.UTC)
        val local = Instant.from(fmt.parse(s)).atZone(ZoneId.systemDefault())
        val time = Fa.digits(DateTimeFormatter.ofPattern("HH:mm").format(local))
        val today = LocalDate.now()
        when (local.toLocalDate()) {
            today -> "امروز - $time"
            today.minusDays(1) -> "دیروز - $time"
            else -> Fa.digits(DateTimeFormatter.ofPattern("MM/dd").format(local))
        }
    } catch (e: Exception) {
        s
    }
}

@Composable
fun HomeScreen(
    onDisconnect: () -> Unit,
    onOpenStores: () -> Unit,
    onOpenCards: () -> Unit,
    onOpenStatus: () -> Unit,
    onOpenPending: () -> Unit,
    onOpenDiagnostics: () -> Unit,
    onOpenAccount: () -> Unit,
    onOpenNotifications: () -> Unit,
    onAddStore: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val prefs = Prefs.get(context)

    var tab by remember { mutableStateOf(MainTab.DASHBOARD) }
    var stores by remember { mutableStateOf(prefs.cachedStores()) }
    var overview by remember { mutableStateOf<AccountOverview?>(null) }
    var legacyStatus by remember { mutableStateOf<StoreStatus?>(null) }
    var activeStoreId by remember {
        // «همهٔ فروشگاه‌ها» حذف شد — همیشه یک فروشگاه مشخص فعال است (پیش‌فرض: اولین)
        val cs = prefs.cachedStores()
        val init = prefs.activeStoreId
        mutableStateOf(if (cs.size > 1 && (init == 0L || cs.none { it.id == init })) cs.first().id else init)
    }
    var txRows by remember { mutableStateOf<List<AccountTxRow>?>(null) }
    var txFilter by remember { mutableStateOf("all") }
    var txPage by remember { mutableStateOf(1) }
    var txMore by remember { mutableStateOf(false) }
    var txLoading by remember { mutableStateOf(false) }
    var txError by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var storePickerOpen by remember { mutableStateOf(false) }
    var confirmDisconnect by remember { mutableStateOf(false) }

    val txRecords by AppDb.get(context).txRecordDao().observeAll().collectAsState(initial = emptyList())
    val outboxPending by AppDb.get(context).outboxDao().pendingCount().collectAsState(initial = 0)

    val multi = stores.size > 1
    val versionName = try {
        context.packageManager.getPackageInfo(context.packageName, 0).versionName ?: ""
    } catch (e: Exception) {
        ""
    }

    fun refresh() {
        if (loading) return
        loading = true
        error = null
        scope.launch {
            try {
                val api = Api(prefs.serverUrl)
                try {
                    val s = api.accountStores(prefs.deviceId, prefs.deviceSecret)
                    stores = s.stores
                    prefs.setCachedStores(s.stores)
                } catch (e: Api.ApiException) {
                    // سرور پیش از ۲.۰ — تک‌فروشگاهی
                }
                if (stores.size > 1) {
                    if (activeStoreId == 0L || stores.none { it.id == activeStoreId }) {
                        activeStoreId = stores.first().id
                        prefs.activeStoreId = activeStoreId
                    }
                    val ov = api.accountOverview(prefs.deviceId, prefs.deviceSecret, activeStoreId)
                    overview = ov
                    legacyStatus = null
                    prefs.deviceStatus = ov.device.status.ifEmpty { prefs.deviceStatus }
                    prefs.storeName = stores.firstOrNull { it.id == activeStoreId }?.name
                        ?: stores.firstOrNull()?.name
                        ?: prefs.storeName
                } else {
                    overview = null
                    val s = api.storeStatus(prefs.deviceId, prefs.deviceSecret)
                    legacyStatus = s
                    prefs.deviceStatus = s.device?.status ?: prefs.deviceStatus
                    prefs.storeName = s.store.name.ifEmpty { prefs.storeName }
                }
                prefs.lastSyncAt = System.currentTimeMillis()
            } catch (e: Exception) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun loadTx(reset: Boolean) {
        scope.launch {
            if (reset) { txLoading = true; txPage = 1 }
            try {
                val api = Api(prefs.serverUrl)
                val res = api.accountTransactions(
                    prefs.deviceId, prefs.deviceSecret,
                    storeId = activeStoreId, status = txFilter,
                    page = if (reset) 1 else txPage + 1,
                )
                txRows = if (reset) res.transactions else (txRows ?: emptyList()) + res.transactions
                txMore = res.more
                txPage = res.page
                txError = null
            } catch (e: Exception) {
                txError = e.message
                if (reset) txRows = null
            } finally {
                txLoading = false
            }
        }
    }

    LaunchedEffect(Unit) {
        refresh()
        UploadWorker.enqueueNow(context)
        ir.peakopay.app.service.PeakopayService.start(context)
        try {
            val api = Api(prefs.serverUrl)
            val hb = api.heartbeat(prefs.deviceId, prefs.deviceSecret)
            val remote = hb.sms_config_version ?: 0L
            if (remote > 0 && remote != ir.peakopay.app.data.CardConfigStore.localVersion(context)) {
                val c = api.deviceConfig(prefs.deviceId, prefs.deviceSecret)
                ir.peakopay.app.data.CardConfigStore.save(context, ir.peakopay.app.data.CardConfigStore.fromApi(c))
            }
        } catch (e: Exception) {
            // آفلاین — کش قبلی استفاده می‌شود
        }
    }

    LaunchedEffect(tab, activeStoreId) {
        if (tab == MainTab.TRANSACTIONS) loadTx(reset = true)
    }

    val activeStoreName = when {
        !multi -> prefs.storeName
        else -> stores.firstOrNull { it.id == activeStoreId }?.name
            ?: stores.firstOrNull()?.name
            ?: prefs.accountName.ifEmpty { prefs.storeName }
    }

    fun openTxWith(filter: String) {
        txFilter = filter
        txRows = null
        tab = MainTab.TRANSACTIONS
    }

    Column(Modifier.fillMaxSize().background(BgLavender)) {
        // ───────────────────────────── هدر بنفش (مشترک — مطابق طرح)
        PurpleHeader(
            subtitle = when (tab) {
                MainTab.DASHBOARD -> stringResource(R.string.home_header_subtitle)
                MainTab.TRANSACTIONS -> stringResource(R.string.tab_transactions)
                MainTab.SMS -> stringResource(R.string.tab_sms)
                MainTab.SETTINGS -> stringResource(R.string.settings_subtitle)
            },
            onBell = onOpenNotifications,
        )

        // ───────────────────────────── محتوای تب
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState()),
        ) {
            if (error != null) {
                Card(
                    Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                ) {
                    Text(
                        error!!,
                        Modifier.padding(14.dp),
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
            }
            when (tab) {
                MainTab.DASHBOARD -> DashboardTab(
                    overview = overview,
                    legacyStatus = legacyStatus,
                    stores = stores,
                    multi = multi,
                    activeStoreId = activeStoreId,
                    activeStoreName = activeStoreName,
                    lastSyncMs = prefs.lastSyncAt,
                    online = Net.isOnline(context),
                    loading = loading,
                    onOpenStorePicker = { storePickerOpen = true },
                    onRefresh = { refresh() },
                    onOpenPending = onOpenPending,
                    onOpenStores = onOpenStores,
                    onShowPaid = { openTxWith("paid") },
                    onShowAllTx = { openTxWith("all") },
                    onShowExpired = { openTxWith("expired") },
                    onAddStore = onAddStore,
                    onPickStore = { id ->
                        activeStoreId = id
                        prefs.activeStoreId = id
                        txRows = null
                        refresh()
                    },
                )

                MainTab.TRANSACTIONS -> TransactionsTab(
                    rows = txRows,
                    loading = txLoading,
                    error = txError,
                    filter = txFilter,
                    todayIncomeRial = overview?.today?.income_rial ?: legacyStatus?.today?.income_rial ?: 0,
                    more = txMore,
                    localHistory = txRecords,
                    onFilter = { f -> txFilter = f; loadTx(reset = true) },
                    onLoadMore = { loadTx(reset = false) },
                    onRefresh = { loadTx(reset = true) },
                )

                MainTab.SMS -> SmsTab(
                    context = context,
                    outboxPending = outboxPending,
                    history = txRecords,
                    onOpenDiagnostics = onOpenDiagnostics,
                )

                MainTab.SETTINGS -> SettingsTab(
                    versionName = versionName,
                    onOpenAccount = onOpenAccount,
                    onOpenStores = onOpenStores,
                    onOpenCards = onOpenCards,
                    onOpenStatus = onOpenStatus,
                    onOpenNotifications = onOpenNotifications,
                    onDisconnect = { confirmDisconnect = true },
                )
            }
            Spacer(Modifier.height(16.dp))
        }

        // ───────────────────────────── منوی پایین (مطابق طرح — سفید، بدون کاشی)
        BottomNavBar(tab = tab, onSelect = { tab = it })
    }

    // ── انتخاب فروشگاه (دور ۲۶ — مطابق اسکرین‌شات: لیست + تغییر + افزودن)
    if (storePickerOpen) {
        AlertDialog(
            onDismissRequest = { storePickerOpen = false },
            title = { Text(stringResource(R.string.select_store)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    stores.forEach { st ->
                        StorePickerRow(
                            label = st.name,
                            logoUrl = st.logo_url,
                            selected = activeStoreId == st.id,
                            onClick = {
                                storePickerOpen = false
                                if (activeStoreId != st.id) { activeStoreId = st.id; prefs.activeStoreId = st.id; txRows = null; refresh() }
                            },
                        )
                    }
                    // ── افزودن فروشگاه جدید (همان صفحهٔ ورود ابتدایی) ──
                    androidx.compose.material3.HorizontalDivider(
                        Modifier.padding(vertical = 8.dp),
                        color = Color(0xFFE4DCF6),
                    )
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(12.dp))
                            .clickable {
                                storePickerOpen = false
                                onAddStore()
                            }
                            .padding(horizontal = 8.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Box(
                            Modifier
                                .size(38.dp)
                                .background(LavenderChip, RoundedCornerShape(50)),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text("+", color = DeepViolet, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                        }
                        Text(
                            stringResource(R.string.picker_add_store),
                            style = MaterialTheme.typography.bodyLarge,
                            fontWeight = FontWeight.Bold,
                            color = DeepViolet,
                        )
                    }
                }
            },
            confirmButton = {},
            dismissButton = {
                TextButton(onClick = { storePickerOpen = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }

    if (confirmDisconnect) {
        AlertDialog(
            onDismissRequest = { confirmDisconnect = false },
            title = { Text(stringResource(R.string.disconnect)) },
            text = { Text(stringResource(R.string.disconnect_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmDisconnect = false
                    onDisconnect()
                }) { Text(stringResource(R.string.yes), color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDisconnect = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

// ═══════════════════════════════════════════════════════ منوی پایین

/**
 * منوی پایین (مطابق اسکرین‌شات): نوار سفید، چهار آیتم «آیکون + برچسب»؛
 * آیتم فعال بنفش، بقیه خاکستری — بدون کاشی پس‌زمینه.
 */
@Composable
fun BottomNavBar(tab: MainTab, onSelect: (MainTab) -> Unit) {
    Surface(
        color = Color.White,
        shadowElevation = 14.dp,
    ) {
        Row(
            Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .padding(vertical = 10.dp),
            horizontalArrangement = Arrangement.SpaceEvenly,
        ) {
            BottomNavItem(
                labelRes = R.string.tab_dashboard,
                selected = tab == MainTab.DASHBOARD,
                onClick = { onSelect(MainTab.DASHBOARD) },
                icon = { tint ->
                    ColumnsIcon(tint, Modifier.size(24.dp))
                },
            )
            BottomNavItem(
                labelRes = R.string.tab_transactions,
                selected = tab == MainTab.TRANSACTIONS,
                onClick = { onSelect(MainTab.TRANSACTIONS) },
                icon = { tint ->
                    Icon(Icons.AutoMirrored.Filled.List, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp))
                },
            )
            BottomNavItem(
                labelRes = R.string.tab_sms,
                selected = tab == MainTab.SMS,
                onClick = { onSelect(MainTab.SMS) },
                icon = { tint ->
                    Icon(Icons.Filled.Email, contentDescription = null, tint = tint, modifier = Modifier.size(23.dp))
                },
            )
            BottomNavItem(
                labelRes = R.string.tab_settings,
                selected = tab == MainTab.SETTINGS,
                onClick = { onSelect(MainTab.SETTINGS) },
                icon = { tint ->
                    Icon(Icons.Filled.Settings, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp))
                },
            )
        }
    }
}

@Composable
private fun BottomNavItem(
    labelRes: Int,
    selected: Boolean,
    onClick: () -> Unit,
    icon: @Composable (Color) -> Unit,
) {
    val tint = if (selected) DeepViolet else NavGray
    Column(
        Modifier
            .clip(RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        icon(tint)
        Spacer(Modifier.height(5.dp))
        Text(
            stringResource(labelRes),
            fontSize = 11.sp,
            fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
            color = tint,
            maxLines = 1,
        )
    }
}

// ═══════════════════════════════════════════════════════ تب داشبورد

/**
 * داشبورد (مطابق اسکرین‌شات): انتخاب فروشگاه ▾، خط وضعیت، کارت درآمد،
 * چهار کارت آمار (در انتظار بررسی/پرداخت‌های موفق/تعداد کل/منقضی شده)،
 * «فروشگاه‌های من | مشاهده همه ›» و کارت هر فروشگاه.
 */
@Composable
private fun DashboardTab(
    overview: AccountOverview?,
    legacyStatus: StoreStatus?,
    stores: List<AccountStoreView>,
    multi: Boolean,
    activeStoreId: Long,
    activeStoreName: String,
    lastSyncMs: Long,
    online: Boolean,
    loading: Boolean,
    onOpenStorePicker: () -> Unit,
    onRefresh: () -> Unit,
    onOpenPending: () -> Unit,
    onOpenStores: () -> Unit,
    onShowPaid: () -> Unit,
    onShowAllTx: () -> Unit,
    onShowExpired: () -> Unit,
    onAddStore: () -> Unit,
    onPickStore: (Long) -> Unit,
) {
    val income = overview?.today?.income_rial ?: legacyStatus?.today?.income_rial ?: 0
    val paid = overview?.today?.paid_count ?: legacyStatus?.today?.paid_count ?: 0
    val reviewCount = overview?.today?.unmatched ?: legacyStatus?.today?.unmatched ?: 0
    val totalCount = overview?.today?.transactions ?: legacyStatus?.today?.transactions ?: 0
    val expiredCount = overview?.today?.expired ?: legacyStatus?.today?.expired ?: 0

    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // انتخاب فروشگاه (مطابق طرح) — با لوگوی سایت فروشگاه فعال
        val activeLogo = stores.firstOrNull { it.id == activeStoreId }?.logo_url ?: stores.firstOrNull()?.logo_url ?: ""
        StoreSelectorRow(
            storeName = activeStoreName,
            onClick = onOpenStorePicker,
            logoUrl = activeLogo,
        )

        // خط وضعیت: آخرین بروزرسانی + آنلاین (مطابق طرح)
        StatusLine(
            lastUpdate = if (lastSyncMs > 0) {
                stringResource(R.string.last_update_fmt, Fa.timeLabel(lastSyncMs))
            } else {
                stringResource(R.string.tap_to_refresh)
            },
            online = online,
            onClick = onRefresh,
        )

        Spacer(Modifier.height(2.dp))

        // کارت هیرو درآمد امروز — دور ۲۵: حاشیهٔ افقی مثل بقیهٔ کارت‌ها (حالت کارتی)
        HeroIncomeCard(
            modifier = Modifier.padding(horizontal = 20.dp),
            title = stringResource(R.string.today_income),
            amount = Fa.rialAsToman(income),
            unit = stringResource(R.string.toman),
            greenLine = stringResource(R.string.paid_today_fmt, Fa.number(paid.toLong())),
        )

        // چهار کارت آمار (مطابق طرح)
        Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            StatCard(
                label = stringResource(R.string.review_needed),
                value = Fa.number(reviewCount.toLong()),
                icon = {
                    StatIconTile(bg = AmberTile) {
                        ClockIcon(color = AmberIcon, modifier = Modifier.size(22.dp))
                    }
                },
                onClick = onOpenPending,
                modifier = Modifier.weight(1f),
            )
            StatCard(
                label = stringResource(R.string.successful_payments),
                value = Fa.number(paid.toLong()),
                icon = {
                    StatIconTile(bg = GreenTile) {
                        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = GreenIcon, modifier = Modifier.size(24.dp))
                    }
                },
                onClick = onShowPaid,
                modifier = Modifier.weight(1f),
            )
        }
        Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            StatCard(
                label = stringResource(R.string.stat_total_transactions),
                value = Fa.number(totalCount.toLong()),
                icon = {
                    StatIconTile(bg = LavenderChip) {
                        Icon(Icons.AutoMirrored.Filled.List, contentDescription = null, tint = VioletBright, modifier = Modifier.size(24.dp))
                    }
                },
                onClick = onShowAllTx,
                modifier = Modifier.weight(1f),
            )
            StatCard(
                label = stringResource(R.string.stat_expired),
                value = Fa.number(expiredCount.toLong()),
                icon = {
                    StatIconTile(bg = RedTile) {
                        HourglassIcon(circle = RedIcon, glyph = Color.White, modifier = Modifier.size(22.dp))
                    }
                },
                onClick = onShowExpired,
                modifier = Modifier.weight(1f),
            )
        }

        // فروشگاه‌های من | مشاهده همه › (مطابق طرح)
        if (stores.isNotEmpty()) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 20.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.my_stores), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text(
                    stringResource(R.string.view_all),
                    color = VioletBright,
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(onClick = onOpenStores),
                )
            }
        }
        stores.take(3).forEach { st ->
            val per = overview?.per_store?.firstOrNull { it.store_id == st.id }
            StoreCard(
                name = st.name,
                domain = st.domain,
                connected = st.connection_status == "connected",
                onClick = { if (!multi) onOpenStores() else if (activeStoreId != st.id) onPickStore(st.id) },
                modifier = Modifier.padding(horizontal = 20.dp),
                logoUrl = st.logo_url,
            )
        }
    }
}

// ═══════════════════════════════════════════════════════ تب تراکنش‌ها

@Composable
private fun TransactionsTab(
    rows: List<AccountTxRow>?,
    loading: Boolean,
    error: String?,
    filter: String,
    todayIncomeRial: Long,
    more: Boolean,
    localHistory: List<TxRecordEntity>,
    onFilter: (String) -> Unit,
    onLoadMore: () -> Unit,
    onRefresh: () -> Unit = {},
) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    // دور ۲۶ — × روی کارت‌ها: حذف از بررسی‌ها / بستن پرداخت باز (با تأیید)
    var dismissConfirmFor by remember { mutableStateOf(0L) }
    var closeConfirmFor by remember { mutableStateOf(0L) }

    fun dismissTxn(txnId: Long) {
        scope.launch {
            try {
                Api(prefs.serverUrl).dismissTransaction(prefs.deviceId, prefs.deviceSecret, txnId)
                Toast.makeText(context, context.getString(R.string.dismiss_done_toast), Toast.LENGTH_SHORT).show()
                onRefresh()
            } catch (e: Exception) {
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
        }
    }

    fun closePay(paymentId: Long) {
        scope.launch {
            try {
                Api(prefs.serverUrl).closePayment(prefs.deviceId, prefs.deviceSecret, paymentId)
                Toast.makeText(context, context.getString(R.string.close_done_toast), Toast.LENGTH_SHORT).show()
                onRefresh()
            } catch (e: Exception) {
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
        }
    }

    Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // چیپ‌های فیلتر — برچسب‌ها مطابق طرح
        Row(
            Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            TX_FILTERS.forEach { (key, labelRes) ->
                androidx.compose.material3.FilterChip(
                    selected = filter == key,
                    onClick = { onFilter(key) },
                    label = { Text(stringResource(labelRes)) },
                    colors = androidx.compose.material3.FilterChipDefaults.filterChipColors(
                        containerColor = Color.White,
                        labelColor = Color(0xFF5C6480),
                        selectedContainerColor = LavenderChip,
                        selectedLabelColor = DeepViolet,
                    ),
                )
            }
        }

        if (loading && rows == null) {
            Row(Modifier.fillMaxWidth().padding(24.dp), horizontalArrangement = Arrangement.Center) {
                CircularProgressIndicator(Modifier.size(28.dp))
            }
        } else if (rows != null) {
            if (rows.isEmpty()) {
                Card(
                    Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                ) {
                    Text(
                        stringResource(R.string.tx_list_empty),
                        Modifier.fillMaxWidth().padding(20.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        color = Color(0xFF5C6480),
                        textAlign = TextAlign.Center,
                    )
                }
            }
            rows.forEach { row ->
                TxRowDesign(row) {
                    if (row.status == "unmatched") dismissConfirmFor = row.id else closeConfirmFor = row.id
                }
            }
            if (more) {
                Card(
                    Modifier.fillMaxWidth().clickable(onClick = onLoadMore),
                    shape = RoundedCornerShape(14.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                ) {
                    Row(Modifier.fillMaxWidth().padding(vertical = 11.dp), horizontalArrangement = Arrangement.Center) {
                        Text(stringResource(R.string.show_more), color = DeepViolet, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleSmall)
                    }
                }
            }
        } else {
            // سرور قدیمی — تاریخچهٔ محلی
            if (localHistory.isEmpty()) {
                Card(
                    Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                ) {
                    Text(
                        stringResource(R.string.tx_list_empty),
                        Modifier.fillMaxWidth().padding(20.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        color = Color(0xFF5C6480),
                        textAlign = TextAlign.Center,
                    )
                }
            } else {
                Text(stringResource(R.string.sms_local_history), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                localHistory.forEach { r -> LocalTxRow(r) }
            }
        }

        // مجموع امروز (مطابق طرح)
        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            colors = CardDefaults.cardColors(containerColor = LavenderChip),
        ) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 13.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.today_total), color = DeepViolet, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Text(
                    Fa.rialAsToman(todayIncomeRial) + " تومان",
                    color = DeepViolet,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                )
            }
        }
    }

    // ── دور ۲۶: تأیید حذف تراکنش از بررسی‌ها ──
    if (dismissConfirmFor > 0) {
        AlertDialog(
            onDismissRequest = { dismissConfirmFor = 0 },
            title = { Text(stringResource(R.string.pending_dismiss_title)) },
            text = { Text(stringResource(R.string.pending_dismiss_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    val id = dismissConfirmFor
                    dismissConfirmFor = 0
                    dismissTxn(id)
                }) { Text(stringResource(R.string.pending_dismiss_btn), color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { dismissConfirmFor = 0 }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }

    // ── دور ۲۶: تأیید بستن پرداخت باز ──
    if (closeConfirmFor > 0) {
        AlertDialog(
            onDismissRequest = { closeConfirmFor = 0 },
            title = { Text(stringResource(R.string.pending_close_title)) },
            text = { Text(stringResource(R.string.pending_close_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    val id = closeConfirmFor
                    closeConfirmFor = 0
                    closePay(id)
                }) { Text(stringResource(R.string.pending_close_btn), color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { closeConfirmFor = 0 }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

/** ردیف تراکنش — مطابق طرح: نماد وضعیت + عنوان + بانک/زمان | مبلغ رنگ‌وضعیت. */
@Composable
private fun TxRowDesign(row: AccountTxRow, onRemove: (() -> Unit)? = null) {
    val st = row.status
    val amountToman = Fa.rialAsToman(row.amount_rial)
    val title = when {
        row.kind == "txn" -> stringResource(R.string.tx_deposit_fmt, Fa.number(row.amount_rial / 10))
        st == "paid" -> stringResource(R.string.tx_payment_paid_fmt, amountToman)
        else -> stringResource(R.string.tx_payment_fmt, amountToman)
    }
    val time = serverTimeLabel(if (row.kind == "txn") row.occurred_at else row.created_at)
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier
                    .size(38.dp)
                    .background(statusColor(st).copy(alpha = 0.14f), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                Text(statusGlyph(st), color = statusColor(st), fontWeight = FontWeight.Bold, style = MaterialTheme.typography.bodyLarge)
            }
            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    (if (row.bank.isNotEmpty()) stringResource(R.string.bank_fmt, row.bank) else "") +
                        (if (time.isNotEmpty()) (if (row.bank.isNotEmpty()) " · " else "") + time else ""),
                    style = MaterialTheme.typography.labelSmall,
                    color = Color(0xFF5C6480),
                    maxLines = 1,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(
                    amountToman + " تومان",
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Bold,
                    color = statusColor(st),
                )
                Text(statusLabel(st), style = MaterialTheme.typography.labelSmall, color = statusColor(st))
            }
            // دور ۲۶ — × : حذف از بررسی‌ها (unmatched) یا بستن پرداخت باز (در انتظار)
            if (onRemove != null && (st == "unmatched" || st == "pending" || st == "verifying")) {
                androidx.compose.material3.IconButton(
                    onClick = onRemove,
                    modifier = Modifier.size(30.dp),
                ) {
                    Text("×", color = Color(0xFF8F87B5), style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/** ردیف تاریخچهٔ محلی (سرورهای قدیمی). */
@Composable
private fun LocalTxRow(record: TxRecordEntity) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(record.bank, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold)
                ir.peakopay.app.ui.common.AmountText(record.amount_rial, emphasize = false)
                if (!record.message.isNullOrEmpty()) {
                    Text(record.message, style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480), maxLines = 1)
                }
            }
            Text(statusLabel(record.result), style = MaterialTheme.typography.labelSmall, color = statusColor(record.result))
        }
    }
}

// ═══════════════════════════════════════════════════════ تب پیامک‌ها

/**
 * تب پیامک‌ها — فقط فرستنده‌های ثبت‌شده در قالب کارت‌ها نمایش داده می‌شوند
 * (حریم خصوصی دور ۲۱: پیامک شماره‌های ثبت‌نشده نه بررسی می‌شود نه ثبت؛
 * در نمایش هم دوباره در برابر پیکربندی محلی فیلتر می‌شود).
 */
@Composable
private fun SmsTab(
    context: android.content.Context,
    outboxPending: Int,
    history: List<TxRecordEntity>,
    onOpenDiagnostics: () -> Unit,
) {
    // فقط رخدادهای فرستنده‌های ثبت‌شده — دقیقاً همان معیار موتور (کارت‌های فعال + SenderNorm)
    val registered = remember {
        val cfg = ir.peakopay.app.data.CardConfigStore.load(context)
        val senders = cfg.cards.filter { it.enabled }.flatMap { it.senders }
        Prefs.get(context).pipelineEvents().filter { ev ->
            ir.peakopay.app.sms.SenderNorm.matches(ev.sender, senders)
        }
    }

    Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // راهنمای بهینه‌سازی (مطابق طرح)
        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            colors = CardDefaults.cardColors(containerColor = LavenderChip),
        ) {
            Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("💡", style = MaterialTheme.typography.bodyLarge)
                Text(
                    stringResource(R.string.optimize_hint),
                    style = MaterialTheme.typography.bodySmall,
                    color = DeepViolet,
                )
            }
        }

        // صف پیامک‌ها (مطابق طرح)
        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(18.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
        ) {
            Column(Modifier.fillMaxWidth().padding(14.dp)) {
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(stringResource(R.string.sms_queue_title), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
                    Text(
                        if (outboxPending > 0) Fa.number(outboxPending.toLong()) else "۰",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = if (outboxPending > 0) Warning else Success,
                    )
                }
                if (outboxPending > 0) {
                    Spacer(Modifier.height(6.dp))
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            stringResource(R.string.offline_queue_hint),
                            style = MaterialTheme.typography.labelSmall,
                            color = Color(0xFF5C6480),
                        )
                        TextButton(onClick = { UploadWorker.enqueueNow(context) }) {
                            Text(stringResource(R.string.retry_now))
                        }
                    }
                }
            }
        }

        // رویدادهای اخیر (فقط فرستنده‌های ثبت‌شده)
        Text(stringResource(R.string.sms_recent_title), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        if (registered.isEmpty()) {
            Card(
                Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(18.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
            ) {
                Text(
                    stringResource(R.string.sms_empty),
                    Modifier.fillMaxWidth().padding(20.dp),
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color(0xFF5C6480),
                    textAlign = TextAlign.Center,
                )
            }
        } else {
            registered.forEach { ev ->
                val (label, color) = when (ev.kind) {
                    "queued" -> stringResource(R.string.sms_event_queued) to VioletBright
                    "no_rule" -> stringResource(R.string.sms_event_no_rule) to Warning
                    else -> ev.kind to Color(0xFF5C6480)
                }
                Card(
                    Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(16.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 11.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(8.dp).background(color, CircleShape))
                        Column(Modifier.weight(1f)) {
                            Text(label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold)
                            Text(
                                stringResource(R.string.sms_sender_fmt, ev.sender) + (if (ev.detail.isNotEmpty()) " · " + ev.detail else ""),
                                style = MaterialTheme.typography.labelSmall,
                                color = Color(0xFF5C6480),
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                        }
                        Text(Fa.timeLabel(ev.ts), style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480))
                    }
                }
            }
            Card(
                Modifier.fillMaxWidth().clickable(onClick = onOpenDiagnostics),
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
            ) {
                Row(Modifier.fillMaxWidth().padding(vertical = 11.dp), horizontalArrangement = Arrangement.Center) {
                    Text(stringResource(R.string.sms_full_diagnostics), color = DeepViolet, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleSmall)
                }
            }
        }

        // تاریخچهٔ ارسال این دستگاه
        if (history.isNotEmpty()) {
            Text(stringResource(R.string.sms_local_history), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            history.take(20).forEach { r -> LocalTxRow(r) }
        }
    }
}

// ═══════════════════════════════════════════════════════ تب تنظیمات (منو)

@Composable
private fun SettingsTab(
    versionName: String,
    onOpenAccount: () -> Unit,
    onOpenStores: () -> Unit,
    onOpenCards: () -> Unit,
    onOpenStatus: () -> Unit,
    onOpenNotifications: () -> Unit,
    onDisconnect: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        MenuRow(
            glyph = "👤",
            title = stringResource(R.string.menu_account),
            desc = stringResource(R.string.menu_account_desc),
            onClick = onOpenAccount,
        )
        MenuRow(
            glyph = "🏬",
            title = stringResource(R.string.menu_stores),
            desc = stringResource(R.string.menu_stores_desc),
            onClick = onOpenStores,
        )
        MenuRow(
            glyph = "💳",
            title = stringResource(R.string.menu_payments),
            desc = stringResource(R.string.menu_payments_desc),
            onClick = onOpenCards,
        )
        MenuRow(
            glyph = "📱",
            title = stringResource(R.string.menu_device),
            desc = stringResource(R.string.menu_device_desc),
            onClick = onOpenStatus,
        )
        MenuRow(
            glyph = "🔔",
            title = stringResource(R.string.menu_notifications),
            desc = stringResource(R.string.menu_notifications_desc),
            onClick = onOpenNotifications,
        )
        MenuRow(
            glyph = "⏻",
            title = stringResource(R.string.disconnect),
            desc = stringResource(R.string.disconnect_confirm),
            onClick = onDisconnect,
        )
        Spacer(Modifier.height(4.dp))
        BrandFooter(versionName = versionName)
    }
}

@Composable
private fun StorePickerRow(
    label: String,
    logoUrl: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(if (selected) LavenderChip else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 7.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ir.peakopay.app.ui.common.StoreAvatar(
            logoUrl = logoUrl,
            modifier = Modifier.size(38.dp),
            shape = RoundedCornerShape(50),
            glyphSize = 18,
        )
        Text(
            label,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
            modifier = Modifier.weight(1f),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        if (selected) {
            Text("✓", color = DeepViolet, fontWeight = FontWeight.Bold)
        }
    }
}
