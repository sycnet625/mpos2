<?php
// ARCHIVO: /var/www/palweb/api/pos.php

// ── Cabeceras de Seguridad HTTP ──────────────────────────────────────────────
// pos.php usa autenticación por PIN (sin sesión PHP), por tanto no se
// configura session_set_cookie_params aquí.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; media-src 'self' https://assets.mixkit.co; connect-src 'self' https://www.google-analytics.com https://analytics.google.com https://stats.g.doubleclick.net; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header("Alt-Svc: h2=\":443\"; ma=86400");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
// ─────────────────────────────────────────────────────────────────────────────

ini_set('display_errors', 0);
$posHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $posHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once 'db.php';
require_once 'config_loader.php';
require_once 'combo_helper.php';

error_log('POS: Session loaded. SID=' . session_id() . ' Data=' . json_encode([
    'cajero' => $_SESSION['cajero'] ?? 'EMPTY',
    'id_sucursal' => $_SESSION['id_sucursal'] ?? 'EMPTY',
    'id_almacen' => $_SESSION['id_almacen'] ?? 'EMPTY'
]));

$posScriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/pos.php'));
$posBasePath = rtrim($posScriptDir === '.' ? '/' : $posScriptDir, '/');
if ($posBasePath === '') {
    $posBasePath = '/';
}
$posPrefix = $posBasePath === '/' ? '' : $posBasePath;
$posDocumentBase = $posPrefix . '/';
$posScopePath = $posPrefix . '/pos/';

function pos_is_authenticated(): bool
{
    return !empty($_SESSION['cajero']) || !empty($_SESSION['admin_logged_in']);
}

function pos_client_ip_fragment(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return implode('.', array_slice($parts, 0, 3));
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 4));
    }
    return $ip;
}

function pos_session_fingerprint(): string
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180);
    return hash('sha256', pos_client_ip_fragment() . '|' . $ua);
}

function pos_reset_session_state(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function pos_ensure_csrf_token(): string
{
    if (empty($_SESSION['pos_csrf_token']) || !is_string($_SESSION['pos_csrf_token'])) {
        $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pos_csrf_token'];
}

function pos_enforce_session_security(): void
{
    if (!pos_is_authenticated()) {
        pos_ensure_csrf_token();
        return;
    }

    $fingerprint = pos_session_fingerprint();
    if (!empty($_SESSION['pos_session_fingerprint']) && !hash_equals((string)$_SESSION['pos_session_fingerprint'], $fingerprint)) {
        pos_reset_session_state();
        pos_ensure_csrf_token();
        return;
    }

    $_SESSION['pos_session_fingerprint'] = $fingerprint;

    $lastRegen = (int)($_SESSION['pos_session_regenerated_at'] ?? 0);
    if ($lastRegen <= 0 || (time() - $lastRegen) > 900) {
        // false = no destruir la sesión antigua para evitar race conditions
        session_regenerate_id(false);
        $_SESSION['pos_session_regenerated_at'] = time();
        $_SESSION['pos_session_fingerprint'] = $fingerprint;
    }

    pos_ensure_csrf_token();
}

function pos_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $input = json_decode($raw, true);
    return is_array($input) ? $input : [];
}

function pos_json_error(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message, 'msg' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function pos_require_auth_json(): void
{
    if (!pos_is_authenticated()) {
        pos_json_error('No autenticado', 401);
    }
}

function pos_require_csrf(array $input = []): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
    $sessionToken = (string)($_SESSION['pos_csrf_token'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        pos_json_error('Token CSRF inválido', 403);
    }
}

function pos_clean_text($value, int $maxLen = 255): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $maxLen, 'UTF-8');
}

function pos_clean_sku($value): string
{
    $sku = trim((string)$value);
    return preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $sku) ? $sku : '';
}

function pos_clean_barcode($value): string
{
    $barcode = trim((string)$value);
    if ($barcode === '') {
        return '';
    }
    return preg_match('/^[A-Za-z0-9_.\-\/\s]{1,64}$/', $barcode) ? $barcode : '';
}

function pos_normalize_items($items): array
{
    if (!is_array($items)) {
        return [];
    }
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sku = pos_clean_sku($item['sku'] ?? '');
        $qty = isset($item['cantidad']) && is_numeric($item['cantidad']) ? (float)$item['cantidad'] : null;
        if ($sku === '' || $qty === null || !is_finite($qty)) {
            continue;
        }
        $normalized[] = ['sku' => $sku, 'cantidad' => $qty];
        if (count($normalized) >= 500) {
            break;
        }
    }
    return $normalized;
}

pos_enforce_session_security();
$POS_CSRF_TOKEN = pos_ensure_csrf_token();

function pos_image_meta(string $code): array {
    $safe = trim($code);
    if ($safe === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $safe)) {
        return [false, 0];
    }
    $bases = [
        __DIR__ . '/assets/product_images/' . $safe,
        dirname(__DIR__) . '/assets/product_images/' . $safe,
    ];
    foreach ($bases as $base) {
        foreach (['.avif', '.webp', '.jpg', '.jpeg'] as $ext) {
            $f = $base . $ext;
            if (file_exists($f)) return [true, (int)filemtime($f)];
        }
    }
    return [false, 0];
}

function pos_get_dynamic_cashiers(PDO $pdo, array $config): array
{
    try {
        $rows = $pdo->query("
            SELECT nombre, pin, rol, id_empresa, id_sucursal, id_almacen
            FROM pos_cashiers
            WHERE COALESCE(activo, 1) = 1
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return array_map(static function (array $row): array {
                return [
                    'nombre' => (string)$row['nombre'],
                    'pin' => (string)$row['pin'],
                    'rol' => (string)($row['rol'] ?? 'cajero'),
                    'id_empresa' => (int)($row['id_empresa'] ?? 1),
                    'id_sucursal' => (int)($row['id_sucursal'] ?? 1),
                    'id_almacen' => (int)($row['id_almacen'] ?? 1),
                ];
            }, $rows);
        }
    } catch (Throwable $e) {
        // Fallback a configuración legacy.
    }

    $fallback = [];
    foreach (($config['cajeros'] ?? []) as $c) {
        $fallback[] = [
            'nombre' => (string)($c['nombre'] ?? 'Cajero'),
            'pin' => (string)($c['pin'] ?? ''),
            'rol' => (string)($c['rol'] ?? 'cajero'),
            'id_empresa' => (int)($config['id_empresa'] ?? 1),
            'id_sucursal' => (int)($config['id_sucursal'] ?? 1),
            'id_almacen' => (int)($config['id_almacen'] ?? 1),
        ];
    }
    return $fallback;
}

