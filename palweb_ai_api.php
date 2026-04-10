<?php
// ARCHIVO: palweb_ai_api.php
// DESCRIPCIÓN: Backend para el módulo de IA Interactivo (Direct TMUX Mode)
// VERSIÓN: 3.1 - Soporte para Proyectos y Directorios de Trabajo

ini_set('display_errors', 0);
session_start();
// require_once 'db.php'; // Removido: Uso de ficheros locales

header('Content-Type: application/json');

$GLOBALS['AI_API_RESPONSE_SENT'] = false;

// --- GESTIÓN DE DATOS EN FICHEROS LOCALES ---

function ai_data_dir() {
    $dir = __DIR__ . '/ai_data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function ai_get_projects() {
    $file = ai_data_dir() . '/projects.json';
    if (!is_file($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function ai_save_projects($projects) {
    $file = ai_data_dir() . '/projects.json';
    file_put_contents($file, json_encode($projects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function ai_get_chats() {
    $file = ai_data_dir() . '/chats.json';
    if (!is_file($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function ai_save_chats($chats) {
    $file = ai_data_dir() . '/chats.json';
    file_put_contents($file, json_encode($chats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function ai_get_messages($chat_id) {
    $file = ai_data_dir() . "/messages_{$chat_id}.json";
    if (!is_file($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function ai_save_messages($chat_id, $messages) {
    $file = ai_data_dir() . "/messages_{$chat_id}.json";
    file_put_contents($file, json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// --------------------------------------------

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
    @chmod($dir, 0777);
    return $dir;
}

function ai_runtime_path($suffix = '') {
    $base = ai_runtime_base();
    $path = $suffix ? ($base . '/' . ltrim($suffix, '/')) : $base;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    @chmod($path, 0777);
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

function ai_tmux_output_is_server_down($output) {
    $o = strtolower((string)$output);
    return str_contains($o, 'no server running')
        || str_contains($o, 'error connecting')
        || str_contains($o, 'failed to connect');
}

function ai_ensure_local_tmux_server() {
    global $socket_cmd, $socket_path;

    // Arranque y configuración base del servidor tmux en socket dedicado
    ai_exec_local("/usr/bin/tmux $socket_cmd start-server");
    ai_exec_local("/usr/bin/tmux $socket_cmd set-option -g exit-empty off");

    // Mantener una sesión viva para evitar caída del server entre requests
    $keepalive = '__palweb_ai_keepalive';
    $check = ai_exec_local("/usr/bin/tmux $socket_cmd has-session -t " . escapeshellarg($keepalive));
    if (ai_tmux_output_is_server_down($check) || str_contains((string)$check, "can't find session")) {
        ai_exec_local("/usr/bin/tmux $socket_cmd new-session -d -s " . escapeshellarg($keepalive) . " " . escapeshellarg("while true; do sleep 3600; done"));
    }

    // Si el socket quedó huérfano, recrearlo
    $probe = ai_exec_local("/usr/bin/tmux $socket_cmd ls");
    if (ai_tmux_output_is_server_down($probe) && is_file($socket_path)) {
        @unlink($socket_path);
        ai_exec_local("/usr/bin/tmux $socket_cmd start-server");
        ai_exec_local("/usr/bin/tmux $socket_cmd set-option -g exit-empty off");
        ai_exec_local("/usr/bin/tmux $socket_cmd new-session -d -s " . escapeshellarg($keepalive) . " " . escapeshellarg("while true; do sleep 3600; done"));
    }
}

function ai_exec_local_tmux($command) {
    global $socket_cmd;
    ai_ensure_local_tmux_server();
    $out = ai_exec_local("/usr/bin/tmux $socket_cmd $command");
    if (ai_tmux_output_is_server_down($out)) {
        ai_log("tmux caído, reintentando comando: $command");
        ai_ensure_local_tmux_server();
        $out = ai_exec_local("/usr/bin/tmux $socket_cmd $command");
    }
    return $out;
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
    ai_log('Daemon tmux no disponible; inicializando tmux local persistente');
    ai_ensure_local_tmux_server();
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
    // 1. Eliminar secuencias de escape ANSI
    $text = preg_replace('/[\x1b\x9b][[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/', '', $text);
    
    // 2. Artefactos específicos de tmux/ncurses
    $text = str_replace(['0[m', '0['], '', $text);

    $lines = explode("\n", $text);
    $filtered = [];
    
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === '') continue;

        // --- LISTA NEGRA DE RUIDO VISUAL ---
        // Bloques y decoraciones sólidas: ▄ █ ▀
        if (preg_match('/[▄█▀░▒▓█]{3,}/u', $l)) continue;
        // Spinners y estados de pensamiento: ⠙ Thinking... (9s)
        if (preg_match('/[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏].*Thinking/u', $l)) continue;
        // Atajos y ayudas de teclado: esc to cancel, ? for shortcuts, Ctrl+Y
        if (preg_match('/(esc to cancel|\? for shortcuts|Ctrl\+[A-Z0-9])/i', $l)) continue;
        // Prompts de entrada de agente: Type your message or @path
        if (preg_match('/Type your message|@path\/to\/file/i', $l)) continue;
        // Información de contexto/configuración del modelo/sandbox
        if (preg_match('/workspace.*sandbox|model.*Auto|Gemini.*latest|no sandbox/i', $l)) continue;
        // Archivos detectados o info de sesión irrelevante
        if (preg_match('/\d+ GEMINI\.md file/i', $l)) continue;
        // Bordes de cuadros y líneas de separación de terminal
        if (preg_match('/^[ \-=_*#\.\/|\\│─┌┐└┘├┤┬┴┼═║╒╓╔╕╖╗╘╙╚╛╜╝╞╟╠╡╢╣╤╥╦╧╨╩╪╫╬]+$/u', $l)) continue;

        $filtered[] = $line;
    }
    
    return trim(implode("\n", $filtered));
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
    $out = (string)ai_exec_local_tmux("capture-pane -p -J -S -{$lines} -E - -t {$target}");
    $low = strtolower($out);
    if (str_contains($low, "can't find session") || ai_tmux_output_is_server_down($out)) {
        return '';
    }
    return $out;
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

function ai_strlen_safe($text) {
    if (function_exists('mb_strlen')) {
        return mb_strlen((string)$text, 'UTF-8');
    }
    return strlen((string)$text);
}

function ai_substr_safe($text, $start, $len = null) {
    $text = (string)$text;
    if (function_exists('mb_substr')) {
        return $len === null
            ? mb_substr($text, (int)$start, null, 'UTF-8')
            : mb_substr($text, (int)$start, (int)$len, 'UTF-8');
    }
    return $len === null
        ? substr($text, (int)$start)
        : substr($text, (int)$start, (int)$len);
}

function ai_message_contenido_limit($pdo = null) {
    return 1000000; // Límite virtual amplio para ficheros locales
}

function ai_fit_message_content($pdo, $text) {
    $text = (string)$text;
    $limit = ai_message_contenido_limit();
    if (ai_strlen_safe($text) <= $limit) {
        return $text;
    }

    $suffix = "… [truncado]";
    $suffixLen = ai_strlen_safe($suffix);
    $cut = max(0, $limit - $suffixLen);
    $trimmed = ai_substr_safe($text, 0, $cut);
    return $trimmed . $suffix;
}

function ai_build_agent_command($agente, $modeloSeleccionado = '', $promptInicial = '') {
    $modeloSeleccionado = trim((string)$modeloSeleccionado);
    $promptInicial = trim((string)$promptInicial);

    switch ($agente) {
        case 'codex':
            return '/usr/bin/codex --no-alt-screen -a on-request -s workspace-write';
        case 'opencode':
            return '/bin/opencode'
                . ($modeloSeleccionado !== '' ? ' -m ' . escapeshellarg($modeloSeleccionado) : '');
        case 'kilo':
            // Si el modelo de kilo empieza por "kilo/", limpiar el prefijo
            if (str_starts_with($modeloSeleccionado, 'kilo/')) {
                $modeloSeleccionado = substr($modeloSeleccionado, 5);
            }
            return '/usr/bin/kilo'
                . ($modeloSeleccionado !== '' ? ' -m ' . escapeshellarg($modeloSeleccionado) : '');
        case 'gemini':
            $cmd = '/usr/bin/gemini -y -r latest';
            if ($modeloSeleccionado !== '') {
                $cmd .= ' -m ' . escapeshellarg($modeloSeleccionado);
            }
            if ($promptInicial !== '') {
                $cmd .= ' -i ' . escapeshellarg($promptInicial);
            }
            return $cmd;
        case 'claude':
        default:
            return '/usr/local/bin/claude';
    }
}

function ai_load_chat_runtime($pdo, $chat_id) {
    $chat_id = (int)$chat_id;
    if ($chat_id <= 0) return null;

    $chats = ai_get_chats();
    foreach ($chats as $c) {
        if ((int)$c['id'] === $chat_id) {
            $projects = ai_get_projects();
            $ruta = '/var/www';
            foreach ($projects as $p) {
                if ($p['id'] == $c['project_id']) {
                    $ruta = $p['ruta'];
                    break;
                }
            }
            return [
                'id' => $c['id'],
                'agente' => $c['agente'],
                'modelo' => $c['modelo'],
                'ruta' => $ruta
            ];
        }
    }
    return null;
}

function ai_ensure_chat_session($pdo, $chat_id, $agente, $mensajeInicial = '') {
    $chat_id = (int)$chat_id;
    $agente = trim((string)$agente);
    $mensajeInicial = trim((string)$mensajeInicial);
    if ($chat_id <= 0) {
        return ['status' => 'error', 'msg' => 'chat_id inválido', 'http' => 400];
    }

    $chat = ai_load_chat_runtime(null, $chat_id);
    if (!$chat) {
        return ['status' => 'error', 'msg' => 'Chat no encontrado', 'http' => 404];
    }

    if ($agente === '') {
        $agente = (string)($chat['agente'] ?? 'claude');
    }
    $workingDir = (string)($chat['ruta'] ?? '/var/www');
    $modeloSeleccionado = (string)($chat['modelo'] ?? '');
    $sessionName = "ai_chat_{$chat_id}_{$agente}";

    if (!tmux_has_session($sessionName)) {
        // Al crear la sesión por primera vez, pasamos el mensaje inicial si es Gemini para usar -i
        $baseCmd = ai_build_agent_command($agente, $modeloSeleccionado, ($agente === 'gemini' ? $mensajeInicial : ''));
        $runResult = tmux_new_session($sessionName, $baseCmd, $workingDir);
        if (($runResult['status'] ?? 'error') !== 'success') {
            return ['status' => 'error', 'msg' => $runResult['msg'] ?? 'No se pudo iniciar sesión tmux', 'http' => 500];
        }
        usleep($agente === 'codex' ? 1800000 : 1200000);
    }

    return [
        'status' => 'success',
        'session' => $sessionName,
        'agente' => $agente,
        'working_dir' => $workingDir,
        'modelo' => $modeloSeleccionado
    ];
}

function ai_sync_terminal_to_messages($pdo, $chat_id, $agente) {
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
    $messages = ai_get_messages($chat_id);

    if (!$stateExists) {
        if (count($messages) > 0) {
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
        $foundIdx = -1;
        if ($pendingId > 0) {
            foreach ($messages as $idx => $m) {
                if ((int)$m['id'] === $pendingId && $m['rol'] === 'assistant') {
                    $foundIdx = $idx;
                    break;
                }
            }
        }

        if ($foundIdx !== -1) {
            $merged = ai_merge_chunks($messages[$foundIdx]['contenido'] ?? '', $delta);
            $merged = ai_fit_message_content(null, $merged);
            $messages[$foundIdx]['contenido'] = $merged;
            $pendingId = (int)$messages[$foundIdx]['id'];
        } else {
            $delta = ai_fit_message_content(null, $delta);
            $newId = 1;
            foreach ($messages as $m) { if ($m['id'] >= $newId) $newId = $m['id'] + 1; }
            $messages[] = [
                'id' => $newId,
                'chat_id' => $chat_id,
                'rol' => 'assistant',
                'contenido' => $delta,
                'fecha' => date('Y-m-d H:i:s')
            ];
            $pendingId = $newId;
        }

        ai_save_messages($chat_id, $messages);
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
        $chats = ai_get_chats();
        $projects = ai_get_projects();
        foreach ($chats as &$c) {
            $c['proyecto_nombre'] = '';
            foreach ($projects as $p) {
                if ($p['id'] == $c['project_id']) {
                    $c['proyecto_nombre'] = $p['nombre'];
                    break;
                }
            }
        }
        usort($chats, function($a, $b) { return strcmp($b['fecha'], $a['fecha']); });
        ai_json_response($chats);
        break;

    case 'list_projects':
        $projects = ai_get_projects();
        usort($projects, function($a, $b) { return strcmp($a['nombre'], $b['nombre']); });
        ai_json_response($projects);
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
            $rawModels = (string)ai_exec_local('/bin/opencode models');
        }
        $models = array_filter(explode("\n", trim($rawModels)));
        ai_json_response(['status' => 'success', 'models' => array_values($models)]);
        break;

    case 'list_kilo_models':
        $rawModels = (string)ai_exec_local('/usr/bin/kilo models');
        $allModels = array_filter(explode("\n", trim($rawModels)));
        // Filtrar modelos que contienen 'free' o ':free'
        $freeModels = array_filter($allModels, function($m) {
            return stripos($m, 'free') !== false;
        });
        // Si no hay ninguno con 'free', devolver los primeros 10 como fallback
        if (empty($freeModels)) {
            $freeModels = array_slice($allModels, 0, 10);
        }
        ai_json_response(['status' => 'success', 'models' => array_values($freeModels)]);
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
            $rawStats = (string)ai_exec_local('/bin/opencode stats');
        }
        ai_json_response(['status' => 'success', 'raw' => ansi_clean($rawStats)]);
        break;

    case 'save_project':
        $input = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($input['nombre'] ?? '');
        $ruta = trim($input['ruta'] ?? '/var/www');
        $id = (int)($input['id'] ?? 0);
        $projects = ai_get_projects();
        if ($id > 0) {
            foreach ($projects as &$p) {
                if ((int)$p['id'] === $id) {
                    $p['nombre'] = $nombre;
                    $p['ruta'] = $ruta;
                    break;
                }
            }
        } else {
            $newId = 1;
            foreach ($projects as $p) { if ((int)$p['id'] >= $newId) $newId = (int)$p['id'] + 1; }
            $projects[] = ['id' => $newId, 'nombre' => $nombre, 'ruta' => $ruta];
        }
        ai_save_projects($projects);
        ai_json_response(['status' => 'success']);
        break;

    case 'delete_project':
        $id = (int)($_GET['id'] ?? 0);
        $projects = ai_get_projects();
        $projects = array_filter($projects, function($p) use ($id) { return (int)$p['id'] !== $id; });
        ai_save_projects(array_values($projects));
        ai_json_response(['status' => 'success']);
        break;

    case 'clear_chat':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        if ($chat_id > 0) {
            ai_save_messages($chat_id, []);
            ai_reset_state($chat_id, $agente, '');
            ai_json_response(['status' => 'success']);
        } else {
            ai_json_response(['status' => 'error', 'msg' => 'ID de chat inválido'], 400);
        }
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
        $ensure = ai_ensure_chat_session(null, $chat_id, $agente);
        if (($ensure['status'] ?? 'error') !== 'success') {
            ai_json_response(['status' => 'error', 'msg' => $ensure['msg'] ?? 'No se pudo abrir terminal'], (int)($ensure['http'] ?? 500));
            break;
        }
        $agente = $ensure['agente'];
        $sessionName = $ensure['session'];
        ai_sync_terminal_to_messages(null, $chat_id, $agente);
        ai_json_response(['status' => 'success', 'data' => tmux_capture($sessionName)]);
        break;

    case 'get_messages':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        if ($chat_id > 0) {
            $chats = ai_get_chats();
            $agente = 'claude';
            foreach ($chats as $c) {
                if ((int)$c['id'] === $chat_id) {
                    $agente = $c['agente'];
                    break;
                }
            }
            ai_sync_terminal_to_messages(null, $chat_id, $agente);
        }
        $rows = ai_get_messages($chat_id);
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
        $chats = ai_get_chats();
        $newId = 1;
        foreach ($chats as $c) { if ((int)$c['id'] >= $newId) $newId = (int)$c['id'] + 1; }
        $newChat = [
            'id' => $newId,
            'titulo' => $titulo,
            'agente' => $agente,
            'project_id' => $project_id,
            'modelo' => $modelo,
            'fecha' => date('Y-m-d H:i:s')
        ];
        $chats[] = $newChat;
        ai_save_chats($chats);
        ai_json_response(['status' => 'success', 'id' => $newId]);
        break;

    case 'delete_chat':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? '';
        $chats = ai_get_chats();
        if ($chat_id > 0 && $agente === '') {
            foreach ($chats as $c) {
                if ((int)$c['id'] === $chat_id) {
                    $agente = $c['agente'];
                    break;
                }
            }
        }
        if ($agente === '') $agente = 'claude';

        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        tmux_kill($sessionName);
        ai_delete_state($chat_id, $agente);
        
        $chats = array_filter($chats, function($c) use ($chat_id) { return (int)$c['id'] !== $chat_id; });
        ai_save_chats(array_values($chats));
        @unlink(ai_data_dir() . "/messages_{$chat_id}.json");

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
                $msgText = "**[SISTEMA] Archivo subido: " . basename($file['name']) . "**";
                $msgText = ai_fit_message_content(null, $msgText);
                
                $messages = ai_get_messages($chat_id);
                $newId = 1;
                foreach ($messages as $m) { if ($m['id'] >= $newId) $newId = $m['id'] + 1; }
                $messages[] = [
                    'id' => $newId,
                    'chat_id' => $chat_id,
                    'rol' => 'user',
                    'contenido' => $msgText,
                    'fecha' => date('Y-m-d H:i:s')
                ];
                ai_save_messages($chat_id, $messages);
                
                ai_json_response(['status' => 'success', 'msg' => 'Archivo subido']);
            } else { throw new Exception("Error al mover archivo"); }
        } catch (Exception $e) { ai_json_response(['status' => 'error', 'msg' => $e->getMessage()], 500); }
        break;

    case 'check_status':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        $ensure = ai_ensure_chat_session(null, $chat_id, $agente);
        if (($ensure['status'] ?? 'error') !== 'success') {
            ai_json_response(['status' => 'success', 'awaiting_approval' => false]);
            break;
        }
        $sessionName = $ensure['session'];
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
        $txt = ai_fit_message_content(null, "**[SISTEMA] $txt**");
        
        $messages = ai_get_messages($chat_id);
        $newId = 1;
        foreach ($messages as $m) { if ($m['id'] >= $newId) $newId = $m['id'] + 1; }
        $messages[] = [
            'id' => $newId,
            'chat_id' => $chat_id,
            'rol' => 'user',
            'contenido' => $txt,
            'fecha' => date('Y-m-d H:i:s')
        ];
        ai_save_messages($chat_id, $messages);
        
        usleep(300000);
        ai_sync_terminal_to_messages(null, $chat_id, $agente);
        ai_json_response(['status' => 'success']);
        break;

    case 'send_message':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $messageText = trim($input['message'] ?? '');
        $agente = $input['agente'] ?? 'claude';
        $mode = $input['mode'] ?? 'terminal';

        if (empty($messageText) || !$chat_id) {
            ai_json_response(['status' => 'error', 'msg' => 'Datos incompletos.'], 400);
            exit;
        }

        try {
            ai_log("--- MSG (Chat: $chat_id, Agente: $agente, Modo: $mode) ---");
            $messageText = ai_fit_message_content(null, $messageText);
            
            $messages = ai_get_messages($chat_id);
            $newId = 1;
            foreach ($messages as $m) { if ($m['id'] >= $newId) $newId = $m['id'] + 1; }
            $messages[] = [
                'id' => $newId,
                'chat_id' => $chat_id,
                'rol' => 'user',
                'contenido' => $messageText,
                'fecha' => date('Y-m-d H:i:s')
            ];
            ai_save_messages($chat_id, $messages);

            $sessionExisted = tmux_has_session("ai_chat_{$chat_id}_{$agente}");

            $ensure = ai_ensure_chat_session(null, $chat_id, $agente, $messageText);
            if (($ensure['status'] ?? 'error') !== 'success') {
                throw new RuntimeException($ensure['msg'] ?? 'No se pudo iniciar la sesión del agente');
            }
            $agente = $ensure['agente'];
            $sessionName = $ensure['session'];

            $captureBefore = tmux_capture_clean($sessionName);
            ai_reset_state($chat_id, $agente, $captureBefore);

            if ($agente !== 'gemini' || $sessionExisted) {
                tmux_send_keys($sessionName, $messageText);
            }
            usleep(700000);

            $sync = ai_sync_terminal_to_messages(null, $chat_id, $agente);
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
        
        $ensure = ai_ensure_chat_session(null, $chat_id, $agente);
        if (($ensure['status'] ?? 'error') !== 'success') {
            ai_json_response(['status' => 'error', 'msg' => $ensure['msg'] ?? 'Sesión no encontrada'], (int)($ensure['http'] ?? 404));
            break;
        }
        $agente = $ensure['agente'];
        $sessionName = $ensure['session'];
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
        ai_sync_terminal_to_messages(null, $chat_id, $agente);
        ai_json_response(['status' => 'success']);
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

        $ensure = ai_ensure_chat_session(null, $chat_id, $agente);
        if (($ensure['status'] ?? 'error') !== 'success') {
            ai_json_response(['status' => 'error', 'msg' => $ensure['msg'] ?? 'Sesión no encontrada'], (int)($ensure['http'] ?? 404));
            exit;
        }
        $agente = $ensure['agente'];
        $sessionName = $ensure['session'];

        if (ai_daemon_available()) {
            $res = daemon_call('send', [
                'session' => $sessionName,
                'keys' => $text
            ]);
        } else {
            $res = tmux_send_keys($sessionName, $text);
        }

        usleep(250000);
        ai_sync_terminal_to_messages(null, $chat_id, $agente);
        ai_json_response([
            'status' => 'success',
            'result' => $res
        ]);
        break;

    default:
        ai_json_response(['status' => 'error', 'msg' => 'Acción no definida.'], 400);
        break;
}
