<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/habana_delivery.php';
require_once __DIR__ . '/push_notify.php';

$BOT_OUTBOX = [];
$BOT_BRIDGE_STATUS_FILE = __DIR__ . '/wa_web_bridge/status.json';
$BOT_BRIDGE_CHATS_FILE = '/tmp/palweb_wa_chats.json';
$BOT_PROMO_QUEUE_FILE = '/tmp/palweb_wa_promo_queue.json';
$BOT_PROMO_TEMPLATES_FILE = '/tmp/palweb_wa_promo_templates.json';
$BOT_PROMO_GROUP_LISTS_FILE = '/tmp/palweb_wa_promo_group_lists.json';
$BOT_BRIDGE_OUTBOX_FILE = '/tmp/palweb_wa_outbox_queue.json';
$BOT_AUTOREPLY_REQUEST = false;
$BOT_NEW_CLIENT_NOTIFY = [];

function bot_clone_promo_job(array $job): array {
    $now = time();
    $baseName = trim((string)($job['name'] ?? 'Campaña'));
    $newName = $baseName !== '' ? ($baseName . ' (Copia)') : ('Campaña ' . date('d/m H:i', $now) . ' (Copia)');
    return [
        'id' => 'promo_' . date('Ymd_His', $now) . '_' . bin2hex(random_bytes(3)),
        'status' => !empty($job['schedule_enabled']) ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => mb_substr($newName, 0, 120),
        'campaign_group' => substr(trim((string)($job['campaign_group'] ?? 'General')), 0, 80) ?: 'General',
        'template_id' => substr(trim((string)($job['template_id'] ?? '')), 0, 80),
        'text' => trim((string)($job['text'] ?? '')),
        'banner_images' => array_values(is_array($job['banner_images'] ?? null) ? $job['banner_images'] : []),
        'outro_text' => trim((string)($job['outro_text'] ?? '')),
        'targets' => array_values(is_array($job['targets'] ?? null) ? $job['targets'] : []),
        'products' => array_values(is_array($job['products'] ?? null) ? $job['products'] : []),
        'min_seconds' => max(60, min(180, (int)($job['min_seconds'] ?? 60))),
        'max_seconds' => max(60, min(300, (int)($job['max_seconds'] ?? 120))),
        'schedule_enabled' => !empty($job['schedule_enabled']) ? 1 : 0,
        'schedule_time' => substr(trim((string)($job['schedule_time'] ?? '')), 0, 5),
        'schedule_days' => array_values(is_array($job['schedule_days'] ?? null) ? $job['schedule_days'] : []),
        'last_schedule_key' => '',
        'next_run_at' => !empty($job['schedule_enabled']) ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => []
    ];
}

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

function bot_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
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

