<?php
// ARCHIVO: terminal_api.php
// Backend API para el terminal web SSH/tmux

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

ini_set('display_errors', 0);
set_time_limit(0);
ignore_user_abort(true);

// ── tmux como root via sudo (www-data ALL=(root) NOPASSWD: /usr/bin/tmux) ────
define('TMUX_SOCK', '/tmp/palweb_term_root.sock');

$action = $_GET['action'] ?? '';
if (empty($action)) {
    $rawBody = file_get_contents('php://input');
    $input   = json_decode($rawBody, true);
    if (!is_array($input)) $input = [];
    $action  = $input['action'] ?? $_POST['action'] ?? '';
} else {
    $rawBody = file_get_contents('php://input');
    $input   = json_decode($rawBody, true);
    if (!is_array($input)) $input = [];
}

if ($action !== 'stream') {
    header('Content-Type: application/json; charset=utf-8');
}

function term_exec($cmd) {
    // sudo -n -u root: corre tmux como root sin password (sudoers: www-data ALL=(root) NOPASSWD: /usr/bin/tmux)
    // Se pasan HOME y SHELL como variables al inicio del comando via sudoers env_keep o directamente
    $fullCmd = "sudo -n -u root /usr/bin/tmux -S " . TMUX_SOCK . " " . $cmd;
    // proc_open con descriptores explícitos evita que PHP-FPM cuelgue
    // al heredar los pipes de nginx en stdin/stdout/stderr
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($fullCmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['output' => '', 'code' => 1];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['output' => trim($stdout . $stderr), 'code' => $code];
}

/**
 * Asegura que el servidor tmux esté corriendo en nuestro socket compartido.
 * Si el socket ya existe, asume que el servidor está vivo y no hace nada
 * (evita proc_open innecesarios que saturan PHP-FPM).
 */
function term_ensure_server(): void {
    if (file_exists(TMUX_SOCK)) {
        return; // Servidor ya corriendo, nada que hacer
    }
    // Socket no existe → arrancar servidor con sesión keepalive
    term_exec("new-session -d -s __pw_boot__ 'while true; do sleep 3600; done'");
    usleep(400000);
    // Asegura permisos si el socket fue creado
    if (file_exists(TMUX_SOCK)) @chmod(TMUX_SOCK, 0600);
}

function term_safe_name(string $n): string {
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($n));
    return substr($clean ?: 'term_' . time(), 0, 40);
}

function term_session_exists(string $s): bool {
    $res = term_exec('has-session -t ' . escapeshellarg($s));
    return $res['code'] === 0;
}

function term_pipe_file(string $s): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $s);
    // Usar /var/www para los logs: root puede escribir aquí y www-data puede leer
    $dir = __DIR__ . '/.term_pipes';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . '/term_' . $safe . '.log';
}

function term_ensure_pipe(string $s): void {
    $f = term_pipe_file($s);
    // Stop any existing pipe
    term_exec('pipe-pane -t ' . escapeshellarg($s));
    usleep(20000);
    // Crear/vaciar el archivo como root y dejarlo legible para www-data
    term_exec('run-shell ' . escapeshellarg("touch " . escapeshellarg($f) . " && chmod 644 " . escapeshellarg($f)));
    usleep(30000);
    $oArg = escapeshellarg('cat >> ' . $f);
    term_exec('pipe-pane -t ' . escapeshellarg($s) . " -o {$oArg}");
}

// ── Asegurar servidor tmux (solo en acciones que lo necesitan) ───────────────
// send/resize/kill no llaman term_ensure_server: evita 3 proc_open extra por tecla
if (!in_array($action, ['send', 'resize', 'kill', 'stream'], true)) {
    term_ensure_server();
}

// ── Router ────────────────────────────────────────────────────────────────────

