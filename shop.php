<?php
// ===================================================================
// ARCHIVO: shop.php
// VERSIÓN: MODAL DETALLES PRO + CHAT SOPORTE
// ===================================================================

// ── Cookie de sesión segura ───────────────────────────────────────────────────
// Debe llamarse ANTES de session_start().
// Secure=true  → solo se envía por HTTPS.
// HttpOnly=true → inaccesible desde JavaScript (mitiga XSS).
// SameSite=Lax → protege contra CSRF pero permite enlaces externos entrantes
//                (WhatsApp, redes sociales). "Strict" rompería esos flujos.
session_set_cookie_params([
    'lifetime' => 0,           // Sesión de navegador (expira al cerrar la pestaña)
    'path'     => '/',
    'domain'   => '',          // Dominio actual
    'secure'   => true,        // Solo HTTPS
    'httponly' => true,        // No accesible por JS
    'samesite' => 'Lax',
]);
// ─────────────────────────────────────────────────────────────────────────────
session_start();
ob_start();

// ── Cabeceras de Seguridad HTTP ──────────────────────────────────────────────
// Content-Security-Policy: los scripts e inline-styles son necesarios por la
// arquitectura monolítica; migrar a nonces/hashes en refactorización futura.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://www.google-analytics.com https://analytics.google.com https://www.googletagmanager.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
// HTTP/2: informa al cliente que el servidor soporta h2 en el puerto 443.
// La habilitación real del protocolo requiere configuración en Nginx/Apache.
header("Alt-Svc: h2=\":443\"; ma=86400");
// HSTS: fuerza HTTPS durante 1 año (activar sólo si el sitio ya tiene TLS válido)
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
// Evita que el navegador detecte el MIME type de respuesta automáticamente
header("X-Content-Type-Options: nosniff");
// Impide que la página sea embebida en iframes de otros orígenes
header("X-Frame-Options: DENY");
// Activa el filtro XSS del navegador (legacy, complementa la CSP)
header("X-XSS-Protection: 1; mode=block");
// ─────────────────────────────────────────────────────────────────────────────

// CSRF token por sesión (protege checkout, login y registro)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = $_SESSION['csrf_token'];

require_once 'config_loader.php';
require_once 'push_notify.php';
require_once 'shop_skins.php';
require_once 'combo_helper.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

// Skin activo para esta sucursal
$ACTIVE_SKIN_ID   = shop_skin_for_sucursal($SUC_ID, $config);
$ACTIVE_SKIN      = shop_skin_get($ACTIVE_SKIN_ID);
$ACTIVE_SKIN_BODY_CLASS = $ACTIVE_SKIN['body_class'] ?? '';
$TARIFA_KM = floatval($config['mensajeria_tarifa_km'] ?? 150);

// Datos de tarjeta para pasarela de transferencia
$CFG_TARJETA = $config['numero_tarjeta'] ?? '';
$CFG_TITULAR = $config['titular_tarjeta'] ?? '';
$CFG_BANCO   = $config['banco_tarjeta']   ?? 'Bandec / BPA';

// Multi-divisa y métodos de pago para la shop
$metodosShop   = array_values(array_filter(
    $config['metodos_pago'] ?? [],
    fn($m) => ($m['activo'] ?? false) && ($m['aplica_shop'] ?? false)
));
$tipoCambioUSD = floatval($config['tipo_cambio_usd'] ?? 385);
$tipoCambioMLC = floatval($config['tipo_cambio_mlc'] ?? 310);
$systemBrandName = trim((string)($config['marca_sistema_nombre'] ?? 'PalWeb POS Marinero')) ?: 'PalWeb POS Marinero';
$companyBrandName = trim((string)($config['marca_empresa_nombre'] ?? ($config['tienda_nombre'] ?? 'MI TIENDA'))) ?: 'MI TIENDA';
$companyBrandLogo = trim((string)($config['marca_empresa_logo'] ?? ''));

// Cache estático para evitar N+1 de file_exists (se llena una sola vez por request)
function shop_image_meta(string $code): array {
    static $cache    = null;
    static $dirCache = [];

    $safe = trim($code);
    if ($safe === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $safe)) return [false, 0];

    // Primera llamada: pre-escanear el directorio completo con glob() (1 syscall)
    if ($cache === null) {
        $cache = [];
        $dirs  = [
            __DIR__ . '/assets/product_images/',
            dirname(__DIR__) . '/assets/product_images/',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '*.{avif,webp,jpg,jpeg}', GLOB_BRACE) as $f) {
                $base = basename($f);
                // Guardar el primer formato encontrado por nombre base (sin extensión)
                $stem = pathinfo($base, PATHINFO_FILENAME);
                if (!isset($cache[$stem])) {
                    $cache[$stem] = [true, (int)filemtime($f)];
                }
            }
        }
    }

    return $cache[$safe] ?? [false, 0];
}

function shop_slugify(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    if ($text === '') {
        return 'producto';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false) {
        $text = $ascii;
    }

    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'producto';
}

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- REGISTRO DE MÉTRICAS (VISITANTES) ---
    $userIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $sessId = session_id();
    
    // Evitar duplicar en la misma sesión/página en corto tiempo
    $pdo->prepare("INSERT INTO metricas_web (ip, url_visitada, user_agent, session_id) VALUES (?, ?, ?, ?)")
        ->execute([$userIP, $uri, $userAgent, $sessId]);

} catch (Exception $e) {
    die("Error DB: " . $e->getMessage());
}

