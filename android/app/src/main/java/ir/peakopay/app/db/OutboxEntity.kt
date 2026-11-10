package ir.peakopay.app.db

import androidx.room.Dao
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.PrimaryKey
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

/**
 * یک پیامک بانکی در صف ارسال.
 *
 * `status = pending_offline` یعنی تراکنش آفلاین ذخیره شده و هنوز با سرور همگام
 * نشده (دور ۱۵). ستون `last_attempt_at` زمان آخرین تلاش ارسال است (میلی‌ثانیه،
 * ۰ = هرگز). `attempts` همان retry_count است.
 */
@Entity(tableName = "outbox")
data class OutboxEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val bank: String,
    val sender: String,
    val amount_rial: Long,
    /** ISO-8601 با اختلاف ساعت */
    val occurred_at: String,
    val dest_last4: String,
    val reference: String,
    val raw_text: String,
    val raw_hash: String?,
    /** کارت تطبیق‌یافته در خط لوله فرستنده-محور (null = قدیمی/نامشخص) */
    val card_id: Long? = null,
    /** اثر انگشت ضدتکرار SHA-256 (کارت|فرستنده|مبلغ|مرجع|هش) */
    val fingerprint: String? = null,
    val received_at: Long,
    /** status: pending | pending_offline | sending | sent | failed | rejected */
    val status: String = "pending",
    /** تعداد تلاش‌های ارسال (retry_count) */
    val attempts: Int = 0,
    /** آخرین تلاش ارسال — میلی‌ثانیه (۰ = هرگز) */
    val last_attempt_at: Long = 0,
    val last_error: String = "",
)

@Dao
interface OutboxDao {
    @Insert
    suspend fun insert(item: OutboxEntity): Long

    /**
     * برداشتن دسته ارسال: وضعیت‌های قابل‌تلاش (از جمله pending_offline).
     * سقف ۱۰ تلاش؛ اما آیتم‌های مانده حداکثر یک‌بار در روز (last_attempt_at قدیمی)
     * دوباره واجد شرط می‌شوند — حذف نمی‌شوند و برای همیشه رها نمی‌شوند.
     */
    @Query("SELECT * FROM outbox WHERE status IN ('pending','pending_offline','failed') AND (attempts < 10 OR last_attempt_at < :staleCutoffMs) ORDER BY received_at ASC LIMIT 25")
    suspend fun takeBatch(staleCutoffMs: Long): List<OutboxEntity>

    @Query("SELECT * FROM outbox ORDER BY received_at DESC LIMIT 200")
    fun observeAll(): Flow<List<OutboxEntity>>

    @Query("SELECT COUNT(*) FROM outbox WHERE status IN ('pending','pending_offline','failed')")
    fun pendingCount(): Flow<Int>

    @Query("UPDATE outbox SET status = :status, attempts = attempts + 1, last_attempt_at = :nowMs, last_error = :error WHERE id = :id")
    suspend fun markAttempt(id: Long, status: String, error: String, nowMs: Long)

    @Query("UPDATE outbox SET status = 'sent', last_error = '' WHERE id = :id")
    suspend fun markSent(id: Long)

    @Query("UPDATE outbox SET status = 'rejected', last_error = :error WHERE id = :id")
    suspend fun markRejected(id: Long, error: String)

    @Query("SELECT * FROM outbox WHERE raw_hash = :hash LIMIT 1")
    suspend fun findByHash(hash: String): OutboxEntity?

    @Query("SELECT COUNT(*) FROM outbox WHERE status = 'pending_offline'")
    fun pendingOfflineCount(): Flow<Int>
}
