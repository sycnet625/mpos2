package com.example.salestracker

enum class DataTab {
    ALL, CALLS, SMS, WHATSAPP
}

data class UiLog(
    val line1: String,
    val line2: String,
    val line3: String,
    val ts: Long = 0L
)

data class CallLogEntry(
    val number: String,
    val type: Int,
    val timestamp: Long,
    val durationSec: Long
)

data class SmsLogEntry(
    val address: String,
    val body: String,
    val timestamp: Long,
    val type: Int
)

data class WaTextLogEntry(
    val sender: String,
    val body: String,
    val timestamp: Long,
    val source: String
)

data class WaterSyncEvent(
    val id: Long,
    val eventType: String,
    val payloadJson: String,
    val createdAt: Long
)

data class ClientTelemetryEntry(
    val batteryPct: Int,
    val latitude: Double?,
    val longitude: Double?,
    val accuracyM: Float?,
    val timestamp: Long
)
