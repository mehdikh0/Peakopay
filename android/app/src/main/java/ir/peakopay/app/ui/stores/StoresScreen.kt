package ir.peakopay.app.ui.stores

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.List
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.AccountOverview
import ir.peakopay.app.data.api.Api.AccountTxRow
import ir.peakopay.app.ui.common.AmountText
import ir.peakopay.app.ui.common.ResultChip
import ir.peakopay.app.ui.common.StatCard
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.ui.theme.Warning
import ir.peakopay.app.util.Fa
import kotlinx.coroutines.launch

/**
 * دور ۲۳ — فروشگاه‌های حساب (چندفروشگاهی):
 *  - نمای کلی هر فروشگاه (درآمد امروز، پرداخت موفق، در انتظار، وضعیت اتصال ووکامرس)
 *  - آخرین پرداخت‌ها/تراکنش‌های حساب با فیلتر فروشگاه و وضعیت
 * داده‌ها کاملاً ایزوله‌اند؛ سرور فقط فروشگاه‌های همان حساب را برمی‌گرداند.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StoresScreen(onBack: () -> Unit, onAddStore: () -> Unit = {}) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var overview by remember { mutableStateOf<AccountOverview?>(null) }
    var stores by remember { mutableStateOf(prefs.cachedStores()) }
    var rows by remember { mutableStateOf<List<AccountTxRow>>(emptyList()) }
    var filterStore by remember { mutableStateOf(0L) }      // ۰ = همه
    var filterStatus by remember { mutableStateOf("all") }
    var page by remember { mutableStateOf(1) }
    var more by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var loadingMore by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun loadTxns(reset: Boolean) {
        scope.launch {
            if (reset) { loading = true; page = 1 } else loadingMore = true
            try {
                val api = Api(prefs.serverUrl)
                val res = api.accountTransactions(
                    prefs.deviceId, prefs.deviceSecret,
                    storeId = filterStore, status = filterStatus,
                    page = if (reset) 1 else page + 1,
                )
                rows = if (reset) res.transactions else rows + res.transactions
                more = res.more
                page = res.page
            } catch (e: Exception) {
                error = e.message
            } finally {
                loading = false
                loadingMore = false
            }
        }
    }

    fun load() {
        scope.launch {
            loading = true
            error = null
            try {
                val api = Api(prefs.serverUrl)
                val ov = api.accountOverview(prefs.deviceId, prefs.deviceSecret)
                overview = ov
                stores = ov.stores
                prefs.setCachedStores(ov.stores)
                loadTxns(reset = true)
            } catch (e: Exception) {
                error = e.message
            } finally {
                loading = false
            }
        }
    }

    LaunchedEffect(Unit) { load() }

    val statusFilters = listOf(
        "all" to stringResource(R.string.stores_filter_all),
        "paid" to stringResource(R.string.stores_filter_paid),
        "pending" to stringResource(R.string.stores_filter_pending),
        "expired" to stringResource(R.string.stores_filter_expired),
        "failed" to stringResource(R.string.stores_filter_failed),
        "unmatched" to stringResource(R.string.stores_filter_unmatched),
    )

    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        ir.peakopay.app.ui.common.PurpleHeader(
            subtitle = stringResource(R.string.stores_title),
            onBack = onBack,
            onRefresh = { load() },
            refreshing = loading,
        )
        LazyColumn(
            Modifier.fillMaxSize().weight(1f).padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            item { Spacer(Modifier.height(4.dp)) }

            if (error != null) {
                item {
                    Card(
                        Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    ) {
                        Text(
                            error!!,
                            Modifier.padding(16.dp),
                            color = MaterialTheme.colorScheme.error,
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
            }

            // خلاصهٔ کل حساب
            val ov = overview
            if (ov != null) {
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
                        StatCard(
                            title = stringResource(R.string.stores_today_income),
                            value = Fa.rialAsToman(ov.today.income_rial) + " تومان",
                            subtitle = Fa.number(ov.today.income_rial) + " ریال",
                            modifier = Modifier.weight(1f),
                        )
                        StatCard(
                            title = stringResource(R.string.today_paid),
                            value = Fa.number(ov.today.paid_count.toLong()),
                            subtitle = stringResource(R.string.stores_pending) + ": " + Fa.number(ov.today.pending.toLong()),
                            accent = androidx.compose.ui.graphics.Color(0xFF7C3AED),
                            modifier = Modifier.weight(1f),
                        )
                    }
                }
            }

            // کارت هر فروشگاه
            item {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("🏬", style = MaterialTheme.typography.titleMedium)
                    Text(stringResource(R.string.stores_title), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                }
            }
            if (stores.isEmpty() && !loading) {
                item {
                    Card(
                        Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    ) {
                        Text(
                            stringResource(R.string.stores_txns_empty),
                            Modifier.padding(20.dp),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            // دور ۲۵ — افزودن فروشگاه (چنداکانتی)
            item {
                Card(
                    Modifier
                        .fillMaxWidth()
                        .clickable(onClick = onAddStore),
                    shape = RoundedCornerShape(20.dp),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        Text("＋", style = MaterialTheme.typography.titleLarge, color = ir.peakopay.app.ui.theme.DeepViolet)
                        Column {
                            Text(
                                stringResource(R.string.add_store),
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold,
                                color = ir.peakopay.app.ui.theme.DeepViolet,
                            )
                            Text(
                                stringResource(R.string.add_store_desc),
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }
            items(stores, key = { it.id }) { st ->
                val per = overview?.per_store?.firstOrNull { it.store_id == st.id }
                Card(
                    Modifier.fillMaxWidth().clickable {
                        filterStore = if (filterStore == st.id) 0L else st.id
                        loadTxns(reset = true)
                    },
                    shape = RoundedCornerShape(20.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = if (filterStore == st.id) MaterialTheme.colorScheme.primaryContainer
                        else MaterialTheme.colorScheme.surface,
                    ),
                    elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                ) {
                    Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Row(
                            Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(st.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                if (st.domain.isNotEmpty()) {
                                    Text(
                                        st.domain,
                                        style = MaterialTheme.typography.labelMedium,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                            // دور ۲۶ — لوگوی سایت فروشگاه
                            ir.peakopay.app.ui.common.StoreAvatar(
                                logoUrl = st.logo_url,
                                modifier = Modifier.size(46.dp),
                                shape = RoundedCornerShape(15.dp),
                                glyphSize = 24,
                            )
                            val connected = st.connection_status == "connected"
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                androidx.compose.foundation.layout.Box(
                                    Modifier
                                        .size(8.dp)
                                        .background(
                                            if (connected) Success else Warning,
                                            androidx.compose.foundation.shape.CircleShape,
                                        ),
                                )
                                Text(
                                    stringResource(if (connected) R.string.stores_connected else R.string.stores_pending_conn),
                                    style = MaterialTheme.typography.labelMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                            Column {
                                Text(stringResource(R.string.stores_today_income), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Text(Fa.rialAsToman(per?.today_income_rial ?: 0) + " تومان", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
                            }
                            Column {
                                Text(stringResource(R.string.stores_paid_today), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Text(Fa.number((per?.today_paid ?: 0).toLong()), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
                            }
                            Column {
                                Text(stringResource(R.string.stores_pending), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Text(Fa.number((per?.pending ?: 0).toLong()), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
                            }
                        }
                    }
                }
            }

            // فیلتر وضعیت
            item {
                Row(
                    Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    statusFilters.forEach { (key, label) ->
                        FilterChip(
                            selected = filterStatus == key,
                            onClick = { filterStatus = key; loadTxns(reset = true) },
                            label = { Text(label) },
                        )
                    }
                }
            }

            // فهرست پرداخت‌ها/تراکنش‌ها
            item {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.AutoMirrored.Filled.List, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Text(
                        stringResource(R.string.stores_txns_title) +
                            (stores.firstOrNull { it.id == filterStore }?.let { " — " + it.name } ?: ""),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
            if (loading) {
                item {
                    Row(Modifier.fillMaxWidth().padding(20.dp), horizontalArrangement = Arrangement.Center) {
                        CircularProgressIndicator(Modifier.size(28.dp))
                    }
                }
            } else if (rows.isEmpty()) {
                item {
                    Card(
                        Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                    ) {
                        Text(
                            stringResource(R.string.stores_txns_empty),
                            Modifier.padding(20.dp),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            } else {
                items(rows, key = { "${it.kind}-${it.id}" }) { row -> AccountTxRowView(row, showStore = filterStore == 0L && stores.size > 1) }
                if (more) {
                    item {
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                            if (loadingMore) {
                                CircularProgressIndicator(Modifier.size(22.dp))
                            } else {
                                TextButton(onClick = { loadTxns(reset = false) }) {
                                    Text(stringResource(R.string.stores_txns_more))
                                }
                            }
                        }
                    }
                }
            }

            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}

/** یک ردیف پرداخت/تراکنش حساب — با نشان فروشگاه در حالت چندفروشگاهی. */
@Composable
private fun AccountTxRowView(row: AccountTxRow, showStore: Boolean) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                if (showStore && row.store_name.isNotEmpty()) {
                    Text(
                        row.store_name,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.primary,
                        fontWeight = FontWeight.Bold,
                    )
                }
                Text(
                    if (row.kind == "payment") "سفارش #" + Fa.digits(row.order_id.ifEmpty { "-" }) else row.bank,
                    style = MaterialTheme.typography.titleMedium,
                )
                AmountText(row.amount_rial, emphasize = false)
                if (row.is_test) {
                    Text(
                        stringResource(R.string.test_suffix),
                        style = MaterialTheme.typography.labelSmall,
                        color = Warning,
                    )
                }
            }
            ResultChip(row.status)
        }
    }
}
