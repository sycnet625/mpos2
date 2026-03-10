package com.example.salestracker

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.provider.CallLog
import android.provider.Telephony
import androidx.core.content.ContextCompat
import org.json.JSONObject

class LogRepository(private val context: Context, private val dbHelper: LogDbHelper) {

    fun importDeviceCallLogs(maxRows: Int = 500): Int {
        if (ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.READ_CALL_LOG
            ) != PackageManager.PERMISSION_GRANTED
        ) return 0

        val resolver = context.contentResolver
        val cursor = resolver.query(
            CallLog.Calls.CONTENT_URI,
            arrayOf(
                CallLog.Calls.NUMBER,
                CallLog.Calls.TYPE,
                CallLog.Calls.DATE,
                CallLog.Calls.DURATION
            ),
            null,
            null,
            "${CallLog.Calls.DATE} DESC"
        )

        var count = 0
        cursor?.use {
            while (it.moveToNext() && count < maxRows) {
                val item = CallLogEntry(
                    number = it.getString(0) ?: "(sin numero)",
                    type = it.getInt(1),
                    timestamp = it.getLong(2),
                    durationSec = it.getLong(3)
                )
                val inserted = dbHelper.insertCallLog(item)
                if (inserted) {
                    dbHelper.insertWaterSyncEvent(
                        "call_log",
                        JSONObject().apply {
                            put("number", item.number)
                            put("type", item.type)
                            put("timestamp", item.timestamp)
                            put("duration_sec", item.durationSec)
                        }.toString(),
                        createdAt = item.timestamp
                    )
                    count++
                }
            }
        }
        return count
    }

    fun importSmsLogs(maxRows: Int = 500): Int {
        if (ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.READ_SMS
            ) != PackageManager.PERMISSION_GRANTED
        ) return 0

        val resolver = context.contentResolver
        val cursor = resolver.query(
            Telephony.Sms.CONTENT_URI,
            arrayOf(
                Telephony.Sms.ADDRESS,
                Telephony.Sms.BODY,
                Telephony.Sms.DATE,
                Telephony.Sms.TYPE
            ),
            null,
            null,
            "${Telephony.Sms.DATE} DESC"
        )

        var count = 0
        cursor?.use {
            while (it.moveToNext() && count < maxRows) {
                val item = SmsLogEntry(
                    address = it.getString(0) ?: "(sin direccion)",
                    body = it.getString(1) ?: "",
                    timestamp = it.getLong(2),
                    type = it.getInt(3)
                )
                val inserted = dbHelper.insertSmsLog(item)
                if (inserted) {
                    dbHelper.insertWaterSyncEvent(
                        "sms_log",
                        JSONObject().apply {
                            put("address", item.address)
                            put("body", item.body)
                            put("timestamp", item.timestamp)
                            put("type", item.type)
                        }.toString(),
                        createdAt = item.timestamp
                    )
                    count++
                }
            }
        }
        return count
    }
}
