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
    "customer_display_insect" => "mosca",
    // Mensajería / Entrega a domicilio
    "mensajeria_tarifa_km" => 150,
    // Pasarela de pagos online (Transferencia)
    "numero_tarjeta" => "",
    "titular_tarjeta" => "",
    "banco_tarjeta" => "Bandec / BPA",
    "facebook_url" => "",
    "twitter_url" => "",
    "instagram_url" => "",
    "youtube_url" => "",
    // Diseño del ticket
    "ticket_logo" => "",
    "ticket_slogan" => "",
    "ticket_mostrar_uuid" => false,
    "ticket_mostrar_canal" => true,
    "ticket_mostrar_cajero" => true,
    "ticket_mostrar_qr" => true,
    "ticket_mostrar_items_count" => true,
    // Multi-divisa y métodos de pago
    "tipo_cambio_usd"    => 385.00,
    "tipo_cambio_mlc"    => 310.00,
    "moneda_default_pos" => "CUP",
    "metodos_pago" => [
        ["id"=>"Efectivo",      "nombre"=>"Efectivo",      "icono"=>"fa-money-bill-wave","color_bootstrap"=>"success","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""],
        ["id"=>"Transferencia", "nombre"=>"Transferencia", "icono"=>"fa-university",     "color_bootstrap"=>"primary","activo"=>true,"requiere_codigo"=>true, "aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>true, "es_especial"=>false,"texto_especial"=>""],
        ["id"=>"Tarjeta",       "nombre"=>"Tarjeta/Gasto", "icono"=>"fa-credit-card",    "color_bootstrap"=>"warning","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>false,"es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""],
    ],
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
                ? $_POST['customer_display_insect'] : 'mosca',
            // Mensajería
            "mensajeria_tarifa_km" => floatval($_POST['mensajeria_tarifa_km'] ?? 150),
            // Pasarela de pagos (transferencia)
            "numero_tarjeta"  => trim($_POST['numero_tarjeta']  ?? ''),
            "titular_tarjeta" => trim($_POST['titular_tarjeta'] ?? ''),
            "banco_tarjeta"   => trim($_POST['banco_tarjeta']   ?? 'Bandec / BPA'),
            "facebook_url"    => trim($_POST['facebook_url']    ?? ''),
            "twitter_url"     => trim($_POST['twitter_url']     ?? ''),
            "instagram_url"   => trim($_POST['instagram_url']   ?? ''),
            "youtube_url"     => trim($_POST['youtube_url']     ?? ''),
            // Multi-divisa
            "tipo_cambio_usd"    => floatval($_POST['tipo_cambio_usd']    ?? 385),
            "tipo_cambio_mlc"    => floatval($_POST['tipo_cambio_mlc']    ?? 310),
            "moneda_default_pos" => in_array($_POST['moneda_default_pos'] ?? 'CUP', ['CUP','USD','MLC']) ? $_POST['moneda_default_pos'] : 'CUP',
            "metodos_pago"       => [],
        ];

        // Procesar Métodos de Pago
        if (isset($_POST['mp_id']) && is_array($_POST['mp_id'])) {
            $coloresValidos = ['success','primary','secondary','warning','danger','info','dark'];
            for ($i = 0; $i < count($_POST['mp_id']); $i++) {
                $mpId = trim($_POST['mp_id'][$i] ?? '');
                if (empty($mpId)) continue;
                $mpColor = $_POST['mp_color'][$i] ?? 'secondary';
                $newConfig['metodos_pago'][] = [
                    "id"               => $mpId,
                    "nombre"           => trim($_POST['mp_nombre'][$i]  ?? $mpId),
                    "icono"            => trim($_POST['mp_icono'][$i]   ?? 'fa-money-bill-wave'),
                    "color_bootstrap"  => in_array($mpColor, $coloresValidos) ? $mpColor : 'secondary',
                    "activo"           => isset($_POST['mp_activo'][$i]),
                    "requiere_codigo"  => isset($_POST['mp_requiere_codigo'][$i]),
                    "aplica_pos"       => isset($_POST['mp_aplica_pos'][$i]),
                    "aplica_shop"      => isset($_POST['mp_aplica_shop'][$i]),
                    "es_transferencia" => isset($_POST['mp_es_transferencia'][$i]),
                    "es_especial"      => isset($_POST['mp_es_especial'][$i]),
                    "texto_especial"   => trim($_POST['mp_texto_especial'][$i] ?? ''),
                ];
            }
        }
        if (empty($newConfig['metodos_pago'])) {
            $newConfig['metodos_pago'] = $defaultConfig['metodos_pago'] ?? [];
        }

        // Procesar Cajeros Dinámicos
        if (isset($_POST['cajero_nombre']) && is_array($_POST['cajero_nombre'])) {
            $rolesValidos = ['cajero', 'supervisor', 'admin'];
            for ($i = 0; $i < count($_POST['cajero_nombre']); $i++) {
                $nombre = trim($_POST['cajero_nombre'][$i]);
                $pin = trim($_POST['cajero_pin'][$i]);
                $rol = in_array($_POST['cajero_rol'][$i] ?? '', $rolesValidos) ? $_POST['cajero_rol'][$i] : 'cajero';
                if (!empty($nombre) && !empty($pin)) {
                    $newConfig['cajeros'][] = ["nombre" => $nombre, "pin" => $pin, "rol" => $rol];
                }
            }
        }

        // Validar que haya al menos un cajero
        if (empty($newConfig['cajeros'])) {
            $newConfig['cajeros'][] = ["nombre" => "Admin", "pin" => "0000"];
        }

        // ---- Logo del ticket ----
        $newConfig['ticket_logo'] = $currentConfig['ticket_logo'] ?? '';
        if (!empty($_POST['ticket_logo_remove'])) {
            foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
                // Limpiar rutas antiguas (assets/) y nueva (assets/img/)
                foreach (['/assets/ticket_logo.', '/assets/img/ticket_logo.'] as $prefix) {
                    $f = __DIR__ . $prefix . $ext;
                    if (file_exists($f)) unlink($f);
                }
            }
            $newConfig['ticket_logo'] = '';
        } elseif (isset($_FILES['ticket_logo']) && $_FILES['ticket_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = mime_content_type($_FILES['ticket_logo']['tmp_name']);
            if (isset($allowed[$mime]) && $_FILES['ticket_logo']['size'] <= 2 * 1024 * 1024) {
                $ext = $allowed[$mime];
                // Eliminar logos anteriores en ambas ubicaciones posibles
                foreach (array_values($allowed) as $e) {
                    foreach (['/assets/ticket_logo.', '/assets/img/ticket_logo.'] as $prefix) {
                        $f = __DIR__ . $prefix . $e;
                        if (file_exists($f)) unlink($f);
                    }
                }
                $dest = __DIR__ . '/assets/img/ticket_logo.' . $ext;
                if (move_uploaded_file($_FILES['ticket_logo']['tmp_name'], $dest)) {
                    $newConfig['ticket_logo'] = 'assets/img/ticket_logo.' . $ext;
                } else {
                    throw new Exception("No se pudo guardar el logo. Verifique permisos en assets/img/.");
                }
            } else {
                throw new Exception("El logo debe ser JPG, PNG o WebP y no superar 2MB.");
            }
        }

        // ---- Otros campos del diseño ----
        $newConfig['ticket_slogan']             = substr(trim($_POST['ticket_slogan'] ?? ''), 0, 100);
        $newConfig['ticket_mostrar_uuid']        = isset($_POST['ticket_mostrar_uuid']);
        $newConfig['ticket_mostrar_canal']       = isset($_POST['ticket_mostrar_canal']);
        $newConfig['ticket_mostrar_cajero']      = isset($_POST['ticket_mostrar_cajero']);
        $newConfig['ticket_mostrar_qr']          = isset($_POST['ticket_mostrar_qr']);
        $newConfig['ticket_mostrar_items_count'] = isset($_POST['ticket_mostrar_items_count']);

        // Guardar JSON — preservar campos del cfg que no están en el form (ej: vapid_*, sync_api_key)
        $saveConfig = array_merge($currentConfig, $newConfig);
        if (file_put_contents($configFile, json_encode($saveConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
            $msg = "Configuración guardada exitosamente.";
            $msgType = "success";
            $currentConfig = $saveConfig; // Refrescar vista
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
        #configTabs { border-bottom: 2px solid #dee2e6; flex-wrap: wrap; }
        #configTabs .nav-link { color: #555; font-weight: 500; font-size: 0.9rem; border: none; border-bottom: 3px solid transparent; padding: 10px 16px; }
        #configTabs .nav-link:hover { color: #0d6efd; border-bottom-color: #cfe2ff; }
        #configTabs .nav-link.active { color: #0d6efd; border-bottom-color: #0d6efd; background: transparent; }
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

    <form method="POST" enctype="multipart/form-data">

        <!-- ===== TABS DE NAVEGACIÓN ===== -->
        <ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-negocio" data-bs-toggle="tab" data-bs-target="#pane-negocio" type="button" role="tab">
                    <i class="fas fa-store me-1"></i> Negocio
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-pos" data-bs-toggle="tab" data-bs-target="#pane-pos" type="button" role="tab">
                    <i class="fas fa-cash-register me-1"></i> POS
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-pagos" data-bs-toggle="tab" data-bs-target="#pane-pagos" type="button" role="tab">
                    <i class="fas fa-credit-card me-1"></i> Pagos
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-cajeros" data-bs-toggle="tab" data-bs-target="#pane-cajeros" type="button" role="tab">
                    <i class="fas fa-users me-1"></i> Cajeros
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-ticket" data-bs-toggle="tab" data-bs-target="#pane-ticket" type="button" role="tab">
                    <i class="fas fa-receipt me-1"></i> Ticket
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-pantalla" data-bs-toggle="tab" data-bs-target="#pane-pantalla" type="button" role="tab">
                    <i class="fas fa-tv me-1"></i> Pantalla
                </button>
            </li>
        </ul>

        <div class="tab-content" id="configTabsContent">

        <!-- ============================================================ -->
        <!-- TAB 1: NEGOCIO                                               -->
        <!-- ============================================================ -->
        <div class="tab-pane fade show active" id="pane-negocio" role="tabpanel">

            <div class="card">
                <div class="card-header text-primary"><i class="fas fa-store"></i> Datos del Negocio</div>
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
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header text-secondary"><i class="fas fa-share-alt"></i> Redes Sociales</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">URL de Facebook</label>
                            <input type="url" name="facebook_url" class="form-control" placeholder="https://facebook.com/tu_pagina" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">URL de Instagram</label>
                            <input type="url" name="instagram_url" class="form-control" placeholder="https://instagram.com/tu_perfil" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">URL de Twitter (X)</label>
                            <input type="url" name="twitter_url" class="form-control" placeholder="https://twitter.com/tu_cuenta" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">URL de YouTube</label>
                            <input type="url" name="youtube_url" class="form-control" placeholder="https://youtube.com/tu_canal" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>">
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

        </div><!-- /pane-negocio -->

        <!-- ============================================================ -->
        <!-- TAB 2: POS (Preferencias + Kiosco + Ficha de Costo)          -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="pane-pos" role="tabpanel">

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
                <div class="card-header text-primary"><i class="fas fa-mobile-alt"></i> Kiosco (Autopedido)</div>
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="kiosco_solo_stock" id="chkKioscoStock" <?php echo ($currentConfig['kiosco_solo_stock'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="chkKioscoStock">Mostrar solo productos con existencias</label>
                        <div class="form-text">Si se activa, el Kiosco (client_order.php) ocultará automáticamente los productos que no tengan stock.</div>
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
                <div class="card-header" style="color:#0077b6;"><i class="fas fa-motorcycle"></i> Mensajería y Entrega a Domicilio</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tarifa por kilómetro (CUP)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="0" name="mensajeria_tarifa_km" class="form-control"
                                       value="<?php echo htmlspecialchars($currentConfig['mensajeria_tarifa_km'] ?? 150); ?>">
                                <span class="input-group-text">/km</span>
                            </div>
                            <div class="form-text">Precio por kilómetro de distancia al calcular el costo de envío en la tienda online.</div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /pane-pos -->

        <!-- ============================================================ -->
        <!-- TAB 3: PAGOS (Tasas + Pasarela + Métodos)                    -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="pane-pagos" role="tabpanel">

            <div class="card">
                <div class="card-header text-success"><i class="fas fa-exchange-alt"></i> Tasas de Cambio</div>
                <div class="card-body">
                    <div class="alert alert-info border-0 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Define cuántos <strong>CUP</strong> equivalen a 1 USD o 1 MLC. El POS y la tienda online usarán estas tasas para mostrar precios en divisas.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tasa USD → CUP</label>
                            <div class="input-group">
                                <span class="input-group-text">1 USD =</span>
                                <input type="number" step="0.01" min="1" name="tipo_cambio_usd" class="form-control"
                                       value="<?= htmlspecialchars($currentConfig['tipo_cambio_usd'] ?? 385) ?>">
                                <span class="input-group-text">CUP</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tasa MLC → CUP</label>
                            <div class="input-group">
                                <span class="input-group-text">1 MLC =</span>
                                <input type="number" step="0.01" min="1" name="tipo_cambio_mlc" class="form-control"
                                       value="<?= htmlspecialchars($currentConfig['tipo_cambio_mlc'] ?? 310) ?>">
                                <span class="input-group-text">CUP</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Moneda por defecto (POS)</label>
                            <select name="moneda_default_pos" class="form-select">
                                <?php foreach (['CUP','USD','MLC'] as $mon): ?>
                                <option value="<?= $mon ?>" <?= ($currentConfig['moneda_default_pos'] ?? 'CUP') === $mon ? 'selected' : '' ?>><?= $mon ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Moneda que aparece seleccionada por defecto al abrir el cobro.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header text-success"><i class="fas fa-university"></i> Pasarela de Pagos Online (Transferencia)</div>
                <div class="card-body">
                    <div class="alert alert-info border-0 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Estos datos se muestran al cliente cuando elige pagar por <strong>Transferencia Enzona / Transfermovil</strong> en la tienda online. Si los campos están vacíos, la opción de transferencia mostrará "(sin configurar)".
                    </div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Número de Tarjeta</label>
                            <input type="text" name="numero_tarjeta" class="form-control font-monospace"
                                   placeholder="Ej: 9225 1234 5678 9012"
                                   value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta'] ?? ''); ?>">
                            <div class="form-text">Tarjeta bancaria donde el cliente realizará la transferencia.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Titular de la Cuenta</label>
                            <input type="text" name="titular_tarjeta" class="form-control"
                                   placeholder="Ej: JUAN PEREZ GARCIA"
                                   value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Banco</label>
                            <select name="banco_tarjeta" class="form-select">
                                <?php
                                $bancos = ['Bandec / BPA', 'Bandec', 'BPA', 'Banco Metropolitano', 'Banco Popular de Ahorro'];
                                $bancoActual = $currentConfig['banco_tarjeta'] ?? 'Bandec / BPA';
                                foreach ($bancos as $b) {
                                    $sel = ($bancoActual === $b) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($b) . "\" $sel>" . htmlspecialchars($b) . "</option>";
                                }
                                if (!in_array($bancoActual, $bancos)) {
                                    echo "<option value=\"" . htmlspecialchars($bancoActual) . "\" selected>" . htmlspecialchars($bancoActual) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <?php if (!empty($currentConfig['numero_tarjeta'])): ?>
                    <div class="mt-3 p-3 border rounded-3" style="background:#f0f9f0;">
                        <div class="small text-muted mb-1 fw-bold">Vista previa — lo que verá el cliente:</div>
                        <div class="small">Tarjeta: <strong class="font-monospace"><?= htmlspecialchars($currentConfig['numero_tarjeta']) ?></strong></div>
                        <div class="small">Titular: <strong><?= htmlspecialchars($currentConfig['titular_tarjeta']) ?></strong></div>
                        <div class="small">Banco: <strong><?= htmlspecialchars($currentConfig['banco_tarjeta']) ?></strong></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header text-primary"><i class="fas fa-credit-card"></i> Métodos de Pago</div>
                <div class="card-body">
                    <div class="alert alert-warning border-0 small mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        El campo <strong>ID</strong> se guarda en la base de datos — no lo cambies en métodos existentes. Solo un método puede ser <strong>Transferencia</strong>.
                    </div>
                    <div id="metodosContainer">
                        <?php
                        $metodosConfig = $currentConfig['metodos_pago'] ?? [];
                        $coloresOpts = ['success'=>'Verde','primary'=>'Azul','secondary'=>'Gris','warning'=>'Amarillo','danger'=>'Rojo','info'=>'Celeste','dark'=>'Negro'];
                        foreach ($metodosConfig as $mi => $mp):
                        ?>
                        <div class="metodo-row border rounded p-2 mb-2 bg-light">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-2">
                                    <label class="small text-muted d-block">ID (inmutable)</label>
                                    <input type="text" name="mp_id[]" class="form-control form-control-sm font-monospace fw-bold"
                                           value="<?= htmlspecialchars($mp['id']) ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="small text-muted d-block">Nombre visible</label>
                                    <input type="text" name="mp_nombre[]" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($mp['nombre']) ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="small text-muted d-block">Icono FontAwesome</label>
                                    <input type="text" name="mp_icono[]" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($mp['icono']) ?>" placeholder="fa-money-bill-wave">
                                </div>
                                <div class="col-md-2">
                                    <label class="small text-muted d-block">Color</label>
                                    <select name="mp_color[]" class="form-select form-select-sm">
                                        <?php foreach ($coloresOpts as $cv => $cl): ?>
                                        <option value="<?= $cv ?>" <?= ($mp['color_bootstrap'] ?? '') === $cv ? 'selected' : '' ?>><?= $cl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="small text-muted d-block">Opciones</label>
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="mp_activo[<?= $mi ?>]" value="1"
                                                   id="mpActivo<?= $mi ?>" <?= ($mp['activo'] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="mpActivo<?= $mi ?>">Activo</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="mp_aplica_pos[<?= $mi ?>]" value="1"
                                                   id="mpPos<?= $mi ?>" <?= ($mp['aplica_pos'] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="mpPos<?= $mi ?>">POS</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="mp_aplica_shop[<?= $mi ?>]" value="1"
                                                   id="mpShop<?= $mi ?>" <?= ($mp['aplica_shop'] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="mpShop<?= $mi ?>">Shop</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="mp_es_transferencia[<?= $mi ?>]" value="1"
                                                   id="mpTrans<?= $mi ?>" <?= ($mp['es_transferencia'] ?? false) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="mpTrans<?= $mi ?>">Transf.</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox" name="mp_es_especial[<?= $mi ?>]" value="1"
                                                   id="mpEspecial<?= $mi ?>" <?= ($mp['es_especial'] ?? false) ? 'checked' : '' ?>
                                                   onchange="toggleTextoEspecial(this, <?= $mi ?>)">
                                            <label class="form-check-label small text-info fw-semibold" for="mpEspecial<?= $mi ?>">Especial</label>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" onclick="removeMetodo(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2 especial-text-row" id="mpTextoEspecialRow<?= $mi ?>" style="<?= ($mp['es_especial'] ?? false) ? '' : 'display:none;' ?>">
                                        <input type="text" name="mp_texto_especial[<?= $mi ?>]" class="form-control form-control-sm"
                                               placeholder="Texto a mostrar al seleccionar este método"
                                               value="<?= htmlspecialchars($mp['texto_especial'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addMetodoPago()">
                        <i class="fas fa-plus-circle"></i> Agregar Método de Pago
                    </button>
                </div>
            </div>

        </div><!-- /pane-pagos -->

        <!-- ============================================================ -->
        <!-- TAB 4: CAJEROS                                               -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="pane-cajeros" role="tabpanel">

            <div class="card">
                <div class="card-header text-info"><i class="fas fa-users"></i> Gestión de Cajeros</div>
                <div class="card-body">
                    <div id="cajerosContainer">
                        <?php foreach($currentConfig['cajeros'] as $cajero):
                            $rolCajero = $cajero['rol'] ?? 'cajero';
                            $rolColors = ['cajero'=>'primary','supervisor'=>'warning','admin'=>'danger'];
                            $rolIcons  = ['cajero'=>'fa-user','supervisor'=>'fa-user-shield','admin'=>'fa-user-crown'];
                            $rolColor  = $rolColors[$rolCajero] ?? 'primary';
                            $rolIcon   = $rolIcons[$rolCajero]  ?? 'fa-user';
                        ?>
                        <div class="row g-2 align-items-center cajero-row mb-1">
                            <div class="col-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-user"></i></span>
                                    <input type="text" name="cajero_nombre[]" class="form-control" placeholder="Nombre" value="<?php echo htmlspecialchars($cajero['nombre']); ?>" required>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="fas fa-key"></i></span>
                                    <input type="text" name="cajero_pin[]" class="form-control" placeholder="PIN" value="<?php echo htmlspecialchars($cajero['pin']); ?>" required pattern="\d{4,}" title="Mínimo 4 dígitos">
                                </div>
                            </div>
                            <div class="col-3">
                                <select name="cajero_rol[]" class="form-select form-select-sm">
                                    <option value="cajero" <?= $rolCajero==='cajero' ? 'selected' : '' ?>>🔵 Cajero</option>
                                    <option value="supervisor" <?= $rolCajero==='supervisor' ? 'selected' : '' ?>>🟠 Supervisor</option>
                                    <option value="admin" <?= $rolCajero==='admin' ? 'selected' : '' ?>>🔴 Admin</option>
                                </select>
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

        </div><!-- /pane-cajeros -->

        <!-- ============================================================ -->
        <!-- TAB 5: TICKET                                                -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="pane-ticket" role="tabpanel">

            <?php
            $logoPath   = $currentConfig['ticket_logo'] ?? '';
            $logoExists = !empty($logoPath) && file_exists(__DIR__ . '/' . $logoPath);
            $togglesDef = [
                ['ticket_mostrar_cajero',      'chkMostrarCajero',      'fas fa-user-tie',    'Cajero',           $currentConfig['ticket_mostrar_cajero']      ?? true],
                ['ticket_mostrar_canal',       'chkMostrarCanal',       'fas fa-tag',         'Canal de Origen',  $currentConfig['ticket_mostrar_canal']       ?? true],
                ['ticket_mostrar_uuid',        'chkMostrarUuid',        'fas fa-fingerprint', 'UUID de venta',    $currentConfig['ticket_mostrar_uuid']        ?? false],
                ['ticket_mostrar_items_count', 'chkMostrarItemsCount',  'fas fa-list-ol',     'Total de Ítems',   $currentConfig['ticket_mostrar_items_count'] ?? true],
                ['ticket_mostrar_qr',          'chkMostrarQr',          'fas fa-qrcode',      'Código QR',        $currentConfig['ticket_mostrar_qr']          ?? true],
            ];
            ?>
            <div class="card">
                <div class="card-header" style="color:#7c3aed;"><i class="fas fa-receipt me-2"></i> Diseño del Ticket</div>
                <div class="card-body">
                    <div class="row g-4">

                        <!-- Columna controles -->
                        <div class="col-xl-7">

                            <!-- Logo -->
                            <div class="mb-4 pb-3 border-bottom">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-image me-1"></i> Logo</h6>
                                <?php if ($logoExists): ?>
                                <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded bg-light">
                                    <img src="<?= htmlspecialchars($logoPath) ?>?v=<?= filemtime(__DIR__ . '/' . $logoPath) ?>"
                                         style="max-height:70px; max-width:180px; border-radius:4px;" alt="Logo actual">
                                    <div>
                                        <span class="badge bg-success mb-2"><i class="fas fa-check me-1"></i>Logo activo</span>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="ticket_logo_remove" id="chkLogoRemove" value="1">
                                            <label class="form-check-label small text-danger fw-semibold" for="chkLogoRemove">
                                                <i class="fas fa-trash me-1"></i>Eliminar logo actual
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="mb-2 p-2 border rounded bg-light text-muted small">
                                    <i class="fas fa-ban me-1"></i> Sin logo — se mostrará solo el nombre del negocio
                                </div>
                                <?php endif; ?>
                                <input type="file" name="ticket_logo" class="form-control" accept="image/jpeg,image/png,image/webp"
                                       onchange="onLogoFileChange(this)">
                                <div class="form-text">JPG, PNG o WebP · Máx 2 MB · Recomendado: 280 × 80 px</div>
                            </div>

                            <!-- Textos -->
                            <div class="mb-4 pb-3 border-bottom">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-font me-1"></i> Textos del Ticket</h6>
                                <div class="mb-3">
                                    <label class="form-label">Slogan / Tagline <small class="text-muted">(debajo del nombre)</small></label>
                                    <input type="text" name="ticket_slogan" class="form-control" maxlength="100"
                                           value="<?= htmlspecialchars($currentConfig['ticket_slogan'] ?? '') ?>"
                                           placeholder="Ej: La mejor calidad de la ciudad"
                                           oninput="updateTicketPreview()">
                                </div>
                                <div>
                                    <label class="form-label">Mensaje Final <small class="text-muted">(pie del ticket)</small></label>
                                    <input type="text" name="mensaje_final" class="form-control" maxlength="150"
                                           value="<?= htmlspecialchars($currentConfig['mensaje_final']) ?>"
                                           placeholder="Ej: ¡Gracias por su compra!"
                                           oninput="updateTicketPreview()">
                                </div>
                            </div>

                            <!-- Toggles -->
                            <div>
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-sliders-h me-1"></i> Elementos a Mostrar</h6>
                                <div class="row g-2">
                                    <?php foreach ($togglesDef as [$name, $id, $icon, $label, $checked]): ?>
                                    <div class="col-sm-6">
                                        <div class="form-check form-switch p-2 rounded border bg-light">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   name="<?= $name ?>" id="<?= $id ?>"
                                                   <?= $checked ? 'checked' : '' ?>
                                                   onchange="updateTicketPreview()">
                                            <label class="form-check-label small fw-semibold" for="<?= $id ?>">
                                                <i class="<?= $icon ?> me-1 text-secondary"></i><?= $label ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div><!-- /col controles -->

                        <!-- Columna preview -->
                        <div class="col-xl-5">
                            <div class="sticky-top" style="top:20px;">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-eye me-1"></i> Vista Previa</h6>
                                <div style="background:#d1d5db; padding:16px; border-radius:10px;">
                                    <div id="ticketPreview"
                                         style="font-family:'Courier New',monospace; font-size:11px; background:#fff;
                                                border:1px solid #999; padding:12px; width:220px; margin:0 auto;
                                                box-shadow:2px 2px 0 #bbb;">
                                    </div>
                                </div>
                                <div class="form-text mt-2 text-center">
                                    Vista aproximada ·
                                    <a href="ticket_view.php?id=216" target="_blank" class="text-decoration-none">
                                        Ver ticket real <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                        </div><!-- /col preview -->

                    </div><!-- /row -->
                </div><!-- /card-body -->
            </div><!-- /card Diseño del Ticket -->

        </div><!-- /pane-ticket -->

        <!-- ============================================================ -->
        <!-- TAB 6: PANTALLA DEL CLIENTE                                  -->
        <!-- ============================================================ -->
        <div class="tab-pane fade" id="pane-pantalla" role="tabpanel">

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

        </div><!-- /pane-pantalla -->

        </div><!-- /tab-content -->

        <div class="d-grid gap-2 mb-5 mt-4">
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
        div.className = 'row g-2 align-items-center cajero-row mb-1';
        div.innerHTML = `
            <div class="col-4">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-user"></i></span>
                    <input type="text" name="cajero_nombre[]" class="form-control" placeholder="Nombre" required>
                </div>
            </div>
            <div class="col-3">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-key"></i></span>
                    <input type="text" name="cajero_pin[]" class="form-control" placeholder="PIN" required pattern="\\d{4,}">
                </div>
            </div>
            <div class="col-3">
                <select name="cajero_rol[]" class="form-select form-select-sm">
                    <option value="cajero">🔵 Cajero</option>
                    <option value="supervisor">🟠 Supervisor</option>
                    <option value="admin">🔴 Admin</option>
                </select>
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

    let _mpIndex = <?= count($currentConfig['metodos_pago'] ?? []) ?>;
    const COLORES = ['success','primary','secondary','warning','danger','info','dark'];
    const COLORES_LABELS = {'success':'Verde','primary':'Azul','secondary':'Gris','warning':'Amarillo','danger':'Rojo','info':'Celeste','dark':'Negro'};

    function addMetodoPago() {
        const container = document.getElementById('metodosContainer');
        const i = _mpIndex++;
        const colorOpts = COLORES.map(c => `<option value="${c}">${COLORES_LABELS[c]}</option>`).join('');
        const div = document.createElement('div');
        div.className = 'metodo-row border rounded p-2 mb-2 bg-light';
        div.innerHTML = `
            <div class="row g-2 align-items-center">
                <div class="col-md-2">
                    <label class="small text-muted d-block">ID (inmutable)</label>
                    <input type="text" name="mp_id[]" class="form-control form-control-sm font-monospace fw-bold" required>
                </div>
                <div class="col-md-2">
                    <label class="small text-muted d-block">Nombre visible</label>
                    <input type="text" name="mp_nombre[]" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="small text-muted d-block">Icono FontAwesome</label>
                    <input type="text" name="mp_icono[]" class="form-control form-control-sm" placeholder="fa-money-bill-wave">
                </div>
                <div class="col-md-2">
                    <label class="small text-muted d-block">Color</label>
                    <select name="mp_color[]" class="form-select form-select-sm">${colorOpts}</select>
                </div>
                <div class="col-md-4">
                    <label class="small text-muted d-block">Opciones</label>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="mp_activo[${i}]" value="1" id="mpActivo${i}" checked>
                            <label class="form-check-label small" for="mpActivo${i}">Activo</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="mp_aplica_pos[${i}]" value="1" id="mpPos${i}" checked>
                            <label class="form-check-label small" for="mpPos${i}">POS</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="mp_aplica_shop[${i}]" value="1" id="mpShop${i}">
                            <label class="form-check-label small" for="mpShop${i}">Shop</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="mp_es_transferencia[${i}]" value="1" id="mpTrans${i}">
                            <label class="form-check-label small" for="mpTrans${i}">Transf.</label>
                        </div>
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="mp_es_especial[${i}]" value="1" id="mpEspecial${i}"
                                   onchange="toggleTextoEspecial(this, ${i})">
                            <label class="form-check-label small text-info fw-semibold" for="mpEspecial${i}">Especial</label>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" onclick="removeMetodo(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <div class="mt-2 especial-text-row" id="mpTextoEspecialRow${i}" style="display:none;">
                        <input type="text" name="mp_texto_especial[${i}]" class="form-control form-control-sm"
                               placeholder="Texto a mostrar al seleccionar este método">
                    </div>
                </div>
            </div>`;
        container.appendChild(div);
    }

    function removeMetodo(btn) {
        const container = document.getElementById('metodosContainer');
        if (container.children.length > 1) {
            btn.closest('.metodo-row').remove();
        } else {
            alert("Debe existir al menos un método de pago.");
        }
    }

    function toggleTextoEspecial(chk, idx) {
        const row = document.getElementById('mpTextoEspecialRow' + idx);
        if (row) row.style.display = chk.checked ? '' : 'none';
    }
</script>

<script>
// ===== VISTA PREVIA DEL TICKET =====
const _tplCfg = {
    nombre:      <?= json_encode($currentConfig['tienda_nombre']) ?>,
    dir:         <?= json_encode($currentConfig['direccion']) ?>,
    tel:         <?= json_encode($currentConfig['telefono']) ?>,
    slogan:      <?= json_encode($currentConfig['ticket_slogan'] ?? '') ?>,
    msgFinal:    <?= json_encode($currentConfig['mensaje_final']) ?>,
    logoSrc:     <?= json_encode($logoExists ? $logoPath . '?v=' . filemtime(__DIR__ . '/' . $logoPath) : '') ?>,
};

function _esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function _sep() {
    return '<div style="border-top:1px dashed #999;margin:5px 0;"></div>';
}

function updateTicketPreview() {
    const nombre     = document.querySelector('[name=tienda_nombre]')?.value || _tplCfg.nombre;
    const dir        = document.querySelector('[name=direccion]')?.value     || _tplCfg.dir;
    const tel        = document.querySelector('[name=telefono]')?.value      || _tplCfg.tel;
    const slogan     = document.querySelector('[name=ticket_slogan]')?.value  ?? _tplCfg.slogan;
    const msgFinal   = document.querySelector('[name=mensaje_final]')?.value  ?? _tplCfg.msgFinal;
    const showCajero = document.getElementById('chkMostrarCajero')?.checked;
    const showCanal  = document.getElementById('chkMostrarCanal')?.checked;
    const showUuid   = document.getElementById('chkMostrarUuid')?.checked;
    const showItems  = document.getElementById('chkMostrarItemsCount')?.checked;
    const showQr     = document.getElementById('chkMostrarQr')?.checked;
    const logoSrc    = window._ticketLogoSrc ?? _tplCfg.logoSrc;

    let h = '';

    // Cabecera
    h += '<div style="text-align:center;margin-bottom:6px;">';
    if (logoSrc) {
        h += `<img src="${logoSrc}" style="display:block;max-width:160px;max-height:52px;margin:0 auto 4px;" alt="">`;
    }
    h += `<strong style="font-size:13px;">${_esc(nombre)}</strong>`;
    if (slogan) h += `<div style="font-size:9px;margin-top:1px;">${_esc(slogan)}</div>`;
    h += `<div style="font-size:9px;color:#666;">${_esc(dir)}</div>`;
    if (tel) h += `<div style="font-size:9px;color:#666;">Tel: ${_esc(tel)}</div>`;
    h += '</div>';

    h += _sep();

    // Meta
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><td>Ticket:</td><td style="text-align:right"><b>#000216</b></td></tr>';
    if (showUuid) h += '<tr><td>UUID:</td><td style="text-align:right;font-size:8px;color:#666;">WEB-1234567890</td></tr>';
    h += '<tr><td>Fecha:</td><td style="text-align:right">23/02/2026 09:02</td></tr>';
    if (showCajero) h += '<tr><td>Cajero:</td><td style="text-align:right"><b>Admin</b></td></tr>';
    if (showCanal)  h += '<tr><td>Origen:</td><td style="text-align:right"><span style="background:#0ea5e9;color:#fff;padding:1px 5px;border-radius:10px;font-size:8px;">🌐 Web</span></td></tr>';
    h += '<tr><td>Método Pago:</td><td style="text-align:right"><b>Efectivo</b></td></tr>';
    h += '</table>';

    h += _sep();

    // Ítems
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><th style="text-align:left;border-bottom:1px solid #000;padding:2px 0;">Cant</th>'
       + '<th style="text-align:left;border-bottom:1px solid #000;padding:2px 0;">Descripción</th>'
       + '<th style="text-align:right;border-bottom:1px solid #000;padding:2px 0;">Total</th></tr>';
    h += '<tr><td>1.00</td><td>Cafe en sobre</td><td style="text-align:right">$40.00</td></tr>';
    h += '</table>';
    if (showItems) {
        h += '<div style="text-align:right;font-size:9px;font-weight:bold;border-top:1px solid #000;margin-top:3px;padding-top:2px;">Total Ítems: 1</div>';
    }

    // Totales
    h += '<div style="border:2px solid #000;padding:5px;margin-top:5px;">';
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><td>Subtotal productos:</td><td style="text-align:right">$40.00</td></tr>';
    h += '<tr><td><b>Costo mensajería:</b></td><td style="text-align:right"><b>$250.00</b></td></tr>';
    h += '<tr style="border-top:1px dashed #000;"><td colspan="2"></td></tr>';
    h += '<tr><td><b style="font-size:11px;">TOTAL A PAGAR:</b></td><td style="text-align:right"><b style="font-size:14px;">$290.00</b></td></tr>';
    h += '<tr><td colspan="2" style="text-align:center;font-size:8px;border-top:1px dashed #000;padding-top:3px;">💳 Pagado con: <b>Efectivo</b></td></tr>';
    h += '</table></div>';

    // Pie
    h += _sep();
    if (msgFinal) {
        h += `<div style="text-align:center;font-weight:bold;font-size:10px;">${_esc(msgFinal)}</div>`;
    }
    h += '<div style="text-align:center;font-size:8px;color:#999;margin-top:2px;">Sistema PALWEB POS v3.0</div>';

    // QR placeholder
    if (showQr) {
        h += _sep();
        h += '<div style="text-align:center;">';
        h += '<div style="font-size:8px;margin-bottom:3px;">Escanea para ver este ticket</div>';
        h += '<svg width="52" height="52" viewBox="0 0 52 52" style="display:inline-block;">';
        // QR simulado con rectángulos
        h += '<rect x="0" y="0" width="52" height="52" fill="white"/>';
        h += '<rect x="2" y="2" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="6" y="6" width="12" height="12" fill="#000"/>';
        h += '<rect x="30" y="2" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="34" y="6" width="12" height="12" fill="#000"/>';
        h += '<rect x="2" y="30" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="6" y="34" width="12" height="12" fill="#000"/>';
        h += '<rect x="30" y="30" width="6" height="6" fill="#000"/><rect x="38" y="30" width="6" height="6" fill="#000"/>';
        h += '<rect x="30" y="38" width="6" height="6" fill="#000"/><rect x="46" y="46" width="6" height="6" fill="#000"/>';
        h += '</svg>';
        h += '</div>';
    }

    document.getElementById('ticketPreview').innerHTML = h;
}

function onLogoFileChange(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { window._ticketLogoSrc = e.target.result; updateTicketPreview(); };
        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateTicketPreview();
    // Sincronizar nombre/dirección/tel con la preview en tiempo real
    ['tienda_nombre','direccion','telefono'].forEach(n => {
        document.querySelector(`[name="${n}"]`)?.addEventListener('input', updateTicketPreview);
    });

    // Persistir tab activo en localStorage
    const STORAGE_KEY = 'pos_config_active_tab';
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
        const tab = document.querySelector(`#configTabs button[data-bs-target="${saved}"]`);
        if (tab) new bootstrap.Tab(tab).show();
    }
    document.querySelectorAll('#configTabs button[data-bs-toggle="tab"]').forEach(btn => {
        btn.addEventListener('shown.bs.tab', e => {
            localStorage.setItem(STORAGE_KEY, e.target.getAttribute('data-bs-target'));
            // Re-render ticket preview when switching to that tab
            if (e.target.getAttribute('data-bs-target') === '#pane-ticket') updateTicketPreview();
        });
    });
});
</script>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
