package ir.peakopay.app.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * دور ۲۴/۲۵ — پالت طرح مرجع یک طرح «روشن» ثابت است (بنفش عمیق + لاوندر + کارت سفید).
 * قبلاً تم تیرهٔ سیستم دنبال می‌شد؛ در نتیجه در دارک‌مود، رنگ متن‌ها به «تقریباً سفید»
 * تغییر می‌کرد ولی پس‌زمینه‌ها همان سفید/لاوندر ثابت طرح بودند → متن با پس‌زمینه یکی
 * می‌شد. اکنون طرح همیشه با پالت روشن برند رندر می‌شود (مستقل از دارک/لایت سیستم)
 * تا ظاهر دقیقاً مطابق تصاویر مرجع و همیشه خوانا بماند.
 */
private val LightColors = lightColorScheme(
    primary = DeepViolet,
    onPrimary = CardBg,
    primaryContainer = LavenderChip,
    onPrimaryContainer = DeepViolet,
    secondary = VioletBright,
    onSecondary = CardBg,
    background = BgLavender,
    onBackground = TextPrimary,
    surface = CardBg,
    onSurface = TextPrimary,
    surfaceVariant = LavenderSoft,
    onSurfaceVariant = TextSecondary,
    outline = Color(0xFFB7A8DE), // قبلاً #E4DCF6 — مرز چیپ‌ها/فیلدها تقریباً نامرئی بود
    error = Danger,
    tertiary = Success,
)

@Composable
fun PeakopayTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = LightColors,
        typography = HamrahTypography,
        content = content,
    )
}
