<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

const DEFAULT_SYNC_TOKEN = 'CHANGE_ME_SYNC_TOKEN';
const TOKEN_FILE = __DIR__ . '/water_sync_token.txt';
const RETENTION_DAYS_DEFAULT = 90;
const RETENTION_CHECK_INTERVAL_SEC = 21600;
const RETENTION_MARK_FILE = '/tmp/water_sync_retention_last.txt';

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$requestAction = $_GET['action'] ?? 'push';
$payloadSha = substr((string)($_SERVER['HTTP_X_PAYLOAD_SHA256'] ?? ''), 0, 128);

ensureAccessLogTable($pdo);
logIncomingSyncAccess(
    $pdo,
    $requestAction,
    $clientIp,
    $userAgent,
    $contentLength,
    null,
    null,
    null,
    null,
    null,
    $payloadSha
);

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$serverToken = loadServerToken();
if (!hash_equals($serverToken, $apiKey)) {
    logIncomingSyncAccess(
        $pdo,
        $requestAction,
        $clientIp,
        $userAgent,
        $contentLength,
        null,
        403,
        null,
        null,
        null,
        $payloadSha
    );
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'invalid api key']);
    exit;
}

$action = $requestAction;
if ($action !== 'push' && $action !== 'cleanup') {
    logIncomingSyncAccess(
        $pdo,
        $requestAction,
        $clientIp,
        $userAgent,
        $contentLength,
        null,
        400,
        null,
        null,
        null,
        $payloadSha
    );
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'unsupported action']);
    exit;
}

ensureTables($pdo);

if ($action === 'cleanup') {
    $days = (int)($_GET['days'] ?? RETENTION_DAYS_DEFAULT);
    $days = max(7, min(365, $days));
    $deleted = cleanupOldData($pdo, $days);
    echo json_encode(['status' => 'success', 'action' => 'cleanup', 'days' => $days, 'deleted_events' => $deleted]);
    exit;
}

runRetentionIfDue($pdo);

$raw = file_get_contents('php://input');
$contentEncoding = strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '');
if (strpos($contentEncoding, 'gzip') !== false) {
    $decoded = gzdecode($raw);
    if ($decoded !== false) {
        $raw = $decoded;
    }
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    logIncomingSyncAccess(
        $pdo,
        $requestAction,
        $clientIp,
        $userAgent,
        $contentLength,
        null,
        400,
        null,
        null,
        null,
        $payloadSha
    );
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'invalid json']);
    exit;
}

$deviceName = trim((string)($input['device_name'] ?? 'unknown-device'));
$events = $input['events'] ?? [];
if (!is_array($events)) $events = [];

