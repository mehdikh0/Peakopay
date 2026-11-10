package ir.peakopay.app.ui.pending

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.R
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.PendingOverview
import ir.peakopay.app.ui.theme.Danger
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.util.Err
import ir.peakopay.app.util.Fa
import android.widget.Toast
import androidx.compose.material3.Icon
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Refresh
import kotlinx.coroutines.launch

/**
 * «در انتظار تأیید» — پرداخت‌های منتظر تصمیم فروشنده و تراکنش‌های بدون تطبیق،
 * با تأیید/اتصال دستی از خود اپ (بدون باز کردن پنل).
 *
 *  - تأیید: پرداخت‌های verifying (تطبیق خودکار خاموش یا نیازمند تأیید مدیر)
 *  - اتصال: تراکنش unmatched به یکی از پرداخت‌های باز (هم‌مبلغ اول فهرست)
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun PendingScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var overview by remember { mutableStateOf<PendingOverview?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf("") }
    var busyId by remember { mutableStateOf(0L) } // id آیتمی که رویش کار می‌کنیم
    var actionMsg by remember { mutableStateOf("") }
    // انتخاب پرداخت برای اتصال: شناسه تراکنش → شناسه پرداخت انتخاب‌شده
    var pickerForTxn by remember { mutableStateOf(0L) }
    var chosen by remember { mutableStateOf(mapOf<Long, Long>()) } // txnId → payId
    // دور ۲۵ — × کنار کارت‌ها: بستن پرداخت / حذف تراکنش از بررسی‌ها (با تأیید)
    var closeConfirmFor by remember { mutableStateOf(0L) }
    var dismissConfirmFor by remember { mutableStateOf(0L) }

    fun load() {
        scope.launch {
            loading = true
            error = ""
            try {
                val api = Api(prefs.serverUrl)
                overview = api.pendingOverview(prefs.deviceId, prefs.deviceSecret)
            } catch (e: Exception) {
                error = e.message ?: "خطای نامشخص"
            }
            loading = false
        }
    }

    // بارگذاری اول
    LaunchedEffect(Unit) { load() }

    fun confirm(paymentId: Long) {
        scope.launch {
            busyId = paymentId
            try {
                Api(prefs.serverUrl).confirmPayment(prefs.deviceId, prefs.deviceSecret, paymentId)
                actionMsg = context.getString(R.string.pending_confirmed)
                Toast.makeText(context, context.getString(R.string.pending_confirmed), Toast.LENGTH_SHORT).show()
                load()
            } catch (e: Exception) {
                error = Err.friendly(e)
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
            busyId = 0
        }
    }

    fun attach(txnId: Long, payId: Long) {
        scope.launch {
            busyId = txnId
            try {
                Api(prefs.serverUrl).attachTransaction(prefs.deviceId, prefs.deviceSecret, txnId, payId)
                actionMsg = context.getString(R.string.pending_attached)
                Toast.makeText(context, context.getString(R.string.pending_attached), Toast.LENGTH_SHORT).show()
                load()
            } catch (e: Exception) {
                error = Err.friendly(e)
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
            busyId = 0
        }
    }

    /** دور ۲۵ — بستن پرداخت باز: failed + لغو سفارش در ووکامرس. */
    fun closePayment(paymentId: Long) {
        scope.launch {
            busyId = paymentId
            try {
                Api(prefs.serverUrl).closePayment(prefs.deviceId, prefs.deviceSecret, paymentId)
                actionMsg = context.getString(R.string.pending_close_done)
                Toast.makeText(context, context.getString(R.string.pending_close_done), Toast.LENGTH_SHORT).show()
                load()
            } catch (e: Exception) {
                error = Err.friendly(e)
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
            busyId = 0
        }
    }

    /** دور ۲۵ — حذف تراکنش از فهرست «نیاز به بررسی». */
    fun dismissTxn(txnId: Long) {
        scope.launch {
            busyId = txnId
            try {
                Api(prefs.serverUrl).dismissTransaction(prefs.deviceId, prefs.deviceSecret, txnId)
                actionMsg = context.getString(R.string.pending_dismiss_done)
                Toast.makeText(context, context.getString(R.string.dismiss_done_toast), Toast.LENGTH_SHORT).show()
                load()
            } catch (e: Exception) {
                error = Err.friendly(e)
                Toast.makeText(context, Err.friendly(e), Toast.LENGTH_LONG).show()
            }
            busyId = 0
        }
    }

    val ov = overview
    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        ir.peakopay.app.ui.common.PurpleHeader(
            subtitle = context.getString(R.string.pending_title),
            onBack = onBack,
            onRefresh = { load() },
            refreshing = loading,
        )
        Column(
            Modifier
                .fillMaxSize()
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (actionMsg.isNotEmpty()) {
                Text(actionMsg, color = Success, style = MaterialTheme.typography.titleSmall)
            }
            if (error.isNotEmpty()) {
                Text(error, color = Danger, style = MaterialTheme.typography.bodyMedium)
            }

            when {
                loading && ov == null -> Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.Center,
                ) { CircularProgressIndicator() }

                ov == null -> {}

                ov.awaiting.isEmpty() && ov.unmatched.isEmpty() -> Card(
                    Modifier.fillMaxWidth(),
                    colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                ) {
                    Text(
                        context.getString(R.string.pending_empty),
                        Modifier.padding(18.dp),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                else -> {
                    // ── بخش ۱: پرداخت‌های در انتظار تأیید ──
                    if (ov.awaiting.isNotEmpty()) {
                        Text(
                            context.getString(R.string.pending_awaiting_section),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                        )
                        ov.awaiting.forEach { p ->
                            Card(
                                Modifier.fillMaxWidth(),
                                shape = RoundedCornerShape(16.dp),
                                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                                elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                            ) {
                                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                        Text(
                                            "سفارش #" + Fa.digits(p.order_id),
                                            style = MaterialTheme.typography.titleMedium,
                                            modifier = Modifier.weight(1f),
                                        )
                                        // دور ۲۵ — بستن پرداخت + لغو سفارش در ووکامرس
                                        IconButton(
                                            onClick = { closeConfirmFor = p.id },
                                            enabled = busyId == 0L,
                                        ) {
                                            Icon(
                                                Icons.Filled.Close,
                                                contentDescription = context.getString(R.string.pending_close_title),
                                                tint = Danger,
                                                modifier = Modifier.size(20.dp),
                                            )
                                        }
                                    }
                                    Text(
                                        Fa.amountLabel(p.amount_rial),
                                        style = MaterialTheme.typography.titleLarge,
                                        color = MaterialTheme.colorScheme.primary,
                                    )
                                    if ((p.txn_id ?: 0L) > 0L) {
                                        Text(
                                            (if (!(p.bank ?: "").isEmpty()) (p.bank ?: "") + " • " else "") +
                                                context.getString(R.string.pending_sms_time) + " " + Fa.digits((p.occurred_at ?: "").take(16).replace('T', ' ')),
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    } else {
                                        // پرداخت باز بدون پیامک — تأیید دستی در هر حالتی
                                        Text(
                                            "بدون پیامک — تأیید دستی فروشنده",
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                    Button(
                                        onClick = { confirm(p.id) },
                                        enabled = busyId == 0L,
                                        modifier = Modifier.padding(top = 6.dp),
                                    ) {
                                        if (busyId == p.id) {
                                            CircularProgressIndicator(
                                                Modifier.height(16.dp),
                                                strokeWidth = 2.dp,
                                                color = MaterialTheme.colorScheme.onPrimary,
                                            )
                                        } else {
                                            Text(context.getString(R.string.pending_confirm_btn))
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // ── بخش ۲: تراکنش‌های بدون تطبیق ──
                    if (ov.unmatched.isNotEmpty()) {
                        Text(
                            context.getString(R.string.pending_unmatched_section),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                        )
                        ov.unmatched.forEach { t ->
                            val selectedPay = ov.open.firstOrNull { it.id == (chosen[t.id] ?: 0L) }
                            Card(
                                Modifier.fillMaxWidth(),
                                shape = RoundedCornerShape(16.dp),
                                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                                elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                            ) {
                                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                        Text(
                                            Fa.amountLabel(t.amount_rial),
                                            style = MaterialTheme.typography.titleLarge,
                                            color = MaterialTheme.colorScheme.primary,
                                            modifier = Modifier.weight(1f),
                                        )
                                        // دور ۲۵ — حذف از فهرست بررسی‌ها
                                        IconButton(
                                            onClick = { dismissConfirmFor = t.id },
                                            enabled = busyId == 0L,
                                        ) {
                                            Icon(
                                                Icons.Filled.Close,
                                                contentDescription = context.getString(R.string.pending_dismiss_title),
                                                tint = Danger,
                                                modifier = Modifier.size(20.dp),
                                            )
                                        }
                                    }
                                    Text(
                                        (if (t.bank.isNotEmpty()) t.bank + " • " else "") +
                                            Fa.digits(t.occurred_at.take(16).replace('T', ' ')),
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                    if (t.reason.isNotEmpty()) {
                                        Text(
                                            context.getString(R.string.pending_reason_label) + " " + t.reason,
                                            style = MaterialTheme.typography.bodySmall,
                                            color = Danger,
                                        )
                                    }
                                    Row(
                                        Modifier.fillMaxWidth().padding(top = 6.dp),
                                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        OutlinedButton(onClick = { pickerForTxn = t.id }, enabled = busyId == 0L && ov.open.isNotEmpty()) {
                                            Text(
                                                if (ov.open.isEmpty()) context.getString(R.string.pending_no_open_payments)
                                                else if (selectedPay != null) "#" + Fa.digits(selectedPay.order_id) + " — " + Fa.amountLabel(selectedPay.amount_rial)
                                                else context.getString(R.string.pending_choose_payment),
                                                maxLines = 1,
                                            )
                                        }
                                        Button(
                                            onClick = { attach(t.id, chosen[t.id] ?: 0L) },
                                            enabled = busyId == 0L && (chosen[t.id] ?: 0L) > 0,
                                        ) {
                                            if (busyId == t.id) {
                                                CircularProgressIndicator(
                                                    Modifier.height(16.dp),
                                                    strokeWidth = 2.dp,
                                                    color = MaterialTheme.colorScheme.onPrimary,
                                                )
                                            } else {
                                                Text(context.getString(R.string.pending_attach_btn))
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // ── دیالوگ انتخاب پرداخت برای اتصال ──
    if (pickerForTxn > 0) {
        val ov2 = overview
        val opens = ov2?.open ?: emptyList()
        // هم‌مبلغ‌ها اول فهرست
        val txnAmount = ov2?.unmatched?.firstOrNull { it.id == pickerForTxn }?.amount_rial ?: 0
        val sorted = opens.sortedByDescending { it.amount_rial == txnAmount }
        AlertDialog(
            onDismissRequest = { pickerForTxn = 0 },
            title = { Text(context.getString(R.string.pending_choose_payment)) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    sorted.forEach { op ->
                        Row(
                            Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            RadioButton(
                                selected = (chosen[pickerForTxn] ?: 0L) == op.id,
                                onClick = {
                                    chosen = chosen + (pickerForTxn to op.id)
                                    pickerForTxn = 0
                                },
                            )
                            Column {
                                Text("سفارش #" + Fa.digits(op.order_id), style = MaterialTheme.typography.bodyMedium)
                                Text(
                                    Fa.amountLabel(op.amount_rial) + " • " + Fa.digits(op.created_at.take(16).replace('T', ' ')),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { pickerForTxn = 0 }) { Text("بستن") }
            },
        )
    }

    // ── دور ۲۵: تأیید بستن پرداخت (× روی «در انتظار تأیید») ──
    if (closeConfirmFor > 0) {
        AlertDialog(
            onDismissRequest = { closeConfirmFor = 0 },
            title = { Text(context.getString(R.string.pending_close_title)) },
            text = { Text(context.getString(R.string.pending_close_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    val id = closeConfirmFor
                    closeConfirmFor = 0
                    closePayment(id)
                }) { Text(context.getString(R.string.pending_close_btn), color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { closeConfirmFor = 0 }) { Text(context.getString(R.string.cancel)) }
            },
        )
    }

    // ── دور ۲۵: تأیید حذف تراکنش از بررسی‌ها (× روی «نیاز به بررسی») ──
    if (dismissConfirmFor > 0) {
        AlertDialog(
            onDismissRequest = { dismissConfirmFor = 0 },
            title = { Text(context.getString(R.string.pending_dismiss_title)) },
            text = { Text(context.getString(R.string.pending_dismiss_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    val id = dismissConfirmFor
                    dismissConfirmFor = 0
                    dismissTxn(id)
                }) { Text(context.getString(R.string.pending_dismiss_btn), color = Danger) }
            },
            dismissButton = {
                TextButton(onClick = { dismissConfirmFor = 0 }) { Text(context.getString(R.string.cancel)) }
            },
        )
    }
}
