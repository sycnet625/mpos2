<?php
require_once __DIR__ . '/../db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) UNIQUE NOT NULL,
        emoji VARCHAR(10),
        color VARCHAR(20)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "Tabla 'categorias' creada o ya existente.
";

    // Insertar categorías iniciales si la tabla está vacía
    $stmt = $pdo->query("SELECT COUNT(*) FROM categorias");
    if ($stmt->fetchColumn() == 0) {
        $initialCategories = [
            ['Hamburguesas', '🍔', '#FFF9C4'],
            ['Pizzas', '🍕', '#F8BBD0'],
            ['Ensaladas', '🥗', '#C8E6C9'],
            ['Sushi', '🍣', '#B3E5FC'],
            ['Tacos', '🌮', '#FFCCBC'],
            ['Sopas', '🍜', '#D1C4E9'],
            ['Carnes', '🥩', '#CFD8DC'],
            ['Pollo', '🍗', '#F0F4C3'],
            ['Sandwiches', '🥪', '#FFE0B2'],
            ['Desayunos', '🍳', '#E1BEE7'],
            ['Postres', '🍰', '#DCEDC8'],
            ['Helados', '🍦', '#FFECB3'],
            ['Donas', '🍩', '#D0F0C0'],
            ['Frutas', '🍎', '#E0F7FA'],
            ['Bebidas', '🥤', '#F5F5DC'],
            ['Café', '☕', '#FFF0F5'],
            ['Cervezas', '🍺', '#FAFAD2'],
            ['Cocteles', '🍹', '#E6E6FA'],
            ['Snacks', '🍿', '#FFF5EE'],
            ['Panadería', '🥨', '#F0FFFF']
        ];

        $stmtInsert = $pdo->prepare("INSERT INTO categorias (nombre, emoji, color) VALUES (?, ?, ?)");
        foreach ($initialCategories as $cat) {
            $stmtInsert->execute($cat);
        }
        echo "Categorías iniciales insertadas.
";
    }

} catch (PDOException $e) {
    echo "Error creando tabla: " . $e->getMessage() . "
";
}
?>
