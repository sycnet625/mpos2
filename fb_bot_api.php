<?php
ini_set('display_errors', 0);
if (!defined('FB_BOT_API_LIB_ONLY')) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

$FB_QUEUE_FILE = '/tmp/palweb_fb_queue.json';
$FB_TEMPLATES_FILE = '/tmp/palweb_fb_templates.json';
$FB_WORKER_LOG_FILE = '/tmp/palweb_fb_worker.log';
$FB_MANUAL_GROUPS_FILE = '/tmp/palweb_fb_manual_groups.json';
$FB_BROWSER_COOKIES_FILE = '/tmp/palweb_fb_browser_cookies.json';
$FB_BROWSER_LOGIN_STATUS_FILE = '/tmp/palweb_fb_browser_login_status.json';
$FB_BROWSER_LOGIN_RUNNER_LOG = '/tmp/palweb_fb_browser_login_runner.log';
$FB_BROWSER_PROFILE_DIR = '/var/www/fb_bot_browser_profile';
$FB_BROWSER_DISPLAY = ':99';

function fb_require_admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
        exit;
    }
}

function fb_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function fb_read_json_file(string $file, $default = []): array {
    if (!is_file($file)) return is_array($default) ? $default : [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return is_array($default) ? $default : [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : (is_array($default) ? $default : []);
}

function fb_write_json_file(string $file, array $data): bool {
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}

function fb_worker_log(string $message): void {
    $file = $GLOBALS['FB_WORKER_LOG_FILE'] ?? '/tmp/palweb_fb_worker.log';
    @file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function fb_pid_alive($pid): bool {
    $pid = (int)$pid;
    return $pid > 1 && is_dir('/proc/' . $pid);
}

function fb_tail_file(string $file, int $maxLines = 300): string {
    if (!is_file($file)) return '';
    $raw = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($raw)) return '';
    return implode(PHP_EOL, array_slice($raw, -max(1, $maxLines)));
}

function fb_parse_cookie_header(string $header): array {
    $cookies = [];
    foreach (explode(';', $header) as $part) {
        $piece = trim($part);
        if ($piece === '' || !str_contains($piece, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $piece, 2));
        if ($name === '') continue;
        $cookies[] = [
            'name' => $name,
            'value' => $value,
            'domain' => '.facebook.com',
            'path' => '/',
            'httpOnly' => false,
            'secure' => true,
        ];
    }
    return $cookies;
}

function fb_normalize_publish_mode($mode): string {
    $mode = strtolower(trim((string)$mode));
    return in_array($mode, ['facebook','instagram','both'], true) ? $mode : 'both';
}

function fb_public_base_url(): string {
    global $config;
    $website = trim((string)($config['website'] ?? ''));
    if ($website !== '') {
        if (!preg_match('~^https?://~i', $website)) $website = 'https://' . ltrim($website, '/');
        $parts = parse_url($website);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) $origin .= ':' . (int)$parts['port'];
            return $origin;
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (in_array($host, ['127.0.0.1', 'localhost'], true)) return 'https://www.palweb.net';
    return $scheme . '://' . $host;
}

function fb_public_product_image(string $sku): string {
    return fb_public_base_url() . '/image.php?code=' . rawurlencode($sku);
}

function fb_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fb_bot_config (
        id TINYINT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        business_name VARCHAR(120) DEFAULT 'PalWeb Facebook',
        page_name VARCHAR(120) DEFAULT '',
        page_id VARCHAR(80) DEFAULT '',
        page_access_token TEXT DEFAULT NULL,
        enable_instagram TINYINT(1) NOT NULL DEFAULT 0,
        ig_username VARCHAR(120) DEFAULT '',
        ig_user_id VARCHAR(80) DEFAULT '',
        ig_access_token TEXT DEFAULT NULL,
        graph_version VARCHAR(20) DEFAULT 'v23.0',
        worker_key VARCHAR(120) DEFAULT 'palweb_fb_worker',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fb_bot_posts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',
        campaign_id VARCHAR(120) DEFAULT '',
        page_id VARCHAR(80) DEFAULT '',
        page_name VARCHAR(120) DEFAULT '',
        fb_post_id VARCHAR(120) DEFAULT '',
        message_text MEDIUMTEXT,
        status ENUM('success','error') NOT NULL DEFAULT 'success',
        error_text TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at),
        KEY idx_campaign (campaign_id),
        KEY idx_platform (platform)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!fb_has_column($pdo, 'fb_bot_config', 'enable_instagram')) $pdo->exec("ALTER TABLE fb_bot_config ADD COLUMN enable_instagram TINYINT(1) NOT NULL DEFAULT 0");
    if (!fb_has_column($pdo, 'fb_bot_config', 'ig_username')) $pdo->exec("ALTER TABLE fb_bot_config ADD COLUMN ig_username VARCHAR(120) DEFAULT ''");
    if (!fb_has_column($pdo, 'fb_bot_config', 'ig_user_id')) $pdo->exec("ALTER TABLE fb_bot_config ADD COLUMN ig_user_id VARCHAR(80) DEFAULT ''");
    if (!fb_has_column($pdo, 'fb_bot_config', 'ig_access_token')) $pdo->exec("ALTER TABLE fb_bot_config ADD COLUMN ig_access_token TEXT DEFAULT NULL");
    if (!fb_has_column($pdo, 'fb_bot_posts', 'platform')) $pdo->exec("ALTER TABLE fb_bot_posts ADD COLUMN platform ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook' AFTER id");

    $pdo->exec("INSERT IGNORE INTO fb_bot_config (id,enabled,business_name,page_name,page_id,enable_instagram,ig_username,ig_user_id,graph_version,worker_key) VALUES (1,0,'PalWeb Facebook','','',0,'','','v23.0','palweb_fb_worker')");
}

