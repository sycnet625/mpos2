package com.palweb.reservasoffline

import kotlinx.coroutines.flow.Flow
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

private val DATE_TIME_FMT = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.US)

fun epochToText(epoch: Long): String = DATE_TIME_FMT.format(Date(epoch))
fun textToEpoch(text: String): Long = runCatching { DATE_TIME_FMT.parse(text)?.time }.getOrNull() ?: System.currentTimeMillis()

class ReservasRepository(
    private val db: AppDatabase,
    private val api: OfflineApi,
    private val cfg: AppConfig,
) {
    fun observeReservations(): Flow<List<ReservationEntity>> = db.reservationDao().observeAll()
    fun observePendingQueueCount(): Flow<Int> = db.queueDao().countFlow()
    fun observePendingReservationsUploadCount(): Flow<Int> = db.reservationDao().countNeedsSyncFlow()
    fun observeLocalProductsCount(): Flow<Int> = db.productDao().countFlow()
    fun observeSyncHistory(): Flow<List<SyncHistoryEntity>> = db.syncHistoryDao().latest(30)
    fun observeProducts(q: String): Flow<List<ProductEntity>> = db.productDao().search(q)
    fun observeClients(q: String): Flow<List<ClientEntity>> = db.clientDao().search(q)

    suspend fun downloadProductsOnly(): Int {
        val products = api.downloadProductsOnly()
        db.productDao().upsertAll(products)
        return products.size
    }

    suspend fun downloadReservationsOnly(): Int {
        val reservations = api.downloadReservationsOnly()
        reservations.forEach { row ->
            db.reservationDao().upsert(row.reservation.copy(needsSync = 0))
            db.reservationDao().clearItems(row.reservation.localUuid)
            if (row.items.isNotEmpty()) db.reservationDao().insertItems(row.items)
        }
        return reservations.size
    }

    suspend fun downloadClientsOnly(): Int {
        val clients = api.downloadClientsOnly()
        db.clientDao().upsertAll(clients)
        return clients.size
    }

    suspend fun bootstrapSync() {
        val data = api.bootstrap()
        db.productDao().clear()
        db.productDao().upsertAll(data.products)

        db.clientDao().clear()
        db.clientDao().upsertAll(data.clients)

        db.reservationDao().clearAllItems()
        db.reservationDao().clear()
        db.reservationDao().upsertAll(data.reservations.map { it.reservation })
        data.reservations.forEach { row ->
            if (row.items.isNotEmpty()) db.reservationDao().insertItems(row.items)
        }
        cfg.lastBootstrapEpoch = System.currentTimeMillis()
    }

    suspend fun saveReservation(
        localUuid: String?,
        remoteId: Long?,
        clientName: String,
        clientPhone: String,
        clientAddress: String,
        clientRemoteId: Long?,
        fechaReservaText: String,
        notes: String,
        metodoPago: String,
        canalOrigen: String,
        abono: Double,
        estadoPago: String,
        estadoReserva: String,
        costoMensajeria: Double,
        items: List<ReservationItemEntity>,
    ): String {
        val uuid = localUuid ?: UUID.randomUUID().toString()
        val subtotal = items.sumOf { it.qty * it.price }
        val total = subtotal + costoMensajeria
        val sinExistencia = if (items.any { it.esServicio == 0 && it.stockSnapshot < it.qty }) 1 else 0
        val now = System.currentTimeMillis()

        val reservation = ReservationEntity(
            localUuid = uuid,
            remoteId = remoteId,
            clientName = clientName.ifBlank { "Sin nombre" },
            clientPhone = clientPhone,
            clientAddress = clientAddress,
            clientRemoteId = clientRemoteId,
            fechaReservaEpoch = textToEpoch(fechaReservaText),
            notes = notes,
            metodoPago = metodoPago,
            canalOrigen = canalOrigen,
            estadoPago = estadoPago,
            estadoReserva = estadoReserva,
            abono = abono,
            total = total,
            costoMensajeria = costoMensajeria,
            sinExistencia = sinExistencia,
            updatedAtEpoch = now,
            syncAttempts = 0,
            syncError = "",
            needsSync = 1,
        )
        db.reservationDao().upsert(reservation)
        db.reservationDao().clearItems(uuid)
        db.reservationDao().insertItems(items.map { it.copy(id = 0, reservationUuid = uuid) })

        val payload = reservationToPayload(reservation, items)
        val type = if (remoteId == null) QueueOp.CREATE_RESERVATION else QueueOp.UPDATE_RESERVATION
        db.queueDao().insert(
            SyncQueueEntity(
                opType = type,
                reservationUuid = uuid,
                payloadJson = payload.toString(),
                createdAtEpoch = now,
            )
        )
        return uuid
    }

    suspend fun queueStatusChange(localUuid: String, opType: String, estado: String? = null, nota: String? = null) {
        val reservation = db.reservationDao().byLocalUuid(localUuid) ?: return
        val updated = when (opType) {
            QueueOp.COMPLETE_RESERVATION -> reservation.copy(estadoReserva = "ENTREGADO", needsSync = 1, updatedAtEpoch = System.currentTimeMillis())
            QueueOp.CANCEL_RESERVATION -> reservation.copy(estadoReserva = "CANCELADO", needsSync = 1, updatedAtEpoch = System.currentTimeMillis())
            QueueOp.UPDATE_STATUS -> reservation.copy(
                estadoReserva = estado ?: reservation.estadoReserva,
                notes = nota ?: reservation.notes,
                needsSync = 1,
                updatedAtEpoch = System.currentTimeMillis()
            )
            else -> reservation
        }
        db.reservationDao().upsert(updated)

        val payload = JSONObject()
            .put("local_uuid", localUuid)
            .put("remote_id", reservation.remoteId)
            .put("client_updated_at_epoch", reservation.updatedAtEpoch / 1000)
            .put("estado", estado ?: updated.estadoReserva)
            .put("nota", nota ?: updated.notes)
        db.queueDao().insert(
            SyncQueueEntity(
                opType = opType,
                reservationUuid = localUuid,
                payloadJson = payload.toString(),
                createdAtEpoch = System.currentTimeMillis(),
            )
        )
    }

    suspend fun createClientOffline(name: String, phone: String, address: String, category: String): Long {
        val localId = db.clientDao().insert(
            ClientEntity(name = name, phone = phone, address = address, category = category)
        )
        val payload = JSONObject()
            .put("local_client_id", localId)
            .put("nombre", name)
            .put("telefono", phone)
            .put("direccion", address)
            .put("categoria", category)
        db.queueDao().insert(
            SyncQueueEntity(
                opType = QueueOp.CREATE_CLIENT,
                reservationUuid = null,
                payloadJson = payload.toString(),
                createdAtEpoch = System.currentTimeMillis(),
            )
        )
        return localId
    }

    suspend fun syncQueue(): Pair<Int, Int> {
        val ops = db.queueDao().all()
        if (ops.isEmpty()) return 0 to 0
        var okCount = 0
        val nowEpoch = System.currentTimeMillis()
        for (queueOp in ops) {
            if (queueOp.nextAttemptAtEpoch > nowEpoch) continue
            val payload = JSONObject(queueOp.payloadJson)
            if (queueOp.reservationUuid != null && payload.optLong("remote_id", 0L) == 0L) {
                db.reservationDao().byLocalUuid(queueOp.reservationUuid)?.remoteId?.let {
                    payload.put("remote_id", it)
                }
            }

            val syncedOp = queueOp.copy(payloadJson = payload.toString())
            val result = api.syncOperations(listOf(syncedOp)).firstOrNull()
            if (result?.ok == true) {
                if (queueOp.opType == QueueOp.CREATE_RESERVATION && result.remoteReservationId != null) {
                    queueOp.reservationUuid?.let { localUuid ->
                        db.reservationDao().byLocalUuid(localUuid)?.let { reservation ->
                            db.reservationDao().upsert(
                                reservation.copy(
                                    remoteId = result.remoteReservationId,
                                    sinExistencia = result.sinExistencia ?: reservation.sinExistencia,
                                    syncAttempts = 0,
                                    syncError = "",
                                    needsSync = 0,
                                )
                            )
                        }
                    }
                } else if (
                    queueOp.opType == QueueOp.UPDATE_RESERVATION ||
                    queueOp.opType == QueueOp.COMPLETE_RESERVATION ||
                    queueOp.opType == QueueOp.CANCEL_RESERVATION ||
                    queueOp.opType == QueueOp.UPDATE_STATUS
                ) {
                    queueOp.reservationUuid?.let { uuid ->
                        db.reservationDao().byLocalUuid(uuid)?.let { r ->
                            db.reservationDao().upsert(r.copy(needsSync = 0, syncAttempts = 0, syncError = ""))
                        }
                    }
                } else if (queueOp.opType == QueueOp.CREATE_CLIENT && result.remoteClientId != null) {
                    val localClientId = payload.optLong("local_client_id")
                    db.clientDao().byId(localClientId)?.let { existing ->
                        db.clientDao().insert(existing.copy(remoteId = result.remoteClientId))
                    }
                }

                db.queueDao().delete(queueOp.id)
                okCount++
                db.syncHistoryDao().insert(
                    SyncHistoryEntity(
                        action = queueOp.opType,
                        success = 1,
                        detail = result.message,
                        itemsTotal = 1,
                        itemsOk = 1,
                        createdAtEpoch = System.currentTimeMillis(),
                    )
                )
            } else {
                val msg = result?.message ?: "Error desconocido"
                val nextAttempts = queueOp.attempts + 1
                val backoffSeconds = minOf(3600, 30 * (1 shl minOf(8, nextAttempts)))
                val nextAttemptAt = System.currentTimeMillis() + backoffSeconds * 1000L
                db.queueDao().scheduleRetry(queueOp.id, nextAttemptAt, msg)
                queueOp.reservationUuid?.let { uuid ->
                    db.reservationDao().byLocalUuid(uuid)?.let { r ->
                        db.reservationDao().upsert(
                            r.copy(syncAttempts = nextAttempts, syncError = msg, needsSync = 1)
                        )
                    }
                }
                db.syncHistoryDao().insert(
                    SyncHistoryEntity(
                        action = queueOp.opType,
                        success = 0,
                        detail = msg,
                        itemsTotal = 1,
                        itemsOk = 0,
                        createdAtEpoch = System.currentTimeMillis(),
                    )
                )
                val isRemoteMissing = result?.message?.contains("remote_id", ignoreCase = true) == true
                if (isRemoteMissing && queueOp.reservationUuid != null) {
                    // Aun no existe en servidor; dejar en cola y continuar con el resto.
                    continue
                }
                val isConflict = result?.message?.contains("CONFLICT", ignoreCase = true) == true
                if (isConflict && queueOp.reservationUuid != null) {
                    val serverTs = extractServerUpdatedAt(result?.message ?: "")
                    db.reservationDao().byLocalUuid(queueOp.reservationUuid)?.let { r ->
                        db.reservationDao().upsert(
                            r.copy(
                                needsSync = 0,
                                syncError = "Conflicto: se mantuvo version servidor",
                                serverUpdatedAtEpoch = serverTs,
                            )
                        )
                    }
                    db.queueDao().delete(queueOp.id)
                }
            }
        }
        return okCount to ops.size
    }

    suspend fun reservationItems(uuid: String): List<ReservationItemEntity> = db.reservationDao().itemsByUuid(uuid)
    suspend fun reservationByUuid(uuid: String): ReservationEntity? = db.reservationDao().byLocalUuid(uuid)
    suspend fun fetchServerReservation(remoteId: Long): ReservationWithItems? = api.reservationDetailParsed(remoteId)

    private fun reservationToPayload(res: ReservationEntity, items: List<ReservationItemEntity>): JSONObject {
        val arr = JSONArray()
        items.forEach {
            arr.put(
                JSONObject()
                    .put("codigo", it.productCode)
                    .put("nombre", it.productName)
                    .put("categoria", it.category)
                    .put("cantidad", it.qty)
                    .put("precio", it.price)
            )
        }
        return JSONObject()
            .put("local_uuid", res.localUuid)
            .put("remote_id", res.remoteId)
            .put("client_updated_at_epoch", res.updatedAtEpoch / 1000)
            .put("cliente_nombre", res.clientName)
            .put("cliente_telefono", res.clientPhone)
            .put("cliente_direccion", res.clientAddress)
            .put("id_cliente", res.clientRemoteId)
            .put("fecha_reserva", epochToText(res.fechaReservaEpoch) + ":00")
            .put("notas", res.notes)
            .put("metodo_pago", res.metodoPago)
            .put("canal_origen", res.canalOrigen)
            .put("estado_pago", res.estadoPago)
            .put("abono", res.abono)
            .put("costo_mensajeria", res.costoMensajeria)
            .put("items", arr)
    }

    private fun extractServerUpdatedAt(message: String): Long {
        val regex = Regex("server_updated_at=(\\d+)")
        return regex.find(message)?.groupValues?.getOrNull(1)?.toLongOrNull()?.times(1000) ?: 0L
    }
}
