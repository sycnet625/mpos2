package com.example.salestracker

import android.content.Context
import android.os.Environment
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.TimeUnit

object ExportScheduler {
    private const val WORK_NAME = "water_export_daily"
    private const val KEY_ENABLED = "auto_export_enabled"

    fun setEnabled(context: Context, enabled: Boolean) {
        context.getSharedPreferences(WaterSyncConfig.PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(KEY_ENABLED, enabled)
            .apply()
        if (enabled) {
            val req = PeriodicWorkRequestBuilder<AutoExportWorker>(24, TimeUnit.HOURS)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.NOT_REQUIRED)
                        .build()
                )
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.MINUTES)
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.UPDATE,
                req
            )
        } else {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
        }
    }

    fun isEnabled(context: Context): Boolean {
        return context.getSharedPreferences(WaterSyncConfig.PREFS_NAME, Context.MODE_PRIVATE)
            .getBoolean(KEY_ENABLED, false)
    }
}

class AutoExportWorker(
    appContext: Context,
    params: WorkerParameters
) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        return runCatching {
            val db = LogDbHelper(applicationContext)
            val out = buildCombinedCsv(db)
            val docsDir = applicationContext.getExternalFilesDir(Environment.DIRECTORY_DOCUMENTS)
                ?: return Result.retry()
            if (!docsDir.exists()) docsDir.mkdirs()
            val stamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(Date())
            File(docsDir, "water_auto_export_$stamp.csv").writeText(out)
            Result.success()
        }.getOrElse { Result.retry() }
    }

    private fun buildCombinedCsv(db: LogDbHelper): String {
        fun csv(raw: String): String = "\"${raw.replace("\"", "\"\"")}\""
        return buildString {
            appendLine("source,type,contact,timestamp,extra,body")
            db.readCalls(limit = 20000).forEach {
                appendLine("CALL,${csv(it.type.toString())},${csv(it.number)},${it.timestamp},${csv("duration_sec=${it.durationSec}")},")
            }
            db.readSms(limit = 20000).forEach {
                appendLine("SMS,${csv(it.type.toString())},${csv(it.address)},${it.timestamp},,${csv(it.body)}")
            }
            db.readWhatsAppText(limit = 20000).forEach {
                appendLine("WHATSAPP,${csv(it.source)},${csv(it.sender)},${it.timestamp},,${csv(it.body)}")
            }
        }
    }
}