function fb_cfg(PDO $pdo): array {
    $row = $pdo->query("SELECT * FROM fb_bot_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function fb_local_time_info(): array {
    $tz = new DateTimeZone('America/Havana');
    $now = new DateTimeImmutable('now', $tz);
    return [
        'day' => (int)$now->format('w'),
        'hm' => $now->format('H:i'),
        'key' => $now->format('Y-m-d_H:i'),
        'ts' => $now->getTimestamp(),
    ];
}

function fb_clean_targets(array $cfg): array {
    $targets = [];
    $pageId = substr(trim((string)($cfg['page_id'] ?? '')), 0, 80);
    $pageName = substr(trim((string)($cfg['page_name'] ?? '')), 0, 120);
    if ($pageId !== '') {
        $targets[] = [
            'id' => $pageId,
            'name' => $pageName !== '' ? $pageName : $pageId,
            'type' => 'page',
            'token' => ''
        ];
    }
    foreach (fb_group_targets() as $group) {
        $targets[] = $group;
    }
    return $targets;
}

function fb_group_targets(): array {
    $data = fb_read_json_file($GLOBALS['FB_MANUAL_GROUPS_FILE'], ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $out = [];
    foreach ($rows as $row) {
        $id = substr(trim((string)($row['id'] ?? '')), 0, 80);
        if ($id === '') continue;
        $enabled = !empty($row['enabled']) ? 1 : 0;
        if (!$enabled) continue;
        $name = substr(trim((string)($row['name'] ?? $id)), 0, 180);
        $url = substr(trim((string)($row['url'] ?? '')), 0, 500);
        $token = trim((string)($row['access_token'] ?? ''));
        $out[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : $id,
            'url' => $url,
            'type' => 'group',
            'token' => $token
        ];
    }
    return $out;
}

function fb_catalog_for_campaigns(PDO $pdo, array $config): array {
    $idEmpresa = (int)($config['id_empresa'] ?? 0);
    $idAlmacen = (int)($config['id_almacen'] ?? 1);
    $sql = "SELECT p.codigo, p.nombre, p.precio, p.es_reservable, COALESCE(SUM(s.cantidad),0) AS stock_total
            FROM productos p
            LEFT JOIN stock_almacen s ON s.id_producto = p.codigo AND s.id_almacen = ?
            WHERE p.activo = 1 AND p.id_empresa = ?
            GROUP BY p.codigo, p.nombre, p.precio, p.es_reservable
            ORDER BY p.nombre ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$idAlmacen, $idEmpresa]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fb_my_page_payload(PDO $pdo, array $config): array {
    $rows = fb_catalog_for_campaigns($pdo, $config);
    $products = [];
    $reservables = [];
    foreach ($rows as $r) {
        $sku = trim((string)($r['codigo'] ?? ''));
        if ($sku === '') continue;
        $name = trim((string)($r['nombre'] ?? $sku));
        $stock = (float)($r['stock_total'] ?? 0);
        if ($stock > 0) {
            $products[] = [
                'id' => $sku,
                'name' => $name,
                'price' => (float)($r['precio'] ?? 0),
                'image' => fb_public_product_image($sku),
            ];
        }
        if (!empty($r['es_reservable'])) $reservables[] = $name;
    }
    $lines = [];
    if ($reservables) {
        $lines[] = 'Productos reservables:';
        foreach ($reservables as $name) $lines[] = '- ' . $name;
    }
    $lines[] = 'En www.palweb.net se pueden comprar automaticamente.';
    return ['products' => $products, 'reservables' => $reservables, 'outro_text' => implode("\n", $lines)];
}

function fb_normalize_campaign_products($products): array {
    $cleanProducts = [];
    foreach ((array)$products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'image' => trim((string)($p['image'] ?? fb_public_product_image($pid)))
        ];
    }
    return $cleanProducts;
}

function fb_normalize_campaign_banners($bannerImages): array {
    $cleanBannerImages = [];
    foreach ((array)$bannerImages as $img) {
        $url = trim((string)($img['url'] ?? $img));
        if ($url === '') continue;
        $cleanBannerImages[] = [
            'url' => substr($url, 0, 500),
            'name' => substr(trim((string)($img['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'banner'))), 0, 180)
        ];
        if (count($cleanBannerImages) >= 3) break;
    }
    return $cleanBannerImages;
}

function fb_campaign_snapshot(array $job): array {
    $days = array_values(array_map('intval', is_array($job['schedule_days'] ?? null) ? $job['schedule_days'] : []));
    sort($days);
    return [
        'name' => substr(trim((string)($job['name'] ?? '')), 0, 120),
        'campaign_group' => substr(trim((string)($job['campaign_group'] ?? 'General')), 0, 80) ?: 'General',
        'publish_mode' => fb_normalize_publish_mode($job['publish_mode'] ?? 'both'),
        'text' => trim((string)($job['text'] ?? '')),
        'outro_text' => trim((string)($job['outro_text'] ?? '')),
        'template_id' => substr(trim((string)($job['template_id'] ?? '')), 0, 80),
        'preview_html' => substr(trim((string)($job['preview_html'] ?? '')), 0, 120000),
        'products' => fb_normalize_campaign_products($job['products'] ?? []),
        'banner_images' => fb_normalize_campaign_banners($job['banner_images'] ?? []),
        'schedule_enabled' => !empty($job['schedule_enabled']) ? 1 : 0,
        'schedule_time' => substr(trim((string)($job['schedule_time'] ?? '')), 0, 5),
        'schedule_days' => $days,
        'status' => substr(trim((string)($job['status'] ?? 'scheduled')), 0, 20),
    ];
}

function fb_campaign_add_version(array &$job, string $type, ?array $before, string $note = ''): void {
    if (!is_array($job['versions'] ?? null)) $job['versions'] = [];
    $job['versions'][] = [
        'at' => date('c'),
        'type' => substr(trim($type), 0, 40),
        'note' => substr(trim($note), 0, 220),
        'actor' => substr(trim((string)($_SESSION['admin_name'] ?? 'admin')), 0, 120),
        'before' => $before,
        'after' => fb_campaign_snapshot($job),
    ];
    if (count($job['versions']) > 25) {
        $job['versions'] = array_slice($job['versions'], -25);
    }
}

function fb_clone_campaign(array $job): array {
    $now = time();
    $name = trim((string)($job['name'] ?? 'Campaña'));
    $clone = [
        'id' => 'fbpromo_' . date('Ymd_His', $now) . '_' . bin2hex(random_bytes(3)),
        'status' => !empty($job['schedule_enabled']) ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => mb_substr(($name !== '' ? $name : 'Campaña') . ' (Copia)', 0, 120),
        'campaign_group' => substr(trim((string)($job['campaign_group'] ?? 'General')), 0, 80) ?: 'General',
        'publish_mode' => fb_normalize_publish_mode($job['publish_mode'] ?? 'both'),
        'preview_html' => (string)($job['preview_html'] ?? ''),
        'template_id' => substr(trim((string)($job['template_id'] ?? '')), 0, 80),
        'text' => trim((string)($job['text'] ?? '')),
        'banner_images' => array_values(is_array($job['banner_images'] ?? null) ? $job['banner_images'] : []),
        'outro_text' => trim((string)($job['outro_text'] ?? '')),
        'targets' => array_values(is_array($job['targets'] ?? null) ? $job['targets'] : []),
        'products' => array_values(is_array($job['products'] ?? null) ? $job['products'] : []),
        'schedule_enabled' => !empty($job['schedule_enabled']) ? 1 : 0,
        'schedule_time' => substr(trim((string)($job['schedule_time'] ?? '')), 0, 5),
        'schedule_days' => array_values(is_array($job['schedule_days'] ?? null) ? $job['schedule_days'] : []),
        'last_schedule_key' => '',
        'next_run_at' => !empty($job['schedule_enabled']) ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => [],
        'versions' => []
    ];
    fb_campaign_add_version($clone, 'clone', fb_campaign_snapshot($job), 'Clonada desde ' . ($job['id'] ?? 'campaña'));
    return $clone;
}

function fb_log_post(PDO $pdo, string $platform, string $campaignId, string $pageId, string $pageName, string $fbPostId, string $message, string $status='success', string $error=''): void {
    $st = $pdo->prepare("INSERT INTO fb_bot_posts (platform,campaign_id,page_id,page_name,fb_post_id,message_text,status,error_text) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$platform, $campaignId, $pageId, $pageName, $fbPostId, $message, $status, $error]);
}

function fb_graph_request(array $cfg, string $path, array $params = [], string $method = 'POST', ?string $tokenOverride = null): array {
    $token = trim((string)($tokenOverride ?? $cfg['page_access_token'] ?? ''));
    if ($token === '') return ['ok' => false, 'error' => 'Falta page access token'];
    $version = trim((string)($cfg['graph_version'] ?? 'v23.0')) ?: 'v23.0';
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . $path;
    $params['access_token'] = $token;
    if (strtoupper($method) === 'GET') {
        $url .= '?' . http_build_query($params);
    }
    $raw = false;
    $err = '';
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        if (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['User-Agent: PalWebFB/1.0']
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $requestHeaders = [
            'User-Agent: PalWebFB/1.0',
            'Accept: application/json'
        ];
        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'ignore_errors' => true,
                'timeout' => 45,
                'header' => implode("\r\n", $requestHeaders),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        if (strtoupper($method) !== 'GET') {
            $opts['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
            $opts['http']['content'] = http_build_query($params);
        }
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        $respHeaders = $http_response_header ?? [];
        foreach ((array)$respHeaders as $hdr) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$hdr, $m)) {
                $code = (int)$m[1];
                break;
            }
        }
        if ($raw === false) {
            $last = error_get_last();
            $err = (string)($last['message'] ?? 'Error desconocido');
        }
    }
    if ($raw === false) return ['ok' => false, 'error' => $err !== '' ? $err : 'Error desconocido'];
    $json = json_decode($raw, true);
    if ($code >= 400 || isset($json['error'])) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'error' => $msg, 'raw' => $json ?: $raw];
    }
    return ['ok' => true, 'data' => $json ?: ['raw' => $raw]];
}

function fb_build_message(array $job): string {
    $lines = [];
    $text = trim((string)($job['text'] ?? ''));
    if ($text !== '') $lines[] = $text;
    $products = is_array($job['products'] ?? null) ? $job['products'] : [];
    if ($products) {
        if ($text !== '') $lines[] = '';
        $lines[] = 'Productos destacados:';
        foreach (array_slice($products, 0, 18) as $p) {
            $name = trim((string)($p['name'] ?? $p['id'] ?? 'Producto'));
            $price = (float)($p['price'] ?? 0);
            $lines[] = '- ' . $name . ' - $' . number_format($price, 2);
        }
    }
    $outro = trim((string)($job['outro_text'] ?? ''));
    if ($outro !== '') {
        if ($lines) $lines[] = '';
        $lines[] = $outro;
    }
    return trim(implode("\n", $lines));
}

function fb_collect_media(array $job): array {
    $media = [];
    foreach ((array)($job['banner_images'] ?? []) as $img) {
        $url = trim((string)($img['url'] ?? $img));
        if ($url !== '') $media[$url] = $url;
    }
    foreach (array_slice((array)($job['products'] ?? []), 0, 6) as $p) {
        $url = trim((string)($p['image'] ?? ''));
        if ($url !== '') $media[$url] = $url;
    }
    return array_values($media);
}

function fb_instagram_token(array $cfg): string {
    $token = trim((string)($cfg['ig_access_token'] ?? ''));
    if ($token !== '') return $token;
    return trim((string)($cfg['page_access_token'] ?? ''));
}

function fb_wait_instagram_container(array $cfg, string $creationId, int $timeoutSeconds = 40): array {
    $deadline = time() + max(10, $timeoutSeconds);
    do {
        $res = fb_graph_request(
            $cfg,
            '/' . rawurlencode($creationId),
            ['fields' => 'status_code,status'],
            'GET',
            fb_instagram_token($cfg)
        );
        if (!$res['ok']) return $res;
        $data = $res['data'] ?? [];
        $status = strtoupper((string)($data['status_code'] ?? $data['status'] ?? ''));
        if ($status === '' || in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
            return ['ok' => true, 'data' => $data];
        }
        if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
            return ['ok' => false, 'error' => 'Instagram media status: ' . $status];
        }
        usleep(1500000);
    } while (time() < $deadline);
    return ['ok' => false, 'error' => 'Timeout esperando procesamiento de media en Instagram'];
}

function fb_publish_instagram(PDO $pdo, array $cfg, array $job): array {
    if (empty($cfg['enable_instagram'])) return ['ok' => true, 'skipped' => true, 'messages_sent' => 0];
    $igUserId = trim((string)($cfg['ig_user_id'] ?? ''));
    $igName = trim((string)($cfg['ig_username'] ?? $igUserId));
    if ($igUserId === '') return ['ok' => false, 'error' => 'Falta Instagram User ID'];
    $token = fb_instagram_token($cfg);
    if ($token === '') return ['ok' => false, 'error' => 'Falta Instagram access token'];

    $message = fb_build_message($job);
    $media = fb_collect_media($job);
    if (!$media) return ['ok' => false, 'error' => 'Instagram requiere al menos una imagen pública'];

    if (count($media) === 1) {
        $create = fb_graph_request(
            $cfg,
            '/' . rawurlencode($igUserId) . '/media',
            ['image_url' => $media[0], 'caption' => $message],
            'POST',
            $token
        );
        if (!$create['ok']) return $create;
        $creationId = (string)($create['data']['id'] ?? '');
        if ($creationId === '') return ['ok' => false, 'error' => 'Instagram no devolvió creation id'];
        $wait = fb_wait_instagram_container($cfg, $creationId);
        if (!$wait['ok']) return $wait;
        $publish = fb_graph_request(
            $cfg,
            '/' . rawurlencode($igUserId) . '/media_publish',
            ['creation_id' => $creationId],
            'POST',
            $token
        );
        if (!$publish['ok']) return $publish;
        fb_log_post($pdo, 'instagram', (string)($job['id'] ?? ''), $igUserId, $igName, (string)($publish['data']['id'] ?? ''), $message, 'success', '');
        return ['ok' => true, 'post_id' => (string)($publish['data']['id'] ?? ''), 'messages_sent' => 1];
    }

    $children = [];
    foreach (array_slice($media, 0, 10) as $url) {
        $createItem = fb_graph_request(
            $cfg,
            '/' . rawurlencode($igUserId) . '/media',
            ['image_url' => $url, 'is_carousel_item' => 'true'],
            'POST',
            $token
        );
        if (!$createItem['ok']) return $createItem;
        $childId = (string)($createItem['data']['id'] ?? '');
        if ($childId === '') return ['ok' => false, 'error' => 'Instagram no devolvió child creation id'];
        $waitChild = fb_wait_instagram_container($cfg, $childId);
        if (!$waitChild['ok']) return $waitChild;
        $children[] = $childId;
    }
    $carousel = fb_graph_request(
        $cfg,
        '/' . rawurlencode($igUserId) . '/media',
        ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $message],
        'POST',
        $token
    );
    if (!$carousel['ok']) return $carousel;
    $creationId = (string)($carousel['data']['id'] ?? '');
    if ($creationId === '') return ['ok' => false, 'error' => 'Instagram no devolvió creation id del carrusel'];
    $wait = fb_wait_instagram_container($cfg, $creationId, 60);
    if (!$wait['ok']) return $wait;
    $publish = fb_graph_request(
        $cfg,
        '/' . rawurlencode($igUserId) . '/media_publish',
        ['creation_id' => $creationId],
        'POST',
        $token
    );
    if (!$publish['ok']) return $publish;
    fb_log_post($pdo, 'instagram', (string)($job['id'] ?? ''), $igUserId, $igName, (string)($publish['data']['id'] ?? ''), $message, 'success', '');
    return ['ok' => true, 'post_id' => (string)($publish['data']['id'] ?? ''), 'messages_sent' => count($children)];
}

