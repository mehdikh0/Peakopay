package ir.peakopay.app.ui.support

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.R
import ir.peakopay.app.ui.common.BrandFooter
import ir.peakopay.app.ui.common.MenuRow
import ir.peakopay.app.ui.common.PurpleHeader
import ir.peakopay.app.ui.theme.BgLavender
import ir.peakopay.app.ui.theme.DeepViolet

/**
 * دور ۲۴ — صفحهٔ «پشتیبانی و راهنما» (در طرح نیست؛ با همان زبان بصری بازطراحی شد).
 * محتوای راهنما همان راهنمای داخل برنامه است.
 */
@Composable
fun SupportScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val versionName = try {
        context.packageManager.getPackageInfo(context.packageName, 0).versionName ?: ""
    } catch (e: Exception) {
        ""
    }

    Column(Modifier.fillMaxSize().background(BgLavender)) {
        PurpleHeader(
            subtitle = stringResource(R.string.menu_support_desc),
            onBack = onBack,
        )
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(stringResource(R.string.support_menu), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)

            // مراحل راه‌اندازی (همان ویزارد — خلاصه)
            HelpCard(
                title = "راه‌اندازی سریع",
                lines = listOf(
                    "۱. افزونهٔ ووکامرس Peakopay را در فروشگاه خود نصب و با کلید سرور متصل کنید.",
                    "۲. در اپ، شمارهٔ دستگاه را از پنل ووکامرس → Peakopay → دستگاه‌ها دریافت و جفت‌سازی کنید.",
                    "۳. قالب‌های پیامک هر کارت بانکی را در پنل ثبت کنید تا پرداخت‌ها خودکار تأیید شوند.",
                ),
            )
            HelpCard(
                title = "پرداخت تأیید نمی‌شود؟",
                lines = listOf(
                    "• مطمئن شوید شمارهٔ ارسال‌کنندهٔ بانک در قالب کارت ثبت شده است؛ پیامک شماره‌های ثبت‌نشده بررسی نمی‌شود.",
                    "• مجوز پیامک و اعلان‌ها را از صفحهٔ «دستگاه» بررسی کنید.",
                    "• صف ارسال را از تب «پیامک‌ها» ببینید؛ در حالت آفلاین پرداخت‌ها صف می‌شوند و با اتصال ارسال می‌گردند.",
                ),
            )
            HelpCard(
                title = "حریم خصوصی",
                lines = listOf(
                    "• فقط پیامک‌های فرستنده‌های ثبت‌شدهٔ بانکی بررسی و نمایش داده می‌شوند.",
                    "• محتوای پیامک روی سرور ذخیره نمی‌شود؛ فقط شمارهٔ کارت، مبلغ و شمارهٔ مرجع استخراج و ارسال می‌شود.",
                ),
            )

            Spacer(Modifier.height(4.dp))
            MenuRow(
                glyph = "🔧",
                title = stringResource(R.string.menu_device),
                desc = stringResource(R.string.menu_device_desc),
                onClick = onBack,
            )
            Spacer(Modifier.height(8.dp))
            BrandFooter(versionName = versionName)
        }
    }
}

@Composable
private fun HelpCard(title: String, lines: List<String>) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold, color = DeepViolet)
            lines.forEach { line ->
                Text(line, style = MaterialTheme.typography.bodySmall, color = Color(0xFF5C6480))
            }
        }
    }
}
