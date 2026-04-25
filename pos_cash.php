<?php
// ARCHIVO: /var/www/palweb/api/pos_cash.php
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'push_notify.php';
require_once 'config_loader.php';

function poscash_is_authenticated(): bool {
    return !empty($_SESSION['cajero']) || !empty($_SESSION['admin_logged_in']);
}

function poscash_client_ip_fragment(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    if ($ip === '') {
        $ip = '0.0.0.0';
    }
    return substr(hash('sha256', $ip), 0, 16);
}

function poscash_session_fingerprint(): string {
    $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'));
    if ($agent === '') {
        $agent = 'unknown-agent';
    }
    return hash('sha256', poscash_client_ip_fragment() . '|' . $agent);
}

function poscash_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function poscash_error(string $msg, int $httpCode = 400): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'msg' => $msg]);
    exit;
}

function poscash_ensure_csrf_token(): string {
    if (empty($_SESSION['pos_csrf_token']) || !is_string($_SESSION['pos_csrf_token'])) {
        $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pos_csrf_token'];
}

function poscash_enforce_session_security(): void {
    if (!poscash_is_authenticated()) {
        return;
    }

    $expected = (string)($_SESSION['pos_session_fingerprint'] ?? '');
    $current = poscash_session_fingerprint();

    if ($expected === '') {
        $_SESSION['pos_session_fingerprint'] = $current;
        $_SESSION['pos_session_regenerated_at'] = time();
        return;
    }

    if (!hash_equals($expected, $current)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
        poscash_error('Sesion invalida. Vuelva a autenticarse.', 403);
    }

    $lastRegeneratedAt = (int)($_SESSION['pos_session_regenerated_at'] ?? 0);
    if ($lastRegeneratedAt <= 0 || (time() - $lastRegeneratedAt) > 1800) {
        // Usar false para no destruir la sesión antigua inmediatamente
        // y evitar race conditions con requests paralelos
        session_regenerate_id(false);
        $_SESSION['pos_session_regenerated_at'] = time();
        $_SESSION['pos_session_fingerprint'] = $current;
    }
}

function poscash_require_auth(): void {
    if (!poscash_is_authenticated()) {
        poscash_error('Sesion requerida.', 401);
    }
}

function poscash_require_csrf(array $input): void {
    $sessionToken = (string)($_SESSION['pos_csrf_token'] ?? '');
    $providedToken = '';

    if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $providedToken = trim($_SERVER['HTTP_X_CSRF_TOKEN']);
    }
    if ($providedToken === '' && isset($input['csrf_token']) && is_string($input['csrf_token'])) {
        $providedToken = trim($input['csrf_token']);
    }

    if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
        poscash_error('Token CSRF invalido.', 403);
    }
}

$action = $_GET['action'] ?? '';
$input = poscash_json_input();
$sucursalID = intval($config['id_sucursal']);

poscash_enforce_session_security();

