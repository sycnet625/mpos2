<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

$BOT_OUTBOX = [];
$BOT_BRIDGE_STATUS_FILE = __DIR__ . '/wa_web_bridge/status.json';
$BOT_BRIDGE_CHATS_FILE = '/tmp/palweb_wa_chats.json';
$BOT_PROMO_QUEUE_FILE = '/tmp/palweb_wa_promo_queue.json';
$BOT_PROMO_TEMPLATES_FILE = '/tmp/palweb_wa_promo_templates.json';

function bot_require_admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
        exit;
    }
}

function bot_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function bot_clean_wa_id(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits !== '') return substr($digits, 0, 40);
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $raw) ?? '';
    return substr($safe, 0, 40);
}

function bot_read_json_file(string $file, $default = []): array {
    if (!is_file($file)) return is_array($default) ? $default : [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return is_array($default) ? $default : [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : (is_array($default) ? $default : []);
}

function bot_write_json_file(string $file, array $data): bool {
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}

function bot_public_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    return $scheme . '://' . $host;
}

function bot_public_product_image(string $sku): string {
    return bot_public_base_url() . "/image.php?code=" . rawurlencode($sku);
}

function bot_catalog_for_campaigns(PDO $pdo, array $config): array {
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

function bot_my_group_campaign_payload(PDO $pdo, array $config): array {
    $rows = bot_catalog_for_campaigns($pdo, $config);
    $products = [];
    $reservables = [];
    foreach ($rows as $r) {
        $sku = trim((string)($r['codigo'] ?? ''));
        if ($sku === '') continue;
        $name = trim((string)($r['nombre'] ?? $sku));
        $price = (float)($r['precio'] ?? 0);
        $stock = (float)($r['stock_total'] ?? 0);
        $isReservable = !empty($r['es_reservable']);
        if ($stock > 0) {
            $products[] = [
                'id' => $sku,
                'name' => $name,
                'price' => $price,
                'image' => bot_public_product_image($sku),
                'stock' => $stock
            ];
        }
        if ($isReservable) {
            $reservables[] = $name;
        }
    }
    $outroLines = [];
    if ($reservables) {
        $outroLines[] = 'Productos reservables:';
        foreach ($reservables as $name) {
            $outroLines[] = '- ' . $name;
        }
    }
    $outroLines[] = 'En www.palweb.net se pueden comprar automaticamente.';
    return [
        'products' => $products,
        'reservables' => $reservables,
        'outro_text' => implode("\n", $outroLines)
    ];
}

function bot_sanitize_shell_output(string $text): string {
    $lines = preg_split('/\R+/', trim($text)) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === '') continue;
        if (stripos($l, 'sudo: a password is required') !== false) continue;
        if (stripos($l, 'sudo: no tty present') !== false) continue;
        if (stripos($l, 'a terminal is required to read the password') !== false) continue;
        $clean[] = $l;
    }
    return trim(implode("\n", $clean));
}

