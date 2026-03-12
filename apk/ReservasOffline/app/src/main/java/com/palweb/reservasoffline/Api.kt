package com.palweb.reservasoffline

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

class AppConfig(context: Context) {
    private val prefs = context.getSharedPreferences("reservas_offline_cfg", Context.MODE_PRIVATE)

    companion object {
        const val DEFAULT_BASE_URL = "https://shop.palweb.net"
        const val DEFAULT_OTA_JSON_URL = "https://shop.palweb.net/api/reservas_offline_ota.php"
    }

    var baseUrl: String
        get() = prefs.getString("base_url", DEFAULT_BASE_URL) ?: DEFAULT_BASE_URL
        set(value) = prefs.edit().putString("base_url", value.trim().trimEnd('/')).apply()

    var apiKey: String
        get() = prefs.getString("api_key", "") ?: ""
        set(value) = prefs.edit().putString("api_key", value.trim()).apply()

    var lastBootstrapEpoch: Long
        get() = prefs.getLong("last_bootstrap", 0L)
        set(value) = prefs.edit().putLong("last_bootstrap", value).apply()

    var lastProductsSyncEpoch: Long
        get() = prefs.getLong("last_products_sync", 0L)
        set(value) = prefs.edit().putLong("last_products_sync", value).apply()

    var lastClientsSyncEpoch: Long
        get() = prefs.getLong("last_clients_sync", 0L)
        set(value) = prefs.edit().putLong("last_clients_sync", value).apply()

    var lastReservationsSyncEpoch: Long
        get() = prefs.getLong("last_reservations_sync", 0L)
        set(value) = prefs.edit().putLong("last_reservations_sync", value).apply()

    var otaJsonUrl: String
        get() = prefs.getString("ota_json_url", DEFAULT_OTA_JSON_URL) ?: DEFAULT_OTA_JSON_URL
        set(value) = prefs.edit().putString("ota_json_url", value.trim()).apply()

    var sucursalId: Int
        get() = prefs.getInt("sucursal_id", 1)
        set(value) = prefs.edit().putInt("sucursal_id", value.coerceIn(1, 9999)).apply()

    fun endpoint(path: String): String {
        val p = if (path.startsWith("/")) path else "/$path"
        return baseUrl.trimEnd('/') + p
    }
}

data class BootstrapData(
    val products: List<ProductEntity>,
    val clients: List<ClientEntity>,
    val reservations: List<ReservationWithItems>
)

data class OtaInfo(
    val versionCode: Int,
    val versionName: String,
    val apkUrl: String,
    val apkSha256: String,
    val notes: String,
)

data class SyncOperationResult(
    val ok: Boolean,
    val opId: String,
    val message: String,
    val remoteReservationId: Long? = null,
    val remoteClientId: Long? = null,
    val sinExistencia: Int? = null,
)

class OfflineApi(private val cfg: AppConfig) {
    fun downloadProductsOnly(): List<ProductEntity> {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=download_products&sucursal_id=${cfg.sucursalId}&updated_after=${cfg.lastProductsSyncEpoch / 1000}"
        val json = request(url, "GET", null)
        if (json.optString("status") != "success") error(json.optString("msg", "Error descargando productos"))
        return parseProducts(json.optJSONArray("products") ?: JSONArray()).also {
            cfg.lastProductsSyncEpoch = System.currentTimeMillis()
        }
    }

    fun downloadClientsOnly(): List<ClientEntity> {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=download_clients&sucursal_id=${cfg.sucursalId}&updated_after=${cfg.lastClientsSyncEpoch / 1000}"
        val json = request(url, "GET", null)
        if (json.optString("status") != "success") error(json.optString("msg", "Error descargando clientes"))
        return parseClients(json.optJSONArray("clients") ?: JSONArray()).also {
            cfg.lastClientsSyncEpoch = System.currentTimeMillis()
        }
    }

