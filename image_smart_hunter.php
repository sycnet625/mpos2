<?php
declare(strict_types=1);

// ==========================
//  MÓDULO: Buscador inteligente de imágenes por proveedor
// ==========================

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

require_once 'db.php';
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa'] ?? 1);

function isWritableDir(string $path): bool {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) return false;
    }
    if (!is_writable($path)) return false;
    $probe = rtrim($path, '/') . '/.writetest_' . uniqid('', true);
    $ok = @file_put_contents($probe, '1') !== false;
    if ($ok) @unlink($probe);
    return (bool)$ok;
}

function pickImageDir(array $candidates): array {
    foreach ($candidates as $path) {
        if (isWritableDir($path)) {
            return [rtrim($path, '/'), 'ok'];
        }
    }
    $fallback = '/tmp/palweb_product_images';
    if (isWritableDir($fallback)) return [$fallback, 'fallback'];
    return [rtrim($candidates[0], '/'), 'unwritable'];
}

[$IMAGE_DIR, $IMAGE_DIR_STATUS] = pickImageDir([
    __DIR__ . '/assets/product_images/',
    dirname(__DIR__) . '/assets/product_images/',
    '/tmp/palweb_product_images/'
]);
$IMAGE_DIR .= '/';

if (!is_dir($IMAGE_DIR) || !is_writable($IMAGE_DIR)) {
    http_response_code(500);
    die('Error: No fue posible preparar una carpeta escribible para guardar imágenes.');
}

function jsonOut(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getConfigValue(string $envKey, string $configKey = '', string $default = ''): string {
    $fromEnv = trim((string)($_ENV[$envKey] ?? ''));
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    if ($configKey !== '') {
        global $config;
        $fromConfig = trim((string)($config[$configKey] ?? ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }
    }

    return $default;
}

function safeProductId(string $id): string {
    return preg_replace('/[^A-Za-z0-9_.-]/', '', trim($id));
}

function httpGet(string $url, int $timeout = 15): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PalWebImageHunter/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => (string)($body ?: ''), 'url' => $url];
}

function httpGetJson(string $url, int $timeout = 15): ?array {
    $res = httpGet($url, $timeout);
    if ($res['code'] < 200 || $res['code'] >= 300 || $res['body'] === '') {
        return null;
    }
    $data = json_decode($res['body'], true);
    return is_array($data) ? $data : null;
}

function sanitizeImageUrl(string $url): string {
    $url = trim($url);
    if (preg_match('#^https?://#i', $url)) {
        return str_replace('&amp;', '&', $url);
    }
    return '';
}

function addCandidates(array &$bucket, string $url, string $source, string $title, int $score, int $max = 8): void {
    $url = sanitizeImageUrl($url);
    if (!$url) return;
    $url = str_replace('&amp;', '&', $url);
    if (isset($bucket[$url])) return;

    $bucket[$url] = [
        'url' => $url,
        'source' => $source,
        'title' => $title,
        'score' => max(0, min(100, $score)),
    ];

    if (count($bucket) >= $max) return;
}

function hasAnyVariant(string $base): bool {
    foreach (['.avif', '.webp', '.jpg', '.jpeg'] as $ext) {
        if (file_exists($base . $ext)) return true;
    }
    return false;
}

function toSafeQuery(string $term): string {
    $term = trim((string)$term);
    return $term !== '' ? $term : 'producto';
}

function downloadImageToBasePath(string $url, string $basePath): bool {
    if (!is_string($url) || !preg_match('#^https?://#i', $url)) return false;
    if (!is_dir(dirname($basePath)) || !is_writable(dirname($basePath))) return false;

    $probe = dirname($basePath) . '/.writetest_' . uniqid('', true);
    $probeOk = @file_put_contents($probe, '1') !== false;
    if (!$probeOk) return false;
    @unlink($probe);

    $res = httpGet($url, 20);
    if ($res['code'] < 200 || $res['code'] >= 300 || $res['body'] === '') return false;

    if (!function_exists('imagecreatefromstring')) return false;
    $im = @imagecreatefromstring($res['body']);
    if ($im === false) return false;

    $okJ = @imagejpeg($im, $basePath . '.jpg', 86);
    $okW = function_exists('imagewebp') ? @imagewebp($im, $basePath . '.webp', 82) : true;
    $okA = function_exists('imageavif') ? @imageavif($im, $basePath . '.avif', 58, 6) : true;
    imagedestroy($im);

    return (bool)$okJ;
}

