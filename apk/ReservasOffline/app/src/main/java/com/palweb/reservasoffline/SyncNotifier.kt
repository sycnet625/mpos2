package com.palweb.reservasoffline

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat

object SyncNotifier {
    private const val CHANNEL_ID = "reservas_sync"

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val ch = NotificationChannel(CHANNEL_ID, "Reservas Sync", NotificationManager.IMPORTANCE_DEFAULT)
            val nm = context.getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(ch)
        }
    }

    fun notify(context: Context, id: Int, title: String, body: String) {
        ensureChannel(context)
        val n = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(id, n) }
    }
}
