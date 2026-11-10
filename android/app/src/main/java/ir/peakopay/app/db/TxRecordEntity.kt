package ir.peakopay.app.db

import androidx.room.Dao
import androidx.room.Entity
import androidx.room.Insert
import androidx.room.PrimaryKey
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

/** نتیجه ارسال هر تراکنش — برای صفحه «تاریخچه ارسال». */
@Entity(tableName = "tx_records")
data class TxRecordEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val outboxId: Long,
    val bank: String,
    val amount_rial: Long,
    val reference: String,
    /** result از سرور: matched | matched_pending | unmatched | duplicate | received | rejected */
    val result: String,
    val order_id: String?,
    val payment_status: String?,
    /** دلیل فارسی سرور (مثل «چرا تطبیق نخورد») — نمایش در تاریخچه */
    val message: String? = null,
    val recorded_at: Long = System.currentTimeMillis(),
)

@Dao
interface TxRecordDao {
    @Insert
    suspend fun insert(record: TxRecordEntity)

    @Query("SELECT * FROM tx_records ORDER BY recorded_at DESC LIMIT 200")
    fun observeAll(): Flow<List<TxRecordEntity>>

    @Query("DELETE FROM tx_records WHERE recorded_at < :cutoff")
    suspend fun purgeOlderThan(cutoff: Long)
}
