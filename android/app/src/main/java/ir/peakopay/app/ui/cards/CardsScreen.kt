package ir.peakopay.app.ui.cards

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.data.CardConfigStore
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.data.api.Api
import ir.peakopay.app.data.api.Api.MgmtCard
import ir.peakopay.app.sms.CardRuleParser
import ir.peakopay.app.ui.common.CardGlyph
import ir.peakopay.app.ui.common.RedTile
import ir.peakopay.app.ui.common.RedTile
import ir.peakopay.app.ui.common.StatusBadge
import ir.peakopay.app.ui.theme.BgLavender
import ir.peakopay.app.ui.theme.DeepViolet
import ir.peakopay.app.ui.theme.Danger
import ir.peakopay.app.ui.theme.LavenderChip
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.util.Fa
import kotlinx.coroutines.launch

/** برچسب انواع فیلد — همان فهرست پنل */
private val FIELD_LABELS = linkedMapOf(
    "amount" to "مبلغ", "balance" to "مانده", "txn_type" to "نوع تراکنش",
    "card_last4" to "کارت", "date" to "تاریخ", "time" to "ساعت",
    "reference" to "کد پیگیری", "txn_id" to "شناسه تراکنش", "bank" to "بانک",
)

/**
 * مدیریت کارت‌ها + فرستنده‌ها + قالب‌های پیامک — کلاً از خود اپ.
 * ساخت/ویرایش کارت، ساخت قالب با «تحلیل نمونه» (همان موتور پنل، روی سرور)،
 * حذف/فعال‌سازی، تست خط لوله. بعد از هر تغییر، پیکربندی محلی هم‌زمان می‌شود
 * تا خط لوله پیامک فوراً از قالب‌های جدید استفاده کند.
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun CardsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var cards by remember { mutableStateOf<List<MgmtCard>?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf("") }
    var msg by remember { mutableStateOf("") }
    // دور ۲۳ — چندفروشگاهی: کارت‌ها per-store هستند؛ انتخابگر فقط وقتی >۱ فروشگاه
    var stores by remember { mutableStateOf(prefs.cachedStores()) }
    var cardStoreId by remember { mutableStateOf(0L) }

    // دیالوگ‌ها
    var editCard by remember { mutableStateOf<MgmtCard?>(null) }      // null + showEdit=true → کارت جدید
    var showEdit by remember { mutableStateOf(false) }
    var builderCard by remember { mutableStateOf<MgmtCard?>(null) }   // ساخت قالب برای این کارت
    var testCard by remember { mutableStateOf<MgmtCard?>(null) }      // تست خط لوله
    var confirmDelete by remember { mutableStateOf<MgmtCard?>(null) }

    fun load() {
        scope.launch {
            loading = true
            error = ""
            try {
                val api = Api(prefs.serverUrl)
                // کش فروشگاه‌های حساب را تازه کن (برای انتخابگر)
                try {
                    stores = api.accountStores(prefs.deviceId, prefs.deviceSecret).stores
                    prefs.setCachedStores(stores)
                } catch (e: Exception) {
                    // سرور قدیمی — تک‌فروشگاهی
                }
                cards = api.cardsMgmt(prefs.deviceId, prefs.deviceSecret, cardStoreId).cards
                // هم‌زمانی پیکربندی محلی (خط لوله پیامک)
                val c = api.deviceConfig(prefs.deviceId, prefs.deviceSecret)
                CardConfigStore.save(context, CardConfigStore.fromApi(c))
            } catch (e: Exception) {
                error = e.message ?: "خطای نامشخص"
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }

    fun act(card: MgmtCard, action: String, okMsg: String) {
        scope.launch {
            try {
                Api(prefs.serverUrl).cardAction(prefs.deviceId, prefs.deviceSecret, card.id, action)
                msg = okMsg
                load()
            } catch (e: Exception) {
                error = e.message ?: ""
            }
        }
    }

    Column(Modifier.fillMaxSize().background(ir.peakopay.app.ui.theme.BgLavender)) {
        ir.peakopay.app.ui.common.PurpleHeader(
            subtitle = "کارت‌ها و قالب‌های پیامک",
            onBack = onBack,
            onRefresh = { load() },
            refreshing = loading,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (msg.isNotEmpty()) Text(msg, color = Success, style = MaterialTheme.typography.titleSmall)
            if (error.isNotEmpty()) Text(error, color = Danger, style = MaterialTheme.typography.bodyMedium)

            // دور ۲۳ — انتخابگر فروشگاه (کارت‌ها per-store هستند) — استایل طرح جدید
            if (stores.size > 1) {
                Row(
                    Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    androidx.compose.material3.FilterChip(
                        selected = cardStoreId == 0L,
                        onClick = { cardStoreId = 0L; load() },
                        label = { Text("همه") },
                        colors = androidx.compose.material3.FilterChipDefaults.filterChipColors(
                            containerColor = Color.White,
                            labelColor = Color(0xFF5C6480),
                            selectedContainerColor = LavenderChip,
                            selectedLabelColor = DeepViolet,
                        ),
                    )
                    stores.forEach { st ->
                        androidx.compose.material3.FilterChip(
                            selected = cardStoreId == st.id,
                            onClick = { cardStoreId = st.id; load() },
                            label = { Text(st.name) },
                            colors = androidx.compose.material3.FilterChipDefaults.filterChipColors(
                                containerColor = Color.White,
                                labelColor = Color(0xFF5C6480),
                                selectedContainerColor = LavenderChip,
                                selectedLabelColor = DeepViolet,
                            ),
                        )
                    }
                }
            }

            when {
                loading && cards == null -> Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) { CircularProgressIndicator() }
                cards == null -> {}
                else -> {
                    // ── افزودن کارت جدید (طرح جدید: کارت سفید گرد + کاشی بنفش) ──
                    Card(
                        Modifier
                            .fillMaxWidth()
                            .clickable { editCard = null; showEdit = true },
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
                    ) {
                        Row(
                            Modifier.fillMaxWidth().padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(14.dp),
                        ) {
                            Box(
                                Modifier
                                    .size(46.dp)
                                    .background(DeepViolet, RoundedCornerShape(15.dp)),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text("+", color = Color.White, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
                            }
                            Column {
                                Text(
                                    "افزودن کارت جدید",
                                    style = MaterialTheme.typography.titleMedium,
                                    fontWeight = FontWeight.Bold,
                                    color = DeepViolet,
                                )
                                Text(
                                    "کارت مقصد واریز، فرستنده‌های پیامک و قالب را بسازید",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = Color(0xFF5C6480),
                                )
                            }
                        }
                    }
                    if (cards!!.isEmpty()) {
                        Card(
                            Modifier.fillMaxWidth(),
                            shape = RoundedCornerShape(18.dp),
                            colors = CardDefaults.cardColors(containerColor = LavenderChip),
                        ) {
                            Text(
                                "هنوز کارتی ثبت نشده. اول کارت مقصد واریز را اضافه کنید، بعد فرستنده‌های پیامک و قالب را برایش بسازید.",
                                Modifier.fillMaxWidth().padding(16.dp),
                                style = MaterialTheme.typography.bodyMedium,
                                color = DeepViolet,
                            )
                        }
                    }
                    cards!!.forEach { c ->
                        ManagedCardItem(
                            card = c,
                            onEdit = { editCard = c; showEdit = true },
                            onBuildRule = { builderCard = c },
                            onTest = { testCard = c },
                            onToggle = { act(c, "toggle", if (c.enabled) "کارت غیرفعال شد" else "کارت فعال شد") },
                            onDefault = { act(c, "default", "پیش‌فرض شد") },
                            onDelete = { confirmDelete = c },
                            onRuleToggle = { r ->
                                scope.launch {
                                    try {
                                        Api(prefs.serverUrl).ruleAction(prefs.deviceId, prefs.deviceSecret, r.id, "toggle")
                                        load()
                                    } catch (e: Exception) { error = e.message ?: "" }
                                }
                            },
                            onRuleDelete = { r ->
                                scope.launch {
                                    try {
                                        Api(prefs.serverUrl).ruleAction(prefs.deviceId, prefs.deviceSecret, r.id, "delete")
                                        msg = "قالب حذف شد"
                                        load()
                                    } catch (e: Exception) { error = e.message ?: "" }
                                }
                            },
                        )
                    }
                    Card(
                        Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(18.dp),
                        colors = CardDefaults.cardColors(containerColor = LavenderChip),
                    ) {
                        Row(Modifier.fillMaxWidth().padding(14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            Text("💡", style = MaterialTheme.typography.bodyLarge)
                            Text(
                                "بعد از هر تغییر، پیکربندی گوشی خودکار هم‌زمان می‌شود؛ مدیریت کامل کارت/فرستنده/قالب بدون پنل انجام می‌شود.",
                                style = MaterialTheme.typography.bodySmall,
                                color = DeepViolet,
                            )
                        }
                    }
                }
            }
        }
    }

    // ── دیالوگ ساخت/ویرایش کارت ──
    if (showEdit) {
        CardEditDialog(
            initial = editCard,
            onDismiss = { showEdit = false },
            onSave = { label, bank, holder, number, iban, account, senders ->
                showEdit = false
                scope.launch {
                    try {
                        Api(prefs.serverUrl).cardSave(
                            prefs.deviceId, prefs.deviceSecret,
                            editCard?.id ?: 0, label, bank, holder, number, iban, account, senders,
                            storeId = if (editCard == null) cardStoreId else 0,
                        )
                        msg = "کارت ذخیره شد"
                        load()
                    } catch (e: Exception) { error = e.message ?: "" }
                }
            },
        )
    }

    // ── دیالوگ ساخت قالب ──
    builderCard?.let { bc ->
        RuleBuilderDialog(
            card = bc,
            onDismiss = { builderCard = null },
            onSaved = {
                builderCard = null
                msg = "قالب ذخیره شد و خط لوله پیامک به‌روز شد"
                load()
            },
        )
    }

    // ── دیالوگ تست خط لوله ──
    testCard?.let { tc ->
        PipelineTestDialog(
            card = tc,
            onDismiss = { testCard = null },
        )
    }

    // ── تأیید حذف کارت ──
    confirmDelete?.let { dc ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("حذف کارت") },
            text = { Text("کارت «" + (dc.label.ifEmpty { dc.bank_name }) + "» و همه فرستنده‌ها/قالب‌هایش حذف شود؟") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = null
                    act(dc, "delete", "کارت حذف شد")
                }) { Text("حذف", color = Danger) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("انصراف") } },
        )
    }
}

/** دیالوگ ساخت/ویرایش کارت — شماره کارت فقط نوشتنی است (سرور رمزنگاری می‌کند، هرگز برنمی‌گردد) */
@Composable
private fun CardEditDialog(
    initial: MgmtCard?,
    onDismiss: () -> Unit,
    onSave: (label: String, bank: String, holder: String, number: String, iban: String, account: String, senders: List<String>) -> Unit,
) {
    var label by remember { mutableStateOf(initial?.label ?: "") }
    var bank by remember { mutableStateOf(initial?.bank_name ?: "") }
    var holder by remember { mutableStateOf(initial?.holder ?: "") }
    var number by remember { mutableStateOf("") }
    var iban by remember { mutableStateOf(initial?.iban ?: "") }
    var account by remember { mutableStateOf(initial?.account ?: "") }
    var senders by remember { mutableStateOf(initial?.senders?.joinToString("\n") ?: "") }
    var err by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (initial == null) "افزودن کارت" else "ویرایش کارت") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = label, onValueChange = { label = it }, label = { Text("نام کارت (اختیاری)") }, singleLine = true)
                OutlinedTextField(value = bank, onValueChange = { bank = it }, label = { Text("نام بانک *") }, singleLine = true)
                OutlinedTextField(value = holder, onValueChange = { holder = it }, label = { Text("نام صاحب کارت *") }, singleLine = true)
                OutlinedTextField(
                    value = number, onValueChange = { number = it },
                    label = { Text(if (initial == null) "شماره کارت ۱۶ رقمی *" else "شماره کارت (خالی = بدون تغییر)") },
                    singleLine = true,
                )
                OutlinedTextField(value = senders, onValueChange = { senders = it }, label = { Text("شماره‌های ارسال‌کننده پیامک (هر خط یک شماره)" ) }, modifier = Modifier.fillMaxWidth())
                Text(
                    "نکته: اگر پیامک بانک با «نام فرستنده» می‌آید (مثل BlooIr) همان نام را وارد کنید. +98/0098/0 خودکار یکسان شمرده می‌شوند.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (err.isNotEmpty()) Text(err, color = Danger, style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = {
            Button(
                onClick = {
                    when {
                        bank.isBlank() || holder.isBlank() -> err = "نام بانک و صاحب کارت الزامی است."
                        initial == null && number.replace(Regex("[^0-9]"), "").length !in 16..19 -> err = "شماره کارت ۱۶ رقمی را کامل وارد کنید."
                        else -> onSave(label.trim(), bank.trim(), holder.trim(), number.trim(), iban.trim(), account.trim(), senders.lines().map { it.trim() }.filter { it.isNotEmpty() })
                    }
                },
                shape = RoundedCornerShape(14.dp),
                colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = DeepViolet),
            ) { Text("ذخیره", color = Color.White) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("انصراف") } },
    )
}

