<?php
require_once __DIR__ . '/../db.php';

// Mapa de emojis y colores sugeridos
$categoryMap = [
    'BEBIDAS' => ['emoji' => '🥤', 'color' => '#B3E5FC'],
    'BEBIDAS_A' => ['emoji' => '🍸', 'color' => '#E1BEE7'],
    'BODEGON' => ['emoji' => '🍷', 'color' => '#D1C4E9'],
    'CARNICOS' => ['emoji' => '🥩', 'color' => '#FFCCBC'],
    'CERVEZAS' => ['emoji' => '🍺', 'color' => '#FFF9C4'],
    'CONFITURAS' => ['emoji' => '🍬', 'color' => '#F8BBD0'],
    'CONGELADOS' => ['emoji' => '🧊', 'color' => '#E0F7FA'],
    'DULCES' => ['emoji' => '🍭', 'color' => '#FFECB3'],
    'INSUMOS' => ['emoji' => '📦', 'color' => '#CFD8DC'],
    'Pruebas' => ['emoji' => '🧪', 'color' => '#F5F5DC'],
    // Default fallback
    'DEFAULT' => ['emoji' => '🏷️', 'color' => '#FFFFFF']
];

try {
    // 1. Obtener categorías existentes en productos
    $stmt = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria != ''");
    $existingCats = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "Encontradas " . count($existingCats) . " categorías en productos.
";

    $inserted = 0;
    $skipped = 0;

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE nombre = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO categorias (nombre, emoji, color) VALUES (?, ?, ?)");

    foreach ($existingCats as $catName) {
        // Verificar si ya existe en la nueva tabla
        $stmtCheck->execute([$catName]);
        if ($stmtCheck->fetchColumn() > 0) {
            echo "Skipping: $catName (ya existe)
";
            $skipped++;
            continue;
        }

        // Determinar emoji y color
        $map = $categoryMap[$catName] ?? $categoryMap['DEFAULT'];
        
        // Intentar buscar coincidencias parciales si no es exacto
        if (!isset($categoryMap[$catName])) {
            foreach ($categoryMap as $key => $val) {
                if (stripos($catName, $key) !== false) {
                    $map = $val;
                    break;
                }
            }
        }

        $stmtInsert->execute([$catName, $map['emoji'], $map['color']]);
        echo "Inserted: $catName [{$map['emoji']}]
";
        $inserted++;
    }

    echo "
Resumen:
";
    echo "Insertadas: $inserted
";
    echo "Omitidas: $skipped
";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "
";
}
?>