try {
    if ($action === 'csrf') {
        echo json_encode([
            'status' => 'success',
            'csrf_token' => poscash_ensure_csrf_token(),
            'authenticated' => poscash_is_authenticated(),
        ]);
        exit;
    }

    if ($action === 'login') {
        $rawPin = (string)($input['pin'] ?? '');
        $csrfDebug = (string)($_SESSION['pos_csrf_token'] ?? 'EMPTY');
        error_log("POS_CASH login: SID=" . session_id() . " pin_raw=" . json_encode($rawPin) . " input_keys=" . json_encode(array_keys($input)) . " csrf_in_session=" . substr($csrfDebug, 0, 8));

        poscash_require_csrf($input);

        $pin = preg_replace('/\D+/', '', $rawPin);
        if ($pin === null || $pin === '' || strlen($pin) < 4 || strlen($pin) > 10) {
            error_log("POS_CASH login: PIN INVALIDO pin_clean=" . json_encode($pin) . " len=" . strlen($pin));
            poscash_error('PIN invalido.', 422);
        }

        $foundCajero = null;

        try {
            $stmtCashier = $pdo->prepare("
                SELECT id, nombre, pin, rol, id_empresa, id_sucursal, id_almacen
                FROM pos_cashiers
                WHERE pin = ? AND COALESCE(activo, 1) = 1
                LIMIT 1
            ");
            $stmtCashier->execute([(string)$pin]);
            $row = $stmtCashier->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $foundCajero = [
                    'id' => (int)$row['id'],
                    'nombre' => (string)$row['nombre'],
                    'pin' => (string)$row['pin'],
                    'rol' => (string)($row['rol'] ?? 'cajero'),
                    'id_empresa' => (int)($row['id_empresa'] ?? 1),
                    'id_sucursal' => (int)($row['id_sucursal'] ?? 1),
                    'id_almacen' => (int)($row['id_almacen'] ?? 1),
                ];
            }
        } catch (Throwable $e) {
            // Fallback a configuración legacy en pos.cfg.
        }

        if (!$foundCajero) {
            $cajeros = $config['cajeros'] ?? [];
            foreach ($cajeros as $c) {
                if ((string)($c['pin'] ?? '') === (string)$pin) {
                    $foundCajero = [
                        'id' => 0,
                        'nombre' => (string)($c['nombre'] ?? 'Cajero'),
                        'pin' => (string)$pin,
                        'rol' => (string)($c['rol'] ?? 'cajero'),
                        'id_empresa' => (int)($config['id_empresa'] ?? 1),
                        'id_sucursal' => (int)($config['id_sucursal'] ?? 1),
                        'id_almacen' => (int)($config['id_almacen'] ?? 1),
                    ];
                    break;
                }
            }
        }

        if ($foundCajero) {
            // NO regenerar ID aquí para evitar race condition:
            // requests paralelos (set_almacen, doOpen) llegarían con el SID antiguo destruido
            $_SESSION['cajero'] = $foundCajero['nombre'];
            $_SESSION['cajero_id'] = (int)$foundCajero['id'];
            $_SESSION['id_empresa'] = (int)$foundCajero['id_empresa'];
            $_SESSION['id_sucursal'] = (int)$foundCajero['id_sucursal'];
            $_SESSION['id_almacen'] = (int)$foundCajero['id_almacen'];
            $_SESSION['pos_rol'] = (string)$foundCajero['rol'];
            $_SESSION['pos_session_fingerprint'] = poscash_session_fingerprint();
            $_SESSION['pos_session_regenerated_at'] = time();
            $csrfToken = poscash_ensure_csrf_token();
            session_write_close();

            error_log("POS_CASH login OK: SID=" . session_id() . " cajero=" . $foundCajero['nombre']);

            echo json_encode([
                'status' => 'success',
                'cajero' => $foundCajero['nombre'],
                'rol' => $foundCajero['rol'],
                'id_empresa' => (int)$foundCajero['id_empresa'],
                'id_sucursal' => (int)$foundCajero['id_sucursal'],
                'id_almacen' => (int)$foundCajero['id_almacen'],
                'csrf_token' => $csrfToken,
            ]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'PIN incorrecto']);
        }
    }

    elseif ($action === 'status') {
        $stmt = $pdo->prepare("SELECT * FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_sucursal = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sucursalID]);
        $sesion = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sesion) {
            // RECTIFICACIÓN: Obtener desglose real desglosando pagos mixtos
            $sqlPagos = "SELECT metodo_pago, SUM(monto_ajustado) as total
                         FROM (
                            /* Caso A: Detalle de pagos mixtos/simples registrados */
                            SELECT p.metodo_pago, 
                                   (p.monto * v.total / NULLIF((SELECT SUM(m2.monto) FROM ventas_pagos m2 WHERE m2.id_venta_cabecera = v.id), 0)) as monto_ajustado
                            FROM ventas_pagos p
                            JOIN ventas_cabecera v ON p.id_venta_cabecera = v.id
                            WHERE v.id_caja = ?
                            
                            UNION ALL
                            
                            /* Caso B: Tickets sin detalle en ventas_pagos (legacy) */
                            SELECT v.metodo_pago, 
                                   v.total as monto_ajustado
                            FROM ventas_cabecera v
                            WHERE v.id_caja = ?
                            AND NOT EXISTS (SELECT 1 FROM ventas_pagos p2 WHERE p2.id_venta_cabecera = v.id)
                         ) as desglose
                         WHERE metodo_pago != 'Mixto' AND metodo_pago IS NOT NULL
                         GROUP BY metodo_pago";
            
            $stmtV = $pdo->prepare($sqlPagos);
            $stmtV->execute([$sesion['id'], $sesion['id']]);
            $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'open', 'data' => $sesion, 'ventas' => $ventas]);
        } else {
            echo json_encode(['status' => 'closed']);
        }
    } 
    
    elseif ($action === 'open') {
        error_log("POS_CASH open: SID=" . session_id() . " cajero=" . json_encode($_SESSION['cajero'] ?? 'EMPTY') . " auth=" . (poscash_is_authenticated() ? 'YES' : 'NO'));
        poscash_require_auth();
        poscash_require_csrf($input);

        $cajero = trim((string)($_SESSION['cajero'] ?? $input['cajero'] ?? 'Admin'));
        if ($cajero === '') {
            $cajero = 'Admin';
        }

        $monto = isset($input['monto']) && is_numeric($input['monto']) ? (float)$input['monto'] : 0.0;
        if ($monto < 0) {
            poscash_error('El monto inicial no puede ser negativo.', 422);
        }

        $fechaContable = date('Y-m-d');
        if (!empty($input['fecha']) && is_string($input['fecha'])) {
            $fechaTmp = trim($input['fecha']);
            $dt = DateTime::createFromFormat('Y-m-d', $fechaTmp);
            if ($dt && $dt->format('Y-m-d') === $fechaTmp) {
                $fechaContable = $fechaTmp;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO caja_sesiones 
            (nombre_cajero, monto_inicial, fecha_contable, id_sucursal, estado, fecha_apertura) 
            VALUES (?, ?, ?, ?, 'ABIERTA', NOW())");
        
        if ($stmt->execute([$cajero, $monto, $fechaContable, $sucursalID])) {
            $sessionId = (int)$pdo->lastInsertId();
            push_notify(
                $pdo,
                'operador',
                '🟢 Sesión de caja abierta',
                "{$cajero} — fondo inicial $" . number_format($monto, 2),
                '/marinero/pos.php',
                'cash_session_opened'
            );
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo abrir el turno en la BD']);
        }
    } 

    elseif ($action === 'close') {
        poscash_require_auth();
        poscash_require_csrf($input);

        // RECTIFICACIÓN: Incluir columna 'nota' y validación de ejecución
        $sql = "UPDATE caja_sesiones SET 
                    fecha_cierre = NOW(), 
                    monto_final_sistema = ?, 
                    monto_final_real = ?, 
                    diferencia = ?, 
                    nota = ?, 
                    estado = 'CERRADA' 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $sessionId = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;
        if ($sessionId <= 0) {
            poscash_error('Sesion de caja invalida.', 422);
        }

        $montoSistema = isset($input['sistema']) && is_numeric($input['sistema']) ? (float)$input['sistema'] : 0.0;
        $montoReal = isset($input['real']) && is_numeric($input['real']) ? (float)$input['real'] : 0.0;
        $nota = trim((string)($input['nota'] ?? ''));
        if (strlen($nota) > 500) {
            $nota = substr($nota, 0, 500);
        }

        $diff = $montoReal - $montoSistema;
        
        if ($stmt->execute([
            $montoSistema, 
            $montoReal, 
            $diff, 
            $nota, 
            $sessionId
        ])) {
            push_notify(
                $pdo,
                'operador',
                '🔴 Sesión de caja cerrada',
                'Caja #' . $sessionId . ' — diferencia $' . number_format($diff, 2),
                '/marinero/pos.php',
                'cash_session_closed'
            );
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo actualizar la base de datos']);
        }
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