/**
 * ساخت قالب از نمونه — همان جریان پنل:
 * نمونه ← «تحلیل» (موتور مشترک روی سرور) ← انتخاب نوع هر بخش ← ذخیره.
 * هر span = [شروع، پایان، نوع] — نوع خالی یعنی متن ثابت.
 */
@Composable
private fun RuleBuilderDialog(card: MgmtCard, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var sample by remember { mutableStateOf("") }
    var segments by remember { mutableStateOf<List<Api.Segment>>(emptyList()) }
    // انتخاب نوع هر بخش: اندیس سگمنت → کلید ("" = متن ثابت)
    var choices by remember { mutableStateOf(mapOf<Int, String>()) }
    var name by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf("") }
    var result by remember { mutableStateOf("") }

    fun analyze() {
        scope.launch {
            busy = true; err = ""; result = ""
            try {
                val r = Api(prefs.serverUrl).analyzeSample(prefs.deviceId, prefs.deviceSecret, sample)
                segments = r.segments
                choices = r.segments.mapIndexed { i, s ->
                    i to (((s.span?.getOrNull(2)) as? kotlinx.serialization.json.JsonPrimitive)?.content ?: "")
                }.toMap()
            } catch (e: Exception) { err = e.message ?: "" }
            busy = false
        }
    }

    fun build() {
        scope.launch {
            busy = true; err = ""
            try {
                val spansJson = segments.mapIndexedNotNull { i, _ ->
                    val key = choices[i] ?: ""
                    val sp = segments[i].span ?: return@mapIndexedNotNull null
                    val s = (sp.getOrNull(0) as? kotlinx.serialization.json.JsonPrimitive)?.content?.toIntOrNull() ?: return@mapIndexedNotNull null
                    val e = (sp.getOrNull(1) as? kotlinx.serialization.json.JsonPrimitive)?.content?.toIntOrNull() ?: return@mapIndexedNotNull null
                    kotlinx.serialization.json.JsonArray(listOfNotNull(
                        kotlinx.serialization.json.JsonPrimitive(s),
                        kotlinx.serialization.json.JsonPrimitive(e),
                        if (key.isEmpty()) null else kotlinx.serialization.json.JsonPrimitive(key),
                    ))
                }.let { kotlinx.serialization.json.JsonArray(it).toString() }
                val r = Api(prefs.serverUrl).ruleSave(prefs.deviceId, prefs.deviceSecret, card.id, sample, spansJson, name, 10)
                // توجه: card_id داخل ruleSave صفر است — باید کارت را بدهیم؛ پاسخ با خطا برمی‌گردد اگر null
                result = if (r.ok) "قالب ساخته شد و خودش پارس شد ✓ مبلغ: " + Fa.amountLabel(r.amount_rial) else "خطا"
                if (r.ok) { onSaved() }
            } catch (e: Exception) { err = e.message ?: "" }
            busy = false
        }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("قالب جدید — " + card.label.ifEmpty { card.bank_name }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = sample, onValueChange = { sample = it },
                    label = { Text("یک پیامک واریز واقعی را عیناً اینجا بچسبانید") },
                    modifier = Modifier.fillMaxWidth(), minLines = 4,
                )
                Button(
                    onClick = { analyze() },
                    enabled = !busy && sample.length >= 6,
                    shape = RoundedCornerShape(14.dp),
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = DeepViolet),
                ) {
                    if (busy) CircularProgressIndicator(Modifier.padding(2.dp), strokeWidth = 2.dp, color = Color.White) else Text("تحلیل نمونه", color = Color.White)
                }
                if (segments.isNotEmpty()) {
                    Text("نوع هر بخش را انتخاب کنید (بخش‌های خط‌چین = پیشنهادی نیستند):", style = MaterialTheme.typography.bodySmall)
                    segments.forEachIndexed { i, seg ->
                        val sp = seg.span
                        if (sp != null) {
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Text(
                                    seg.text,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = if (seg.candidate) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.primary,
                                    modifier = Modifier.weight(1f, fill = false),
                                    maxLines = 1,
                                )
                                // انتخاب نوع با منوی ساده
                                var expanded by remember { mutableStateOf(false) }
                                OutlinedButton(onClick = { expanded = true }) {
                                    Text(FIELD_LABELS[choices[i] ?: ""] ?: if ((choices[i] ?: "").isEmpty()) "متن ثابت" else (choices[i] ?: ""), style = MaterialTheme.typography.labelMedium)
                                }
                                androidx.compose.material3.DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                                    TextButton(onClick = { choices = choices + (i to ""); expanded = false }) { Text("— متن ثابت —") }
                                    FIELD_LABELS.forEach { (k, v) ->
                                        TextButton(onClick = { choices = choices + (i to k); expanded = false }) { Text(v) }
                                    }
                                }
                            }
                        } else if (seg.text.isNotBlank()) {
                            Text(seg.text, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
                        }
                    }
                    OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text("نام قالب (اختیاری)") }, singleLine = true)
                    Button(
                        onClick = { build() },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(14.dp),
                        colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = DeepViolet),
                    ) { Text("ساخت و ذخیره قالب", color = Color.White) }
                }
                if (err.isNotEmpty()) Text(err, color = Danger, style = MaterialTheme.typography.bodySmall)
                if (result.isNotEmpty()) Text(result, color = Success, style = MaterialTheme.typography.bodyMedium)
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("بستن") } },
    )
}

