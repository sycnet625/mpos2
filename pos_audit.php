<?php
// ARCHIVO: /var/www/palweb/api/pos_audit.php

if (!function_exists('log_audit')) {
    function log_audit($pdo, $accion, $usuario, $datos = []) {
        try {
            $stmt = $pdo->prepare("INSERT INTO auditoria_pos (accion, usuario, datos) VALUES (?, ?, ?)");
            $jsonDatos = json_encode($datos, JSON_UNESCAPED_UNICODE);
            
            // Si el JSON es muy largo, lo truncamos para no romper la BD (opcional)
            if (strlen($jsonDatos) > 60000) $jsonDatos = substr($jsonDatos, 0, 60000) . '... (truncated)';
            
            $stmt->execute([$accion, $usuario, $jsonDatos]);
        } catch (Exception $e) {
            // Fallo silencioso: No queremos detener una venta porque falló el log
            error_log("Audit Error: " . $e->getMessage());
        }
    }
}
?>