function fb_publish_campaign(PDO $pdo, array $cfg, array $job, array $target): array {
    $pageId = trim((string)($target['id'] ?? $cfg['page_id'] ?? ''));
    $pageName = trim((string)($target['name'] ?? $cfg['page_name'] ?? $pageId));
    $targetType = trim((string)($target['type'] ?? 'page')) ?: 'page';
    $tokenOverride = trim((string)($target['token'] ?? ''));
    if ($pageId === '') return ['ok' => false, 'error' => 'Falta page_id'];
    $message = fb_build_message($job);
    $media = fb_collect_media($job);
    if (!$media) {
        $res = fb_graph_request($cfg, '/' . rawurlencode($pageId) . '/feed', ['message' => $message], 'POST', $tokenOverride !== '' ? $tokenOverride : null);
        if (!$res['ok']) return $res;
        fb_log_post($pdo, 'facebook', (string)($job['id'] ?? ''), $pageId, $pageName, (string)($res['data']['id'] ?? ''), $message, 'success', '');
        return ['ok' => true, 'post_id' => (string)($res['data']['id'] ?? ''), 'messages_sent' => 1, 'facebook_sent' => 1];
    }
    if (count($media) === 1) {
        $params = $targetType === 'group'
            ? ['url' => $media[0], 'message' => $message]
            : ['url' => $media[0], 'caption' => $message];
        $res = fb_graph_request($cfg, '/' . rawurlencode($pageId) . '/photos', $params, 'POST', $tokenOverride !== '' ? $tokenOverride : null);
        if (!$res['ok']) return $res;
        fb_log_post($pdo, 'facebook', (string)($job['id'] ?? ''), $pageId, $pageName, (string)($res['data']['post_id'] ?? $res['data']['id'] ?? ''), $message, 'success', '');
        return ['ok' => true, 'post_id' => (string)($res['data']['post_id'] ?? $res['data']['id'] ?? ''), 'messages_sent' => 1, 'facebook_sent' => 1];
    }
    $attached = [];
    foreach ($media as $idx => $url) {
        $upload = fb_graph_request($cfg, '/' . rawurlencode($pageId) . '/photos', ['url' => $url, 'published' => 'false'], 'POST', $tokenOverride !== '' ? $tokenOverride : null);
        if (!$upload['ok']) return $upload;
        $mediaId = (string)($upload['data']['id'] ?? '');
        if ($mediaId === '') return ['ok' => false, 'error' => 'Meta no devolvió media id'];
        $attached['attached_media[' . $idx . ']'] = json_encode(['media_fbid' => $mediaId], JSON_UNESCAPED_UNICODE);
    }
    $params = ['message' => $message] + $attached;
    $res = fb_graph_request($cfg, '/' . rawurlencode($pageId) . '/feed', $params, 'POST', $tokenOverride !== '' ? $tokenOverride : null);
    if (!$res['ok']) return $res;
    fb_log_post($pdo, 'facebook', (string)($job['id'] ?? ''), $pageId, $pageName, (string)($res['data']['id'] ?? ''), $message, 'success', '');
    return ['ok' => true, 'post_id' => (string)($res['data']['id'] ?? ''), 'messages_sent' => count($media) + 1, 'facebook_sent' => count($media) + 1];
}

