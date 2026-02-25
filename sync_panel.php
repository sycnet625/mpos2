<?php
// ARCHIVO: sync_panel.php
// Panel Web de Sincronización entre Sucursales
// Versión: 1.0

session_start();
header('Content-Type: text/html; charset=utf-8');

// Cargar DB
require_once __DIR__ . '/db.php';

// Archivo de configuración
$configFile = __DIR__ . '/pos.cfg';
$config = [
    "tienda_nombre" => "MI TIENDA",
    "id_empresa" => 1,
    "id_sucursal" => 1,
    "id_almacen" => 1,
    "sync_remote_url" => "",
    "sync_api_key" => "CubaLibre2026SecureSync",
    "sync_enabled" => false
];

// Cargar configuración existente
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}

// ============================================================================
// API BACKEND (AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    // --- GUARDAR CONFIGURACIÓN ---
    if ($action === 'save_config') {
        try {
            $config['sync_remote_url'] = trim($input['sync_remote_url'] ?? '');
            $config['sync_api_key'] = trim($input['sync_api_key'] ?? '');
            $config['sync_enabled'] = (bool)($input['sync_enabled'] ?? false);
            
            // Guardar en pos.cfg
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            echo json_encode(['status' => 'success', 'msg' => 'Configuración guardada']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- TEST CONEXIÓN ---
    if ($action === 'test_connection') {
        try {
            $url = trim($input['url'] ?? '');
            $apiKey = trim($input['api_key'] ?? '');
            
            if (empty($url)) throw new Exception("URL vacía");
            
            // Intentar conectar
            $ch = curl_init($url . "?action=pull&sucursal_id=0&last_date=2099-01-01");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: $apiKey"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) throw new Exception("Error CURL: $error");
            if ($httpCode === 403) throw new Exception("API Key inválida");
            if ($httpCode !== 200) throw new Exception("Servidor respondió código: $httpCode");
            
            $data = json_decode($result, true);
            if (!$data || !isset($data['status'])) throw new Exception("Respuesta inválida del servidor");
            
            echo json_encode(['status' => 'success', 'msg' => 'Conexión exitosa', 'server_response' => $data]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- PUSH: SUBIR CAMBIOS LOCALES ---
    if ($action === 'push') {
        try {
            $MI_SUCURSAL_ID = intval($config['id_sucursal']);
            $REMOTE_URL = $config['sync_remote_url'];
            $API_KEY = $config['sync_api_key'];
            
            if (empty($REMOTE_URL)) throw new Exception("URL del servidor no configurada");
            if ($MI_SUCURSAL_ID === 0) throw new Exception("ID de sucursal no configurado");
            
            // Obtener eventos pendientes de subir
            $sqlPush = "SELECT * FROM sync_journal 
                        WHERE sincronizado = 0 
                        AND origen_sucursal_id = ? 
                        ORDER BY id ASC LIMIT 100";
            $stmtPush = $pdo->prepare($sqlPush);
            $stmtPush->execute([$MI_SUCURSAL_ID]);
            $eventosLocales = $stmtPush->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($eventosLocales) === 0) {
                echo json_encode(['status' => 'success', 'msg' => 'No hay cambios pendientes de subir', 'count' => 0]);
                exit;
            }
            
            // Enviar al servidor
            $ch = curl_init($REMOTE_URL . "?action=push");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['eventos' => $eventosLocales]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "X-API-KEY: $API_KEY"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) throw new Exception("Error CURL: $error");
            if ($httpCode !== 200) throw new Exception("Servidor respondió código: $httpCode");
            
            $response = json_decode($result, true);
            
            if ($response && ($response['status'] ?? '') === 'success') {
                // Marcar como sincronizados
                $ids = array_column($eventosLocales, 'id');
                $inQuery = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE sync_journal SET sincronizado = 1 WHERE id IN ($inQuery)")->execute($ids);
                
                echo json_encode([
                    'status' => 'success', 
                    'msg' => 'Subida completada', 
                    'count' => count($eventosLocales),
                    'server_recibidos' => $response['recibidos'] ?? 0
                ]);
            } else {
                throw new Exception($response['msg'] ?? 'Error desconocido del servidor');
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- PULL: BAJAR CAMBIOS DEL SERVIDOR ---
    if ($action === 'pull') {
        try {
            $MI_SUCURSAL_ID = intval($config['id_sucursal']);
            $REMOTE_URL = $config['sync_remote_url'];
            $API_KEY = $config['sync_api_key'];
            
            if (empty($REMOTE_URL)) throw new Exception("URL del servidor no configurada");
            if ($MI_SUCURSAL_ID === 0) throw new Exception("ID de sucursal no configurado");
            
            // Buscar última fecha de evento externo
            $stmtLast = $pdo->prepare("SELECT MAX(fecha_evento) FROM sync_journal WHERE origen_sucursal_id != ?");
            $stmtLast->execute([$MI_SUCURSAL_ID]);
            $lastDate = $stmtLast->fetchColumn();
            if (!$lastDate) $lastDate = '2020-01-01 00:00:00';
            
            // Solicitar al servidor
            $pullUrl = $REMOTE_URL . "?action=pull&sucursal_id=$MI_SUCURSAL_ID&last_date=" . urlencode($lastDate);
            
            $ch = curl_init($pullUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: $API_KEY"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) throw new Exception("Error CURL: $error");
            if ($httpCode !== 200) throw new Exception("Servidor respondió código: $httpCode");
            
            $response = json_decode($result, true);
            
            if (!$response || ($response['status'] ?? '') !== 'success') {
                throw new Exception($response['msg'] ?? 'Error desconocido del servidor');
            }
            
            $incoming = $response['eventos'] ?? [];
            
            if (count($incoming) === 0) {
                echo json_encode(['status' => 'success', 'msg' => 'Todo está actualizado', 'count' => 0]);
                exit;
            }
            
            // Procesar eventos recibidos
            $pdo->beginTransaction();
            $procesados = 0;
            
            foreach ($incoming as $evt) {
                // Verificar si ya existe
                $check = $pdo->prepare("SELECT id FROM sync_journal WHERE registro_uuid = ? AND accion = ?");
                $check->execute([$evt['registro_uuid'], $evt['accion']]);
                if ($check->fetch()) continue;
                
                // Insertar en journal local (sincronizado=1 para no re-subirlo)
                $stmtIns = $pdo->prepare("INSERT INTO sync_journal 
                    (fecha_evento, tabla, accion, registro_uuid, datos_json, origen_sucursal_id, sincronizado) 
                    VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmtIns->execute([
                    $evt['fecha_evento'], $evt['tabla'], $evt['accion'], 
                    $evt['registro_uuid'], $evt['datos_json'], $evt['origen_sucursal_id']
                ]);
                
                // Aplicar cambio en tablas locales
                aplicarCambioLocal($pdo, $evt['tabla'], $evt['accion'], json_decode($evt['datos_json'], true));
                $procesados++;
            }
            
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success', 
                'msg' => 'Descarga completada', 
                'count' => $procesados,
                'last_date' => $lastDate
            ]);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- OBTENER ESTADÍSTICAS ---
    if ($action === 'get_stats') {
        try {
            $MI_SUCURSAL_ID = intval($config['id_sucursal']);
            
            // Pendientes de subir
            $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE sincronizado = 0 AND origen_sucursal_id = ?");
            $stmt1->execute([$MI_SUCURSAL_ID]);
            $pendientesSubir = $stmt1->fetchColumn();
            
            // Total de eventos locales
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE origen_sucursal_id = ?");
            $stmt2->execute([$MI_SUCURSAL_ID]);
            $totalLocal = $stmt2->fetchColumn();
            
            // Eventos recibidos de otras sucursales
            $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE origen_sucursal_id != ?");
            $stmt3->execute([$MI_SUCURSAL_ID]);
            $recibidos = $stmt3->fetchColumn();
            
            // Última sincronización
            $stmt4 = $pdo->query("SELECT MAX(fecha_evento) FROM sync_journal WHERE sincronizado = 1");
            $ultimaSync = $stmt4->fetchColumn();
            
            // Desglose por tabla
            $stmt5 = $pdo->query("SELECT tabla, COUNT(*) as total FROM sync_journal GROUP BY tabla ORDER BY total DESC");
            $porTabla = $stmt5->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'pendientes_subir' => intval($pendientesSubir),
                'total_local' => intval($totalLocal),
                'recibidos' => intval($recibidos),
                'ultima_sync' => $ultimaSync,
                'por_tabla' => $porTabla
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- LIMPIAR JOURNAL (OPCIONAL) ---
    if ($action === 'clear_synced') {
        try {
            $dias = intval($input['dias'] ?? 30);
            $stmt = $pdo->prepare("DELETE FROM sync_journal WHERE sincronizado = 1 AND fecha_evento < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$dias]);
            $deleted = $stmt->rowCount();
            
            echo json_encode(['status' => 'success', 'msg' => "Se eliminaron $deleted registros antiguos"]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'msg' => 'Acción no reconocida']);
    exit;
}

// ============================================================================
// FUNCIÓN AUXILIAR: Aplicar cambio en BD local
// ============================================================================
function aplicarCambioLocal($pdo, $tabla, $accion, $data) {
    $tablasPermitidas = [
        // POS core
        'productos', 'stock_almacen',
        'ventas_cabecera', 'ventas_detalle', 'ventas_pagos',
        'kardex',
        'mermas_cabecera', 'mermas_detalle',
        'compras_cabecera', 'compras_detalle',
        'transferencias_cabecera', 'transferencias_detalle',
        // Catálogo
        'categorias', 'producto_variantes',
        'recetas_cabecera', 'recetas_detalle',
        // Clientes y pedidos
        'clientes', 'clientes_tienda',
        'pedidos_cabecera', 'pedidos_detalle',
        'facturas', 'facturas_detalle',
        // Tienda online
        'resenas_productos', 'restock_avisos', 'wishlist',
    ];
    if (!in_array($tabla, $tablasPermitidas)) return;

    try {
        if ($accion === 'INSERT') {
            unset($data['id']); // Usar autoincrement local
            if (empty($data)) return;
            
            $cols = array_keys($data);
            $vals = array_values($data);
            $binds = implode(',', array_fill(0, count($vals), '?'));
            $sql = "INSERT IGNORE INTO $tabla (" . implode(',', $cols) . ") VALUES ($binds)";
            $pdo->prepare($sql)->execute($vals);
        } 
        elseif ($accion === 'UPDATE') {
            $uuid = $data['uuid'] ?? null;
            unset($data['id'], $data['uuid']);
            if (empty($data) || !$uuid) return;
            
            $set = []; $vals = [];
            foreach ($data as $k => $v) { 
                $set[] = "$k = ?"; 
                $vals[] = $v; 
            }
            $vals[] = $uuid;
            
            $sql = "UPDATE $tabla SET " . implode(',', $set) . " WHERE uuid = ?";
            $pdo->prepare($sql)->execute($vals);
        }
    } catch (Exception $ex) {
        error_log("Sync Error ($tabla): " . $ex->getMessage());
    }
}

// ============================================================================
// OBTENER ESTADÍSTICAS INICIALES
// ============================================================================
$stats = ['pendientes_subir' => 0, 'total_local' => 0, 'recibidos' => 0];
try {
    $MI_SUCURSAL_ID = intval($config['id_sucursal']);
    
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE sincronizado = 0 AND origen_sucursal_id = ?");
    $stmt1->execute([$MI_SUCURSAL_ID]);
    $stats['pendientes_subir'] = $stmt1->fetchColumn();
    
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE origen_sucursal_id = ?");
    $stmt2->execute([$MI_SUCURSAL_ID]);
    $stats['total_local'] = $stmt2->fetchColumn();
    
    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM sync_journal WHERE origen_sucursal_id != ?");
    $stmt3->execute([$MI_SUCURSAL_ID]);
    $stats['recibidos'] = $stmt3->fetchColumn();
} catch (Exception $e) {
    // Tabla no existe aún
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronización | <?php echo htmlspecialchars($config['tienda_nombre']); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); min-height: 100vh; }
        .sync-card { background: rgba(255,255,255,0.95); border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        .stat-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 20px; text-align: center; }
        .stat-box.warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-box.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-box.info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-number { font-size: 2.5rem; font-weight: bold; }
        .btn-sync { padding: 15px 30px; font-size: 1.1rem; border-radius: 10px; }
        .btn-upload { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
        .btn-download { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border: none; }
        .config-section { background: #f8f9fa; border-radius: 10px; padding: 20px; }
        .log-area { background: #1a1a2e; color: #38ef7d; font-family: monospace; font-size: 0.85rem; height: 200px; overflow-y: auto; border-radius: 10px; padding: 15px; }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .online-badge { background: #38ef7d; }
        .offline-badge { background: #f5576c; }
    </style>
</head>
<body class="py-4">
<div class="container">
    
    <!-- HEADER -->
    <div class="text-center mb-4">
        <h2 class="text-white fw-bold"><i class="fas fa-cloud-upload-alt me-2"></i> Panel de Sincronización</h2>
        <p class="text-white-50">Sucursal #<?php echo $config['id_sucursal']; ?> - <?php echo htmlspecialchars($config['tienda_nombre']); ?></p>
        <span id="connectionStatus" class="badge offline-badge">Verificando conexión...</span>
    </div>
    
    <!-- ESTADÍSTICAS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-box warning">
                <i class="fas fa-cloud-upload-alt fa-2x mb-2"></i>
                <div class="stat-number" id="statPendientes"><?php echo $stats['pendientes_subir']; ?></div>
                <div>Pendientes de Subir</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <i class="fas fa-database fa-2x mb-2"></i>
                <div class="stat-number" id="statLocal"><?php echo $stats['total_local']; ?></div>
                <div>Eventos Locales</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box success">
                <i class="fas fa-cloud-download-alt fa-2x mb-2"></i>
                <div class="stat-number" id="statRecibidos"><?php echo $stats['recibidos']; ?></div>
                <div>Recibidos de Otras</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box info">
                <i class="fas fa-clock fa-2x mb-2"></i>
                <div id="statUltimaSync" class="small mt-2">-</div>
                <div>Última Sincronización</div>
            </div>
        </div>
    </div>
    
    <!-- PANEL PRINCIPAL -->
    <div class="sync-card p-4 mb-4">
        <div class="row">
            <!-- BOTONES DE ACCIÓN -->
            <div class="col-md-6 mb-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-sync-alt text-primary me-2"></i> Acciones de Sincronización</h5>
                
                <div class="d-grid gap-3">
                    <button id="btnPush" class="btn btn-upload btn-sync text-white" onclick="doPush()">
                        <i class="fas fa-cloud-upload-alt me-2"></i> SUBIR Cambios Locales
                        <span class="badge bg-light text-dark ms-2" id="badgePush"><?php echo $stats['pendientes_subir']; ?></span>
                    </button>
                    
                    <button id="btnPull" class="btn btn-download btn-sync text-white" onclick="doPull()">
                        <i class="fas fa-cloud-download-alt me-2"></i> BAJAR Cambios del Servidor
                    </button>
                    
                    <button id="btnFullSync" class="btn btn-primary btn-sync" onclick="doFullSync()">
                        <i class="fas fa-sync me-2"></i> Sincronización Completa (Push + Pull)
                    </button>
                </div>
                
                <hr class="my-4">
                
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshStats()">
                    <i class="fas fa-refresh me-1"></i> Actualizar Estadísticas
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="clearOldRecords()">
                    <i class="fas fa-trash me-1"></i> Limpiar Registros Antiguos
                </button>
            </div>
            
            <!-- LOG DE ACTIVIDAD -->
            <div class="col-md-6">
                <h5 class="fw-bold mb-3"><i class="fas fa-terminal text-success me-2"></i> Log de Actividad</h5>
                <div class="log-area" id="logArea">
                    <div class="text-muted">Esperando acciones...</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- CONFIGURACIÓN -->
    <div class="sync-card p-4">
        <h5 class="fw-bold mb-3"><i class="fas fa-cog text-secondary me-2"></i> Configuración del Servidor</h5>
        
        <div class="config-section">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label small fw-bold">URL del Servidor de Sincronización</label>
                    <input type="url" id="cfgUrl" class="form-control" 
                           value="<?php echo htmlspecialchars($config['sync_remote_url'] ?? ''); ?>"
                           placeholder="https://tu-servidor.com/api/api_sync_server.php">
                    <div class="form-text">Dirección completa del endpoint api_sync_server.php en el servidor central</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">API Key</label>
                    <input type="password" id="cfgApiKey" class="form-control" 
                           value="<?php echo htmlspecialchars($config['sync_api_key'] ?? ''); ?>"
                           placeholder="Tu clave secreta">
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="cfgEnabled" <?php echo ($config['sync_enabled'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="cfgEnabled">Sincronización Automática Habilitada</label>
                    </div>
                </div>
                <div class="col-12">
                    <button class="btn btn-success me-2" onclick="saveConfig()">
                        <i class="fas fa-save me-1"></i> Guardar Configuración
                    </button>
                    <button class="btn btn-outline-primary" onclick="testConnection()">
                        <i class="fas fa-plug me-1"></i> Probar Conexión
                    </button>
                </div>
            </div>
        </div>
        
        <!-- INFO DE SUCURSAL -->
        <div class="mt-4 p-3 bg-light rounded">
            <div class="row text-center">
                <div class="col-md-3">
                    <small class="text-muted d-block">ID Empresa</small>
                    <strong><?php echo $config['id_empresa']; ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">ID Sucursal</small>
                    <strong class="text-primary"><?php echo $config['id_sucursal']; ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">ID Almacén</small>
                    <strong><?php echo $config['id_almacen']; ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">Tienda</small>
                    <strong><?php echo htmlspecialchars($config['tienda_nombre']); ?></strong>
                </div>
            </div>
        </div>
    </div>
    
    <div class="text-center mt-4">
        <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i> Volver al Dashboard</a>
    </div>
    
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Log de actividad
    function log(msg, type = 'info') {
        const logArea = document.getElementById('logArea');
        const time = new Date().toLocaleTimeString();
        const colors = { info: '#4facfe', success: '#38ef7d', error: '#f5576c', warning: '#f093fb' };
        const icons = { info: 'ℹ️', success: '✅', error: '❌', warning: '⚠️' };
        
        logArea.innerHTML += `<div style="color:${colors[type]}">[${time}] ${icons[type]} ${msg}</div>`;
        logArea.scrollTop = logArea.scrollHeight;
    }
    
    // Verificar conexión al cargar
    document.addEventListener('DOMContentLoaded', () => {
        checkOnline();
        refreshStats();
    });
    
    function checkOnline() {
        const badge = document.getElementById('connectionStatus');
        if (navigator.onLine) {
            badge.className = 'badge online-badge';
            badge.innerHTML = '<i class="fas fa-wifi me-1"></i> Online';
        } else {
            badge.className = 'badge offline-badge';
            badge.innerHTML = '<i class="fas fa-plane me-1"></i> Offline';
        }
    }
    
    // Actualizar estadísticas
    async function refreshStats() {
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_stats' })
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                document.getElementById('statPendientes').textContent = data.pendientes_subir;
                document.getElementById('statLocal').textContent = data.total_local;
                document.getElementById('statRecibidos').textContent = data.recibidos;
                document.getElementById('badgePush').textContent = data.pendientes_subir;
                
                if (data.ultima_sync) {
                    document.getElementById('statUltimaSync').textContent = new Date(data.ultima_sync).toLocaleString();
                }
            }
        } catch (e) {
            log('Error actualizando stats: ' + e.message, 'error');
        }
    }
    
    // PUSH - Subir cambios
    async function doPush() {
        const btn = document.getElementById('btnPush');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Subiendo...';
        log('Iniciando PUSH (subir cambios locales)...');
        
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'push' })
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                log(`PUSH completado: ${data.count} eventos subidos`, 'success');
                refreshStats();
            } else {
                log('Error en PUSH: ' + data.msg, 'error');
            }
        } catch (e) {
            log('Error de conexión: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-cloud-upload-alt me-2"></i> SUBIR Cambios Locales <span class="badge bg-light text-dark ms-2" id="badgePush">0</span>';
            refreshStats();
        }
    }
    
    // PULL - Bajar cambios
    async function doPull() {
        const btn = document.getElementById('btnPull');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Descargando...';
        log('Iniciando PULL (bajar cambios del servidor)...');
        
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'pull' })
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                log(`PULL completado: ${data.count} eventos descargados`, 'success');
                refreshStats();
            } else {
                log('Error en PULL: ' + data.msg, 'error');
            }
        } catch (e) {
            log('Error de conexión: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i> BAJAR Cambios del Servidor';
        }
    }
    
    // Sincronización completa
    async function doFullSync() {
        const btn = document.getElementById('btnFullSync');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sincronizando...';
        log('=== SINCRONIZACIÓN COMPLETA INICIADA ===', 'warning');
        
        await doPush();
        await new Promise(r => setTimeout(r, 500));
        await doPull();
        
        log('=== SINCRONIZACIÓN COMPLETA FINALIZADA ===', 'success');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-2"></i> Sincronización Completa (Push + Pull)';
    }
    
    // Guardar configuración
    async function saveConfig() {
        const data = {
            action: 'save_config',
            sync_remote_url: document.getElementById('cfgUrl').value,
            sync_api_key: document.getElementById('cfgApiKey').value,
            sync_enabled: document.getElementById('cfgEnabled').checked
        };
        
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await res.json();
            
            if (result.status === 'success') {
                log('Configuración guardada correctamente', 'success');
                alert('✅ Configuración guardada');
            } else {
                log('Error guardando: ' + result.msg, 'error');
            }
        } catch (e) {
            log('Error: ' + e.message, 'error');
        }
    }
    
    // Probar conexión
    async function testConnection() {
        log('Probando conexión con el servidor...');
        
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'test_connection',
                    url: document.getElementById('cfgUrl').value,
                    api_key: document.getElementById('cfgApiKey').value
                })
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                log('¡Conexión exitosa con el servidor!', 'success');
                alert('✅ Conexión exitosa');
            } else {
                log('Error de conexión: ' + data.msg, 'error');
                alert('❌ ' + data.msg);
            }
        } catch (e) {
            log('Error: ' + e.message, 'error');
        }
    }
    
    // Limpiar registros antiguos
    async function clearOldRecords() {
        const dias = prompt('¿Eliminar registros sincronizados de más de cuántos días?', '30');
        if (!dias || isNaN(dias)) return;
        
        if (!confirm(`¿Eliminar registros sincronizados de más de ${dias} días?`)) return;
        
        try {
            const res = await fetch('sync_panel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_synced', dias: parseInt(dias) })
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                log(data.msg, 'success');
                refreshStats();
            } else {
                log('Error: ' + data.msg, 'error');
            }
        } catch (e) {
            log('Error: ' + e.message, 'error');
        }
    }
    
    // Detectar cambios de conexión
    window.addEventListener('online', checkOnline);
    window.addEventListener('offline', checkOnline);
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