try {
    $pdo->beginTransaction();

    $clientId = upsertClient($pdo, $deviceName);

    $inserted = 0;
    $discarded = 0;
    $acked = [];

    $sql = "INSERT INTO water_sync_events
            (client_id, local_event_id, event_type, payload_json, created_at_ms)
            VALUES (:client_id, :local_event_id, :event_type, :payload_json, :created_at_ms)
            ON DUPLICATE KEY UPDATE id = id";
    $stmt = $pdo->prepare($sql);

    foreach ($events as $ev) {
        $localId = isset($ev['local_id']) ? (int)$ev['local_id'] : 0;
        if ($localId <= 0) {
            $discarded++;
            continue;
        }

        $eventType = substr((string)($ev['event_type'] ?? 'unknown'), 0, 60);
        $createdAt = isset($ev['created_at']) ? (int)$ev['created_at'] : (int)(microtime(true) * 1000);

        $payloadObj = $ev['payload_json'] ?? [];
        if (!is_array($payloadObj)) {
            $payloadObj = ['raw' => (string)$payloadObj];
        }
        $payloadJson = json_encode($payloadObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt->execute([
            ':client_id' => $clientId,
            ':local_event_id' => $localId,
            ':event_type' => $eventType,
            ':payload_json' => $payloadJson,
            ':created_at_ms' => $createdAt,
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        }
        $acked[] = $localId;
    }

    $pdo->commit();
    logIncomingSyncAccess(
        $pdo,
        $requestAction,
        $clientIp,
        $userAgent,
        $contentLength,
        $deviceName,
        200,
        count($events),
        $inserted,
        $discarded,
        $payloadSha
    );

    echo json_encode([
        'status' => 'success',
        'device_name' => $deviceName,
        'received' => count($events),
        'inserted' => $inserted,
        'discarded' => $discarded,
        'ack_local_ids' => $acked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logIncomingSyncAccess(
        $pdo,
        $requestAction,
        $clientIp,
        $userAgent,
        $contentLength,
        $deviceName ?? null,
        500,
        count($events ?? []),
        null,
        null,
        $payloadSha
    );
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}

function upsertClient(PDO $pdo, string $deviceName): int {
    $sql = "INSERT INTO water_sync_clients (device_name, last_seen_at)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_seen_at = NOW()";
    $pdo->prepare($sql)->execute([$deviceName]);

    $stmt = $pdo->prepare("SELECT id FROM water_sync_clients WHERE device_name = ? LIMIT 1");
    $stmt->execute([$deviceName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('client not found after upsert');
    }
    return (int)$row['id'];
}

function ensureTables(PDO $pdo): void {
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
}

function ensureAccessLogTable(PDO $pdo): void {
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

    // Compatibilidad con instalaciones existentes.
    foreach ([
        "ALTER TABLE water_sync_access_log ADD COLUMN received_count INT NULL AFTER status_code",
        "ALTER TABLE water_sync_access_log ADD COLUMN inserted_count INT NULL AFTER received_count",
        "ALTER TABLE water_sync_access_log ADD COLUMN discarded_count INT NULL AFTER inserted_count",
        "ALTER TABLE water_sync_access_log ADD COLUMN payload_sha256 VARCHAR(128) NULL AFTER discarded_count",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $ignored) {
            // Columna ya existe u otro error no crítico.
        }
    }
}

function logIncomingSyncAccess(
    PDO $pdo,
    string $action,
    string $clientIp,
    string $userAgent,
    int $contentLength,
    ?string $deviceName,
    ?int $statusCode,
    ?int $receivedCount = null,
    ?int $insertedCount = null,
    ?int $discardedCount = null,
    ?string $payloadSha256 = null
): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO water_sync_access_log
            (action, client_ip, user_agent, content_length, device_name, status_code, received_count, inserted_count, discarded_count, payload_sha256)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            substr($action, 0, 32),
            substr($clientIp, 0, 120),
            $userAgent,
            $contentLength,
            $deviceName ? substr($deviceName, 0, 190) : null,
            $statusCode,
            $receivedCount,
            $insertedCount,
            $discardedCount,
            $payloadSha256 ? substr($payloadSha256, 0, 128) : null
        ]);
    } catch (Throwable $ignored) {
        // Nunca bloquear el endpoint principal por un error de log.
    }
}

function loadServerToken(): string {
    $fileToken = '';
    if (is_file(TOKEN_FILE) && is_readable(TOKEN_FILE)) {
        $fileToken = trim((string)file_get_contents(TOKEN_FILE));
    }
    if ($fileToken !== '') return $fileToken;

    $envToken = trim((string)(getenv('WATER_SYNC_TOKEN') ?: ''));
    if ($envToken !== '') return $envToken;

    return DEFAULT_SYNC_TOKEN;
}

function runRetentionIfDue(PDO $pdo): void {
    $now = time();
    $last = 0;
    if (is_file(RETENTION_MARK_FILE)) {
        $last = (int)trim((string)@file_get_contents(RETENTION_MARK_FILE));
    }
    if (($now - $last) < RETENTION_CHECK_INTERVAL_SEC) {
        return;
    }
    cleanupOldData($pdo, RETENTION_DAYS_DEFAULT);
    @file_put_contents(RETENTION_MARK_FILE, (string)$now, LOCK_EX);
}

function cleanupOldData(PDO $pdo, int $days): int {
    $stmt = $pdo->prepare("DELETE FROM water_sync_events WHERE received_at < (NOW() - INTERVAL :days DAY)");
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->execute();
    // Limpiar logs muy antiguos para mantener tamaño controlado.
    $stmtLog = $pdo->prepare("DELETE FROM water_sync_access_log WHERE created_at < (NOW() - INTERVAL :days DAY)");
    $stmtLog->bindValue(':days', $days, PDO::PARAM_INT);
    $stmtLog->execute();
    return $stmt->rowCount();
}
