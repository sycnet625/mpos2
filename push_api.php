<?php
// ARCHIVO: push_api.php
// REST API de notificaciones push.
// Endpoints GET:  vapid_key | latest | count | list
// Endpoints POST: subscribe | unsubscribe | test | send

ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Si el navegador accede directamente sin parámetros (text/html), redirigir al dashboard.
// Esto evita mostrar JSON crudo en pantalla.
$method  = $_SERVER['REQUEST_METHOD'];
$accept  = $_SERVER['HTTP_ACCEPT'] ?? '';
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains($accept, 'application/json')
        || !str_contains($accept, 'text/html');
$actionQ = $_GET['action'] ?? null;

if (!$isAjax && empty($actionQ) && $method === 'GET') {
    header('Location: dashboard.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_manager.php';

$action = $actionQ;
$input  = [];
if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $action ?? ($input['action'] ?? null);
}

// ── Auto-crear tablas ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        endpoint    TEXT NOT NULL,
        p256dh      VARCHAR(500) NOT NULL,
        auth        VARCHAR(200) NOT NULL,
        tipo        VARCHAR(30)  NOT NULL DEFAULT 'operador',
        device_name VARCHAR(150),
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_push   TIMESTAMP NULL,
        UNIQUE KEY uk_ep (endpoint(500))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        tipo       VARCHAR(30)  NOT NULL,
        titulo     VARCHAR(200) NOT NULL,
        cuerpo     TEXT,
        url        VARCHAR(500),
        leida      TINYINT(1)  DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tipo_leida (tipo, leida)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}


// ══════════════════════════════════════════════════════════════════════════
// GET: clave pública VAPID (necesaria para que el cliente se suscriba)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'vapid_key') {
    $keys = PushManager::ensureKeys();
    echo json_encode(['publicKey' => $keys['publicKey']]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// GET: última notificación no leída (la consume el SW al despertar)
// Parámetros: ?tipo=operador|cocina  (opcional, sin filtro = más reciente)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'latest') {
    $tipo = $_GET['tipo'] ?? null;
    try {
        if ($tipo) {
            $st = $pdo->prepare(
                "SELECT id, titulo, cuerpo, url, acciones FROM push_notifications
                  WHERE tipo = ? AND leida = 0
                  ORDER BY created_at DESC LIMIT 1"
            );
            $st->execute([$tipo]);
        } else {
            $st = $pdo->query(
                "SELECT id, titulo, cuerpo, url, acciones FROM push_notifications
                  WHERE leida = 0 ORDER BY created_at DESC LIMIT 1"
            );
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("UPDATE push_notifications SET leida = 1 WHERE id = ?")->execute([$row['id']]);
            // Decodificar acciones si existen
            if (!empty($row['acciones'])) {
                $row['acciones'] = json_decode($row['acciones'], true);
            }
            echo json_encode($row);
        } else {
            echo json_encode(['titulo' => null]);
        }
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// GET: contador de no leídas (para badge de interfaz)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'count') {
    $tipo = $_GET['tipo'] ?? null;
    try {
        if ($tipo) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM push_notifications WHERE tipo = ? AND leida = 0");
            $st->execute([$tipo]);
        } else {
            $st = $pdo->query("SELECT COUNT(*) FROM push_notifications WHERE leida = 0");
        }
        echo json_encode(['count' => intval($st->fetchColumn())]);
    } catch (Throwable $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST: registrar/actualizar subscripción push
// Body: { action:'subscribe', subscription:{endpoint,keys:{p256dh,auth}}, tipo:'operador'|'cocina', device:'...' }
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'subscribe') {
    $sub    = $input['subscription'] ?? [];
    $tipo   = in_array($input['tipo'] ?? '', ['operador','cocina','cliente']) ? $input['tipo'] : 'operador';
    $device = substr($input['device'] ?? '', 0, 150);

    if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        echo json_encode(['error' => 'Datos de subscripción incompletos']);
        exit;
    }
    try {
        $pdo->prepare(
            "INSERT INTO push_subscriptions (endpoint, p256dh, auth, tipo, device_name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), device_name = VALUES(device_name)"
        )->execute([
            $sub['endpoint'],
            $sub['keys']['p256dh'],
            $sub['keys']['auth'],
            $tipo,
            $device,
        ]);
        echo json_encode(['status' => 'ok', 'tipo' => $tipo]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST: cancelar subscripción
// Body: { action:'unsubscribe', endpoint:'...' }
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'unsubscribe') {
    $endpoint = $input['endpoint'] ?? '';
    if (!$endpoint) { echo json_encode(['error' => 'endpoint requerido']); exit; }
    try {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
        echo json_encode(['status' => 'ok']);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST: enviar notificación de prueba (solo admin)
// Body: { action:'test' }
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'test') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    require_once 'push_notify.php';
    push_notify($pdo, 'todos', '🔔 Prueba PALWEB', 'Las notificaciones push están funcionando correctamente.', '/marinero/dashboard.php');
    echo json_encode(['status' => 'ok', 'msg' => 'Notificación de prueba enviada a todos los dispositivos.']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// POST: enviar notificación publicitaria a clientes de la tienda (solo admin)
// Body: { action:'send', titulo:'...', cuerpo:'...', url:'...', tipo:'cliente' }
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'send') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    require_once 'push_notify.php';
    $titulo = trim($input['titulo'] ?? '');
    $cuerpo = trim($input['cuerpo'] ?? '');
    $url    = trim($input['url']    ?? '/marinero/shop.php');
    $tipo   = in_array($input['tipo'] ?? '', ['cliente','operador','cocina','todos']) ? $input['tipo'] : 'cliente';

    if (empty($titulo)) {
        echo json_encode(['error' => 'El título es requerido']);
        exit;
    }

    push_notify($pdo, $tipo, $titulo, $cuerpo, $url);

    if ($tipo === 'todos') {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE tipo = ?");
        $st->execute([$tipo]);
        $count = (int)$st->fetchColumn();
    }
    echo json_encode(['status' => 'ok', 'enviado_a' => $count, 'msg' => "Notificación enviada a {$count} dispositivo(s)"]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// GET: historial de notificaciones enviadas por tipo (solo admin)
// ?action=history&tipo=cliente&limit=20
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'history') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    $htipo  = $_GET['tipo']  ?? 'cliente';
    $hlimit = min((int)($_GET['limit'] ?? 20), 50);
    try {
        $st = $pdo->prepare(
            "SELECT id, tipo, titulo, cuerpo, url, created_at
               FROM push_notifications WHERE tipo = ?
               ORDER BY created_at DESC LIMIT ?"
        );
        $st->execute([$htipo, $hlimit]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// GET: listar subscripciones activas (solo admin)
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'list') {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
    try {
        $rows = $pdo->query(
            "SELECT id, tipo, device_name, created_at, last_push FROM push_subscriptions ORDER BY tipo, created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Acción no reconocida', 'action' => $action]);
