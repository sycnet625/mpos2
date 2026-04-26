<?php
require_once 'db.php';
require_once 'config_loader.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT SUM(v.total) as total, COUNT(*) as count 
                           FROM ventas_cabecera v 
                           LEFT JOIN caja_sesiones s ON v.id_caja = s.id 
                           WHERE IFNULL(s.fecha_contable, DATE(v.fecha)) = CURDATE() AND v.total > 0");
    $stmt->execute();
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT v.id_cliente) as clients 
                            FROM ventas_cabecera v 
                            LEFT JOIN caja_sesiones s ON v.id_caja = s.id 
                            WHERE IFNULL(s.fecha_contable, DATE(v.fecha)) = CURDATE() AND v.total > 0");
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