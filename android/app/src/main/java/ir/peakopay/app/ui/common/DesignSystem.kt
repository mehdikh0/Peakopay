package ir.peakopay.app.ui.common

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ir.peakopay.app.R
import ir.peakopay.app.ui.theme.BgLavender
import ir.peakopay.app.ui.theme.DeepViolet
import ir.peakopay.app.ui.theme.LavenderChip
import ir.peakopay.app.ui.theme.Success
import ir.peakopay.app.ui.theme.TextPrimary
import ir.peakopay.app.ui.theme.TextSecondary
import ir.peakopay.app.ui.theme.VioletBright
import ir.peakopay.app.ui.theme.Warning
import coil.compose.AsyncImage

/**
 * دور ۲۴ (مرحلهٔ ۳ — مطابق اسکرین‌شات مرجع):
 * هدر بنفش با «پایه‌های» کناری، کارت‌های آمار با آیکون رنگی،
 * کارت هیرو، کارت فروشگاه و منوی پایین سفید — زبان بصری طرح جدید.
 */

// ───────────────────────────────────────────────────────── رنگ‌های طرح
val AmberIcon = Color(0xFFF88613)
val AmberTile = Color(0xFFFFEAD3)
val GreenIcon = Color(0xFF089B54)
val GreenTile = Color(0xFFDCF2EA)
val RedIcon = Color(0xFFDC2626)
val RedTile = Color(0xFFFCE1E1)
val HeroGreen = Color(0xFF4ADE80)
// دور ۲۵ — تیره‌تر از #8A84A8 تا برچسب/آیکون تب‌های غیرفعال روی نوار سفید به‌وضوح خوانا باشند
val NavGray = Color(0xFF6C6690)

/** لوگوی Peakopay داخل دایرهٔ سفید — نشان برند در هدر. */
@Composable
fun BrandLogo(size: Int = 46, glyph: Int = 30) {
    Box(
        Modifier
            .size(size.dp)
            .clip(CircleShape)
            .background(Color.White),
        contentAlignment = Alignment.Center,
    ) {
        Image(
            painter = painterResource(R.drawable.peakopay_logo),
            contentDescription = "Peakopay",
            modifier = Modifier.size(glyph.dp),
        )
    }
}

/**
 * هدر بنفش (مطابق اسکرین‌شات): لوگو + «Peakopay» + زیرعنوان در یک ردیف،
 * زنگوله/بازگشت در انتهای ردیف، پایه‌های کناری در پایین.
 */
@Composable
fun PurpleHeader(
    subtitle: String,
    onBack: (() -> Unit)? = null,
    onBell: (() -> Unit)? = null,
    onRefresh: (() -> Unit)? = null,
    refreshing: Boolean = false,
) {
    Column(Modifier.fillMaxWidth()) {
        Column(
            Modifier
                .fillMaxWidth()
                .background(DeepViolet)
                .statusBarsPadding()
                .padding(start = 20.dp, end = 20.dp, top = 16.dp, bottom = 14.dp),
        ) {
            HeaderRow(subtitle, onBack, onBell, onRefresh, refreshing)
        }
        // «پایه‌های» کناری (مطابق طرح) — با Canvas بیرون‌زده از پایین هدر (بدون کلیپ)
        Canvas(Modifier.fillMaxWidth().height(0.dp)) {
            val legW = 18.dp.toPx()
            val drop = 42.dp.toPx()
            val r = 7.dp.toPx()
            fun leg(x: Float) = Path().apply {
                moveTo(x, 0f)
                lineTo(x + legW, 0f)
                lineTo(x + legW, drop - r)
                quadraticBezierTo(x + legW, drop, x + legW - r, drop)
                lineTo(x + r, drop)
                quadraticBezierTo(x, drop, x, drop - r)
                close()
            }
            drawPath(leg(0f), DeepViolet)
            drawPath(leg(size.width - legW), DeepViolet)
        }
    }
}

