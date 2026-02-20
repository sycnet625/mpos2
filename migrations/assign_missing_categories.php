<?php
require_once __DIR__ . '/../db.php';

try {
    // 1. Obtener productos sin categoría
    $stmt = $pdo->query("SELECT codigo, nombre FROM productos WHERE categoria IS NULL OR categoria = ''");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Encontrados " . count($products) . " productos sin categoría.\n";

    if (count($products) === 0) {
        exit;
    }

    // Mapa de Palabras Clave -> Categoría
    $categoryMap = [
        'REFRESCO' => 'BEBIDAS',
        'JUGO' => 'BEBIDAS',
        'AGUA' => 'BEBIDAS',
        'CERVEZA' => 'CERVEZAS',
        'VINO' => 'BODEGON',
        'RON' => 'BODEGON',
        'WHISKY' => 'BODEGON',
        'VODKA' => 'BODEGON',
        'GINEBRA' => 'BODEGON',
        'TEQUILA' => 'BODEGON',
        'SOLOMO' => 'CARNICOS',
        'LOMO' => 'CARNICOS',
        'POLLO' => 'CARNICOS',
        'CARNE' => 'CARNICOS',
        'CHULETA' => 'CARNICOS',
        'COSTILLA' => 'CARNICOS',
        'QUESO' => 'INSUMOS',
        'JAMON' => 'INSUMOS',
        'SALSA' => 'INSUMOS',
        'PAN' => 'INSUMOS',
        'HARINA' => 'INSUMOS',
        'AZUCAR' => 'INSUMOS',
        'SAL' => 'INSUMOS',
        'ACEITE' => 'INSUMOS',
        'MANTEQUILLA' => 'INSUMOS',
        'HUEVO' => 'INSUMOS',
        'LECHE' => 'INSUMOS',
        'YOGURT' => 'INSUMOS',
        'HELADO' => 'CONGELADOS',
        'HIELO' => 'CONGELADOS',
        'CHOCOLATE' => 'CONFITURAS',
        'GALLETA' => 'CONFITURAS',
        'CARAMELO' => 'CONFITURAS',
        'CHICLE' => 'CONFITURAS',
        'GOLOSINA' => 'CONFITURAS',
        'POSTRE' => 'DULCES',
        'TORTA' => 'DULCES',
        'PASTEL' => 'DULCES',
        'PIE' => 'DULCES'
    ];

    $updated = 0;
    // Let's use 'codigo' for update based on previous file usage.
    $stmtUpdate = $pdo->prepare("UPDATE productos SET categoria = ? WHERE codigo = ?");

    foreach ($products as $prod) {
        $name = strtoupper($prod['nombre']);
        $assignedCat = 'VARIOS'; // Categoría por defecto

        foreach ($categoryMap as $keyword => $cat) {
            if (strpos($name, $keyword) !== false) {
                $assignedCat = $cat;
                break; 
            }
        }
        
        $stmtUpdate->execute([$assignedCat, $prod['codigo']]);
        $updated++;
        echo "Producto: {$prod['nombre']} ({$prod['codigo']}) -> Asignado a: $assignedCat\n";
    }

    echo "\nTotal actualizados: $updated\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
