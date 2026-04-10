<?php
// ARCHIVO: palweb_ai_monitor.php
// DESCRIPCIÓN: Monitor en segundo plano para sincronizar tmux con la base de datos en tiempo real.

require_once 'db.php';

$socket_path = "/tmp/palweb_ai_tmux.sock";
$socket_cmd = "-S $socket_path";

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

function ai_exec_root($command) {
    return shell_exec(ai_root_env_prefix() . " /bin/bash -lc " . escapeshellarg($command) . " 2>&1");
}

function ansi_clean($text) {
    if (!$text) return '';
    $text = preg_replace('/[\x1b\x9b][[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/', '', $text);
    $text = str_replace(['0[m', '0['], '', $text);
    // Limpiar marcos ASCII
    $text = preg_replace('/[┃╽╹▀▀▀▀█▣⬝]/u', '', $text);
    return trim($text);
}

echo "Iniciando Monitor de IA PalWeb...\n";

while (true) {
    // 1. Obtener sesiones activas de tmux
    $sessions_raw = ai_exec_root("/usr/bin/tmux $socket_cmd ls");
    if (!$sessions_raw || strpos($sessions_raw, 'failed') !== false) {
        sleep(2);
        continue;
    }

    preg_match_all('/ai_chat_(\d+)_([a-z0-9-]+):/', $sessions_raw, $matches);
    
    foreach ($matches[1] as $index => $chat_id) {
        $chat_id = (int)$chat_id;
        $agente = $matches[2][$index];
        $session_name = "ai_chat_{$chat_id}_{$agente}";

        // 2. Capturar contenido actual de la terminal
        $content_raw = ai_exec_root("/usr/bin/tmux $socket_cmd capture-pane -p -t " . escapeshellarg($session_name));
        $content = ansi_clean($content_raw);

        if (empty($content)) continue;

        // 3. Obtener el último mensaje del asistente en la DB para este chat
        $stmt = $pdo->prepare("SELECT id, contenido FROM ai_messages WHERE chat_id = ? AND rol = 'assistant' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$chat_id]);
        $last_msg = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si el contenido actual de tmux contiene el mensaje del usuario + la respuesta,
        // intentamos extraer solo la parte nueva o relevante. 
        // Para simplificar, si el contenido de tmux es diferente a lo que tenemos, actualizamos.
        
        if (!$last_msg) {
            // No hay mensaje del asistente aún, creamos uno si el contenido parece una respuesta
            // (evitamos guardar solo el prompt inicial si es posible)
            if (strlen($content) > 10) {
                $ins = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'assistant', ?)");
                $ins->execute([$chat_id, $content]);
                echo "[$session_name] Nueva respuesta creada.\n";
            }
        } else {
            // Ya existe un mensaje, comparamos. 
            // Usamos una comparación de longitud o contenido para evitar updates innecesarios.
            if ($last_msg['contenido'] !== $content && strlen($content) >= strlen($last_msg['contenido'])) {
                $upd = $pdo->prepare("UPDATE ai_messages SET contenido = ? WHERE id = ?");
                $upd->execute([$content, $last_msg['id']]);
                // echo "[$session_name] Respuesta actualizada.\n";
            }
        }
    }

    usleep(800000); // Esperar 0.8 segundos antes de la siguiente vuelta
}