function searchWikipediaCandidates(string $query): array {
    $query = toSafeQuery($query);
    $base = [];
    $searchUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'format' => 'json',
        'list' => 'search',
        'srsearch' => $query,
        'srprop' => 'snippet',
        'srlimit' => 5,
        'origin' => '*'
    ]);
    $searchData = httpGetJson($searchUrl, 12);
    if (!$searchData || empty($searchData['query']['search']) || !is_array($searchData['query']['search'])) {
        return [];
    }

    $terms = array_slice($searchData['query']['search'], 0, 4);
    foreach ($terms as $idx => $item) {
        $title = $item['title'] ?? '';
        if (!is_string($title) || $title === '') continue;

        $imgUrl = 'https://en.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'format' => 'json',
            'prop' => 'pageimages',
            'piprop' => 'original',
            'pithumbsize' => 1200,
            'titles' => $title,
            'origin' => '*'
        ]);
        $imgData = httpGetJson($imgUrl, 10);
        if (!$imgData || empty($imgData['query']['pages'])) continue;

        $pages = $imgData['query']['pages'];
        if (!is_array($pages)) continue;
        foreach ($pages as $pg) {
            $thumb = $pg['thumbnail']['source'] ?? null;
            if (!is_string($thumb)) continue;
            $score = 62 + ($idx * 6);
            if (stripos(mb_strtolower($title), mb_strtolower($query)) !== false) $score += 25;
            addCandidates($base, $thumb, 'Wikipedia', $title, $score);
        }
    }
    return array_values($base);
}

function searchDbpediaCandidates(string $query): array {
    $query = toSafeQuery($query);
    $needle = mb_strtolower(preg_replace('/\s+/', ' ', $query));
    $escaped = str_replace("'", "\\'", $needle);
    $sparql = <<<SPARQL
PREFIX dbo: <http://dbpedia.org/ontology/>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT DISTINCT ?item ?thumbnail ?label WHERE {
  ?item rdfs:label ?label .
  FILTER(LANG(?label) = "en")
  FILTER(CONTAINS(LCASE(STR(?label)), "{$escaped}"))
  ?item dbo:thumbnail ?thumbnail .
}
LIMIT 10
SPARQL;

    $url = 'https://dbpedia.org/sparql?' . http_build_query([
        'query' => $sparql,
        'format' => 'json'
    ]);
    $json = httpGetJson($url, 12);
    if (!$json || empty($json['results']['bindings']) || !is_array($json['results']['bindings'])) return [];

    $cands = [];
    foreach (array_slice($json['results']['bindings'], 0, 8) as $row) {
        $img = $row['thumbnail']['value'] ?? null;
        $name = $row['label']['value'] ?? '';
        if (!is_string($img)) continue;
        addCandidates($cands, $img, 'DBpedia', is_string($name) ? $name : 'DBpedia', 60);
    }
    return array_values($cands);
}

function searchOpenFoodFactsCandidates(string $query, string $sku = ''): array {
    $query = toSafeQuery($query);
    $cands = [];

    if ($sku !== '') {
        $exact = 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($sku) . '.json?fields=code,product_name,image_front_url,image_url,image_front_small_url';
        $data = httpGetJson($exact, 12);
        if ($data && (($data['status'] ?? 0) == 1)) {
            $product = $data['product'] ?? [];
            $label = $product['product_name'] ?? $query;
            if (!empty($product['image_front_url'])) addCandidates($cands, $product['image_front_url'], 'OpenFoodFacts', (string)$label . ' (código)', 96);
            if (!empty($product['image_url'])) addCandidates($cands, $product['image_url'], 'OpenFoodFacts', (string)$label . ' (código)', 92);
        }
    }

    $search = 'https://world.openfoodfacts.org/cgi/search.pl?' . http_build_query([
        'search_terms' => $query,
        'search_simple' => 1,
        'json' => 1,
        'page_size' => 8,
        'fields' => 'code,product_name,image_front_url,image_url',
    ]);
    $json = httpGetJson($search, 15);
    if (!$json || empty($json['products']) || !is_array($json['Products']) && !is_array($json['products'])) {
        return array_values($cands);
    }

    $products = $json['products'] ?? $json['Products'];
    foreach (array_slice($products, 0, 8) as $item) {
        $name = $item['product_name'] ?? $query;
        if (!empty($item['image_front_url'])) addCandidates($cands, $item['image_front_url'], 'OpenFoodFacts', (string)$name, 84);
        if (!empty($item['image_url'])) addCandidates($cands, $item['image_url'], 'OpenFoodFacts', (string)$name, 80);
    }
    return array_values($cands);
}

