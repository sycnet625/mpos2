<?php
// ARCHIVO: palweb_ai_api.php
// DESCRIPCIÓN: Backend para el módulo de IA Interactivo (Direct TMUX Mode)
// VERSIÓN: 3.1 - Soporte para Proyectos y Directorios de Trabajo

ini_set('display_errors', 0);
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no válida.']);
    exit;
}

$action = $_GET['action'] ?? '';

// Función de Logging
function ai_log($msg) {
    $logFile = 'palweb_ai_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

// Socket persistente para evitar problemas de descubrimiento en /tmp
$socket_path = "/var/www/tmux_ai_socket";
$socket_cmd = "-S $socket_path";

// Asegurar que el socket de tmux existe y tiene permisos
function ai_bootstrap_socket($socket_cmd, $socket_path) {
    $socket_exists = file_exists($socket_path);
    $server_running = false;

    if ($socket_exists) {
        // Verificar si el servidor realmente responde
        $check = shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd ls 2>&1");
        if ($check !== null && !str_contains($check, 'error connecting') && !str_contains($check, 'no server running')) {
            $server_running = true;
        }
    }

    if (!$server_running) {
        ai_log("Bootstrap: Servidor no responde o socket no existe. Reiniciando...");
        if ($socket_exists) {
            // No podemos usar rm directo con sudo sin permiso específico, 
            // pero podemos intentar sobreescribirlo o crear la sesión
            shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd new-session -d -s bootstrap_init 'sleep 1' 2>&1");
        } else {
            shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd new-session -d -s bootstrap_init 'sleep 1' 2>&1");
        }
        usleep(800000);
    }

    // Asegurar permisos siempre (esto sí tiene permiso en sudoers)
    if (file_exists($socket_path)) {
        shell_exec("sudo /usr/bin/chmod 0777 $socket_path");
    }
}

ai_bootstrap_socket($socket_cmd, $socket_path);

// --- FUNCIONES DIRECTAS DE TMUX ---

function tmux_has_session($name) {
    global $socket_cmd;
    $res = shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd has-session -t $name 2>&1");
    return !(str_contains($res, 'can\'t find session') || str_contains($res, 'error connecting'));
}

function tmux_new_session($name, $cmd, $workingDir = '/var/www') {
    global $socket_cmd;
    $finalCmd = "cd " . escapeshellarg($workingDir) . " && " . $cmd;
    return shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd new-session -d -s $name " . escapeshellarg($finalCmd) . " 2>&1");
}

function tmux_send_keys($name, $keys) {
    global $socket_cmd;
    $escaped = str_replace("'", "'\\''", $keys);
    return shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd send-keys -t $name '$escaped' Enter 2>&1");
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
    global $socket_cmd;
    // Quitamos -e para menos basura ANSI
    $res = shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd capture-pane -p -t $name 2>&1");
    return $res;
}

function tmux_capture_clean($name, $lines = 100) {
    return ansi_clean(tmux_capture($name, $lines));
}

function tmux_kill($name) {
    global $socket_cmd;
    return shell_exec("sudo -u dude1 /usr/bin/tmux $socket_cmd kill-session -t $name 2>&1");
}

// ---------------------------------

switch ($action) {
    case 'list_chats':
        $stmt = $pdo->query("SELECT c.*, p.nombre as proyecto_nombre FROM ai_chats c LEFT JOIN ai_projects p ON c.project_id = p.id ORDER BY c.fecha DESC LIMIT 50");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'list_projects':
        $stmt = $pdo->query("SELECT * FROM ai_projects ORDER BY nombre ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
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
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_project':
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM ai_projects WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'get_raw_logs':
        if (file_exists('palweb_ai_debug.log')) {
            echo json_encode(['status' => 'success', 'data' => shell_exec('tail -n 100 palweb_ai_debug.log')]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Log no encontrado']);
        }
        break;

    case 'get_terminal_view':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        echo json_encode(['status' => 'success', 'data' => tmux_capture($sessionName)]);
        break;

    case 'get_messages':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM ai_messages WHERE chat_id = ? ORDER BY fecha ASC");
        $stmt->execute([$chat_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['contenido'] = ansi_clean($r['contenido']);
        }
        echo json_encode($rows);
        break;

    case 'new_chat':
        $input = json_decode(file_get_contents('php://input'), true);
        $agente = $input['agente'] ?? 'claude';
        $titulo = $input['titulo'] ?? 'Nueva conversación';
        $project_id = (int)($input['project_id'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO ai_chats (titulo, agente, project_id) VALUES (?, ?, ?)");
        $stmt->execute([$titulo, $agente, $project_id]);
        echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
        break;

    case 'delete_chat':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        tmux_kill($sessionName);
        $stmt = $pdo->prepare("DELETE FROM ai_chats WHERE id = ?");
        $stmt->execute([$chat_id]);
        echo json_encode(['status' => 'success']);
        break;

    case 'upload_file':
        $chat_id = (int)($_POST['chat_id'] ?? 0);
        if (!$chat_id || empty($_FILES['file'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Datos insuficientes']);
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
                echo json_encode(['status' => 'success', 'msg' => 'Archivo subido']);
            } else { throw new Exception("Error al mover archivo"); }
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        break;

    case 'check_status':
        $chat_id = (int)($_GET['chat_id'] ?? 0);
        $agente = $_GET['agente'] ?? 'claude';
        $sessionName = "ai_chat_{$chat_id}_{$agente}";
        $content = tmux_capture($sessionName);
        $awaiting = false;
        $patterns = ['/\[y\/N\]/i', '/Confirm\?/i', '/Approve\?/i', '/Are you sure\?/i', '/\(y\/n\)/i'];
        foreach ($patterns as $p) { if (preg_match($p, $content)) { $awaiting = true; break; } }
        echo json_encode(['status' => 'success', 'awaiting_approval' => $awaiting]);
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
        echo json_encode(['status' => 'success']);
        break;

    case 'send_message':
        $input = json_decode(file_get_contents('php://input'), true);
        $chat_id = (int)($input['chat_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        $agente = $input['agente'] ?? 'claude';
        $mode = $input['mode'] ?? 'terminal';

        if (empty($message) || !$chat_id) {
            echo json_encode(['status' => 'error', 'msg' => 'Datos incompletos.']);
            exit;
        }

        try {
            ai_log("--- MSG (Chat: $chat_id, Agente: $agente, Modo: $mode) ---");
            $stmt = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'user', ?)");
            $stmt->execute([$chat_id, $message]);

            $stProj = $pdo->prepare("SELECT p.ruta FROM ai_chats c JOIN ai_projects p ON c.project_id = p.id WHERE c.id = ?");
            $stProj->execute([$chat_id]);
            $workingDir = $stProj->fetchColumn() ?: '/var/www';

            $sessionName = "ai_chat_{$chat_id}_{$agente}";
            $comandos = [
                'claude'    => '/usr/local/bin/claude',
                'opencode'  => '/usr/local/bin/opencode',
                'codex'     => '/usr/bin/codex',
                'kilo'      => '/usr/bin/kilo',
                'kilo-code' => '/usr/bin/kilocode'
            ];
            $baseCmd = $comandos[$agente] ?? '/usr/local/bin/claude';

            if ($mode === 'chat') {
                // Para evitar 504, ejecutamos en una sesión tmux temporal y capturamos
                // O usamos un timeout agresivo. Intentemos modo tmux para consistencia.
                if (!tmux_has_session($sessionName)) {
                    tmux_new_session($sessionName, $baseCmd, $workingDir);
                    usleep(800000);
                }
                tmux_send_keys($sessionName, $message);
                
                // Esperamos un poco y devolvemos lo que haya, el frontend seguirá refrescando
                sleep(2);
                $respuesta_final = tmux_capture_clean($sessionName);
            } else {
                if (!tmux_has_session($sessionName)) {
                    tmux_new_session($sessionName, $baseCmd, $workingDir);
                    usleep(1000000); 
                }
                tmux_send_keys($sessionName, $message);
                sleep(1.5); // Reducido para evitar 504
                $respuesta_final = tmux_capture_clean($sessionName);
            }

            // Guardar solo si tenemos algo sustancial para no llenar de basura
            if (strlen($respuesta_final) > 5) {
                $stmtResp = $pdo->prepare("INSERT INTO ai_messages (chat_id, rol, contenido) VALUES (?, 'assistant', ?)");
                $stmtResp->execute([$chat_id, $respuesta_final]);
            }
            
            echo json_encode(['status' => 'success', 'response' => $respuesta_final]);
        } catch (Exception $e) {
            ai_log("ERROR: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'msg' => 'Acción no definida.']);
        break;
}
