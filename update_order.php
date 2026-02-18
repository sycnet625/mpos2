<?php
// ARCHIVO: update_order.php
require_once 'db.php';
$input = json_decode(file_get_contents('php://input'), true);

if ($input) {
    try {
        $sql = "UPDATE pedidos_cabecera SET estado = :est, notas_admin = :not WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':est' => $input['estado'],
            ':not' => $input['nota'],
            ':id'  => $input['id']
        ]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
}
?>

