<?php
// ARCHIVO: /var/www/palweb/api/image_hunter.php
// DESCRIPCIÓN: Herramienta visual para buscar y asignar imágenes una por una.

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CONFIGURACIÓN
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);
$localPath = __DIR__ . '/assets/product_images/';

if (!is_dir($localPath)) {
    @mkdir($localPath, 0775, true);
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function safeProductId(string $id): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
}

function downloadImageToPath(string $url, string $destPath): bool {
    if (!preg_match('#^https?://#i', $url)) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Referer: https://www.google.com/',
        ],
    ]);
    $imgData = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($imgData === false || $imgData === '' || $httpCode < 200 || $httpCode >= 300) return false;
    if (stripos($contentType, 'image/') === false && @getimagesizefromstring($imgData) === false) return false;

    // Normalizar a JPEG para mantener compatibilidad con el ecosistema actual (*.jpg).
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $im = @imagecreatefromstring($imgData);
        if ($im !== false) {
            $ok = @imagejpeg($im, $destPath, 88);
            imagedestroy($im);
            return $ok;
        }
    }

    return @file_put_contents($destPath, $imgData) !== false;
}

function getGoogleCandidates(string $query): array {
    $q = urlencode($query);
    $url = "https://www.google.com/search?q={$q}&tbm=isch&hl=es";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    $candidates = [];

    // 1) imgurl=... (enlaces reales de imagen)
    if (preg_match_all('/imgurl=([^&"]+)/i', $html, $m1)) {
        foreach ($m1[1] as $enc) {
            $u = urldecode($enc);
            if (preg_match('#^https?://#i', $u)) $candidates[] = $u;
        }
    }

    // 2) URLs directas de imagen embebidas
    if (preg_match_all('#https?://[^"\'\\s<>]+\\.(?:jpg|jpeg|png|webp|gif)(?:\\?[^"\'\\s<>]*)?#i', $html, $m2)) {
        foreach ($m2[0] as $u) $candidates[] = $u;
    }

    // 3) thumbnails de googleusercontent/gstatic como último recurso
    if (preg_match_all('/https:\/\/encrypted-tbn0\.gstatic\.com\/images\?q=tbn:[^"\']+/i', $html, $m3)) {
        foreach ($m3[0] as $u) $candidates[] = $u;
    }

    $out = [];
    $seen = [];
    foreach ($candidates as $u) {
        $lu = strtolower($u);
        if (strpos($lu, 'gstatic.com/gb/images') !== false) continue;
        if (strpos($lu, '/favicon') !== false) continue;
        if (strpos($lu, '/logo') !== false) continue;
        if (strpos($lu, 'sprite') !== false) continue;
        if (strpos($lu, 'googlelogo') !== false) continue;
        if (!isset($seen[$u])) {
            $seen[$u] = true;
            $out[] = $u;
        }
        if (count($out) >= 25) break;
    }
    return $out;
}

// =========================================================
//  BACKEND: PROCESAR SOLICITUDES (AJAX)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = safeProductId((string)($input['id'] ?? ''));
    if ($id === '') jsonOut(['status' => 'error', 'msg' => 'ID de producto inválido']);
    $destino = $localPath . $id . '.jpg';

    // OPCIÓN A: SCRAPING AUTOMÁTICO DE GOOGLE (GBV=1)
    if (isset($input['action']) && $input['action'] === 'scrape_google') {
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') jsonOut(['status' => 'error', 'msg' => 'Consulta vacía']);

        $candidatas = getGoogleCandidates($query);
        foreach ($candidatas as $imgUrl) {
            if (downloadImageToPath($imgUrl, $destino)) {
                jsonOut(['status' => 'success', 'msg' => 'Imagen capturada automáticamente', 'source' => $imgUrl]);
            }
        }
        jsonOut(['status' => 'error', 'msg' => 'No se pudo descargar una imagen válida desde Google']);
    }

    // OPCIÓN B: GUARDADO MANUAL (PEGAR URL)
    if (isset($input['action']) && $input['action'] === 'save_url') {
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '') jsonOut(['status' => 'error', 'msg' => 'URL vacía']);

        if (downloadImageToPath($url, $destino)) {
            jsonOut(['status' => 'success']);
        } else {
            jsonOut(['status' => 'error', 'msg' => 'URL inválida, protegida o no es imagen']);
        }
    }

    jsonOut(['status' => 'error', 'msg' => 'Acción no soportada']);
}

