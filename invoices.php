<?php
// ARCHIVO: invoices.php
// DESCRIPCIÓN: Dashboard CRUD de Facturación (Master/Slave, KPIs, Filtros)
require_once 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// --- LOGICA DE ACTUALIZACIÓN DE ESTADO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $id = intval($_POST['id']);
        $estado = $_POST['estado']; // ACTIVA / ANULADA
        $pago = $_POST['estado_pago']; // PENDIENTE / PAGADA
        $metodo = $_POST['metodo_pago'];
        
        $fechaPago = ($pago === 'PAGADA') ? date('Y-m-d H:i:s') : NULL;
        
        $stmt = $pdo->prepare("UPDATE facturas SET estado = ?, estado_pago = ?, metodo_pago = ?, fecha_pago = ? WHERE id = ?");
        $stmt->execute([$estado, $pago, $metodo, $fechaPago, $id]);
        
        header("Location: invoices.php?msg=updated");
        exit;
    }
}

// --- FILTROS ---
$start = $_GET['start'] ?? date('Y-m-01'); // Inicio de mes por defecto
$end = $_GET['end'] ?? date('Y-m-d');
$clientFilter = $_GET['cliente'] ?? '';
$statusFilter = $_GET['status'] ?? 'all'; // all, pending, paid, cancelled

// Construcción de Query
$sql = "SELECT * FROM facturas WHERE DATE(fecha_emision) BETWEEN ? AND ?";
$params = [$start, $end];

if ($clientFilter) {
    $sql .= " AND cliente_nombre LIKE ?";
    $params[] = "%$clientFilter%";
}

