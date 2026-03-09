package com.palweb.reservasoffline

import androidx.room.Entity
import androidx.room.ForeignKey
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(tableName = "products")
data class ProductEntity(
    @PrimaryKey val code: String,
    val name: String,
    val price: Double,
    val category: String,
    val stock: Double,
    val esServicio: Int,
    val esReservable: Int,
    val active: Int = 1
)

@Entity(tableName = "clients", indices = [Index(value = ["remoteId"], unique = true)])
data class ClientEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val remoteId: Long? = null,
    val name: String,
    val phone: String,
    val address: String,
    val category: String = "Regular",
    val active: Int = 1
)

@Entity(tableName = "reservations", indices = [Index(value = ["localUuid"], unique = true), Index(value = ["remoteId"])])
data class ReservationEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val localUuid: String,
    val remoteId: Long? = null,
    val clientName: String,
    val clientPhone: String,
    val clientAddress: String,
    val clientRemoteId: Long? = null,
    val fechaReservaEpoch: Long,
    val notes: String,
    val metodoPago: String,
    val canalOrigen: String,
    val estadoPago: String,
    val estadoReserva: String,
    val abono: Double,
    val total: Double,
    val costoMensajeria: Double,
    val sinExistencia: Int,
    val updatedAtEpoch: Long,
    val serverUpdatedAtEpoch: Long = 0L,
    val syncAttempts: Int = 0,
    val syncError: String = "",
    val needsSync: Int = 0
)

@Entity(
    tableName = "reservation_items",
    foreignKeys = [
        ForeignKey(
            entity = ReservationEntity::class,
            parentColumns = ["localUuid"],
            childColumns = ["reservationUuid"],
            onDelete = ForeignKey.CASCADE
        )
    ],
    indices = [Index(value = ["reservationUuid"])]
)
data class ReservationItemEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val reservationUuid: String,
    val productCode: String,
    val productName: String,
    val category: String,
    val qty: Double,
    val price: Double,
    val stockSnapshot: Double,
    val esServicio: Int
)

@Entity(tableName = "sync_queue")
data class SyncQueueEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val opType: String,
    val reservationUuid: String?,
    val payloadJson: String,
    val createdAtEpoch: Long,
    val attempts: Int = 0,
    val nextAttemptAtEpoch: Long = 0L,
    val lastError: String = ""
)

@Entity(tableName = "sync_history", indices = [Index(value = ["createdAtEpoch"])])
data class SyncHistoryEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val action: String,
    val success: Int,
    val detail: String,
    val itemsTotal: Int = 0,
    val itemsOk: Int = 0,
    val createdAtEpoch: Long
)

data class ReservationWithItems(
    val reservation: ReservationEntity,
    val items: List<ReservationItemEntity>
)

object QueueOp {
    const val CREATE_RESERVATION = "create_reservation"
    const val UPDATE_RESERVATION = "update_reservation"
    const val COMPLETE_RESERVATION = "complete_reservation"
    const val CANCEL_RESERVATION = "cancel_reservation"
    const val UPDATE_STATUS = "update_status"
    const val CREATE_CLIENT = "create_client"
}
