<?php
// ARCHIVO: crm_clients.php
// DESCRIPCIÓN: Gestión de Relaciones con Clientes (CRM) - CRUD Completo + Métricas + Mensajeros
// ESTILO: Dashboard moderno PALWEB
require_once 'db.php';
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
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $nombre = trim($_POST['nombre']);
            $telefono = $_POST['telefono'];
            $email = $_POST['email'];
            $direccion = $_POST['direccion'];
            $nit = $_POST['nit_ci'];
            $nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
            $categoria = $_POST['categoria'];
            $origen = $_POST['origen'];
            $notas = $_POST['notas'];
            
            // CAMPOS NUEVOS MENSAJERÍA
            $es_mensajero = isset($_POST['es_mensajero']) ? 1 : 0;
            $vehiculo = $_POST['vehiculo'] ?? '';
            $matricula = $_POST['matricula'] ?? '';

            if ($id) {
                // UPDATE
                $sql = "UPDATE clientes SET nombre=?, telefono=?, email=?, direccion=?, nit_ci=?, fecha_nacimiento=?, categoria=?, origen=?, notas=?, es_mensajero=?, vehiculo=?, matricula=? WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $telefono, $email, $direccion, $nit, $nacimiento, $categoria, $origen, $notas, $es_mensajero, $vehiculo, $matricula, $id]);
            } else {
                // INSERT
                $sql = "INSERT INTO clientes (nombre, telefono, email, direccion, nit_ci, fecha_nacimiento, categoria, origen, notas, es_mensajero, vehiculo, matricula) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$nombre, $telefono, $email, $direccion, $nit, $nacimiento, $categoria, $origen, $notas, $es_mensajero, $vehiculo, $matricula]);
            }
            header("Location: crm_clients.php?msg=saved");
            exit;
        } catch (Exception $e) {
            die("Error al guardar: " . $e->getMessage());
        }
    }

    // --- ELIMINAR CLIENTE ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        header("Location: crm_clients.php?msg=deleted");
        exit;
    }
}

// ---------------------------------------------------------
// 📊 LECTURA DE DATOS E INTELIGENCIA (GET)
// ---------------------------------------------------------
$filter = $_GET['q'] ?? '';

// 1. Obtener Clientes
$sqlClients = "SELECT * FROM clientes WHERE activo = 1";
if ($filter) {
    $sqlClients .= " AND (nombre LIKE :q OR telefono LIKE :q OR nit_ci LIKE :q)";
}
$sqlClients .= " ORDER BY id DESC LIMIT 100";

