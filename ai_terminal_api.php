<?php
// ai_terminal_api.php - Usa el daemon para tmux

$chat_id = (int)($_GET['chat_id'] ?? 0);
$agente = $_GET['agente'] ?? 'claude';
$sessionName = "ai_chat_{$chat_id}_{$agente}";

function daemon_call($action, $data = []) {
    $client = @socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$client) return ['status' => 'error', 'msg' => 'Socket failed'];
    if (!@socket_connect($client, '/tmp/palweb_ai_tmux_daemon.sock')) {
        socket_close($client);
        return ['status' => 'error', 'msg' => 'Daemon not connected'];
    }
    $data['action'] = $action;
    socket_write($client, json_encode($data) . "\n");
    socket_set_nonblock($client);
    usleep(200000);
    $response = socket_read($client, 8192);
    socket_close($client);
    return json_decode($response, true) ?: ['status' => 'error', 'msg' => 'Invalid response'];
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $command = $input['command'] ?? '';
    
    if ($command === 'resize') {
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($command === 'send') {
        $keys = $input['keys'] ?? '';
        daemon_call('send', ['session' => $sessionName, 'keys' => $keys]);
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($command === 'start_session') {
        daemon_call('run', ['chat_id' => $chat_id, 'agente' => $agente]);
        echo json_encode(['status' => 'success', 'session' => $sessionName]);
        exit;
    }
}

$result = daemon_call('capture', ['session' => $sessionName]);

echo json_encode([
    'session' => $sessionName,
    'output' => $result['output'] ?? 'Sin contenido',
    'timestamp' => time()
]);