if ($statusFilter === 'pending') {
    $sql .= " AND estado = 'ACTIVA' AND estado_pago = 'PENDIENTE'";
} elseif ($statusFilter === 'paid') {
    $sql .= " AND estado = 'ACTIVA' AND estado_pago = 'PAGADA'";
} elseif ($statusFilter === 'cancelled') {
    $sql .= " AND estado = 'ANULADA'";
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CÁLCULO DE KPIs ---
$kpiTotal = 0;
$kpiPendiente = 0;
$kpiPagado = 0;
$kpiAnulado = 0;

foreach ($facturas as $f) {
    if ($f['estado'] === 'ANULADA') {
        $kpiAnulado += $f['total'];
    } else {
        $kpiTotal += $f['total'];
        if ($f['estado_pago'] === 'PAGADA') {
            $kpiPagado += $f['total'];
        } else {
            $kpiPendiente += $f['total'];
        }
    }
}

// Próximo consecutivo lógico
$stmtNext = $pdo->query("SELECT MAX(numero_factura) FROM facturas");
$lastNum = $stmtNext->fetchColumn();
$nextSuggested = $lastNum ? ($lastNum + 1) : date('Ymd')."001";
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Facturas - PalWeb ERP</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .kpi-card { border: none; border-radius: 12px; transition: transform 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .kpi-card:hover { transform: translateY(-3px); }
        .table-card { border-radius: 12px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .status-badge { font-size: 0.8em; padding: 5px 10px; border-radius: 20px; }
        .filters-bar { background: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
    </style>
</head>
<body class="p-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-file-invoice-dollar text-primary"></i> Facturación</h3>
            <p class="text-muted mb-0">Gestión de Cobros y Documentos</p>
        </div>
        <div>
            <button class="btn btn-outline-dark me-2" data-bs-toggle="modal" data-bs-target="#configModal">
                <i class="fas fa-cog"></i> Configurar
            </button>
            <a href="pos.php" class="btn btn-primary">Ir al POS</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card bg-primary text-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1">Total Facturado</h6>
                        <h3 class="fw-bold mb-0">$<?php echo number_format($kpiTotal, 2); ?></h3>
                    </div>
                    <i class="fas fa-chart-line fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-success text-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1">Cobrado (Pagado)</h6>
                        <h3 class="fw-bold mb-0">$<?php echo number_format($kpiPagado, 2); ?></h3>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-warning text-dark p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted text-uppercase mb-1">Pendiente de Cobro</h6>
                        <h3 class="fw-bold mb-0">$<?php echo number_format($kpiPendiente, 2); ?></h3>
                    </div>
                    <i class="fas fa-clock fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card bg-danger text-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-white-50 text-uppercase mb-1">Anulado / Cancelado</h6>
                        <h3 class="fw-bold mb-0">$<?php echo number_format($kpiAnulado, 2); ?></h3>
                    </div>
                    <i class="fas fa-ban fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <form class="filters-bar d-flex gap-3 align-items-end flex-wrap">
        <div class="flex-grow-1">
            <label class="form-label small fw-bold">Buscar Cliente</label>
            <input type="text" name="cliente" class="form-control" placeholder="Nombre..." value="<?php echo htmlspecialchars($clientFilter); ?>">
        </div>
        <div>
            <label class="form-label small fw-bold">Estado</label>
            <select name="status" class="form-select">
                <option value="all" <?php if($statusFilter=='all') echo 'selected'; ?>>Todos</option>
                <option value="pending" <?php if($statusFilter=='pending') echo 'selected'; ?>>Pendientes de Pago</option>
                <option value="paid" <?php if($statusFilter=='paid') echo 'selected'; ?>>Pagadas</option>
                <option value="cancelled" <?php if($statusFilter=='cancelled') echo 'selected'; ?>>Anuladas</option>
            </select>
        </div>
        <div>
            <label class="form-label small fw-bold">Desde</label>
            <input type="date" name="start" class="form-control" value="<?php echo $start; ?>">
        </div>
        <div>
            <label class="form-label small fw-bold">Hasta</label>
            <input type="date" name="end" class="form-control" value="<?php echo $end; ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
        <?php if($clientFilter || $statusFilter != 'all'): ?>
            <a href="invoices.php" class="btn btn-outline-secondary">Limpiar</a>
        <?php endif; ?>
    </form>

    <div class="card table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4"># Factura</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Mensajero</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Pago</th>
                            <th class="text-end">Total</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($facturas)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No se encontraron facturas en este periodo.</td></tr>
                        <?php else: foreach($facturas as $f): 
                            $bgStatus = ($f['estado'] === 'ANULADA') ? 'bg-secondary' : 'bg-success';
                            $bgPago = ($f['estado_pago'] === 'PAGADA') ? 'bg-primary' : 'bg-warning text-dark';
                        ?>
                        <tr class="<?php echo ($f['estado'] === 'ANULADA') ? 'opacity-50' : ''; ?>">
                            <td class="ps-4 fw-bold text-primary"><?php echo $f['numero_factura']; ?></td>
                            <td>
                                <div><?php echo date('d/m/Y', strtotime($f['fecha_emision'])); ?></div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($f['fecha_emision'])); ?></small>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($f['cliente_nombre']); ?></div>
                                <small class="text-muted"><i class="fas fa-phone-alt ms-1"></i> <?php echo $f['cliente_telefono']; ?></small>
                            </td>
                            <td><?php echo $f['mensajero_nombre']; ?><br><small class="text-muted"><?php echo $f['vehiculo']; ?></small></td>
                            
                            <td class="text-center"><span class="badge <?php echo $bgStatus; ?>"><?php echo $f['estado']; ?></span></td>
                            
                            <td class="text-center">
                                <?php if($f['estado'] === 'ACTIVA'): ?>
                                    <span class="badge <?php echo $bgPago; ?>"><?php echo $f['estado_pago']; ?></span>
                                    <?php if($f['metodo_pago']): ?>
                                        <div style="font-size: 0.75rem;" class="text-muted mt-1"><?php echo $f['metodo_pago']; ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end fw-bold fs-5">$<?php echo number_format($f['total'], 2); ?></td>
                            
                            <td class="text-end pe-4">
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-primary" title="Ver / Imprimir" onclick="printInvoice(<?php echo $f['id']; ?>)">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" title="Editar Estado" 
                                            onclick='openEditModal(<?php echo json_encode($f); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" id="modalId">
                    
                    <div class="modal-header">
                        <h5 class="modal-title">Administrar Factura <span id="modalFacturaNum" class="text-primary fw-bold"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Estado del Documento</label>
                            <select name="estado" class="form-select" id="modalEstado">
                                <option value="ACTIVA">✅ ACTIVA (Válida)</option>
                                <option value="ANULADA">🚫 ANULADA (Cancelada)</option>
                            </select>
                            <div class="form-text text-danger small">Anular la factura la excluirá de los reportes de ingresos.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Estado del Cobro</label>
                            <select name="estado_pago" class="form-select" id="modalPago" onchange="toggleMetodoPago()">
                                <option value="PENDIENTE">⏳ Pendiente de Pago</option>
                                <option value="PAGADA">💰 Pagada / Cobrada</option>
                            </select>
                        </div>

                        <div class="mb-3" id="divMetodo">
                            <label class="form-label fw-bold">Método de Pago</label>
                            <select name="metodo_pago" class="form-select" id="modalMetodo">
                                <option value="">- Seleccione -</option>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Transferencia">Transferencia</option>
                                <option value="En Linea">Pasarela Web</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h6 class="modal-title">Configuración</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">El sistema detecta automáticamente el último número.</p>
                    <label class="form-label small fw-bold">Próximo Sugerido:</label>
                    <input type="text" class="form-control text-center fw-bold" value="<?php echo $nextSuggested; ?>" readonly>
                    <div class="alert alert-info mt-2 p-2 small">Para cambiar la numeración, cree una factura manual con el número deseado en el POS.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function printInvoice(id) {
            window.open('invoice_print.php?id=' + id, 'Factura', 'width=900,height=800');
        }

        function openEditModal(data) {
            const modalEl = document.getElementById('editModal');
            const modal = new bootstrap.Modal(modalEl);
            
            document.getElementById('modalId').value = data.id;
            document.getElementById('modalFacturaNum').innerText = data.numero_factura;
            document.getElementById('modalEstado').value = data.estado;
            document.getElementById('modalPago').value = data.estado_pago;
            document.getElementById('modalMetodo').value = data.metodo_pago || '';
            
            toggleMetodoPago();
            modal.show();
        }

        function toggleMetodoPago() {
            const pagoState = document.getElementById('modalPago').value;
            const divMetodo = document.getElementById('divMetodo');
            if (pagoState === 'PAGADA') {
                divMetodo.style.display = 'block';
                document.getElementById('modalMetodo').setAttribute('required', 'required');
            } else {
                divMetodo.style.display = 'none';
                document.getElementById('modalMetodo').removeAttribute('required');
            }
        }
    </script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

