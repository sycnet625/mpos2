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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
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
            $localPath = __DIR__ . '/../product_images/' . $r['codigo'] . '.jpg';
            $r['hasImg'] = file_exists($localPath);
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
             WHERE s.id_producto = p.codigo AND s.id_almacen = ?) as stock_total 
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
        'price_desc' => 'p.precio DESC'
    ];
    
    $sql .= " ORDER BY " . ($sortMap[$sort] ?? 'p.categoria ASC, p.nombre ASC');
    $sql .= " LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener Wishlist si está logueado
    $userWishlist = [];
    if (isset($_SESSION['client_id'])) {
        $stmtW = $pdo->prepare("SELECT producto_codigo FROM wishlist WHERE cliente_id = ?");
        $stmtW->execute([$_SESSION['client_id']]);
        $userWishlist = $stmtW->fetchAll(PDO::FETCH_COLUMN);
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
        
        // Buscamos por ID exacto o por teléfono parcial
        $stmt = $pdo->prepare("SELECT id, estado, total, fecha, fecha_actualizacion, cliente_nombre, items_json 
                                FROM pedidos_cabecera 
                                WHERE id = ? OR cliente_telefono LIKE ? 
                                ORDER BY fecha DESC LIMIT 5");
        $stmt->execute([$q, $phonePattern]);
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pedidos as &$pedido) {
            $pedido['items'] = json_decode($pedido['items_json'] ?? '[]', true);
            unset($pedido['items_json']); 
        }

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

            $stmtP = $pdo->prepare("SELECT id, fecha, total, estado FROM pedidos_cabecera WHERE cliente_telefono = ? ORDER BY fecha DESC");
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

    <!-- PWA -->
    <meta name="theme-color" content="#0d6efd">
    <link rel="manifest" href="manifest.json">
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
                
                <div id="authButtons">
                    <?php if(isset($_SESSION['client_id'])): ?>
                        <div class="dropdown">
                            <button class="btn btn-light btn-sm rounded-pill px-3 dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars(explode(' ', $_SESSION['client_name'])[0]) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                                <li><a class="dropdown-item" href="#" onclick="openProfileModal()"><i class="fas fa-user-edit me-2 text-primary"></i>Mi Perfil</a></li>
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
            $imgPath = $localImgPath . $p['codigo'] . '.jpg';
            $hasImg = file_exists($imgPath);
            $imgUrl = $hasImg ? 'image.php?code=' . urlencode($p['codigo']) : null;
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
            "bg"          => $bg,
            "initials"    => $initials,
            "hasImg"      => $hasImg,
            "hasStock"    => $hasStock,
            "stock"       => $stock,
            "code"        => $p['codigo'],
            "cat"         => $p['categoria'],
            "unit"        => $p['unidad_medida'] ?? '',
            "color"       => $p['color'] ?? '',
            "esReservable"=> $esReservable,
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
                    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1 1'%3E%3C/svg%3E" 
                         data-src="<?= $imgUrl ?>" 
                         class="product-image lazy-img" 
                         alt="<?= htmlspecialchars($p['nombre']) ?>"
                         loading="lazy"
                         onload="this.classList.add('loaded'); this.src=this.dataset.src; this.onload=null;">
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

                <div class="product-footer">
                    <div class="product-price">$<?= number_format($p['precio'], 2) ?></div>
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
                        <button class="btn-add-cart" disabled>Agotado</button>
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
                    <div class="col-md-5 d-flex align-items-center justify-content-center bg-light rounded-3 p-3 position-relative" style="min-height: 250px;">
                         <img id="detailImg" class="d-none img-fluid rounded shadow-sm" style="max-height: 250px; object-fit: contain;">
                         <div id="detailPlaceholder" class="d-none rounded shadow-sm d-flex align-items-center justify-content-center text-white fw-bold display-4" style="width: 150px; height: 150px;"></div>
                         <span id="detailStockBadge" class="position-absolute top-0 start-0 m-3 badge rounded-pill"></span>
                    </div>

                    <div class="col-md-7 d-flex flex-column">
                        <small class="text-uppercase text-muted fw-bold mb-1" id="detailCat">Categoría</small>
                        <h3 class="fw-bold mb-2" id="detailName">Nombre Producto</h3>
                        
                        <div class="d-flex align-items-center mb-3">
                            <h2 class="text-primary fw-bold mb-0 me-3" id="detailPrice">$0.00</h2>
                            <small class="text-muted" id="detailUnit"></small>
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
                            <input type="date" id="cliDate" class="form-control" required>
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
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="metodoPago" id="mpEfectivoMensajero" value="EFECTIVO_MENSAJERO" onchange="onPayMethodChange()" checked>
                                    <label class="form-check-label" for="mpEfectivoMensajero">💵 Efectivo al mensajero (a la entrega)</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="metodoPago" id="mpEfectivoLocal" value="EFECTIVO_LOCAL" onchange="onPayMethodChange()">
                                    <label class="form-check-label" for="mpEfectivoLocal">🏪 Efectivo en el local (a la entrega)</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="metodoPago" id="mpTransferencia" value="TRANSFERENCIA" onchange="onPayMethodChange()">
                                    <label class="form-check-label" for="mpTransferencia">📲 Transferencia Enzona / Transfermovil</label>
                                </div>

                                <div id="transferenciaInfo" style="display:none; margin-top:1rem;">
                                    <div class="p-3 mb-3" style="background:#e8f4f8; border-radius:10px; border-left:4px solid #0d6efd;">
                                        <div class="small mb-1">Tarjeta: <strong id="tCardNum"></strong></div>
                                        <div class="small mb-1">Titular: <strong id="tCardHolder"></strong></div>
                                        <div class="small mb-1">Banco: <strong id="tCardBank"></strong></div>
                                        <div class="small">Monto a transferir: <strong class="text-success" id="tMonto"></strong></div>
                                    </div>
                                    <label class="form-label fw-bold">Código de confirmación del pago *</label>
                                    <input id="codigoPago" type="text" class="form-control" placeholder="Ej: TRF-20260220-001234">
                                    <small class="text-muted">Escribe el número de confirmación de la transferencia realizada.</small>
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
                <div class="modal-body text-center py-5">
                    <div style="font-size: 5rem; margin-bottom: 1rem;">🎉</div>
                    <h3 class="fw-bold mb-3 text-success">¡Pago Confirmado!</h3>
                    <p class="text-muted fs-5">Tu transferencia fue verificada exitosamente.<br>Tu pedido está en proceso.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center">
                    <button class="btn btn-success btn-lg px-5" data-bs-dismiss="modal" onclick="location.reload()">Cerrar</button>
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
<script>
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
        if (pedidos.length === 0) {
            div.innerHTML = '<div class="text-center py-5 text-muted">Aún no tienes pedidos registrados.</div>';
            return;
        }

        let html = '<div class="list-group shadow-sm">';
        pedidos.forEach(p => {
            let badge = 'bg-secondary';
            if (p.estado === 'Pendiente') badge = 'bg-warning text-dark';
            if (p.estado === 'Entregado') badge = 'bg-success';
            
            html += `
            <div class="list-group-item border-0 border-bottom p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Pedido #${p.id}</span>
                    <span class="badge ${badge} small">${p.estado}</span>
                </div>
                <div class="small text-muted mb-1">${p.fecha}</div>
                <div class="fw-bold text-primary">$${parseFloat(p.total).toFixed(2)}</div>
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

    let cart = JSON.parse(localStorage.getItem('palweb_cart') || '[]');
    let cartSyncTimeout = null;
    let flujoActual = 'reserva'; // 'reserva' | 'compra'
    let ordenUUID = null; // para polling de estado de pago
    let pollInterval = null;
    
    function toggleTrackingModal() { trackingModal.show(); }
    function toggleAuthModal() { authModal.show(); }
    
    // ===== BUSCADOR AJAX =====
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    let searchTimeout = null;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            searchResults.style.display = 'none';
            return;
        }

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
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'search-item';
                        
                        const imgHTML = item.hasImg 
                            ? `<img src="${item.imgUrl}" class="search-thumb">`
                            : `<div class="search-placeholder" style="background: ${item.bg}">${item.initials}</div>`;
                        
                        div.innerHTML = `
                            ${imgHTML}
                            <div style="flex: 1;">
                                <div style="font-weight: 600;">${item.nombre}</div>
                                <small class="text-muted">${item.categoria || ''}</small>
                            </div>
                            <div style="font-weight: 700; color: var(--primary);">$${parseFloat(item.precio).toFixed(2)}</div>
                        `;
                        
                        div.onclick = function() {
                            searchInput.value = '';
                            searchResults.style.display = 'none';
                            openProductDetail({
                                id:          item.codigo,
                                name:        item.nombre,
                                price:       item.precio,
                                desc:        item.descripcion || '',
                                img:         item.imgUrl,
                                bg:          item.bg,
                                initials:    item.initials,
                                hasImg:      item.hasImg,
                                hasStock:    item.hasStock,
                                stock:       item.stock,
                                code:        item.codigo,
                                cat:         item.categoria,
                                unit:        item.unidad_medida,
                                color:       item.color,
                                esReservable: item.esReservable || false
                            });
                        };
                        
                        searchResults.appendChild(div);
                    });
                } else {
                    searchResults.innerHTML = '<div class="p-3 text-center text-muted">No se encontraron productos</div>';
                }
            } catch (error) {
                console.error('Error:', error);
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
    
    function addToCart(id, name, price, isReserva = false) {
        if (event) event.stopPropagation();
        const existing = cart.find(i => i.id === id && i.isReserva === isReserva);
        if (existing) {
            existing.qty++;
        } else {
            cart.push({ id, name, price, qty: 1, isReserva });
        }
        updateCounters();
        const msg = isReserva
            ? '📅 Reserva agregada al carrito'
            : '✓ Producto agregado al carrito';
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
                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold">${item.name}${reservaBadge}</div>
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
        // Registrar vista en el servidor para estadísticas
        fetch(`shop.php?action_view_product&code=${data.id}`).catch(e => {});

        // Llenar campos con el nuevo diseño profesional
        document.getElementById('detailName').innerText = data.name;
        document.getElementById('detailPrice').innerText = '$' + parseFloat(data.price).toFixed(2);
        document.getElementById('detailDesc').innerText = data.desc || 'Sin descripción detallada.';
        document.getElementById('detailCat').innerText = data.cat || 'General';
        document.getElementById('detailSku').innerText = data.code || '-';
        document.getElementById('detailUnit').innerText = data.unit ? '/ ' + data.unit : '';
        
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
        
        if (data.hasStock) {
            stockBadge.innerText = '✓ Disponible';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-success';
            btn.disabled = false;
            btn.style.background = '';
            btn.innerHTML = '<i class="fas fa-cart-plus me-2"></i> AGREGAR AL CARRITO';
            btn.onclick = () => {
                addToCart(data.id, data.name, data.price, false);
                modalDetail.hide();
            };
        } else if (data.esReservable) {
            stockBadge.innerText = '📅 Disponible bajo reserva';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-warning text-dark';
            btn.disabled = false;
            btn.style.background = '#f59e0b';
            btn.innerHTML = '📅 RESERVAR PRODUCTO';
            btn.onclick = () => {
                addToCart(data.id, data.name, data.price, true);
                modalDetail.hide();
            };
        } else {
            stockBadge.innerText = '✗ Agotado';
            stockBadge.className = 'position-absolute top-0 start-0 m-3 badge rounded-pill bg-danger';
            btn.disabled = true;
            btn.style.background = '';
            btn.innerHTML = 'PRODUCTO AGOTADO';
        }
        
        // Imagen
        const img = document.getElementById('detailImg');
        const placeholder = document.getElementById('detailPlaceholder');
        
        if (data.hasImg) {
            img.src = data.img;
            img.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            placeholder.innerText = data.initials;
            placeholder.style.background = data.bg + 'cc';
            img.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
        
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

    function abrirCheckoutInterno(flujo) {
        flujoActual = flujo;
        document.getElementById('cartItemsView').style.display = 'none';
        document.getElementById('checkoutView').style.display = 'block';
        document.getElementById('successView').style.display = 'none';
        document.getElementById('waitingPaymentView').style.display = 'none';
        document.getElementById('pagoConfirmadoView').style.display = 'none';

        // Mostrar u ocultar sección de pago
        const paySection = document.getElementById('paymentSection');
        paySection.style.display = flujo === 'compra' ? 'block' : 'none';

        // Siempre limpiar estado del campo código de pago (evitar validación HTML5 en campo oculto)
        const codigoPagoInput = document.getElementById('codigoPago');
        if (codigoPagoInput) { codigoPagoInput.removeAttribute('required'); codigoPagoInput.required = false; codigoPagoInput.value = ''; }

        // Si es compra: marcar el primer radio como checked y ocultar info de transferencia
        if (flujo === 'compra') {
            const firstRadio = document.getElementById('mpEfectivoMensajero');
            if (firstRadio) firstRadio.checked = true;
            document.getElementById('transferenciaInfo').style.display = 'none';
        }

        // Fecha mínima
        const today = new Date().toISOString().split('T')[0];
        const tomorrow = new Date(Date.now() + 86400000).toISOString().split('T')[0];
        const dateInput = document.getElementById('cliDate');
        dateInput.min = today;
        dateInput.max = flujo === 'compra' ? tomorrow : '';
        if (!dateInput.value) dateInput.value = tomorrow;
    }

    function onPayMethodChange() {
        const val = document.querySelector('input[name=metodoPago]:checked')?.value;
        const infoDiv = document.getElementById('transferenciaInfo');
        const codigoPagoInput = document.getElementById('codigoPago');
        if (val === 'TRANSFERENCIA') {
            document.getElementById('tCardNum').textContent    = CFG_TARJETA || '(sin configurar)';
            document.getElementById('tCardHolder').textContent = CFG_TITULAR || '(sin configurar)';
            document.getElementById('tCardBank').textContent   = CFG_BANCO   || 'Bandec / BPA';
            const total = parseFloat(document.getElementById('grandTotal').innerText) || 0;
            document.getElementById('tMonto').textContent =
                new Intl.NumberFormat('es-CU', { style: 'currency', currency: 'CUP' }).format(total);
            infoDiv.style.display = 'block';
            if (codigoPagoInput) codigoPagoInput.required = true;
        } else {
            infoDiv.style.display = 'none';
            if (codigoPagoInput) codigoPagoInput.required = false;
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
                    document.getElementById('pagoConfirmadoView').style.display = 'block';
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
            metodoPago = radioChecked ? radioChecked.value : 'EFECTIVO_MENSAJERO';
            if (metodoPago === 'TRANSFERENCIA') {
                codigoPago = (document.getElementById('codigoPago')?.value || '').trim();
                if (!codigoPago) {
                    alert('Por favor ingresa el código de confirmación de la transferencia.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Confirmar Pedido';
                    return;
                }
                estadoPago = 'verificando';
            }
        }

        const uuid = 'WEB-' + Date.now();
        const data = {
            uuid,
            total:              parseFloat(document.getElementById('grandTotal').innerText),
            metodo_pago:        metodoPago,
            tipo_servicio:      tipoServicio,
            cliente_nombre:     document.getElementById('cliName').value,
            cliente_telefono:   document.getElementById('cliTel').value,
            cliente_direccion:  address,
            fecha_reserva:      document.getElementById('cliDate').value,
            mensajero_nombre:   notes,
            codigo_pago:        codigoPago,
            estado_pago:        estadoPago,
            items: cart.map(i => ({ id: i.id, qty: i.qty, price: i.price, name: i.name, note: '', isReserva: i.isReserva || false }))
        };

        try {
            const res = await fetch('pos_save.php', {
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

</body>
</html>