function fb_process_queue(PDO $pdo, array $cfg): array {
    $queue = fb_read_json_file($GLOBALS['FB_QUEUE_FILE'], ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $info = fb_local_time_info();
    $processed = 0;
    $changed = false;

    foreach ($jobs as &$job) {
        if (!is_array($job)) continue;
        if (!is_array($job['log'] ?? null)) $job['log'] = [];

        if (!empty($job['schedule_enabled']) && ($job['status'] ?? '') === 'scheduled') {
            $days = is_array($job['schedule_days'] ?? null) ? $job['schedule_days'] : [];
            $time = trim((string)($job['schedule_time'] ?? ''));
            if (in_array($info['day'], array_map('intval', $days), true) && $time !== '' && $time <= $info['hm']) {
                if ((string)($job['last_schedule_key'] ?? '') !== $info['key']) {
                    $job['status'] = 'queued';
                    $job['next_run_at'] = $info['ts'];
                    $job['current_index'] = 0;
                    $changed = true;
                }
            }
        }

        if (($job['status'] ?? '') !== 'queued') continue;
        if ((int)($job['next_run_at'] ?? 0) > $info['ts']) continue;
        if (empty($cfg['enabled'])) break;

        $publishMode = fb_normalize_publish_mode($job['publish_mode'] ?? 'both');
        $targets = is_array($job['targets'] ?? null) ? $job['targets'] : fb_clean_targets($cfg);
        if (in_array($publishMode, ['facebook','both'], true) && !$targets) {
            $job['status'] = 'error';
            $job['log'][] = ['at' => date('c'), 'type' => 'publish', 'ok' => false, 'target_id' => '', 'target_name' => '', 'messages_sent' => 0, 'error' => 'No hay página configurada'];
            fb_worker_log('Campaña ' . ($job['id'] ?? '-') . ' falló: no hay página configurada');
            $changed = true;
            $processed++;
            continue;
        }
        if ($publishMode === 'instagram' && !empty($cfg['enable_instagram']) && trim((string)($cfg['ig_user_id'] ?? '')) === '') {
            $job['status'] = 'error';
            $job['log'][] = ['at' => date('c'), 'type' => 'publish', 'ok' => false, 'target_id' => '', 'target_name' => '', 'messages_sent' => 0, 'error' => 'No hay Instagram User ID configurado'];
            fb_worker_log('Campaña ' . ($job['id'] ?? '-') . ' falló: no hay Instagram User ID configurado');
            $changed = true;
            $processed++;
            continue;
        }

        $job['status'] = 'running';
        $changed = true;
        $allOk = true;
        $sent = 0;
        $effectiveTargets = $targets ?: [['id' => (string)($cfg['ig_user_id'] ?? 'instagram'), 'name' => (string)($cfg['ig_username'] ?? 'Instagram')]];
        foreach ($effectiveTargets as $target) {
            $resFb = ['ok' => true, 'skipped' => true, 'messages_sent' => 0];
            $resIg = ['ok' => true, 'skipped' => true, 'messages_sent' => 0];
            if (in_array($publishMode, ['facebook','both'], true)) {
                $resFb = fb_publish_campaign($pdo, $cfg, $job, $target);
            }
            if (in_array($publishMode, ['instagram','both'], true)) {
                $resIg = fb_publish_instagram($pdo, $cfg, $job);
            }
            $okFb = !empty($resFb['ok']);
            $okIg = !empty($resIg['ok']);
            $ok = $okFb && $okIg;
            $allOk = $allOk && $ok;
            $sent += (int)($resFb['messages_sent'] ?? 0) + (int)($resIg['messages_sent'] ?? 0);
            $errorParts = [];
            if (!$okFb) $errorParts[] = 'Facebook: ' . (string)($resFb['error'] ?? 'Error');
            if (!$okIg && empty($resIg['skipped'])) $errorParts[] = 'Instagram: ' . (string)($resIg['error'] ?? 'Error');
            $job['log'][] = [
                'at' => date('c'),
                'type' => 'publish',
                'ok' => $ok,
                'target_id' => (string)($target['id'] ?? ''),
                'target_name' => (string)($target['name'] ?? $target['id'] ?? ''),
                'messages_sent' => (int)($resFb['messages_sent'] ?? 0) + (int)($resIg['messages_sent'] ?? 0),
                'error' => $ok ? '' : implode(' | ', $errorParts),
                'facebook_post_id' => (string)($resFb['post_id'] ?? ''),
                'instagram_post_id' => empty($resIg['skipped']) ? (string)($resIg['post_id'] ?? '') : '',
                'publish_mode' => $publishMode
            ];
        }
        $job['current_index'] = count($effectiveTargets);
        if (!empty($job['schedule_enabled'])) {
            $job['status'] = $allOk ? 'scheduled' : 'error';
            $job['last_schedule_key'] = $allOk ? $info['key'] : (string)($job['last_schedule_key'] ?? '');
            $job['next_run_at'] = $info['ts'] + 60;
            if (!$allOk) $job['current_index'] = 0;
        } else {
            $job['status'] = $allOk ? 'done' : 'error';
        }
        fb_worker_log('Campaña ' . ($job['id'] ?? '-') . ' modo=' . $publishMode . ' estado=' . $job['status'] . ' publicaciones=' . $sent);
        $changed = true;
        $processed++;
    }
    unset($job);

    if ($changed) fb_write_json_file($GLOBALS['FB_QUEUE_FILE'], ['jobs' => $jobs]);
    return ['processed' => $processed];
}

if (!defined('FB_BOT_API_LIB_ONLY')) {
fb_ensure_tables($pdo);
$cfg = fb_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_posts','promo_products','promo_my_page_payload','promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete','promo_clone','promo_import','test_post','worker_logs','manual_groups',
    'save_browser_cookies','browser_groups_scrape','browser_login','browser_login_status','test_group'
];
if (in_array($action, $adminActions, true)) fb_require_admin_session();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_config') {
    $safe = $cfg;
    if (!empty($safe['page_access_token'])) $safe['page_access_token'] = '************';
    if (!empty($safe['ig_access_token'])) $safe['ig_access_token'] = '************';
    echo json_encode(['status' => 'success', 'config' => $safe]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_config') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string)($in['page_access_token'] ?? ''));
    if ($token === '') $token = (string)($cfg['page_access_token'] ?? '');
    $igToken = trim((string)($in['ig_access_token'] ?? ''));
    if ($igToken === '') $igToken = (string)($cfg['ig_access_token'] ?? '');
    $st = $pdo->prepare("UPDATE fb_bot_config SET enabled=?, business_name=?, page_name=?, page_id=?, page_access_token=?, enable_instagram=?, ig_username=?, ig_user_id=?, ig_access_token=?, graph_version=?, worker_key=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        substr(trim((string)($in['business_name'] ?? 'PalWeb Facebook')), 0, 120),
        substr(trim((string)($in['page_name'] ?? '')), 0, 120),
        substr(trim((string)($in['page_id'] ?? '')), 0, 80),
        $token,
        !empty($in['enable_instagram']) ? 1 : 0,
        substr(trim((string)($in['ig_username'] ?? '')), 0, 120),
        substr(trim((string)($in['ig_user_id'] ?? '')), 0, 80),
        $igToken,
        substr(trim((string)($in['graph_version'] ?? 'v23.0')), 0, 20),
        substr(trim((string)($in['worker_key'] ?? ($cfg['worker_key'] ?? 'palweb_fb_worker'))), 0, 120),
    ]);
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'stats') {
    $stats = [
        'enabled' => !empty($cfg['enabled']) ? 1 : 0,
        'posts_today' => (int)$pdo->query("SELECT COUNT(*) FROM fb_bot_posts WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'success_today' => (int)$pdo->query("SELECT COUNT(*) FROM fb_bot_posts WHERE DATE(created_at)=CURDATE() AND status='success'")->fetchColumn(),
        'errors_today' => (int)$pdo->query("SELECT COUNT(*) FROM fb_bot_posts WHERE DATE(created_at)=CURDATE() AND status='error'")->fetchColumn(),
        'facebook_today' => (int)$pdo->query("SELECT COUNT(*) FROM fb_bot_posts WHERE DATE(created_at)=CURDATE() AND platform='facebook'")->fetchColumn(),
        'instagram_today' => (int)$pdo->query("SELECT COUNT(*) FROM fb_bot_posts WHERE DATE(created_at)=CURDATE() AND platform='instagram'")->fetchColumn(),
        'instagram_enabled' => !empty($cfg['enable_instagram']) ? 1 : 0,
    ];
    echo json_encode(['status' => 'success', 'stats' => $stats]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'recent_posts') {
    $rows = $pdo->query("SELECT id,platform,campaign_id,page_id,page_name,fb_post_id,status,error_text,created_at,message_text FROM fb_bot_posts ORDER BY id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'worker_logs') {
    echo json_encode(['status' => 'success', 'logs' => fb_tail_file($FB_WORKER_LOG_FILE, 400)]); exit;
}

if ($action === 'manual_groups') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = fb_read_json_file($FB_MANUAL_GROUPS_FILE, ['rows' => []]);
        $rows = [];
        foreach ((array)($data['rows'] ?? []) as $row) {
            $id = substr(trim((string)($row['id'] ?? '')), 0, 80);
            $name = substr(trim((string)($row['name'] ?? '')), 0, 180);
            $url = substr(trim((string)($row['url'] ?? '')), 0, 500);
            $enabled = !empty($row['enabled']) ? 1 : 0;
            $token = trim((string)($row['access_token'] ?? ''));
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'enabled' => $enabled,
                'access_token' => $token !== '' ? '************' : ''
            ];
        }
        echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $rows = [];
        foreach ((array)($in['rows'] ?? []) as $row) {
            $id = substr(trim((string)($row['id'] ?? '')), 0, 80);
            $name = substr(trim((string)($row['name'] ?? '')), 0, 180);
            $url = substr(trim((string)($row['url'] ?? '')), 0, 500);
            $enabled = !empty($row['enabled']) ? 1 : 0;
            $token = trim((string)($row['access_token'] ?? ''));
            if ($id === '' && $name === '' && $url === '') continue;
            $storedToken = $token;
            if ($storedToken === '' && !empty($row['keep_token'])) {
                $prevData = fb_read_json_file($FB_MANUAL_GROUPS_FILE, ['rows' => []]);
                foreach ((array)($prevData['rows'] ?? []) as $prev) {
                    if ((string)($prev['id'] ?? '') === $id && $id !== '') {
                        $storedToken = trim((string)($prev['access_token'] ?? ''));
                        break;
                    }
                }
            }
            $rows[] = ['id' => $id, 'name' => $name, 'url' => $url, 'enabled' => $enabled, 'access_token' => $storedToken];
        }
        if (!fb_write_json_file($FB_MANUAL_GROUPS_FILE, ['rows' => $rows])) {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar listado manual']); exit;
        }
        $safeRows = array_map(static function ($row) {
            return [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'url' => (string)($row['url'] ?? ''),
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'access_token' => trim((string)($row['access_token'] ?? '')) !== '' ? '************' : ''
            ];
        }, $rows);
        echo json_encode(['status' => 'success', 'rows' => $safeRows]); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_browser_cookies') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $cookieHeader = trim((string)($in['cookie_header'] ?? ''));
    if ($cookieHeader === '') {
        echo json_encode(['status' => 'error', 'msg' => 'Pega primero el header Cookie']); exit;
    }
    $cookies = fb_parse_cookie_header($cookieHeader);
    if (!$cookies) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudieron extraer cookies válidas']); exit;
    }
    if (!fb_write_json_file($FB_BROWSER_COOKIES_FILE, ['cookies' => $cookies, 'updated_at' => date('c')])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudieron guardar cookies']); exit;
    }
    echo json_encode(['status' => 'success', 'count' => count($cookies)]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'browser_groups_scrape') {
    $cookieData = fb_read_json_file($FB_BROWSER_COOKIES_FILE, ['cookies' => []]);
    $cookies = is_array($cookieData['cookies'] ?? null) ? $cookieData['cookies'] : [];
    if (!$cookies) {
        echo json_encode(['status' => 'error', 'msg' => 'No hay cookies guardadas para Facebook']); exit;
    }
    $script = __DIR__ . '/fb_group_scraper.js';
    if (!is_file($script)) {
        echo json_encode(['status' => 'error', 'msg' => 'No existe el scraper de grupos']); exit;
    }
    $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($FB_BROWSER_COOKIES_FILE) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $raw = trim(implode("\n", $output));
    $json = json_decode($raw, true);
    if ($code !== 0 || !is_array($json)) {
        echo json_encode(['status' => 'error', 'msg' => 'Fallo ejecutando scraper', 'raw' => $raw]); exit;
    }
    if (($json['status'] ?? 'error') !== 'success') {
        echo json_encode(['status' => 'error', 'msg' => (string)($json['msg'] ?? 'No se pudieron obtener grupos'), 'raw' => $json]); exit;
    }
    $found = is_array($json['rows'] ?? null) ? $json['rows'] : [];
    $stored = fb_read_json_file($FB_MANUAL_GROUPS_FILE, ['rows' => []]);
    $rows = is_array($stored['rows'] ?? null) ? $stored['rows'] : [];
    $indexed = [];
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if ($id !== '') $indexed[$id] = $row;
    }
    foreach ($found as $row) {
        $id = substr(trim((string)($row['id'] ?? '')), 0, 80);
        if ($id === '') continue;
        $prev = $indexed[$id] ?? [];
        $indexed[$id] = [
            'id' => $id,
            'name' => substr(trim((string)($row['name'] ?? ($prev['name'] ?? $id))), 0, 180),
            'url' => substr(trim((string)($row['url'] ?? ($prev['url'] ?? ''))), 0, 500),
            'enabled' => !empty($prev['enabled']) ? 1 : 0,
            'access_token' => trim((string)($prev['access_token'] ?? '')),
        ];
    }
    $merged = array_values($indexed);
    if (!fb_write_json_file($FB_MANUAL_GROUPS_FILE, ['rows' => $merged])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar la lista escaneada']); exit;
    }
    $safeRows = array_map(static function ($row) {
        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'url' => (string)($row['url'] ?? ''),
            'enabled' => !empty($row['enabled']) ? 1 : 0,
            'access_token' => trim((string)($row['access_token'] ?? '')) !== '' ? '************' : ''
        ];
    }, $merged);
    echo json_encode(['status' => 'success', 'rows' => $safeRows, 'found' => count($found)]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'browser_login') {
    $script = __DIR__ . '/fb_group_login.js';
    if (!is_file($script)) {
        echo json_encode(['status' => 'error', 'msg' => 'No existe el script de login de Facebook']); exit;
    }
    $statusData = fb_read_json_file($FB_BROWSER_LOGIN_STATUS_FILE, []);
    $runningPid = (int)($statusData['pid'] ?? 0);
    if (($statusData['status'] ?? '') === 'running' && fb_pid_alive($runningPid)) {
        echo json_encode(['status' => 'success', 'msg' => 'Ya existe un login en curso', 'running' => 1, 'pid' => $runningPid, 'viewer_url' => '/fbnovnc/vnc_lite.html?autoconnect=1&resize=remote&path=fbnovnc/websockify']); exit;
    }
    @file_put_contents($FB_BROWSER_LOGIN_STATUS_FILE, json_encode([
        'status' => 'starting',
        'message' => 'Iniciando navegador de Facebook...',
        'updated_at' => date('c'),
        'pid' => 0
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @mkdir($FB_BROWSER_PROFILE_DIR, 0775, true);
    @mkdir($FB_BROWSER_PROFILE_DIR . '/.config', 0775, true);
    @mkdir($FB_BROWSER_PROFILE_DIR . '/.cache', 0775, true);
    @mkdir('/tmp/palweb-fb-runtime', 0775, true);
    $cmd = 'nohup env DISPLAY=' . escapeshellarg((string)$FB_BROWSER_DISPLAY)
        . ' HOME=/var/www'
        . ' PUPPETEER_CACHE_DIR=/var/www/wa_web_bridge/.cache/puppeteer'
        . ' XDG_CONFIG_HOME=' . escapeshellarg($FB_BROWSER_PROFILE_DIR . '/.config')
        . ' XDG_CACHE_HOME=' . escapeshellarg($FB_BROWSER_PROFILE_DIR . '/.cache')
        . ' XDG_RUNTIME_DIR=/tmp/palweb-fb-runtime '
        . 'node '
        . escapeshellarg($script) . ' '
        . escapeshellarg($FB_BROWSER_COOKIES_FILE) . ' '
        . escapeshellarg($FB_BROWSER_PROFILE_DIR) . ' '
        . escapeshellarg($FB_BROWSER_LOGIN_STATUS_FILE)
        . ' > ' . escapeshellarg($FB_BROWSER_LOGIN_RUNNER_LOG) . ' 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($cmd));
    @file_put_contents($FB_BROWSER_LOGIN_STATUS_FILE, json_encode([
        'status' => 'starting',
        'message' => 'Iniciando navegador de Facebook...',
        'updated_at' => date('c'),
        'pid' => $pid
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success', 'msg' => 'Login de Facebook iniciado', 'pid' => $pid, 'viewer_url' => '/fbnovnc/vnc_lite.html?autoconnect=1&resize=remote&path=fbnovnc/websockify']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'browser_login_status') {
    $statusData = fb_read_json_file($FB_BROWSER_LOGIN_STATUS_FILE, []);
    if (!$statusData) {
        echo json_encode(['status' => 'success', 'state' => 'idle', 'message' => 'Sin login en curso']); exit;
    }
    $state = (string)($statusData['status'] ?? 'idle');
    $pid = (int)($statusData['pid'] ?? 0);
    if (in_array($state, ['running','starting'], true) && !fb_pid_alive($pid)) {
        $statusData['status'] = 'error';
        $statusData['message'] = 'El navegador visual no está activo. Vuelve a iniciarlo.';
        $statusData['updated_at'] = date('c');
        @file_put_contents($FB_BROWSER_LOGIN_STATUS_FILE, json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    echo json_encode(['status' => 'success', 'state' => (string)($statusData['status'] ?? 'idle'), 'message' => (string)($statusData['message'] ?? ''), 'details' => $statusData]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_group') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $groupId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($groupId === '') {
        echo json_encode(['status' => 'error', 'msg' => 'Group ID requerido']); exit;
    }
    $groupName = substr(trim((string)($in['name'] ?? $groupId)), 0, 180);
    $groupToken = trim((string)($in['access_token'] ?? ''));
    $job = [
        'id' => 'fbgroup_test_' . date('Ymd_His'),
        'text' => trim((string)($in['text'] ?? ('Prueba automática grupo Facebook ' . date('Y-m-d H:i:s')))),
        'banner_images' => [],
        'outro_text' => '',
        'products' => [],
    ];
    $res = fb_publish_campaign($pdo, $cfg, $job, [
        'id' => $groupId,
        'name' => $groupName,
        'type' => 'group',
        'token' => $groupToken,
    ]);
    echo json_encode($res); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'promo_products') {
    $q = trim((string)($_GET['q'] ?? ''));
    $lim = max(1, min(30, (int)($_GET['limit'] ?? 20)));
    $like = '%' . $q . '%';
    $sql = "SELECT codigo,nombre,precio FROM productos WHERE activo=1 AND id_empresa=? AND (nombre LIKE ? OR codigo LIKE ?) ORDER BY nombre ASC LIMIT {$lim}";
    $st = $pdo->prepare($sql);
    $st->execute([(int)$config['id_empresa'], $like, $like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $sku = (string)$r['codigo'];
        $out[] = ['id' => $sku, 'name' => (string)$r['nombre'], 'price' => (float)$r['precio'], 'image' => fb_public_product_image($sku)];
    }
    echo json_encode(['status' => 'success', 'rows' => $out]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'promo_my_page_payload') {
    echo json_encode(['status' => 'success'] + fb_my_page_payload($pdo, $config)); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'promo_templates') {
    $data = fb_read_json_file($FB_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_template_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = substr(trim((string)($in['id'] ?? '')), 0, 80);
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $text = trim((string)($in['text'] ?? ''));
    $publishMode = fb_normalize_publish_mode($in['publish_mode'] ?? 'both');
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    if ($name === '') { echo json_encode(['status' => 'error', 'msg' => 'Nombre requerido']); exit; }
    if ($text === '' && !$products && !$bannerImages) { echo json_encode(['status' => 'error', 'msg' => 'La plantilla está vacía']); exit; }
    $data = fb_read_json_file($FB_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $now = date('c');
    if ($id === '') $id = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $saved = false;
    foreach ($rows as &$row) {
        if ((string)($row['id'] ?? '') !== $id) continue;
        $row['name'] = $name;
        $row['text'] = $text;
        $row['publish_mode'] = $publishMode;
        $row['products'] = $products;
        $row['banner_images'] = $bannerImages;
        $row['updated_at'] = $now;
        $saved = true;
        break;
    }
    unset($row);
    if (!$saved) $rows[] = ['id' => $id, 'name' => $name, 'text' => $text, 'publish_mode' => $publishMode, 'products' => $products, 'banner_images' => $bannerImages, 'updated_at' => $now];
    if (!fb_write_json_file($FB_TEMPLATES_FILE, ['rows' => $rows])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar plantilla']); exit; }
    echo json_encode(['status' => 'success', 'id' => $id]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_upload_image') {
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Imagen requerida']); exit;
    }
    $dir = __DIR__ . '/uploads/promo_campaigns';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo crear carpeta']); exit;
    }
    $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($_FILES['image']['name'] ?? 'banner', PATHINFO_FILENAME)) ?: 'banner';
    $ext = strtolower(pathinfo($_FILES['image']['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) $ext = 'jpg';
    $fileName = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . substr($baseName, 0, 40) . '.' . $ext;
    $dest = $dir . '/' . $fileName;
    if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo mover imagen']); exit;
    }
    echo json_encode(['status' => 'success', 'name' => $fileName, 'url' => fb_public_base_url() . '/uploads/promo_campaigns/' . rawurlencode($fileName)]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_template_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = substr(trim((string)($in['id'] ?? '')), 0, 80);
    $data = fb_read_json_file($FB_TEMPLATES_FILE, ['rows' => []]);
    $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn($r) => (string)($r['id'] ?? '') !== $id));
    if (!fb_write_json_file($FB_TEMPLATES_FILE, ['rows' => $rows])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo eliminar plantilla']); exit; }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'promo_list') {
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobs = array_slice(array_reverse((array)($queue['jobs'] ?? [])), 0, 50);
    echo json_encode(['status' => 'success', 'rows' => $jobs]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'promo_detail') {
    $jobId = substr(trim((string)($_GET['id'] ?? '')), 0, 120);
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    foreach ((array)($queue['jobs'] ?? []) as $job) {
        if ((string)($job['id'] ?? '') === $jobId) { echo json_encode(['status' => 'success', 'row' => $job]); exit; }
    }
    echo json_encode(['status' => 'error', 'msg' => 'Campaña no encontrada']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_force_now') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;
    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $job['status'] = 'queued';
        $job['next_run_at'] = time();
        $job['current_index'] = 0;
        $job['last_schedule_key'] = '';
        $found = true;
        break;
    }
    unset($job);
    if (!$found) { echo json_encode(['status' => 'error', 'msg' => 'Campaña no encontrada']); exit; }
    if (!fb_write_json_file($FB_QUEUE_FILE, ['jobs' => $jobs])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo forzar']); exit; }
    fb_process_queue($pdo, fb_cfg($pdo));
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_update') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $cfgLive = fb_cfg($pdo);
    $found = false;
    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;
        $before = fb_campaign_snapshot($job);
        if (array_key_exists('name', $in)) $job['name'] = substr(trim((string)$in['name']), 0, 120);
        if (array_key_exists('campaign_group', $in)) $job['campaign_group'] = substr(trim((string)$in['campaign_group']), 0, 80) ?: 'General';
        if (array_key_exists('publish_mode', $in)) $job['publish_mode'] = fb_normalize_publish_mode($in['publish_mode']);
        if (array_key_exists('template_id', $in)) $job['template_id'] = substr(trim((string)$in['template_id']), 0, 80);
        if (array_key_exists('text', $in)) $job['text'] = trim((string)$in['text']);
        if (array_key_exists('outro_text', $in)) $job['outro_text'] = trim((string)$in['outro_text']);
        if (array_key_exists('products', $in)) $job['products'] = fb_normalize_campaign_products($in['products']);
        if (array_key_exists('banner_images', $in)) $job['banner_images'] = fb_normalize_campaign_banners($in['banner_images']);
        if (array_key_exists('preview_html', $in)) $job['preview_html'] = substr(trim((string)$in['preview_html']), 0, 120000);
        if (array_key_exists('schedule_enabled', $in)) $job['schedule_enabled'] = !empty($in['schedule_enabled']) ? 1 : 0;
        if (array_key_exists('schedule_time', $in)) $job['schedule_time'] = substr(trim((string)$in['schedule_time']), 0, 5);
        if (array_key_exists('schedule_days', $in)) {
            $days = [];
            foreach ((array)$in['schedule_days'] as $d) { $n = (int)$d; if ($n >= 0 && $n <= 6) $days[$n] = $n; }
            $job['schedule_days'] = array_values($days);
            sort($job['schedule_days']);
        }
        if (array_key_exists('status', $in)) {
            $status = strtolower(trim((string)$in['status']));
            if (in_array($status, ['scheduled','queued','running','done','error'], true)) $job['status'] = $status;
        }
        if (trim((string)($job['text'] ?? '')) === '' && trim((string)($job['outro_text'] ?? '')) === '' && empty($job['products']) && empty($job['banner_images'])) {
            echo json_encode(['status' => 'error', 'msg' => 'La campaña no puede quedar vacía']); exit;
        }
        if (!empty($job['schedule_enabled']) && !preg_match('/^\d{2}:\d{2}$/', (string)($job['schedule_time'] ?? ''))) {
            echo json_encode(['status' => 'error', 'msg' => 'Hora inválida']); exit;
        }
        $job['targets'] = in_array($job['publish_mode'] ?? 'both', ['facebook','both'], true) ? fb_clean_targets($cfgLive) : [];
        if (empty($job['schedule_enabled'])) {
            $job['schedule_time'] = '';
            $job['schedule_days'] = [];
            if (($job['status'] ?? '') === 'scheduled') $job['status'] = 'queued';
        } elseif (($job['status'] ?? '') === 'done') {
            $job['status'] = 'scheduled';
        }
        if (fb_campaign_snapshot($job) !== $before) {
            fb_campaign_add_version($job, 'update', $before, 'Edición desde el panel');
        }
        break;
    }
    unset($job);
    if (!$found) { echo json_encode(['status' => 'error', 'msg' => 'Campaña no encontrada']); exit; }
    if (!fb_write_json_file($FB_QUEUE_FILE, ['jobs' => $jobs])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo actualizar']); exit; }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_import') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = trim((string)($in['type'] ?? ''));
    $rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
    if (!$rows) { echo json_encode(['status' => 'error', 'msg' => 'No hay filas para importar']); exit; }

    if ($type === 'templates') {
        $data = fb_read_json_file($FB_TEMPLATES_FILE, ['rows' => []]);
        $stored = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        $indexed = [];
        foreach ($stored as $row) {
            $rowId = (string)($row['id'] ?? '');
            if ($rowId !== '') $indexed[$rowId] = $row;
        }
        $imported = 0;
        foreach ($rows as $row) {
            $name = substr(trim((string)($row['name'] ?? '')), 0, 120);
            if ($name === '') continue;
            $id = substr(trim((string)($row['id'] ?? '')), 0, 80);
            if ($id === '') $id = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $indexed[$id] = [
                'id' => $id,
                'name' => $name,
                'text' => trim((string)($row['text'] ?? '')),
                'publish_mode' => fb_normalize_publish_mode($row['publish_mode'] ?? 'both'),
                'products' => fb_normalize_campaign_products($row['products'] ?? []),
                'banner_images' => fb_normalize_campaign_banners($row['banner_images'] ?? []),
                'updated_at' => date('c'),
            ];
            $imported++;
        }
        if ($imported < 1) { echo json_encode(['status' => 'error', 'msg' => 'No hubo plantillas válidas para importar']); exit; }
        if (!fb_write_json_file($FB_TEMPLATES_FILE, ['rows' => array_values($indexed)])) {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar importación']); exit;
        }
        echo json_encode(['status' => 'success', 'imported' => $imported]); exit;
    }

    if ($type === 'campaigns') {
        $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
        $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
        $cfgLive = fb_cfg($pdo);
        $imported = 0;
        foreach ($rows as $row) {
            $scheduleEnabled = !empty($row['schedule_enabled']) ? 1 : 0;
            $daysFinal = [];
            foreach ((array)($row['schedule_days'] ?? []) as $d) {
                $n = (int)$d;
                if ($n >= 0 && $n <= 6) $daysFinal[$n] = $n;
            }
            $daysFinal = array_values($daysFinal);
            sort($daysFinal);
            $publishMode = fb_normalize_publish_mode($row['publish_mode'] ?? 'both');
            $job = [
                'id' => 'fbpromo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
                'status' => $scheduleEnabled ? 'scheduled' : 'queued',
                'created_at' => date('c'),
                'created_by' => $_SESSION['admin_name'] ?? 'admin',
                'name' => substr(trim((string)($row['name'] ?? 'Campaña importada')), 0, 120),
                'campaign_group' => substr(trim((string)($row['campaign_group'] ?? 'General')), 0, 80) ?: 'General',
                'publish_mode' => $publishMode,
                'preview_html' => substr(trim((string)($row['preview_html'] ?? '')), 0, 120000),
                'template_id' => substr(trim((string)($row['template_id'] ?? '')), 0, 80),
                'text' => trim((string)($row['text'] ?? '')),
                'banner_images' => fb_normalize_campaign_banners($row['banner_images'] ?? []),
                'outro_text' => trim((string)($row['outro_text'] ?? '')),
                'targets' => in_array($publishMode, ['facebook','both'], true) ? fb_clean_targets($cfgLive) : [],
                'products' => fb_normalize_campaign_products($row['products'] ?? []),
                'schedule_enabled' => $scheduleEnabled,
                'schedule_time' => substr(trim((string)($row['schedule_time'] ?? '09:00')), 0, 5),
                'schedule_days' => $daysFinal,
                'last_schedule_key' => '',
                'next_run_at' => !empty($scheduleEnabled) ? (time() + 30) : time(),
                'current_index' => 0,
                'log' => [],
                'versions' => [],
            ];
            if ($job['name'] === '' || ($job['text'] === '' && $job['outro_text'] === '' && !$job['banner_images'] && !$job['products'])) {
                continue;
            }
            fb_campaign_add_version($job, 'import', null, 'Importada desde JSON');
            $jobs[] = $job;
            $imported++;
        }
        if ($imported < 1) { echo json_encode(['status' => 'error', 'msg' => 'No hubo campañas válidas para importar']); exit; }
        if (!fb_write_json_file($FB_QUEUE_FILE, ['jobs' => $jobs])) {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar campañas importadas']); exit;
        }
        echo json_encode(['status' => 'success', 'imported' => $imported]); exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Tipo de importación inválido']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $before = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['id'] ?? '') !== $jobId));
    if ($before === count($jobs)) { echo json_encode(['status' => 'error', 'msg' => 'Campaña no encontrada']); exit; }
    if (!fb_write_json_file($FB_QUEUE_FILE, ['jobs' => $jobs])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo eliminar']); exit; }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_clone') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $source = null;
    foreach ($jobs as $job) { if ((string)($job['id'] ?? '') === $jobId) { $source = $job; break; } }
    if (!$source) { echo json_encode(['status' => 'error', 'msg' => 'Campaña no encontrada']); exit; }
    $clone = fb_clone_campaign($source);
    $jobs[] = $clone;
    if (!fb_write_json_file($FB_QUEUE_FILE, ['jobs' => $jobs])) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo clonar']); exit; }
    echo json_encode(['status' => 'success', 'id' => $clone['id'], 'name' => $clone['name']]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'promo_create') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($in['text'] ?? ''));
    $outroText = trim((string)($in['outro_text'] ?? ''));
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    $campaignName = substr(trim((string)($in['campaign_name'] ?? '')), 0, 120);
    $campaignGroup = substr(trim((string)($in['campaign_group'] ?? 'General')), 0, 80);
    $publishMode = fb_normalize_publish_mode($in['publish_mode'] ?? 'both');
    $previewHtml = trim((string)($in['preview_html'] ?? ''));
    $scheduleTime = substr(trim((string)($in['schedule_time'] ?? '')), 0, 5);
    $scheduleDays = is_array($in['schedule_days'] ?? null) ? $in['schedule_days'] : [];
    $templateId = substr(trim((string)($in['template_id'] ?? '')), 0, 80);
    $scheduleEnabled = !empty($in['schedule_enabled']) ? 1 : 0;

    if ($templateId !== '' && ($text === '' || count($products) === 0)) {
        $tplData = fb_read_json_file($FB_TEMPLATES_FILE, ['rows' => []]);
        foreach ((array)($tplData['rows'] ?? []) as $tpl) {
            if ((string)($tpl['id'] ?? '') !== $templateId) continue;
            if ($text === '') $text = trim((string)($tpl['text'] ?? ''));
            if (empty($in['publish_mode'])) $publishMode = fb_normalize_publish_mode($tpl['publish_mode'] ?? 'both');
            if (!$products && is_array($tpl['products'] ?? null)) $products = $tpl['products'];
            if (!$bannerImages && is_array($tpl['banner_images'] ?? null)) $bannerImages = $tpl['banner_images'];
            if ($campaignName === '') $campaignName = substr(trim((string)($tpl['name'] ?? '')), 0, 120);
            break;
        }
    }

    $cleanProducts = fb_normalize_campaign_products($products);
    $cleanBannerImages = fb_normalize_campaign_banners($bannerImages);
    if ($text === '' && $outroText === '' && !$cleanProducts && !$cleanBannerImages) { echo json_encode(['status' => 'error', 'msg' => 'La campaña está vacía']); exit; }
    $cfgLive = fb_cfg($pdo);
    $targets = in_array($publishMode, ['facebook','both'], true) ? fb_clean_targets($cfgLive) : [];
    if (in_array($publishMode, ['facebook','both'], true) && !$targets) { echo json_encode(['status' => 'error', 'msg' => 'Configura primero la página de Facebook']); exit; }
    if (in_array($publishMode, ['instagram','both'], true) && empty($cfgLive['enable_instagram'])) { echo json_encode(['status' => 'error', 'msg' => 'Instagram no está habilitado en la configuración']); exit; }

    $daysFinal = [];
    foreach ($scheduleDays as $d) { $n = (int)$d; if ($n >= 0 && $n <= 6) $daysFinal[$n] = $n; }
    $daysFinal = array_values($daysFinal);
    sort($daysFinal);
    if ($scheduleEnabled) {
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) { echo json_encode(['status' => 'error', 'msg' => 'Hora inválida']); exit; }
        if (!$daysFinal) { echo json_encode(['status' => 'error', 'msg' => 'Selecciona al menos un día']); exit; }
    } else {
        $scheduleTime = '';
        $daysFinal = [];
    }

    $queue = fb_read_json_file($FB_QUEUE_FILE, ['jobs' => []]);
    $jobId = 'fbpromo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $now = time();
    $job = [
        'id' => $jobId,
        'status' => $scheduleEnabled ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => $campaignName !== '' ? $campaignName : ('Campaña ' . date('d/m H:i')),
        'campaign_group' => $campaignGroup !== '' ? $campaignGroup : 'General',
        'publish_mode' => $publishMode,
        'preview_html' => substr($previewHtml, 0, 120000),
        'template_id' => $templateId,
        'text' => $text,
        'banner_images' => $cleanBannerImages,
        'outro_text' => $outroText,
        'targets' => $targets,
        'products' => $cleanProducts,
        'schedule_enabled' => $scheduleEnabled,
        'schedule_time' => $scheduleTime,
        'schedule_days' => $daysFinal,
        'last_schedule_key' => '',
        'next_run_at' => $scheduleEnabled ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => [],
        'versions' => []
    ];
    fb_campaign_add_version($job, 'create', null, 'Campaña creada');
    $queue['jobs'][] = $job;
    if (!fb_write_json_file($FB_QUEUE_FILE, $queue)) { echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar campaña']); exit; }
    fb_worker_log('Campaña creada ' . $jobId . ' modo=' . $publishMode . ' nombre=' . ($campaignName !== '' ? $campaignName : 'Campaña'));
    if (!$scheduleEnabled) fb_process_queue($pdo, fb_cfg($pdo));
    echo json_encode(['status' => 'success', 'id' => $jobId]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_post') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $publishMode = fb_normalize_publish_mode($in['publish_mode'] ?? 'both');
    $job = [
        'id' => 'fbtest_' . date('Ymd_His'),
        'publish_mode' => $publishMode,
        'text' => trim((string)($in['text'] ?? 'Prueba de publicación desde PalWeb Facebook')),
        'banner_images' => is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [],
        'outro_text' => trim((string)($in['outro_text'] ?? '')),
        'products' => is_array($in['products'] ?? null) ? $in['products'] : [],
    ];
    $cfgLive = fb_cfg($pdo);
    $targets = in_array($publishMode, ['facebook','both'], true) ? fb_clean_targets($cfgLive) : [];
    if (in_array($publishMode, ['facebook','both'], true) && !$targets) { echo json_encode(['status' => 'error', 'msg' => 'Configura al menos una página o grupo de Facebook']); exit; }
    if (in_array($publishMode, ['instagram','both'], true) && empty($cfgLive['enable_instagram'])) { echo json_encode(['status' => 'error', 'msg' => 'Instagram no está habilitado']); exit; }
    $resFb = ['ok' => true, 'skipped' => true];
    $resIg = ['ok' => true, 'skipped' => true];
    if (in_array($publishMode, ['facebook','both'], true)) {
        $errors = [];
        $messagesSent = 0;
        $postIds = [];
        foreach ($targets as $target) {
            $single = fb_publish_campaign($pdo, $cfgLive, $job, $target);
            if (empty($single['ok'])) {
                $errors[] = (string)($target['name'] ?? $target['id'] ?? 'Destino') . ': ' . (string)($single['error'] ?? 'Error');
            } else {
                $messagesSent += (int)($single['messages_sent'] ?? 0);
                if (!empty($single['post_id'])) $postIds[] = (string)$single['post_id'];
            }
        }
        $resFb = empty($errors)
            ? ['ok' => true, 'messages_sent' => $messagesSent, 'post_id' => implode(',', $postIds)]
            : ['ok' => false, 'error' => implode(' | ', $errors), 'messages_sent' => $messagesSent, 'post_id' => implode(',', $postIds)];
    }
    if (in_array($publishMode, ['instagram','both'], true)) $resIg = fb_publish_instagram($pdo, $cfgLive, $job);
    if (!empty($resFb['ok']) && (!empty($resIg['ok']) || !empty($resIg['skipped']))) {
        echo json_encode([
            'status' => 'success',
            'post_id' => $resFb['post_id'] ?? '',
            'instagram_post_id' => $resIg['post_id'] ?? '',
            'instagram_skipped' => !empty($resIg['skipped']) ? 1 : 0
        ]);
        exit;
    }
    $parts = [];
    if (empty($resFb['ok'])) $parts[] = 'Facebook: ' . (string)($resFb['error'] ?? 'Error');
    if (empty($resIg['ok']) && empty($resIg['skipped'])) $parts[] = 'Instagram: ' . (string)($resIg['error'] ?? 'Error');
    fb_worker_log('Test post modo=' . $publishMode . ' resultado=' . (!empty($parts) ? implode(' | ', $parts) : 'ok'));
    echo json_encode(['status' => 'error', 'msg' => implode(' | ', $parts)]); exit;
}

if ($action === 'process_queue') {
    $workerKey = trim((string)($_GET['worker_key'] ?? $_POST['worker_key'] ?? ''));
    if ($workerKey === '' || !hash_equals((string)($cfg['worker_key'] ?? ''), $workerKey)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'invalid worker key']); exit;
    }
    echo json_encode(['status' => 'success'] + fb_process_queue($pdo, $cfg)); exit;
}

echo json_encode(['status' => 'error', 'msg' => 'invalid request']);
}