function bot_enqueue_bridge_job(array $job): bool {
    global $BOT_BRIDGE_OUTBOX_FILE;
    $queue = bot_read_json_file($BOT_BRIDGE_OUTBOX_FILE, ['jobs' => []]);
    $queue['jobs'] = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $job['id'] = trim((string)($job['id'] ?? ('out_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)))));
    $job['created_at'] = $job['created_at'] ?? date('c');
    $job['status'] = $job['status'] ?? 'queued';
    $queue['jobs'][] = $job;
    return bot_write_json_file($BOT_BRIDGE_OUTBOX_FILE, $queue);
}

function bot_public_base_url(): string {
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

function bot_normalize_banner_images($input): array {
    $items = is_array($input) ? $input : [];
    $out = [];
    $hasSeen = [];
    foreach ($items as $img) {
        $url = trim((string)($img['url'] ?? $img));
        if ($url === '') continue;
        if (!empty($hasSeen[$url])) continue;
        $hasSeen[$url] = true;
        $out[] = [
            'url' => substr($url, 0, 500),
            'name' => substr(trim((string)($img['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'banner'))), 0, 180)
        ];
        if (count($out) >= 3) break;
    }
    return $out;
}

function bot_run_bridge_service_command(string $verb, ?string &$detail = null): bool {
    $verb = trim($verb);
    if ($verb === '') {
        $detail = 'Comando de servicio vacío.';
        return false;
    }
    $commands = [
        "/usr/bin/systemctl {$verb} palweb-wa-bridge.service 2>&1",
        "/usr/bin/sudo -n /usr/bin/systemctl {$verb} palweb-wa-bridge.service 2>&1"
    ];
    $lastOut = '';
    foreach ($commands as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $lastOut = bot_sanitize_shell_output(implode("\n", $out));
        if ($code === 0) {
            $detail = $lastOut;
            return true;
        }
    }
    $detail = $lastOut !== '' ? $lastOut : 'Permisos insuficientes para systemctl desde PHP.';
    return false;
}

function bot_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_config (
        id TINYINT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        auto_schedule_enabled TINYINT(1) NOT NULL DEFAULT 1,
        auto_off_start CHAR(5) NOT NULL DEFAULT '07:00',
        auto_off_end CHAR(5) NOT NULL DEFAULT '20:00',
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
    if (!bot_has_column($pdo, 'pos_bot_config', 'auto_schedule_enabled')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN auto_schedule_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled");
    }
    if (!bot_has_column($pdo, 'pos_bot_config', 'auto_off_start')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN auto_off_start CHAR(5) NOT NULL DEFAULT '07:00' AFTER auto_schedule_enabled");
    }
    if (!bot_has_column($pdo, 'pos_bot_config', 'auto_off_end')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN auto_off_end CHAR(5) NOT NULL DEFAULT '20:00' AFTER auto_off_start");
    }
    if (!bot_has_column($pdo, 'pos_bot_config', 'bot_tone')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN bot_tone ENUM('premium','popular_cubano','formal_comercial','muy_cercano') NOT NULL DEFAULT 'muy_cercano' AFTER wa_mode");
    }
    $pdo->exec("UPDATE pos_bot_config SET wa_mode='web' WHERE wa_mode IS NULL OR wa_mode=''");
    $pdo->exec("UPDATE pos_bot_config SET bot_tone='muy_cercano' WHERE bot_tone IS NULL OR bot_tone=''");
    $pdo->exec("UPDATE pos_bot_config SET auto_off_start='07:00' WHERE auto_off_start IS NULL OR auto_off_start=''");
    $pdo->exec("UPDATE pos_bot_config SET auto_off_end='20:00' WHERE auto_off_end IS NULL OR auto_off_end=''");
}

function bot_cfg(PDO $pdo): array {
    $c = $pdo->query("SELECT * FROM pos_bot_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    return $c ?: [];
}

function bot_session_exists(PDO $pdo, string $wa): bool {
    $st = $pdo->prepare("SELECT 1 FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    return (bool)$st->fetchColumn();
}

function bot_begin_autoreply_request(PDO $pdo, string $wa): void {
    global $BOT_AUTOREPLY_REQUEST, $BOT_NEW_CLIENT_NOTIFY;
    $BOT_AUTOREPLY_REQUEST = true;
    if ($wa !== '' && !bot_session_exists($pdo, $wa)) {
        $BOT_NEW_CLIENT_NOTIFY[$wa] = true;
    }
}

function bot_end_autoreply_request(string $wa = ''): void {
    global $BOT_AUTOREPLY_REQUEST, $BOT_NEW_CLIENT_NOTIFY;
    $BOT_AUTOREPLY_REQUEST = false;
    if ($wa !== '') unset($BOT_NEW_CLIENT_NOTIFY[$wa]);
}

function bot_valid_time_hhmm(string $value, string $fallback): string {
    $value = substr(trim($value), 0, 5);
    return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : $fallback;
}

function bot_time_in_range(string $current, string $start, string $end): bool {
    if ($start === $end) {
        return true;
    }
    if ($start < $end) {
        return $current >= $start && $current < $end;
    }
    return $current >= $start || $current < $end;
}

function bot_autoreply_state(array $cfg, string $timezone = 'America/Havana'): array {
    $manualEnabled = !empty($cfg['enabled']);
    $scheduleEnabled = !empty($cfg['auto_schedule_enabled']);
    $start = bot_valid_time_hhmm((string)($cfg['auto_off_start'] ?? '07:00'), '07:00');
    $end = bot_valid_time_hhmm((string)($cfg['auto_off_end'] ?? '20:00'), '20:00');
    $tz = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now', $tz);
    $current = $now->format('H:i');
    $mutedBySchedule = $scheduleEnabled && bot_time_in_range($current, $start, $end);
    $effectiveEnabled = $manualEnabled && !$mutedBySchedule;
    $reason = $effectiveEnabled ? 'Autorepuesta activa.' : 'Autorepuesta desactivada manualmente.';
    if ($manualEnabled && $mutedBySchedule) {
        $reason = "Autorepuesta en apagado automatico de {$start} a {$end} (hora Habana).";
    } elseif ($manualEnabled && $scheduleEnabled && !$mutedBySchedule) {
        $reason = "Autorepuesta activa fuera de la franja {$start} a {$end} (hora Habana).";
    }
    return [
        'manual_enabled' => $manualEnabled ? 1 : 0,
        'schedule_enabled' => $scheduleEnabled ? 1 : 0,
        'auto_off_start' => $start,
        'auto_off_end' => $end,
        'timezone' => $timezone,
        'now_havana' => $now->format('Y-m-d H:i:s'),
        'now_havana_hm' => $current,
        'muted_by_schedule' => $mutedBySchedule ? 1 : 0,
        'effective_enabled' => $effectiveEnabled ? 1 : 0,
        'reason' => $reason,
    ];
}

function bot_log(PDO $pdo, string $wa, string $dir, string $txt, string $type='text'): void {
    $st = $pdo->prepare("INSERT INTO pos_bot_messages (wa_user_id,direction,msg_type,message_text) VALUES (?,?,?,?)");
    $st->execute([$wa,$dir,$type,$txt]);
}

function bot_queue_response(PDO $pdo, string $wa, string $type, array $payload): void {
    global $BOT_OUTBOX, $BOT_AUTOREPLY_REQUEST, $BOT_NEW_CLIENT_NOTIFY;
    if ($BOT_AUTOREPLY_REQUEST) {
        $liveCfg = bot_cfg($pdo);
        $liveReplyState = bot_autoreply_state($liveCfg);
        if (empty($liveReplyState['effective_enabled'])) {
            return;
        }
    }
    $row = ['wa_user_id' => $wa, 'type' => $type] + $payload;
    $BOT_OUTBOX[] = $row;
    $logText = trim((string)($payload['text'] ?? $payload['caption'] ?? $payload['url'] ?? ''));
    if ($logText !== '') bot_log($pdo, $wa, 'out', $logText, $type);
    if (!empty($BOT_NEW_CLIENT_NOTIFY[$wa])) {
        $preview = trim((string)($payload['text'] ?? $payload['caption'] ?? ''));
        if ($preview === '') $preview = 'El bot respondió por primera vez a un cliente nuevo.';
        push_notify(
            $pdo,
            'operador',
            '🤖 Bot respondió a un cliente nuevo',
            "{$wa}" . ($preview !== '' ? " — " . mb_substr($preview, 0, 120) : ''),
            '/marinero/pos_bot.php',
            'bot_first_reply_new_client'
        );
        unset($BOT_NEW_CLIENT_NOTIFY[$wa]);
    }
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

function bot_send_buttons(PDO $pdo, string $wa, string $body, array $buttons, string $title='', string $footer=''): void {
    $cleanButtons = [];
    foreach ($buttons as $b) {
        $txt = trim((string)$b);
        if ($txt === '') continue;
        $cleanButtons[] = $txt;
        if (count($cleanButtons) >= 3) break;
    }
    if (!$cleanButtons) {
        if (trim($body) !== '') bot_send($pdo, [], $wa, $body);
        return;
    }
    bot_queue_response($pdo, $wa, 'buttons', [
        'text' => trim($body),
        'buttons' => $cleanButtons,
        'title' => trim($title),
        'footer' => trim($footer)
    ]);
}

function bot_send_quick_actions(PDO $pdo, array $cfg, array $appCfg, string $wa, string $context='main'): void {
    $title = 'Accesos rápidos';
    $footer = preg_replace('~^https?://~i', '', bot_site_url($appCfg));
    $body1 = $context === 'recovery'
        ? 'Retoma tu compra con un toque:'
        : 'Usa estos botones para ir más rápido:';
    bot_send_buttons($pdo, $wa, $body1, ['Menu', 'Repetir pedido', 'Carrito'], $title, $footer);
    bot_send_buttons($pdo, $wa, 'Más opciones:', ['Confirmar', 'Comprar en web'], $title, $footer);
}

function bot_mark_cart_activity(array &$cart): void {
    $cart['last_cart_activity_at'] = date('c');
    $cart['last_cart_reminder_at'] = '';
}

function bot_default_cart(string $name=''): array {
    return [
        'items' => [],
        'item_notes' => [],
        'customer_name' => $name,
        'customer_address' => '',
        'fulfillment_mode' => '',
        'address_validation' => null,
        'delivery_fee' => 0.0,
        'delivery_distance_km' => 0.0,
        'delivery_rate_per_km' => 100.0,
        'requested_datetime' => '',
        'requested_datetime_label' => '',
        'escalation_active' => 0,
        'escalation_reason' => '',
        'escalation_label' => '',
        'escalation_at' => '',
        'awaiting_field' => '',
        'pending_choice' => null,
        'last_order' => null,
        'greet_count' => 0,
        'bot_paused' => 0,
        'last_cart_activity_at' => '',
        'last_cart_reminder_at' => '',
        'last_manual_reply_at' => ''
    ];
}

function bot_merge_cart_shape(array $cart, string $name=''): array {
    $base = bot_default_cart($name);
    $cart = array_merge($base, $cart);
    if (!is_array($cart['items'] ?? null)) $cart['items'] = [];
    if (!is_array($cart['item_notes'] ?? null)) $cart['item_notes'] = [];
    if (!is_array($cart['last_order'] ?? null)) $cart['last_order'] = $base['last_order'];
    if (!is_array($cart['pending_choice'] ?? null)) $cart['pending_choice'] = null;
    $cart['bot_paused'] = !empty($cart['bot_paused']) ? 1 : 0;
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

function bot_delivery_rate_per_km(): float {
    return 100.0;
}

function bot_detect_fulfillment_mode(string $norm): string {
    if (bot_intent_has($norm, ['recoger','recojo','recogida','recoger en tienda','paso a buscar','voy a buscar','retiro','retirar'])) {
        return 'pickup';
    }
    if (bot_intent_has($norm, ['mensajeria','mensajería','mensajero','envio','envío','domicilio','delivery','entrega'])) {
        return 'delivery';
    }
    return '';
}

function bot_validate_habana_address(string $address): array {
    $result = palweb_habana_address_resolve($address);
    if (!$result['ok']) return $result;
    $result['rate_per_km'] = bot_delivery_rate_per_km();
    $result['delivery_fee'] = palweb_habana_delivery_fee((float)$result['distance_km'], (float)$result['rate_per_km']);
    return $result;
}

function bot_fulfillment_label(string $mode): string {
    return $mode === 'delivery' ? 'Mensajería' : ($mode === 'pickup' ? 'Recoger en tienda' : 'Sin definir');
}

function bot_delivery_summary_line(array $cart): string {
    if (($cart['fulfillment_mode'] ?? '') !== 'delivery') return 'Entrega: recoger en tienda';
    $km = (float)($cart['delivery_distance_km'] ?? 0);
    $fee = (float)($cart['delivery_fee'] ?? 0);
    $validation = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : [];
    $zone = trim((string)(($validation['municipio'] ?? '') . ' / ' . ($validation['barrio'] ?? '')), ' /');
    $suffix = $zone !== '' ? " ({$zone})" : '';
    return "Mensajería: {$km} km{$suffix} = $" . number_format($fee, 2);
}

function bot_extract_schedule_datetime(string $text, string $timezone='America/Havana'): ?array {
    $raw = trim($text);
    if ($raw === '') return null;
    $norm = bot_norm($raw);
    $normLoose = mb_strtolower($raw, 'UTF-8');
    $normLoose = strtr($normLoose, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u']);
    $tz = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now', $tz);

    $setTime = static function (DateTimeImmutable $base, int $hour, int $minute): DateTimeImmutable {
        return $base->setTime(max(0, min(23, $hour)), max(0, min(59, $minute)), 0);
    };

    if (preg_match('/\b(hoy|manana|mañana)\b/u', $normLoose, $dMatch)) {
        $dayWord = $dMatch[1];
        $base = $dayWord === 'hoy' ? $now : $now->modify('+1 day');
        $hour = null;
        $minute = 0;
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $normLoose, $m) || preg_match('/\b(\d{1,2})(?::(\d{2}))\b/u', $normLoose, $m) || preg_match('/\b(\d{1,2})\s*(am|pm)\b/u', $normLoose, $m)) {
            $hour = (int)$m[1];
            if (isset($m[3]) || isset($m[2])) {
                if (isset($m[3]) && in_array(strtolower((string)$m[3]), ['am','pm'], true)) {
                    $minute = isset($m[2]) && ctype_digit((string)$m[2]) ? (int)$m[2] : 0;
                    $ampm = strtolower((string)$m[3]);
                } else {
                    $minute = isset($m[2]) && ctype_digit((string)$m[2]) ? (int)$m[2] : 0;
                    $ampm = isset($m[2]) && in_array(strtolower((string)$m[2]), ['am','pm'], true) ? strtolower((string)$m[2]) : '';
                }
            } else {
                $ampm = '';
            }
            if ($ampm === 'pm' && $hour < 12) $hour += 12;
            if ($ampm === 'am' && $hour === 12) $hour = 0;
        }
        if ($hour !== null) {
            $dt = $setTime($base, $hour, $minute);
            if ($dt <= $now) $dt = $dt->modify('+1 day');
            return [
                'value' => $dt->format('Y-m-d H:i:s'),
                'label' => $dt->format('d/m/Y h:i A'),
                'date' => $dt->format('Y-m-d'),
                'time' => $dt->format('H:i')
            ];
        }
    }

    $formats = [
        'd/m/Y H:i', 'd-m-Y H:i', 'Y-m-d H:i',
        'd/m/Y g:i A', 'd-m-Y g:i A', 'Y-m-d g:i A',
        'd/m/Y g A', 'd-m-Y g A'
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0 && $dt > $now) {
                return [
                    'value' => $dt->format('Y-m-d H:i:s'),
                    'label' => $dt->format('d/m/Y h:i A'),
                    'date' => $dt->format('Y-m-d'),
                    'time' => $dt->format('H:i')
                ];
            }
        }
    }

    if (preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*$/iu', $raw, $m)) {
        $hour = (int)$m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $ampm = strtolower((string)($m[3] ?? ''));
        if ($ampm === 'pm' && $hour < 12) $hour += 12;
        if ($ampm === 'am' && $hour === 12) $hour = 0;
        $dt = $setTime($now, $hour, $minute);
        if ($dt <= $now) $dt = $dt->modify('+1 day');
        return [
            'value' => $dt->format('Y-m-d H:i:s'),
            'label' => $dt->format('d/m/Y h:i A'),
            'date' => $dt->format('Y-m-d'),
            'time' => $dt->format('H:i')
        ];
    }

    return null;
}

function bot_delivery_address_prompt(): string {
    return "Compárteme la dirección completa en La Habana, Cuba.\nEjemplo: Calle 10 #45 entre A y B, Vedado, Plaza de la Revolución, La Habana.";
}

function bot_datetime_prompt(string $mode): string {
    $target = $mode === 'delivery' ? 'entrega' : 'recogida en tienda';
    return "Ahora dime la fecha y hora para la {$target}.\nEjemplos válidos:\n- hoy 5:30 pm\n- mañana 10:00 am\n- 15/03/2026 14:30";
}

function bot_ticket_url(int $idReserva): string {
    return bot_public_base_url() . '/ticket_view.php?id=' . $idReserva . '&source=qr';
}

function bot_send_fulfillment_prompt(PDO $pdo, string $wa): void {
    bot_send_buttons($pdo, $wa, 'Antes de cerrar la reserva, dime cómo la quieres recibir:', ['Recoger en tienda', 'Mensajeria'], 'Tipo de entrega', 'La Habana / Cuba');
}

function bot_detect_escalation(string $norm): ?array {
    $rules = [
        ['label' => 'No ha llegado', 'reason' => 'delivery_delay', 'patterns' => ['no ha llegado', 'todavia no llega', 'todavia no ha llegado', 'mi pedido no llega', 'no me ha llegado', 'demora pedido']],
        ['label' => 'Cobro incorrecto', 'reason' => 'billing_issue', 'patterns' => ['me cobraron mal', 'cobro mal', 'cobro incorrecto', 'precio mal', 'me cobraron de mas', 'me cobraron demás']],
        ['label' => 'Reclamación', 'reason' => 'complaint', 'patterns' => ['quiero reclamar', 'quiero poner una queja', 'tengo una queja', 'necesito reclamar', 'reclamo', 'queja']],
    ];
    foreach ($rules as $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (str_contains($norm, bot_norm($pattern))) return $rule;
        }
    }
    return null;
}

function bot_mark_escalation(array &$cart, array $escalation): void {
    $cart['bot_paused'] = 1;
    $cart['escalation_active'] = 1;
    $cart['escalation_reason'] = (string)($escalation['reason'] ?? 'complaint');
    $cart['escalation_label'] = (string)($escalation['label'] ?? 'Atención humana');
    $cart['escalation_at'] = date('c');
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
    global $config;
    $idAlmacen = (int)($config['id_almacen'] ?? 1);
    $st = $pdo->prepare("SELECT p.codigo,p.nombre,p.precio,p.categoria,COALESCE(p.es_servicio,0) AS es_servicio,
                            COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?),0) AS stock_total
                         FROM productos p
                         WHERE activo=1 AND es_web=1 AND id_empresa=?
                           AND (sucursales_web='' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web)>0)
                         ORDER BY categoria,nombre LIMIT 80");
    $st->execute([$idAlmacen,$emp,$suc]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bot_product_in_stock(array $product, int $qty=1): bool {
    if (!empty($product['es_servicio'])) return true;
    return (float)($product['stock_total'] ?? 0) >= max(1, $qty);
}

function bot_find_substitutes(array $menu, array $product, int $limit=3): array {
    $category = trim((string)($product['categoria'] ?? ''));
    $currentSku = (string)($product['codigo'] ?? '');
    $out = [];
    foreach ($menu as $p) {
        if ((string)($p['codigo'] ?? '') === $currentSku) continue;
        if ($category !== '' && trim((string)($p['categoria'] ?? '')) !== $category) continue;
        if (!bot_product_in_stock($p, 1)) continue;
        $out[] = $p;
        if (count($out) >= $limit) break;
    }
    if (!$out) {
        foreach ($menu as $p) {
            if ((string)($p['codigo'] ?? '') === $currentSku) continue;
            if (!bot_product_in_stock($p, 1)) continue;
            $out[] = $p;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
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

function bot_merge_text_block(string $existing, string $append): string {
    $existing = trim($existing);
    $append = trim($append);
    if ($append === '') return $existing;
    if ($existing === '') return $append;
    $lines = preg_split('/\R+/', $existing . "\n" . $append) ?: [];
    $seen = [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $key = mb_strtolower($line, 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $line;
    }
    return implode("\n", $out);
}

function bot_crm_preferences_from_cart(array $cart): string {
    $parts = [];
    $last = is_array($cart['last_order'] ?? null) ? $cart['last_order'] : [];
    $items = is_array($last['items'] ?? null) && $last['items'] ? $last['items'] : (is_array($cart['items'] ?? null) ? $cart['items'] : []);
    $itemNotes = is_array($last['item_notes'] ?? null) && $last['item_notes'] ? $last['item_notes'] : (is_array($cart['item_notes'] ?? null) ? $cart['item_notes'] : []);
    if ($items) {
        $itemParts = [];
        foreach ($items as $sku => $qty) {
            $entry = trim((string)$sku) . ' x' . max(1, (int)$qty);
            $note = trim((string)($itemNotes[$sku] ?? ''));
            if ($note !== '') $entry .= " [{$note}]";
            $itemParts[] = $entry;
        }
        if ($itemParts) $parts[] = 'Preferencias de compra: ' . implode(' | ', $itemParts);
    }
    if (!empty($cart['customer_address'])) {
        $parts[] = 'Dirección habitual: ' . trim((string)$cart['customer_address']);
    }
    return implode("\n", $parts);
}

function bot_sync_crm_client(PDO $pdo, string $wa, string $name, string $address='', array $cart=[], string $extraNote=''): void {
    if (!bot_table_exists($pdo, 'clientes')) return;
    if (!bot_has_column($pdo, 'clientes', 'preferencias')) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN preferencias TEXT NULL");
    }
    $phone = substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50);
    if ($phone === '') return;
    $hasOrigen = bot_has_column($pdo, 'clientes', 'origen');
    $hasCategoria = bot_has_column($pdo, 'clientes', 'categoria');
    $hasNotas = bot_has_column($pdo, 'clientes', 'notas');
    $hasPreferencias = bot_has_column($pdo, 'clientes', 'preferencias');
    $hasFechaRegistro = bot_has_column($pdo, 'clientes', 'fecha_registro');
    $st = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $name = trim($name) !== '' ? trim($name) : 'Cliente WhatsApp';
    $address = trim($address);
    $notes = 'Cliente sincronizado desde pos_bot / WhatsApp.';
    if (trim($extraNote) !== '') $notes .= "\n" . trim($extraNote);
    $preferences = bot_crm_preferences_from_cart($cart);
    if ($row) {
        $fields = ['nombre = ?', 'telefono = ?'];
        $params = [$name, $phone];
        if ($address !== '' && bot_has_column($pdo, 'clientes', 'direccion')) {
            $fields[] = 'direccion = ?';
            $params[] = $address;
        }
        if ($hasOrigen) {
            $fields[] = 'origen = ?';
            $params[] = 'WhatsApp Bot';
        }
        if ($hasCategoria && trim((string)($row['categoria'] ?? '')) === '') {
            $fields[] = 'categoria = ?';
            $params[] = 'Regular';
        }
        if ($hasNotas) {
            $fields[] = 'notas = ?';
            $params[] = bot_merge_text_block((string)($row['notas'] ?? ''), $notes);
        }
        if ($hasPreferencias) {
            $fields[] = 'preferencias = ?';
            $params[] = bot_merge_text_block((string)($row['preferencias'] ?? ''), $preferences);
        }
        $params[] = (int)$row['id'];
        $pdo->prepare("UPDATE clientes SET " . implode(',', $fields) . " WHERE id = ?")->execute($params);
        return;
    }
    $cols = ['nombre', 'telefono'];
    $vals = [$name, $phone];
    if (bot_has_column($pdo, 'clientes', 'direccion')) { $cols[] = 'direccion'; $vals[] = $address; }
    if ($hasOrigen) { $cols[] = 'origen'; $vals[] = 'WhatsApp Bot'; }
    if ($hasCategoria) { $cols[] = 'categoria'; $vals[] = 'Regular'; }
    if ($hasNotas) { $cols[] = 'notas'; $vals[] = $notes; }
    if ($hasPreferencias) { $cols[] = 'preferencias'; $vals[] = $preferences; }
    if ($hasFechaRegistro) { $cols[] = 'fecha_registro'; $vals[] = date('Y-m-d H:i:s'); }
    if (bot_has_column($pdo, 'clientes', 'activo')) { $cols[] = 'activo'; $vals[] = 1; }
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO clientes (" . implode(',', $cols) . ") VALUES ({$placeholders})")->execute($vals);
    push_notify(
        $pdo,
        'operador',
        '🤖 Nuevo cliente atendido por auto bot',
        "{$name} — {$phone}",
        '/marinero/pos_bot.php',
        'bot_new_client'
    );
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
    $deliveryFee = (($cart['fulfillment_mode'] ?? '') === 'delivery') ? (float)($cart['delivery_fee'] ?? 0) : 0.0;
    if ($deliveryFee > 0) $lines[] = "Mensajería: $" . number_format($deliveryFee, 2);
    $grandTotal = $tot + $deliveryFee;
    $lines[] = "TOTAL: $" . number_format($grandTotal,2);
    if (!empty($cart['customer_name'])) $lines[] = "Nombre: " . $cart['customer_name'];
    if (!empty($cart['customer_address'])) $lines[] = "Direccion: " . $cart['customer_address'];
    if (!empty($cart['fulfillment_mode'])) $lines[] = bot_delivery_summary_line($cart);
    if (!empty($cart['requested_datetime_label'])) $lines[] = (($cart['fulfillment_mode'] ?? '') === 'delivery' ? 'Entrega: ' : 'Recogida: ') . $cart['requested_datetime_label'];
    $lines[] = "Si lo ves bien, escribe CONFIRMAR y lo cierro por ti.";
    return ['text' => implode("\n", $lines), 'total' => $grandTotal, 'subtotal' => $tot, 'delivery_fee' => $deliveryFee];
}

function bot_find_cliente_by_phone(PDO $pdo, string $wa): ?array {
    if (!bot_table_exists($pdo, 'clientes') || !bot_has_column($pdo, 'clientes', 'telefono')) return null;
    $phone = substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50);
    if ($phone === '') return null;
    $st = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bot_upsert_cliente_for_reserva(PDO $pdo, string $wa, string $name, string $address, array $cart): int {
    bot_sync_crm_client($pdo, $wa, $name, $address, $cart, 'Reserva generada desde WhatsApp Bot.');
    $row = bot_find_cliente_by_phone($pdo, $wa);
    return (int)($row['id'] ?? 0);
}

function bot_create_reserva(PDO $pdo, array $appCfg, string $wa, string $name, string $address, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['ok'=>false, 'msg'=>'Carrito vacio'];
    $fulfillmentMode = (string)($cart['fulfillment_mode'] ?? '');
    if (!in_array($fulfillmentMode, ['pickup', 'delivery'], true)) {
        return ['ok' => false, 'msg' => 'Falta definir si es recogida o mensajería'];
    }
    if (trim((string)($cart['requested_datetime'] ?? '')) === '') {
        return ['ok' => false, 'msg' => 'Falta fecha y hora de entrega'];
    }
    if ($fulfillmentMode === 'delivery' && trim($address) === '') {
        return ['ok' => false, 'msg' => 'Falta dirección para la mensajería'];
    }

    $pdo->beginTransaction();
    try {
        $total = 0.0; $valid = [];
        $itemNotes = (array)($cart['item_notes'] ?? []);
        $deliveryFee = $fulfillmentMode === 'delivery' ? (float)($cart['delivery_fee'] ?? 0) : 0.0;
        $validation = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : [];
        $requestedAt = trim((string)($cart['requested_datetime'] ?? ''));
        $requestedLabel = trim((string)($cart['requested_datetime_label'] ?? ''));
        foreach ($items as $sku => $qty) {
            $st = $pdo->prepare("SELECT codigo,nombre,precio,categoria,COALESCE(es_servicio,0) AS es_servicio FROM productos WHERE codigo=? AND id_empresa=? LIMIT 1");
            $st->execute([$sku, (int)$appCfg['id_empresa']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;
            $q = max(1, (int)$qty);
            if (empty($p['es_servicio'])) {
                $stockSt = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                $stockSt->execute([$sku, (int)($appCfg['id_almacen'] ?? 1)]);
                $stock = (float)$stockSt->fetchColumn();
                if ($stock < $q) {
                    throw new Exception("Sin existencia suficiente para {$p['nombre']}.");
                }
            }
            $total += (float)$p['precio'] * $q;
            $valid[] = [
                'sku'=>$p['codigo'],
                'name'=>(string)$p['nombre'],
                'qty'=>$q,
                'price'=>(float)$p['precio'],
                'note'=>trim((string)($itemNotes[(string)$p['codigo']] ?? '')),
                'category'=>(string)($p['categoria'] ?? 'General')
            ];
        }
        if (!$valid) throw new Exception('Sin productos validos');
        $idCliente = bot_upsert_cliente_for_reserva($pdo, $wa, $name, $address, $cart);
        $totalFinal = $total + $deliveryFee;
        $addressForReserva = trim($address);
        $notes = [
            'Reserva creada desde WhatsApp Bot',
            'Tipo: ' . bot_fulfillment_label($fulfillmentMode),
            'Fecha solicitada: ' . ($requestedLabel !== '' ? $requestedLabel : $requestedAt),
            'Cliente WA: ' . $wa
        ];
        if ($fulfillmentMode === 'delivery') {
            $municipio = trim((string)($validation['municipio'] ?? ''));
            $barrio = trim((string)($validation['barrio'] ?? ''));
            $distanceKm = (float)($cart['delivery_distance_km'] ?? ($validation['distance_km'] ?? 0));
            $notes[] = 'Mensajería: ' . number_format($distanceKm, 2) . ' km x 100 CUP';
            if ($municipio !== '' || $barrio !== '') {
                $notes[] = 'Zona: ' . trim($municipio . ' / ' . $barrio, ' /');
                $addressForReserva = '[MENSAJERÍA: ' . trim($municipio . ' - ' . $barrio, ' -') . '] ' . $addressForReserva;
            }
        } else {
            $addressForReserva = trim($addressForReserva) !== '' ? '[RECOGER EN TIENDA] ' . $addressForReserva : 'RECOGER EN TIENDA';
        }

        $uuid = uniqid('R-WA-', true);
        $h = $pdo->prepare("INSERT INTO ventas_cabecera
            (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, tipo_servicio,
             fecha_reserva, notas, cliente_nombre, cliente_telefono, cliente_direccion,
             abono, estado_pago, id_empresa, estado_reserva, sincronizado, id_caja, id_cliente, canal_origen)
            VALUES (?, NOW(), ?, 'Pendiente', ?, ?, 'reserva', ?, ?, ?, ?, ?, 0, 'pendiente', ?, 'PENDIENTE', 0, 1, ?, 'WhatsApp')");
        $h->execute([
            $uuid,
            $totalFinal,
            (int)$appCfg['id_sucursal'],
            (int)$appCfg['id_almacen'],
            $requestedAt,
            implode("\n", $notes),
            $name ?: 'Cliente WhatsApp',
            substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50),
            mb_substr($addressForReserva, 0, 255),
            (int)$appCfg['id_empresa'],
            $idCliente
        ]);
        $idReserva = (int)$pdo->lastInsertId();

        $sinExistencia = 0;
        $d = $pdo->prepare("INSERT INTO ventas_detalle
            (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto, categoria_producto)
            VALUES (?,?,?,?,?,?,?)");
        foreach ($valid as $it) {
            $d->execute([$idReserva, $it['sku'], $it['qty'], $it['price'], $it['name'], $it['sku'], $it['category']]);
        }
        if ($sinExistencia) {
            $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia=1 WHERE id=?")->execute([$idReserva]);
        }

        $pdo->prepare("INSERT INTO pos_bot_orders (id_pedido,wa_user_id,total) VALUES (?,?,?)")->execute([$idReserva, $wa, $totalFinal]);
        $pdo->commit();
        return [
            'ok'=>true,
            'id_reserva'=>$idReserva,
            'id_pedido'=>$idReserva,
            'total'=>$totalFinal,
            'ticket_url'=>bot_ticket_url($idReserva),
            'order_snapshot'=>[
                'items' => $items,
                'item_notes' => $itemNotes,
                'customer_name' => $name,
                'customer_address' => $address,
                'fulfillment_mode' => $fulfillmentMode,
                'delivery_fee' => $deliveryFee,
                'delivery_distance_km' => (float)($cart['delivery_distance_km'] ?? 0),
                'address_validation' => $validation,
                'requested_datetime' => $requestedAt,
                'requested_datetime_label' => $requestedLabel,
                'created_at' => date('c'),
                'id_reserva' => $idReserva
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'msg'=>$e->getMessage()];
    }
}

function bot_build_conversation_rows(PDO $pdo): array {
    $rows = $pdo->query("SELECT wa_user_id, wa_name, cart_json, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
    $latestMsgSt = $pdo->prepare("SELECT direction, msg_type, message_text, created_at FROM pos_bot_messages WHERE wa_user_id=? ORDER BY id DESC LIMIT 1");
    $out = [];
    foreach ($rows as $row) {
        $cart = json_decode((string)($row['cart_json'] ?? ''), true);
        if (!is_array($cart)) $cart = [];
        $cart = bot_merge_cart_shape($cart, (string)($row['wa_name'] ?? ''));
        $latestMsgSt->execute([(string)$row['wa_user_id']]);
        $lastMsg = $latestMsgSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $itemsCount = 0;
        foreach ((array)$cart['items'] as $qty) $itemsCount += max(0, (int)$qty);
        $out[] = [
            'wa_user_id' => (string)$row['wa_user_id'],
            'wa_name' => (string)($cart['customer_name'] ?: ($row['wa_name'] ?? '')),
            'customer_address' => (string)($cart['customer_address'] ?? ''),
            'items_count' => $itemsCount,
            'awaiting_field' => (string)($cart['awaiting_field'] ?? ''),
            'bot_paused' => !empty($cart['bot_paused']) ? 1 : 0,
            'escalation_active' => !empty($cart['escalation_active']) ? 1 : 0,
            'escalation_reason' => (string)($cart['escalation_reason'] ?? ''),
            'escalation_label' => (string)($cart['escalation_label'] ?? ''),
            'escalation_at' => (string)($cart['escalation_at'] ?? ''),
            'last_seen' => (string)($row['last_seen'] ?? ''),
            'last_message_text' => (string)($lastMsg['message_text'] ?? ''),
            'last_message_dir' => (string)($lastMsg['direction'] ?? ''),
            'last_message_at' => (string)($lastMsg['created_at'] ?? ''),
            'last_cart_activity_at' => (string)($cart['last_cart_activity_at'] ?? ''),
            'last_cart_reminder_at' => (string)($cart['last_cart_reminder_at'] ?? ''),
            'last_order' => is_array($cart['last_order'] ?? null) ? $cart['last_order'] : null
        ];
    }
    return $out;
}

function bot_conversation_update(PDO $pdo, string $wa, callable $mutator): array {
    $st = $pdo->prepare("SELECT wa_name, cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $name = (string)($row['wa_name'] ?? '');
    $cart = is_array(json_decode((string)($row['cart_json'] ?? ''), true)) ? json_decode((string)($row['cart_json'] ?? ''), true) : [];
    $cart = bot_merge_cart_shape($cart, $name);
    $cart = $mutator($cart) ?: $cart;
    if ($row) {
        bot_save_cart($pdo, $wa, $name, $cart);
    } else {
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
    }
    return $cart;
}

function bot_enqueue_cart_recovery_jobs(PDO $pdo, array $cfg, array $appCfg): int {
    $rows = $pdo->query("SELECT wa_user_id, wa_name, cart_json, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $enqueued = 0;
    $now = time();
    foreach ($rows as $row) {
        $cart = json_decode((string)($row['cart_json'] ?? ''), true);
        if (!is_array($cart)) continue;
        $cart = bot_merge_cart_shape($cart, (string)($row['wa_name'] ?? ''));
        if (!empty($cart['bot_paused'])) continue;
        if (empty($cart['items'])) continue;
        $lastSeenTs = strtotime((string)($row['last_seen'] ?? '')) ?: 0;
        $lastActivityTs = strtotime((string)($cart['last_cart_activity_at'] ?? '')) ?: $lastSeenTs;
        if ($lastActivityTs <= 0 || ($now - $lastActivityTs) < 20 * 60) continue;
        $lastReminderTs = strtotime((string)($cart['last_cart_reminder_at'] ?? '')) ?: 0;
        if ($lastReminderTs > 0 && ($now - $lastReminderTs) < 6 * 3600) continue;
        $name = bot_customer_display_name($cart, (string)($row['wa_name'] ?? 'Cliente'));
        $text = bot_pick([
            "Hola {$name}, dejaste un pedido a medias. Si quieres, te ayudo a terminarlo ahora mismo.",
            "{$name}, tu carrito sigue guardado. Puedes retomarlo cuando quieras.",
            "Seguimos teniendo guardado tu carrito, {$name}. Si quieres, lo cerramos enseguida."
        ], $row['wa_user_id'] . date('YmdH'));
        $text .= "\n" . bot_site_promo($cfg, $appCfg, $row['wa_user_id'] . 'recovery');
        bot_enqueue_bridge_job([
            'target_id' => (string)$row['wa_user_id'],
            'type' => 'text',
            'text' => $text
        ]);
        bot_enqueue_bridge_job([
            'target_id' => (string)$row['wa_user_id'],
            'type' => 'buttons',
            'text' => 'Retoma tu compra con un toque:',
            'buttons' => ['Carrito', 'Confirmar', 'Comprar en web'],
            'title' => 'Carrito pendiente',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($appCfg))
        ]);
        $cart['last_cart_reminder_at'] = date('c', $now);
        bot_save_cart($pdo, (string)$row['wa_user_id'], (string)($row['wa_name'] ?? ''), $cart);
        $enqueued++;
    }
    return $enqueued;
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
    bot_mark_cart_activity($cart);
    $cart['pending_choice'] = null;
    return ['status' => 'resolved', 'added' => $added];
}

function bot_handle_text(PDO $pdo, array $cfg, array $appCfg, string $wa, string $name, string $text): void {
    $liveCfg = bot_cfg($pdo);
    $liveReplyState = bot_autoreply_state($liveCfg);
    if (empty($liveReplyState['effective_enabled'])) {
        return;
    }
    if (!empty($liveCfg)) {
        $cfg = array_merge($cfg, $liveCfg);
    }

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
    $isRepeat = bot_intent_has($norm, ['lo mismo','lo de siempre','repite el ultimo','repite mi ultimo','igual que la vez pasada','igual que siempre','repetir pedido']);
    $isWebBuyReq = bot_intent_has($norm, ['comprar en web','comprar web','tienda web','catalogo web','catálogo web','comprar online']);
    $looksLikeName = bot_intent_has($norm, ['mi nombre es','soy','me llamo','nombre']);
    $looksLikeAddress = bot_intent_has($norm, ['direccion','dirección','mi direccion es','vivo en','entrega en','dir']);
    $fulfillmentDetected = bot_detect_fulfillment_mode($norm);
    $scheduleDetected = bot_extract_schedule_datetime($msg);
    $escalationDetected = bot_detect_escalation($norm);

    if ($escalationDetected) {
        bot_mark_escalation($cart, $escalationDetected);
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, "Entendido. Ya pasé tu caso a atención humana por: {$escalationDetected['label']}.\nUn operador revisará tu chat lo antes posible.");
        return;
    }

    if (!empty($cart['bot_paused'])) {
        bot_save_cart($pdo, $wa, $name, $cart);
        return;
    }

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
            $cart['awaiting_field'] = 'fulfillment';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, $candidateName, (string)($cart['customer_address'] ?? ''), $cart, 'Nombre actualizado desde conversación.');
            bot_send($pdo, $cfg, $wa, "Gracias, {$candidateName}. Ahora voy a preparar tu reserva.");
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        bot_send($pdo, $cfg, $wa, 'Para continuar necesito tu nombre completo. Ejemplo: Mi nombre es Juan Perez.');
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'fulfillment' && !$isMenuReq && !$isCartReq && !$isCancel) {
        if ($fulfillmentDetected === '') {
            bot_send($pdo, $cfg, $wa, 'Necesito que me digas si prefieres recoger en tienda o mensajería.');
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        $cart['fulfillment_mode'] = $fulfillmentDetected;
        $cart['awaiting_field'] = '';
        if ($fulfillmentDetected === 'pickup') {
            $cart['address_validation'] = null;
            $cart['delivery_distance_km'] = 0.0;
            $cart['delivery_fee'] = 0.0;
            $cart['requested_datetime'] = '';
            $cart['requested_datetime_label'] = '';
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Perfecto, será para recoger en tienda.');
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt('pickup'));
            return;
        }
        if (trim((string)($cart['customer_address'] ?? '')) !== '') {
            $validation = bot_validate_habana_address((string)$cart['customer_address']);
            if (!empty($validation['ok'])) {
                $cart['address_validation'] = $validation;
                $cart['delivery_distance_km'] = (float)$validation['distance_km'];
                $cart['delivery_fee'] = (float)$validation['delivery_fee'];
                $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
                $cart['requested_datetime'] = '';
                $cart['requested_datetime_label'] = '';
                $cart['awaiting_field'] = 'datetime';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, "Perfecto, será por mensajería.\nDirección validada en {$validation['municipio']} / {$validation['barrio']}.\nDistancia estimada: " . number_format((float)$validation['distance_km'], 2) . " km.\nCosto de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . '.');
                bot_send($pdo, $cfg, $wa, bot_datetime_prompt('delivery'));
                return;
            }
        }
        $cart['awaiting_field'] = 'address';
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, 'Perfecto, será por mensajería.');
        bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'address' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            $validation = bot_validate_habana_address($candidateAddress);
            if (empty($validation['ok'])) {
                $cart['address_validation'] = null;
                $cart['delivery_distance_km'] = 0.0;
                $cart['delivery_fee'] = 0.0;
                $cart['awaiting_field'] = 'address';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, ($validation['msg'] ?? 'No pude validar esa dirección.') . "\n" . bot_delivery_address_prompt());
                return;
            }
            $cart['address_validation'] = $validation;
            $cart['delivery_distance_km'] = (float)$validation['distance_km'];
            $cart['delivery_fee'] = (float)$validation['delivery_fee'];
            $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), $candidateAddress, $cart, 'Dirección validada para reserva por WhatsApp.');
            bot_send($pdo, $cfg, $wa, "Perfecto. Validé tu dirección en {$validation['municipio']} / {$validation['barrio']}.\nDistancia estimada: " . number_format((float)$validation['distance_km'], 2) . " km.\nCosto de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . ".\n" . bot_datetime_prompt('delivery'));
            return;
        }
        bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'datetime' && !$isMenuReq && !$isCartReq && !$isCancel) {
        if (!$scheduleDetected) {
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt((string)($cart['fulfillment_mode'] ?? 'pickup')));
            return;
        }
        $cart['requested_datetime'] = (string)$scheduleDetected['value'];
        $cart['requested_datetime_label'] = (string)$scheduleDetected['label'];
        $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        $res = bot_create_reserva($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = bot_default_cart((string)$cart['customer_name']);
            $cartAfter['customer_address'] = (string)$cart['customer_address'];
            $cartAfter['fulfillment_mode'] = (string)($cart['fulfillment_mode'] ?? '');
            $cartAfter['address_validation'] = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : null;
            $cartAfter['delivery_fee'] = (float)($cart['delivery_fee'] ?? 0);
            $cartAfter['delivery_distance_km'] = (float)($cart['delivery_distance_km'] ?? 0);
            $cartAfter['requested_datetime'] = (string)$scheduleDetected['value'];
            $cartAfter['requested_datetime_label'] = (string)$scheduleDetected['label'];
            $cartAfter['greet_count'] = (int)($cart['greet_count'] ?? 0);
            $cartAfter['last_order'] = is_array($res['order_snapshot'] ?? null) ? $res['order_snapshot'] : null;
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            bot_sync_crm_client($pdo, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cartAfter, 'Reserva confirmada desde WhatsApp Bot.');
            bot_send($pdo, $cfg, $wa, "Perfecto, {$cart['customer_name']}. Ya quedó creada tu reserva #{$res['id_reserva']}.\nFecha y hora: {$scheduleDetected['label']}.\n" . bot_delivery_summary_line($cart) . "\nTotal: $" . number_format((float)$res['total'], 2) . "\nTicket: {$res['ticket_url']}\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'reserva'));
        } else {
            bot_send($pdo, $cfg, $wa, "No pude crear la reserva: {$res['msg']}");
        }
        return;
    }

    if ($looksLikeName) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            if (($cart['awaiting_field'] ?? '') === 'name') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, $candidateName, (string)($cart['customer_address'] ?? ''), $cart, 'Nombre confirmado desde conversación.');
            bot_send($pdo, $cfg, $wa, "Perfecto, {$candidateName}. Ya guardé tu nombre.");
            return;
        }
    }

    if ($looksLikeAddress) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            if (($cart['awaiting_field'] ?? '') === 'address') $cart['awaiting_field'] = '';
            if (($cart['fulfillment_mode'] ?? '') === 'delivery') {
                $validation = bot_validate_habana_address($candidateAddress);
                if (!empty($validation['ok'])) {
                    $cart['address_validation'] = $validation;
                    $cart['delivery_distance_km'] = (float)$validation['distance_km'];
                    $cart['delivery_fee'] = (float)$validation['delivery_fee'];
                    $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
                } else {
                    $cart['address_validation'] = null;
                    $cart['delivery_distance_km'] = 0.0;
                    $cart['delivery_fee'] = 0.0;
                }
            }
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), $candidateAddress, $cart, 'Dirección confirmada desde conversación.');
            if (($cart['fulfillment_mode'] ?? '') === 'delivery' && !empty($cart['address_validation']['ok'])) {
                $validation = $cart['address_validation'];
                bot_send($pdo, $cfg, $wa, "Direccion guardada y validada en {$validation['municipio']} / {$validation['barrio']}.\nCosto estimado de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . '.');
            } else {
                bot_send($pdo, $cfg, $wa, "Direccion guardada: {$candidateAddress}.");
            }
            return;
        }
    }

    if ($fulfillmentDetected !== '') {
        $cart['fulfillment_mode'] = $fulfillmentDetected;
        if ($fulfillmentDetected === 'pickup') {
            $cart['address_validation'] = null;
            $cart['delivery_distance_km'] = 0.0;
            $cart['delivery_fee'] = 0.0;
        }
        if (($cart['awaiting_field'] ?? '') === 'fulfillment') $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        if ($fulfillmentDetected === 'pickup') {
            bot_send($pdo, $cfg, $wa, 'Perfecto, te dejo el pedido como recogida en tienda.');
        } else {
            bot_send($pdo, $cfg, $wa, 'Perfecto, te lo dejo por mensajería. Cuando quieras, envíame la dirección completa en La Habana.');
        }
        return;
    }

    if ($scheduleDetected && !empty($cart['items'])) {
        $cart['requested_datetime'] = (string)$scheduleDetected['value'];
        $cart['requested_datetime_label'] = (string)$scheduleDetected['label'];
        if (($cart['awaiting_field'] ?? '') === 'datetime') $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, (($cart['fulfillment_mode'] ?? '') === 'delivery' ? 'Entrega' : 'Recogida') . " programada para {$scheduleDetected['label']}.");
        return;
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
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isMenuReq) {
        $menuVisible = array_values(array_filter($menu, static fn($p) => bot_product_in_stock($p, 1)));
        if (!$menuVisible) $menuVisible = $menu;
        $lines = [bot_greeting_text($cfg, $appCfg, $cart, $name), '', (string)($cfg['menu_intro'] ?? 'Menu:')];
        foreach (array_slice($menuVisible, 0, 40) as $p) {
            $suffix = !empty($p['es_servicio']) ? '' : (' · stock ' . max(0, (int)($p['stock_total'] ?? 0)));
            $lines[] = "{$p['codigo']} - {$p['nombre']} - $" . number_format((float)$p['precio'],2) . $suffix;
        }
        $lines[] = '';
        $lines[] = 'Puedes pedirme algo natural, por ejemplo: "quiero 2 croquetas y 1 refresco sin hielo".';
        $lines[] = 'Tambien puedo repetir tu ultimo pedido si me dices: "lo de siempre".';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'menu');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        bot_send_catalog_showcase($pdo, $wa, $appCfg, $menuVisible, 'Aquí tienes una vista rápida del catálogo.');
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'menu');
        return;
    }

    if ($isWebBuyReq) {
        bot_send($pdo, $cfg, $wa, "Compra directa: " . bot_site_url($appCfg) . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'webbuy'));
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isRepeat) {
        $repeated = bot_repeat_last_order($cart);
        if ($repeated) {
            bot_mark_cart_activity($cart);
            bot_save_cart($pdo, $wa, $name, $cart);
            $summary = bot_cart_text($pdo, $cart);
            bot_send($pdo, $cfg, $wa, "Te repetí el ultimo pedido que tenías guardado.\n\n" . $summary['text'] . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'repeat'));
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
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
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
            return;
        }
        $catalogBySku = [];
        foreach ($menu as $p) $catalogBySku[(string)$p['codigo']] = $p;
        $availableItems = [];
        $unavailable = [];
        foreach ($parsed['items'] as $it) {
            $sku = (string)($it['sku'] ?? '');
            $product = $catalogBySku[$sku] ?? null;
            if (!$product) continue;
            $qty = max(1, (int)($it['qty'] ?? 1));
            if (!bot_product_in_stock($product, $qty)) {
                $unavailable[] = ['product' => $product, 'qty' => $qty];
                continue;
            }
            $availableItems[] = $it;
        }
        $added = bot_add_items_to_cart($cart, $availableItems);
        if (!$added && $unavailable) {
            $lines = ['Ahora mismo no tengo existencia suficiente de ese producto.'];
            foreach ($unavailable as $row) {
                $product = $row['product'];
                $subs = bot_find_substitutes($menu, $product, 3);
                $lines[] = "- {$product['nombre']} no disponible.";
                if ($subs) {
                    $lines[] = 'Sustituciones: ' . implode(' | ', array_map(static fn($p) => "{$p['nombre']} ({$p['codigo']})", $subs));
                }
            }
            $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'stock');
            bot_send($pdo, $cfg, $wa, implode("\n", $lines));
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
            return;
        }
        if ($added) bot_mark_cart_activity($cart);
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), (string)($cart['customer_address'] ?? ''), $cart, 'Preferencias de compra actualizadas por actividad del carrito.');
        $lines = ['Perfecto, ya te lo agregué:'];
        foreach ($added as $it) {
            $line = "- {$it['name']} x{$it['qty']}";
            if ($it['note'] !== '') $line .= " [{$it['note']}]";
            $lines[] = $line;
        }
        if ($unavailable) {
            foreach ($unavailable as $row) {
                $subs = bot_find_substitutes($menu, $row['product'], 3);
                $lines[] = "{$row['product']['nombre']} no tenía stock suficiente.";
                if ($subs) $lines[] = 'Te puedo sugerir: ' . implode(' | ', array_map(static fn($p) => "{$p['nombre']} ({$p['codigo']})", $subs));
            }
        }
        $suggest = bot_suggest_item($menu, $cart);
        if ($suggest) $lines[] = "Sugerencia: ¿quieres añadir {$suggest['nombre']} por $" . number_format((float)$suggest['precio'],2) . '?';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'add');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isRemove || str_starts_with(mb_strtoupper($msg), 'QUITAR ')) {
        $sku = bot_parse_remove_item($menu, $msg);
        if (!$sku && preg_match('/^QUITAR\s+(\S+)/iu', $msg, $m)) $sku = (string)$m[1];
        if ($sku !== null && $sku !== '' && isset($cart['items'][$sku])) {
            unset($cart['items'][$sku], $cart['item_notes'][$sku]);
            bot_mark_cart_activity($cart);
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
        if (!empty($cart['items'])) bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
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
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
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
        if (!in_array((string)($cart['fulfillment_mode'] ?? ''), ['pickup', 'delivery'], true)) {
            $cart['awaiting_field'] = 'fulfillment';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        if (($cart['fulfillment_mode'] ?? '') === 'delivery' && trim((string)($cart['customer_address'] ?? '')) === '') {
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
            return;
        }
        if (($cart['fulfillment_mode'] ?? '') === 'delivery' && empty($cart['address_validation']['ok'])) {
            $validation = bot_validate_habana_address((string)$cart['customer_address']);
            if (empty($validation['ok'])) {
                $cart['awaiting_field'] = 'address';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, ($validation['msg'] ?? 'No pude validar la dirección.') . "\n" . bot_delivery_address_prompt());
                return;
            }
            $cart['address_validation'] = $validation;
            $cart['delivery_distance_km'] = (float)$validation['distance_km'];
            $cart['delivery_fee'] = (float)$validation['delivery_fee'];
            $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
            bot_save_cart($pdo, $wa, $name, $cart);
        }
        if (trim((string)($cart['requested_datetime'] ?? '')) === '') {
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt((string)($cart['fulfillment_mode'] ?? 'pickup')));
            return;
        }
        $summary = bot_cart_text($pdo, $cart);
        $res = bot_create_reserva($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = bot_default_cart((string)$cart['customer_name']);
            $cartAfter['customer_address'] = (string)$cart['customer_address'];
            $cartAfter['fulfillment_mode'] = (string)($cart['fulfillment_mode'] ?? '');
            $cartAfter['address_validation'] = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : null;
            $cartAfter['delivery_fee'] = (float)($cart['delivery_fee'] ?? 0);
            $cartAfter['delivery_distance_km'] = (float)($cart['delivery_distance_km'] ?? 0);
            $cartAfter['requested_datetime'] = (string)($cart['requested_datetime'] ?? '');
            $cartAfter['requested_datetime_label'] = (string)($cart['requested_datetime_label'] ?? '');
            $cartAfter['greet_count'] = (int)($cart['greet_count'] ?? 0);
            $cartAfter['last_order'] = is_array($res['order_snapshot'] ?? null) ? $res['order_snapshot'] : null;
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            bot_sync_crm_client($pdo, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cartAfter, 'Reserva confirmada desde WhatsApp Bot.');
            $summaryText = preg_replace('/\nSi lo ves bien, escribe CONFIRMAR y lo cierro por ti\.\s*$/u', '', $summary['text']) ?? $summary['text'];
            bot_send($pdo, $cfg, $wa, "Perfecto, {$cart['customer_name']}. Ya quedó creada tu reserva #{$res['id_reserva']} por $" . number_format((float)$res['total'],2) . ".\n\nResumen final:\n" . $summaryText . "\n\nTicket: {$res['ticket_url']}\n" . bot_site_promo($cfg, $appCfg, $wa . 'confirm'));
        } else {
            bot_send($pdo, $cfg, $wa, "No pude crear la reserva: {$res['msg']}");
        }
        return;
    }

    bot_send(
        $pdo,
        $cfg,
        $wa,
        "No te entendí del todo, pero te ayudo.\nPuedes escribirme cosas como:\n- quiero 2 croquetas y 1 refresco sin hielo\n- quita la pizza\n- carrito\n- confirmar\n- lo de siempre\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'fallback')
    );
    bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
}

bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'conversation_list','conversation_pause','conversation_resume','conversation_send_manual',
    'promo_chats','promo_products','promo_my_group_payload','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete','promo_clone',
    'promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'promo_group_lists','promo_group_list_save','promo_group_list_delete',
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

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='bridge_scan_jobs') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $provided = (string)($in['verify_token'] ?? '');
    $expected = (string)($cfg['verify_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','msg'=>'invalid token']); exit;
    }
    $count = bot_enqueue_cart_recovery_jobs($pdo, $cfg, $config);
    echo json_encode(['status' => 'success', 'enqueued' => $count]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='get_config') {
    $safe = $cfg;
    $safe['wa_mode'] = in_array((string)($safe['wa_mode'] ?? ''), ['web','meta_api'], true) ? $safe['wa_mode'] : 'web';
    $safe['bot_tone'] = in_array((string)($safe['bot_tone'] ?? ''), ['premium','popular_cubano','formal_comercial','muy_cercano'], true) ? $safe['bot_tone'] : 'muy_cercano';
    $safe['auto_schedule_enabled'] = !empty($safe['auto_schedule_enabled']) ? 1 : 0;
    $safe['auto_off_start'] = bot_valid_time_hhmm((string)($safe['auto_off_start'] ?? '07:00'), '07:00');
    $safe['auto_off_end'] = bot_valid_time_hhmm((string)($safe['auto_off_end'] ?? '20:00'), '20:00');
    $safe['auto_reply_state'] = bot_autoreply_state($safe);
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
    $autoScheduleEnabled = !empty($in['auto_schedule_enabled']) ? 1 : 0;
    $autoOffStart = bot_valid_time_hhmm((string)($in['auto_off_start'] ?? $cfg['auto_off_start'] ?? '07:00'), '07:00');
    $autoOffEnd = bot_valid_time_hhmm((string)($in['auto_off_end'] ?? $cfg['auto_off_end'] ?? '20:00'), '20:00');
    $st = $pdo->prepare("UPDATE pos_bot_config SET enabled=?,auto_schedule_enabled=?,auto_off_start=?,auto_off_end=?,wa_mode=?,bot_tone=?,verify_token=?,wa_phone_number_id=?,wa_access_token=?,business_name=?,welcome_message=?,menu_intro=?,no_match_message=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        $autoScheduleEnabled,
        $autoOffStart,
        $autoOffEnd,
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

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='conversation_list') {
    echo json_encode(['status' => 'success', 'rows' => bot_build_conversation_rows($pdo)]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_pause') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 1;
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_resume') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 0;
        $cart['escalation_active'] = 0;
        $cart['escalation_reason'] = '';
        $cart['escalation_label'] = '';
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_send_manual') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    $sendQuick = !empty($in['send_quick_actions']);
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    if ($text === '' && !$sendQuick) { echo json_encode(['status'=>'error','msg'=>'Mensaje vacío']); exit; }
    if ($text !== '' && !bot_enqueue_bridge_job([
        'target_id' => $wa,
        'type' => 'text',
        'text' => $text
    ])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo encolar mensaje']); exit;
    }
    if ($text !== '') bot_log($pdo, $wa, 'out', $text, 'manual');
    if ($sendQuick) {
        bot_enqueue_bridge_job([
            'target_id' => $wa,
            'type' => 'buttons',
            'text' => 'Accesos rápidos:',
            'buttons' => ['Menu', 'Repetir pedido', 'Carrito'],
            'title' => 'Atajos',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($config))
        ]);
        bot_enqueue_bridge_job([
            'target_id' => $wa,
            'type' => 'buttons',
            'text' => 'Más opciones:',
            'buttons' => ['Confirmar', 'Comprar en web'],
            'title' => 'Atajos',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($config))
        ]);
    }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 1;
        $cart['last_manual_reply_at'] = date('c');
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_messages') {
    $rows = $pdo->query("SELECT m.wa_user_id,m.direction,m.msg_type,m.message_text,m.created_at,COALESCE(s.wa_name,'') AS wa_name
        FROM pos_bot_messages m
        LEFT JOIN pos_bot_sessions s ON s.wa_user_id = m.wa_user_id
        ORDER BY m.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_orders') {
    $rows = $pdo->query("SELECT bo.id,bo.id_pedido,bo.wa_user_id,bo.total,bo.created_at,
            COALESCE(vc.cliente_nombre, pc.cliente_nombre, '') AS cliente_nombre
        FROM pos_bot_orders bo
        LEFT JOIN pedidos_cabecera pc ON pc.id=bo.id_pedido
        LEFT JOIN ventas_cabecera vc ON vc.id=bo.id_pedido AND vc.tipo_servicio='reserva'
        ORDER BY bo.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
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
    $detail = '';
    if (!bot_run_bridge_service_command('restart', $detail)) {
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo reiniciar el bridge desde PHP. Revisa permisos de systemctl/sudo.',
            'detail' => $detail
        ]);
        exit;
    }

    $active = '';
    $activeCmds = [
        '/usr/bin/systemctl is-active palweb-wa-bridge.service 2>&1',
        '/usr/bin/sudo -n /usr/bin/systemctl is-active palweb-wa-bridge.service 2>&1'
    ];
    foreach ($activeCmds as $cmd) {
        $activeOut = [];
        $activeCode = 1;
        @exec($cmd, $activeOut, $activeCode);
        $active = trim(bot_sanitize_shell_output(implode("\n", $activeOut)));
        if ($activeCode === 0 && $active !== '') {
            break;
        }
    }
    echo json_encode([
        'status' => 'success',
        'msg' => 'Bridge reiniciado. Estado: ' . ($active !== '' ? $active : 'desconocido')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='bridge_reset_session') {
    $detail = '';
    if (!bot_run_bridge_service_command('stop', $detail)) {
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo detener el bridge antes de cerrar la sesión.',
            'detail' => $detail
        ]);
        exit;
    }

    $sessionDir = __DIR__ . '/wa_web_bridge/.wwebjs_auth/session-palweb-pos-bot';
    $cacheDir = __DIR__ . '/wa_web_bridge/.wwebjs_cache';
    $statusFile = __DIR__ . '/wa_web_bridge/status.json';
    $removed = [];
    $errors = [];

    $deleteTree = static function (string $path) use (&$deleteTree, &$errors): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) $errors[] = 'No se pudo borrar ' . $path;
            return;
        }
        $items = @scandir($path);
        if (!is_array($items)) {
            $errors[] = 'No se pudo leer ' . $path;
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $deleteTree($path . DIRECTORY_SEPARATOR . $item);
        }
        if (!@rmdir($path)) $errors[] = 'No se pudo borrar ' . $path;
    };

    if (is_dir($sessionDir)) {
        $deleteTree($sessionDir);
        if (!file_exists($sessionDir)) $removed[] = basename($sessionDir);
    } else {
        $removed[] = basename($sessionDir) . ' (no existía)';
    }
    if (is_dir($cacheDir)) {
        $deleteTree($cacheDir);
        if (!file_exists($cacheDir)) $removed[] = basename($cacheDir);
    }
    if (is_file($statusFile) && @unlink($statusFile)) {
        $removed[] = basename($statusFile);
    }

    $startDetail = '';
    if (!bot_run_bridge_service_command('start', $startDetail)) {
        echo json_encode([
            'status' => 'error',
            'msg' => 'La sesión fue cerrada, pero no se pudo arrancar el bridge.',
            'detail' => trim(implode(' | ', array_filter([$startDetail, implode('; ', $errors)])))
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'msg' => 'Sesión cerrada. El bridge reinició limpio y debe generar un QR nuevo.',
        'detail' => trim(implode(' | ', array_filter([
            $removed ? ('Borrado: ' . implode(', ', $removed)) : '',
            $errors ? ('Avisos: ' . implode('; ', $errors)) : ''
        ])))
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='bridge_logs') {
    $cmds = [
        '/usr/bin/journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1',
        '/usr/bin/sudo -n /usr/bin/journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1'
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
    $q = trim((string)($_GET['q'] ?? ''));
    $search = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $data = bot_read_json_file($BOT_BRIDGE_CHATS_FILE, ['updated_at' => null, 'rows' => []]);
    $rows = [];
    foreach (($data['rows'] ?? []) as $r) {
        $id = substr(trim((string)($r['id'] ?? '')), 0, 120);
        if ($id === '') continue;
        $name = substr(trim((string)($r['name'] ?? $id)), 0, 200);
        if ($search !== '') {
            $haystackId = function_exists('mb_strtolower') ? mb_strtolower($id, 'UTF-8') : strtolower($id);
            $haystackName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (strpos($haystackId, $search) === false && strpos($haystackName, $search) === false) {
                continue;
            }
        }
        $rows[] = [
            'id' => $id,
            'name' => $name,
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
    $sql = "SELECT codigo,nombre,precio,COALESCE(precio_mayorista,0) AS precio_mayorista
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
            'retail_price' => (float)$r['precio'],
            'wholesale_price' => (float)($r['precio_mayorista'] ?? 0),
            'price_mode' => 'retail',
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

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_group_lists') {
    $data = bot_read_json_file($BOT_PROMO_GROUP_LISTS_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    foreach ($rows as &$row) {
        $row['id'] = substr(trim((string)($row['id'] ?? '')), 0, 80);
        $name = substr(trim((string)($row['name'] ?? '')), 0, 120);
        $row['name'] = $name !== '' ? $name : 'Lista sin nombre';
        $targets = is_array($row['targets'] ?? null) ? $row['targets'] : [];
        $row['targets'] = array_values(array_filter(array_map(static function($t){
            $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
            if ($id === '') return null;
            $name = substr(trim((string)($t['name'] ?? $id)), 0, 200);
            return ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }, $targets), static fn($x) => is_array($x) && (trim((string)($x['id'] ?? '')) !== '')));
    }
    unset($row);
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_group_list_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $listId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $targetsInput = is_array($in['targets'] ?? null) ? $in['targets'] : [];
    if ($name === '') {
        echo json_encode(['status' => 'error', 'msg' => 'Nombre de lista obligatorio']);
        exit;
    }
    if (count($targetsInput) === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'La lista no tiene destinos']);
        exit;
    }

    $targets = [];
    $seen = [];
    foreach ($targetsInput as $t) {
        $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
        if ($id === '' || !empty($seen[$id])) continue;
        $seen[$id] = true;
        $targets[] = [
            'id' => $id,
            'name' => substr(trim((string)($t['name'] ?? $id)), 0, 200)
        ];
    }
    if (count($targets) === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'La lista no tiene destinos']);
        exit;
    }

    $data = bot_read_json_file($BOT_PROMO_GROUP_LISTS_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $now = date('c');
    $updated = false;
    if ($listId !== '') {
        foreach ($rows as &$row) {
            if ((string)($row['id'] ?? '') !== $listId) continue;
            $row['name'] = $name;
            $row['targets'] = $targets;
            $row['updated_at'] = $now;
            $updated = true;
            break;
        }
        unset($row);
    }
    if (!$updated) {
        $listId = 'list_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $rows[] = [
            'id' => $listId,
            'name' => $name,
            'targets' => $targets,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $_SESSION['admin_name'] ?? 'admin'
        ];
    }

    if (!bot_write_json_file($BOT_PROMO_GROUP_LISTS_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar la lista']);
        exit;
    }
    echo json_encode(['status' => 'success', 'id' => $listId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_group_list_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $listId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($listId === '') {
        echo json_encode(['status' => 'error', 'msg' => 'id requerido']);
        exit;
    }
    $data = bot_read_json_file($BOT_PROMO_GROUP_LISTS_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $filtered = array_values(array_filter($rows, static fn($r) => (string)($r['id'] ?? '') !== $listId));
    if (count($filtered) === count($rows)) {
        echo json_encode(['status' => 'error', 'msg' => 'Lista no encontrada']);
        exit;
    }
    if (!bot_write_json_file($BOT_PROMO_GROUP_LISTS_FILE, ['rows' => $filtered])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo eliminar la lista']);
        exit;
    }
    echo json_encode(['status' => 'success']); exit;
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
            'retail_price' => (float)($p['retail_price'] ?? ($p['price'] ?? 0)),
            'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
            'price_mode' => in_array((string)($p['price_mode'] ?? 'retail'), ['retail','wholesale'], true) ? (string)$p['price_mode'] : 'retail',
            'image' => trim((string)($p['image'] ?? ''))
        ];
    }
    $cleanBannerImages = bot_normalize_banner_images($bannerImages);

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
    $beforeTpl = count((array)($data['rows'] ?? []));
    $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn($r) => (string)($r['id'] ?? '') !== $templateId));
    if ($beforeTpl === count($rows)) { echo json_encode(['status' => 'error', 'msg' => 'Plantilla no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo borrar plantilla']); exit;
    }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $beforeJobs = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['template_id'] ?? '') !== $templateId));
    $deletedCampaigns = $beforeJobs - count($jobs);
    if ($deletedCampaigns > 0) {
        if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
            echo json_encode(['status' => 'error', 'msg' => 'La plantilla se borró, pero no se pudieron eliminar las campañas enlazadas']); exit;
        }
    }
    echo json_encode(['status' => 'success', 'deleted_campaigns' => $deletedCampaigns]); exit;
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

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_clone') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $source = null;
    foreach ($jobs as $job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $source = $job;
        break;
    }
    if (!$source) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    $clone = bot_clone_promo_job($source);
    $jobs[] = $clone;
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo clonar campaña']); exit;
    }
    echo json_encode(['status'=>'success','id'=>$clone['id'],'name'=>$clone['name']]); exit;
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
            'retail_price' => (float)($p['retail_price'] ?? ($p['price'] ?? 0)),
            'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
            'price_mode' => in_array((string)($p['price_mode'] ?? 'retail'), ['retail','wholesale'], true) ? (string)$p['price_mode'] : 'retail',
            'image' => trim((string)($p['image'] ?? ('image.php?code=' . rawurlencode($pid))))
        ];
    }
    $cleanBannerImages = bot_normalize_banner_images($bannerImages);

    if ($text === '' && $outroText === '' && count($cleanProducts) === 0 && count($cleanBannerImages) === 0) { echo json_encode(['status'=>'error','msg'=>'La campaña está vacía']); exit; }
    if (count($targetsFinal) === 0) { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un grupo/chat']); exit; }

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
    push_notify(
        $pdo,
        'operador',
        '📣 Campaña programada',
        ($campaignName !== '' ? $campaignName : $jobId) . ' — ' . count($targetsFinal) . ' destino(s)',
        '/marinero/pos_bot.php',
        'bot_campaign_created'
    );
    echo json_encode(['status'=>'success','id'=>$jobId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='test_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>$replyState['reason'],'responses'=>[]]); exit;
    }
    $wa = preg_replace('/\D+/', '', (string)($in['wa_user_id'] ?? '5350000000'));
    $name = trim((string)($in['wa_name'] ?? 'Cliente Test'));
    $text = trim((string)($in['text'] ?? 'MENU'));
    bot_log($pdo, $wa, 'in', $text);
    bot_begin_autoreply_request($pdo, $wa);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    bot_end_autoreply_request($wa);
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
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>$replyState['reason'],'responses'=>[]]); exit;
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
    bot_begin_autoreply_request($pdo, $wa);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    bot_end_autoreply_request($wa);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) { echo json_encode(['status'=>'ok','msg'=>$replyState['reason']]); exit; }
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
                bot_begin_autoreply_request($pdo, $from);
                bot_handle_text($pdo, $cfg, $config, $from, $name, $text);
                bot_end_autoreply_request($from);
            }
        }
    }
    echo json_encode(['status'=>'ok']); exit;
}

echo json_encode(['status'=>'error','msg'=>'invalid request']);
