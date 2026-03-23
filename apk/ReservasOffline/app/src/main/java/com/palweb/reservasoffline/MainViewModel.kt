package com.palweb.reservasoffline

import android.app.Application
import android.os.Build
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.util.Calendar
import java.util.Locale
import java.util.concurrent.TimeUnit

class MainViewModel(app: Application) : AndroidViewModel(app) {
    private val cfg = AppConfig(app)
    private val db = AppDatabase.get(app, cfg.sucursalId)
    private val repo = ReservasRepository(db, OfflineApi(cfg), cfg)

    val baseUrl = MutableStateFlow(cfg.baseUrl)
    val apiKey = MutableStateFlow(cfg.apiKey)
    val otaJsonUrl = MutableStateFlow(cfg.otaJsonUrl)
    val sucursalId = MutableStateFlow(cfg.sucursalId)
    val searchText = MutableStateFlow("")
    val estadoFilter = MutableStateFlow("PENDIENTE")
    val fechaFilter = MutableStateFlow("TODAS")
    val tab = MutableStateFlow("LISTA")
    val productSearch = MutableStateFlow("")
    val clientSearch = MutableStateFlow("")
    val syncHistoryFilter = MutableStateFlow("TODOS")
    val statusMsg = MutableStateFlow("Listo")
    val online = MutableStateFlow(false)
    val isBootstrapping = MutableStateFlow(false)
    val isSyncingQueue = MutableStateFlow(false)
    val isInstallingOta = MutableStateFlow(false)
    val otaProgressText = MutableStateFlow("")
    val silenceNonReservationNotifications = MutableStateFlow(cfg.silenceNonReservationNotifications)
    val toastMessage = MutableStateFlow<String?>(null)
    val otaEvent = MutableStateFlow<OtaInfo?>(null)
    val diagnosticReport = MutableStateFlow<String?>(null)
    val appVersionLabel = "v${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"

