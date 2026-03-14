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
const PRIMARY_IMAGE_DIR = __DIR__ . '/assets/product_images/';
const TEMP_IMAGE_DIR = '/tmp/palweb_product_images/';

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

function syncTempImagesToPrimary(string $tempDir, string $primaryDir): array {
    $result = ['moved' => 0, 'errors' => []];
    if (!is_dir($tempDir) || !is_dir($primaryDir)) {
        return $result;
    }

    $files = @scandir($tempDir);
    if (!is_array($files)) {
        return $result;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+\.(?:jpg|jpeg|png|webp|avif)$/i', $file)) {
            continue;
        }
        $source = rtrim($tempDir, '/') . '/' . $file;
        $target = rtrim($primaryDir, '/') . '/' . $file;
        if (!is_file($source)) {
            continue;
        }
        if (@copy($source, $target)) {
            @unlink($source);
            $result['moved']++;
        } else {
            $result['errors'][] = $file;
        }
    }
    return $result;
}

$IMAGE_DIR = rtrim(PRIMARY_IMAGE_DIR, '/') . '/';
$IMAGE_DIR_STATUS = isWritableDir($IMAGE_DIR) ? 'ok' : 'unwritable';
$TEMP_IMAGE_COUNT = 0;
$TEMP_SYNC_RESULT = ['moved' => 0, 'errors' => []];
$tempFiles = is_dir(TEMP_IMAGE_DIR) ? glob(rtrim(TEMP_IMAGE_DIR, '/') . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE) : [];
$TEMP_IMAGE_COUNT = is_array($tempFiles) ? count($tempFiles) : 0;
if ($IMAGE_DIR_STATUS === 'ok' && $TEMP_IMAGE_COUNT > 0) {
    $TEMP_SYNC_RESULT = syncTempImagesToPrimary(TEMP_IMAGE_DIR, $IMAGE_DIR);
    $tempFiles = is_dir(TEMP_IMAGE_DIR) ? glob(rtrim(TEMP_IMAGE_DIR, '/') . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE) : [];
    $TEMP_IMAGE_COUNT = is_array($tempFiles) ? count($tempFiles) : 0;
}
$GD_AVAILABLE = extension_loaded('gd') && function_exists('imagecreatefromstring');

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
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PalWebImageHunter/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => (string)($body ?: ''),
            'url' => $url,
            'headers' => [],
            'content_type' => $contentType,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 4,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (compatible; PalWebImageHunter/1.0)',
                'Accept: application/json,text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
                'Connection: close',
            ]),
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $code = 0;
    foreach ($headers as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m)) {
            $code = (int)$m[1];
        }
    }

    $contentType = '';
    foreach ($headers as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
        }
    }

    return [
        'code' => $code,
        'body' => (string)($body ?: ''),
        'url' => $url,
        'headers' => $headers,
        'content_type' => $contentType,
    ];
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

function searchBingImageCandidates(string $query, string $siteDomain, string $regex, string $source, int $score = 54): array {
    $query = trim($query);
    if ($query === '' || $siteDomain === '' || $regex === '') {
        return [];
    }

    $searchUrl = 'https://www.bing.com/images/search?' . http_build_query([
        'q' => 'site:' . $siteDomain . ' ' . $query,
    ]);
    $html = httpGet($searchUrl, 14)['body'] ?? '';
    if ($html === '') {
        return [];
    }

    $cands = [];
    if (preg_match_all($regex, $html, $matches)) {
        foreach (array_slice(array_unique($matches[0]), 0, 8) as $url) {
            $cleanUrl = normalizeCandidateImageUrl((string)$url);
            addCandidates($cands, $cleanUrl, $source, $query, $score);
        }
    }
    return array_values($cands);
}

function hasAnyVariant(string $base): bool {
    foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png'] as $ext) {
        if (file_exists($base . $ext)) return true;
    }
    return false;
}

function toSafeQuery(string $term): string {
    $term = trim((string)$term);
    return $term !== '' ? $term : 'producto';
}

function normalizeCandidateImageUrl(string $url): string {
    $cleanUrl = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($cleanUrl === '') {
        return '';
    }

    if (strpos($cleanUrl, '//') === 0) {
        $cleanUrl = 'https:' . $cleanUrl;
    }

    $cleanUrl = preg_replace('/[\"\\\'].*$/', '', $cleanUrl);
    $cleanUrl = preg_replace('/[\\s<>{}\\]\\[,].*$/', '', $cleanUrl);
    $cleanUrl = preg_replace('/(?:&quot;|&#34;|\\\\u0026quot;).*/i', '', $cleanUrl);
    $cleanUrl = preg_replace('/(?:\\\\u0026|\\\\u003c|\\\\u003e).*/i', '', $cleanUrl);
    $cleanUrl = preg_replace('/\?x-oss-process=.*$/i', '', $cleanUrl);
    return trim((string)$cleanUrl);
}