    fun downloadReservationsOnly(): List<ReservationWithItems> {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=download_reservations&sucursal_id=${cfg.sucursalId}&updated_after=${cfg.lastReservationsSyncEpoch / 1000}"
        val json = request(url, "GET", null)
        if (json.optString("status") != "success") error(json.optString("msg", "Error descargando reservaciones"))
        return parseReservations(json.optJSONArray("reservations") ?: JSONArray()).also {
            cfg.lastReservationsSyncEpoch = System.currentTimeMillis()
        }
    }

    fun checkOtaUpdate(otaJsonUrl: String): OtaInfo {
        val json = request(otaJsonUrl, "GET", null)
        if (json.optString("status", "success") != "success") error(json.optString("msg", "Error OTA"))
        return OtaInfo(
            versionCode = json.optInt("version_code", 0),
            versionName = json.optString("version_name", "0"),
            apkUrl = json.optString("apk_url"),
            apkSha256 = json.optString("apk_sha256", ""),
            notes = json.optString("notes", ""),
        )
    }

    fun bootstrap(): BootstrapData {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=bootstrap&sucursal_id=${cfg.sucursalId}"
        val json = request(url, "GET", null)
        if (json.optString("status") != "success") {
            error(json.optString("msg", "Error en bootstrap"))
        }
        return BootstrapData(
            products = parseProducts(json.optJSONArray("products") ?: JSONArray()),
            clients = parseClients(json.optJSONArray("clients") ?: JSONArray()),
            reservations = parseReservations(json.optJSONArray("reservations") ?: JSONArray()),
        )
    }

    fun syncOperations(ops: List<SyncQueueEntity>): List<SyncOperationResult> {
        if (ops.isEmpty()) return emptyList()
        val arr = JSONArray()
        ops.forEach {
            arr.put(
                JSONObject()
                    .put("op_id", it.id.toString())
                    .put("type", it.opType)
                    .put("reservation_uuid", it.reservationUuid)
                    .put("payload", JSONObject(it.payloadJson))
            )
        }
        val body = JSONObject().put("operations", arr)
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=sync&sucursal_id=${cfg.sucursalId}"
        val json = request(url, "POST", body)
        if (json.optString("status") != "success") {
            error(json.optString("msg", "Error sincronizando"))
        }

        val results = mutableListOf<SyncOperationResult>()
        val data = json.optJSONArray("results") ?: JSONArray()
        for (i in 0 until data.length()) {
            val r = data.getJSONObject(i)
            results += SyncOperationResult(
                ok = r.optBoolean("ok", false),
                opId = r.optString("op_id"),
                message = r.optString("msg"),
                remoteReservationId = r.optLong("remote_reservation_id").takeIf { it > 0 },
                remoteClientId = r.optLong("remote_client_id").takeIf { it > 0 },
                sinExistencia = r.optInt("sin_existencia", -1).takeIf { it >= 0 },
            )
        }
        return results
    }

    fun changesSince(epochSeconds: Long): JSONObject {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=changes_since&sucursal_id=${cfg.sucursalId}&since=$epochSeconds"
        return request(url, "GET", null)
    }

    fun reservationDetail(remoteId: Long): JSONObject {
        val url = cfg.endpoint(BuildConfig.DEFAULT_API_PATH) + "?action=reservation_detail&sucursal_id=${cfg.sucursalId}&id=$remoteId"
        return request(url, "GET", null)
    }

    fun reservationDetailParsed(remoteId: Long): ReservationWithItems? {
        val json = reservationDetail(remoteId)
        if (json.optString("status") != "success") return null
        val obj = json.optJSONObject("reservation") ?: return null
        return parseReservationObject(obj, 0)
    }

    private fun request(url: String, method: String, body: JSONObject?): JSONObject {
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15000
            readTimeout = 30000
            setRequestProperty("Content-Type", "application/json")
            val key = cfg.apiKey
            if (key.isNotBlank()) setRequestProperty("X-API-KEY", key)
            if (method == "POST") doOutput = true
        }

        if (body != null) {
            OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { it.write(body.toString()) }
        }

