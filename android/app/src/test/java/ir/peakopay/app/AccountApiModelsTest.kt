package ir.peakopay.app

import ir.peakopay.app.data.api.Api
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * دور ۲۳ — مدل‌های چندفروشگاهی (ورود حسابی + نمای کلی + تراکنش‌ها):
 * decode پاسخ‌های واقعی سرور (پیوست JSON) با پیش‌فرض‌های امن — شکل پاسخ
 * هر endpoint فقط از طریق تایپ خودش decode می‌شود.
 */
class AccountApiModelsTest {

    private val json = Json { ignoreUnknownKeys = true; coerceInputValues = true }

    @Test
    fun loginResult_decodesAllStores() {
        val payload = """
        {"device_id":12,"device_secret":"hpd_x","status":"active",
         "user":{"name":"مدیر","role":"owner"},
         "account":{"name":"حساب اصلی"},
         "stores":[
           {"id":1,"name":"فروشگاه اصلی","domain":"shop1.example.com","connection_status":"connected","currency":"IRT"},
           {"id":2,"name":"شعبه دوم","domain":"shop2.example.com","connection_status":"pending","currency":"IRT"}
         ],
         "config_version":7,"api_version":"v1"}
        """.trimIndent()
        val r = json.decodeFromString(Api.LoginResult.serializer(), payload)
        assertEquals(12L, r.device_id)
        assertEquals("active", r.status)
        assertEquals("owner", r.user.role)
        assertEquals(2, r.stores.size)
        assertEquals("شعبه دوم", r.stores[1].name)
        assertEquals("pending", r.stores[1].connection_status)
        assertEquals(7L, r.config_version)
    }

    @Test
    fun loginResult_legacySingleStore_defaults() {
        // فیلدهای غایب → پیش‌فرض امن (بدون کرش)
        val payload = """{"device_id":3,"device_secret":"hpd_y"}"""
        val r = json.decodeFromString(Api.LoginResult.serializer(), payload)
        assertEquals(3L, r.device_id)
        assertEquals(0, r.stores.size)
        assertEquals("", r.account.name)
        assertEquals("active", r.status)
    }

    @Test
    fun accountOverview_multiStore_withPerStore() {
        val payload = """
        {"scope":"account","store_id":null,
         "stores":[{"id":1,"name":"a","domain":"a.com","connection_status":"connected","currency":"IRT"},
                   {"id":2,"name":"b","domain":"b.com","connection_status":"connected","currency":"IRT"}],
         "per_store":[
           {"store_id":1,"name":"a","domain":"a.com","connection_status":"connected","today_income_rial":120000,"today_paid":2,"pending":1,"last_sync_at":"2026-11-04 10:00:00"},
           {"store_id":2,"name":"b","domain":"b.com","connection_status":"connected","today_income_rial":450000,"today_paid":5,"pending":0,"last_sync_at":""}
         ],
         "today":{"income_rial":570000,"paid_count":7,"transactions":9,"unmatched":1,"failed":0,"expired":2,"pending":1},
         "totals":{"income_rial":9000000,"paid_count":120},
         "last_transaction":{"amount_rial":45000,"bank":"بانک ملی","created_at":"2026-11-04 09:30:00","status":"matched","store_id":2,"store_name":"b"},
         "device":{"id":12,"name":"گوشی مدیر","status":"active","last_seen_at":"2026-11-04 10:05:00"},
         "server_time":"2026-11-04T10:05:00+00:00"}
        """.trimIndent()
        val r = json.decodeFromString(Api.AccountOverview.serializer(), payload)
        assertEquals("account", r.scope)
        assertNull(r.store_id)
        assertEquals(2, r.per_store.size)
        assertEquals(570000L, r.today.income_rial)
        assertEquals(120, r.totals.paid_count)
        assertEquals("b", r.last_transaction?.store_name)
        assertEquals(2, r.today.expired)
    }

