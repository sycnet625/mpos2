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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com data:; connect-src 'self' https://www.google-analytics.com https://analytics.google.com https://www.googletagmanager.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
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

require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
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
$storeName = $config['tienda_nombre'] ?? 'Marinero POS';
$storeAddr = $config['direccion'] ?? 'La Habana, Cuba';
$storeTel  = $config['telefono'] ?? '';
$fbUrl     = $config['facebook_url'] ?? '';
$xUrl      = $config['twitter_url'] ?? '';
$igUrl     = $config['instagram_url'] ?? '';
$ytUrl     = $config['youtube_url'] ?? '';
$siteUrl   = $config['sitio_web'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'palweb.net') . '/marinero/shop.php');

$metaTitle = $storeName . " | Tienda Online en La Habana – Productos Frescos y Entrega a Domicilio";
$metaDesc  = "Bienvenido a " . $storeName . ", tu tienda online en La Habana. Compra productos de calidad con entrega a domicilio en toda La Habana. Pedido fácil, rápido y seguro. ¡Haz tu pedido ahora!";
$metaImg   = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'palweb.net') . '/marinero/icon-512.png';


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
        $sql = "SELECT p.codigo, p.nombre, p.precio, p.descripcion, p.categoria, p.unidad_medida, p.color, p.es_reservable,
                COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s
                WHERE s.id_producto = p.codigo AND s.id_almacen = ?), 0) as stock
                FROM productos p
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
            $ALM_ID, $EMP_ID, $SUC_ID,          
            $searchPattern, $searchPattern, $searchPattern,   
            $q, $q, $searchStart      
        ]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as &$r) {
            $r['precio'] = floatval($r['precio']);
            $r['stock'] = floatval($r['stock']);
            $r['hasStock']     = $r['stock'] > 0;
            $r['esReservable'] = intval($r['es_reservable'] ?? 0) === 1;
            $b = __DIR__ . '/../product_images/' . $r['codigo'];
            $r['hasImg'] = file_exists($b.'.avif') || file_exists($b.'.webp') || file_exists($b.'.jpg');
            $r['imgUrl'] = $r['hasImg'] ? "image.php?code=" . urlencode($r['codigo']) : null;
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
$localImgPath = '/home/marinero/product_images/';

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
            (SELECT COALESCE(SUM(s.cantidad), 0)
             FROM stock_almacen s
             WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock_total,
            (SELECT ROUND(AVG(r.rating),1) FROM resenas_productos r WHERE r.producto_codigo = p.codigo AND r.aprobada = 1) as avg_rating,
            (SELECT COUNT(*) FROM resenas_productos r WHERE r.producto_codigo = p.codigo AND r.aprobada = 1) as total_resenas
            FROM productos p
            WHERE p.activo = 1 
              AND p.es_web = 1 
              AND p.id_empresa = ?
              AND (p.sucursales_web = '' OR p.sucursales_web IS NULL OR FIND_IN_SET(?, p.sucursales_web) > 0)";

    $params = [$ALM_ID, $EMP_ID, $SUC_ID];
    
    if ($catFilter) {
        $sql .= " AND p.categoria = ?";
        $params[] = $catFilter;
    }

    $sortMap = [
        'categoria_asc' => 'p.categoria ASC, p.nombre ASC',
        'price_asc' => 'p.precio ASC',
        'price_desc' => 'p.precio DESC',
        'popular' => '(SELECT COUNT(*) FROM vistas_productos v WHERE v.codigo_producto = p.codigo) DESC, p.nombre ASC',
    ];
    
    $sql .= " ORDER BY " . ($sortMap[$sort] ?? 'p.categoria ASC, p.nombre ASC');
    $sql .= " LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            if (intval($input_client_api['captcha_ans']) !== intval($input_client_api['captcha_val'])) {
                throw new Exception("Captcha incorrecto. Eres un robot?");
            }
            
            $hash = password_hash($input_client_api['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO clientes_tienda (nombre, telefono, password_hash, direccion) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input_client_api['nombre'], $input_client_api['telefono'], $hash, $input_client_api['direccion'] ?? '']);
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
if (isset($_POST['update_cart_tracker'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    try {
        $stmt = $pdo->prepare("INSERT INTO carritos_abandonados (session_id, items_json, total) VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE items_json = ?, total = ?, fecha_actualizacion = NOW()");
        $stmt->execute([session_id(), json_encode($input['items']), $input['total'], json_encode($input['items']), $input['total']]);
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

// ── Build compact product list for client-side JS cache (Feature 11) ──
$productsJs = [];
foreach ($productos as $_p) {
    $_b = $localImgPath . $_p['codigo'];
    $_hasImg = false; $_pImgUrl = null;
    foreach (['.avif','.webp','.jpg'] as $_e) {
        if (file_exists($_b.$_e)) { $_hasImg = true; $_pImgUrl = 'image.php?code='.urlencode($_p['codigo']); break; }
    }
    $productsJs[] = [
        'codigo'       => $_p['codigo'],
        'nombre'       => $_p['nombre'],
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
    ];
}

ob_end_flush();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo htmlspecialchars($metaTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDesc); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($siteUrl); ?>">

    <!-- Open Graph / Facebook -->
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?php echo htmlspecialchars($siteUrl); ?>">
    <meta property="og:site_name"   content="<?php echo htmlspecialchars($storeName); ?>">
    <meta property="og:title"       content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDesc); ?>">
    <meta property="og:image"       content="<?php echo htmlspecialchars($metaImg); ?>">
    <meta property="og:image:width"  content="512">
    <meta property="og:image:height" content="512">
    <?php if ($fbUrl): ?><meta property="og:see_also" content="<?php echo htmlspecialchars($fbUrl); ?>"><?php endif; ?>

    <!-- Twitter / X Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?php echo htmlspecialchars($metaTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($metaDesc); ?>">
    <meta name="twitter:image"       content="<?php echo htmlspecialchars($metaImg); ?>">

    <!-- Structured Data: LocalBusiness -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "LocalBusiness",
      "name": "<?php echo addslashes($storeName); ?>",
      "url": "<?php echo addslashes($siteUrl); ?>",
      "image": "<?php echo addslashes($metaImg); ?>",
      "description": "<?php echo addslashes($metaDesc); ?>",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "<?php echo addslashes($storeAddr); ?>",
        "addressLocality": "La Habana",
        "addressCountry": "CU"
      },
      "telephone": "<?php echo addslashes($storeTel); ?>"
      <?php if ($fbUrl): ?>,"sameAs": [
        "<?php echo addslashes($fbUrl); ?>"
        <?php if ($xUrl): ?>, "<?php echo addslashes($xUrl); ?>"<?php endif; ?>
        <?php if ($igUrl): ?>, "<?php echo addslashes($igUrl); ?>"<?php endif; ?>
        <?php if ($ytUrl): ?>, "<?php echo addslashes($ytUrl); ?>"<?php endif; ?>
      ]<?php endif; ?>
    }
    </script>

    <!-- PWA Tienda -->
    <meta name="theme-color" content="#0d6efd">
    <link rel="manifest" href="manifest-shop.php">
    <link rel="apple-touch-icon" href="icon-192.png">

    <!-- Performance: preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.googleapis.com">

    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
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

        .promo-carousel {
            margin: 2rem 0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        
        .promo-slide {
            height: 93px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 1rem;
            text-align: center;
            color: white;
        }
        
        .promo-slide h2 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }
        
        .promo-slide p {
            font-size: 0.75rem;
            font-weight: 500;
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
            height: 240px;
            overflow: hidden;
            background: #f3f4f6;
        }
        
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
            .promo-slide { height: 80px; }
            .promo-slide h2 { font-size: 1rem; }
            .promo-slide p { font-size: 0.65rem; }
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

        /* Thumbnails galería detalle producto */
        .detail-thumb {
            width: 56px; height: 56px; object-fit: cover;
            border-radius: 8px; cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s, transform 0.15s, opacity 0.15s;
            opacity: 0.65;
        }
        .detail-thumb:hover { transform: scale(1.1); opacity: 1; }
        .detail-thumb.active { border-color: #0d6efd; opacity: 1; box-shadow: 0 0 0 3px rgba(13,110,253,0.25); }

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
    </style>
</head>
<body>

<nav class="navbar-premium">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between w-100 flex-wrap gap-3">
            <a href="shop.php" class="navbar-brand-premium">
                <i class="fas fa-store"></i>
                <span>PalWeb Shop</span>
                <span class="badge-sucursal"><?= htmlspecialchars($config['tienda_nombre']) ?> (Suc <?= $SUC_ID ?>)</span>
            </a>

            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="toggleTrackingModal()">
                    <i class="fas fa-truck me-1"></i> <span class="d-none d-md-inline">Rastreo</span>
                </button>
                <a href="como_comprar.php" class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-bold" title="¿Cómo comprar?">
                    <i class="fas fa-question-circle me-1"></i> <span class="d-none d-md-inline">Ayuda</span>
                </a>
                <a href="quienes_somos.php" class="btn btn-outline-info btn-sm rounded-pill px-3 fw-bold" title="Quiénes Somos">
                    <i class="fas fa-building me-1"></i> <span class="d-none d-md-inline">Nosotros</span>
                </a>

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
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal()"><i class="fas fa-user-edit me-2 text-primary"></i>Mi Perfil</a></li>
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal(); setTimeout(()=>document.getElementById('orderHistoryContent')?.scrollIntoView({behavior:'smooth'}),400)"><i class="fas fa-box-open me-2 text-warning"></i>Mis Pedidos</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" onclick="logoutClient()"><i class="fas fa-sign-out-alt me-2 text-danger"></i>Salir</a></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <button class="btn btn-light btn-sm rounded-pill px-3" onclick="toggleAuthModal()">
                            <i class="fas fa-user me-1"></i> Mi Cuenta
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="search-wrapper w-100 mt-2">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar productos..." autocomplete="off">
                <div id="searchResults" class="search-results"></div>
            </div>
        </div>
    </div>
</nav>

<div class="container">
    <div id="promoCarousel" class="carousel slide promo-carousel" data-bs-ride="carousel">
        <div class="carousel-inner">
            <div class="carousel-item active">
                <div class="promo-slide gradient-1">
                    <h2>🚀 Envíos a La Habana</h2>
                    <p>Calcula el costo a tu barrio</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-2">
                    <h2>🍰 Dulces Frescos</h2>
                    <p>Hechos cada mañana</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-3">
                    <h2>🛒 Ofertas Semanales</h2>
                    <p>Grandes descuentos</p>
                </div>
            </div>
            <div class="carousel-item">
                <div class="promo-slide gradient-4">
                    <h2>🍕 Comida Caliente</h2>
                    <p>Lista para llevar</p>
                </div>
            </div>
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
                <h5 class="mb-3 mb-md-0 fw-bold">
                    <i class="fas fa-filter me-2"></i>Categorías
                </h5>
            </div>
            <div class="col-md-6 text-md-end">
                <label for="sortSelect" class="visually-hidden">Ordenar productos</label>
                <select id="sortSelect" class="form-select d-inline-block w-auto" onchange="location.href='?cat=<?= urlencode($catFilter) ?>&sort='+this.value">
                    <option value="categoria_asc" <?= $sort==='categoria_asc'?'selected':'' ?>>Ordenar: Categoría</option>
                    <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>Precio: Menor a Mayor</option>
                    <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Precio: Mayor a Menor</option>
                    <option value="popular" <?= $sort==='popular'?'selected':'' ?>>🔥 Más populares</option>
                </select>
            </div>
        </div>
        
        <div class="category-pills">
            <a href="?sort=<?= htmlspecialchars($sort) ?>" class="cat-pill <?= empty($catFilter)?'active':'' ?>">
                <i class="fas fa-th me-1"></i> Todas
            </a>
            <?php foreach($cats as $c): ?>
                <a href="?cat=<?= urlencode($c) ?>&sort=<?= htmlspecialchars($sort) ?>" 
                   class="cat-pill <?= $c===$catFilter?'active':'' ?>">
                    <?= htmlspecialchars($c) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0">Productos Disponibles</h4>
        <span class="badge bg-primary" style="font-size: 1rem; padding: 0.5rem 1rem;">
            <?= count($productos) ?> productos
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
        
        <?php foreach($productos as $p):
            $b = $localImgPath . $p['codigo'];
            $hasImg = false; $imgV = '';
            foreach (['.avif','.webp','.jpg'] as $_e) {
                if (file_exists($b.$_e)) { $hasImg = true; $imgV = '&v='.filemtime($b.$_e); break; }
            }
            $imgUrl = $hasImg ? 'image.php?code=' . urlencode($p['codigo']) . $imgV : null;
            $b1 = $localImgPath . $p['codigo'] . '_extra1';
            $hasExtra1 = false; $imgV1 = '';
            foreach (['.avif','.webp','.jpg'] as $_e) {
                if (file_exists($b1.$_e)) { $hasExtra1 = true; $imgV1 = '&v='.filemtime($b1.$_e); break; }
            }
            $b2 = $localImgPath . $p['codigo'] . '_extra2';
            $hasExtra2 = false; $imgV2 = '';
            foreach (['.avif','.webp','.jpg'] as $_e) {
                if (file_exists($b2.$_e)) { $hasExtra2 = true; $imgV2 = '&v='.filemtime($b2.$_e); break; }
            }
            $stock = floatval($p['stock_total'] ?? 0);
            $hasStock = $stock > 0;
            $esReservable = intval($p['es_reservable'] ?? 0) === 1;
            $bg = "#" . substr(md5($p['nombre']), 0, 6);
            $initials = mb_strtoupper(mb_substr($p['nombre'], 0, 2));
        ?>
        <div class="product-card" onclick='openProductDetail(<?= json_encode([
            "id"          => $p['codigo'],
            "name"        => $p['nombre'],
            "price"       => floatval($p['precio']),
            "desc"        => $p['descripcion'] ?? '',
            "img"         => $imgUrl,
            "imgExtra1"   => $hasExtra1 ? 'image.php?code=' . urlencode($p['codigo'] . '_extra1') . $imgV1 : null,
            "imgExtra2"   => $hasExtra2 ? 'image.php?code=' . urlencode($p['codigo'] . '_extra2') . $imgV2 : null,
            "bg"          => $bg,
            "initials"    => $initials,
            "hasImg"      => $hasImg,
            "hasStock"    => $hasStock,
            "stock"       => $stock,
            "code"        => $p['codigo'],
            "cat"         => $p['categoria'],
            "unit"        => $p['unidad_medida'] ?? '',
            "color"       => $p['color'] ?? '',
            "esReservable"  => $esReservable,
            "precioOferta"  => floatval($p['precio_oferta'] ?? 0),
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

                <?php 
                $umbralBajo = ($p['stock_minimo'] > 0) ? ($p['stock_minimo'] * 1.5) : 5;
                if($hasStock && $stock <= $umbralBajo): ?>
                    <div class="stock-alert-badge">
                        <i class="fas fa-fire-alt me-1"></i> ¡Solo <?php echo $stock; ?> disponibles!
                    </div>
                <?php endif; ?>

                <?php if($hasImg): ?>
                    <picture>
                        <source type="image/avif" srcset="<?= $imgUrl ?>&amp;fmt=avif">
                        <source type="image/webp" srcset="<?= $imgUrl ?>&amp;fmt=webp">
                        <img src="<?= $imgUrl ?>&amp;fmt=jpg"
                             class="product-image lazy-img"
                             alt="<?= htmlspecialchars($p['nombre']) ?>"
                             loading="lazy"
                             onload="this.classList.add('loaded')">
                    </picture>
                <?php else: ?>
                    <div class="product-placeholder" style="background: <?= $bg ?>cc"><?= $initials ?></div>
                <?php endif; ?>
                
                <?php if ($hasStock): ?>
                <div class="stock-badge in-stock">✓ Disponible</div>
                <?php elseif ($esReservable): ?>
                <div class="stock-badge" style="background:rgba(255,193,7,0.92);color:#1a1a1a;">📅 Reservable</div>
                <?php else: ?>
                <div class="stock-badge out-of-stock">✗ Agotado</div>
                <?php endif; ?>
            </div>

            <div class="product-body">
                <div class="product-category"><?= htmlspecialchars($p['categoria'] ?? 'General') ?></div>
                <h6 class="product-name"><?= htmlspecialchars($p['nombre']) ?></h6>

                <?php if (floatval($p['avg_rating'] ?? 0) > 0): ?>
                <div class="card-stars">
                    <?php
                    $avg = floatval($p['avg_rating']);
                    for ($s = 1; $s <= 5; $s++) {
                        if ($s <= floor($avg)) echo '<i class="fas fa-star"></i>';
                        elseif ($s - $avg < 1) echo '<i class="fas fa-star-half-alt"></i>';
                        else echo '<i class="far fa-star"></i>';
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
                            + Agregar
                        </button>
                    <?php elseif ($esReservable): ?>
                        <button class="btn-add-cart" style="background:#f59e0b;border-color:#f59e0b;font-size:.78rem;"
                                onclick="event.stopPropagation(); addToCart('<?= $p['codigo'] ?>', '<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= $p['precio'] ?>, true)">
                            📅 Reservar
                        </button>
                    <?php else: ?>
                        <button class="btn-add-cart" style="background:#6c757d;border-color:#6c757d;font-size:.76rem;"
                                onclick="event.stopPropagation(); this.closest('.product-card').click()">
                            🔔 Avísame
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<button class="cart-float" onclick="openCart()" aria-label="Ver carrito de compras">
    <i class="fas fa-shopping-cart" aria-hidden="true"></i>
    <span id="cartBadge" class="cart-badge d-none" aria-live="polite">0</span>
</button>

<div class="floating-container">
    <div class="float-btn btn-chat" onclick="toggleChat()" role="button" tabindex="0"
         aria-label="Abrir chat de soporte" onkeydown="if(event.key==='Enter'||event.key===' ')toggleChat()">
        <i class="fas fa-comments" aria-hidden="true"></i>
        <div class="chat-badge-notify" id="clientChatBadge" aria-live="polite"></div>
    </div>

    <a href="https://wa.me/5352783083?text=deseo%20mas%20informacion%20web" target="_blank"
       class="float-btn btn-whatsapp" aria-label="Contactar por WhatsApp" rel="noopener noreferrer">
        <i class="fab fa-whatsapp" aria-hidden="true"></i>
    </a>
</div>

<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <span><i class="fas fa-headset me-2"></i> Soporte en Línea</span>
        <button type="button" class="btn-close btn-close-white" onclick="toggleChat()" aria-label="Cerrar chat"></button>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="text-center text-muted small mt-5">
            <i class="fas fa-comment-dots fa-3x mb-3 text-secondary opacity-50"></i><br>
            ¡Hola! 👋<br>Escribe tu consulta.<br>Si no estamos, deja un mensaje.
        </div>
    </div>
    <div class="chat-footer">
        <input type="text" id="chatInput" class="form-control" placeholder="Escribe aquí..." onkeypress="if(event.key==='Enter') sendClientMsg()">
        <button class="btn btn-primary" onclick="sendClientMsg()" aria-label="Enviar mensaje"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
    </div>
</div>

<div class="modal fade" id="modalDetail" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content overflow-hidden" style="border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="z-index: 5;" aria-label="Cerrar detalle del producto"></button>
            </div>
            <div class="modal-body pt-0">
                <div class="row g-4">
                    <div class="col-md-5 d-flex flex-column align-items-center justify-content-center bg-light rounded-3 p-3 position-relative" style="min-height: 250px;">
                         <img id="detailImg" class="d-none img-fluid rounded shadow-sm" style="max-height: 210px; object-fit: contain;">
                         <div id="detailPlaceholder" class="d-none rounded shadow-sm d-flex align-items-center justify-content-center text-white fw-bold display-4" style="width: 150px; height: 150px;"></div>
                         <span id="detailStockBadge" class="position-absolute top-0 start-0 m-3 badge rounded-pill"></span>
                         <div id="detailThumbs" class="justify-content-center flex-wrap gap-2 mt-3" style="display:none;"></div>
                    </div>

                    <div class="col-md-7 d-flex flex-column" style="max-height:70vh;overflow-y:auto;padding-right:4px;">
                        <small class="text-uppercase text-muted fw-bold mb-1" id="detailCat">Categoría</small>
                        <h3 class="fw-bold mb-2" id="detailName">Nombre Producto</h3>
                        
                        <div class="d-flex align-items-center mb-2">
                            <div class="me-3">
                                <span id="detailPriceOriginal" class="price-original" style="display:none"></span>
                                <h2 class="text-primary fw-bold mb-0 d-inline" id="detailPrice">$0.00</h2>
                            </div>
                            <small class="text-muted" id="detailUnit"></small>
                            <span id="detailAvgStars" class="ms-auto text-warning small fw-bold"></span>
                        </div>

                        <!-- Variantes -->
                        <div id="variantSection" class="mb-3" style="display:none">
                            <p class="mb-1 small text-uppercase text-muted fw-bold" style="letter-spacing:.5px">Presentación</p>
                            <div id="variantButtons" class="d-flex flex-wrap gap-2"></div>
                        </div>

                        <div class="row g-2 mb-3 small text-muted border-top border-bottom py-2">
                            <div class="col-6">
                                <i class="fas fa-barcode me-1"></i> SKU: <span id="detailSku" class="fw-bold text-dark"></span>
                            </div>
                            <div class="col-6" id="divDetailColor">
                                <i class="fas fa-palette me-1"></i> Color: <span id="detailColor" class="fw-bold text-dark"></span>
                            </div>
                        </div>

                        <div class="mb-4 flex-grow-1" style="max-height: 200px; overflow-y: auto;">
                            <h6 class="fw-bold mb-1">Descripción</h6>
                            <p class="text-secondary" id="detailDesc" style="white-space: pre-line;">Sin descripción.</p>
                        </div>

                        <div class="mt-auto">
                            <button type="button" id="btnAddDetail" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-pill">
                                <i class="fas fa-cart-plus me-2"></i> AGREGAR AL CARRITO
                            </button>
                            <!-- Feature 1: restock aviso (visible sólo cuando agotado) -->
                            <div id="restockAvisoSection" style="display:none" class="aviso-form-wrap mt-2">
                                <p class="small text-muted mb-2"><i class="fas fa-bell me-1 text-warning"></i>Recibe un aviso cuando llegue:</p>
                                <div class="row g-1">
                                    <div class="col-5"><input type="text" id="avisoNombre" class="form-control form-control-sm" placeholder="Tu nombre"></div>
                                    <div class="col-5"><input type="tel" id="avisoTelefono" class="form-control form-control-sm" placeholder="Teléfono"></div>
                                    <div class="col-2"><button class="btn btn-warning btn-sm w-100" onclick="submitRestock()"><i class="fas fa-bell"></i></button></div>
                                </div>
                            </div>
                            <!-- Feature 15: share -->
                            <div class="text-center mt-2">
                                <button class="btn btn-outline-secondary btn-sm px-3" onclick="shareCurrentProduct()">
                                    <i class="fas fa-share-alt me-1"></i>Compartir
                                </button>
                            </div>
                        </div>

                        <!-- ── Sección de reseñas ── -->
                        <hr class="mt-3 mb-2">
                        <div id="reviewsSection">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="fw-bold mb-0" style="font-size:.9rem">
                                    <i class="fas fa-star text-warning me-1"></i>Opiniones
                                </h6>
                                <span id="reviewsAvgDisplay" class="small text-muted"></span>
                            </div>
                            <div id="reviewsList" style="max-height:160px;overflow-y:auto;"></div>
                            <div id="reviewFormWrap" class="mt-2"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCart" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; border: none;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2"></i>Tu Carrito</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar carrito"></button>
            </div>

            <div id="cartItemsView">
                <div class="modal-body">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Cantidad</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody"></tbody>
                    </table>
                    <div class="text-end fs-3 fw-bold">Total: $<span id="cartTotal">0.00</span></div>
                </div>
                <div class="modal-footer border-0 flex-column gap-2">
                    <div id="reservaCartNotice" class="alert alert-warning py-2 px-3 w-100 mb-0 small" style="display:none;">
                        📅 Tu carrito incluye productos <strong>de reserva</strong> (sin stock). Usa <strong>Reservar</strong> para procesarlos.
                    </div>
                    <div class="d-flex w-100 gap-2">
                        <button class="btn btn-outline-danger" onclick="clearCart()">
                            <i class="fas fa-trash me-1"></i> Vaciar
                        </button>
                        <button class="btn btn-outline-primary flex-fill" onclick="iniciarFlujo('reserva')">
                            📅 Reservar
                            <small class="d-block" style="font-size:.7rem;opacity:.8">Sin stock OK</small>
                        </button>
                        <button class="btn btn-success flex-fill" onclick="iniciarFlujo('compra')">
                            💳 Pagar Ahora
                            <small class="d-block" style="font-size:.7rem;opacity:.8">Solo con stock</small>
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="checkoutView" style="display:none;">
                <form onsubmit="submitOrder(event)">
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <h5 class="fw-bold mb-4"><i class="fas fa-user me-2"></i>Datos del Cliente</h5>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre Completo *</label>
                            <input type="text" id="cliName" class="form-control" placeholder="Ej: Juan Pérez" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Teléfono *</label>
                            <input type="tel" id="cliTel" class="form-control" placeholder="Ej: +53 5555-5555" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Dirección *</label>
                            <textarea id="cliDir" class="form-control" rows="2" placeholder="Calle, número, entre calles..." required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha de Entrega/Recogida *</label>
                            <input type="date" id="cliDate" class="form-control" required onchange="updateSlotsAvailability()">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                <i class="fas fa-clock me-1 text-primary"></i>Horario de Entrega *
                            </label>
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
                            <div id="slotErrorMsg" class="text-danger small mt-1" style="display:none;">
                                <i class="fas fa-exclamation-circle me-1"></i>Selecciona un horario de entrega.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Notas Adicionales</label>
                            <textarea id="cliNotes" class="form-control" rows="3" placeholder="Ej: El cake de relleno chocolate y color rosado el merengue. Entregar antes de las 3 PM."></textarea>
                            <small class="text-muted">Especifica detalles importantes como colores, sabores, horarios, etc.</small>
                        </div>
                        
                        <div class="form-check mb-3" style="padding: 1rem; background: #f8f9fa; border-radius: 12px;">
                            <input class="form-check-input" type="checkbox" id="delHome" onchange="toggleDelivery()">
                            <label class="form-check-label fw-bold" for="delHome">
                                <i class="fas fa-truck me-2"></i>Entrega a domicilio
                            </label>
                        </div>
                        
                        <div id="deliveryBox" style="display:none; background: #f8f9fa; padding: 1.5rem; border-radius: 12px; margin-bottom: 1rem;">
                            <h6 class="fw-bold mb-3"><i class="fas fa-map-marker-alt me-2"></i>Ubicación de Entrega</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="cMun">Municipio</label>
                                    <select id="cMun" class="form-control" onchange="loadBarrios()">
                                        <option value="">Seleccione municipio...</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="cBar">Barrio</label>
                                    <select id="cBar" class="form-control" onchange="calcShip()">
                                        <option value="">Seleccione barrio...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 p-4" style="background: #f8f9fa; border-radius: 12px;">
                            <h6 class="fw-bold mb-3">Resumen del Pedido</h6>
                            <div class="d-flex justify-content-between py-2">
                                <span>Subtotal Productos:</span>
                                <span class="fw-bold">$<span id="cartTotal2">0.00</span></span>
                            </div>
                            <div class="d-flex justify-content-between py-2">
                                <span>Costo de Envío:</span>
                                <span class="fw-bold">$<span id="shipCost">0.00</span></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between fs-4">
                                <span class="fw-bold">TOTAL A PAGAR:</span>
                                <span class="fw-bold" style="color: var(--primary);">$<span id="grandTotal">0.00</span></span>
                            </div>
                        </div>

                        <!-- SECCIÓN DE PAGO (solo para flujo 'compra') -->
                        <div id="paymentSection" style="display:none; margin-top:1rem;">
                            <div class="p-3 border rounded-3">
                                <h6 class="fw-bold mb-3"><i class="fas fa-credit-card me-2 text-primary"></i>Método de Pago</h6>
                                <div id="shopPaymentMethodsContainer">
                                    <!-- Renderizado dinámico por renderShopPaymentMethods() -->
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary" onclick="showCartView()">
                            <i class="fas fa-arrow-left me-1"></i> Volver
                        </button>
                        <button type="submit" id="btnConfirmarPedido" class="btn btn-success btn-lg px-4">
                            <i class="fas fa-check-circle me-2"></i> Confirmar Pedido
                        </button>
                    </div>
                </form>
            </div>
            
            <div id="successView" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div style="font-size: 5rem; margin-bottom: 1rem;">✅</div>
                    <h3 class="fw-bold mb-3">¡Pedido Recibido!</h3>
                    <p class="text-muted fs-5">Gracias por tu compra. Te contactaremos pronto para confirmar tu orden.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-primary btn-lg px-5" data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
                </div>
            </div>

            <!-- Vista: esperando confirmación de transferencia -->
            <div id="waitingPaymentView" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div style="font-size: 4rem; margin-bottom: 1rem; animation: spin 2s linear infinite; display:inline-block;">⏳</div>
                    <h4 class="fw-bold mb-3">Verificando tu transferencia...</h4>
                    <p class="text-muted">El operador confirmará tu pago en breve.<br>Esta pantalla se actualizará automáticamente.</p>
                    <div class="mt-3 p-3 bg-light rounded">
                        <small class="text-muted">Código enviado: <strong id="codigoPagoDisplay"></strong></small>
                    </div>
                </div>
            </div>

            <!-- Vista: pago confirmado -->
            <div id="pagoConfirmadoView" style="display:none;">
                <div class="modal-body text-center py-4">
                    <div style="font-size: 4.5rem; margin-bottom: 0.5rem;">🎉</div>
                    <h3 class="fw-bold mb-1 text-success">¡Pago Confirmado!</h3>
                    <p class="text-muted mb-3">Tu transferencia fue verificada exitosamente.</p>
                    <div class="bg-success bg-opacity-10 border border-success rounded-3 px-4 py-3 mb-3 mx-auto" style="max-width:320px;">
                        <div class="text-muted small mb-1">Número de pedido</div>
                        <div class="fw-bold text-success" style="font-size:2rem; letter-spacing:2px;">#<span id="confirmadoPedido">—</span></div>
                        <div class="text-muted small mt-2">Guarda este número para consultar el estado de tu pedido.</div>
                    </div>
                    <p class="fs-5 fw-bold text-dark mb-0">¡Gracias, <span id="confirmadoNombre">Cliente</span>!</p>
                    <p class="text-muted small">Tu pedido está en proceso. Te avisaremos cuando esté listo.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-success btn-lg px-5" data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
                </div>
            </div>

            <!-- Vista: pago rechazado -->
            <div id="pagoRechazadoView" style="display:none;">
                <div class="modal-body text-center py-5">
                    <div style="font-size: 5rem; margin-bottom: 1rem;">❌</div>
                    <h3 class="fw-bold mb-3 text-danger">Transferencia Rechazada</h3>
                    <p class="text-muted fs-5">El operador no pudo verificar tu transferencia.<br>Por favor contáctanos para más información.</p>
                    <div id="motivoRechazoDiv" class="alert alert-warning mt-3" style="display:none;">
                        <strong>Motivo:</strong> <span id="motivoRechazoTxt"></span>
                    </div>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2">
                    <button class="btn btn-outline-secondary btn-lg px-4" data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
                    <button class="btn btn-primary btn-lg px-4" onclick="showCheckout()">Intentar de nuevo</button>
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
                <h5 class="modal-title fw-bold"><i class="fas fa-truck me-2"></i>Seguimiento de Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar seguimiento"></button>
            </div>
            <div class="modal-body p-4">
                <div class="input-group mb-3 shadow-sm">
                    <input type="text" id="trackInput" class="form-control border-0 bg-light p-3" placeholder="Nº Pedido o Teléfono...">
                    <button class="btn btn-primary px-4" onclick="searchTrack()" aria-label="Buscar pedido">
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                </div>
                <div id="trackResults" class="mt-4">
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        Ingresa tus datos para ver el estado de tus compras recientes.
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
                <h5 class="modal-title fw-bold"><i class="fas fa-user-circle me-2" aria-hidden="true"></i>Mi Perfil de Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar perfil"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="col-md-4 bg-light p-4 border-end">
                        <h6 class="fw-bold mb-3 text-uppercase small text-muted">Mis Datos</h6>
                        <div class="mb-3">
                            <label class="small fw-bold">Nombre</label>
                            <input type="text" id="profNom" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold">Teléfono</label>
                            <input type="text" id="profTel" class="form-control form-control-sm" disabled>
                        </div>
                        <div class="mb-4">
                            <label class="small fw-bold">Dirección</label>
                            <textarea id="profDir" class="form-control form-control-sm" rows="3"></textarea>
                        </div>
                        <button class="btn btn-primary btn-sm w-100 fw-bold" onclick="updateProfile()">
                            Actualizar Datos
                        </button>
                    </div>
                    <div class="col-md-8 p-4">
                        <h6 class="fw-bold mb-3 text-uppercase small text-muted">Historial de Pedidos</h6>
                        <div id="orderHistoryContent" style="max-height: 400px; overflow-y: auto;">
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>Cargando pedidos...
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
                        <a class="nav-link active fw-bold" data-bs-toggle="pill" href="#loginTab">Iniciar Sesión</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-bold" data-bs-toggle="pill" href="#registerTab" onclick="generateCaptcha()">Crear Cuenta</a>
                    </li>
                </ul>
                <div class="tab-content p-4">
                    <!-- LOGIN -->
                    <div class="tab-pane fade show active" id="loginTab">
                        <form onsubmit="loginClient(); return false;">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted" for="logTel">Teléfono</label>
                                <input type="text" id="logTel" class="form-control bg-light border-0 p-3" placeholder="Tu número..." required>
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted" for="logPass">Contraseña</label>
                                <input type="password" id="logPass" class="form-control bg-light border-0 p-3" placeholder="••••••••" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-3 shadow">
                                Entrar <i class="fas fa-sign-in-alt ms-2"></i>
                            </button>
                        </form>
                    </div>
                    <!-- REGISTRO -->
                    <div class="tab-pane fade" id="registerTab">
                        <form onsubmit="registerClient(); return false;">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="small fw-bold text-muted">Nombre Completo</label>
                                    <input type="text" id="regNom" class="form-control bg-light border-0 p-2" placeholder="Ej. Juan Pérez" required autocomplete="name">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted">Teléfono</label>
                                    <input type="text" id="regTel" class="form-control bg-light border-0 p-2" placeholder="Ej. 5352... " required autocomplete="tel">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted">Contraseña</label>
                                    <input type="password" id="regPass" class="form-control bg-light border-0 p-2" placeholder="Mín. 6 caracteres" required autocomplete="new-password">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted">Dirección (Opcional)</label>
                                    <input type="text" id="regDir" class="form-control bg-light border-0 p-2" placeholder="Calle, Nº, e/...">
                                </div>
                                <div class="col-12">
                                    <div class="bg-warning bg-opacity-10 p-3 rounded-3 border border-warning border-opacity-20">
                                        <label class="small fw-bold text-warning-emphasis mb-2 d-block">Verificación Humana</label>
                                        <div class="d-flex align-items-center gap-2">
                                            <span id="captchaLabel" class="fw-bold fs-5 text-dark"></span>
                                            <input type="number" id="regCaptcha" class="form-control border-warning p-2" style="width: 80px" placeholder="?" required>
                                        </div>
                                        <input type="hidden" id="captchaVal">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100 py-3 mt-4 fw-bold rounded-3 shadow">
                                Registrarme <i class="fas fa-user-plus ms-2"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>

<!-- Toast de notificación -->
<div id="shopToastContainer" aria-live="polite" aria-atomic="true"
     style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;min-width:240px;"></div>

<script>
    // Feature 11: product catalog for client-side search + localStorage cache
    const PRODUCTS_DATA = <?= json_encode($productsJs, JSON_UNESCAPED_UNICODE) ?>;
    const PRODUCTS_CACHE_KEY = 'palweb_products_v1_<?= $SUC_ID ?>';
    const PRODUCTS_CACHE_TS  = 'palweb_products_ts_<?= $SUC_ID ?>';
    const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutos

    // Guardar catálogo en localStorage al cargar
    try {
        localStorage.setItem(PRODUCTS_CACHE_KEY, JSON.stringify(PRODUCTS_DATA));
        localStorage.setItem(PRODUCTS_CACHE_TS, Date.now().toString());
    } catch(e) {}

    function getProductsFromCache() {
        try {
            const ts = parseInt(localStorage.getItem(PRODUCTS_CACHE_TS) || '0');
            if (Date.now() - ts > CACHE_TTL_MS) return null;
            const raw = localStorage.getItem(PRODUCTS_CACHE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch(e) { return null; }
    }

    // Variable para el producto actualmente mostrado en modal (Feature 15)
    let _currentDetailProduct = null;

    // INICIALIZACIÓN
    window.addEventListener('load', () => {
        const skeleton = document.getElementById('skeletonLoader');
        const grid = document.getElementById('productsGrid');
        
        if (skeleton) skeleton.style.display = 'none';
        if (grid) grid.style.display = 'grid'; // O el valor que tenga tu CSS
        
        // Lazy loading manual para navegadores que no cargan onload automáticamente
        document.querySelectorAll('.lazy-img').forEach(img => {
            if (img.complete) img.classList.add('loaded');
        });
    });

    const modalDetail = new bootstrap.Modal(document.getElementById('modalDetail'));
    const modalCart = new bootstrap.Modal(document.getElementById('modalCart'));
    const trackingModal = new bootstrap.Modal(document.getElementById('trackingModal'));
    const authModal = new bootstrap.Modal(document.getElementById('authModal'));
    const profileModal = new bootstrap.Modal(document.getElementById('profileModal'));

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
                        btn.onclick = () => { addToCart(codigo, document.getElementById('detailName').innerText, basePrice, false, currentVariant); modalDetail.hide(); };
                    } else if (esReservable) {
                        btn.onclick = () => { addToCart(codigo, document.getElementById('detailName').innerText, basePrice, true, currentVariant); modalDetail.hide(); };
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
        list.innerHTML = '<div class="text-center text-muted py-2 small">Cargando reseñas...</div>';
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
                list.innerHTML = '<div class="text-center text-muted py-2 small">Sin reseñas aún. ¡Sé el primero!</div>';
            }

            if (IS_LOGGED_IN) {
                formWrap.innerHTML = `
                    <div class="border rounded-3 p-3 bg-light mt-2">
                        <p class="fw-bold mb-2 small">Tu valoración</p>
                        <div id="starInput" data-codigo="${escHtml(codigo)}" data-rating="0" class="mb-2">
                            ${[1,2,3,4,5].map(n =>
                                `<span class="star-pick" data-val="${n}" style="font-size:1.6rem;cursor:pointer;color:#d1d5db;">★</span>`
                            ).join('')}
                        </div>
                        <textarea id="reviewComment" class="form-control form-control-sm mb-2" rows="2"
                            placeholder="Cuéntanos tu experiencia (opcional)" maxlength="400"></textarea>
                        <button class="btn btn-warning btn-sm rounded-pill px-3 fw-bold"
                            onclick="submitReview('${escHtml(codigo)}')">
                            <i class="fas fa-paper-plane me-1"></i> Publicar
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
                    <a href="#" onclick="toggleAuthModal()">Inicia sesión</a> para dejar una reseña.</p>`;
            }
        } catch(e) {
            list.innerHTML = '<div class="text-center text-muted py-2 small">No se pudieron cargar las reseñas.</div>';
        }
    }

    async function submitReview(codigo) {
        const starInput = document.getElementById('starInput');
        const rating    = parseInt(starInput?.dataset.rating ?? 0);
        const comment   = document.getElementById('reviewComment')?.value.trim() ?? '';
        if (rating < 1 || rating > 5) { showToast('Selecciona de 1 a 5 estrellas'); return; }

        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action_client: 1, action: 'submit_review', codigo, rating, comentario: comment })
            });
            const data = await res.json();
            if (data.status === 'success') {
                showToast('¡Reseña publicada! Gracias por tu opinión.');
                loadReviews(codigo); // Recargar
            } else {
                showToast(data.msg || 'Error al publicar reseña');
            }
        } catch(e) { showToast('Error de conexión'); }
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
    function openProfileModal() { profileModal.show(); loadProfileData(); }

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
            div.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-box-open fa-2x mb-2 d-block opacity-50"></i>Aún no tienes pedidos registrados.</div>';
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
                        <div class="fw-bold">${tipoIcon(p.tipo_servicio)}Pedido #${idPad}</div>
                        <div class="small text-muted">${fmt(p.fecha)} · ${p.num_items} producto(s)</div>
                    </div>
                    <div class="text-end">
                        ${estadoBadge(p)}
                        <div class="fw-bold text-primary mt-1">${parseFloat(p.total).toLocaleString('es-CU')} CUP</div>
                    </div>
                </div>
                <div class="mt-2">
                    <a href="ticket_view.php?id=${p.id}" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-2">
                        <i class="fas fa-receipt me-1"></i>Ver ticket
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
            direccion: document.getElementById('profDir').value
        };
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const resp = await res.json();
            if (resp.status === 'success') {
                alert("Perfil actualizado correctamente.");
                location.reload();
            }
        } catch (e) { alert("Error al actualizar."); }
    }
    
    // Config de tarjeta para pasarela (inyectada desde PHP)
    const CFG_TARJETA = <?= json_encode($CFG_TARJETA) ?>;
    const CFG_TITULAR = <?= json_encode($CFG_TITULAR) ?>;
    const CFG_BANCO   = <?= json_encode($CFG_BANCO)   ?>;

    // Multi-divisa
    window.SHOP_METODOS = <?= json_encode($metodosShop, JSON_UNESCAPED_UNICODE) ?>;
    window.SHOP_TC_USD  = <?= $tipoCambioUSD ?>;
    window.SHOP_TC_MLC  = <?= $tipoCambioMLC ?>;

    // Perfil del cliente logueado (null si no hay sesión)
    const CLIENT_PROFILE = <?= json_encode($clienteProfile) ?>;

    let cart = JSON.parse(localStorage.getItem('palweb_cart') || '[]');
    let cartSyncTimeout = null;
    let flujoActual = 'reserva'; // 'reserva' | 'compra'
    let ordenUUID = null; // para polling de estado de pago
    let pollInterval = null;
    
    function toggleTrackingModal() { trackingModal.show(); }
    function toggleAuthModal() { authModal.show(); }
    
    // ===== BUSCADOR (Feature 11: usa caché local si disponible, AJAX como fallback) =====
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout = null;

    function renderSearchItem(item) {
        const div = document.createElement('div');
        div.className = 'search-item';
        const imgHTML = item.hasImg
            ? `<picture>
                <source type="image/avif" srcset="${item.imgUrl}&fmt=avif">
                <source type="image/webp" srcset="${item.imgUrl}&fmt=webp">
                <img src="${item.imgUrl}&fmt=jpg" class="search-thumb" loading="lazy">
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
                code: item.codigo, cat: item.categoria,
                unit: item.unidad_medida, color: item.color,
                esReservable: item.esReservable || false
            });
        };
        return div;
    }

    function doClientSearch(query) {
        const cached = getProductsFromCache();
        if (!cached) return false; // usar AJAX
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
            searchResults.innerHTML = '<div class="p-3 text-center text-muted">No se encontraron productos</div>';
        }
        searchResults.style.display = 'block';
        return true;
    }

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);

        if (query.length < 1) {
            searchResults.style.display = 'none';
            return;
        }

        // Intentar búsqueda client-side instantánea
        if (doClientSearch(query)) return;

        // Fallback a AJAX si no hay caché
        searchResults.innerHTML = '<div class="p-3 text-center">🔍 Buscando...</div>';
        searchResults.style.display = 'block';

        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`shop.php?ajax_search=${encodeURIComponent(query)}`);
                const data = await response.json();
                searchResults.innerHTML = '';
                if (data.error) {
                    searchResults.innerHTML = `<div class="p-3 text-danger">❌ ${data.message}</div>`;
                    return;
                }
                if (data.length > 0) {
                    data.forEach(item => searchResults.appendChild(renderSearchItem(item)));
                } else {
                    searchResults.innerHTML = '<div class="p-3 text-center text-muted">No se encontraron productos</div>';
                }
            } catch (error) {
                searchResults.innerHTML = '<div class="p-3 text-danger">Error al buscar</div>';
            }
        }, 300);
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
            ? `📅 Reserva agregada${variantLabel}`
            : `✓ Agregado al carrito${variantLabel}`;
        showToast(msg);
    }
    
    function renderCart() {
        const tbody = document.getElementById('cartTableBody');
        tbody.innerHTML = '';
        let total = 0;
        
        if (cart.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Carrito vacío</td></tr>';
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
        if (confirm('¿Vaciar el carrito?')) {
            cart = [];
            updateCounters();
            renderCart();
        }
    }
    
    function openProductDetail(data) {
        // Guardar referencia para compartir (Feature 15)
        _currentDetailProduct = data;

        // Registrar vista en el servidor para estadísticas
        fetch(`shop.php?action_view_product&code=${data.id}`).catch(e => {});

        // Llenar campos con el nuevo diseño profesional
        document.getElementById('detailName').innerText = data.name;
        document.getElementById('detailDesc').innerText = data.desc || 'Sin descripción detallada.';
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
            stockBadge.innerText = '✓ Disponible';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-success';
            btn.disabled = false;
            btn.style.background = '';
            btn.innerHTML = '<i class="fas fa-cart-plus me-2"></i> AGREGAR AL CARRITO';
            btn.onclick = () => {
                addToCart(data.id, data.name, po > 0 && po < bp ? po : bp, false);
                modalDetail.hide();
            };
        } else if (data.esReservable) {
            stockBadge.innerText = '📅 Disponible bajo reserva';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-warning text-dark';
            btn.disabled = false;
            btn.style.background = '#f59e0b';
            btn.innerHTML = '📅 RESERVAR PRODUCTO';
            btn.onclick = () => {
                addToCart(data.id, data.name, po > 0 && po < bp ? po : bp, true);
                modalDetail.hide();
            };
        } else {
            // Feature 1: mostrar form de aviso restock
            stockBadge.innerText = '✗ Agotado';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-danger';
            btn.disabled = true;
            btn.style.background = '#6c757d';
            btn.innerHTML = '✗ PRODUCTO AGOTADO';
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
        if (allImgs.length > 0) {
            img.src = allImgs[0];
            img.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            placeholder.innerText = data.initials;
            placeholder.style.background = data.bg + 'cc';
            img.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }

        // Thumbnails — solo si hay más de 1 imagen
        thumbsWrap.innerHTML = '';
        if (allImgs.length > 1) {
            thumbsWrap.style.display = 'flex';
            allImgs.forEach((src, i) => {
                const t = document.createElement('img');
                t.src       = src;
                t.className = 'detail-thumb' + (i === 0 ? ' active' : '');
                t.alt       = 'Vista ' + (i + 1);
                t.onclick   = () => {
                    img.src = src;
                    thumbsWrap.querySelectorAll('.detail-thumb').forEach(x => x.classList.remove('active'));
                    t.classList.add('active');
                };
                thumbsWrap.appendChild(t);
            });
        } else {
            thumbsWrap.style.display = 'none';
        }

        // Cargar variantes y reseñas para este producto
        loadVariants(data.id, parseFloat(data.price), data.hasStock, data.esReservable);
        loadReviews(data.id);

        modalDetail.show();
    }

    function openCart() {
        showCartView(); 
        renderCart(); 
        modalCart.show(); 
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
        if (cart.length === 0) { showToast('El carrito está vacío'); return; }
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
        });
    }

    function onPayMethodChange() {
        const val = document.querySelector('input[name=metodoPago]:checked')?.value;
        // Ocultar todos los paneles de transferencia
        document.querySelectorAll('[id^="transInfo_"]').forEach(d => d.style.display = 'none');
        if (val) {
            const metodo = (window.SHOP_METODOS || []).find(x => x.id === val);
            if (metodo?.es_transferencia) {
                const transDiv = document.getElementById('transInfo_' + val.replace(/\W/g, '_'));
                if (transDiv) transDiv.style.display = 'block';
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
            sel.innerHTML = '<option value="">Seleccione municipio...</option>';
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
            sel.innerHTML = '<option value="">Seleccione barrio...</option>';
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
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando...';

        let address = document.getElementById('cliDir').value;
        const notes = document.getElementById('cliNotes').value;
        const delivery = document.getElementById('delHome').checked;

        if (delivery) {
            const mun = document.getElementById('cMun').value;
            const bar = document.getElementById('cBar').value;
            if (!mun || !bar) {
                alert('Por favor selecciona municipio y barrio');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
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
                    alert('Por favor ingresa el código de confirmación de la transferencia.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
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
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
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
            console.error('Error:', error);
            alert('Error de red al enviar el pedido. Verifica tu conexión.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
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

    function toggleChat() {
        const win = document.getElementById('chatWindow');
        chatOpen = !chatOpen;
        win.style.display = chatOpen ? 'flex' : 'none';
        
        if (chatOpen) {
            document.getElementById('clientChatBadge').style.display = 'none';
            loadMessages();
            chatInterval = setInterval(loadMessages, 3000); // Polling cada 3 seg
            // Scroll al fondo
            setTimeout(() => { 
                const body = document.getElementById('chatBody');
                body.scrollTop = body.scrollHeight; 
            }, 100);
        } else {
            clearInterval(chatInterval);
        }
    }
    window.toggleChat = toggleChat; // Exponer globalmente

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
                body.innerHTML = '<div class="text-center text-muted small mt-5">¡Hola! 👋<br>Escribe tu consulta.<br>Si no estamos, deja un mensaje.</div>';
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
                resultsDiv.innerHTML = '<div class="alert alert-warning">No se encontraron pedidos con ese dato.</div>';
                return;
            }
            
            let html = '<div class="list-group shadow-sm">';
            data.forEach(p => {
                let badgeClass = 'bg-secondary';
                let statusText = 'Estado desconocido.';
                
                switch (p.estado) {
                    case 'Pendiente':
                        badgeClass = 'bg-warning text-dark';
                        statusText = 'Tu pedido ha sido recibido y está esperando confirmación. ¡Pronto empezaremos a prepararlo!';
                        break;
                    case 'En Preparación':
                        badgeClass = 'bg-info';
                        statusText = '¡Estamos preparando tu pedido con mucho cariño! En breve estará listo para salir.';
                        break;
                    case 'En Camino':
                        badgeClass = 'bg-primary';
                        statusText = '¡Buenas noticias! Tu pedido ha salido del local y va en camino. El mensajero está de ruta.';
                        break;
                    case 'Entregado':
                        badgeClass = 'bg-success';
                        statusText = '¡Tu pedido ha sido entregado con éxito! Esperamos que lo disfrutes.';
                        break;
                    case 'Cancelado':
                        badgeClass = 'bg-danger';
                        statusText = 'Lamentamos informarte que tu pedido ha sido cancelado. Por favor, contáctanos si tienes alguna duda.';
                        break;
                }
                
                let productsHtml = '';
                if (p.items && p.items.length > 0) {
                    productsHtml = '<div class="small mt-3 pt-2 border-top border-light-subtle"><b>Productos:</b><ul>';
                    p.items.forEach(item => {
                        productsHtml += `<li>${item.qty}x ${item.name} ($${parseFloat(item.price).toFixed(2)})</li>`;
                    });
                    productsHtml += '</ul></div>';
                }

                html += `
                <div class="list-group-item border-0 border-bottom p-3 animate__animated animate__fadeIn">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Pedido #${p.id}</span>
                        <span class="badge ${badgeClass}">${p.estado}</span>
                    </div>
                    <div class="small text-muted mb-1">
                        <i class="fas fa-calendar-alt me-1"></i> ${p.fecha}
                    </div>
                    <div class="mb-2">
                        <i class="fas fa-user me-1"></i> Cliente: <span class="fw-bold text-dark">${p.cliente_nombre || 'N/A'}</span>
                    </div>
                    <div class="fw-bold text-primary mb-2">Total: $${parseFloat(p.total).toFixed(2)}</div>
                    <div class="alert alert-light border small py-2 animate__animated animate__fadeIn">
                        <i class="fas fa-info-circle me-1"></i> ${statusText}
                    </div>
                    ${productsHtml}
                </div>`;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        } catch (e) { resultsDiv.innerHTML = '<div class="alert alert-danger">Error al consultar.</div>'; }
    }

    // ========================================================
    // LOGIN & REGISTRO DE CLIENTES
    // ========================================================
    function generateCaptcha() {
        const n1 = Math.floor(Math.random() * 10) + 1;
        const n2 = Math.floor(Math.random() * 10) + 1;
        document.getElementById('captchaLabel').innerText = `${n1} + ${n2} =`;
        document.getElementById('captchaVal').value = n1 + n2;
    }

    async function loginClient() {
        const tel = document.getElementById('logTel').value;
        const pass = document.getElementById('logPass').value;
        if (!tel || !pass) return alert("Completa los campos.");

        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'login', action_client: 1, telefono: tel, password: pass })
            });
            const data = await res.json();
            if (data.status === 'success') {
                location.reload();
            } else {
                alert(data.msg);
            }
        } catch (e) { alert("Error de conexión."); }
    }

    async function registerClient() {
        const nombre = document.getElementById('regNom').value.trim();
        const tel = document.getElementById('regTel').value.trim();
        const pass = document.getElementById('regPass').value.trim();
        const dir = document.getElementById('regDir').value.trim();
        const ans = document.getElementById('regCaptcha').value.trim();
        const val = document.getElementById('captchaVal').value.trim();

        if (!nombre || !tel || !pass || !ans) return alert("Completa todos los campos.");
        
        try {
            const res = await fetch('shop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'register', action_client: 1, 
                    nombre, telefono: tel, password: pass, direccion: dir,
                    captcha_ans: ans, captcha_val: val
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                alert("Cuenta creada con éxito. Ya puedes iniciar sesión.");
                // Cambiar a tab de login
                const triggerEl = document.querySelector('#authTabs a[href="#loginTab"]');
                bootstrap.Tab.getInstance(triggerEl).show();
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
        <p class="mb-2" style="font-size:.8rem;opacity:.8;text-transform:uppercase;letter-spacing:.05em;">Síguenos</p>
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

      <!-- Sistema -->
      <div class="col-12 col-md-4 text-center text-md-end">
        <small style="opacity:.65;font-size:.72rem;">Sistema PALWEB POS v3.0</small>
      </div>

    </div>
  </div>
</footer>

<!-- ── Campana de notificaciones push (tienda) ── -->
<div id="shopPushBell" style="position:fixed;bottom:80px;right:16px;z-index:9800;display:none;">
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
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw-shop.js', { scope: '/marinero/shop.php' })
        .then(reg => { console.log('[Shop PWA] SW registrado, scope:', reg.scope); })
        .catch(err => console.warn('[Shop PWA] SW error:', err));
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

        const reg = await navigator.serviceWorker.register('sw-shop.js', { scope: '/marinero/shop.php' });
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
        const reg = await navigator.serviceWorker.getRegistration('/marinero/shop.php');
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
    const reg = await navigator.serviceWorker.getRegistration('/marinero/shop.php');
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
        const reg = await navigator.serviceWorker.getRegistration('/marinero/shop.php');
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
function shareCurrentProduct() {
    if (!_currentDetailProduct) return;
    const prod = _currentDetailProduct;
    const url  = location.origin + location.pathname + '?producto=' + encodeURIComponent(prod.id || prod.code);
    const text = prod.name + ' — $' + parseFloat(prod.price || 0).toFixed(2);
    if (navigator.share) {
        navigator.share({ title: prod.name, text, url }).catch(() => {});
    } else {
        navigator.clipboard.writeText(url).then(() => showToast('🔗 Enlace copiado al portapapeles')).catch(() => {
            prompt('Copia este enlace:', url);
        });
    }
}

// Abrir producto desde URL ?producto=CODE
(function() {
    const params = new URLSearchParams(location.search);
    const code = params.get('producto');
    if (!code) return;
    const cached = getProductsFromCache();
    const list = cached || PRODUCTS_DATA;
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

</body>
</html>