function bot_is_bridge_process_running(): bool {
    $out = [];
    $code = 1;
    @exec('pgrep -f "wa_web_bridge/bridge.js" 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

function bot_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_config (
        id TINYINT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        wa_mode ENUM('web','meta_api') NOT NULL DEFAULT 'web',
        bot_tone ENUM('premium','popular_cubano','formal_comercial','muy_cercano') NOT NULL DEFAULT 'muy_cercano',
        verify_token VARCHAR(120) DEFAULT NULL,
        wa_phone_number_id VARCHAR(80) DEFAULT NULL,
        wa_access_token TEXT DEFAULT NULL,
        business_name VARCHAR(120) DEFAULT 'PalWeb POS',
        welcome_message TEXT,
        menu_intro TEXT,
        no_match_message TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_sessions (
        wa_user_id VARCHAR(40) PRIMARY KEY,
        wa_name VARCHAR(120) DEFAULT NULL,
        cart_json TEXT,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        wa_user_id VARCHAR(40) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        msg_type VARCHAR(20) DEFAULT 'text',
        message_text TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_wa_created (wa_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_orders (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        id_pedido INT NOT NULL,
        wa_user_id VARCHAR(40) NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pedido (id_pedido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO pos_bot_config
        (id,enabled,verify_token,business_name,welcome_message,menu_intro,no_match_message)
        VALUES (1,0,'palweb_bot_verify','PalWeb POS',
        'Hola {{name}}. Bienvenido a {{business}}. Escribe MENU.',
        'Usa AGREGAR CODIGO CANTIDAD | CARRITO | CONFIRMAR',
        'No te entendi. Usa MENU, AGREGAR, CARRITO o CONFIRMAR')");

    if (!bot_has_column($pdo, 'pos_bot_config', 'wa_mode')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN wa_mode ENUM('web','meta_api') NOT NULL DEFAULT 'web' AFTER enabled");
    }
    if (!bot_has_column($pdo, 'pos_bot_config', 'bot_tone')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN bot_tone ENUM('premium','popular_cubano','formal_comercial','muy_cercano') NOT NULL DEFAULT 'muy_cercano' AFTER wa_mode");
    }
    $pdo->exec("UPDATE pos_bot_config SET wa_mode='web' WHERE wa_mode IS NULL OR wa_mode=''");
    $pdo->exec("UPDATE pos_bot_config SET bot_tone='muy_cercano' WHERE bot_tone IS NULL OR bot_tone=''");
}

function bot_cfg(PDO $pdo): array {
    $c = $pdo->query("SELECT * FROM pos_bot_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    return $c ?: [];
}

function bot_log(PDO $pdo, string $wa, string $dir, string $txt, string $type='text'): void {
    $st = $pdo->prepare("INSERT INTO pos_bot_messages (wa_user_id,direction,msg_type,message_text) VALUES (?,?,?,?)");
    $st->execute([$wa,$dir,$type,$txt]);
}

function bot_queue_response(PDO $pdo, string $wa, string $type, array $payload): void {
    global $BOT_OUTBOX;
    $row = ['wa_user_id' => $wa, 'type' => $type] + $payload;
    $BOT_OUTBOX[] = $row;
    $logText = trim((string)($payload['text'] ?? $payload['caption'] ?? $payload['url'] ?? ''));
    if ($logText !== '') bot_log($pdo, $wa, 'out', $logText, $type);
}

function bot_send(PDO $pdo, array $cfg, string $wa, string $text): void {
    bot_queue_response($pdo, $wa, 'text', ['text' => $text]);
    // Modo local (si no hay token/phoneId). Integración Meta opcional.
}

function bot_take_outbox(): array {
    global $BOT_OUTBOX;
    $out = $BOT_OUTBOX;
    $BOT_OUTBOX = [];
    return $out;
}

function bot_send_image(PDO $pdo, string $wa, string $url, string $caption=''): void {
    $url = trim($url);
    if ($url === '') return;
    bot_queue_response($pdo, $wa, 'image', ['url' => $url, 'caption' => trim($caption)]);
}

function bot_default_cart(string $name=''): array {
    return [
        'items' => [],
        'item_notes' => [],
        'customer_name' => $name,
        'customer_address' => '',
        'awaiting_field' => '',
        'pending_choice' => null,
        'last_order' => null,
        'greet_count' => 0
    ];
}

function bot_merge_cart_shape(array $cart, string $name=''): array {
    $base = bot_default_cart($name);
    $cart = array_merge($base, $cart);
    if (!is_array($cart['items'] ?? null)) $cart['items'] = [];
    if (!is_array($cart['item_notes'] ?? null)) $cart['item_notes'] = [];
    if (!is_array($cart['last_order'] ?? null)) $cart['last_order'] = $base['last_order'];
    if (!is_array($cart['pending_choice'] ?? null)) $cart['pending_choice'] = null;
    if (($cart['customer_name'] ?? '') === '' && $name !== '') $cart['customer_name'] = $name;
    return $cart;
}

function bot_get_cart(PDO $pdo, string $wa, string $name=''): array {
    $st = $pdo->prepare("SELECT cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cart = bot_default_cart($name);
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
        return $cart;
    }
    $cart = json_decode((string)$row['cart_json'], true);
    if (!is_array($cart)) $cart = [];
    return bot_merge_cart_shape($cart, $name);
}

function bot_save_cart(PDO $pdo, string $wa, string $name, array $cart): void {
    $cart = bot_merge_cart_shape($cart, $name);
    $st = $pdo->prepare("UPDATE pos_bot_sessions SET wa_name=?, cart_json=?, last_seen=NOW() WHERE wa_user_id=?");
    $st->execute([$name, json_encode($cart, JSON_UNESCAPED_UNICODE), $wa]);
}

function bot_norm(string $text): string {
    $t = mb_strtolower($text, 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n'
    ];
    $t = strtr($t, $map);
    $t = preg_replace('/[^a-z0-9\s]/', ' ', $t) ?? '';
    $t = preg_replace('/\s+/', ' ', trim($t)) ?? '';
    return $t;
}

function bot_clean_value(string $text): string {
    $v = trim($text);
    $v = preg_replace('/\s+/', ' ', $v) ?? '';
    return trim($v, " \t\n\r\0\x0B,.;:-");
}

function bot_extract_name(string $text): string {
    $raw = trim($text);
    if ($raw === '') return '';
    if (preg_match('/^\[[a-z0-9_]+\]$/iu', $raw)) return '';
    $patterns = [
        '/^(mi nombre es|soy|me llamo)\s+/iu',
        '/^(nombre)\s*[:\-]?\s*/iu',
    ];
    foreach ($patterns as $p) $raw = preg_replace($p, '', $raw) ?? $raw;
    $val = bot_clean_value($raw);
    if ($val === '' || mb_strlen($val) < 2) return '';
    if (in_array(bot_norm($val), ['confirmar','menu','carrito','cancelar','pedido'], true)) return '';
    return mb_substr($val, 0, 120);
}

function bot_extract_address(string $text): string {
    $raw = trim($text);
    if ($raw === '') return '';
    $patterns = [
        '/^(mi direccion es|direccion|dir)\s*[:\-]?\s*/iu',
        '/^(vivo en|entrega en)\s+/iu',
    ];
    foreach ($patterns as $p) $raw = preg_replace($p, '', $raw) ?? $raw;
    $val = bot_clean_value($raw);
    if ($val === '' || mb_strlen($val) < 6) return '';
    if (in_array(bot_norm($val), ['confirmar','menu','carrito','cancelar','pedido'], true)) return '';
    $normVal = bot_norm($val);
    $hasAddressHint = preg_match('/\d/', $val) || preg_match('/\b(calle|callejon|avenida|ave|av|reparto|edificio|apto|apartamento|casa|km|kilometro|barrio|entre|esquina|carretera|pasaje|zona)\b/u', $normVal);
    if (!$hasAddressHint) return '';
    return mb_substr($val, 0, 220);
}

function bot_intent_has(string $norm, array $needles): bool {
    foreach ($needles as $n) {
        if (str_contains($norm, bot_norm($n))) return true;
    }
    return false;
}

function bot_extract_qty(string $normText): int {
    if (preg_match('/\b(\d{1,2})\b/', $normText, $m)) return max(1, min(99, (int)$m[1]));
    $map = [
        'un'=>1,'uno'=>1,'una'=>1,
        'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10,
        'par'=>2
    ];
    foreach ($map as $word => $qty) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normText)) return $qty;
    }
    return 1;
}

function bot_strip_filler_words(string $normText): string {
    $patterns = [
        '/\b(agregar|anadir|añadir|sumar|quiero|dame|deseo|mandame|manda|ponme|pon|me das|favor|por favor|necesito|para mi|para)\b/u',
        '/\b(un|una|unos|unas)\b/u'
    ];
    $clean = $normText;
    foreach ($patterns as $p) $clean = preg_replace($p, ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
    return $clean;
}

function bot_extract_item_note(string $normText): array {
    $notes = [];
    $clean = $normText;
    $patterns = [
        '/\bsin\s+([a-z0-9 ]{2,40})/u' => 'Sin %s',
        '/\bcon\s+extra\s+([a-z0-9 ]{2,40})/u' => 'Con extra %s',
        '/\bextra\s+([a-z0-9 ]{2,40})/u' => 'Extra %s',
        '/\bbien\s+([a-z0-9 ]{2,30})/u' => 'Bien %s',
        '/\bpoco\s+([a-z0-9 ]{2,30})/u' => 'Poco %s',
        '/\bsin\s+([a-z0-9 ]{2,25})\s+y\s+sin\s+([a-z0-9 ]{2,25})/u' => 'Sin %s y sin %s'
    ];
    foreach ($patterns as $regex => $fmt) {
        if (preg_match_all($regex, $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $parts = [];
                for ($i = 1; $i < count($m); $i++) {
                    $parts[] = trim((string)$m[$i]);
                }
                $notes[] = vsprintf($fmt, $parts);
            }
            $clean = preg_replace($regex, ' ', $clean) ?? $clean;
        }
    }
    $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
    return ['text' => $clean, 'note' => trim(implode('; ', array_unique(array_filter($notes))))];
}

function bot_similarity_score(string $a, string $b): int {
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 1000;
    if (str_contains($a, $b) || str_contains($b, $a)) return 850;
    $aCompact = str_replace(' ', '', $a);
    $bCompact = str_replace(' ', '', $b);
    $maxLen = max(strlen($aCompact), strlen($bCompact));
    $lev = levenshtein($aCompact, $bCompact);
    $levScore = $maxLen > 0 ? (int)round((1 - min($lev, $maxLen) / $maxLen) * 100) : 0;
    $aTokens = array_values(array_filter(explode(' ', $a), static fn($x) => mb_strlen($x) >= 2));
    $bTokens = array_values(array_filter(explode(' ', $b), static fn($x) => mb_strlen($x) >= 2));
    $common = count(array_intersect($aTokens, $bTokens));
    $ratio = $common > 0 ? $common / max(1, count($bTokens)) : 0;
    return max($levScore, (int)round($ratio * 100) + ($common * 10));
}

function bot_find_product_candidates(array $menu, string $normText): array {
    $normText = bot_strip_filler_words($normText);
    $scored = [];
    foreach ($menu as $p) {
        $sku = (string)($p['codigo'] ?? '');
        $name = (string)($p['nombre'] ?? '');
        $skuNorm = bot_norm($sku);
        $nameNorm = bot_norm($name);
        $score = 0;
        if ($skuNorm !== '' && preg_match('/\b' . preg_quote($skuNorm, '/') . '\b/u', $normText)) {
            $score = 1100;
        } else {
            $score = max(bot_similarity_score($normText, $nameNorm), bot_similarity_score($normText, $skuNorm));
        }
        if ($score < 45) continue;
        $scored[] = ['product' => $p, 'score' => $score];
    }
    usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcasecmp((string)$a['product']['nombre'], (string)$b['product']['nombre']));
    return array_slice($scored, 0, 4);
}

function bot_parse_add_request(array $menu, string $text): array {
    $norm = bot_norm($text);
    if ($norm === '') return ['items' => [], 'ambiguous' => [], 'unknown' => []];
    $segments = preg_split('/\s*(?:,| y | e |;|\+| mas )\s*/u', $norm) ?: [$norm];
    $items = [];
    $ambiguous = [];
    $unknown = [];
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $qty = bot_extract_qty($seg);
        $noteInfo = bot_extract_item_note($seg);
        $lookup = bot_strip_filler_words($noteInfo['text']);
        $lookup = preg_replace('/\b(\d{1,2}|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|par)\b/u', ' ', $lookup) ?? $lookup;
        $lookup = preg_replace('/\s+/', ' ', trim($lookup)) ?? '';
        $candidates = bot_find_product_candidates($menu, $lookup);
        if (!$candidates) {
            $unknown[] = $seg;
            continue;
        }
        $best = $candidates[0];
        $second = $candidates[1] ?? null;
        $isAmbiguous = $second && ($best['score'] - $second['score'] <= 10) && $best['score'] < 900;
        if ($isAmbiguous) {
            $ambiguous[] = [
                'text' => $seg,
                'qty' => $qty,
                'note' => $noteInfo['note'],
                'options' => array_map(static fn($row) => [
                    'sku' => (string)$row['product']['codigo'],
                    'name' => (string)$row['product']['nombre']
                ], array_slice($candidates, 0, 3))
            ];
            continue;
        }
        $sku = (string)$best['product']['codigo'];
        $items[] = [
            'sku' => $sku,
            'qty' => $qty,
            'name' => (string)$best['product']['nombre'],
            'note' => $noteInfo['note']
        ];
    }
    return ['items' => $items, 'ambiguous' => $ambiguous, 'unknown' => $unknown];
}

function bot_find_product_by_text(array $menu, string $normText): ?array {
    $candidates = bot_find_product_candidates($menu, bot_norm($normText));
    return $candidates[0]['product'] ?? null;
}

function bot_parse_remove_item(array $menu, string $text): ?string {
    $norm = bot_norm($text);
    $p = bot_find_product_by_text($menu, $norm);
    return $p ? (string)$p['codigo'] : null;
}

function bot_menu(PDO $pdo, int $emp, int $suc): array {
    $st = $pdo->prepare("SELECT codigo,nombre,precio FROM productos
                         WHERE activo=1 AND es_web=1 AND id_empresa=?
                           AND (sucursales_web='' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web)>0)
                         ORDER BY categoria,nombre LIMIT 40");
    $st->execute([$emp,$suc]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bot_pick(array $options, string $seed=''): string {
    if (!$options) return '';
    $idx = abs(crc32($seed !== '' ? $seed : (string)microtime(true))) % count($options);
    return (string)$options[$idx];
}

function bot_site_url(array $appCfg): string {
    $url = trim((string)($appCfg['website'] ?? ''));
    if ($url === '') $url = 'https://www.palweb.net';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    return $url;
}

function bot_site_promo(array $cfg, array $appCfg, string $seed=''): string {
    $site = preg_replace('~^https?://~i', '', bot_site_url($appCfg));
    $tone = bot_tone_variants($cfg);
    return sprintf(bot_pick($tone['site'], $seed), $site);
}

function bot_business_logo_url(array $appCfg): string {
    $logo = trim((string)($appCfg['ticket_logo'] ?? ''));
    if ($logo === '') return '';
    if (preg_match('~^https?://~i', $logo)) return $logo;
    $base = bot_public_base_url();
    return $logo[0] === '/' ? ($base . $logo) : ($base . '/' . ltrim($logo, '/'));
}

function bot_tone(array $cfg): string {
    $tone = trim((string)($cfg['bot_tone'] ?? 'muy_cercano'));
    return in_array($tone, ['premium','popular_cubano','formal_comercial','muy_cercano'], true) ? $tone : 'muy_cercano';
}

function bot_tone_variants(array $cfg): array {
    switch (bot_tone($cfg)) {
        case 'premium':
            return [
                'greetings' => [
                    'Hola %s, es un placer atenderte.',
                    'Bienvenido %s, estoy a tu disposición para ayudarte con tu compra.',
                    'Hola %s, con gusto te ayudo a realizar tu pedido.'
                ],
                'followup' => [
                    'Puedo mostrarte el catálogo, ayudarte a repetir tu última compra o tomar tu pedido paso a paso.',
                    'Si lo deseas, te enseño el menú o preparo tu pedido de forma rápida.',
                    'Cuéntame qué necesitas y me encargo de guiarte.'
                ],
                'site' => [
                    'También puedes consultar el catálogo y comprar automáticamente en %s.',
                    'Si prefieres una experiencia directa, en %s puedes comprar automáticamente.',
                    'Recuerda que en %s puedes revisar productos y comprar de forma automática.'
                ]
            ];
        case 'popular_cubano':
            return [
                'greetings' => [
                    'Hola %s, qué bolá, aquí te atiendo rapidito.',
                    'Asere %s, dime qué te hace falta y te ayudo enseguida.',
                    'Hola %s, tranquilo, que yo te armo el pedido.'
                ],
                'followup' => [
                    'Si quieres, te saco el menú, te repito lo de siempre o te monto el pedido al momento.',
                    'Escríbeme como hablas normal y yo te resuelvo.',
                    'Tú dime lo que quieres comer o comprar y yo lo voy armando.'
                ],
                'site' => [
                    'Oye, en %s también puedes mirar el catálogo y comprar automático.',
                    'Si quieres ir más rápido, entra en %s y haces la compra automática.',
                    'Acuérdate que por %s puedes comprar automático sin esperar.'
                ]
            ];
        case 'formal_comercial':
            return [
                'greetings' => [
                    'Buenas %s, gracias por contactarnos.',
                    'Estimado %s, estamos listos para atender su solicitud.',
                    'Hola %s, con gusto gestiono su pedido.'
                ],
                'followup' => [
                    'Puedo mostrarle el catálogo, registrar su pedido o ayudarle a repetir una compra anterior.',
                    'Indíqueme los productos que desea y con gusto los organizo.',
                    'Si lo prefiere, le muestro el menú disponible.'
                ],
                'site' => [
                    'Puede consultar el catálogo y comprar automáticamente en %s.',
                    'Para una compra más directa, utilice %s.',
                    'Le recordamos que en %s puede comprar de forma automática.'
                ]
            ];
        default:
            return [
                'greetings' => [
                    'Hola %s, qué bueno tenerte por aquí.',
                    'Hola %s, aquí estoy para ayudarte con tu pedido.',
                    'Buenas %s, dime qué te apetece y te ayudo enseguida.'
                ],
                'followup' => [
                    'Puedo mostrarte el catálogo, repetir tu pedido anterior o ayudarte paso a paso.',
                    'Escríbeme como hablas normalmente y yo te ayudo.',
                    'Si quieres, te enseño el menú o vamos armando el pedido juntos.'
                ],
                'site' => [
                    'También puedes ver el catálogo y comprar automático en %s.',
                    'Si prefieres comprar más rápido, entra en %s y haz tu pedido automático.',
                    'Recuerda que en %s puedes ver productos y comprar automáticamente.'
                ]
            ];
    }
}

function bot_customer_display_name(array $cart, string $fallback='Cliente'): string {
    $name = trim((string)($cart['customer_name'] ?? ''));
    if ($name === '') $name = trim($fallback);
    if ($name === '') $name = 'Cliente';
    $parts = preg_split('/\s+/', $name) ?: [$name];
    return ucfirst(mb_strtolower((string)$parts[0], 'UTF-8'));
}

function bot_send_catalog_showcase(PDO $pdo, string $wa, array $appCfg, array $menu, string $intro=''): void {
    $logo = bot_business_logo_url($appCfg);
    if ($logo !== '') {
        bot_send_image($pdo, $wa, $logo, $intro);
        $intro = '';
    }
    $count = 0;
    foreach ($menu as $p) {
        $sku = trim((string)($p['codigo'] ?? ''));
        if ($sku === '') continue;
        $caption = "{$p['nombre']}\nPrecio: $" . number_format((float)$p['precio'], 2);
        if ($count === 0 && $intro !== '') $caption = $intro . "\n\n" . $caption;
        bot_send_image($pdo, $wa, bot_public_product_image($sku), $caption);
        $count++;
        if ($count >= 3) break;
    }
    if ($count === 0 && $intro !== '') {
        bot_send($pdo, [], $wa, $intro);
    }
}

function bot_greeting_text(array $cfg, array $appCfg, array $cart, string $fallbackName='Cliente'): string {
    $name = bot_customer_display_name($cart, $fallbackName);
    $business = trim((string)($cfg['business_name'] ?? ($appCfg['tienda_nombre'] ?? 'PalWeb POS')));
    $tone = bot_tone_variants($cfg);
    $greet = sprintf(bot_pick($tone['greetings'], $name . date('YmdH')), $name);
    $extra = ((int)($cart['greet_count'] ?? 0) > 0)
        ? bot_pick($tone['followup'], $name . 'repeat')
        : "Estás hablando con {$business}. Puedes pedir escribiendo natural, por ejemplo: \"quiero 2 pizzas y 1 refresco\".";
    return $greet . "\n" . $extra . "\n" . bot_site_promo($cfg, $appCfg, $name . 'promo');
}

function bot_format_item_line(array $product, int $qty, string $note=''): string {
    $line = "- {$product['nombre']} ({$product['codigo']}) x{$qty}";
    if ($note !== '') $line .= " [{$note}]";
    $line .= " = $" . number_format(((float)$product['precio']) * $qty, 2);
    return $line;
}

function bot_suggest_item(array $menu, array $cart): ?array {
    $existing = array_keys((array)($cart['items'] ?? []));
    $preferredWords = ['refresco','bebida','jugo','agua','postre','cafe','café'];
    foreach ($menu as $p) {
        $sku = (string)$p['codigo'];
        if (in_array($sku, $existing, true)) continue;
        $nameNorm = bot_norm((string)$p['nombre']);
        foreach ($preferredWords as $word) {
            if (str_contains($nameNorm, bot_norm($word))) return $p;
        }
    }
    foreach ($menu as $p) {
        if (!in_array((string)$p['codigo'], $existing, true)) return $p;
    }
    return null;
}

function bot_repeat_last_order(array &$cart): array {
    $last = is_array($cart['last_order'] ?? null) ? $cart['last_order'] : [];
    $items = is_array($last['items'] ?? null) ? $last['items'] : [];
    if (!$items) return [];
    foreach ($items as $sku => $qty) {
        $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + max(1, (int)$qty);
    }
    foreach ((array)($last['item_notes'] ?? []) as $sku => $note) {
        if (trim((string)$note) !== '') $cart['item_notes'][$sku] = trim((string)$note);
    }
    return $items;
}

function bot_faq_answer(string $norm, array $cfg, array $appCfg): ?string {
    $answers = [];
    if (bot_intent_has($norm, ['horario','abren','abierto','cierran','hora'])) {
        $answers[] = "Puedo ayudarte a tomar el pedido ahora mismo por este chat.";
        $answers[] = "Si quieres confirmar disponibilidad inmediata, pideme el menu o dime lo que necesitas.";
    }
    if (bot_intent_has($norm, ['direccion','dirección','donde estan','ubicacion','ubicación','local'])) {
        $dir = trim((string)($appCfg['direccion'] ?? ''));
        $answers[] = $dir !== '' ? "Nuestra direccion es: {$dir}." : "La direccion del negocio no esta configurada todavia en el sistema.";
    }
    if (bot_intent_has($norm, ['telefono','teléfono','llamar','contacto'])) {
        $tel = trim((string)($appCfg['telefono'] ?? ''));
        $answers[] = $tel !== '' ? "Puedes llamarnos o escribirnos al {$tel}." : "El telefono del negocio no esta configurado aun.";
    }
    if (bot_intent_has($norm, ['correo','email'])) {
        $email = trim((string)($appCfg['email'] ?? ''));
        if ($email !== '') $answers[] = "Nuestro correo es {$email}.";
    }
    if (bot_intent_has($norm, ['web','sitio','pagina','pagina web','catalogo','catálogo online'])) {
        $answers[] = "Puedes ver el catalogo y comprar automaticamente en " . bot_site_url($appCfg) . ".";
    }
    if (bot_intent_has($norm, ['pago','pagos','transferencia','efectivo','tarjeta'])) {
        $answers[] = "Aceptamos pagos segun la configuracion del negocio y tambien puedes revisar las opciones al comprar en " . bot_site_url($appCfg) . ".";
    }
    if (bot_intent_has($norm, ['envio','envío','domicilio','delivery','entrega'])) {
        $tarifa = (float)($appCfg['mensajeria_tarifa_km'] ?? 0);
        $answers[] = $tarifa > 0 ? "Hacemos entregas a domicilio. La tarifa base configurada es {$tarifa} por km." : "Podemos gestionar entrega a domicilio. Si quieres, comparte tu direccion y te guiamos.";
    }
    if (!$answers) return null;
    $answers[] = bot_site_promo($cfg, $appCfg, $norm . 'faq');
    return implode("\n", array_unique($answers));
}

function bot_cart_text(PDO $pdo, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['text' => 'Tu carrito esta vacio por ahora.', 'total' => 0];
    $lines = ['Asi va tu pedido:'];
    $tot = 0.0;
    $notes = (array)($cart['item_notes'] ?? []);
    foreach ($items as $sku => $qty) {
        $st = $pdo->prepare("SELECT nombre,precio FROM productos WHERE codigo=? LIMIT 1");
        $st->execute([$sku]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $sub = (float)$p['precio'] * (float)$qty;
        $tot += $sub;
        $note = trim((string)($notes[$sku] ?? ''));
        $line = "- {$p['nombre']} ({$sku}) x{$qty}";
        if ($note !== '') $line .= " [{$note}]";
        $line .= " = $" . number_format($sub,2);
        $lines[] = $line;
    }
    $lines[] = "TOTAL: $" . number_format($tot,2);
    if (!empty($cart['customer_name'])) $lines[] = "Nombre: " . $cart['customer_name'];
    if (!empty($cart['customer_address'])) $lines[] = "Direccion: " . $cart['customer_address'];
    $lines[] = "Si lo ves bien, escribe CONFIRMAR y lo cierro por ti.";
    return ['text' => implode("\n", $lines), 'total' => $tot];
}

function bot_create_order(PDO $pdo, array $appCfg, string $wa, string $name, string $address, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['ok'=>false, 'msg'=>'Carrito vacio'];

    $pdo->beginTransaction();
    try {
        $total = 0.0; $valid = [];
        $itemNotes = (array)($cart['item_notes'] ?? []);
        foreach ($items as $sku => $qty) {
            $st = $pdo->prepare("SELECT codigo,nombre,precio FROM productos WHERE codigo=? AND id_empresa=? LIMIT 1");
            $st->execute([$sku, (int)$appCfg['id_empresa']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;
            $q = max(1, (int)$qty);
            $total += (float)$p['precio'] * $q;
            $valid[] = [
                'sku'=>$p['codigo'],
                'name'=>(string)$p['nombre'],
                'qty'=>$q,
                'price'=>(float)$p['precio'],
                'note'=>trim((string)($itemNotes[(string)$p['codigo']] ?? ''))
            ];
        }
        if (!$valid) throw new Exception('Sin productos validos');

        $orderLines = ["WHATSAPP_BOT","wa_user={$wa}","wa_name={$name}","wa_address={$address}"];
        foreach ($valid as $it) {
            $line = "item={$it['sku']} x{$it['qty']}";
            if ($it['note'] !== '') $line .= " note={$it['note']}";
            $orderLines[] = $line;
        }
        $notes = implode("\n", $orderLines);
        $h = $pdo->prepare("INSERT INTO pedidos_cabecera
            (cliente_nombre,cliente_telefono,total,estado,fecha,id_empresa,id_sucursal,notas)
            VALUES (?,?,?,'pendiente',NOW(),?,?,?)");
        $h->execute([$name ?: 'Cliente WhatsApp', $wa, $total, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal'], $notes]);
        $idp = (int)$pdo->lastInsertId();

        $d = $pdo->prepare("INSERT INTO pedidos_detalle (id_pedido,id_producto,cantidad,precio_unitario) VALUES (?,?,?,?)");
        foreach ($valid as $it) $d->execute([$idp, $it['sku'], $it['qty'], $it['price']]);

        $pdo->prepare("INSERT INTO pos_bot_orders (id_pedido,wa_user_id,total) VALUES (?,?,?)")->execute([$idp,$wa,$total]);
        $pdo->commit();
        return [
            'ok'=>true,
            'id_pedido'=>$idp,
            'total'=>$total,
            'order_snapshot'=>[
                'items' => $items,
                'item_notes' => $itemNotes,
                'customer_name' => $name,
                'customer_address' => $address,
                'created_at' => date('c'),
                'id_pedido' => $idp
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'msg'=>$e->getMessage()];
    }
}

function bot_add_items_to_cart(array &$cart, array $items): array {
    $added = [];
    foreach ($items as $it) {
        $sku = (string)($it['sku'] ?? '');
        $qty = max(1, (int)($it['qty'] ?? 1));
        $name = (string)($it['name'] ?? $sku);
        $note = trim((string)($it['note'] ?? ''));
        if ($sku === '') continue;
        $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + $qty;
        if ($note !== '') $cart['item_notes'][$sku] = $note;
        $added[] = ['sku' => $sku, 'qty' => $qty, 'name' => $name, 'note' => $note];
    }
    return $added;
}

function bot_pending_choice_prompt(array $pending): string {
    $options = is_array($pending['options'] ?? null) ? $pending['options'] : [];
    if (!$options) return 'Necesito que me digas mejor cual producto quieres.';
    $lines = ["Quiero asegurarme de entenderte bien. ¿Cual de estos productos quieres?"];
    foreach ($options as $i => $op) {
        $lines[] = ($i + 1) . ". {$op['name']} ({$op['sku']})";
    }
    $lines[] = 'Respóndeme con el número o el nombre exacto.';
    return implode("\n", $lines);
}

function bot_try_resolve_pending_choice(array $menu, array &$cart, string $msg): ?array {
    $pending = is_array($cart['pending_choice'] ?? null) ? $cart['pending_choice'] : null;
    if (!$pending) return null;
    $norm = bot_norm($msg);
    if (bot_intent_has($norm, ['cancelar','ninguno','olvida','dejalo'])) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $options = is_array($pending['options'] ?? null) ? $pending['options'] : [];
    if (!$options) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $pick = null;
    if (preg_match('/^\s*([1-3])\s*$/', $msg, $m)) {
        $idx = max(0, ((int)$m[1]) - 1);
        $pick = $options[$idx] ?? null;
    }
    if (!$pick) {
        foreach ($options as $op) {
            $skuNorm = bot_norm((string)$op['sku']);
            $nameNorm = bot_norm((string)$op['name']);
            if ($skuNorm !== '' && str_contains($norm, $skuNorm)) { $pick = $op; break; }
            if ($nameNorm !== '' && (str_contains($norm, $nameNorm) || bot_similarity_score($norm, $nameNorm) >= 70)) { $pick = $op; break; }
        }
    }
    if (!$pick) return ['status' => 'retry'];
    $product = null;
    foreach ($menu as $p) {
        if ((string)($p['codigo'] ?? '') === (string)$pick['sku']) { $product = $p; break; }
    }
    if (!$product) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $added = bot_add_items_to_cart($cart, [[
        'sku' => (string)$product['codigo'],
        'qty' => max(1, (int)($pending['qty'] ?? 1)),
        'name' => (string)$product['nombre'],
        'note' => trim((string)($pending['note'] ?? ''))
    ]]);
    $cart['pending_choice'] = null;
    return ['status' => 'resolved', 'added' => $added];
}

function bot_handle_text(PDO $pdo, array $cfg, array $appCfg, string $wa, string $name, string $text): void {
    $msg = trim($text);
    if ($msg === '') return;
    $norm = bot_norm($msg);
    $cart = bot_get_cart($pdo, $wa, $name);
    $menu = bot_menu($pdo, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal']);
    if (($cart['customer_name'] ?? '') === '' && $name !== '') $cart['customer_name'] = $name;
    $isGreeting = bot_intent_has($norm, ['hola','buenas','buen dia','buenas tardes','buenas noches','start','iniciar','hello','saludos']);
    $isMenuReq = bot_intent_has($norm, ['menu','menú','catalogo','catálogo','carta','productos','que tienes','que venden','muestrame']);
    $isCartReq = bot_intent_has($norm, ['carrito','mi pedido','resumen','que llevo']);
    $isCancel = bot_intent_has($norm, ['cancelar','vaciar carrito','borrar carrito','anular pedido']);
    $isConfirm = bot_intent_has($norm, ['confirmar','ordenar','pedir','finalizar','checkout','cerrar pedido']);
    $isAdd = bot_intent_has($norm, ['agregar','añadir','anadir','sumar','quiero','dame','deseo','me das','ponme','mandame','preparame','me pones']);
    $isRemove = bot_intent_has($norm, ['quitar','eliminar','sacar','remover']);
    $isRepeat = bot_intent_has($norm, ['lo mismo','lo de siempre','repite el ultimo','repite mi ultimo','igual que la vez pasada','igual que siempre']);
    $looksLikeName = bot_intent_has($norm, ['mi nombre es','soy','me llamo','nombre']);
    $looksLikeAddress = bot_intent_has($norm, ['direccion','dirección','mi direccion es','vivo en','entrega en','dir']);

    $faq = ((string)($cart['awaiting_field'] ?? '') === '') ? bot_faq_answer($norm, $cfg, $appCfg) : null;
    if ($faq && !$isAdd && !$isConfirm && !$isCartReq) {
        bot_send($pdo, $cfg, $wa, $faq);
        return;
    }

    $pendingResolved = bot_try_resolve_pending_choice($menu, $cart, $msg);
    if ($pendingResolved) {
        if (($pendingResolved['status'] ?? '') === 'resolved') {
            bot_save_cart($pdo, $wa, $name, $cart);
            $addedLines = array_map(static function ($it) {
                $line = "{$it['name']} x{$it['qty']}";
                if (($it['note'] ?? '') !== '') $line .= " [{$it['note']}]";
                return $line;
            }, $pendingResolved['added'] ?? []);
            bot_send($pdo, $cfg, $wa, "Perfecto, ya lo entendí.\nAgregué:\n- " . implode("\n- ", $addedLines) . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'resolved'));
            return;
        }
        if (($pendingResolved['status'] ?? '') === 'retry') {
            bot_send($pdo, $cfg, $wa, bot_pending_choice_prompt($cart['pending_choice'] ?? []));
            return;
        }
        if (($pendingResolved['status'] ?? '') === 'cancelled') {
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Perfecto, descarto esa selección. Si quieres, dime el producto otra vez con otras palabras.');
            return;
        }
    }

    if (($cart['awaiting_field'] ?? '') === 'name' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Gracias, {$candidateName}. Ahora comparteme la direccion de entrega.\n" . bot_site_promo($cfg, $appCfg, $wa . 'name'));
            return;
        }
        bot_send($pdo, $cfg, $wa, 'Para continuar necesito tu nombre completo. Ejemplo: Mi nombre es Juan Perez.');
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'address' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Perfecto. Ya guardé tu direccion: {$candidateAddress}.\nCuando quieras, escribe CONFIRMAR y cierro el pedido.");
            return;
        }
        bot_send($pdo, $cfg, $wa, 'Necesito una direccion valida para entrega. Ejemplo: Direccion: Calle 10 #45 entre A y B.');
        return;
    }

    if ($looksLikeName) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            if (($cart['awaiting_field'] ?? '') === 'name') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Perfecto, {$candidateName}. Ya guardé tu nombre.");
            return;
        }
    }

    if ($looksLikeAddress) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            if (($cart['awaiting_field'] ?? '') === 'address') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Direccion guardada: {$candidateAddress}.");
            return;
        }
    }

    if (
        $isGreeting ||
        (
            ($cart['greet_count'] ?? 0) === 0 &&
            empty($cart['items']) &&
            trim((string)($cart['customer_address'] ?? '')) === '' &&
            !$isAdd && !$isConfirm && !$isCartReq
        )
    ) {
        $cart['greet_count'] = ((int)($cart['greet_count'] ?? 0)) + 1;
        bot_save_cart($pdo, $wa, $name, $cart);
        $greetingText = bot_greeting_text($cfg, $appCfg, $cart, $name);
        bot_send($pdo, $cfg, $wa, $greetingText);
        $logo = bot_business_logo_url($appCfg);
        if ($logo !== '') bot_send_image($pdo, $wa, $logo, "Compra fácil por chat o en " . bot_site_url($appCfg));
        return;
    }

    if ($isMenuReq) {
        $lines = [bot_greeting_text($cfg, $appCfg, $cart, $name), '', (string)($cfg['menu_intro'] ?? 'Menu:')];
        foreach ($menu as $p) $lines[] = "{$p['codigo']} - {$p['nombre']} - $" . number_format((float)$p['precio'],2);
        $lines[] = '';
        $lines[] = 'Puedes pedirme algo natural, por ejemplo: "quiero 2 croquetas y 1 refresco sin hielo".';
        $lines[] = 'Tambien puedo repetir tu ultimo pedido si me dices: "lo de siempre".';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'menu');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        bot_send_catalog_showcase($pdo, $wa, $appCfg, $menu, 'Aquí tienes una vista rápida del catálogo.');
        return;
    }

    if ($isRepeat) {
        $repeated = bot_repeat_last_order($cart);
        if ($repeated) {
            bot_save_cart($pdo, $wa, $name, $cart);
            $summary = bot_cart_text($pdo, $cart);
            bot_send($pdo, $cfg, $wa, "Te repetí el ultimo pedido que tenías guardado.\n\n" . $summary['text'] . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'repeat'));
        } else {
            bot_send($pdo, $cfg, $wa, "Todavia no tengo un pedido anterior guardado para repetir. Si quieres, te muestro el menu o puedes pedirme algo natural.");
        }
        return;
    }

    if ($isAdd || str_starts_with(mb_strtoupper($msg), 'AGREGAR ')) {
        $parsed = bot_parse_add_request($menu, $msg);
        if (!empty($parsed['ambiguous'])) {
            $cart['pending_choice'] = $parsed['ambiguous'][0];
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_pending_choice_prompt($cart['pending_choice']));
            return;
        }
        if (empty($parsed['items'])) {
            $extra = !empty($parsed['unknown']) ? ("\nNo reconocí bien: " . implode(', ', $parsed['unknown']) . '.') : '';
            bot_send($pdo, $cfg, $wa, 'No logré identificar bien los productos.' . $extra . "\nPrueba algo como: \"quiero 2 empanadas y 1 refresco\" o pideme el menu.");
            return;
        }
        $added = bot_add_items_to_cart($cart, $parsed['items']);
        bot_save_cart($pdo, $wa, $name, $cart);
        $lines = ['Perfecto, ya te lo agregué:'];
        foreach ($added as $it) {
            $line = "- {$it['name']} x{$it['qty']}";
            if ($it['note'] !== '') $line .= " [{$it['note']}]";
            $lines[] = $line;
        }
        $suggest = bot_suggest_item($menu, $cart);
        if ($suggest) $lines[] = "Sugerencia: ¿quieres añadir {$suggest['nombre']} por $" . number_format((float)$suggest['precio'],2) . '?';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'add');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        return;
    }

    if ($isRemove || str_starts_with(mb_strtoupper($msg), 'QUITAR ')) {
        $sku = bot_parse_remove_item($menu, $msg);
        if (!$sku && preg_match('/^QUITAR\s+(\S+)/iu', $msg, $m)) $sku = (string)$m[1];
        if ($sku !== null && $sku !== '' && isset($cart['items'][$sku])) {
            unset($cart['items'][$sku], $cart['item_notes'][$sku]);
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Listo, quité {$sku} de tu carrito.");
        } else {
            bot_send($pdo, $cfg, $wa, 'Ese producto no lo tengo ahora mismo en tu carrito. Si quieres, te muestro el resumen.');
        }
        return;
    }

    if ($isCartReq || mb_strtoupper($msg) === 'CARRITO') {
        $summary = bot_cart_text($pdo, $cart);
        bot_send($pdo, $cfg, $wa, $summary['text'] . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'cart'));
        return;
    }

    if ($isCancel || mb_strtoupper($msg) === 'CANCELAR') {
        $lastOrder = $cart['last_order'] ?? null;
        $greetCount = (int)($cart['greet_count'] ?? 0);
        $cart = bot_default_cart((string)($cart['customer_name'] ?? $name));
        if (($name !== '') && ($cart['customer_name'] ?? '') === '') $cart['customer_name'] = $name;
        $cart['last_order'] = is_array($lastOrder) ? $lastOrder : null;
        $cart['greet_count'] = $greetCount;
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, 'Listo, dejé el carrito vacío. Cuando quieras, empezamos de nuevo.');
        return;
    }

    if ($isConfirm || in_array(mb_strtoupper($msg), ['CONFIRMAR','ORDENAR','PEDIR'], true)) {
        if (empty($cart['items'])) {
            bot_send($pdo, $cfg, $wa, 'Tu carrito está vacío. Pídeme el menu o escríbeme lo que deseas comprar.');
            return;
        }
        if (trim((string)($cart['customer_name'] ?? '')) === '') {
            $cart['awaiting_field'] = 'name';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Antes de confirmar, necesito tu nombre completo.');
            return;
        }
        if (trim((string)($cart['customer_address'] ?? '')) === '') {
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Ya casi. Ahora necesito la direccion de entrega.');
            return;
        }
        $summary = bot_cart_text($pdo, $cart);
        $res = bot_create_order($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = bot_default_cart((string)$cart['customer_name']);
            $cartAfter['customer_address'] = (string)$cart['customer_address'];
            $cartAfter['greet_count'] = (int)($cart['greet_count'] ?? 0);
            $cartAfter['last_order'] = is_array($res['order_snapshot'] ?? null) ? $res['order_snapshot'] : null;
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            $summaryText = preg_replace('/\nSi lo ves bien, escribe CONFIRMAR y lo cierro por ti\.\s*$/u', '', $summary['text']) ?? $summary['text'];
            bot_send($pdo, $cfg, $wa, "Perfecto, {$cart['customer_name']}. Ya quedó creado tu pedido #{$res['id_pedido']} por $" . number_format((float)$res['total'],2) . ".\n\nResumen final:\n" . $summaryText . "\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'confirm'));
        } else {
            bot_send($pdo, $cfg, $wa, "No pude crear el pedido: {$res['msg']}");
        }
        return;
    }

    bot_send(
        $pdo,
        $cfg,
        $wa,
        "No te entendí del todo, pero te ayudo.\nPuedes escribirme cosas como:\n- quiero 2 croquetas y 1 refresco sin hielo\n- quita la pizza\n- carrito\n- confirmar\n- lo de siempre\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'fallback')
    );
}

bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'promo_chats','promo_products','promo_my_group_payload','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete',
    'promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'bridge_restart','bridge_logs'
];
if (in_array($action, $adminActions, true)) bot_require_admin_session();