@Composable
private fun HeaderRow(
    subtitle: String,
    onBack: (() -> Unit)?,
    onBell: (() -> Unit)?,
    onRefresh: (() -> Unit)?,
    refreshing: Boolean,
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
            // انتهای ردیف (راست): زنگوله یا بازگشت/بروزرسانی
            if (onBack != null) {
                IconButton(onClick = onBack) {
                    Text("‹", color = Color.White, style = MaterialTheme.typography.headlineMedium)
                }
            }
            if (onRefresh != null) {
                IconButton(onClick = onRefresh, enabled = !refreshing) {
                    if (refreshing) {
                        CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                    } else {
                        Text("⟳", color = Color.White, style = MaterialTheme.typography.titleLarge)
                    }
                }
            }
            if (onBell != null) {
                IconButton(onClick = onBell) {
                    Icon(
                        Icons.Filled.Notifications,
                        contentDescription = stringResource(R.string.menu_notifications),
                        tint = Color.White,
                        modifier = Modifier.size(25.dp),
                    )
                }
            }
            Spacer(Modifier.weight(1f))
            // متن‌ها + لوگو (طبق طرح: لوگو سمت چپ، سپس Peakopay و زیرعنوان)
            Text(
                subtitle,
                color = Color(0xFFD9CCFB),
                style = MaterialTheme.typography.labelMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Spacer(Modifier.width(10.dp))
            Text(
                "Peakopay",
                color = Color.White,
                fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.titleMedium,
            )
            Spacer(Modifier.width(10.dp))
            BrandLogo(size = 38, glyph = 26)
    }
}

// ───────────────────────────────────────────────────────── آیکون‌های ترسیمی

/** ساعت کهنه‌زرد (در انتظار بررسی) — دایره + عقربه‌ها. */
@Composable
fun ClockIcon(color: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val r = size.minDimension / 2f
        drawCircle(color = color, radius = r * 0.9f, style = Stroke(width = r * 0.22f))
        val c = center
        drawLine(color, c, Offset(c.x, c.y - r * 0.5f), strokeWidth = r * 0.2f)
        drawLine(color, c, Offset(c.x + r * 0.4f, c.y + r * 0.1f), strokeWidth = r * 0.2f)
    }
}

/** ساعت‌شیشه (منقضی شده) — دایرهٔ قرمز توپر + ساعت‌شیشهٔ سفید. */
@Composable
fun HourglassIcon(circle: Color, glyph: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        drawCircle(color = circle, radius = size.minDimension / 2f)
        val w = size.width
        val h = size.height
        val p = Path().apply {
            moveTo(w * 0.30f, h * 0.26f)
            lineTo(w * 0.70f, h * 0.26f)
            lineTo(w * 0.50f, h * 0.50f)
            close()
            moveTo(w * 0.50f, h * 0.50f)
            lineTo(w * 0.30f, h * 0.74f)
            lineTo(w * 0.70f, h * 0.74f)
            close()
        }
        drawPath(p, color = glyph)
    }
}

/** نمودار ستونی (داشبورد) — دو ستون گرد با ارتفاع متفاوت. */
@Composable
fun ColumnsIcon(color: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = size.width
        val h = size.height
        drawRoundRect(
            color = color,
            topLeft = Offset(0f, h * 0.18f),
            size = Size(w * 0.38f, h * 0.82f),
            cornerRadius = CornerRadius(w * 0.12f),
        )
        drawRoundRect(
            color = color,
            topLeft = Offset(w * 0.62f, 0f),
            size = Size(w * 0.38f, h * 0.70f),
            cornerRadius = CornerRadius(w * 0.12f),
        )
    }
}

/** نشان فروشگاه (سفید) — سقف سان + بدنه. */
@Composable
fun StoreGlyph(color: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = size.width
        val h = size.height
        // سقف/سان
        drawRoundRect(
            color = color,
            topLeft = Offset(0f, 0f),
            size = Size(w, h * 0.22f),
            cornerRadius = CornerRadius(h * 0.1f),
        )
        // بدنه
        drawRoundRect(
            color = color,
            topLeft = Offset(w * 0.10f, h * 0.32f),
            size = Size(w * 0.80f, h * 0.68f),
            cornerRadius = CornerRadius(h * 0.08f),
        )
    }
}

