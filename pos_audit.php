<?php
// ARCHIVO: pos_audit.php
// VERSIÓN: 2.0 — Audit trail inmutable: constantes tipadas, IP, checksum SHA1

// ── Constantes de Tipo de Acción ──────────────────────────────────────────────
// Toda anulación, descuento y devolución se firma con cajero + timestamp + hash.
// La tabla auditoria_pos NUNCA recibe UPDATE ni DELETE desde la aplicación.
define('AUDIT_VENTA_GUARDADA',     'VENTA_GUARDADA');
define('AUDIT_DESCUENTO_ITEM',     'DESCUENTO_ITEM');
define('AUDIT_DESCUENTO_GLOBAL',   'DESCUENTO_GLOBAL');
define('AUDIT_VENTA_ANULADA',      'VENTA_ANULADA');
define('AUDIT_DEVOLUCION_ITEM',    'DEVOLUCION_ITEM');
define('AUDIT_DEVOLUCION_TICKET',  'DEVOLUCION_TICKET');
define('AUDIT_SESION_ABIERTA',     'SESION_ABIERTA');
define('AUDIT_SESION_CERRADA',     'SESION_CERRADA');

// ── Inicialización de tabla (solo una vez por proceso) ────────────────────────
if (!function_exists('_audit_ensure_table')) {
    function _audit_ensure_table(PDO $pdo): void {
        // Crear tabla con esquema completo si no existe
        $pdo->exec("CREATE TABLE IF NOT EXISTS auditoria_pos (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            accion      VARCHAR(50)  NOT NULL,
            usuario     VARCHAR(100) NOT NULL,
            datos       TEXT,
            ip          VARCHAR(45)  NULL,
            checksum    CHAR(40)     NULL COMMENT 'SHA1 de accion|usuario|datos|ip — evidencia de integridad',
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accion     (accion),
            INDEX idx_usuario    (usuario),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Agregar columnas nuevas a tablas existentes (silencioso si ya existen)
        foreach (['ADD COLUMN ip VARCHAR(45) NULL', 'ADD COLUMN checksum CHAR(40) NULL'] as $alter) {
            try { $pdo->exec("ALTER TABLE auditoria_pos $alter"); } catch (PDOException $ignored) {}
        }
    }
}

// ── Función principal ──────────────────────────────────────────────────────────
if (!function_exists('log_audit')) {
    /**
     * Registra una acción en el audit trail inmutable.
     *
     * @param PDO    $pdo     Conexión activa (no debe estar dentro de transacción abierta)
     * @param string $accion  Una de las constantes AUDIT_*
     * @param string $usuario Nombre del cajero / admin que ejecutó la acción
     * @param array  $datos   Contexto estructurado (id_venta, motivo, importes, etc.)
     */
    function log_audit(PDO $pdo, string $accion, string $usuario, array $datos = []): void {
        static $tableReady = false;
        try {
            if (!$tableReady) {
                _audit_ensure_table($pdo);
                $tableReady = true;
            }

            $ip        = $_SERVER['REMOTE_ADDR'] ?? null;
            $jsonDatos = json_encode($datos, JSON_UNESCAPED_UNICODE);

            // Truncar si es demasiado largo
            if (strlen($jsonDatos) > 60000) {
                $jsonDatos = substr($jsonDatos, 0, 60000) . '...(truncated)';
            }

            // Checksum para detectar manipulación posterior del registro
            $checksum = sha1($accion . '|' . $usuario . '|' . $jsonDatos . '|' . ($ip ?? ''));

            $stmt = $pdo->prepare(
                "INSERT INTO auditoria_pos (accion, usuario, datos, ip, checksum) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$accion, $usuario, $jsonDatos, $ip, $checksum]);

        } catch (Throwable $e) {
            // Fallo silencioso: el audit nunca debe interrumpir una operación de caja
            error_log("Audit Error [{$accion}/{$usuario}]: " . $e->getMessage());
        }
    }
}
?>