if ($action === 'webhook_verify' || ($_SERVER['REQUEST_METHOD']==='GET' && (isset($_GET['hub.mode']) || isset($_GET['hub_mode'])))) {
    $mode = $_GET['hub.mode'] ?? $_GET['hub_mode'] ?? '';
    $token = $_GET['hub.verify_token'] ?? $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub.challenge'] ?? $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $token !== '' && hash_equals((string)($cfg['verify_token'] ?? ''), (string)$token)) {
        header('Content-Type: text/plain; charset=utf-8'); echo $challenge; exit;
    }
    http_response_code(403); echo json_encode(['status'=>'error','msg'=>'verify failed']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='get_config') {
    $safe = $cfg;
    $safe['wa_mode'] = in_array((string)($safe['wa_mode'] ?? ''), ['web','meta_api'], true) ? $safe['wa_mode'] : 'web';
    $safe['bot_tone'] = in_array((string)($safe['bot_tone'] ?? ''), ['premium','popular_cubano','formal_comercial','muy_cercano'], true) ? $safe['bot_tone'] : 'muy_cercano';
    if (!empty($safe['wa_access_token'])) $safe['wa_access_token'] = '************';
    echo json_encode(['status'=>'success','config'=>$safe]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_config') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $waMode = (string)($in['wa_mode'] ?? $cfg['wa_mode'] ?? 'web');
    if (!in_array($waMode, ['web','meta_api'], true)) $waMode = 'web';
    $token = trim((string)($in['wa_access_token'] ?? ''));
    if ($token === '') $token = (string)($cfg['wa_access_token'] ?? '');
    if ($waMode === 'web') {
        $token = '';
    }
    $botTone = (string)($in['bot_tone'] ?? $cfg['bot_tone'] ?? 'muy_cercano');
    if (!in_array($botTone, ['premium','popular_cubano','formal_comercial','muy_cercano'], true)) $botTone = 'muy_cercano';
    $st = $pdo->prepare("UPDATE pos_bot_config SET enabled=?,wa_mode=?,bot_tone=?,verify_token=?,wa_phone_number_id=?,wa_access_token=?,business_name=?,welcome_message=?,menu_intro=?,no_match_message=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        $waMode,
        $botTone,
        substr(trim((string)($in['verify_token'] ?? $cfg['verify_token'] ?? 'palweb_bot_verify')),0,120),
        $waMode === 'web' ? '' : substr(trim((string)($in['wa_phone_number_id'] ?? $cfg['wa_phone_number_id'] ?? '')),0,80),
        $token,
        substr(trim((string)($in['business_name'] ?? $cfg['business_name'] ?? 'PalWeb POS')),0,120),
        trim((string)($in['welcome_message'] ?? $cfg['welcome_message'] ?? '')),
        trim((string)($in['menu_intro'] ?? $cfg['menu_intro'] ?? '')),
        trim((string)($in['no_match_message'] ?? $cfg['no_match_message'] ?? '')),
    ]);
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='stats') {
    $s = [
        'sessions' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_sessions")->fetchColumn(),
        'msgs_today' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_messages WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'orders_today' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'sales_today' => (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    ];
    echo json_encode(['status'=>'success','stats'=>$s]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_messages') {
    $rows = $pdo->query("SELECT m.wa_user_id,m.direction,m.msg_type,m.message_text,m.created_at,COALESCE(s.wa_name,'') AS wa_name
        FROM pos_bot_messages m
        LEFT JOIN pos_bot_sessions s ON s.wa_user_id = m.wa_user_id
        ORDER BY m.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_orders') {
    $rows = $pdo->query("SELECT bo.id,bo.id_pedido,bo.wa_user_id,bo.total,bo.created_at,pc.cliente_nombre FROM pos_bot_orders bo LEFT JOIN pedidos_cabecera pc ON pc.id=bo.id_pedido ORDER BY bo.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='bridge_status') {
    $statusFile = $BOT_BRIDGE_STATUS_FILE;
    if (!is_file($statusFile)) {
        $running = bot_is_bridge_process_running();
        echo json_encode([
            'status'=>'success',
            'bridge'=>[
                'state'=>$running ? 'starting' : 'stopped',
                'msg'=>$running ? 'Bridge ejecutándose sin status.json aún' : 'Bridge detenido o sin status.json'
            ]
        ]);
        exit;
    }
    $raw = @file_get_contents($statusFile);
    $bridge = json_decode((string)$raw, true);
    if (!is_array($bridge)) {
        $running = bot_is_bridge_process_running();
        echo json_encode([
            'status'=>'success',
            'bridge'=>[
                'state'=>$running ? 'starting' : 'unknown',
                'msg'=>'Estado invalido en status.json'
            ]
        ]);
        exit;
    }
    $updatedAt = strtotime((string)($bridge['updated_at'] ?? '')) ?: 0;
    $ageSec = $updatedAt > 0 ? (time() - $updatedAt) : PHP_INT_MAX;
    if ($ageSec > 45) {
        $bridge['state'] = 'disconnected';
        $bridge['msg'] = 'Estado del bridge desactualizado';
        $bridge['stale_seconds'] = $ageSec;
    }
    echo json_encode(['status'=>'success','bridge'=>$bridge]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='bridge_restart') {
    $commands = [
        'systemctl restart palweb-wa-bridge.service 2>&1',
        'sudo -n systemctl restart palweb-wa-bridge.service 2>&1'
    ];
    $lastOut = '';
    $ok = false;
    foreach ($commands as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $lastOut = bot_sanitize_shell_output(implode("\n", $out));
        if ($code === 0) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $detail = $lastOut !== '' ? $lastOut : 'Permisos insuficientes para systemctl desde PHP.';
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo reiniciar el bridge desde PHP. Revisa permisos de systemctl/sudo.',
            'detail' => $detail
        ]);
        exit;
    }

    $activeOut = [];
    $activeCode = 1;
    @exec('systemctl is-active palweb-wa-bridge.service 2>&1', $activeOut, $activeCode);
    $active = trim(implode("\n", $activeOut));
    echo json_encode([
        'status' => 'success',
        'msg' => 'Bridge reiniciado. Estado: ' . ($active !== '' ? $active : 'desconocido')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='bridge_logs') {
    $cmds = [
        'journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1',
        'sudo -n journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1'
    ];
    $logText = '';
    foreach ($cmds as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $txt = bot_sanitize_shell_output(implode("\n", $out));
        if ($txt !== '') {
            $logText = $txt;
            if ($code === 0) break;
        }
    }
    if ($logText === '') {
        $logText = 'Sin logs disponibles (o sin permisos de journal para el usuario web).';
    }
    $statusRaw = @file_get_contents($BOT_BRIDGE_STATUS_FILE);
    $status = json_decode((string)$statusRaw, true);
    echo json_encode([
        'status' => 'success',
        'logs' => $logText,
        'bridge' => is_array($status) ? $status : null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_chats') {
    $data = bot_read_json_file($BOT_BRIDGE_CHATS_FILE, ['updated_at' => null, 'rows' => []]);
    $rows = [];
    foreach (($data['rows'] ?? []) as $r) {
        $id = substr(trim((string)($r['id'] ?? '')), 0, 120);
        if ($id === '') continue;
        $rows[] = [
            'id' => $id,
            'name' => substr(trim((string)($r['name'] ?? $id)), 0, 200),
            'is_group' => !empty($r['is_group']) ? 1 : 0,
            'is_contact' => !empty($r['is_contact']) ? 1 : 0,
        ];
    }
    usort($rows, static function ($a, $b) {
        if ((int)$a['is_group'] !== (int)$b['is_group']) return (int)$b['is_group'] <=> (int)$a['is_group'];
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    echo json_encode(['status' => 'success', 'updated_at' => $data['updated_at'] ?? null, 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_products') {
    $q = trim((string)($_GET['q'] ?? ''));
    $lim = max(1, min(30, (int)($_GET['limit'] ?? 20)));
    $like = '%' . $q . '%';
    $sql = "SELECT codigo,nombre,precio
            FROM productos
            WHERE activo=1 AND id_empresa=?
              AND (nombre LIKE ? OR codigo LIKE ?)
            ORDER BY nombre ASC
            LIMIT {$lim}";
    $st = $pdo->prepare($sql);
    $st->execute([(int)$config['id_empresa'], $like, $like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    $base = bot_public_base_url();
    foreach ($rows as $r) {
        $sku = (string)$r['codigo'];
        $img = $base . "/image.php?code=" . rawurlencode($sku);
        $out[] = [
            'id' => $sku,
            'name' => (string)$r['nombre'],
            'price' => (float)$r['precio'],
            'image' => $img
        ];
    }
    echo json_encode(['status'=>'success','rows'=>$out]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_my_group_payload') {
    $payload = bot_my_group_campaign_payload($pdo, $config);
    echo json_encode([
        'status' => 'success',
        'products' => $payload['products'],
        'reservables' => $payload['reservables'],
        'outro_text' => $payload['outro_text']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_templates') {
    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $text = trim((string)($in['text'] ?? ''));
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($name === '') { echo json_encode(['status' => 'error', 'msg' => 'Nombre de plantilla obligatorio']); exit; }
    if ($text === '' && count($products) === 0 && count($bannerImages) === 0) { echo json_encode(['status' => 'error', 'msg' => 'Plantilla vacía']); exit; }

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'image' => trim((string)($p['image'] ?? ''))
        ];
    }
    $cleanBannerImages = [];
    foreach ($bannerImages as $img) {
        $url = trim((string)($img['url'] ?? $img));
        if ($url === '') continue;
        $cleanBannerImages[] = [
            'url' => substr($url, 0, 500),
            'name' => substr(trim((string)($img['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'banner'))), 0, 180)
        ];
        if (count($cleanBannerImages) >= 3) break;
    }

    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $nowIso = date('c');
    if ($templateId === '') $templateId = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

    $updated = false;
    foreach ($rows as &$row) {
        if ((string)($row['id'] ?? '') !== $templateId) continue;
        $row['name'] = $name;
        $row['text'] = $text;
        $row['products'] = $cleanProducts;
        $row['banner_images'] = $cleanBannerImages;
        $row['updated_at'] = $nowIso;
        $updated = true;
        break;
    }
    unset($row);
    if (!$updated) {
        $rows[] = [
            'id' => $templateId,
            'name' => $name,
            'text' => $text,
            'products' => $cleanProducts,
            'banner_images' => $cleanBannerImages,
            'updated_at' => $nowIso,
            'created_by' => $_SESSION['admin_name'] ?? 'admin'
        ];
    }
    if (!bot_write_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar plantilla']); exit;
    }
    echo json_encode(['status' => 'success', 'id' => $templateId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_upload_image') {
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Imagen requerida']); exit;
    }
    $file = $_FILES['image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'msg' => 'Error al subir imagen']); exit;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        echo json_encode(['status' => 'error', 'msg' => 'Carga inválida']); exit;
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'msg' => 'La imagen supera 5MB']); exit;
    }
    $mime = (string)(mime_content_type($tmp) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['status' => 'error', 'msg' => 'Formato no permitido']); exit;
    }
    $dir = __DIR__ . '/uploads/promo_campaigns';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo crear carpeta de banners']); exit;
    }
    $nameSafe = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)($file['name'] ?? 'banner')) ?: 'banner';
    $baseName = pathinfo($nameSafe, PATHINFO_FILENAME);
    $ext = $allowed[$mime];
    $fileName = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . substr($baseName, 0, 40) . '.' . $ext;
    $target = $dir . '/' . $fileName;
    if (!@move_uploaded_file($tmp, $target)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar la imagen']); exit;
    }
    @chmod($target, 0664);
    echo json_encode([
        'status' => 'success',
        'url' => bot_public_base_url() . '/uploads/promo_campaigns/' . rawurlencode($fileName),
        'name' => $fileName
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($templateId === '') { echo json_encode(['status' => 'error', 'msg' => 'id requerido']); exit; }
    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn($r) => (string)($r['id'] ?? '') !== $templateId));
    if (!bot_write_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo borrar plantilla']); exit;
    }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_list') {
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = array_slice(array_reverse($queue['jobs'] ?? []), 0, 25);
    echo json_encode(['status' => 'success', 'rows' => $jobs]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_detail') {
    $jobId = substr(trim((string)($_GET['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    foreach ($jobs as $job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        echo json_encode(['status' => 'success', 'row' => $job]); exit;
    }
    echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_force_now') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;
    $now = time();
    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;
        $wasDone = (($job['status'] ?? '') === 'done');
        $reachedEnd = ((int)($job['current_index'] ?? 0) >= count((array)($job['targets'] ?? [])));
        $job['status'] = 'queued';
        $job['next_run_at'] = $now;
        $job['forced_at'] = date('c', $now);
        $job['last_schedule_key'] = '';
        if ($wasDone || $reachedEnd) {
            $job['current_index'] = 0;
        }
        if (!is_array($job['log'] ?? null)) $job['log'] = [];
        $job['log'][] = [
            'at' => date('c', $now),
            'type' => 'forced_start',
            'ok' => true,
            'target_id' => '',
            'target_name' => '',
            'messages_sent' => 0,
            'error' => ''
        ];
        break;
    }
    unset($job);
    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo forzar la campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_update') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }

    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;

    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;

        if (array_key_exists('name', $in)) {
            $job['name'] = substr(trim((string)$in['name']), 0, 120);
        }
        if (array_key_exists('campaign_group', $in)) {
            $job['campaign_group'] = substr(trim((string)$in['campaign_group']), 0, 80);
            if ($job['campaign_group'] === '') $job['campaign_group'] = 'General';
        }
        if (array_key_exists('schedule_enabled', $in)) {
            $job['schedule_enabled'] = !empty($in['schedule_enabled']) ? 1 : 0;
        }
        if (array_key_exists('schedule_time', $in)) {
            $tm = substr(trim((string)$in['schedule_time']), 0, 5);
            if ($tm !== '' && !preg_match('/^\d{2}:\d{2}$/', $tm)) {
                echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
            }
            $job['schedule_time'] = $tm;
        }
        if (array_key_exists('schedule_days', $in)) {
            $daysIn = is_array($in['schedule_days']) ? $in['schedule_days'] : [];
            $days = [];
            foreach ($daysIn as $d) {
                $n = (int)$d;
                if ($n >= 0 && $n <= 6) $days[$n] = $n;
            }
            $days = array_values($days);
            sort($days);
            $job['schedule_days'] = $days;
        }
        if (array_key_exists('status', $in)) {
            $status = strtolower(trim((string)$in['status']));
            if (in_array($status, ['scheduled','queued','running','paused','done','error'], true)) {
                $job['status'] = $status;
            }
        }

        if (!array_key_exists('next_run_at', $job) || !is_numeric($job['next_run_at'])) {
            $job['next_run_at'] = time() + 20;
        }
        if ((int)($job['schedule_enabled'] ?? 0) === 1 && ($job['status'] ?? '') === 'done') {
            $job['status'] = 'scheduled';
            $job['current_index'] = 0;
            $job['next_run_at'] = time() + 20;
        }
        break;
    }
    unset($job);

    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo actualizar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $before = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['id'] ?? '') !== $jobId));
    if ($before === count($jobs)) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo eliminar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_create') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($in['text'] ?? ''));
    $outroText = trim((string)($in['outro_text'] ?? ''));
    $targets = is_array($in['targets'] ?? null) ? $in['targets'] : [];
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    $minSec = max(60, min(180, (int)($in['min_seconds'] ?? 60)));
    $maxSec = max($minSec, min(300, (int)($in['max_seconds'] ?? 120)));
    $campaignName = substr(trim((string)($in['campaign_name'] ?? '')), 0, 120);
    $campaignGroup = substr(trim((string)($in['campaign_group'] ?? 'General')), 0, 80);
    $scheduleTime = substr(trim((string)($in['schedule_time'] ?? '')), 0, 5);
    $scheduleDays = is_array($in['schedule_days'] ?? null) ? $in['schedule_days'] : [];
    $templateId = substr(trim((string)($in['template_id'] ?? '')), 0, 80);
    $scheduleEnabled = !empty($in['schedule_enabled']) ? 1 : 0;

    if ($templateId !== '' && ($text === '' || count($products) === 0)) {
        $tplData = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
        foreach (($tplData['rows'] ?? []) as $tpl) {
            if ((string)($tpl['id'] ?? '') !== $templateId) continue;
            if ($text === '') $text = trim((string)($tpl['text'] ?? ''));
            if (count($products) === 0 && is_array($tpl['products'] ?? null)) $products = $tpl['products'];
            if (count($bannerImages) === 0 && is_array($tpl['banner_images'] ?? null)) $bannerImages = $tpl['banner_images'];
            if ($campaignName === '') $campaignName = substr(trim((string)($tpl['name'] ?? '')), 0, 120);
            break;
        }
    }

    $cleanTargetsMap = [];
    foreach ($targets as $t) {
        $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
        if ($id === '') continue;
        $name = substr(trim((string)($t['name'] ?? $id)), 0, 200);
        $cleanTargetsMap[$id] = $name !== '' ? $name : $id;
    }
    $targetsFinal = [];
    foreach ($cleanTargetsMap as $id => $name) $targetsFinal[] = ['id' => $id, 'name' => $name];

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'image' => trim((string)($p['image'] ?? ('image.php?code=' . rawurlencode($pid))))
        ];
    }
    $cleanBannerImages = [];
    foreach ($bannerImages as $img) {
        $url = trim((string)($img['url'] ?? $img));
        if ($url === '') continue;
        $cleanBannerImages[] = [
            'url' => substr($url, 0, 500),
            'name' => substr(trim((string)($img['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'banner'))), 0, 180)
        ];
        if (count($cleanBannerImages) >= 3) break;
    }

    if ($text === '' && $outroText === '' && count($cleanProducts) === 0 && count($cleanBannerImages) === 0) { echo json_encode(['status'=>'error','msg'=>'La campaña está vacía']); exit; }
    if (count($targetsFinal) === 0) { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un grupo/chat']); exit; }
    if (count($cleanProducts) === 0 && $outroText === '') { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un producto']); exit; }

    $daysFinal = [];
    foreach ($scheduleDays as $d) {
        $n = (int)$d;
        if ($n >= 0 && $n <= 6) $daysFinal[$n] = $n;
    }
    $daysFinal = array_values($daysFinal);
    sort($daysFinal);
    if ($scheduleEnabled) {
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
            echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
        }
        if (count($daysFinal) === 0) {
            echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un día']); exit;
        }
    } else {
        $scheduleTime = '';
        $daysFinal = [];
    }

    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobId = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $now = time();
    $queue['jobs'][] = [
        'id' => $jobId,
        'status' => $scheduleEnabled ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => $campaignName !== '' ? $campaignName : ('Campaña ' . date('d/m H:i')),
        'campaign_group' => $campaignGroup !== '' ? $campaignGroup : 'General',
        'template_id' => $templateId,
        'text' => $text,
        'banner_images' => $cleanBannerImages,
        'outro_text' => $outroText,
        'targets' => $targetsFinal,
        'products' => $cleanProducts,
        'min_seconds' => $minSec,
        'max_seconds' => $maxSec,
        'schedule_enabled' => $scheduleEnabled,
        'schedule_time' => $scheduleTime,
        'schedule_days' => $daysFinal,
        'last_schedule_key' => '',
        'next_run_at' => $scheduleEnabled ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => []
    ];
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, $queue)) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo guardar cola de promoción']); exit;
    }
    echo json_encode(['status'=>'success','id'=>$jobId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='test_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = preg_replace('/\D+/', '', (string)($in['wa_user_id'] ?? '5350000000'));
    $name = trim((string)($in['wa_name'] ?? 'Cliente Test'));
    $text = trim((string)($in['text'] ?? 'MENU'));
    bot_log($pdo, $wa, 'in', $text);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='web_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $provided = (string)($in['verify_token'] ?? '');
    $expected = (string)($cfg['verify_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','msg'=>'invalid token']); exit;
    }
    if (empty($cfg['enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>'bot disabled','responses'=>[]]); exit;
    }
    if (($cfg['wa_mode'] ?? 'web') !== 'web') {
        echo json_encode(['status'=>'ok','msg'=>'wa_mode is not web','responses'=>[]]); exit;
    }
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') {
        http_response_code(400);
        echo json_encode(['status'=>'error','msg'=>'wa_user_id required']); exit;
    }
    $name = trim((string)($in['wa_name'] ?? 'Cliente'));
    $text = trim((string)($in['text'] ?? ''));
    $ignoredPayloads = ['[e2e_notification]','[notification_template]','[protocol]','[ciphertext]','[gp2]','[revoked]'];
    if (in_array(strtolower($text), array_map('strtolower', $ignoredPayloads), true)) {
        echo json_encode(['status'=>'ok','msg'=>'technical payload ignored','responses'=>[]]); exit;
    }
    if ($text === '') {
        echo json_encode(['status'=>'ok','msg'=>'empty text ignored','responses'=>[]]); exit;
    }
    bot_log($pdo, $wa, 'in', $text);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (empty($cfg['enabled'])) { echo json_encode(['status'=>'ok','msg'=>'bot disabled']); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $chg) {
            $value = $chg['value'] ?? [];
            foreach (($value['messages'] ?? []) as $m) {
                $from = preg_replace('/\D+/', '', (string)($m['from'] ?? ''));
                if ($from === '') continue;
                $name = (string)($value['contacts'][0]['profile']['name'] ?? 'Cliente');
                $type = (string)($m['type'] ?? 'text');
                $text = $type === 'text' ? (string)($m['text']['body'] ?? '') : (string)($m['button']['text'] ?? '[non-text]');
                bot_log($pdo, $from, 'in', $text, $type);
                bot_handle_text($pdo, $cfg, $config, $from, $name, $text);
            }
        }
    }
    echo json_encode(['status'=>'ok']); exit;
}

echo json_encode(['status'=>'error','msg'=>'invalid request']);
