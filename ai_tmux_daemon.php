#!/usr/bin/env php
<?php
// AI Agent Tmux Daemon - Corre como root, escucha en socket Unix

$daemonSocketPath = '/tmp/palweb_ai_tmux_daemon.sock';
$pidFile = '/tmp/palweb_ai_tmux_daemon.pid';
$tmuxSocketPath = "/tmp/palweb_ai_tmux.sock";
$tmux_socket_cmd = "-S $tmuxSocketPath";

function ai_runtime_dir($suffix = '') {
    $base = '/tmp/palweb_ai_runtime';
    $dir = $suffix ? ($base . '/' . ltrim($suffix, '/')) : $base;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function ai_root_env_prefix() {
    $path = '/root/.opencode/bin:/root/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    $stateDir = ai_runtime_dir('state');
    $dataDir = ai_runtime_dir('data');
    $cacheDir = ai_runtime_dir('cache');
    return "env HOME=/root USER=root LOGNAME=root SHELL=/bin/bash PATH=" . escapeshellarg($path)
        . " XDG_CONFIG_HOME=/root/.config"
        . " XDG_STATE_HOME=" . escapeshellarg($stateDir)
        . " XDG_DATA_HOME=" . escapeshellarg($dataDir)
        . " XDG_CACHE_HOME=" . escapeshellarg($cacheDir);
}

function ai_build_agent_command($agente, $modeloSeleccionado = '') {
    $modeloSeleccionado = trim((string)$modeloSeleccionado);

    switch ($agente) {
        case 'codex':
            return '/usr/bin/codex --no-alt-screen -a on-request -s workspace-write';
        case 'opencode':
            return '/root/.opencode/bin/opencode'
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

function ai_build_session_command($workingDir, $agentCmd) {
    $workingDir = $workingDir ?: '/var/www';
    $shellCmd = "cd " . escapeshellarg($workingDir) . " && exec " . $agentCmd;
    return ai_root_env_prefix() . " /bin/bash --noprofile --norc -c " . escapeshellarg($shellCmd);
}

if (file_exists($pidFile)) {
    $oldPid = (int)file_get_contents($pidFile);
    if ($oldPid && posix_kill($oldPid, 0)) {
        echo "Daemon ya corriendo (PID: $oldPid)\n";
        exit(0);
    }
    @unlink($pidFile);
}

if (file_exists($daemonSocketPath)) @unlink($daemonSocketPath);
if (!file_exists($tmuxSocketPath)) {
    shell_exec("/usr/bin/tmux -S $tmuxSocketPath new-session -d -s bootstrap 'sleep 3600' 2>&1");
    usleep(500000);
    @chmod($tmuxSocketPath, 0777);
} else {
    // Verificar que el servidor esté corriendo
    $checkBootstrap = shell_exec("/usr/bin/tmux -S $tmuxSocketPath has-session -t bootstrap 2>&1");
    if (strpos($checkBootstrap, 'no server') !== false || strpos($checkBootstrap, 'not found') !== false) {
        shell_exec("/usr/bin/tmux -S $tmuxSocketPath new-session -d -s bootstrap 'sleep 3600' 2>&1");
        usleep(500000);
    }
}

$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_bind($sock, $daemonSocketPath);
socket_listen($sock, 5);
chmod($daemonSocketPath, 0777);

$pid = getmypid();
file_put_contents($pidFile, $pid);

echo "Daemon tmux iniciado (PID: $pid, DaemonSocket: $daemonSocketPath)\n";

$running = true;
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
pcntl_signal(SIGINT, function() use (&$running) { $running = false; });

function run_tmux($cmd) {
    global $tmux_socket_cmd;
    $fullCmd = "/usr/bin/tmux $tmux_socket_cmd $cmd 2>&1";
    $output = [];
    $returnCode = 0;
    exec($fullCmd, $output, $returnCode);
    return implode("\n", $output);
}

function handleCommand($cmd) {
    if (!$cmd) return ['status' => 'error', 'msg' => 'JSON inválido'];
    
    switch ($cmd['action'] ?? '') {
        case 'run': return runAgent($cmd);
        case 'capture': return captureTerminal($cmd);
        case 'send': return sendKeys($cmd);
        case 'list': return listSessions();
        case 'kill': return killSession($cmd);
        case 'has': return hasSession($cmd);
        case 'opencode_models': return opencodeModels();
        case 'opencode_stats': return opencodeStats();
        default: return ['status' => 'error', 'msg' => 'Acción desconocida'];
    }
}

function runAgent($cmd) {
    $sessionName = $cmd['session'] ?? '';
    if ($sessionName === '') {
        $agente = $cmd['agente'] ?? 'opencode';
        $chat_id = (int)($cmd['chat_id'] ?? 0);
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
    }

    $agente = $cmd['agente'] ?? 'opencode';
    $workingDir = $cmd['working_dir'] ?? '/var/www';
    $modelo = $cmd['modelo'] ?? '';
    $agentCmd = $cmd['command'] ?? ai_build_agent_command($agente, $modelo);
    $bootCmd = ai_build_session_command($workingDir, $agentCmd);

    $check = run_tmux("has-session -t " . escapeshellarg($sessionName) . " 2>&1");
    $msg = "Check: $check";

    if (strpos($check, 'no server') !== false || strpos($check, 'not found') !== false || strpos($check, 'error') !== false || strpos($check, 'can\'t find session') !== false) {
        $createResult = run_tmux("new-session -d -s " . escapeshellarg($sessionName) . ' ' . escapeshellarg($bootCmd));
        $msg .= " | Create: $createResult | Cmd: $bootCmd";
        usleep(1000000);
    }
    
    return ['status' => 'success', 'session' => $sessionName, 'debug' => $msg];
}

function captureTerminal($cmd) {
    $sessionName = $cmd['session'] ?? '';
    if (!$sessionName) return ['status' => 'error', 'msg' => 'Session requerida'];
    
    $lines = max(50, (int)($cmd['lines'] ?? 200));
    $target = escapeshellarg("{$sessionName}:0");
    $output = run_tmux("capture-pane -p -J -S -{$lines} -E - -t {$target}");
    $output = $output ?: '';
    $output = @shell_exec("printf %s " . escapeshellarg($output) . " | sed 's/\x1b\\[[0-9;]*[A-Za-z]//g; s/M-BM-[^ ]*//g'");
    
    return ['status' => 'success', 'output' => $output ?: $output];
}

function sendKeys($cmd) {
    $sessionName = $cmd['session'] ?? '';
    $keys = $cmd['keys'] ?? '';
    $special = !empty($cmd['special']);
    
    if (!$sessionName) return ['status' => 'error', 'msg' => 'Session requerida'];
    
    $target = escapeshellarg("{$sessionName}:0");
    if ($special) {
        run_tmux("send-keys -t {$target} {$keys}");
    } else {
        $literal = escapeshellarg($keys);
        run_tmux("send-keys -t {$target} -l -- {$literal}");
        run_tmux("send-keys -t {$target} Enter");
    }
    
    return ['status' => 'success'];
}

function listSessions() {
    $output = run_tmux("list-sessions");
    return ['status' => 'success', 'sessions' => $output];
}

function killSession($cmd) {
    $sessionName = $cmd['session'] ?? '';
    if (!$sessionName) return ['status' => 'error', 'msg' => 'Session requerida'];
    
    run_tmux("kill-session -t " . escapeshellarg($sessionName));
    return ['status' => 'success'];
}

function hasSession($cmd) {
    $sessionName = $cmd['session'] ?? '';
    if (!$sessionName) return ['status' => 'error', 'msg' => 'Session requerida'];

    $output = run_tmux("has-session -t " . escapeshellarg($sessionName) . " 2>&1");
    $exists = !(strpos($output, 'no server') !== false || strpos($output, 'not found') !== false || strpos($output, 'can\'t find session') !== false || strpos($output, 'error') !== false);
    return ['status' => 'success', 'exists' => $exists, 'raw' => $output];
}

function opencodeModels() {
    $cmd = ai_root_env_prefix() . " /bin/bash --noprofile --norc -c " . escapeshellarg('/root/.opencode/bin/opencode models');
    $output = shell_exec($cmd . ' 2>&1');
    return ['status' => 'success', 'output' => (string)$output];
}

function opencodeStats() {
    $cmd = ai_root_env_prefix() . " /bin/bash --noprofile --norc -c " . escapeshellarg('/root/.opencode/bin/opencode stats');
    $output = shell_exec($cmd . ' 2>&1');
    return ['status' => 'success', 'output' => (string)$output];
}

while ($running) {
    pcntl_signal_dispatch();
    socket_set_nonblock($sock);
    $client = @socket_accept($sock);
    
    if ($client) {
        socket_set_block($client);
        $data = socket_read($client, 8192);
        $cmd = json_decode($data, true);
        $response = handleCommand($cmd);
        socket_write($client, json_encode($response) . "\n");
        socket_close($client);
    }
    
    usleep(50000);
}

socket_close($sock);
@unlink($daemonSocketPath);
@unlink($pidFile);
echo "Daemon detenido\n";