function searchWalmartCandidates(string $query): array {
    $query = toSafeQuery($query);
    $cands = [];
    $apiKey = getConfigValue('WALMART_API_KEY', 'walmart_api_key');
    if ($apiKey !== '') {
        $apiUrl = 'https://developer.api.walmart.com/api-proxy/service/affil/product/v2/search';
        $queryUrl = $apiUrl . '?' . http_build_query([
            'query' => $query,
            'format' => 'json',
            'sort' => 'relevance',
            'apiKey' => $apiKey,
        ]);

        $json = httpGetJson($queryUrl, 14);
        if ($json && is_array($json['items'] ?? null)) {
            foreach (array_slice($json['items'], 0, 8) as $item) {
                $img = $item['largeImage'] ?? ($item['mediumImage'] ?? null);
                $name = $item['name'] ?? $query;
                if (is_string($img)) addCandidates($cands, $img, 'Walmart API', (string)$name, 88);
            }
        }
        if (!empty($cands)) return array_values($cands);
    }

    $searchUrl = 'https://www.walmart.com/search?' . http_build_query(['q' => $query]);
    $html = httpGet($searchUrl, 12)['body'] ?? '';
    if (preg_match_all('~https://[^"\'\\s<>]*i5\\.walmartimages\\.com/[^"\'\\s<>]+\\.(?:jpg|jpeg|png|webp)~i', $html, $m)) {
        foreach (array_slice(array_unique($m[0]), 0, 8) as $img) addCandidates($cands, $img, 'Walmart', $query, 58);
    }
    return array_values($cands);
}

function searchAmazonCandidates(string $query): array {
    $query = toSafeQuery($query);
    $cands = [];
    $apiKey = getConfigValue('AMAZON_ACCESS_KEY');
    if ($apiKey === '') {
        // Amazon no expone una API de búsqueda pública sin firma. Se intenta fallback por scraping.
        // Puede fallar por bot-protection, pero intentamos no bloquear el flujo del módulo.
    }

    $hasEnvKeys = false;
    if ($apiKey !== '') {
        $secret = getConfigValue('AMAZON_SECRET_KEY');
        $partnerTag = getConfigValue('AMAZON_PARTNER_TAG');
        $hasEnvKeys = $apiKey !== '' && $secret !== '' && $partnerTag !== '';
    }
    if ($hasEnvKeys) {
        // Implementación pausada: requeriría firma AWS v4 no incluida.
        // Se mantiene sin API oficial para evitar dependencia incompleta.
    }

    $search = 'https://www.amazon.com/s?' . http_build_query(['k' => $query]);
    $html = httpGet($search, 12)['body'] ?? '';
    if (!$html) return [];

    if (preg_match_all('~https://[^"\'\\s<>]*m\\.media-amazon\\.com/images/I/[^"\'\\s<>]+\\.(?:jpg|jpeg|png|webp)~i', $html, $m)) {
        foreach (array_slice(array_unique($m[0]), 0, 6) as $img) addCandidates($cands, $img, 'Amazon', $query, 56);
    }
    return array_values($cands);
}

function searchBySkuCandidates(string $sku): array {
    $sku = toSafeQuery($sku);
    $cands = [];
    if ($sku === '') return [];

    $fromWiki = searchWikipediaCandidates("{$sku} producto");
    foreach ($fromWiki as $c) addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score'] - 10);
    $fromOpen = searchOpenFoodFactsCandidates($sku, $sku);
    foreach ($fromOpen as $c) addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    $fromDB = searchDbpediaCandidates($sku);
    foreach ($fromDB as $c) addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    return array_values($cands);
}

