<?php
// ARCHIVO: /var/www/palweb/api/dashboard.php

// 1. CONFIGURACIÓN Y CONEXIÓN
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once 'db.php';
    date_default_timezone_set('America/Havana');
    $pdo->exec("SET time_zone = '-05:00';");
} catch (Exception $e) {
    die("Error crítico de base de datos: " . $e->getMessage());
}

// 2. LÓGICA DE ESTADÍSTICAS (KPIs)
try {
    // A. Pedidos Pendientes
    $stmt = $pdo->query("SELECT COUNT(*) FROM pedidos_cabecera WHERE estado = 'pendiente'");
    $countPendientes = $stmt->fetchColumn();

    // B. Ventas de Hoy (Solo completados)
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM pedidos_cabecera WHERE DATE(fecha) = ? AND estado != 'cancelado'");
    $stmt->execute([$today]);
    $ventasHoy = $stmt->fetchColumn();

    // C. Productos Bajos de Stock (Menor al mínimo)
    $sqlLowStock = "SELECT COUNT(*) FROM productos p 
                    JOIN stock_almacen s ON p.id = s.id_producto 
                    WHERE s.id_almacen = 1 AND s.cantidad <= p.stock_minimo AND p.activo = 1";
    $countLowStock = $pdo->query($sqlLowStock)->fetchColumn();

    // D. Total de Ordenes Activas (No completadas ni canceladas)
    $stmt = $pdo->query("SELECT COUNT(*) FROM pedidos_cabecera WHERE estado NOT IN ('completado', 'cancelado')");
    $countActivas = $stmt->fetchColumn();

} catch (Exception $e) {
    $countPendientes = 0; $ventasHoy = 0; $countLowStock = 0; $countActivas = 0;
}

// 3. OBTENER LISTA DE PEDIDOS (Últimos 50)
try {
    $sqlOrders = "SELECT * FROM pedidos_cabecera ORDER BY 
                  CASE WHEN estado = 'pendiente' THEN 1 ELSE 2 END, 
                  fecha DESC LIMIT 50";
    $stmtOrders = $pdo->query($sqlOrders);
    $pedidos = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pedidos = [];
}