/**
 * آواتار فروشگاه (دور ۲۶): لوگوی سایتِ همان فروشگاه (favicon) — و اگر
 * نبود/لود نشد، همان نشان بنفش پیش‌فرض دیده می‌شود (تصویر فقط رویش می‌نشیند).
 */
@Composable
fun StoreAvatar(
    logoUrl: String,
    modifier: Modifier = Modifier,
    shape: androidx.compose.ui.graphics.Shape = RoundedCornerShape(14.dp),
    glyphSize: Int = 20,
) {
    Box(
        modifier
            .clip(shape)
            .background(DeepViolet, shape),
        contentAlignment = Alignment.Center,
    ) {
        StoreGlyph(Color.White, Modifier.size(glyphSize.dp))
        if (logoUrl.isNotEmpty()) {
            AsyncImage(
                model = logoUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

/** نشان کارت بانکی — بدنهٔ گرد + نوار مغناطیسی. */
@Composable
fun CardGlyph(color: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = size.width
        val h = size.height
        drawRoundRect(
            color = color,
            topLeft = Offset(0f, h * 0.04f),
            size = Size(w, h * 0.92f),
            cornerRadius = CornerRadius(w * 0.12f),
        )
        drawRect(
            color = color,
            topLeft = Offset(0f, h * 0.32f),
            size = Size(w, h * 0.16f),
        )
    }
}

/**
 * تزئین «نمودار روند» کارت هیرو (دور ۲۵ — مطابق تصویر مرجع):
 * نمودار خطی صعودی با چند شکستگی + پیکان در انتها و نقاط راس — سفیدِ نیمه‌شفاف.
 */
@Composable
fun TrendChart(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = size.width
        val h = size.height
        // نقاط نمودار (چپ→راست، رو به بالا) — الگوی نمودار خطی واقعی
        val pts = listOf(
            Offset(w * 0.04f, h * 0.86f),
            Offset(w * 0.26f, h * 0.64f),
            Offset(w * 0.44f, h * 0.74f), // افت کوچک — نمودار طبیعی
            Offset(w * 0.66f, h * 0.38f),
            Offset(w * 0.88f, h * 0.10f),
        )
        val line = Path().apply {
            moveTo(pts[0].x, pts[0].y)
            for (i in 1 until pts.size) lineTo(pts[i].x, pts[i].y)
        }
        // ناحیهٔ زیر خط (پر بسیار محو)
        val area = Path().apply {
            addPath(line)
            lineTo(pts.last().x, h)
            lineTo(pts[0].x, h)
            close()
        }
        drawPath(area, Color.White.copy(alpha = 0.10f))
        drawPath(line, Color.White.copy(alpha = 0.85f), style = Stroke(width = 2.6.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
        // نقاط راس
        pts.forEachIndexed { i, p ->
            if (i != pts.size - 1) drawCircle(Color.White.copy(alpha = 0.75f), radius = 2.6.dp.toPx(), center = p)
        }
        // پیکان در انتهای خط (رو به بالا-راست)
        val tip = pts.last()
        val dir = Offset(tip.x - pts[pts.size - 2].x, tip.y - pts[pts.size - 2].y)
        val len = kotlin.math.hypot(dir.x, dir.y)
        if (len > 0f) {
            val ux = dir.x / len
            val uy = dir.y / len
            val ah = 7.dp.toPx()   // طول شاخهٔ پیکان
            val aw = 4.5.dp.toPx() // عرض شاخهٔ پیکان
            val arrow = Path().apply {
                moveTo(tip.x, tip.y)
                lineTo(tip.x - ux * ah + uy * aw, tip.y - uy * ah - ux * aw)
                lineTo(tip.x - ux * ah - uy * aw, tip.y - uy * ah + ux * aw)
                close()
            }
            drawPath(arrow, Color.White.copy(alpha = 0.95f))
        }
    }
}

// ───────────────────────────────────────────────────────── اجزای داشبورد

/**
 * ردیف انتخاب فروشگاه (دور ۲۵ — بازطراحی): قرص سفید تمام‌گرد با حاشیهٔ
 * لاوندر، کاشی بنفش نشان فروشگاه + نام + دکمهٔ گرد فلش پایین.
 */
@Composable
fun StoreSelectorRow(
    storeName: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    logoUrl: String = "",
) {
    Row(
        modifier
            .fillMaxWidth()
            .padding(horizontal = 20.dp, vertical = 4.dp)
            .clip(RoundedCornerShape(50))
            .background(Color.White)
            .border(1.dp, Color(0xFFE4DCF6), RoundedCornerShape(50))
            .clickable(onClick = onClick)
            .padding(start = 8.dp, end = 10.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        StoreAvatar(
            logoUrl = logoUrl,
            modifier = Modifier.size(38.dp),
            shape = RoundedCornerShape(50),
            glyphSize = 20,
        )
        Text(
            storeName,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = TextPrimary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )
        Box(
            Modifier
                .size(30.dp)
                .background(LavenderChip, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Filled.KeyboardArrowDown,
                contentDescription = null,
                tint = DeepViolet,
                modifier = Modifier.size(22.dp),
            )
        }
    }
}

/** خط وضعیت (مطابق طرح): «آخرین بروزرسانی …» + نشان سبز آنلاین. */
@Composable
fun StatusLine(
    lastUpdate: String,
    online: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 40.dp, vertical = 2.dp),
    ) {
        Text(
            lastUpdate,
            style = MaterialTheme.typography.labelMedium,
            color = TextSecondary,
            modifier = Modifier.align(Alignment.Center),
        )
        Row(
            Modifier.align(Alignment.CenterEnd),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Box(
                Modifier
                    .size(9.dp)
                    .background(if (online) Success else Warning, CircleShape),
            )
            Text(
                stringResource(if (online) R.string.online else R.string.offline),
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.Bold,
                color = if (online) Success else Warning,
            )
        }
    }
}

/** کاشی دایره‌ای آیکون آمار. */
@Composable
fun StatIconTile(bg: Color, content: @Composable () -> Unit) {
    Box(
        Modifier
            .size(42.dp)
            .background(bg, CircleShape),
        contentAlignment = Alignment.Center,
    ) { content() }
}

/** کارت آمار داشبورد (مطابق طرح): آیکون رنگی + برچسب + عدد. */
@Composable
fun StatCard(
    label: String,
    value: String,
    icon: @Composable () -> Unit,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    androidx.compose.material3.Card(
        modifier
            .clip(RoundedCornerShape(18.dp))
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(18.dp),
        colors = androidx.compose.material3.CardDefaults.cardColors(containerColor = Color.White),
        elevation = androidx.compose.material3.CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            icon()
            Spacer(Modifier.width(12.dp))
            Column(
                Modifier.weight(1f),
                horizontalAlignment = Alignment.End, // در RTL = سمت چپ (مطابق طرح)
            ) {
                Text(
                    label,
                    style = MaterialTheme.typography.labelMedium,
                    color = TextSecondary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    value,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    color = TextPrimary,
                )
            }
        }
    }
}

/** کارت هیرو درآمد (مطابق طرح): گرادیان بنفش + عنوان + مبلغ درشت + خط سبز. */
@Composable
fun HeroIncomeCard(
    title: String,
    amount: String,
    unit: String,
    greenLine: String,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier
            .fillMaxWidth()
            .background(
                Brush.linearGradient(
                    listOf(VioletBright, Color(0xFF5420D8)),
                    start = Offset.Zero,
                    end = Offset.Infinite,
                ),
                RoundedCornerShape(24.dp),
            ),
    ) {
        TrendChart(
            Modifier
                .align(Alignment.TopEnd)
                .padding(horizontal = 18.dp, vertical = 20.dp)
                .size(120.dp, 84.dp),
        )
        Column(
            Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, top = 20.dp, bottom = 22.dp),
        ) {
            Text(
                title,
                color = Color(0xFFE3D8FD),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    amount,
                    color = Color.White,
                    fontWeight = FontWeight.Bold,
                    fontSize = 34.sp,
                    style = MaterialTheme.typography.displaySmall,
                )
                Text(
                    unit,
                    color = Color(0xFFD9CCFB),
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.padding(bottom = 7.dp),
                )
            }
            Spacer(Modifier.height(8.dp))
            Text(
                greenLine,
                color = HeroGreen,
                style = MaterialTheme.typography.labelLarge,
                fontWeight = FontWeight.Bold,
            )
        }
    }
}

