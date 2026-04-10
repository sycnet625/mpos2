<?php
// ARCHIVO: palweb_ai_api.php
// DESCRIPCIÓN: Backend para el módulo de IA Interactivo (Direct TMUX Mode)
// VERSIÓN: 3.1 - Soporte para Proyectos y Directorios de Trabajo

ini_set('display_errors', 0);
session_start();
require_once 'db.php';

header('Content-Type: application/json');

$GLOBALS['AI_API_RESPONSE_SENT'] = false;

function ai_json_response($payload, $statusCode = 200) {
    if (!headers_sent()) {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json');
    }
    echo json_encode($payload);
    $GLOBALS['AI_API_RESPONSE_SENT'] = true;
}

set_exception_handler(function ($e) {
    $msg = 'Error interno';
    if (is_object($e) && method_exists($e, 'getMessage')) {
        $msg = $e->getMessage();
    }
    ai_log('UNCAUGHT: ' . $msg);
    if (!$GLOBALS['AI_API_RESPONSE_SENT']) {
        ai_json_response(['status' => 'error', 'msg' => $msg], 500);
    }
});

register_shutdown_function(function () {
    if ($GLOBALS['AI_API_RESPONSE_SENT']) {
        return;
    }
    $fatal = error_get_last();
    if (!$fatal) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($fatal['type'] ?? 0, $fatalTypes, true)) {
        return;
    }
    $msg = ($fatal['message'] ?? 'Fatal error') . ' @ ' . ($fatal['file'] ?? 'unknown') . ':' . ($fatal['line'] ?? 0);
    ai_log('FATAL: ' . $msg);
    ai_json_response(['status' => 'error', 'msg' => 'Error fatal en API'], 500);
});

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ai_json_response(['status' => 'error', 'msg' => 'Sesión no válida.'], 401);
    exit;
}

$action = $_GET['action'] ?? '';

// Función de Logging
function ai_log($msg) {
    $logFile = 'palweb_ai_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

// Socket persistente para tmux/daemon
$socket_path = "/tmp/palweb_ai_tmux.sock";
$socket_cmd = "-S $socket_path";
$daemon_socket_path = '/tmp/palweb_ai_tmux_daemon.sock';

function ai_runtime_base() {
    $dir = __DIR__ . '/.palweb_ai_runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function ai_runtime_path($suffix = '') {
    $base = ai_runtime_base();
    $path = $suffix ? ($base . '/' . ltrim($suffix, '/')) : $base;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    return $path;
}

function ai_agent_env_prefix() {
    $home = ai_runtime_path('home');
    $config = ai_runtime_path('home/.config');
    $state = ai_runtime_path('home/.local/state');
    $data = ai_runtime_path('home/.local/share');
    $cache = ai_runtime_path('home/.cache');
    $path = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin';

    return "env HOME=" . escapeshellarg($home)
        . " USER=palweb_ai LOGNAME=palweb_ai SHELL=/bin/bash PATH=" . escapeshellarg($path)
        . " XDG_CONFIG_HOME=" . escapeshellarg($config)
        . " XDG_STATE_HOME=" . escapeshellarg($state)
        . " XDG_DATA_HOME=" . escapeshellarg($data)
        . " XDG_CACHE_HOME=" . escapeshellarg($cache);
}

function ai_exec_local($command) {
    return shell_exec(ai_agent_env_prefix() . " /bin/bash --noprofile --norc -c " . escapeshellarg($command) . " 2>&1");
}

function ai_exec_local_tmux($command) {
    global $socket_cmd;
    return ai_exec_local("/usr/bin/tmux $socket_cmd $command");
}

function ai_daemon_available() {
    $list = daemon_call('list');
    return (($list['status'] ?? 'error') === 'success');
}

function daemon_call($action, $data = []) {
    global $daemon_socket_path;

    $client = @socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$client) {
        return ['status' => 'error', 'msg' => 'No se pudo crear socket hacia daemon'];
    }

    if (!@socket_connect($client, $daemon_socket_path)) {
        socket_close($client);
        return ['status' => 'error', 'msg' => 'Daemon tmux no conectado'];
    }

    $data['action'] = $action;
    socket_write($client, json_encode($data) . "\n");
    socket_set_nonblock($client);
    usleep(250000);
    $response = socket_read($client, 65535);
    socket_close($client);

    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : ['status' => 'error', 'msg' => 'Respuesta inválida del daemon', 'raw' => $response];
}

