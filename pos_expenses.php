<?php
// ARCHIVO: /var/www/palweb/api/pos_expenses.php
// VERSIÓN: GESTOR DE GASTOS GOLD (CON MODALES Y NÓMINA)

session_start();
// if (!isset($_SESSION['admin_logged_in'])) header('Location: login.php'); 
require_once 'db.php';

// --- CONFIGURACIÓN Y SUCURSAL ---
date_default_timezone_set('America/Havana');
require_once 'config_loader.php';
$SUC_ID = intval($config['id_sucursal']); 

$mesActual = date('m');
$anioActual = date('Y');
$diasEnMes = date('t');
$primerDiaSemana = date('w', strtotime("$anioActual-$mesActual-01"));

// --- API ACTIONS BÁSICAS (CRUD LOCAL) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api_mode'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';

    try {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO gastos_historial (fecha, concepto, monto, categoria, tipo, id_usuario, notas, id_sucursal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['fecha'], $input['concepto'], $input['monto'], 
                $input['categoria'], $input['tipo'], 
                $_SESSION['user_id'] ?? 'Admin', $input['notas'] ?? '',
                $SUC_ID
            ]);
            echo json_encode(['status' => 'success', 'msg' => 'Gasto registrado']);
        }
        elseif ($action === 'update') {
            $stmt = $pdo->prepare("UPDATE gastos_historial SET fecha=?, concepto=?, monto=?, categoria=?, tipo=?, notas=? WHERE id=? AND id_sucursal=?");
            $stmt->execute([
                $input['fecha'], $input['concepto'], $input['monto'], 
                $input['categoria'], $input['tipo'], $input['notas'], 
                $input['id'], $SUC_ID
            ]);
            echo json_encode(['status' => 'success', 'msg' => 'Gasto actualizado']);
        }
        elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM gastos_historial WHERE id=? AND id_sucursal=?");
            $stmt->execute([$input['id'], $SUC_ID]);
            echo json_encode(['status' => 'success', 'msg' => 'Gasto eliminado']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

// --- CONSULTAS DE DATOS ---
$sqlTotal = "SELECT SUM(monto) FROM gastos_historial WHERE MONTH(fecha) = '$mesActual' AND YEAR(fecha) = '$anioActual' AND id_sucursal = $SUC_ID";
$totalMes = $pdo->query($sqlTotal)->fetchColumn() ?: 0;

$sqlCal = "SELECT DAY(fecha) as dia, SUM(monto) as total, COUNT(*) as items 
           FROM gastos_historial 
           WHERE MONTH(fecha) = '$mesActual' AND YEAR(fecha) = '$anioActual' AND id_sucursal = $SUC_ID
           GROUP BY DAY(fecha)";
$rawCal = $pdo->query($sqlCal)->fetchAll(PDO::FETCH_ASSOC);
$calendarData = [];
foreach($rawCal as $r) $calendarData[$r['dia']] = $r;

$sqlList = "SELECT * FROM gastos_historial WHERE id_sucursal = $SUC_ID ORDER BY fecha DESC LIMIT 100";
$gastos = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC);

