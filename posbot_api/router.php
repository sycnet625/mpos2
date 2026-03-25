<?php
bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'conversation_list','conversation_pause','conversation_resume','conversation_send_manual',
    'promo_chats','promo_products','promo_my_group_payload','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete','promo_clone',
    'promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'promo_group_lists','promo_group_list_save','promo_group_list_delete',
    'bridge_restart','bridge_logs','clear_message_logs'
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
    if (!bot_verify_token_matches($cfg, $provided)) {
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

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='clear_message_logs') {
    $deleted = (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_messages")->fetchColumn();
    $pdo->exec("TRUNCATE TABLE pos_bot_messages");
    echo json_encode(['status'=>'success','deleted'=>$deleted]); exit;
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
    if (!bot_queue_bridge_control(['action' => 'restart'], $detail) && !bot_run_bridge_service_command('restart', $detail)) {
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo reiniciar el bridge desde PHP ni enviar la orden interna al bridge.',
            'detail' => $detail
        ]);
        exit;
    }

    $active = '';
    $activeCmds = [];
    foreach (bot_bridge_service_names() as $serviceName) {
        $activeCmds[] = "/usr/bin/systemctl is-active {$serviceName} 2>&1";
        $activeCmds[] = "/usr/bin/sudo -n /usr/bin/systemctl is-active {$serviceName} 2>&1";
    }
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
    if (bot_queue_bridge_control(['action' => 'reset_session'], $detail)) {
        echo json_encode([
            'status' => 'success',
            'msg' => 'Orden enviada. El bridge cerrará la sesión actual y generará un QR nuevo.',
            'detail' => $detail
        ]);
        exit;
    }

    if (!bot_run_bridge_service_command('stop', $detail)) {
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo detener el bridge ni enviar la orden interna para cerrar la sesión.',
            'detail' => $detail
        ]);
        exit;
    }

    $instanceDir = bot_bridge_instance_dir();
    $sessionDir = $instanceDir . '/.wwebjs_auth/session-' . bot_bridge_session_name();
    $cacheDir = $instanceDir . '/.wwebjs_cache';
    $statusFile = $instanceDir . '/status.json';
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
    $logText = '';
    $runtimeLogFile = dirname($BOT_BRIDGE_STATUS_FILE) . '/runtime/bridge.log';
    if (is_file($runtimeLogFile) && is_readable($runtimeLogFile)) {
        $raw = @file($runtimeLogFile, FILE_IGNORE_NEW_LINES);
        if (is_array($raw) && $raw) {
            $tail = array_slice($raw, -120);
            $logText = bot_sanitize_shell_output(implode("\n", $tail));
        }
    }
    if ($logText === '') {
        $cmds = [];
        foreach (bot_bridge_service_names() as $serviceName) {
            $cmds[] = "/usr/bin/journalctl -q -u {$serviceName} -n 120 --no-pager 2>&1";
            $cmds[] = "/usr/bin/sudo -n /usr/bin/journalctl -q -u {$serviceName} -n 120 --no-pager 2>&1";
        }
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
    }
    if ($logText === '') {
        $logText = 'Sin logs disponibles.';
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
    if (empty($data['rows']) || !is_array($data['rows'])) {
        $fallbackRows = $pdo->query("SELECT wa_user_id, wa_name, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
        $data = [
            'updated_at' => date('c'),
            'rows' => array_map(static function(array $row): array {
                $id = substr(trim((string)($row['wa_user_id'] ?? '')), 0, 120);
                return [
                    'id' => $id,
                    'name' => substr(trim((string)($row['wa_name'] ?? $id)), 0, 200),
                    'is_group' => 0,
                    'is_contact' => 1,
                ];
            }, $fallbackRows ?: [])
        ];
    }
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
    $hasWholesale = bot_has_column($pdo, 'productos', 'precio_mayorista');
    $idEmpresa = (int)($config['id_empresa'] ?? 0);
    $sql = "SELECT codigo,nombre,precio," . ($hasWholesale ? "COALESCE(precio_mayorista,0)" : "0") . " AS precio_mayorista
            FROM productos
            WHERE activo=1";
    $params = [];
    if ($idEmpresa > 0) {
        $sql .= " AND id_empresa=?";
        $params[] = $idEmpresa;
    }
    $sql .= " AND (nombre LIKE ? OR codigo LIKE ?)
            ORDER BY nombre ASC
            LIMIT {$lim}";
    $st = $pdo->prepare($sql);
    $params[] = $like;
    $params[] = $like;
    $st->execute($params);
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
    $overwriteExisting = !empty($in['overwrite_existing']);

    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $nowIso = date('c');
    $nameNorm = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $conflictId = '';
    foreach ($rows as $row) {
        $rowName = trim((string)($row['name'] ?? ''));
        $rowNorm = function_exists('mb_strtolower') ? mb_strtolower($rowName, 'UTF-8') : strtolower($rowName);
        $rowId = (string)($row['id'] ?? '');
        if ($rowNorm === $nameNorm && $rowId !== $templateId) {
            $conflictId = $rowId;
            break;
        }
    }
    if ($conflictId !== '') {
        if (!$overwriteExisting) {
            echo json_encode(['status' => 'error', 'msg' => 'Ya existe una plantilla con ese nombre', 'code' => 'duplicate_name', 'duplicate_id' => $conflictId]); exit;
        }
        $templateId = $conflictId;
    }
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
    $dir = POSBOT_API_ROOT . '/uploads/promo_campaigns';
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
    if (!bot_verify_token_matches($cfg, $provided)) {
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
