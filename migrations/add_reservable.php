<?php
// MIGRACIÓN: add_reservable.php
// Agrega columna es_reservable a productos (idempotente)

require_once __DIR__ . '/../db.php';
header('Content-Type: text/plain; charset=utf-8');

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME   = ?
                             AND COLUMN_NAME  = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if (columnExists($pdo, 'productos', 'es_reservable')) {
        echo "  ✓ Columna 'es_reservable' ya existe — omitida.\n";
    } else {
        $pdo->exec("ALTER TABLE productos ADD COLUMN es_reservable TINYINT(1) NOT NULL DEFAULT 0 AFTER es_web");
        echo "  + Columna 'es_reservable' AGREGADA correctamente.\n";
    }
    echo "=== COMPLETADO ===\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