function ai_state_dir() {
    $dir = '/tmp/palweb_ai_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function ai_state_file($chat_id, $agente) {
    $safeAgent = preg_replace('/[^a-z0-9_-]/i', '_', (string)$agente);
    return ai_state_dir() . "/chat_{$chat_id}_{$safeAgent}.json";
}

function ai_load_state($chat_id, $agente) {
    $file = ai_state_file($chat_id, $agente);
    if (!is_file($file)) {
        return [
            'last_capture' => '',
            'pending_assistant_message_id' => 0
        ];
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        return [
            'last_capture' => '',
            'pending_assistant_message_id' => 0
        ];
    }
    return array_merge([
        'last_capture' => '',
        'pending_assistant_message_id' => 0
    ], $data);
}

function ai_save_state($chat_id, $agente, array $state) {
    file_put_contents(ai_state_file($chat_id, $agente), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ai_reset_state($chat_id, $agente, $lastCapture = '') {
    ai_save_state($chat_id, $agente, [
        'last_capture' => (string)$lastCapture,
        'pending_assistant_message_id' => 0
    ]);
}

function ai_delete_state($chat_id, $agente) {
    $file = ai_state_file($chat_id, $agente);
    if (is_file($file)) {
        @unlink($file);
    }
}

function ai_bootstrap_socket($socket_cmd, $socket_path) {
    if (ai_daemon_available()) {
        return;
    }

    $check = ai_exec_local_tmux('ls');
    if ($check === null || str_contains($check, 'no server running') || str_contains($check, 'error connecting')) {
        ai_log('Daemon tmux no disponible; levantando servidor tmux local');
        ai_exec_local_tmux("new-session -d -s bootstrap_init 'sleep 1'");
        usleep(500000);
    }
}

ai_bootstrap_socket($socket_cmd, $socket_path);

// --- FUNCIONES DIRECTAS DE TMUX ---

function tmux_has_session($name) {
    if (ai_daemon_available()) {
        $res = daemon_call('has', ['session' => $name]);
        return (bool)($res['exists'] ?? false);
    }

    $target = escapeshellarg($name);
    $res = ai_exec_local_tmux("has-session -t $target");
    return !(str_contains((string)$res, 'can\'t find session') || str_contains((string)$res, 'no server running') || str_contains((string)$res, 'error connecting'));
}

function tmux_new_session($name, $cmd, $workingDir = '/var/www') {
    if (ai_daemon_available()) {
        return daemon_call('run', [
            'session' => $name,
            'command' => $cmd,
            'working_dir' => $workingDir
        ]);
    }

    $finalCmd = "cd " . escapeshellarg($workingDir) . " && exec " . $cmd;
    $session = escapeshellarg($name);
    $output = ai_exec_local_tmux("new-session -d -s $session " . escapeshellarg($finalCmd));
    return ['status' => 'success', 'output' => $output];
}

function tmux_send_keys($name, $keys) {
    if (ai_daemon_available()) {
        return daemon_call('send', [
            'session' => $name,
            'keys' => $keys
        ]);
    }

    $target = escapeshellarg("{$name}:0");
    $literal = escapeshellarg($keys);
    $out1 = ai_exec_local_tmux("send-keys -t {$target} -l -- {$literal}");
    $out2 = ai_exec_local_tmux("send-keys -t {$target} Enter");
    return ['status' => 'success', 'output' => trim((string)$out1 . "\n" . (string)$out2)];
}

function ansi_clean($text) {
    if (!$text) return '';
    // Eliminar secuencias ANSI
    $text = preg_replace('/[\x1b\x9b][[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/', '', $text);
    // Eliminar artefactos específicos como 0[ o 0[m que a veces deja tmux capture-pane
    $text = str_replace(['0[m', '0['], '', $text);
    return trim($text);
}

function tmux_capture($name, $lines = 100) {
    if (ai_daemon_available()) {
        $res = daemon_call('capture', [
            'session' => $name,
            'lines' => $lines
        ]);
        return (string)($res['output'] ?? '');
    }

    $target = escapeshellarg("{$name}:0");
    $lines = max(50, (int)$lines);
    return (string)ai_exec_local_tmux("capture-pane -p -J -S -{$lines} -E - -t {$target}");
}

function tmux_capture_clean($name, $lines = 100) {
    return ansi_clean(tmux_capture($name, $lines));
}

function tmux_kill($name) {
    if (ai_daemon_available()) {
        return daemon_call('kill', ['session' => $name]);
    }

    $target = escapeshellarg($name);
    return ['status' => 'success', 'output' => ai_exec_local_tmux("kill-session -t {$target}")];
}

function ai_overlap_length($left, $right) {
    $max = min(strlen($left), strlen($right));
    for ($i = $max; $i > 0; $i--) {
        if (substr($left, -$i) === substr($right, 0, $i)) {
            return $i;
        }
    }
    return 0;
}

function ai_extract_delta($previous, $current) {
    $previous = (string)$previous;
    $current = (string)$current;

    if ($current === $previous) {
        return '';
    }
    if ($previous === '') {
        return $current;
    }
    if (str_starts_with($current, $previous)) {
        return substr($current, strlen($previous));
    }

    $pos = strpos($current, $previous);
    if ($pos !== false) {
        return substr($current, $pos + strlen($previous));
    }

    $overlap = ai_overlap_length($previous, $current);
    if ($overlap > 0) {
        return substr($current, $overlap);
    }

    return $current;
}

function ai_merge_chunks($existing, $delta) {
    $existing = trim((string)$existing);
    $delta = trim((string)$delta);

    if ($delta === '') {
        return $existing;
    }
    if ($existing === '') {
        return $delta;
    }
    if (str_contains($existing, $delta)) {
        return $existing;
    }

    $overlap = ai_overlap_length($existing, $delta);
    if ($overlap > 0) {
        return $existing . substr($delta, $overlap);
    }

    return $existing . "\n" . $delta;
}

function ai_build_agent_command($agente, $modeloSeleccionado = '') {
    $modeloSeleccionado = trim((string)$modeloSeleccionado);

    switch ($agente) {
        case 'codex':
            return '/usr/bin/codex --no-alt-screen -a on-request -s workspace-write';
        case 'opencode':
            return '/usr/local/bin/opencode'
                . ($modeloSeleccionado !== '' ? ' -m ' . escapeshellarg($modeloSeleccionado) : '');
        case 'kilo':
            return '/usr/bin/kilo';
        case 'kilo-code':
            return '/usr/bin/kilocode';
        case 'claude':
        default:
            return '/usr/local/bin/claude';
    }
}

function ai_sync_terminal_to_messages(PDO $pdo, $chat_id, $agente) {
    $chat_id = (int)$chat_id;
    $agente = (string)$agente;
    $sessionName = "ai_chat_{$chat_id}_{$agente}";
    $stateFile = ai_state_file($chat_id, $agente);
    $stateExists = is_file($stateFile);

    if ($chat_id <= 0 || !tmux_has_session($sessionName)) {
        return [
            'status' => 'idle',
            'delta' => '',
            'capture' => ''
        ];
    }

    $capture = trim((string)tmux_capture_clean($sessionName));
    if ($capture === '') {
        return [
            'status' => 'idle',
            'delta' => '',
            'capture' => ''
        ];
    }

    $state = ai_load_state($chat_id, $agente);
    if (!$stateExists) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_messages WHERE chat_id = ?");
        $countStmt->execute([$chat_id]);
        if ((int)$countStmt->fetchColumn() > 0) {
            ai_reset_state($chat_id, $agente, $capture);
            return [
                'status' => 'initialized',
                'delta' => '',
                'capture' => $capture
            ];
        }
    }

    $previousCapture = (string)($state['last_capture'] ?? '');
    $delta = trim(ai_extract_delta($previousCapture, $capture));

    if ($delta !== '') {
        $pendingId = (int)($state['pending_assistant_message_id'] ?? 0);
        if ($pendingId > 0) {
            $stmt = $pdo->prepare("SELECT id, contenido FROM ai_messages WHERE id = ? AND chat_id = ? AND rol = 'assistant' LIMIT 1");
            $stmt->execute([$pendingId, $chat_id]);
            $existingMsg = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $existingMsg = false;
        }

        if ($existingMsg) {
            $merged = ai_merge_chunks($existingMsg['contenido'] ?? '', $delta);
            $upd = $pdo->prepare("UPDATE ai_messages SET contenido = ? WHERE id = ?");
            $upd->execute([$merged, $existingMsg['id']]);
            $pendingId = (int)$existingMsg['id'];
        } else {
            $ins = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'assistant', ?)");
            $ins->execute([$chat_id, $delta]);
            $pendingId = (int)$pdo->lastInsertId();
        }

        $state['pending_assistant_message_id'] = $pendingId;
    }

    $state['last_capture'] = $capture;
    ai_save_state($chat_id, $agente, $state);

    return [
        'status' => $delta !== '' ? 'updated' : 'unchanged',
        'delta' => $delta,
        'capture' => $capture,
        'pending_assistant_message_id' => (int)($state['pending_assistant_message_id'] ?? 0)
    ];
}

