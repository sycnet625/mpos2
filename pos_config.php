<?php
// ARCHIVO: /var/www/palweb/api/pos_config.php

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

// CONEXIÓN BD (Para obtener categorías disponibles)
require_once 'db.php';

// ARCHIVO DE CONFIGURACIÓN
$configFile = __DIR__ . '/pos.cfg';

// VALORES POR DEFECTO
$defaultConfig = [
    "tienda_nombre" => "MI TIENDA",
    "direccion" => "Dirección del Negocio",
    "telefono" => "000-0000",
    "mensaje_final" => "¡Gracias por su compra!",
    "id_empresa" => 1,
    "id_sucursal" => 1,
    "id_almacen" => 1,
    "mostrar_materias_primas" => false,
    "mostrar_servicios" => true,
    "categorias_ocultas" => [],
    "semana_inicio_dia" => 1,
    "reserva_limpieza_pct" => 10,
    "cajeros" => [
        ["nombre" => "Admin", "pin" => "0000"]
    ],
    // Nuevos parámetros de costos
    "salario_elaborador_pct" => 0,
    "reserva_negocio_pct" => 0,
    "depreciacion_equipos_pct" => 0,
    "kiosco_solo_stock" => false,
    "customer_display_chime_type" => "mixkit_bell",
    "customer_display_insect" => "mosca"
];

// 1. CARGAR CONFIGURACIÓN ACTUAL
$currentConfig = $defaultConfig;
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        $currentConfig = array_merge($defaultConfig, $loaded);
    }
}

// 2. OBTENER CATEGORÍAS DE LA BD (Para el selector de ocultas)
try {
    $stmtCat = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE activo = 1 ORDER BY categoria");
    $dbCategories = $stmtCat->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $dbCategories = [];
}

$msg = "";
$msgType = "";

