<?php
// ARCHIVO: track.php
ini_set('display_errors', 0);
require_once 'db.php';

$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$order = null;
$items = [];

if ($orderId > 0) {
    try {
        // Buscar Cabecera
        $stmt = $pdo->prepare("SELECT * FROM pedidos_cabecera WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            // Buscar Items
            $stmtDet = $pdo->prepare("SELECT d.*, p.nombre FROM pedidos_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
            $stmtDet->execute([$orderId]);
            $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { /* Error silencioso */ }
}

// Lógica de Barra de Progreso
$statusPercent = 10;
$statusClass = 'bg-primary';
$statusText = 'Recibido';

if ($order) {
    switch($order['estado']) {
        case 'pendiente':  $statusPercent = 25; $statusText='Recibido'; break;
        case 'proceso':    $statusPercent = 50; $statusText='En Preparación'; break;
        case 'camino':     $statusPercent = 75; $statusText='En Camino / Listo'; break;
        case 'completado': $statusPercent = 100; $statusText='Entregado'; $statusClass='bg-success'; break;
        case 'cancelado':  $statusPercent = 100; $statusText='Cancelado'; $statusClass='bg-danger'; break;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastreo de Pedido | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: sans-serif; }
        .track-card { max-width: 600px; margin: 0 auto; border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .status-icon { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; margin: 0 auto 10px; background: #e9ecef; color: #adb5bd; }
        .status-icon.active { background: #cfe2ff; color: #0d6efd; }
        .status-icon.completed { background: #d1e7dd; color: #198754; }
    </style>
</head>
<body>

<nav class="navbar navbar-light bg-white shadow-sm mb-5">
    <div class="container justify-content-center">
        <a class="navbar-brand fw-bold text-primary" href="shop.php"><i class="fas fa-store me-2"></i>PalWeb Shop</a>
    </div>
</nav>

<div class="container pb-5">
    
    <div class="text-center mb-5">
        <h2 class="fw-bold mb-3">Rastrea tu Pedido 🚚</h2>
        <form class="d-flex justify-content-center" method="GET">
            <div class="input-group" style="max-width: 400px;">
                <span class="input-group-text bg-white border-end-0">#</span>
                <input type="number" name="order_id" class="form-control border-start-0" placeholder="Número de Orden (ej: 123)" value="<?php echo $orderId ? $orderId : ''; ?>" required>
                <button class="btn btn-primary fw-bold px-4" type="submit">Buscar</button>
            </div>
        </form>
    </div>

    <?php if ($orderId > 0 && !$order): ?>
        <div class="alert alert-warning text-center track-card">
            <i class="fas fa-search me-2"></i> No encontramos el pedido #<?php echo $orderId; ?>. Verifica el número.
        </div>
    <?php elseif ($order): ?>

        <div class="card track-card">
            <div class="card-body p-4 p-md-5">
                
                <div class="text-center mb-4">
                    <h5 class="text-muted mb-1">Estado del Pedido #<?php echo $orderId; ?></h5>
                    <h2 class="fw-bold text-primary"><?php echo strtoupper($statusText); ?></h2>
                    <?php if($order['fecha_programada']): ?>
                        <div class="badge bg-warning text-dark mt-2 fs-6">
                            <i class="far fa-calendar-alt"></i> Programado: <?php echo date('d/m/Y h:i A', strtotime($order['fecha_programada'])); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="progress mb-4" style="height: 20px; border-radius: 10px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $statusClass; ?>" role="progressbar" style="width: <?php echo $statusPercent; ?>%"></div>
                </div>

                <?php if (!empty($order['notas_admin'])): ?>
                    <div class="alert alert-info border-0 d-flex">
                        <i class="fas fa-info-circle fs-4 me-3"></i>
                        <div>
                            <strong>Nota de la tienda:</strong><br>
                            <?php echo nl2br(htmlspecialchars($order['notas_admin'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <hr>

                <h5 class="fw-bold mt-4"><i class="fas fa-receipt text-muted me-2"></i>Resumen</h5>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach($items as $item): ?>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <div>
                                <span class="fw-bold"><?php echo $item['cantidad']; ?>x</span> <?php echo $item['nombre']; ?>
                            </div>
                            <span>$<?php echo number_format($item['precio_unitario'] * $item['cantidad'], 2); ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between px-0 bg-light mt-2 rounded p-2">
                        <span class="fw-bold">TOTAL</span>
                        <span class="fw-bold text-success fs-5">$<?php echo number_format($order['total'], 2); ?></span>
                    </li>
                </ul>

                <div class="row text-muted small mt-4">
                    <div class="col-6">
                        <strong>Cliente:</strong><br>
                        <?php echo htmlspecialchars($order['cliente_nombre']); ?>
                    </div>
                    <div class="col-6 text-end">
                        <strong>Dirección:</strong><br>
                        <?php echo htmlspecialchars($order['cliente_direccion']); ?>
                    </div>
                </div>

                <div class="text-center mt-5">
                    <a href="shop.php" class="btn btn-outline-secondary rounded-pill px-4">Volver a la Tienda</a>
                </div>

            </div>
        </div>
    <?php endif; ?>
</div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

