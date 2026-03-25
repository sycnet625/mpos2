<?php
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
    $queue = bot_repo_read('bridge_outbox_file', ['jobs' => []]);
    $queue['jobs'] = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $job['id'] = trim((string)($job['id'] ?? ('out_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)))));
    $job['created_at'] = $job['created_at'] ?? date('c');
    $job['status'] = $job['status'] ?? 'queued';
    $queue['jobs'][] = $job;
    return bot_repo_write('bridge_outbox_file', $queue);
}

function bot_public_base_url(array $appCfg = []): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    $hostCheck = preg_replace('/:\d+$/', '', $host ?? '');
    if ($hostCheck !== '' && !in_array($hostCheck, ['127.0.0.1', 'localhost'], true)) {
        return $scheme . '://' . $host;
    }
    if ($appCfg === []) {
        global $config;
        $appCfg = is_array($config ?? null) ? $config : [];
    }
    $website = trim((string)($appCfg['website'] ?? ''));
    if ($website !== '') {
        if (!preg_match('~^https?://~i', $website)) $website = 'https://' . ltrim($website, '/');
        $parts = parse_url($website);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) $origin .= ':' . (int)$parts['port'];
            return $origin;
        }
    }
    return 'https://www.palweb.net';
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
    $outroLines[] = 'En ' . preg_replace('#^https?://#i', '', rtrim(bot_public_base_url($config), '/')) . ' se pueden comprar automaticamente.';
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
    $commands = [];
    foreach (bot_bridge_service_names() as $serviceName) {
        $commands[] = "/usr/bin/systemctl {$verb} {$serviceName} 2>&1";
        $commands[] = "/usr/bin/sudo -n /usr/bin/systemctl {$verb} {$serviceName} 2>&1";
    }
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

function bot_queue_bridge_control(array $command, ?string &$detail = null): bool {
    $controlFile = bot_repo_file('bridge_control_file');
    if ($controlFile === '') {
        $detail = 'No se encontró el archivo de control del bridge.';
        return false;
    }
    $command['requested_at'] = date('c');
    $command['request_id'] = substr(bin2hex(random_bytes(8)), 0, 16);
    $tmpFile = $controlFile . '.tmp';
    $json = json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $detail = 'No se pudo serializar la orden al bridge.';
        return false;
    }
    if (@file_put_contents($tmpFile, $json) === false) {
        $detail = 'No se pudo escribir el archivo temporal de control del bridge.';
        return false;
    }
    if (!@rename($tmpFile, $controlFile)) {
        @unlink($tmpFile);
        $detail = 'No se pudo publicar la orden de control del bridge.';
        return false;
    }
    $detail = 'Orden enviada al bridge.';
    return true;
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
    bot_context_set('autoreply_request', true);
    if ($wa !== '' && !bot_session_exists($pdo, $wa)) {
        $pending = bot_context_get('new_client_notify', []);
        if (!is_array($pending)) $pending = [];
        $pending[$wa] = true;
        bot_context_set('new_client_notify', $pending);
    }
}

function bot_end_autoreply_request(string $wa = ''): void {
    bot_context_set('autoreply_request', false);
    if ($wa !== '') {
        $pending = bot_context_get('new_client_notify', []);
        if (!is_array($pending)) $pending = [];
        unset($pending[$wa]);
        bot_context_set('new_client_notify', $pending);
    }
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
    if ((bool)bot_context_get('autoreply_request', false)) {
        $liveCfg = bot_cfg($pdo);
        $liveReplyState = bot_autoreply_state($liveCfg);
        if (empty($liveReplyState['effective_enabled'])) {
            return;
        }
    }
    $row = ['wa_user_id' => $wa, 'type' => $type] + $payload;
    $outbox = bot_context_get('outbox', []);
    if (!is_array($outbox)) $outbox = [];
    $outbox[] = $row;
    bot_context_set('outbox', $outbox);
    $logText = trim((string)($payload['text'] ?? $payload['caption'] ?? $payload['url'] ?? ''));
    if ($logText !== '') bot_log($pdo, $wa, 'out', $logText, $type);
    $pending = bot_context_get('new_client_notify', []);
    if (!is_array($pending)) $pending = [];
    if (!empty($pending[$wa])) {
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
        unset($pending[$wa]);
        bot_context_set('new_client_notify', $pending);
    }
}

function bot_send(PDO $pdo, array $cfg, string $wa, string $text): void {
    bot_queue_response($pdo, $wa, 'text', ['text' => $text]);
    // Modo local (si no hay token/phoneId). Integración Meta opcional.
}

function bot_take_outbox(): array {
    $out = bot_context_get('outbox', []);
    bot_context_set('outbox', []);
    return is_array($out) ? $out : [];
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
