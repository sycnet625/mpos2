<?php
// ARCHIVO: crm_clients.php
// DESCRIPCIÓN: Gestión de Relaciones con Clientes (CRM) PREMIUM
// - Múltiples teléfonos y direcciones por cliente
// - Distinción entre Personas y Negocios
// - Métricas avanzadas + Dashboard

require_once 'db.php';
require_once 'config_loader.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ---------------------------------------------------------
// 🧠 LÓGICA CRUD (POST)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- GUARDAR / EDITAR CLIENTE ---
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        try {
            $pdo->beginTransaction();

            // Datos básicos
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $nombre = trim($_POST['nombre']);
            $email = $_POST['email'] ?? '';
            $nit_ci = $_POST['nit_ci'] ?? '';
            $nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
            $categoria = $_POST['categoria'] ?? 'Regular';
            $origen = $_POST['origen'] ?? 'Local';
            $notas = $_POST['notas'] ?? '';
            $preferencias = $_POST['preferencias'] ?? '';

            // Datos CRM Premium
            $tipo_cliente = $_POST['tipo_cliente'] ?? 'Persona';
            $ruc = ($tipo_cliente === 'Negocio') ? ($_POST['ruc'] ?? '') : null;
            $contacto_principal = ($tipo_cliente === 'Negocio') ? ($_POST['contacto_principal'] ?? '') : null;
            $giro_negocio = ($tipo_cliente === 'Negocio') ? ($_POST['giro_negocio'] ?? '') : null;

            // Mensajería
            $es_mensajero = isset($_POST['es_mensajero']) ? 1 : 0;
            $vehiculo = $_POST['vehiculo'] ?? '';
            $matricula = $_POST['matricula'] ?? '';

            // Teléfonos y Direcciones (desde JSON)
            $telefonos = !empty($_POST['telefonos_json']) ? json_decode($_POST['telefonos_json'], true) : [];
            $direcciones = !empty($_POST['direcciones_json']) ? json_decode($_POST['direcciones_json'], true) : [];

            if ($id) {
                // UPDATE cliente
                $sql = "UPDATE clientes SET nombre=?, email=?, nit_ci=?, ruc=?, fecha_nacimiento=?,
                        categoria=?, origen=?, notas=?, preferencias=?, es_mensajero=?,
                        vehiculo=?, matricula=?, tipo_cliente=?, contacto_principal=?, giro_negocio=?
                        WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $nombre, $email, $nit_ci, $ruc, $nacimiento,
                    $categoria, $origen, $notas, $preferencias, $es_mensajero,
                    $vehiculo, $matricula, $tipo_cliente, $contacto_principal, $giro_negocio,
                    $id
                ]);

                // Eliminar teléfonos antiguos
                $pdo->prepare("DELETE FROM clientes_telefonos WHERE id_cliente = ?")->execute([$id]);

                // Eliminar direcciones antiguas
                $pdo->prepare("DELETE FROM clientes_direcciones WHERE id_cliente = ?")->execute([$id]);

                $idCliente = $id;
            } else {
                // INSERT cliente
                $sql = "INSERT INTO clientes (nombre, email, nit_ci, ruc, fecha_nacimiento,
                        categoria, origen, notas, preferencias, es_mensajero,
                        vehiculo, matricula, tipo_cliente, contacto_principal, giro_negocio)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $nombre, $email, $nit_ci, $ruc, $nacimiento,
                    $categoria, $origen, $notas, $preferencias, $es_mensajero,
                    $vehiculo, $matricula, $tipo_cliente, $contacto_principal, $giro_negocio
                ]);
                $idCliente = $pdo->lastInsertId();
            }

            // Insertar teléfonos nuevos
            $stmtTel = $pdo->prepare("INSERT INTO clientes_telefonos (id_cliente, tipo, numero, es_principal) VALUES (?, ?, ?, ?)");
            foreach ($telefonos as $tel) {
                if (!empty($tel['numero'])) {
                    $stmtTel->execute([$idCliente, $tel['tipo'] ?? 'Celular', $tel['numero'], $tel['es_principal'] ? 1 : 0]);
                }
            }

            // Insertar direcciones nuevas
            $stmtDir = $pdo->prepare("INSERT INTO clientes_direcciones (id_cliente, tipo, calle, numero, apartamento, reparto, ciudad, codigo_postal, es_principal, instrucciones)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($direcciones as $dir) {
                if (!empty($dir['calle'])) {
                    $stmtDir->execute([
                        $idCliente,
                        $dir['tipo'] ?? 'Entrega',
                        $dir['calle'],
                        $dir['numero'] ?? null,
                        $dir['apartamento'] ?? null,
                        $dir['reparto'] ?? null,
                        $dir['ciudad'] ?? null,
                        $dir['codigo_postal'] ?? null,
                        $dir['es_principal'] ? 1 : 0,
                        $dir['instrucciones'] ?? null
                    ]);
                }
            }

            $pdo->commit();
            header("Location: crm_clients.php?msg=saved");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("Error al guardar: " . htmlspecialchars($e->getMessage()));
        }
    }

    // --- ELIMINAR CLIENTE ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $id = intval($_POST['id']);
            // CASCADE elimina teléfonos y direcciones automáticamente
            $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: crm_clients.php?msg=deleted");
            exit;
        } catch (Exception $e) {
            die("Error al eliminar: " . htmlspecialchars($e->getMessage()));
        }
    }
}