switch ($action) {

    // ── Listar sesiones ───────────────────────────────────────────────────────
    case 'list':
        $res = term_exec("list-sessions -F '#{session_name}\t#{session_windows}\t#{session_attached}\t#{session_activity}'");
        $lines = explode("\n", trim($res['output']));
        $sessions = [];
        foreach ($lines as $line) {
            if (empty($line) || strpos($line, 'no server running') !== false) continue;
            $p = explode("\t", $line . "\t\t\t\t", 4);
            if ($p[0] === '__pw_boot__') continue; // ocultar sesión bootstrap
            $sessions[] = [
                'name'     => $p[0],
                'windows'  => (int)$p[1],
                'attached' => (int)$p[2],
                'activity' => (int)$p[3],
            ];
        }
        echo json_encode(['sessions' => $sessions]);
        break;

    // ── Nueva sesión ──────────────────────────────────────────────────────────
    case 'new':
        $name = term_safe_name($input['name'] ?? 'term_' . date('His'));
        if (term_session_exists($name)) {
            term_ensure_pipe($name);
            $f = term_pipe_file($name);
            echo json_encode(['status' => 'existing', 'session' => $name,
                'offset' => file_exists($f) ? max(0, filesize($f) - 65536) : 0]);
        } else {
            $res = term_exec('new-session -d -s ' . escapeshellarg($name) . ' -x 200 -y 50');
            if ($res['code'] === 0) {
                term_ensure_pipe($name);
                echo json_encode(['status' => 'created', 'session' => $name, 'offset' => 0]);
            } else {
                echo json_encode(['error' => $res['output']]);
            }
        }
        break;

    // ── Adjuntar a sesión existente ───────────────────────────────────────────
    case 'attach':
        $name = term_safe_name($input['session'] ?? '');
        if (!$name || !term_session_exists($name)) {
            echo json_encode(['error' => 'Sesión no encontrada']);
            break;
        }
        term_ensure_pipe($name);
        $f = term_pipe_file($name);
        $offset = file_exists($f) ? max(0, filesize($f) - 65536) : 0;
        echo json_encode(['status' => 'ok', 'session' => $name, 'offset' => $offset]);
        break;

    // ── Enviar input al terminal ──────────────────────────────────────────────
    case 'send':
        $name = $input['session'] ?? '';
        $data = $input['data']    ?? '';
        if (!$name || !term_session_exists($name)) {
            echo json_encode(['error' => 'Sesión no encontrada']);
            break;
        }
        // Archivo en /var/www para que root pueda leerlo (root no puede leer /tmp/www-data)
        $tmpDir = __DIR__ . '/.term_pipes';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
        $tmp = $tmpDir . '/in_' . uniqid('', true);
        file_put_contents($tmp, $data);
        chmod($tmp, 0644); // root (sudo tmux) puede leerlo
        // Buffer único por request para evitar colisiones con teclas rápidas
        $buf   = '_pw_' . substr(md5(uniqid('', true)), 0, 8);
        $target = escapeshellarg($name);
        $res1 = term_exec("load-buffer -b {$buf} " . escapeshellarg($tmp));
        $res2 = term_exec("paste-buffer -b {$buf} -t {$target} -d");
        @unlink($tmp);
        echo json_encode(['status' => ($res1['code'] === 0 && $res2['code'] === 0) ? 'ok' : 'error',
                          'detail' => $res1['output'] . '|' . $res2['output']]);
        break;

    // ── Redimensionar ─────────────────────────────────────────────────────────
    case 'resize':
        $name = $input['session'] ?? '';
        $cols = max(20, min(500, (int)($input['cols'] ?? 80)));
        $rows = max(5,  min(200, (int)($input['rows'] ?? 24)));
        if ($name && term_session_exists($name)) {
            term_exec('resize-window -t ' . escapeshellarg($name) . " -x $cols -y $rows");
        }
        echo json_encode(['status' => 'ok']);
        break;

    // ── Matar sesión ──────────────────────────────────────────────────────────
    case 'kill':
        $name = $input['session'] ?? '';
        if ($name && term_session_exists($name)) {
            term_exec('kill-session -t ' . escapeshellarg($name));
        }
        $f = term_pipe_file($name);
        if ($f && file_exists($f)) @unlink($f);
        echo json_encode(['status' => 'killed']);
        break;

    // ── Stream SSE de output ──────────────────────────────────────────────────
    case 'stream':
        $name   = term_safe_name($_GET['session'] ?? '');
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        if (!$name || !term_session_exists($name)) {
            echo "event: error\ndata: Sesión no encontrada\n\n";
            @ob_flush(); flush();
            exit;
        }

        $file = term_pipe_file($name);
        if (!file_exists($file)) touch($file);
        $fp = @fopen($file, 'rb');
        if (!$fp) { echo "event: error\ndata: No se pudo abrir el pipe\n\n"; @ob_flush(); flush(); exit; }
        if ($offset > 0) fseek($fp, $offset);

        $ping = time();
        while (!connection_aborted()) {
            $chunk = @fread($fp, 16384);
            if ($chunk !== false && strlen($chunk) > 0) {
                echo 'data: ' . base64_encode($chunk) . "\n";
                echo 'id: '   . ftell($fp) . "\n\n";
                @ob_flush(); flush();
                $ping = time();
            } else {
                if (time() - $ping >= 20) {
                    echo ": ping\n\n";
                    @ob_flush(); flush();
                    $ping = time();
                }
                usleep(50000); // 50 ms
            }
        }
        fclose($fp);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción desconocida: ' . htmlspecialchars($action)]);
}
