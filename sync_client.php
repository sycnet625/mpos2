
<?php
// ARCHIVO: sync_client.php (EJECUTAR EN LOCAL/WINDOWS)
// Sincroniza la BD Local con AWS. 
// Uso: php sync_client.php

// Evitar timeout en conexiones lentas
set_time_limit(300); 
ini_set('memory_limit', '256M');

// Cargar DB y Configuración Local
require_once __DIR__ . '/db.php';
$configFile = __DIR__ . '/pos.cfg';

// LEER ID DE SUCURSAL LOCAL
$config = json_decode(file_get_contents($configFile), true);
$MI_SUCURSAL_ID = intval($config['id_sucursal'] ?? 0);

if ($MI_SUCURSAL_ID === 0) die("❌ Error: No se detectó ID de sucursal en pos.cfg\n");

// CONFIGURACIÓN REMOTA
$REMOTE_URL = "http://tu-servidor-aws.com/api/api_sync_server.php"; // <--- PON TU URL REAL AQUÍ
$API_KEY = "CubaLibre2026SecureSync"; // <--- DEBE COINCIDIR CON EL SERVIDOR

echo "--- SINCRONIZACIÓN SUCURSAL #$MI_SUCURSAL_ID (" . date('H:i:s') . ") ---\n";

// 1. CHEQUEO DE INTERNET (Ping ligero)
if (!check_internet()) {
    die("⚠️ Modo Offline: No hay conexión a internet.\n");
}