function detectImageExtension(string $url, string $contentType = ''): string {
    $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    $map = [
        'image/jpeg' => '.jpg',
        'image/jpg' => '.jpg',
        'image/webp' => '.webp',
        'image/avif' => '.avif',
        'image/png' => '.png',
    ];
    if (isset($map[$contentType])) {
        return $map[$contentType];
    }

    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'webp', 'avif', 'png'], true)) {
        return $ext === 'jpeg' ? '.jpeg' : '.' . $ext;
    }
    return '';
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

    if (!function_exists('imagecreatefromstring')) {
        $ext = detectImageExtension($url, (string)($res['content_type'] ?? ''));
        if (!in_array($ext, ['.jpg', '.jpeg', '.webp', '.avif', '.png'], true)) {
            return false;
        }
        return @file_put_contents($basePath . $ext, $res['body']) !== false;
    }

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
            'piprop' => 'thumbnail|original',
            'pithumbsize' => 1200,
            'titles' => $title,
            'origin' => '*'
        ]);
        $imgData = httpGetJson($imgUrl, 10);
        if (!$imgData || empty($imgData['query']['pages'])) continue;

        $pages = $imgData['query']['pages'];
        if (!is_array($pages)) continue;
        foreach ($pages as $pg) {
            $thumb = $pg['original']['source'] ?? ($pg['thumbnail']['source'] ?? null);
            if (!is_string($thumb)) continue;
            $score = 62 + ($idx * 6);
            if (stripos(mb_strtolower($title), mb_strtolower($query)) !== false) $score += 25;
            addCandidates($base, $thumb, 'Wikipedia', $title, $score);
        }
    }
    return array_values($base);
}

function searchTargetCandidates(string $query): array {
    $query = toSafeQuery($query);
    $cands = [];

    $searchUrl = 'https://www.target.com/s?' . http_build_query(['searchTerm' => $query]);
    $html = httpGet($searchUrl, 12)['body'] ?? '';
    if (preg_match_all('~https://target\\.scene7\\.com/is/image/Target/[^"\'\\s<>]+~i', $html, $m)) {
        foreach (array_slice(array_unique($m[0]), 0, 8) as $img) {
            addCandidates($cands, $img, 'Target', $query, 60);
        }
    }
    if (!empty($cands)) {
        return array_values($cands);
    }

    $bing = searchBingImageCandidates(
        $query,
        'target.com',
        '~https://target\\.scene7\\.com/is/image/Target/[^"\'\\s<>]+~i',
        'Target/Bing',
        56
    );
    foreach ($bing as $cand) {
        addCandidates($cands, $cand['url'], $cand['source'], $cand['title'], $cand['score']);
    }
    return array_values($cands);
}

function searchAliExpressCandidates(string $query): array {
    $query = toSafeQuery($query);
    $cands = [];

    $searchUrl = 'https://www.aliexpress.com/wholesale?' . http_build_query(['SearchText' => $query]);
    $html = httpGet($searchUrl, 12)['body'] ?? '';
    if ($html !== '' && preg_match_all('~(?:https?:)?//[^"\'\\s<>]*(?:ae-pic-[^.]+\\.aliexpress-media\\.com|aliexpress-media\\.com|alicdn\\.com)[^"\'\\s<>]+(?:jpg|jpeg|png|webp|avif)(?:_[^"\'\\s<>]*)?~i', $html, $m)) {
        foreach (array_slice(array_unique($m[0]), 0, 12) as $img) {
            $cleanUrl = normalizeCandidateImageUrl((string)$img);
            addCandidates($cands, $cleanUrl, 'AliExpress', $query, 64);
        }
    }
    if (!empty($cands)) {
        return array_values($cands);
    }

    $bing = searchBingImageCandidates(
        $query,
        'aliexpress.com',
        '~(?:https?:)?//[^"\'\\s<>]*(?:ae-pic-[^.]+\\.aliexpress-media\\.com|aliexpress-media\\.com|alicdn\\.com)[^"\'\\s<>]+~i',
        'AliExpress/Bing',
        54
    );
    foreach ($bing as $cand) {
        addCandidates($cands, $cand['url'], $cand['source'], $cand['title'], $cand['score']);
    }
    return array_values($cands);
}

