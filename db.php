<?php
// ARCHIVO: /var/www/palweb/api/db.php

$host = '127.0.0.1'; // O la IP 172.17.0.3 si es docker
$db   = 'palweb_central';
$user = 'admin_web';
$pass = 'o2';
$port = '3306';
$charset = 'utf8mb4';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,   // falla rápido en vez de bloquear 30s
];

if (!function_exists('palweb_db_should_retry')) {
    function palweb_db_should_retry(\PDOException $e): bool
    {
        $msg = $e->getMessage();

        // Estos errores no mejoran probando otros DSN; insistir solo agrava la carga.
        foreach (['[1040]', '[1044]', '[1045]', '[1698]'] as $fatalCode) {
            if (strpos($msg, $fatalCode) !== false) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('palweb_db_emit_error')) {
    function palweb_db_emit_error(string $message): void
    {
        $format = defined('PALWEB_DB_ERROR_FORMAT') ? PALWEB_DB_ERROR_FORMAT : 'json';

        http_response_code(500);

        if ($format === 'html') {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error de Base de Datos</title></head><body style="font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;padding:2rem;"><div style="max-width:40rem;margin:4rem auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;box-shadow:0 10px 30px rgba(15,23,42,.08);"><h1 style="margin-top:0;font-size:1.4rem;">Base de datos no disponible</h1><p style="margin-bottom:0;line-height:1.5;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
            exit;
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["status" => "error", "message" => $message]);
        exit;
    }
}

try {
    // Priorizar socket Unix (sin overhead TCP, más rápido en localhost)
    $dsnCandidates = [];
    foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock'] as $socketPath) {
        if (is_readable($socketPath)) {
            $dsnCandidates[] = "mysql:unix_socket=$socketPath;dbname=$db;charset=$charset";
            break; // un socket es suficiente
        }
    }
    // Fallback TCP
    $dsnCandidates[] = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    $dsnCandidates[] = "mysql:host=localhost;port=$port;dbname=$db;charset=$charset";

    $pdo = null;
    $lastErr = null;
    foreach ($dsnCandidates as $dsn) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            break;
        } catch (\PDOException $inner) {
            $lastErr = $inner->getMessage();
            if (!palweb_db_should_retry($inner)) {
                break;
            }
        }
    }
    if (!$pdo) {
        throw new \PDOException($lastErr ?: 'No se pudo conectar a MySQL');
    }
// --- 🔥 CORRECCIÓN DE HORA (FIX TIMEZONE) 🔥 ---
    
    // 1. Ajustar la hora de PHP (Para que los logs salgan bien)
    date_default_timezone_set('America/Havana'); 

    // 2. Ajustar la hora de MySQL (Para que CURDATE() coincida contigo)
    // Usamos el offset '-05:00' que corresponde a Cuba/Este de EEUU.
    $pdo->exec("SET time_zone = '-05:00';");

} catch (\PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    palweb_db_emit_error("Error DB: " . $e->getMessage());
}
?>
