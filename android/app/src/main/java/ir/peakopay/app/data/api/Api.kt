package ir.peakopay.app.data.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * کلاینت REST پیکو پی.
 *
 * احراز هویت دستگاه:
 *   X-Peakopay-Device:    شناسه دستگاه
 *   X-Peakopay-Timestamp: ثانیه از epoch
 *   X-Peakopay-Signature: HMAC-SHA256(راز دستگاه, timestamp + "." + body)
 *
 * پاکت پاسخ: {"ok":true,"data":{…}} یا {"ok":false,"error":{"code","message"}}
 * فیلد `data` بسته به endpoint شکل متفاوتی دارد؛ اینجا هر endpoint نوع خودش را
 * decode می‌کند تا هرگز تداخل شکل رخ ندهد.
 */
class Api(private val serverUrl: String) {

    val json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
        explicitNulls = false
        // null صریح در JSON برای فیلد غیر-null با مقدار پیش‌فرض → همان پیش‌فرض
        // (رفع خطای «Failed to parse literal 'null' as a long» در فهرست در انتظار)
        coerceInputValues = true
    }

    private val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build()

    class ApiException(val code: String, message: String, val httpStatus: Int) : Exception(message)

    companion object {

        /**
         * پاک‌سازی و استانداردسازی آدرس سرور:
         * حذف نویسه‌های صفر-عرض/RTL (که با کیبورد فارسی راحت وارد می‌شوند)،
         * حذف فاصله‌ها، افزودن خودکار https:// در صورت نبودِ scheme.
         * خروجی: آدرس بدون اسلش انتهایی، یا exception با پیام فارسی.
         */
        fun normalizeUrl(raw: String): String {
            val cleaned = raw
                .replace(Regex("[\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]"), "")
                .trim()
                .trim()
            if (cleaned.isEmpty()) throw ApiException("bad_url", "آدرس سرور را وارد کنید.", 0)
            var url = cleaned.trimEnd('/')
            val lower = url.lowercase()
            if (!lower.startsWith("http://") && !lower.startsWith("https://")) {
                url = "https://" + url   // پیش‌فرض امن؛ http فقط اگر صریحاً وارد شود
            }
            val parsed = url.toHttpUrlOrNull()
                ?: throw ApiException(
                    "bad_url",
                    "آدرس سرور معتبر نیست (مثال: https://pay.example.com). متن واردشده را بررسی کنید.",
                    0,
                )
            val origin = buildString {
                append(parsed.scheme).append("://").append(parsed.host)
                if (parsed.port != 80 && parsed.port != 443) append(":").append(parsed.port)
            }
            return origin + (parsed.encodedPath.trimEnd('/').takeIf { it.isNotEmpty() && it != "/" } ?: "")
        }

        /**
         * عیب‌یابی گام‌به‌گام اتصال (DNS → TLS → GET /health) با پیام فارسی.
         * خروجی چندخطی برای نمایش به کاربر.
         */
        suspend fun diagnose(rawUrl: String): String = withContext(Dispatchers.IO) { diagnoseBlocking(rawUrl) }

        private fun diagnoseBlocking(rawUrl: String): String {
            val url = try {
                normalizeUrl(rawUrl)
            } catch (e: ApiException) {
                return "✗ " + e.message
            }
            val sb = StringBuilder()
            sb.append("۱) آدرس: ").append(url).append('\n')
            // گام DNS
            val host = url.toHttpUrlOrNull()?.host
                ?: return sb.append("✗ آدرس نامعتبر است.").toString()
            try {
                val addrs = java.net.InetAddress.getAllByName(host)
                sb.append("۲) DNS: پیدا شد (").append(addrs.firstOrNull()?.hostAddress).append(")\n")
            } catch (e: Exception) {
                return sb.append("✗ دامنه در DNS پیدا نشد — اتصال اینترنت یا املا دامنه را بررسی کنید.").toString()
            }
            // گام TLS/HTTP
            val client = OkHttpClient.Builder()
                .connectTimeout(12, TimeUnit.SECONDS)
                .readTimeout(15, TimeUnit.SECONDS)
                .build()
            return try {
                val resp = client.newCall(
                    Request.Builder().url(url + "/health").header("Accept", "application/json").build()
                ).execute()
                resp.use { r ->
                    val body = r.body?.string().orEmpty()
                    val msg = when {
                        r.code == 200 && body.contains("\"ok\"") ->
                            sb.append("۳) اتصال HTTPS: برقرار ✓\n").append("✓ سرور در دسترس است و پاسخ می‌دهد (HTTP 200). اگر همراه‌سازی باز هم خطا داد، کد همراه‌سازی را دوباره بسازید.").toString()
                        r.code == 200 -> sb.append("۳) HTTPS ✓ اما پاسخ /health غیرمنتظره بود: ").append(body.take(120)).toString()
                        else -> sb.append("✗ سرور پاسخ داد اما خطای HTTP ").append(r.code)
                            .append(" برگرداند.\nپاسخ: ").append(body.take(160))
                            .append("\n(اگر 403 است، ممکن است فایروال هاست درخواست اپ را مسدود کند — با پشتیبانی هاست بررسی کنید.)").toString()
                    }
                    msg
                }
            } catch (e: javax.net.ssl.SSLException) {
                sb.append("✗ اتصال SSL/TLS برقرار نشد (").append(e.message).append(").\n")
                    .append("گواهی SSL دامنه احتمالاً کامل نصب نشده — در cPanel ← SSL/TLS Status ← Run AutoSSL را بزنید و چند دقیقه صبر کنید.").toString()
            } catch (e: java.net.SocketTimeoutException) {
                sb.append("✗ سرور در مهلت ۱۲ ثانیه پاسخی نداد (Timeout).\n")
                    .append("احتمالاً فایروال هاست IP گوشی/اینترنت شما را مسدود می‌کند یا سایتダウن است.\n")
                    .append("در مرورگر همین گوشی آدرس ").append(url).append("/health را باز کنید — اگر باز نشد، مشکل از سرور/شبکه است نه اپ.").toString()
            } catch (e: java.net.ConnectException) {
                sb.append("✗ اتصال به سرور رد شد (").append(e.message).append(").\nپورت 443 روی سرور باز نیست یا وب‌سرور از کار افتاده است.").toString()
            } catch (e: IOException) {
                sb.append("✗ خطای شبکه (").append(e.javaClass.simpleName).append(": ").append(e.message).append(")").toString()
            }
        }
    }

    // ------------------------------------------------------------ envelope

    @Serializable
    private data class Envelope(val ok: Boolean, val data: JsonElement? = null, val error: ApiError? = null)

    @Serializable
    data class ApiError(val code: String = "", val message: String = "")

    // ------------------------------------------------------------ response types

    /** POST /api/v1/auth/pair */
    @Serializable
    data class PairResult(
        val device_id: Long = 0,
        val device_secret: String = "",
        val store: String = "",
        val store_id: Long = 0,
        val status: String = "pending",
        val added_store: Boolean = false,
        val message: String = "",
        val api_version: String = "v1",
    )

    /** POST /api/v1/devices/register */
    @Serializable
    data class RegisterResult(val registered: Boolean = false, val status: String = "")

    /** POST /api/v1/devices/heartbeat */
    @Serializable
    data class HeartbeatResult(
        val server_time: String = "",
        val device_status: String = "",
        val store: HeartbeatStore = HeartbeatStore(),
        val sms_config_version: Long? = null,
        val today: TodayStats = TodayStats(),
        val pending: PendingInfo = PendingInfo(),
    )

    @Serializable
    data class HeartbeatStore(val name: String = "")

    /** GET /api/v1/store/status */
    @Serializable
    data class StoreStatus(
        val store: StoreInfo = StoreInfo(),
        val today: TodayStats = TodayStats(),
        val totals: Totals = Totals(),
        val pending_payments: Int = 0,
        val last_transaction: LastTransaction? = null,
        val device: DeviceInfo? = null,
        val server_time: String = "",
    )

    @Serializable
    data class StoreInfo(val name: String = "", val currency: String = "IRT", val timezone: String = "Asia/Tehran")

    @Serializable
    data class TodayStats(
        val income_rial: Long = 0,
        val paid_count: Int = 0,
        val transactions: Int = 0,
        val unmatched: Int = 0,
        val paid_income_rial: Long = 0,
        val failed: Int = 0,
        val expired: Int = 0,
    )

    @Serializable
    data class Totals(val income_rial: Long = 0, val paid_count: Int = 0)

    @Serializable
    data class PendingInfo(val payments: Int = 0, val unmatched: Int = 0)

    @Serializable
    data class LastTransaction(val amount_rial: Long = 0, val bank: String = "", val created_at: String = "", val status: String = "")

    @Serializable
    data class DeviceInfo(val id: Long = 0, val name: String = "", val status: String = "", val last_seen_at: String = "")

    /** POST /api/v1/transactions */
    @Serializable
    data class TxResult(
        val index: Int = 0,
        val result: String = "",
        val transaction_id: Long = 0,
        val payment_id: Long? = null,
        val order_id: String? = null,
        val payment_status: String? = null,
        val message: String? = null,
    )

    @Serializable
    data class UploadResult(val results: List<TxResult> = emptyList(), val matched: Boolean = false)

    // ------------------------------------------------------------ request types

    @Serializable
    data class PairRequest(
        val code: String,
        val device_name: String,
        val model: String,
        val app_version: String,
        val android_version: String,
        // دور ۲۵ — افزودن فروشگاه: اگر پر شوند، همان دستگاه به فروشگاه کد جدید وصل می‌شود
        val device_id: Long = 0,
        val device_secret: String = "",
    )

    @Serializable
    data class TxUpload(
        val bank: String,
        val sender: String,
        val amount_rial: Long,
        /** ISO-8601 همراه اختلاف ساعت (مثلاً 2026-09-18T14:30:00+03:30) — قرارداد اصلی */
        val occurred_at: String,
        val dest_last4: String,
        val reference: String,
        val raw_text: String,
        @SerialName("raw_hash") val rawHash: String? = null,
        /** کارتِ تطبیق‌یافته در خط لوله جدید (اختیاری — سرور اعتبارسنجی می‌کند) */
        val card_id: Long? = null,
        /** اثر انگشت ضدتکرار (SHA-256) — سرور هم UNIQUE اعمال می‌کند */
        val fingerprint: String? = null,
    )

    @Serializable
    private data class TxUploadRequest(val transactions: List<TxUpload>)

    @Serializable
    private data class RegisterRequest(val device_name: String, val model: String, val app_version: String)

    // ------------------------------------------------------------ endpoints

    suspend fun pair(req: PairRequest): PairResult = post("/api/v1/auth/pair", json.encodeToString(PairRequest.serializer(), req), null) {
        json.decodeFromJsonElement(PairResult.serializer(), it)
    }

    suspend fun registerDevice(deviceId: Long, secret: String, name: String, model: String, appVersion: String): RegisterResult =
        post(
            "/api/v1/devices/register",
            json.encodeToString(
                RegisterRequest.serializer(),
                RegisterRequest(device_name = name, model = model, app_version = appVersion),
            ),
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(RegisterResult.serializer(), it) }

    suspend fun heartbeat(deviceId: Long, secret: String): HeartbeatResult =
        post("/api/v1/devices/heartbeat", "{}", DeviceAuth(deviceId, secret)) {
            json.decodeFromJsonElement(HeartbeatResult.serializer(), it)
        }

    suspend fun storeStatus(deviceId: Long, secret: String): StoreStatus =
        get("/api/v1/store/status", DeviceAuth(deviceId, secret)) {
            json.decodeFromJsonElement(StoreStatus.serializer(), it)
        }

    suspend fun uploadTransactions(deviceId: Long, secret: String, items: List<TxUpload>): UploadResult =
        post(
            "/api/v1/transactions",
            json.encodeToString(TxUploadRequest.serializer(), TxUploadRequest(items)),
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(UploadResult.serializer(), it) }

    // ------------------------------------------------------------ device config sync

    @Serializable
    data class DeviceConfigPayload(
        val config_version: Long = 0,
        val cards: List<DeviceCard> = emptyList(),
    )

    @Serializable
    data class DeviceCard(
        val id: Long,
        val label: String = "",
        val bank: String = "",
        val last4: String = "",
        val enabled: Boolean = true,
        val senders: List<String> = emptyList(),
        val rules: List<DeviceRule> = emptyList(),
        /** دور ۲۳ — چندفروشگاهی: کارت به کدام فروشگاه تعلق دارد (۰ = سرور قدیمی) */
        val store_id: Long = 0,
        val store_name: String = "",
    )

    @Serializable
    data class DeviceRule(
        val id: Long,
        val name: String = "",
        val priority: Int = 100,
        val rule: ir.peakopay.app.sms.CardRuleParser.Rule,
        val version: Long = 0,
    )

    /** GET /api/v1/devices/config — کارت‌ها/فرستنده‌ها/قواعد پارسر (بدون شماره کارت کامل). */
    suspend fun deviceConfig(deviceId: Long, secret: String): DeviceConfigPayload =
        get("/api/v1/devices/config", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(DeviceConfigPayload.serializer(), el)
        }

    // ------------------------------------------------------------ payment events (notifications)

    /** GET /api/v1/devices/payevents — آخرین پرداخت‌ها برای اعلان‌های اپ */
    @Serializable
    data class PayEvent(
        val id: Long = 0,
        val order_id: String = "",
        val amount_rial: Long = 0,
        val status: String = "",
        val is_test: Boolean = false,
        val updated_at: String = "",
    )

    @Serializable
    data class PayEvents(val events: List<PayEvent> = emptyList(), val server_time: String = "")

    suspend fun paymentEvents(deviceId: Long, secret: String): PayEvents =
        get("/api/v1/devices/payevents", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(PayEvents.serializer(), el)
        }

    // ------------------------------------------------------------ card manager

    /** GET /api/v1/devices/cards */
    @Serializable
    data class MgmtRule(val id: Long = 0, val name: String = "", val priority: Int = 0, val enabled: Boolean = false)

    @Serializable
    data class MgmtCard(
        val id: Long = 0,
        val label: String = "",
        val bank_name: String = "",
        val holder: String = "",
        val last4: String = "",
        val iban: String = "",
        val account: String = "",
        val enabled: Boolean = true,
        val is_default: Boolean = false,
        val senders: List<String> = emptyList(),
        val rules: List<MgmtRule> = emptyList(),
    )

    @Serializable
    data class CardsMgmt(val cards: List<MgmtCard> = emptyList(), val config_version: Long = 0)

    suspend fun cardsMgmt(deviceId: Long, secret: String, storeId: Long = 0): CardsMgmt =
        get("/api/v1/devices/cards" + if (storeId > 0) "?store_id=$storeId" else "", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(CardsMgmt.serializer(), el)
        }

    /** ساخت/ویرایش کارت — شماره کارت فقط نوشتنی است (هرگز برنمی‌گردد) */
    @Serializable
    data class CardSaveResult(val card_id: Long = 0, val config_version: Long = 0)

    suspend fun cardSave(
        deviceId: Long, secret: String,
        cardId: Long, label: String, bankName: String, holder: String,
        number: String, iban: String, account: String, senders: List<String>,
        storeId: Long = 0,
    ): CardSaveResult = post(
        "/api/v1/devices/cards/save",
        buildJsonObject {
                put("card_id", cardId)
                put("label", label); put("bank_name", bankName); put("holder", holder)
                put("number", number); put("iban", iban); put("account", account)
                put("senders", kotlinx.serialization.json.JsonArray(senders.map { kotlinx.serialization.json.JsonPrimitive(it) }))
                if (storeId > 0) put("store_id", storeId)
            }.toString(),
        DeviceAuth(deviceId, secret),
    ) { json.decodeFromJsonElement(CardSaveResult.serializer(), it) }

    suspend fun cardAction(deviceId: Long, secret: String, cardId: Long, action: String): SimpleResult =
        post("/api/v1/devices/cards/action", "{\"card_id\":$cardId,\"do\":\"$action\"}", DeviceAuth(deviceId, secret)) {
            json.decodeFromJsonElement(SimpleResult.serializer(), it)
        }

    suspend fun ruleAction(deviceId: Long, secret: String, ruleId: Long, action: String): SimpleResult =
        post("/api/v1/devices/rules/action", "{\"rule_id\":$ruleId,\"do\":\"$action\"}", DeviceAuth(deviceId, secret)) {
            json.decodeFromJsonElement(SimpleResult.serializer(), it)
        }

    /** تحلیل نمونه — همان موتور پنل (سگمنت‌ها + پیشنهاد نوع) */
    @Serializable
    data class Segment(val text: String, val span: List<JsonElement?>? = null, val candidate: Boolean = false)

    @Serializable
    data class AnalyzeResult(val ok: Boolean = false, val segments: List<Segment> = emptyList())

    suspend fun analyzeSample(deviceId: Long, secret: String, sample: String): AnalyzeResult =
        post("/api/v1/devices/analyze", buildJsonObject { put("sample", sample) }.toString(), DeviceAuth(deviceId, secret)) {
            json.decodeFromJsonElement(AnalyzeResult.serializer(), it)
        }

    /** ساخت قالب از نمونه + انتخاب‌های کاربر */
    @Serializable
    data class RuleSaveResult(
        val rule_id: Long = 0, val ok: Boolean = false, val amount_rial: Long = 0,
        val fields: Map<String, String> = emptyMap(), val config_version: Long = 0,
    )

    suspend fun ruleSave(deviceId: Long, secret: String, cardId: Long, sample: String, spansJson: String, name: String, priority: Int): RuleSaveResult =
        post(
            "/api/v1/devices/rules/save",
            buildJsonObject {
                    put("card_id", cardId); put("sample", sample)
                    put("spans", json.parseToJsonElement(spansJson))
                    put("name", name); put("priority", priority)
                }.toString(),
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(RuleSaveResult.serializer(), it) }

    /** تست خط لوله روی سرور (فرستنده ← کارت ← قالب‌ها) */
    @Serializable
    data class TestResult(val card: String = "", val matched_rule: String = "", val amount_rial: Long = 0, val currency: String = "", val fields: Map<String, String> = emptyMap())

    suspend fun pipelineTest(deviceId: Long, secret: String, sender: String, text: String, cardId: Long): TestResult =
        post(
            "/api/v1/devices/test",
            buildJsonObject { put("sender", sender); put("text", text); put("card_id", cardId) }.toString(),
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(TestResult.serializer(), it) }

    @Serializable
    data class SimpleResult(val done: Boolean = false, val config_version: Long = 0)

    // ------------------------------------------------------------ manual confirm

    /** GET /api/v1/devices/pending — آیتم‌های در انتظار تصمیم فروشنده */
    @Serializable
    data class PendingPayment(
        val id: Long = 0,
        val order_id: String = "",
        val amount_rial: Long = 0,
        val currency: String = "IRR",
        val created_at: String = "",
        val expires_at: String = "",
        // پرداخت بدون پیامک/تراکنش: سرور COALESCE می‌کند، ولی nullable هم امن است
        val txn_id: Long? = 0L,
        val txn_amount: Long? = 0L,
        val bank: String? = "",
        val sender: String? = "",
        val occurred_at: String? = "",
        val reference: String? = "",
    )

    @Serializable
    data class PendingTxn(
        val id: Long = 0,
        val amount_rial: Long = 0,
        val bank: String = "",
        val sender: String = "",
        val occurred_at: String = "",
        val reference: String = "",
        val dest_last4: String = "",
        val reason: String = "",
    )

    @Serializable
    data class OpenPayment(
        val id: Long = 0,
        val order_id: String = "",
        val amount_rial: Long = 0,
        val created_at: String = "",
        val expires_at: String = "",
    )

    @Serializable
    data class PendingOverview(
        val awaiting: List<PendingPayment> = emptyList(),
        val unmatched: List<PendingTxn> = emptyList(),
        val open: List<OpenPayment> = emptyList(),
    )

    @Serializable
    data class ActionOutcome(
        val confirmed: Boolean = false,
        val attached: Boolean = false,
        val payment: PaidInfo = PaidInfo(),
    )

    @Serializable
    data class PaidInfo(val id: Long = 0, val status: String = "", val order_id: String = "")

    suspend fun pendingOverview(deviceId: Long, secret: String): PendingOverview =
        get("/api/v1/devices/pending", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(PendingOverview.serializer(), el)
        }

    /** تأیید دستی پرداختِ «در انتظار تأیید» */
    suspend fun confirmPayment(deviceId: Long, secret: String, paymentId: Long): ActionOutcome =
        post(
            "/api/v1/devices/confirm",
            "{\"payment_id\":$paymentId}",
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(ActionOutcome.serializer(), it) }

    /** اتصال دستی تراکنش بدون تطبیق به یک پرداخت باز */
    suspend fun attachTransaction(deviceId: Long, secret: String, transactionId: Long, paymentId: Long): ActionOutcome =
        post(
            "/api/v1/devices/attach",
            "{\"transaction_id\":$transactionId,\"payment_id\":$paymentId}",
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(ActionOutcome.serializer(), it) }

    /** دور ۲۵ — حذف تراکنش «نیاز به بررسی» از فهرست بررسی‌ها (× کنار کارت) */
    @Serializable
    data class DismissResult(val dismissed: Boolean = false)

    suspend fun dismissTransaction(deviceId: Long, secret: String, transactionId: Long): DismissResult =
        post(
            "/api/v1/devices/dismiss",
            "{\"transaction_id\":$transactionId}",
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(DismissResult.serializer(), it) }

    /** دور ۲۵ — بستن پرداخت باز: failed + رویداد cancelled به ووکامرس (لغو سفارش) */
    @Serializable
    data class CloseResult(val closed: Boolean = false, val woo_notified: Boolean = false, val payment: PaidInfo = PaidInfo())

    suspend fun closePayment(deviceId: Long, secret: String, paymentId: Long): CloseResult =
        post(
            "/api/v1/devices/close",
            "{\"payment_id\":$paymentId}",
            DeviceAuth(deviceId, secret),
        ) { json.decodeFromJsonElement(CloseResult.serializer(), it) }

    // ------------------------------------------------------------ حساب (دور ۲۳ — چندفروشگاهی)

    /** POST /api/v1/auth/login — ورود اپ با ایمیل/رمز حساب پنل */
    @Serializable
    data class LoginRequest(
        val email: String,
        val password: String,
        val device_name: String,
        val model: String,
        val app_version: String,
        val android_version: String,
        // دور ۲۵ — افزودن فروشگاه: ارتقای همین دستگاه به این حساب (بدون دستگاه جدید)
        val device_id: Long = 0,
        val device_secret: String = "",
    )

    @Serializable
    data class AccountStoreView(
        val id: Long = 0,
        val name: String = "",
        val domain: String = "",
        val connection_status: String = "",
        val currency: String = "IRT",
        val logo_url: String = "", // دور ۲۶ — لوگوی سایت فروشگاه (favicon) برای آواتار
    )

    @Serializable
    data class LoginUser(val name: String = "", val role: String = "")

    @Serializable
    data class LoginAccount(val name: String = "")

    @Serializable
    data class LoginResult(
        val device_id: Long = 0,
        val device_secret: String = "",
        val status: String = "active",
        val user: LoginUser = LoginUser(),
        val account: LoginAccount = LoginAccount(),
        val stores: List<AccountStoreView> = emptyList(),
        val config_version: Long = 0,
        val api_version: String = "v1",
    )

    suspend fun accountLogin(req: LoginRequest): LoginResult =
        post("/api/v1/auth/login", json.encodeToString(LoginRequest.serializer(), req), null) {
            json.decodeFromJsonElement(LoginResult.serializer(), it)
        }

    /** GET /api/v1/account/stores — فروشگاه‌های حساب این دستگاه */
    @Serializable
    data class AccountStores(val stores: List<AccountStoreView> = emptyList(), val server_time: String = "")

    suspend fun accountStores(deviceId: Long, secret: String): AccountStores =
        get("/api/v1/account/stores", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(AccountStores.serializer(), el)
        }

    /** GET /api/v1/account/overview — نمای کلی کل حساب یا یک فروشگاه (?store_id=) */
    @Serializable
    data class OverviewPerStore(
        val store_id: Long = 0,
        val name: String = "",
        val domain: String = "",
        val connection_status: String = "",
        val today_income_rial: Long = 0,
        val today_paid: Int = 0,
        val pending: Int = 0,
        val last_sync_at: String = "",
    )

    @Serializable
    data class OverviewToday(
        val income_rial: Long = 0,
        val paid_count: Int = 0,
        val transactions: Int = 0,
        val unmatched: Int = 0,
        val failed: Int = 0,
        val expired: Int = 0,
        val pending: Int = 0,
    )

    @Serializable
    data class OverviewTotals(val income_rial: Long = 0, val paid_count: Int = 0)

    @Serializable
    data class OverviewLastTx(
        val amount_rial: Long = 0,
        val bank: String = "",
        val created_at: String = "",
        val status: String = "",
        val store_id: Long = 0,
        val store_name: String = "",
    )

    @Serializable
    data class OverviewDevice(val id: Long = 0, val name: String = "", val status: String = "", val last_seen_at: String = "")

    @Serializable
    data class AccountOverview(
        val scope: String = "store",
        val store_id: Long? = null,
        val stores: List<AccountStoreView> = emptyList(),
        val per_store: List<OverviewPerStore> = emptyList(),
        val today: OverviewToday = OverviewToday(),
        val totals: OverviewTotals = OverviewTotals(),
        val last_transaction: OverviewLastTx? = null,
        val device: OverviewDevice = OverviewDevice(),
        val server_time: String = "",
    )

    suspend fun accountOverview(deviceId: Long, secret: String, storeId: Long = 0): AccountOverview =
        get("/api/v1/account/overview" + if (storeId > 0) "?store_id=$storeId" else "", DeviceAuth(deviceId, secret)) { el ->
            json.decodeFromJsonElement(AccountOverview.serializer(), el)
        }

    /** GET /api/v1/account/transactions — پرداخت‌ها/تراکنش‌های حساب با فیلتر */
    @Serializable
    data class AccountTxRow(
        val kind: String = "payment",
        val id: Long = 0,
        val store_id: Long = 0,
        val store_name: String = "",
        val amount_rial: Long = 0,
        val status: String = "",
        val order_id: String = "",
        val is_test: Boolean = false,
        val created_at: String = "",
        val paid_at: String = "",
        val expires_at: String = "",
        val woo_notify_status: String = "",
        val bank: String = "",
        val sender: String = "",
        val occurred_at: String = "",
        val reference: String = "",
        val dest_last4: String = "",
        val has_txn: Boolean = false,
    )

    @Serializable
    data class AccountTransactions(
        val transactions: List<AccountTxRow> = emptyList(),
        val page: Int = 1,
        val more: Boolean = false,
        val scope_store: Long? = null,
        val server_time: String = "",
    )

    suspend fun accountTransactions(
        deviceId: Long, secret: String,
        storeId: Long = 0, status: String = "all", q: String = "", page: Int = 1,
    ): AccountTransactions =
        get(
            "/api/v1/account/transactions?status=" + java.net.URLEncoder.encode(status, "UTF-8") +
                "&page=$page" + (if (q.isNotEmpty()) "&q=" + java.net.URLEncoder.encode(q, "UTF-8") else "") +
                (if (storeId > 0) "&store_id=$storeId" else ""),
            DeviceAuth(deviceId, secret),
        ) { el ->
            json.decodeFromJsonElement(AccountTransactions.serializer(), el)
        }

    // ------------------------------------------------------------ core

    data class DeviceAuth(val deviceId: Long, val secret: String)

    private fun hmacSha256(secret: String, data: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(secret.toByteArray(Charsets.UTF_8), "HmacSHA256"))
        return mac.doFinal(data.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
    }

    private fun buildRequest(method: String, path: String, body: String?, auth: DeviceAuth?): Request {
        val builder = Request.Builder()
            .url(serverUrl + path)
            .header("Accept", "application/json")
        if (body != null) {
            builder.header("Content-Type", "application/json; charset=utf-8")
        }
        if (auth != null) {
            val ts = (System.currentTimeMillis() / 1000).toString()
            val signature = hmacSha256(auth.secret, ts + "." + (body ?: ""))
            builder.header("X-Peakopay-Device", auth.deviceId.toString())
                .header("X-Peakopay-Timestamp", ts)
                .header("X-Peakopay-Signature", signature)
        }
        val media = "application/json; charset=utf-8".toMediaType()
        return when (method) {
            "GET" -> builder.get().build()
            else -> builder.method(method, (body ?: "{}").toRequestBody(media)).build()
        }
    }

    /**
     * اجرای درخواست — همیشه خارج از ترد اصلی (Dispatchers.IO).
     * نکته مهم: OkHttp همگام است؛ قبل از این اصلاح فراخوانی از Main باعث
     * NetworkOnMainThreadException و خطای «ارتباط با سرور برقرار نشد» می‌شد.
     */
    private suspend fun execute(request: Request): JsonElement = withContext(Dispatchers.IO) {
        val response = try {
            http.newCall(request).execute()
        } catch (e: IOException) {
            throw ApiException("network", "ارتباط با سرور برقرار نشد (${e.javaClass.simpleName}: ${e.message}). آدرس سرور و اینترنت را بررسی کنید.", 0)
        }
        response.use { resp ->
            val text = resp.body?.string().orEmpty()
            val envelope = try {
                json.decodeFromString(Envelope.serializer(), text)
            } catch (e: Exception) {
                throw ApiException("bad_response", "پاسخ سرور قابل خواندن نبود.", resp.code)
            }
            if (!envelope.ok) {
                throw ApiException(
                    envelope.error?.code ?: "unknown",
                    envelope.error?.message ?: "خطای نامشخص",
                    resp.code,
                )
            }
            envelope.data ?: buildJsonObject { }
        }
    }

    private suspend fun <T> post(path: String, body: String, auth: DeviceAuth?, mapper: (JsonElement) -> T): T =
        mapper(execute(buildRequest("POST", path, body, auth)))

    private suspend fun <T> get(path: String, auth: DeviceAuth?, mapper: (JsonElement) -> T): T =
        mapper(execute(buildRequest("GET", path, null, auth)))
}