// ── Crear tablas de reseñas y variantes (ANTES de cualquier SELECT) ──
// COLLATE utf8mb4_unicode_ci para coincidir con la colación de la tabla productos
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS resenas_productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_codigo VARCHAR(50) NOT NULL,
        cliente_id INT NOT NULL,
        cliente_nombre VARCHAR(100) NOT NULL,
        rating TINYINT(1) NOT NULL,
        comentario TEXT,
        fecha DATETIME DEFAULT NOW(),
        aprobada TINYINT(1) DEFAULT 1,
        UNIQUE KEY uniq_cliente_prod (cliente_id, producto_codigo),
        INDEX idx_codigo (producto_codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS producto_variantes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_codigo VARCHAR(50) NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        precio_extra DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden TINYINT UNSIGNED DEFAULT 0,
        INDEX idx_codigo (producto_codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Corregir colación si las tablas ya existían con utf8mb4_general_ci
    $pdo->exec("ALTER TABLE resenas_productos   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE producto_variantes  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {}

// ── Tablas auxiliares: vistas, carritos abandonados, restock avisos ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vistas_productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo_producto VARCHAR(50) NOT NULL,
        ip VARCHAR(45),
        fecha DATETIME DEFAULT NOW(),
        INDEX idx_codigo (codigo_producto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS carritos_abandonados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(128) NOT NULL,
        items_json TEXT,
        total DECIMAL(12,2) DEFAULT 0,
        fecha_actualizacion DATETIME DEFAULT NOW(),
        UNIQUE KEY uk_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS restock_avisos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        producto_codigo VARCHAR(50) NOT NULL,
        nombre VARCHAR(100) NOT NULL,
        telefono VARCHAR(30) NOT NULL,
        notificado TINYINT(1) DEFAULT 0,
        fecha DATETIME DEFAULT NOW(),
        INDEX idx_codigo (producto_codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}
// ── Agregar columna precio_oferta si aún no existe ──
try { $pdo->exec("ALTER TABLE productos ADD COLUMN precio_oferta DECIMAL(12,2) DEFAULT NULL"); } catch (Exception $e) {}

// --- SEO & META TAGS DINÁMICOS ---
$storeName = $companyBrandName;
$storeAddr = $config['direccion'] ?? 'La Habana, Cuba';
$storeTel  = $config['telefono'] ?? '';
$fbUrl     = $config['facebook_url'] ?? '';
$xUrl      = $config['twitter_url'] ?? '';
$igUrl     = $config['instagram_url'] ?? '';
$ytUrl     = $config['youtube_url'] ?? '';
$https     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
$scheme    = $https ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'] ?? 'palweb.net';
$baseDir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/shop.php')), '/');
$baseDir   = $baseDir === '.' ? '' : $baseDir;
$shopBasePath = ($baseDir !== '' ? $baseDir : '') . '/shop.php';
$shopPrettyBasePath = $baseDir !== '' ? $baseDir : '';
$shopDocumentBase = $scheme . '://' . $host . ($baseDir !== '' ? $baseDir . '/' : '/');
$shopAssetPrefix = ($baseDir !== '' ? $baseDir : '') . '/';
$siteUrl   = $config['sitio_web'] ?? ($scheme . '://' . $host . $shopBasePath);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPathTrimmed = trim($requestPath, '/');
$scriptPathTrimmed = trim($_SERVER['SCRIPT_NAME'] ?? '/shop.php', '/');
$requestedSlug = '';
if ($requestPathTrimmed !== ''
    && $requestPathTrimmed !== $scriptPathTrimmed
    && $requestPathTrimmed !== 'shop.php'
    && !str_contains($requestPathTrimmed, '/')
) {
    $requestedSlug = strtolower(rawurldecode($requestPathTrimmed));
}

$metaTitle = $storeName . " | Tienda Online en La Habana – Productos Frescos y Entrega a Domicilio";
$metaDesc  = "Bienvenido a " . $storeName . ", tu tienda online en La Habana. Compra productos de calidad con entrega a domicilio en toda La Habana. Pedido fácil, rápido y seguro. ¡Haz tu pedido ahora!";
// Imagen OG 1200×630 para WhatsApp/redes — fallback al logo de empresa
$ogImgLocal = __DIR__ . '/assets/img/og-social.png';
$metaImg    = file_exists($ogImgLocal)
    ? $scheme . '://' . $host . $baseDir . '/assets/img/og-social.png'
    : ($companyBrandLogo !== ''
        ? $scheme . '://' . $host . $baseDir . '/' . ltrim($companyBrandLogo, '/')
        : $scheme . '://' . $host . $baseDir . '/icon-shop-512.png');


// =========================================================
//  API: GEOLOCALIZACIÓN
// =========================================================
if (isset($_GET['action_geo'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $act = $_GET['action_geo'];
    $locations = [
        "Cerro" => ["El Canal" => 0.5, "Pilar-Atares" => 2.0, "Cerro" => 1.5, "Las Cañas" => 2.5, "Palatino" => 3.0, "Armada" => 2.0, "Latinoamericano" => 1.0],
        "Plaza" => ["Rampa" => 4.0, "Vedado" => 4.5, "Carmelo" => 5.5, "Príncipe" => 2.5, "Plaza" => 3.0, "Nuevo Vedado" => 4.0],
        "Centro Habana" => ["Cayo Hueso" => 3.0, "Pueblo Nuevo" => 3.5, "Los Sitios" => 3.0, "Dragones" => 3.5, "Colón" => 4.0],
        "Habana Vieja" => ["Prado" => 4.5, "Catedral" => 5.0, "Plaza Vieja" => 5.0, "Belén" => 5.0, "San Isidro" => 5.5],
        "Diez de Octubre" => ["Luyanó" => 4.0, "Jesús del Monte" => 3.5, "Lawton" => 5.0, "Víbora" => 4.5, "Santos Suárez" => 3.5, "Sevillano" => 4.5],
        "Playa" => ["Miramar" => 7.0, "Buena Vista" => 6.0, "Ceiba" => 5.0, "Siboney" => 9.0, "Santa Fe" => 12.0],
        "Marianao" => ["Los Ángeles" => 6.0, "Pocito" => 7.0, "Zamora" => 6.5, "Libertad" => 6.0],
        "La Lisa" => ["La Lisa" => 9.0, "El Cano" => 11.0, "Punta Brava" => 12.0, "San Agustín" => 10.0],
        "Boyeros" => ["Santiago de las Vegas" => 15.0, "Boyeros" => 12.0, "Wajay" => 11.0, "Calabazar" => 9.0, "Altahabana" => 7.0],
        "Arroyo Naranjo" => ["Los Pinos" => 7.0, "Poey" => 8.0, "Vibora Park" => 7.0, "Mantilla" => 9.0, "Managua" => 15.0],
        "San Miguel" => ["Rocafort" => 6.0, "Luyanó Moderno" => 7.0, "Diezmero" => 8.0, "San Francisco" => 10.0],
        "Cotorro" => ["San Pedro" => 16.0, "Cuatro Caminos" => 18.0, "Santa Maria" => 14.0],
        "Regla" => ["Guaicanamar" => 8.0, "Casablanca" => 9.0],
        "Guanabacoa" => ["Villa I" => 10.0, "Chibas" => 9.5, "Minas" => 12.0],
        "Habana del Este" => ["Camilo Cienfuegos" => 9.0, "Cojímar" => 11.0, "Guiteras" => 10.0, "Alamar" => 12.0, "Guanabo" => 25.0]
    ];

    if ($act === 'list_mun') echo json_encode(array_keys($locations));
    elseif ($act === 'list_bar') {
        $m = $_GET['m'] ?? '';
        echo json_encode(isset($locations[$m]) ? array_keys($locations[$m]) : []);
    }
    elseif ($act === 'calc') {
        $m = $_GET['m'] ?? '';
        $b = $_GET['b'] ?? '';
        $dist = $locations[$m][$b] ?? 0;
        echo json_encode(['costo' => round(100 + ($dist * $TARIFA_KM), 2)]);
    }
    exit;
}

// =========================================================
//  API: BÚSQUEDA AJAX
// =========================================================
if (isset($_GET['ajax_search'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $q = trim($_GET['ajax_search'] ?? '');
    if (strlen($q) < 1) { echo json_encode([]); exit; }

    try {
        $sql = "SELECT p.codigo, p.nombre,
                COALESCE(ps.precio_venta, p.precio) AS precio,
                p.descripcion, p.categoria, p.unidad_medida, p.color, p.es_reservable,
                COALESCE(p.es_combo, 0) AS es_combo,
                COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s
                WHERE s.id_producto = p.codigo AND s.id_almacen = ?), 0) as stock
                FROM productos p
                LEFT JOIN productos_precios_sucursal ps
                    ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
                WHERE p.es_web = 1
                  AND p.activo = 1
                  AND p.id_empresa = ?
                  AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)
                  AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ?)
                ORDER BY CASE
                    WHEN p.nombre = ? THEN 1
                    WHEN p.codigo = ? THEN 2
                    WHEN p.nombre LIKE ? THEN 3
                    ELSE 4 END, p.nombre ASC
                LIMIT 15";
        
        $searchPattern = "%$q%";
        $searchStart = "$q%";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ALM_ID, $SUC_ID, $EMP_ID, $SUC_ID,
            $searchPattern, $searchPattern, $searchPattern,
            $q, $q, $searchStart
        ]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = combo_apply_product_rows($pdo, $results, $EMP_ID, $ALM_ID);
        
        foreach ($results as &$r) {
            $r['precio'] = floatval($r['precio']);
            $r['stock'] = floatval($r['stock']);
            $r['hasStock']     = $r['stock'] > 0;
            $r['esReservable'] = intval($r['es_reservable'] ?? 0) === 1;
            $r['slug'] = shop_slugify((string)($r['nombre'] ?? 'producto'));
            [$r['hasImg'], $imgV] = shop_image_meta((string)$r['codigo']);
            $r['imgUrl'] = $r['hasImg'] ? $shopAssetPrefix . "image.php?code=" . urlencode($r['codigo']) : null;
            $r['bg'] = "#" . substr(md5($r['nombre']), 0, 6);
            $r['initials'] = mb_strtoupper(mb_substr($r['nombre'], 0, 2));
        }
        
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { 
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => $e->getMessage()]); 
    }
    exit;
}

// =========================================================
//  CARGA INICIAL
// =========================================================
$catFilter = trim($_GET['cat'] ?? '');
$sort = $_GET['sort'] ?? 'categoria_asc';
$localImgPath = __DIR__ . '/assets/product_images/';

try {
    $catSql = "SELECT DISTINCT categoria FROM productos 
               WHERE activo=1 AND es_web=1 AND id_empresa=? 
               AND (sucursales_web = '' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web) > 0)
               ORDER BY categoria";
    $catStmt = $pdo->prepare($catSql);
    $catStmt->execute([$EMP_ID, $SUC_ID]);
    $cats = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $cats = [];
}

try {
    $sql = "SELECT p.*,
            COALESCE(p.es_combo, 0) AS es_combo,
            COALESCE(ps.precio_venta, p.precio) AS precio,
            (SELECT COALESCE(SUM(s.cantidad), 0)
             FROM stock_almacen s
             WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock_total,
            (SELECT ROUND(AVG(r.rating),1) FROM resenas_productos r WHERE r.producto_codigo = p.codigo AND r.aprobada = 1) as avg_rating,
            (SELECT COUNT(*) FROM resenas_productos r WHERE r.producto_codigo = p.codigo AND r.aprobada = 1) as total_resenas
            FROM productos p
            LEFT JOIN productos_precios_sucursal ps
                ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
            WHERE p.activo = 1
              AND p.es_web = 1
              AND p.id_empresa = ?
              AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)";

    $params = [$ALM_ID, $SUC_ID, $EMP_ID, $SUC_ID];

    if ($catFilter) {
        $sql .= " AND p.categoria = ?";
        $params[] = $catFilter;
    }

    $sortMap = [
        'categoria_asc' => 'p.categoria ASC, p.nombre ASC',
        'price_asc' => 'COALESCE(ps.precio_venta, p.precio) ASC',
        'price_desc' => 'COALESCE(ps.precio_venta, p.precio) DESC',
        'popular' => '(SELECT COUNT(*) FROM vistas_productos v WHERE v.codigo_producto = p.codigo) DESC, p.nombre ASC',
    ];
    
    $sql .= " ORDER BY " . ($sortMap[$sort] ?? 'p.categoria ASC, p.nombre ASC');
    $sql .= " LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productos = combo_apply_product_rows($pdo, $productos, $EMP_ID, $ALM_ID);

    $slugCounts = [];
    foreach ($productos as &$productoRow) {
        $baseSlug = shop_slugify((string)($productoRow['nombre'] ?? 'producto'));
        $slugCounts[$baseSlug] = ($slugCounts[$baseSlug] ?? 0) + 1;
        $productoRow['slug'] = $slugCounts[$baseSlug] === 1
            ? $baseSlug
            : ($baseSlug . '-' . shop_slugify((string)($productoRow['codigo'] ?? 'item')));
    }
    unset($productoRow);
    
    // Obtener Wishlist si está logueado
    $userWishlist = [];
    $clienteProfile = null;
    if (isset($_SESSION['client_id'])) {
        $stmtW = $pdo->prepare("SELECT producto_codigo FROM wishlist WHERE cliente_id = ?");
        $stmtW->execute([$_SESSION['client_id']]);
        $userWishlist = $stmtW->fetchAll(PDO::FETCH_COLUMN);

        $stmtCP = $pdo->prepare("SELECT nombre, telefono, COALESCE(direccion,'') AS direccion FROM clientes_tienda WHERE id = ?");
        $stmtCP->execute([$_SESSION['client_id']]);
        $clienteProfile = $stmtCP->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $productos = [];
    $userWishlist = [];
    $clienteProfile = null;
}

$productByCode = [];
$productBySlug = [];
foreach ($productos as $productoItem) {
    $codigoProducto = (string)($productoItem['codigo'] ?? '');
    $slugProducto = (string)($productoItem['slug'] ?? '');
    if ($codigoProducto !== '') {
        $productByCode[$codigoProducto] = $productoItem;
    }
    if ($slugProducto !== '') {
        $productBySlug[$slugProducto] = $productoItem;
    }
}

if ($requestedSlug !== '' && isset($productBySlug[$requestedSlug])) {
    $_GET['producto'] = $productBySlug[$requestedSlug]['codigo'];
}

$selectedProductCode = trim((string)($_GET['producto'] ?? ''));
$selectedProduct = $selectedProductCode !== '' && isset($productByCode[$selectedProductCode])
    ? $productByCode[$selectedProductCode]
    : null;

if ($selectedProduct) {
    $selectedSlug = (string)($selectedProduct['slug'] ?? shop_slugify((string)($selectedProduct['nombre'] ?? 'producto')));
    $siteUrl = $scheme . '://' . $host . $shopPrettyBasePath . '/' . rawurlencode($selectedSlug) . '/';
    $metaTitle = trim((string)($selectedProduct['nombre'] ?? 'Producto')) . ' | ' . $storeName;

    $selectedDescription = trim((string)($selectedProduct['descripcion'] ?? ''));
    if ($selectedDescription !== '') {
        $metaDesc = mb_substr($selectedDescription, 0, 155, 'UTF-8');
    } else {
        $metaDesc = 'Compra ' . trim((string)($selectedProduct['nombre'] ?? 'este producto')) . ' en ' . $storeName . '. Disponible para pedido online y entrega a domicilio en La Habana.';
    }

    [$selectedHasImg, $selectedImgVersion] = shop_image_meta((string)($selectedProduct['codigo'] ?? ''));
    if ($selectedHasImg) {
        $metaImg = $scheme . '://' . $host . $baseDir . '/image.php?code=' . rawurlencode((string)$selectedProduct['codigo']);
        if ($selectedImgVersion) {
            $metaImg .= '&v=' . $selectedImgVersion;
        }
    }
}

// =========================================================
//  API: TRACKING DE PEDIDOS
// =========================================================
if (isset($_GET['action_track'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? ''); 
    try {
        if (empty($q)) {
            echo json_encode([]);
            exit;
        }

        // Búsqueda flexible por teléfono: quitar prefijo 53 si existe
        $cleanPhone = preg_replace('/^53/', '', $q);
        $phonePattern = "%" . $cleanPhone . "%";
        
        // Buscamos reservas por ID exacto o por teléfono parcial en ventas_cabecera
        $stmt = $pdo->prepare("SELECT id, estado_reserva, total, fecha_reserva, cliente_nombre
                                FROM ventas_cabecera
                                WHERE tipo_servicio='reserva'
                                  AND (id = ? OR cliente_telefono LIKE ?)
                                ORDER BY fecha_reserva DESC LIMIT 5");
        $stmt->execute([$q, $phonePattern]);
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mapear estado_reserva a formato legible para el frontend
        $estadoMap = [
            'PENDIENTE'    => 'Pendiente',
            'ENTREGADO'    => 'Entregado',
            'CANCELADO'    => 'Cancelado',
            'EN_CAMINO'    => 'En Camino',
            'EN_PREPARACION' => 'En Preparación',
        ];

        // Cargar ítems desde ventas_detalle
        $stmtItems = $pdo->prepare("SELECT nombre_producto AS name, cantidad AS qty, precio AS price
                                     FROM ventas_detalle WHERE id_venta_cabecera = ?");
        foreach ($pedidos as &$pedido) {
            $pedido['estado'] = $estadoMap[$pedido['estado_reserva']] ?? ucfirst(strtolower($pedido['estado_reserva'] ?? 'Pendiente'));
            $pedido['fecha']  = $pedido['fecha_reserva'];
            unset($pedido['estado_reserva'], $pedido['fecha_reserva']);
            $stmtItems->execute([$pedido['id']]);
            $pedido['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($pedido);

        echo json_encode($pedidos);
    } catch (Exception $e) { 
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); 
    }
    exit;
}

// =========================================================
//  API: LOGIN / REGISTRO / CLIENTE
// =========================================================
$input_client_api = json_decode(file_get_contents('php://input'), true);

if (isset($input_client_api['action_client'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $act = $input_client_api['action'] ?? '';

    // Validar CSRF token en acciones que modifican estado
    $csrfActionsProtected = ['login', 'register', 'submit_review', 'toggle_wishlist', 'update_profile', 'change_password'];
    if (in_array($act, $csrfActionsProtected, true)) {
        $sentToken = $input_client_api['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'msg' => 'Token de seguridad inválido. Recarga la página.']);
            exit;
        }
    }

    // Rate limiting: max 10 intentos por IP en 60 segundos (APCu o sesión)
    $rl_ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rl_key = 'rl_shop_auth_' . md5($rl_ip);
    $rl_max = 10; $rl_win = 60;
    if (function_exists('apcu_fetch')) {
        $rl_hits = (int)apcu_fetch($rl_key);
        if ($rl_hits >= $rl_max) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'msg' => 'Demasiados intentos. Espera un minuto.']);
            exit;
        }
        apcu_add($rl_key, 0, $rl_win);
        apcu_inc($rl_key);
    } else {
        // Fallback: sesión PHP (menos preciso pero funciona sin APCu)
        if (!isset($_SESSION['rl_auth'])) $_SESSION['rl_auth'] = ['n' => 0, 't' => time()];
        if (time() - $_SESSION['rl_auth']['t'] > $rl_win) $_SESSION['rl_auth'] = ['n' => 0, 't' => time()];
        if ($_SESSION['rl_auth']['n'] >= $rl_max) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'msg' => 'Demasiados intentos. Espera un minuto.']);
            exit;
        }
        $_SESSION['rl_auth']['n']++;
    }

    try {
        // LOGIN
        if ($act === 'login') {
            $stmt = $pdo->prepare("SELECT * FROM clientes_tienda WHERE telefono = ?");
            $stmt->execute([$input_client_api['telefono']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($input_client_api['password'], $user['password_hash'])) {
                $_SESSION['client_id'] = $user['id'];
                $_SESSION['client_name'] = $user['nombre'];
                echo json_encode(['status' => 'success', 'user' => ['nombre' => $user['nombre']]]);
            } else {
                throw new Exception("Credenciales incorrectas.");
            }
        }
        // REGISTRO
        elseif ($act === 'register') {
            // Verificar Captcha
            // Validar Captcha y campos obligatorios
            if (empty($input_client_api['nombre']) || empty($input_client_api['telefono']) || empty($input_client_api['password']) || empty($input_client_api['captcha_ans'])) {
                $missing = [];
                if (empty($input_client_api['nombre'])) $missing[] = 'Nombre';
                if (empty($input_client_api['telefono'])) $missing[] = 'Teléfono';
                if (empty($input_client_api['password'])) $missing[] = 'Contraseña';
                if (empty($input_client_api['captcha_ans'])) $missing[] = 'Captcha';
                throw new Exception("Campos obligatorios incompletos: " . implode(", ", $missing) . ".");
            }
            if (intval($input_client_api['captcha_ans']) !== intval($_SESSION['captcha_reg'] ?? -1)) {
                throw new Exception("Captcha incorrecto. Eres un robot?");
            }
            if (
                strlen((string)$input_client_api['password']) < 8
                || !preg_match('/[A-Z]/', (string)$input_client_api['password'])
                || !preg_match('/\d/', (string)$input_client_api['password'])
            ) {
                throw new Exception("La contraseña debe tener al menos 8 caracteres, una mayúscula y un número.");
            }
            unset($_SESSION['captcha_reg'], $_SESSION['captcha_q']);
            
            $hash = password_hash($input_client_api['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO clientes_tienda (nombre, telefono, password_hash, direccion) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input_client_api['nombre'], $input_client_api['telefono'], $hash, $input_client_api['direccion'] ?? '']);
            push_notify(
                $pdo,
                'operador',
                '👤 Nuevo cliente web',
                trim(($input_client_api['nombre'] ?? 'Cliente') . ' — ' . ($input_client_api['telefono'] ?? '')),
                '/marinero/shop.php',
                'shop_client_new'
            );
            echo json_encode(['status' => 'success']);
        }
        elseif ($act === 'logout') {
            unset($_SESSION['client_id'], $_SESSION['client_name']);
            echo json_encode(['status' => 'success']);
        }
        // --- WISHLIST ---
        elseif ($act === 'toggle_wishlist') {
            if (!isset($_SESSION['client_id'])) throw new Exception("Inicia sesión.");
            $code = $input_client_api['code'];
            $check = $pdo->prepare("SELECT id FROM wishlist WHERE cliente_id = ? AND producto_codigo = ?");
            $check->execute([$_SESSION['client_id'], $code]);
            if ($check->fetch()) {
                $pdo->prepare("DELETE FROM wishlist WHERE cliente_id = ? AND producto_codigo = ?")->execute([$_SESSION['client_id'], $code]);
                echo json_encode(['status' => 'success', 'added' => false]);
            } else {
                $pdo->prepare("INSERT INTO wishlist (cliente_id, producto_codigo) VALUES (?, ?)")->execute([$_SESSION['client_id'], $code]);
                echo json_encode(['status' => 'success', 'added' => true]);
            }
        }
        // --- OBTENER PERFIL Y PEDIDOS ---
        elseif ($act === 'get_profile') {
            if (!isset($_SESSION['client_id'])) throw new Exception("Inicia sesión.");
            $stmtU = $pdo->prepare("SELECT nombre, telefono, direccion, municipio, barrio FROM clientes_tienda WHERE id = ?");
            $stmtU->execute([$_SESSION['client_id']]);
            $user = $stmtU->fetch(PDO::FETCH_ASSOC);

            // Consultar en ventas_cabecera (todas las órdenes, no solo pedidos)
            $stmtP = $pdo->prepare(
                "SELECT id, fecha, total, tipo_servicio, estado_reserva, estado_pago,
                        (SELECT COUNT(*) FROM ventas_detalle vd WHERE vd.id_venta_cabecera = vc.id) AS num_items
                 FROM ventas_cabecera vc
                 WHERE vc.cliente_telefono = ?
                   AND vc.canal_origen IN ('Web','WhatsApp','Teléfono','Kiosko','ICS','Presencial','Otro')
                 ORDER BY vc.fecha DESC
                 LIMIT 30"
            );
            $stmtP->execute([$user['telefono']]);
            $pedidos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'user' => $user, 'pedidos' => $pedidos]);
        }
        // --- ACTUALIZAR DATOS ---
        elseif ($act === 'update_profile') {
            if (!isset($_SESSION['client_id'])) throw new Exception("Inicia sesión.");
            $sql = "UPDATE clientes_tienda SET nombre = ?, direccion = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([$input_client_api['nombre'], $input_client_api['direccion'], $_SESSION['client_id']]);
            $_SESSION['client_name'] = $input_client_api['nombre'];
            echo json_encode(['status' => 'success']);
        }
        elseif ($act === 'change_password') {
            if (!isset($_SESSION['client_id'])) throw new Exception("Inicia sesión.");

            $currentPassword = (string)($input_client_api['current_password'] ?? '');
            $newPassword = (string)($input_client_api['new_password'] ?? '');
            $confirmPassword = (string)($input_client_api['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                throw new Exception("Completa todos los campos de contraseña.");
            }
            if (
                strlen($newPassword) < 8
                || !preg_match('/[A-Z]/', $newPassword)
                || !preg_match('/\d/', $newPassword)
            ) {
                throw new Exception("La nueva contraseña debe tener al menos 8 caracteres, una mayúscula y un número.");
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new Exception("La confirmación de la contraseña no coincide.");
            }

            $stmt = $pdo->prepare("SELECT password_hash FROM clientes_tienda WHERE id = ?");
            $stmt->execute([$_SESSION['client_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                throw new Exception("La contraseña actual es incorrecta.");
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE clientes_tienda SET password_hash = ? WHERE id = ?")
                ->execute([$newHash, $_SESSION['client_id']]);

            echo json_encode(['status' => 'success']);
        }
        // --- PUBLICAR RESEÑA ---
        elseif ($act === 'submit_review') {
            if (!isset($_SESSION['client_id'])) throw new Exception("Debes iniciar sesión para dejar una reseña.");
            $codigo   = trim($input_client_api['codigo'] ?? '');
            $rating   = intval($input_client_api['rating'] ?? 0);
            $comment  = trim($input_client_api['comentario'] ?? '');
            if (!$codigo || $rating < 1 || $rating > 5) throw new Exception("Datos inválidos.");
            $stmtChk = $pdo->prepare("SELECT id FROM resenas_productos WHERE producto_codigo = ? AND cliente_id = ?");
            $stmtChk->execute([$codigo, $_SESSION['client_id']]);
            if ($stmtChk->fetch()) throw new Exception("Ya dejaste una reseña para este producto.");
            $nombre = $_SESSION['client_name'] ?? 'Cliente';
            $pdo->prepare("INSERT INTO resenas_productos (producto_codigo, cliente_id, cliente_nombre, rating, comentario) VALUES (?,?,?,?,?)")
                ->execute([$codigo, $_SESSION['client_id'], $nombre, $rating, $comment]);
            echo json_encode(['status' => 'success']);
        }
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
    exit;
}

// --- ACTUALIZAR CARRITO ABANDONADO ---
if (isset($input_client_api['update_cart_tracker'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $cartItems = $input_client_api['items'] ?? [];
        $cartTotal = $input_client_api['total'] ?? 0;
        $stmt = $pdo->prepare("INSERT INTO carritos_abandonados (session_id, items_json, total) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE items_json = ?, total = ?, fecha_actualizacion = NOW()");
        $stmt->execute([session_id(), json_encode($cartItems), $cartTotal, json_encode($cartItems), $cartTotal]);
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// --- TRACKER DE VISTAS DE PRODUCTO ---
if (isset($_GET['action_view_product'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $code = $_GET['code'] ?? '';
    if($code) {
        $pdo->prepare("INSERT INTO vistas_productos (codigo_producto, ip) VALUES (?, ?)")->execute([$code, $_SERVER['REMOTE_ADDR']]);
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// =========================================================
//  API: GENERAR CAPTCHA NUEVO (server-side, almacena en sesión)
// =========================================================
if (isset($_GET['action_new_captcha'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $cn1 = rand(1, 10); $cn2 = rand(1, 10);
    $_SESSION['captcha_reg'] = $cn1 + $cn2;
    echo json_encode(['q' => "$cn1 + $cn2 ="]);
    exit;
}

// =========================================================
//  API: RESTOCK AVISOS — POST
// =========================================================
if (isset($input_client_api['action_restock_aviso'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $codigo   = trim($input_client_api['codigo']   ?? '');
    $nombre   = trim($input_client_api['nombre']   ?? '');
    $telefono = trim($input_client_api['telefono'] ?? '');
    if (!$codigo || !$nombre || !$telefono) {
        echo json_encode(['error' => 'Datos incompletos']); exit;
    }
    try {
        $pdo->prepare("INSERT INTO restock_avisos (producto_codigo, nombre, telefono) VALUES (?, ?, ?)")
            ->execute([$codigo, $nombre, $telefono]);
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// =========================================================
//  API: JS ERROR LOGGING
// =========================================================
if (isset($_GET['action_js_error'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $msg  = substr(trim($body['msg']  ?? ''), 0, 500);
    $src  = substr(trim($body['src']  ?? ''), 0, 200);
    $line = intval($body['line'] ?? 0);
    $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($msg) {
        error_log("[JS-shop] {$msg} @ {$src}:{$line} | UA:{$ua} | IP:{$ip}");
    }
    echo json_encode(['ok' => 1]);
    exit;
}

// =========================================================
//  API: RESEÑAS — GET
// =========================================================
if (isset($_GET['action_reviews'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $codigo = trim($_GET['codigo'] ?? '');
    try {
        $stmt = $pdo->prepare(
            "SELECT r.cliente_nombre, r.rating, r.comentario,
                    DATE_FORMAT(r.fecha,'%d/%m/%Y') as fecha
             FROM resenas_productos r
             WHERE r.producto_codigo = ? AND r.aprobada = 1
             ORDER BY r.fecha DESC LIMIT 20"
        );
        $stmt->execute([$codigo]);
        $resenas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtAvg = $pdo->prepare(
            "SELECT ROUND(AVG(rating),1) as avg, COUNT(*) as total
             FROM resenas_productos WHERE producto_codigo = ? AND aprobada = 1"
        );
        $stmtAvg->execute([$codigo]);
        $stats = $stmtAvg->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'resenas' => $resenas,
            'avg'     => floatval($stats['avg'] ?? 0),
            'total'   => intval($stats['total'])
        ]);
    } catch (Exception $e) {
        echo json_encode(['resenas' => [], 'avg' => 0, 'total' => 0]);
    }
    exit;
}

// =========================================================
//  API: VARIANTES — GET
// =========================================================
if (isset($_GET['action_variants'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $codigo = trim($_GET['codigo'] ?? '');
    try {
        $stmt = $pdo->prepare(
            "SELECT id, nombre, precio_extra
             FROM producto_variantes
             WHERE producto_codigo = ? AND activo = 1
             ORDER BY orden, id"
        );
        $stmt->execute([$codigo]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ── Build compact product list for client-side JS cache ──────────────────────
$productsJs = [];
foreach ($productos as $_p) {
    [$_hasImg, $_imgV] = shop_image_meta((string)$_p['codigo']);
    $_pImgUrl = $_hasImg ? $shopAssetPrefix . 'image.php?code=' . urlencode($_p['codigo']) : null;
    $productsJs[] = [
        'codigo'       => $_p['codigo'],
        'nombre'       => $_p['nombre'],
        'slug'         => $_p['slug'] ?? shop_slugify((string)($_p['nombre'] ?? 'producto')),
        'precio'       => floatval($_p['precio']),
        'precioOferta' => floatval($_p['precio_oferta'] ?? 0),
        'descripcion'  => $_p['descripcion'] ?? '',
        'imgUrl'       => $_pImgUrl,
        'bg'           => '#'.substr(md5($_p['nombre']),0,6),
        'initials'     => mb_strtoupper(mb_substr($_p['nombre'],0,2)),
        'hasImg'       => $_hasImg,
        'hasStock'     => floatval($_p['stock_total'] ?? 0) > 0,
        'stock'        => floatval($_p['stock_total'] ?? 0),
        'categoria'    => $_p['categoria'] ?? 'General',
        'unidad_medida'=> $_p['unidad_medida'] ?? '',
        'color'        => $_p['color'] ?? '',
        'esReservable' => intval($_p['es_reservable'] ?? 0) === 1,
        'esCombo'      => intval($_p['es_combo'] ?? 0) === 1,
        'comboResumen' => $_p['combo_resumen'] ?? '',
    ];
}

// ── Endpoint: catálogo JSON con ETag para evitar retransmisión innecesaria ────
if (isset($_GET['action']) && $_GET['action'] === 'products_json') {
    ob_end_clean();
    $catalogJson = json_encode(['products' => $productsJs, 'suc' => $SUC_ID], JSON_UNESCAPED_UNICODE);
    $etag = '"' . md5($catalogJson) . '"';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache'); // revalidar siempre, pero usar ETag
    header('ETag: ' . $etag);
    header('Vary: Accept-Encoding');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304);
        exit;
    }
    if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
        header('Content-Encoding: gzip');
        echo gzencode($catalogJson, 6);
    } else {
        echo $catalogJson;
    }
    exit;
}

// Captcha inicial para el formulario de registro
if (empty($_SESSION['captcha_reg'])) {
    $captcha_n1 = rand(1, 10); $captcha_n2 = rand(1, 10);
    $_SESSION['captcha_reg'] = $captcha_n1 + $captcha_n2;
    $_SESSION['captcha_q']   = "$captcha_n1 + $captcha_n2 =";
}

// Gzip del HTML si el cliente lo soporta y el servidor no lo hace globalmente
if (!ini_get('zlib.output_compression') && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    ob_end_clean();
    ob_start('ob_gzhandler');
} else {
    ob_end_flush();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<!-- Google Analytics — cargado en idle para no bloquear FCP/LCP -->
<script>
  window.dataLayer = window.dataLayer || [];
  window.gtag = function(){dataLayer.push(arguments);};
  // Cola de eventos antes de que cargue el script real
  gtag('js', new Date());
  gtag('config', 'G-ZM015S9N6M', {send_page_view: false});

  function _loadGA() {
    if (window._gaLoaded) return;
    window._gaLoaded = true;
    var s = document.createElement('script');
    s.src = 'https://www.googletagmanager.com/gtag/js?id=G-ZM015S9N6M';
    s.async = true;
    s.onload = function(){ gtag('event', 'page_view'); };
    document.head.appendChild(s);
  }

  if ('requestIdleCallback' in window) {
    requestIdleCallback(_loadGA, {timeout: 4000});
  } else {
    // Fallback: carga tras primer evento de usuario o a los 4s
    ['pointerdown','touchstart','scroll','keydown'].forEach(function(e){
      window.addEventListener(e, _loadGA, {once:true, passive:true});
    });
    setTimeout(_loadGA, 4000);
  }
</script>

    <meta charset="UTF-8">
    <base href="<?php echo htmlspecialchars($shopDocumentBase); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDesc); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($siteUrl); ?>">

    <!-- Open Graph / WhatsApp / Facebook -->
    <meta property="og:type"         content="website">
    <meta property="og:url"          content="<?php echo htmlspecialchars($siteUrl); ?>">
    <meta property="og:site_name"    content="<?php echo htmlspecialchars($storeName); ?>">
    <meta property="og:title"        content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta property="og:description"  content="<?php echo htmlspecialchars($metaDesc); ?>">
    <meta property="og:locale"       content="es_CU">
    <meta property="og:image"        content="<?php echo htmlspecialchars($metaImg); ?>">
    <meta property="og:image:secure_url" content="<?php echo htmlspecialchars($metaImg); ?>">
    <meta property="og:image:type"   content="image/png">
    <meta property="og:image:width"  content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt"    content="<?php echo htmlspecialchars($storeName . ' — Tienda Online La Habana'); ?>">
    <?php if ($fbUrl): ?><meta property="og:see_also" content="<?php echo htmlspecialchars($fbUrl); ?>"><?php endif; ?>

    <!-- Twitter / X Card -->
    <meta name="twitter:card"         content="summary_large_image">
    <meta name="twitter:title"        content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta name="twitter:description"  content="<?php echo htmlspecialchars($metaDesc); ?>">
    <meta name="twitter:image"        content="<?php echo htmlspecialchars($metaImg); ?>">
    <meta name="twitter:image:alt"    content="<?php echo htmlspecialchars($storeName); ?>">

    <!-- Structured Data: LocalBusiness -->
    <script type="application/ld+json">
    <?php
    $ldJson = [
        '@context' => 'https://schema.org',
        '@type'    => 'LocalBusiness',
        'name'     => $storeName,
        'url'      => $siteUrl,
        'image'    => $metaImg,
        'description' => $metaDesc,
        'address'  => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $storeAddr,
            'addressLocality' => 'La Habana',
            'addressCountry'  => 'CU',
        ],
        'telephone' => $storeTel,
    ];
    if ($fbUrl) {
        $sameAs = array_filter([$fbUrl, $xUrl, $igUrl, $ytUrl]);
        $ldJson['sameAs'] = array_values($sameAs);
    }
    echo json_encode($ldJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG);
    ?>
    </script>

    <!-- PWA Tienda -->
    <meta name="theme-color" content="#0d6efd">
    <link rel="manifest" href="manifest-shop.php">
    <link rel="apple-touch-icon" href="icon-shop-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <!-- Performance: preconnect + preload recursos críticos -->
    <!-- Fuentes servidas en local — sin peticiones externas -->

    <!-- Bootstrap base se carga bajo demanda; el above-the-fold queda cubierto por CSS crítico -->
    <noscript><link rel="stylesheet" href="assets/css/bootstrap.min.css"></noscript>

    <!-- FontAwesome se carga bajo demanda para no penalizar el primer render -->
    <noscript><link rel="stylesheet" href="assets/css/all.min.css"></noscript>

    <!-- ═══════════════════════════════════════════════════════════════
         CSS CRÍTICO: subconjunto de Bootstrap necesario para el
         primer paint (grid + utilidades + btn + badge + form básico).
         Evita FOUC mientras carga bootstrap.min.css de forma async.
         ═══════════════════════════════════════════════════════════════ -->
    <style>
        /* Reset mínimo (el <style> principal refuerza esto) */
        *,*::before,*::after{box-sizing:border-box}
        /* Grid */
        .container,.container-fluid{width:100%;padding-right:1rem;padding-left:1rem;margin-right:auto;margin-left:auto}
        @media(min-width:576px){.container{max-width:540px}}
        @media(min-width:768px){.container{max-width:720px}}
        @media(min-width:992px){.container{max-width:960px}}
        @media(min-width:1200px){.container{max-width:1140px}}
        @media(min-width:1400px){.container{max-width:1320px}}
        .row{--bs-gutter-x:1.5rem;--bs-gutter-y:0;display:flex;flex-wrap:wrap;margin-top:calc(-1*var(--bs-gutter-y));margin-right:calc(-.5*var(--bs-gutter-x));margin-left:calc(-.5*var(--bs-gutter-x))}
        .row>*{flex-shrink:0;width:100%;max-width:100%;padding-right:calc(var(--bs-gutter-x)*.5);padding-left:calc(var(--bs-gutter-x)*.5);margin-top:var(--bs-gutter-y)}
        @media(min-width:768px){.col-md-6{flex:0 0 auto;width:50%}.col-md-4{flex:0 0 auto;width:33.333%}.col-md-8{flex:0 0 auto;width:66.667%}}
        @media(min-width:992px){.col-lg-4{flex:0 0 auto;width:33.333%}.col-lg-6{flex:0 0 auto;width:50%}.col-lg-8{flex:0 0 auto;width:66.667%}}
        /* Display */
        .d-none{display:none!important}.d-block{display:block!important}.d-flex{display:flex!important}.d-inline-block{display:inline-block!important}.d-grid{display:grid!important}
        @media(min-width:768px){.d-md-none{display:none!important}.d-md-flex{display:flex!important}.d-md-block{display:block!important}}
        @media(min-width:992px){.d-lg-none{display:none!important}.d-lg-flex{display:flex!important}.d-lg-block{display:block!important}}
        /* Flex */
        .flex-wrap{flex-wrap:wrap!important}.flex-grow-1{flex-grow:1!important}.flex-shrink-0{flex-shrink:0!important}
        .align-items-center{align-items:center!important}.align-items-start{align-items:flex-start!important}.align-items-end{align-items:flex-end!important}
        .justify-content-center{justify-content:center!important}.justify-content-between{justify-content:space-between!important}.justify-content-end{justify-content:flex-end!important}
        .gap-1{gap:.25rem!important}.gap-2{gap:.5rem!important}.gap-3{gap:1rem!important}
        /* Spacing */
        .m-0{margin:0!important}.m-1{margin:.25rem!important}.m-2{margin:.5rem!important}.m-3{margin:1rem!important}
        .mb-0{margin-bottom:0!important}.mb-1{margin-bottom:.25rem!important}.mb-2{margin-bottom:.5rem!important}.mb-3{margin-bottom:1rem!important}.mb-4{margin-bottom:1.5rem!important}.mb-5{margin-bottom:3rem!important}
        .mt-0{margin-top:0!important}.mt-1{margin-top:.25rem!important}.mt-2{margin-top:.5rem!important}.mt-3{margin-top:1rem!important}.mt-4{margin-top:1.5rem!important}.mt-5{margin-top:3rem!important}
        .me-0{margin-right:0!important}.me-1{margin-right:.25rem!important}.me-2{margin-right:.5rem!important}.me-3{margin-right:1rem!important}
        .ms-0{margin-left:0!important}.ms-1{margin-left:.25rem!important}.ms-2{margin-left:.5rem!important}.ms-3{margin-left:1rem!important}.ms-auto{margin-left:auto!important}
        .p-0{padding:0!important}.p-1{padding:.25rem!important}.p-2{padding:.5rem!important}.p-3{padding:1rem!important}.p-4{padding:1.5rem!important}
        .py-1{padding-top:.25rem!important;padding-bottom:.25rem!important}.py-2{padding-top:.5rem!important;padding-bottom:.5rem!important}.py-3{padding-top:1rem!important;padding-bottom:1rem!important}
        .px-1{padding-right:.25rem!important;padding-left:.25rem!important}.px-2{padding-right:.5rem!important;padding-left:.5rem!important}.px-3{padding-right:1rem!important;padding-left:1rem!important}.px-4{padding-right:1.5rem!important;padding-left:1.5rem!important}.px-5{padding-right:3rem!important;padding-left:3rem!important}
        .pe-1{padding-right:.25rem!important}.pe-2{padding-right:.5rem!important}.pe-3{padding-right:1rem!important}
        .ps-1{padding-left:.25rem!important}.ps-2{padding-left:.5rem!important}.ps-3{padding-left:1rem!important}
        /* Typography */
        .fw-bold{font-weight:700!important}.fw-semibold{font-weight:600!important}.fw-normal{font-weight:400!important}
        .text-center{text-align:center!important}.text-end{text-align:right!important}.text-start{text-align:left!important}
        .text-muted{color:#6c757d!important}.text-white{color:#fff!important}.text-primary{color:#0d6efd!important}.text-success{color:#198754!important}.text-danger{color:#dc3545!important}.text-warning{color:#ffc107!important}
        .small{font-size:.875em}.fs-5{font-size:1.25rem!important}.fs-6{font-size:1rem!important}
        .lh-1{line-height:1!important}
        /* Sizing */
        .w-100{width:100%!important}.w-auto{width:auto!important}.h-100{height:100%!important}.mw-100{max-width:100%!important}
        /* Button */
        .btn{display:inline-block;font-weight:400;line-height:1.5;text-align:center;text-decoration:none;vertical-align:middle;cursor:pointer;user-select:none;background-color:transparent;border:1px solid transparent;padding:.375rem .75rem;font-size:1rem;border-radius:.375rem;transition:color .15s,background-color .15s,border-color .15s,box-shadow .15s}
        .btn:disabled{opacity:.65;pointer-events:none}
        .btn-primary{color:#fff;background-color:#0d6efd;border-color:#0d6efd}
        .btn-secondary{color:#fff;background-color:#6c757d;border-color:#6c757d}
        .btn-outline-light{color:#f8f9fa;border-color:#f8f9fa}
        .btn-outline-secondary{color:#6c757d;border-color:#6c757d}
        .btn-sm{padding:.25rem .5rem;font-size:.875rem;border-radius:.25rem}
        .btn-lg{padding:.5rem 1rem;font-size:1.25rem;border-radius:.5rem}
        /* Badge */
        .badge{display:inline-block;padding:.35em .65em;font-size:.75em;font-weight:700;line-height:1;color:#fff;text-align:center;white-space:nowrap;vertical-align:baseline;border-radius:.375rem}
        .bg-primary{background-color:#0d6efd!important}.bg-success{background-color:#198754!important}.bg-danger{background-color:#dc3545!important}.bg-warning{background-color:#ffc107!important}.bg-secondary{background-color:#6c757d!important}.bg-light{background-color:#f8f9fa!important}.bg-dark{background-color:#212529!important}
        /* Form */
        .form-control,.form-select{display:block;width:100%;padding:.375rem .75rem;font-size:1rem;font-weight:400;line-height:1.5;color:#212529;background-color:#fff;background-clip:padding-box;border:1px solid #ced4da;border-radius:.375rem;transition:border-color .15s,box-shadow .15s}
        .form-control:focus,.form-select:focus{color:#212529;background-color:#fff;border-color:#86b7fe;outline:0;box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}
        .form-label{margin-bottom:.5rem;font-weight:500}
        .form-check{min-height:1.5rem;padding-left:1.5em;margin-bottom:.125rem}
        .form-check-input{width:1em;height:1em;margin-top:.25em;vertical-align:top;background-color:#fff;background-repeat:no-repeat;background-position:center;background-size:contain;border:1px solid rgba(0,0,0,.25);appearance:none}
        /* Visibility */
        .visually-hidden,.visually-hidden-focusable{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        /* Misc */
        .img-fluid{max-width:100%;height:auto}
        .rounded{border-radius:.375rem!important}.rounded-circle{border-radius:50%!important}.rounded-pill{border-radius:50rem!important}
        .border{border:1px solid #dee2e6!important}.border-0{border:0!important}
        .shadow-sm{box-shadow:0 .125rem .25rem rgba(0,0,0,.075)!important}.shadow{box-shadow:0 .5rem 1rem rgba(0,0,0,.15)!important}
        .position-relative{position:relative!important}.position-absolute{position:absolute!important}.position-fixed{position:fixed!important}.position-sticky{position:sticky!important}
        .top-0{top:0!important}.bottom-0{bottom:0!important}.start-0{left:0!important}.end-0{right:0!important}
        .z-3{z-index:3!important}
        .overflow-hidden{overflow:hidden!important}.overflow-auto{overflow:auto!important}
        .opacity-0{opacity:0!important}.opacity-25{opacity:.25!important}.opacity-75{opacity:.75!important}
        .text-decoration-none{text-decoration:none!important}
        .cursor-pointer{cursor:pointer}
        /* Alert (se usan en el body) */
        .alert{position:relative;padding:1rem;margin-bottom:1rem;border:1px solid transparent;border-radius:.375rem}
        .alert-info{color:#055160;background-color:#cff4fc;border-color:#b6effb}
        .alert-warning{color:#664d03;background-color:#fff3cd;border-color:#ffecb5}
        .alert-danger{color:#842029;background-color:#f8d7da;border-color:#f5c2c7}
        .alert-light{color:#636464;background-color:#fefefe;border-color:#fdfdfe}
    </style>
    <style>
        /* Fallbacks - sobreescritos por el skin activo al final del <head> */
        :root {
            --primary: #0d6efd;
            --secondary: #6c757d;
            --success: #10b981;
            --danger: #ef4444;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f9fafb;
            color: #1f2937;
        }

        /* DISEÑO PREMIUM ORIGINAL */
        .navbar-premium {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand-premium {
            font-size: 1.5rem;
            font-weight: 800;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .shop-brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            object-fit: contain;
            background: rgba(255,255,255,0.95);
            padding: 4px;
            box-shadow: 0 10px 20px rgba(15,23,42,.18);
        }
        
        .badge-sucursal {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .search-wrapper {
            position: relative;
            max-width: 600px;
            width: 100%;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: none;
            border-radius: 50px;
            background: rgba(255,255,255,0.9);
            font-size: 0.95rem;
        }
        
        .search-input:focus {
            outline: none;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
        }
        
        .search-results {
            position: absolute;
            top: calc(100% + 0.5rem);
            left: 0;
            right: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            max-height: 400px;
            overflow-y: auto;
            z-index: 9999;
            display: none;
        }
        
        .search-item {
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .search-item:hover { background: #f3f4f6; }
        
        .search-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .search-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
        }

        /* Carrusel: visibilidad + transición slide antes de que Bootstrap CSS cargue */
        .carousel{position:relative}.carousel-inner{overflow:hidden;position:relative;width:100%}
        .carousel-item{display:none;float:left;width:100%;margin-right:-100%;backface-visibility:hidden;transition:transform .55s ease-in-out}
        .carousel-item.active{display:block}
        .carousel-item-next,.carousel-item-prev{display:block}
        .carousel-item-next:not(.carousel-item-start),.active.carousel-item-end{transform:translateX(100%)}
        .carousel-item-prev:not(.carousel-item-end),.active.carousel-item-start{transform:translateX(-100%)}
        .carousel-item-start,.carousel-item-end{transform:translateX(0)}
        .carousel-control-prev,.carousel-control-next{position:absolute;top:0;bottom:0;z-index:1;display:flex;align-items:center;justify-content:center;width:15%;color:#fff;opacity:.5;transition:opacity .15s}
        .carousel-control-prev{left:0}.carousel-control-next{right:0}
        .carousel-control-prev-icon,.carousel-control-next-icon{display:inline-block;width:2rem;height:2rem;background-repeat:no-repeat;background-position:50%;background-size:100% 100%}
        .carousel-control-prev-icon{background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z'/%3e%3c/svg%3e")}
        .carousel-control-next-icon{background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e")}

        .promo-carousel {
            margin: 2rem 0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        .promo-slide {
            height: 124px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 1rem;
            text-align: center;
            color: white;
            background-repeat: no-repeat;
            background-position: center center;
            background-size: 100% 100%;
        }
        
        .promo-slide h2, .promo-slide-title {
            font-size: 1.7rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
            text-shadow: 0 1px 4px rgba(0,0,0,.45);
        }

        .promo-slide p {
            font-size: 1rem;
            font-weight: 500;
            text-shadow: 0 1px 3px rgba(0,0,0,.4);
        }
        
        .gradient-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .gradient-2 { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
        .gradient-3 { background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%); }
        .gradient-4 { background: linear-gradient(135deg, #fccb90 0%, #d57eeb 100%); }

        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin: 2rem 0;
        }
        
        .category-pills {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .cat-pill {
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            background: #f3f4f6;
            color: #1f2937;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .cat-pill:hover, .cat-pill.active {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        @media (min-width: 1400px) {
            .products-grid { grid-template-columns: repeat(5, 1fr); }
        }
        
        @media (min-width: 992px) and (max-width: 1399px) {
            .products-grid { grid-template-columns: repeat(4, 1fr); }
        }
        
        /* SKELETON LOADER */
        @keyframes placeholderShimmer {
            0% { background-position: -468px 0; }
            100% { background-position: 468px 0; }
        }

        .skeleton {
            background: #f6f7f8;
            background-image: linear-gradient(to right, #f6f7f8 0%, #edeef1 20%, #f6f7f8 40%, #f6f7f8 100%);
            background-repeat: no-repeat;
            background-size: 800px 104px;
            display: inline-block;
            position: relative;
            animation: placeholderShimmer 1.2s linear infinite forwards;
        }

        .skeleton-img { width: 100%; height: 200px; border-radius: 15px; }
        .skeleton-text { height: 15px; margin-bottom: 10px; border-radius: 4px; }
        .skeleton-title { width: 80%; }
        .skeleton-price { width: 40%; }

        /* LAZY LOADING & BLUR EFFECT */
        .lazy-img {
            filter: blur(5px);
            transition: filter 0.3s ease-in-out;
            opacity: 0;
        }
        .lazy-img.loaded {
            filter: blur(0);
            opacity: 1;
        }
        .product-image.priority-image {
            filter: none;
            opacity: 1;
        }

        .bg-orange { background-color: #fd7e14 !important; color: white !important; }
        
        .product-ribbon {
            position: absolute;
            top: 15px;
            left: -5px;
            z-index: 10;
            padding: 4px 12px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.2);
            border-radius: 0 4px 4px 0;
            letter-spacing: 0.5px;
        }
        
        .product-ribbon::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 0;
            border-top: 5px solid rgba(0,0,0,0.3);
            border-left: 5px solid transparent;
        }

        .btn-wishlist {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
            background: white;
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            color: #ccc;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-wishlist.active {
            color: #ef4444;
            transform: scale(1.1);
        }
        .btn-wishlist:hover { transform: scale(1.2); }

        .stock-alert-badge {
            position: absolute;
            bottom: 10px;
            right: 10px;
            z-index: 5;
            background: #fff5f5;
            color: #e53e3e;
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 0.65rem;
            font-weight: 800;
            border: 1px solid #feb2b2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            animation: pulse-danger 2s infinite;
        }

        @keyframes pulse-danger {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .product-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s;
            cursor: pointer;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .product-image-wrapper {
            position: relative;
            /* aspect-ratio reserva el espacio antes de que cargue la imagen (CLS=0) */
            aspect-ratio: 1 / 1;
            height: auto;
            overflow: hidden;
            background: #f3f4f6;
            contain: layout;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            /* Mejora el renderizado en conexiones lentas: muestra el color de fondo rápido */
            background-color: #e5e7eb;
        }
        
        .product-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 800;
            color: white;
        }
        
        .stock-badge {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(255,255,255,0.95);
        }
        
        .stock-badge.in-stock { color: var(--success); }
        .stock-badge.out-of-stock { color: var(--danger); }

        /* ── Estrellas en tarjetas ── */
        .card-stars {
            display: flex; align-items: center; gap: 3px;
            font-size: .7rem; color: #f59e0b;
            margin-bottom: .35rem;
        }
        .card-stars .stars-count { color: #6b7280; font-size:.65rem; }

        /* ── Variantes en modal ── */
        .variant-btn { font-size: .82rem; transition: all .15s; padding: .3rem .85rem; }
        .variant-btn.active {
            background: #3b82f6 !important;
            color: white !important;
            border-color: #3b82f6 !important;
        }

        /* ── Picker de estrellas en formulario reseña ── */
        .star-pick { transition: transform .1s; display: inline-block; }
        .star-pick:hover { transform: scale(1.25); }

        /* ── Time Slots ── */
        .slot-card {
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px 8px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            background: white;
            user-select: none;
        }
        .slot-card:hover {
            border-color: #3b82f6;
            background: #eff6ff;
            transform: translateY(-2px);
        }
        .slot-card.active {
            border-color: #3b82f6;
            background: #dbeafe;
            box-shadow: 0 0 0 3px rgba(59,130,246,.2);
        }
        .slot-card.disabled {
            opacity: .38;
            cursor: not-allowed;
            pointer-events: none;
        }
        .slot-icon  { font-size: 1.6rem; margin-bottom: 4px; }
        .slot-name  { font-weight: 800; font-size: .9rem; color: #1e293b; }
        .slot-hours { font-size: .7rem; color: #64748b; margin-top: 2px; }
        
        .product-body {
            padding: 0.875rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .product-category {
            color: #6b7280;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        
        .product-name {
            font-size: 0.875rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        
        .product-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            gap: 0.5rem;
        }
        
        .product-price {
            font-size: 1.125rem;
            font-weight: 800;
            color: var(--primary);
        }
        
        .btn-add-cart {
            padding: 0.5rem 0.875rem;
            border-radius: 50px;
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
        }
        
        .btn-add-cart:hover:not(:disabled) { background: #0b5ed7; }
        .btn-add-cart:disabled { background: #9ca3af; cursor: not-allowed; }

        /* ESTILOS CARRITO FLOTANTE (Derecha) */
        .cart-float {
            position: fixed; bottom: 2rem; right: 2rem; width: 64px; height: 64px;
            border-radius: 50%; background: linear-gradient(135deg, var(--primary) 0%, #0b5ed7 100%);
            color: white; border: none; box-shadow: 0 8px 16px rgba(13,110,253,0.4);
            z-index: 999; display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; cursor: pointer;
        }
        .cart-badge {
            position: absolute; top: -4px; right: -4px; background: var(--danger);
            color: white; width: 24px; height: 24px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; border: 2px solid white;
        }
        
        @media (max-width: 768px) {
            .products-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .promo-slide { height: 107px; }
            .promo-slide h2, .promo-slide-title { font-size: 1.25rem; }
            .promo-slide p { font-size: 0.85rem; }
        }

        /* ======================================================= */
        /* BOTONES FLOTANTES: CHAT Y WHATSAPP (Izquierda Abajo) */
        /* ======================================================= */
        .floating-container {
            position: fixed;
            bottom: 20px;
            left: 20px; /* Posición izquierda */
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .float-btn {
            width: 55px; height: 55px;
            border-radius: 50%; color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            cursor: pointer; transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
        }
        .float-btn:hover { transform: scale(1.1); color: white; }
        .btn-whatsapp { background-color: #25d366; }
        .btn-chat { background-color: #0d6efd; position: relative; }
        
        /* CHAT WINDOW */
        .chat-window {
            position: fixed; bottom: 85px; left: 20px; width: 320px; height: 420px;
            background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            display: none; flex-direction: column; z-index: 10000; overflow: hidden; border: 1px solid #e5e7eb;
        }
        .chat-header { background: #0d6efd; color: white; padding: 12px 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .chat-body { flex: 1; padding: 15px; overflow-y: auto; background: #f8f9fa; display: flex; flex-direction: column; gap: 8px; }
        .chat-footer { padding: 12px; background: white; border-top: 1px solid #eee; display: flex; gap: 8px; }
        
        .msg-bubble { max-width: 85%; padding: 8px 12px; border-radius: 12px; font-size: 0.9rem; line-height: 1.4; word-wrap: break-word; }
        .msg-client { align-self: flex-end; background: #0d6efd; color: white; border-bottom-right-radius: 2px; }
        .msg-admin { align-self: flex-start; background: #e9ecef; color: #333; border-bottom-left-radius: 2px; }
        .chat-badge-notify { position: absolute; top: 0; right: 0; background: red; width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; display: none; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* Zoom overlay imagen producto */
        #imgZoomOverlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0,0,0,0.85);
            align-items: center;
            justify-content: center;
            cursor: zoom-out;
            animation: fadeInZoom .18s ease;
        }
        #imgZoomOverlay.active { display: flex; }
        #imgZoomOverlay img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.6);
            transform: scale(1);
            animation: popZoom .2s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes popZoom { from { transform: scale(0.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes fadeInZoom { from { opacity: 0; } to { opacity: 1; } }
        #btnZoomImg {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255,255,255,0.92);
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: zoom-in;
            color: #333;
            font-size: 15px;
            transition: background .15s, transform .15s;
            z-index: 5;
        }
        #btnZoomImg:hover { background: #fff; transform: scale(1.1); }
        #btnZoomImg.visible { display: flex; }

        /* ══════════════════════════════════════════════════
           MODAL DETALLE PRODUCTO — DISEÑO PREMIUM
           ══════════════════════════════════════════════════ */
        #modalDetail .modal-content {
            border: none !important;
            border-radius: 20px !important;
            box-shadow: 0 24px 64px rgba(0,0,0,0.22) !important;
            overflow: hidden;
        }
        #modalDetail .modal-dialog { max-width: 860px; }

        /* Panel izquierdo: imagen */
        .detail-img-panel {
            background: #f4f5f7;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem 1.25rem;
            position: relative;
            min-height: 420px;
        }
        .detail-img-main-wrap {
            position: relative;
            width: 100%;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #detailImg {
            max-width: 100%;
            max-height: 320px;
            object-fit: contain;
            border-radius: 12px;
            transition: opacity .25s;
        }
        #detailPlaceholder {
            width: 140px; height: 140px;
            border-radius: 16px;
            font-size: 2.5rem;
            display: flex; align-items: center; justify-content: center;
        }
        /* Thumbnails verticales bajo la imagen principal */
        #detailThumbs {
            display: flex;
            flex-direction: row;
            justify-content: center;
            gap: 10px;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .detail-thumb {
            width: 64px; height: 64px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            border: 2.5px solid transparent;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: border-color .18s, transform .15s, box-shadow .15s;
            opacity: .72;
        }
        .detail-thumb:hover { transform: translateY(-2px); opacity: 1; box-shadow: 0 4px 14px rgba(0,0,0,0.14); }
        .detail-thumb.active { border-color: var(--primary, #0d6efd); opacity: 1; box-shadow: 0 0 0 3px rgba(13,110,253,0.18); }

        /* Panel derecho: información */
        .detail-info-panel {
            display: flex;
            flex-direction: column;
            padding: 2rem 2rem 1.5rem;
            max-height: 85vh;
            overflow-y: auto;
            scrollbar-width: thin;
        }
        .detail-info-panel::-webkit-scrollbar { width: 4px; }
        .detail-info-panel::-webkit-scrollbar-track { background: transparent; }
        .detail-info-panel::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

        .detail-category-label {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--primary, #0d6efd);
            margin-bottom: .4rem;
        }
        #detailName {
            font-size: 1.45rem !important;
            font-weight: 800;
            line-height: 1.25;
            color: #111827;
            margin-bottom: .6rem;
        }
        .detail-price-row {
            display: flex;
            align-items: baseline;
            gap: .6rem;
            margin-bottom: 1rem;
        }
        #detailPrice {
            font-size: 2rem !important;
            font-weight: 800;
            color: var(--primary, #0d6efd);
            line-height: 1;
        }
        .price-original { font-size: 1rem; color: #9ca3af; text-decoration: line-through; }
        .detail-unit-label { font-size: .78rem; color: #9ca3af; font-weight: 500; }
        .detail-stars { font-size: .85rem; color: #f59e0b; }

        .detail-divider { border: none; border-top: 1px solid #f0f0f0; margin: .9rem 0; }

        .detail-meta {
            display: flex; gap: 1.25rem; flex-wrap: wrap;
            font-size: .78rem; color: #6b7280;
            margin-bottom: .9rem;
        }
        .detail-meta span { display: flex; align-items: center; gap: .3rem; }
        .detail-meta strong { color: #374151; }

        .detail-desc {
            font-size: .875rem;
            color: #4b5563;
            line-height: 1.65;
            white-space: pre-line;
            margin-bottom: 1rem;
        }
        .detail-desc-label {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: .3rem;
        }

        /* Stock badge encima de la imagen */
        #detailStockBadge {
            position: absolute;
            top: 14px; left: 14px;
            font-size: .72rem;
            padding: .35em .75em;
            z-index: 3;
        }

        /* Botón de acción principal */
        #btnAddDetail {
            border-radius: 50px !important;
            font-size: 1rem;
            font-weight: 700;
            padding: .75rem 1.5rem;
            letter-spacing: .02em;
            box-shadow: 0 4px 14px rgba(13,110,253,0.35);
            transition: transform .15s, box-shadow .15s;
        }
        #btnAddDetail:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13,110,253,0.45);
        }

        /* Variantes */
        .variant-chip {
            padding: .35rem .9rem;
            border-radius: 50px;
            border: 1.5px solid #d1d5db;
            background: #fff;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            transition: border-color .15s, background .15s, color .15s;
            color: #374151;
        }
        .variant-chip.active, .variant-chip:hover {
            border-color: var(--primary, #0d6efd);
            background: var(--primary, #0d6efd);
            color: #fff;
        }

        /* Reseñas */
        .detail-reviews-label {
            font-size: .7rem; font-weight: 700;
            letter-spacing: .06em; text-transform: uppercase;
            color: #9ca3af;
        }
        #reviewsList { max-height: 140px; overflow-y: auto; scrollbar-width: thin; }

        /* Cerrar modal: posición */
        #modalDetail .btn-close-premium {
            position: absolute;
            top: 14px; right: 14px;
            z-index: 10;
            width: 32px; height: 32px;
            background: rgba(255,255,255,.9);
            border: none;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
            transition: background .15s, transform .15s;
            color: #374151;
            font-size: 14px;
        }
        #modalDetail .btn-close-premium:hover { background: #fff; transform: scale(1.1); }

        @media (max-width: 767px) {
            #modalDetail .modal-dialog { margin: .5rem; max-width: 100%; }
            .detail-img-panel { min-height: 220px; padding: 1.25rem 1rem .75rem; }
            #detailImg { max-height: 200px; }
            .detail-info-panel { padding: 1.25rem 1rem 1rem; max-height: none; }
            #detailName { font-size: 1.2rem !important; }
            #detailPrice { font-size: 1.6rem !important; }
        }

        /* Feature 2: precio tachado */
        .price-original { font-size:.82em; color:#999; text-decoration:line-through; margin-right:4px; }
        .price-oferta { color:#dc3545; font-weight:700; }

        /* Feature 13: fly-to-cart dot */
        .fly-dot { position:fixed; width:14px; height:14px; border-radius:50%; background:var(--primary,#0d6efd);
                   z-index:9999; pointer-events:none;
                   transition:left .55s cubic-bezier(.3,.8,.7,.2),top .55s cubic-bezier(.3,.8,.7,.2),
                               opacity .45s ease-in,transform .45s ease-in; }
        @keyframes cartBounce { 0%,100%{transform:scale(1)} 40%{transform:scale(1.4)} 70%{transform:scale(0.88)} }
        .cart-bounce { animation: cartBounce .45s ease-in-out; }

        /* Feature 12: abandoned cart banner */
        .abandoned-banner { background:linear-gradient(135deg,#fff3cd,#ffe69c); border-left:4px solid #f59e0b;
                            border-radius:8px; padding:10px 16px; margin-bottom:12px;
                            display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .abandoned-banner .ab-msg { flex:1; font-weight:600; font-size:.92rem; }

        /* Feature 1: restock aviso form */
        .aviso-form-wrap { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:12px; margin-top:8px; }

        /* Modo offline: banner fijo en top */
        .shop-offline-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 10000;
            background: #dc3545;
            color: #fff;
            text-align: center;
            padding: 8px 16px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        body.shop-is-offline { padding-top: 36px; }

        /* Modo ahorro de datos (2G/saveData): oculta imágenes, reduce animaciones */
        body.conn-slow .product-image-wrapper picture,
        body.conn-slow .product-image { display: none; }
        body.conn-slow .product-placeholder { display: flex !important; }
        body.conn-slow *, body.conn-slow *::before, body.conn-slow *::after {
            animation-duration: 0.01ms !important;
            transition-duration: 0.01ms !important;
        }
        /* 3G: mantiene imágenes pero desactiva animaciones pesadas */
        body.conn-medium *, body.conn-medium *::before, body.conn-medium *::after {
            animation-duration: 0.1ms !important;
        }
    </style>
    <?php echo shop_skin_render($ACTIVE_SKIN_ID); ?>
</head>
<body class="<?php echo htmlspecialchars($ACTIVE_SKIN_BODY_CLASS); ?>">

<div id="shopOfflineBanner" class="d-none shop-offline-bar">
    <span class="me-2" aria-hidden="true">📡</span>Sin conexión &mdash; viendo catálogo guardado
</div>

<nav class="navbar-premium">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between w-100 flex-wrap gap-3">
            <a href="shop.php" class="navbar-brand-premium">
                <?php if ($companyBrandLogo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($companyBrandLogo); ?>" alt="<?php echo htmlspecialchars($companyBrandName); ?>" class="shop-brand-logo">
                <?php else: ?>
                    <span aria-hidden="true">🏬</span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($companyBrandName); ?></span>
                <span class="badge-sucursal">Tienda online · Suc <?= $SUC_ID ?></span>
            </a>

            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="toggleTrackingModal()">
                    <span class="me-1" aria-hidden="true">🚚</span> <span class="d-none d-md-inline" data-i18n="nav.tracking">Rastreo</span>
                </button>
                <a href="como_comprar.php" class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-bold" title="¿Cómo comprar?">
                    <span class="me-1" aria-hidden="true">?</span> <span class="d-none d-md-inline" data-i18n="nav.help">Ayuda</span>
                </a>
                <a href="quienes_somos.php" class="btn btn-outline-info btn-sm rounded-pill px-3 fw-bold" title="Quiénes Somos">
                    <span class="me-1" aria-hidden="true">🏢</span> <span class="d-none d-md-inline" data-i18n="nav.about">Nosotros</span>
                </a>

                <!-- Selector de idioma -->
                <div class="btn-group btn-group-sm" role="group" aria-label="Idioma">
                    <button class="btn btn-sm btn-outline-secondary lang-btn active"
                            data-lang="es" onclick="setLang('es')">🇨🇺 ES</button>
                    <button class="btn btn-sm btn-outline-secondary lang-btn"
                            data-lang="en" onclick="setLang('en')">🇺🇸 EN</button>
                </div>

                <!-- Toggle de moneda -->
                <div class="btn-group btn-group-sm" id="shopCurrencyToggle" title="Cambiar moneda de visualización">
                    <button class="btn btn-outline-light btn-sm fw-bold" onclick="setShopCurrency('CUP')">CUP</button>
                    <button class="btn btn-outline-light btn-sm fw-bold" onclick="setShopCurrency('USD')">USD</button>
                    <button class="btn btn-outline-light btn-sm fw-bold" onclick="setShopCurrency('MLC')">MLC</button>
                </div>

                <div id="authButtons">
                    <?php if(isset($_SESSION['client_id'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-light btn-sm rounded-pill px-3 dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars(explode(' ', $_SESSION['client_name'])[0]) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal()"><i class="fas fa-user-edit me-2 text-primary"></i><span data-i18n="nav.profile">Mi Perfil</span></a></li>
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal('password')"><i class="fas fa-key me-2 text-secondary"></i><span data-i18n="nav.change_password">Cambiar contraseña</span></a></li>
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal(); setTimeout(()=>document.getElementById('orderHistoryContent')?.scrollIntoView({behavior:'smooth'}),400)"><i class="fas fa-box-open me-2 text-warning"></i><span data-i18n="nav.orders">Mis Pedidos</span></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" onclick="logoutClient()"><i class="fas fa-sign-out-alt me-2 text-danger"></i><span data-i18n="nav.logout">Salir</span></a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <button class="btn btn-light btn-sm rounded-pill px-3" onclick="toggleAuthModal()">
                            <span class="me-1" aria-hidden="true">👤</span> <span data-i18n="nav.account">Mi Cuenta</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="search-wrapper w-100 mt-2">
                <span class="search-icon" aria-hidden="true">⌕</span>
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar productos..." autocomplete="off" data-i18n-attr="placeholder:nav.search_ph">
                <div id="searchResults" class="search-results"></div>
            </div>
        </div>
    </div>
</nav>

<main id="main-content">

<!-- h1 visible solo para lectores de pantalla; el logo/marca visual lo reemplaza visualmente -->
<h1 class="visually-hidden"><?= htmlspecialchars($config['tienda_nombre'] ?? 'Tienda') ?> — Catálogo de productos</h1>

<div class="container">
    <div id="promoCarousel" class="carousel slide promo-carousel">
        <div class="carousel-inner">
            <?php
            $shopBanners = $config['banners'] ?? [
                ['titulo' => '🚀 Envíos a La Habana',  'subtitulo' => 'Calcula el costo a tu barrio', 'imagen' => '', 'color_clase' => 'gradient-1'],
                ['titulo' => '🍰 Dulces Frescos',       'subtitulo' => 'Hechos cada mañana',            'imagen' => '', 'color_clase' => 'gradient-2'],
                ['titulo' => '🛒 Ofertas Semanales',    'subtitulo' => 'Grandes descuentos',             'imagen' => '', 'color_clase' => 'gradient-3'],
                ['titulo' => '🍕 Comida Caliente',      'subtitulo' => 'Lista para llevar',              'imagen' => '', 'color_clase' => 'gradient-4'],
            ];
            foreach ($shopBanners as $bi => $sb):
                $sbImg   = trim((string)($sb['imagen'] ?? ''));
                $sbHasImg = $sbImg !== '' && file_exists(__DIR__ . '/' . $sbImg);
                $sbSize  = trim((string)($sb['bg_size'] ?? 'cover')) ?: 'cover';
                // 'cover' → 100% 100%: llena ancho y alto sin recortar ni repetir
                $sbSizeCss = ($sbSize === 'cover') ? '100% 100%' : $sbSize;
                $sbBg    = $sbHasImg
                    ? 'background-image:url(\'' . htmlspecialchars($sbImg, ENT_QUOTES) . '?v=' . filemtime(__DIR__ . '/' . $sbImg) . '\');background-size:' . htmlspecialchars($sbSizeCss, ENT_QUOTES) . ';background-position:center center;background-repeat:no-repeat;'
                    : '';
                $sbClass = $sbHasImg ? '' : htmlspecialchars($sb['color_clase'] ?? 'gradient-' . ($bi + 1), ENT_QUOTES);
            ?>
            <div class="carousel-item <?php echo $bi === 0 ? 'active' : ''; ?>">
                <div class="promo-slide <?php echo $sbClass; ?>" style="<?php echo $sbBg; ?>">
                    <p class="promo-slide-title"><?php echo htmlspecialchars((string)($sb['titulo'] ?? ''), ENT_QUOTES); ?></p>
                    <p><?php echo htmlspecialchars((string)($sb['subtitulo'] ?? ''), ENT_QUOTES); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="carousel-control-prev" type="button" data-bs-target="#promoCarousel" data-bs-slide="prev" aria-label="Diapositiva anterior">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#promoCarousel" data-bs-slide="next" aria-label="Diapositiva siguiente">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
        </button>
    </div>
</div>

<div class="container">
    <div class="filter-section">
        <div class="row align-items-center">
            <div class="col-md-6">
                <p class="mb-3 mb-md-0 fw-bold fs-5">
                    <span class="me-2" aria-hidden="true">▦</span><span data-i18n="filter.categories">Categorías</span>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <label for="sortSelect" class="visually-hidden">Ordenar productos</label>
                <select id="sortSelect" class="form-select d-inline-block w-auto" onchange="location.href='?cat=<?= urlencode($catFilter) ?>&sort='+this.value">
                    <option value="categoria_asc" <?= $sort==='categoria_asc'?'selected':'' ?> data-i18n="sort.category">Ordenar: Categoría</option>
                    <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?> data-i18n="sort.price_asc">Precio: Menor a Mayor</option>
                    <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?> data-i18n="sort.price_desc">Precio: Mayor a Menor</option>
                    <option value="popular" <?= $sort==='popular'?'selected':'' ?> data-i18n="sort.popular">🔥 Más populares</option>
                </select>
            </div>
        </div>
        
        <div class="category-pills" id="categoryPills">
            <button class="cat-pill <?= empty($catFilter)?'active':'' ?>" onclick="filterByCategory('')">
                <span class="me-1" aria-hidden="true">▥</span> <span data-i18n="filter.all">Todas</span>
            </button>
            <?php foreach($cats as $c): ?>
                <button class="cat-pill <?= $c===$catFilter?'active':'' ?>" onclick="filterByCategory(<?= json_encode($c) ?>)">
                    <?= htmlspecialchars($c) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="fw-bold mb-0 fs-4" data-i18n="prod.available_title">Productos Disponibles</h2>
        <span class="badge bg-primary" style="font-size: 1rem; padding: 0.5rem 1rem;">
            <?= count($productos) ?> <span data-i18n="prod.count" data-i18n-n="<?= count($productos) ?>">productos</span>
        </span>
    </div>
    
    <!-- Skeleton Loaders (Se ocultan vía JS) -->
    <div id="skeletonLoader" class="container mt-4">
        <div class="row g-3">
            <?php for($i=0; $i<8; $i++): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="product-card">
                        <div class="skeleton skeleton-img"></div>
                        <div class="p-3">
                            <div class="skeleton skeleton-text skeleton-title"></div>
                            <div class="skeleton skeleton-text skeleton-price"></div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="products-grid" id="productsGrid" style="display:none;">
        <?php if (count($productos) == 0): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>No hay productos disponibles</strong><br>
                    Verifica que los productos tengan:<br>
                    • <code>activo = 1</code><br>
                    • <code>es_web = 1</code><br>
                    • <code>id_empresa = <?= $EMP_ID ?></code><br>
                    • <code>sucursales_web</code> vacío o contenga "<?= $SUC_ID ?>"
                </div>
            </div>
        <?php endif; ?>
        
        <?php foreach($productos as $idx => $p):
            [$hasImg, $mtimeMain] = shop_image_meta((string)$p['codigo']);
            $imgV = $mtimeMain ? '&v=' . $mtimeMain : '';
            $imgUrl = $hasImg ? $shopAssetPrefix . 'image.php?code=' . urlencode($p['codigo']) . $imgV : null;
            [$hasExtra1, $mtime1] = shop_image_meta((string)$p['codigo'] . '_extra1');
            $imgV1 = $mtime1 ? '&v=' . $mtime1 : '';
            [$hasExtra2, $mtime2] = shop_image_meta((string)$p['codigo'] . '_extra2');
            $imgV2 = $mtime2 ? '&v=' . $mtime2 : '';
            $stock = floatval($p['stock_total'] ?? 0);
            $hasStock = $stock > 0;
            $esReservable = intval($p['es_reservable'] ?? 0) === 1;
            $bg = "#" . substr(md5($p['nombre']), 0, 6);
            $initials = mb_strtoupper(mb_substr($p['nombre'], 0, 2));
            $isPriorityCard = $idx < 4;
        ?>
        <div class="product-card" data-cat="<?= htmlspecialchars($p['categoria'] ?? '', ENT_QUOTES) ?>" onclick='openProductDetail(<?= json_encode([
            "id"          => $p['codigo'],
            "name"        => $p['nombre'],
            "price"       => floatval($p['precio']),
            "desc"        => $p['descripcion'] ?? '',
            "img"         => $imgUrl,
            "imgExtra1"   => $hasExtra1 ? $shopAssetPrefix . 'image.php?code=' . urlencode($p['codigo'] . '_extra1') . $imgV1 : null,
            "imgExtra2"   => $hasExtra2 ? $shopAssetPrefix . 'image.php?code=' . urlencode($p['codigo'] . '_extra2') . $imgV2 : null,
            "bg"          => $bg,
            "initials"    => $initials,
            "hasImg"      => $hasImg,
            "hasStock"    => $hasStock,
            "stock"       => $stock,
            "code"        => $p['codigo'],
            "slug"        => $p['slug'] ?? shop_slugify((string)($p['nombre'] ?? 'producto')),
            "cat"         => $p['categoria'],
            "unit"        => $p['unidad_medida'] ?? '',
            "color"       => $p['color'] ?? '',
            "esReservable"  => $esReservable,
            "precioOferta"  => floatval($p['precio_oferta'] ?? 0),
            "esCombo"       => intval($p['es_combo'] ?? 0) === 1,
            "comboResumen"  => $p['combo_resumen'] ?? '',
        ], JSON_UNESCAPED_UNICODE) ?>)'>
            <div class="product-image-wrapper">
                <?php if(isset($_SESSION['client_id'])): 
                    $isFavorite = in_array($p['codigo'], $userWishlist);
                ?>
                    <button class="btn-wishlist <?php echo $isFavorite ? 'active' : ''; ?>"
                            aria-label="<?php echo $isFavorite ? 'Quitar de favoritos' : 'Agregar a favoritos'; ?>"
                            onclick="event.stopPropagation(); toggleWishlist('<?php echo $p['codigo']; ?>', this)">
                        <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>

                <?php if(!empty($p['etiqueta_web'])): ?>
                    <div class="product-ribbon <?php echo $p['etiqueta_color'] ?: 'bg-danger'; ?>">
                        <?php echo htmlspecialchars($p['etiqueta_web']); ?>
                    </div>
                <?php endif; ?>

                <?php if (intval($p['es_combo'] ?? 0) === 1): ?>
                    <div class="product-ribbon bg-dark" style="top:10px;left:10px;right:auto;">
                        Combo
                    </div>
                <?php endif; ?>

                <?php 
                $umbralBajo = ($p['stock_minimo'] > 0) ? ($p['stock_minimo'] * 1.5) : 5;
                if($hasStock && $stock <= $umbralBajo): ?>
                    <div class="stock-alert-badge">
                        <span class="me-1" aria-hidden="true">🔥</span> ¡Solo <?php echo $stock; ?> disponibles!
                    </div>
                <?php endif; ?>

                <?php if($hasImg): ?>
                    <picture>
                        <source type="image/avif" srcset="<?= $imgUrl ?>&amp;fmt=avif&amp;w=200 200w, <?= $imgUrl ?>&amp;fmt=avif&amp;w=400 400w" sizes="(max-width: 767px) 45vw, (max-width: 1399px) 22vw, 178px">
                        <source type="image/webp" srcset="<?= $imgUrl ?>&amp;fmt=webp&amp;w=200 200w, <?= $imgUrl ?>&amp;fmt=webp&amp;w=400 400w" sizes="(max-width: 767px) 45vw, (max-width: 1399px) 22vw, 178px">
                        <img src="<?= $imgUrl ?>&amp;fmt=jpg&amp;w=200"
                             class="product-image <?= $isPriorityCard ? 'priority-image' : 'lazy-img' ?>"
                             alt="<?= htmlspecialchars($p['nombre']) ?>"
                             width="400" height="400"
                             sizes="(max-width: 767px) 45vw, (max-width: 1399px) 22vw, 178px"
                             loading="<?= $isPriorityCard ? 'eager' : 'lazy' ?>"
                             fetchpriority="<?= $isPriorityCard ? 'high' : 'auto' ?>"
                             decoding="async"
                             onload="this.classList.add('loaded')"
                             onerror="this.closest('picture').replaceWith(Object.assign(document.createElement('div'),{className:'product-placeholder',textContent:'<?= addslashes($initials) ?>',style:'background:<?= $bg ?>cc'}))">
                    </picture>
                <?php else: ?>
                    <div class="product-placeholder" style="background: <?= $bg ?>cc"><?= $initials ?></div>
                <?php endif; ?>
                
                <?php if ($hasStock): ?>
                <div class="stock-badge in-stock" data-i18n="prod.available">✓ Disponible</div>
                <?php elseif ($esReservable): ?>
                <div class="stock-badge" style="background:rgba(255,193,7,0.92);color:#1a1a1a;" data-i18n="prod.reservable">📅 Reservable</div>
                <?php else: ?>
                <div class="stock-badge out-of-stock" data-i18n="prod.out_of_stock">✗ Agotado</div>
                <?php endif; ?>
            </div>

            <div class="product-body">
                <div class="product-category"><?= htmlspecialchars($p['categoria'] ?? 'General') ?></div>
                <h3 class="product-name"><?= htmlspecialchars($p['nombre']) ?></h3>

                <?php if (floatval($p['avg_rating'] ?? 0) > 0): ?>
                <div class="card-stars">
                    <?php
                    $avg = floatval($p['avg_rating']);
                    for ($s = 1; $s <= 5; $s++) {
                        if ($s <= floor($avg)) echo '<span aria-hidden="true">★</span>';
                        elseif ($s - $avg < 1) echo '<span aria-hidden="true">★</span>';
                        else echo '<span aria-hidden="true">☆</span>';
                    }
                    ?>
                    <span class="stars-count">(<?= intval($p['total_resenas']) ?>)</span>
                </div>
                <?php endif; ?>

                <div class="product-footer">
                    <div class="product-price" data-cup="<?= floatval($p['precio']) ?>" data-cup-oferta="<?= floatval($p['precio_oferta'] ?? 0) ?>">
                        <?php $precioOferta = floatval($p['precio_oferta'] ?? 0); ?>
                        <?php if ($precioOferta > 0 && $precioOferta < floatval($p['precio'])): ?>
                            <span class="price-original">$<?= number_format($p['precio'], 2) ?></span>
                            <span class="price-oferta">$<?= number_format($precioOferta, 2) ?></span>
                        <?php else: ?>
                            $<?= number_format($p['precio'], 2) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasStock): ?>
                        <button class="btn-add-cart"
                                onclick="event.stopPropagation(); addToCart('<?= $p['codigo'] ?>', '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['precio'] ?>)">
                            <span data-i18n="prod.add">+ Agregar</span>
                        </button>
                    <?php elseif ($esReservable): ?>
                        <button class="btn-add-cart" style="background:#f59e0b;border-color:#f59e0b;font-size:.78rem;"
                                onclick="event.stopPropagation(); addToCart('<?= $p['codigo'] ?>', '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['precio'] ?>, true)">
                            <span data-i18n="prod.reserve">📅 Reservar</span>
                        </button>
                    <?php else: ?>
                        <button class="btn-add-cart" style="background:#6c757d;border-color:#6c757d;font-size:.76rem;"
                                onclick="event.stopPropagation(); this.closest('.product-card').click()">
                            <span data-i18n="prod.notify">🔔 Avísame</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div id="infiniteScrollSentinel" style="grid-column:1/-1;height:1px;"></div>
    </div>
</div>

<button class="cart-float" onclick="openCart()" aria-label="Ver carrito de compras">
    <span aria-hidden="true">🛒</span>
    <span id="cartBadge" class="cart-badge d-none" aria-live="polite">0</span>
</button>

<div class="floating-container">
    <div class="float-btn btn-chat" onclick="toggleChat()" role="button" tabindex="0"
         aria-label="Abrir chat de soporte" onkeydown="if(event.key==='Enter'||event.key===' ')toggleChat()">
        <span aria-hidden="true">💬</span>
        <div class="chat-badge-notify" id="clientChatBadge" aria-live="polite"></div>
    </div>

    <a href="https://wa.me/5352783083?text=deseo%20mas%20informacion%20web" target="_blank"
       class="float-btn btn-whatsapp" aria-label="Contactar por WhatsApp" rel="noopener noreferrer">
        <span aria-hidden="true" style="font-weight:700;font-size:.95rem;">WA</span>
    </a>
</div>

<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <span><i class="fas fa-headset me-2"></i> <span data-i18n="chat.title">Soporte en Línea</span></span>
        <button type="button" class="btn-close btn-close-white" onclick="toggleChat()" aria-label="Cerrar chat" data-i18n-attr="aria-label:chat.close_aria"></button>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="text-center text-muted small mt-5">
            <i class="fas fa-comment-dots fa-3x mb-3 text-secondary opacity-50"></i><br>
            ¡Hola! 👋<br>Escribe tu consulta.<br>Si no estamos, deja un mensaje.
        </div>
    </div>
    <div class="chat-footer">
        <input type="text" id="chatInput" class="form-control" placeholder="Escribe aquí..." onkeypress="if(event.key==='Enter') sendClientMsg()" data-i18n-attr="placeholder:chat.input_ph">
        <button class="btn btn-primary" onclick="sendClientMsg()" aria-label="Enviar mensaje" data-i18n-attr="aria-label:chat.send_aria"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
    </div>
</div>

<div class="modal fade" id="modalDetail" tabindex="-1" aria-labelledby="detailName">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <!-- Botón cerrar flotante -->
            <button type="button" class="btn-close-premium" data-bs-dismiss="modal" aria-label="Cerrar detalle del producto">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>

            <div class="d-flex flex-column flex-md-row" style="min-height:480px">

                <!-- ══ COLUMNA IZQUIERDA: imagen + miniaturas ══ -->
                <div class="detail-img-panel" style="flex:0 0 42%">
                    <span id="detailStockBadge" class="badge rounded-pill"></span>

                    <div class="detail-img-main-wrap">
                        <img id="detailImg" class="d-none" alt="" draggable="false"
                             onerror="this.classList.add('d-none');document.getElementById('detailPlaceholder').classList.remove('d-none');document.getElementById('btnZoomImg').classList.remove('visible')">
                        <div id="detailPlaceholder" class="d-none text-white fw-bold"></div>
                        <button type="button" id="btnZoomImg" onclick="openImgZoom()" title="Ampliar imagen" aria-label="Ampliar imagen">
                            <i class="fas fa-search-plus" aria-hidden="true"></i>
                        </button>
                    </div>

                    <!-- Miniaturas debajo de la imagen principal -->
                    <div id="detailThumbs"></div>
                </div>

                <!-- ══ COLUMNA DERECHA: información del producto ══ -->
                <div class="detail-info-panel" style="flex:1">

                    <!-- Categoría -->
                    <p class="detail-category-label" id="detailCat">Categoría</p>

                    <!-- Nombre -->
                    <h2 id="detailName">Nombre Producto</h2>

                    <!-- Precio + estrellas -->
                    <div class="detail-price-row">
                        <span id="detailPriceOriginal" class="price-original" style="display:none"></span>
                        <p id="detailPrice" class="mb-0">$0.00</p>
                        <span class="detail-unit-label" id="detailUnit"></span>
                        <span id="detailAvgStars" class="detail-stars ms-auto"></span>
                    </div>

                    <!-- Variantes -->
                    <div id="variantSection" style="display:none; margin-bottom:.9rem">
                        <p class="detail-desc-label">Presentación</p>
                        <div id="variantButtons" class="d-flex flex-wrap gap-2"></div>
                    </div>

                    <hr class="detail-divider">

                    <!-- Meta: SKU + Color -->
                    <div class="detail-meta">
                        <span><i class="fas fa-barcode" aria-hidden="true"></i> SKU: <strong id="detailSku"></strong></span>
                        <span id="divDetailColor"><i class="fas fa-palette" aria-hidden="true"></i> Color: <strong id="detailColor"></strong></span>
                    </div>

                    <!-- Descripción -->
                    <p class="detail-desc-label">Descripción</p>
                    <p class="detail-desc" id="detailDesc">Sin descripción.</p>

                    <!-- Acción -->
                    <div class="mt-auto pt-1">
                        <button type="button" id="btnAddDetail" class="btn btn-primary w-100">
                            <i class="fas fa-cart-plus me-2" aria-hidden="true"></i><span data-i18n="modal.add_cart">AGREGAR AL CARRITO</span>
                        </button>

                        <!-- Aviso restock -->
                        <div id="restockAvisoSection" style="display:none" class="aviso-form-wrap mt-2">
                            <p class="small text-muted mb-2">
                                <i class="fas fa-bell me-1 text-warning" aria-hidden="true"></i>
                                <span data-i18n="restock.notice">Recibe un aviso cuando llegue:</span>
                            </p>
                            <div class="d-flex gap-1">
                                <input type="text" id="avisoNombre" class="form-control form-control-sm" placeholder="Tu nombre" data-i18n-attr="placeholder:restock.name_ph">
                                <input type="tel" id="avisoTelefono" class="form-control form-control-sm" placeholder="Teléfono" data-i18n-attr="placeholder:restock.tel_ph">
                                <button class="btn btn-warning btn-sm px-3" onclick="submitRestock()" aria-label="Enviar aviso">
                                    <i class="fas fa-bell" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Compartir -->
                        <div class="text-center mt-2">
                            <button class="btn btn-outline-secondary btn-sm px-4" onclick="shareCurrentProduct()">
                                <i class="fas fa-share-alt me-1" aria-hidden="true"></i>
                                <span data-i18n="modal.share">Compartir</span>
                            </button>
                        </div>
                    </div>

                    <!-- Reseñas -->
                    <hr class="detail-divider mt-3">
                    <div id="reviewsSection">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="detail-reviews-label mb-0">
                                <i class="fas fa-star text-warning me-1" aria-hidden="true"></i>
                                <span data-i18n="modal.reviews">Opiniones</span>
                            </h3>
                            <span id="reviewsAvgDisplay" class="small text-muted"></span>
                        </div>
                        <div id="reviewsList"></div>
                        <div id="reviewFormWrap" class="mt-2"></div>
                    </div>

                </div><!-- /detail-info-panel -->
            </div><!-- /flex row -->
        </div><!-- /modal-content -->
    </div>
</div>

<!-- Zoom overlay imagen producto -->
<div id="imgZoomOverlay" onclick="closeImgZoom()" role="dialog" aria-label="Imagen ampliada">
    <img id="imgZoomSrc" src="" alt="Imagen ampliada del producto">
</div>

<style>
/* ══ CART MODAL PREMIUM ═══════════════════════════════════════════════════ */
#modalCart .modal-content {
    border-radius: 24px !important;
    border: none !important;
    overflow: hidden;
    box-shadow: 0 32px 80px rgba(0,0,0,.22);
}
#modalCart .cart-header {
    background: linear-gradient(135deg, var(--nav-grad-1,#667eea) 0%, var(--nav-grad-2,#764ba2) 100%);
    padding: 1.25rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
#modalCart .cart-header-title {
    color: #fff; font-size: 1.15rem; font-weight: 800;
    display: flex; align-items: center; gap: .6rem; margin: 0;
}
#modalCart .cart-header-title i { opacity: .85; font-size: 1rem; }
#modalCart .cart-header-badge {
    background: rgba(255,255,255,.22); color: #fff;
    border-radius: 999px; font-size: .72rem; font-weight: 700;
    padding: 2px 10px; margin-left: 6px;
}
#modalCart .btn-close-cart {
    background: rgba(255,255,255,.18); border: none; border-radius: 50%;
    width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem; cursor: pointer; transition: background .15s;
    flex-shrink: 0;
}
#modalCart .btn-close-cart:hover { background: rgba(255,255,255,.35); }
/* Tabla del carrito */
#cartTableBody tr { border-bottom: 1px solid #f1f3f5; }
#cartTableBody tr:last-child { border-bottom: none; }
#cartTableBody td { padding: .75rem .5rem; vertical-align: middle; }
#cartTableBody .cart-prod-name { font-weight: 600; font-size: .92rem; color: #1f2937; }
#cartTableBody .cart-prod-price { font-size: .78rem; color: #6b7280; }
#cartTableBody .cart-qty-ctrl { display: flex; align-items: center; gap: 6px; justify-content: center; }
#cartTableBody .cart-qty-ctrl button {
    width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid #e5e7eb;
    background: #f9fafb; color: #374151; font-size: .9rem;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .15s; padding: 0;
}
#cartTableBody .cart-qty-ctrl button:hover { background: var(--primary,#0d6efd); color: #fff; border-color: var(--primary,#0d6efd); }
#cartTableBody .cart-qty-num { font-weight: 700; min-width: 22px; text-align: center; }
/* Fila de total */
#cartItemsView .cart-total-row {
    background: linear-gradient(135deg,#f0f4ff,#fdf4ff);
    border-radius: 16px; padding: 1rem 1.25rem;
    display: flex; align-items: center; justify-content: space-between;
    margin: .5rem 1.25rem 0;
}
#cartItemsView .cart-total-label { font-size: .85rem; color: #6b7280; font-weight: 600; }
#cartItemsView .cart-total-amount {
    font-size: 1.75rem; font-weight: 900; color: var(--primary,#0d6efd);
    letter-spacing: -.03em;
}
/* Botones acción carrito */
.cart-action-bar {
    padding: 1rem 1.25rem 1.25rem;
    display: flex; gap: .6rem;
}
.cart-btn-trash {
    border: 1.5px solid #fca5a5; background: #fff5f5; color: #ef4444;
    border-radius: 12px; padding: .55rem .9rem; font-size: .85rem;
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.cart-btn-trash:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
.cart-btn-reserve {
    flex: 1; border: 1.5px solid #a5b4fc; background: #eef2ff; color: #4f46e5;
    border-radius: 12px; padding: .6rem; font-size: .88rem; font-weight: 700;
    cursor: pointer; transition: all .15s; text-align: center;
}
.cart-btn-reserve:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }
.cart-btn-pay {
    flex: 1.4; background: linear-gradient(135deg, #10b981, #059669);
    color: #fff; border: none; border-radius: 12px; padding: .6rem;
    font-size: .95rem; font-weight: 800; cursor: pointer;
    box-shadow: 0 4px 14px rgba(16,185,129,.35);
    transition: all .15s; text-align: center;
}
.cart-btn-pay:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(16,185,129,.45); }
/* Checkout premium */
#checkoutView .checkout-section {
    background: #f8faff; border-radius: 16px; padding: 1.2rem 1.25rem;
    margin-bottom: 1rem; border: 1px solid #e8edff;
}
#checkoutView .checkout-section-title {
    font-size: .78rem; font-weight: 700; color: #4f46e5;
    text-transform: uppercase; letter-spacing: .07em; margin-bottom: .9rem;
    display: flex; align-items: center; gap: .4rem;
}
#checkoutView .form-control, #checkoutView .form-select {
    border-radius: 10px; border-color: #e5e7eb;
    font-size: .9rem; padding: .55rem .85rem;
}
#checkoutView .form-control:focus, #checkoutView .form-select:focus {
    border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
#checkoutView .form-label { font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
/* Resumen total checkout */
.checkout-total-box {
    background: linear-gradient(135deg, var(--nav-grad-1,#667eea), var(--nav-grad-2,#764ba2));
    border-radius: 16px; padding: 1.1rem 1.3rem; color: #fff; margin-top: .5rem;
}
.checkout-total-box .row-line { display: flex; justify-content: space-between; font-size: .85rem; opacity: .88; margin-bottom: .3rem; }
.checkout-total-box .row-total { display: flex; justify-content: space-between; font-size: 1.5rem; font-weight: 900; margin-top: .5rem; }
/* Botón confirmar */
.btn-confirmar-pedido {
    background: linear-gradient(135deg,#10b981,#059669) !important;
    border: none !important; border-radius: 14px !important;
    font-weight: 800 !important; font-size: 1rem !important;
    box-shadow: 0 4px 16px rgba(16,185,129,.4) !important;
    padding: .75rem 2rem !important;
}
.btn-confirmar-pedido:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(16,185,129,.5) !important; }
/* Vista éxito */
.cart-success-icon {
    width: 80px; height: 80px; border-radius: 50%;
    background: linear-gradient(135deg,#10b981,#059669);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.2rem; font-size: 2.2rem;
    box-shadow: 0 8px 24px rgba(16,185,129,.35);
}
.cart-order-badge {
    background: linear-gradient(135deg,#f0fdf4,#dcfce7);
    border: 1.5px solid #86efac; border-radius: 16px;
    padding: 1rem 2rem; display: inline-block; margin: .8rem 0;
}
.cart-order-badge .order-num { font-size: 2.2rem; font-weight: 900; color: #059669; letter-spacing: 2px; }
</style>

<div class="modal fade" id="modalCart" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="--bs-modal-border-radius:24px;">
        <div class="modal-content" style="border-radius:24px;border:none;">

            <!-- Header premium -->
            <div class="cart-header">
                <h5 class="cart-header-title">
                    <i class="fas fa-shopping-bag"></i>
                    <span data-i18n="cart.title">Tu Carrito</span>
                    <span class="cart-header-badge" id="cartHeaderCount">0 items</span>
                </h5>
                <button class="btn-close-cart" data-bs-dismiss="modal" aria-label="Cerrar carrito">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- ── Vista: lista de productos ── -->
            <div id="cartItemsView">
                <div class="modal-body" style="padding:1rem 1.25rem .5rem;">
                    <table class="table mb-0" style="border:none;">
                        <thead>
                            <tr style="border-bottom:2px solid #f1f3f5;">
                                <th style="font-size:.75rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;border:none;padding:.5rem .5rem .75rem;" data-i18n="cart.product_col">Producto</th>
                                <th class="text-center" style="font-size:.75rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;border:none;padding:.5rem .5rem .75rem;" data-i18n="cart.qty_col">Cant.</th>
                                <th class="text-end" style="font-size:.75rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;border:none;padding:.5rem .5rem .75rem;" data-i18n="cart.subtotal_col">Subtotal</th>
                                <th style="border:none;width:32px;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody"></tbody>
                    </table>
                </div>

                <!-- Total -->
                <div class="cart-total-row">
                    <div>
                        <div class="cart-total-label">Total a pagar</div>
                        <div style="font-size:.72rem;color:#9ca3af;" id="cartItemCount">0 productos</div>
                    </div>
                    <div class="cart-total-amount">$<span id="cartTotal">0.00</span></div>
                </div>

                <!-- Aviso reserva -->
                <div id="reservaCartNotice" class="alert alert-warning py-2 px-3 mx-3 mt-2 mb-0 small" style="display:none;border-radius:12px;" data-i18n-html="cart.reserva_notice">
                    📅 Tu carrito incluye productos <strong>de reserva</strong> (sin stock). Usa <strong>Reservar</strong> para procesarlos.
                </div>

                <!-- Botones acción -->
                <div class="cart-action-bar">
                    <button class="cart-btn-trash" onclick="clearCart()" title="Vaciar carrito">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="cart-btn-reserve" onclick="iniciarFlujo('reserva')">
                        📅 <span data-i18n="cart.reserve_btn">Reservar</span>
                        <div style="font-size:.68rem;opacity:.75;font-weight:400;" data-i18n="cart.no_stock_ok">Sin stock OK</div>
                    </button>
                    <button class="cart-btn-pay" onclick="iniciarFlujo('compra')">
                        <i class="fas fa-bolt me-1"></i> <span data-i18n="cart.pay_btn">Pagar Ahora</span>
                        <div style="font-size:.7rem;opacity:.8;font-weight:500;" data-i18n="cart.only_stock">Solo con stock</div>
                    </button>
                </div>
            </div>

            <!-- ── Vista: checkout ── -->
            <div id="checkoutView" style="display:none;">
                <form onsubmit="submitOrder(event)">
                    <div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:1.25rem;">

                        <!-- Datos personales -->
                        <div class="checkout-section">
                            <div class="checkout-section-title"><i class="fas fa-user-circle"></i> <span data-i18n="checkout.title">Datos del Cliente</span></div>
                            <div class="mb-3">
                                <label class="form-label" data-i18n="checkout.name">Nombre Completo *</label>
                                <input type="text" id="cliName" class="form-control" placeholder="Ej: Juan Pérez" required data-i18n-attr="placeholder:checkout.name_ph">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" data-i18n="checkout.phone">Teléfono *</label>
                                <input type="tel" id="cliTel" class="form-control" placeholder="Ej: +53 5555-5555" required data-i18n-attr="placeholder:checkout.phone_ph">
                            </div>
                            <div class="mb-0">
                                <label class="form-label" data-i18n="checkout.address">Dirección *</label>
                                <textarea id="cliDir" class="form-control" rows="2" placeholder="Calle, número, entre calles..." required data-i18n-attr="placeholder:checkout.address_ph"></textarea>
                            </div>
                        </div>

                        <!-- Fecha y horario -->
                        <div class="checkout-section">
                            <div class="checkout-section-title"><i class="fas fa-calendar-alt"></i> <span data-i18n="checkout.date">Fecha y Horario de Entrega</span></div>
                            <div class="mb-3">
                                <label class="form-label" data-i18n="checkout.date">Fecha *</label>
                                <input type="date" id="cliDate" class="form-control" required onchange="updateSlotsAvailability()">
                            </div>
                            <div class="row g-2" id="timeSlotGrid">
                                <div class="col-6">
                                    <div class="slot-card" data-slot="manana" data-label="Mañana (9am–12pm)" onclick="selectSlot(this)">
                                        <div class="slot-icon">🌅</div>
                                        <div class="slot-name">Mañana</div>
                                        <div class="slot-hours">9:00 AM – 12:00 PM</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="slot-card" data-slot="mediodia" data-label="Mediodía (12pm–3pm)" onclick="selectSlot(this)">
                                        <div class="slot-icon">☀️</div>
                                        <div class="slot-name">Mediodía</div>
                                        <div class="slot-hours">12:00 PM – 3:00 PM</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="slot-card" data-slot="tarde" data-label="Tarde (3pm–6pm)" onclick="selectSlot(this)">
                                        <div class="slot-icon">🌆</div>
                                        <div class="slot-name">Tarde</div>
                                        <div class="slot-hours">3:00 PM – 6:00 PM</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="slot-card" data-slot="noche" data-label="Noche (6pm–9pm)" onclick="selectSlot(this)">
                                        <div class="slot-icon">🌙</div>
                                        <div class="slot-name">Noche</div>
                                        <div class="slot-hours">6:00 PM – 9:00 PM</div>
                                    </div>
                                </div>
                            </div>
                            <div id="slotErrorMsg" class="text-danger small mt-2" style="display:none;">
                                <i class="fas fa-exclamation-circle me-1"></i><span data-i18n="checkout.slot_required">Selecciona un horario de entrega.</span>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div class="checkout-section">
                            <div class="checkout-section-title"><i class="fas fa-sticky-note"></i> <span data-i18n="checkout.notes">Notas Adicionales</span></div>
                            <textarea id="cliNotes" class="form-control" rows="3" placeholder="Ej: El cake de relleno chocolate y color rosado el merengue. Entregar antes de las 3 PM." data-i18n-attr="placeholder:checkout.notes_ph"></textarea>
                            <small class="text-muted mt-1 d-block" style="font-size:.75rem;" data-i18n="checkout.notes_hint">Especifica detalles importantes como colores, sabores, horarios, etc.</small>
                        </div>

                        <!-- Entrega a domicilio -->
                        <div class="checkout-section" style="cursor:pointer;" onclick="document.getElementById('delHome').click()">
                            <div class="d-flex align-items: center; gap:.75rem; align-items:center;">
                                <input class="form-check-input mt-0 me-2" type="checkbox" id="delHome" onclick="event.stopPropagation()" onchange="toggleDelivery()" style="width:20px;height:20px;border-radius:6px;">
                                <div>
                                    <div style="font-weight:700;font-size:.9rem;"><i class="fas fa-truck me-2 text-primary"></i><span data-i18n="checkout.home_delivery">Entrega a domicilio</span></div>
                                    <div style="font-size:.75rem;color:#6b7280;">Se calculará el costo de envío según tu barrio</div>
                                </div>
                            </div>
                        </div>

                        <div id="deliveryBox" style="display:none;">
                            <div class="checkout-section">
                                <div class="checkout-section-title"><i class="fas fa-map-marker-alt"></i> Ubicación de Entrega</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="cMun" data-i18n="checkout.municipality">Municipio</label>
                                        <select id="cMun" class="form-control" onchange="loadBarrios()">
                                            <option value="" data-i18n="checkout.municipality_ph">Seleccione municipio...</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="cBar" data-i18n="checkout.neighborhood">Barrio</label>
                                        <select id="cBar" class="form-control" onchange="calcShip()">
                                            <option value="" data-i18n="checkout.neighborhood_ph">Seleccione barrio...</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Resumen total -->
                        <div class="checkout-total-box">
                            <div class="row-line"><span data-i18n="checkout.subtotal">Subtotal Productos</span><span>$<span id="cartTotal2">0.00</span></span></div>
                            <div class="row-line"><span data-i18n="checkout.shipping">Costo de Envío</span><span>$<span id="shipCost">0.00</span></span></div>
                            <div style="border-top:1px solid rgba(255,255,255,.25);margin:.6rem 0;"></div>
                            <div class="row-total"><span data-i18n="checkout.total">TOTAL</span><span>$<span id="grandTotal">0.00</span></span></div>
                        </div>

                        <!-- Método de pago -->
                        <div id="paymentSection" style="display:none;margin-top:1rem;">
                            <div class="checkout-section">
                                <div class="checkout-section-title"><i class="fas fa-credit-card"></i> <span data-i18n="checkout.payment_method">Método de Pago</span></div>
                                <div id="shopPaymentMethodsContainer"></div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-0 gap-2 px-4 pb-4 pt-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="showCartView()">
                            <i class="fas fa-arrow-left me-1"></i> <span data-i18n="checkout.back">Volver</span>
                        </button>
                        <button type="submit" id="btnConfirmarPedido" class="btn btn-confirmar-pedido flex-fill">
                            <i class="fas fa-check-circle me-2"></i> <span data-i18n="checkout.confirm">Confirmar Pedido</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Vista: pedido recibido ── -->
            <div id="successView" style="display:none;">
                <div class="modal-body text-center py-5 px-4">
                    <div class="cart-success-icon">✅</div>
                    <h3 class="fw-bold mb-2" data-i18n="order.received">¡Pedido Recibido!</h3>
                    <p class="text-muted" data-i18n="order.thanks">Gracias por tu compra. Te contactaremos pronto para confirmar tu orden.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button class="btn btn-primary rounded-pill px-5 py-2 fw-bold" data-bs-dismiss="modal" onclick="location.reload()" data-i18n="btn.close">Cerrar</button>
                </div>
            </div>

            <!-- ── Vista: verificando pago ── -->
            <div id="waitingPaymentView" style="display:none;">
                <div class="modal-body text-center py-5 px-4">
                    <div style="font-size:4rem;margin-bottom:1rem;animation:spin 2s linear infinite;display:inline-block;">⏳</div>
                    <h4 class="fw-bold mb-3" data-i18n="payment.verifying">Verificando tu transferencia...</h4>
                    <p class="text-muted" data-i18n="payment.verifying_sub">El operador confirmará tu pago en breve.<br>Esta pantalla se actualizará automáticamente.</p>
                    <div class="mt-3 p-3 rounded-3" style="background:#f8faff;border:1px solid #e8edff;">
                        <small class="text-muted"><span data-i18n="payment.sent_code">Código enviado:</span> <strong id="codigoPagoDisplay"></strong></small>
                    </div>
                </div>
            </div>

            <!-- ── Vista: pago confirmado ── -->
            <div id="pagoConfirmadoView" style="display:none;">
                <div class="modal-body text-center py-5 px-4">
                    <div class="cart-success-icon" style="background:linear-gradient(135deg,#10b981,#059669);">🎉</div>
                    <h3 class="fw-bold mb-1 text-success" data-i18n="payment.confirmed">¡Pago Confirmado!</h3>
                    <p class="text-muted mb-3" data-i18n="payment.confirmed_sub">Tu transferencia fue verificada exitosamente.</p>
                    <div class="cart-order-badge">
                        <div style="font-size:.75rem;color:#6b7280;margin-bottom:.25rem;" data-i18n="order.number">Número de pedido</div>
                        <div class="order-num">#<span id="confirmadoPedido">—</span></div>
                        <div style="font-size:.75rem;color:#6b7280;margin-top:.25rem;" data-i18n="order.save_number">Guarda este número para consultar el estado.</div>
                    </div>
                    <p class="fw-bold text-dark mt-3 mb-0">¡<span data-i18n="payment.thanks">Gracias,</span> <span id="confirmadoNombre">Cliente</span>!</p>
                    <p class="text-muted small" data-i18n="order.in_process">Tu pedido está en proceso. Te avisaremos cuando esté listo.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button class="btn btn-success rounded-pill px-5 py-2 fw-bold" data-bs-dismiss="modal" onclick="location.reload()" data-i18n="btn.close">Cerrar</button>
                </div>
            </div>

            <!-- ── Vista: pago rechazado ── -->
            <div id="pagoRechazadoView" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div style="font-size: 5rem; margin-bottom: 1rem;">❌</div>
                    <h3 class="fw-bold mb-3 text-danger" data-i18n="payment.rejected">Transferencia Rechazada</h3>
                    <p class="text-muted fs-5" data-i18n="payment.rejected_sub">El operador no pudo verificar tu transferencia.<br>Por favor contáctanos para más información.</p>
                    <div id="motivoRechazoDiv" class="alert alert-warning mt-3" style="display:none;">
                        <strong data-i18n="payment.reason">Motivo:</strong> <span id="motivoRechazoTxt"></span>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2">
                    <button class="btn btn-outline-secondary btn-lg px-4" data-bs-dismiss="modal" onclick="location.reload()" data-i18n="btn.close">Cerrar</button>
                    <button class="btn btn-primary btn-lg px-4" onclick="showCheckout()" data-i18n="payment.try_again">Intentar de nuevo</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: SEGUIMIENTO DE PEDIDOS -->
<div class="modal fade" id="trackingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-primary text-white p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-truck me-2"></i><span data-i18n="tracking.title">Seguimiento de Pedido</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar seguimiento"></button>
            </div>
            <div class="modal-body p-4">
                <div class="input-group mb-3 shadow-sm">
                    <input type="text" id="trackInput" class="form-control border-0 bg-light p-3" placeholder="Nº Pedido o Teléfono..." data-i18n-attr="placeholder:tracking.input_ph">
                    <button class="btn btn-primary px-4" onclick="searchTrack()" aria-label="Buscar pedido">
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="trackResults" class="mt-4">
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        <span data-i18n="tracking.initial">Ingresa tus datos para ver el estado de tus compras recientes.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PERFIL DE USUARIO -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-dark text-white p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-circle me-2" aria-hidden="true"></i><span data-i18n="profile.title">Mi Perfil de Cliente</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar perfil"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="col-md-4 bg-light p-4 border-end">
                        <h6 class="fw-bold mb-3 text-uppercase small text-muted" data-i18n="profile.my_data">Mis Datos</h6>
                        <div class="mb-3">
                            <label class="small fw-bold" data-i18n="profile.name">Nombre</label>
                            <input type="text" id="profNom" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold" data-i18n="profile.phone">Teléfono</label>
                            <input type="text" id="profTel" class="form-control form-control-sm" disabled>
                        </div>
                        <div class="mb-4">
                            <label class="small fw-bold" data-i18n="profile.address">Dirección</label>
                            <textarea id="profDir" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <button class="btn btn-primary btn-sm w-100 fw-bold" onclick="updateProfile()" data-i18n="profile.update">
                            Actualizar Datos
                        </button>
                        <hr class="my-4">
                        <div id="passwordSection">
                            <h6 class="fw-bold mb-3 text-uppercase small text-muted" data-i18n="profile.password_section">Cambiar Contraseña</h6>
                            <div class="mb-3">
                                <label class="small fw-bold" for="profPassCurrent" data-i18n="profile.current_password">Contraseña actual</label>
                                <input type="password" id="profPassCurrent" class="form-control form-control-sm" autocomplete="current-password">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold" for="profPassNew" data-i18n="profile.new_password">Nueva contraseña</label>
                                <input type="password" id="profPassNew" class="form-control form-control-sm" autocomplete="new-password" data-i18n-attr="placeholder:auth.new_password_ph" placeholder="Mín. 8 caracteres, 1 mayúscula y 1 número">
                            </div>
                            <div class="mb-0">
                                <label class="small fw-bold" for="profPassConfirm" data-i18n="profile.confirm_password">Confirmar contraseña</label>
                                <input type="password" id="profPassConfirm" class="form-control form-control-sm" autocomplete="new-password" data-i18n-attr="placeholder:profile.confirm_password_ph" placeholder="Repite la nueva contraseña">
                            </div>
                            <div class="form-text mt-2" data-i18n="profile.password_help">Usa al menos 8 caracteres, una mayúscula y un número.</div>
                            <button class="btn btn-outline-dark btn-sm w-100 fw-bold mt-3" onclick="changePassword()" data-i18n="profile.change_password">
                                Guardar Nueva Contraseña
                            </button>
                        </div>
                    </div>
                    <div class="col-md-8 p-4">
                        <h6 class="fw-bold mb-3 text-uppercase small text-muted" data-i18n="profile.history">Historial de Pedidos</h6>
                        <div id="orderHistoryContent" style="max-height: 400px; overflow-y: auto;">
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br><span data-i18n="profile.loading_orders">Cargando pedidos...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: MI CUENTA (LOGIN/REGISTRO) -->
<div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-body p-0">
                <ul class="nav nav-pills nav-justified bg-light p-2 rounded-top-4" id="authTabs">
                    <li class="nav-item">
                        <a class="nav-link active fw-bold" data-bs-toggle="pill" href="#loginTab" data-i18n="auth.login">Iniciar Sesión</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-bold" data-bs-toggle="pill" href="#registerTab" onclick="generateCaptcha()" data-i18n="auth.register">Crear Cuenta</a>
                    </li>
                </ul>
                <div class="tab-content p-4">
                    <!-- LOGIN -->
                    <div class="tab-pane fade show active" id="loginTab">
                        <form onsubmit="loginClient(); return false;">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted" for="logTel" data-i18n="auth.phone">Teléfono</label>
                                <input type="text" id="logTel" class="form-control bg-light border-0 p-3" placeholder="Tu número..." required autocomplete="username" data-i18n-attr="placeholder:auth.phone_ph">
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted" for="logPass" data-i18n="auth.password">Contraseña</label>
                                <input type="password" id="logPass" class="form-control bg-light border-0 p-3" placeholder="••••••••" required autocomplete="current-password" data-i18n-attr="placeholder:auth.password_ph">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3 shadow">
                                <span data-i18n="auth.enter">Entrar</span> <i class="fas fa-sign-in-alt ms-2"></i>
                            </button>
                        </form>
                    </div>
                    <!-- REGISTRO -->
                    <div class="tab-pane fade" id="registerTab">
                        <form onsubmit="registerClient(); return false;">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="small fw-bold text-muted" data-i18n="auth.full_name">Nombre Completo</label>
                                    <input type="text" id="regNom" class="form-control bg-light border-0 p-2" placeholder="Ej. Juan Pérez" required autocomplete="name" data-i18n-attr="placeholder:auth.full_name_ph">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted" data-i18n="auth.phone">Teléfono</label>
                                    <input type="text" id="regTel" class="form-control bg-light border-0 p-2" placeholder="Ej. 5352... " required autocomplete="tel">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted" data-i18n="auth.new_password">Contraseña</label>
                                    <input type="password" id="regPass" class="form-control bg-light border-0 p-2" placeholder="Mín. 6 caracteres" required autocomplete="new-password" data-i18n-attr="placeholder:auth.new_password_ph">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted" data-i18n="auth.address_opt">Dirección (Opcional)</label>
                                    <input type="text" id="regDir" class="form-control bg-light border-0 p-2" placeholder="Calle, Nº, e/..." data-i18n-attr="placeholder:auth.address_ph">
                                </div>
                                <div class="col-12">
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-3 border border-warning border-opacity-20">
                                        <label class="small fw-bold text-warning-emphasis mb-2 d-block" data-i18n="auth.captcha">Verificación Humana</label>
                                        <div class="d-flex align-items-center gap-2">
                                            <span id="captchaLabel" class="fw-bold fs-5 text-dark"><?php echo htmlspecialchars($_SESSION['captcha_q'] ?? '? + ? ='); ?></span>
                                            <input type="number" id="regCaptcha" class="form-control border-warning p-2" style="width: 80px" placeholder="?" required>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100 py-3 mt-4 fw-bold rounded-3 shadow">
                                <span data-i18n="auth.register_btn">Registrarme</span> <i class="fas fa-user-plus ms-2"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── i18n Engine ──────────────────────────────────────────────────── -->
<script>
// ── i18n ──────────────────────────────────────────────────────────────
const LANG_ES = {
  // Navbar
  'nav.tracking':     'Rastreo',
  'nav.help':         'Ayuda',
  'nav.about':        'Nosotros',
  'nav.account':      'Mi Cuenta',
  'nav.profile':      'Mi Perfil',
  'nav.change_password':'Cambiar contraseña',
  'nav.orders':       'Mis Pedidos',
  'nav.logout':       'Salir',
  'nav.search_ph':    'Buscar productos...',
  'nav.cart_aria':    'Ver carrito de compras',
  // Filtros
  'filter.categories':'Categorías',
  'filter.all':       'Todas',
  'sort.category':    'Ordenar: Categoría',
  'sort.price_asc':   'Precio: Menor a Mayor',
  'sort.price_desc':  'Precio: Mayor a Menor',
  'sort.popular':     '🔥 Más populares',
  // Productos
  'prod.available':       '✓ Disponible',
  'prod.reservable':      '📅 Reservable',
  'prod.out_of_stock':    '✗ Agotado',
  'prod.add':             '+ Agregar',
  'prod.reserve':         '📅 Reservar',
  'prod.notify':          '🔔 Avísame',
  'prod.count':           '{n} productos',
  'prod.none':            'No hay productos disponibles',
  'prod.available_title': 'Productos Disponibles',
  'prod.only_left':       '¡Solo {n} disponibles!',
  // Stock badges
  'stock.available':  '✓ Disponible',
  'stock.reservable': '📅 Disponible bajo reserva',
  'stock.out_of_stock':'✗ Agotado',
  // Modal detalle
  'modal.category':       'Categoría',
  'modal.product_name':   'Nombre Producto',
  'modal.description':    'Descripción',
  'modal.no_desc':        'Sin descripción detallada.',
  'modal.add_to_cart':    'AGREGAR AL CARRITO',
  'modal.reserve_product':'📅 RESERVAR PRODUCTO',
  'modal.out_of_stock':   '✗ PRODUCTO AGOTADO',
  'modal.presentation':   'Presentación',
  'modal.reviews':        'Opiniones',
  'modal.share':          'Compartir',
  'modal.close_detail':   'Cerrar detalle del producto',
  'modal.sku':            'SKU',
  'modal.color':          'Color',
  // Restock
  'restock.notice':   'Recibe un aviso cuando llegue:',
  'restock.name_ph':  'Tu nombre',
  'restock.tel_ph':   'Teléfono',
  'restock.toast_ok': '🔔 ¡Te avisamos cuando llegue!',
  'restock.tel_required': 'Ingresa tu teléfono',
  'restock.error':    'Error al registrar aviso',
  // Reseñas
  'reviews.login_prompt': 'Inicia sesión para dejar una reseña.',
  'reviews.login_link':   'Inicia sesión',
  'reviews.empty':        'Sin reseñas aún. ¡Sé el primero!',
  'reviews.loading':      'Cargando reseñas...',
  'reviews.load_error':   'No se pudieron cargar las reseñas.',
  'reviews.your_rating':  'Tu valoración',
  'reviews.comment_ph':   'Cuéntanos tu experiencia (opcional)',
  'reviews.submit':       'Publicar',
  // Carrito
  'cart.title':           'Tu Carrito',
  'cart.product_col':     'Producto',
  'cart.qty_col':         'Cantidad',
  'cart.subtotal_col':    'Subtotal',
  'cart.total':           'Total:',
  'cart.empty_btn':       'Vaciar',
  'cart.reserve_btn':     '📅 Reservar',
  'cart.pay_btn':         '💳 Pagar Ahora',
  'cart.no_stock_ok':     'Sin stock OK',
  'cart.only_stock':      'Solo con stock',
  'cart.empty_state':     'Carrito vacío',
  'cart.confirm_clear':   '¿Vaciar el carrito?',
  'cart.reserva_notice':  '📅 Tu carrito incluye productos <strong>de reserva</strong> (sin stock). Usa <strong>Reservar</strong> para procesarlos.',
  'cart.close_aria':      'Cerrar carrito',
  // Checkout
  'checkout.title':       'Datos del Cliente',
  'checkout.name':        'Nombre Completo *',
  'checkout.name_ph':     'Ej: Juan Pérez',
  'checkout.phone':       'Teléfono *',
  'checkout.phone_ph':    'Ej: +53 5555-5555',
  'checkout.address':     'Dirección *',
  'checkout.address_ph':  'Calle, número, entre calles...',
  'checkout.date':        'Fecha de Entrega/Recogida *',
  'checkout.time_slot':   'Horario de Entrega *',
  'checkout.notes':       'Notas Adicionales',
  'checkout.notes_ph':    'Ej: El cake de relleno chocolate y color rosado el merengue. Entregar antes de las 3 PM.',
  'checkout.notes_hint':  'Especifica detalles importantes como colores, sabores, horarios, etc.',
  'checkout.home_delivery':'Entrega a domicilio',
  'checkout.municipality': 'Municipio',
  'checkout.municipality_ph': 'Seleccione municipio...',
  'checkout.neighborhood': 'Barrio',
  'checkout.neighborhood_ph': 'Seleccione barrio...',
  'checkout.summary':     'Resumen del Pedido',
  'checkout.subtotal':    'Subtotal Productos:',
  'checkout.shipping':    'Costo de Envío:',
  'checkout.total':       'TOTAL A PAGAR:',
  'checkout.payment_method': 'Método de Pago',
  'checkout.back':        'Volver',
  'checkout.confirm':     'Confirmar Pedido',
  'checkout.sending':     'Enviando...',
  'checkout.slot_required':  'Selecciona un horario de entrega.',
  'checkout.mun_bar_required':'Por favor selecciona municipio y barrio',
  'checkout.transfer_code_required': 'Por favor ingresa el código de confirmación de la transferencia.',
  'checkout.card_label':  'Tarjeta:',
  'checkout.holder_label':'Titular:',
  'checkout.bank_label':  'Banco:',
  'checkout.code_label':  'Código de confirmación *',
  'checkout.code_ph':     'Ej: TRF-20260220-001234',
  'checkout.code_hint':   'Código de confirmación de la transferencia.',
  // Slots
  'slot.morning':    'Mañana',
  'slot.noon':       'Mediodía',
  'slot.afternoon':  'Tarde',
  'slot.evening':    'Noche',
  // Orden
  'order.received':   '¡Pedido Recibido!',
  'order.thanks':     'Gracias por tu compra. Te contactaremos pronto para confirmar tu orden.',
  'order.close':      'Cerrar',
  'order.number':     'Número de pedido',
  'order.save_number':'Guarda este número para consultar el estado de tu pedido.',
  'order.in_process': 'Tu pedido está en proceso. Te avisaremos cuando esté listo.',
  // Pago
  'payment.verifying':      'Verificando tu transferencia...',
  'payment.verifying_sub':  'El operador confirmará tu pago en breve.\nEsta pantalla se actualizará automáticamente.',
  'payment.sent_code':      'Código enviado:',
  'payment.confirmed':      '¡Pago Confirmado!',
  'payment.confirmed_sub':  'Tu transferencia fue verificada exitosamente.',
  'payment.thanks':         'Gracias,',
  'payment.rejected':       'Transferencia Rechazada',
  'payment.rejected_sub':   'El operador no pudo verificar tu transferencia.\nPor favor contáctanos para más información.',
  'payment.reason':         'Motivo:',
  'payment.try_again':      'Intentar de nuevo',
  // Tracking
  'tracking.title':     'Seguimiento de Pedido',
  'tracking.input_ph':  'Nº Pedido o Teléfono...',
  'tracking.initial':   'Ingresa tus datos para ver el estado de tus compras recientes.',
  'tracking.not_found': 'No se encontraron pedidos con ese dato.',
  'tracking.error':     'Error al consultar.',
  'tracking.order':     'Pedido #{id}',
  'tracking.client':    'Cliente:',
  'tracking.total':     'Total:',
  'tracking.products':  'Productos:',
  'status.unknown':         'Estado desconocido.',
  'status.pendiente':       'Tu pedido ha sido recibido y está esperando confirmación. ¡Pronto empezaremos a prepararlo!',
  'status.en_preparacion':  '¡Estamos preparando tu pedido con mucho cariño! En breve estará listo para salir.',
  'status.en_camino':       '¡Buenas noticias! Tu pedido ha salido del local y va en camino. El mensajero está de ruta.',
  'status.entregado':       '¡Tu pedido ha sido entregado con éxito! Esperamos que lo disfrutes.',
  'status.cancelado':       'Lamentamos informarte que tu pedido ha sido cancelado. Por favor, contáctanos si tienes alguna duda.',
  // Perfil
  'profile.title':          'Mi Perfil de Cliente',
  'profile.my_data':        'Mis Datos',
  'profile.name':           'Nombre',
  'profile.phone':          'Teléfono',
  'profile.address':        'Dirección',
  'profile.update':         'Actualizar Datos',
  'profile.password_section':'Cambiar Contraseña',
  'profile.current_password':'Contraseña actual',
  'profile.new_password':   'Nueva contraseña',
  'profile.confirm_password':'Confirmar contraseña',
  'profile.confirm_password_ph':'Repite la nueva contraseña',
  'profile.password_help':  'Usa al menos 8 caracteres, una mayúscula y un número.',
  'profile.change_password':'Guardar Nueva Contraseña',
  'profile.history':        'Historial de Pedidos',
  'profile.loading_orders': 'Cargando pedidos...',
  'profile.no_orders':      'Aún no tienes pedidos registrados.',
  'profile.view_ticket':    'Ver ticket',
  'profile.products':       '{n} producto(s)',
  // Auth
  'auth.login':             'Iniciar Sesión',
  'auth.register':          'Crear Cuenta',
  'auth.phone':             'Teléfono',
  'auth.phone_ph':          'Tu número...',
  'auth.password':          'Contraseña',
  'auth.password_ph':       '••••••••',
  'auth.enter':             'Entrar',
  'auth.full_name':         'Nombre Completo',
  'auth.full_name_ph':      'Ej. Juan Pérez',
  'auth.new_password':      'Contraseña',
  'auth.new_password_ph':   'Mín. 8 caracteres, 1 mayúscula y 1 número',
  'auth.address_opt':       'Dirección (Opcional)',
  'auth.address_ph':        'Calle, Nº, e/...',
  'auth.captcha':           'Verificación Humana',
  'auth.register_btn':      'Registrarme',
  'auth.register_success':  'Cuenta creada con éxito. Ya puedes iniciar sesión.',
  'auth.fill_fields':       'Completa los campos.',
  'auth.conn_error':        'Error de conexión.',
  // Chat
  'chat.title':       'Soporte en Línea',
  'chat.input_ph':    'Escribe aquí...',
  'chat.greeting':    '¡Hola! 👋\nEscribe tu consulta.\nSi no estamos, deja un mensaje.',
  'chat.close_aria':  'Cerrar chat',
  'chat.send_aria':   'Enviar mensaje',
  // Footer
  'footer.follow':    'Síguenos',
  // Toasts
  'toast.added':           '✓ Agregado al carrito',
  'toast.reserved':        '📅 Reserva agregada',
  'toast.review_ok':       '¡Reseña publicada! Gracias por tu opinión.',
  'toast.cart_empty':      'El carrito está vacío',
  'toast.rating_required': 'Selecciona de 1 a 5 estrellas',
  'toast.conn_error':      'Error de conexión',
  'toast.link_copied':     '🔗 Enlace copiado al portapapeles',
  'toast.no_stock_warning':'Sin stock: {list}',
  'toast.profile_updated': 'Perfil actualizado correctamente.',
  'toast.password_updated':'Contraseña actualizada correctamente.',
  'toast.password_policy': 'La contraseña debe tener al menos 8 caracteres, una mayúscula y un número.',
  'toast.wishlist_error':  'Error al guardar favorito',
  // Carrito abandonado
  'abandoned.msg':        '🛒 Tienes {n} artículo(s) guardados por ${total} CUP',
  'abandoned.view_cart':  'Ver carrito',
  'abandoned.dismiss':    'Descartar',
  // Búsqueda
  'search.not_found': 'No se encontraron productos',
  'search.searching': '🔍 Buscando...',
  'search.error':     'Error al buscar',
  // Misc
  'btn.close':   'Cerrar',
  'btn.back':    'Volver',
  'btn.loading': 'Cargando...',
  'wishlist.add_aria':    'Agregar a favoritos',
  'wishlist.remove_aria': 'Quitar de favoritos',
};

const LANG_EN = {}; // filled lazily from lang/en.json

let currentLang = localStorage.getItem('palweb_lang') || 'es';

function t(key, vars = {}) {
    const dict = currentLang === 'en' ? LANG_EN : LANG_ES;
    let str = dict[key] ?? LANG_ES[key] ?? key;
    Object.entries(vars).forEach(([k, v]) => { str = str.replaceAll('{' + k + '}', v); });
    return str;
}

async function setLang(lang) {
    if (lang === 'en' && Object.keys(LANG_EN).length === 0) {
        try {
            const r = await fetch('lang/en.json');
            if (r.ok) Object.assign(LANG_EN, await r.json());
        } catch(e) { console.warn('No se pudo cargar lang/en.json'); }
    }
    currentLang = lang;
    localStorage.setItem('palweb_lang', lang);
    applyLang();
    updateLangSelector();
}

function applyLang() {
    // Textos en nodos de texto
    document.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = t(el.dataset.i18n);
    });
    // Atributos (placeholder, title, aria-label, etc.)
    document.querySelectorAll('[data-i18n-attr]').forEach(el => {
        const [attr, key] = el.dataset.i18nAttr.split(':');
        el.setAttribute(attr, t(key));
    });
}

function updateLangSelector() {
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.lang === currentLang);
    });
}

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', () => {
    updateLangSelector();
    if (currentLang === 'en') setLang('en');
});
</script>
<!-- ── /i18n Engine ─────────────────────────────────────────────────── -->

<!-- Toast de notificación -->
<div id="shopToastContainer" aria-live="polite" aria-atomic="true"
     style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;min-width:240px;"></div>

<script>
    // ── Captura global de errores JS → servidor ──────────────────────────────
    (function() {
        let _errCount = 0;
        function _sendErr(msg, src, line) {
            if (_errCount++ > 5) return; // máximo 5 errores por sesión de página
            try {
                navigator.sendBeacon('shop.php?action_js_error=1', JSON.stringify({ msg, src, line }));
            } catch(e) {}
        }
        window.onerror = function(msg, src, line) { _sendErr(String(msg).slice(0,400), src, line); return false; };
        window.addEventListener('unhandledrejection', function(e) {
            _sendErr(String(e.reason?.message || e.reason || 'Promise rejected').slice(0,400), 'promise', 0);
        });
    })();
    // ── /error logger ────────────────────────────────────────────────────────

    // Catálogo de productos — cargado bajo demanda con ETag (sin inline JSON)
    const PRODUCTS_CACHE_KEY  = 'palweb_products_v2_<?= $SUC_ID ?>';
    const PRODUCTS_ETAG_KEY   = 'palweb_products_etag_<?= $SUC_ID ?>';
    const PRODUCTS_CACHE_TS   = 'palweb_products_ts_<?= $SUC_ID ?>';
    const CATALOG_TTL_MS      = 10 * 60 * 1000; // 10 min sin revalidar

    let _catalogReady  = false;
    let _catalogData   = null; // se rellena al primer uso

    function getProductsFromCache() {
        if (_catalogData) return _catalogData;
        try {
            const raw = localStorage.getItem(PRODUCTS_CACHE_KEY);
            if (!raw) return null;
            _catalogData = JSON.parse(raw);
            return _catalogData;
        } catch { return null; }
    }

    async function ensureCatalog(force = false) {
        if (_catalogReady && !force) return _catalogData;

        const storedEtag = localStorage.getItem(PRODUCTS_ETAG_KEY) || '';
        const storedTs   = parseInt(localStorage.getItem(PRODUCTS_CACHE_TS) || '0');
        const age        = Date.now() - storedTs;

        // Si el catálogo es reciente, usar sin revalidar
        if (!force && age < CATALOG_TTL_MS && getProductsFromCache()) {
            _catalogReady = true;
            return _catalogData;
        }

        try {
            const headers = {};
            if (storedEtag) headers['If-None-Match'] = storedEtag;

            const res = await fetch('shop.php?action=products_json', { headers, credentials: 'same-origin' });

            if (res.status === 304) {
                // Sin cambios — refrescar timestamp
                localStorage.setItem(PRODUCTS_CACHE_TS, Date.now().toString());
                _catalogReady = true;
                return _catalogData || getProductsFromCache();
            }

            if (res.ok) {
                const json = await res.json();
                _catalogData = json.products || [];
                const newEtag = res.headers.get('ETag') || '';
                try {
                    localStorage.setItem(PRODUCTS_CACHE_KEY, JSON.stringify(_catalogData));
                    localStorage.setItem(PRODUCTS_CACHE_TS, Date.now().toString());
                    if (newEtag) localStorage.setItem(PRODUCTS_ETAG_KEY, newEtag);
                } catch { /* localStorage lleno */ }
                _catalogReady = true;
                return _catalogData;
            }
        } catch { /* offline — usar lo que haya */ }

        _catalogReady = true;
        return getProductsFromCache();
    }

    function warmCatalogWhenIdle() {
        const run = () => ensureCatalog().catch(() => {});
        if ('requestIdleCallback' in window) {
            requestIdleCallback(run, { timeout: 2500 });
        } else {
            setTimeout(run, 1800);
        }
    }

    // Variable para el producto actualmente mostrado en modal (Feature 15)
    let _currentDetailProduct = null;

    // Infinite scroll: muestra los primeros PAGE_SIZE productos y revela más al llegar al final
    function _initInfiniteScroll(grid) {
        const PAGE_SIZE = 24;
        const cards = Array.from(grid.querySelectorAll('.product-card'));
        if (cards.length <= PAGE_SIZE) return;

        let shown = PAGE_SIZE;
        cards.slice(shown).forEach(c => { c.style.display = 'none'; c.dataset.lazy = '1'; });

        const sentinel = document.getElementById('infiniteScrollSentinel');
        if (!sentinel || !('IntersectionObserver' in window)) {
            cards.forEach(c => { c.style.display = ''; delete c.dataset.lazy; });
            return;
        }

        const obs = new IntersectionObserver((entries) => {
            if (!entries[0].isIntersecting) return;
            const batch = cards.slice(shown, shown + PAGE_SIZE);
            batch.forEach(c => { c.style.display = ''; delete c.dataset.lazy; });
            shown += batch.length;
            if (shown >= cards.length) obs.disconnect();
        }, { rootMargin: '200px' });

        obs.observe(sentinel);

        window._infiniteScrollReset = () => {
            obs.disconnect();
            cards.forEach(c => { c.style.display = ''; delete c.dataset.lazy; });
        };
    }

    // INICIALIZACIÓN
    window.addEventListener('load', () => {
        const skeleton = document.getElementById('skeletonLoader');
        const grid     = document.getElementById('productsGrid');

        if (skeleton) skeleton.style.display = 'none';
        if (grid) {
            grid.style.display = 'grid';
            _initInfiniteScroll(grid);
        }

        document.querySelectorAll('.lazy-img').forEach(img => {
            if (img.complete) img.classList.add('loaded');
        });
    });

    // Bootstrap y FontAwesome se cargan bajo demanda para no penalizar el arranque
    let modalDetail, modalCart, trackingModal, authModal, profileModal;
    let bootstrapRuntimePromise = null;
    let bootstrapStylesPromise = null;
    let fontAwesomePromise = null;

    function loadScriptOnce(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[src="${src}"]`);
            if (existing) {
                if (existing.dataset.loaded === '1') return resolve();
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.defer = true;
            s.addEventListener('load', () => {
                s.dataset.loaded = '1';
                resolve();
            }, { once: true });
            s.addEventListener('error', reject, { once: true });
            document.head.appendChild(s);
        });
    }

    function loadStylesheetOnce(href) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector(`link[href="${href}"]`);
            if (existing) {
                if (existing.dataset.loaded === '1' || existing.sheet) return resolve();
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', reject, { once: true });
                return;
            }
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.addEventListener('load', () => {
                link.dataset.loaded = '1';
                resolve();
            }, { once: true });
            link.addEventListener('error', reject, { once: true });
            document.head.appendChild(link);
        });
    }

    function ensureBootstrapStyles() {
        if (document.querySelector('link[href="assets/css/bootstrap.min.css"]')) return Promise.resolve();
        if (!bootstrapStylesPromise) {
            bootstrapStylesPromise = loadStylesheetOnce('assets/css/bootstrap.min.css');
        }
        return bootstrapStylesPromise;
    }

    function ensureBootstrapRuntime() {
        if (window.bootstrap?.Modal && window.bootstrap?.Tab) return Promise.resolve(window.bootstrap);
        if (!bootstrapRuntimePromise) {
            bootstrapRuntimePromise = Promise.all([
                ensureBootstrapStyles(),
                loadScriptOnce('assets/js/bootstrap.bundle.min.js')
            ])
                .then(() => window.bootstrap);
        }
        return bootstrapRuntimePromise;
    }

    function ensureFontAwesome() {
        if (document.querySelector('link[href="assets/css/all.min.css"]')) return Promise.resolve();
        if (!fontAwesomePromise) {
            fontAwesomePromise = loadStylesheetOnce('assets/css/all.min.css');
        }
        return fontAwesomePromise;
    }

    function getModalInstance(currentInstance, elementId) {
        if (window.bootstrap?.Modal == null) return null;
        if (currentInstance) return currentInstance;
        const el = document.getElementById(elementId);
        return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
    }

    window.addEventListener('load', warmCatalogWhenIdle, { once: true });

    // Carrusel: cargar Bootstrap en cuanto la página esté lista y arrancar auto-rotación
    window.addEventListener('load', () => {
        ensureBootstrapRuntime().then(() => {
            const el = document.getElementById('promoCarousel');
            if (el && window.bootstrap?.Carousel) {
                new bootstrap.Carousel(el, { interval: 4000, ride: 'carousel', wrap: true });
            }
        }).catch(() => {});
    }, { once: true });

    ['pointerdown', 'keydown'].forEach((evtName) => {
        window.addEventListener(evtName, () => { ensureFontAwesome().catch(() => {}); }, {
            once: true,
            passive: true
        });
    });

    // ========================================================
    // TOAST DE NOTIFICACIÓN
    // ========================================================
    function showToast(msg, duration = 3000) {
        const container = document.getElementById('shopToastContainer');
        const id = 'toast_' + Date.now();
        const el = document.createElement('div');
        el.id = id;
        el.className = 'toast align-items-center text-white bg-dark border-0 show mb-2';
        el.setAttribute('role', 'status');
        el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"
                onclick="document.getElementById('${id}').remove()" aria-label="Cerrar"></button></div>`;
        container.appendChild(el);
        setTimeout(() => { if (el.parentNode) el.remove(); }, duration);
    }
    window.showToast = showToast;

    // ========================================================
    // HELPER HTML ESCAPE
    // ========================================================
    function escHtml(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ========================================================
    // VARIANTES DE PRODUCTO
    // ========================================================
    let currentVariant = null;

    async function loadVariants(codigo, basePrice, hasStock, esReservable) {
        currentVariant = null;
        const section  = document.getElementById('variantSection');
        const container = document.getElementById('variantButtons');
        section.style.display = 'none';
        container.innerHTML = '';

        try {
            const res = await fetch(`shop.php?action_variants=1&codigo=${encodeURIComponent(codigo)}`);
            const variants = await res.json();
            if (!variants || variants.length === 0) return;

            section.style.display = 'block';
            const btn = document.getElementById('btnAddDetail');
            const priceEl = document.getElementById('detailPrice');

            variants.forEach((v, i) => {
                const extra = parseFloat(v.precio_extra);
                const label = extra > 0 ? `${v.nombre} (+$${extra.toFixed(2)})` :
                              extra < 0 ? `${v.nombre} (-$${Math.abs(extra).toFixed(2)})` : v.nombre;
                const b = document.createElement('button');
                b.className = 'btn btn-outline-secondary btn-sm variant-btn rounded-pill';
                b.textContent = label;
                b.onclick = () => {
                    document.querySelectorAll('.variant-btn').forEach(x => x.classList.remove('active'));
                    b.classList.add('active');
                    currentVariant = { nombre: v.nombre, precio_extra: extra };
                    priceEl.innerText = '$' + (basePrice + extra).toFixed(2);
                    if (hasStock) {
                        btn.onclick = () => {
                            modalDetail = getModalInstance(modalDetail, 'modalDetail');
                            addToCart(codigo, document.getElementById('detailName').innerText, basePrice, false, currentVariant);
                            modalDetail?.hide();
                        };
                    } else if (esReservable) {
                        btn.onclick = () => {
                            modalDetail = getModalInstance(modalDetail, 'modalDetail');
                            addToCart(codigo, document.getElementById('detailName').innerText, basePrice, true, currentVariant);
                            modalDetail?.hide();
                        };
                    }
                };
                if (i === 0) setTimeout(() => b.click(), 0); // auto-seleccionar primera
                container.appendChild(b);
            });
        } catch(e) { console.error('Error cargando variantes:', e); }
    }

    // ========================================================
    // RESEÑAS
    // ========================================================
    const IS_LOGGED_IN = <?= isset($_SESSION['client_id']) ? 'true' : 'false' ?>;

    async function loadReviews(codigo) {
        const list    = document.getElementById('reviewsList');
        const avgDisp = document.getElementById('reviewsAvgDisplay');
        const formWrap = document.getElementById('reviewFormWrap');
        list.innerHTML = `<div class="text-center text-muted py-2 small">${t('reviews.loading')}</div>`;
        avgDisp.innerHTML = '';
        formWrap.innerHTML = '';

        try {
            const res  = await fetch(`shop.php?action_reviews=1&codigo=${encodeURIComponent(codigo)}`);
            const data = await res.json();

            // Stars en el header del modal
            const avgStarsEl = document.getElementById('detailAvgStars');
            if (data.total > 0) {
                avgDisp.innerHTML = `<span class="text-warning">★</span> ${data.avg} <span class="text-muted">(${data.total})</span>`;
                if (avgStarsEl) avgStarsEl.innerHTML = `<span class="text-warning small">★ ${data.avg}</span>`;
            } else {
                if (avgStarsEl) avgStarsEl.innerHTML = '';
            }

            if (data.resenas && data.resenas.length > 0) {
                list.innerHTML = data.resenas.map(r => `
                    <div class="py-2 border-bottom">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong class="small">${escHtml(r.cliente_nombre)}</strong>
                            <span class="text-warning" style="font-size:.85rem">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</span>
                            <span class="text-muted" style="font-size:.72rem">${escHtml(r.fecha)}</span>
                        </div>
                        ${r.comentario ? `<p class="mb-0 text-secondary" style="font-size:.85rem">${escHtml(r.comentario)}</p>` : ''}
                    </div>
                `).join('');
            } else {
                list.innerHTML = `<div class="text-center text-muted py-2 small">${t('reviews.empty')}</div>`;
            }

            if (IS_LOGGED_IN) {
                formWrap.innerHTML = `
                    <div class="border rounded-3 p-3 bg-light mt-2">
                        <p class="fw-bold mb-2 small">${t('reviews.your_rating')}</p>
                        <div id="starInput" data-codigo="${escHtml(codigo)}" data-rating="0" class="mb-2">
                            ${[1,2,3,4,5].map(n =>
                                `<span class="star-pick" data-val="${n}" style="font-size:1.6rem;cursor:pointer;color:#d1d5db;">★</span>`
                            ).join('')}
                        </div>
                        <textarea id="reviewComment" class="form-control form-control-sm mb-2" rows="2"
                            placeholder="${t('reviews.comment_ph')}" maxlength="400"></textarea>
                        <button class="btn btn-warning btn-sm rounded-pill px-3 fw-bold"
                            onclick="submitReview('${escHtml(codigo)}')">
                            <i class="fas fa-paper-plane me-1"></i> ${t('reviews.submit')}
                        </button>
                    </div>`;

                // Interacción con las estrellas
                let selectedRating = 0;
                const picks = formWrap.querySelectorAll('.star-pick');
                picks.forEach(s => {
                    s.addEventListener('mouseover', () =>
                        picks.forEach(x => x.style.color = parseInt(x.dataset.val) <= parseInt(s.dataset.val) ? '#f59e0b' : '#d1d5db'));
                    s.addEventListener('click', () => {
                        selectedRating = parseInt(s.dataset.val);
                        document.getElementById('starInput').dataset.rating = selectedRating;
                        picks.forEach(x => x.style.color = parseInt(x.dataset.val) <= selectedRating ? '#f59e0b' : '#d1d5db');
                    });
                });
                formWrap.querySelector('#starInput').addEventListener('mouseleave', () =>
                    picks.forEach(x => x.style.color = parseInt(x.dataset.val) <= selectedRating ? '#f59e0b' : '#d1d5db'));
            } else {
                formWrap.innerHTML = `<p class="text-center text-muted small mt-2">
                    <a href="#" onclick="toggleAuthModal()">${t('reviews.login_link')}</a> ${t('reviews.login_prompt')}</p>`;
            }
        } catch(e) {
            list.innerHTML = `<div class="text-center text-muted py-2 small">${t('reviews.load_error')}</div>`;
        }
    }

    async function submitReview(codigo) {
        const starInput = document.getElementById('starInput');
        const rating    = parseInt(starInput?.dataset.rating ?? 0);
        const comment   = document.getElementById('reviewComment')?.value.trim() ?? '';
        if (rating < 1 || rating > 5) { showToast(t('toast.rating_required')); return; }

        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action_client: 1, action: 'submit_review', codigo, rating, comentario: comment })
            });
            const data = await res.json();
            if (data.status === 'success') {
                showToast(t('toast.review_ok'));
                loadReviews(codigo); // Recargar
            } else {
                showToast(data.msg || 'Error al publicar reseña');
            }
        } catch(e) { showToast(t('toast.conn_error')); }
    }

    // ========================================================
    // GESTIÓN DE WISHLIST
    // ========================================================
    async function toggleWishlist(code, btn) {
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_wishlist', action_client: 1, code: code })
            });
            const data = await res.json();
            if (data.status === 'success') {
                btn.classList.toggle('active', data.added);
                const icon = btn.querySelector('i');
                icon.className = data.added ? 'fas fa-heart' : 'far fa-heart';
            }
        } catch (e) { alert("Error al guardar favorito"); }
    }

    // ========================================================
    // PERFIL Y HISTORIAL
    // ========================================================
    async function openProfileModal(section = '') {
        await ensureBootstrapRuntime().catch(() => {});
        profileModal = getModalInstance(profileModal, 'profileModal');
        profileModal?.show();
        loadProfileData();
        if (section === 'password') {
            setTimeout(() => {
                document.getElementById('passwordSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('profPassCurrent')?.focus();
            }, 250);
        }
    }

    async function loadProfileData() {
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_profile', action_client: 1 })
            });
            const data = await res.json();
            if (data.status === 'success') {
                document.getElementById('profNom').value = data.user.nombre;
                document.getElementById('profTel').value = data.user.telefono;
                document.getElementById('profDir').value = data.user.direccion || '';
                
                renderHistory(data.pedidos);
            }
        } catch (e) { console.error(e); }
    }

    function renderHistory(pedidos) {
        const div = document.getElementById('orderHistoryContent');
        if (!pedidos || pedidos.length === 0) {
            div.innerHTML = `<div class="text-center py-5 text-muted"><i class="fas fa-box-open fa-2x mb-2 d-block opacity-50"></i>${t('profile.no_orders')}</div>`;
            return;
        }

        const estadoBadge = (p) => {
            const ep = (p.estado_pago || '').toLowerCase();
            const er = (p.estado_reserva || '').toUpperCase();
            if (ep === 'rechazado' || er === 'CANCELADO') return '<span class="badge bg-danger">Cancelado</span>';
            if (ep === 'verificando')                    return '<span class="badge bg-warning text-dark">Verificando pago</span>';
            if (er === 'ENTREGADO')                      return '<span class="badge bg-success">Entregado</span>';
            if (er === 'EN_CAMINO')                      return '<span class="badge bg-info text-dark">En camino</span>';
            if (er === 'EN_PREPARACION')                 return '<span class="badge bg-primary">Preparando</span>';
            return '<span class="badge bg-secondary">Pendiente</span>';
        };

        const tipoIcon = (ts) => {
            if (ts === 'domicilio') return '<i class="fas fa-motorcycle text-primary me-1"></i>';
            if (ts === 'recogida')  return '<i class="fas fa-store text-success me-1"></i>';
            if (ts === 'reserva')   return '<i class="fas fa-calendar-check text-warning me-1"></i>';
            return '<i class="fas fa-shopping-bag text-muted me-1"></i>';
        };

        const fmt = (f) => {
            try { return new Date(f.replace(' ','T')).toLocaleDateString('es-CU', {day:'2-digit',month:'short',year:'numeric'}); }
            catch(e) { return f; }
        };

        let html = '<div class="list-group list-group-flush">';
        pedidos.forEach(p => {
            const idPad = String(p.id).padStart(5,'0');
            html += `
            <div class="list-group-item px-0 py-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-bold">${tipoIcon(p.tipo_servicio)}${t('tracking.order', {id: idPad})}</div>
                        <div class="small text-muted">${fmt(p.fecha)} · ${t('profile.products', {n: p.num_items})}</div>
                    </div>
                    <div class="text-end">
                        ${estadoBadge(p)}
                        <div class="fw-bold text-primary mt-1">${parseFloat(p.total).toLocaleString('es-CU')} CUP</div>
                    </div>
                </div>
                <div class="mt-2">
                    <a href="ticket_view.php?id=${p.id}" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-2">
                        <i class="fas fa-receipt me-1"></i>${t('profile.view_ticket')}
                    </a>
                </div>
            </div>`;
        });
        html += '</div>';
        div.innerHTML = html;
    }

    async function updateProfile() {
        const data = {
            action: 'update_profile',
            action_client: 1,
            nombre: document.getElementById('profNom').value,
            direccion: document.getElementById('profDir').value,
            csrf_token: CSRF_TOKEN
        };
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const resp = await res.json();
            if (resp.status === 'success') {
                showToast(t('toast.profile_updated'));
                location.reload();
            } else {
                showToast(resp.msg || t('toast.conn_error'));
            }
        } catch (e) { showToast(t('toast.conn_error')); }
    }

    async function changePassword() {
        const currentPassword = document.getElementById('profPassCurrent').value;
        const newPassword = document.getElementById('profPassNew').value;
        const confirmPassword = document.getElementById('profPassConfirm').value;

        if (!currentPassword || !newPassword || !confirmPassword) {
            showToast(t('auth.fill_fields'));
            return;
        }
        if (newPassword.length < 8 || !/[A-Z]/.test(newPassword) || !/\d/.test(newPassword)) {
            showToast(t('toast.password_policy'));
            return;
        }

        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'change_password',
                    action_client: 1,
                    current_password: currentPassword,
                    new_password: newPassword,
                    confirm_password: confirmPassword,
                    csrf_token: CSRF_TOKEN
                })
            });
            const resp = await res.json();
            if (resp.status === 'success') {
                document.getElementById('profPassCurrent').value = '';
                document.getElementById('profPassNew').value = '';
                document.getElementById('profPassConfirm').value = '';
                showToast(t('toast.password_updated'));
                setTimeout(() => profileModal?.hide(), 350);
            } else {
                showToast(resp.msg || t('toast.conn_error'));
            }
        } catch (e) {
            showToast(t('toast.conn_error'));
        }
    }
    
    // Config de tarjeta para pasarela (inyectada desde PHP)
    const CFG_TARJETA  = <?= json_encode($CFG_TARJETA) ?>;
    const CFG_TITULAR  = <?= json_encode($CFG_TITULAR) ?>;
    const CFG_BANCO    = <?= json_encode($CFG_BANCO)   ?>;
    const CSRF_TOKEN   = <?= json_encode($CSRF_TOKEN) ?>;

    // Multi-divisa
    window.SHOP_METODOS = <?= json_encode($metodosShop, JSON_UNESCAPED_UNICODE) ?>;
    window.SHOP_TC_USD  = <?= $tipoCambioUSD ?>;
    window.SHOP_TC_MLC  = <?= $tipoCambioMLC ?>;

    // Perfil del cliente logueado (null si no hay sesión)
    const CLIENT_PROFILE = <?= json_encode($clienteProfile) ?>;
    const SHOP_BASE_PATH = <?= json_encode($shopBasePath) ?>;
    const SHOP_PRETTY_BASE_PATH = <?= json_encode($shopPrettyBasePath) ?>;
    const INITIAL_PRODUCT_CODE = <?= json_encode($selectedProductCode) ?>;

    let cart = JSON.parse(localStorage.getItem('palweb_cart') || '[]');
    let cartSyncTimeout = null;
    let flujoActual = 'reserva'; // 'reserva' | 'compra'
    let ordenUUID = null; // para polling de estado de pago
    let pollInterval = null;
    
    async function toggleTrackingModal() {
        await ensureBootstrapRuntime().catch(() => {});
        trackingModal = getModalInstance(trackingModal, 'trackingModal');
        trackingModal?.show();
    }
    async function toggleAuthModal() {
        await ensureBootstrapRuntime().catch(() => {});
        authModal = getModalInstance(authModal, 'authModal');
        authModal?.show();
    }
    
    // ===== BUSCADOR (Feature 11: usa caché local si disponible, AJAX como fallback) =====
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout   = null;
    // Caché en memoria de resultados AJAX (máx 30 entradas, FIFO)
    const _searchCache  = new Map();
    const _SEARCH_MAX   = 30;

    function _searchCachePut(q, results) {
        if (_searchCache.size >= _SEARCH_MAX) {
            _searchCache.delete(_searchCache.keys().next().value);
        }
        _searchCache.set(q, results);
    }

    // ── Prefetch de variantes+reseñas al hacer hover 350ms en tarjeta ────────
    const _prefetchCache = new Set();
    function attachPrefetch(card) {
        let hoverTimer = null;
        card.addEventListener('mouseenter', () => {
            hoverTimer = setTimeout(() => {
                try {
                    const dataStr = card.getAttribute('onclick') || '';
                    const match   = dataStr.match(/"codigo"\s*:\s*"([^"]+)"/);
                    if (!match) return;
                    const codigo = match[1];
                    if (_prefetchCache.has(codigo)) return;
                    _prefetchCache.add(codigo);
                    // Prefetch silencioso en background
                    fetch(`shop.php?action_variants=1&codigo=${encodeURIComponent(codigo)}`, { priority: 'low' }).catch(() => {});
                    fetch(`shop.php?action_reviews=1&codigo=${encodeURIComponent(codigo)}`, { priority: 'low' }).catch(() => {});
                } catch {}
            }, 350);
        });
        card.addEventListener('mouseleave', () => clearTimeout(hoverTimer));
        // Móvil: prefetch en touchstart
        card.addEventListener('touchstart', () => {
            try {
                const match = (card.getAttribute('onclick')||'').match(/"codigo"\s*:\s*"([^"]+)"/);
                if (!match || _prefetchCache.has(match[1])) return;
                _prefetchCache.add(match[1]);
                fetch(`shop.php?action_variants=1&codigo=${encodeURIComponent(match[1])}`, { priority: 'low' }).catch(() => {});
            } catch {}
        }, { passive: true });
    }

    // Aplicar prefetch a todas las tarjetas presentes y futuras (MutationObserver)
    document.querySelectorAll('.product-card').forEach(attachPrefetch);
    new MutationObserver(mutations => {
        mutations.forEach(m => m.addedNodes.forEach(n => {
            if (n.nodeType === 1) {
                if (n.classList?.contains('product-card')) attachPrefetch(n);
                n.querySelectorAll?.('.product-card').forEach(attachPrefetch);
            }
        }));
    }).observe(document.getElementById('productsGrid') || document.body, { childList: true, subtree: true });

    function renderSearchItem(item) {
        const div = document.createElement('div');
        div.className = 'search-item';
        const imgHTML = item.hasImg
            ? `<picture>
                <source type="image/avif" srcset="${item.imgUrl}&fmt=avif&w=96 96w, ${item.imgUrl}&fmt=avif&w=192 192w" sizes="56px">
                <source type="image/webp" srcset="${item.imgUrl}&fmt=webp&w=96 96w, ${item.imgUrl}&fmt=webp&w=192 192w" sizes="56px">
                <img src="${item.imgUrl}&fmt=jpg&w=96" class="search-thumb" loading="lazy" sizes="56px">
               </picture>`
            : `<div class="search-placeholder" style="background:${item.bg}">${item.initials}</div>`;
        const priceDisplay = (item.precioOferta > 0 && item.precioOferta < item.precio)
            ? `<s style="font-size:.8em;color:#999">$${parseFloat(item.precio).toFixed(2)}</s> <span style="color:#dc3545;font-weight:700">$${parseFloat(item.precioOferta).toFixed(2)}</span>`
            : `$${parseFloat(item.precio).toFixed(2)}`;
        div.innerHTML = `${imgHTML}
            <div style="flex:1"><div style="font-weight:600">${escHtml(item.nombre)}</div>
            <small class="text-muted">${escHtml(item.categoria||'')}</small></div>
            <div style="font-weight:700;color:var(--primary)">${priceDisplay}</div>`;
        div.onclick = function() {
            searchInput.value = '';
            searchResults.style.display = 'none';
            openProductDetail({
                id: item.codigo, name: item.nombre,
                price: item.precioOferta > 0 && item.precioOferta < item.precio ? item.precioOferta : item.precio,
                precioOferta: item.precioOferta || 0,
                desc: item.descripcion || '',
                img: item.imgUrl, bg: item.bg, initials: item.initials,
                hasImg: item.hasImg, hasStock: item.hasStock, stock: item.stock,
                code: item.codigo, slug: item.slug || '',
                cat: item.categoria,
                unit: item.unidad_medida, color: item.color,
                esReservable: item.esReservable || false
            });
        };
        return div;
    }

    function doClientSearch(query) {
        const cached = getProductsFromCache();
        if (!cached) return false;
        const q = query.toLowerCase();
        const matches = cached.filter(p =>
            p.nombre.toLowerCase().includes(q) ||
            (p.categoria||'').toLowerCase().includes(q) ||
            (p.descripcion||'').toLowerCase().includes(q) ||
            p.codigo.toLowerCase().includes(q)
        ).slice(0, 12);
        searchResults.innerHTML = '';
        if (matches.length > 0) {
            matches.forEach(item => searchResults.appendChild(renderSearchItem(item)));
        } else {
            searchResults.innerHTML = `<div class="p-3 text-center text-muted">${t('search.not_found')}</div>`;
        }
        searchResults.style.display = 'block';
        return true;
    }

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);

        if (query.length >= 2 && !_catalogReady) warmCatalogWhenIdle();

        if (query.length < 1) {
            searchResults.style.display = 'none';
            return;
        }

        // Intentar búsqueda client-side instantánea
        if (doClientSearch(query)) return;

        // Fallback a AJAX — verificar caché en memoria primero
        if (_searchCache.has(query)) {
            const cached = _searchCache.get(query);
            searchResults.innerHTML = '';
            if (cached.length > 0) {
                cached.forEach(item => searchResults.appendChild(renderSearchItem(item)));
            } else {
                searchResults.innerHTML = `<div class="p-3 text-center text-muted">${t('search.not_found')}</div>`;
            }
            searchResults.style.display = 'block';
            return;
        }

        searchResults.innerHTML = `<div class="p-3 text-center">${t('search.searching')}</div>`;
        searchResults.style.display = 'block';

        // Debounce adaptativo: más largo en conexiones lentas
        const conn = navigator.connection || {};
        const debounceMs = ['slow-2g','2g'].includes(conn.effectiveType) ? 700
                         : conn.effectiveType === '3g' ? 450
                         : 280;

        searchTimeout = setTimeout(async () => {
            try {
                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 8000);
                const response   = await fetch(`shop.php?ajax_search=${encodeURIComponent(query)}`, { signal: controller.signal });
                clearTimeout(timeoutId);
                const data = await response.json();
                searchResults.innerHTML = '';
                if (data.error) {
                    searchResults.innerHTML = `<div class="p-3 text-danger">❌ ${data.message}</div>`;
                    return;
                }
                _searchCachePut(query, Array.isArray(data) ? data : []);
                if (data.length > 0) {
                    data.forEach(item => searchResults.appendChild(renderSearchItem(item)));
                } else {
                    searchResults.innerHTML = `<div class="p-3 text-center text-muted">${t('search.not_found')}</div>`;
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    searchResults.innerHTML = `<div class="p-3 text-muted">${t('search.timeout') || 'Tiempo de espera superado. Intenta de nuevo.'}</div>`;
                } else {
                    searchResults.innerHTML = `<div class="p-3 text-danger">${t('search.error')}</div>`;
                }
            }
        }, debounceMs);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // ===== FUNCIONES DEL CARRITO =====
    function updateCounters() {
        const qty = cart.reduce((a, b) => a + b.qty, 0);
        const badge = document.getElementById('cartBadge');
        badge.innerText = qty;
        badge.classList.toggle('d-none', qty === 0);
        localStorage.setItem('palweb_cart', JSON.stringify(cart));
        
        // --- Tracker de Carritos Abandonados ---
        if (typeof syncCartWithServer === 'function') syncCartWithServer();
    }
    
    function addToCart(id, name, price, isReserva = false, variant = null) {
        // Feature 13: capturar posición del botón antes de stopPropagation
        const _evt = typeof event !== 'undefined' ? event : null;
        if (_evt) _evt.stopPropagation();

        const vNombre = variant?.nombre ?? null;
        const finalPrice = variant ? (parseFloat(price) + parseFloat(variant.precio_extra)) : parseFloat(price);
        const existing = cart.find(i => i.id === id && i.isReserva === isReserva && (i.variant?.nombre ?? null) === vNombre);
        if (existing) {
            existing.qty++;
        } else {
            cart.push({ id, name, price: finalPrice, qty: 1, isReserva, variant: variant || null });
        }
        updateCounters();

        // Feature 13: animación fly-to-cart
        if (_evt && _evt.target) {
            const r = _evt.target.getBoundingClientRect();
            flyToCart(r.left + r.width / 2, r.top + r.height / 2);
        }

        const variantLabel = variant ? ` (${variant.nombre})` : '';
        const msg = isReserva
            ? t('toast.reserved') + variantLabel
            : t('toast.added') + variantLabel;
        showToast(msg);
    }
    
    function renderCart() {
        const tbody = document.getElementById('cartTableBody');
        tbody.innerHTML = '';
        let total = 0;
        
        if (cart.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-muted">${t('cart.empty_state')}</td></tr>`;
        } else {
            cart.forEach((item, index) => {
                const subtotal = item.qty * item.price;
                total += subtotal;
                const reservaBadge = item.isReserva
                    ? `<span class="badge ms-1" style="background:#f59e0b;color:#1a1a1a;font-size:.65rem;">📅 RESERVA</span>`
                    : '';
                const variantBadge = item.variant
                    ? `<span class="badge bg-secondary ms-1" style="font-size:.65rem;">${item.variant.nombre}</span>`
                    : '';
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold">${item.name}${reservaBadge}${variantBadge}</div>
                        </td>
                        <td class="text-center">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, -1)" aria-label="Disminuir cantidad de ${item.name}">-</button>
                                <span class="btn btn-sm btn-outline-secondary disabled" aria-live="polite" aria-label="Cantidad: ${item.qty}">${item.qty}</span>
                                <button class="btn btn-sm btn-outline-secondary" onclick="modQty(${index}, 1)" aria-label="Aumentar cantidad de ${item.name}">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-bold">$${subtotal.toFixed(2)}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger" onclick="remItem(${index})" aria-label="Eliminar ${item.name} del carrito">
                                <i class="fas fa-trash" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        // Aviso si hay items de reserva en el carrito
        const hasReservaItems = cart.some(i => i.isReserva);
        const reservaNotice = document.getElementById('reservaCartNotice');
        if (reservaNotice) reservaNotice.style.display = hasReservaItems ? 'block' : 'none';
        
        document.getElementById('cartTotal').innerText = total.toFixed(2);
        calcTotal();
    }
    
    function modQty(index, delta) {
        cart[index].qty += delta;
        if (cart[index].qty <= 0) cart.splice(index, 1);
        updateCounters();
        renderCart();
    }
    
    function remItem(index) {
        cart.splice(index, 1);
        updateCounters();
        renderCart();
        if (cart.length === 0) showCartView();
    }
    
    function clearCart() {
        if (confirm(t('cart.confirm_clear'))) {
            cart = [];
            updateCounters();
            renderCart();
        }
    }
    
    async function openProductDetail(data) {
        await Promise.all([
            ensureBootstrapRuntime().catch(() => {}),
            ensureFontAwesome().catch(() => {})
        ]);
        modalDetail = getModalInstance(modalDetail, 'modalDetail');
        // Guardar referencia para compartir (Feature 15)
        _currentDetailProduct = data;
        syncProductUrl(data);

        // Registrar vista en el servidor para estadísticas
        fetch(`shop.php?action_view_product&code=${data.id}`).catch(e => {});

        // Llenar campos con el nuevo diseño profesional
        document.getElementById('detailName').innerText = data.name;
        document.getElementById('detailDesc').innerText = data.desc || t('modal.no_desc');
        document.getElementById('detailCat').innerText = data.cat || 'General';
        document.getElementById('detailSku').innerText = data.code || '-';
        document.getElementById('detailUnit').innerText = data.unit ? '/ ' + data.unit : '';

        // Feature 2: precio tachado en modal
        const priceEl    = document.getElementById('detailPrice');
        const priceOrig  = document.getElementById('detailPriceOriginal');
        const po = parseFloat(data.precioOferta || 0);
        const bp = parseFloat(data.price || 0);
        if (po > 0 && po < bp) {
            priceOrig.textContent = '$' + bp.toFixed(2);
            priceOrig.style.display = 'inline';
            priceEl.textContent = '$' + po.toFixed(2);
            priceEl.classList.add('price-oferta');
        } else {
            priceOrig.style.display = 'none';
            priceEl.textContent = '$' + bp.toFixed(2);
            priceEl.classList.remove('price-oferta');
        }

        // Manejo de color
        if(data.color) {
            document.getElementById('divDetailColor').style.display = 'block';
            document.getElementById('detailColor').innerText = data.color;
        } else {
            document.getElementById('divDetailColor').style.display = 'none';
        }

        // Manejo de Stock
        const stockBadge = document.getElementById('detailStockBadge');
        const btn = document.getElementById('btnAddDetail');
        const avisoSection = document.getElementById('restockAvisoSection');
        avisoSection.style.display = 'none';

        if (data.hasStock) {
            stockBadge.innerText = t('stock.available');
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-success';
            btn.disabled = false;
            btn.style.background = '';
            btn.innerHTML = `<i class="fas fa-cart-plus me-2"></i> ${t('modal.add_to_cart')}`;
            btn.onclick = () => {
                addToCart(data.id, data.name, po > 0 && po < bp ? po : bp, false);
                modalDetail?.hide();
            };
        } else if (data.esReservable) {
            stockBadge.innerText = t('stock.reservable');
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-warning text-dark';
            btn.disabled = false;
            btn.style.background = '#f59e0b';
            btn.innerHTML = t('modal.reserve_product');
            btn.onclick = () => {
                addToCart(data.id, data.name, po > 0 && po < bp ? po : bp, true);
                modalDetail?.hide();
            };
        } else {
            // Feature 1: mostrar form de aviso restock
            stockBadge.innerText = t('stock.out_of_stock');
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-danger';
            btn.disabled = true;
            btn.style.background = '#6c757d';
            btn.innerHTML = t('modal.out_of_stock');
            avisoSection.style.display = 'block';
            avisoSection.setAttribute('data-codigo', data.id);
        }
        
        // Imagen principal + galería de extras
        const img         = document.getElementById('detailImg');
        const placeholder = document.getElementById('detailPlaceholder');
        const thumbsWrap  = document.getElementById('detailThumbs');

        // Recopilar todas las imágenes disponibles en orden
        const allImgs = [];
        if (data.img)       allImgs.push(data.img);
        if (data.imgExtra1) allImgs.push(data.imgExtra1);
        if (data.imgExtra2) allImgs.push(data.imgExtra2);

        // Mostrar imagen activa (la primera)
        const btnZoom = document.getElementById('btnZoomImg');
        if (allImgs.length > 0) {
            img.src = allImgs[0];
            img.classList.remove('d-none');
            placeholder.classList.add('d-none');
            btnZoom.classList.add('visible');
            img.onerror = function() {
                img.classList.add('d-none');
                placeholder.innerText = data.initials;
                placeholder.style.background = data.bg + 'cc';
                placeholder.classList.remove('d-none');
                btnZoom.classList.remove('visible');
                img.onerror = null;
            };
        } else {
            placeholder.innerText = data.initials;
            placeholder.style.background = data.bg + 'cc';
            img.classList.add('d-none');
            placeholder.classList.remove('d-none');
            btnZoom.classList.remove('visible');
        }

        // Thumbnails — siempre limpiar; mostrar si hay >1 imagen
        thumbsWrap.innerHTML = '';
        thumbsWrap.style.display = '';
        if (allImgs.length > 1) {
            allImgs.forEach((src, i) => {
                const t = document.createElement('img');
                t.src       = src + '&w=128';
                t.className = 'detail-thumb' + (i === 0 ? ' active' : '');
                t.alt       = 'Vista ' + (i + 1);
                t.loading   = 'lazy';
                t.onclick   = () => {
                    img.src = src;
                    document.getElementById('imgZoomSrc').src = src;
                    thumbsWrap.querySelectorAll('.detail-thumb').forEach(x => x.classList.remove('active'));
                    t.classList.add('active');
                };
                thumbsWrap.appendChild(t);
            });
        }

        // Cargar variantes y reseñas para este producto
        loadVariants(data.id, parseFloat(data.price), data.hasStock, data.esReservable);
        loadReviews(data.id);

        modalDetail?.show();
    }

    async function openCart() {
        await Promise.all([
            ensureBootstrapRuntime().catch(() => {}),
            ensureFontAwesome().catch(() => {})
        ]);
        modalCart = getModalInstance(modalCart, 'modalCart');
        showCartView(); 
        renderCart(); 
        modalCart?.show(); 
    }
    
    function showCartView() {
        document.getElementById('cartItemsView').style.display = 'block';
        document.getElementById('checkoutView').style.display = 'none';
        document.getElementById('successView').style.display = 'none';
        document.getElementById('waitingPaymentView').style.display = 'none';
        document.getElementById('pagoConfirmadoView').style.display = 'none';
        document.getElementById('pagoRechazadoView').style.display = 'none';
    }

    function showCheckout() {
        // Alias para compatibilidad (flujo reserva por defecto)
        iniciarFlujo('reserva');
    }

    function iniciarFlujo(flujo) {
        if (cart.length === 0) { showToast(t('toast.cart_empty')); return; }
        flujoActual = flujo;

        if (flujo === 'compra') {
            // Bloquear si hay items marcados como reserva en el carrito
            const reservaItems = cart.filter(i => i.isReserva);
            if (reservaItems.length > 0) {
                const nombres = reservaItems.map(i => '• ' + i.name).join('\n');
                alert('⚠️ Tu carrito contiene productos de RESERVA (sin stock):\n\n' + nombres +
                      '\n\nEstos productos solo pueden procesarse con el botón "📅 Reservar".\nPor favor elimínalos del carrito o usa "Reservar" para todos.');
                return;
            }
            // Verificar stock antes de abrir checkout
            const items = cart.map(i => ({ id: i.id, qty: i.qty }));
            fetch('pagos_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'check_stock', items })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.all_ok) {
                    const lista = res.out.map(p =>
                        `• ${p.nombre}: disponible ${p.stock}, necesario ${p.needed}`
                    ).join('\n');
                    alert("⚠️ Los siguientes productos no tienen stock suficiente para pago inmediato:\n\n" + lista +
                          "\n\nPuedes usar 'Reservar' para pedirlos sin stock.");
                    return;
                }
                abrirCheckoutInterno('compra');
            })
            .catch(() => {
                // Si falla la verificación, abrir igual (la validación final la hace pos_save)
                abrirCheckoutInterno('compra');
            });
        } else {
            abrirCheckoutInterno('reserva');
        }
    }

    // ========================================================
    // TIME SLOTS DE ENTREGA
    // ========================================================
    const TIME_SLOTS = [
        { id: 'manana',   label: 'Mañana (9am–12pm)',   start: 9,  end: 12 },
        { id: 'mediodia', label: 'Mediodía (12pm–3pm)',  start: 12, end: 15 },
        { id: 'tarde',    label: 'Tarde (3pm–6pm)',      start: 15, end: 18 },
        { id: 'noche',    label: 'Noche (6pm–9pm)',      start: 18, end: 21 },
    ];
    let selectedSlot = null;

    function selectSlot(el) {
        if (el.classList.contains('disabled')) return;
        document.querySelectorAll('.slot-card').forEach(c => c.classList.remove('active'));
        el.classList.add('active');
        selectedSlot = { id: el.dataset.slot, label: el.dataset.label };
        document.getElementById('slotErrorMsg').style.display = 'none';
    }
    window.selectSlot = selectSlot;

    function updateSlotsAvailability() {
        const dateVal = document.getElementById('cliDate')?.value ?? '';
        const todayStr = new Date().toISOString().split('T')[0];
        const nowHour = new Date().getHours();
        const isToday = dateVal === todayStr;

        document.querySelectorAll('.slot-card').forEach(card => {
            const slot = TIME_SLOTS.find(s => s.id === card.dataset.slot);
            if (!slot) return;
            // Para hoy, deshabilitar slots cuyo horario ya pasó
            if (isToday && nowHour >= slot.end) {
                card.classList.add('disabled');
                if (selectedSlot?.id === slot.id) {
                    card.classList.remove('active');
                    selectedSlot = null;
                }
            } else {
                card.classList.remove('disabled');
            }
        });

        // Auto-seleccionar el primer slot disponible si ninguno está activo
        if (!selectedSlot) {
            const first = document.querySelector('.slot-card:not(.disabled)');
            if (first) selectSlot(first);
        }
    }
    window.updateSlotsAvailability = updateSlotsAvailability;

    function resetSlots() {
        selectedSlot = null;
        document.querySelectorAll('.slot-card').forEach(c => {
            c.classList.remove('active', 'disabled');
        });
        document.getElementById('slotErrorMsg').style.display = 'none';
    }

    function abrirCheckoutInterno(flujo) {
        flujoActual = flujo;
        document.getElementById('cartItemsView').style.display = 'none';
        document.getElementById('checkoutView').style.display = 'block';
        document.getElementById('successView').style.display = 'none';
        document.getElementById('waitingPaymentView').style.display = 'none';
        document.getElementById('pagoConfirmadoView').style.display = 'none';
        document.getElementById('pagoRechazadoView').style.display = 'none';

        // Mostrar u ocultar sección de pago
        const paySection = document.getElementById('paymentSection');
        paySection.style.display = flujo === 'compra' ? 'block' : 'none';

        // Siempre limpiar estado de campos código de pago (evitar validación HTML5 en campo oculto)
        document.querySelectorAll('[id^="codigoPago_"]').forEach(inp => {
            inp.removeAttribute('required'); inp.required = false; inp.value = '';
        });

        // Si es compra: marcar el primer radio como checked y ocultar info de transferencia
        if (flujo === 'compra') {
            const firstRadio = document.querySelector('input[name=metodoPago]');
            if (firstRadio) firstRadio.checked = true;
            document.querySelectorAll('[id^="transInfo_"]').forEach(d => d.style.display = 'none');
            onPayMethodChange();
        }

        // Fecha mínima
        const today = new Date().toISOString().split('T')[0];
        const tomorrow = new Date(Date.now() + 86400000).toISOString().split('T')[0];
        const dateInput = document.getElementById('cliDate');
        dateInput.min = today;
        dateInput.max = flujo === 'compra' ? tomorrow : '';
        if (!dateInput.value) dateInput.value = tomorrow;

        // Reiniciar y actualizar slots según la fecha seleccionada
        resetSlots();
        updateSlotsAvailability();

        // Auto-rellenar datos del cliente si está logueado
        if (CLIENT_PROFILE) {
            const nameEl = document.getElementById('cliName');
            const telEl  = document.getElementById('cliTel');
            const dirEl  = document.getElementById('cliDir');
            if (nameEl && !nameEl.value) nameEl.value = CLIENT_PROFILE.nombre || '';
            if (telEl  && !telEl.value)  telEl.value  = CLIENT_PROFILE.telefono || '';
            if (dirEl  && !dirEl.value)  dirEl.value  = CLIENT_PROFILE.direccion || '';
        }
    }

    // ───── Multi-divisa shop ─────
    let shopCurrency = localStorage.getItem('shop_currency') || 'CUP';
    let shopTC = 1.0;

    function formatPrice(precioCUP) {
        const p = precioCUP / shopTC;
        const sym = shopCurrency === 'CUP' ? '$' : shopCurrency + ' ';
        return sym + p.toLocaleString('es-CU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Filtro de categoría client-side — sin recarga de página
    let _activeCat = '';
    window.filterByCategory = function(cat) {
        _activeCat = cat;
        // Restablecer infinite scroll antes de filtrar
        if (typeof window._infiniteScrollReset === 'function') window._infiniteScrollReset();

        const cards = document.querySelectorAll('#productsGrid .product-card');
        let visible = 0;
        cards.forEach(card => {
            const match = !cat || card.dataset.cat === cat;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        // Actualizar pills activos
        document.querySelectorAll('#categoryPills .cat-pill').forEach(btn => {
            const btnCat = btn.getAttribute('onclick')?.match(/filterByCategory\(([^)]*)\)/)?.[1];
            const isAll  = btnCat === "''";
            btn.classList.toggle('active', isAll ? !cat : btnCat === JSON.stringify(cat));
        });

        // Actualizar contador visible
        const badge = document.querySelector('.badge.bg-primary');
        if (badge) badge.firstChild.textContent = visible + ' ';
    };

    window.setShopCurrency = function(m) {
        shopCurrency = m;
        localStorage.setItem('shop_currency', m);
        shopTC = { CUP: 1.0, USD: window.SHOP_TC_USD, MLC: window.SHOP_TC_MLC }[m] || 1.0;
        document.querySelectorAll('#shopCurrencyToggle button').forEach(b =>
            b.classList.toggle('active', b.textContent.trim() === m));
        renderProducts();
    };

    function renderProducts() {
        // Actualiza los precios en las tarjetas del catálogo (PHP-rendered con data-cup)
        document.querySelectorAll('.product-price[data-cup]').forEach(el => {
            const cup      = parseFloat(el.dataset.cup) || 0;
            const cupOferta= parseFloat(el.dataset.cupOferta) || 0;
            if (cupOferta > 0 && cupOferta < cup) {
                el.innerHTML = `<span class="price-original">${formatPrice(cup)}</span> <span class="price-oferta">${formatPrice(cupOferta)}</span>`;
            } else {
                el.textContent = formatPrice(cup);
            }
        });
    }

    // ───── Métodos de pago dinámicos (shop) ─────
    function renderShopPaymentMethods() {
        const container = document.getElementById('shopPaymentMethodsContainer');
        if (!container) return;
        container.innerHTML = '';
        const metodos = window.SHOP_METODOS || [];
        metodos.forEach((m, i) => {
            const radioId = 'mp_' + m.id.replace(/\W/g, '_');
            container.innerHTML += `
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="metodoPago"
                           id="${radioId}" value="${m.id}" onchange="onPayMethodChange()" ${i === 0 ? 'checked' : ''}>
                    <label class="form-check-label" for="${radioId}">
                        <i class="fas ${m.icono} me-2"></i>${m.nombre}
                    </label>
                </div>`;
            if (m.es_transferencia) {
                container.innerHTML += `
                    <div id="transInfo_${m.id.replace(/\W/g,'_')}" style="display:none;" class="mb-2">
                        <div class="p-3 mb-2 rounded" style="background:#e8f4f8; border-left:4px solid #0d6efd;">
                            <div class="small">Tarjeta: <strong>${CFG_TARJETA || '(sin configurar)'}</strong></div>
                            <div class="small">Titular: <strong>${CFG_TITULAR || '(sin configurar)'}</strong></div>
                            <div class="small">Banco: <strong>${CFG_BANCO || 'Bandec / BPA'}</strong></div>
                        </div>
                        <label class="form-label fw-bold">Código de confirmación *</label>
                        <input id="codigoPago_${m.id.replace(/\W/g,'_')}" type="text" class="form-control mb-1"
                               placeholder="Ej: TRF-20260220-001234">
                        <small class="text-muted">Código de confirmación de la transferencia.</small>
                    </div>`;
            }
            if (m.es_especial && m.texto_especial) {
                const safeId = m.id.replace(/\W/g,'_');
                container.innerHTML += `
                    <div id="especialInfo_${safeId}" style="display:none;" class="mb-2">
                        <div class="p-3 rounded" style="background:#fff8e1; border-left:4px solid #ffc107;">
                            <div class="small"><i class="fas fa-info-circle me-1 text-warning"></i>${m.texto_especial.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div>
                        </div>
                    </div>`;
            }
        });
    }

    function onPayMethodChange() {
        const val = document.querySelector('input[name=metodoPago]:checked')?.value;
        // Ocultar todos los paneles de transferencia y especiales
        document.querySelectorAll('[id^="transInfo_"]').forEach(d => d.style.display = 'none');
        document.querySelectorAll('[id^="especialInfo_"]').forEach(d => d.style.display = 'none');
        if (val) {
            const safeVal = val.replace(/\W/g, '_');
            const metodo = (window.SHOP_METODOS || []).find(x => x.id === val);
            if (metodo?.es_transferencia) {
                const transDiv = document.getElementById('transInfo_' + safeVal);
                if (transDiv) transDiv.style.display = 'block';
            }
            if (metodo?.es_especial && metodo?.texto_especial) {
                const espDiv = document.getElementById('especialInfo_' + safeVal);
                if (espDiv) espDiv.style.display = 'block';
            }
        }
    }

    function mostrarEsperandoPago(uuid, codigo) {
        ordenUUID = uuid;
        document.getElementById('checkoutView').style.display = 'none';
        document.getElementById('waitingPaymentView').style.display = 'block';
        const displayEl = document.getElementById('codigoPagoDisplay');
        if (displayEl) displayEl.textContent = codigo || '';
        // Iniciar polling
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(pollPago, 5000);
    }

    function pollPago() {
        if (!ordenUUID) return;
        fetch(`pagos_api.php?action=check_status&uuid=${encodeURIComponent(ordenUUID)}`)
            .then(r => r.json())
            .then(res => {
                if (res.estado_pago === 'confirmado') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    document.getElementById('waitingPaymentView').style.display = 'none';
                    // Rellenar # pedido y nombre del cliente
                    const nombre = (res.cliente_nombre || '').split(' ').slice(0,2).join(' ');
                    document.getElementById('confirmadoNombre').textContent  = nombre || 'Cliente';
                    document.getElementById('confirmadoPedido').textContent  = res.id ? String(res.id).padStart(5,'0') : '—';
                    document.getElementById('pagoConfirmadoView').style.display = 'block';
                } else if (res.estado_pago === 'rechazado') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    document.getElementById('waitingPaymentView').style.display = 'none';
                    const motivoDiv = document.getElementById('motivoRechazoDiv');
                    const motivoTxt = document.getElementById('motivoRechazoTxt');
                    if (res.motivo_rechazo) {
                        motivoTxt.textContent = res.motivo_rechazo;
                        motivoDiv.style.display = 'block';
                    }
                    document.getElementById('pagoRechazadoView').style.display = 'block';
                }
            })
            .catch(() => { /* silencioso */ });
    }

    function showStockWarningToast(out) {
        const lista = out.map(p => `${p.nombre} (${p.stock} disp.)`).join(', ');
        showToast('Sin stock: ' + lista, 5000);
    }

    // ===== GEOLOCALIZACIÓN =====
    async function loadMunicipios() {
        try {
            const res = await fetch('shop.php?action_geo=list_mun');
            const muns = await res.json();
            const sel = document.getElementById('cMun');
            sel.innerHTML = `<option value="">${t('checkout.municipality_ph')}</option>`;
            muns.forEach(m => sel.innerHTML += `<option value="${m}">${m}</option>`);
        } catch(e) {
            console.error('Error cargando municipios:', e);
        }
    }
    
    async function loadBarrios() {
        try {
            const mun = document.getElementById('cMun').value;
            const res = await fetch(`shop.php?action_geo=list_bar&m=${encodeURIComponent(mun)}`);
            const bars = await res.json();
            const sel = document.getElementById('cBar');
            sel.innerHTML = `<option value="">${t('checkout.neighborhood_ph')}</option>`;
            bars.forEach(b => sel.innerHTML += `<option value="${b}">${b}</option>`);
            calcShip();
        } catch(e) {
            console.error('Error cargando barrios:', e);
        }
    }
    
    async function calcShip() {
        try {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            if (mun && bar) {
                const res = await fetch(`shop.php?action_geo=calc&m=${encodeURIComponent(mun)}&b=${encodeURIComponent(bar)}`);
                const data = await res.json();
                document.getElementById('shipCost').innerText = data.costo.toFixed(2);
            } else {
                document.getElementById('shipCost').innerText = '0.00';
            }
            calcTotal();
        } catch(e) {
            console.error('Error calculando envío:', e);
        }
    }
    
    function toggleDelivery() {
        const isDel = document.getElementById('delHome').checked;
        document.getElementById('deliveryBox').style.display = isDel ? 'block' : 'none';
        if (isDel && document.getElementById('cMun').options.length === 1) loadMunicipios();
        if (!isDel) document.getElementById('shipCost').innerText = '0.00';
        calcTotal();
    }
    
    function calcTotal() {
        const prod = parseFloat(document.getElementById('cartTotal').innerText);
        const ship = parseFloat(document.getElementById('shipCost').innerText);
        document.getElementById('grandTotal').innerText = (prod + ship).toFixed(2);
        document.getElementById('cartTotal2').innerText = prod.toFixed(2);
    }

    // ===== ENVIAR PEDIDO =====
    async function submitOrder(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i>${t('checkout.sending')}`;

        let address = document.getElementById('cliDir').value;
        const notes = document.getElementById('cliNotes').value;
        const delivery = document.getElementById('delHome').checked;

        if (delivery) {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            if (!mun || !bar) {
                alert(t('checkout.mun_bar_required'));
                btn.disabled = false;
                btn.innerHTML = `<i class="fas fa-check-circle me-2"></i> ${t('checkout.confirm')}`;
                return;
            }
            address = `[MENSAJERÍA: ${mun} - ${bar}] ${address}`;
        } else {
            address = flujoActual === 'reserva' ? "[RESERVA] " + address : "[RECOGIDA] " + address;
        }

        // Determinar tipo_servicio y metodo_pago según flujo
        let tipoServicio, metodoPago, codigoPago = null, estadoPago = 'pendiente';

        if (flujoActual === 'reserva') {
            tipoServicio = 'reserva';
            metodoPago   = 'PENDIENTE_ENTREGA';
        } else {
            tipoServicio = delivery ? 'domicilio' : 'recogida';
            const radioChecked = document.querySelector('input[name=metodoPago]:checked');
            metodoPago = radioChecked ? radioChecked.value : ((window.SHOP_METODOS?.[0]?.id) || 'Efectivo');
            const metodoObj = (window.SHOP_METODOS || []).find(m => m.id === metodoPago);
            if (metodoObj?.es_transferencia) {
                const codigoInput = document.getElementById('codigoPago_' + metodoPago.replace(/\W/g, '_'));
                codigoPago = (codigoInput?.value || '').trim();
                if (!codigoPago) {
                    alert(t('checkout.transfer_code_required'));
                    btn.disabled = false;
                    btn.innerHTML = `<i class="fas fa-check-circle me-2"></i> ${t('checkout.confirm')}`;
                    return;
                }
                estadoPago = 'verificando';
            }
        }

        // Totales en CUP (siempre en CUP para el backend)
        const grandTotalCUP = parseFloat(document.getElementById('grandTotal').innerText) || 0;

        // Validar time slot
        if (!selectedSlot) {
            document.getElementById('slotErrorMsg').style.display = 'block';
            document.getElementById('slotErrorMsg').scrollIntoView({ behavior: 'smooth', block: 'center' });
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-check-circle me-2"></i> ${t('checkout.confirm')}`;
            return;
        }

        const uuid = 'WEB-' + Date.now();
        const slotPrefix = `[⏰ ${selectedSlot.label}] `;
        const data = {
            uuid,
            total:                   grandTotalCUP,
            metodo_pago:             metodoPago,
            tipo_servicio:           tipoServicio,
            cliente_nombre:          document.getElementById('cliName').value,
            cliente_telefono:        document.getElementById('cliTel').value,
            cliente_direccion:       address,
            fecha_reserva:           document.getElementById('cliDate').value,
            mensajero_nombre:        slotPrefix + notes,
            codigo_pago:             codigoPago,
            estado_pago:             estadoPago,
            canal_origen:            'Web',
            moneda:                  shopCurrency,
            tipo_cambio:             shopTC,
            monto_moneda_original:   parseFloat((grandTotalCUP / shopTC).toFixed(2)),
            csrf_token:              CSRF_TOKEN,
            items: cart.map(i => ({ id: i.id, qty: i.qty, price: i.price, name: i.name, note: '', isReserva: i.isReserva || false }))
        };

        try {
            const res = await fetch('ventas_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const json = await res.json();

            if (json.status === 'success') {
                cart = [];
                localStorage.removeItem('palweb_cart');
                updateCounters();
                document.getElementById('checkoutView').style.display = 'none';

                if (estadoPago === 'verificando') {
                    // Mostrar pantalla de espera con polling
                    mostrarEsperandoPago(uuid, codigoPago);
                } else {
                    document.getElementById('successView').style.display = 'block';
                }
            } else {
                alert('Error: ' + (json.msg || 'Error desconocido'));
            }
        } catch (error) {
            console.error('[submitOrder]', error);
            // Sin red → encolar para Background Sync
            if (!navigator.onLine || error.name === 'TypeError') {
                await queueCheckoutForSync(data);
            } else {
                alert('Error de red al enviar el pedido. Verifica tu conexión.');
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-check-circle me-2"></i> ${t('checkout.confirm')}`;
        }
    }
    
    // Hacer funciones globales accesibles desde onclick
    window.addToCart = addToCart;
    window.openProductDetail = openProductDetail;
    window.openCart = openCart;
    window.clearCart = clearCart;
    window.showCartView = showCartView;
    window.showCheckout = showCheckout;
    window.iniciarFlujo = iniciarFlujo;
    window.onPayMethodChange = onPayMethodChange;
    window.modQty = modQty;
    window.remItem = remItem;
    window.loadMunicipios = loadMunicipios;
    window.loadBarrios = loadBarrios;
    window.calcShip = calcShip;
    window.toggleDelivery = toggleDelivery;
    window.submitOrder = submitOrder;
    
    // Inicializar
    updateCounters();

    // ========================================================
    // LÓGICA CHAT CLIENTE
    // ========================================================
    const CHAT_API_URL = 'chat_api.php';
    let clientUUID = localStorage.getItem('palweb_chat_uuid');
    if (!clientUUID) {
        clientUUID = 'cli_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('palweb_chat_uuid', clientUUID);
    }

    let chatOpen = false;
    let chatInterval = null;
    const CHAT_POLL_MS = 15000; // 15s — suficiente para chat humano, ahorra batería y ancho de banda

    function _chatStartPolling() {
        if (chatInterval) return;
        chatInterval = setInterval(() => { if (chatOpen && document.visibilityState !== 'hidden') loadMessages(); }, CHAT_POLL_MS);
    }
    function _chatStopPolling() { clearInterval(chatInterval); chatInterval = null; }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { _chatStopPolling(); }
        else if (chatOpen) { loadMessages(); _chatStartPolling(); }
    });

    function toggleChat() {
        const win = document.getElementById('chatWindow');
        chatOpen = !chatOpen;
        win.style.display = chatOpen ? 'flex' : 'none';

        if (chatOpen) {
            document.getElementById('clientChatBadge').style.display = 'none';
            loadMessages();
            _chatStartPolling();
            setTimeout(() => {
                const body = document.getElementById('chatBody');
                body.scrollTop = body.scrollHeight;
            }, 100);
        } else {
            _chatStopPolling();
        }
    }
    window.toggleChat = toggleChat;

    async function sendClientMsg() {
        const input = document.getElementById('chatInput');
        const text = input.value.trim();
        if (!text) return;

        // Optimistic UI: Mostrar inmediatamente
        renderMsg('client', text);
        input.value = '';

        try {
            await fetch(CHAT_API_URL + '?action=send', {
                method: 'POST',
                body: JSON.stringify({ uuid: clientUUID, message: text, sender: 'client' })
            });
        } catch (e) { console.error(e); }
    }
    window.sendClientMsg = sendClientMsg;

    async function loadMessages() {
        try {
            const res = await fetch(`${CHAT_API_URL}?action=get_client_msgs&uuid=${clientUUID}`);
            const msgs = await res.json();
            
            const body = document.getElementById('chatBody');
            body.innerHTML = ''; // Limpiar y repintar (simple pero efectivo)
            
            if (msgs.length === 0) {
                body.innerHTML = `<div class="text-center text-muted small mt-5">${t('chat.greeting').replace(/\n/g,'<br>')}</div>`;
                return;
            }

            msgs.forEach(m => {
                const div = document.createElement('div');
                div.className = `msg-bubble msg-${m.sender}`;
                div.innerText = m.message;
                body.appendChild(div);
            });
            
            // Auto scroll solo si estamos abajo
            // body.scrollTop = body.scrollHeight; 
        } catch (e) { console.error(e); }
    }

    function renderMsg(sender, text) {
        const body = document.getElementById('chatBody');
        if (body.querySelector('.text-center')) body.innerHTML = '';
        
        const div = document.createElement('div');
        div.className = `msg-bubble msg-${sender}`;
        div.innerText = text;
        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
    }

    // ========================================================
    // SEGUIMIENTO DE PEDIDOS
    // ========================================================
    async function searchTrack() {
        const q = document.getElementById('trackInput').value.trim();
        if (!q) return;
        
        const resultsDiv = document.getElementById('trackResults');
        resultsDiv.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        
        try {
            const res = await fetch(`shop.php?action_track&q=${encodeURIComponent(q)}`);
            const data = await res.json();
            
            if (data.status === 'error') {
                resultsDiv.innerHTML = `<div class="alert alert-danger">Error del servidor: ${data.msg}</div>`;
                return;
            }

            if (data.length === 0) {
                resultsDiv.innerHTML = `<div class="alert alert-warning">${t('tracking.not_found')}</div>`;
                return;
            }

            let html = '<div class="list-group shadow-sm">';
            data.forEach(p => {
                let badgeClass = 'bg-secondary';
                let statusText = t('status.unknown');

                switch (p.estado) {
                    case 'Pendiente':
                        badgeClass = 'bg-warning text-dark';
                        statusText = t('status.pendiente');
                        break;
                    case 'En Preparación':
                        badgeClass = 'bg-info';
                        statusText = t('status.en_preparacion');
                        break;
                    case 'En Camino':
                        badgeClass = 'bg-primary';
                        statusText = t('status.en_camino');
                        break;
                    case 'Entregado':
                        badgeClass = 'bg-success';
                        statusText = t('status.entregado');
                        break;
                    case 'Cancelado':
                        badgeClass = 'bg-danger';
                        statusText = t('status.cancelado');
                        break;
                }

                let productsHtml = '';
                if (p.items && p.items.length > 0) {
                    productsHtml = `<div class="small mt-3 pt-2 border-top border-light-subtle"><b>${t('tracking.products')}</b><ul>`;
                    p.items.forEach(item => {
                        productsHtml += `<li>${item.qty}x ${item.name} ($${parseFloat(item.price).toFixed(2)})</li>`;
                    });
                    productsHtml += '</ul></div>';
                }

                html += `
                <div class="list-group-item border-0 border-bottom p-3 animate__animated animate__fadeIn">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">${t('tracking.order', {id: p.id})}</span>
                        <span class="badge ${badgeClass}">${p.estado}</span>
                    </div>
                    <div class="small text-muted mb-1">
                        <i class="fas fa-calendar-alt me-1"></i> ${p.fecha}
                    </div>
                    <div class="mb-2">
                        <i class="fas fa-user me-1"></i> ${t('tracking.client')} <span class="fw-bold text-dark">${p.cliente_nombre || 'N/A'}</span>
                    </div>
                    <div class="fw-bold text-primary mb-2">${t('tracking.total')} $${parseFloat(p.total).toFixed(2)}</div>
                    <div class="alert alert-light border small py-2 animate__animated animate__fadeIn">
                        <i class="fas fa-info-circle me-1"></i> ${statusText}
                    </div>
                    ${productsHtml}
                </div>`;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        } catch (e) { resultsDiv.innerHTML = `<div class="alert alert-danger">${t('tracking.error')}</div>`; }
    }

    // ========================================================
    // LOGIN & REGISTRO DE CLIENTES
    // ========================================================
    async function generateCaptcha() {
        try {
            const res = await fetch('shop.php?action_new_captcha');
            const data = await res.json();
            const el = document.getElementById('captchaLabel');
            if (el) el.innerText = data.q;
        } catch (e) { console.warn('captcha refresh failed', e); }
    }

    async function loginClient() {
        const tel = document.getElementById('logTel').value;
        const pass = document.getElementById('logPass').value;
        if (!tel || !pass) return alert(t('auth.fill_fields'));

        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'login', action_client: 1, telefono: tel, password: pass, csrf_token: CSRF_TOKEN })
            });
            const data = await res.json();
            if (data.status === 'success') {
                location.reload();
            } else {
                alert(data.msg);
            }
        } catch (e) { alert(t('auth.conn_error')); }
    }

    async function registerClient() {
        const nombre = document.getElementById('regNom').value.trim();
        const tel = document.getElementById('regTel').value.trim();
        const pass = document.getElementById('regPass').value.trim();
        const dir = document.getElementById('regDir').value.trim();
        const ans = document.getElementById('regCaptcha').value.trim();

        if (!nombre || !tel || !pass || !ans) return alert("Completa todos los campos.");
        
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'register', action_client: 1, csrf_token: CSRF_TOKEN,
                    nombre, telefono: tel, password: pass, direccion: dir,
                    captcha_ans: ans
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                alert("Cuenta creada con éxito. Ya puedes iniciar sesión.");
                // Cambiar a tab de login
                const triggerEl = document.querySelector('#authTabs a[href="#loginTab"]');
                await ensureBootstrapRuntime().catch(() => {});
                if (triggerEl && window.bootstrap?.Tab) {
                    bootstrap.Tab.getOrCreateInstance(triggerEl).show();
                }
            } else {
                alert(data.msg);
                generateCaptcha();
            }
        } catch (e) { alert("Error de conexión."); }
    }

    async function logoutClient() {
        await fetch('shop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout', action_client: 1 })
        });
        location.reload();
    }

    // ========================================================
    // TRACKER DE CARRITOS ABANDONADOS
    // ========================================================
    function syncCartWithServer() {
        if (cartSyncTimeout) clearTimeout(cartSyncTimeout);
        cartSyncTimeout = setTimeout(async () => {
            const cartItems = JSON.parse(localStorage.getItem('palweb_cart')) || [];
            if (cartItems.length === 0) return;
            
            const totalValue = cartItems.reduce((sum, item) => sum + (item.price * item.qty), 0);
            
            try {
                await fetch('shop.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ update_cart_tracker: 1, items: cartItems, total: totalValue })
                });
            } catch (e) { console.warn("Cart tracker failed:", e); }
        }, 5000); 
    }

</script>

<!-- =========================================================
     TESTIMONIOS — lazy-loaded al hacer scroll
     ========================================================= -->
<div id="testimonialsSentinel" style="height:1px;"></div>
<section id="testimonialsSection" aria-label="Testimonios de clientes"
    style="background:linear-gradient(135deg,#f0f4ff 0%,#fdf4ff 100%);padding:3rem 0;display:none;">
  <div class="container">
    <h2 style="text-align:center;font-size:1.5rem;font-weight:800;color:#4f46e5;margin-bottom:.4rem;">
      Lo que dicen nuestros clientes
    </h2>
    <p style="text-align:center;color:#6b7280;font-size:.9rem;margin-bottom:2rem;">Experiencias reales de personas que confían en nosotros</p>

    <div id="testimonialsCarousel" style="position:relative;overflow:hidden;">
      <div id="testimonialsTrack" style="display:flex;transition:transform .45s ease;will-change:transform;">

        <!-- Testimonio 1 -->
        <div class="testimonial-card">
          <div class="t-stars">★★★★★</div>
          <p class="t-text">"Mira, yo tenía una cafeterita aquí en el Vedado y los productos llegaban tarde y malos. Desde que empecé a pedir con PalWeb, me llega fresquito y a tiempo. Mi negocio mejoró un montón, los clientes se quedaron contentos y yo también. ¡No hay comparación, mi socio!"</p>
          <div class="t-author">
            <img src="assets/img/testimonial_1.jpg" alt="Yosvany Reyes" class="t-avatar-img">
            <div>
              <strong>Yosvany Reyes</strong>
              <span>Cafetería El Rincón · Vedado, La Habana</span>
            </div>
          </div>
        </div>

        <!-- Testimonio 2 -->
        <div class="testimonial-card">
          <div class="t-stars">★★★★★</div>
          <p class="t-text">"Yo buscaba dónde conseguir todo junto sin andar de un lado pa' otro. Aquí encuentro lo que necesito pa' mi paladarcito y me lo mandan sin tardanza. El servicio es el mejor que he visto en La Habana, y eso que yo soy exigente, ¿eh? ¡100% recomendado!"</p>
          <div class="t-author">
            <img src="assets/img/testimonial_2.jpg" alt="Marlenis Cruz" class="t-avatar-img">
            <div>
              <strong>Marlenis Cruz</strong>
              <span>Paladar La Casona · Centro Habana</span>
            </div>
          </div>
        </div>

        <!-- Testimonio 3 -->
        <div class="testimonial-card">
          <div class="t-stars">★★★★★</div>
          <p class="t-text">"Al principio no me fiaba mucho de pedir por internet, normal... pero una amiga me recomendó PalWeb y me animé. Hice el pedido por WhatsApp, me respondieron al momento y llegó en menos de lo que esperaba. Ahora soy clienta fija, ¡qué va, no hay quien me quite esto!"</p>
          <div class="t-author">
            <img src="assets/img/testimonial_3.jpg" alt="Dailenis Valdés" class="t-avatar-img">
            <div>
              <strong>Dailenis Valdés</strong>
              <span>Emprendedora · Playa, La Habana</span>
            </div>
          </div>
        </div>

      </div><!-- /track -->

      <!-- Controles -->
      <button class="t-btn t-btn-prev" onclick="_tPrev()" aria-label="Testimonio anterior">&#8249;</button>
      <button class="t-btn t-btn-next" onclick="_tNext()" aria-label="Testimonio siguiente">&#8250;</button>
    </div>

    <!-- Dots -->
    <div style="text-align:center;margin-top:1.2rem;display:flex;justify-content:center;gap:8px;" id="tDots">
      <button class="t-dot active" onclick="_tGo(0)" aria-label="Testimonio 1"></button>
      <button class="t-dot"        onclick="_tGo(1)" aria-label="Testimonio 2"></button>
      <button class="t-dot"        onclick="_tGo(2)" aria-label="Testimonio 3"></button>
    </div>
  </div>
</section>

<style>
.testimonial-card {
    min-width: 100%;
    box-sizing: border-box;
    padding: 0 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.testimonial-card > * { max-width: 680px; width: 100%; }
.t-stars { color: #f59e0b; font-size: 1.3rem; margin-bottom: .6rem; }
.t-text {
    font-size: 1rem; line-height: 1.7; color: #374151;
    background: #fff; border-radius: 16px;
    padding: 1.4rem 1.6rem;
    box-shadow: 0 4px 18px rgba(79,70,229,.09);
    border-left: 4px solid #667eea;
    margin-bottom: 1.2rem;
    font-style: italic;
    position: relative;
}
.t-text::before { content: '\201C'; font-size: 3rem; color: #c7d2fe; line-height: 0; vertical-align: -.5em; margin-right: .2em; }
.t-author { display: flex; align-items: center; gap: .9rem; }
.t-avatar-img {
    width: 52px; height: 52px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    border: 3px solid #fff;
    box-shadow: 0 2px 10px rgba(79,70,229,.25);
}
.t-author strong { display: block; color: #1f2937; font-size: .95rem; }
.t-author span   { display: block; color: #6b7280; font-size: .8rem; }
.t-btn {
    position: absolute; top: 50%; transform: translateY(-50%);
    background: #fff; border: none; border-radius: 50%;
    width: 40px; height: 40px; font-size: 1.5rem; line-height: 1;
    box-shadow: 0 2px 10px rgba(0,0,0,.12); cursor: pointer; color: #4f46e5;
    display: flex; align-items: center; justify-content: center;
    padding: 0; z-index: 2;
}
.t-btn-prev { left: 0; }
.t-btn-next { right: 0; }
.t-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: #c7d2fe; border: none; cursor: pointer; padding: 0; transition: background .2s;
}
.t-dot.active { background: #4f46e5; }
@media (max-width: 576px) {
    .t-text { font-size: .9rem; padding: 1rem 1.1rem; }
    .t-btn  { width: 32px; height: 32px; font-size: 1.2rem; }
}
</style>

<script>
(function() {
    const TOTAL = 3;
    let _tIdx = 0, _tTimer;
    function _tRender() {
        const track = document.getElementById('testimonialsTrack');
        if (track) track.style.transform = 'translateX(-' + (_tIdx * 100) + '%)';
        document.querySelectorAll('.t-dot').forEach((d, i) => d.classList.toggle('active', i === _tIdx));
    }
    window._tGo   = function(i) { _tIdx = i; _tRender(); _tResetTimer(); };
    window._tNext = function()  { _tIdx = (_tIdx + 1) % TOTAL; _tRender(); _tResetTimer(); };
    window._tPrev = function()  { _tIdx = (_tIdx - 1 + TOTAL) % TOTAL; _tRender(); _tResetTimer(); };
    function _tResetTimer() { clearInterval(_tTimer); _tTimer = setInterval(window._tNext, 6000); }

    // Lazy-load: observar el sentinel (visible) en lugar de la sección oculta
    const section  = document.getElementById('testimonialsSection');
    const sentinel = document.getElementById('testimonialsSentinel');
    if (section && sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver(function(entries, obs) {
            if (entries[0].isIntersecting) {
                section.style.display = '';
                _tResetTimer();
                obs.disconnect();
            }
        }, { rootMargin: '300px' }).observe(sentinel);
    } else if (section) {
        section.style.display = '';
        _tResetTimer();
    }
})();
</script>

<!-- =========================================================
     FOOTER: Contacto + Redes Sociales
     ========================================================= -->
<footer class="py-4 mt-5" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;">
  <div class="container">
    <div class="row gy-3 align-items-center">

      <!-- Contacto -->
      <div class="col-12 col-md-4 text-center text-md-start">
        <strong class="d-block mb-1" style="font-size:1.1rem;">
          <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($storeName); ?>
        </strong>
        <?php if ($storeAddr): ?>
        <span class="d-block" style="font-size:.875rem;opacity:.9;">
          <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($storeAddr); ?>
        </span>
        <?php endif; ?>
        <?php if ($storeTel): ?>
        <a href="tel:+53<?php echo preg_replace('/\D/','',$storeTel); ?>"
           class="d-block text-white text-decoration-none" style="font-size:.875rem;opacity:.9;">
          <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($storeTel); ?>
        </a>
        <?php endif; ?>
      </div>

      <!-- Redes Sociales -->
      <div class="col-12 col-md-4 text-center">
        <p class="mb-2" style="font-size:.8rem;opacity:.8;text-transform:uppercase;letter-spacing:.05em;" data-i18n="footer.follow">Síguenos</p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
          <?php if ($fbUrl): ?>
          <a href="<?php echo htmlspecialchars($fbUrl); ?>" target="_blank" rel="noopener noreferrer"
             class="text-white" title="Facebook" aria-label="Síguenos en Facebook" style="font-size:1.6rem;">
            <i class="fab fa-facebook" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
          <?php if ($xUrl): ?>
          <a href="<?php echo htmlspecialchars($xUrl); ?>" target="_blank" rel="noopener noreferrer"
             class="text-white" title="X (Twitter)" aria-label="Síguenos en X (Twitter)" style="font-size:1.6rem;">
            <i class="fab fa-x-twitter" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
          <?php if ($igUrl): ?>
          <a href="<?php echo htmlspecialchars($igUrl); ?>" target="_blank" rel="noopener noreferrer"
             class="text-white" title="Instagram" aria-label="Síguenos en Instagram" style="font-size:1.6rem;">
            <i class="fab fa-instagram" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
          <?php if ($ytUrl): ?>
          <a href="<?php echo htmlspecialchars($ytUrl); ?>" target="_blank" rel="noopener noreferrer"
             class="text-white" title="YouTube" aria-label="Síguenos en YouTube" style="font-size:1.6rem;">
            <i class="fab fa-youtube" aria-hidden="true"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Sistema + selector de idioma -->
      <div class="col-12 col-md-4 text-center text-md-end">
        <div class="mb-2">
            <button class="btn btn-sm btn-outline-light lang-btn me-1" data-lang="es" onclick="setLang('es')">🇨🇺 ES</button>
            <button class="btn btn-sm btn-outline-light lang-btn" data-lang="en" onclick="setLang('en')">🇺🇸 EN</button>
        </div>
        <small style="opacity:.65;font-size:.72rem;"><?php echo htmlspecialchars($systemBrandName); ?> v3.0</small>
      </div>

    </div>
  </div>
</footer>

<!-- ── Campana de notificaciones push (tienda) ── -->
<div id="shopPushBell" style="position:fixed;bottom:116px;right:16px;z-index:9800;display:none;">
    <button id="shopBellBtn" onclick="handleShopBellClick()"
            style="width:46px;height:46px;border-radius:50%;border:none;font-size:1.1rem;
                   box-shadow:0 3px 12px rgba(0,0,0,.25);cursor:pointer;transition:.2s;
                   background:#0d6efd;color:#fff;"
            title="Activar notificaciones de ofertas">
        <i id="shopBellIcon" class="fas fa-bell-slash"></i>
    </button>
</div>

<script>
// ── Service Worker Tienda (scope exclusivo shop.php) ──
const SHOP_SCOPE_PATH = new URL('./', document.baseURI).pathname;
const SHOP_SW_URL = new URL('sw-shop.js', document.baseURI).toString();

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(SHOP_SW_URL, { scope: SHOP_SCOPE_PATH })
        .then(reg => { console.log('[Shop PWA] SW registrado, scope:', reg.scope); })
        .catch(err => console.warn('[Shop PWA] SW error:', err));
}

// ── MODO OFFLINE / RECONEXIÓN ──────────────────────────────────────────────
(function() {
    const offlineBanner = document.getElementById('shopOfflineBanner');

    function goOffline() {
        if (offlineBanner) offlineBanner.classList.remove('d-none');
        document.body.classList.add('shop-is-offline');
        // Renovar timestamp del cache para que la búsqueda siga funcionando offline
        // Forzar revalidación del catálogo al reconectar
        ensureCatalog(true).catch(() => {});
    }

    function goOnline() {
        if (offlineBanner) offlineBanner.classList.add('d-none');
        document.body.classList.remove('shop-is-offline');
        showToast(
            '<i class="fas fa-wifi me-2"></i>Conexión restaurada. ' +
            '<a href="javascript:location.reload()" class="text-white fw-bold text-decoration-underline">Actualizar catálogo</a>',
            10000
        );
    }

    window.addEventListener('offline', goOffline);
    window.addEventListener('online',  goOnline);

    if (!navigator.onLine) goOffline();
})();

// ── Network Quality Detection (Connection API) ────────────────────────────
(function() {
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (!conn) return;

    function applyConnectionMode() {
        const slow = conn.saveData || ['slow-2g','2g'].includes(conn.effectiveType);
        const medium = conn.effectiveType === '3g';

        if (slow) {
            document.body.classList.add('conn-slow');
            document.body.classList.remove('conn-medium');
            showToast('<i class="fas fa-signal me-2"></i>Modo ahorro activo: imágenes reducidas.', 4000);
        } else if (medium) {
            document.body.classList.add('conn-medium');
            document.body.classList.remove('conn-slow');
        } else {
            document.body.classList.remove('conn-slow','conn-medium');
        }
    }

    applyConnectionMode();
    conn.addEventListener('change', applyConnectionMode);
})();

// ── IndexedDB helper para cola de pedidos offline ─────────────────────────
const CheckoutQueue = {
    _db: null,
    async db() {
        if (this._db) return this._db;
        return new Promise((resolve, reject) => {
            const req = indexedDB.open('palweb-shop-checkout', 1);
            req.onupgradeneeded = e => e.target.result.createObjectStore('pending_checkouts', { keyPath: 'id', autoIncrement: true });
            req.onsuccess = e => { this._db = e.target.result; resolve(this._db); };
            req.onerror   = e => reject(e.target.error);
        });
    },
    async push(payload) {
        const db    = await this.db();
        const store = db.transaction('pending_checkouts','readwrite').objectStore('pending_checkouts');
        return new Promise((res, rej) => {
            const req = store.add({ payload, ts: Date.now() });
            req.onsuccess = () => res(req.result);
            req.onerror   = e => rej(e.target.error);
        });
    },
    async count() {
        const db    = await this.db();
        const store = db.transaction('pending_checkouts','readonly').objectStore('pending_checkouts');
        return new Promise((res, rej) => {
            const req = store.count();
            req.onsuccess = () => res(req.result);
            req.onerror   = e => rej(e.target.error);
        });
    }
};

// ── Background Sync: guardar pedido si la red falla ──────────────────────
async function queueCheckoutForSync(data) {
    try {
        await CheckoutQueue.push({ action: 'crear_venta_web', ...data });
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            const reg = await navigator.serviceWorker.ready;
            await reg.sync.register('checkout-retry');
            showToast('<i class="fas fa-clock me-2"></i>Sin conexión. Tu pedido se enviará automáticamente cuando vuelva la red.', 8000);
        } else {
            showToast('<i class="fas fa-exclamation-triangle me-2"></i>Sin conexión. Anota tu pedido y reintenta más tarde.', 8000);
        }
    } catch(e) {
        console.error('[CheckoutSync]', e);
        showToast('Error al guardar pedido offline.', 5000);
    }
}

// ── Push Notifications Tienda ─────────────────────────────────────────────
const SHOP_PUSH_TIPO  = 'cliente';
const SHOP_PUSH_CACHE = 'push-config-v1';
const SHOP_PUSH_API   = 'push_api.php';

function urlB64ToUint8ArrayShop(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function saveShopTipoToCache(tipo) {
    try {
        const cache = await caches.open(SHOP_PUSH_CACHE);
        await cache.put('push-tipo', new Response(tipo));
    } catch (e) {}
}

async function subscribeShopPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        alert('Tu navegador no soporta notificaciones push.');
        return;
    }
    try {
        const resp = await fetch(SHOP_PUSH_API + '?action=vapid_key');
        const { publicKey } = await resp.json();

        const reg = await navigator.serviceWorker.register(SHOP_SW_URL, { scope: SHOP_SCOPE_PATH });
        const swReg = await Promise.race([
            navigator.serviceWorker.ready,
            new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), 6000))
        ]);

        const sub = await swReg.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: urlB64ToUint8ArrayShop(publicKey)
        });

        await saveShopTipoToCache(SHOP_PUSH_TIPO);

        await fetch(SHOP_PUSH_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:       'subscribe',
                subscription: sub.toJSON(),
                tipo:         SHOP_PUSH_TIPO,
                device:       navigator.userAgent.substring(0, 100)
            })
        });

        updateShopBellUI('active');
    } catch (err) {
        if (err.name === 'NotAllowedError') {
            updateShopBellUI('denied');
        } else {
            console.error('[ShopPush] subscribe error:', err);
        }
    }
}

async function unsubscribeShopPush() {
    try {
        const reg = await navigator.serviceWorker.getRegistration(SHOP_SCOPE_PATH);
        if (!reg) return;
        const sub = await reg.pushManager.getSubscription();
        if (!sub) { updateShopBellUI('off'); return; }
        const endpoint = sub.endpoint;
        await sub.unsubscribe();
        await fetch(SHOP_PUSH_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'unsubscribe', endpoint })
        });
        updateShopBellUI('off');
    } catch (err) {
        console.error('[ShopPush] unsubscribe error:', err);
    }
}

function updateShopBellUI(state) {
    const btn  = document.getElementById('shopBellBtn');
    const icon = document.getElementById('shopBellIcon');
    if (!btn) return;
    if (state === 'active') {
        btn.style.background = '#198754'; icon.className = 'fas fa-bell';
        btn.title = 'Notificaciones de ofertas activas — clic para desactivar';
    } else if (state === 'denied') {
        btn.style.background = '#dc3545'; icon.className = 'fas fa-bell-slash';
        btn.title = 'Notificaciones bloqueadas por el sistema';
    } else {
        btn.style.background = '#0d6efd'; icon.className = 'fas fa-bell-slash';
        btn.title = 'Activar notificaciones de ofertas';
    }
}

async function handleShopBellClick() {
    if (Notification.permission === 'denied') {
        alert('Las notificaciones están bloqueadas. Actívalas en la configuración del navegador.');
        return;
    }
    const reg = await navigator.serviceWorker.getRegistration(SHOP_SCOPE_PATH);
    const sub = reg ? await reg.pushManager.getSubscription() : null;
    if (sub) {
        await unsubscribeShopPush();
    } else {
        await subscribeShopPush();
    }
}

async function initShopPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    const wrap = document.getElementById('shopPushBell');
    if (wrap) wrap.style.display = 'block';

    if (Notification.permission === 'denied') {
        updateShopBellUI('denied'); return;
    }
    try {
        const reg = await navigator.serviceWorker.getRegistration(SHOP_SCOPE_PATH);
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        updateShopBellUI(sub ? 'active' : 'off');
    } catch (e) {
        updateShopBellUI('off');
    }
}

document.addEventListener('DOMContentLoaded', () => { setTimeout(initShopPush, 1200); });

// ── Inicializar moneda y métodos de pago dinámicos ──
document.addEventListener('DOMContentLoaded', () => {
    renderShopPaymentMethods();
    setShopCurrency(shopCurrency);
});

// =========================================================
// Feature 13: FLY-TO-CART ANIMATION
// =========================================================
function flyToCart(srcX, srcY) {
    const cartBtn = document.querySelector('.cart-float');
    if (!cartBtn) return;
    const cartRect = cartBtn.getBoundingClientRect();
    const dot = document.createElement('div');
    dot.className = 'fly-dot';
    dot.style.left = (srcX - 7) + 'px';
    dot.style.top  = (srcY - 7) + 'px';
    document.body.appendChild(dot);
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            dot.style.left    = (cartRect.left + cartRect.width  / 2 - 7) + 'px';
            dot.style.top     = (cartRect.top  + cartRect.height / 2 - 7) + 'px';
            dot.style.opacity = '0';
            dot.style.transform = 'scale(0.2)';
        });
    });
    setTimeout(() => {
        dot.remove();
        cartBtn.classList.add('cart-bounce');
        setTimeout(() => cartBtn.classList.remove('cart-bounce'), 500);
    }, 620);
}

// =========================================================
// Feature 15: COMPARTIR PRODUCTO
// =========================================================
function openImgZoom() {
    const src = document.getElementById('detailImg').src;
    if (!src) return;
    const overlay = document.getElementById('imgZoomOverlay');
    document.getElementById('imgZoomSrc').src = src;
    overlay.classList.add('active');
    document.addEventListener('keydown', _zoomKeyClose);
}

function closeImgZoom() {
    document.getElementById('imgZoomOverlay').classList.remove('active');
    document.removeEventListener('keydown', _zoomKeyClose);
}

function _zoomKeyClose(e) { if (e.key === 'Escape') closeImgZoom(); }

function slugifyProductName(name) {
    return String(name || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'producto';
}

function buildShopListingUrl() {
    const url = new URL(location.origin + SHOP_BASE_PATH);
    const params = new URLSearchParams(location.search);
    params.delete('producto');
    const qs = params.toString();
    if (qs) {
        url.search = qs;
    }
    return url.toString();
}

function buildProductUrl(prod) {
    const slug = prod?.slug || slugifyProductName(prod?.name || prod?.nombre || prod?.code || prod?.codigo || 'producto');
    return location.origin + SHOP_PRETTY_BASE_PATH + '/' + encodeURIComponent(slug) + '/';
}

function syncProductUrl(prod) {
    if (!prod) return;
    const targetUrl = buildProductUrl(prod);
    if (location.href !== targetUrl) {
        history.replaceState({ product: prod.code || prod.id || '' }, '', targetUrl);
    }
}

function resetShopUrl() {
    const targetUrl = buildShopListingUrl();
    if (location.href !== targetUrl) {
        history.replaceState({}, '', targetUrl);
    }
}

function shareCurrentProduct() {
    if (!_currentDetailProduct) return;
    const prod = _currentDetailProduct;
    const url  = buildProductUrl(prod);
    const text = prod.name + ' — $' + parseFloat(prod.price || 0).toFixed(2);
    if (navigator.share) {
        navigator.share({ title: prod.name, text, url }).catch(() => {});
    } else {
        navigator.clipboard.writeText(url).then(() => showToast('🔗 Enlace copiado al portapapeles')).catch(() => {
            prompt('Copia este enlace:', url);
        });
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const detailModalEl = document.getElementById('modalDetail');
    if (detailModalEl) {
        detailModalEl.addEventListener('hidden.bs.modal', () => {
            _currentDetailProduct = null;
            resetShopUrl();
        });
    }
});

// Abrir producto desde URL bonita o desde ?producto=CODE
(function() {
    const params = new URLSearchParams(location.search);
    const code = INITIAL_PRODUCT_CODE || params.get('producto');
    if (!code) return;
    const list = getProductsFromCache() || [];
    const prod = list.find(p => p.codigo === code);
    if (prod) {
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => openProductDetail({
                id: prod.codigo, name: prod.nombre,
                price: prod.precioOferta > 0 && prod.precioOferta < prod.precio ? prod.precioOferta : prod.precio,
                precioOferta: prod.precioOferta || 0,
                desc: prod.descripcion || '', img: prod.imgUrl,
                bg: prod.bg, initials: prod.initials, hasImg: prod.hasImg,
                hasStock: prod.hasStock, stock: prod.stock, code: prod.codigo,
                slug: prod.slug || '',
                cat: prod.categoria, unit: prod.unidad_medida, color: prod.color,
                esReservable: prod.esReservable || false
            }), 600);
        });
    }
})();

// =========================================================
// Feature 1: AVÍSAME CUANDO LLEGUE (restock)
// =========================================================
function notifyRestock(codigo, nombre) {
    const modal = document.getElementById('restockAvisoSection');
    if (modal) {
        // Si el modal de detalle está abierto, scroll to aviso section
        modal.setAttribute('data-codigo', codigo);
        modal.style.display = 'block';
        modal.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        // Fallback prompt si se llama desde la card
        const tel = prompt(`Ingresa tu teléfono para avisar cuando "${nombre}" esté disponible:`);
        if (!tel) return;
        fetch('shop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action_restock_aviso: 1, codigo, nombre: 'Cliente', telefono: tel })
        }).then(() => showToast('🔔 ¡Te avisamos cuando llegue!')).catch(() => {});
    }
}

function submitRestock() {
    const section  = document.getElementById('restockAvisoSection');
    const codigo   = section?.getAttribute('data-codigo') || (_currentDetailProduct?.id ?? '');
    const nombre   = document.getElementById('avisoNombre')?.value.trim() || 'Cliente';
    const telefono = document.getElementById('avisoTelefono')?.value.trim() || '';
    if (!telefono) { showToast('Ingresa tu teléfono'); return; }
    fetch('shop.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action_restock_aviso: 1, codigo, nombre, telefono })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'ok') {
            showToast('🔔 ¡Te avisamos cuando llegue!', 4000);
            if (section) section.style.display = 'none';
        } else {
            showToast('Error: ' + (res.error || 'inténtalo de nuevo'));
        }
    })
    .catch(() => showToast('Error al registrar aviso'));
}

// =========================================================
// Feature 12: CARRITO ABANDONADO — banner de recuperación
// =========================================================
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('palweb_cart');
    if (!saved) return;
    let items;
    try { items = JSON.parse(saved); } catch(e) { return; }
    if (!Array.isArray(items) || items.length === 0) return;

    // Mostrar banner sólo si hay productos guardados Y el carrito actual está vacío
    if (cart.length > 0) return; // ya se restauró

    const container = document.querySelector('.container');
    if (!container) return;
    const total = items.reduce((s, i) => s + (i.price * i.qty), 0);
    const banner = document.createElement('div');
    banner.className = 'abandoned-banner mt-3';
    banner.innerHTML = `
        <span class="ab-msg">🛒 Tienes ${items.length} artículo(s) guardados por $${total.toFixed(2)} CUP</span>
        <button class="btn btn-warning btn-sm fw-bold" onclick="restoreAbandonedCart(this.closest('.abandoned-banner'))">
            Ver carrito
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="this.closest('.abandoned-banner').remove(); localStorage.removeItem('palweb_cart');">
            Descartar
        </button>`;
    container.insertBefore(banner, container.firstChild);
});

function restoreAbandonedCart(banner) {
    if (banner) banner.remove();
    openCart();
}
</script>

</main><!-- /#main-content -->
</body>
</html>