// 3. PROCESAR GUARDADO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Datos Básicos
        $newConfig = [
            "tienda_nombre" => trim($_POST['tienda_nombre']),
            "direccion" => trim($_POST['direccion']),
            "telefono" => trim($_POST['telefono']),
            "mensaje_final" => trim($_POST['mensaje_final']),
            "id_empresa" => intval($_POST['id_empresa']),
            "id_sucursal" => intval($_POST['id_sucursal']),
            "id_almacen" => intval($_POST['id_almacen']),
            "mostrar_materias_primas" => isset($_POST['mostrar_materias_primas']),
            "mostrar_servicios" => isset($_POST['mostrar_servicios']),
            "categorias_ocultas" => isset($_POST['categorias_ocultas']) ? $_POST['categorias_ocultas'] : [],
            "semana_inicio_dia" => intval($_POST['semana_inicio_dia'] ?? 1),
            "reserva_limpieza_pct" => floatval($_POST['reserva_limpieza_pct'] ?? 10),
            "cajeros" => [],
            // Guardar nuevos parámetros
            "salario_elaborador_pct" => floatval($_POST['salario_elaborador_pct']),
            "reserva_negocio_pct" => floatval($_POST['reserva_negocio_pct']),
            "depreciacion_equipos_pct" => floatval($_POST['depreciacion_equipos_pct']),
            "kiosco_solo_stock" => isset($_POST['kiosco_solo_stock']),
            "customer_display_chime_type" => $_POST['customer_display_chime_type'] ?? 'mixkit_bell',
            "customer_display_insect" => in_array($_POST['customer_display_insect'] ?? 'mosca', ['mosca', 'mariposa', 'mariquita'])
                ? $_POST['customer_display_insect'] : 'mosca'
        ];

        // Procesar Cajeros Dinámicos
        if (isset($_POST['cajero_nombre']) && is_array($_POST['cajero_nombre'])) {
            for ($i = 0; $i < count($_POST['cajero_nombre']); $i++) {
                $nombre = trim($_POST['cajero_nombre'][$i]);
                $pin = trim($_POST['cajero_pin'][$i]);
                if (!empty($nombre) && !empty($pin)) {
                    $newConfig['cajeros'][] = ["nombre" => $nombre, "pin" => $pin];
                }
            }
        }

        // Validar que haya al menos un cajero
        if (empty($newConfig['cajeros'])) {
            $newConfig['cajeros'][] = ["nombre" => "Admin", "pin" => "0000"];
        }

        // Guardar JSON
        if (file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $msg = "Configuración guardada exitosamente.";
            $msgType = "success";
            $currentConfig = $newConfig; // Refrescar vista
        } else {
            throw new Exception("No se pudo escribir en el archivo pos.cfg. Verifique permisos.");
        }

    } catch (Exception $e) {
        $msg = "Error al guardar: " . $e->getMessage();
        $msgType = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración POS | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-header { background-color: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .form-label { font-weight: 500; color: #555; font-size: 0.9rem; }
        .cajero-row { background: #f8f9fa; border-radius: 8px; padding: 10px; margin-bottom: 10px; border: 1px solid #e9ecef; }
        .insect-card { border: 2px solid #dee2e6; border-radius: 14px; padding: 10px 14px; cursor: pointer; transition: border-color 0.2s, background 0.2s; background: white; width: 120px; text-align: center; }
        .insect-option input:checked + .insect-card { border-color: #0d6efd; background: #eef3ff; }
        .insect-option:hover .insect-card { border-color: #6ea8fe; }
    </style>
</head>
<body class="p-4">

<div class="container" style="max-width: 900px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-cogs text-primary"></i> Configuración del Sistema</h3>
            <p class="text-muted mb-0">Ajustes generales del Punto de Venta</p>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Volver</a>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show shadow-sm">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        
        <div class="card">
            <div class="card-header text-primary"><i class="fas fa-store"></i> Datos del Negocio (Ticket)</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nombre de la Tienda</label>
                        <input type="text" name="tienda_nombre" class="form-control" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Dirección</label>
                        <input type="text" name="direccion" class="form-control" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mensaje Final (Pie de Ticket)</label>
                        <input type="text" name="mensaje_final" class="form-control" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-danger"><i class="fas fa-server"></i> Parámetros Técnicos</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">ID Empresa</label>
                        <input type="number" name="id_empresa" class="form-control" value="<?php echo $currentConfig['id_empresa']; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ID Sucursal</label>
                        <input type="number" name="id_sucursal" class="form-control" value="<?php echo $currentConfig['id_sucursal']; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ID Almacén (Stock)</label>
                        <input type="number" name="id_almacen" class="form-control" value="<?php echo $currentConfig['id_almacen']; ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-warning"><i class="fas fa-file-invoice-dollar"></i> Ficha de Costo (Producción)</div>
            <div class="card-body">
                <div class="alert alert-light border small text-muted">
                    <i class="fas fa-info-circle me-1"></i> Estos porcentajes se calculan sobre el <strong>Precio de Venta</strong> del lote/producto y se descuentan de la Ganancia Bruta en los reportes de producción.
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Salario Elaborador (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="salario_elaborador_pct" class="form-control" value="<?php echo $currentConfig['salario_elaborador_pct']; ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reserva Negocio (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="reserva_negocio_pct" class="form-control" value="<?php echo $currentConfig['reserva_negocio_pct']; ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Depreciación Equipos (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" name="depreciacion_equipos_pct" class="form-control" value="<?php echo $currentConfig['depreciacion_equipos_pct']; ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-success"><i class="fas fa-desktop"></i> Preferencias del POS</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="mostrar_materias_primas" id="chkMateria" <?php echo $currentConfig['mostrar_materias_primas'] ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="chkMateria">Mostrar Materias Primas</label>
                            <div class="form-text">Permite vender insumos directamente en el POS.</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="mostrar_servicios" id="chkServicio" <?php echo $currentConfig['mostrar_servicios'] ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="chkServicio">Mostrar Servicios</label>
                            <div class="form-text">Muestra productos marcados como servicios (sin stock).</div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label fw-bold">Día de inicio de semana</label>
                            <select name="semana_inicio_dia" class="form-select">
                                <option value="1" <?php echo ($currentConfig['semana_inicio_dia'] ?? 1) == 1 ? 'selected' : ''; ?>>Lunes (Estándar)</option>
                                <option value="0" <?php echo ($currentConfig['semana_inicio_dia'] ?? 1) == 0 ? 'selected' : ''; ?>>Domingo</option>
                                <option value="6" <?php echo ($currentConfig['semana_inicio_dia'] ?? 1) == 6 ? 'selected' : ''; ?>>Sábado</option>
                            </select>
                        </div>
                        <div class="mt-3">
                            <label class="form-label fw-bold">Reserva Ganancia Limpia (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.1" name="reserva_limpieza_pct" class="form-control" value="<?php echo $currentConfig['reserva_limpieza_pct'] ?? 10; ?>">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">Porcentaje a deducir de la ganancia bruta para calcular la ganancia limpia.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Categorías Ocultas</label>
                        <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto; background: #fff;">
                            <?php if(empty($dbCategories)): ?>
                                <small class="text-muted">No hay categorías registradas.</small>
                            <?php else: ?>
                                <?php foreach($dbCategories as $cat): 
                                    $isChecked = in_array($cat, $currentConfig['categorias_ocultas']) ? 'checked' : '';
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($cat); ?>" id="cat_<?php echo md5($cat); ?>" <?php echo $isChecked; ?>>
                                    <label class="form-check-label small" for="cat_<?php echo md5($cat); ?>">
                                        <?php echo htmlspecialchars($cat); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="form-text">Las categorías seleccionadas NO aparecerán en el POS.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-primary"><i class="fas fa-mobile-alt"></i> Configuración del Kiosco (Autopedido)</div>
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="kiosco_solo_stock" id="chkKioscoStock" <?php echo ($currentConfig['kiosco_solo_stock'] ?? false) ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="chkKioscoStock">Mostrar solo productos con existencias</label>
                    <div class="form-text">Si se activa, el Kiosco (client_order.php) ocultará automáticamente los productos que no tengan stock.</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-dark" style="background-color: #e3f2fd;"><i class="fas fa-tv"></i> Pantalla del Cliente</div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-5">
                        <label class="form-label fw-bold">Sonido de Campanadas (Hora en Punto)</label>
                        <select name="customer_display_chime_type" class="form-select">
                            <option value="mixkit_bell" <?php echo ($currentConfig['customer_display_chime_type'] ?? 'mixkit_bell') == 'mixkit_bell' ? 'selected' : ''; ?>>Campana Mixkit (Actual)</option>
                            <option value="cuckoo" <?php echo ($currentConfig['customer_display_chime_type'] ?? '') == 'cuckoo' ? 'selected' : ''; ?>>Pájaro Cu-cú</option>
                            <option value="church" <?php echo ($currentConfig['customer_display_chime_type'] ?? '') == 'church' ? 'selected' : ''; ?>>Campana de Iglesia</option>
                        </select>
                        <div class="form-text">Selecciona el sonido que escuchará el cliente cada hora.</div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-bold">Insecto Animado</label>
                        <?php $selInsect = $currentConfig['customer_display_insect'] ?? 'mosca'; ?>
                        <div class="d-flex gap-3 flex-wrap">

                            <!-- MOSCA -->
                            <label class="insect-option">
                                <input type="radio" name="customer_display_insect" value="mosca" class="d-none" <?php echo $selInsect === 'mosca' ? 'checked' : ''; ?>>
                                <div class="insect-card">
                                    <svg viewBox="0 0 100 100" style="width:64px;height:64px">
                                        <ellipse fill="#111" cx="50" cy="55" rx="15" ry="25"/>
                                        <circle fill="#111" cx="50" cy="30" r="12"/>
                                        <path fill="rgba(255,255,255,0.65)" stroke="#ccc" stroke-width="1" d="M50 40 Q 90 10 95 40 T 50 60"/>
                                        <path fill="rgba(255,255,255,0.65)" stroke="#ccc" stroke-width="1" d="M50 40 Q 10 10 5 40 T 50 60"/>
                                        <line stroke="#000" stroke-width="3" x1="40" y1="45" x2="20" y2="35"/>
                                        <line stroke="#000" stroke-width="3" x1="60" y1="45" x2="80" y2="35"/>
                                        <line stroke="#000" stroke-width="3" x1="40" y1="65" x2="15" y2="75"/>
                                        <line stroke="#000" stroke-width="3" x1="60" y1="65" x2="85" y2="75"/>
                                    </svg>
                                    <div class="small fw-bold mt-1 text-dark">Mosca</div>
                                </div>
                            </label>

                            <!-- MARIPOSA MONARCA -->
                            <label class="insect-option">
                                <input type="radio" name="customer_display_insect" value="mariposa" class="d-none" <?php echo $selInsect === 'mariposa' ? 'checked' : ''; ?>>
                                <div class="insect-card">
                                    <svg viewBox="0 0 100 100" style="width:64px;height:64px">
                                        <path fill="#E8711A" stroke="#1a0a00" stroke-width="2" d="M50,50 C35,30 15,15 5,28 C0,45 20,65 48,68"/>
                                        <path fill="#E8711A" stroke="#1a0a00" stroke-width="2" d="M50,50 C65,30 85,15 95,28 C100,45 80,65 52,68"/>
                                        <path fill="#E8711A" stroke="#1a0a00" stroke-width="2" d="M50,62 C35,68 18,78 14,92 C28,100 44,85 50,75"/>
                                        <path fill="#E8711A" stroke="#1a0a00" stroke-width="2" d="M50,62 C65,68 82,78 86,92 C72,100 56,85 50,75"/>
                                        <path d="M48,67 C35,52 18,38 10,28" stroke="#1a0a00" stroke-width="1.2" fill="none"/>
                                        <path d="M52,67 C65,52 82,38 90,28" stroke="#1a0a00" stroke-width="1.2" fill="none"/>
                                        <circle cx="9" cy="30" r="2.2" fill="white"/>
                                        <circle cx="91" cy="30" r="2.2" fill="white"/>
                                        <circle cx="17" cy="89" r="2" fill="white"/>
                                        <circle cx="83" cy="89" r="2" fill="white"/>
                                        <ellipse fill="#1a0a00" cx="50" cy="60" rx="3.5" ry="20"/>
                                        <circle fill="#1a0a00" cx="50" cy="38" r="5"/>
                                        <line x1="48" y1="34" x2="36" y2="16" stroke="#1a0a00" stroke-width="1.5"/>
                                        <circle cx="36" cy="16" r="2.5" fill="#1a0a00"/>
                                        <line x1="52" y1="34" x2="64" y2="16" stroke="#1a0a00" stroke-width="1.5"/>
                                        <circle cx="64" cy="16" r="2.5" fill="#1a0a00"/>
                                    </svg>
                                    <div class="small fw-bold mt-1 text-dark">Mariposa</div>
                                </div>
                            </label>

                            <!-- MARIQUITA -->
                            <label class="insect-option">
                                <input type="radio" name="customer_display_insect" value="mariquita" class="d-none" <?php echo $selInsect === 'mariquita' ? 'checked' : ''; ?>>
                                <div class="insect-card">
                                    <svg viewBox="0 0 100 100" style="width:64px;height:64px">
                                        <ellipse fill="#CC1010" stroke="#111" stroke-width="2" cx="50" cy="63" rx="28" ry="26"/>
                                        <line x1="50" y1="38" x2="50" y2="89" stroke="#111" stroke-width="2.5"/>
                                        <circle fill="#111" cx="37" cy="54" r="6.5"/>
                                        <circle fill="#111" cx="63" cy="54" r="6.5"/>
                                        <circle fill="#111" cx="35" cy="70" r="5.5"/>
                                        <circle fill="#111" cx="65" cy="70" r="5.5"/>
                                        <circle fill="#111" cx="40" cy="84" r="4.5"/>
                                        <circle fill="#111" cx="60" cy="84" r="4.5"/>
                                        <ellipse fill="#111" cx="50" cy="38" rx="17" ry="13"/>
                                        <circle fill="white" cx="44" cy="34" r="3.5"/>
                                        <circle fill="white" cx="56" cy="34" r="3.5"/>
                                        <circle fill="#111" cx="44" cy="34" r="1.8"/>
                                        <circle fill="#111" cx="56" cy="34" r="1.8"/>
                                        <line x1="44" y1="27" x2="31" y2="11" stroke="#111" stroke-width="1.8"/>
                                        <circle fill="#111" cx="31" cy="11" r="2.8"/>
                                        <line x1="56" y1="27" x2="69" y2="11" stroke="#111" stroke-width="1.8"/>
                                        <circle fill="#111" cx="69" cy="11" r="2.8"/>
                                    </svg>
                                    <div class="small fw-bold mt-1 text-dark">Mariquita</div>
                                </div>
                            </label>

                        </div>
                        <div class="form-text mt-2">Insecto decorativo animado en la pantalla del cliente.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header text-info"><i class="fas fa-users"></i> Gestión de Cajeros</div>
            <div class="card-body">
                <div id="cajerosContainer">
                    <?php foreach($currentConfig['cajeros'] as $cajero): ?>
                    <div class="row g-2 align-items-center cajero-row">
                        <div class="col-5">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fas fa-user"></i></span>
                                <input type="text" name="cajero_nombre[]" class="form-control" placeholder="Nombre" value="<?php echo htmlspecialchars($cajero['nombre']); ?>" required>
                            </div>
                        </div>
                        <div class="col-5">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fas fa-key"></i></span>
                                <input type="text" name="cajero_pin[]" class="form-control" placeholder="PIN (4 dígitos)" value="<?php echo htmlspecialchars($cajero['pin']); ?>" required pattern="\d{4,}" title="Mínimo 4 dígitos">
                            </div>
                        </div>
                        <div class="col-2 text-end">
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeCajero(this)"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addCajero()">
                    <i class="fas fa-plus-circle"></i> Agregar Cajero
                </button>
            </div>
        </div>

        <div class="d-grid gap-2 mb-5">
            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow">
                <i class="fas fa-save me-2"></i> GUARDAR CAMBIOS
            </button>
        </div>

    </form>
</div>

<script>
    function addCajero() {
        const container = document.getElementById('cajerosContainer');
        const div = document.createElement('div');
        div.className = 'row g-2 align-items-center cajero-row';
        div.innerHTML = `
            <div class="col-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-user"></i></span>
                    <input type="text" name="cajero_nombre[]" class="form-control" placeholder="Nombre" required>
                </div>
            </div>
            <div class="col-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-key"></i></span>
                    <input type="text" name="cajero_pin[]" class="form-control" placeholder="PIN (4 dígitos)" required pattern="\\d{4,}">
                </div>
            </div>
            <div class="col-2 text-end">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeCajero(this)"><i class="fas fa-trash"></i></button>
            </div>
        `;
        container.appendChild(div);
    }

    function removeCajero(btn) {
        const container = document.getElementById('cajerosContainer');
        if (container.children.length > 1) {
            btn.closest('.cajero-row').remove();
        } else {
            alert("Debe existir al menos un cajero.");
        }
    }
</script>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
