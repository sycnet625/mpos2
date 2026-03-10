package com.example.salestracker

import android.content.Context
import android.os.Build
import android.provider.Settings
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.TimeUnit
import java.util.zip.GZIPOutputStream
import kotlin.math.max
import kotlinx.coroutines.delay

object WaterSyncConfig {
    const val PREFS_NAME = "water_control_prefs"
    const val KEY_SYNC_URL = "sync_url"
    const val KEY_SYNC_TOKEN = "sync_token"
    const val KEY_LAST_SYNC_AT = "last_sync_at"
    const val KEY_LAST_SYNC_OK = "last_sync_ok"
    const val KEY_LAST_SYNC_ERROR = "last_sync_error"
    const val KEY_LAST_SYNC_HTTP = "last_sync_http"
    const val KEY_LAST_ACKED = "last_sync_acked"
    const val KEY_LAST_PENDING = "last_sync_pending"
    const val KEY_LAST_DISCARDED = "last_sync_discarded"
    const val KEY_LAST_DUPLICATES = "last_sync_duplicates"
    const val KEY_LAST_AVG_LATENCY_MS = "last_sync_avg_latency_ms"
    const val KEY_SYNC_FAIL_STREAK = "sync_fail_streak"
    const val KEY_SYNC_LAST_HEALTHCHECK_AT = "sync_last_healthcheck_at"
    private const val LAST_PAYLOAD_FILE = "last_sync_payload.json"

    const val DEFAULT_SYNC_URL = "https://shop.palweb.net/apk/api/water_sync.php?action=push"
    const val DEFAULT_SYNC_TOKEN = "CHANGE_ME_SYNC_TOKEN"

    fun loadSyncUrl(context: Context): String {
        return context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_SYNC_URL, DEFAULT_SYNC_URL)
            ?.trim()
            ?.ifBlank { DEFAULT_SYNC_URL }
            ?: DEFAULT_SYNC_URL
    }

    fun loadSyncToken(context: Context): String {
        return context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_SYNC_TOKEN, DEFAULT_SYNC_TOKEN)
            ?.trim()
            ?.ifBlank { DEFAULT_SYNC_TOKEN }
            ?: DEFAULT_SYNC_TOKEN
    }

    fun saveSyncConfig(context: Context, url: String, token: String) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_SYNC_URL, url.trim())
            .putString(KEY_SYNC_TOKEN, token.trim())
            .apply()
    }

    fun saveLastSyncResult(
        context: Context,
        success: Boolean,
        error: String?,
        httpCode: Int?,
        acked: Int,
        pending: Int,
        discarded: Int,
        duplicates: Int,
        avgLatencyMs: Long
    ) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putLong(KEY_LAST_SYNC_AT, System.currentTimeMillis())
            .putBoolean(KEY_LAST_SYNC_OK, success)
            .putString(KEY_LAST_SYNC_ERROR, error ?: "")
            .putInt(KEY_LAST_SYNC_HTTP, httpCode ?: -1)
            .putInt(KEY_LAST_ACKED, acked)
            .putInt(KEY_LAST_PENDING, pending)
            .putInt(KEY_LAST_DISCARDED, discarded)
            .putInt(KEY_LAST_DUPLICATES, duplicates)
            .putLong(KEY_LAST_AVG_LATENCY_MS, avgLatencyMs)
            .putInt(
                KEY_SYNC_FAIL_STREAK,
                if (success) 0 else context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                    .getInt(KEY_SYNC_FAIL_STREAK, 0) + 1
            )
            .apply()
    }

    fun saveLastSyncPayload(context: Context, payload: String) {
        runCatching {
            context.openFileOutput(LAST_PAYLOAD_FILE, Context.MODE_PRIVATE).use { out ->
                out.write(payload.toByteArray(Charsets.UTF_8))
            }
        }
    }

    fun loadLastSyncPayload(context: Context): String {
        return runCatching {
            context.openFileInput(LAST_PAYLOAD_FILE).bufferedReader(Charsets.UTF_8).use { it.readText() }
        }.getOrDefault("")
    }

    fun deviceName(context: Context): String {
        val model = "${Build.MANUFACTURER} ${Build.MODEL}".trim()
        val androidId = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
            ?.takeLast(6)
            ?: "unknown"
        return "$model-$androidId"
    }
}