    val reservations = repo.observeReservations().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())
    val queueCount = repo.observePendingQueueCount().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)
    val pendingReservationsToUpload = repo.observePendingReservationsUploadCount().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)
    val localProductsCount = repo.observeLocalProductsCount().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)
    val syncHistory = repo.observeSyncHistory().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())
    val filteredSyncHistory = combine(syncHistory, syncHistoryFilter) { list, filter ->
        when (filter) {
            "OK" -> list.filter { it.success == 1 }
            "ERROR" -> list.filter { it.success == 0 }
            else -> list
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())
    val products = combine(productSearch, repo.observeProducts("")) { q, list ->
        if (q.isBlank()) list.take(50) else list.filter { it.name.contains(q, true) || it.code.contains(q, true) }.take(50)
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())
    val clients = combine(clientSearch, repo.observeClients("")) { q, list ->
        if (q.isBlank()) list.take(20) else list.filter { it.name.contains(q, true) || it.phone.contains(q, true) }.take(20)
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val filteredReservations: StateFlow<List<ReservationEntity>> = combine(
        reservations, searchText, estadoFilter, fechaFilter
    ) { all, q, estado, fecha ->
        val now = System.currentTimeMillis()
        val sevenDays = now + (7L * 24L * 60L * 60L * 1000L)
        all.filter { r ->
            val matchQ = q.isBlank() || r.clientName.contains(q, true) || r.clientPhone.contains(q, true)
            val matchEstado = when (estado) {
                "TODOS" -> true
                else -> r.estadoReserva.equals(estado, true)
            }
            val matchFecha = when (fecha) {
                "HOY" -> isSameDay(now, r.fechaReservaEpoch)
                "SEMANA" -> r.fechaReservaEpoch in now..sevenDays
                "VENCIDAS" -> r.fechaReservaEpoch < now && r.estadoReserva.equals("PENDIENTE", true)
                else -> true
            }
            matchQ && matchEstado && matchFecha
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    init {
        observeConnectivity(app)
        scheduleAutoSync(app)
        startPlatformNotificationPolling()
    }

    fun saveSettings() {
        cfg.baseUrl = baseUrl.value
        cfg.apiKey = apiKey.value
        cfg.otaJsonUrl = otaJsonUrl.value
        cfg.sucursalId = sucursalId.value
        cfg.silenceNonReservationNotifications = silenceNonReservationNotifications.value
        statusMsg.value = "Configuracion guardada"
        refreshPlatformNotifications()
    }

    private fun activeBaseUrl(): String = baseUrl.value.trim().trimEnd('/').ifBlank { AppConfig.DEFAULT_BASE_URL }

    private fun apiEndpoint(action: String, extraQuery: String = ""): String {
        val base = activeBaseUrl()
        val tail = if (extraQuery.isBlank()) "" else "&$extraQuery"
        return "$base${BuildConfig.DEFAULT_API_PATH}?action=$action&sucursal_id=${sucursalId.value}$tail"
    }

    private fun activeOtaUrl(): String = otaJsonUrl.value.trim().ifBlank { "${activeBaseUrl()}${BuildConfig.DEFAULT_OTA_JSON_PATH}" }

    private fun debugError(action: String, endpoint: String, e: Exception): String {
        val root = generateSequence(e as Throwable?) { it.cause }.lastOrNull()
        val rootMsg = root?.message?.takeIf { it.isNotBlank() && it != e.message }
        val apiInfo = if (apiKey.value.isBlank()) "API key=no" else "API key=si"
        return buildString {
            append(action)
            append(" fallo")
            append(" | endpoint=")
            append(endpoint)
            append(" | sucursal=")
            append(sucursalId.value)
            append(" | ")
            append(apiInfo)
            append(" | error=")
            append(e.message ?: e::class.java.simpleName)
            if (!rootMsg.isNullOrBlank()) {
                append(" | causa=")
                append(rootMsg)
            }
        }
    }

    fun runBootstrap() = viewModelScope.launch {
        try {
            isBootstrapping.value = true
            statusMsg.value = "Sincronizando catalogo..."
            saveSettings()
            withContext(Dispatchers.IO) {
                repo.bootstrapSync()
            }
            statusMsg.value = "Descarga inicial completada"
        } catch (e: Exception) {
            val msg = debugError("Bootstrap", apiEndpoint("bootstrap"), e)
            statusMsg.value = msg
            toastMessage.value = msg
        } finally {
            isBootstrapping.value = false
        }
    }

    fun runDownloadProducts() = viewModelScope.launch {
        try {
            isBootstrapping.value = true
            saveSettings()
            val count = withContext(Dispatchers.IO) {
                repo.downloadProductsOnly()
            }
            statusMsg.value = "Catalogo actualizado"
            toastMessage.value = "Se descargaron $count productos a la base local"
        } catch (e: Exception) {
            val msg = debugError("Descargar productos", apiEndpoint("download_products", "updated_after=${cfg.lastProductsSyncEpoch / 1000}"), e)
            statusMsg.value = msg
            toastMessage.value = msg
        } finally {
            isBootstrapping.value = false
        }
    }

    fun runDownloadReservations() = viewModelScope.launch {
        try {
            isBootstrapping.value = true
            saveSettings()
            val count = withContext(Dispatchers.IO) {
                repo.downloadReservationsOnly()
            }
            statusMsg.value = "Reservaciones descargadas"
            toastMessage.value = "Se descargaron $count reservaciones del servidor"
        } catch (e: Exception) {
            val msg = debugError("Descargar reservaciones", apiEndpoint("download_reservations", "updated_after=${cfg.lastReservationsSyncEpoch / 1000}"), e)
            statusMsg.value = msg
            toastMessage.value = msg
        } finally {
            isBootstrapping.value = false
        }
    }

    fun runDownloadClients() = viewModelScope.launch {
        try {
            isBootstrapping.value = true
            saveSettings()
            val count = withContext(Dispatchers.IO) {
                repo.downloadClientsOnly()
            }
            statusMsg.value = "Clientes descargados"
            toastMessage.value = "Se descargaron $count clientes a la base local"
        } catch (e: Exception) {
            val msg = debugError("Descargar clientes", apiEndpoint("download_clients", "updated_after=${cfg.lastClientsSyncEpoch / 1000}"), e)
            statusMsg.value = msg
            toastMessage.value = msg
        } finally {
            isBootstrapping.value = false
        }
    }

    fun runQueueSync() = viewModelScope.launch {
        try {
            isSyncingQueue.value = true
            statusMsg.value = "Subiendo pendientes..."
            saveSettings()
            val (ok, total) = withContext(Dispatchers.IO) {
                repo.syncQueue()
            }
            statusMsg.value = "Sincronizadas $ok/$total operaciones"
            toastMessage.value = "Subidas $ok de $total operaciones pendientes"
            if (online.value) {
                withContext(Dispatchers.IO) {
                    repo.downloadProductsOnly()
                    repo.downloadClientsOnly()
                    repo.downloadReservationsOnly()
                    refreshPlatformNotificationsNow()
                }
            }
        } catch (e: Exception) {
            val msg = debugError("Sincronizar cola", apiEndpoint("sync"), e)
            statusMsg.value = msg
            toastMessage.value = msg
        } finally {
            isSyncingQueue.value = false
        }
    }

    fun consumeToast() {
        toastMessage.value = null
    }

    fun refreshPlatformNotifications() = viewModelScope.launch(Dispatchers.IO) {
        refreshPlatformNotificationsNow()
    }

    fun checkOtaUpdate() = viewModelScope.launch {
        try {
            saveSettings()
            val otaUrl = activeOtaUrl()
            val info = withContext(Dispatchers.IO) {
                OfflineApi(cfg).checkOtaUpdate(otaUrl)
            }
            if (info.versionCode > BuildConfig.VERSION_CODE) {
                toastMessage.value = "Nueva version ${info.versionName} disponible"
                otaEvent.value = info
            } else {
                toastMessage.value = "Ya tienes la ultima version instalada"
            }
        } catch (e: Exception) {
            val msg = debugError("Buscar OTA", activeOtaUrl(), e)
            statusMsg.value = msg
            toastMessage.value = msg
        }
    }

    fun consumeOtaEvent() {
        otaEvent.value = null
    }

    fun runDiagnostics() = viewModelScope.launch {
        try {
            saveSettings()
            val api = OfflineApi(cfg)
            val now = System.currentTimeMillis()
            val sb = StringBuilder()
            sb.appendLine("Diagnostico Reservas Offline")
            sb.appendLine("Fecha: ${epochToText(now)}")
            sb.appendLine("Version app: ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
            sb.appendLine("Base URL: ${cfg.baseUrl}")
            sb.appendLine("Sucursal: ${cfg.sucursalId}")
            sb.appendLine("Internet: ${if (online.value) "SI" else "NO"}")
            sb.appendLine("Productos locales: ${localProductsCount.value}")
            sb.appendLine("Reservas por subir: ${pendingReservationsToUpload.value}")
            sb.appendLine("Ops cola: ${queueCount.value}")

            val changes = runCatching {
                withContext(Dispatchers.IO) {
                    api.changesSince()
                }
            }.getOrNull()
            if (changes != null && changes.optString("status") == "success") {
                sb.appendLine("API changes_since: OK")
                sb.appendLine("Cambios remotos -> reservas:${changes.optInt("reservations_changed", 0)}, productos:${changes.optInt("products_changed", 0)}, clientes:${changes.optInt("clients_changed", 0)}")
            } else {
                sb.appendLine("API changes_since: ERROR")
            }

            val otaUrl = cfg.otaJsonUrl.ifBlank { cfg.endpoint(BuildConfig.DEFAULT_OTA_JSON_PATH) }
            val ota = runCatching {
                withContext(Dispatchers.IO) {
                    api.checkOtaUpdate(otaUrl)
                }
            }.getOrNull()
            if (ota != null) {
                sb.appendLine("OTA endpoint: OK")
                sb.appendLine("OTA version: ${ota.versionName} (${ota.versionCode})")
                sb.appendLine("OTA hash: ${if (ota.apkSha256.isNotBlank()) "SI" else "NO"}")
            } else {
                sb.appendLine("OTA endpoint: ERROR")
            }

            val canInstall = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                getApplication<Application>().packageManager.canRequestPackageInstalls()
            } else true
            sb.appendLine("Permiso instalar APK: ${if (canInstall) "SI" else "NO"}")

            diagnosticReport.value = sb.toString()
            toastMessage.value = "Diagnostico completado"
        } catch (e: Exception) {
            diagnosticReport.value = "Error ejecutando diagnostico: ${e.message}"
            toastMessage.value = "Diagnostico con error"
        }
    }

    fun consumeDiagnosticReport() {
        diagnosticReport.value = null
    }

    fun installOtaUpdate(info: OtaInfo) = viewModelScope.launch {
        try {
            isInstallingOta.value = true
            otaProgressText.value = "Descargando OTA..."
            statusMsg.value = "Descargando OTA..."
            SyncNotifier.notifyOtaProgress(getApplication(), "Descargando actualización", "Preparando descarga OTA...", null, indeterminate = true)
            val path = withContext(Dispatchers.IO) {
                OtaInstaller.downloadAndInstall(
                    getApplication(),
                    info.apkUrl,
                    info.apkSha256,
                    onProgress = { downloadedBytes, totalBytes ->
                        val progressText = if (totalBytes > 0) {
                            val pct = ((downloadedBytes * 100) / totalBytes).toInt().coerceIn(0, 100)
                            "Descargando OTA... $pct%"
                        } else {
                            "Descargando OTA... ${downloadedBytes / 1024} KB"
                        }
                        otaProgressText.value = progressText
                        statusMsg.value = progressText
                        val progressValue = if (totalBytes > 0) {
                            ((downloadedBytes * 100) / totalBytes).toInt().coerceIn(0, 100)
                        } else null
                        SyncNotifier.notifyOtaProgress(
                            getApplication(),
                            "Descargando actualización ${info.versionName}",
                            progressText,
                            progressValue,
                            indeterminate = totalBytes <= 0
                        )
                    }
                )
            }
            statusMsg.value = "OTA descargada"
            toastMessage.value = "OTA descargada: $path"
            SyncNotifier.notifyOtaResult(
                getApplication(),
                true,
                "Actualización descargada",
                "Versión ${info.versionName} lista para instalar."
            )
        } catch (e: Exception) {
            val msg = debugError("Instalar OTA", activeOtaUrl(), e)
            statusMsg.value = msg
            toastMessage.value = msg
            SyncNotifier.notifyOtaResult(
                getApplication(),
                false,
                "Error actualizando",
                e.message ?: "No se pudo descargar o verificar la actualización OTA."
            )
        } finally {
            isInstallingOta.value = false
            otaProgressText.value = ""
            otaEvent.value = null
        }
    }

    fun exportSyncHistoryCsv() = viewModelScope.launch {
        try {
            val rows = filteredSyncHistory.value
            val csv = buildString {
                append("fecha,accion,success,items_total,items_ok,detalle\n")
                rows.forEach { h ->
                    val detail = h.detail.replace("\"", "\"\"").replace("\n", " ")
                    append("${epochToText(h.createdAtEpoch)},${h.action},${h.success},${h.itemsTotal},${h.itemsOk},\"$detail\"\n")
                }
            }
            val ts = java.text.SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(java.util.Date())
            val dir = getApplication<Application>().getExternalFilesDir(null) ?: getApplication<Application>().filesDir
            val file = File(dir, "sync_history_$ts.csv")
            file.writeText(csv)
            toastMessage.value = "CSV exportado: ${file.absolutePath}"
        } catch (e: Exception) {
            val msg = "Exportar CSV fallo | dir=${getApplication<Application>().getExternalFilesDir(null)?.absolutePath ?: getApplication<Application>().filesDir.absolutePath} | error=${e.message ?: e::class.java.simpleName}"
            statusMsg.value = msg
            toastMessage.value = msg
        }
    }

    fun saveReservation(input: ReservationFormInput) = viewModelScope.launch {
        try {
            repo.saveReservation(
                localUuid = input.localUuid,
                remoteId = input.remoteId,
                clientName = input.clientName,
                clientPhone = input.clientPhone,
                clientAddress = input.clientAddress,
                clientRemoteId = input.clientRemoteId,
                fechaReservaText = input.fechaReservaText,
                notes = input.notes,
                metodoPago = input.metodoPago,
                canalOrigen = input.canalOrigen,
                abono = input.abono,
                estadoPago = input.estadoPago,
                estadoReserva = input.estadoReserva,
                costoMensajeria = input.costoMensajeria,
                items = input.items,
            )
            statusMsg.value = "Reserva guardada en modo offline"
        } catch (e: Exception) {
            val msg = "Guardar reserva fallo | cliente=${input.clientName.ifBlank { "Sin nombre" }} | items=${input.items.size} | error=${e.message ?: e::class.java.simpleName}"
            statusMsg.value = msg
            toastMessage.value = msg
        }
    }

    fun markComplete(uuid: String) = viewModelScope.launch {
        repo.queueStatusChange(uuid, QueueOp.COMPLETE_RESERVATION)
    }

    fun markCancel(uuid: String) = viewModelScope.launch {
        repo.queueStatusChange(uuid, QueueOp.CANCEL_RESERVATION)
    }

    fun updateStatus(uuid: String, estado: String, nota: String) = viewModelScope.launch {
        repo.queueStatusChange(uuid, QueueOp.UPDATE_STATUS, estado, nota)
    }

    suspend fun loadItems(uuid: String): List<ReservationItemEntity> = repo.reservationItems(uuid)
    suspend fun loadReservation(uuid: String): ReservationEntity? = repo.reservationByUuid(uuid)
    suspend fun loadServerReservation(remoteId: Long): ReservationWithItems? = withContext(Dispatchers.IO) {
        repo.fetchServerReservation(remoteId)
    }

    private fun observeConnectivity(app: Application) {
        val cm = app.getSystemService(ConnectivityManager::class.java)
        val req = NetworkRequest.Builder().addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET).build()
        cm.registerNetworkCallback(req, object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                online.value = true
                statusMsg.value = "Internet disponible: pulsa Subir pendientes"
                viewModelScope.launch(Dispatchers.IO) { refreshPlatformNotificationsNow() }
                WorkManager.getInstance(app).enqueue(
                    OneTimeWorkRequestBuilder<AutoSyncWorker>()
                        .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                        .build()
                )
            }

            override fun onLost(network: Network) {
                online.value = false
                statusMsg.value = "Sin internet: trabajando offline"
            }
        })
        online.value = cm.activeNetwork?.let { n ->
            cm.getNetworkCapabilities(n)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } ?: false
    }

    private fun scheduleAutoSync(app: Application) {
        val req = PeriodicWorkRequestBuilder<AutoSyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(app).enqueueUniquePeriodicWork(
            "reservas_auto_sync",
            ExistingPeriodicWorkPolicy.UPDATE,
            req
        )
    }

    private fun startPlatformNotificationPolling() {
        viewModelScope.launch(Dispatchers.IO) {
            while (isActive) {
                if (online.value) {
                    runCatching { refreshPlatformNotificationsNow() }
                }
                delay(45_000)
            }
        }
    }

    private fun refreshPlatformNotificationsNow() {
        val rows = OfflineApi(cfg).fetchPlatformNotifications(cfg.lastPlatformNotificationId)
        if (rows.isEmpty()) return
        rows.forEach { n ->
            if (cfg.silenceNonReservationNotifications && !n.eventKey.startsWith("reservation_")) return@forEach
            SyncNotifier.notify(
                getApplication(),
                300_000 + n.id.toInt(),
                n.title.ifBlank { "Nuevo evento" },
                n.body.ifBlank { n.type.ifBlank { "Hay una novedad en la plataforma." } }
            )
        }
        cfg.lastPlatformNotificationId = maxOf(cfg.lastPlatformNotificationId, rows.maxOf { it.id })
    }

    private fun isSameDay(a: Long, b: Long): Boolean {
        val c1 = Calendar.getInstance().apply { timeInMillis = a }
        val c2 = Calendar.getInstance().apply { timeInMillis = b }
        return c1.get(Calendar.YEAR) == c2.get(Calendar.YEAR) && c1.get(Calendar.DAY_OF_YEAR) == c2.get(Calendar.DAY_OF_YEAR)
    }
}

data class ReservationFormInput(
    val localUuid: String? = null,
    val remoteId: Long? = null,
    val clientName: String,
    val clientPhone: String,
    val clientAddress: String,
    val clientRemoteId: Long? = null,
    val fechaReservaText: String,
    val notes: String,
    val metodoPago: String,
    val canalOrigen: String,
    val estadoPago: String,
    val estadoReserva: String,
    val abono: Double,
    val costoMensajeria: Double,
    val items: List<ReservationItemEntity>,
)