$providerByName = [
    'wikipedia' => 'searchWikipediaCandidates',
    'dbpedia'   => 'searchDbpediaCandidates',
    'open_food_facts' => 'searchOpenFoodFactsCandidates',
    'walmart'   => 'searchWalmartCandidates',
    'amazon'    => 'searchAmazonCandidates',
    'sku'       => 'searchBySkuCandidates',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonOut(['status' => 'error', 'msg' => 'Payload inválido']);

    $action = (string)($input['action'] ?? '');
    $rawId = (string)($input['id'] ?? '');
    $productId = safeProductId($rawId);
    $provider = (string)($input['provider'] ?? '');
    $query = toSafeQuery((string)($input['query'] ?? ''));
    $sku = toSafeQuery((string)($input['sku'] ?? ''));

    if ($action === 'search') {
        if (!isset($providerByName[$provider])) jsonOut(['status' => 'error', 'msg' => 'Proveedor inválido']);
        if ($productId === '') jsonOut(['status' => 'error', 'msg' => 'ID de producto inválido']);

        if ($provider === 'open_food_facts') {
            $cands = searchOpenFoodFactsCandidates($query, $sku);
        } elseif ($provider === 'sku') {
            $cands = searchBySkuCandidates($sku !== '' ? $sku : $query);
        } else {
            $cands = $providerByName[$provider]($query);
        }

        jsonOut([
            'status' => 'success',
            'provider' => $provider,
            'product_id' => $productId,
            'candidates' => array_values($cands),
            'count' => count($cands),
        ]);
    }

    if ($action === 'approve') {
        $imgUrl = sanitizeImageUrl((string)($input['url'] ?? ''));
        if ($productId === '') jsonOut(['status' => 'error', 'msg' => 'ID de producto inválido']);
        if ($imgUrl === '') jsonOut(['status' => 'error', 'msg' => 'URL inválida']);

        $dest = $IMAGE_DIR . $productId;
        if (downloadImageToBasePath($imgUrl, $dest)) {
            jsonOut(['status' => 'success', 'msg' => 'Imagen aprobada y guardada']);
        }
        jsonOut(['status' => 'error', 'msg' => 'No se pudo descargar ni guardar la imagen']);
    }

    jsonOut(['status' => 'error', 'msg' => 'Acción no soportada']);
}