/** کارت فروشگاه (مطابق طرح): کاشی بنفش + نشان وضعیت + نام + دامنه. */
@Composable
fun StoreCard(
    name: String,
    domain: String,
    connected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    logoUrl: String = "",
) {
    androidx.compose.material3.Card(
        modifier
            .clip(RoundedCornerShape(20.dp))
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(20.dp),
        colors = androidx.compose.material3.CardDefaults.cardColors(containerColor = Color.White),
        elevation = androidx.compose.material3.CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            StoreAvatar(
                logoUrl = logoUrl,
                modifier = Modifier.size(46.dp),
                shape = RoundedCornerShape(15.dp),
                glyphSize = 24,
            )
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    StatusBadge(
                        label = stringResource(if (connected) R.string.store_connected else R.string.store_pending_review),
                        ok = connected,
                    )
                    Spacer(Modifier.weight(1f))
                    Text(
                        name,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = TextPrimary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                if (domain.isNotEmpty()) {
                    Text(
                        domain,
                        style = MaterialTheme.typography.labelSmall,
                        color = VioletBright,
                        textAlign = TextAlign.Left,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }
    }
}

/** نقطهٔ وضعیت + برچسب (متصل سبز / در انتظار زرد / …). */
@Composable
fun StatusBadge(label: String, ok: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
        Box(
            Modifier
                .size(7.dp)
                .background(if (ok) Success else Warning, CircleShape),
        )
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = if (ok) Success else Warning,
        )
    }
}