// ---------------------------------

switch ($action) {
    case 'list_chats':
        $stmt = $pdo->query("SELECT c.*, p.nombre as proyecto_nombre FROM ai_chats c LEFT JOIN ai_projects p ON c.project_id = p.id ORDER BY c.fecha DESC LIMIT 50");
        ai_json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'list_projects':
        $stmt = $pdo->query("SELECT * FROM ai_projects ORDER BY nombre ASC");
        ai_json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'list_opencode_models':
        if (ai_daemon_available()) {
            $res = daemon_call('opencode_models');
            if (($res['status'] ?? 'error') !== 'success') {
                ai_json_response(['status' => 'error', 'msg' => $res['msg'] ?? 'No se pudo consultar modelos'], 500);
                break;
            }
            $rawModels = (string)($res['output'] ?? '');
        } else {
            $rawModels = (string)ai_exec_local('/usr/local/bin/opencode models');
        }
        $models = array_filter(explode("\n", trim($rawModels)));
        ai_json_response(['status' => 'success', 'models' => array_values($models)]);
        break;

    case 'get_opencode_stats':
        if (ai_daemon_available()) {
            $res = daemon_call('opencode_stats');
            if (($res['status'] ?? 'error') !== 'success') {
                ai_json_response(['status' => 'error', 'msg' => $res['msg'] ?? 'No se pudo consultar estadísticas'], 500);
                break;
            }
            $rawStats = (string)($res['output'] ?? '');
        } else {
            $rawStats = (string)ai_exec_local('/usr/local/bin/opencode stats');
        }
        ai_json_response(['status' => 'success', 'raw' => ansi_clean($rawStats)]);
        break;

    case 'save_project':
        $input = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($input['nombre'] ?? '');
        $ruta = trim($input['ruta'] ?? '/var/www');
        $id = (int)($input['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE ai_projects SET nombre = ?, ruta = ? WHERE id = ?");
            $st->execute([$nombre, $ruta, $id]);
        } else {
            $st = $pdo->prepare("INSERT INTO ai_projects (nombre, ruta) VALUES (?, ?)");
            $st->execute([$nombre, $ruta]);
        }
        ai_json_response(['status' => 'success']);
        break;

    case 'delete_project':
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_projects WHERE id = ?")->execute([$id]);
        ai_json_response(['status' => 'success']);
        break;

    case 'get_raw_logs':
        if (file_exists('palweb_ai_debug.log')) {
            ai_json_response(['status' => 'success', 'data' => shell_exec('tail -n 100 palweb_ai_debug.log')]);
        } else {
            ai_json_response(['status' => 'error', 'msg' => 'Log no encontrado'], 404);
        }
        break;

    case 'get_terminal_view':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        ai_sync_terminal_to_messages($pdo, $chat_id, $agente);
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        ai_json_response(['status' => 'success', 'data' => tmux_capture($sessionName)]);
        break;

    case 'get_messages':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        if ($chat_id > 0) {
            $stChat = $pdo->prepare("SELECT agente FROM ai_chats WHERE id = ? LIMIT 1");
            $stChat->execute([$chat_id]);
            $chatInfo = $stChat->fetch(PDO::FETCH_ASSOC);
            if ($chatInfo && !empty($chatInfo['agente'])) {
                ai_sync_terminal_to_messages($pdo, $chat_id, $chatInfo['agente']);
            }
        }
        $stmt = $pdo->prepare("SELECT * FROM ai_messages WHERE chat_id = ? ORDER BY fecha ASC");
        $stmt->execute([$chat_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['contenido'] = ansi_clean($r['contenido']);
        }
        ai_json_response($rows);
        break;

    case 'new_chat':
        $input = json_decode(file_get_contents('php://input'), true);
        $agente = $input['agente'] ?? 'claude';
        $titulo = $input['titulo'] ?? 'Nueva conversación';
        $modelo = $input['modelo'] ?? null;
        $project_id = (int)($input['project_id'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO ai_chats (titulo, agente, project_id, modelo) VALUES (?, ?, ?, ?)");
        $stmt->execute([$titulo, $agente, $project_id, $modelo]);
        ai_json_response(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        break;

    case 'delete_chat':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? '';
        if ($chat_id > 0 && $agente === '') {
            $stChat = $pdo->prepare("SELECT agente FROM ai_chats WHERE id = ? LIMIT 1");
            $stChat->execute([$chat_id]);
            $chatInfo = $stChat->fetch(PDO::FETCH_ASSOC);
            $agente = $chatInfo['agente'] ?? 'claude';
        }
        if ($agente === '') {
            $agente = 'claude';
        }
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        tmux_kill($sessionName);
        ai_delete_state($chat_id, $agente);
        $stmt = $pdo->prepare("DELETE FROM ai_chats WHERE id = ?");
        $stmt->execute([$chat_id]);
        ai_json_response(['status' => 'success']);
        break;

    case 'upload_file':
        $chat_id = (int)($_POST['chat_id'] ?? 0);
        if (!$chat_id || empty($_FILES['file'])) {
            ai_json_response(['status' => 'error', 'msg' => 'Datos insuficientes'], 400);
            exit;
        }
        try {
            $file = $_FILES['file'];
            $targetDir = "/var/www/ai_workspace/chat_{$chat_id}/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $targetPath = $targetDir . basename($file['name']);
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $stmt = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'user', ?)");
                $stmt->execute([$chat_id, "**[SISTEMA] Archivo subido: " . basename($file['name']) . "**"]);
                ai_json_response(['status' => 'success', 'msg' => 'Archivo subido']);
            } else { throw new Exception("Error al mover archivo"); }
        } catch (Exception $e) { ai_json_response(['status' => 'error', 'msg' => $e->getMessage()], 500); }
        break;

    case 'check_status':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        $content = tmux_capture($sessionName);
        $awaiting = false;
        $patterns = [
            '/\[y\/N\]/i',
            '/Confirm\?/i',
            '/Approve\?/i',
            '/Are you sure\?/i',
            '/\(y\/n\)/i',
            '/Do you want to allow/i',
            '/approval/i'
        ];
        foreach ($patterns as $p) { if (preg_match($p, $content)) { $awaiting = true; break; } }
        ai_json_response(['status' => 'success', 'awaiting_approval' => $awaiting]);
        break;

    case 'approve_action':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $response = $input['response'] ?? 'y';
        $agente = $input['agente'] ?? 'claude';
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        tmux_send_keys($sessionName, $response);
        $txt = ($response === 'y') ? "Aprobado remotamente." : "Denegado remotamente.";
        $stmt = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'user', ?)");
        $stmt->execute([$chat_id, "**[SISTEMA] $txt**"]);
        usleep(300000);
        ai_sync_terminal_to_messages($pdo, $chat_id, $agente);
        ai_json_response(['status' => 'success']);
        break;

    case 'send_message':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        $agente = $input['agente'] ?? 'claude';
        $mode = $input['mode'] ?? 'terminal';

        if (empty($message) || !$chat_id) {
            ai_json_response(['status' => 'error', 'msg' => 'Datos incompletos.'], 400);
            exit;
        }

        try {
            ai_log("--- MSG (Chat: $chat_id, Agente: $agente, Modo: $mode) ---");
            $stmt = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'user', ?)");
            $stmt->execute([$chat_id, $message]);

            $stChat = $pdo->prepare("SELECT c.modelo, p.ruta FROM ai_chats c LEFT JOIN ai_projects p ON c.project_id = p.id WHERE c.id = ?");
            $stChat->execute([$chat_id]);
            $chatData = $stChat->fetch(PDO::FETCH_ASSOC);
            $workingDir = $chatData['ruta'] ?? '/var/www';
            $modeloSeleccionado = $chatData['modelo'] ?? '';

            $sessionName = "ai_chat_{$chat_id}_{$agente}";
            $baseCmd = ai_build_agent_command($agente, $modeloSeleccionado);

            if (!tmux_has_session($sessionName)) {
                $runResult = tmux_new_session($sessionName, $baseCmd, $workingDir);
                if (($runResult['status'] ?? 'error') !== 'success') {
                    throw new RuntimeException($runResult['msg'] ?? 'No se pudo iniciar la sesión del agente');
                }
                usleep($agente === 'codex' ? 1800000 : 1000000);
            }

            $captureBefore = tmux_capture_clean($sessionName);
            ai_reset_state($chat_id, $agente, $captureBefore);

            tmux_send_keys($sessionName, $message);
            usleep(700000);

            $sync = ai_sync_terminal_to_messages($pdo, $chat_id, $agente);
            $respuesta_final = $sync['delta'] ?? '';

            ai_json_response([
                'status' => 'success',
                'response' => $respuesta_final,
                'mode' => $mode
            ]);
        } catch (Exception $e) {
            ai_log("ERROR: " . $e->getMessage());
            ai_json_response(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }
        break;

    case 'send_key':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $agente = $input['agente'] ?? 'claude';
        $key = $input['key'] ?? 'Escape';
        
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        if (tmux_has_session($sessionName)) {
            if (ai_daemon_available()) {
                daemon_call('send', [
                    'session' => $sessionName,
                    'keys' => $key,
                    'special' => true
                ]);
            } else {
                $target = escapeshellarg("{$sessionName}:0");
                ai_exec_local_tmux("send-keys -t {$target} {$key}");
            }
            usleep(250000);
            ai_sync_terminal_to_messages($pdo, $chat_id, $agente);
            ai_json_response(['status' => 'success']);
        } else {
            ai_json_response(['status' => 'error', 'msg' => 'Sesión no encontrada'], 404);
        }
        break;

    case 'send_terminal_input':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $agente = $input['agente'] ?? 'claude';
        $text = (string)($input['text'] ?? '');

        if ($chat_id <= 0 || $text === '') {
            ai_json_response(['status' => 'error', 'msg' => 'Datos incompletos.'], 400);
            exit;
        }

        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        if (!tmux_has_session($sessionName)) {
            ai_json_response(['status' => 'error', 'msg' => 'Sesión no encontrada'], 404);
            exit;
        }

        if (ai_daemon_available()) {
            $res = daemon_call('send', [
                'session' => $sessionName,
                'keys' => $text
            ]);
        } else {
            $res = tmux_send_keys($sessionName, $text);
        }

        usleep(250000);
        ai_sync_terminal_to_messages($pdo, $chat_id, $agente);
        ai_json_response([
            'status' => 'success',
            'result' => $res
        ]);
        break;

    default:
        ai_json_response(['status' => 'error', 'msg' => 'Acción no definida.'], 400);
        break;
}