/** تست خط لوله روی سرور: فرستنده + متن ← همان مسیر پیامک واقعی */
@Composable
private fun PipelineTestDialog(card: MgmtCard, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)
    val scope = rememberCoroutineScope()

    var sender by remember { mutableStateOf(card.senders.firstOrNull() ?: "") }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var err by remember { mutableStateOf("") }
    var ok by remember { mutableStateOf<Api.TestResult?>(null) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("تست خط لوله — " + card.label.ifEmpty { card.bank_name }) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(value = sender, onValueChange = { sender = it }, label = { Text("فرستنده (مثل 30001234 یا BlooIr)") }, singleLine = true)
                OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text("متن پیامک") }, modifier = Modifier.fillMaxWidth(), minLines = 4)
                Button(
                    onClick = {
                        scope.launch {
                            busy = true; err = ""; ok = null
                            try {
                                ok = Api(prefs.serverUrl).pipelineTest(prefs.deviceId, prefs.deviceSecret, sender, text, card.id)
                            } catch (e: Exception) { err = e.message ?: "" }
                            busy = false
                        }
                    },
                    enabled = !busy && text.length >= 6,
                    shape = RoundedCornerShape(14.dp),
                    colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = DeepViolet),
                ) {
                    if (busy) CircularProgressIndicator(Modifier.padding(2.dp), strokeWidth = 2.dp, color = Color.White) else Text("تست (روی سرور)", color = Color.White)
                }
                // تست محلی همزمان — همان چیزی که گوشی هنگام پیامک واقعی می‌کند
                Button(onClick = {
                    val local = CardConfigStore.cardForSender(context, sender)
                    val res = local?.let { CardRuleParser.runRules(CardConfigStore.rulesOf(it), text) }
                    err = when {
                        local == null -> "محلی: فرستنده در کش گوشی به هیچ کارتی نگرفت (اگر همین الان ساختید، دکمه ⟳ بالای صفحه را بزنید)"
                        res == null || !res.ok -> "محلی: قالب نگرفت — " + (res?.rejectReason ?: "")
                        else -> ""
                    }
                    if (res != null && res.ok) {
                        ok = Api.TestResult(card = local.bank, matched_rule = res.matchedRule?.name ?: "", amount_rial = res.amountRial, fields = res.fields)
                    }
                }, enabled = text.length >= 6, shape = RoundedCornerShape(14.dp)) { Text("تست محلی (مثل گوشی)") }
                ok?.let { r ->
                    Text("✓ معتبر — کارت: " + r.card + " | قالب: " + r.matched_rule, color = Success, style = MaterialTheme.typography.bodyMedium)
                    Text("مبلغ: " + Fa.amountLabel(r.amount_rial), style = MaterialTheme.typography.bodyMedium)
                    Text(r.fields.entries.joinToString(" • ") { (k, v) -> (FIELD_LABELS[k] ?: k) + ": " + Fa.digits(v) }, style = MaterialTheme.typography.bodySmall)
                }
                if (err.isNotEmpty()) Text(err, color = Danger, style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("بستن") } },
    )
}

