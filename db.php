<?php
// ARCHIVO: /var/www/palweb/api/db.php

$host = '127.0.0.1'; // O la IP 172.17.0.3 si es docker
$db   = 'palweb_central';
$user = 'admin_web';
$pass = 'o2';
$port = '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
// --- 🔥 CORRECCIÓN DE HORA (FIX TIMEZONE) 🔥 ---
    
    // 1. Ajustar la hora de PHP (Para que los logs salgan bien)
    date_default_timezone_set('America/Havana'); 

    // 2. Ajustar la hora de MySQL (Para que CURDATE() coincida contigo)
    // Usamos el offset '-05:00' que corresponde a Cuba/Este de EEUU.
    $pdo->exec("SET time_zone = '-05:00';");

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error DB: " . $e->getMessage()]);
    exit;
}
?>
