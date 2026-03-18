package com.palweb.reservasoffline

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

class AutoSyncWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        return try {
            val cfg = AppConfig(applicationContext)
            val db = AppDatabase.get(applicationContext, cfg.sucursalId)
            val api = OfflineApi(cfg)
            val repo = ReservasRepository(db, api, cfg)
            val lastReservationsSyncBeforePull = cfg.lastReservationsSyncEpoch / 1000

            repo.syncQueue()
            repo.downloadProductsOnly()
            repo.downloadClientsOnly()
            val downloadedReservations = repo.downloadReservationsOnly()

            val changes = api.changesSince(lastReservationsSyncBeforePull)
            val reservationsChanged = changes.optInt("reservations_changed", 0)
            val productsChanged = changes.optInt("products_changed", 0)
            val clientsChanged = changes.optInt("clients_changed", 0)
            val changedCount = reservationsChanged + productsChanged + clientsChanged
            if (changedCount > 0 && downloadedReservations == 0) {
                val parts = buildList {
                    if (reservationsChanged > 0) add("$reservationsChanged reservas")
                    if (productsChanged > 0) add("$productsChanged productos")
                    if (clientsChanged > 0) add("$clientsChanged clientes")
                }
                SyncNotifier.notify(
                    applicationContext,
                    2001,
                    "Cambios remotos disponibles",
                    "Pendientes: ${parts.joinToString(", ")}."
                )
            }

            val otaUrl = cfg.otaJsonUrl.ifBlank { cfg.endpoint(BuildConfig.DEFAULT_OTA_JSON_PATH) }
            val ota = api.checkOtaUpdate(otaUrl)
            if (ota.versionCode > BuildConfig.VERSION_CODE) {
                SyncNotifier.notify(
                    applicationContext,
                    2002,
                    "Nueva version disponible",
                    "Version ${ota.versionName} lista para actualizar."
                )
            }

            val platformNotifications = api.fetchPlatformNotifications(cfg.lastPlatformNotificationId)
            if (platformNotifications.isNotEmpty()) {
                platformNotifications.forEach { n ->
                    if (cfg.silenceNonReservationNotifications && !n.eventKey.startsWith("reservation_")) {
                        return@forEach
                    }
                    SyncNotifier.notify(
                        applicationContext,
                        300_000 + n.id.toInt(),
                        n.title.ifBlank { "Nuevo evento" },
                        n.body.ifBlank { n.type.ifBlank { "Hay una novedad en la plataforma." } }
                    )
                }
                cfg.lastPlatformNotificationId = maxOf(
                    cfg.lastPlatformNotificationId,
                    platformNotifications.maxOf { it.id }
                )
            }

            db.syncHistoryDao().insert(
                SyncHistoryEntity(
                    action = "auto_sync",
                    success = 1,
                    detail = "Auto sync ejecutado",
                    createdAtEpoch = System.currentTimeMillis(),
                )
            )
            Result.success()
        } catch (e: Exception) {
            Result.retry()
        }
    }
}
