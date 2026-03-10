package com.example.salestracker

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

class LogDbHelper(context: Context) : SQLiteOpenHelper(context, DB_NAME, null, DB_VERSION) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE $TABLE_CALLS (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                number TEXT NOT NULL,
                type INTEGER NOT NULL,
                timestamp INTEGER NOT NULL,
                duration_sec INTEGER NOT NULL,
                UNIQUE(number, type, timestamp, duration_sec) ON CONFLICT IGNORE
            )
            """.trimIndent()
        )
        db.execSQL(
            """
            CREATE TABLE $TABLE_SMS (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                address TEXT NOT NULL,
                body TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                type INTEGER NOT NULL,
                UNIQUE(address, body, timestamp, type) ON CONFLICT IGNORE
            )
            """.trimIndent()
        )
        db.execSQL(
            """
            CREATE TABLE $TABLE_WA_TEXT (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender TEXT NOT NULL,
                body TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                source TEXT NOT NULL,
                UNIQUE(sender, body, timestamp, source) ON CONFLICT IGNORE
            )
            """.trimIndent()
        )
        db.execSQL(
            """
            CREATE TABLE $TABLE_WATER_SYNC_EVENTS (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                synced_at INTEGER
            )
            """.trimIndent()
        )
        db.execSQL(
            """
            CREATE TABLE $TABLE_CLIENT_TELEMETRY (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                battery_pct INTEGER NOT NULL,
                latitude REAL,
                longitude REAL,
                accuracy_m REAL,
                timestamp INTEGER NOT NULL
            )
            """.trimIndent()
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        db.execSQL("DROP TABLE IF EXISTS $TABLE_CALLS")
        db.execSQL("DROP TABLE IF EXISTS $TABLE_SMS")
        db.execSQL("DROP TABLE IF EXISTS $TABLE_WA_TEXT")
        db.execSQL("DROP TABLE IF EXISTS $TABLE_WATER_SYNC_EVENTS")
        db.execSQL("DROP TABLE IF EXISTS $TABLE_CLIENT_TELEMETRY")
        onCreate(db)
    }

    fun insertCallLog(item: CallLogEntry): Boolean {
        val values = ContentValues().apply {
            put("number", item.number)
            put("type", item.type)
            put("timestamp", item.timestamp)
            put("duration_sec", item.durationSec)
        }
        return writableDatabase.insert(TABLE_CALLS, null, values) != -1L
    }

    fun insertSmsLog(item: SmsLogEntry): Boolean {
        val values = ContentValues().apply {
            put("address", item.address)
            put("body", item.body)
            put("timestamp", item.timestamp)
            put("type", item.type)
        }
        return writableDatabase.insert(TABLE_SMS, null, values) != -1L
    }

    fun insertWhatsAppText(item: WaTextLogEntry): Boolean {
        val values = ContentValues().apply {
            put("sender", item.sender)
            put("body", item.body)
            put("timestamp", item.timestamp)
            put("source", item.source)
        }
        return writableDatabase.insert(TABLE_WA_TEXT, null, values) != -1L
    }

    fun readCalls(limit: Int = 300): List<CallLogEntry> {
        val result = mutableListOf<CallLogEntry>()
        val c = readableDatabase.query(
            TABLE_CALLS,
            arrayOf("number", "type", "timestamp", "duration_sec"),
            null,
            null,
            null,
            null,
            "timestamp DESC",
            limit.toString()
        )
        c.use {
            while (it.moveToNext()) {
                result += CallLogEntry(
                    number = it.getString(0),
                    type = it.getInt(1),
                    timestamp = it.getLong(2),
                    durationSec = it.getLong(3)
                )
            }
        }
        return result
    }

    fun readSms(limit: Int = 300): List<SmsLogEntry> {
        val result = mutableListOf<SmsLogEntry>()
        val c = readableDatabase.query(
            TABLE_SMS,
            arrayOf("address", "body", "timestamp", "type"),
            null,
            null,
            null,
            null,
            "timestamp DESC",
            limit.toString()
        )
        c.use {
            while (it.moveToNext()) {
                result += SmsLogEntry(
                    address = it.getString(0),
                    body = it.getString(1),
                    timestamp = it.getLong(2),
                    type = it.getInt(3)
                )
            }
        }
        return result
    }

    fun readWhatsAppText(limit: Int = 300): List<WaTextLogEntry> {
        val result = mutableListOf<WaTextLogEntry>()
        val c = readableDatabase.query(
            TABLE_WA_TEXT,
            arrayOf("sender", "body", "timestamp", "source"),
            null,
            null,
            null,
            null,
            "timestamp DESC",
            limit.toString()
        )
        c.use {
            while (it.moveToNext()) {
                result += WaTextLogEntry(
                    sender = it.getString(0),
                    body = it.getString(1),
                    timestamp = it.getLong(2),
                    source = it.getString(3)
                )
            }
        }
        return result
    }

    fun insertWaterSyncEvent(eventType: String, payloadJson: String, createdAt: Long = System.currentTimeMillis()) {
        val values = ContentValues().apply {
            put("event_type", eventType)
            put("payload_json", payloadJson)
            put("created_at", createdAt)
            putNull("synced_at")
        }
        writableDatabase.insert(TABLE_WATER_SYNC_EVENTS, null, values)
    }

    fun readUnsyncedWaterEvents(limit: Int = 300): List<WaterSyncEvent> {
        val result = mutableListOf<WaterSyncEvent>()
        val c = readableDatabase.query(
            TABLE_WATER_SYNC_EVENTS,
            arrayOf("id", "event_type", "payload_json", "created_at"),
            "synced_at IS NULL",
            null,
            null,
            null,
            "id ASC",
            limit.toString()
        )
        c.use {
            while (it.moveToNext()) {
                result += WaterSyncEvent(
                    id = it.getLong(0),
                    eventType = it.getString(1),
                    payloadJson = it.getString(2),
                    createdAt = it.getLong(3)
                )
            }
        }
        return result
    }

    fun markWaterEventsSynced(ids: List<Long>, syncedAt: Long = System.currentTimeMillis()) {
        if (ids.isEmpty()) return
        val placeholders = ids.joinToString(",") { "?" }
        val sql = "UPDATE $TABLE_WATER_SYNC_EVENTS SET synced_at = ? WHERE id IN ($placeholders)"
        val args = arrayOf(syncedAt.toString(), *ids.map { it.toString() }.toTypedArray())
        writableDatabase.execSQL(sql, args)
    }

    fun countUnsyncedWaterEvents(): Int {
        val c = readableDatabase.rawQuery(
            "SELECT COUNT(*) FROM $TABLE_WATER_SYNC_EVENTS WHERE synced_at IS NULL",
            null
        )
        c.use {
            if (!it.moveToFirst()) return 0
            return it.getInt(0)
        }
    }

    fun countCalls(): Int = countRows(TABLE_CALLS)
    fun countSms(): Int = countRows(TABLE_SMS)
    fun countWhatsApp(): Int = countRows(TABLE_WA_TEXT)

    private fun countRows(table: String): Int {
        val c = readableDatabase.rawQuery("SELECT COUNT(*) FROM $table", null)
        c.use {
            if (!it.moveToFirst()) return 0
            return it.getInt(0)
        }
    }

    fun insertClientTelemetry(item: ClientTelemetryEntry) {
        val values = ContentValues().apply {
            put("battery_pct", item.batteryPct)
            if (item.latitude == null) putNull("latitude") else put("latitude", item.latitude)
            if (item.longitude == null) putNull("longitude") else put("longitude", item.longitude)
            if (item.accuracyM == null) putNull("accuracy_m") else put("accuracy_m", item.accuracyM)
            put("timestamp", item.timestamp)
        }
        writableDatabase.insert(TABLE_CLIENT_TELEMETRY, null, values)
    }

    fun readLatestClientTelemetry(): ClientTelemetryEntry? {
        val c = readableDatabase.query(
            TABLE_CLIENT_TELEMETRY,
            arrayOf("battery_pct", "latitude", "longitude", "accuracy_m", "timestamp"),
            null,
            null,
            null,
            null,
            "timestamp DESC",
            "1"
        )
        c.use {
            if (!it.moveToFirst()) return null
            return ClientTelemetryEntry(
                batteryPct = it.getInt(0),
                latitude = if (it.isNull(1)) null else it.getDouble(1),
                longitude = if (it.isNull(2)) null else it.getDouble(2),
                accuracyM = if (it.isNull(3)) null else it.getFloat(3),
                timestamp = it.getLong(4)
            )
        }
    }

    companion object {
        private const val DB_NAME = "sales_tracker_logs.db"
        private const val DB_VERSION = 3

        const val TABLE_CALLS = "call_logs"
        const val TABLE_SMS = "sms_logs"
        const val TABLE_WA_TEXT = "wa_text_logs"
        const val TABLE_WATER_SYNC_EVENTS = "water_sync_events"
        const val TABLE_CLIENT_TELEMETRY = "client_telemetry"
    }
}