// LISTAR PRODUCTOS SIN IMAGEN
$stmt = $pdo->prepare('SELECT codigo, nombre, categoria, descripcion FROM productos WHERE activo = 1 AND id_empresa = ? ORDER BY nombre ASC');
$stmt->execute([$EMP_ID]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sinImagen = [];
foreach ($productos as $p) {
    $safeId = safeProductId((string)$p['codigo']);
    if ($safeId === '') continue;
    if (!hasAnyVariant($IMAGE_DIR . $safeId)) {
        $sinImagen[] = [
            'codigo' => (string)$p['codigo'],
            'safe' => $safeId,
            'nombre' => (string)$p['nombre'],
            'categoria' => (string)($p['categoria'] ?? ''),
            'descripcion' => (string)($p['descripcion'] ?? ''),
        ];
    }
}

$providers = [
    ['key' => 'wikipedia', 'label' => 'Wikipedia'],
    ['key' => 'dbpedia', 'label' => 'DBpedia'],
    ['key' => 'open_food_facts', 'label' => 'Open Food Facts'],
    ['key' => 'walmart', 'label' => 'Walmart'],
    ['key' => 'amazon', 'label' => 'Amazon'],
    ['key' => 'sku', 'label' => 'SKU'],
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Buscador Inteligente de Imágenes</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background:#f3f4f7; padding:20px; }
        .topbar { display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-bottom:15px; }
        .stat-card { background:#fff; border-radius:10px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,.06); }
        .product-card { background:#fff; border-radius:12px; padding:12px; margin-bottom:10px; border:1px solid #e9ecef; }
        .product-title { font-weight:700; line-height:1.3; }
        .provider-row { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
        .candidates { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; margin-top:10px; }
        .candidate { border:1px solid #dee2e6; border-radius:8px; padding:8px; background:#fff; display:flex; flex-direction:column; gap:6px; }
        .candidate img { width:100%; height:130px; object-fit:cover; border-radius:6px; background:#e9ecef; }
        .small-muted { color:#6c757d; font-size:.85rem; }
        .badge-source { width:max-content; }
        .status-area { margin-top:8px; font-size:.88rem; }
        .status-ok { color:#146c43; font-weight:600; }
        .status-ko { color:#a61e4d; font-weight:600; }
        .manual-row { display:flex; gap:6px; margin-top:8px; }
        .manual-row input { max-width:400px; }
        .summary p { margin-bottom:5px; }
    </style>
</head>
<body>
<div class="container" style="max-width:1220px;">
    <div class="stat-card">
        <div class="topbar">
            <div>
                <h3 class="mb-1">🔍 Buscador Inteligente de Imágenes</h3>
                <p class="small text-muted m-0">Productos sin imagen: <strong><?php echo count($sinImagen); ?></strong></p>
                <p class="small text-muted m-0">Carpeta de salida: <strong><?php echo htmlspecialchars($IMAGE_DIR, ENT_QUOTES, 'UTF-8'); ?></strong></p>
            </div>
            <div>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="btnReload" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt"></i> Recargar
                </button>
                <a class="btn btn-outline-primary btn-sm" href="image_hunter.php"><i class="fas fa-images"></i> Google Hunter</a>
                <a class="btn btn-outline-dark btn-sm" href="image_filler.php"><i class="fas fa-magic"></i> Pexels Filler</a>
            </div>
        </div>

        <hr>
        <div class="summary">
            <p class="mb-2"><strong>Acciones rápidas por proveedor:</strong></p>
            <div class="provider-row">
                <button class="btn btn-success btn-sm" onclick="runProviderAll('wikipedia')"><i class="fas fa-book"></i> Auto Wikipedia</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('dbpedia')"><i class="fas fa-network-wired"></i> Auto DBpedia</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('open_food_facts')"><i class="fas fa-lemon"></i> Auto OpenFood</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('walmart')"><i class="fab fa-wikipedia-w"></i> Auto Walmart</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('amazon')"><i class="fab fa-amazon"></i> Auto Amazon</button>
                <button class="btn btn-primary btn-sm" onclick="runProviderAll('sku')"><i class="fas fa-barcode"></i> Auto por SKU</button>
            </div>
        </div>
    </div>

    <div class="mt-3" id="listadoProductos">
        <?php foreach ($sinImagen as $p): ?>
            <div class="product-card" id="card-<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>" data-code="<?php echo htmlspecialchars($p['codigo'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="product-title"><?php echo htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="small-muted">
                    SKU: <strong><?php echo htmlspecialchars($p['codigo'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="ms-2"><?php echo htmlspecialchars($p['categoria'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="manual-row">
                    <input type="text" class="form-control form-control-sm" id="manual-<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Pega URL y aprueba manual">
                    <button class="btn btn-outline-dark btn-sm" data-code="<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>" onclick="approveManual('<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>')">
                        <i class="fas fa-save"></i> Guardar URL manual
                    </button>
                </div>

                <div class="provider-row">
                    <?php foreach ($providers as $prov): ?>
                        <button
                            class="btn btn-outline-primary btn-sm btn-provider"
                            data-action="search-provider"
                            data-code="<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-provider="<?php echo htmlspecialchars($prov['key'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-query="<?php echo htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-sku="<?php echo htmlspecialchars($p['codigo'], ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="searchProvider(this)">
                            <?php echo htmlspecialchars($prov['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="status-area" id="status-<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="candidates" id="cands-<?php echo htmlspecialchars($p['safe'], ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($sinImagen)): ?>
            <div class="product-card text-center">
                <div class="text-success fw-bold fs-4"><i class="fas fa-check-circle"></i> Listo</div>
                <p class="small text-muted m-0">No hay productos sin imagen disponibles.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function escapeHtml(value) {
    const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
    return String(value).replace(/[&<>"']/g, function(m){ return map[m]; });
}

function setStatus(code, text, ok = true) {
    const el = document.getElementById('status-' + code);
    if (!el) return;
    el.innerHTML = `<span class="${ok ? 'status-ok' : 'status-ko'}">${escapeHtml(text)}</span>`;
}

function renderCandidates(code, candidates, sourceLabel) {
    const target = document.getElementById('cands-' + code);
    if (!target) return;
    target.innerHTML = '';
    if (!candidates || candidates.length === 0) {
        target.innerHTML = `<div class="small text-muted">No se encontraron candidatos para <strong>${escapeHtml(sourceLabel || '')}</strong>.</div>`;
        return;
    }

    candidates.forEach((c) => {
        const id = 'cimg-' + code + '-' + Math.random().toString(36).slice(2);
        const wrap = document.createElement('div');
        wrap.className = 'candidate';
        wrap.innerHTML =
            `<img id="${id}" src="${escapeHtml(c.url)}" alt="preview" loading="lazy" onerror="this.style.display='none'">
             <span class="badge text-bg-light badge-source">${escapeHtml(c.source || 'Proveedor')}</span>
             <span class="small-muted">${escapeHtml(c.title || '')}</span>
             <div><span class="small-muted">Score: ${parseInt(c.score || 0, 10)} / 100</span></div>
             <button class="btn btn-sm btn-success" onclick="approveCandidate('${code}', '${escapeHtml(c.url)}')"><i class="fas fa-check"></i> Aprobar imagen</button>`;
        target.appendChild(wrap);
        const img = document.getElementById(id);
        if (img) img.onerror = function(){ this.style.display='none'; };
    });
}

async function requestJson(payload, button) {
    const res = await fetch('image_smart_hunter.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
    }
    const txt = await res.text();
    try {
        const parsed = JSON.parse(txt);
        if (parsed.status === 'error') return parsed;
        return parsed;
    } catch (e) {
        if (button) {
            button.disabled = false;
            button.innerHTML = button.getAttribute('data-label-original') || button.innerHTML;
        }
        const sample = (txt || '').trim();
        throw new Error(sample ? `Respuesta inválida del servidor: ${sample.slice(0, 120)}` : 'Respuesta inválida del servidor');
    }
}

function lockButton(btn, state) {
    if (!btn) return;
    if (!btn.getAttribute('data-label-original')) {
        btn.setAttribute('data-label-original', btn.innerHTML);
    }
    if (state === 'loading') {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando...';
    } else {
        btn.disabled = false;
        btn.innerHTML = btn.getAttribute('data-label-original');
    }
}

async function searchProvider(button) {
    const code = button.getAttribute('data-code');
    const provider = button.getAttribute('data-provider');
    const query = button.getAttribute('data-query');
    const sku = button.getAttribute('data-sku');
    const status = document.getElementById('status-' + code);
    const cands = document.getElementById('cands-' + code);

    lockButton(button, 'loading');
    setStatus(code, 'Buscando...');
    if (status) status.innerHTML = '';
    if (cands) cands.innerHTML = '';

    try {
        const data = await requestJson({ action: 'search', id: code, provider, query, sku }, button);
        if (data.status === 'success') {
            setStatus(code, `Proveedor: ${provider} | resultados: ${data.count || 0}`);
            renderCandidates(code, data.candidates || [], provider);
        } else {
            setStatus(code, data.msg || 'Sin resultados', false);
        }
    } catch (err) {
        setStatus(code, 'Error en búsqueda', false);
    }

    lockButton(button, 'ready');
}

async function approveCandidate(code, url) {
    setStatus(code, 'Guardando...');
    try {
        const data = await requestJson({
            action: 'approve',
            id: code,
            url: url
        });
        if (data.status === 'success') {
            setStatus(code, '✅ Guardado y aprobado', true);
            const card = document.getElementById('card-' + code);
            if (card) {
                card.style.opacity = '.85';
                card.style.background = '#f0fff5';
            }
            const btns = card ? card.querySelectorAll('[data-action="search-provider"]') : [];
            btns.forEach((el) => { el.disabled = true; });
            const candWrap = document.getElementById('cands-' + code);
            if (candWrap) candWrap.innerHTML = '<div class="small text-success">Imagen aprobada para este producto.</div>';
        } else {
            setStatus(code, data.msg || 'No se pudo guardar', false);
        }
    } catch (e) {
        setStatus(code, 'Error de conexión', false);
    }
}

async function approveManual(code) {
    const input = document.getElementById('manual-' + code);
    if (!input) return;
    const url = input.value.trim();
    if (!url) {
        alert('Pegue un URL de imagen antes de aprobar.');
        return;
    }
    await approveCandidate(code, url);
}

async function runProviderAll(provider) {
    const buttons = Array.from(document.querySelectorAll('.btn-provider[data-provider="' + provider + '"]'));
    if (!buttons.length) return;

    for (const btn of buttons) {
        const code = btn.getAttribute('data-code');
        if (!code) continue;
        const status = document.getElementById('status-' + code);
        const hasDone = status && /✅|guardada|Listo/i.test(status.textContent);
        if (hasDone) continue;

        const candsWrap = document.getElementById('cands-' + code);
        if (!candsWrap) continue;

        await searchProvider(btn);
        const result = candsWrap.querySelector('button');
        if (result) {
            result.click();
            await new Promise(r => setTimeout(r, 450));
        } else {
            await new Promise(r => setTimeout(r, 180));
        }
    }

    alert('Proceso automático por proveedor completado.');
}
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
