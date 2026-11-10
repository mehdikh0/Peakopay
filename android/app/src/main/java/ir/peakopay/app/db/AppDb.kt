package ir.peakopay.app.db

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

/**
 * پایگاه داده محلی:
 *  - OutboxEntity: صف پیامک‌های بانکی در انتظار ارسال (تا رسیدن اینترنت)
 *  - TxRecordEntity: تاریخچه نتایج ارسال (برای صفحه تاریخچه)
 */
@Database(
    entities = [OutboxEntity::class, TxRecordEntity::class],
    version = 5,
    exportSchema = false,
)
abstract class AppDb : RoomDatabase() {
    abstract fun outboxDao(): OutboxDao
    abstract fun txRecordDao(): TxRecordDao

    companion object {
        /** v1 → v2: ستون‌های card_id و fingerprint برای خط لوله فرستنده-محور (صف حفظ می‌شود). */
        private val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE outbox ADD COLUMN card_id INTEGER")
                db.execSQL("ALTER TABLE outbox ADD COLUMN fingerprint TEXT")
            }
        }

        /** v2 → v3: ستون message در تاریخچه — «چرا تطبیق نخورد» (دلیل فارسی سرور). */
        private val MIGRATION_2_3 = object : Migration(2, 3) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE tx_records ADD COLUMN message TEXT")
            }
        }

        /**
         * v3 → v5 (رفع کرش مهاجرت):
         *
         * ریشه باگ: در APK versionCode 13، نسخه @Database به‌اشتباه ۳ ماند (نه ۴).
         * در نتیجه روی دستگاه‌هایی که از نسخه ۱۲ ارتقا داده بودند، هیچ مهاجرتی
         * اجرا نشد و اعتبارسنجی Room به‌خاطر ستون جدید last_attempt_at کرش می‌کرد
         * («Migration didn't properly handle: outbox») — اپ روی هر بار باز شدن می‌بست.
         *
         * این مهاجرت هر دو حالت ممکنِ دیتابیسِ نسخه ۳ را پوشش می‌دهد:
         *  - شکل A (ارتقا از نسخه‌های قبل از ۱۳): ستون last_attempt_at وجود ندارد → ALTER.
         *  - شکل B (نصب تازه نسخه ۱۳): ستون هست (Room با entity جدید ساخته).
         * سپس جدول outbox دقیقاً به شکل CREATE تولیدی Room بازسازی می‌شود تا هر دو
         * شکل همگرا شوند (بدون DEFAULT سطح دیتابیس — مقدار پیش‌فرض در خود Kotlin
         * است و Room هنگام INSERT همیشه مقدار می‌فرستد). داده‌های صف دست‌نخورده
         * کپی می‌شوند. نسخه ۴ عمداً رد شده چون هرگز منتشر نشد.
         */
        private val MIGRATION_3_5 = object : Migration(3, 5) {
            override fun migrate(db: SupportSQLiteDatabase) {
                // آیا ستون از قبل وجود دارد؟ (شکل A یا B)
                var hasColumn = false
                db.query("PRAGMA table_info(`outbox`)").use { cursor ->
                    val nameIdx = cursor.getColumnIndexOrThrow("name")
                    while (cursor.moveToNext()) {
                        if (cursor.getString(nameIdx) == "last_attempt_at") {
                            hasColumn = true
                            break
                        }
                    }
                }
                if (!hasColumn) {
                    db.execSQL("ALTER TABLE `outbox` ADD COLUMN `last_attempt_at` INTEGER NOT NULL DEFAULT 0")
                }

                // بازسازی جدول به شکل دقیقاً یکسان با CREATE TABLE تولیدی Room
                db.execSQL(
                    "CREATE TABLE IF NOT EXISTS `_new_outbox` (" +
                        "`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, " +
                        "`bank` TEXT NOT NULL, `sender` TEXT NOT NULL, `amount_rial` INTEGER NOT NULL, " +
                        "`occurred_at` TEXT NOT NULL, `dest_last4` TEXT NOT NULL, `reference` TEXT NOT NULL, " +
                        "`raw_text` TEXT NOT NULL, `raw_hash` TEXT, `card_id` INTEGER, `fingerprint` TEXT, " +
                        "`received_at` INTEGER NOT NULL, `status` TEXT NOT NULL, `attempts` INTEGER NOT NULL, " +
                        "`last_attempt_at` INTEGER NOT NULL, `last_error` TEXT NOT NULL)",
                )
                db.execSQL(
                    "INSERT INTO `_new_outbox` (`id`,`bank`,`sender`,`amount_rial`,`occurred_at`," +
                        "`dest_last4`,`reference`,`raw_text`,`raw_hash`,`card_id`,`fingerprint`," +
                        "`received_at`,`status`,`attempts`,`last_attempt_at`,`last_error`) " +
                        "SELECT `id`,`bank`,`sender`,`amount_rial`,`occurred_at`,`dest_last4`," +
                        "`reference`,`raw_text`,`raw_hash`,`card_id`,`fingerprint`,`received_at`," +
                        "`status`,`attempts`,`last_attempt_at`,`last_error` FROM `outbox`",
                )
                db.execSQL("DROP TABLE `outbox`")
                db.execSQL("ALTER TABLE `_new_outbox` RENAME TO `outbox`")
            }
        }

        @Volatile
        private var inst: AppDb? = null

        fun get(context: Context): AppDb {
            inst?.let { return it }
            return synchronized(this) {
                if (inst == null) {
                    inst = Room.databaseBuilder(
                        context.applicationContext,
                        AppDb::class.java,
                        "peakopay.db",
                    ).addMigrations(MIGRATION_1_2, MIGRATION_2_3, MIGRATION_3_5).build()
                }
                inst!!
            }
        }
    }
}
