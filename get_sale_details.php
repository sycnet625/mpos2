<?php
// ARCHIVO: /var/www/palweb/api/get_sale_details.php
require_once 'db.php';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($id > 0) {
    // Hacemos JOIN con productos para sacar el nombre actual
    $sql = "SELECT d.*, p.nombre, p.codigo 
            FROM ventas_detalle d 
            JOIN productos p ON d.id_producto = p.id 
            WHERE d.id_venta_cabecera = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $items = [];
}
?>

<?php if(empty($items)): ?>
    <div class="alert alert-warning">No hay productos en este ticket.</div>
<?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach($items as $item): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <div class="fw-bold">
                    <?php echo floatval($item['cantidad']); ?>x <?php echo htmlspecialchars($item['nombre']); ?>
                </div>
                <small class="text-muted">Cod: <?php echo $item['codigo']; ?></small>
                <?php if($item['descuento_pct'] > 0): ?>
                    <span class="badge bg-danger ms-2">-<?php echo floatval($item['descuento_pct']); ?>%</span>
                <?php endif; ?>
            </div>
            <span class="fw-bold">$<?php echo number_format($item['precio'] * $item['cantidad'], 2); ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

