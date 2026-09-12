<?php
/**
 * API móvil del POS. Mantiene la misma sesión y reglas de negocio que pos.php.
 * Todas las operaciones mutantes continúan pasando por pos_save.php, que valida
 * CSRF, caja abierta, empresa/sucursal/almacén, stock e idempotencia UUID.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../pos_security.php';
pos_security_bootstrap_session();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config_loader.php';

function mobile_json(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_input(): array {
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function mobile_auth(): void {
    if (!pos_security_is_authenticated()) mobile_json(['status' => 'error', 'msg' => 'Sesion requerida'], 401);
}

function mobile_csrf(array $input): void {
    pos_security_require_csrf($input);
}

$action = trim((string)($_GET['action'] ?? ''));
if ($action === 'ping') mobile_json(['status' => 'success', 'service' => 'pos_mobile', 'time' => time()]);

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobile_input();
    $name = pos_security_clean_text($input['nombre'] ?? '', 100);
    $pin = trim((string)($input['pin'] ?? ''));
    if ($name === '' || !preg_match('/^\d{4,8}$/', $pin)) mobile_json(['status' => 'error', 'msg' => 'Credenciales invalidas'], 422);

    $now = time();
    if ((int)($_SESSION['mobile_lock_until'] ?? 0) > $now) mobile_json(['status' => 'error', 'msg' => 'Demasiados intentos. Espere un minuto.'], 429);

    $cashier = null;
    try {
        $q = $pdo->prepare('SELECT nombre, pin, rol, id_empresa, id_sucursal, id_almacen FROM pos_cashiers WHERE nombre = ? AND COALESCE(activo, 1) = 1 LIMIT 1');
        $q->execute([$name]);
        $cashier = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        foreach (($config['cajeros'] ?? []) as $candidate) {
            if ((string)($candidate['nombre'] ?? '') === $name) { $cashier = $candidate; break; }
        }
    }

    $valid = $cashier && hash_equals((string)($cashier['pin'] ?? ''), $pin);
    if (!$valid) {
        $attempts = (int)($_SESSION['mobile_failed_attempts'] ?? 0) + 1;
        $_SESSION['mobile_failed_attempts'] = $attempts;
        if ($attempts >= 5) { $_SESSION['mobile_lock_until'] = $now + 60; $_SESSION['mobile_failed_attempts'] = 0; }
        usleep(250000);
        mobile_json(['status' => 'error', 'msg' => 'Credenciales invalidas'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['cajero'] = $name;
    $_SESSION['id_empresa'] = (int)($cashier['id_empresa'] ?? ($config['id_empresa'] ?? 1));
    $_SESSION['id_sucursal'] = (int)($cashier['id_sucursal'] ?? ($config['id_sucursal'] ?? 1));
    $_SESSION['id_almacen'] = (int)($cashier['id_almacen'] ?? ($config['id_almacen'] ?? 1));
    $_SESSION['pos_session_fingerprint'] = pos_security_session_fingerprint();
    $_SESSION['pos_session_regenerated_at'] = time();
    $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['mobile_failed_attempts'] = 0;
    mobile_json([
        'status' => 'success',
        'csrf_token' => $_SESSION['pos_csrf_token'],
        'usuario' => $name,
        'rol' => (string)($cashier['rol'] ?? 'cajero'),
        'contexto' => ['id_empresa' => $_SESSION['id_empresa'], 'id_sucursal' => $_SESSION['id_sucursal'], 'id_almacen' => $_SESSION['id_almacen']],
        'save_endpoint' => '/pos_save.php'
    ]);
}

if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    mobile_auth();
    $empresa = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
    $sucursal = (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1);
    $almacen = (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);
    try {
        $sql = 'SELECT p.codigo AS id, p.codigo, p.nombre, COALESCE(ps.precio_venta, p.precio) AS precio,
                COALESCE(ps.precio_mayorista, p.precio_mayorista, p.precio) AS precio_mayorista,
                p.categoria, p.es_servicio, COALESCE(p.favorito,0) AS favorito,
                (SELECT COALESCE(SUM(s.cantidad),0) FROM stock_almacen s WHERE s.id_producto=p.codigo AND s.id_almacen=? AND s.id_sucursal=?) AS stock
                FROM productos p LEFT JOIN productos_precios_sucursal ps ON ps.codigo_producto=p.codigo AND ps.id_sucursal=?
                WHERE p.id_empresa=? AND p.activo=1 ORDER BY p.nombre';
        $q = $pdo->prepare($sql); $q->execute([$almacen, $sucursal, $sucursal, $empresa]);
        $products = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as &$product) { $product['stock'] = (float)$product['stock']; $product['precio'] = (float)$product['precio']; }
        mobile_json(['status' => 'success', 'products' => $products, 'contexto' => compact('empresa', 'sucursal', 'almacen'), 'csrf_token' => $_SESSION['pos_csrf_token'] ?? '']);
    } catch (Throwable $e) {
        error_log('pos_mobile bootstrap: ' . $e->getMessage());
        mobile_json(['status' => 'error', 'msg' => 'No se pudo cargar el catalogo'], 500);
    }
}

if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    mobile_auth();
    mobile_json(['status' => 'success', 'usuario' => $_SESSION['cajero'] ?? '', 'csrf_token' => $_SESSION['pos_csrf_token'] ?? '', 'contexto' => ['id_empresa' => (int)($_SESSION['id_empresa'] ?? 0), 'id_sucursal' => (int)($_SESSION['id_sucursal'] ?? 0), 'id_almacen' => (int)($_SESSION['id_almacen'] ?? 0)]]);
}

mobile_json(['status' => 'error', 'msg' => 'Accion no valida'], 404);
