<?php
// ARCHIVO: /var/www/palweb/api/reservas.php

// --- MODO DEBUG ACTIVADO (Para ver el error 500) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ---------------------------------------------------

require_once 'db.php';

// Configuración
$configFile = __DIR__ . '/pos.cfg';
$config = ["id_sucursal" => 1];
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) $config = array_merge($config, $loaded);
}
$sucursalID = intval($config['id_sucursal']);

// Acciones (Completar / Cancelar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json'); // Importante para respuestas AJAX
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['action']) && isset($data['id'])) {
            $newState = ($data['action'] === 'complete') ? 'ENTREGADO' : 'CANCELADO';
            $stmtUpd = $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva = ? WHERE id = ?");
            $stmtUpd->execute([$newState, $data['id']]);
            echo json_encode(['status' => 'success']);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

// Consultar Reservas Pendientes
$reservas = [];
$errorDB = "";

try {
    $sql = "SELECT v.*, 
            (SELECT COUNT(*) FROM ventas_detalle d WHERE d.id_venta_cabecera = v.id) as items_count
            FROM ventas_cabecera v 
            WHERE v.tipo_servicio = 'reserva' 
            AND v.id_sucursal = ? 
            AND (v.estado_reserva = 'PENDIENTE' OR v.estado_reserva IS NULL)
            ORDER BY v.fecha_reserva ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sucursalID]);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Si falla aquí, es probable que falten columnas en la BD
    $errorDB = "Error Crítico de Base de Datos: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Reservas</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .reserva-card { border: none; border-radius: 12px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 15px; border-left: 5px solid #6f42c1; transition: transform 0.2s; }
        .reserva-card:hover { transform: translateY(-3px); }
        .badge-date { font-size: 0.9rem; background: #e9ecef; color: #495057; padding: 5px 10px; border-radius: 20px; }
        .text-debt { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body class="p-4">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-dark"><i class="fas fa-calendar-check" style="color: #6f42c1;"></i> Reservas Pendientes</h3>
        <a href="pos.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Volver al POS</a>
    </div>

    <?php if($errorDB): ?>
        <div class="alert alert-danger shadow-sm">
            <h4><i class="fas fa-exclamation-triangle"></i> Error Técnico</h4>
            <p>La consulta a la base de datos falló. Probablemente falten las columnas nuevas.</p>
            <hr>
            <code><?php echo $errorDB; ?></code>
            <div class="mt-3">
                <strong>Solución:</strong> Ejecuta este comando en tu base de datos:<br>
                <pre class="bg-light p-2 mt-1 border">ALTER TABLE ventas_cabecera ADD COLUMN fecha_reserva DATETIME DEFAULT NULL;
ALTER TABLE ventas_cabecera ADD COLUMN abono DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE ventas_cabecera ADD COLUMN estado_reserva VARCHAR(20) DEFAULT 'PENDIENTE';
ALTER TABLE ventas_cabecera ADD COLUMN cliente_telefono VARCHAR(50) DEFAULT NULL;</pre>
            </div>
        </div>
    <?php endif; ?>

    <?php if(empty($reservas) && empty($errorDB)): ?>
        <div class="alert alert-info text-center py-5">
            <i class="fas fa-clipboard-check fa-3x mb-3"></i>
            <h4>No hay reservas pendientes</h4>
            <p>Todas las reservas han sido entregadas o no existen registros.</p>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php foreach($reservas as $r): 
            $fechaEntrega = !empty($r['fecha_reserva']) ? date('d/m/Y h:i A', strtotime($r['fecha_reserva'])) : 'Sin fecha';
            $abono = floatval($r['abono'] ?? 0);
            $total = floatval($r['total']);
            $deuda = $total - $abono;
            
            // Evitar división por cero
            $pctAbonado = ($total > 0) ? ($abono / $total) * 100 : 0;
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="reserva-card p-3">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($r['cliente_nombre'] ?? 'Cliente'); ?></h5>
                        <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($r['cliente_telefono'] ?? 'S/N'); ?></small>
                    </div>
                    <span class="badge bg-light text-dark border">#<?php echo $r['id']; ?></span>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span><i class="far fa-clock"></i> Entrega:</span>
                        <span class="fw-bold text-dark"><?php echo $fechaEntrega; ?></span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $pctAbonado; ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <div>
                            <small class="d-block text-muted">Total</small>
                            <span class="fw-bold">$<?php echo number_format($total, 2); ?></span>
                        </div>
                        <div>
                            <small class="d-block text-muted">Abonado</small>
                            <span class="text-success fw-bold">$<?php echo number_format($abono, 2); ?></span>
                        </div>
                        <div class="text-end">
                            <small class="d-block text-muted">Pendiente</small>
                            <span class="text-debt">$<?php echo number_format($deuda, 2); ?></span>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 d-flex">
                    <button class="btn btn-outline-danger btn-sm flex-fill" onclick="updateReserva(<?php echo $r['id']; ?>, 'cancel')">Cancelar</button>
                    <button class="btn btn-success btn-sm flex-fill fw-bold" onclick="updateReserva(<?php echo $r['id']; ?>, 'complete')">
                        <i class="fas fa-check"></i> Entregar
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
async function updateReserva(id, action) {
    const msg = action === 'complete' ? '¿Confirmar entrega y saldo cobrado?' : '¿Cancelar esta reserva?';
    if (!confirm(msg)) return;

    try {
        const res = await fetch('reservas.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: id, action: action })
        });
        
        // Verificamos si la respuesta es JSON válido
        if (!res.ok) throw new Error("Error en el servidor");
        
        const data = await res.json();
        if (data.status === 'success') {
            location.reload();
        } else {
            alert("Error: " + (data.msg || "Desconocido"));
        }
    } catch (e) {
        console.error(e);
        alert("Error de conexión o error PHP visible en consola.");
    }
}
</script>

<?php include_once 'menu_master.php'; ?>

</body>
</html>