function searchElYerroMenuCandidates(string $query): array {
    $query = toSafeQuery($query);
    $cands = [];
    $searchUrl = 'https://elyerromenu.com/l/search/' . rawurlencode($query);
    $html = httpGet($searchUrl, 16)['body'] ?? '';
    if ($html !== '') {
        if (preg_match_all('~<a[^>]+href="https://elyerromenu\\.com/[^"]+"[^>]*class="[^"]*text-left[^"]*"[^>]*>.*?<img src="(https://img[12]\\.elyerromenu\\.com/images/[^"]+\\.(?:jpg|jpeg|png|webp|avif))"[^>]*>.*?<div class="text-md font-semibold leading-relaxed">\\s*([^<]+)~is', $html, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 8) as $row) {
                $img = html_entity_decode(trim((string)($row[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = trim(html_entity_decode((string)($row[2] ?? $query), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                addCandidates($cands, $img, 'El Yerro Menu', $title !== '' ? $title : $query, 64);
            }
        }
        if (empty($cands) && preg_match_all('~https://img[12]\\.elyerromenu\\.com/images/[^"\'\\s<>]+\\.(?:jpg|jpeg|png|webp|avif)~i', $html, $m)) {
            foreach (array_slice(array_unique($m[0]), 0, 8) as $img) {
                addCandidates($cands, $img, 'El Yerro Menu', $query, 61);
            }
        }
        if (!empty($cands)) {
            return array_values($cands);
        }
    }

    $bing = searchBingImageCandidates(
        $query,
        'elyerromenu.com',
        '~https://img[12]\\.elyerromenu\\.com/images/[^"\'\\s<>]+~i',
        'El Yerro Menu/Bing',
        58
    );
    foreach ($bing as $cand) {
        addCandidates($cands, $cand['url'], $cand['source'], $cand['title'], $cand['score']);
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
    if (!empty($cands)) {
        return array_values($cands);
    }

    $bing = searchBingImageCandidates(
        $query,
        'walmart.com',
        '~https://i5\\.walmartimages\\.com/[^"\'\\s<>]+~i',
        'Walmart/Bing',
        55
    );
    foreach ($bing as $cand) {
        addCandidates($cands, $cand['url'], $cand['source'], $cand['title'], $cand['score']);
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
    if ($html) {
        if (preg_match_all('~https://[^"\'\\s<>]*m\\.media-amazon\\.com/images/I/[^"\'\\s<>]+\\.(?:jpg|jpeg|png|webp)~i', $html, $m)) {
            foreach (array_slice(array_unique($m[0]), 0, 6) as $img) addCandidates($cands, $img, 'Amazon', $query, 56);
        }
    }

    if (!empty($cands)) {
        return array_values($cands);
    }

    $bing = searchBingImageCandidates(
        $query,
        'amazon.com',
        '~https://m\\.media-amazon\\.com/images/I/[^"\'\\s<>]+~i',
        'Amazon/Bing',
        53
    );
    foreach ($bing as $cand) {
        addCandidates($cands, $cand['url'], $cand['source'], $cand['title'], $cand['score']);
    }
    return array_values($cands);
}

function searchBySkuCandidates(string $sku): array {
    $sku = toSafeQuery($sku);
    $cands = [];
    if ($sku === '') return [];

    $fromWalmart = searchWalmartCandidates($sku);
    foreach ($fromWalmart as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    }
    if (!empty($cands)) {
        return array_values($cands);
    }

    $fromTarget = searchTargetCandidates($sku);
    foreach ($fromTarget as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    }

    $fromAmazon = searchAmazonCandidates($sku);
    foreach ($fromAmazon as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    }

    $fromElYerro = searchElYerroMenuCandidates($sku);
    foreach ($fromElYerro as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    }

    $fromAliExpress = searchAliExpressCandidates($sku);
    foreach ($fromAliExpress as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score']);
    }

    $fromWiki = searchWikipediaCandidates("{$sku} producto");
    foreach ($fromWiki as $c) {
        addCandidates($cands, $c['url'], $c['source'], $c['title'], $c['score'] - 10);
    }

    return array_values($cands);
}

$providerByName = [
    'wikipedia' => 'searchWikipediaCandidates',
    'target'    => 'searchTargetCandidates',
    'aliexpress' => 'searchAliExpressCandidates',
    'elyerromenu' => 'searchElYerroMenuCandidates',
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

        if ($provider === 'sku') {
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
        if ($IMAGE_DIR_STATUS !== 'ok') {
            jsonOut(['status' => 'error', 'msg' => 'La carpeta principal no es escribible: ' . $IMAGE_DIR . '. Corrija permisos.']);
        }

        $dest = $IMAGE_DIR . $productId;
        if (downloadImageToBasePath($imgUrl, $dest)) {
            jsonOut(['status' => 'success', 'msg' => 'Imagen aprobada y guardada en ' . $IMAGE_DIR]);
        }
        jsonOut(['status' => 'error', 'msg' => 'No se pudo descargar ni guardar la imagen en ' . $IMAGE_DIR . '. Revise permisos o formato.']);
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
    ['key' => 'target', 'label' => 'Target'],
    ['key' => 'aliexpress', 'label' => 'AliExpress (China)'],
    ['key' => 'elyerromenu', 'label' => 'El Yerro Menu'],
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
    <?php if (!$GD_AVAILABLE): ?>
        <div class="alert alert-warning border-warning shadow-sm">
            <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation"></i> Extensión GD no disponible en PHP</div>
            <div class="small mb-2">
                El módulo puede guardar imágenes originales, pero no podrá convertirlas ni generar variantes optimizadas desde PHP mientras falte GD.
            </div>
            <div class="small">
                En Ubuntu instala GD con:
                <code>sudo apt-get update && sudo apt-get install php-gd</code>
                y luego reinicia Apache o PHP-FPM.
            </div>
        </div>
    <?php endif; ?>
    <?php if ($IMAGE_DIR_STATUS !== 'ok'): ?>
        <div class="alert alert-danger border-danger shadow-sm">
            <div class="fw-bold mb-1"><i class="fas fa-circle-exclamation"></i> Carpeta principal no escribible</div>
            <div class="small mb-1">Las imágenes deben guardarse en <code><?php echo htmlspecialchars($IMAGE_DIR, ENT_QUOTES, 'UTF-8'); ?></code>.</div>
            <div class="small">Corrija permisos de esa ruta. El módulo ya no usa <code>/tmp/palweb_product_images/</code> como destino silencioso.</div>
        </div>
    <?php endif; ?>
    <?php if ($TEMP_SYNC_RESULT['moved'] > 0): ?>
        <div class="alert alert-info border-info shadow-sm">
            <div class="fw-bold mb-1"><i class="fas fa-folder-open"></i> Imágenes migradas desde /tmp</div>
            <div class="small">Se copiaron <strong><?php echo (int)$TEMP_SYNC_RESULT['moved']; ?></strong> imágenes desde <code>/tmp/palweb_product_images/</code> hacia <code><?php echo htmlspecialchars($IMAGE_DIR, ENT_QUOTES, 'UTF-8'); ?></code>.</div>
        </div>
    <?php endif; ?>
    <?php if ($TEMP_IMAGE_COUNT > 0 && $IMAGE_DIR_STATUS !== 'ok'): ?>
        <div class="alert alert-warning border-warning shadow-sm">
            <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation"></i> Imágenes pendientes en /tmp</div>
            <div class="small">Quedan <strong><?php echo (int)$TEMP_IMAGE_COUNT; ?></strong> imágenes en <code>/tmp/palweb_product_images/</code> sin copiar porque la carpeta principal no es escribible.</div>
        </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="topbar">
            <div>
                <h3 class="mb-1">🔍 Buscador Inteligente de Imágenes</h3>
                <p class="small text-muted m-0">Productos sin imagen: <strong><?php echo count($sinImagen); ?></strong></p>
                <p class="small text-muted m-0">Carpeta de salida: <strong><?php echo htmlspecialchars($IMAGE_DIR, ENT_QUOTES, 'UTF-8'); ?></strong></p>
                <p class="small text-muted m-0">Pendientes en /tmp: <strong><?php echo (int)$TEMP_IMAGE_COUNT; ?></strong></p>
            </div>
            <div>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="btnReload" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt"></i> Recargar
                </button>
            </div>
        </div>

        <hr>
        <div class="summary">
            <p class="mb-2"><strong>Acciones rápidas por proveedor:</strong></p>
            <div class="provider-row">
                <button class="btn btn-success btn-sm" onclick="runProviderAll('wikipedia')"><i class="fas fa-book"></i> Auto Wikipedia</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('target')"><i class="fas fa-bullseye"></i> Auto Target</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('aliexpress')"><i class="fas fa-globe-asia"></i> Auto AliExpress</button>
                <button class="btn btn-success btn-sm" onclick="runProviderAll('elyerromenu')"><i class="fas fa-store"></i> Auto El Yerro</button>
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