        val code = conn.responseCode
        val reader = if (code in 200..299) conn.inputStream.bufferedReader() else conn.errorStream?.bufferedReader()
        val text = reader?.use(BufferedReader::readText).orEmpty()
        if (text.isBlank()) {
            if (code !in 200..299) error("HTTP $code | $method $url | respuesta vacia")
            return JSONObject()
        }
        val result = try {
            JSONObject(text)
        } catch (_: Exception) {
            val preview = text.replace("\n", " ").replace("\r", " ").take(180)
            error("Respuesta no JSON | HTTP $code | $method $url | body=$preview")
        }
        if (code !in 200..299) {
            val msg = result.optString("msg", "HTTP $code")
            error("$msg | HTTP $code | $method $url")
        }
        return result
    }

    private fun parseProducts(productArr: JSONArray): List<ProductEntity> {
        val products = mutableListOf<ProductEntity>()
        for (i in 0 until productArr.length()) {
            val p = productArr.getJSONObject(i)
            products += ProductEntity(
                code = p.optString("codigo"),
                name = p.optString("nombre"),
                price = p.optDouble("precio", 0.0),
                category = p.optString("categoria", "General"),
                stock = p.optDouble("stock", 0.0),
                esServicio = p.optInt("es_servicio", 0),
                esReservable = p.optInt("es_reservable", 0),
                active = 1,
            )
        }
        return products
    }

    private fun parseClients(clientArr: JSONArray): List<ClientEntity> {
        val clients = mutableListOf<ClientEntity>()
        for (i in 0 until clientArr.length()) {
            val c = clientArr.getJSONObject(i)
            clients += ClientEntity(
                remoteId = c.optLong("id").takeIf { it > 0 },
                name = c.optString("nombre"),
                phone = c.optString("telefono"),
                address = c.optString("direccion"),
                category = c.optString("categoria", "Regular"),
                active = 1,
            )
        }
        return clients
    }

    private fun parseReservations(reservationArr: JSONArray): List<ReservationWithItems> {
        val reservations = mutableListOf<ReservationWithItems>()
        for (i in 0 until reservationArr.length()) {
            parseReservationObject(reservationArr.getJSONObject(i), i)?.let { reservations += it }
        }
        return reservations
    }

    private fun parseReservationObject(r: JSONObject, index: Int): ReservationWithItems? {
        val uuid = r.optString("local_uuid").ifBlank {
            "remote-${r.optLong("id")}-${System.currentTimeMillis()}-$index"
        }
        val res = ReservationEntity(
            localUuid = uuid,
            remoteId = r.optLong("id").takeIf { it > 0 },
            clientName = r.optString("cliente_nombre"),
            clientPhone = r.optString("cliente_telefono"),
            clientAddress = r.optString("cliente_direccion"),
            clientRemoteId = r.optLong("id_cliente").takeIf { it > 0 },
            fechaReservaEpoch = r.optLong("fecha_reserva_epoch"),
            notes = r.optString("notas"),
            metodoPago = r.optString("metodo_pago", "Efectivo"),
            canalOrigen = r.optString("canal_origen", "POS"),
            estadoPago = r.optString("estado_pago", "pendiente"),
            estadoReserva = r.optString("estado_reserva", "PENDIENTE"),
            abono = r.optDouble("abono", 0.0),
            total = r.optDouble("total", 0.0),
            costoMensajeria = r.optDouble("costo_mensajeria", 0.0),
            sinExistencia = r.optInt("sin_existencia", 0),
            updatedAtEpoch = System.currentTimeMillis(),
            serverUpdatedAtEpoch = r.optLong("server_updated_at_epoch", 0L),
            needsSync = 0,
        )
        val items = mutableListOf<ReservationItemEntity>()
        val itemArr = r.optJSONArray("items") ?: JSONArray()
        for (j in 0 until itemArr.length()) {
            val it = itemArr.getJSONObject(j)
            items += ReservationItemEntity(
                reservationUuid = uuid,
                productCode = it.optString("codigo"),
                productName = it.optString("nombre"),
                category = it.optString("categoria", "General"),
                qty = it.optDouble("cantidad", 0.0),
                price = it.optDouble("precio", 0.0),
                stockSnapshot = it.optDouble("stock", 0.0),
                esServicio = it.optInt("es_servicio", 0),
            )
        }
        return ReservationWithItems(res, items)
    }
}
