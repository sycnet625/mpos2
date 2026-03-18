package com.palweb.reservasoffline

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.BufferedInputStream
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

class OtaInstallException(message: String) : Exception(message)

object OtaInstaller {
    fun downloadAndInstall(
        context: Context,
        apkUrl: String,
        expectedSha256: String,
        onProgress: ((downloadedBytes: Long, totalBytes: Long) -> Unit)? = null,
    ): String {
        val outDir = File(context.getExternalFilesDir(null), "ota")
        if (!outDir.exists() && !outDir.mkdirs()) {
            throw OtaInstallException("No se pudo crear carpeta OTA: ${outDir.absolutePath}")
        }
        val apkFile = File(outDir, "update.apk")
        val partFile = File(outDir, "update.apk.part")
        if (partFile.exists()) partFile.delete()
        if (apkFile.exists()) apkFile.delete()

        val conn = (URL(apkUrl).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 15000
            readTimeout = 60000
            instanceFollowRedirects = true
        }

        try {
            val code = conn.responseCode
            if (code !in 200..299) {
                val body = conn.errorStream?.bufferedReader()?.use { it.readText() }?.replace("\n", " ")?.take(180).orEmpty()
                throw OtaInstallException("Descarga OTA fallo | HTTP $code | $body")
            }

            val totalBytes = conn.contentLengthLong
            BufferedInputStream(conn.inputStream).use { input ->
                FileOutputStream(partFile).use { output ->
                    val buffer = ByteArray(8192)
                    var downloaded = 0L
                    while (true) {
                        val read = input.read(buffer)
                        if (read <= 0) break
                        output.write(buffer, 0, read)
                        downloaded += read
                        onProgress?.invoke(downloaded, totalBytes)
                    }
                    output.flush()
                }
            }
        } catch (e: Exception) {
            partFile.delete()
            throw e
        } finally {
            conn.disconnect()
        }

        if (!partFile.exists() || partFile.length() <= 0L) {
            throw OtaInstallException("Descarga OTA incompleta o vacia")
        }
        if (!partFile.renameTo(apkFile)) {
            partFile.copyTo(apkFile, overwrite = true)
            partFile.delete()
        }

        if (expectedSha256.isNotBlank()) {
            val actual = sha256(apkFile)
            if (!actual.equals(expectedSha256, ignoreCase = true)) {
                apkFile.delete()
                throw OtaInstallException("Hash OTA no coincide | esperado=$expectedSha256 | actual=$actual")
            }
        }

        if (!apkFile.exists()) {
            throw OtaInstallException("APK OTA no existe: ${apkFile.absolutePath}")
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
