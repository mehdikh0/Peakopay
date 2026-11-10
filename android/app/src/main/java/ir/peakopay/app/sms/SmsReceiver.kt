package ir.peakopay.app.sms

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import ir.peakopay.app.data.CardConfigStore
import ir.peakopay.app.data.Prefs
import ir.peakopay.app.sms.CardRuleParser
import ir.peakopay.app.data.RulesRepository
import ir.peakopay.app.db.AppDb
import ir.peakopay.app.db.OutboxEntity
import ir.peakopay.app.sms.SenderNorm
import ir.peakopay.app.work.UploadWorker
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * دریافت‌کننده پیامک بانکی.
 *
 * حریم خصوصی (دور ۲۱): فقط پیامکِ فرستنده‌هایی که کاربر در قالب کارت‌ها به‌عنوان
 * «شماره ارسال‌کننده بانک» ثبت کرده است اصلاً بررسی می‌شود. پیامک هر شماره دیگر
 * (شخصی، تبلیغاتی، برداشت/خرید) در همان گام نخست و بدون هیچ اثری کنار گذاشته
 * می‌شود: نه متنش خوانده/پارس می‌شود، نه در رخدادها ثبت می‌شود و حتی همگام‌سازی‌ای
 * به‌خاطر آن انجام نمی‌گیرد. این گیرنده هیچ پیامی حذف یا تغییر نمی‌دهد — فقط می‌خواند.
 */
class SmsReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return

        val prefs = Prefs.get(context)
        if (!prefs.isPaired) return // دستگاه جفت نشده — هیچ کاری نکن

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return

        // بخش‌های پیامک چندبخشی را یکی کنیم
        val bySender = messages.groupBy { it.originatingAddress ?: "" }
        val receivedAt = System.currentTimeMillis()

        val pending = goAsync()
        CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
            try {
                val dao = AppDb.get(context).outboxDao()
                var anyInserted = false
                // اینترنت هست؟ (بررسی یک‌باره در لحظه رخداد — نه polling)
                // جریان آنلاین مثل قبل است؛ فقط «برچسب» وضعیت صف فرق می‌کند.
                val online = ir.peakopay.app.util.Net.isOnline(context)
                for ((sender, parts) in bySender) {
                    // ── گارد نخست: فقط فرستنده‌های ثبت‌شده در قالب کارت‌ها ──
                    // کاربر این شماره را به‌عنوان «شماره ارسال‌کننده بانک» ثبت نکرده؟
                    // پس پیامک او اصلاً بررسی نمی‌شود — حتی تلاشی برای بررسی نمی‌شود:
                    // نه متن سرهم می‌شود، نه رخدادی ثبت می‌شود و نه کاری درگیر می‌شود.
                    val card = CardConfigStore.cardForSender(context, sender)
                    if (card == null) continue

                    val body = parts.joinToString("") { it.displayMessageBody ?: "" }

                    // ۱) اجرای قواعد پارسر همین کارت (به‌ترتیب اولویت)
                    val res = CardRuleParser.runRules(CardConfigStore.rulesOf(card), body)
                    if (!res.ok) {
                        // با هیچ قالبی تطبیق نکرد → نادیده. ممکن است قالب تازه در
                        // پنل ساخته/ویرایش شده و هنوز به گوشی نرسیده باشد.
                        prefs.addPipelineEvent("no_rule", sender, "هیچ قالبی با پیامک تطبیق نداشت" + (if (res.rejectReason.isNotEmpty()) ": " + res.rejectReason else ""))
                        ir.peakopay.app.work.HeartbeatWorker.enqueueNow(context)
                        continue
                    }

                    prefs.lastSmsReceivedAt = receivedAt
                    val rawHash = CardRuleParser.fingerprint(
                        card.id, sender, res.amountRial, res.fields["reference"] ?: "",
                        ir.peakopay.app.util.Fa.sha256(body),
                    )
                    // ۲) ضدتکرار محلی: raw_hash پیامک + اثر انگشت تراکنش
                    val smsHash = ir.peakopay.app.util.Fa.sha256(body + "|" + sender)
                    if (dao.findByHash(smsHash) != null) {
                        // پیامک تکراری (گیرنده دوبار شلیک شده/همان پیامک قبلی) —
                        // هیچ تراکنش جدیدی ساخته نمی‌شود؛ صرف‌نظر از وضعیت ردیف قبلی.
                        ir.peakopay.app.util.OfflineLog.duplicateIgnored()
                        continue
                    }
                    val newId = dao.insert(
                        OutboxEntity(
                            bank = card.bank,
                            sender = sender,
                            amount_rial = res.amountRial,
                            occurred_at = ir.peakopay.app.util.Fa.isoNow(receivedAt),
                            dest_last4 = res.fields["card_last4"] ?: card.last4,
                            reference = res.fields["reference"] ?: res.fields["txn_id"] ?: "",
                            raw_text = body,
                            raw_hash = smsHash,
                            card_id = card.id,
                            fingerprint = rawHash,
                            received_at = receivedAt,
                            // آفلاین: pending_offline — همگام‌سازی خودکار با بازگشت اینترنت.
                            // آنلاین: pending — جریان قبلی بدون هیچ تغییری.
                            status = if (online) {
                                ir.peakopay.app.db.OutboxStatus.PENDING
                            } else {
                                ir.peakopay.app.db.OutboxStatus.PENDING_OFFLINE
                            },
                        ),
                    )
                    if (!online) {
                        ir.peakopay.app.util.OfflineLog.transactionSaved(newId, card.bank, res.amountRial)
                    }
                    anyInserted = true
                    prefs.addPipelineEvent("queued", sender, "مبلغ " + ir.peakopay.app.util.Fa.amountLabel(res.amountRial) + " به صف ارسال رفت")
                }
                if (anyInserted) UploadWorker.enqueueNow(context)
            } finally {
                pending.finish()
            }
        }
    }
}