object WaterSyncScheduler {
    private const val PERIODIC_WORK_NAME = "water_sync_hourly"

    fun schedule(context: Context) {
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()

        val periodic = PeriodicWorkRequestBuilder<WaterSyncWorker>(1, TimeUnit.HOURS)
            .setConstraints(constraints)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.MINUTES)
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            PERIODIC_WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            periodic
        )
    }

    fun triggerNow(context: Context) {
        val oneTime = OneTimeWorkRequestBuilder<WaterSyncWorker>().build()
        WorkManager.getInstance(context).enqueue(oneTime)
    }
}

data class WaterSyncRunResult(
    val success: Boolean,
    val pending: Int,
    val acked: Int,
    val error: String? = null,
    val discarded: Int = 0,
    val duplicates: Int = 0,
    val avgLatencyMs: Long = 0,
    val httpCode: Int? = null
)

private data class PostSyncResponse(
    val ackIds: List<Long>,
    val httpCode: Int,
    val serverInserted: Int,
    val serverReceived: Int
)

object WaterSyncEngine {
    suspend fun syncOnce(context: Context): WaterSyncRunResult {
        val db = LogDbHelper(context)
        val pendingTotal = db.countUnsyncedWaterEvents()
        val failStreak = context.getSharedPreferences(WaterSyncConfig.PREFS_NAME, Context.MODE_PRIVATE)
            .getInt(WaterSyncConfig.KEY_SYNC_FAIL_STREAK, 0)
        val batchSize = chooseBatchSize(pendingTotal, failStreak)
        val pending = db.readUnsyncedWaterEvents(limit = batchSize)

        val url = WaterSyncConfig.loadSyncUrl(context)
        val token = WaterSyncConfig.loadSyncToken(context)
        val deviceName = WaterSyncConfig.deviceName(context)
        val validEvents = pending.filter { it.id > 0 && it.eventType.isNotBlank() }
        val discarded = max(0, pending.size - validEvents.size)
        val now = System.currentTimeMillis()
        val avgLatency = if (validEvents.isEmpty()) 0L else {
            validEvents.sumOf { ev -> max(0L, now - ev.createdAt) } / validEvents.size
        }

        return runCatching {
            val payload = JSONObject().apply {
                put("device_name", deviceName)
                put("sent_at", isoNow())
                put("batch_size", batchSize)
                put("events", JSONArray().apply {
                    validEvents.forEach { ev ->
                        put(JSONObject().apply {
                            put("local_id", ev.id)
                            put("event_type", ev.eventType)
                            put("payload_json", JSONObject(ev.payloadJson))
                            put("created_at", ev.createdAt)
                        })
                    }
                })
            }.toString()
            WaterSyncConfig.saveLastSyncPayload(context, payload)

            val sendResult = postWithRetry(url, token, payload)
            if (sendResult.ackIds.isNotEmpty()) {
                db.markWaterEventsSynced(sendResult.ackIds)
            }
            val duplicates = max(0, sendResult.serverReceived - sendResult.serverInserted)
            val runResult = WaterSyncRunResult(
                success = validEvents.isEmpty() || sendResult.ackIds.size >= validEvents.size,
                pending = pendingTotal,
                acked = sendResult.ackIds.size,
                error = if (validEvents.isNotEmpty() && sendResult.ackIds.size < validEvents.size) "ACK incompleto" else null,
                discarded = discarded,
                duplicates = duplicates,
                avgLatencyMs = avgLatency,
                httpCode = sendResult.httpCode
            )
            WaterSyncConfig.saveLastSyncResult(
                context = context,
                success = runResult.success,
                error = runResult.error,
                httpCode = runResult.httpCode,
                acked = runResult.acked,
                pending = runResult.pending,
                discarded = runResult.discarded,
                duplicates = runResult.duplicates,
                avgLatencyMs = runResult.avgLatencyMs
            )
            runResult
        }.getOrElse {
            val runResult = WaterSyncRunResult(
                success = false,
                pending = pendingTotal,
                acked = 0,
                error = it.message ?: "Error de sync",
                discarded = discarded,
                duplicates = 0,
                avgLatencyMs = avgLatency,
                httpCode = null
            )
            WaterSyncConfig.saveLastSyncResult(
                context = context,
                success = false,
                error = runResult.error,
                httpCode = null,
                acked = 0,
                pending = runResult.pending,
                discarded = runResult.discarded,
                duplicates = 0,
                avgLatencyMs = runResult.avgLatencyMs
            )
            runResult
        }
    }