// Plantillas simples para botones rápidos
$plantillasRapidas = [
    ['concepto'=>'Compra Hielo', 'monto'=>0, 'cat'=>'INSUMOS'],
    ['concepto'=>'Taxi Personal', 'monto'=>0, 'cat'=>'SERVICIOS'],
    ['concepto'=>'Adelanto Nómina', 'monto'=>0, 'cat'=>'NOMINA']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Control de Gastos | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .calendar-header { font-weight: bold; text-align: center; background: #e9ecef; padding: 5px; border-radius: 4px; font-size: 0.8rem; text-transform: uppercase; }
        .calendar-day { background: white; border: 1px solid #dee2e6; border-radius: 6px; min-height: 80px; padding: 5px; position: relative; transition: 0.2s; }
        .calendar-day:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .day-num { font-weight: bold; color: #495057; font-size: 0.9rem; margin-bottom: 5px; display: block;}
        .day-total { display: block; background: #ffebeb; color: #dc3545; font-size: 0.75rem; padding: 2px 4px; border-radius: 4px; text-align: center; font-weight: bold; }
        .day-items { font-size: 0.7rem; color: #6c757d; text-align: center; display: block; margin-top: 2px; }
        .empty-cell { background: transparent; border: none; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .badge-variable { background-color: #ffc107; color: #000; }
        .badge-fijo { background-color: #0d6efd; color: #fff; }
        .modal-header { background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
    </style>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body>

<div class="container-fluid px-4 py-4">
    
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <h3 class="fw-bold text-dark"><i class="fas fa-wallet me-2"></i> Control de Gastos</h3>
            <p class="text-muted mb-0">Gestión de gastos operativos. <span class="badge bg-primary">Sucursal #<?php echo $SUC_ID; ?></span></p>
        </div>
        <div class="col-md-3">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-outline-primary btn-sm" onclick="showCopyPreviousMonthModal()"><i class="fas fa-copy me-1"></i> Copiar Mes Ant.</button>
                <button class="btn btn-warning btn-sm" onclick="showDailyExpensesModal()"><i class="fas fa-calendar-day me-1"></i> Gastos del Día</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="showTemplatesModal()"><i class="fas fa-cog me-1"></i> Configurar Plantillas</button>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card kpi-card bg-danger text-white">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div><small class="text-uppercase opacity-75 fw-bold">Total Gastado (<?php echo date('M'); ?>)</small><h2 class="m-0 fw-bold">$<?php echo number_format($totalMes, 2); ?></h2></div>
                    <i class="fas fa-chart-line fa-3x opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-4 rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-3"><h5 class="fw-bold m-0 text-primary"><i class="fas fa-plus-circle me-2"></i> Registrar Gasto Individual</h5></div>
                <div class="card-body">
                    <form id="formExpense" onsubmit="saveExpense(event)">
                        <input type="hidden" name="id" id="inputId"> <div class="row g-2">
                            <div class="col-6"><label class="small fw-bold">Fecha</label><input type="date" name="fecha" id="inputFecha" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                            <div class="col-6"><label class="small fw-bold">Tipo</label><select name="tipo" id="inputTipo" class="form-select fw-bold text-secondary"><option value="VARIABLE">⚡ Variable</option><option value="FIJO">🔒 Fijo</option></select></div>
                            <div class="col-12"><label class="small fw-bold">Concepto</label><input type="text" name="concepto" id="inputConcepto" class="form-control" placeholder="Ej: Compra de hielo" required></div>
                            <div class="col-6"><label class="small fw-bold">Monto</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" name="monto" id="inputMonto" class="form-control fw-bold" required></div></div>
                            <div class="col-6"><label class="small fw-bold">Categoría</label><select name="categoria" id="inputCat" class="form-select"><option value="GENERAL">General</option><option value="INSUMOS">Insumos</option><option value="SERVICIOS">Servicios</option><option value="NOMINA">Nómina</option><option value="MANTENIMIENTO">Mantenimiento</option><option value="RENTA">Renta</option></select></div>
                            <div class="col-12 mt-3"><button type="submit" class="btn btn-success w-100 fw-bold shadow-sm" id="btnSave"><i class="fas fa-save me-2"></i> GUARDAR GASTO</button><button type="button" class="btn btn-secondary w-100 mt-2 d-none" id="btnCancel" onclick="resetForm()">Cancelar Edición</button></div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light"><small class="fw-bold text-muted">ACCESOS RÁPIDOS</small></div>
                <div class="card-body p-2">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach($plantillasRapidas as $p): ?>
                        <button class="btn btn-outline-secondary btn-sm" onclick="fillTemplate('<?php echo $p['concepto']; ?>', <?php echo $p['monto']; ?>, '<?php echo $p['cat']; ?>')">
                            <i class="fas fa-bolt text-warning me-1"></i> <?php echo $p['concepto']; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><h5 class="fw-bold m-0"><i class="far fa-calendar-alt me-2"></i> Calendario <?php echo date('M Y'); ?></h5><span class="badge bg-light text-dark border">Vista Diaria</span></div>
                <div class="card-body p-3"><div class="calendar-grid"><div class="calendar-header text-danger">Dom</div><div class="calendar-header">Lun</div><div class="calendar-header">Mar</div><div class="calendar-header">Mie</div><div class="calendar-header">Jue</div><div class="calendar-header">Vie</div><div class="calendar-header">Sab</div><?php for($i=0; $i<$primerDiaSemana; $i++): ?><div class="empty-cell"></div><?php endfor; ?><?php for($dia=1; $dia<=$diasEnMes; $dia++): $info = $calendarData[$dia] ?? ['total'=>0, 'items'=>0]; $hasData = $info['total'] > 0; $bgClass = $hasData ? 'border-danger' : ''; ?><div class="calendar-day <?php echo $bgClass; ?>"><span class="day-num"><?php echo $dia; ?></span><?php if($hasData): ?><span class="day-total">-$<?php echo number_format($info['total']); ?></span><span class="day-items"><?php echo $info['items']; ?> movs.</span><?php endif; ?></div><?php endfor; ?></div></div>
            </div>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h5 class="fw-bold m-0"><i class="fas fa-list me-2"></i> Historial Reciente</h5></div>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light small"><tr><th>Fecha</th><th>Concepto</th><th>Categ.</th><th>Tipo</th><th class="text-end">Monto</th><th class="text-end">Acciones</th></tr></thead><tbody><?php foreach($gastos as $g): ?><tr id="row-<?php echo $g['id']; ?>"><td><?php echo date('d/m', strtotime($g['fecha'])); ?></td><td class="fw-bold text-dark"><?php echo htmlspecialchars($g['concepto']); ?></td><td><span class="badge bg-light text-dark border"><?php echo $g['categoria']; ?></span></td><td><?php if($g['tipo']=='FIJO'): ?><span class="badge badge-fijo" style="font-size:0.65rem">FIJO</span><?php else: ?><span class="badge badge-variable" style="font-size:0.65rem">VAR</span><?php endif; ?></td><td class="text-end fw-bold text-danger">-$<?php echo number_format($g['monto'], 2); ?></td><td class="text-end"><button class="btn btn-sm btn-link text-primary p-0 me-2" onclick='editExpense(<?php echo json_encode($g); ?>)'><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-link text-secondary p-0" onclick="deleteExpense(<?php echo $g['id']; ?>)"><i class="fas fa-trash"></i></button></td></tr><?php endforeach; ?></tbody></table></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCopyMonth" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-copy text-primary me-2"></i> Copiar Gastos Fijos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="copyPreviewContent" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Analizando mes anterior...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary fw-bold" id="btnConfirmCopy" disabled onclick="executeCopyMonth()">Confirmar Copia</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDailyExpenses" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-day text-warning me-2"></i> Gastos Operativos del Día</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3 align-items-end">
                    <div class="col-md-4">
                        <label class="small fw-bold">Fecha</label>
                        <input type="date" id="dailyDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" onchange="loadDailyData()">
                    </div>
                    <div class="col-md-8 text-end">
                        <span class="badge bg-light text-dark border p-2">
                            Ventas del Día: <strong id="lblVentasDia">$0.00</strong>
                        </span>
                    </div>
                </div>

                <div class="card mb-3 bg-light border-0">
                    <div class="card-body p-2">
                         <h6 class="fw-bold mb-2 small text-uppercase">Agregar Gasto Rápido</h6>
                         <div class="d-flex gap-2">
                             <input type="text" id="quickConcept" class="form-control form-control-sm" placeholder="Concepto (ej: Hielo)">
                             <input type="number" id="quickMonto" class="form-control form-control-sm" placeholder="Monto" style="width: 100px;">
                             <button class="btn btn-primary btn-sm" onclick="addQuickExpense()"><i class="fas fa-plus"></i></button>
                         </div>
                    </div>
                </div>

                <h6 class="fw-bold small border-bottom pb-1">Nómina y Gastos Configurables</h6>
                <div id="dailyTemplatesList">
                    <div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                 <div class="fw-bold text-danger" id="lblTotalDaily">Total: $0.00</div>
                 <button type="button" class="btn btn-success fw-bold" onclick="saveDailyExpenses()">GUARDAR TODO</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTemplates" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-cog me-2"></i> Configuración de Plantillas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="col-md-4 border-end bg-light">
                        <div class="p-2 border-bottom"><button class="btn btn-primary btn-sm w-100" onclick="newTemplate()">+ Nueva Plantilla</button></div>
                        <div id="templatesList" class="list-group list-group-flush" style="max-height: 400px; overflow-y:auto;"></div>
                    </div>
                    <div class="col-md-8 p-3">
                        <form id="formTemplate">
                            <input type="hidden" name="id" id="tplId">
                            <div class="mb-2">
                                <label class="small fw-bold">Nombre / Concepto</label>
                                <input type="text" name="nombre" id="tplNombre" class="form-control" required>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="small fw-bold">Categoría</label>
                                    <select name="categoria" id="tplCat" class="form-select">
                                        <option value="NOMINA">Nómina</option>
                                        <option value="INSUMOS">Insumos</option>
                                        <option value="SERVICIOS">Servicios</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="small fw-bold">Orden</label>
                                    <input type="number" name="orden" id="tplOrden" class="form-control" value="0">
                                </div>
                            </div>
                            
                            <div class="form-check form-switch bg-light p-2 rounded mb-3 border">
                                <input class="form-check-input ms-0 me-2" type="checkbox" id="tplEsSalario" onchange="toggleSalaryOptions()">
                                <label class="form-check-label fw-bold" for="tplEsSalario">Es Pago de Salario / Nómina</label>
                            </div>

                            <div id="salaryOptions" class="d-none border p-3 rounded mb-3 bg-light">
                                <label class="small fw-bold">Tipo de Cálculo</label>
                                <select id="tplTipoCalculo" class="form-select mb-2" onchange="updateSalaryFields()">
                                    <option value="FIJO">Monto Fijo</option>
                                    <option value="PORCENTAJE_VENTAS">% de Ventas</option>
                                    <option value="FIJO_MAS_PORCENTAJE">Fijo + % Ventas</option>
                                    <option value="POR_HORA">Por Hora</option>
                                </select>
                                
                                <div class="row g-2">
                                    <div class="col-6 field-fijo">
                                        <label class="small">Salario Base ($)</label>
                                        <input type="number" step="0.01" id="tplSalarioFijo" class="form-control">
                                    </div>
                                    <div class="col-6 field-pct d-none">
                                        <label class="small">% Comisión</label>
                                        <input type="number" step="0.1" id="tplPctVentas" class="form-control">
                                    </div>
                                    <div class="col-6 field-hora d-none">
                                        <label class="small">Valor Hora ($)</label>
                                        <input type="number" step="0.01" id="tplValorHora" class="form-control">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="small fw-bold">Monto por Defecto (Si no es salario)</label>
                                <input type="number" step="0.01" name="monto_default" id="tplMontoDef" class="form-control">
                            </div>

                            <div class="text-end border-top pt-2">
                                <button type="button" class="btn btn-danger btn-sm me-auto" onclick="deleteTemplate()">Eliminar</button>
                                <button type="button" class="btn btn-success fw-bold" onclick="saveTemplate()">Guardar Plantilla</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- CORE LOCAL ---
async function saveExpense(e) { e.preventDefault(); const id = document.getElementById('inputId').value; const action = id ? 'update' : 'create'; const formData = new FormData(document.getElementById('formExpense')); const data = Object.fromEntries(formData.entries()); if(id) data.id = id; if(!confirm(id ? '¿Guardar cambios?' : '¿Registrar este gasto?')) return; try { const res = await fetch(`?action=${action}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data) }); const json = await res.json(); if(json.status === 'success') { alert(json.msg); location.reload(); } else { alert('Error: ' + json.msg); } } catch(err) { alert('Error de conexión'); } }
async function deleteExpense(id) { if(!confirm('¿Estás seguro de ELIMINAR este gasto?')) return; try { const res = await fetch('?action=delete', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id}) }); const json = await res.json(); if(json.status === 'success') { document.getElementById(`row-${id}`).remove(); } else { alert('Error: ' + json.msg); } } catch(err) { alert('Error de conexión'); } }
function editExpense(gasto) { document.getElementById('inputId').value = gasto.id; document.getElementById('inputFecha').value = gasto.fecha.split(' ')[0]; document.getElementById('inputConcepto').value = gasto.concepto; document.getElementById('inputMonto').value = gasto.monto; document.getElementById('inputCat').value = gasto.categoria; document.getElementById('inputTipo').value = gasto.tipo; document.getElementById('btnSave').innerHTML = '<i class="fas fa-sync me-2"></i> ACTUALIZAR'; document.getElementById('btnSave').classList.replace('btn-success', 'btn-primary'); document.getElementById('btnCancel').classList.remove('d-none'); document.getElementById('formExpense').scrollIntoView({behavior: 'smooth'}); }
function resetForm() { document.getElementById('formExpense').reset(); document.getElementById('inputId').value = ''; document.getElementById('btnSave').innerHTML = '<i class="fas fa-save me-2"></i> GUARDAR GASTO'; document.getElementById('btnSave').classList.replace('btn-primary', 'btn-success'); document.getElementById('btnCancel').classList.add('d-none'); }
function fillTemplate(concepto, monto, cat) { resetForm(); document.getElementById('inputConcepto').value = concepto; document.getElementById('inputMonto').value = monto; document.getElementById('inputCat').value = cat; document.getElementById('inputTipo').value = 'FIJO'; }

// --- FUNCIONES AVANZADAS (API) ---

// 1. MODAL COPIAR MES
function showCopyPreviousMonthModal() {
    new bootstrap.Modal(document.getElementById('modalCopyMonth')).show();
    fetch('pos_expenses_api.php?action=preview_previous_month')
    .then(r => r.json())
    .then(data => {
        const content = document.getElementById('copyPreviewContent');
        const btn = document.getElementById('btnConfirmCopy');
        if(data.count > 0) {
            content.innerHTML = `<h3 class="text-success fw-bold">$${new Intl.NumberFormat().format(data.total)}</h3><p>Se copiarán <b>${data.count}</b> gastos fijos del mes <b>${data.mes}</b>.</p>`;
            btn.disabled = false;
        } else {
            content.innerHTML = `<p class="text-danger fw-bold">No hay gastos fijos en el mes anterior.</p>`;
            btn.disabled = true;
        }
    });
}

function executeCopyMonth() {
    fetch('pos_expenses_api.php?action=copy_previous_month', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        location.reload();
    });
}

// 2. MODAL GASTOS DEL DÍA Y NÓMINA
let currentDailyExpenses = [];
let dailyModalInstance = null;

function showDailyExpensesModal() {
    dailyModalInstance = new bootstrap.Modal(document.getElementById('modalDailyExpenses'));
    dailyModalInstance.show();
    loadDailyData();
}

function loadDailyData() {
    const fecha = document.getElementById('dailyDate').value;
    
    // 1. Obtener Ventas del Día
    fetch(`pos_expenses_api.php?action=get_daily_sales&fecha=${fecha}`)
    .then(r => r.json())
    .then(data => {
        document.getElementById('lblVentasDia').innerText = '$' + new Intl.NumberFormat().format(data.total_ventas);
        loadDailyTemplates(data.total_ventas);
    });
}

function loadDailyTemplates(ventasTotales) {
    const list = document.getElementById('dailyTemplatesList');
    list.innerHTML = '<div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Cargando plantillas...</div>';
    const fecha = document.getElementById('dailyDate').value;

    Promise.all([
        fetch('pos_expenses_api.php?action=get_templates').then(r => r.json()),
        fetch('pos_expenses_api.php?action=calculate_all_salaries', {
            method: 'POST',
            body: JSON.stringify({ fecha: fecha, ventas_totales: ventasTotales })
        }).then(r => r.json())
    ])
    .then(([templatesData, salariesData]) => {
        list.innerHTML = '';
        const allTemplates = templatesData.plantillas || [];
        const calculatedSalaries = salariesData.salarios || [];

        if (allTemplates.length === 0) {
            list.innerHTML = '<div class="text-muted small p-2">No hay plantillas de gastos configuradas.</div>';
            updateTotalDaily();
            return;
        }

        allTemplates.forEach(tpl => {
            const rowId = `tpl_${tpl.id}`;
            const div = document.createElement('div');
            div.className = "d-flex justify-content-between align-items-center border-bottom py-2";
            let montoInicial = parseFloat(tpl.monto_default) || 0;
            let desgloseHtml = '';

            if (tpl.es_salario == 1) {
                const matchingSalary = calculatedSalaries.find(s => s.id == tpl.id);
                if (matchingSalary) {
                    montoInicial = matchingSalary.salario;
                    desgloseHtml = `<small class="text-muted" style="font-size:0.75rem">${matchingSalary.desglose.join(', ')}</small>`;
                }
            }

            div.innerHTML = `
                <div>
                    <div class="fw-bold">${tpl.nombre}</div>
                    ${desgloseHtml}
                </div>
                <div class="d-flex align-items-center gap-2">
                    <input type="number" class="form-control form-control-sm fw-bold text-end" style="width:100px" 
                        id="${rowId}" value="${montoInicial.toFixed(2)}" onchange="updateTotalDaily()">
                    <button class="btn btn-outline-danger btn-sm" onclick="this.parentElement.parentElement.remove(); updateTotalDaily()"><i class="fas fa-times"></i></button>
                </div>
            `;
            
            div.dataset.nombre = tpl.nombre;
            div.dataset.cat = tpl.categoria;
            div.dataset.inputId = rowId;
            
            list.appendChild(div);
        });
        updateTotalDaily();
    })
    .catch(error => {
        console.error('Error loading daily templates:', error);
        list.innerHTML = '<div class="text-danger small p-2">Error al cargar las plantillas de gastos.</div>';
    });
}

function addQuickExpense() {
    const list = document.getElementById('dailyTemplatesList');
    const concepto = document.getElementById('quickConcept').value;
    const monto = document.getElementById('quickMonto').value;
    
    if(!concepto || !monto) return;

    const rowId = `quick_${Date.now()}`;
    const div = document.createElement('div');
    div.className = "d-flex justify-content-between align-items-center border-bottom py-2 bg-light px-2";
    div.innerHTML = `
        <div class="fw-bold text-primary">${concepto} <span class="badge bg-secondary ms-2" style="font-size:0.6rem">MANUAL</span></div>
        <div class="d-flex align-items-center gap-2">
            <input type="number" class="form-control form-control-sm fw-bold text-end" style="width:100px" 
                id="${rowId}" value="${monto}" onchange="updateTotalDaily()">
            <button class="btn btn-outline-danger btn-sm" onclick="this.parentElement.parentElement.remove(); updateTotalDaily()"><i class="fas fa-times"></i></button>
        </div>
    `;
    div.dataset.nombre = concepto;
    div.dataset.cat = 'INSUMOS';
    div.dataset.inputId = rowId;
    
    list.prepend(div);
    document.getElementById('quickConcept').value = '';
    document.getElementById('quickMonto').value = '';
    updateTotalDaily();
}

function updateTotalDaily() {
    let total = 0;
    document.querySelectorAll('#dailyTemplatesList input[type="number"]').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    document.getElementById('lblTotalDaily').innerText = 'Total: $' + new Intl.NumberFormat().format(total);
}

function saveDailyExpenses() {
    const fecha = document.getElementById('dailyDate').value;
    const gastosParaGuardar = [];
    
    document.querySelectorAll('#dailyTemplatesList > div').forEach(div => {
        const monto = parseFloat(document.getElementById(div.dataset.inputId).value) || 0;
        if(monto > 0) {
            gastosParaGuardar.push({
                concepto: div.dataset.nombre,
                monto: monto,
                categoria: div.dataset.cat
            });
        }
    });

    if(gastosParaGuardar.length === 0) { alert("No hay montos para guardar"); return; }

    if(confirm(`¿Guardar ${gastosParaGuardar.length} gastos por el total mostrado?`)) {
        fetch('pos_expenses_api.php?action=save_daily_expenses', {
            method: 'POST',
            body: JSON.stringify({ fecha: fecha, gastos: gastosParaGuardar })
        })
        .then(r => r.json())
        .then(data => {
            alert(data.msg);
            location.reload();
        });
    }
}

// 3. GESTIÓN PLANTILLAS
let currentTemplates = [];

function showTemplatesModal() {
    new bootstrap.Modal(document.getElementById('modalTemplates')).show();
    loadTemplatesList();
}

function loadTemplatesList() {
    fetch('pos_expenses_api.php?action=get_templates')
    .then(r => r.json())
    .then(data => {
        currentTemplates = data.plantillas || [];
        const list = document.getElementById('templatesList');
        list.innerHTML = '';
        currentTemplates.forEach(t => {
            const btn = document.createElement('button');
            btn.className = "list-group-item list-group-item-action d-flex justify-content-between align-items-center";
            btn.innerHTML = `<span>${t.nombre}</span> <span class="badge bg-secondary rounded-pill">${t.categoria}</span>`;
            btn.onclick = () => loadTemplateForm(t);
            list.appendChild(btn);
        });
    });
}

function loadTemplateForm(t) {
    document.getElementById('tplId').value = t.id;
    document.getElementById('tplNombre').value = t.nombre;
    document.getElementById('tplCat').value = t.categoria;
    document.getElementById('tplOrden').value = t.orden;
    document.getElementById('tplMontoDef').value = t.monto_default;
    
    // Lógica Salario
    const esSalario = t.es_salario == 1;
    document.getElementById('tplEsSalario').checked = esSalario;
    toggleSalaryOptions();

    if(esSalario) {
        document.getElementById('tplTipoCalculo').value = t.tipo_calculo_salario;
        document.getElementById('tplSalarioFijo').value = t.salario_fijo;
        document.getElementById('tplPctVentas').value = t.porcentaje_ventas;
        document.getElementById('tplValorHora').value = t.valor_hora;
        updateSalaryFields();
    }
}

function newTemplate() {
    document.getElementById('formTemplate').reset();
    document.getElementById('tplId').value = '';
    toggleSalaryOptions();
}

function toggleSalaryOptions() {
    const es = document.getElementById('tplEsSalario').checked;
    document.getElementById('salaryOptions').classList.toggle('d-none', !es);
    document.getElementById('tplMontoDef').disabled = es;
}

function updateSalaryFields() {
    const tipo = document.getElementById('tplTipoCalculo').value;
    document.querySelector('.field-fijo').classList.add('d-none');
    document.querySelector('.field-pct').classList.add('d-none');
    document.querySelector('.field-hora').classList.add('d-none');

    if(tipo.includes('FIJO')) document.querySelector('.field-fijo').classList.remove('d-none');
    if(tipo.includes('PORCENTAJE')) document.querySelector('.field-pct').classList.remove('d-none');
    if(tipo.includes('HORA')) document.querySelector('.field-hora').classList.remove('d-none');
}

function saveTemplate() {
    const data = {
        id: document.getElementById('tplId').value,
        nombre: document.getElementById('tplNombre').value,
        categoria: document.getElementById('tplCat').value,
        orden: document.getElementById('tplOrden').value,
        monto_default: document.getElementById('tplMontoDef').value,
        es_salario: document.getElementById('tplEsSalario').checked ? 1 : 0,
        tipo_calculo_salario: document.getElementById('tplTipoCalculo').value,
        salario_fijo: document.getElementById('tplSalarioFijo').value,
        porcentaje_ventas: document.getElementById('tplPctVentas').value,
        valor_hora: document.getElementById('tplValorHora').value
    };

    fetch('pos_expenses_api.php?action=save_template', { method: 'POST', body: JSON.stringify(data) })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            alert('Plantilla guardada');
            loadTemplatesList();
            newTemplate();
        } else {
            alert('Error al guardar');
        }
    });
}

function deleteTemplate() {
    const id = document.getElementById('tplId').value;
    if(!id) return;
    if(!confirm('¿Eliminar esta plantilla?')) return;
    
    fetch('pos_expenses_api.php?action=delete_template', { method: 'POST', body: JSON.stringify({id: id}) })
    .then(r => r.json())
    .then(() => {
        loadTemplatesList();
        newTemplate();
    });
}
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

