<?php
// ARCHIVO: api_sync_server.php (ALOJAR EN AWS)
// Recibe cambios de las sucursales y distribuye actualizaciones globales.

header('Content-Type: application/json');
require_once '../db.php'; // Asegúrate de que apunte a tu conexión DB en AWS

// 🔒 SEGURIDAD: Token simple para evitar accesos no autorizados
$API_KEY = "CubaLibre2026SecureSync"; // CAMBIA ESTO por una clave compleja

// Verificar API Key
$headers = getallheaders();
$clientKey = $headers['X-API-KEY'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');

if ($clientKey !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Acceso denegado: API Key inválida']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    // -----------------------------------------------------------------------
    // ACCIÓN 1: PUSH (RECIBIR DATOS DESDE UNA SUCURSAL)
    // -----------------------------------------------------------------------
    if ($action === 'push') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['eventos'])) {
            echo json_encode(['status' => 'success', 'recibidos' => 0, 'msg' => 'Nada que procesar']);
            exit;
        }

        $pdo->beginTransaction();
        $contador = 0;

        foreach ($input['eventos'] as $evt) {
            // 1. IDEMPOTENCIA: Verificar si este UUID de evento ya fue procesado para evitar duplicados
            // Usamos registro_uuid + accion como firma única
            $stmtCheck = $pdo->prepare("SELECT id FROM sync_journal WHERE registro_uuid = ? AND accion = ? AND tabla = ?");
            $stmtCheck->execute([$evt['registro_uuid'], $evt['accion'], $evt['tabla']]);
            if ($stmtCheck->fetch()) {
                continue; // Ya existe, saltamos
            }

            // 2. INSERTAR EN EL JOURNAL DE AWS (Para que otras sucursales lo descarguen)
            // Marcamos sincronizado=1 porque ya está en la nube
            $sqlJ = "INSERT INTO sync_journal (fecha_evento, tabla, accion, registro_uuid, datos_json, origen_sucursal_id, sincronizado) 
                     VALUES (?, ?, ?, ?, ?, ?, 1)";
            $stmtJ = $pdo->prepare($sqlJ);
            $stmtJ->execute([
                $evt['fecha_evento'], 
                $evt['tabla'], 
                $evt['accion'], 
                $evt['registro_uuid'], 
                $evt['datos_json'], 
                $evt['origen_sucursal_id']
            ]);

            // 3. APLICAR EL CAMBIO REAL EN LAS TABLAS DE AWS
            aplicarCambioEnBD($pdo, $evt['tabla'], $evt['accion'], json_decode($evt['datos_json'], true));
            $contador++;
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'recibidos' => $contador]);
    }

    // -----------------------------------------------------------------------
    // ACCIÓN 2: PULL (ENVIAR DATOS A UNA SUCURSAL)
    // -----------------------------------------------------------------------
    elseif ($action === 'pull') {
        $mi_sucursal_id = intval($_GET['sucursal_id'] ?? 0);
        $last_date = $_GET['last_date'] ?? '2000-01-01 00:00:00';

        if ($mi_sucursal_id === 0) throw new Exception("ID de sucursal obligatorio");

        // Seleccionar eventos ocurridos DESPUÉS de la última fecha que tiene el cliente
        // Y EXCLUYENDO los eventos que generó esa misma sucursal (para no devolvérselos)
        $sql = "SELECT * FROM sync_journal 
                WHERE fecha_evento > ? 
                AND origen_sucursal_id != ? 
                ORDER BY fecha_evento ASC 
                LIMIT 50"; // Enviamos de 50 en 50 para no saturar la red lenta
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$last_date, $mi_sucursal_id]);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'eventos' => $eventos]);
    } 
    else {
        throw new Exception("Acción desconocida");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}

// --- FUNCIÓN AUXILIAR: Convierte JSON a SQL ---
function aplicarCambioEnBD($pdo, $tabla, $accion, $data) {
    // Lista blanca de tablas permitidas para seguridad
    $tablasPermitidas = ['productos', 'ventas_cabecera', 'kardex', 'mermas_cabecera', 'clientes'];
    if (!in_array($tabla, $tablasPermitidas)) return;

    if ($accion === 'INSERT') {
        // Quitamos el ID autoincremental local, dejamos que AWS genere el suyo
        unset($data['id']); 
        
        $columnas = array_keys($data);
        $valores = array_values($data);
        $placeholders = implode(',', array_fill(0, count($valores), '?'));
        
        // Usamos INSERT IGNORE para evitar errores si el registro ya llegó por otra vía
        $sql = "INSERT IGNORE INTO $tabla (" . implode(',', $columnas) . ") VALUES ($placeholders)";
        $pdo->prepare($sql)->execute($valores);
    } 
    elseif ($accion === 'UPDATE') {
        $uuid = $data['uuid'];
        unset($data['id'], $data['uuid']); // No actualizamos ID ni UUID
        
        if (empty($data)) return; // Nada que actualizar

        $setPart = [];
        $valores = [];
        foreach ($data as $k => $v) {
            $setPart[] = "$k = ?";
            $valores[] = $v;
        }
        $valores[] = $uuid; // Para el WHERE
        
        $sql = "UPDATE $tabla SET " . implode(', ', $setPart) . " WHERE uuid = ?";
        $pdo->prepare($sql)->execute($valores);
    }
    // DELETE podría implementarse similar si fuera necesario
}
?>