// ---------------------------------------------------------
// API INTERNA: GESTIÓN DE CLIENTES (NUEVO)
// ---------------------------------------------------------
if (isset($_GET['api_client']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    pos_require_auth_json();
    $input = pos_json_input();
    pos_require_csrf($input);
    
    try {
        $nombre = pos_clean_text($input['nombre'] ?? '', 200);
        $telefono = pos_clean_text($input['telefono'] ?? '', 60);
        $direccion = pos_clean_text($input['direccion'] ?? '', 255);
        $nitCi = pos_clean_text($input['nit_ci'] ?? '', 60);

        if ($nombre === '') throw new Exception("El nombre es obligatorio");
        
        $stmt = $pdo->prepare("INSERT INTO clientes (nombre, telefono, direccion, nit_ci, activo) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([
            $nombre,
            $telefono,
            $direccion,
            $nitCi
        ]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'status' => 'success', 
            'client' => [
                'id' => $newId,
                'nombre' => $nombre,
                'telefono' => $telefono,
                'direccion' => $direccion,
                'nit_ci' => $nitCi
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ENDPOINT: Carga de productos para caché
if (isset($_GET['load_products'])) {
    header('Content-Type: application/json');
    $ctxConfig = array_merge([
        "id_almacen" => 1,
        "id_sucursal" => 1,
        "mostrar_materias_primas" => false,
        "mostrar_servicios" => true,
        "categorias_ocultas" => []
    ], $config ?? []);

    // Si hay una sesión de cajero activa, su almacén tiene prioridad sobre pos.cfg
    if (!empty($_SESSION['id_almacen'])) {
        $ctxConfig['id_almacen'] = (int)$_SESSION['id_almacen'];
    }
    if (!empty($_SESSION['id_sucursal'])) {
        $ctxConfig['id_sucursal'] = (int)$_SESSION['id_sucursal'];
    }
    // Override explícito desde el selector de almacén: solo si hay sesión activa y el almacén
    // pertenece a la sucursal del cajero (validado también en set_almacen, doble check aquí).
    if (!empty($_GET['alm']) && !empty($_SESSION['cajero'])) {
        $almOverride = (int)$_GET['alm'];
        if ($almOverride > 0) {
            $sucSes = (int)($ctxConfig['id_sucursal'] ?? 0);
            $valid  = false;
            if ($sucSes > 0) {
                try {
                    $stmtV = $pdo->prepare("SELECT id FROM almacenes WHERE id = ? AND id_sucursal = ? AND activo = 1 LIMIT 1");
                    $stmtV->execute([$almOverride, $sucSes]);
                    $valid = (bool)$stmtV->fetchColumn();
                } catch (Throwable $e) { $valid = true; /* tabla ausente, confiar */ }
            } else {
                $valid = true; // sin sucursal en sesión, aceptar
            }
            if ($valid) $ctxConfig['id_almacen'] = $almOverride;
        }
    }

    try {
        $cond = ["p.activo = 1"];
        if (empty($ctxConfig['mostrar_materias_primas'])) $cond[] = "p.es_materia_prima = 0";
        if (empty($ctxConfig['mostrar_servicios'])) $cond[] = "p.es_servicio = 0";
        if (!empty($ctxConfig['categorias_ocultas'])) {
            $placeholders = implode(',', array_fill(0, count($ctxConfig['categorias_ocultas']), '?'));
            $cond[] = "p.categoria NOT IN ($placeholders)";
        }

        $where = implode(" AND ", $cond);
        $almacenID = (int)$ctxConfig['id_almacen'];
        $params = $ctxConfig['categorias_ocultas'] ?? [];
        
        $sucursalID = (int)$ctxConfig['id_sucursal'];
        $sqlProd = "SELECT p.codigo as id, p.codigo, p.nombre,
                    p.precio                                                         AS precio_global,
                    COALESCE(p.precio_mayorista, 0)                                  AS precio_mayorista_global,
                    COALESCE(ps.precio_venta,    p.precio)                           AS precio_suc,
                    COALESCE(ps.precio_mayorista, p.precio_mayorista, p.precio)      AS precio_mayorista_suc,
                    COALESCE(ps.precio_venta,    p.precio)                           AS precio,
                    p.categoria, p.es_elaborado, p.es_servicio, COALESCE(p.es_combo, 0) AS es_combo,
                    p.codigo_barra_1, p.codigo_barra_2,
                    COALESCE(p.favorito,0) as favorito,
                    (SELECT COALESCE(SUM(s.cantidad), 0)
                     FROM stock_almacen s
                     WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock
                    FROM productos p
                    LEFT JOIN productos_precios_sucursal ps
                           ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
                    WHERE $where
                    ORDER BY p.nombre";

        $stmtProd = $pdo->prepare($sqlProd);
        $stmtProd->execute(array_merge([$almacenID, $sucursalID], $params));
        $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
        $prods = combo_apply_product_rows($pdo, $prods, (int)$ctxConfig['id_empresa'], $almacenID);

        // Procesar para incluir colores e imágenes
        foreach ($prods as &$p) {
            [$p['has_image'], $p['img_version']] = pos_image_meta((string)$p['codigo']);
            // crc32 puede devolver valor negativo; & 0xFFFFFFFF lo convierte a sin signo
            // Usamos los bytes 1-3 (del 0x__RRGGBB) para evitar colores demasiado oscuros
            $p['color'] = '#' . substr(sprintf('%08x', crc32($p['nombre']) & 0xFFFFFFFF), 2, 6);
            $p['stock'] = floatval($p['stock']);
        }
        unset($p);
        
        echo json_encode(['status' => 'success', 'products' => $prods, 'timestamp' => time()]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ENDPOINT: Actualizar almacén de sesión (selección tras login multi-almacén)
if (isset($_GET['set_almacen']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    pos_require_auth_json();
    $inp = pos_json_input();
    pos_require_csrf($inp);
    $almId = (int)($inp['id_almacen'] ?? 0);
    if ($almId <= 0) {
        echo json_encode(['status' => 'error', 'msg' => 'ID de almacén inválido']); exit;
    }
    // Validar que el almacén pertenece a la sucursal del cajero en sesión
    $sucId = (int)($_SESSION['id_sucursal'] ?? 0);
    if ($sucId > 0) {
        try {
            $stmtChk = $pdo->prepare("SELECT id FROM almacenes WHERE id = ? AND id_sucursal = ? AND activo = 1 LIMIT 1");
            $stmtChk->execute([$almId, $sucId]);
            if (!$stmtChk->fetchColumn()) {
                echo json_encode(['status' => 'error', 'msg' => 'Almacén no pertenece a tu sucursal']); exit;
            }
        } catch (Throwable $e) { /* si no existe la tabla, permitir sin validar */ }
    }
    $_SESSION['id_almacen'] = $almId;
    echo json_encode(['status' => 'success', 'id_almacen' => $almId]);
    exit;
}

// ENDPOINT: Ping para medir velocidad
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    echo json_encode(['pong' => true, 'timestamp' => time()]);
    exit;
}

// ENDPOINT: Cajeros completos (con PIN) para poblar IndexedDB offline
// Solo accesible con sesión activa (cajero ya autenticado o admin)
if (isset($_GET['load_cashiers'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['cajero']) && empty($_SESSION['admin_logged_in'])) {
        echo json_encode(['status' => 'error', 'msg' => 'No autenticado']);
        exit;
    }
    echo json_encode(['status' => 'success', 'cashiers' => pos_get_dynamic_cashiers($pdo, $config)]);
    exit;
}

// ENDPOINT: Toggle favorito de producto
if (isset($_GET['toggle_fav']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    pos_require_auth_json();
    $inp = pos_json_input();
    pos_require_csrf($inp);
    $codigo = pos_clean_sku($inp['codigo'] ?? '');
    if ($codigo) {
        $pdo->prepare("UPDATE productos SET favorito = 1 - favorito WHERE codigo = ? AND id_empresa = ?")->execute([$codigo, (int)($config['id_empresa'] ?? 1)]);
        $favStmt = $pdo->prepare("SELECT favorito FROM productos WHERE codigo = ? AND id_empresa = ?");
        $favStmt->execute([$codigo, (int)($config['id_empresa'] ?? 1)]);
        echo json_encode(['status' => 'success', 'favorito' => (int)$favStmt->fetchColumn()]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Código inválido']);
    }
    exit;
}

// ENDPOINT: Inventario desde POS — opera sobre el carrito completo
if (isset($_GET['inventario_api']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    pos_require_auth_json();
    $input = pos_json_input();
    pos_require_csrf($input);
    $accion  = pos_clean_text($input['accion'] ?? '', 40);
    $motivo  = pos_clean_text($input['motivo'] ?? '', 500);
    $items   = pos_normalize_items($input['items'] ?? []);
    $fechaRaw = trim($input['fecha'] ?? '');
    $fechaParsed = $fechaRaw ? DateTime::createFromFormat('Y-m-d', substr($fechaRaw, 0, 10)) : false;
    $fecha   = $fechaParsed ? $fechaParsed->format('Y-m-d') . ' ' . date('H:i:s') : date('Y-m-d H:i:s');
    $usuarioSesion = pos_clean_text($_SESSION['cajero'] ?? $_SESSION['admin_user'] ?? $_SESSION['admin_name'] ?? 'POS-Admin', 100);
    $usuario = $usuarioSesion !== '' ? $usuarioSesion : 'POS-Admin';
    $allowedActions = ['update_barcodes', 'consultar_bulk', 'merma', 'entrada', 'transferencia', 'ajuste', 'conteo'];
    if (!in_array($accion, $allowedActions, true)) {
        echo json_encode(['status' => 'error', 'msg' => 'Acción inválida']);
        exit;
    }

    $ctxConfig = array_merge([
        "id_almacen" => 1,
        "id_sucursal" => 1,
        "id_empresa" => 1
    ], $config ?? []);

    // El cajero logueado tiene prioridad sobre pos.cfg — igual que en load_products
    if (!empty($_SESSION['id_almacen']))  $ctxConfig['id_almacen']  = (int)$_SESSION['id_almacen'];
    if (!empty($_SESSION['id_sucursal'])) $ctxConfig['id_sucursal'] = (int)$_SESSION['id_sucursal'];
    if (!empty($_SESSION['id_empresa']))  $ctxConfig['id_empresa']  = (int)$_SESSION['id_empresa'];

    $ALM = (int)$ctxConfig['id_almacen'];
    $SUC = (int)$ctxConfig['id_sucursal'];
    $EMP = (int)$ctxConfig['id_empresa'];

    require_once 'kardex_engine.php';

    // Actualización de códigos de barras desde el POS (Modo Inventario)
    if ($accion === 'update_barcodes') {
        $sku = pos_clean_sku($input['sku'] ?? '');
        $b1  = pos_clean_barcode($input['barcode'] ?? '');
        $b2  = pos_clean_barcode($input['barcode2'] ?? '');
        if (!$sku) { echo json_encode(['status'=>'error','msg'=>'SKU no proporcionado']); exit; }
        
        $q = $pdo->prepare("UPDATE productos SET codigo_barra_1=?, codigo_barra_2=? WHERE codigo=? AND id_empresa=?");
        if ($q->execute([$b1, $b2, $sku, $EMP])) {
            echo json_encode(['status'=>'success', 'msg'=>'Códigos actualizados']);
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'Error en base de datos']);
        }
        exit;
    }

    // Consulta bulk de stock para el conteo visual (sin modificar datos)
    if ($accion === 'consultar_bulk') {
        $skus = is_array($input['skus'] ?? null) ? $input['skus'] : [];
        $stocks = [];
        foreach ($skus as $s) {
            $s = pos_clean_sku($s);
            if (!$s) continue;
            $q = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
            $q->execute([$s, $ALM]);
            $stocks[$s] = floatval($q->fetchColumn() ?: 0);
        }
        echo json_encode(['status'=>'success','stocks'=>$stocks]);
        exit;
    }

    if (empty($items)) { echo json_encode(['status'=>'error','msg'=>'Carrito vacío']); exit; }

    $ke = new KardexEngine($pdo);
    $results = [];
    $stocks_updated = [];

    try {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        // Crear tablas merma si hacen falta (solo una vez)
        if ($accion === 'merma') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS mermas_cabecera (id INT AUTO_INCREMENT PRIMARY KEY, usuario VARCHAR(100), motivo_general TEXT, total_costo_perdida DECIMAL(12,2) DEFAULT 0, estado VARCHAR(20) DEFAULT 'PROCESADA', id_sucursal INT DEFAULT 1, id_empresa INT DEFAULT 1, id_almacen INT DEFAULT 1, fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS mermas_detalle (id INT AUTO_INCREMENT PRIMARY KEY, id_merma INT, id_producto VARCHAR(50), cantidad DECIMAL(10,3), costo_al_momento DECIMAL(12,2), motivo_especifico TEXT) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $totalCosto = 0;
            foreach ($items as $it) {
                $p = $pdo->prepare("SELECT costo FROM productos WHERE codigo=? AND id_empresa=?");
                $p->execute([trim($it['sku']??''), $EMP]);
                $totalCosto += abs(floatval($it['cantidad']??0)) * floatval($p->fetchColumn()?:0);
            }
            $pdo->prepare("INSERT INTO mermas_cabecera (usuario,motivo_general,total_costo_perdida,id_sucursal,id_empresa,id_almacen,fecha_registro) VALUES(?,?,?,?,?,?,?)")->execute([$usuario,$motivo,$totalCosto,$SUC,$EMP,$ALM,$fecha]);
            $idMerma = $pdo->lastInsertId();
        }

        if ($accion === 'entrada') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS entradas_cabecera (
                id INT AUTO_INCREMENT PRIMARY KEY,
                motivo TEXT NULL,
                total DECIMAL(12,2) DEFAULT 0,
                usuario VARCHAR(100) NULL,
                fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
                id_empresa INT DEFAULT 1,
                id_sucursal INT DEFAULT 1,
                id_almacen INT DEFAULT 1
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS entradas_detalle (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_entrada INT NOT NULL,
                id_producto VARCHAR(50) NOT NULL,
                cantidad DECIMAL(12,2) NOT NULL,
                costo_unitario DECIMAL(12,2) NOT NULL,
                subtotal DECIMAL(12,2) NOT NULL
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $totalEntrada = 0;
            foreach ($items as $it) {
                $p = $pdo->prepare("SELECT costo FROM productos WHERE codigo=? AND id_empresa=?");
                $p->execute([trim($it['sku']??''), $EMP]);
                $totalEntrada += abs(floatval($it['cantidad']??0)) * floatval($p->fetchColumn()?:0);
            }
            $pdo->prepare("INSERT INTO entradas_cabecera (motivo,total,usuario,fecha,id_empresa,id_sucursal,id_almacen) VALUES(?,?,?,?,?,?,?)")->execute([$motivo,$totalEntrada,$usuario,$fecha,$EMP,$SUC,$ALM]);
            $idEntrada = $pdo->lastInsertId();
        }

        if ($accion === 'transferencia') {
            $destino = pos_clean_text($input['destino'] ?? '', 100);
            if (!$destino) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['status'=>'error','msg'=>'Indica la sucursal destino']);
                exit;
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS transferencias_cabecera (
                id INT AUTO_INCREMENT PRIMARY KEY,
                destino_nombre VARCHAR(100) NULL,
                total_items INT DEFAULT 0,
                usuario VARCHAR(100) NULL,
                motivo TEXT NULL,
                estado VARCHAR(20) DEFAULT 'PENDIENTE',
                id_empresa INT DEFAULT 1,
                id_sucursal_origen INT DEFAULT 1,
                id_almacen_origen INT DEFAULT 1,
                id_almacen_destino INT DEFAULT NULL,
                fecha DATETIME DEFAULT CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS transferencias_detalle (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_transferencia INT NOT NULL,
                id_producto VARCHAR(50) NOT NULL,
                cantidad DECIMAL(12,2) NOT NULL,
                costo_unitario DECIMAL(12,2) NOT NULL,
                subtotal DECIMAL(12,2) NOT NULL
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Buscar almacén destino en la misma BD (por nombre de sucursal o almacén)
            $destAlmID = null;
            $destSucID = null;
            try {
                $stmtDA = $pdo->prepare(
                    "SELECT a.id as alm_id, a.id_sucursal as suc_id
                     FROM almacenes a
                     LEFT JOIN sucursales s ON a.id_sucursal = s.id
                     WHERE a.nombre LIKE ? OR s.nombre LIKE ?
                     LIMIT 1"
                );
                $stmtDA->execute(["%$destino%", "%$destino%"]);
                $rowDA = $stmtDA->fetch(PDO::FETCH_ASSOC);
                if ($rowDA) {
                    $destAlmID = (int)$rowDA['alm_id'];
                    $destSucID = (int)$rowDA['suc_id'];
                }
            } catch (Exception $e) { /* tabla puede no existir aún */ }

            $estadoTransf = $destAlmID ? 'COMPLETADO' : 'PENDIENTE';
            $totalItems = count($items);
            $pdo->prepare(
                "INSERT INTO transferencias_cabecera
                 (destino_nombre,total_items,usuario,motivo,estado,id_empresa,id_sucursal_origen,id_almacen_origen,id_almacen_destino,fecha)
                 VALUES(?,?,?,?,?,?,?,?,?,?)"
            )->execute([$destino,$totalItems,$usuario,$motivo,$estadoTransf,$EMP,$SUC,$ALM,$destAlmID,$fecha]);
            $idTransferencia = $pdo->lastInsertId();
        }

        foreach ($items as $item) {
            $sku = trim($item['sku'] ?? '');
            $qty = floatval($item['cantidad'] ?? 0);
            // Para conteo se permite qty=0 (el usuario contó cero unidades)
            if (!$sku) continue;
            if ($accion !== 'conteo' && $qty <= 0) continue;
            if ($accion === 'conteo' && $qty < 0) continue;

            $pq = $pdo->prepare("SELECT codigo, nombre, costo FROM productos WHERE codigo=? AND id_empresa=?");
            $pq->execute([$sku, $EMP]);
            $prod = $pq->fetch(PDO::FETCH_ASSOC);
            if (!$prod) { $results[] = "SKU no encontrado: $sku"; continue; }
            $costo = floatval($prod['costo']);

            if ($accion === 'entrada') {
                $ke->registrarMovimiento($sku,$ALM,$SUC,'ENTRADA',abs($qty),"ENTRADA POS: $motivo",$costo,$usuario,$fecha);
                $pdo->prepare("INSERT INTO entradas_detalle (id_entrada,id_producto,cantidad,costo_unitario,subtotal) VALUES(?,?,?,?,?)")->execute([$idEntrada,$sku,abs($qty),$costo,abs($qty)*$costo]);
                $results[] = "+".abs($qty)." ".$prod['nombre'];

            } elseif ($accion === 'merma') {
                $pdo->prepare("INSERT INTO mermas_detalle (id_merma,id_producto,cantidad,costo_al_momento,motivo_especifico) VALUES(?,?,?,?,?)")->execute([$idMerma,$sku,abs($qty),$costo,$motivo]);
                $ke->registrarMovimiento($sku,$ALM,$SUC,'MERMA',-abs($qty),"MERMA #$idMerma POS",$costo,$usuario,$fecha);
                $results[] = "-".abs($qty)." ".$prod['nombre'];

            } elseif ($accion === 'ajuste') {
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',$qty,"AJUSTE POS: $motivo",$costo,$usuario,$fecha);
                $results[] = ($qty>0?"+":"").$qty." ".$prod['nombre'];

            } elseif ($accion === 'conteo') {
                $sq = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
                $sq->execute([$sku,$ALM]);
                $stockAnt = floatval($sq->fetchColumn() ?: 0);
                $delta = $qty - $stockAnt;
                // Registrar incluso cuando delta=0 (confirma el stock real)
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',$delta,"CONTEO FISICO POS: {$stockAnt}→{$qty}",$costo,$usuario,$fecha);
                $results[] = $prod['nombre'].": ".($delta >= 0 ? "+$delta" : "$delta");

            } elseif ($accion === 'transferencia') {
                // Débito en almacén origen
                $ke->registrarMovimiento($sku,$ALM,$SUC,'AJUSTE',-abs($qty),"TRANSF_SALIDA→{$destino} POS: $motivo",$costo,$usuario,$fecha);
                $pdo->prepare("INSERT INTO transferencias_detalle (id_transferencia,id_producto,cantidad,costo_unitario,subtotal) VALUES(?,?,?,?,?)")->execute([$idTransferencia,$sku,abs($qty),$costo,abs($qty)*$costo]);
                // Crédito en almacén destino si existe en la misma BD
                if ($destAlmID) {
                    $ke->registrarMovimiento($sku,$destAlmID,$destSucID,'ENTRADA',abs($qty),"TRANSF_ENTRADA desde Alm#{$ALM} POS: $motivo",$costo,$usuario,$fecha);
                }
                $results[] = "-".abs($qty)." ".$prod['nombre']." → $destino";
            }

            // Stock actualizado para caché JS
            $sq2 = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
            $sq2->execute([$sku,$ALM]);
            $stocks_updated[] = ['sku'=>$sku, 'nuevo_stock'=>floatval($sq2->fetchColumn()?:0)];
        }

        if ($pdo->inTransaction()) $pdo->commit();
        $n = count($results);
        $preview = implode(', ', array_slice($results,0,3)) . ($n>3 ? '…':'');
        echo json_encode(['status'=>'success','msg'=>"$n items procesados: $preview",'stocks_updated'=>$stocks_updated]);

    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        error_log('inventario_api error: ' . $e->getMessage());
        echo json_encode(['status'=>'error','msg'=>'Error al procesar inventario. Intente nuevamente.']);
    }
    exit;
}

// Config
$config = array_merge([
    "marca_sistema_nombre" => "PalWeb POS Marinero",
    "marca_empresa_nombre" => "MI TIENDA",
    "tienda_nombre" => "MI TIENDA",
    "cajeros" => [["nombre" => "Admin", "pin" => "0000", "rol" => "admin"]],
    "id_empresa" => 1,
    "id_almacen" => 1,
    "id_sucursal" => 1,
    "mostrar_materias_primas" => false,
    "mostrar_servicios" => true,
    "categorias_ocultas" => []
], $config ?? []);
$systemBrandName = trim((string)($config['marca_sistema_nombre'] ?? 'PalWeb POS Marinero')) ?: 'PalWeb POS Marinero';
$systemBrandLogo = trim((string)($config['marca_sistema_logo'] ?? ''));
$posLoginBrandLogo = trim((string)($config['marca_pos_login_logo'] ?? ''));
$companyBrandName = trim((string)($config['marca_empresa_nombre'] ?? ($config['tienda_nombre'] ?? 'MI TIENDA'))) ?: 'MI TIENDA';
$companyBrandLogo = trim((string)($config['marca_empresa_logo'] ?? ''));
$dynamicCashiers = pos_get_dynamic_cashiers($pdo, $config);

// Banners por sucursal — fondo del carrito y selector de login
$sucursalesBanners = [];
try {
    $stmtSB = $pdo->query("SELECT id, imagen_banner FROM sucursales WHERE imagen_banner IS NOT NULL AND imagen_banner != ''");
    foreach ($stmtSB->fetchAll(PDO::FETCH_ASSOC) as $rowSB) {
        if (file_exists(__DIR__ . '/' . $rowSB['imagen_banner'])) {
            $sucursalesBanners[(int)$rowSB['id']] = $rowSB['imagen_banner'];
        }
    }
} catch (Throwable $e) {}

// Almacenes agrupados por sucursal — para el selector de login multi-almacén
$almacenesPorSucursal = [];
try {
    $stmtAlm = $pdo->query(
        "SELECT id, nombre, id_sucursal FROM almacenes WHERE activo = 1 ORDER BY id_sucursal, nombre ASC"
    );
    foreach ($stmtAlm->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $suc = (int)$row['id_sucursal'];
        $almacenesPorSucursal[$suc][] = ['id' => (int)$row['id'], 'nombre' => (string)$row['nombre']];
    }
} catch (Throwable $e) { /* tabla puede no existir */ }

// Migración de columnas — se ejecuta una única vez y crea un flag file para no repetirla
$_posMigFlag = __DIR__ . '/.pos_schema_v2';
if (!file_exists($_posMigFlag)) {
    try {
        // ADD COLUMN IF NOT EXISTS requiere MySQL 8.0+ o MariaDB 10.3+
        // Para compatibilidad total usamos SHOW COLUMNS como guarda
        $existingCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM productos") as $col) {
            $existingCols[$col['Field']] = true;
        }
        if (!isset($existingCols['favorito']))
            $pdo->exec("ALTER TABLE productos ADD COLUMN favorito TINYINT(1) NOT NULL DEFAULT 0");
        if (!isset($existingCols['codigo_barra_1']))
            $pdo->exec("ALTER TABLE productos ADD COLUMN codigo_barra_1 VARCHAR(64) NULL AFTER codigo");
        if (!isset($existingCols['codigo_barra_2']))
            $pdo->exec("ALTER TABLE productos ADD COLUMN codigo_barra_2 VARCHAR(64) NULL AFTER codigo_barra_1");
        file_put_contents($_posMigFlag, date('Y-m-d H:i:s'));
    } catch (Exception $e) { /* silenciar — la próxima carga lo reintentará */ }
}
unset($_posMigFlag);

// Carga de Datos
$prods = [];
$catsData = [];
$clientsData = [];
$mensajeros = []; // Array para mensajeros

try {
    $cond = ["p.activo = 1"];
    if (!$config['mostrar_materias_primas']) $cond[] = "p.es_materia_prima = 0";
    if (!$config['mostrar_servicios']) $cond[] = "p.es_servicio = 0";
    if (!empty($config['categorias_ocultas'])) {
        $placeholders = implode(',', array_fill(0, count($config['categorias_ocultas']), '?'));
        $cond[] = "p.categoria NOT IN ($placeholders)";
    }
    
    $where = implode(" AND ", $cond);
    $almacenID = intval($config['id_almacen']);
    $params = $config['categorias_ocultas'];

    // Categorias
    $stmtCat = $pdo->prepare("SELECT DISTINCT p.categoria as nombre_categoria, c.color, c.emoji FROM productos p LEFT JOIN categorias c ON p.categoria = c.nombre WHERE $where ORDER BY p.categoria");
    $stmtCat->execute($params);
    $catsData = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

    // Productos
    $sqlProd = "SELECT p.codigo as id, p.codigo, p.nombre, p.precio, p.categoria, p.es_elaborado, p.es_servicio, COALESCE(p.es_combo, 0) AS es_combo,
                p.codigo_barra_1, p.codigo_barra_2,
                COALESCE(p.favorito,0) as favorito,
                (SELECT COALESCE(SUM(s.cantidad), 0)
                 FROM stock_almacen s
                 WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock
                FROM productos p
                WHERE $where
                ORDER BY p.nombre";

    $stmtProd = $pdo->prepare($sqlProd);
    $stmtProd->execute(array_merge([$almacenID], $params));
    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
    $prods = combo_apply_product_rows($pdo, $prods, intval($config['id_empresa'] ?? 1), $almacenID);

    // CARGA DE CLIENTES (NUEVO - YA ORDENADO)
    $stmtCli = $pdo->query("SELECT id, nombre, telefono, direccion, nit_ci FROM clientes WHERE activo = 1 ORDER BY nombre ASC");
    $clientsData = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

    // CARGA DE MENSAJEROS (NUEVO - FILTRADO)
    try {
        $stmtMsj = $pdo->query("SELECT nombre FROM clientes WHERE activo = 1 AND es_mensajero = 1 ORDER BY nombre ASC");
        $mensajeros = $stmtMsj->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $mensajeros = []; } // Si falla (campo no existe aún) queda vacío

} catch (Exception $e) { 
    error_log("POS DB Error: " . $e->getMessage());
    $prods = [];
}

// Procesamiento visual
foreach ($prods as &$p) {
    [$p['has_image'], $p['img_version']] = pos_image_meta((string)$p['codigo']);
    $p['color'] = '#' . substr(sprintf('%08x', crc32($p['nombre']) & 0xFFFFFFFF), 2, 6);
    $p['stock'] = floatval($p['stock']);
} unset($p);

// Query de más vendidos (para sugerencias cuando no hay favoritos)
$mostSoldCodes = [];
try {
    $stmtMs = $pdo->query(
        "SELECT id_producto FROM ventas_detalle
         GROUP BY id_producto ORDER BY SUM(cantidad) DESC LIMIT 10"
    );
    $mostSoldCodes = $stmtMs->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $mostSoldCodes = []; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-ZM015S9N6M"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-ZM015S9N6M');
</script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($systemBrandName); ?> | <?php echo htmlspecialchars($companyBrandName); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/all.min.css" rel="stylesheet">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background-color: #e9ecef; font-family: 'Segoe UI', sans-serif; touch-action: manipulation; user-select: none; }
        .pos-container { display: flex; height: 100vh; width: 100vw; overflow: hidden; }
        .left-panel { background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; z-index: 20; box-shadow: 2px 0 5px rgba(0,0,0,0.05); height: 100%; overflow-y: auto; }
        .top-bar { flex-shrink: 0; }
        .cart-list { flex-grow: 1; overflow-y: auto; background: #fff; border-bottom: 1px solid #eee; min-height: 150px; }
        .controls-wrapper { padding: 8px; padding-bottom: 60px; background: #fff; flex-shrink: 0; border-top: 1px solid #eee; }
        .right-panel { flex-grow: 1; display: flex; flex-direction: column; background: #e9ecef; padding: 10px; overflow: hidden; position: relative; }
        @media (orientation: landscape) { .pos-container { flex-direction: row; } .left-panel { width: 35%; min-width: 340px; max-width: 420px; } .right-panel { width: 65%; } #keypadContainer { display: grid !important; } #toggleKeypadBtn { display: none !important; } }
        @media (orientation: portrait) { .pos-container { flex-direction: column; } .left-panel { width: 100%; height: 45%; border-right: none; border-bottom: 2px solid #ccc; order: 1; } .right-panel { width: 100%; height: 55%; order: 2; padding: 5px; } #keypadContainer { display: none; } #toggleKeypadBtn { display: block; width: 100%; margin-bottom: 5px; } .controls-wrapper { padding-bottom: 10px; } }
        .cart-item { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer; position: relative; font-size: 0.95rem; }
        .cart-item.selected { background: #e8f0fe; border-left: 4px solid #0d6efd; }
        .cart-note { font-size: 0.75rem; color: #d63384; font-style: italic; display: block; }
        .discount-tag { font-size: 0.7rem; background: #dc3545; color: white; padding: 1px 4px; border-radius: 3px; margin-left: 5px; }
        .price-origin-tag { font-size: 0.68rem; background: #d9f7ff; color: #0b7285; padding: 1px 5px; border-radius: 999px; margin-left: 5px; font-weight: 700; border: 1px solid #9eeaf9; }
        .pin-brand-logo {
            width: 203px; height: 76px; object-fit: cover; border-radius: 18px; padding: 6px;
            background: rgba(255,255,255,0.95); border: 1px solid #dbe3ee; box-shadow: 0 12px 28px rgba(15,23,42,.12);
            margin: 0 auto 12px;
        }
        .cart-empty-logo {
            width: 120px; margin-bottom: 15px; opacity: 0.85; object-fit: contain;
        }
        .btn-ctrl {
            height: 48px; border-radius: 10px; font-weight: 700; font-size: 0.95rem;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            background: linear-gradient(175deg, #ffffff 0%, #e9ecef 100%);
            border: 1px solid #c0c8d0;
            color: #333;
            box-shadow: 0 2px 5px rgba(0,0,0,0.12), inset 0 1px 0 rgba(255,255,255,0.9);
            transition: transform 0.1s, box-shadow 0.1s, filter 0.1s;
            letter-spacing: 0.02em;
        }
        .btn-ctrl:hover  { transform: translateY(-1px); filter: brightness(1.06); box-shadow: 0 4px 10px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.9); }
        .btn-ctrl:active { transform: translateY(1px) scale(0.97); filter: brightness(0.92); box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .btn-pay {
            height: 58px; font-size: 1.35rem; width: 100%; border-radius: 6px; cursor: pointer;
            background-color: #0d6efd;
            background-image: linear-gradient(0deg, #0936be, #1049f4);
            border: 1px solid #07288d;
            color: white;
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.4);
            transition: box-shadow 0.15s, filter 0.15s, transform 0.1s;
        }
        .btn-pay:hover  { filter: brightness(1.08); box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 3px 8px 0 rgba(0,0,0,0.35); }
        .btn-pay:active { filter: none; box-shadow: 0 2px 4px -2px rgba(0,0,0,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.12); transform: scale(0.98); }
        .keypad-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
        .action-row { display: flex; gap: 6px; margin-bottom: 6px; }
        .c-yellow {
            background: linear-gradient(175deg, #ffe066 0%, #ffc107 60%, #e0a800 100%) !important;
            color: #212529 !important; border-color: #c79200 !important;
            box-shadow: 0 2px 8px rgba(255,193,7,0.5), inset 0 1px 0 rgba(255,255,255,0.4) !important;
        }
        .c-green {
            background: linear-gradient(175deg, #34c47a 0%, #198754 60%, #136b42 100%) !important;
            color: white !important; border-color: #0e5233 !important;
            box-shadow: 0 2px 8px rgba(25,135,84,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-red {
            background: linear-gradient(175deg, #f06070 0%, #dc3545 60%, #b02a37 100%) !important;
            color: white !important; border-color: #8f2230 !important;
            box-shadow: 0 2px 8px rgba(220,53,69,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-grey {
            background: linear-gradient(175deg, #909aa3 0%, #6c757d 60%, #545b62 100%) !important;
            color: white !important; border-color: #424950 !important;
            box-shadow: 0 2px 8px rgba(108,117,125,0.45), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-blue {
            background: linear-gradient(175deg, #4d96ff 0%, #0d6efd 60%, #0a54d4 100%) !important;
            color: white !important; border-color: #0849a4 !important;
            box-shadow: 0 2px 8px rgba(13,110,253,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-purple {
            background: linear-gradient(175deg, #9b6fe0 0%, #6f42c1 60%, #582fa0 100%) !important;
            color: white !important; border-color: #472690 !important;
            box-shadow: 0 2px 8px rgba(111,66,193,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-orange {
            background: linear-gradient(175deg, #ff9f43 0%, #fd7e14 60%, #d96304 100%) !important;
            color: white !important; border-color: #b85300 !important;
            box-shadow: 0 2px 8px rgba(253,126,20,0.5), inset 0 1px 0 rgba(255,255,255,0.2) !important;
        }
        .c-teal {
            background: linear-gradient(175deg, #3dd6f5 0%, #0dcaf0 60%, #0aa8ca 100%) !important;
            color: #212529 !important; border-color: #0891ae !important;
            box-shadow: 0 2px 8px rgba(13,202,240,0.45), inset 0 1px 0 rgba(255,255,255,0.3) !important;
        }

        /* ================================================================
           ALPHA — action-row (botones - / + / Vaciar / trash)
           Estilo: 3D raised, sombra inferior gruesa, sin border visible
           Ref: loupbrun.ca/buttons — Alpha
        ================================================================ */
        .action-row .btn-ctrl {
            border-width: 0 !important;
            border-radius: 8px !important;
            position: relative !important; overflow: hidden !important;
            transition: box-shadow 0.2s, transform 0.2s !important;
            filter: none !important;
        }
        /* Glossy crystal — capa superior translúcida */
        .action-row .btn-ctrl::after {
            content: ""; display: block; position: absolute; top: 0; left: 0;
            width: 100%; height: 52%; pointer-events: none;
            background: linear-gradient(180deg, rgba(255,255,255,0.28) 0%, rgba(255,255,255,0.06) 60%, rgba(255,255,255,0) 100%);
            border-radius: 8px 8px 0 0;
        }
        /* Hover: glow gris difuso alrededor */
        .action-row .btn-ctrl:hover  { transform: none !important; filter: none !important; }
        .action-row .btn-ctrl.c-yellow:hover { box-shadow: 0 3px 0 0 #a07400, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-green:hover  { box-shadow: 0 3px 0 0 #0e5233, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-red:hover    { box-shadow: 0 3px 0 0 #8f2230, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-blue:hover   { box-shadow: 0 3px 0 0 #0849a4, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl.c-orange:hover { box-shadow: 0 3px 0 0 #b85300, 0 4px 4px -1px rgba(0,0,0,0.4), 0 18px 32px -2px rgba(255,255,255,0.12) inset, 0 0 20px 6px rgba(200,200,200,0.32) !important; }
        .action-row .btn-ctrl:active { transform: translateY(3px) !important; filter: none !important; }

        .action-row .btn-ctrl.c-yellow {
            background: linear-gradient(-45deg, #ffc107, #ffe066) !important; color: #212529 !important;
            box-shadow: 0 3px 0 0 #a07400, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-yellow:active { box-shadow: 0 0 0 0 #7a5800, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #a07400 !important; }
        .action-row .btn-ctrl.c-green {
            background: linear-gradient(-45deg, #198754, #34c47a) !important; color: white !important;
            box-shadow: 0 3px 0 0 #0e5233, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-green:active  { box-shadow: 0 0 0 0 #07361f, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #0e5233 !important; }
        .action-row .btn-ctrl.c-red {
            background: linear-gradient(-45deg, #dc3545, #f06070) !important; color: white !important;
            box-shadow: 0 3px 0 0 #8f2230, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-red:active    { box-shadow: 0 0 0 0 #621520, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #8f2230 !important; }
        .action-row .btn-ctrl.c-blue {
            background: linear-gradient(-45deg, #0d6efd, #4d96ff) !important; color: white !important;
            box-shadow: 0 3px 0 0 #0849a4, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-blue:active   { box-shadow: 0 0 0 0 #052d6a, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #0849a4 !important; }
        .action-row .btn-ctrl.c-orange {
            background: linear-gradient(-45deg, #fd7e14, #ff9f43) !important; color: white !important;
            box-shadow: 0 3px 0 0 #b85300, 0 4px 4px -1px rgba(0,0,0,0.55), 0 4px 6px 1px rgba(0,0,0,0.28), 0 18px 32px -2px rgba(255,255,255,0.12) inset !important;
        }
        .action-row .btn-ctrl.c-orange:active { box-shadow: 0 0 0 0 #7a3600, 0 1px 2px 1px rgba(0,0,0,0.5) inset, 0 -18px 32px -2px rgba(255,255,255,0.1) inset !important; color: #b85300 !important; }

        /* ================================================================
           ETA — keypad-grid (Cnt / % Item / % Total / Nota / Pause / etc.)
           Estilo: vidrio profundo, brillo diagonal, borde doble inferior
           Ref: loupbrun.ca/buttons — Eta
        ================================================================ */
        .keypad-grid .btn-ctrl {
            border-width: 2px !important; border-style: solid !important; border-radius: 7px !important;
            position: relative !important; overflow: hidden !important;
            text-shadow: 0 -1px 0 rgba(0,0,0,0.2) !important;
            transition: box-shadow 0.4s cubic-bezier(0.23,1,0.32,1) !important;
            transform: none !important; filter: none !important;
        }
        .keypad-grid .btn-ctrl::after {
            content: ""; display: block; position: absolute; top: 0; left: 0;
            height: 100%; width: 100%; pointer-events: none;
            transform: rotate(-19deg) translateY(-1.3em) scale(1.05); filter: blur(1px);
            background-image: linear-gradient(-90deg, rgba(255,255,255,0.13) 20%, rgba(255,255,255,0));
        }
        .keypad-grid .btn-ctrl:hover  { transform: none !important; filter: none !important; }
        .keypad-grid .btn-ctrl:active { transform: none !important; filter: none !important; text-shadow: 0 1px 0 rgba(255,255,255,0.2) !important; transition-duration: 0.1s !important; }
        .keypad-grid .btn-ctrl:active::after { background-image: linear-gradient(-90deg, rgba(255,255,255,0.02) 20%, rgba(255,255,255,0)); }

        .keypad-grid .btn-ctrl.c-grey {
            background-color: #6c757d !important; color: white !important;
            border-color: #454d55 !important; border-bottom-color: #2e3338 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(108,117,125,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-grey:active   { background-color: #5f666d !important; color: #2e3338 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(108,117,125,.1) !important; }
        .keypad-grid .btn-ctrl.c-purple {
            background-color: #6f42c1 !important; color: white !important;
            border-color: #472690 !important; border-bottom-color: #2e195c !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(111,66,193,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-purple:active { background-color: #6238ae !important; color: #2e195c !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(111,66,193,.1) !important; }
        .keypad-grid .btn-ctrl.c-blue {
            background-color: #0d6efd !important; color: white !important;
            border-color: #0849a4 !important; border-bottom-color: #052d6a !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(13,110,253,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-blue:active   { background-color: #0c63e4 !important; color: #052d6a !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(13,110,253,.1) !important; }
        .keypad-grid .btn-ctrl.c-red {
            background-color: #dc3545 !important; color: white !important;
            border-color: #8f2230 !important; border-bottom-color: #621520 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(220,53,69,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-red:active    { background-color: #c22d3d !important; color: #8f2230 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(220,53,69,.1) !important; }
        .keypad-grid .btn-ctrl.c-teal {
            background-color: #0dcaf0 !important; color: #212529 !important;
            border-color: #0891ae !important; border-bottom-color: #055e73 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(13,202,240,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-teal:active   { background-color: #0bb5d7 !important; color: #055e73 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(13,202,240,.1) !important; }
        .keypad-grid .btn-ctrl.c-orange {
            background-color: #fd7e14 !important; color: white !important;
            border-color: #b85300 !important; border-bottom-color: #7a3600 !important;
            box-shadow: 0 1px 1px -1px rgba(255,255,255,.9) inset, 0 40px 20px -20px rgba(255,255,255,.15) inset, 0 -1px 1px -1px rgba(0,0,0,.7) inset, 0 -40px 20px -20px rgba(0,0,0,.06) inset, 0 9px 8px -4px rgba(0,0,0,.4), 0 2px 1px -1px rgba(0,0,0,.3), 7px 7px 8px -4px rgba(0,0,0,.1), -7px 7px 8px -4px rgba(0,0,0,.1), 0 -4px 12px 2px rgba(253,126,20,.2) !important;
        }
        .keypad-grid .btn-ctrl.c-orange:active { background-color: #e2720f !important; color: #7a3600 !important; box-shadow: 0 -1px 1px -1px rgba(255,255,255,.4) inset, 0 -40px 20px -20px rgba(255,255,255,.1) inset, 0 1px 1px -1px rgba(0,0,0,.7) inset, 0 40px 20px -20px rgba(0,0,0,.06) inset, 0 7px 8px -4px rgba(0,0,0,.4), 7px 7px 8px -4px rgba(0,0,0,.05), -7px 7px 8px -4px rgba(0,0,0,.05), 0 -4px 12px 2px rgba(253,126,20,.1) !important; }

        /* ================================================================
           ZETA — inv-btn (panel de inventario)
           Estilo: minimalista, gradiente fino 0deg, sombra plana, sin radius
           Ref: loupbrun.ca/buttons — Zeta
        ================================================================ */
        .inv-btn {
            border-width: 1px !important; border-style: solid !important;
            border-radius: 5px !important;
            box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.45) !important;
            transition: filter 0.15s, transform 0.1s !important;
        }
        .inv-btn:hover  { filter: brightness(1.1) !important; transform: translateY(-1px) !important; box-shadow: 0 1px 0 0 rgba(255,255,255,0.2) inset, 0 3px 6px 0 rgba(0,0,0,0.35) !important; }
        .inv-btn:active { transform: scale(0.97) !important; filter: none !important; box-shadow: 0 2px 4px -2px rgba(0,0,0,0.2) inset, 0 1px 3px 0 rgba(0,0,0,0.12) !important; }
        .top-bar .inv-btn { height: auto !important; padding: 2px 6px !important; flex-direction: row !important; border-radius: 5px !important; font-size: 0.8rem !important; gap: 0 !important; }
        .top-bar .inv-btn i { font-size: 0.8rem !important; }
        .category-bar { display: flex; overflow-x: auto; overflow-y: hidden; gap: 8px; margin-bottom: 8px; padding-bottom: 5px; scrollbar-width: none; height: 52px; align-items: center; flex-shrink: 0; }
        body.pos-bars-hidden .category-bar,
        body.pos-bars-hidden #favoritesBar { display: none !important; }
        .category-btn { padding: 8px 16px; border: none; border-radius: 20px; font-weight: 600; background: white; color: #555; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .category-btn.active { background: #0d6efd; color: white; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 10px; overflow-y: auto; padding-bottom: 80px; }
        .product-card { background: white; border-radius: 10px; overflow: hidden; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.08); display: flex; flex-direction: column; position: relative !important; min-height: 180px; }
        .product-card.disabled { opacity: 0.5; pointer-events: none; }
        .product-img-container { width: 100%; aspect-ratio: 4/3; background: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .product-img { width: 100%; height: 100%; object-fit: cover; }
        .placeholder-text { font-size: 1.5rem; color: white; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        .product-info { padding: 8px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .product-name { font-weight: 600; font-size: 0.9rem; line-height: 1.2; margin-bottom: 5px; color: #333; }
        .product-price { color: #0d6efd; font-weight: 800; font-size: 1rem; text-align: right; }
        .stock-badge { position: absolute !important; top: 5px; right: 5px; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.75rem; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .stock-ok { background: #ffc107; color: black; } .stock-zero { background: #dc3545; color: white; }
        #pinOverlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .pin-box { background: white; padding: 30px; border-radius: 15px; text-align: center; width: 320px; box-sizing: border-box; }
        #pinDisplay { min-width: 8ch; display: inline-block; letter-spacing: 0.5ch; height: 1.2em; line-height: 1.2em; }
        #pinAttemptsDots { height: 1.2em; }
        #pinLockMsg { min-height: 0; }
        .cash-status { font-size: 0.7rem; margin-left: 5px; padding: 1px 6px; border-radius: 4px; display: inline-block; vertical-align: middle; }
        .cash-open { background: #d1e7dd; color: #0f5132; } .cash-closed { background: #f8d7da; color: #842029; }
        .pin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; }
        .pin-btn { height: 60px; font-size: 1.5rem; border-radius: 10px; border: 1px solid #ccc; background: #f8f9fa; }
        .toast-container { z-index: 10000; }
        .toast { background-color: rgba(255,255,255,0.95); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15); }
        
        /* MODO SIN IMÁGENES */
        body.mode-no-images .product-img { display: none !important; }
        body.mode-no-images .placeholder-text { display: flex !important; font-size: 2rem; }
        body.mode-no-images .product-img-container { background: linear-gradient(135deg, var(--product-color, #6c757d), var(--product-color-dark, #495057)); }
        
        /* BARRA DE PROGRESO */
        #progressOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        #progressOverlay.active { display: flex; }
        .progress-card { background: white; padding: 30px; border-radius: 15px; width: 90%; max-width: 450px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .progress-title { margin: 0 0 20px 0; color: #333; font-size: 1.2rem; font-weight: 600; }
        .progress-bar-container { background: #e0e0e0; height: 28px; border-radius: 14px; overflow: hidden; margin-bottom: 15px; position: relative; }
        .progress-bar-fill { background: linear-gradient(90deg, #198754, #20c997); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.95rem; }
        .progress-detail { margin: 0; text-align: center; color: #666; font-size: 0.9rem; }
        
        /* BADGES ADICIONALES */
        .offline-badge { background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; margin-left: 5px; display: none; }
        .offline-badge.active { display: inline-block; }
        
        /* NUEVOS ESTILOS */
        .bg-dark-blue { background-color: #2c3e50 !important; }
        .btn-filter-active { background-color: #198754 !important; color: white !important; border-color: #198754 !important; }

        /* FIX: BOTÓN SYNC VISIBLE */
        #btnSync { z-index: 100; position: relative; margin-right: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }

        /* Reloj digital 7 segmentos */
        #posClock {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 1.3rem;
            font-weight: bold;
            letter-spacing: 0.12em;
            background: #0a100a;
            color: #00e676;
            text-shadow: 0 0 6px #00e676, 0 0 14px rgba(0,230,118,0.45);
            padding: 0 14px;
            border-radius: 8px;
            white-space: nowrap;
            flex-shrink: 0;
            min-width: 108px;
            text-align: center;
            border: 1px solid #143314;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.7), 0 1px 3px rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }
        #posClock.blink-colon .clock-sep { opacity: 0.2; }

        /* Panel de atajos de teclado */
        #hotkeyPanel { position: absolute; top: 50px; right: 10px; z-index: 1000;
            background: white; border-radius: 10px; padding: 12px 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            min-width: 280px; font-size: 0.8rem; display: none; }
        #hotkeyPanel.show { display: block; }
        #hotkeyPanel kbd { background: #2c3e50; color: white; border: 1px solid #1a252f;
            border-radius: 4px; padding: 2px 6px; font-size: 0.75rem; }

        /* Barra de favoritos */
        .fav-card { flex-shrink: 0; width: 88px; min-height: 54px;
            background: white; border-radius: 8px; cursor: pointer;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 5px 6px;
            border: 2px solid transparent; transition: border-color 0.15s, transform 0.1s;
            display: flex; flex-direction: column; justify-content: center; }
        .fav-card:hover { border-color: #ffc107; transform: translateY(-1px); }
        .fav-card .fav-name { font-size: 0.68rem; font-weight: 600; line-height: 1.2;
            overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; color: #333; }
        .fav-card .fav-price { font-size: 0.75rem; color: #0d6efd; font-weight: 800; margin-top: 2px; }
        .fav-card.out-of-stock { opacity: 0.5; }

        /* Estrella ★ en card principal */
        .star-btn { position: absolute; top: 5px; left: 5px; z-index: 11;
            background: rgba(255,255,255,0.88); border: none; border-radius: 50%;
            width: 22px; height: 22px; display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; cursor: pointer; padding: 0; line-height: 1;
            transition: transform 0.1s; }
        .star-btn:hover { transform: scale(1.25); }
        .star-btn.active { color: #ffc107; }
        .star-btn.inactive { color: #bbb; }
        
        /* Panel de inventario */
        #inventarioPanel { display: none; }
        .stock-inv { background: #6f42c1 !important; color: #fff !important; }  /* badge morado en modo inv para stock=0 */
        .inv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 8px; }
        .inv-btn {
            height: 68px; border-radius: 12px; font-weight: 700; font-size: 0.78rem;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 4px; cursor: pointer;
            border: 1px solid rgba(0,0,0,0.2);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.2);
            transition: filter 0.15s, transform 0.12s, box-shadow 0.12s;
            letter-spacing: 0.02em;
        }
        .inv-btn:hover  { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 5px 14px rgba(0,0,0,0.28), inset 0 1px 0 rgba(255,255,255,0.2); }
        .inv-btn:active { transform: scale(0.95) translateY(1px); filter: brightness(0.88); box-shadow: 0 1px 4px rgba(0,0,0,0.2); }
        .inv-btn i { font-size: 1.3rem; }
        #btnInventario.inv-active { background: #2c3e50 !important; color: #ffc107 !important;
                                    border-color: #ffc107 !important; }

        /* Estilos adicionales para modal de pago grande */
        .total-display-large { font-size: 3rem; font-weight: 800; color: #198754; text-shadow: 1px 1px 0px #fff; }
        .bg-primary-custom { background: var(--hero-gradient, #2c3e50) !important; }

        /* ── Banner de instalación PWA ── */
        #pwaBanner {
            display: none; /* JS lo muestra si no está instalado */
            margin-bottom: 14px;
            background: linear-gradient(135deg, #1a1f2e 0%, #2c3e50 100%);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            padding: 10px 12px;
            text-align: left;
            color: #fff;
        }
        #pwaBanner .pwa-title {
            font-size: 0.78rem; font-weight: 700; margin-bottom: 3px;
            display: flex; align-items: center; gap: 6px;
        }
        #pwaBanner .pwa-sub {
            font-size: 0.7rem; color: rgba(255,255,255,0.65); margin-bottom: 8px; line-height: 1.35;
        }
        #pwaBanner .pwa-ios-steps {
            font-size: 0.68rem; color: rgba(255,255,255,0.7);
            padding: 6px 8px; background: rgba(255,255,255,0.07);
            border-radius: 7px; margin-bottom: 8px; line-height: 1.6;
        }
        #btnInstallPwa {
            width: 100%; padding: 7px 0; font-size: 0.8rem; font-weight: 700;
            border-radius: 8px; border: none; cursor: pointer;
            background: linear-gradient(135deg, #0d6efd, #0a54d4);
            color: #fff; transition: filter 0.15s;
        }
        #btnInstallPwa:hover { filter: brightness(1.12); }
        #btnInstallPwa:active { filter: brightness(0.9); }
        #btnDismissPwa {
            margin-top: 5px; width: 100%; padding: 3px 0; font-size: 0.7rem;
            background: none; border: none; color: rgba(255,255,255,0.4);
            cursor: pointer; text-decoration: underline;
        }
        #sessionLoginModal .modal-content {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 18px 60px rgba(0,0,0,0.28);
        }
        #sessionLoginModal .modal-header {
            background: linear-gradient(135deg, #1f2937 0%, #334155 100%);
            color: #fff;
            border-bottom: none;
            padding: 0.8rem 1rem;
        }
        #sessionLoginModal .modal-body {
            padding: 1rem 1rem 1.1rem;
            background: #f8fafc;
        }
        #sessionLoginModal .mini-login-copy {
            font-size: 0.82rem;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        #sessionLoginModal .mini-pin-display {
            font-size: 1.9rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #0f172a;
            text-align: center;
            margin-bottom: 0.75rem;
            min-height: 2.3rem;
        }
        #sessionLoginModal .mini-pin-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        #sessionLoginModal .mini-pin-grid .pin-btn {
            min-height: 56px;
        }
        #sessionLoginReason {
            font-size: 0.78rem;
            color: #b91c1c;
            min-height: 1.15rem;
            margin-bottom: 0.55rem;
            text-align: center;
        }
    </style>

<base href="<?php echo htmlspecialchars($posDocumentBase, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="manifest-pos.php">
<meta name="theme-color" content="#2c3e50">
<link rel="apple-touch-icon" href="icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<script>
    // Registro del Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            const posScope = <?php echo json_encode($posScopePath); ?>;
            const rootScope = new URL('./', document.baseURI).href;
            navigator.serviceWorker.getRegistration(rootScope)
                .then(reg => {
                    if (reg && reg.scope === rootScope) return reg.unregister();
                })
                .catch(() => {})
                .finally(() => navigator.serviceWorker.register('service-worker.js', { scope: posScope }))
                .then(reg => console.log('SW registrado: ', reg))
                .catch(err => console.log('SW error: ', err));
        });
    }
</script>


</head>
<body>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<script>
// Stub temporal: evita ReferenceError si el usuario toca el PIN antes de que pos1.js cargue.
// function typePin() en pos1.js sobreescribe window.typePin automáticamente al cargar.
window._pinStub = '';
window.typePin = function(v) {
    if (v === 'C') window._pinStub = '';
    else if (window._pinStub.length < 4) window._pinStub += String(v);
    var d = document.getElementById('pinDisplay');
    if (d) d.innerText = '\u2022'.repeat(window._pinStub.length);
};
window.verifyPin = function() { /* se activa tras cargar pos1.js */ };
</script>

<div id="pinOverlay">
    <div class="pin-box">

        <!-- Banner de instalación PWA — JS lo muestra solo si no está instalado -->
        <div id="pwaBanner">
            <div class="pwa-title">
                <i class="fas fa-download" style="font-size:0.9rem;color:#4d96ff;"></i>
                Instalar aplicación
            </div>
            <div class="pwa-sub" id="pwaSubText">
                Instala el POS en este dispositivo para usarlo sin internet y acceder más rápido.
            </div>
            <!-- Instrucciones manuales iOS (se muestran solo en Safari/iOS) -->
            <div class="pwa-ios-steps" id="pwaIosSteps" style="display:none;">
                1. Toca <strong>Compartir</strong> <i class="fas fa-share-from-square"></i><br>
                2. Selecciona <strong>"Agregar a pantalla de inicio"</strong><br>
                3. Pulsa <strong>Agregar</strong>
            </div>
            <button id="btnInstallPwa" onclick="triggerPwaInstall()">
                <i class="fas fa-download me-1"></i> Instalar ahora
            </button>
            <button id="btnDismissPwa" onclick="dismissPwaBanner()">Ahora no</button>
        </div>

        <?php $loginLogo = !empty($posLoginBrandLogo) ? $posLoginBrandLogo : $systemBrandLogo; ?>
        <?php if ($loginLogo !== ''): ?>
            <img src="<?php echo htmlspecialchars($loginLogo); ?>" alt="<?php echo htmlspecialchars($systemBrandName); ?>" class="pin-brand-logo">
        <?php endif; ?>
        <h3 class="mb-1"><?php echo htmlspecialchars($systemBrandName); ?></h3>
        <div class="small text-muted mb-2"><?php echo htmlspecialchars($companyBrandName); ?></div>
        <div class="fs-1 mb-2" id="pinDisplay">••••</div>
        <div id="pinAttemptsDots" class="mb-2 text-muted small"></div>
        <div id="pinLockMsg" class="alert alert-danger py-1 mb-2 small" style="visibility:hidden; height:0; overflow:hidden; padding:0; margin:0; border:none;"></div>
        <div class="pin-grid" id="pinGrid">
            <button class="pin-btn" onclick="typePin(1)">1</button><button class="pin-btn" onclick="typePin(2)">2</button><button class="pin-btn" onclick="typePin(3)">3</button>
            <button class="pin-btn" onclick="typePin(4)">4</button><button class="pin-btn" onclick="typePin(5)">5</button><button class="pin-btn" onclick="typePin(6)">6</button>
            <button class="pin-btn" onclick="typePin(7)">7</button><button class="pin-btn" onclick="typePin(8)">8</button><button class="pin-btn" onclick="typePin(9)">9</button>
            <button class="pin-btn c-red" onclick="typePin('C')">C</button><button class="pin-btn" onclick="typePin(0)">0</button><button class="pin-btn c-green" onclick="verifyPin()">OK</button>
        </div>

        <!-- Selector de almacén — aparece tras PIN exitoso si la sucursal tiene múltiples almacenes -->
        <div id="almacenPicker" style="display:none; margin-top:18px; text-align:left;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-warehouse text-primary"></i>
                <span class="fw-semibold" style="font-size:0.88rem;">Selecciona el almacén de trabajo:</span>
            </div>
            <select id="almacenSelect" class="form-select form-select-sm mb-3"></select>
            <button class="btn btn-primary btn-sm w-100" onclick="confirmAlmacenSelection()">
                <i class="fas fa-check me-1"></i> Confirmar almacén
            </button>
        </div>
    </div>
</div>

<div class="modal fade" id="sessionLoginModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:360px;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold mb-0"><i class="fas fa-user-shield me-2"></i>Inicio de sesion requerido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mini-login-copy">Vuelve a autenticarte para continuar esta accion sin cerrar el POS.</div>
                <div id="sessionLoginReason"></div>
                <div class="mini-pin-display" id="sessionPinDisplay">....</div>
                <div class="mini-pin-grid">
                    <button class="pin-btn" onclick="typePin(1)">1</button><button class="pin-btn" onclick="typePin(2)">2</button><button class="pin-btn" onclick="typePin(3)">3</button>
                    <button class="pin-btn" onclick="typePin(4)">4</button><button class="pin-btn" onclick="typePin(5)">5</button><button class="pin-btn" onclick="typePin(6)">6</button>
                    <button class="pin-btn" onclick="typePin(7)">7</button><button class="pin-btn" onclick="typePin(8)">8</button><button class="pin-btn" onclick="typePin(9)">9</button>
                    <button class="pin-btn c-red" onclick="typePin('C')">C</button><button class="pin-btn" onclick="typePin(0)">0</button><button class="pin-btn c-green" onclick="verifyPin()">OK</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="pos-container">
    <div class="left-panel">
        <div class="px-2 pt-2 pb-1 bg-dark-blue text-white top-bar shadow-sm" style="display:flex; flex-direction:column; gap:6px;">
            <!-- Fila 1: Cajero + Estado de conexión -->
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1">
                    <i class="fas fa-user" style="font-size:0.8rem; opacity:0.75;"></i>
                    <span id="cashierName" style="font-weight:700; font-size:0.9rem; line-height:1;">Cajero</span>
                    <span id="cashStatusBadge" class="cash-status cash-closed">CERRADA</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button id="btnSync" onclick="syncOfflineQueue()" class="btn btn-sm btn-warning text-dark px-2 d-none" title="Sincronizar cola offline">
                        <i class="fas fa-sync"></i>
                    </button>
                    <a href="cocina_tickets.php" target="_blank" rel="noopener" class="btn btn-sm btn-light text-danger px-2 inv-btn" title="Pantalla de Cocina" style="font-size: 1.1rem; padding: 2px 8px !important;">
                        <i class="fas fa-fire"></i>
                    </a>
                    <button id="btnCaja" onclick="checkCashRegister()" class="btn btn-sm btn-light text-primary px-2 inv-btn border-primary shadow-sm" title="Caja" style="font-size: 1.1rem; padding: 2px 8px !important;">
                        <i class="fas fa-cash-register"></i>
                    </button>
                    <span id="netStatus" class="badge bg-success px-2" style="font-size:0.7rem;">
                        <i class="fas fa-wifi"></i>
                    </span>
                </div>
            </div>
            <!-- Fila 2: Sucursal/Almacén + Botones de acción -->
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-light text-dark" style="font-size:0.68rem; padding:3px 6px;" title="Marca">
                        <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($companyBrandName); ?>
                    </span>
                    <span class="badge bg-secondary" style="font-size:0.68rem; padding:3px 6px;" title="Sucursal">
                        <i class="fas fa-building"></i> <span id="ctxSucursalBadge"><?php echo intval($config['id_sucursal'] ?? 1); ?></span>
                    </span>
                    <span class="badge bg-secondary" style="font-size:0.68rem; padding:3px 6px;" title="Almacén">
                        <i class="fas fa-warehouse"></i> <span id="ctxAlmacenBadge"><?php echo intval($config['id_almacen']); ?></span>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <a href="customer_display.php" target="_blank" class="btn btn-sm btn-outline-info px-2 inv-btn" title="Pantalla del cliente">
                        <i class="fas fa-desktop"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-warning position-relative px-2 animate__animated inv-btn" id="btnSelfOrders" onclick="openSelfOrdersModal()" title="Autopedidos">
                        <i class="fas fa-mobile-alt"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="selfOrderBadge" style="font-size:0.6rem;">0</span>
                    </button>
                    <button onclick="showHistorialModal()" class="btn btn-sm btn-light text-success px-2 inv-btn" title="Historial de Ventas">
                        <i class="fas fa-history"></i>
                    </button>
                    <button onclick="showParkedOrders()" class="btn btn-sm btn-light text-warning px-2 inv-btn" title="Pausados">
                        <i class="fas fa-pause"></i>
                    </button>
                    <a href="reportes_caja.php" target="_blank" rel="noopener" class="btn btn-sm btn-light text-primary px-2 inv-btn" title="Reportes">
                        <i class="fas fa-chart-line"></i>
                    </a>

                </div>
            </div>
        </div>

        <!-- Banner visible solo en modo inventario -->
        <div id="invModeBanner" style="display:none;background:#2c3e50;color:#ffc107;padding:7px 12px;font-weight:700;font-size:0.82rem;text-align:center;letter-spacing:0.04em;">
            <i class="fas fa-boxes me-1"></i> MODO INVENTARIO — Agrega los productos con sus cantidades y usa los botones de abajo
        </div>

        <?php
        // Fondo del carrito: banner de la sucursal activa (si existe), o logo corporativo
        $cartBannerUrl = '';
        $sucActiva = (int)($config['id_sucursal'] ?? 1);
        if (!empty($sucursalesBanners[$sucActiva])) {
            $cartBannerUrl = '/' . ltrim($sucursalesBanners[$sucActiva], '/');
        }
        $cartBgStyle = $cartBannerUrl
            ? "background-image: linear-gradient(rgba(255,255,255,0.84),rgba(255,255,255,0.84)), url('" . htmlspecialchars($cartBannerUrl) . "'); background-size: cover; background-position: center;"
            : '';
        ?>
        <div class="cart-list" id="cartContainer" style="<?php echo $cartBgStyle; ?>">
            <div class="text-center text-muted h-100 d-flex flex-column align-items-center justify-content-center" id="cartEmptyState">
                <?php
                // Logo en estado vacío: banner sucursal → logo empresa → logo sistema
                $cartLogo = $sucursalesBanners[$sucActiva] ?? ($config['marca_empresa_logo'] ?? $config['marca_sistema_logo'] ?? '');
                if ($cartLogo !== '' && file_exists(__DIR__ . '/' . $cartLogo)):
                ?>
                    <img src="/<?php echo htmlspecialchars(ltrim($cartLogo, '/')); ?>?v=<?php echo filemtime(__DIR__ . '/' . $cartLogo) ?: 1; ?>" class="cart-empty-logo" style="width:180px; max-height:90px; border-radius:8px; margin-bottom:10px; object-fit:contain; opacity:0.55;" alt="Logo">
                <?php endif; ?>
                <i class="fas fa-shopping-basket fa-3x mb-2 opacity-25"></i><p class="small">Carrito Vacío</p>
            </div>
        </div>

        <div class="px-3 py-2 bg-light border-top totals-area">
            <div class="d-flex justify-content-between align-items-end">
                <div class="small text-muted">Items: <span id="totalItems">0</span> &nbsp;Prod: <span id="totalProds">0</span></div>
                <div class="fs-3 fw-bold text-dark text-end" id="totalAmount">$0.00</div>
            </div>
        </div>

        <div class="controls-wrapper">
            <button id="toggleKeypadBtn" class="btn btn-sm btn-outline-secondary mb-2" style="width:100%; display:none;" onclick="toggleMobileKeypad()">
                <i class="fas fa-keyboard"></i> Teclado
            </button>

            <!-- Botón toggle inventario — solo admin -->
            <button id="btnInventario" class="btn btn-sm btn-outline-warning w-100 mb-2 fw-bold" style="display:none;" onclick="toggleInventarioMode()">
                <i class="fas fa-boxes me-1"></i> INVENTARIO
            </button>

            <!-- Panel POS clásico -->
            <div id="posPanel">
                <div id="keypadContainer">
                    <div class="action-row">
                        <button class="btn-ctrl c-yellow flex-grow-1" onclick="modifyQty(-1)"><i class="fas fa-minus"></i></button>
                        <button class="btn-ctrl c-green flex-grow-1" onclick="modifyQty(1)"><i class="fas fa-plus"></i></button>
                        <button class="btn-ctrl c-red flex-grow-1" onclick="removeItem()"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="keypad-grid">
                        <button class="btn-ctrl c-grey" onclick="askQty()">Cnt</button>
                        <button class="btn-ctrl c-purple" onclick="applyDiscount()">% Item</button>
                        <button id="btnGlobalDiscount" class="btn-ctrl c-purple" onclick="applyGlobalDiscount()">% Total</button>
                        <button class="btn-ctrl c-blue" onclick="addNote()"><i class="fas fa-pen"></i></button>
                        <button class="btn-ctrl c-orange" onclick="parkOrder()"><i class="fas fa-pause"></i></button>
                        <button class="btn-ctrl c-red" onclick="clearCart()">Vaciar</button>
                        <button class="btn-ctrl c-teal" onclick="showHistorialModal()"><i class="fas fa-history"></i> HIST</button>
                        <button class="btn-ctrl c-blue" onclick="openOrderTemplatesModal()"><i class="fas fa-bookmark"></i> PLANT</button>
                        <button id="btnSyncKeypad" class="btn-ctrl c-orange" style="opacity:0.4;" onclick="syncManual()" disabled><i class="fas fa-cloud-upload-alt"></i> 0</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-pay fw-bold shadow-sm" onclick="openPaymentModal()">
                    <i class="fas fa-check-circle me-2"></i> COBRAR
                </button>
            </div>

            <!-- Panel de inventario -->
            <div id="inventarioPanel">
                <div class="action-row mb-2">
                    <button class="btn-ctrl c-yellow flex-grow-1" onclick="modifyQty(-1)" title="Restar cantidad"><i class="fas fa-minus"></i></button>
                    <button class="btn-ctrl c-green flex-grow-1" onclick="modifyQty(1)" title="Sumar cantidad"><i class="fas fa-plus"></i></button>
                    <button class="btn-ctrl c-red flex-grow-1" onclick="clearCart()" title="Vaciar carrito">Vaciar</button>
                </div>
                <div class="inv-grid">
                    <button class="inv-btn" style="background:linear-gradient(175deg,#34c47a 0%,#198754 60%,#136b42 100%);color:white;" onclick="openInvModal('entrada')">
                        <i class="fas fa-truck-loading"></i> Entrada
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#f06070 0%,#dc3545 60%,#b02a37 100%);color:white;" onclick="openInvModal('merma')">
                        <i class="fas fa-trash-alt"></i> Merma
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#ffe066 0%,#ffc107 60%,#e0a800 100%);color:#212529;" onclick="openInvModal('ajuste')">
                        <i class="fas fa-sliders-h"></i> Ajuste
                    </button>
                    <button class="inv-btn" id="btnPriceMode" style="background:linear-gradient(175deg,#3dd6f5 0%,#0dcaf0 60%,#0aa8ca 100%);color:#212529;" onclick="openPriceModeModal()">
                        <i class="fas fa-tags"></i> <span id="priceModeLabel">Precio</span>
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#4d96ff 0%,#0d6efd 60%,#0a54d4 100%);color:white;" onclick="openInvModal('transferencia')">
                        <i class="fas fa-exchange-alt"></i> Transferir
                    </button>
                    <button class="inv-btn" style="background:linear-gradient(175deg,#909aa3 0%,#6c757d 60%,#545b62 100%);color:white;" onclick="openBarcodeModal()">
                        <i class="fas fa-barcode"></i> Cód. Barras
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <div class="d-flex align-items-stretch gap-2 mb-2" style="flex-shrink:0;">
            <div class="input-group shadow-sm" style="flex:1;">
                <button id="btnRefresh" onclick="refreshProducts()" class="btn btn-primary border-0"><i class="fas fa-sync-alt"></i></button>

                <button id="btnForceDownload" onclick="forceDownloadProducts()" class="btn btn-danger border-0" title="Forzar descarga del servidor">
                    <i class="fas fa-cloud-download-alt"></i>
                </button>

                <button id="btnStockFilter" class="btn btn-light border-0" onclick="toggleStockFilter()" title="Solo con existencias">
                    <i class="fas fa-cubes"></i>
                </button>

                <button id="btnToggleImages" class="btn btn-light border-0" onclick="toggleImages()" title="Mostrar/Ocultar imagenes">
                    <i class="fas fa-image"></i>
                </button>

                <button id="btnToggleBars" class="btn btn-light border-0" onclick="toggleBars()" title="Mostrar/Ocultar barras de categorías y favoritos">
                    <i class="fas fa-bars"></i>
                </button>

                <span class="input-group-text bg-white border-0"><i class="fas fa-search"></i></span>
                <input type="text" id="searchInput" class="form-control border-0" placeholder="Buscar / Escanear..." onkeyup="filterProducts()" autocomplete="off" autocorrect="off" spellcheck="false">
                <button class="btn btn-light border-0" onclick="document.getElementById('searchInput').value='';filterProducts()">X</button>
                <button id="btnHotkeyHelp" class="btn btn-light border-0" onclick="toggleHotkeyPanel()" title="Atajos de teclado">⌨</button>
            </div>
            <div id="posClock"><span class="clock-h">12</span><span class="clock-sep">:</span><span class="clock-m">00</span><span class="clock-ampm" style="font-size:0.7rem; letter-spacing:0; margin-left:3px; opacity:0.85;">AM</span></div>
            <button id="btnLockScreen" class="btn btn-outline-secondary btn-sm ms-2" onclick="lockPos()" title="Bloquear terminal"><i class="fas fa-lock"></i></button>
        </div>
        <div id="hotkeyPanel">
            <div class="fw-bold mb-2 text-secondary" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em;">Atajos de Teclado</div>
            <div class="row g-1">
                <div class="col-6"><kbd>F1</kbd> Abrir/Cerrar Caja</div>
                <div class="col-6"><kbd>F2</kbd> Descuento Global</div>
                <div class="col-6"><kbd>F3</kbd> Autopedidos</div>
                <div class="col-6"><kbd>F4</kbd> Pausar Pedido</div>
                <div class="col-6"><kbd>F5</kbd> Ir a Búsqueda</div>
                <div class="col-6"><kbd>F6</kbd> Historial Tickets</div>
                <div class="col-6"><kbd>F7</kbd> Nuevo Cliente</div>
                <div class="col-6"><kbd>F8</kbd> Forzar Descarga</div>
                <div class="col-6"><kbd>F9</kbd> Descuento Ítem</div>
                <div class="col-6"><kbd>F10</kbd> Modificar Cantidad</div>
                <div class="col-6"><kbd>Del</kbd> Eliminar Ítem</div>
                <div class="col-6"><kbd>Enter</kbd> Cobrar</div>
            </div>
        </div>
        <div class="category-bar" id="categoryBar">
            <button class="category-btn active" onclick="filterCategory('all', this)">TODOS</button>
            <?php foreach($catsData as $catData): ?>
                <?php
                    $bgColor = htmlspecialchars($catData['color'] ?? '#ffffff');
                    $textColor = (isset($catData['color']) && sscanf($catData['color'], "#%02x%02x%02x") == 3 && array_sum(sscanf($catData['color'], "#%02x%02x%02x")) < 382) ? '#ffffff' : '#000000';
                    $emoji = htmlspecialchars($catData['emoji'] ?? '');
                ?>
                <button class="category-btn" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>;" onclick="filterCategory('<?php echo htmlspecialchars($catData['nombre_categoria']); ?>', this)">
                    <?php echo $emoji . ' ' . htmlspecialchars($catData['nombre_categoria']); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <div id="favoritesBar" style="flex-shrink:0; display:none;">
            <div class="d-flex align-items-center gap-2 mb-1 px-1" style="min-height:32px;">
                <span id="favBarLabel" class="text-warning fw-bold" style="font-size:0.72rem; white-space:nowrap; flex-shrink:0;">
                    <i class="fas fa-star"></i> FAVORITOS
                </span>
                <div id="favCardsRow" class="d-flex gap-2 pb-1" style="overflow-x:auto; scrollbar-width:none; flex:1;"></div>
            </div>
            <div style="height:1px; background:#dee2e6; margin-bottom:6px;"></div>
        </div>
        <div class="product-grid" id="productContainer"></div>
    </div>
</div>

<div class="modal fade" id="newClientModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title">Nuevo Cliente</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="mb-2">
                    <label class="small fw-bold">Nombre *</label>
                    <input type="text" id="ncNombre" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                    <label class="small">Teléfono</label>
                    <input type="text" id="ncTel" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="small">Dirección</label>
                    <input type="text" id="ncDir" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="small">NIT/CI</label>
                    <input type="text" id="ncNit" class="form-control form-control-sm">
                </div>
                <button class="btn btn-success w-100 btn-sm fw-bold" onclick="saveNewClient()">Guardar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Caja</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="cashModalBody"></div></div></div></div>
<div class="modal fade" id="parkModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-warning"><h5 class="modal-title">Espera</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="list-group list-group-flush" id="parkList"></div></div></div></div></div>

<!-- MODAL ANULAR VENTA ─────────────────────────────────────────────────────
     Audit trail: registra usuario del sistema + motivo + timestamp.
     Solo ventas de la sesión activa. Requiere credenciales del sistema. -->
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-ban me-2"></i>Anular Venta #<span id="voidTicketId">-</span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pb-2">
                <p class="text-muted small mb-3">Esta acción es permanente y quedará registrada en el audit trail con su nombre y hora exacta.</p>
                <form onsubmit="confirmVoid(); return false;">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Motivo de Anulación <span class="text-danger">*</span></label>
                        <textarea id="voidMotivo" class="form-control form-control-sm" rows="3"
                            placeholder="Ej: Error en precio, cliente canceló, producto incorrecto..."
                            maxlength="200"></textarea>
                        <div class="form-text text-muted" style="font-size:0.7rem">Mínimo 5 caracteres. Quedará firmado con su usuario del sistema.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Usuario del sistema</label>
                        <input type="text" id="voidAuthUser" class="form-control form-control-sm"
                            maxlength="100" placeholder="Ej: admin" autocomplete="username">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Contraseña del sistema</label>
                        <input type="password" id="voidAuthPass" class="form-control form-control-sm"
                            maxlength="120" placeholder="••••••" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-danger w-100 fw-bold btn-void-confirm">
                        <i class="fas fa-ban me-1"></i> CONFIRMAR ANULACIÓN
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="historialModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Historial de Tickets - Sesión Actual</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="historialModalBody">
                <div class="text-center p-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="mt-2 text-muted">Cargando tickets...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Estilos para el historial */
    .ticket-row { cursor: pointer; transition: background 0.1s; border-left: 5px solid transparent; }
    .ticket-row:hover { background-color: #f1f3f5; }
    .row-efectivo { border-left-color: #198754; } 
    .row-transfer { border-left-color: #0d6efd; } 
    .row-gasto { border-left-color: #fd7e14; } 
    .row-reserva { border-left-color: #6f42c1; background-color: #f3f0ff; }
    .row-llevar { border-left-color: #0dcaf0; }
    .row-refund { border-left-color: #dc3545; background-color: #ffeaea !important; }
    .badge-pago { font-size: 0.8rem; font-weight: 500; width: 90px; display: inline-block; text-align: center; }
    .bg-efectivo { background-color: #d1e7dd; color: #0f5132; }
    .bg-transfer { background-color: #cfe2ff; color: #084298; }
    .bg-gasto { background-color: #ffe5d0; color: #994d07; }
    .bg-refund-badge { background-color: #f8d7da; color: #842029; }
    .detail-row { background-color: #fafafa; border-left: 5px solid #ccc; font-size: 0.9rem; }
    .kpi-mini { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
</style>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // INYECCIÓN DE DATOS GLOBALES
    let currentSaleTotal = 0;
    let currentPaymentMode = 'cash';
    window.POS_CSRF_TOKEN_VALUE = <?php echo json_encode($POS_CSRF_TOKEN); ?>;
    const PRODUCTS_DATA = <?php echo json_encode($prods); ?>;
    // CAJEROS_CONFIG no incluye el pin — los PINs viven solo en IndexedDB (cargados via load_cashiers tras autenticarse)
    const CAJEROS_CONFIG = <?php
        echo json_encode(array_map(static function(array $c): array {
            unset($c['pin']);
            return $c;
        }, $dynamicCashiers ?? []));
    ?>;
    const MOST_SOLD_CODES = <?php echo json_encode($mostSoldCodes); ?>;
    let CLIENTS_DATA = <?php echo json_encode($clientsData); ?>; // Lista inicial
    const MESSENGERS_DATA = <?php echo json_encode($mensajeros ?? []); ?>;
    // Almacenes disponibles por sucursal (id_sucursal → [{id, nombre}])
    // Usado para el selector multi-almacén en la pantalla de login con PIN
    const ALMACENES_BY_SUCURSAL = <?php echo json_encode($almacenesPorSucursal); ?>;
    // Banners de sucursal (id_sucursal → URL relativa) — fondo dinámico del carrito
    const SUCURSALES_BANNERS = <?php echo json_encode($sucursalesBanners); ?>;

    window.posJsonHeaders = function(extra) {
        const headers = Object.assign({
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.POS_CSRF_TOKEN_VALUE || ''
        }, extra || {});
        return headers;
    };

    // --- LÓGICA DE CLIENTES ---
    
    function fillClientData(select) {
        const opt = select.options[select.selectedIndex];
        if(!opt.value) {
            document.getElementById('cliPhone').value = '';
            document.getElementById('cliAddr').value = '';
            if (typeof window.afterClientSelected === 'function') {
                window.afterClientSelected(select);
            }
            return;
        }
        document.getElementById('cliPhone').value = opt.getAttribute('data-tel') || '';
        document.getElementById('cliAddr').value = opt.getAttribute('data-dir') || '';
        if (typeof window.afterClientSelected === 'function') {
            window.afterClientSelected(select);
        }
    }

    function openNewClientModal() {
        const modal = new bootstrap.Modal(document.getElementById('newClientModal'));
        document.getElementById('ncNombre').value = ""; 
        document.getElementById('ncTel').value = '';
        document.getElementById('ncDir').value = '';
        document.getElementById('ncNit').value = '';
        modal.show();
    }

    function toggleHotkeyPanel() {
        const panel = document.getElementById('hotkeyPanel');
        if (panel) panel.classList.toggle('show');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const panel = document.getElementById('hotkeyPanel');
            if (panel) panel.classList.remove('show');
        }
    });

    function saveNewClient() {
        const data = {
            nombre: document.getElementById('ncNombre').value,
            telefono: document.getElementById('ncTel').value,
            direccion: document.getElementById('ncDir').value,
            nit_ci: document.getElementById('ncNit').value
        };

        if(!data.nombre) return alert("Nombre obligatorio");

        fetch('pos.php?api_client=1', {
            method: 'POST',
            headers: window.posJsonHeaders ? window.posJsonHeaders() : {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                CLIENTS_DATA.push(res.client);
                const list = document.getElementById('cliName');
                if (list) {
                    const opt = document.createElement('option');
                    opt.value = res.client.nombre;
                    opt.text = res.client.nombre;
                    opt.setAttribute('data-tel', res.client.telefono);
                    opt.setAttribute('data-dir', res.client.direccion);
                    list.add(opt);
                    list.value = res.client.nombre;
                    if (typeof window.setSuppressClientAutoLoad === 'function') {
                        window.setSuppressClientAutoLoad(true);
                    }
                    fillClientData(list);
                    setTimeout(() => {
                        if (typeof window.setSuppressClientAutoLoad === 'function') {
                            window.setSuppressClientAutoLoad(false);
                        }
                    }, 150);
                }
                bootstrap.Modal.getInstance(document.getElementById('newClientModal')).hide();
                showToast('Cliente guardado');
            } else {
                alert("Error: " + res.message);
            }
        })
        .catch(e => alert("Error de conexión"));
    }
</script>

<script src="pos1.js"></script>
<script src="pos-offline-system.js"></script>

<script>
// ── PWA Install Banner ────────────────────────────────────────────────────────
(function () {
    let _deferredPrompt = null; // guarda el evento beforeinstallprompt

    // Detectar si YA está instalado como PWA
    function isPwaInstalled() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || navigator.standalone === true; // iOS Safari
    }

    // Detectar iOS/iPadOS (no tienen beforeinstallprompt)
    function isIos() {
        return /iphone|ipad|ipod/i.test(navigator.userAgent) ||
               (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    // Mostrar el banner si aplica
    function maybeShowBanner() {
        if (isPwaInstalled()) return; // ya instalado, no mostrar nada
        if (sessionStorage.getItem('pwa_banner_dismissed')) return; // el usuario lo cerró esta sesión

        const banner   = document.getElementById('pwaBanner');
        const iosSteps = document.getElementById('pwaIosSteps');
        const installBtn = document.getElementById('btnInstallPwa');
        if (!banner) return;

        if (isIos() && !navigator.standalone) {
            // iOS: mostrar instrucciones manuales
            iosSteps.style.display = 'block';
            installBtn.style.display = 'none'; // no hay API de install en iOS
            banner.style.display = 'block';
        } else if (_deferredPrompt) {
            // Chrome/Android/Edge: botón de instalación disponible
            banner.style.display = 'block';
        }
        // Si no hay deferredPrompt y no es iOS → browser no soporta o ya instalado → no mostrar
    }

    // Capturar el evento nativo de instalación (Chrome, Edge, Android)
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();           // evitar el mini-infobar automático del browser
        _deferredPrompt = e;
        maybeShowBanner();            // el evento llegó → mostrar banner
    });

    // Si ya está instalado → ocultar banner por si acaso
    window.addEventListener('appinstalled', function () {
        document.getElementById('pwaBanner').style.display = 'none';
        _deferredPrompt = null;
    });

    // Botón "Instalar ahora"
    window.triggerPwaInstall = async function () {
        if (!_deferredPrompt) return;
        _deferredPrompt.prompt();
        const { outcome } = await _deferredPrompt.userChoice;
        _deferredPrompt = null;
        if (outcome === 'accepted') {
            document.getElementById('pwaBanner').style.display = 'none';
        }
    };

    // Botón "Ahora no"
    window.dismissPwaBanner = function () {
        document.getElementById('pwaBanner').style.display = 'none';
        sessionStorage.setItem('pwa_banner_dismissed', '1');
    };

    // Comprobar al cargar (para iOS, que no dispara beforeinstallprompt)
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(maybeShowBanner, 600); // pequeño delay para que el overlay ya esté visible
    });
})();

// ─── Fondo dinámico del carrito según sucursal ───────────────────────────────
window.updateCartBackground = function (sucursalId) {
    const cart = document.getElementById('cartContainer');
    if (!cart) return;
    const bannerPath = (typeof SUCURSALES_BANNERS !== 'undefined') ? SUCURSALES_BANNERS[sucursalId] : null;
    if (bannerPath) {
        const url = '/' + bannerPath.replace(/^\//, '');
        cart.style.backgroundImage = "linear-gradient(rgba(255,255,255,0.84),rgba(255,255,255,0.84)), url('" + url + "')";
        cart.style.backgroundSize    = 'cover';
        cart.style.backgroundPosition = 'center';
        // Actualizar también la imagen del estado vacío
        const emptyImg = document.querySelector('#cartEmptyState img.cart-empty-logo');
        if (emptyImg) { emptyImg.src = url + '?v=' + Date.now(); }
    } else {
        cart.style.backgroundImage = '';
    }
};

// ─── Selector de almacén multi-almacén ────────────────────────────────────────
(function () {
    let _pickerCallback = null;
    let _pickerContext  = null;

    // Llamado desde verifyPin() en pos1.js cuando la sucursal tiene >1 almacén.
    // almacenes: [{id, nombre}]
    // loginContext: objeto con id_almacen actual (el del cajero por defecto)
    // onConfirm: fn(overrideAlmId, overrideAlmNombre) — continúa el flujo de login
    window.showAlmacenPicker = function (almacenes, loginContext, onConfirm) {
        const picker = document.getElementById('almacenPicker');
        const sel    = document.getElementById('almacenSelect');
        if (!picker || !sel || almacenes.length === 0) { onConfirm(loginContext.id_almacen, ''); return; }

        // Ocultar teclado PIN para que el picker tenga todo el espacio
        const grid = document.getElementById('pinGrid');
        if (grid) grid.style.display = 'none';

        sel.innerHTML = '';
        almacenes.forEach(function (a) {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.nombre;
            if (a.id === loginContext.id_almacen) opt.selected = true;
            sel.appendChild(opt);
        });

        _pickerCallback = onConfirm;
        _pickerContext  = loginContext;
        picker.style.display = 'block';
    };

    window.confirmAlmacenSelection = async function () {
        const sel    = document.getElementById('almacenSelect');
        const picker = document.getElementById('almacenPicker');
        const grid   = document.getElementById('pinGrid');
        if (!sel) return;

        const almId     = parseInt(sel.value, 10);
        const almNombre = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : String(almId);

        // Actualizar badges
        const almBadge = document.getElementById('ctxAlmacenBadge');
        if (almBadge) almBadge.innerText = almNombre;

        // Persistir en sesión PHP solo cuando el login realmente creó sesión en backend.
        if (navigator.onLine && _pickerContext && _pickerContext.serverAuthenticated === true) {
            try {
                const resp = await fetch('pos.php?set_almacen=1', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: window.posJsonHeaders ? window.posJsonHeaders() : { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_almacen: almId }),
                    signal: AbortSignal.timeout(5000)
                });
                if (!resp.ok) {
                    console.warn('No se pudo persistir el almacén en la sesión del servidor');
                }
            } catch (e) { /* sin conexión — el contexto JS ya fue actualizado */ }
        }

        // Restaurar teclado y ocultar picker
        if (picker) picker.style.display = 'none';
        if (grid)   grid.style.display   = '';

        const cb = _pickerCallback;
        _pickerCallback = null;
        _pickerContext  = null;
        if (cb) cb(almId, almNombre);
    };
})();
// ─────────────────────────────────────────────────────────────────────────────
</script>

<div id="progressOverlay">
    <div class="progress-card">
        <h4 id="progressMessage" class="progress-title">Cargando...</h4>
        <div class="progress-bar-container">
            <div id="progressBar" class="progress-bar-fill">0%</div>
        </div>
        <p id="progressDetail" class="progress-detail"></p>
    </div>
</div>

<!-- Modal Origen del Precio -->
<div class="modal fade" id="priceModeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:360px">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden">
            <div class="modal-header border-0 text-white" style="background:#0dcaf0">
                <h6 class="modal-title fw-bold"><i class="fas fa-tags me-2"></i>Origen del Precio</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">Selecciona qué precio se muestra en las tarjetas y se agrega al carrito.</p>
                <div class="d-flex flex-column gap-2">
                    <button class="btn fw-bold text-start px-4 py-3 price-mode-opt" data-mode="sucursal"
                        style="border-radius:12px; border:2px solid #0dcaf0; background:#e0f9fd">
                        <i class="fas fa-store me-2 text-info"></i>Precio de Venta Normal
                        <div class="small fw-normal text-muted mt-1">Usa el precio específico de la sucursal. Si no hay uno definido, usa el precio global.</div>
                    </button>
                    <button class="btn fw-bold text-start px-4 py-3 price-mode-opt" data-mode="mayorista_suc"
                        style="border-radius:12px; border:2px solid #ffc107; background:#fffbe6">
                        <i class="fas fa-tags me-2 text-warning"></i>Precio Mayorista
                        <div class="small fw-normal text-muted mt-1">Precio mayorista de la sucursal. Si no hay uno, usa el mayorista global y si tampoco, el precio general.</div>
                    </button>
                    <div class="px-4 py-3" style="border-radius:12px; border:2px solid #dc3545; background:#fff5f5;">
                        <div class="fw-bold mb-2"><i class="fas fa-percent me-2 text-danger"></i>Porcentaje sobre precio base</div>
                        <div class="small text-muted mb-2">Aplica un porcentaje al precio de venta normal. Puede ser negativo o positivo.</div>
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text">%</span>
                            <input type="number" class="form-control" id="priceAdjustPct" step="0.01" min="-99.99" max="999.99" value="0">
                            <button type="button" class="btn btn-danger fw-bold" id="btnApplyCustomPriceMode">Usar %</button>
                        </div>
                        <div class="small fw-semibold text-danger" id="priceAdjustHint">Se aplicará 0% sobre el precio de venta normal.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Inventario POS -->
<div class="modal fade" id="invModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="invModalTitle"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Resumen del carrito -->
                <div id="invCartSummary" class="mb-3" style="max-height:200px;overflow-y:auto;"></div>

                <!-- Comparación conteo vs BD (solo conteo) -->
                <div id="invConteoInfo" class="mb-3" style="display:none;max-height:200px;overflow-y:auto;"></div>

                <!-- Fecha + destino transferencia -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Fecha del movimiento</label>
                        <input type="date" id="invFechaInput" class="form-control">
                    </div>
                    <div class="col-6" id="invDestinoCol" style="display:none;">
                        <label class="form-label small fw-bold">Sucursal destino <span class="text-danger">*</span></label>
                        <input type="text" id="invDestinoInput" class="form-control" placeholder="Nombre o ID de sucursal" autocomplete="off">
                    </div>
                </div>

                <!-- Motivo -->
                <div class="mb-1">
                    <label class="form-label small fw-bold">Motivo <span class="text-danger">*</span></label>
                    <input type="text" id="invMotivoInput" class="form-control" placeholder="Describe el motivo..." autocomplete="off">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnInvConfirmar" class="btn btn-primary fw-bold" onclick="invConfirmar()">
                    <i class="fas fa-check me-1"></i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="barcodeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header py-2 bg-primary text-white">
                <h6 class="modal-title fw-bold"><i class="fas fa-barcode me-2"></i>Editar Códigos de Barras</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="barcodeProductInfo" class="mb-4">
                    <h6 id="barcodeProductName" class="fw-bold mb-1">Cargando...</h6>
                    <small id="barcodeProductSku" class="text-muted d-block"></small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Código de Barras 1 (Principal)</label>
                    <input type="text" class="form-control" id="barcodeInput1" placeholder="Escanee o escriba..." autocomplete="off">
                </div>

                <div class="mb-0">
                    <label class="form-label small fw-bold text-muted">Código de Barras 2 (Alternativo)</label>
                    <input type="text" class="form-control" id="barcodeInput2" placeholder="Escanee o escriba..." autocomplete="off">
                </div>
            </div>
            <div class="modal-footer py-2 bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSaveBarcodes" class="btn btn-primary fw-bold" onclick="saveBarcodes()">
                    <i class="fas fa-save me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<?php include_once 'modal_payment.php'; ?>
<?php include_once 'modal_edit_sale.php'; ?>
<div class="modal fade" id="orderTemplatesModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-secondary text-white py-2">
                <h5 class="modal-title fw-bold"><i class="fas fa-bookmark me-2"></i>Plantillas y Pedidos Regulares</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="small fw-bold text-muted mb-1">Cargar último pedido por cliente</label>
                        <select id="regularClientSelect" class="form-select form-select-sm mb-2" onchange="loadLastOrderForSelectedClient(this)">
                            <option value="">Selecciona un cliente...</option>
                        </select>
                        <div class="small text-muted">
                            Al escoger un cliente, se intentará cargar al carrito exactamente lo que pidió la última vez.
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="small fw-bold text-muted mb-0">Plantillas guardadas</label>
                            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="refreshOrderTemplatesList()">
                                <i class="fas fa-sync-alt me-1"></i>Actualizar
                            </button>
                        </div>
                        <div id="orderTemplatesList" class="list-group" style="max-height:360px; overflow-y:auto;">
                            <div class="list-group-item text-muted small">Cargando plantillas...</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
<!-- MODAL AUTOPEDIDOS -->
<div class="modal fade" id="selfOrdersModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-mobile-alt me-2"></i> Autopedidos de Clientes</h5>
                <div class="ms-auto d-flex align-items-center me-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="switchAcceptOrders" checked onchange="toggleAcceptOrders(this.checked)">
                        <label class="form-check-label small fw-bold" for="switchAcceptOrders">Aceptar Pedidos</label>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3"># Ticket</th>
                                <th>Cliente / Mesa</th>
                                <th>Hora</th>
                                <th>Total</th>
                                <th class="text-end pe-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="selfOrdersTableBody">
                            <!-- Se llena vía JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<audio id="alertSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>

<script>
    function escapeHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    let lastSelfOrderId = 0;
    
    async function checkNewSelfOrders() {
        try {
            const res = await fetch(`self_order_api.php?action=check_new&last_id=${lastSelfOrderId}`);
            const data = await res.json();
            if(data.status === 'success' && data.orders.length > 0) {
                data.orders.forEach(o => { if(o.id > lastSelfOrderId) lastSelfOrderId = o.id; });
                updateSelfOrderBadge(data.orders.length);
                playAlertSound();
            }
        } catch(e) { console.error("Error checking self orders", e); }
    }

    function updateSelfOrderBadge(count) {
        const badge = document.getElementById('selfOrderBadge');
        const btn = document.getElementById('btnSelfOrders');
        if(count > 0) {
            badge.innerText = count;
            badge.classList.remove('d-none');
            btn.classList.add('animate__shakeX', 'btn-warning');
            btn.classList.remove('btn-outline-warning');
        } else {
            badge.classList.add('d-none');
            btn.classList.remove('animate__shakeX', 'btn-warning');
            btn.classList.add('btn-outline-warning');
        }
    }

    function playAlertSound() {
        document.getElementById('alertSound').play().catch(e => console.log("Audio block", e));
    }

    async function openSelfOrdersModal() {
        // Cargar estado actual del switch
        try {
            const resCfg = await fetch('self_order_api.php?action=get_config');
            const dataCfg = await resCfg.json();
            if(dataCfg.status === 'success') {
                document.getElementById('switchAcceptOrders').checked = dataCfg.config.kiosco_aceptar_pedidos !== false;
            }
        } catch(e) { console.error("Error loading config", e); }

        const res = await fetch('self_order_api.php?action=get_pending');
        const data = await res.json();
        const tbody = document.getElementById('selfOrdersTableBody');
        tbody.innerHTML = '';
        
        if(data.orders.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No hay pedidos pendientes</td></tr>';
        }

        data.orders.forEach(o => {
            const safeId     = parseInt(o.id, 10);
            const safeNombre = escapeHtml(o.cliente_nombre);
            const safeHora   = escapeHtml(o.fecha.split(' ')[1] ?? '');
            const safeTotal  = parseFloat(o.total).toFixed(2);
            tbody.innerHTML += `
                <tr>
                    <td class="ps-3 fw-bold">#${safeId}</td>
                    <td class="fw-bold">${safeNombre}</td>
                    <td>${safeHora}</td>
                    <td class="text-success fw-bold">$${safeTotal}</td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-primary rounded-pill" onclick="importSelfOrder(${safeId})">
                            <i class="fas fa-file-import me-1"></i> Cargar al Carrito
                        </button>
                    </td>
                </tr>
            `;
        });
        
        new bootstrap.Modal(document.getElementById('selfOrdersModal')).show();
    }

    async function toggleAcceptOrders(accept) {
        try {
            await fetch('self_order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_config', aceptar_pedidos: accept })
            });
            if(typeof showToast === 'function') showToast(accept ? "Pedidos activados" : "Pedidos bloqueados");
        } catch(e) { 
            console.error("Error setting config", e);
            alert("Error al guardar configuración");
        }
    }

    async function importSelfOrder(id) {
        if(!confirm("¿Cargar este pedido al carrito actual?")) return;
        
        const res = await fetch(`self_order_api.php?action=get_details&id=${id}`);
        const data = await res.json();
        
        if(data.status === 'success') {
            data.items.forEach(it => {
                // Buscar producto real en el catálogo local del POS
                const product = productsDB.find(p => p.codigo === it.codigo);
                if(product) {
                    // Pasar la cantidad real (puede ser decimal, ej: 2.5 kg)
                    window.addToCart(product, parseFloat(it.cantidad) || 1);
                } else {
                    console.error("Producto no encontrado en el catálogo POS:", it.codigo);
                }
            });
            
            // Marcar pedido como completado en la DB para que no aparezca más
            await fetch('self_order_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'complete', id: id })
            });

            bootstrap.Modal.getInstance(document.getElementById('selfOrdersModal')).hide();
            checkNewSelfOrders(); // Refrescar badge
        }
    }

    // Iniciar el polling
    setInterval(checkNewSelfOrders, 10000);
</script>

<?php include_once 'modal_close_register.php'; ?>

</body>
</html>
