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

            repo.syncQueue()
            repo.downloadProductsOnly()
            repo.downloadClientsOnly()
            repo.downloadReservationsOnly()

            val changes = api.changesSince(cfg.lastReservationsSyncEpoch / 1000)
            val changedCount = changes.optInt("reservations_changed", 0) +
                changes.optInt("products_changed", 0) +
                changes.optInt("clients_changed", 0)
            if (changedCount > 0) {
                SyncNotifier.notify(
                    applicationContext,
                    2001,
                    "Cambios remotos disponibles",
                    "Hay $changedCount cambios nuevos en el servidor."
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
