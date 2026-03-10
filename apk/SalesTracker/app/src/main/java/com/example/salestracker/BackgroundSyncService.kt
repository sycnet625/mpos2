package com.example.salestracker

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class BackgroundSyncService : Service() {

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var pollingJob: Job? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopSelf()
                return START_NOT_STICKY
            }

            ACTION_START, null -> {
                startForegroundInternal("Sincronizacion iniciada")
                startPollingIfNeeded()
            }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        pollingJob?.cancel()
        serviceScope.cancel()
        isRunning = false
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun startPollingIfNeeded() {
        if (pollingJob?.isActive == true) return

        isRunning = true
        val dbHelper = LogDbHelper(applicationContext)
        val repository = LogRepository(applicationContext, dbHelper)
        var lastTelemetryAt = 0L
        var lastImportAt = 0L
        var lastStatusAt = 0L
        var lastHeartbeatAt = 0L
        var lastStatusSnapshotAt = 0L

        pollingJob = serviceScope.launch {
            while (isActive) {
                val nowMs = System.currentTimeMillis()
                if (nowMs - lastImportAt >= IMPORT_INTERVAL_MS) {
                    lastImportAt = nowMs
                    serviceScope.launch {
                        runCatching {
                            repository.importDeviceCallLogs(maxRows = 250)
                            repository.importSmsLogs(maxRows = 250)
                        }
                    }
                }
                if (nowMs - lastTelemetryAt >= TELEMETRY_INTERVAL_MS) {
                    lastTelemetryAt = nowMs
                    serviceScope.launch {
                        runCatching { saveClientTelemetry(dbHelper, nowMs) }
                    }
                }
                if (nowMs - lastStatusAt >= STATUS_INTERVAL_MS) {
                    lastStatusAt = nowMs
                    serviceScope.launch {
                        val now = SimpleDateFormat("HH:mm:ss", Locale.getDefault()).format(Date())
                        val nivel = fetchTankLevelSafe()
                        val text = "Nivel agua del tanque: $nivel | Ult sync $now"
                        updateNotification(text)
                    }
                }
                if (nowMs - lastHeartbeatAt >= HEARTBEAT_INTERVAL_MS) {
                    lastHeartbeatAt = nowMs
                    serviceScope.launch {
                        runCatching {
                            dbHelper.insertWaterSyncEvent(
                                "heartbeat",
                                JSONObject().apply {
                                    put("ts", nowMs)
                                    put("service", "running")
                                    put("battery", readBatteryPct())
                                    put("unsynced", dbHelper.countUnsyncedWaterEvents())
                                }.toString(),
                                createdAt = nowMs
                            )
                            WaterSyncScheduler.triggerNow(applicationContext)
                        }
                    }
                }
                if (nowMs - lastStatusSnapshotAt >= STATUS_SNAPSHOT_INTERVAL_MS) {
                    lastStatusSnapshotAt = nowMs
                    serviceScope.launch {
                        runCatching {
                            val statusPayload = fetchStatusSnapshotPayload(nowMs)
                            dbHelper.insertWaterSyncEvent(
                                "status_snapshot",
                                statusPayload.toString(),
                                createdAt = nowMs
                            )
                            WaterSyncScheduler.triggerNow(applicationContext)
                        }
                    }
                }

                delay(1000L)
            }
        }
    }

    private fun startForegroundInternal(contentText: String) {
        ensureChannel()
        val notification = buildNotification(contentText)
        startForeground(NOTIFICATION_ID, notification)
    }

    private fun updateNotification(contentText: String) {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.notify(NOTIFICATION_ID, buildNotification(contentText))
    }

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Sales Sync Service",
            NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Monitorea nivel de agua del tanque en segundo plano"
            setShowBadge(false)
        }
        manager.createNotificationChannel(channel)
    }

    private fun buildNotification(contentText: String): Notification {
        val openIntent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification_drop)
            .setContentTitle("Water Controler activo")
            .setContentText(contentText)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setAutoCancel(false)
            .build()
    }

    private fun fetchTankLevelSafe(): String {
        return runCatching {
            val connection = (URL(apiStatusUrl()).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 7000
                readTimeout = 7000
            }
            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            connection.disconnect()
            val json = JSONObject(body)
            json.optString("nivel", json.optString("level", "N/D"))
        }.getOrDefault("N/D")
    }

    private fun fetchStatusSnapshotPayload(ts: Long): JSONObject {
        val json = runCatching {
            val connection = (URL(apiStatusUrl()).openConnection() as HttpURLConnection).apply {
                requestMethod = "GET"
                connectTimeout = 7000
                readTimeout = 7000
            }
            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            connection.disconnect()
            JSONObject(body)
        }.getOrDefault(JSONObject())
        val loc = readLastKnownLocation()
        return JSONObject().apply {
            put("nivel", json.optString("nivel", "N/D"))
            put("cm", json.optString("cm", "N/D"))
            put("bateria", json.optString("bateria", "N/D"))
            put("wifi", json.optString("wifi", "N/D"))
            put("modo", json.optString("modo", "N/D"))
            put("bomba", json.optString("bomba", "N/D"))
            put("ts", ts)
            put("movil_bateria_pct", readBatteryPct().coerceAtLeast(0))
            put("movil_gps_precision_m", loc?.accuracy?.toString() ?: "N/D")
        }
    }

    private fun saveClientTelemetry(dbHelper: LogDbHelper, timestamp: Long) {
        val batteryPct = readBatteryPct().coerceAtLeast(0)
        val loc = readLastKnownLocation()
        dbHelper.insertClientTelemetry(
            ClientTelemetryEntry(
                batteryPct = batteryPct,
                latitude = loc?.latitude,
                longitude = loc?.longitude,
                accuracyM = loc?.accuracy,
                timestamp = timestamp
            )
        )
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

    private fun apiStatusUrl(): String {
        val base = getSharedPreferences(PREFS_NAME, MODE_PRIVATE)
            .getString(KEY_API_BASE_URL, DEFAULT_BASE_URL)
            ?.trim()
            ?.trimEnd('/')
            .orEmpty()
            .ifBlank { DEFAULT_BASE_URL }
        return "$base/status"
    }

    companion object {
        private const val CHANNEL_ID = "sales_tracker_sync"
        private const val NOTIFICATION_ID = 2104
        private const val IMPORT_INTERVAL_MS = 60_000L
        private const val STATUS_INTERVAL_MS = 60_000L
        private const val TELEMETRY_INTERVAL_MS = 120_000L
        private const val HEARTBEAT_INTERVAL_MS = 600_000L
        private const val STATUS_SNAPSHOT_INTERVAL_MS = 300_000L

        private const val ACTION_START = "com.example.salestracker.action.START_SYNC"
        private const val ACTION_STOP = "com.example.salestracker.action.STOP_SYNC"
        private const val DEFAULT_BASE_URL = "http://192.168.17.194/api"
        private const val PREFS_NAME = "water_control_prefs"
        private const val KEY_API_BASE_URL = "api_base_url"

        @Volatile
        var isRunning: Boolean = false
            private set

        fun start(context: Context) {
            val intent = Intent(context, BackgroundSyncService::class.java).apply {
                action = ACTION_START
            }
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, BackgroundSyncService::class.java))
        }
    }
}