// =========================================================
//  FRONTEND: OBTENER PRODUCTOS SIN IMAGEN
// =========================================================
// Obtenemos TODOS los productos activos
$sql = "SELECT codigo, nombre, categoria FROM productos WHERE activo = 1 AND id_empresa = $EMP_ID ORDER BY nombre ASC";
$stmt = $pdo->query($sql);
$todos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtramos en PHP los que NO tienen imagen
$sinImagen = [];
foreach ($todos as $p) {
    if (!file_exists($localPath . $p['codigo'] . '.jpg')) {
        $sinImagen[] = $p;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cazador de Imágenes</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #eef2f7; padding: 20px; }
        .card-prod { background: white; border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; gap: 15px; transition: 0.3s; }
        .card-prod:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .status-badge { width: 50px; height: 50px; background: #eee; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #ccc; flex-shrink: 0; }
        .status-badge.ok { background: #d1e7dd; color: #198754; }
        .info { flex-grow: 1; }
        .actions { display: flex; gap: 5px; flex-shrink: 0; }
        .manual-input { display: none; margin-top: 10px; }
    </style>
</head>
<body>

<div class="container" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary"><i class="fas fa-camera-retro"></i> Cazador de Imágenes</h3>
            <p class="text-muted mb-0">Hay <strong><?php echo count($sinImagen); ?></strong> productos sin foto. ¡A trabajar!</p>
        </div>
        <div class="d-flex gap-2">
            <button id="btn-auto-all" class="btn btn-primary" onclick="autoLlenarTodo()">
                <i class="fas fa-bolt"></i> Auto llenar todo
            </button>
            <a href="shop.php" class="btn btn-outline-secondary">Ir a la Tienda</a>
        </div>
    </div>

    <div id="listaProductos">
        <?php foreach ($sinImagen as $p): ?>
            <div class="card-prod" id="card-<?php echo $p['codigo']; ?>">
                <div class="status-badge" id="badge-<?php echo $p['codigo']; ?>">
                    <i class="fas fa-image"></i>
                </div>

                <div class="info">
                    <h5 class="fw-bold m-0"><?php echo htmlspecialchars($p['nombre']); ?></h5>
                    <span class="badge bg-light text-dark border"><?php echo $p['categoria']; ?></span>
                    <small class="text-muted ms-2"><?php echo $p['codigo']; ?></small>
                    
                    <div class="manual-input input-group input-group-sm" id="manual-<?php echo $p['codigo']; ?>">
                        <input type="text" class="form-control" id="url-<?php echo $p['codigo']; ?>" placeholder="Pega aquí el enlace de la imagen...">
                        <button class="btn btn-success" onclick="guardarManual('<?php echo $p['codigo']; ?>')"><i class="fas fa-save"></i></button>
                    </div>
                </div>

                <div class="actions" id="btns-<?php echo $p['codigo']; ?>">
                    
                    <button class="btn btn-primary btn-auto" data-id="<?php echo $p['codigo']; ?>" data-query="<?php echo htmlspecialchars($p['nombre'] . ' ' . $p['categoria'], ENT_QUOTES, 'UTF-8'); ?>" onclick="autoCaptura('<?php echo $p['codigo']; ?>', '<?php echo addslashes($p['nombre'] . ' ' . $p['categoria']); ?>')" title="Intentar descarga automática de Google">
                        <i class="fas fa-magic"></i> Auto
                    </button>

                    <button class="btn btn-outline-dark" onclick="abrirGoogle('<?php echo $p['codigo']; ?>', '<?php echo addslashes($p['nombre'] . ' ' . $p['categoria']); ?>')" title="Buscar manualmente">
                        <i class="fab fa-google"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if(empty($sinImagen)): ?>
            <div class="text-center py-5">
                <i class="fas fa-check-circle text-success" style="font-size: 50px;"></i>
                <h3 class="mt-3">¡Todo listo!</h3>
                <p>No hay productos sin imagen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // 1. AUTO CAPTURA (Intenta scrapear Google Basic Version desde el servidor)
    async function autoCaptura(id, query) {
        const btn = document.querySelector(`#card-${id} .btn-primary`);
        const badge = document.getElementById(`badge-${id}`);
        const originalText = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const res = await fetch('image_hunter.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'scrape_google', id: id, query: query })
            });
            const data = await res.json();

            if (data.status === 'success') {
                marcarComoListo(id);
            } else {
                alert('⚠️ Falló la auto-captura: ' + data.msg + '\n\nPrueba el botón de Google Manual.');
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        } catch (e) {
            alert('Error de conexión');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    async function autoLlenarTodo() {
        const btn = document.getElementById('btn-auto-all');
        if (!btn) return;
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

        const botones = Array.from(document.querySelectorAll('.btn-auto'));
        for (const b of botones) {
            if (b.disabled) continue;
            const id = b.getAttribute('data-id');
            const query = b.getAttribute('data-query') || '';
            if (!id || !query) continue;
            await autoCaptura(id, query);
            await new Promise(r => setTimeout(r, 350));
        }

        btn.innerHTML = original;
        btn.disabled = false;
    }

    // 2. ABRIR GOOGLE Y MOSTRAR CAMPO MANUAL
    function abrirGoogle(id, query) {
        // Abrir pestaña nueva con búsqueda de imágenes
        const url = `https://www.google.com.cu/search?q=${encodeURIComponent(query)}&udm=2`; // udm=2 fuerza modo imágenes
        window.open(url, '_blank');

        // Mostrar el campo para pegar URL
        document.getElementById(`manual-${id}`).style.display = 'flex';
        document.getElementById(`url-${id}`).focus();
    }

    // 3. GUARDAR MANUALMENTE (Pegar URL)
    async function guardarManual(id) {
        const urlInput = document.getElementById(`url-${id}`);
        const url = urlInput.value.trim();
        
        if (!url) return;

        try {
            const res = await fetch('image_hunter.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'save_url', id: id, url: url })
            });
            const data = await res.json();

            if (data.status === 'success') {
                marcarComoListo(id);
            } else {
                alert('Error: No se pudo descargar esa URL. Prueba con otra (a veces tienen protección).');
            }
        } catch (e) { alert('Error de red'); }
    }

    // FUNCIÓN VISUAL DE ÉXITO
    function marcarComoListo(id) {
        const card = document.getElementById(`card-${id}`);
        const badge = document.getElementById(`badge-${id}`);
        const btns = document.getElementById(`btns-${id}`);
        
        badge.className = 'status-badge ok';
        badge.innerHTML = '<img src="assets/product_images/'+id+'.jpg?t='+new Date().getTime()+'" style="width:100%; height:100%; object-fit:cover; border-radius:8px;">';
        
        btns.innerHTML = '<span class="text-success fw-bold"><i class="fas fa-check"></i> Guardado</span>';
        document.getElementById(`manual-${id}`).style.display = 'none';
        
        // Efecto visual
        card.style.background = '#f8fff9';
        card.style.borderColor = '#b6ebc3';
    }
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
