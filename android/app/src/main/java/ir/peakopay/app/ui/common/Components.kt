package ir.peakopay.app.ui.common

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.peakopay.app.util.Fa

/** اجزای مشترک UI — کارت‌های گرد، برچسب وضعیت، نمایش مبلغ با ارقام فارسی. */

@Composable
fun StatCard(
    title: String,
    value: String,
    subtitle: String? = null,
    accent: Color = MaterialTheme.colorScheme.primary,
    modifier: Modifier = Modifier,
) {
    Card(
        modifier = modifier,
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Box(
                Modifier
                    .size(width = 36.dp, height = 5.dp)
                    .background(accent, RoundedCornerShape(3.dp)),
            )
            Text(title, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(
                value,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurface,
            )
            if (subtitle != null) {
                Text(subtitle, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

/** برچسب وضعیت تراکنش با رنگ معنایی. */
@Composable
fun ResultChip(result: String) {
    val (bg, fg, label) = when (result) {
        "matched", "matched_pending", "sent" -> Triple(Color(0xFFDCFCE7), Color(0xFF166534), "تطبیق شد")
        "duplicate" -> Triple(Color(0xFFE0E7FF), Color(0xFF3730A3), "تکراری (ثبت شده)")
        "unmatched", "received" -> Triple(Color(0xFFFEF3C7), Color(0xFF92400E), "بدون تطبیق")
        "rejected", "failed" -> Triple(Color(0xFFFEE2E2), Color(0xFF991B1B), "رد شد")
        "pending" -> Triple(Color(0xFFE0E7FF), Color(0xFF3730A3), "در صف ارسال")
        else -> Triple(Color(0xFFE5E7EB), Color(0xFF374151), result)
    }
    Row(
        Modifier.background(bg, RoundedCornerShape(50)).padding(horizontal = 12.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = fg)
    }
}

/** نقطه وضعیت + متن (متصل / در انتظار تأیید / غیرفعال). */
@Composable
fun StatusDot(text: String, color: Color) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Box(Modifier.size(10.dp).background(color, CircleShape))
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** مبلغ ریال — نمایش شفاف تومان + ریال. */
@Composable
fun AmountText(rial: Long, modifier: Modifier = Modifier, emphasize: Boolean = true) {
    Text(
        Fa.amountLabel(rial),
        modifier = modifier,
        style = if (emphasize) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium,
        fontWeight = if (emphasize) FontWeight.Bold else null,
    )
}
