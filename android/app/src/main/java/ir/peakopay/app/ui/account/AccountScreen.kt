package ir.peakopay.app.ui.account

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
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
import ir.peakopay.app.ui.common.BrandLogo
import ir.peakopay.app.ui.common.MenuRow
import ir.peakopay.app.ui.common.PurpleHeader
import ir.peakopay.app.ui.theme.BgLavender
import ir.peakopay.app.ui.theme.DeepViolet
import ir.peakopay.app.util.Fa

/**
 * دور ۲۴ — صفحهٔ «حساب کاربری» (مطابق طرح): مشخصات حساب و امنیت.
 * (بخش «اعضای تیم» مطابق درخواست کاربر پیاده نشده.)
 */
@Composable
fun AccountScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = Prefs.get(context)

    Column(Modifier.fillMaxSize().background(BgLavender)) {
        PurpleHeader(
            subtitle = stringResource(R.string.menu_account_desc),
            onBack = onBack,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // کارت مشخصات
            Card(
                Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(22.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier
                                .size(56.dp)
                                .background(Color(0xFFECE4FE), CircleShape),
                            contentAlignment = Alignment.Center,
                        ) {
                            BrandLogo(size = 44, glyph = 30)
                        }
                        Column {
                            Text(
                                prefs.accountName.ifEmpty { prefs.storeName.ifEmpty { "حساب کاربری" } },
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold,
                            )
                            if (prefs.accountEmail.isNotEmpty()) {
                                Text(prefs.accountEmail, style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480))
                            }
                        }
                    }
                    InfoRow(stringResource(R.string.account_email), prefs.accountEmail.ifEmpty { "—" })
                    InfoRow(
                        stringResource(R.string.account_role),
                        when (prefs.deviceSource) {
                            "login" -> "مدیر حساب"
                            else -> "دستگاه همراه‌سازی‌شده"
                        },
                    )
                    InfoRow(
                        stringResource(R.string.account_store_count_fmt, Fa.number(prefs.cachedStores().size.toLong())),
                        "",
                    )
                }
            }

            // امنیت
            Text(stringResource(R.string.menu_account), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            MenuRow(
                glyph = "🔐",
                title = stringResource(R.string.disconnect),
                desc = stringResource(R.string.disconnect_confirm),
                onClick = onBack,
            )
            Spacer(Modifier.height(8.dp))
        }
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row(
        Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Color(0xFF5C6480))
        Text(value, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold)
    }
}

/**
 * دور ۲۴ — صفحهٔ «اعلان‌ها» (مطابق طرح): تنظیمات پیام و هشدارها.
 */
@Composable
fun NotificationsScreen(onBack: () -> Unit) {
    Column(Modifier.fillMaxSize().background(BgLavender)) {
        PurpleHeader(
            subtitle = stringResource(R.string.menu_notifications_desc),
            onBack = onBack,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            NotifRow(R.string.notif_payment_new, R.string.notif_new_payment)
            NotifRow(R.string.notif_payment_paid, R.string.notif_paid)
            NotifRow(R.string.notif_payment_expired, R.string.notif_expired)
            NotifRow(R.string.notif_payment_failed, R.string.notif_failed)
        }
    }
}

@Composable
private fun NotifRow(titleRes: Int, descRes: Int) {
    var enabled by remember { mutableStateOf(true) }
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(titleRes), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
                Text(stringResource(descRes), style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480))
            }
            Switch(
                checked = enabled,
                onCheckedChange = { enabled = it },
                colors = SwitchDefaults.colors(
                    checkedTrackColor = DeepViolet,
                    checkedThumbColor = Color.White,
                ),
            )
        }
    }
}
