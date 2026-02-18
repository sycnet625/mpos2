<?php
// ARCHIVO: /var/www/palweb/api/pos_health.php
header('Content-Type: application/json');
ini_set('display_errors', 0);

$start = microtime(true);
$status = ['status' => 'ok', 'services' => []];

// 1. Verificar DB
try {
    require_once 'db.php';
    $pdo->query("SELECT 1");
    $status['services']['db'] = 'OK';
} catch (Exception $e) {
    $status['status'] = 'error';
    $status['services']['db'] = 'FAIL: ' . $e->getMessage();
    http_response_code(500);
}

// 2. Verificar Espacio en Disco (Opcional)
$freeSpace = disk_free_space(".");
$status['services']['disk_free_mb'] = round($freeSpace / 1024 / 1024, 2);

// 3. Uptime del Servidor (Linux)
$uptime = @shell_exec('uptime -p');
$status['server']['uptime'] = $uptime ? trim($uptime) : 'N/A';
$status['server']['version'] = 'POS v2.2.0';

$status['response_time_ms'] = round((microtime(true) - $start) * 1000, 2);

echo json_encode($status);
?>

