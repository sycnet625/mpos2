<?php
// ARCHIVO: offers_editor.php
// DESCRIPCIÓN: Editor de Ofertas Comerciales — con selector CRM y autocompletado de productos
require_once 'db.php';
require_once 'config_loader.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$id   = isset($_GET['id'])   ? intval($_GET['id'])   : 0;
$mode = isset($_GET['mode']) ? $_GET['mode']          : 'offer';
$offerData = null;
$items     = [];

if ($id > 0) {
    if ($mode === 'offer' || isset($_GET['convert'])) {
        $stmt = $pdo->prepare("SELECT * FROM ofertas WHERE id = ?");
        $stmt->execute([$id]);
        $offerData = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmtDet = $pdo->prepare("SELECT * FROM ofertas_detalle WHERE id_oferta = ?");
        $stmtDet->execute([$id]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM facturas WHERE id = ?");
        $stmt->execute([$id]);
        $offerData = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmtDet = $pdo->prepare("SELECT * FROM facturas_detalle WHERE id_factura = ?");
        $stmtDet->execute([$id]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Número sugerido
if (!$offerData) {
    if ($mode === 'offer') {
        $nextId = intval($pdo->query("SELECT MAX(id) FROM ofertas")->fetchColumn()) + 1;
        $numSugerido = "OF-" . date('Ymd') . str_pad($nextId, 3, '0', STR_PAD_LEFT);
    } else {
        $numFmt    = $config['factura_numero_formato'] ?? 'fecha_id';
        $numPrefij = $config['factura_numero_prefijo']  ?? 'F-';
        if ($numFmt === 'consecutivo') {
            $nextConsec = intval($pdo->query("SELECT COUNT(*) FROM facturas")->fetchColumn()) + 1;
            $numSugerido = $numPrefij . str_pad($nextConsec, 4, '0', STR_PAD_LEFT);
        } else {
            // fecha_id: prefijo + YYYYMMDD + secuencia del día
            $hoy = date('Y-m-d');
            $countHoy = intval($pdo->query("SELECT COUNT(*) FROM facturas WHERE DATE(fecha_emision) = '$hoy'")->fetchColumn()) + 1;
            $numSugerido = $numPrefij . date('Ymd') . str_pad($countHoy, 3, '0', STR_PAD_LEFT);
        }
    }
} else {
    $numSugerido = $mode === 'offer' ? ($offerData['numero_oferta'] ?? '') : ($offerData['numero_factura'] ?? '');
}

// Cargar todos los clientes CRM activos
$clientes = $pdo->query(
    "SELECT id, nombre,
            COALESCE(NULLIF(telefono, ''), NULLIF(telefono_principal, '')) AS telefono,
            COALESCE(NULLIF(direccion, ''), NULLIF(direccion_principal, '')) AS direccion,
            nit_ci, email
     FROM clientes WHERE activo=1 ORDER BY nombre"
)->fetchAll(PDO::FETCH_ASSOC);

$accentColor = ($mode === 'offer') ? '#e67e22' : '#2F75B5';
$docLabel    = ($mode === 'offer') ? 'Oferta Comercial' : 'Factura Manual';
$isConvert   = isset($_GET['convert']);

// Helper: renderiza una fila de producto
// $desc puede estar pre-cargado (edición); se muestra como seleccionado si no está vacío
function renderRow($desc, $cant, $um, $precio, $importe) {
    $descEsc    = htmlspecialchars($desc);
    $hasProduct = $desc !== '' ? 'true' : 'false';
    $lockStyle  = $desc !== '' ? 'display:none'  : '';
    $chipStyle  = $desc !== '' ? ''               : 'display:none';
    return '<tr class="item-row">
        <td style="min-width:220px">
            <!-- Buscador (visible cuando no hay producto seleccionado) -->
            <div class="prod-search-wrap" style="position:relative;'.$lockStyle.'">
                <input type="text" class="form-control prod-search-input" placeholder="Buscar producto..." autocomplete="off">
                <div class="prod-results-dd"></div>
            </div>
            <!-- Chip del producto seleccionado -->
            <div class="prod-chip d-flex align-items-center gap-1" style="'.$chipStyle.'">
                <span class="badge bg-secondary prod-chip-name flex-grow-1 text-start text-wrap fw-normal" style="font-size:.82rem; padding:5px 8px;">'.$descEsc.'</span>
                <button type="button" class="btn btn-sm btn-outline-danger prod-clear-btn" title="Cambiar producto"><i class="fas fa-times"></i></button>
            </div>
            <!-- Campo real que se envía al servidor -->
            <input type="hidden" name="desc[]" class="prod-desc-val" value="'.$descEsc.'" required>
        </td>
        <td><input type="number" name="cant[]"    class="form-control text-center"      value="'.$cant.'"    step="0.01" min="0"></td>
        <td><input type="text"   name="um[]"      class="form-control text-center"      value="'.htmlspecialchars($um).'" readonly></td>
        <td><input type="number" name="precio[]"  class="form-control text-end"         value="'.$precio.'"  step="0.01" min="0"></td>
        <td><input type="text"   name="importe[]" class="form-control text-end fw-bold" value="'.$importe.'" readonly></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
    </tr>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $id > 0 && !$isConvert ? 'Editar' : 'Nueva' ?> <?= $docLabel ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        :root { --accent: <?= $accentColor ?>; }
        .accent-header { background: var(--accent); color: white; padding: 6px 14px; border-radius: 8px 8px 0 0; font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; }
        .client-card, .product-card { border: 1px solid #dee2e6; border-radius: 0 8px 8px 8px; }
        #clientDropdown { position: absolute; z-index: 1050; background: white; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,.15); max-height: 260px; overflow-y: auto; width: 100%; display: none; top: 100%; left: 0; }
        .client-result { cursor: pointer; padding: 8px 12px; border-bottom: 1px solid #f0f0f0; transition: background .12s; }
        .client-result:hover { background: #fff3e0; }
        .cli-name { font-weight: 700; font-size: .88rem; }
        .cli-sub  { font-size: .75rem; color: #777; }
        .prod-results-dd { position: absolute; z-index: 1060; background: white; border: 1px solid #ccc; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,.14); max-height: 220px; overflow-y: auto; width: 100%; min-width: 280px; display: none; top: 100%; left: 0; }
        .prod-item { padding: 7px 12px; cursor: pointer; font-size: .82rem; border-bottom: 1px solid #f5f5f5; }
        .prod-item:hover { background: #e8f4fd; }
        .prod-item .pname { font-weight: 600; }
        .prod-item .pcode { color: #999; font-size: .72rem; }
        .prod-item.no-results { color: #999; cursor: default; }
        .prod-item.searching  { color: #aaa; cursor: default; font-style: italic; }
        .selected-client-badge { background: #fff3e0; border: 1.5px solid var(--accent); border-radius: 8px; padding: 6px 12px; display: flex; align-items: center; gap: 8px; }
        .item-row:hover { background: #fafafa; }
    </style>
</head>
<body class="inventory-suite p-3 pb-5">
<div class="container-lg shell inventory-shell">

    <div class="d-flex justify-content-between align-items-center mb-4 pt-2">
        <h3 class="fw-bold mb-0">
            <i class="fas fa-file-signature me-2" style="color:var(--accent)"></i>
            <?= $id > 0 && !$isConvert ? 'Editar' : 'Nueva' ?> <?= $docLabel ?>
            <?php if($isConvert): ?><span class="badge bg-success ms-2" style="font-size:.68rem">Convirtiendo Oferta #<?= $id ?></span><?php endif; ?>
        </h3>
        <a href="invoices.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Volver</a>
    </div>

    <form id="editorForm" action="offers_api.php" method="POST">
        <input type="hidden" name="action"     value="<?= ($id > 0 && !$isConvert) ? 'update' : 'create' ?>">
        <input type="hidden" name="id"         value="<?= $id ?>">
        <input type="hidden" name="type"       value="<?= $mode ?>">
        <input type="hidden" name="id_cliente" id="hiddenIdCliente" value="<?= intval($offerData['id_cliente'] ?? 0) ?>">
        <?php if($isConvert): ?><input type="hidden" name="convert_from_offer_id" value="<?= $id ?>"><?php endif; ?>

        <div class="row g-4">

            <!-- Datos del documento -->
            <div class="col-lg-4">
                <div class="glass-card p-4">
                    <div class="section-title mb-3"><i class="fas fa-hashtag me-1" style="color:var(--accent)"></i>Datos del Documento</div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Número</label>
                        <input type="text" name="numero" class="form-control fw-bold" value="<?= htmlspecialchars($numSugerido) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Fecha</label>
                        <input type="date" name="fecha" class="form-control" value="<?= $offerData ? date('Y-m-d', strtotime($offerData['fecha_emision'])) : date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="form-label small fw-bold">Notas / <?= $mode === 'offer' ? 'Condiciones' : 'Observaciones' ?></label>
                        <textarea name="notas" class="form-control" rows="3" placeholder="<?= $mode === 'offer' ? 'Ej: Oferta válida por 15 días...' : 'Observaciones internas...' ?>"><?= htmlspecialchars($offerData['notas'] ?? '') ?></textarea>
                    </div>
                    <?php if($mode === 'invoice'): ?>
                    <div class="mt-3 pt-3 border-top">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-bold">Mensajero / Transportista</label>
                                <input type="text" name="mensajero_nombre" class="form-control" placeholder="Nombre del mensajero" value="<?= htmlspecialchars($offerData['mensajero_nombre'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Vehículo</label>
                                <input type="text" name="vehiculo" class="form-control" placeholder="Ej: Moto, Bicicleta..." value="<?= htmlspecialchars($offerData['vehiculo'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Costo de Envío ($)</label>
                                <input type="number" name="costo_envio" class="form-control text-end" step="0.01" min="0" value="<?= number_format(floatval($offerData['costo_envio'] ?? 0), 2, '.', '') ?>">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cliente -->
            <div class="col-lg-8">
                <div class="accent-header"><i class="fas fa-user me-1"></i>Cliente</div>
                <div class="client-card p-4">

                    <!-- Buscador CRM -->
                    <div class="mb-3 position-relative">
                        <label class="form-label small fw-bold">Buscar cliente CRM <span class="text-muted fw-normal">(nombre o teléfono)</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="clientSearch" class="form-control border-start-0" placeholder="Escribe para buscar..." autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" onclick="clearClient()" title="Limpiar"><i class="fas fa-times"></i></button>
                        </div>
                        <div id="clientDropdown"></div>
                        <div id="selectedClientBadge" class="mt-2" style="display:none">
                            <div class="selected-client-badge">
                                <i class="fas fa-check-circle" style="color:var(--accent)"></i>
                                <span id="selectedClientName" class="fw-bold small"></span>
                                <span class="badge ms-auto" style="background:var(--accent); font-size:.65rem">CRM</span>
                            </div>
                        </div>
                    </div>

                    <!-- Campos del cliente -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nombre / Razón Social <span class="text-danger">*</span></label>
                            <input type="text" name="cliente_nombre" id="fNombre" class="form-control" value="<?= htmlspecialchars($offerData['cliente_nombre'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Teléfono</label>
                            <input type="text" name="cliente_telefono" id="fTelefono" class="form-control" value="<?= htmlspecialchars($offerData['cliente_telefono'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Dirección</label>
                            <input type="text" name="cliente_direccion" id="fDireccion" class="form-control" value="<?= htmlspecialchars($offerData['cliente_direccion'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-top d-flex align-items-center gap-2">
                        <span class="text-muted small">¿Cliente nuevo?</span>
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                            <i class="fas fa-user-plus me-1"></i>Crear en CRM
                        </button>
                        <a href="crm_clients.php" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-external-link-alt me-1"></i>Abrir CRM
                        </a>
                    </div>
                </div>
            </div>

            <!-- Tabla de productos -->
            <div class="col-12">
                <div class="accent-header"><i class="fas fa-box me-1"></i>Productos / Conceptos</div>
                <div class="product-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="text-muted small"><i class="fas fa-info-circle me-1"></i>Escribe en Descripción para buscar del catálogo. Puedes incluir productos agotados.</div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addRow()"><i class="fas fa-plus me-1"></i>Agregar Fila</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Descripción</th>
                                    <th width="80">Cant.</th>
                                    <th width="80">UM</th>
                                    <th width="130">P. Unitario</th>
                                    <th width="130">Importe</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <?php if(empty($items)): ?>
                                    <?= renderRow('', 1, 'UND', '0.00', '0.00') ?>
                                <?php else: foreach($items as $i): ?>
                                    <?= renderRow($i['descripcion'], $i['cantidad'], $i['unidad_medida'], number_format($i['precio_unitario'],2,'.',''), number_format($i['importe'],2,'.','')) ?>
                                <?php endforeach; endif; ?>
                            </tbody>
                            <tfoot>
                                <?php if($mode === 'invoice'): ?>
                                <tr>
                                    <td colspan="4" class="text-end text-muted small">Subtotal productos</td>
                                    <td class="text-end text-muted small" id="subtotalLabel">$0.00</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end text-muted small">+ Envío</td>
                                    <td class="text-end text-muted small" id="envioLabel">$0.00</td>
                                    <td></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td colspan="4" class="text-end fw-bold fs-6">TOTAL</td>
                                    <td class="text-end"><span class="fw-bold fs-5" id="totalLabel" style="color:var(--accent)">$0.00</span></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 text-end pb-4">
                <button type="submit" class="btn btn-lg px-5 fw-bold shadow-sm text-white" style="background:var(--accent)">
                    <i class="fas fa-save me-2"></i>Guardar <?= $docLabel ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Modal Nuevo Cliente -->
<div class="modal fade" id="modalNuevoCliente" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden">
            <div class="modal-header border-0 text-white" style="background:var(--accent)">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>Nuevo Cliente CRM</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="newClientMsg"></div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Nombre / Razón Social <span class="text-danger">*</span></label>
                        <input type="text" id="nc_nombre" class="form-control" placeholder="Nombre completo o empresa">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Teléfono</label>
                        <input type="text" id="nc_telefono" class="form-control" placeholder="+53 5xxx-xxxx">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">NIT / CI</label>
                        <input type="text" id="nc_nit" class="form-control" placeholder="Documento de identidad">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Dirección</label>
                        <input type="text" id="nc_direccion" class="form-control" placeholder="Dirección completa">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Email</label>
                        <input type="email" id="nc_email" class="form-control" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Tipo</label>
                        <select id="nc_tipo" class="form-select">
                            <option value="Persona">Persona Natural</option>
                            <option value="Negocio">Negocio / Empresa</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light p-3">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn text-white fw-bold px-4" style="background:var(--accent)" onclick="saveNewClient()">
                    <i class="fas fa-save me-1"></i>Crear y Seleccionar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const CRM_CLIENTS = <?= json_encode($clientes, JSON_UNESCAPED_UNICODE) ?>;

// ══ BUSCADOR CRM ══════════════════════════════════════════════════════════════
const clientSearch   = document.getElementById('clientSearch');
const clientDropdown = document.getElementById('clientDropdown');

clientSearch.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    clientDropdown.innerHTML = '';
    if (q.length < 2) { clientDropdown.style.display = 'none'; return; }
    const matches = CRM_CLIENTS.filter(c =>
        c.nombre.toLowerCase().includes(q) || (c.telefono && c.telefono.includes(q))
    ).slice(0, 12);
    if (!matches.length) {
        clientDropdown.innerHTML = '<div class="p-3 text-muted small text-center">Sin resultados</div>';
        clientDropdown.style.display = 'block'; return;
    }
    matches.forEach(c => {
        const div = document.createElement('div');
        div.className = 'client-result';
        div.innerHTML = `<div class="cli-name">${c.nombre}</div>
            <div class="cli-sub">${c.telefono||''} ${c.nit_ci?'· CI: '+c.nit_ci:''} ${c.direccion?'· '+String(c.direccion).substring(0,45):''}</div>`;
        div.onclick = () => selectClient(c);
        clientDropdown.appendChild(div);
    });
    clientDropdown.style.display = 'block';
});

document.addEventListener('click', e => {
    if (!e.target.closest('#clientSearch') && !e.target.closest('#clientDropdown'))
        clientDropdown.style.display = 'none';
});

function selectClient(c) {
    document.getElementById('hiddenIdCliente').value = c.id;
    document.getElementById('fNombre').value    = c.nombre    || '';
    document.getElementById('fTelefono').value  = c.telefono  || '';
    document.getElementById('fDireccion').value = c.direccion || '';
    clientSearch.value = '';
    clientDropdown.style.display = 'none';
    document.getElementById('selectedClientName').textContent = c.nombre;
    document.getElementById('selectedClientBadge').style.display = 'block';
}

function clearClient() {
    document.getElementById('hiddenIdCliente').value = 0;
    ['fNombre','fTelefono','fDireccion'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('selectedClientBadge').style.display = 'none';
    clientSearch.value = '';
}

// ══ CREAR CLIENTE NUEVO ═══════════════════════════════════════════════════════
function saveNewClient() {
    const nombre = document.getElementById('nc_nombre').value.trim();
    if (!nombre) {
        document.getElementById('newClientMsg').innerHTML = '<div class="alert alert-warning py-2 small">El nombre es obligatorio.</div>';
        return;
    }
    const fd = new FormData();
    fd.append('action',    'create_client');
    fd.append('nombre',    nombre);
    fd.append('telefono',  document.getElementById('nc_telefono').value.trim());
    fd.append('nit_ci',    document.getElementById('nc_nit').value.trim());
    fd.append('direccion', document.getElementById('nc_direccion').value.trim());
    fd.append('email',     document.getElementById('nc_email').value.trim());
    fd.append('tipo',      document.getElementById('nc_tipo').value);
    fetch('offers_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                const newC = { id: res.id, nombre: res.nombre, telefono: res.telefono, direccion: res.direccion, nit_ci: res.nit_ci };
                CRM_CLIENTS.unshift(newC);
                selectClient(newC);
                bootstrap.Modal.getInstance(document.getElementById('modalNuevoCliente')).hide();
                ['nc_nombre','nc_telefono','nc_nit','nc_direccion','nc_email'].forEach(f => document.getElementById(f).value = '');
                document.getElementById('newClientMsg').innerHTML = '';
            } else {
                document.getElementById('newClientMsg').innerHTML = `<div class="alert alert-danger py-2 small">${res.error}</div>`;
            }
        });
}

// ══ BUSCADOR AJAX DE PRODUCTOS ════════════════════════════════════════════════
let searchTimers = new WeakMap();

function bindProdSearch(tr) {
    const searchInp = tr.querySelector('.prod-search-input');
    const dd        = tr.querySelector('.prod-results-dd');
    const chip      = tr.querySelector('.prod-chip');
    const chipName  = tr.querySelector('.prod-chip-name');
    const hidden    = tr.querySelector('.prod-desc-val');
    const clearBtn  = tr.querySelector('.prod-clear-btn');
    const cantInp   = tr.querySelector('input[name="cant[]"]');
    const precioInp = tr.querySelector('input[name="precio[]"]');
    const umInp     = tr.querySelector('input[name="um[]"]');

    function showDD(items) {
        dd.innerHTML = '';
        if (items === null) {
            dd.innerHTML = '<div class="prod-item searching">Buscando...</div>';
        } else if (!items.length) {
            dd.innerHTML = '<div class="prod-item no-results">Sin resultados</div>';
        } else {
            items.forEach(p => {
                const div = document.createElement('div');
                div.className = 'prod-item';
                div.innerHTML = `<div class="pname">${p.nombre}</div><div class="pcode">${p.codigo} · ${p.unidad_medida} · $${parseFloat(p.precio).toFixed(2)}</div>`;
                div.addEventListener('mousedown', e => e.preventDefault()); // evitar blur antes del click
                div.onclick = () => selectProduct(p);
                dd.appendChild(div);
            });
        }
        dd.style.display = 'block';
    }

    function selectProduct(p) {
        hidden.value    = p.nombre;
        umInp.value     = p.unidad_medida;
        precioInp.value = parseFloat(p.precio).toFixed(2);
        chipName.textContent = p.nombre;
        chip.style.display        = 'flex';
        searchInp.closest('.prod-search-wrap').style.display = 'none';
        dd.style.display = 'none';
        searchInp.value  = '';
        calcRow(cantInp);
    }

    clearBtn.onclick = () => {
        hidden.value    = '';
        umInp.value     = '';
        precioInp.value = '0.00';
        chipName.textContent = '';
        chip.style.display        = 'none';
        searchInp.closest('.prod-search-wrap').style.display = '';
        searchInp.focus();
        calcRow(cantInp);
    };

    searchInp.addEventListener('input', function() {
        const q = this.value.trim();
        clearTimeout(searchTimers.get(this));
        if (q.length < 1) { dd.style.display = 'none'; return; }
        showDD(null); // "Buscando..."
        const t = setTimeout(() => {
            fetch(`offers_api.php?action=search_products&q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => showDD(data))
                .catch(() => { dd.style.display = 'none'; });
        }, 280);
        searchTimers.set(this, t);
    });

    searchInp.addEventListener('blur', () => {
        setTimeout(() => { dd.style.display = 'none'; }, 150);
    });

    cantInp.addEventListener('input',   () => calcRow(cantInp));
    precioInp.addEventListener('input', () => calcRow(cantInp));
}

// ══ FILAS DINÁMICAS ═══════════════════════════════════════════════════════════
function newRowHTML() {
    return `<tr class="item-row">
        <td style="min-width:220px">
            <div class="prod-search-wrap" style="position:relative">
                <input type="text" class="form-control prod-search-input" placeholder="Buscar producto..." autocomplete="off">
                <div class="prod-results-dd"></div>
            </div>
            <div class="prod-chip d-flex align-items-center gap-1" style="display:none!important">
                <span class="badge bg-secondary prod-chip-name flex-grow-1 text-start text-wrap fw-normal" style="font-size:.82rem;padding:5px 8px;"></span>
                <button type="button" class="btn btn-sm btn-outline-danger prod-clear-btn" title="Cambiar"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" name="desc[]" class="prod-desc-val" value="" required>
        </td>
        <td><input type="number" name="cant[]"    class="form-control text-center"      value="1"    step="0.01" min="0"></td>
        <td><input type="text"   name="um[]"      class="form-control text-center"      value=""     readonly></td>
        <td><input type="number" name="precio[]"  class="form-control text-end"         value="0.00" step="0.01" min="0"></td>
        <td><input type="text"   name="importe[]" class="form-control text-end fw-bold" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
    </tr>`;
}

function addRow() {
    const body = document.getElementById('itemsBody');
    body.insertAdjacentHTML('beforeend', newRowHTML());
    const tr = body.lastElementChild;
    bindProdSearch(tr);
    calcTotal();
}

function removeRow(btn) {
    if (document.querySelectorAll('.item-row').length > 1) {
        btn.closest('tr').remove();
        calcTotal();
    }
}

function calcRow(cantInp) {
    const row  = cantInp.closest('tr');
    const cant = parseFloat(row.querySelector('input[name="cant[]"]').value)   || 0;
    const prec = parseFloat(row.querySelector('input[name="precio[]"]').value) || 0;
    row.querySelector('input[name="importe[]"]').value = (cant * prec).toFixed(2);
    calcTotal();
}

function calcTotal() {
    let sub = 0;
    document.querySelectorAll('input[name="importe[]"]').forEach(i => { sub += parseFloat(i.value) || 0; });
    const envioEl  = document.querySelector('input[name="costo_envio"]');
    const envio    = envioEl ? (parseFloat(envioEl.value) || 0) : 0;
    const total    = sub + envio;
    const fmt = v => '$' + v.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    document.getElementById('totalLabel').textContent = fmt(total);
    const subLbl = document.getElementById('subtotalLabel');
    const envLbl = document.getElementById('envioLabel');
    if (subLbl) subLbl.textContent = fmt(sub);
    if (envLbl) envLbl.textContent = fmt(envio);
}

// ══ VALIDACIÓN ANTES DE ENVIAR ════════════════════════════════════════════════
document.getElementById('editorForm').addEventListener('submit', function(e) {
    const empties = [...document.querySelectorAll('.prod-desc-val')].filter(h => !h.value.trim());
    if (empties.length) {
        e.preventDefault();
        empties[0].closest('tr').querySelector('.prod-search-input').focus();
        alert('Selecciona un producto de la lista en todas las filas.');
    }
});

// ══ INIT ══════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.item-row').forEach(tr => bindProdSearch(tr));
    calcTotal();
    const costoEnvioEl = document.querySelector('input[name="costo_envio"]');
    if (costoEnvioEl) costoEnvioEl.addEventListener('input', calcTotal);

    const cid = parseInt(document.getElementById('hiddenIdCliente').value);
    if (cid > 0) {
        const c = CRM_CLIENTS.find(x => parseInt(x.id) === cid);
        if (c) {
            document.getElementById('selectedClientName').textContent = c.nombre;
            document.getElementById('selectedClientBadge').style.display = 'block';
        }
    }
});
</script>
</body>
</html>
