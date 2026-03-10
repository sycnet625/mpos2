package com.example.salestracker

import android.Manifest
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.app.ActivityManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.location.Location
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.StatFs
import android.os.SystemClock
import android.provider.Settings
import android.net.Uri
import android.telephony.CellInfoCdma
import android.telephony.CellInfoGsm
import android.telephony.CellInfoLte
import android.telephony.CellInfoWcdma
import android.telephony.TelephonyManager
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeParseException
import java.util.Date
import java.util.Locale

class MainActivity : ComponentActivity() {
    private lateinit var dbHelper: LogDbHelper
    private lateinit var repository: LogRepository
    private var otaDownloadId: Long = -1L
    private var otaReceiverRegistered: Boolean = false

    private val otaDownloadReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action != DownloadManager.ACTION_DOWNLOAD_COMPLETE) return
            val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L)
            if (id == otaDownloadId) {
                promptInstallDownloadedApk()
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        dbHelper = LogDbHelper(this)
        repository = LogRepository(this, dbHelper)
        WaterSyncScheduler.schedule(this)
        registerOtaReceiver()

        setContent {
            MaterialTheme {
                AppRoot(
                    dbHelper = dbHelper,
                    repository = repository
                )
            }
        }
    }

    override fun onDestroy() {
        if (otaReceiverRegistered) {
            unregisterReceiver(otaDownloadReceiver)
            otaReceiverRegistered = false
        }
        super.onDestroy()
    }

    private fun registerOtaReceiver() {
        if (otaReceiverRegistered) return
        val filter = IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(otaDownloadReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("DEPRECATION")
            registerReceiver(otaDownloadReceiver, filter)
        }
        otaReceiverRegistered = true
    }

    private fun startOtaUpdate() {
        val otaUrl = if (loadOtaChannel() == "beta") OTA_BETA_APK_URL else OTA_APK_URL
        val request = DownloadManager.Request(Uri.parse(otaUrl))
            .setTitle("Water Controler OTA")
            .setDescription("Descargando ultima version...")
            .setAllowedOverMetered(true)
            .setAllowedOverRoaming(true)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, OTA_FILE_NAME)

        val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
        otaDownloadId = dm.enqueue(request)
        Toast.makeText(this, "Descarga OTA iniciada ($otaUrl)", Toast.LENGTH_SHORT).show()

        // Fallback robusto: no depender solo del broadcast, consultar estado de descarga.
        lifecycleScope.launch {
            pollAndInstallOta(dm, otaDownloadId)
        }
    }

    private fun promptInstallDownloadedApk() {
        val apkFile = java.io.File(
            getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS),
            OTA_FILE_NAME
        )
        if (!apkFile.exists()) {
            Toast.makeText(this, "APK OTA no encontrada", Toast.LENGTH_LONG).show()
            return
        }

        val otaUrl = if (loadOtaChannel() == "beta") OTA_BETA_APK_URL else OTA_APK_URL
        val expectedSha = runCatching { httpGet("$otaUrl.sha256").trim().split(" ").first().trim() }.getOrDefault("")
        if (expectedSha.isNotBlank()) {
            val localSha = sha256File(apkFile)
            if (!expectedSha.equals(localSha, ignoreCase = true)) {
                Toast.makeText(this, "Checksum OTA invalido", Toast.LENGTH_LONG).show()
                return
            }
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
            !packageManager.canRequestPackageInstalls()
        ) {
            val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES).apply {
                data = Uri.parse("package:$packageName")
            }
            startActivity(intent)
            Toast.makeText(this, "Habilita instalar apps desconocidas", Toast.LENGTH_LONG).show()
            return
        }

        val uri = FileProvider.getUriForFile(
            this,
            "$packageName.fileprovider",
            apkFile
        )
        val installIntent = Intent(Intent.ACTION_INSTALL_PACKAGE).apply {
            data = uri
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true)
            putExtra(Intent.EXTRA_RETURN_RESULT, true)
        }
        startActivity(installIntent)
    }

    private fun loadOtaChannel(): String {
        return getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .getString(KEY_OTA_CHANNEL, "stable")
            ?.trim()
            ?.lowercase(Locale.getDefault())
            ?.ifBlank { "stable" }
            ?: "stable"
    }

    private fun saveOtaChannel(channel: String) {
        getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .edit()
            .putString(KEY_OTA_CHANNEL, channel.trim().lowercase(Locale.getDefault()))
            .apply()
    }

    private fun sha256File(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { ins ->
            val buf = ByteArray(8192)
            while (true) {
                val n = ins.read(buf)
                if (n <= 0) break
                md.update(buf, 0, n)
            }
        }
        return md.digest().joinToString("") { b -> "%02x".format(b) }
    }

    private suspend fun pollAndInstallOta(dm: DownloadManager, downloadId: Long) {
        if (downloadId <= 0) return
        repeat(120) {
            delay(1000)
            val query = DownloadManager.Query().setFilterById(downloadId)
            dm.query(query)?.use { c ->
                if (!c.moveToFirst()) return@use
                val status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
                when (status) {
                    DownloadManager.STATUS_SUCCESSFUL -> {
                        promptInstallDownloadedApk()
                        return
                    }
                    DownloadManager.STATUS_FAILED -> {
                        val reason = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_REASON))
                        Toast.makeText(this, "Descarga OTA fallida: $reason", Toast.LENGTH_LONG).show()
                        return
                    }
                }
            }
        }
    }

    private fun startBackgroundSync() {
        BackgroundSyncService.start(this)
    }

    private fun stopBackgroundSync() {
        BackgroundSyncService.stop(this)
    }

    private fun ensureNotificationAccess() {
        val enabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners")
            ?.contains(packageName) == true
        if (!enabled) {
            Toast.makeText(
                this,
                getString(R.string.enable_notification_access),
                Toast.LENGTH_LONG
            ).show()
            startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
        }
    }

    private suspend fun fetchPumpStatus(baseUrl: String): PumpStatus = withContext(Dispatchers.IO) {
        val body = httpGet(apiUrl(baseUrl, "/status"))
        parsePumpStatusFromJson(body)
    }

    private suspend fun callSimpleApi(url: String): String = withContext(Dispatchers.IO) {
        httpGet(url)
    }

    private suspend fun runImmediateWaterSync(): WaterSyncRunResult = withContext(Dispatchers.IO) {
        WaterSyncEngine.syncOnce(this@MainActivity)
    }

    private fun httpGet(url: String): String {
        val connection = (URL(url).openConnection() as HttpURLConnection)
        connection.requestMethod = "GET"
        connection.connectTimeout = 7000
        connection.readTimeout = 7000

        val code = connection.responseCode
        val stream = if (code in 200..299) connection.inputStream else connection.errorStream
        val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
        connection.disconnect()

        if (code !in 200..299) {
            throw IllegalStateException("HTTP $code: $body")
        }
        return body
    }

    private fun loadApiBaseUrl(): String {
        return getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .getString(KEY_API_BASE_URL, DEFAULT_BASE_URL)
            ?.trim()
            ?.ifBlank { DEFAULT_BASE_URL }
            ?: DEFAULT_BASE_URL
    }

    private fun saveApiBaseUrl(value: String) {
        getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .edit()
            .putString(KEY_API_BASE_URL, normalizeBaseUrl(value))
            .apply()
    }

    private fun saveHiddenFilters(contact: String, fromDate: String, toDate: String) {
        getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .edit()
            .putString(KEY_FILTER_CONTACT, contact)
            .putString(KEY_FILTER_FROM, fromDate)
            .putString(KEY_FILTER_TO, toDate)
            .apply()
    }

    private fun loadHiddenFilters(): Triple<String, String, String> {
        val p = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
        return Triple(
            p.getString(KEY_FILTER_CONTACT, "").orEmpty(),
            p.getString(KEY_FILTER_FROM, "").orEmpty(),
            p.getString(KEY_FILTER_TO, "").orEmpty()
        )
    }

    private fun normalizeBaseUrl(value: String): String {
        return value.trim().trimEnd('/')
    }

    private fun apiUrl(baseUrl: String, path: String): String {
        val base = normalizeBaseUrl(baseUrl)
        val suffix = if (path.startsWith("/")) path else "/$path"
        return "$base$suffix"
    }

    private fun recordSyncEvent(eventType: String, data: JSONObject) {
        dbHelper.insertWaterSyncEvent(eventType, data.toString())
    }

    private suspend fun queueStatusSnapshotNow(baseUrl: String): Boolean = withContext(Dispatchers.IO) {
        runCatching {
            val snap = fetchPumpStatus(baseUrl)
            val mobileLocation = readLastKnownLocation()
            val payload = JSONObject().apply {
                put("nivel", snap.nivel)
                put("cm", snap.cm)
                put("bateria", snap.bateria)
                put("wifi", snap.wifi)
                put("modo", snap.modo)
                put("bomba", snap.bomba)
                put("ts", System.currentTimeMillis())
                put("movil_bateria_pct", readBatteryPct().coerceAtLeast(0))
                put("movil_gps_precision_m", mobileLocation?.accuracy?.toString() ?: "N/D")
                put("movil_radio_base_codigo", readCurrentCellCode())
                put("movil_radio_rssi_dbm", readCellRssiDbm())
                put("movil_tipo_red", readNetworkKind())
                put("movil_operador", readOperatorInfo())
                put("app_version", BuildConfig.VERSION_NAME)
                put("build_code", BuildConfig.VERSION_CODE)
                put("cola_sync_pendiente", dbHelper.countUnsyncedWaterEvents())
            }
            recordSyncEvent("status_snapshot", payload)
            true
        }.getOrDefault(false)
    }

    private fun readBatteryPct(): Int {
        val intent = registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
        if (intent == null) return -1
        val level = intent.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
        val scale = intent.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
        if (level < 0 || scale <= 0) return -1
        return ((level * 100f) / scale).toInt()
    }

    private fun readLastKnownLocation(): Location? {
        val fineGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        val coarseGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (!fineGranted && !coarseGranted) return null

        val lm = getSystemService(LOCATION_SERVICE) as LocationManager
        val providers = listOf(
            LocationManager.GPS_PROVIDER,
            LocationManager.NETWORK_PROVIDER,
            LocationManager.PASSIVE_PROVIDER
        )
        return providers
            .mapNotNull { provider -> runCatching { lm.getLastKnownLocation(provider) }.getOrNull() }
            .maxByOrNull { it.time }
    }

    private fun captureAndStoreClientTelemetry(): ClientTelemetryEntry {
        val battery = readBatteryPct().coerceAtLeast(0)
        val loc = readLastKnownLocation()
        val entry = ClientTelemetryEntry(
            batteryPct = battery,
            latitude = loc?.latitude,
            longitude = loc?.longitude,
            accuracyM = loc?.accuracy,
            timestamp = System.currentTimeMillis()
        )
        dbHelper.insertClientTelemetry(entry)
        return entry
    }

    private fun readCurrentCellCode(): String {
        return runCatching {
            val tm = getSystemService(TELEPHONY_SERVICE) as TelephonyManager
            val all = tm.allCellInfo ?: emptyList()
            val serving = all.firstOrNull { it.isRegistered } ?: all.firstOrNull()
            when (serving) {
                is CellInfoLte -> {
                    val c = serving.cellIdentity
                    "LTE:${c.tac}-${c.ci}"
                }
                is CellInfoGsm -> {
                    val c = serving.cellIdentity
                    "GSM:${c.lac}-${c.cid}"
                }
                is CellInfoWcdma -> {
                    val c = serving.cellIdentity
                    "WCDMA:${c.lac}-${c.cid}"
                }
                is CellInfoCdma -> {
                    val c = serving.cellIdentity
                    "CDMA:${c.networkId}-${c.basestationId}"
                }
                else -> "N/D"
            }
        }.getOrDefault("N/D")
    }

    private fun readCellRssiDbm(): String {
        return runCatching {
            val tm = getSystemService(TELEPHONY_SERVICE) as TelephonyManager
            val all = tm.allCellInfo ?: emptyList()
            val serving = all.firstOrNull { it.isRegistered } ?: all.firstOrNull()
            val dbm = when (serving) {
                is CellInfoLte -> serving.cellSignalStrength.dbm
                is CellInfoGsm -> serving.cellSignalStrength.dbm
                is CellInfoWcdma -> serving.cellSignalStrength.dbm
                is CellInfoCdma -> serving.cellSignalStrength.dbm
                else -> Int.MIN_VALUE
            }
            if (dbm == Int.MIN_VALUE) "N/D" else dbm.toString()
        }.getOrDefault("N/D")
    }

    private fun isChargingNow(): Boolean {
        val intent = registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED)) ?: return false
        val status = intent.getIntExtra(BatteryManager.EXTRA_STATUS, -1)
        return status == BatteryManager.BATTERY_STATUS_CHARGING || status == BatteryManager.BATTERY_STATUS_FULL
    }

    private fun readBatteryTempC(): String {
        val intent = registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED)) ?: return "N/D"
        val raw = intent.getIntExtra(BatteryManager.EXTRA_TEMPERATURE, -1)
        if (raw < 0) return "N/D"
        return String.format(Locale.US, "%.1f", raw / 10f)
    }

    private fun readNetworkKind(): String {
        return runCatching {
            val cm = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
            val active = cm.activeNetwork ?: return@runCatching "OFFLINE"
            val caps = cm.getNetworkCapabilities(active) ?: return@runCatching "OFFLINE"
            when {
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "WIFI"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ETHERNET"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> {
                    val tm = getSystemService(TELEPHONY_SERVICE) as TelephonyManager
                    when (tm.dataNetworkType) {
                        TelephonyManager.NETWORK_TYPE_LTE -> "LTE"
                        TelephonyManager.NETWORK_TYPE_NR -> "5G"
                        TelephonyManager.NETWORK_TYPE_HSPAP,
                        TelephonyManager.NETWORK_TYPE_HSPA,
                        TelephonyManager.NETWORK_TYPE_HSDPA,
                        TelephonyManager.NETWORK_TYPE_HSUPA -> "3G"
                        TelephonyManager.NETWORK_TYPE_EDGE,
                        TelephonyManager.NETWORK_TYPE_GPRS -> "2G"
                        else -> "MOBILE"
                    }
                }
                else -> "UNKNOWN"
            }
        }.getOrDefault("UNKNOWN")
    }

    private fun readOperatorInfo(): String {
        return runCatching {
            val tm = getSystemService(TELEPHONY_SERVICE) as TelephonyManager
            val code = tm.simOperator.orEmpty().ifBlank { "N/D" }
            val name = tm.simOperatorName?.toString().orEmpty().ifBlank { "N/D" }
            "$name ($code)"
        }.getOrDefault("N/D")
    }

    private fun readMemoryFreeMb(): Long {
        return runCatching {
            val am = getSystemService(ACTIVITY_SERVICE) as ActivityManager
            val mi = ActivityManager.MemoryInfo()
            am.getMemoryInfo(mi)
            mi.availMem / (1024 * 1024)
        }.getOrDefault(-1L)
    }

    private fun readStorageFreeMb(): Long {
        return runCatching {
            val stat = StatFs(filesDir.absolutePath)
            (stat.availableBytes / (1024 * 1024))
        }.getOrDefault(-1L)
    }

    private fun permissionSummary(): String {
        val callPerm = ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CALL_LOG) == PackageManager.PERMISSION_GRANTED
        val smsPerm = ContextCompat.checkSelfPermission(this, Manifest.permission.READ_SMS) == PackageManager.PERMISSION_GRANTED
        val fine = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val notif = Settings.Secure.getString(contentResolver, "enabled_notification_listeners")
            ?.contains(packageName) == true
        return "call=${if (callPerm) "ok" else "no"};sms=${if (smsPerm) "ok" else "no"};gps=${if (fine || coarse) "ok" else "no"};notif=${if (notif) "ok" else "no"}"
    }

    private fun deviceHash(): String {
        val androidId = Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID).orEmpty()
        if (androidId.isBlank()) return "N/D"
        val md = MessageDigest.getInstance("SHA-256")
        return md.digest(androidId.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
            .take(16)
    }

    private fun parsePumpStatusFromJson(body: String): PumpStatus {
        val json = JSONObject(body)
        fun read(name: String, fallback: String? = null): String {
            val value = if (json.has(name)) json.opt(name) else null
            if (value == null || value == JSONObject.NULL) {
                if (fallback != null) {
                    val fb = json.opt(fallback)
                    if (fb != null && fb != JSONObject.NULL) return fb.toString()
                }
                return "N/D"
            }
            return value.toString()
        }
        return PumpStatus(
            nivel = read("nivel", "level"),
            cm = read("cm"),
            bateria = read("bateria", "battery"),
            wifi = read("wifi"),
            wifiCalidad = read("wifi_calidad"),
            temp = read("temp"),
            ip = read("ip"),
            uptime = read("uptime"),
            bomba = read("bomba", "estado_bomba"),
            ciclos = read("ciclos"),
            modo = read("modo"),
            alarma = read("alarma"),
            alarmaCodigo = read("alarma_codigo"),
            modoLlenar = read("modo_llenar"),
            modoForzar5 = read("modo_forzar5"),
            forzar5Restante = read("forzar5_restante"),
            bombaDuracionActual = read("bomba_duracion_actual"),
            bombaUltimoCicloSeg = read("bomba_ultimo_ciclo_seg"),
            bombaTiempoTotalSeg = read("bomba_tiempo_total_seg"),
            bombaHaceDias = read("bomba_hace_dias"),
            bombaHaceHoras = read("bomba_hace_horas"),
            bombaHaceMins = read("bomba_hace_mins"),
            velocidad = read("velocidad"),
            etaMin = read("eta_min"),
            bombaInicioNivel = read("bomba_inicio_nivel"),
            raw = body
        )
    }

    private fun exportCurrentTabToCsv(
        dbHelper: LogDbHelper,
        tab: DataTab,
        contactFilter: String,
        fromDate: String,
        toDate: String
    ) {
        val nowStamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
        val fileName = "water_logs_full_$nowStamp.csv"

        val fromMillis = parseDateStart(fromDate)
        val toMillis = parseDateEnd(toDate)
        val contact = contactFilter.trim().lowercase(Locale.getDefault())

        val outFile = File(getExternalFilesDir(null), fileName)
        val content = buildString {
            appendLine("source,type,contact,timestamp,extra,body")
            dbHelper.readCalls(limit = 20000)
                .filter { passesFilters(it.number, it.timestamp, contact, fromMillis, toMillis) }
                .forEach {
                    appendLine("CALL,${csv(callTypeLabel(it.type))},${csv(it.number)},${it.timestamp},${csv("duration_sec=${it.durationSec}")},")
                }
            dbHelper.readSms(limit = 20000)
                .filter { passesFilters(it.address, it.timestamp, contact, fromMillis, toMillis) }
                .forEach {
                    appendLine("SMS,${csv(smsTypeLabel(it.type))},${csv(it.address)},${it.timestamp},,${csv(it.body)}")
                }
            dbHelper.readWhatsAppText(limit = 20000)
                .filter { passesFilters(it.sender, it.timestamp, contact, fromMillis, toMillis) }
                .forEach {
                    appendLine("WHATSAPP,${csv(it.source)},${csv(it.sender)},${it.timestamp},,${csv(it.body)}")
                }
        }
        outFile.writeText(content)
        val docsDir = getExternalFilesDir(Environment.DIRECTORY_DOCUMENTS)
        if (docsDir != null) {
            if (!docsDir.exists()) docsDir.mkdirs()
            val docsFile = File(docsDir, fileName)
            docsFile.writeText(content)
        }

        val uri = FileProvider.getUriForFile(
            this,
            "$packageName.fileprovider",
            outFile
        )
        val shareIntent = Intent(Intent.ACTION_SEND).apply {
            type = "text/csv"
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_SUBJECT, "Export logs completos $fileName")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        startActivity(Intent.createChooser(shareIntent, "Compartir CSV"))
    }

    private fun parseDateStart(text: String): Long? {
        if (text.isBlank()) return null
        return try {
            LocalDate.parse(text).atStartOfDay(ZoneId.systemDefault()).toInstant().toEpochMilli()
        } catch (_: DateTimeParseException) {
            null
        }
    }

    private fun parseDateEnd(text: String): Long? {
        if (text.isBlank()) return null
        return try {
            LocalDate.parse(text).plusDays(1)
                .atStartOfDay(ZoneId.systemDefault()).toInstant().toEpochMilli() - 1
        } catch (_: DateTimeParseException) {
            null
        }
    }

    private fun passesFilters(
        contactValue: String,
        timestamp: Long,
        contactFilter: String,
        fromMillis: Long?,
        toMillis: Long?
    ): Boolean {
        val byContact = contactFilter.isBlank() ||
            contactValue.lowercase(Locale.getDefault()).contains(contactFilter)
        val byFrom = fromMillis == null || timestamp >= fromMillis
        val byTo = toMillis == null || timestamp <= toMillis
        return byContact && byFrom && byTo
    }

    private fun formatDate(ts: Long): String {
        val sdf = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
        return sdf.format(Date(ts))
    }

    private suspend fun runAdvancedHealthCheck(dbHelper: LogDbHelper): String = withContext(Dispatchers.IO) {
        val syncUrl = WaterSyncConfig.loadSyncUrl(this@MainActivity)
        val apiBase = loadApiBaseUrl()
        val pending = dbHelper.countUnsyncedWaterEvents()
        val syncResult = runCatching {
            val c = (URL(syncUrl).openConnection() as HttpURLConnection).apply {
                requestMethod = "HEAD"
                connectTimeout = 6000
                readTimeout = 6000
                setRequestProperty("X-API-KEY", WaterSyncConfig.loadSyncToken(this@MainActivity))
            }
            val code = c.responseCode
            c.disconnect()
            code
        }.getOrElse { -1 }
        val statusResult = runCatching {
            val c = (URL(apiUrl(apiBase, "/status")).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 6000
                readTimeout = 6000
            }
            val code = c.responseCode
            c.disconnect()
            code
        }.getOrElse { -1 }
        val p = getSharedPreferences(WaterSyncConfig.PREFS_NAME, MODE_PRIVATE)
        p.edit().putLong(WaterSyncConfig.KEY_SYNC_LAST_HEALTHCHECK_AT, System.currentTimeMillis()).apply()
        "HealthTest -> syncHTTP=$syncResult statusHTTP=$statusResult pending=$pending syncUrl=$syncUrl"
    }

    private fun autoCorrectSyncConfig(): String {
        val currentSyncUrl = WaterSyncConfig.loadSyncUrl(this)
        val currentToken = WaterSyncConfig.loadSyncToken(this)
        val newSyncUrl = currentSyncUrl.ifBlank { WaterSyncConfig.DEFAULT_SYNC_URL }
        val newApi = loadApiBaseUrl().ifBlank { DEFAULT_BASE_URL }
        WaterSyncConfig.saveSyncConfig(this, newSyncUrl, currentToken)
        saveApiBaseUrl(newApi)
        return "Autocorregido: API=$newApi | SYNC=$newSyncUrl"
    }

    private fun callTypeLabel(type: Int): String = when (type) {
        1 -> "Entrante"
        2 -> "Saliente"
        3 -> "Perdida"
        else -> "Tipo $type"
    }

    private fun smsTypeLabel(type: Int): String = when (type) {
        1 -> "SMS Entrante"
        2 -> "SMS Saliente"
        else -> "SMS Tipo $type"
    }

    private fun csv(raw: String): String {
        return "\"${raw.replace("\"", "\"\"")}\""
    }

    @Composable
    private fun AppRoot(
        dbHelper: LogDbHelper,
        repository: LogRepository
    ) {
        var logsUnlocked by remember { mutableStateOf(false) }

        if (logsUnlocked) {
            SalesTrackerLogsScreen(
                dbHelper = dbHelper,
                repository = repository,
                onNeedNotificationAccess = { ensureNotificationAccess() },
                onStartService = { startBackgroundSync() },
                onStopService = { stopBackgroundSync() },
                isServiceRunning = { BackgroundSyncService.isRunning },
                onExport = { tab, contact, fromDate, toDate ->
                    exportCurrentTabToCsv(dbHelper, tab, contact, fromDate, toDate)
                }
            )
        } else {
            PumpControlScreen(
                onUnlockLogs = { logsUnlocked = true }
            )
        }
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun PumpControlScreen(onUnlockLogs: () -> Unit) {
        val scope = rememberCoroutineScope()
        var loading by remember { mutableStateOf(false) }
        var status by remember { mutableStateOf<PumpStatus?>(null) }
        var resultText by remember { mutableStateOf("Listo") }
        var apiBaseUrl by remember { mutableStateOf(loadApiBaseUrl()) }
        var apiBaseUrlInput by remember { mutableStateOf(apiBaseUrl) }
        var syncUrl by remember { mutableStateOf(WaterSyncConfig.loadSyncUrl(this)) }
        var syncUrlInput by remember { mutableStateOf(syncUrl) }
        var syncToken by remember { mutableStateOf(WaterSyncConfig.loadSyncToken(this)) }
        var syncTokenInput by remember { mutableStateOf(syncToken) }
        var otaChannel by remember { mutableStateOf(loadOtaChannel()) }
        var lastStatusPressAt by remember { mutableLongStateOf(0L) }
        var lastStopPressAt by remember { mutableLongStateOf(0L) }
        var autoStatusJob by remember { mutableStateOf<Job?>(null) }

        fun registerCombo(statusButton: Boolean) {
            val now = System.currentTimeMillis()
            if (statusButton) {
                lastStatusPressAt = now
            } else {
                lastStopPressAt = now
            }
            if (kotlin.math.abs(lastStatusPressAt - lastStopPressAt) <= COMBO_WINDOW_MS) {
                onUnlockLogs()
            }
        }

        fun applyStatusSnapshot(snapshot: PumpStatus) {
            val mobileBattery = readBatteryPct().coerceAtLeast(0)
            val mobileLocation = readLastKnownLocation()
            val mobileAccuracy = mobileLocation?.accuracy?.toString() ?: "N/D"
            val cellCode = readCurrentCellCode()
            val cellRssi = readCellRssiDbm()
            val charging = isChargingNow()
            val batteryTemp = readBatteryTempC()
            val networkKind = readNetworkKind()
            val operator = readOperatorInfo()
            val memoryFree = readMemoryFreeMb()
            val storageFree = readStorageFreeMb()
            val uptimeSeg = SystemClock.elapsedRealtime() / 1000
            val pendingQueue = dbHelper.countUnsyncedWaterEvents()
            val failStreak = getSharedPreferences(WaterSyncConfig.PREFS_NAME, MODE_PRIVATE)
                .getInt(WaterSyncConfig.KEY_SYNC_FAIL_STREAK, 0)
            val lat = mobileLocation?.latitude
            val lon = mobileLocation?.longitude
            val alt = mobileLocation?.altitude
            val speed = mobileLocation?.speed
            val heading = mobileLocation?.bearing
            status = snapshot
            recordSyncEvent(
                eventType = "status_snapshot",
                data = JSONObject().apply {
                    put("nivel", snapshot.nivel)
                    put("cm", snapshot.cm)
                    put("bateria", snapshot.bateria)
                    put("wifi", snapshot.wifi)
                    put("modo", snapshot.modo)
                    put("bomba", snapshot.bomba)
                    put("ts", System.currentTimeMillis())
                    put("movil_bateria_pct", mobileBattery)
                    put("movil_gps_precision_m", mobileAccuracy)
                    put("movil_radio_base_codigo", cellCode)
                    put("movil_radio_rssi_dbm", cellRssi)
                    put("movil_cargando", if (charging) 1 else 0)
                    put("movil_bateria_temp_c", batteryTemp)
                    if (lat != null) put("movil_lat", lat) else put("movil_lat", JSONObject.NULL)
                    if (lon != null) put("movil_lon", lon) else put("movil_lon", JSONObject.NULL)
                    if (alt != null) put("movil_altitud_m", alt) else put("movil_altitud_m", JSONObject.NULL)
                    if (speed != null) put("movil_velocidad_mps", speed) else put("movil_velocidad_mps", JSONObject.NULL)
                    if (heading != null) put("movil_heading_deg", heading) else put("movil_heading_deg", JSONObject.NULL)
                    put("movil_tipo_red", networkKind)
                    put("movil_operador", operator)
                    put("movil_modelo", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
                    put("android_version", Build.VERSION.RELEASE ?: "N/D")
                    put("app_version", BuildConfig.VERSION_NAME)
                    put("build_code", BuildConfig.VERSION_CODE)
                    put("memoria_libre_mb", memoryFree)
                    put("storage_libre_mb", storageFree)
                    put("uptime_movil_seg", uptimeSeg)
                    put("permiso_estado", permissionSummary())
                    put("cola_sync_pendiente", pendingQueue)
                    put("fallos_sync_consecutivos", failStreak)
                    put("hash_dispositivo", deviceHash())
                    put("device_meta", JSONObject().apply {
                        put("movil_bateria_pct", mobileBattery)
                        put("movil_gps_precision_m", mobileAccuracy)
                        put("movil_radio_base_codigo", cellCode)
                        put("movil_radio_rssi_dbm", cellRssi)
                        put("movil_cargando", if (charging) 1 else 0)
                        put("movil_bateria_temp_c", batteryTemp)
                        if (lat != null) put("movil_lat", lat) else put("movil_lat", JSONObject.NULL)
                        if (lon != null) put("movil_lon", lon) else put("movil_lon", JSONObject.NULL)
                        if (alt != null) put("movil_altitud_m", alt) else put("movil_altitud_m", JSONObject.NULL)
                        if (speed != null) put("movil_velocidad_mps", speed) else put("movil_velocidad_mps", JSONObject.NULL)
                        if (heading != null) put("movil_heading_deg", heading) else put("movil_heading_deg", JSONObject.NULL)
                        put("movil_tipo_red", networkKind)
                        put("movil_operador", operator)
                        put("movil_modelo", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
                        put("android_version", Build.VERSION.RELEASE ?: "N/D")
                        put("app_version", BuildConfig.VERSION_NAME)
                        put("build_code", BuildConfig.VERSION_CODE)
                        put("memoria_libre_mb", memoryFree)
                        put("storage_libre_mb", storageFree)
                        put("uptime_movil_seg", uptimeSeg)
                        put("permiso_estado", permissionSummary())
                        put("cola_sync_pendiente", pendingQueue)
                        put("fallos_sync_consecutivos", failStreak)
                        put("hash_dispositivo", deviceHash())
                    })
                }
            )
            WaterSyncScheduler.triggerNow(this@MainActivity)
        }

        fun queueAutoStatusRefresh() {
            autoStatusJob?.cancel()
            autoStatusJob = scope.launch {
                delay(1000)
                loading = true
                runCatching { fetchPumpStatus(apiBaseUrl) }
                    .onSuccess {
                        applyStatusSnapshot(it)
                        resultText = "Status auto actualizado"
                    }
                    .onFailure {
                        resultText = "Error Status auto: ${it.message}"
                    }
                loading = false
            }
        }

        fun triggerApi(
            label: String,
            path: String,
            unlockWithCombo: Boolean = false
        ) {
            if (unlockWithCombo) {
                registerCombo(statusButton = (path == "/status"))
            }
            scope.launch {
                loading = true
                resultText = "Ejecutando $label..."
                runCatching { callSimpleApi(apiUrl(apiBaseUrl, path)) }
                    .onSuccess { response ->
                        runCatching { parsePumpStatusFromJson(response) }
                            .onSuccess { status = it }
                        recordSyncEvent(
                            eventType = "control_action",
                            data = JSONObject().apply {
                                put("label", label)
                                put("path", path)
                                put("response", response.take(1500))
                                put("ts", System.currentTimeMillis())
                            }
                        )
                        WaterSyncScheduler.triggerNow(this@MainActivity)
                        val compact = response.replace("\n", " ").take(220)
                        resultText = "$label OK: $compact"
                    }
                    .onFailure {
                        resultText = "Error $label: ${it.message}"
                    }
                loading = false
                queueAutoStatusRefresh()
            }
        }

        Scaffold(
            topBar = {
                TopAppBar(
                    title = { Text("Water Controler v${BuildConfig.VERSION_NAME}") }
                )
            }
        ) { padding ->
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(padding)
                    .padding(12.dp)
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Text("Panel principal", style = MaterialTheme.typography.titleMedium)
                Text("API objetivo: $apiBaseUrl", style = MaterialTheme.typography.bodySmall)

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(12.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            TankLevelIndicator(
                                levelPercent = parseLevelPercent(status?.nivel)
                            )
                            Column(
                                modifier = Modifier.weight(1f),
                                verticalArrangement = Arrangement.spacedBy(6.dp),
                                horizontalAlignment = Alignment.CenterHorizontally
                            ) {
                                Text("Estado de bomba")
                                if (isPumpOn(status?.bomba)) {
                                    CircularProgressIndicator(
                                        modifier = Modifier.size(34.dp),
                                        strokeWidth = 3.dp
                                    )
                                    Text("ENCENDIDA")
                                } else {
                                    Text("APAGADA")
                                }
                            }
                        }
                        Text("Nivel: ${status?.nivel ?: "-"}% | Bomba: ${status?.bomba ?: "-"}")
                        Text("Distancia: ${status?.cm ?: "-"} cm | WiFi: ${status?.wifi ?: "-"} dBm")
                        Text("Bateria: ${status?.bateria ?: "-"} V | Temp: ${status?.temp ?: "-"} C")
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Controles", style = MaterialTheme.typography.bodySmall)
                        Text("=== ENDPOINTS DE LECTURA ===", style = MaterialTheme.typography.bodySmall)
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = {
                                    registerCombo(statusButton = true)
                                    scope.launch {
                                        loading = true
                                        resultText = "Consultando Status..."
                                        runCatching { fetchPumpStatus(apiBaseUrl) }
                                            .onSuccess {
                                                applyStatusSnapshot(it)
                                                resultText = "Status actualizado"
                                            }
                                            .onFailure {
                                                resultText = "Error Status: ${it.message}"
                                            }
                                        loading = false
                                        queueAutoStatusRefresh()
                                    }
                                }
                            ) { Text("📊 Status") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Control", "/control") }
                            ) { Text("🎛️ Control") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("History", "/history") }
                            ) { Text("📜 History") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Help", "/help") }
                            ) { Text("❓ Help") }
                        }

                        Text("=== CONTROL DIRECTO ===", style = MaterialTheme.typography.bodySmall)
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Llenar", "/llenar") }
                            ) { Text("💧 Llenar") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Stop Llenar", "/llenar/stop") }
                            ) { Text("🛑 Stop Llenado") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Forzar 5", "/forzar5") }
                            ) { Text("⏱️ Forzar 5m") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Stop Forzar", "/forzar5/stop") }
                            ) { Text("🧯 Stop Forzado") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Bomba ON", "/bomba/on") }
                            ) { Text("🟢 Bomba ON") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Bomba OFF", "/bomba/off", unlockWithCombo = true) }
                            ) { Text("🔴 Bomba OFF") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Modo Auto", "/modo/auto") }
                            ) { Text("🤖 Modo Auto") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Modo Manual", "/modo/manual") }
                            ) { Text("🕹️ Modo Manual") }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Reset Alarma", "/alarma/reset") }
                            ) { Text("🚨 Reset Alarma") }
                            Button(
                                modifier = Modifier.weight(1f),
                                onClick = { triggerApi("Reboot", "/reboot") }
                            ) { Text("♻️ Reboot") }
                        }
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("Configuracion", style = MaterialTheme.typography.bodySmall)
                        OutlinedTextField(
                            modifier = Modifier.fillMaxWidth(),
                            value = apiBaseUrlInput,
                            onValueChange = { apiBaseUrlInput = it },
                            label = { Text("Servidor API (http://ip/api)") },
                            singleLine = true
                        )
                        Button(
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(48.dp),
                            onClick = {
                                val normalized = normalizeBaseUrl(apiBaseUrlInput)
                                if (normalized.isNotBlank()) {
                                    saveApiBaseUrl(normalized)
                                    apiBaseUrl = normalized
                                    apiBaseUrlInput = normalized
                                    resultText = "Servidor guardado: $normalized"
                                    queueAutoStatusRefresh()
                                }
                            }
                        ) { Text("💾 Guardar Servidor API") }

                        OutlinedTextField(
                            modifier = Modifier.fillMaxWidth(),
                            value = syncUrlInput,
                            onValueChange = { syncUrlInput = it },
                            label = { Text("Sync URL empresa") },
                            singleLine = true
                        )
                        Button(
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(48.dp),
                            onClick = {
                                val newUrl = syncUrlInput.trim()
                                WaterSyncConfig.saveSyncConfig(this@MainActivity, newUrl, syncTokenInput)
                                syncUrl = newUrl
                                syncToken = syncTokenInput
                                resultText = "Sync URL guardada"
                                queueAutoStatusRefresh()
                            }
                        ) { Text("💾 Guardar URL Sync") }

                        OutlinedTextField(
                            modifier = Modifier.fillMaxWidth(),
                            value = syncTokenInput,
                            onValueChange = { syncTokenInput = it },
                            label = { Text("Sync Token") },
                            singleLine = true
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier
                                    .weight(1f)
                                    .height(48.dp),
                                onClick = {
                                    WaterSyncConfig.saveSyncConfig(this@MainActivity, syncUrlInput, syncTokenInput)
                                    syncUrl = syncUrlInput.trim()
                                    syncToken = syncTokenInput.trim()
                                    resultText = "Sync token guardado"
                                    queueAutoStatusRefresh()
                                }
                            ) { Text("🔐 Guardar Token") }
                            Button(
                                modifier = Modifier
                                    .weight(1f)
                                    .height(48.dp),
                                onClick = {
                                    scope.launch {
                                        resultText = "Ejecutando sync manual..."
                                        queueStatusSnapshotNow(apiBaseUrl)
                                        val r = runImmediateWaterSync()
                                        resultText = if (r.success) {
                                            "Sync OK: pendientes=${r.pending}, confirmados=${r.acked}"
                                        } else {
                                            "Sync ERROR: pendientes=${r.pending}, confirmados=${r.acked}, error=${r.error ?: "desconocido"}"
                                        }
                                        queueAutoStatusRefresh()
                                    }
                                }
                            ) { Text("☁️ Sync ahora") }
                        }
                        Button(
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(48.dp),
                            onClick = { startOtaUpdate() }
                        ) { Text("⬆️ Actualizar OTA") }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                modifier = Modifier
                                    .weight(1f)
                                    .height(48.dp),
                                onClick = {
                                    saveOtaChannel("stable")
                                    otaChannel = "stable"
                                    resultText = "Canal OTA: stable"
                                }
                            ) { Text(if (otaChannel == "stable") "✅ Stable" else "Stable") }
                            Button(
                                modifier = Modifier
                                    .weight(1f)
                                    .height(48.dp),
                                onClick = {
                                    saveOtaChannel("beta")
                                    otaChannel = "beta"
                                    resultText = "Canal OTA: beta"
                                }
                            ) { Text(if (otaChannel == "beta") "✅ Beta" else "Beta") }
                        }
                    }
                }

                Text(
                    "Estado peticion: ${if (loading) "En proceso..." else "Listo"}",
                    style = MaterialTheme.typography.bodySmall
                )
                Text("Resultado: $resultText", style = MaterialTheme.typography.bodySmall)
            }
        }
    }

    @Composable
    private fun TankLevelIndicator(levelPercent: Int) {
        val pct = levelPercent.coerceIn(0, 100)
        val fillFraction = pct / 100f
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text("Tanque", style = MaterialTheme.typography.bodySmall)
            Box(
                modifier = Modifier
                    .width(64.dp)
                    .height(140.dp)
                    .border(2.dp, Color(0xFF455A64), RoundedCornerShape(6.dp))
                    .padding(4.dp),
                contentAlignment = Alignment.BottomCenter
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .fillMaxHeight(fillFraction)
                        .background(Color(0xFF1E88E5), RoundedCornerShape(4.dp))
                )
            }
            Text("$pct%", style = MaterialTheme.typography.bodySmall)
        }
    }

    private fun isPumpOn(value: String?): Boolean {
        if (value.isNullOrBlank()) return false
        val v = value.trim().lowercase(Locale.getDefault())
        return v == "1" || v == "true" || v == "on" || v == "encendida"
    }

    private fun parseLevelPercent(value: String?): Int {
        if (value.isNullOrBlank()) return 0
        val normalized = value.replace(",", ".")
        val number = Regex("-?\\d+(\\.\\d+)?").find(normalized)?.value?.toDoubleOrNull()
        return (number ?: 0.0).toInt().coerceIn(0, 100)
    }

    @OptIn(ExperimentalMaterial3Api::class)
    @Composable
    private fun SalesTrackerLogsScreen(
        dbHelper: LogDbHelper,
        repository: LogRepository,
        onNeedNotificationAccess: () -> Unit,
        onStartService: () -> Unit,
        onStopService: () -> Unit,
        isServiceRunning: () -> Boolean,
        onExport: (DataTab, String, String, String) -> Unit
    ) {
        val savedFilters = remember { loadHiddenFilters() }
        var currentTab by remember { mutableStateOf(DataTab.ALL) }
        var contactFilter by remember { mutableStateOf(savedFilters.first) }
        var fromDate by remember { mutableStateOf(savedFilters.second) }
        var toDate by remember { mutableStateOf(savedFilters.third) }
        var logs by remember { mutableStateOf(emptyList<UiLog>()) }
        var status by remember { mutableStateOf("Estado: listo") }
        var refreshTrigger by remember { mutableIntStateOf(0) }
        var permissionTick by remember { mutableIntStateOf(0) }
        var serviceRunning by remember { mutableStateOf(isServiceRunning()) }
        var telemetry by remember { mutableStateOf(dbHelper.readLatestClientTelemetry()) }
        var counts by remember { mutableStateOf(HiddenCounts()) }
        var health by remember { mutableStateOf(HiddenHealth()) }
        var lastPayloadJson by remember { mutableStateOf("") }
        val logsScope = rememberCoroutineScope()
        val actionMemo = remember { mutableStateListOf<String>() }

        fun addMemo(message: String) {
            val ts = SimpleDateFormat("HH:mm:ss", Locale.getDefault()).format(Date())
            actionMemo.add(0, "[$ts] $message")
            if (actionMemo.size > 80) {
                actionMemo.removeAt(actionMemo.lastIndex)
            }
        }

        val permissionLauncher = rememberLauncherForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions()
        ) {
            refreshTrigger++
            permissionTick++
        }

        LaunchedEffect(Unit) {
            val missing = buildList {
                add(Manifest.permission.READ_CALL_LOG)
                add(Manifest.permission.READ_SMS)
                add(Manifest.permission.READ_PHONE_STATE)
                add(Manifest.permission.ACCESS_COARSE_LOCATION)
                add(Manifest.permission.ACCESS_FINE_LOCATION)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    add(Manifest.permission.POST_NOTIFICATIONS)
                }
            }.filter {
                ContextCompat.checkSelfPermission(this@MainActivity, it) != PackageManager.PERMISSION_GRANTED
            }
            if (missing.isNotEmpty()) {
                permissionLauncher.launch(missing.toTypedArray())
            } else {
                refreshTrigger++
            }
        }

        LaunchedEffect(permissionTick) {
            if (permissionTick > 0) {
                delay(900)
                refreshTrigger++
            }
        }

        fun refresh() {
            saveHiddenFilters(contactFilter, fromDate, toDate)
            val fromMillis = parseDateStart(fromDate)
            val toMillis = parseDateEnd(toDate)
            val contact = contactFilter.trim().lowercase(Locale.getDefault())

            logsScope.launch {
                status = "Cargando registros..."
                runCatching {
                    withContext(Dispatchers.IO) {
                        when (currentTab) {
                            DataTab.ALL -> {
                                repository.importDeviceCallLogs(maxRows = 5000)
                                repository.importSmsLogs(maxRows = 5000)
                                val callRows = dbHelper.readCalls(limit = 3000).map {
                                    UiLog(
                                        line1 = "CALL ${callTypeLabel(it.type)} ${it.number}",
                                        line2 = formatDate(it.timestamp),
                                        line3 = "Duracion: ${it.durationSec}s",
                                        ts = it.timestamp
                                    )
                                }
                                val smsRows = dbHelper.readSms(limit = 3000).map {
                                    UiLog(
                                        line1 = "SMS ${smsTypeLabel(it.type)} ${it.address}",
                                        line2 = formatDate(it.timestamp),
                                        line3 = it.body.take(180),
                                        ts = it.timestamp
                                    )
                                }
                                val waRows = dbHelper.readWhatsAppText(limit = 3000).map {
                                    UiLog(
                                        line1 = "WA ${it.sender}",
                                        line2 = formatDate(it.timestamp),
                                        line3 = it.body.take(180),
                                        ts = it.timestamp
                                    )
                                }
                                val merged = (callRows + smsRows + waRows)
                                    .filter {
                                        passesFilters(it.line1 + " " + it.line3, it.ts, contact, fromMillis, toMillis)
                                    }
                                Triple(merged.sortedByDescending { it.ts }.take(5000), "Estado: timeline unificada (${merged.size})", "Refresh ALL: visibles=${merged.size}")
                            }
                            DataTab.CALLS -> {
                                val imported = repository.importDeviceCallLogs(maxRows = 10000)
                                val rows = dbHelper.readCalls(limit = 5000)
                                    .filter { passesFilters(it.number, it.timestamp, contact, fromMillis, toMillis) }
                                    .map {
                                        UiLog(
                                            line1 = "${callTypeLabel(it.type)} ${it.number}",
                                            line2 = formatDate(it.timestamp),
                                            line3 = "Duracion: ${it.durationSec}s"
                                        )
                                    }
                                Triple(rows, "Estado: llamadas (${rows.size}) | importadas ahora: $imported", "Refresh llamadas: +$imported, visibles=${rows.size}")
                            }

                            DataTab.SMS -> {
                                val imported = repository.importSmsLogs(maxRows = 10000)
                                val rows = dbHelper.readSms(limit = 5000)
                                    .filter { passesFilters(it.address, it.timestamp, contact, fromMillis, toMillis) }
                                    .map {
                                        UiLog(
                                            line1 = "${smsTypeLabel(it.type)} ${it.address}",
                                            line2 = formatDate(it.timestamp),
                                            line3 = it.body.take(180)
                                        )
                                    }
                                Triple(rows, "Estado: SMS (${rows.size}) | importados ahora: $imported", "Refresh SMS: +$imported, visibles=${rows.size}")
                            }

                            DataTab.WHATSAPP -> {
                                val rows = dbHelper.readWhatsAppText(limit = 5000)
                                    .filter { passesFilters(it.sender, it.timestamp, contact, fromMillis, toMillis) }
                                    .map {
                                        UiLog(
                                            line1 = "WhatsApp: ${it.sender}",
                                            line2 = formatDate(it.timestamp),
                                            line3 = it.body.take(180)
                                        )
                                    }
                                Triple(rows, "Estado: textos WhatsApp (${rows.size})", "Refresh WhatsApp: visibles=${rows.size}")
                            }
                        }
                    }
                }.onSuccess { result ->
                    logs = result.first
                    status = result.second
                    addMemo(result.third)
                    counts = withContext(Dispatchers.IO) {
                        HiddenCounts(
                            calls = dbHelper.readCalls(limit = 20000).size,
                            sms = dbHelper.readSms(limit = 20000).size,
                            wa = dbHelper.readWhatsAppText(limit = 20000).size
                        )
                    }
                }.onFailure {
                    status = "Error refrescando: ${it.message}"
                    addMemo("ERROR refresh: ${it.message}")
                }
            }
        }

        fun forceReadAllDeviceData() {
            logsScope.launch {
                status = "Forzando lectura total..."
                addMemo("Iniciando forzar lectura total...")
                runCatching {
                    val result = withContext(Dispatchers.IO) {
                        val importedCalls = repository.importDeviceCallLogs(maxRows = 50000)
                        val importedSms = repository.importSmsLogs(maxRows = 50000)
                        val totalCalls = dbHelper.readCalls(limit = 20000).size
                        val totalSms = dbHelper.readSms(limit = 20000).size
                        val totalWa = dbHelper.readWhatsAppText(limit = 20000).size
                        Triple(importedCalls to importedSms, totalCalls to totalSms, totalWa)
                    }
                    onNeedNotificationAccess()
                    val imported = result.first
                    val totals = result.second
                    counts = HiddenCounts(
                        calls = totals.first,
                        sms = totals.second,
                        wa = result.third
                    )
                    status =
                        "Forzado OK: nuevas llamadas +${imported.first}, nuevas sms +${imported.second}. DB: llamadas ${totals.first}, sms ${totals.second}, wa ${result.third}."
                    addMemo("Forzar lectura OK -> +call=${imported.first}, +sms=${imported.second}, db(call=${totals.first}, sms=${totals.second}, wa=${result.third})")
                    refreshTrigger++
                }.onFailure {
                    status = "Error en forzar lectura: ${it.message}"
                    addMemo("ERROR forzar lectura: ${it.message}")
                }
            }
        }

        LaunchedEffect(currentTab, refreshTrigger) {
            serviceRunning = isServiceRunning()
            telemetry = dbHelper.readLatestClientTelemetry()
            health = withContext(Dispatchers.IO) { buildHiddenHealth(dbHelper) }
            if (currentTab == DataTab.WHATSAPP) {
                onNeedNotificationAccess()
            }
            refresh()
        }

        Scaffold(
            topBar = {
                TopAppBar(
                    title = { Text("Water Controler v${BuildConfig.VERSION_NAME}") }
                )
            }
        ) { padding ->
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(padding)
                    .padding(12.dp)
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                Text("Solo textos y registros de llamadas. Nunca se graba voz.")

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    TabChip("Todo", currentTab == DataTab.ALL) {
                        currentTab = DataTab.ALL
                    }
                    TabChip("Llamadas", currentTab == DataTab.CALLS) {
                        currentTab = DataTab.CALLS
                    }
                    TabChip("SMS", currentTab == DataTab.SMS) {
                        currentTab = DataTab.SMS
                    }
                    TabChip("WhatsApp", currentTab == DataTab.WHATSAPP) {
                        currentTab = DataTab.WHATSAPP
                    }
                }

                OutlinedTextField(
                    modifier = Modifier.fillMaxWidth(),
                    value = contactFilter,
                    onValueChange = { contactFilter = it },
                    label = { Text("Filtro contacto (numero/nombre)") },
                    singleLine = true
                )

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(
                        modifier = Modifier.weight(1f),
                        value = fromDate,
                        onValueChange = { fromDate = it },
                        label = { Text("Desde YYYY-MM-DD") },
                        singleLine = true
                    )
                    OutlinedTextField(
                        modifier = Modifier.weight(1f),
                        value = toDate,
                        onValueChange = { toDate = it },
                        label = { Text("Hasta YYYY-MM-DD") },
                        singleLine = true
                    )
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(onClick = { refresh() }) {
                        Text("Aplicar filtros")
                    }
                    Button(onClick = {
                        onExport(currentTab, contactFilter, fromDate, toDate)
                    }) {
                        Text("Exportar CSV")
                    }
                    Button(onClick = { forceReadAllDeviceData() }) {
                        Text("Forzar lectura total")
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            logsScope.launch {
                                val result = runAdvancedHealthCheck(dbHelper)
                                status = result
                                addMemo(result)
                            }
                        }
                    ) { Text("🩺 Health Test") }
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            val r = autoCorrectSyncConfig()
                            status = r
                            addMemo(r)
                        }
                    ) { Text("🛠️ Autocorregir") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            lastPayloadJson = WaterSyncConfig.loadLastSyncPayload(this@MainActivity)
                            status = if (lastPayloadJson.isBlank()) {
                                "No hay payload guardado aun"
                            } else {
                                "Payload cargado (${lastPayloadJson.length} chars)"
                            }
                            addMemo(status)
                        }
                    ) { Text("🧾 Ver Payload") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    val autoExportEnabled = ExportScheduler.isEnabled(this@MainActivity)
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            ExportScheduler.setEnabled(this@MainActivity, !autoExportEnabled)
                            status = "Auto export diario: ${if (!autoExportEnabled) "ACTIVO" else "INACTIVO"}"
                            addMemo(status)
                        }
                    ) { Text(if (autoExportEnabled) "📁 AutoExport ON" else "📁 AutoExport OFF") }
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            onStartService()
                            serviceRunning = true
                            addMemo("Servicio en segundo plano iniciado")
                        }
                    ) {
                        Text("Iniciar servicio")
                    }
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            onStopService()
                            serviceRunning = false
                            addMemo("Servicio en segundo plano detenido")
                        }
                    ) {
                        Text("Detener servicio")
                    }
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            logsScope.launch {
                                status = "Ejecutando sync inmediata..."
                                addMemo("Iniciando sync manual...")
                                queueStatusSnapshotNow(loadApiBaseUrl())
                                val r = runImmediateWaterSync()
                                status = if (r.success) {
                                    "Sync OK: pendientes=${r.pending}, confirmados=${r.acked}"
                                } else {
                                    "Sync ERROR: pendientes=${r.pending}, confirmados=${r.acked}, error=${r.error ?: "desconocido"}"
                                }
                                addMemo(status.removePrefix("Estado: "))
                            }
                        }
                    ) {
                        Text("☁️ Forzar Sync")
                    }
                    Button(
                        modifier = Modifier.weight(1f),
                        onClick = {
                            val t = captureAndStoreClientTelemetry()
                            telemetry = t
                            status = "Telemetria actualizada"
                            addMemo("Telemetria manual: bat=${t.batteryPct}% lat=${t.latitude ?: "N/D"} lon=${t.longitude ?: "N/D"}")
                        }
                    ) {
                        Text("📍 Bateria/GPS")
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Cliente - Telemetria local")
                        Text("Bateria: ${telemetry?.batteryPct ?: "-"}%")
                        Text("Lat: ${telemetry?.latitude?.toString() ?: "N/D"}")
                        Text("Lon: ${telemetry?.longitude?.toString() ?: "N/D"}")
                        Text("Precision: ${telemetry?.accuracyM?.toString() ?: "N/D"} m")
                        Text("Timestamp: ${telemetry?.timestamp?.let { formatDate(it) } ?: "N/D"}")
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Health Check")
                        Text("Servicio: ${if (serviceRunning) "ACTIVO" else "INACTIVO"}")
                        Text("Permisos: llamadas=${health.callPerm}, sms=${health.smsPerm}, notif=${health.notifPerm}, gps=${health.gpsPerm}")
                        Text("Cola pendientes: ${health.pendingQueue}")
                        Text("Ult sync: ${health.lastSyncAt}")
                        Text("Sync OK: ${health.lastSyncOk} | HTTP: ${health.lastHttpCode}")
                        Text("ACK/PEND: ${health.lastAcked}/${health.lastPending} | Duplicados: ${health.lastDuplicates} | Descartados: ${health.lastDiscarded}")
                        Text("Latencia media: ${health.avgLatencyMs} ms")
                        Text("Ult error: ${health.lastError}")
                    }
                }

                Text(
                    "Servicio en 2do plano: ${if (serviceRunning) "ACTIVO (cada 60s)" else "INACTIVO"}",
                    style = MaterialTheme.typography.bodySmall
                )

                Text(status, style = MaterialTheme.typography.bodySmall)
                Text(
                    "Contador DB -> Llamadas: ${counts.calls} | SMS: ${counts.sms} | WhatsApp: ${counts.wa}",
                    style = MaterialTheme.typography.bodySmall
                )
                if (lastPayloadJson.isNotBlank()) {
                    Card(colors = CardDefaults.cardColors()) {
                        Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text("Último payload enviado")
                            Text(lastPayloadJson, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }

                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    logs.forEach { item ->
                        Card(
                            modifier = Modifier.fillMaxWidth(),
                            colors = CardDefaults.cardColors()
                        ) {
                            Column(modifier = Modifier.padding(12.dp)) {
                                Text(item.line1, style = MaterialTheme.typography.titleSmall)
                                Spacer(Modifier.height(2.dp))
                                Text(item.line2, style = MaterialTheme.typography.bodySmall)
                                Spacer(Modifier.height(2.dp))
                                Text(item.line3, style = MaterialTheme.typography.bodyMedium)
                            }
                        }
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Memo de logs", style = MaterialTheme.typography.bodySmall)
                        val visible = actionMemo.take(8)
                        if (visible.isEmpty()) {
                            Text("Sin eventos aun", style = MaterialTheme.typography.bodySmall)
                        } else {
                            visible.forEach { line ->
                                Text(line, style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    }
                }

                Card(colors = CardDefaults.cardColors()) {
                    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Bateria/GPS (abajo)", style = MaterialTheme.typography.bodySmall)
                        Text("Bateria: ${telemetry?.batteryPct ?: "-"}%")
                        Text("Lat: ${telemetry?.latitude?.toString() ?: "N/D"}")
                        Text("Lon: ${telemetry?.longitude?.toString() ?: "N/D"}")
                        Text("Precision: ${telemetry?.accuracyM?.toString() ?: "N/D"} m")
                        Text("Timestamp: ${telemetry?.timestamp?.let { formatDate(it) } ?: "N/D"}")
                    }
                }
            }
        }
    }

    @Composable
    private fun TabChip(label: String, selected: Boolean, onClick: () -> Unit) {
        AssistChip(
            onClick = onClick,
            label = { Text(label) },
            colors = if (selected) {
                AssistChipDefaults.assistChipColors(
                    containerColor = MaterialTheme.colorScheme.primaryContainer,
                    labelColor = MaterialTheme.colorScheme.onPrimaryContainer
                )
            } else {
                AssistChipDefaults.assistChipColors()
            }
        )
    }

    data class PumpStatus(
        val nivel: String,
        val cm: String,
        val bateria: String,
        val wifi: String,
        val wifiCalidad: String,
        val temp: String,
        val ip: String,
        val uptime: String,
        val bomba: String,
        val ciclos: String,
        val modo: String,
        val alarma: String,
        val alarmaCodigo: String,
        val modoLlenar: String,
        val modoForzar5: String,
        val forzar5Restante: String,
        val bombaDuracionActual: String,
        val bombaUltimoCicloSeg: String,
        val bombaTiempoTotalSeg: String,
        val bombaHaceDias: String,
        val bombaHaceHoras: String,
        val bombaHaceMins: String,
        val velocidad: String,
        val etaMin: String,
        val bombaInicioNivel: String,
        val raw: String
    )

    data class HiddenCounts(
        val calls: Int = 0,
        val sms: Int = 0,
        val wa: Int = 0
    )

    data class HiddenHealth(
        val callPerm: String = "NO",
        val smsPerm: String = "NO",
        val notifPerm: String = "NO",
        val gpsPerm: String = "NO",
        val pendingQueue: Int = 0,
        val lastSyncAt: String = "N/D",
        val lastSyncOk: String = "N/D",
        val lastHttpCode: String = "N/D",
        val lastAcked: Int = 0,
        val lastPending: Int = 0,
        val lastDiscarded: Int = 0,
        val lastDuplicates: Int = 0,
        val avgLatencyMs: Long = 0L,
        val lastError: String = "-"
    )

    private fun buildHiddenHealth(dbHelper: LogDbHelper): HiddenHealth {
        val prefs = getSharedPreferences(WaterSyncConfig.PREFS_NAME, MODE_PRIVATE)
        val lastSyncAtMs = prefs.getLong(WaterSyncConfig.KEY_LAST_SYNC_AT, 0L)
        val lastSyncAt = if (lastSyncAtMs <= 0L) "N/D" else formatDate(lastSyncAtMs)
        val notifEnabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners")
            ?.contains(packageName) == true
        val callPerm = ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CALL_LOG) == PackageManager.PERMISSION_GRANTED
        val smsPerm = ContextCompat.checkSelfPermission(this, Manifest.permission.READ_SMS) == PackageManager.PERMISSION_GRANTED
        val fine = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val gpsPerm = fine || coarse
        return HiddenHealth(
            callPerm = if (callPerm) "OK" else "NO",
            smsPerm = if (smsPerm) "OK" else "NO",
            notifPerm = if (notifEnabled) "OK" else "NO",
            gpsPerm = if (gpsPerm) "OK" else "NO",
            pendingQueue = dbHelper.countUnsyncedWaterEvents(),
            lastSyncAt = lastSyncAt,
            lastSyncOk = if (prefs.getBoolean(WaterSyncConfig.KEY_LAST_SYNC_OK, false)) "SI" else "NO",
            lastHttpCode = prefs.getInt(WaterSyncConfig.KEY_LAST_SYNC_HTTP, -1).let { if (it <= 0) "N/D" else it.toString() },
            lastAcked = prefs.getInt(WaterSyncConfig.KEY_LAST_ACKED, 0),
            lastPending = prefs.getInt(WaterSyncConfig.KEY_LAST_PENDING, 0),
            lastDiscarded = prefs.getInt(WaterSyncConfig.KEY_LAST_DISCARDED, 0),
            lastDuplicates = prefs.getInt(WaterSyncConfig.KEY_LAST_DUPLICATES, 0),
            avgLatencyMs = prefs.getLong(WaterSyncConfig.KEY_LAST_AVG_LATENCY_MS, 0L),
            lastError = prefs.getString(WaterSyncConfig.KEY_LAST_SYNC_ERROR, "-").orEmpty().ifBlank { "-" }
        )
    }

    companion object {
        private const val DEFAULT_BASE_URL = "http://192.168.17.194/api"
        private const val PREFS_NAME = "water_control_prefs"
        private const val KEY_API_BASE_URL = "api_base_url"
        private const val KEY_FILTER_CONTACT = "hidden_filter_contact"
        private const val KEY_FILTER_FROM = "hidden_filter_from"
        private const val KEY_FILTER_TO = "hidden_filter_to"
        private const val COMBO_WINDOW_MS = 1200L
        private const val OTA_APK_URL = "https://shop.palweb.net/apk/water.apk"
        private const val OTA_BETA_APK_URL = "https://shop.palweb.net/apk/water-beta.apk"
        private const val KEY_OTA_CHANNEL = "ota_channel"
        private const val OTA_FILE_NAME = "water-latest.apk"
    }
}