$stmt = $pdo->prepare($sqlClients);
if ($filter) $stmt->execute(['q' => "%$filter%"]);
else $stmt->execute();
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Enriquecer datos con Ventas
foreach ($clientes as &$cli) {
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

// 3. KPIs Generales
$totalClientes = $pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$nuevosMes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE MONTH(fecha_registro) = MONTH(CURRENT_DATE())")->fetchColumn();
$vips = $pdo->query("SELECT COUNT(*) FROM clientes WHERE categoria = 'VIP'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CRM Clientes - PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .kpi-card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-3px); }
        .avatar-circle { width: 40px; height: 40px; background-color: #e9ecef; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #495057; }
        .table-card { border-radius: 12px; border: none; box-shadow: 0 2px 15px rgba(0,0,0,0.03); }
        .badge-cat-VIP { background-color: #ffd700; color: #000; }
        .badge-cat-Regular { background-color: #e2e6ea; color: #000; }
        .badge-cat-Corporativo { background-color: #0d6efd; color: #fff; }
        .badge-cat-Moroso { background-color: #dc3545; color: #fff; }
        .msj-badge { font-size: 0.7rem; background: #6f42c1; color: white; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-users text-primary"></i> Gestión de Clientes (CRM)</h3>
            <p class="text-muted mb-0">Administra perfiles, historial y categorías.</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-user-plus"></i> Nuevo Cliente</button>
            <a href="pos.php" class="btn btn-outline-secondary">Volver al POS</a>
        </div>
    </div>

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
            <div class="card kpi-card p-3 h-100 border-start border-4 border-success">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-muted fw-bold">NUEVOS (Este Mes)</small>
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
        <div class="col-md-3">
            <div class="card kpi-card p-3 h-100 bg-primary text-white">
                <div class="d-flex justify-content-between">
                    <div>
                        <small class="text-white-50 fw-bold">MARKETING</small>
                        <div class="mt-1 small">Utiliza estos datos para enviar promociones por WhatsApp o Email.</div>
                    </div>
                    <i class="fas fa-bullhorn fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-header bg-white py-3">
            <form class="d-flex gap-2" method="GET">
                <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, teléfono o carnet..." value="<?php echo htmlspecialchars($filter); ?>">
                <button class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
                <?php if($filter): ?><a href="crm_clients.php" class="btn btn-outline-secondary">Limpiar</a><?php endif; ?>
            </form>
        </div>
        <div class="card-body p-0">
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
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-3"><?php echo $initials; ?></div>
                                    <div>
                                        <div class="fw-bold text-dark">
                                            <?php echo htmlspecialchars($c['nombre']); ?>
                                            <?php if($c['es_mensajero']): ?><span class="msj-badge"><i class="fas fa-motorcycle"></i> MSJ</span><?php endif; ?>
                                        </div>
                                        <small class="text-muted"><i class="fas fa-id-card me-1"></i> <?php echo $c['nit_ci'] ?: 'S/N'; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div><i class="fas fa-phone me-1 text-muted"></i> <?php echo $c['telefono'] ?: '-'; ?></div>
                                <div class="small text-muted"><?php echo $c['direccion'] ?: 'Sin dirección'; ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo $bgClass; ?> rounded-pill"><?php echo $c['categoria']; ?></span>
                                <div class="small text-muted mt-1"><?php echo $c['origen']; ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-success">$<?php echo number_format($c['ltv'], 2); ?></div>
                                <small class="text-muted"><?php echo $c['visitas']; ?> Compras</small>
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

</div>

<div class="modal fade" id="clientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">Nuevo Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="inpId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nombre Completo *</label>
                            <input type="text" name="nombre" id="inpNombre" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Teléfono / WhatsApp</label>
                            <input type="text" name="telefono" id="inpTel" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Carnet / NIT</label>
                            <input type="text" name="nit_ci" id="inpNit" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email (Opcional)</label>
                            <input type="email" name="email" id="inpEmail" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Dirección de Entrega</label>
                            <input type="text" name="direccion" id="inpDir" class="form-control" placeholder="Calle, #Casa, Reparto...">
                        </div>

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

                        <div class="col-12"><hr class="my-2"></div>
                        <div class="col-md-4">
                            <div class="form-check form-switch pt-3">
                                <input class="form-check-input" type="checkbox" name="es_mensajero" id="inpMensajero" onchange="toggleVehiculo()">
                                <label class="form-check-label fw-bold" for="inpMensajero">🛵 Es Mensajero</label>
                            </div>
                        </div>
                        <div class="col-md-4 vehiculo-div" style="display:none;">
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
                        <div class="col-md-4 vehiculo-div" style="display:none;">
                            <label class="form-label small fw-bold">Matrícula / Chapa</label>
                            <input type="text" name="matricula" id="inpMatricula" class="form-control form-control-sm">
                        </div>
                        <div class="col-12"><hr class="my-2"></div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Notas Internas</label>
                            <textarea name="notas" id="inpNotas" class="form-control" rows="2" placeholder="Preferencias, alergias, horarios de entrega..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Datos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const modalEl = document.getElementById('clientModal');
    const modal = new bootstrap.Modal(modalEl);

    function toggleVehiculo() {
        const check = document.getElementById('inpMensajero').checked;
        document.querySelectorAll('.vehiculo-div').forEach(el => el.style.display = check ? 'block' : 'none');
    }

    function openModal() {
        document.getElementById('modalTitle').innerText = "Nuevo Cliente";
        document.getElementById('inpId').value = "";
        document.getElementById('inpNombre').value = "";
        document.getElementById('inpTel').value = "";
        document.getElementById('inpEmail').value = "";
        document.getElementById('inpNit').value = "";
        document.getElementById('inpDir').value = "";
        document.getElementById('inpNac').value = "";
        document.getElementById('inpCat').value = "Regular";
        document.getElementById('inpOrigen').value = "Local";
        document.getElementById('inpNotas').value = "";
        
        document.getElementById('inpMensajero').checked = false;
        document.getElementById('inpVehiculo').value = "";
        document.getElementById('inpMatricula').value = "";
        toggleVehiculo();

        modal.show();
    }

    function editClient(data) {
        document.getElementById('modalTitle').innerText = "Editar Cliente: " + data.nombre;
        document.getElementById('inpId').value = data.id;
        document.getElementById('inpNombre').value = data.nombre;
        document.getElementById('inpTel').value = data.telefono;
        document.getElementById('inpEmail').value = data.email;
        document.getElementById('inpNit').value = data.nit_ci;
        document.getElementById('inpDir').value = data.direccion;
        document.getElementById('inpNac').value = data.fecha_nacimiento;
        document.getElementById('inpCat').value = data.categoria;
        document.getElementById('inpOrigen').value = data.origen;
        document.getElementById('inpNotas').value = data.notas;
        
        document.getElementById('inpMensajero').checked = (data.es_mensajero == 1);
        document.getElementById('inpVehiculo').value = data.vehiculo || "";
        document.getElementById('inpMatricula').value = data.matricula || "";
        toggleVehiculo();

        modal.show();
    }

    function deleteClient(id, nombre) {
        if(confirm("¿Estás seguro de eliminar a " + nombre + "?\nEsta acción no borrará su historial de ventas, pero perderás sus datos de contacto.")) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
</script>


<?php include_once 'menu_master.php'; ?>
</body>
</html>

