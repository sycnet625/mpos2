<?php
require_once __DIR__ . '/db.php';

const TOKEN_FILE = __DIR__ . '/api/water_sync_token.txt';

$msg = '';
$msgType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_token') {
    $newToken = trim((string)($_POST['sync_token'] ?? ''));
    if ($newToken === '') {
        $msg = 'El token no puede estar vacio.';
        $msgType = 'err';
    } else {
        $ok = @file_put_contents(TOKEN_FILE, $newToken . PHP_EOL, LOCK_EX);
        if ($ok === false) {
            $msg = 'No se pudo guardar token. Revisa permisos de escritura en /var/www/api.';
            $msgType = 'err';
        } else {
            $msg = 'Token de sincronizacion actualizado.';
            $msgType = 'ok';
        }
    }
}

$currentToken = '';
if (is_file(TOKEN_FILE) && is_readable(TOKEN_FILE)) {
    $currentToken = trim((string)file_get_contents(TOKEN_FILE));
}
$maskedToken = $currentToken === '' ? '(usando default/env)' : str_repeat('*', max(4, strlen($currentToken) - 4)) . substr($currentToken, -4);

$client = trim($_GET['client'] ?? '');
$eventType = trim($_GET['event_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [25, 50, 100, 200], true)) {
    $perPage = 50;
}
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($client !== '') {
    $where[] = 'c.device_name LIKE :client';
    $params[':client'] = '%' . $client . '%';
}
if ($eventType !== '') {
    $where[] = 'e.event_type = :event_type';
    $params[':event_type'] = $eventType;
}
if ($dateFrom !== '') {
    $where[] = 'e.received_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'e.received_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($q !== '') {
    $where[] = '(e.payload_json LIKE :q OR c.device_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
$types = [];
$accessRows = [];
$inactiveClients = [];
$kpi = [];
$latestSnapshot = null;
$snapshotData = [
    'nivel' => 'N/D',
    'cm' => 'N/D',
    'bateria' => 'N/D',
    'wifi' => 'N/D',
    'modo' => 'N/D',
    'bomba' => 'N/D',
    'ts' => null,
    'device_name' => 'N/D',
    'movil_bateria_pct' => 'N/D',
    'movil_gps_precision_m' => 'N/D',
    'movil_radio_base_codigo' => 'N/D',
    'movil_radio_rssi_dbm' => 'N/D',
    'movil_cargando' => 'N/D',
    'movil_bateria_temp_c' => 'N/D',
    'movil_lat' => 'N/D',
    'movil_lon' => 'N/D',
    'movil_altitud_m' => 'N/D',
    'movil_velocidad_mps' => 'N/D',
    'movil_heading_deg' => 'N/D',
    'movil_tipo_red' => 'N/D',
    'movil_operador' => 'N/D',
    'movil_modelo' => 'N/D',
    'android_version' => 'N/D',
    'app_version' => 'N/D',
    'build_code' => 'N/D',
    'memoria_libre_mb' => 'N/D',
    'storage_libre_mb' => 'N/D',
    'uptime_movil_seg' => 'N/D',
    'permiso_estado' => 'N/D',
    'cola_sync_pendiente' => 'N/D',
    'fallos_sync_consecutivos' => 'N/D',
    'hash_dispositivo' => 'N/D',
];
$totalRows = 0;
$totalPages = 1;
$fatalError = '';
$format = strtolower(trim($_GET['format'] ?? 'html'));

try {
    ensureWaterSyncTables($pdo);

    $kpi = [
        'total_events' => (int)$pdo->query("SELECT COUNT(*) FROM water_sync_events")->fetchColumn(),
        'events_last_hour' => (int)$pdo->query("SELECT COUNT(*) FROM water_sync_events WHERE received_at >= (NOW() - INTERVAL 1 HOUR)")->fetchColumn(),
        'clients_total' => (int)$pdo->query("SELECT COUNT(*) FROM water_sync_clients")->fetchColumn(),
        'clients_active_10m' => (int)$pdo->query("SELECT COUNT(*) FROM water_sync_clients WHERE last_seen_at >= (NOW() - INTERVAL 10 MINUTE)")->fetchColumn(),
        'failed_24h' => (int)$pdo->query("SELECT COUNT(*) FROM water_sync_access_log WHERE created_at >= (NOW() - INTERVAL 1 DAY) AND status_code >= 400")->fetchColumn(),
        'duplicates_24h' => (int)$pdo->query("SELECT COALESCE(SUM(GREATEST(COALESCE(received_count,0) - COALESCE(inserted_count,0), 0)),0) FROM water_sync_access_log WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn(),
        'discarded_24h' => (int)$pdo->query("SELECT COALESCE(SUM(COALESCE(discarded_count,0)),0) FROM water_sync_access_log WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn(),
        'avg_latency_ms_24h' => (int)$pdo->query("SELECT COALESCE(AVG(GREATEST(TIMESTAMPDIFF(SECOND, FROM_UNIXTIME(created_at_ms / 1000), received_at),0) * 1000),0) FROM water_sync_events WHERE received_at >= (NOW() - INTERVAL 1 DAY)")->fetchColumn(),
    ];

    $stmtInactive = $pdo->query("SELECT device_name, last_seen_at, TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) AS mins_offline FROM water_sync_clients WHERE last_seen_at < (NOW() - INTERVAL 10 MINUTE) ORDER BY last_seen_at ASC LIMIT 200");
    $inactiveClients = $stmtInactive->fetchAll(PDO::FETCH_ASSOC);

    $sqlCount = "
        SELECT COUNT(*)
        FROM water_sync_events e
        INNER JOIN water_sync_clients c ON c.id = e.client_id
        $whereSql
    ";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $sql = "
        SELECT
            e.id,
            c.device_name,
            e.local_event_id,
            e.event_type,
            e.payload_json,
            e.created_at_ms,
            e.received_at
        FROM water_sync_events e
        INNER JOIN water_sync_clients c ON c.id = e.client_id
        $whereSql
        ORDER BY e.id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtTypes = $pdo->query("SELECT DISTINCT event_type FROM water_sync_events ORDER BY event_type ASC");
    $types = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);

    $stmtAccess = $pdo->query("SELECT id, action, client_ip, user_agent, content_length, device_name, status_code, received_count, inserted_count, discarded_count, payload_sha256, created_at FROM water_sync_access_log ORDER BY id DESC LIMIT 300");
    $accessRows = $stmtAccess->fetchAll(PDO::FETCH_ASSOC);

    $snapWhere = ["e.event_type = 'status_snapshot'"];
    $snapParams = [];
    if ($client !== '') {
        $snapWhere[] = 'c.device_name LIKE :snap_client';
        $snapParams[':snap_client'] = '%' . $client . '%';
    }
    $snapSql = "
        SELECT c.device_name, e.payload_json, e.received_at
        FROM water_sync_events e
        INNER JOIN water_sync_clients c ON c.id = e.client_id
        WHERE " . implode(' AND ', $snapWhere) . "
        ORDER BY e.id DESC
        LIMIT 1
    ";
    $stmtSnap = $pdo->prepare($snapSql);
    $stmtSnap->execute($snapParams);
    $latestSnapshot = $stmtSnap->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($latestSnapshot) {
        $payload = json_decode((string)$latestSnapshot['payload_json'], true);
        if (is_array($payload)) {
            $meta = isset($payload['device_meta']) && is_array($payload['device_meta']) ? $payload['device_meta'] : [];
            $readMeta = function(string $key, $default = 'N/D') use ($payload, $meta) {
                if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                    return $payload[$key];
                }
                if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                    return $meta[$key];
                }
                return $default;
            };
            $snapshotData['nivel'] = (string)($payload['nivel'] ?? 'N/D');
            $snapshotData['cm'] = (string)($payload['cm'] ?? 'N/D');
            $snapshotData['bateria'] = (string)($payload['bateria'] ?? 'N/D');
            $snapshotData['wifi'] = (string)($payload['wifi'] ?? 'N/D');
            $snapshotData['modo'] = (string)($payload['modo'] ?? 'N/D');
            $snapshotData['bomba'] = (string)($payload['bomba'] ?? 'N/D');
            $snapshotData['ts'] = isset($payload['ts']) ? (int)$payload['ts'] : null;
            $snapshotData['device_name'] = (string)($latestSnapshot['device_name'] ?? 'N/D');
            $snapshotData['movil_bateria_pct'] = (string)$readMeta('movil_bateria_pct');
            $snapshotData['movil_gps_precision_m'] = (string)$readMeta('movil_gps_precision_m');
            $snapshotData['movil_radio_base_codigo'] = (string)$readMeta('movil_radio_base_codigo');
            $snapshotData['movil_radio_rssi_dbm'] = (string)$readMeta('movil_radio_rssi_dbm');
            $snapshotData['movil_cargando'] = (string)$readMeta('movil_cargando');
            $snapshotData['movil_bateria_temp_c'] = (string)$readMeta('movil_bateria_temp_c');
            $snapshotData['movil_lat'] = (string)$readMeta('movil_lat');
            $snapshotData['movil_lon'] = (string)$readMeta('movil_lon');
            $snapshotData['movil_altitud_m'] = (string)$readMeta('movil_altitud_m');
            $snapshotData['movil_velocidad_mps'] = (string)$readMeta('movil_velocidad_mps');
            $snapshotData['movil_heading_deg'] = (string)$readMeta('movil_heading_deg');
            $snapshotData['movil_tipo_red'] = (string)$readMeta('movil_tipo_red');
            $snapshotData['movil_operador'] = (string)$readMeta('movil_operador');
            $snapshotData['movil_modelo'] = (string)$readMeta('movil_modelo');
            $snapshotData['android_version'] = (string)$readMeta('android_version');
            $snapshotData['app_version'] = (string)$readMeta('app_version');
            $snapshotData['build_code'] = (string)$readMeta('build_code');
            $snapshotData['memoria_libre_mb'] = (string)$readMeta('memoria_libre_mb');
            $snapshotData['storage_libre_mb'] = (string)$readMeta('storage_libre_mb');
            $snapshotData['uptime_movil_seg'] = (string)$readMeta('uptime_movil_seg');
            $snapshotData['permiso_estado'] = (string)$readMeta('permiso_estado');
            $snapshotData['cola_sync_pendiente'] = (string)$readMeta('cola_sync_pendiente');
            $snapshotData['fallos_sync_consecutivos'] = (string)$readMeta('fallos_sync_consecutivos');
            $snapshotData['hash_dispositivo'] = (string)$readMeta('hash_dispositivo');
        }
    }
} catch (Throwable $e) {
    $fatalError = $e->getMessage();
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => $fatalError === '' ? 'ok' : 'error',
        'error' => $fatalError ?: null,
        'kpi' => $kpi,
        'alerts' => [
            'inactive_clients' => $inactiveClients,
            'count' => count($inactiveClients),
            'threshold_min' => 10
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages
        ],
        'filters' => [
            'client' => $client,
            'event_type' => $eventType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'q' => $q
        ],
        'events' => $rows,
        'access_log' => $accessRows
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function buildQuery(array $overrides = []) {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}

function ensureWaterSyncTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS water_sync_clients (
      id INT AUTO_INCREMENT PRIMARY KEY,
      device_name VARCHAR(190) NOT NULL UNIQUE,
      last_seen_at DATETIME NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS water_sync_events (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      client_id INT NOT NULL,
      local_event_id BIGINT NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      payload_json LONGTEXT NOT NULL,
      created_at_ms BIGINT NOT NULL,
      received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_client_event (client_id, local_event_id),
      KEY idx_client_received (client_id, received_at),
      CONSTRAINT fk_water_sync_client FOREIGN KEY (client_id) REFERENCES water_sync_clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS water_sync_access_log (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      action VARCHAR(32) NOT NULL,
      client_ip VARCHAR(120) NOT NULL,
      user_agent VARCHAR(255) NULL,
      content_length INT NOT NULL DEFAULT 0,
      device_name VARCHAR(190) NULL,
      status_code INT NULL,
      received_count INT NULL,
      inserted_count INT NULL,
      discarded_count INT NULL,
      payload_sha256 VARCHAR(128) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_created_at (created_at),
      KEY idx_device_name (device_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
      "ALTER TABLE water_sync_access_log ADD COLUMN received_count INT NULL AFTER status_code",
      "ALTER TABLE water_sync_access_log ADD COLUMN inserted_count INT NULL AFTER received_count",
      "ALTER TABLE water_sync_access_log ADD COLUMN discarded_count INT NULL AFTER inserted_count",
      "ALTER TABLE water_sync_access_log ADD COLUMN payload_sha256 VARCHAR(128) NULL AFTER discarded_count",
    ] as $sql) {
      try { $pdo->exec($sql); } catch (Throwable $ignored) {}
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Water Sync Dashboard</title>
  <style>
    body{font-family:Arial,sans-serif;margin:16px;background:#f6f8fb;color:#1f2937}
    h1{margin:0 0 12px}
    .card{background:#fff;border:1px solid #dbe3ef;border-radius:10px;padding:12px;margin-bottom:12px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
    input,select,button{width:100%;padding:8px;border:1px solid #c8d4e4;border-radius:6px;box-sizing:border-box}
    button{background:#0b5ed7;color:#fff;border:none;cursor:pointer}
    button.secondary{background:#6c757d}
    table{width:100%;border-collapse:collapse;background:#fff}
    th,td{border:1px solid #dbe3ef;padding:8px;text-align:left;vertical-align:top;font-size:12px}
    th{background:#edf3ff}
    .payload{max-width:520px;white-space:pre-wrap;word-break:break-word;font-family:monospace;font-size:11px}
    .pager{display:flex;gap:8px;align-items:center;margin-top:10px}
    .muted{color:#6b7280;font-size:12px}
    .ok{border-color:#b7e4c7;background:#f3fff6}
    .err{border-color:#f5b7b1;background:#fff6f6}
    .kpi{font-size:20px;font-weight:700}
    .kpi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
    .kpi-card{
      position:relative;
      border-radius:14px;
      padding:14px;
      color:#fff;
      border:1px solid rgba(255,255,255,.2);
      box-shadow:0 10px 24px rgba(15,23,42,.18);
      overflow:hidden;
      transition:transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover{
      transform:translateY(-2px);
      box-shadow:0 14px 28px rgba(15,23,42,.24);
    }
    .kpi-card::after{
      content:'';
      position:absolute;
      inset:auto -20% -35% auto;
      width:140px;
      height:140px;
      border-radius:50%;
      background:rgba(255,255,255,.14);
      filter:blur(2px);
      pointer-events:none;
    }
    .kpi-level{background:linear-gradient(140deg,#0ea5e9,#2563eb)}
    .kpi-cm{background:linear-gradient(140deg,#0f766e,#14b8a6)}
    .kpi-bat{background:linear-gradient(140deg,#16a34a,#22c55e)}
    .kpi-wifi{background:linear-gradient(140deg,#7c3aed,#a78bfa)}
    .kpi-mode{background:linear-gradient(140deg,#f59e0b,#fb7185)}
    .kpi-pump{background:linear-gradient(140deg,#dc2626,#f97316)}
    .kpi-mobile{background:linear-gradient(140deg,#0f766e,#0ea5e9)}
    .kpi-gray{background:linear-gradient(140deg,#334155,#64748b)}
    .kpi-clickable{cursor:pointer}
    .kpi-label{font-size:12px;color:rgba(255,255,255,.85);font-weight:600;letter-spacing:.2px}
    .kpi-value{font-size:26px;font-weight:800;margin-top:6px;line-height:1.05}
    .kpi-sub{font-size:11px;color:#64748b;margin-top:8px}
  </style>
</head>
<body>
  <h1>Water Sync Dashboard</h1>
  <?php if ($msg !== ''): ?><div class="card <?= $msgType === 'ok' ? 'ok' : 'err' ?>"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($fatalError !== ''): ?><div class="card err"><strong>Error:</strong> <?= h($fatalError) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" class="grid">
      <input type="hidden" name="action" value="save_token">
      <div><label>Token actual</label><input type="text" value="<?= h($maskedToken) ?>" disabled></div>
      <div><label>Nuevo token sync</label><input type="text" name="sync_token" placeholder="nuevo token seguro"></div>
      <div><label>&nbsp;</label><button type="submit">Guardar token</button></div>
      <div><label>&nbsp;</label><a href="<?= h(buildQuery()) ?>"><button type="button" class="secondary">Refrescar datos</button></a></div>
    </form>
    <div style="margin-top:8px;">
      <a href="/api/water_sync.php?action=cleanup&days=90" target="_blank"><button type="button" class="secondary">Ejecutar limpieza 90 dias</button></a>
      <span class="muted">Retencion automatica activa cada ~6h en endpoint push.</span>
    </div>
  </div>

  <div class="card">
    <div class="grid">
      <div><div class="muted">Eventos totales</div><div class="kpi"><?= h($kpi['total_events'] ?? 0) ?></div></div>
      <div><div class="muted">Eventos (1h)</div><div class="kpi"><?= h($kpi['events_last_hour'] ?? 0) ?></div></div>
      <div><div class="muted">Clientes activos (10m)</div><div class="kpi"><?= h($kpi['clients_active_10m'] ?? 0) ?>/<?= h($kpi['clients_total'] ?? 0) ?></div></div>
      <div><div class="muted">Errores sync (24h)</div><div class="kpi"><?= h($kpi['failed_24h'] ?? 0) ?></div></div>
      <div><div class="muted">Duplicados (24h)</div><div class="kpi"><?= h($kpi['duplicates_24h'] ?? 0) ?></div></div>
      <div><div class="muted">Descartados (24h)</div><div class="kpi"><?= h($kpi['discarded_24h'] ?? 0) ?></div></div>
      <div><div class="muted">Latencia media (24h)</div><div class="kpi"><?= h($kpi['avg_latency_ms_24h'] ?? 0) ?> ms</div></div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;">KPI Último status_snapshot</h3>
    <div class="kpi-cards">
      <div class="kpi-card kpi-level">
        <div class="kpi-label">Nivel</div>
        <div class="kpi-value"><?= h($snapshotData['nivel']) ?>%</div>
      </div>
      <div class="kpi-card kpi-cm">
        <div class="kpi-label">Distancia</div>
        <div class="kpi-value"><?= h($snapshotData['cm']) ?> cm</div>
      </div>
      <div class="kpi-card kpi-bat">
        <div class="kpi-label">Batería</div>
        <div class="kpi-value"><?= h($snapshotData['bateria']) ?> V</div>
      </div>
      <div class="kpi-card kpi-wifi">
        <div class="kpi-label">WiFi RSSI</div>
        <div class="kpi-value"><?= h($snapshotData['wifi']) ?> dBm</div>
      </div>
      <div class="kpi-card kpi-mode">
        <div class="kpi-label">Modo</div>
        <div class="kpi-value"><?= h(strtoupper((string)$snapshotData['modo'])) ?></div>
      </div>
      <div class="kpi-card kpi-pump">
        <div class="kpi-label">Bomba</div>
        <div class="kpi-value"><?= h((string)$snapshotData['bomba'] === '1' ? 'ENCENDIDA' : ((string)$snapshotData['bomba'] === '0' ? 'APAGADA' : $snapshotData['bomba'])) ?></div>
      </div>
    </div>
    <div class="kpi-sub">
      Cliente: <?= h($snapshotData['device_name']) ?> |
      TS móvil: <?= h($snapshotData['ts'] ?? 'N/D') ?> |
      Último recibido: <?= h($latestSnapshot['received_at'] ?? 'N/D') ?>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0;">KPI Móvil / Device Meta</h3>
    <div class="kpi-cards">
      <div class="kpi-card kpi-mobile"><div class="kpi-label">Batería móvil</div><div class="kpi-value"><?= h($snapshotData['movil_bateria_pct']) ?>%</div></div>
      <div class="kpi-card kpi-mobile"><div class="kpi-label">Cargando</div><div class="kpi-value"><?= h((string)$snapshotData['movil_cargando'] === '1' ? 'SI' : ((string)$snapshotData['movil_cargando'] === '0' ? 'NO' : $snapshotData['movil_cargando'])) ?></div></div>
      <div class="kpi-card kpi-mobile"><div class="kpi-label">Temp batería</div><div class="kpi-value"><?= h($snapshotData['movil_bateria_temp_c']) ?> °C</div></div>
      <div class="kpi-card kpi-mobile"><div class="kpi-label">Precisión GPS</div><div class="kpi-value"><?= h($snapshotData['movil_gps_precision_m']) ?> m</div></div>
      <div class="kpi-card kpi-mobile"><div class="kpi-label">Radio Base</div><div class="kpi-value"><?= h($snapshotData['movil_radio_base_codigo']) ?></div></div>
      <div class="kpi-card kpi-mobile"><div class="kpi-label">RSSI Celular</div><div class="kpi-value"><?= h($snapshotData['movil_radio_rssi_dbm']) ?> dBm</div></div>
      <div
        class="kpi-card kpi-gray kpi-clickable"
        id="kpi-latlon"
        data-lat="<?= h($snapshotData['movil_lat']) ?>"
        data-lon="<?= h($snapshotData['movil_lon']) ?>"
        title="Abrir ubicación en Google Maps"
      >
        <div class="kpi-label">Lat / Lon</div>
        <div class="kpi-value"><?= h($snapshotData['movil_lat']) ?><br><?= h($snapshotData['movil_lon']) ?></div>
      </div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Alt / Vel / Rumbo</div><div class="kpi-value"><?= h($snapshotData['movil_altitud_m']) ?>m<br><?= h($snapshotData['movil_velocidad_mps']) ?>m/s<br><?= h($snapshotData['movil_heading_deg']) ?>°</div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Red / Operador</div><div class="kpi-value"><?= h($snapshotData['movil_tipo_red']) ?><br><?= h($snapshotData['movil_operador']) ?></div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Modelo / Android</div><div class="kpi-value"><?= h($snapshotData['movil_modelo']) ?><br>Android <?= h($snapshotData['android_version']) ?></div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">App / Build</div><div class="kpi-value"><?= h($snapshotData['app_version']) ?> / <?= h($snapshotData['build_code']) ?></div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Mem / Storage libre</div><div class="kpi-value"><?= h($snapshotData['memoria_libre_mb']) ?>MB<br><?= h($snapshotData['storage_libre_mb']) ?>MB</div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Uptime móvil</div><div class="kpi-value"><?= h($snapshotData['uptime_movil_seg']) ?>s</div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Cola / Fallos sync</div><div class="kpi-value"><?= h($snapshotData['cola_sync_pendiente']) ?> / <?= h($snapshotData['fallos_sync_consecutivos']) ?></div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Permisos</div><div class="kpi-value"><?= h($snapshotData['permiso_estado']) ?></div></div>
      <div class="kpi-card kpi-gray"><div class="kpi-label">Hash dispositivo</div><div class="kpi-value"><?= h($snapshotData['hash_dispositivo']) ?></div></div>
    </div>
  </div>

  <div class="card <?= count($inactiveClients) > 0 ? 'err' : 'ok' ?>">
    <strong>Alertas heartbeat (sin reportar &gt; 10 min):</strong>
    <?php if (!$inactiveClients): ?>
      <div>Sin alertas activas.</div>
    <?php else: ?>
      <table><thead><tr><th>Cliente</th><th>Ultimo seen</th><th>Offline (min)</th></tr></thead><tbody>
      <?php foreach ($inactiveClients as $ic): ?>
        <tr><td><?= h($ic['device_name']) ?></td><td><?= h($ic['last_seen_at']) ?></td><td><?= h($ic['mins_offline']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </div>

  <div class="card">
    <form method="get" class="grid">
      <div><label>Cliente</label><input type="text" name="client" value="<?= h($client) ?>" placeholder="modelo o id telefono"></div>
      <div><label>Tipo de evento</label><select name="event_type"><option value="">Todos</option><?php foreach ($types as $t): ?><option value="<?= h($t) ?>" <?= $eventType === $t ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
      <div><label>Desde</label><input type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
      <div><label>Hasta</label><input type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
      <div><label>Buscar en JSON</label><input type="text" name="q" value="<?= h($q) ?>" placeholder="nivel, bomba, modo"></div>
      <div><label>Filas</label><select name="per_page"><?php foreach ([25,50,100,200] as $pp): ?><option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option><?php endforeach; ?></select></div>
      <div><label>&nbsp;</label><button type="submit">Filtrar</button></div>
    </form>
  </div>

  <div class="card"><strong>Total:</strong> <?= h($totalRows) ?> registros <span class="muted">| Página <?= h($page) ?> de <?= h($totalPages) ?></span></div>

  <table>
    <thead><tr><th>ID</th><th>Cliente</th><th>Local ID</th><th>Evento</th><th>Creado móvil (ms)</th><th>Recibido servidor</th><th>Payload JSON</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7">Sin resultados</td></tr><?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['id']) ?></td><td><?= h($r['device_name']) ?></td><td><?= h($r['local_event_id']) ?></td><td><?= h($r['event_type']) ?></td><td><?= h($r['created_at_ms']) ?></td><td><?= h($r['received_at']) ?></td><td class="payload"><?= h($r['payload_json']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="pager">
    <?php if ($page > 1): ?><a href="<?= h(buildQuery(['page' => $page - 1])) ?>"><button type="button" class="secondary">Anterior</button></a><?php endif; ?>
    <?php if ($page < $totalPages): ?><a href="<?= h(buildQuery(['page' => $page + 1])) ?>"><button type="button" class="secondary">Siguiente</button></a><?php endif; ?>
  </div>

  <div class="card" style="margin-top:14px;">
    <h3 style="margin:0 0 8px 0;">Log de conexiones Sync (ultimas 300)</h3>
    <table>
      <thead><tr><th>ID</th><th>Fecha</th><th>Action</th><th>IP</th><th>Device</th><th>Status</th><th>Bytes</th><th>Recv/Ins/Disc</th><th>SHA256</th><th>User-Agent</th></tr></thead>
      <tbody>
        <?php if (!$accessRows): ?><tr><td colspan="10">Sin conexiones registradas</td></tr><?php else: foreach ($accessRows as $a): ?>
        <tr>
          <td><?= h($a['id']) ?></td><td><?= h($a['created_at']) ?></td><td><?= h($a['action']) ?></td><td><?= h($a['client_ip']) ?></td><td><?= h($a['device_name'] ?: '-') ?></td><td><?= h($a['status_code'] ?? '-') ?></td><td><?= h($a['content_length']) ?></td><td><?= h(($a['received_count'] ?? '-') . '/' . ($a['inserted_count'] ?? '-') . '/' . ($a['discarded_count'] ?? '-')) ?></td><td><?= h($a['payload_sha256'] ?: '-') ?></td><td><?= h($a['user_agent']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <script>
    (function () {
      var card = document.getElementById('kpi-latlon');
      if (!card) return;
      card.addEventListener('click', function () {
        var lat = (card.getAttribute('data-lat') || '').trim();
        var lon = (card.getAttribute('data-lon') || '').trim();
        if (!lat || !lon || lat === 'N/D' || lon === 'N/D') {
          alert('No hay coordenadas válidas para abrir en Google Maps.');
          return;
        }
        var url = 'https://www.google.com/maps?q=' + encodeURIComponent(lat + ',' + lon) + '&z=17';
        window.open(url, '_blank', 'noopener,noreferrer');
      });
    })();
  </script>
</body>
</html>