// ═══════════════════════════════════════════════════════ اجزای طرح جدید (دور ۲۶)

/** چیپ اقدام — قرص لاوندر با متن بنفش (حذف = قرمز). */
@Composable
private fun ActionChip(text: String, onClick: () -> Unit, destructive: Boolean = false) {
    Text(
        text,
        color = if (destructive) Danger else DeepViolet,
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
        modifier = Modifier
            .clip(RoundedCornerShape(50))
            .background(if (destructive) RedTile else LavenderChip)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 7.dp),
    )
}

/** آیتم کارت مدیریت‌شده — مطابق زبان بصری طرح جدید (کارت سفید گرد + کاشی بنفش + چیپ‌ها). */
@Composable
private fun ManagedCardItem(
    card: MgmtCard,
    onEdit: () -> Unit,
    onBuildRule: () -> Unit,
    onTest: () -> Unit,
    onToggle: () -> Unit,
    onDefault: () -> Unit,
    onDelete: () -> Unit,
    onRuleToggle: (Api.MgmtRule) -> Unit,
    onRuleDelete: (Api.MgmtRule) -> Unit,
) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            // سربرگ: نشان کارت + نام + وضعیت
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(
                    Modifier
                        .size(46.dp)
                        .background(DeepViolet, RoundedCornerShape(15.dp)),
                    contentAlignment = Alignment.Center,
                ) {
                    CardGlyph(Color.White, Modifier.size(26.dp))
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        (card.label.ifEmpty { card.bank_name }) + if (card.is_default) " ★" else "",
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        maxLines = 1,
                        overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
                    )
                    Text(
                        card.bank_name + " • " + "•••• " + Fa.digits(card.last4),
                        style = MaterialTheme.typography.labelSmall,
                        color = Color(0xFF5C6480),
                    )
                }
                Box(
                    Modifier
                        .background(if (card.enabled) ir.peakopay.app.ui.common.GreenTile else RedTile, RoundedCornerShape(50))
                        .padding(horizontal = 10.dp, vertical = 4.dp),
                ) {
                    StatusBadge(
                        label = if (card.enabled) "فعال" else "غیرفعال",
                        ok = card.enabled,
                    )
                }
            }

            // صاحب کارت
            Text(
                "صاحب کارت: " + card.holder,
                style = MaterialTheme.typography.labelMedium,
                color = Color(0xFF5C6480),
            )

            // فرستنده‌ها — چیپ‌های بنفش
            if (card.senders.isEmpty()) {
                Text(
                    "فرستنده‌ای ثبت نشده — بدون فرستنده، پیامکی پردازش نمی‌شود",
                    style = MaterialTheme.typography.labelSmall,
                    color = Danger,
                )
            } else {
                Row(
                    Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Text("فرستنده‌ها:", style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480))
                    card.senders.forEach { snd ->
                        Text(
                            snd,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            color = DeepViolet,
                            modifier = Modifier
                                .clip(RoundedCornerShape(50))
                                .background(LavenderChip)
                                .padding(horizontal = 10.dp, vertical = 4.dp),
                        )
                    }
                }
            }

            // قالب‌ها
            if (card.rules.isEmpty()) {
                Text(
                    "قالبی ثبت نشده — با «قالب جدید» از یک پیامک واقعی بسازید",
                    style = MaterialTheme.typography.labelSmall,
                    color = Danger,
                )
            } else {
                card.rules.forEach { r ->
                    Row(
                        Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Row(
                            Modifier.weight(1f),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            Box(
                                Modifier
                                    .size(8.dp)
                                    .background(if (r.enabled) Success else Color(0xFFB9B3D3), CircleShape),
                            )
                            Text(
                                r.name + " (اولویت " + Fa.digits(r.priority.toString()) + ")",
                                style = MaterialTheme.typography.bodySmall,
                                color = if (r.enabled) MaterialTheme.colorScheme.onSurface else Color(0xFF5C6480),
                                maxLines = 1,
                                overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
                            )
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            ActionChip(if (r.enabled) "خاموش" else "روشن", { onRuleToggle(r) })
                            ActionChip("حذف", { onRuleDelete(r) }, destructive = true)
                        }
                    }
                }
            }

            // اقدام‌ها
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                ActionChip("ویرایش", onEdit)
                ActionChip("قالب جدید", onBuildRule)
                ActionChip("تست", onTest)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                ActionChip(if (card.enabled) "غیرفعال‌کردن" else "فعال‌کردن", onToggle)
                if (!card.is_default) {
                    ActionChip("پیش‌فرض", onDefault)
                }
                ActionChip("حذف کارت", onDelete, destructive = true)
            }
        }
    }
}