    @Test
    fun accountOverview_singleStore_noLastTx() {
        val payload = """
        {"scope":"store","store_id":1,"stores":[{"id":1,"name":"a","domain":"","connection_status":"connected","currency":"IRT"}],
         "per_store":[],"today":{"income_rial":0,"paid_count":0,"transactions":0,"unmatched":0,"failed":0,"expired":0,"pending":0},
         "totals":{"income_rial":0,"paid_count":0},"last_transaction":null,
         "device":{"id":1,"name":"d","status":"active","last_seen_at":""},"server_time":""}
        """.trimIndent()
        val r = json.decodeFromString(Api.AccountOverview.serializer(), payload)
        assertEquals("store", r.scope)
        assertEquals(1L, r.store_id)
        assertNull(r.last_transaction)
        assertEquals(0, r.per_store.size)
    }

    @Test
    fun accountTransactions_paymentAndTxnRows() {
        val payload = """
        {"transactions":[
          {"kind":"payment","id":9,"store_id":1,"store_name":"a","amount_rial":100000,"status":"paid","order_id":"1001","is_test":false,
           "created_at":"2026-11-04 08:00:00","paid_at":"2026-11-04 08:03:00","expires_at":"2026-11-04 08:20:00","woo_notify_status":"sent",
           "bank":"بانک ملت","sender":"MELLAT","occurred_at":"2026-11-04 08:02:00","reference":"123","dest_last4":"1234","has_txn":true},
          {"kind":"txn","id":55,"store_id":2,"store_name":"b","amount_rial":25000,"status":"unmatched","order_id":"","is_test":false,
           "created_at":"2026-11-04 09:00:00","paid_at":"","expires_at":"","woo_notify_status":"",
           "bank":"بانک سامان","sender":"SAMAN","occurred_at":"2026-11-04 08:59:00","reference":"MS-T2-1","dest_last4":"0027","has_txn":true}
        ],"page":1,"more":true,"scope_store":null,"server_time":""}
        """.trimIndent()
        val r = json.decodeFromString(Api.AccountTransactions.serializer(), payload)
        assertEquals(2, r.transactions.size)
        assertEquals("payment", r.transactions[0].kind)
        assertTrue(r.transactions[0].has_txn)
        assertEquals("unmatched", r.transactions[1].status)
        assertEquals("b", r.transactions[1].store_name)
        assertTrue(r.more)
        assertNull(r.scope_store)
    }

    @Test
    fun deviceConfig_cardsCarryStoreIdentity() {
        val payload = """
        {"config_version":9,"cards":[
          {"id":1,"store_id":1,"store_name":"فروشگاه اصلی","label":"کارت ۱","bank":"بانک ملی","last4":"1234","enabled":true,
           "senders":["MELLAT"],"rules":[],"updated_at":1730000000},
          {"id":2,"store_id":2,"store_name":"شعبه دوم","label":"کارت ۲","bank":"بانک سامان","last4":"0027","enabled":true,
           "senders":["SAMAN"],"rules":[],"updated_at":1730000000}
        ]}
        """.trimIndent()
        val r = json.decodeFromString(Api.DeviceConfigPayload.serializer(), payload)
        assertEquals(2, r.cards.size)
        assertEquals(1L, r.cards[0].store_id)
        assertEquals("شعبه دوم", r.cards[1].store_name)
        // بک‌کامپت: کارت بدون store_id (سرور قدیمی) → 0/"" بدون خطا
        val legacy = json.decodeFromString(
            Api.DeviceCard.serializer(),
            """{"id":5,"label":"l","bank":"b","last4":"9999","enabled":true,"senders":[],"rules":[]}""",
        )
        assertEquals(0L, legacy.store_id)
        assertEquals("", legacy.store_name)
        assertFalse(legacy.id == r.cards[1].id)
    }

    @Test
    fun accountStores_decodes() {
        val payload = """
        {"stores":[{"id":1,"name":"a","domain":"a.com","connection_status":"connected","currency":"IRT"}],"server_time":"x"}
        """.trimIndent()
        val r = json.decodeFromString(Api.AccountStores.serializer(), payload)
        assertEquals(1, r.stores.size)
        assertEquals("a.com", r.stores[0].domain)
    }
}
