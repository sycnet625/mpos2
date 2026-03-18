package com.palweb.reservasoffline

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat

object SyncNotifier {
    private const val CHANNEL_ID = "reservas_sync"
    private const val OTA_NOTIFICATION_ID = 2003

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val ch = NotificationChannel(CHANNEL_ID, "Reservas Sync", NotificationManager.IMPORTANCE_DEFAULT)
            val nm = context.getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(ch)
        }
    }

    fun notify(context: Context, id: Int, title: String, body: String) {
        ensureChannel(context)
        val openIntent = MainActivity.intentForLaunch(context).let { intent ->
            PendingIntent.getActivity(
                context,
                11_001,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }
        val n = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_popup_sync)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(openIntent)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(id, n) }
    }

    fun notifyOtaProgress(context: Context, title: String, body: String, progress: Int?, indeterminate: Boolean = false) {
        ensureChannel(context)
        val openIntent = MainActivity.intentForLaunch(context).let { intent ->
            PendingIntent.getActivity(
                context,
                11_003,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }
        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_sys_download)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(openIntent)
            .setOnlyAlertOnce(true)
            .setOngoing(true)
            .setAutoCancel(false)
            .setPriority(NotificationCompat.PRIORITY_LOW)

        if (indeterminate || progress == null) {
            builder.setProgress(100, 0, true)
        } else {
            builder.setProgress(100, progress.coerceIn(0, 100), false)
        }

        runCatching { NotificationManagerCompat.from(context).notify(OTA_NOTIFICATION_ID, builder.build()) }
    }

    fun notifyOtaResult(context: Context, success: Boolean, title: String, body: String) {
        ensureChannel(context)
        val openIntent = MainActivity.intentForLaunch(context).let { intent ->
            PendingIntent.getActivity(
                context,
                11_004,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        }
        val n = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(if (success) android.R.drawable.stat_sys_download_done else android.R.drawable.stat_notify_error)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(openIntent)
            .setOngoing(false)
            .setAutoCancel(true)
            .setPriority(if (success) NotificationCompat.PRIORITY_DEFAULT else NotificationCompat.PRIORITY_HIGH)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(OTA_NOTIFICATION_ID, n) }
    }
}
