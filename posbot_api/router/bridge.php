<?php
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

