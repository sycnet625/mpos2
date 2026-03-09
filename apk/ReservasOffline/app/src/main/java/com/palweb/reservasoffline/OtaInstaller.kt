package com.palweb.reservasoffline

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File
import java.io.FileOutputStream
import java.net.URL
import java.security.MessageDigest

object OtaInstaller {
    fun downloadAndInstall(context: Context, apkUrl: String, expectedSha256: String): String {
        val outDir = File(context.getExternalFilesDir(null), "ota")
        if (!outDir.exists()) outDir.mkdirs()
        val apkFile = File(outDir, "update.apk")

        URL(apkUrl).openStream().use { input ->
            FileOutputStream(apkFile).use { output ->
                input.copyTo(output)
            }
        }

        if (expectedSha256.isNotBlank()) {
            val actual = sha256(apkFile)
            if (!actual.equals(expectedSha256, ignoreCase = true)) {
                apkFile.delete()
                error("Hash OTA no coincide")
            }
        }

        val uri: Uri = FileProvider.getUriForFile(
            context,
            context.packageName + ".fileprovider",
            apkFile
        )

        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(intent)
        return apkFile.absolutePath
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { fis ->
            val buf = ByteArray(8192)
            while (true) {
                val n = fis.read(buf)
                if (n <= 0) break
                digest.update(buf, 0, n)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }
}
