<?php
// MIGRACIÓN: add_payment_columns.php
// Agrega columnas de pago y stock a ventas_cabecera
// Idempotente — se puede ejecutar múltiples veces sin daño.

require_once __DIR__ . '/../db.php';

header('Content-Type: text/plain; charset=utf-8');

$results = [];

// Detectar motor DB
$isMariaDB = str_contains($pdo->query("SELECT VERSION()")->fetchColumn(), 'MariaDB');
$isMySQL8  = !$isMariaDB; // suficiente para nuestro caso

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME   = ?
                             AND COLUMN_NAME  = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$alterations = [
    'codigo_pago'    => "ALTER TABLE ventas_cabecera ADD COLUMN codigo_pago   VARCHAR(100) NULL          AFTER abono",
    'estado_pago'    => "ALTER TABLE ventas_cabecera ADD COLUMN estado_pago   VARCHAR(20)  DEFAULT 'pendiente' AFTER codigo_pago",
    'sin_existencia' => "ALTER TABLE ventas_cabecera ADD COLUMN sin_existencia TINYINT(1)  DEFAULT 0     AFTER estado_pago",
];

try {
    foreach ($alterations as $col => $sql) {
        if (columnExists($pdo, 'ventas_cabecera', $col)) {
            $results[] = "  ✓ Columna '{$col}' ya existe — omitida.";
        } else {
            $pdo->exec($sql);
            $results[] = "  + Columna '{$col}' AGREGADA correctamente.";
        }
    }
    echo "=== Migración add_payment_columns ===\n";
    echo implode("\n", $results) . "\n";
    echo "=== COMPLETADO sin errores ===\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
