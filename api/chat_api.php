<?php
// ARCHIVO: /var/www/palweb/api/chat_api.php
header('Content-Type: application/json');
require_once 'db.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

try {
    // 1. ENVIAR MENSAJE (Cliente o Admin)
    if ($action === 'send') {
        $uuid = $input['uuid'] ?? '';
        $msg = trim($input['message'] ?? '');
        $sender = $input['sender'] ?? 'client'; // 'client' o 'admin'

        if (!$uuid || !$msg) throw new Exception("Datos incompletos");

        $stmt = $pdo->prepare("INSERT INTO chat_messages (client_uuid, sender, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
        $stmt->execute([$uuid, $sender, $msg]);
        
        // Si el admin responde, marcar los del cliente como leídos
        if($sender === 'admin'){
            $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE client_uuid = ? AND sender = 'client'")->execute([$uuid]);
        }

        echo json_encode(['status' => 'success']);
    }

    // 2. OBTENER CONVERSACIÓN (Para el Cliente)
    elseif ($action === 'get_client_msgs') {
        $uuid = $_GET['uuid'] ?? '';
        $stmt = $pdo->prepare("SELECT sender, message, created_at FROM chat_messages WHERE client_uuid = ? ORDER BY id ASC");
        $stmt->execute([$uuid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // 3. ADMIN: VER LISTA DE CHATS ACTIVOS (Con contador de no leídos)
    elseif ($action === 'admin_list') {
        // Agrupar por cliente y contar no leídos
        $sql = "SELECT client_uuid, 
                       COUNT(CASE WHEN is_read = 0 AND sender = 'client' THEN 1 END) as unread,
                       MAX(created_at) as last_msg_time,
                       (SELECT message FROM chat_messages m2 WHERE m2.client_uuid = chat_messages.client_uuid ORDER BY id DESC LIMIT 1) as last_msg
                FROM chat_messages 
                GROUP BY client_uuid 
                ORDER BY last_msg_time DESC";
        $stmt = $pdo->query($sql);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // 4. ADMIN: OBTENER CONVERSACIÓN ESPECÍFICA Y MARCAR LEÍDO
    elseif ($action === 'admin_get_chat') {
        $uuid = $_GET['uuid'] ?? '';
        
        // Marcar como leídos los mensajes de este cliente
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE client_uuid = ? AND sender = 'client'")->execute([$uuid]);

        $stmt = $pdo->prepare("SELECT sender, message, created_at FROM chat_messages WHERE client_uuid = ? ORDER BY id ASC");
        $stmt->execute([$uuid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // 5. ADMIN: POLLING GLOBAL (Solo cuenta total de no leídos para el badge)
    elseif ($action === 'check_unread_global') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM chat_messages WHERE is_read = 0 AND sender = 'client'");
        echo json_encode(['total_unread' => $stmt->fetchColumn()]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>