// Helper para badges de estado
function getStatusBadge($estado) {
    switch($estado) {
        case 'pendiente': return '<span class="badge bg-warning text-dark"><i class="fas fa-bell"></i> Nuevo</span>';
        case 'proceso':   return '<span class="badge bg-info text-dark"><i class="fas fa-fire"></i> Cocina</span>';
        case 'camino':    return '<span class="badge bg-primary"><i class="fas fa-motorcycle"></i> En Camino</span>';
        case 'completado':return '<span class="badge bg-success"><i class="fas fa-check"></i> Entregado</span>';
        case 'cancelado': return '<span class="badge bg-danger"><i class="fas fa-times"></i> Cancelado</span>';
        default:          return '<span class="badge bg-secondary">'.$estado.'</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin | PalWeb POS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">

    <style>
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; }
        .card-stat { border: none; border-radius: 12px; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .icon-stat { font-size: 2.5rem; opacity: 0.2; position: absolute; right: 20px; top: 20px; }
        
        .table-orders tbody tr { transition: background 0.2s; }
        .table-orders tbody tr:hover { background-color: #e9ecef; }
        
        /* Estilos para fecha programada */
        .scheduled-date { background-color: #e3f2fd; color: #0d6efd; padding: 4px 8px; border-radius: 6px; font-weight: 600; display: inline-block; margin-top: 4px; font-size: 0.85rem; }
        .urgent-date { background-color: #ffe0e0; color: #d63384; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top shadow">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-tachometer-alt me-2"></i>PalWeb Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="shop.php" target="_blank"><i class="fas fa-store"></i> Ver Tienda</a></li>
                <li class="nav-item"><a class="nav-link" href="products_table.php"><i class="fas fa-boxes"></i> Inventario</a></li>
                <li class="nav-item"><a class="nav-link text-danger" href="#"><i class="fas fa-power-off"></i> Salir</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid p-4">

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card card-stat bg-warning text-dark h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-uppercase fw-bold">Pendientes</h6>
                    <h2 class="display-6 fw-bold mb-0"><?php echo $countPendientes; ?></h2>
                    <i class="fas fa-bell icon-stat text-dark"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-primary text-white h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-uppercase fw-bold">En Proceso</h6>
                    <h2 class="display-6 fw-bold mb-0"><?php echo $countActivas; ?></h2>
                    <i class="fas fa-fire icon-stat text-white"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-success text-white h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-uppercase fw-bold">Ventas Hoy</h6>
                    <h2 class="display-6 fw-bold mb-0">$<?php echo number_format($ventasHoy, 0); ?></h2>
                    <i class="fas fa-dollar-sign icon-stat text-white"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-danger text-white h-100 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-uppercase fw-bold">Stock Bajo</h6>
                    <h2 class="display-6 fw-bold mb-0"><?php echo $countLowStock; ?></h2>
                    <i class="fas fa-exclamation-triangle icon-stat text-white"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="m-0 fw-bold text-dark"><i class="fas fa-list-ul me-2 text-primary"></i> Gestión de Pedidos</h5>
            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Actualizar</button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-orders align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">#ID</th>
                            <th>Cliente</th>
                            <th>Detalle Compra</th>
                            <th>Fechas / Reserva</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th class="text-end pe-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pedidos)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No hay pedidos registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach($pedidos as $row): 
                                // Obtener items de este pedido
                                $stmtDet = $pdo->prepare("SELECT d.cantidad, p.nombre FROM pedidos_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
                                $stmtDet->execute([$row['id']]);
                                $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
                                
                                // Formato items string
                                $itemsStr = "";
                                foreach($items as $it) {
                                    $itemsStr .= "<div><small><strong>" . (float)$it['cantidad'] . "x</strong> " . htmlspecialchars($it['nombre']) . "</small></div>";
                                }

                                // Lógica de Fecha Programada
                                $fechaCrea = strtotime($row['fecha']);
                                $fechaProg = $row['fecha_programada'] ? strtotime($row['fecha_programada']) : null;
                                
                                $isFuture = $fechaProg && $fechaProg > time();
                            ?>
                            <tr class="<?php echo ($row['estado'] == 'pendiente') ? 'table-warning' : ''; ?>">
                                <td class="ps-3 fw-bold">#<?php echo $row['id']; ?></td>
                                
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['cliente_nombre']); ?></div>
                                    <div class="small text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['cliente_direccion']); ?></div>
                                    <div class="small text-primary"><i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($row['cliente_telefono']); ?></div>
                                </td>

                                <td><?php echo $itemsStr; ?></td>

                                <td>
                                    <div class="small text-muted">Creado: <?php echo date('d/m H:i', $fechaCrea); ?></div>
                                    <?php if($fechaProg): ?>
                                        <div class="scheduled-date shadow-sm <?php echo !$isFuture ? 'urgent-date' : ''; ?>">
                                            <i class="far fa-calendar-alt"></i> 
                                            Reserva: <?php echo date('d/m h:i A', $fechaProg); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">Lo antes posible</span>
                                    <?php endif; ?>
                                </td>

                                <td class="fw-bold text-success fs-6">$<?php echo number_format($row['total'], 2); ?></td>

                                <td><?php echo getStatusBadge($row['estado']); ?></td>

                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-primary shadow-sm" 
                                            onclick="openManageModal(
                                                <?php echo $row['id']; ?>, 
                                                '<?php echo $row['estado']; ?>', 
                                                `<?php echo addslashes($row['notas_admin'] ?? ''); ?>`
                                            )">
                                        <i class="fas fa-edit"></i> Gestionar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="manageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-cog me-2"></i> Gestionar Pedido #<span id="modalOrderId"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="inputOrderId">
                
                <div class="mb-4">
                    <label class="form-label fw-bold">Estado del Pedido</label>
                    <select class="form-select form-select-lg" id="inputOrderState">
                        <option value="pendiente">🟡 Pendiente (Recibido)</option>
                        <option value="proceso">🔵 En Cocina / Preparación</option>
                        <option value="camino">🛵 En Camino / Listo</option>
                        <option value="completado">🟢 Completado (Entregado)</option>
                        <option value="cancelado">🔴 Cancelado</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Nota para el Cliente</label>
                    <div class="alert alert-info py-2 small"><i class="fas fa-info-circle"></i> Esta nota aparecerá cuando el cliente rastree su pedido.</div>
                    <textarea class="form-control" id="inputOrderNote" rows="3" placeholder="Ej: Tu pedido ya salió. El motorista es Juan."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold" onclick="saveChanges()">
                    <i class="fas fa-save me-2"></i> Guardar Cambios
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const manageModal = new bootstrap.Modal(document.getElementById('manageModal'));

    // Abrir Modal con datos cargados
    function openManageModal(id, estado, nota) {
        document.getElementById('inputOrderId').value = id;
        document.getElementById('modalOrderId').innerText = id;
        document.getElementById('inputOrderState').value = estado;
        document.getElementById('inputOrderNote').value = nota;
        manageModal.show();
    }

    // Guardar cambios vía AJAX
    async function saveChanges() {
        const id = document.getElementById('inputOrderId').value;
        const estado = document.getElementById('inputOrderState').value;
        const nota = document.getElementById('inputOrderNote').value;
        
        // Efecto visual en botón
        const btn = event.currentTarget;
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando...';

        try {
            const resp = await fetch('update_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, estado, nota })
            });
            
            const result = await resp.json();
            
            if(result.status === 'success') {
                location.reload(); // Recargar para ver los cambios
            } else {
                alert('Error al guardar: ' + (result.msg || 'Error desconocido'));
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        } catch (error) {
            alert('Error de conexión');
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    }

    // Auto-refresh opcional (cada 60 seg) para ver nuevos pedidos
    setInterval(() => {
        // Solo recarga si no hay modales abiertos para no interrumpir
        if(!document.querySelector('.modal.show')) {
            location.reload();
        }
    }, 60000);
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>