try {
    // =======================================================================
    // FASE A: PUSH (SUBIR MIS CAMBIOS A LA NUBE)
    // =======================================================================
    
    // Seleccionamos solo los eventos generados por ESTA sucursal que no estén subidos
    // IMPORTANTE: Filtrar por 'origen_sucursal_id' evita devolver datos que bajamos de otros
    $sqlPush = "SELECT * FROM sync_journal 
                WHERE sincronizado = 0 
                AND origen_sucursal_id = ? 
                ORDER BY id ASC LIMIT 50";
    
    $stmtPush = $pdo->prepare($sqlPush);
    $stmtPush->execute([$MI_SUCURSAL_ID]);
    $eventosLocales = $stmtPush->fetchAll(PDO::FETCH_ASSOC);

    if (count($eventosLocales) > 0) {
        echo "📤 Subiendo " . count($eventosLocales) . " cambios locales...\n";
        
        $response = send_request($REMOTE_URL . "?action=push", ['eventos' => $eventosLocales]);
        
        if ($response && ($response['status'] ?? '') === 'success') {
            // Marcar como sincronizados
            $ids = array_column($eventosLocales, 'id');
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE sync_journal SET sincronizado = 1 WHERE id IN ($inQuery)")->execute($ids);
            echo "✅ Subida completada.\n";
        } else {
            echo "❌ Error en subida: " . ($response['msg'] ?? 'Error desconocido') . "\n";
        }
    } else {
        echo "✅ Nada nuevo que subir.\n";
    }

    // =======================================================================
    // FASE B: PULL (BAJAR CAMBIOS GLOBALES)
    // =======================================================================
    
    // Buscar la fecha del último evento que recibimos de AFUERA (no mío)
    $stmtLast = $pdo->prepare("SELECT MAX(fecha_evento) FROM sync_journal WHERE origen_sucursal_id != ?");
    $stmtLast->execute([$MI_SUCURSAL_ID]);
    $lastDate = $stmtLast->fetchColumn();
    
    if (!$lastDate) $lastDate = '2020-01-01 00:00:00';

    echo "📥 Buscando actualizaciones desde: $lastDate\n";

    $pullUrl = $REMOTE_URL . "?action=pull&sucursal_id=$MI_SUCURSAL_ID&last_date=" . urlencode($lastDate);
    $response = send_request($pullUrl, null, 'GET');

    if ($response && ($response['status'] ?? '') === 'success' && !empty($response['eventos'])) {
        $incoming = $response['eventos'];
        echo "📥 Procesando " . count($incoming) . " eventos externos...\n";

        $pdo->beginTransaction();

        foreach ($incoming as $evt) {
            // 1. Verificar si ya lo tenemos (por si acaso)
            $check = $pdo->prepare("SELECT id FROM sync_journal WHERE registro_uuid = ? AND accion = ?");
            $check->execute([$evt['registro_uuid'], $evt['accion']]);
            if ($check->fetch()) continue;

            // 2. Insertar en Journal Local (Marcado como sincronizado=1 para no re-subirlo)
            $stmtIns = $pdo->prepare("INSERT INTO sync_journal (fecha_evento, tabla, accion, registro_uuid, datos_json, origen_sucursal_id, sincronizado) 
                                      VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmtIns->execute([
                $evt['fecha_evento'], $evt['tabla'], $evt['accion'], 
                $evt['registro_uuid'], $evt['datos_json'], $evt['origen_sucursal_id']
            ]);

            // 3. Aplicar cambio en Tablas Locales
            // NOTA: Al hacer esto, el Trigger local saltará y creará OTRA entrada en el journal.
            // PERO, el trigger guardará el 'origen_sucursal_id' que viene en el JSON (que es diferente al mío).
            // Por tanto, mi filtro de PUSH (AND origen_sucursal_id = MI_ID) ignorará ese registro duplicado.
            aplicarCambioLocal($pdo, $evt['tabla'], $evt['accion'], json_decode($evt['datos_json'], true));
        }

        $pdo->commit();
        echo "✅ Base de datos actualizada correctamente.\n";
    } else {
        echo "✅ Todo actualizado.\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ ERROR FATAL: " . $e->getMessage() . "\n";
}

// ===========================================================================
// FUNCIONES AUXILIARES
// ===========================================================================

function aplicarCambioLocal($pdo, $tabla, $accion, $data) {
    // Misma lógica que servidor, adaptada a PDO local
    $tablasPermitidas = ['productos', 'ventas_cabecera', 'kardex', 'mermas_cabecera', 'clientes'];
    if (!in_array($tabla, $tablasPermitidas)) return;

    try {
        if ($accion === 'INSERT') {
            unset($data['id']); // Usar autoincrement local
            $cols = array_keys($data);
            $vals = array_values($data);
            $binds = implode(',', array_fill(0, count($vals), '?'));
            $sql = "INSERT IGNORE INTO $tabla (" . implode(',', $cols) . ") VALUES ($binds)";
            $pdo->prepare($sql)->execute($vals);
        } 
        elseif ($accion === 'UPDATE') {
            $uuid = $data['uuid'];
            unset($data['id'], $data['uuid']);
            if(empty($data)) return;
            
            $set = []; $vals = [];
            foreach ($data as $k => $v) { $set[] = "$k = ?"; $vals[] = $v; }
            $vals[] = $uuid;
            
            $sql = "UPDATE $tabla SET " . implode(',', $set) . " WHERE uuid = ?";
            $pdo->prepare($sql)->execute($vals);
        }
    } catch (Exception $ex) {
        echo "   [!] Error aplicando SQL ($tabla): " . $ex->getMessage() . "\n";
    }
}

function send_request($url, $data = null, $method = 'POST') {
    global $API_KEY;
    $ch = curl_init($url);
    
    $headers = [
        "Content-Type: application/json",
        "X-API-KEY: $API_KEY"
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout corto para detectar caídas rápido
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo "   [!] Error CURL: " . curl_error($ch) . "\n";
        return null;
    }
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "   [!] Servidor respondió código: $httpCode\n";
        // echo "   Response: $result\n"; // Descomentar para debug
        return null;
    }

    return json_decode($result, true);
}

function check_internet() {
    $connected = @fsockopen("www.google.com", 80); 
    if ($connected){
        fclose($connected);
        return true;
    }
    return false;
}
?>

