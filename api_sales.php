<?php
require_once 'db.php';
require_once 'config_loader.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT SUM(total) as total, COUNT(*) as count FROM ventas WHERE DATE(fecha) = CURDATE()");
    $stmt->execute();
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT id_cliente) as clients FROM ventas WHERE DATE(fecha) = CURDATE()");
    $stmt2->execute();
    $clients = $stmt2->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'total' => number_format($sales['total'] ?: 0, 2),
        'count' => $sales['count'] ?: 0,
        'clients' => $clients['clients'] ?: 0
    ]);
} catch (Exception $e) {
    echo json_encode(['total' => '0.00', 'count' => 0, 'clients' => 0]);
}
?>