/**
 * فوتر برندینگ بنفش.
 */
@Composable
fun BrandFooter(versionName: String, modifier: Modifier = Modifier) {
    Column(
        modifier
            .fillMaxWidth()
            .background(DeepViolet, RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp))
            .padding(horizontal = 20.dp, vertical = 22.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        BrandLogo(size = 58, glyph = 38)
        Text("Peakopay", color = Color.White, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleLarge)
        Text(
            stringResource(R.string.footer_tagline),
            color = Color(0xFFD9CCFB),
            style = MaterialTheme.typography.bodySmall,
        )
        Box(
            Modifier
                .fillMaxWidth(0.5f)
                .height(1.dp)
                .background(Color(0x33FFFFFF)),
        )
        if (versionName.isNotEmpty()) {
            Text(
                stringResource(R.string.footer_version_fmt, ir.peakopay.app.util.Fa.digits(versionName)),
                color = Color(0xB3FFFFFF),
                style = MaterialTheme.typography.labelSmall,
            )
        }
        Text(
            stringResource(R.string.footer_rights),
            color = Color(0x99FFFFFF),
            style = MaterialTheme.typography.labelSmall,
            textAlign = TextAlign.Center,
        )
    }
}

/** آیتم منوی ردیفی. */
@Composable
fun MenuRow(
    glyph: String,
    title: String,
    desc: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(Color.White)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(
            Modifier
                .size(42.dp)
                .background(Color(0xFFECE4FE), RoundedCornerShape(13.dp)),
            contentAlignment = Alignment.Center,
        ) {
            Text(glyph, style = MaterialTheme.typography.bodyLarge)
        }
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
            Text(desc, style = MaterialTheme.typography.labelSmall, color = Color(0xFF5C6480), maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text("‹", color = Color(0xFF8F87B5), style = MaterialTheme.typography.titleLarge)
    }
}
