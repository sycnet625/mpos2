package com.example.salestracker

import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import org.json.JSONObject

class WhatsAppNotificationListenerService : NotificationListenerService() {

    private lateinit var dbHelper: LogDbHelper

    override fun onCreate() {
        super.onCreate()
        dbHelper = LogDbHelper(applicationContext)
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (sbn.packageName != WHATSAPP_PACKAGE) return

        val extras = sbn.notification.extras
        val title = extras.getCharSequence("android.title")?.toString().orEmpty().trim()
        val text = extras.getCharSequence("android.text")?.toString().orEmpty().trim()

        if (title.isBlank() && text.isBlank()) return

        val item = WaTextLogEntry(
            sender = if (title.isBlank()) "WhatsApp" else title,
            body = if (text.isBlank()) "(sin texto)" else text,
            timestamp = sbn.postTime,
            source = "notification"
        )
        val inserted = dbHelper.insertWhatsAppText(item)
        if (inserted) {
            dbHelper.insertWaterSyncEvent(
                "whatsapp_text",
                JSONObject().apply {
                    put("sender", item.sender)
                    put("body", item.body)
                    put("timestamp", item.timestamp)
                    put("source", item.source)
                }.toString(),
                createdAt = item.timestamp
            )
        }
    }

    companion object {
        private const val WHATSAPP_PACKAGE = "com.whatsapp"
    }
}
