package ir.peakopay.app.data

import android.content.Context
import android.content.SharedPreferences
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.builtins.MapSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.json.Json

/**
 * نشست دستگاه — در حافظه خصوصی اپ (بدون بکاپ، بدون لاگ).
 *
 * راز دستگاه (hpd_…) فقط اینجا نگه داشته می‌شود؛ هرگز در لاگ‌ها، اعلان‌ها
 * یا تراکنش‌های ارسالی قرار نمی‌گیرد.
 */
class Prefs private constructor(private val sp: SharedPreferences) {

    var serverUrl: String
        get() = sp.getString(KEY_SERVER, "").orEmpty().trimEnd('/')
        set(v) = sp.edit().putString(KEY_SERVER, v.trimEnd('/')).apply()

    var deviceId: Long
        get() = sp.getLong(KEY_DEVICE_ID, 0L)
        set(v) = sp.edit().putLong(KEY_DEVICE_ID, v).apply()

    var deviceSecret: String
        get() = sp.getString(KEY_SECRET, "").orEmpty()
        set(v) = sp.edit().putString(KEY_SECRET, v).apply()

    var deviceName: String
        get() = sp.getString(KEY_NAME, "").orEmpty()
        set(v) = sp.edit().putString(KEY_NAME, v).apply()

    var storeName: String
        get() = sp.getString(KEY_STORE, "").orEmpty()
        set(v) = sp.edit().putString(KEY_STORE, v).apply()

    var deviceStatus: String
        get() = sp.getString(KEY_STATUS, "").orEmpty()
        set(v) = sp.edit().putString(KEY_STATUS, v).apply()

    var lastSyncAt: Long
        get() = sp.getLong(KEY_LAST_SYNC, 0L)
        set(v) = sp.edit().putLong(KEY_LAST_SYNC, v).apply()

    /** آخرین پیامک بانکی دریافت‌شده (epoch ms) — برای صفحه وضعیت/عیب‌یابی */
    var lastSmsReceivedAt: Long
        get() = sp.getLong(KEY_LAST_SMS, 0L)
        set(v) = sp.edit().putLong(KEY_LAST_SMS, v).apply()

    /** آخرین خطای همگام‌سازی (بدون هیچ راز/اطلاعات بانکی) */
    var lastSyncError: String
        get() = sp.getString(KEY_LAST_ERR, "").orEmpty()
        set(v) = sp.edit().putString(KEY_LAST_ERR, v.take(300)).apply()

    /** سرویس همیشه‌روشن — پیش‌فرض روشن؛ کاربر از صفحه وضعیت خاموش/روشن می‌کند */
    var serviceEnabled: Boolean
        get() = sp.getBoolean(KEY_SERVICE_ON, true)
        set(v) = sp.edit().putBoolean(KEY_SERVICE_ON, v).apply()

    /** ویزارد راه‌اندازی اولیه انجام شده؟ */
    var wizardDone: Boolean
        get() = sp.getBoolean(KEY_WIZARD, false)
        set(v) = sp.edit().putBoolean(KEY_WIZARD, v).apply()

    // ── اعلان رویدادهای پرداخت: چه چیزی دیده شده (id → status) ──
    fun payEventsSeen(): MutableMap<String, String> {
        val raw = sp.getString(KEY_PAY_SEEN, "{}").orEmpty()
        return try {
            pipeJson.decodeFromString(MapSerializer(String.serializer(), String.serializer()), raw).toMutableMap()
        } catch (e: Exception) { mutableMapOf() }
    }

    fun setPayEventsSeen(map: Map<String, String>) {
        val capped = map.entries.sortedByDescending { it.key.toLongOrNull() ?: 0 }.take(120).associate { it.toPair() }
        sp.edit().putString(KEY_PAY_SEEN, pipeJson.encodeToString(MapSerializer(String.serializer(), String.serializer()), capped)).apply()
    }

    /** اولین poll بعد از نصب فقط وضعیت را می‌خواند (بدون اعلان) */
    var payEventsInit: Boolean
        get() = sp.getBoolean(KEY_PAY_INIT, false)
        set(v) = sp.edit().putBoolean(KEY_PAY_INIT, v).apply()

    /** برای تشخیص «رد قطعی» مجوزها (بعد از اولین درخواست) */
    var smsPermRequested: Boolean
        get() = sp.getBoolean(KEY_SMS_ASKED, false)
        set(v) = sp.edit().putBoolean(KEY_SMS_ASKED, v).apply()

    var notifPermRequested: Boolean
        get() = sp.getBoolean(KEY_NOTIF_ASKED, false)
        set(v) = sp.edit().putBoolean(KEY_NOTIF_ASKED, v).apply()

    // ── دور ۲۳ — چندفروشگاهی: حساب، فروشگاه‌های آن و نمای فعال داشبورد ──

    /** منبع اعتبارنامه: "pair" (کد همراه‌سازی) یا "login" (ایمیل/رمز حساب) */
    var deviceSource: String
        get() = sp.getString(KEY_SOURCE, "pair").orEmpty()
        set(v) = sp.edit().putString(KEY_SOURCE, v).apply()