    private suspend fun postWithRetry(url: String, token: String, body: String): PostSyncResponse {
        var lastError: Throwable? = null
        var waitMs = 1000L
        repeat(3) { attempt ->
            runCatching { return postGzipJson(url, token, body) }
                .onFailure { lastError = it }
            if (attempt < 2) {
                val jitter = (200L..800L).random()
                delay(waitMs + jitter)
                waitMs *= 2
            }
        }
        throw IllegalStateException(lastError?.message ?: "Sync sin respuesta")
    }

    private fun postGzipJson(url: String, token: String, body: String): PostSyncResponse {
        val bodyBytes = body.toByteArray(Charsets.UTF_8)
        val gzipped = gzip(body.toByteArray(Charsets.UTF_8))
        val checksum = sha256Hex(bodyBytes)

        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            connectTimeout = 15_000
            readTimeout = 20_000
            setRequestProperty("Content-Type", "application/json")
            setRequestProperty("Content-Encoding", "gzip")
            setRequestProperty("X-API-KEY", token)
            setRequestProperty("X-Payload-SHA256", checksum)
        }

        conn.outputStream.use { it.write(gzipped) }

        val code = conn.responseCode
        val stream = if (code in 200..299) conn.inputStream else conn.errorStream
        val responseBody = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
        conn.disconnect()

        if (code !in 200..299) throw IllegalStateException("HTTP $code: $responseBody")

        val json = JSONObject(responseBody)
        val ack = json.optJSONArray("ack_local_ids") ?: JSONArray()
        val ids = mutableListOf<Long>()
        for (i in 0 until ack.length()) {
            ids += ack.optLong(i)
        }
        return PostSyncResponse(
            ackIds = ids,
            httpCode = code,
            serverInserted = json.optInt("inserted", ids.size),
            serverReceived = json.optInt("received", ids.size)
        )
    }

    private fun gzip(input: ByteArray): ByteArray {
        val bos = ByteArrayOutputStream()
        GZIPOutputStream(bos).use { it.write(input) }
        return bos.toByteArray()
    }

    private fun isoNow(): String {
        return SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).format(Date())
    }

    private fun chooseBatchSize(pendingTotal: Int, failStreak: Int): Int {
        val base = when {
            pendingTotal >= 5000 -> 100
            pendingTotal >= 1000 -> 200
            else -> 400
        }
        return when {
            failStreak >= 5 -> 50
            failStreak >= 3 -> 100
            failStreak >= 1 -> max(100, base / 2)
            else -> base
        }
    }

    private fun sha256Hex(input: ByteArray): String {
        val md = MessageDigest.getInstance("SHA-256")
        val digest = md.digest(input)
        return digest.joinToString("") { b -> "%02x".format(b) }
    }
}

class WaterSyncWorker(
    appContext: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(appContext, workerParams) {

    override suspend fun doWork(): Result {
        val r = WaterSyncEngine.syncOnce(applicationContext)
        return if (r.success) Result.success() else Result.retry()
    }
}
