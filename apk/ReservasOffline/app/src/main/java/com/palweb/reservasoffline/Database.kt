package com.palweb.reservasoffline

import android.content.Context
import androidx.room.Dao
import androidx.room.Database
import androidx.room.Embedded
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Relation
import androidx.room.Room
import androidx.room.RoomDatabase
import kotlinx.coroutines.flow.Flow

@Dao
interface ProductDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<ProductEntity>)

    @Query("SELECT * FROM products WHERE active = 1 AND (name LIKE '%' || :q || '%' OR code LIKE '%' || :q || '%') ORDER BY name ASC LIMIT 60")
    fun search(q: String): Flow<List<ProductEntity>>

    @Query("SELECT * FROM products WHERE code = :code LIMIT 1")
    suspend fun byCode(code: String): ProductEntity?

    @Query("DELETE FROM products")
    suspend fun clear()

    @Query("SELECT COUNT(*) FROM products WHERE active = 1")
    fun countFlow(): Flow<Int>
}

@Dao
interface ClientDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<ClientEntity>)

    @Query("SELECT * FROM clients WHERE active = 1 AND (name LIKE '%' || :q || '%' OR phone LIKE '%' || :q || '%') ORDER BY name ASC LIMIT 30")
    fun search(q: String): Flow<List<ClientEntity>>

    @Query("SELECT * FROM clients WHERE remoteId = :remoteId LIMIT 1")
    suspend fun byRemoteId(remoteId: Long): ClientEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(item: ClientEntity): Long

    @Query("SELECT * FROM clients WHERE id = :id LIMIT 1")
    suspend fun byId(id: Long): ClientEntity?

    @Query("DELETE FROM clients")
    suspend fun clear()
}

data class ReservationWithItemsRow(
    @Embedded val reservation: ReservationEntity,
    @Relation(parentColumn = "localUuid", entityColumn = "reservationUuid")
    val items: List<ReservationItemEntity>
)

@Dao
interface ReservationDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(item: ReservationEntity): Long

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<ReservationEntity>)

    @Query("DELETE FROM reservation_items WHERE reservationUuid = :reservationUuid")
    suspend fun clearItems(reservationUuid: String)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertItems(items: List<ReservationItemEntity>)

    @Query("SELECT * FROM reservations ORDER BY fechaReservaEpoch ASC")
    fun observeAll(): Flow<List<ReservationEntity>>

    @Query("SELECT * FROM reservations WHERE localUuid = :uuid LIMIT 1")
    suspend fun byLocalUuid(uuid: String): ReservationEntity?

    @Query("SELECT * FROM reservations WHERE remoteId = :remoteId LIMIT 1")
    suspend fun byRemoteId(remoteId: Long): ReservationEntity?

    @Query("SELECT * FROM reservation_items WHERE reservationUuid = :uuid")
    suspend fun itemsByUuid(uuid: String): List<ReservationItemEntity>

    @Query("SELECT * FROM reservations WHERE localUuid = :uuid LIMIT 1")
    fun observeByUuid(uuid: String): Flow<ReservationEntity?>

    @Query("SELECT * FROM reservations WHERE remoteId = :remoteId LIMIT 1")
    suspend fun findByRemote(remoteId: Long): ReservationEntity?

    @Query("SELECT COUNT(*) FROM reservations WHERE estadoReserva = 'PENDIENTE'")
    fun countPending(): Flow<Int>

    @Query("SELECT COUNT(*) FROM reservations WHERE needsSync = 1")
    fun countNeedsSyncFlow(): Flow<Int>

    @Query("DELETE FROM reservations")
    suspend fun clear()

    @Query("DELETE FROM reservation_items")
    suspend fun clearAllItems()
}

@Dao
interface QueueDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(item: SyncQueueEntity): Long

    @Query("SELECT * FROM sync_queue ORDER BY id ASC")
    suspend fun all(): List<SyncQueueEntity>

    @Query("DELETE FROM sync_queue WHERE id = :id")
    suspend fun delete(id: Long)

    @Query("UPDATE sync_queue SET attempts = attempts + 1 WHERE id = :id")
    suspend fun bumpAttempt(id: Long)

    @Query("UPDATE sync_queue SET attempts = attempts + 1, nextAttemptAtEpoch = :nextAttemptAt, lastError = :lastError WHERE id = :id")
    suspend fun scheduleRetry(id: Long, nextAttemptAt: Long, lastError: String)

    @Query("SELECT COUNT(*) FROM sync_queue")
    fun countFlow(): Flow<Int>
}

@Dao
interface SyncHistoryDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(item: SyncHistoryEntity): Long

    @Query("SELECT * FROM sync_history ORDER BY createdAtEpoch DESC LIMIT :limit")
    fun latest(limit: Int = 50): Flow<List<SyncHistoryEntity>>
}

@Database(
    entities = [
        ProductEntity::class,
        ClientEntity::class,
        ReservationEntity::class,
        ReservationItemEntity::class,
        SyncQueueEntity::class,
        SyncHistoryEntity::class,
    ],
    version = 2,
    exportSchema = false
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun productDao(): ProductDao
    abstract fun clientDao(): ClientDao
    abstract fun reservationDao(): ReservationDao
    abstract fun queueDao(): QueueDao
    abstract fun syncHistoryDao(): SyncHistoryDao

    companion object {
        private val INSTANCES: MutableMap<String, AppDatabase> = mutableMapOf()

        fun get(context: Context, sucursalId: Int = 1): AppDatabase = synchronized(this) {
            val dbName = "reservas_offline_suc_${sucursalId}.db"
            INSTANCES[dbName] ?: Room.databaseBuilder(
                context.applicationContext,
                AppDatabase::class.java,
                dbName
            ).fallbackToDestructiveMigration().build().also { INSTANCES[dbName] = it }
        }
    }
}