// ---------------------------------------------------------
// 📋 AJAX: HISTORIAL FACTURAS / OFERTAS POR CLIENTE
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $cid = intval($_GET['id'] ?? 0);
    if (!$cid) { echo json_encode(['facturas'=>[],'ofertas'=>[]]); exit; }

    $stmtF = $pdo->prepare(
        "SELECT id, numero_factura, fecha_emision, total, estado, estado_pago
         FROM facturas WHERE cliente_nombre IN (SELECT nombre FROM clientes WHERE id=?) OR
         cliente_telefono IN (
             SELECT numero FROM clientes_telefonos WHERE id_cliente=?
         )
         ORDER BY id DESC LIMIT 30"
    );
    $stmtF->execute([$cid, $cid]);
    $facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);

    $stmtO = $pdo->prepare(
        "SELECT id, numero_oferta, fecha_emision, total, estado
         FROM ofertas WHERE id_cliente=?
         ORDER BY id DESC LIMIT 30"
    );
    $stmtO->execute([$cid]);
    $ofertas = $stmtO->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['facturas' => $facturas, 'ofertas' => $ofertas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------
// 📊 LECTURA DE DATOS E INTELIGENCIA (GET)
// ---------------------------------------------------------
$filter = $_GET['q'] ?? '';

// Obtener clientes con datos enriquecidos
$sqlClients = "SELECT c.* FROM clientes c WHERE c.activo = 1";
if ($filter) {
    $sqlClients .= " AND (c.nombre LIKE :q OR c.telefono LIKE :q OR c.nit_ci LIKE :q OR c.ruc LIKE :q)";
}
$sqlClients .= " ORDER BY c.id DESC LIMIT 100";

$stmt = $pdo->prepare($sqlClients);
if ($filter) $stmt->execute(['q' => "%$filter%"]);
else $stmt->execute();
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Enriquecer datos con Ventas + Teléfonos + Direcciones
foreach ($clientes as &$cli) {
    // Cargar teléfonos
    $stmtTel = $pdo->prepare("SELECT * FROM clientes_telefonos WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC");
    $stmtTel->execute([$cli['id']]);
    $cli['telefonos'] = $stmtTel->fetchAll(PDO::FETCH_ASSOC);

    // Cargar direcciones
    $stmtDir = $pdo->prepare("SELECT * FROM clientes_direcciones WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC");
    $stmtDir->execute([$cli['id']]);
    $cli['direcciones'] = $stmtDir->fetchAll(PDO::FETCH_ASSOC);

    // Calcular LTV
    $stmtLTV = $pdo->prepare("SELECT SUM(total) as ltv, MAX(fecha) as ultima_visita, COUNT(*) as visitas FROM ventas_cabecera WHERE cliente_nombre = ?");
    $stmtLTV->execute([$cli['nombre']]);
    $metrics = $stmtLTV->fetch(PDO::FETCH_ASSOC);

    $cli['ltv'] = floatval($metrics['ltv'] ?? 0);
    $cli['ultima_visita'] = $metrics['ultima_visita'];
    $cli['visitas'] = intval($metrics['visitas']);

    // Estado
    if ($cli['ltv'] > 0) {
        $daysSince = (time() - strtotime($cli['ultima_visita'])) / (60 * 60 * 24);
        if ($daysSince < 30) $cli['status_calc'] = '🔥 Activo';
        elseif ($daysSince < 90) $cli['status_calc'] = '⚠️ Riesgo';
        else $cli['status_calc'] = '💤 Dormido';
    } else {
        $cli['status_calc'] = '🆕 Nuevo';
    }
}
unset($cli);

// KPIs
$totalClientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$nuevosMes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE MONTH(fecha_registro) = MONTH(CURRENT_DATE())")->fetchColumn();
$vips = $pdo->query("SELECT COUNT(*) FROM clientes WHERE categoria = 'VIP'")->fetchColumn();
$negocios = $pdo->query("SELECT COUNT(*) FROM clientes WHERE tipo_cliente = 'Negocio'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CRM Clientes Premium - PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .table thead th { white-space: nowrap; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        .avatar-circle { width: 40px; height: 40px; background-color: #e9ecef; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #495057; }
        .badge-cat-VIP { background-color: #ffd700; color: #000; }
        .badge-cat-Regular { background-color: #e2e6ea; color: #000; }
        .badge-cat-Corporativo { background-color: #0d6efd; color: #fff; }
        .badge-cat-Moroso { background-color: #dc3545; color: #fff; }
        .badge-tipo-Persona { background-color: #198754; color: #fff; }
        .badge-tipo-Negocio { background-color: #0dcaf0; color: #fff; }
        .msj-badge { font-size: 0.7rem; background: #6f42c1; color: white; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
        .hide-section { display: none !important; }
        .table-dinamica { font-size: 0.85rem; }
        .table-dinamica input, .table-dinamica select { height: 32px; padding: 4px 8px; }
        .btn-agregar-fila { padding: 2px 8px; font-size: 0.75rem; }
        .hidden-input { display: none; }
        /* Fix modal scrollbars */
        .modal-dialog-scrollable .modal-body {
            overflow-y: auto !important;
            max-height: calc(100vh - 200px);
            scrollbar-gutter: stable;
        }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <!-- HERO SECTION -->
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">CRM / Clientes Premium</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-users me-2"></i>Gestión Avanzada de Clientes</h1>
                <p class="mb-3 text-white-50">Múltiples teléfonos, direcciones, y clasificación por tipo de cliente.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-address-book me-1"></i><?php echo $totalClientes; ?> clientes</span>
                    <span class="kpi-chip"><i class="fas fa-crown me-1"></i><?php echo $vips; ?> VIPs</span>
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i><?php echo $negocios; ?> negocios</span>
                    <span class="kpi-chip"><i class="fas fa-user-clock me-1"></i>+<?php echo $nuevosMes; ?> nuevos/mes</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-light fw-bold" onclick="openModal()"><i class="fas fa-user-plus me-1"></i>Nuevo Cliente</button>
                <a href="pos.php" class="btn btn-outline-light"><i class="fas fa-home me-1"></i>Volver al POS</a>
            </div>
        </div>
    </section>

    <!-- BÚSQUEDA -->
    <div class="glass-card p-3 mb-4 inventory-fade-in">
        <form class="d-flex gap-2 align-items-center" method="GET">
            <div class="input-group input-group-sm" style="max-width: 400px;">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, teléfono, carnet..." value="<?php echo htmlspecialchars($filter); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
            </div>
            <?php if($filter): ?><a href="crm_clients.php" class="btn btn-outline-danger btn-sm">Limpiar</a><?php endif; ?>
        </form>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card kpi-card p-3 h-100 border-start border-4 border-primary">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">TOTAL CLIENTES</small>
                        <h2 class="fw-bold mb-0"><?php echo $totalClientes; ?></h2>
                    </div>
                    <i class="fas fa-address-book fa-2x text-primary opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 h-100 border-start border-4 border-info">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">NEGOCIOS</small>
                        <h2 class="fw-bold mb-0 text-info"><?php echo $negocios; ?></h2>
                    </div>
                    <i class="fas fa-building fa-2x text-info opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 h-100 border-start border-4 border-success">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">NUEVOS (Mes)</small>
                        <h2 class="fw-bold mb-0 text-success">+<?php echo $nuevosMes; ?></h2>
                    </div>
                    <i class="fas fa-user-clock fa-2x text-success opacity-25"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card kpi-card p-3 h-100 border-start border-4 border-warning">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">CLIENTES VIP</small>
                        <h2 class="fw-bold mb-0 text-warning"><?php echo $vips; ?></h2>
                    </div>
                    <i class="fas fa-crown fa-2x text-warning opacity-25"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLA DE CLIENTES -->
    <div class="glass-card inventory-fade-in">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Cliente</th>
                        <th>Contacto</th>
                        <th>Categoría</th>
                        <th>Historial (LTV)</th>
                        <th>Última Visita</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($clientes as $c):
                        $initials = strtoupper(substr($c['nombre'], 0, 2));
                        $bgClass = "badge-cat-" . $c['categoria'];
                        $tipoClass = "badge-tipo-" . $c['tipo_cliente'];
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-circle"><?php echo $initials; ?></div>
                                <div>
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($c['nombre']); ?>
                                        <?php if($c['es_mensajero']): ?><span class="msj-badge"><i class="fas fa-motorcycle"></i></span><?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-id-card me-1"></i><?php echo $c['nit_ci'] ?: 'S/N'; ?>
                                        <?php if($c['ruc']): ?> | RUC: <?php echo htmlspecialchars($c['ruc']); ?><?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small">
                                <i class="fas fa-phone me-1 text-muted"></i>
                                <?php if(!empty($c['telefonos'])): ?>
                                    <strong><?php echo htmlspecialchars($c['telefonos'][0]['numero']); ?></strong>
                                    <?php if(count($c['telefonos']) > 1): ?>
                                        <span class="badge bg-secondary"><?php echo count($c['telefonos']); ?> #</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted mt-1">
                                <i class="fas fa-map-marker-alt me-1"></i>
                                <?php if(!empty($c['direcciones'])): ?>
                                    <?php echo htmlspecialchars(mb_strimwidth($c['direcciones'][0]['direccion_completa'] ?? $c['direcciones'][0]['calle'], 0, 40, '...')); ?>
                                    <?php if(count($c['direcciones']) > 1): ?>
                                        <span class="badge bg-secondary"><?php echo count($c['direcciones']); ?> dir</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Sin dirección</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?php echo $bgClass; ?> rounded-pill"><?php echo $c['categoria']; ?></span>
                            <span class="badge <?php echo $tipoClass; ?> rounded-pill mt-1"><?php echo $c['tipo_cliente']; ?></span>
                            <div class="small text-muted mt-1"><?php echo $c['origen']; ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-success">$<?php echo number_format($c['ltv'], 2); ?></div>
                            <small class="text-muted"><?php echo $c['visitas']; ?> compras</small>
                        </td>
                        <td>
                            <?php if($c['ultima_visita']): ?>
                                <div><?php echo date('d/m/Y', strtotime($c['ultima_visita'])); ?></div>
                                <span class="badge bg-light text-dark border"><?php echo $c['status_calc']; ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-info me-1" onclick="openHistory(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['nombre'])); ?>')" title="Ver Facturas y Ofertas"><i class="fas fa-history"></i></button>
                            <button class="btn btn-sm btn-outline-primary me-1" onclick='editClient(<?php echo json_encode($c); ?>)' title="Editar"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteClient(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['nombre']); ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($clientes)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No se encontraron clientes.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- MODAL CLIENTE -->
<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">Nuevo Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formCliente">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="inpId">
                    <input type="hidden" name="telefonos_json" id="telefonosJson">
                    <input type="hidden" name="direcciones_json" id="direccionesJson">

                    <!-- SECCIÓN 1: DATOS BÁSICOS -->
                    <h6 class="fw-bold mb-3 mt-4"><i class="fas fa-user me-2 text-primary"></i>Datos Básicos</h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold">Nombre Completo / Razón Social *</label>
                            <input type="text" name="nombre" id="inpNombre" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Tipo Cliente</label>
                            <div class="d-flex gap-2 mt-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_cliente" id="tipoPersona" value="Persona" checked onchange="toggleTipoCliente()">
                                    <label class="form-check-label" for="tipoPersona">👤 Persona</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="tipo_cliente" id="tipoNegocio" value="Negocio" onchange="toggleTipoCliente()">
                                    <label class="form-check-label" for="tipoNegocio">🏢 Negocio</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" name="email" id="inpEmail" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Carnet / NIT</label>
                            <input type="text" name="nit_ci" id="inpNit" class="form-control">
                        </div>
                    </div>

                    <!-- SECCIÓN 2: DATOS DE NEGOCIO (oculta por defecto) -->
                    <div id="seccionNegocio" class="hide-section">
                        <hr class="my-3">
                        <h6 class="fw-bold mb-3"><i class="fas fa-building me-2 text-info"></i>Datos del Negocio</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">RUC del Negocio *</label>
                                <input type="text" name="ruc" id="inpRuc" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Giro del Negocio *</label>
                                <input type="text" name="giro_negocio" id="inpGiroNegocio" class="form-control" placeholder="Ej: Restaurante, Farmacia...">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Contacto Principal *</label>
                                <input type="text" name="contacto_principal" id="inpContactoPrincipal" class="form-control" placeholder="Nombre del contacto directo">
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 3: TELÉFONOS -->
                    <hr class="my-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0"><i class="fas fa-phone me-2 text-success"></i>Teléfonos</h6>
                        <button type="button" class="btn btn-sm btn-success" onclick="agregarTelefono()"><i class="fas fa-plus me-1"></i>Agregar</button>
                    </div>
                    <div id="telefonosContainer">
                        <div class="alert alert-info small">
                            <i class="fas fa-info-circle me-1"></i>Haz clic en "Agregar" para añadir teléfonos
                        </div>
                    </div>

                    <!-- SECCIÓN 4: DIRECCIONES -->
                    <hr class="my-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0"><i class="fas fa-map-marker-alt me-2 text-danger"></i>Direcciones</h6>
                        <button type="button" class="btn btn-sm btn-danger" onclick="agregarDireccion()"><i class="fas fa-plus me-1"></i>Agregar</button>
                    </div>
                    <div id="direccionesContainer">
                        <div class="alert alert-info small">
                            <i class="fas fa-info-circle me-1"></i>Haz clic en "Agregar" para añadir direcciones
                        </div>
                    </div>

                    <!-- SECCIÓN 5: OTROS DATOS -->
                    <hr class="my-3">
                    <h6 class="fw-bold mb-3"><i class="fas fa-cogs me-2 text-secondary"></i>Datos Adicionales</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Categoría</label>
                            <select name="categoria" id="inpCat" class="form-select">
                                <option value="Regular">Regular</option>
                                <option value="VIP">VIP (Cliente Frecuente)</option>
                                <option value="Corporativo">Empresa</option>
                                <option value="Moroso">🔴 Moroso (Bloqueado)</option>
                                <option value="Empleado">Empleado</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Origen</label>
                            <select name="origen" id="inpOrigen" class="form-select">
                                <option value="Local">Pasante / Local</option>
                                <option value="Facebook">Facebook</option>
                                <option value="Instagram">Instagram</option>
                                <option value="Recomendacion">Recomendación</option>
                                <option value="WhatsApp">WhatsApp</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Cumpleaños</label>
                            <input type="date" name="fecha_nacimiento" id="inpNac" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch pt-3">
                                <input class="form-check-input" type="checkbox" name="es_mensajero" id="inpMensajero" onchange="toggleVehiculo()">
                                <label class="form-check-label fw-bold" for="inpMensajero">🛵 Es Mensajero</label>
                            </div>
                        </div>
                        <div class="col-md-3 vehiculo-div hide-section">
                            <label class="form-label small fw-bold">Vehículo</label>
                            <select name="vehiculo" id="inpVehiculo" class="form-select form-select-sm">
                                <option value="">- Seleccionar -</option>
                                <option value="Moto">Moto</option>
                                <option value="Moto Electrica">Moto Eléctrica</option>
                                <option value="Bicicleta">Bicicleta</option>
                                <option value="Carro">Carro</option>
                                <option value="Triciclo">Triciclo</option>
                            </select>
                        </div>
                        <div class="col-md-3 vehiculo-div hide-section">
                            <label class="form-label small fw-bold">Matrícula</label>
                            <input type="text" name="matricula" id="inpMatricula" class="form-control form-control-sm">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Notas Internas</label>
                            <textarea name="notas" id="inpNotas" class="form-control" rows="2" placeholder="Preferencias, horarios especiales..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Preferencias del Cliente</label>
                            <textarea name="preferencias" id="inpPreferencias" class="form-control" rows="2" placeholder="Productos favoritos, restricciones..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar Cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- MODAL HISTORIAL -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-history me-2"></i>Historial: <span id="histClientName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="histLoading" class="text-center py-5"><div class="spinner-border text-info"></div><div class="mt-2 text-muted">Cargando...</div></div>
                <div id="histContent" style="display:none">
                    <!-- Facturas -->
                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-file-invoice-dollar me-1"></i>Facturas Emitidas</h6>
                    <div id="histFacturasWrap" class="table-responsive mb-4">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light"><tr><th>#</th><th>Fecha</th><th class="text-end">Total</th><th class="text-center">Estado</th><th class="text-center">Pago</th><th></th></tr></thead>
                            <tbody id="histFacturasBody"></tbody>
                        </table>
                    </div>
                    <!-- Ofertas -->
                    <h6 class="fw-bold text-warning mb-3"><i class="fas fa-file-signature me-1"></i>Ofertas Comerciales</h6>
                    <div id="histOfertasWrap" class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light"><tr><th>#</th><th>Fecha</th><th class="text-end">Total</th><th class="text-center">Estado</th><th></th></tr></thead>
                            <tbody id="histOfertasBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // Estado global de teléfonos y direcciones
    let telefonosActuales = [];
    let direccionesActuales = [];
    let telefonoIndex = 0;
    let direccionIndex = 0;

    const modalEl = document.getElementById('clientModal');
    const modal = new bootstrap.Modal(modalEl);

    // ===== TELÉFONOS =====
    function agregarTelefono() {
        telefonosActuales.push({
            index: telefonoIndex,
            tipo: 'Celular',
            numero: '',
            es_principal: telefonosActuales.length === 0
        });
        telefonoIndex++;
        renderTelefonos();
    }

    function eliminarTelefono(index) {
        telefonosActuales = telefonosActuales.filter(t => t.index !== index);
        renderTelefonos();
    }

    function renderTelefonos() {
        const container = document.getElementById('telefonosContainer');
        if (telefonosActuales.length === 0) {
            container.innerHTML = '<div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>Haz clic en "Agregar" para añadir teléfonos</div>';
            return;
        }

        let html = '<table class="table table-sm table-dinamica mb-0"><tbody>';
        telefonosActuales.forEach(tel => {
            html += `
                <tr>
                    <td style="width: 110px;">
                        <select class="form-select form-select-sm" onchange="telefonosActuales.find(t => t.index === ${tel.index}).tipo = this.value">
                            <option value="Celular" ${tel.tipo === 'Celular' ? 'selected' : ''}>Celular</option>
                            <option value="Fijo" ${tel.tipo === 'Fijo' ? 'selected' : ''}>Fijo</option>
                            <option value="WhatsApp" ${tel.tipo === 'WhatsApp' ? 'selected' : ''}>WhatsApp</option>
                            <option value="Comercial" ${tel.tipo === 'Comercial' ? 'selected' : ''}>Comercial</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" placeholder="Número de teléfono" value="${tel.numero}" onchange="telefonosActuales.find(t => t.index === ${tel.index}).numero = this.value">
                    </td>
                    <td style="width: 80px;">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="telPrincipal" id="telPrincipal${tel.index}" ${tel.es_principal ? 'checked' : ''} onchange="marcarTelefonoPrincipal(${tel.index})">
                            <label class="form-check-label" for="telPrincipal${tel.index}" style="font-size: 0.75rem;">Principal</label>
                        </div>
                    </td>
                    <td style="width: 40px;">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarTelefono(${tel.index})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function marcarTelefonoPrincipal(index) {
        telefonosActuales.forEach(t => t.es_principal = (t.index === index));
    }

    // ===== DIRECCIONES =====
    function agregarDireccion() {
        direccionesActuales.push({
            index: direccionIndex,
            tipo: 'Entrega',
            calle: '',
            numero: '',
            apartamento: '',
            reparto: '',
            ciudad: '',
            codigo_postal: '',
            es_principal: direccionesActuales.length === 0,
            instrucciones: ''
        });
        direccionIndex++;
        renderDirecciones();
    }

    function eliminarDireccion(index) {
        direccionesActuales = direccionesActuales.filter(d => d.index !== index);
        renderDirecciones();
    }

    function renderDirecciones() {
        const container = document.getElementById('direccionesContainer');
        if (direccionesActuales.length === 0) {
            container.innerHTML = '<div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>Haz clic en "Agregar" para añadir direcciones</div>';
            return;
        }

        let html = '<div class="row g-2">';
        direccionesActuales.forEach(dir => {
            html += `
                <div class="col-12 border p-2 rounded bg-light">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" onchange="direccionesActuales.find(d => d.index === ${dir.index}).tipo = this.value">
                                <option value="Entrega" ${dir.tipo === 'Entrega' ? 'selected' : ''}>Entrega</option>
                                <option value="Facturación" ${dir.tipo === 'Facturación' ? 'selected' : ''}>Facturación</option>
                                <option value="Comercial" ${dir.tipo === 'Comercial' ? 'selected' : ''}>Comercial</option>
                                <option value="Almacén" ${dir.tipo === 'Almacén' ? 'selected' : ''}>Almacén</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <input type="text" class="form-control form-control-sm" placeholder="Calle" value="${dir.calle}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).calle = this.value">
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control form-control-sm" placeholder="Nº" value="${dir.numero || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).numero = this.value">
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control form-control-sm" placeholder="Apt." value="${dir.apartamento || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).apartamento = this.value">
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control form-control-sm" placeholder="Reparto" value="${dir.reparto || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).reparto = this.value">
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control form-control-sm" placeholder="Ciudad" value="${dir.ciudad || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).ciudad = this.value">
                        </div>
                        <div class="col-md-2">
                            <input type="text" class="form-control form-control-sm" placeholder="CP" value="${dir.codigo_postal || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).codigo_postal = this.value">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="radio" name="dirPrincipal" id="dirPrincipal${dir.index}" ${dir.es_principal ? 'checked' : ''} onchange="marcarDireccionPrincipal(${dir.index})">
                                <label class="form-check-label small" for="dirPrincipal${dir.index}">Principal</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <input type="text" class="form-control form-control-sm" placeholder="Instrucciones de entrega (ej: ringer 3 veces)" value="${dir.instrucciones || ''}" onchange="direccionesActuales.find(d => d.index === ${dir.index}).instrucciones = this.value">
                        </div>
                        <div class="col-12 text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarDireccion(${dir.index})"><i class="fas fa-trash me-1"></i>Eliminar</button>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function marcarDireccionPrincipal(index) {
        direccionesActuales.forEach(d => d.es_principal = (d.index === index));
    }

    // ===== UI =====
    function toggleTipoCliente() {
        const tipo = document.querySelector('input[name="tipo_cliente"]:checked').value;
        const seccion = document.getElementById('seccionNegocio');
        if (tipo === 'Negocio') {
            seccion.classList.remove('hide-section');
        } else {
            seccion.classList.add('hide-section');
        }
    }

    function toggleVehiculo() {
        const check = document.getElementById('inpMensajero').checked;
        document.querySelectorAll('.vehiculo-div').forEach(el => {
            if (check) el.classList.remove('hide-section');
            else el.classList.add('hide-section');
        });
    }

    function openModal() {
        document.getElementById('modalTitle').innerText = "Nuevo Cliente";
        document.getElementById('formCliente').reset();
        document.getElementById('inpId').value = "";

        telefonosActuales = [];
        direccionesActuales = [];
        telefonoIndex = 0;
        direccionIndex = 0;

        renderTelefonos();
        renderDirecciones();
        toggleTipoCliente();
        toggleVehiculo();

        modal.show();
    }

    function editClient(data) {
        document.getElementById('modalTitle').innerText = "Editar Cliente: " + data.nombre;
        document.getElementById('inpId').value = data.id;
        document.getElementById('inpNombre').value = data.nombre;
        document.getElementById('inpEmail').value = data.email || '';
        document.getElementById('inpNit').value = data.nit_ci || '';
        document.getElementById('inpRuc').value = data.ruc || '';
        document.getElementById('inpGiroNegocio').value = data.giro_negocio || '';
        document.getElementById('inpContactoPrincipal').value = data.contacto_principal || '';
        document.getElementById('inpNac').value = data.fecha_nacimiento || '';
        document.getElementById('inpCat').value = data.categoria || 'Regular';
        document.getElementById('inpOrigen').value = data.origen || 'Local';
        document.getElementById('inpNotas').value = data.notas || '';
        document.getElementById('inpPreferencias').value = data.preferencias || '';

        // Tipo cliente
        if (data.tipo_cliente === 'Negocio') {
            document.getElementById('tipoNegocio').checked = true;
        } else {
            document.getElementById('tipoPersona').checked = true;
        }

        // Mensajero
        document.getElementById('inpMensajero').checked = (data.es_mensajero == 1);
        document.getElementById('inpVehiculo').value = data.vehiculo || '';
        document.getElementById('inpMatricula').value = data.matricula || '';

        // Cargar teléfonos
        telefonosActuales = [];
        telefonoIndex = 0;
        if (data.telefonos && data.telefonos.length > 0) {
            data.telefonos.forEach(tel => {
                telefonosActuales.push({
                    index: telefonoIndex,
                    tipo: tel.tipo,
                    numero: tel.numero,
                    es_principal: tel.es_principal == 1
                });
                telefonoIndex++;
            });
        }

        // Cargar direcciones
        direccionesActuales = [];
        direccionIndex = 0;
        if (data.direcciones && data.direcciones.length > 0) {
            data.direcciones.forEach(dir => {
                direccionesActuales.push({
                    index: direccionIndex,
                    tipo: dir.tipo,
                    calle: dir.calle,
                    numero: dir.numero || '',
                    apartamento: dir.apartamento || '',
                    reparto: dir.reparto || '',
                    ciudad: dir.ciudad || '',
                    codigo_postal: dir.codigo_postal || '',
                    es_principal: dir.es_principal == 1,
                    instrucciones: dir.instrucciones || ''
                });
                direccionIndex++;
            });
        }

        renderTelefonos();
        renderDirecciones();
        toggleTipoCliente();
        toggleVehiculo();

        modal.show();
    }

    function deleteClient(id, nombre) {
        if(confirm("¿Estás seguro de eliminar a " + nombre + "?\nEsta acción no borrará su historial de ventas.")) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    // ===== SUBMIT DEL FORMULARIO =====
    document.getElementById('formCliente').addEventListener('submit', function(e) {
        e.preventDefault();

        // Validaciones básicas
        const nombre = document.getElementById('inpNombre').value.trim();
        if (!nombre) {
            alert('El nombre del cliente es obligatorio');
            return;
        }

        const tipo = document.querySelector('input[name="tipo_cliente"]:checked').value;
        if (tipo === 'Negocio') {
            const ruc = document.getElementById('inpRuc').value.trim();
            const contacto = document.getElementById('inpContactoPrincipal').value.trim();
            const giro = document.getElementById('inpGiroNegocio').value.trim();

            if (!ruc || !contacto || !giro) {
                alert('Para un negocio, debe completar: RUC, Contacto Principal y Giro del Negocio');
                return;
            }
        }

        // Validar que hay al menos un teléfono o dirección
        if (telefonosActuales.length === 0 && direccionesActuales.length === 0) {
            alert('Debe agregar al menos un teléfono o dirección');
            return;
        }

        // Convertir arrays a JSON
        document.getElementById('telefonosJson').value = JSON.stringify(telefonosActuales);
        document.getElementById('direccionesJson').value = JSON.stringify(direccionesActuales);

        // Enviar formulario
        this.submit();
    });

    // ===== HISTORIAL FACTURAS / OFERTAS =====
    function openHistory(clientId, clientName) {
        document.getElementById('histClientName').textContent = clientName;
        document.getElementById('histLoading').style.display = 'block';
        document.getElementById('histContent').style.display = 'none';
        const modal = new bootstrap.Modal(document.getElementById('historyModal'));
        modal.show();
        fetch(`crm_clients.php?action=get_history&id=${clientId}`)
            .then(r => r.json())
            .then(data => {
                // Facturas
                const fBody = document.getElementById('histFacturasBody');
                fBody.innerHTML = '';
                if (!data.facturas.length) {
                    fBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Sin facturas registradas</td></tr>';
                } else {
                    data.facturas.forEach(f => {
                        const st = f.estado === 'ANULADA' ? 'bg-secondary' : 'bg-success';
                        const pg = f.estado_pago === 'PAGADA' ? 'bg-primary' : 'bg-warning text-dark';
                        const fecha = new Date(f.fecha_emision).toLocaleDateString('es-ES', {day:'2-digit',month:'2-digit',year:'numeric'});
                        fBody.innerHTML += `<tr>
                            <td class="fw-bold text-primary">#${f.numero_factura}</td>
                            <td>${fecha}</td>
                            <td class="text-end fw-bold">$${parseFloat(f.total).toFixed(2)}</td>
                            <td class="text-center"><span class="badge ${st} rounded-pill">${f.estado}</span></td>
                            <td class="text-center"><span class="badge ${pg} rounded-pill">${f.estado_pago}</span></td>
                            <td><a href="invoice_print.php?id=${f.id}" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm"><i class="fas fa-print"></i></a></td>
                        </tr>`;
                    });
                }
                // Ofertas
                const oBody = document.getElementById('histOfertasBody');
                oBody.innerHTML = '';
                if (!data.ofertas.length) {
                    oBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin ofertas registradas</td></tr>';
                } else {
                    const stMap = { 'PENDIENTE': 'bg-warning text-dark', 'APROBADA': 'bg-success', 'FACTURADA': 'bg-primary', 'RECHAZADA': 'bg-secondary' };
                    data.ofertas.forEach(o => {
                        const fecha = new Date(o.fecha_emision).toLocaleDateString('es-ES', {day:'2-digit',month:'2-digit',year:'numeric'});
                        oBody.innerHTML += `<tr>
                            <td class="fw-bold text-warning">${o.numero_oferta}</td>
                            <td>${fecha}</td>
                            <td class="text-end fw-bold">$${parseFloat(o.total).toFixed(2)}</td>
                            <td class="text-center"><span class="badge ${stMap[o.estado]||'bg-secondary'} rounded-pill">${o.estado}</span></td>
                            <td><a href="offer_print.php?id=${o.id}" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm"><i class="fas fa-print"></i></a></td>
                        </tr>`;
                    });
                }
                document.getElementById('histLoading').style.display = 'none';
                document.getElementById('histContent').style.display = 'block';
            })
            .catch(() => {
                document.getElementById('histLoading').innerHTML = '<div class="text-danger">Error al cargar el historial</div>';
            });
    }

    // Mostrar mensaje de éxito si existe
    window.addEventListener('load', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('msg')) {
            const msg = urlParams.get('msg');
            if (msg === 'saved') {
                alert('✅ Cliente guardado correctamente');
            } else if (msg === 'deleted') {
                alert('✅ Cliente eliminado correctamente');
            }
        }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