    var accountName: String
        get() = sp.getString(KEY_ACCOUNT_NAME, "").orEmpty()
        set(v) = sp.edit().putString(KEY_ACCOUNT_NAME, v).apply()

    var accountEmail: String
        get() = sp.getString(KEY_ACCOUNT_EMAIL, "").orEmpty()
        set(v) = sp.edit().putString(KEY_ACCOUNT_EMAIL, v).apply()

    /** کش فروشگاه‌های حساب (بین اجراها؛ رفرش با account/stores) */
    fun cachedStores(): List<ir.peakopay.app.data.api.Api.AccountStoreView> = try {
        pipeJson.decodeFromString(
            ListSerializer(ir.peakopay.app.data.api.Api.AccountStoreView.serializer()),
            sp.getString(KEY_STORES, "[]").orEmpty(),
        )
    } catch (e: Exception) {
        emptyList()
    }

    fun setCachedStores(stores: List<ir.peakopay.app.data.api.Api.AccountStoreView>) {
        sp.edit().putString(
            KEY_STORES,
            pipeJson.encodeToString(ListSerializer(ir.peakopay.app.data.api.Api.AccountStoreView.serializer()), stores),
        ).apply()
    }

    /** فروشگاهِ فعال داشبورد — ۰ یعنی هنوز انتخاب نشده (خودکار اولین فروشگاه انتخاب می‌شود) */
    var activeStoreId: Long
        get() = sp.getLong(KEY_ACTIVE_STORE, 0L)
        set(v) = sp.edit().putLong(KEY_ACTIVE_STORE, v).apply()

    val isPaired: Boolean get() = deviceId > 0 && deviceSecret.isNotEmpty() && serverUrl.isNotEmpty()

    val isActive: Boolean get() = deviceStatus == "active"

    // ------------------------------------------------- رخدادهای خط لوله پیامک
    // آخرین تصمیم‌های اپ درباره پیامک‌های دریافتی (حلقه ۱۵تایی) — برای «عیب‌یابی».
    // هیچ متن پیامک یا اطلاعات بانکی ذخیره نمی‌شود؛ فقط فرستنده + تصمیم.

    @Serializable
    data class PipelineEvent(val ts: Long, val kind: String, val sender: String, val detail: String = "")

    private val pipeJson = Json { ignoreUnknownKeys = true }

    /**
     * رخدادهای «خط لوله پیامک» — فقط برای فرستنده‌های ثبت‌شده در قالب کارت‌ها
     * ثبت می‌شوند (دور ۲۱): پیامک شماره‌های دیگر اصلاً بررسی/ثبت نمی‌شود.
     */
    fun pipelineEvents(): List<PipelineEvent> = try {
        pipeJson.decodeFromString(
            ListSerializer(PipelineEvent.serializer()),
            sp.getString(KEY_PIPELINE, "[]").orEmpty(),
        )
    } catch (e: Exception) {
        emptyList()
    }

    fun addPipelineEvent(kind: String, sender: String, detail: String = "") {
        val list = pipelineEvents().toMutableList()
        // پاک‌سازی تدریجی رخدادهای قدیمیِ «ignored_sender» (فرستنده ثبت‌نشده —
        // نسخه‌های قبل ثبت می‌کردند؛ دیگر هرگز ثبت نمی‌شود). حریم خصوصی.
        list.removeAll { it.kind == "ignored_sender" }
        list.add(0, PipelineEvent(System.currentTimeMillis(), kind, sender.take(40), detail.take(160)))
        sp.edit().putString(
            KEY_PIPELINE,
            pipeJson.encodeToString(ListSerializer(PipelineEvent.serializer()), list.take(15)),
        ).apply()
    }

    fun clear() = sp.edit().clear().apply()

    companion object {
        private const val FILE = "device_session"
        private const val KEY_SERVER = "server_url"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_SECRET = "device_secret"
        private const val KEY_NAME = "device_name"
        private const val KEY_STORE = "store_name"
        private const val KEY_STATUS = "device_status"
        private const val KEY_LAST_SYNC = "last_sync_at"
        private const val KEY_LAST_SMS = "last_sms_at"
        private const val KEY_LAST_ERR = "last_sync_error"
        private const val KEY_SERVICE_ON = "service_enabled"
        private const val KEY_WIZARD = "wizard_done"
        private const val KEY_SMS_ASKED = "sms_perm_asked"
        private const val KEY_NOTIF_ASKED = "notif_perm_asked"
        private const val KEY_PIPELINE = "pipeline_events"
        private const val KEY_PAY_SEEN = "pay_events_seen"
        private const val KEY_PAY_INIT = "pay_events_init"
        private const val KEY_SOURCE = "device_source"
        private const val KEY_ACCOUNT_NAME = "account_name"
        private const val KEY_ACCOUNT_EMAIL = "account_email"
        private const val KEY_STORES = "account_stores"
        private const val KEY_ACTIVE_STORE = "active_store_id"

        @Volatile
        private var inst: Prefs? = null

        fun get(context: Context): Prefs =
            inst ?: synchronized(this) {
                inst ?: Prefs(context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE))
                    .also { inst = it }
            }
    }
}
