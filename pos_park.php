<?php
// ARCHIVO: pos_park.php
header('Content-Type: application/json');
require_once __DIR__ . '/pos_security.php';
pos_security_bootstrap_session();
pos_security_require_auth();
pos_security_enforce_session(false);
require_once 'db.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if (in_array($action, ['park', 'retrieve'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pos_security_error('Se requiere POST.', 405);
    pos_security_require_csrf(is_array($input) ? $input : $_POST);
}

try {
    if ($action === 'park') {
        // Guardar cuenta
        $stmt = $pdo->prepare("INSERT INTO pedidos_espera (nombre_referencia, datos_json, cajero) VALUES (?, ?, ?)");
        $stmt->execute([$input['nombre'], json_encode($input['cart']), $input['cajero']]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'list') {
        // Listar cuentas
        $stmt = $pdo->query("SELECT id, nombre_referencia, fecha, cajero FROM pedidos_espera ORDER BY id DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } elseif ($action === 'retrieve') {
        // Recuperar y borrar de espera
        $id = (int)($input['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT datos_json FROM pedidos_espera WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $pdo->prepare("DELETE FROM pedidos_espera WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'cart' => json_decode($row['datos_json'])]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'No encontrado']);
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>
