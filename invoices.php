<?php
// ARCHIVO: invoices.php
// DESCRIPCIÓN: Dashboard CRUD de Facturación y Ofertas Comerciales
require_once 'db.php';
require_once 'config_loader.php';
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
    // Acción masiva
    if ($_POST['action'] === 'bulk_action' && !empty($_POST['ids'])) {
        $ids = array_map('intval', (array)$_POST['ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bulkAct = $_POST['bulk_act'] ?? '';
        if ($bulkAct === 'mark_paid') {
            $pdo->prepare("UPDATE facturas SET estado_pago='PAGADA', metodo_pago='Efectivo', fecha_pago=NOW() WHERE id IN ($placeholders)")
                ->execute($ids);
        } elseif ($bulkAct === 'cancel') {
            $pdo->prepare("UPDATE facturas SET estado='ANULADA' WHERE id IN ($placeholders)")->execute($ids);
        }
        header("Location: invoices.php?tab=facturas&msg=bulk_ok");
        exit;
    }
}

// --- FILTROS ---
$tab = $_GET['tab'] ?? 'facturas'; // facturas o ofertas
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$clientFilter = $_GET['cliente'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';

// 1. Obtener Facturas
$sqlF = "SELECT * FROM facturas WHERE DATE(fecha_emision) BETWEEN ? AND ?";
$paramsF = [$start, $end];
if ($clientFilter) { $sqlF .= " AND cliente_nombre LIKE ?"; $paramsF[] = "%$clientFilter%"; }
if ($statusFilter === 'pending') { $sqlF .= " AND estado = 'ACTIVA' AND estado_pago = 'PENDIENTE'"; }
elseif ($statusFilter === 'paid') { $sqlF .= " AND estado = 'ACTIVA' AND estado_pago = 'PAGADA'"; }
elseif ($statusFilter === 'cancelled') { $sqlF .= " AND estado = 'ANULADA'"; }
$sqlF .= " ORDER BY id DESC";
$stmtF = $pdo->prepare($sqlF); $stmtF->execute($paramsF);
$facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);

// 2. Obtener Ofertas
$sqlO = "SELECT * FROM ofertas WHERE DATE(fecha_emision) BETWEEN ? AND ?";
$paramsO = [$start, $end];
if ($clientFilter) { $sqlO .= " AND cliente_nombre LIKE ?"; $paramsO[] = "%$clientFilter%"; }
$sqlO .= " ORDER BY id DESC";
$stmtO = $pdo->prepare($sqlO); $stmtO->execute($paramsO);
$ofertas = $stmtO->fetchAll(PDO::FETCH_ASSOC);

// --- CÁLCULO DE KPIs (Facturas) ---
$kpiTotal = 0; $kpiPendiente = 0; $kpiPagado = 0; $kpiAnulado = 0;
foreach ($facturas as $f) {
    if ($f['estado'] === 'ANULADA') { $kpiAnulado += $f['total']; }
    else {
        $kpiTotal += $f['total'];
        if ($f['estado_pago'] === 'PAGADA') { $kpiPagado += $f['total']; }
        else { $kpiPendiente += $f['total']; }
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión de Documentos - <?= htmlspecialchars(config_loader_system_name()) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .summary-total { font-size: 1.8rem; line-height: 1.2; font-weight: 800; }
        .inventory-hero { background: linear-gradient(135deg, <?php echo $config['hero_color_1'] ?? '#0f766e'; ?>ee, <?php echo $config['hero_color_2'] ?? '#1e293b'; ?>c6) !important; }
        .nav-tabs-inventory { border-bottom: none; gap: 10px; }
        .nav-tabs-inventory .nav-link { border: 1px solid var(--pw-line); border-radius: 15px; color: var(--pw-muted); padding: 10px 25px; font-weight: 600; transition: all 0.2s; }
        .nav-tabs-inventory .nav-link.active { background: var(--pw-accent); color: white; border-color: var(--pw-accent); box-shadow: 0 4px 12px rgba(15,118,110,0.2); }
    </style>
</head>
<body class="pb-5 inventory-suite">
    <div class="container-fluid shell inventory-shell py-4 py-lg-5">
        
        <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start w-100">
                <div>
                    <div class="section-title text-white-50 mb-2">Administración / Documentos</div>
                    <h1 class="h2 fw-bold mb-2"><i class="fas fa-file-invoice-dollar me-2"></i>Gestión de Facturas y Ofertas</h1>
                    <p class="mb-3 text-white-50">Control de cobros y emisión de ofertas comerciales transformables en facturas.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="kpi-chip"><i class="fas fa-file-invoice me-1"></i><?= count($facturas) ?> Facturas</span>
                        <span class="kpi-chip"><i class="fas fa-file-signature me-1"></i><?= count($ofertas) ?> Ofertas</span>
                        <span class="kpi-chip"><i class="fas fa-calendar-day me-1"></i><?= date('d/m/Y', strtotime($start)) ?> - <?= date('d/m/Y', strtotime($end)) ?></span>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="offers_editor.php?mode=offer" class="btn btn-success fw-bold"><i class="fas fa-plus me-1"></i>Nueva Oferta</a>
                    <a href="offers_editor.php?mode=invoice" class="btn btn-light fw-bold"><i class="fas fa-file-invoice me-1"></i>Factura Manual</a>
                    <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#configModal"><i class="fas fa-cog"></i></button>
                    <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left"></i></a>
                </div>
            </div>
        </section>

        <!-- KPIs SOLO PARA FACTURAS -->
        <?php if($tab === 'facturas'): ?>
        <div class="row g-4 mb-4 inventory-fade-in">
            <div class="col-md-3"><div class="glass-card p-3 border-start border-4 border-primary"><div class="section-title text-primary mb-1">Total Facturado</div><div class="summary-total text-primary">$<?= number_format($kpiTotal, 2); ?></div></div></div>
            <div class="col-md-3"><div class="glass-card p-3 border-start border-4 border-success"><div class="section-title text-success mb-1">Cobrado</div><div class="summary-total text-success">$<?= number_format($kpiPagado, 2); ?></div></div></div>
            <div class="col-md-3"><div class="glass-card p-3 border-start border-4 border-warning"><div class="section-title text-warning mb-1">Pendiente</div><div class="summary-total text-warning" style="color:#9a6700;">$<?= number_format($kpiPendiente, 2); ?></div></div></div>
            <div class="col-md-3"><div class="glass-card p-3 border-start border-4 border-danger"><div class="section-title text-danger mb-1">Anulado</div><div class="summary-total text-danger">$<?= number_format($kpiAnulado, 2); ?></div></div></div>
        </div>
        <?php endif; ?>

        <!-- TABS -->
        <ul class="nav nav-tabs nav-tabs-inventory mb-4">
            <li class="nav-item"><a class="nav-link <?= $tab === 'facturas' ? 'active' : '' ?>" href="?tab=facturas&start=<?= $start ?>&end=<?= $end ?>">Facturas Emitidas</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'ofertas' ? 'active' : '' ?>" href="?tab=ofertas&start=<?= $start ?>&end=<?= $end ?>">Ofertas Comerciales</a></li>
        </ul>

        <section class="glass-card p-4 mb-4">
            <form class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <div class="col-md-4"><label class="section-title mb-2 d-block">Buscar Cliente</label><input type="text" name="cliente" class="form-control" placeholder="Nombre..." value="<?= htmlspecialchars($clientFilter) ?>"></div>
                <?php if($tab === 'facturas'): ?>
                <div class="col-md-2"><label class="section-title mb-2 d-block">Estado</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $statusFilter=='all'?'selected':'' ?>>Todos</option>
                        <option value="pending" <?= $statusFilter=='pending'?'selected':'' ?>>Pendientes</option>
                        <option value="paid" <?= $statusFilter=='paid'?'selected':'' ?>>Pagadas</option>
                        <option value="cancelled" <?= $statusFilter=='cancelled'?'selected':'' ?>>Anuladas</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2"><label class="section-title mb-2 d-block">Desde</label><input type="date" name="start" class="form-control" value="<?= $start ?>"></div>
                <div class="col-md-2"><label class="section-title mb-2 d-block">Hasta</label><input type="date" name="end" class="form-control" value="<?= $end ?>"></div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 fw-bold">Filtrar</button></div>
            </form>
        </section>

        <?php if($tab === 'facturas'): ?>
        <!-- Barra acciones masivas -->
        <form method="POST" id="bulkForm">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="tab" value="facturas">
            <div id="bulkBar" class="glass-card p-3 mb-3 d-none">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="fw-bold text-primary"><i class="fas fa-check-square me-1"></i><span id="selCount">0</span> seleccionadas</span>
                    <button type="submit" name="bulk_act" value="mark_paid" class="btn btn-success btn-sm fw-bold" onclick="return confirm('¿Marcar como PAGADAS?')"><i class="fas fa-check me-1"></i>Marcar Pagadas</button>
                    <button type="submit" name="bulk_act" value="cancel" class="btn btn-danger btn-sm fw-bold" onclick="return confirm('¿ANULAR las facturas seleccionadas?')"><i class="fas fa-ban me-1"></i>Anular</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">Limpiar</button>
                </div>
            </div>
        <?php endif; ?>

        <section class="glass-card mb-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <?php if($tab === 'facturas'): ?>
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" width="36"><input type="checkbox" id="chkAll" class="form-check-input" onclick="toggleAll(this)"></th>
                                <th># Factura</th><th>Fecha</th><th>Cliente</th><th class="text-center">Estado</th><th class="text-center">Pago</th><th class="text-end">Total</th><th class="text-center pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($facturas as $f):
                                $statusClass = ($f['estado'] === 'ANULADA') ? 'bg-secondary' : 'bg-success';
                                $pagoClass = ($f['estado_pago'] === 'PAGADA') ? 'bg-primary' : 'bg-warning text-dark';
                                $waPhone = preg_replace('/[^0-9]/', '', $f['cliente_telefono'] ?? '');
                                $waMsg   = urlencode('Hola, le enviamos su factura #'.$f['numero_factura'].'.');
                            ?>
                            <tr class="<?= $f['estado'] === 'ANULADA' ? 'opacity-50' : '' ?>">
                                <td class="ps-3"><input type="checkbox" name="ids[]" value="<?= $f['id'] ?>" class="form-check-input row-chk" form="bulkForm" onchange="updateBulkBar()"></td>
                                <td class="fw-bold text-primary">#<?= $f['numero_factura'] ?></td>
                                <td><div><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div><div class="tiny text-muted"><?= date('H:i', strtotime($f['fecha_emision'])) ?></div></td>
                                <td><div class="fw-bold"><?= htmlspecialchars($f['cliente_nombre']) ?></div><div class="tiny text-muted"><?= $f['cliente_telefono'] ?></div></td>
                                <td class="text-center"><span class="badge <?= $statusClass ?> rounded-pill px-3"><?= $f['estado'] ?></span></td>
                                <td class="text-center"><span class="badge <?= $pagoClass ?> rounded-pill px-3"><?= $f['estado_pago'] ?></span></td>
                                <td class="text-end fw-bold fs-5">$<?= number_format($f['total'], 2) ?></td>
                                <td class="text-center pe-4">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" onclick="printInvoice(<?= $f['id'] ?>)" title="Imprimir"><i class="fas fa-print"></i></button>
                                        <a href="offers_editor.php?mode=invoice&id=<?= $f['id'] ?>" class="btn btn-outline-info" title="Editar contenido"><i class="fas fa-pen-to-square"></i></a>
                                        <button class="btn btn-outline-primary" onclick='openEditModal(<?= json_encode($f) ?>)' title="Cambiar estado/pago"><i class="fas fa-sliders-h"></i></button>
                                        <?php if($waPhone): ?><a href="https://wa.me/<?= $waPhone ?>?text=<?= $waMsg ?>" target="_blank" class="btn btn-outline-success" title="WhatsApp"><i class="fab fa-whatsapp"></i></a><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <thead class="table-light">
                            <tr><th class="ps-4"># Oferta</th><th>Fecha</th><th>Cliente</th><th class="text-center">Estado</th><th class="text-end">Total</th><th class="text-center pe-4">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($ofertas as $o): 
                                $stClass = match($o['estado']){ 'PENDIENTE'=>'bg-warning text-dark', 'APROBADA'=>'bg-success', 'FACTURADA'=>'bg-primary', default=>'bg-secondary' };
                            ?>
                            <tr>
                                <td class="ps-4 fw-bold text-primary"><?= $o['numero_oferta'] ?></td>
                                <td><?= date('d/m/Y', strtotime($o['fecha_emision'])) ?></td>
                                <td><div class="fw-bold"><?= htmlspecialchars($o['cliente_nombre']) ?></div><div class="tiny text-muted"><?= $o['cliente_telefono'] ?></div></td>
                                <td class="text-center"><span class="badge <?= $stClass ?> rounded-pill px-3"><?= $o['estado'] ?></span></td>
                                <td class="text-end fw-bold fs-5">$<?= number_format($o['total'], 2) ?></td>
                                <td class="text-center pe-4">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-secondary" onclick="printOffer(<?= $o['id'] ?>)" title="Imprimir Oferta"><i class="fas fa-print"></i></button>
                                        
                                        <?php if($o['estado'] === 'PENDIENTE'): ?>
                                            <a href="offers_api.php?action=update_offer_status&status=APROBADA&id=<?= $o['id'] ?>" class="btn btn-outline-success" title="Aprobar"><i class="fas fa-check"></i></a>
                                            <a href="offers_api.php?action=update_offer_status&status=RECHAZADA&id=<?= $o['id'] ?>" class="btn btn-outline-warning" title="Rechazar"><i class="fas fa-times"></i></a>
                                        <?php endif; ?>

                                        <a href="offers_editor.php?id=<?= $o['id'] ?>&mode=offer" class="btn btn-outline-primary" title="Editar Oferta"><i class="fas fa-edit"></i></a>
                                        <?php if($o['estado'] !== 'FACTURADA'): ?>
                                            <a href="offers_editor.php?id=<?= $o['id'] ?>&mode=invoice&convert=1" class="btn btn-success" title="Convertir a Factura"><i class="fas fa-file-invoice"></i></a>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary" onclick="printInvoice(<?= $o['id_factura_generada'] ?>)" title="Ver Factura"><i class="fas fa-eye"></i></button>
                                        <?php endif; ?>
                                        <a href="offers_api.php?action=delete_offer&id=<?= $o['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('¿Eliminar oferta?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </section>
        <?php if($tab === 'facturas'): ?></form><?php endif; ?>
    </div>

    <!-- Modal Editar Factura -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius:20px; overflow:hidden">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="id" id="modalId">
                    <div class="modal-header border-0 bg-primary text-white p-4">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Factura <span id="modalFacturaNum"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="section-title mb-2 d-block text-primary">Estado del Documento</label>
                            <select name="estado" class="form-select border-2" id="modalEstado">
                                <option value="ACTIVA">✅ ACTIVA (Válida)</option>
                                <option value="ANULADA">🚫 ANULADA (Cancelada)</option>
                            </select>
                            <div class="tiny text-danger mt-2"><i class="fas fa-exclamation-triangle me-1"></i>Anular la factura la excluirá de los reportes.</div>
                        </div>
                        <div class="mb-3">
                            <label class="section-title mb-2 d-block text-success">Estado del Cobro</label>
                            <select name="estado_pago" class="form-select border-2" id="modalPago" onchange="toggleMetodoPago()">
                                <option value="PENDIENTE">⏳ Pendiente de Pago</option>
                                <option value="PAGADA">💰 Pagada / Cobrada</option>
                            </select>
                        </div>
                        <div class="mb-0" id="divMetodo" style="display:none">
                            <label class="section-title mb-2 d-block text-info">Método de Pago</label>
                            <select name="metodo_pago" class="form-select border-2" id="modalMetodo">
                                <option value="">- Seleccione -</option>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Transferencia">Transferencia</option>
                                <option value="En Linea">Pasarela Web</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 bg-light">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        function printInvoice(id) { window.open('invoice_print.php?id=' + id, 'Factura', 'width=900,height=800'); }
        function printOffer(id) { window.open('offer_print.php?id=' + id, 'Oferta', 'width=900,height=800'); }
        function updateBulkBar() {
            const checked = document.querySelectorAll('.row-chk:checked');
            const bar = document.getElementById('bulkBar');
            if (!bar) return;
            if (checked.length > 0) { bar.classList.remove('d-none'); document.getElementById('selCount').textContent = checked.length; }
            else { bar.classList.add('d-none'); }
            const chkAll = document.getElementById('chkAll');
            if (chkAll) chkAll.indeterminate = checked.length > 0 && checked.length < document.querySelectorAll('.row-chk').length;
        }
        function toggleAll(cb) { document.querySelectorAll('.row-chk').forEach(c => c.checked = cb.checked); updateBulkBar(); }
        function clearSelection() { document.querySelectorAll('.row-chk').forEach(c => c.checked = false); const cb = document.getElementById('chkAll'); if(cb) cb.checked = false; updateBulkBar(); }
        function openEditModal(data) {
            const modal = new bootstrap.Modal(document.getElementById('editModal'));
            document.getElementById('modalId').value = data.id;
            document.getElementById('modalFacturaNum').innerText = data.numero_factura;
            document.getElementById('modalEstado').value = data.estado;
            document.getElementById('modalPago').value = data.estado_pago;
            document.getElementById('modalMetodo').value = data.metodo_pago || '';
            toggleMetodoPago(); modal.show();
        }
        function toggleMetodoPago() {
            const pagoState = document.getElementById('modalPago').value;
            document.getElementById('divMetodo').style.display = (pagoState === 'PAGADA') ? 'block' : 'none';
        }
    </script>
    <?php include_once 'menu_master.php'; ?>
</body>
</html>
