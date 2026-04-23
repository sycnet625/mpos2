<?php
// ARCHIVO: /var/www/palweb/api/pos_newprod.php
// DESCRIPCIÓN: Modal universal para creación rápida de productos (Backend + UI)
// VERSIÓN: V2.2 (FIX USER CONTEXT)

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Detectar si se está llamando directamente o incluido
$is_direct_access = (basename($_SERVER['SCRIPT_FILENAME']) === 'pos_newprod.php');

// 1. LÓGICA DE BACKEND (Solo se ejecuta si hay petición AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && in_array($_GET['action'], ['get_next_sku', 'get_categories']))) {
    if (!function_exists('pos_newprod_ensure_barcode_columns')) {
        function pos_newprod_ensure_barcode_columns(PDO $pdo): void {
            static $ready = false;
            if ($ready) return;
            $stmt = $pdo->query("SHOW COLUMNS FROM productos");
            $existing = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[(string)($col['Field'] ?? '')] = true;
            }
            $add = [];
            if (!isset($existing['codigo_barra_1'])) $add[] = "ADD COLUMN codigo_barra_1 VARCHAR(64) NULL AFTER codigo";
            if (!isset($existing['codigo_barra_2'])) $add[] = "ADD COLUMN codigo_barra_2 VARCHAR(64) NULL AFTER codigo_barra_1";
            if ($add) {
                $pdo->exec("ALTER TABLE productos " . implode(', ', $add));
            }
            $ready = true;
        }
    }
    
    // Evitar conflictos si ya se incluyó db.php antes
    if (!isset($pdo)) {
        ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';
require_once 'product_image_pipeline.php';
require_once 'combo_helper.php';
        $EMP_ID = intval($config['id_empresa']);
        $SUC_ID = intval($config['id_sucursal']); // Obtenemos sucursal para prefijo
    } else {
        // Si ya viene incluido, asegurar variables
        if(!isset($SUC_ID)) $SUC_ID = 1;
        if(!isset($EMP_ID)) $EMP_ID = 1;
    }

    // A. OBTENER PRÓXIMO SKU (LOGICA SUCURSAL)
    if (isset($_GET['action']) && $_GET['action'] === 'get_next_sku') {
        header('Content-Type: application/json');
        try {
            // 1. Generar prefijo: SS (ID Sucursal repetido)
            // Ej: Sucursal 4 -> "44"
            $prefix = str_repeat((string)$SUC_ID, 2); 
            $lenPrefix = strlen($prefix);

            // 2. Buscar el máximo consecutivo existente para este prefijo
            $sql = "SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) as max_seq
                    FROM productos 
                    WHERE codigo LIKE CONCAT(:prefix, '%') 
                    AND id_empresa = :emp";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':len' => $lenPrefix, ':prefix' => $prefix, ':emp' => $EMP_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 3. Calcular siguiente (si no hay, empieza en 1)
            $nextSeq = ($row['max_seq']) ? intval($row['max_seq']) + 1 : 1; 
            
            // Devolver datos separados para que el JS formatee (44 + 0001)
            echo json_encode(['status' => 'success', 'prefix' => $prefix, 'next_seq' => $nextSeq]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // A2. OBTENER CATEGORÍAS (AJAX)
    if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
        header('Content-Type: application/json');
        try {
            $stmt = $pdo->query("SELECT nombre FROM categorias ORDER BY nombre ASC");
            $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['status' => 'success', 'categories' => $cats]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'get_combo_products') {
        header('Content-Type: application/json');
        try {
            echo json_encode(['status' => 'success', 'products' => combo_component_catalog($pdo, $EMP_ID)]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // B. GUARDAR NUEVO PRODUCTO
    if (isset($_POST['save_new_product'])) {
        header('Content-Type: application/json');
        try {
            pos_newprod_ensure_barcode_columns($pdo);
            combo_ensure_schema($pdo);
            // Recoger datos del POST
            $codigo = $_POST['codigo'];
            if (empty($codigo)) throw new Exception("El código es obligatorio");

            // Verificar si ya existe
            $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE codigo = ? AND id_empresa = ?");
            $check->execute([$codigo, $EMP_ID]);
            if ($check->fetchColumn() > 0) throw new Exception("El código $codigo ya existe.");

            // Preparar Query con TODAS las columnas
            $sql = "INSERT INTO productos (
                codigo, id_empresa, nombre, descripcion, categoria,
                codigo_barra_1, codigo_barra_2,
                precio, costo, precio_mayorista, impuesto, activo, stock_minimo,
                es_materia_prima, es_servicio, es_combo, unidad_medida, peso, color,
                es_web, es_reservable, sucursales_web, es_pos,
                es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6,
                id_sucursal_origen
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, 1,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?
            )";

            // Si los precios principales están en 0, intentar rellenarlos desde los precios por sucursal
            $mainPrecio    = floatval($_POST['precio']);
            $mainCosto     = floatval($_POST['costo']);
            $mainMayorista = floatval($_POST['precio_mayorista'] ?? 0);

            if (($mainPrecio <= 0 || $mainCosto <= 0 || $mainMayorista <= 0) && !empty($_POST['suc_precio'])) {
                // Prioridad: sucursal actual → primera sucursal con datos
                $sucOrder = array_merge([$SUC_ID], range(1, 6));
                foreach ($sucOrder as $s) {
                    $sv = floatval($_POST['suc_precio'][$s]    ?? 0);
                    $sc = floatval($_POST['suc_costo'][$s]     ?? 0);
                    $sm = floatval($_POST['suc_mayorista'][$s] ?? 0);
                    if ($sv > 0 || $sc > 0 || $sm > 0) {
                        if ($mainPrecio    <= 0 && $sv > 0) $mainPrecio    = $sv;
                        if ($mainCosto     <= 0 && $sc > 0) $mainCosto     = $sc;
                        if ($mainMayorista <= 0 && $sm > 0) $mainMayorista = $sm;
                        // Si quedan 0, usar precio de venta como fallback de los otros
                        if ($mainCosto     <= 0 && $sv > 0) $mainCosto     = $sv;
                        if ($mainMayorista <= 0 && $sv > 0) $mainMayorista = round($sv * 0.95, 2);
                        break;
                    }
                }
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $codigo,
                $EMP_ID,
                $_POST['nombre'],
                $_POST['descripcion'] ?? '',
                $_POST['categoria'] ?? 'General',
                trim((string)($_POST['codigo_barra_1'] ?? '')) ?: null,
                trim((string)($_POST['codigo_barra_2'] ?? '')) ?: null,
                $mainPrecio,
                $mainCosto,
                $mainMayorista,
                floatval($_POST['impuesto'] ?? 0),
                floatval($_POST['stock_minimo'] ?? 0),
                isset($_POST['es_materia_prima']) ? 1 : 0,
                isset($_POST['es_servicio']) ? 1 : 0,
                isset($_POST['es_combo']) ? 1 : 0,
                $_POST['unidad_medida'] ?? 'U',
                floatval($_POST['peso'] ?? 0),
                $_POST['color'] ?? '#cccccc',
                isset($_POST['es_web']) ? 1 : 0,
                isset($_POST['es_reservable']) ? 1 : 0,
                $_POST['sucursales_web'] ?? '1',
                isset($_POST['es_pos']) ? 1 : 0,
                isset($_POST['es_suc1']) ? 1 : 0,
                isset($_POST['es_suc2']) ? 1 : 0,
                isset($_POST['es_suc3']) ? 1 : 0,
                isset($_POST['es_suc4']) ? 1 : 0,
                isset($_POST['es_suc5']) ? 1 : 0,
                isset($_POST['es_suc6']) ? 1 : 0,
                $SUC_ID
            ]);

            // Guardar Imagen si viene — genera variantes para shop.php
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                product_image_pipeline_store_upload($_FILES['foto']['tmp_name'], $codigo);
            }
            product_image_pipeline_ensure_placeholder($codigo, (string)($_POST['nombre'] ?? $codigo));

            // Guardar precios por sucursal (si se especificaron)
            if (!empty($_POST['suc_precio'])) {
                $stmtSucPrc = $pdo->prepare(
                    "INSERT INTO productos_precios_sucursal (codigo_producto, id_sucursal, precio_costo, precio_venta, precio_mayorista)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE precio_costo=VALUES(precio_costo), precio_venta=VALUES(precio_venta), precio_mayorista=VALUES(precio_mayorista)"
                );
                for ($s = 1; $s <= 6; $s++) {
                    $sv = floatval($_POST['suc_precio'][$s]    ?? 0);
                    $sc = floatval($_POST['suc_costo'][$s]     ?? 0);
                    $sm = floatval($_POST['suc_mayorista'][$s] ?? 0);
                    if ($sv > 0 || $sc > 0 || $sm > 0) {
                        $stmtSucPrc->execute([$codigo, $s, $sc ?: null, $sv ?: null, $sm ?: null]);
                    }
                }
            }

            if (isset($_POST['es_combo'])) {
                $comboItems = json_decode($_POST['combo_items_json'] ?? '[]', true);
                combo_save_definition($pdo, $EMP_ID, $codigo, is_array($comboItems) ? $comboItems : []);
            }

            echo json_encode(['status' => 'success', 'msg' => 'Producto creado', 'data' => [
                'codigo' => $codigo, 'nombre' => $_POST['nombre'], 'es_combo' => isset($_POST['es_combo']) ? 1 : 0
            ]]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}

if ($is_direct_access) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Nuevo Producto - <?= htmlspecialchars(config_loader_system_name()) ?></title>
        <link href="assets/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/all.min.css">
        <script src="assets/js/bootstrap.bundle.min.js"></script>
        <style>
            body { background: #f4f7f6; }
            .full-page-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
            /* Estilos para que cuando sea directo no parezca un modal "flotante" si no se quiere */
            #modalQuickProd.show { display: block !important; opacity: 1 !important; position: relative; z-index: 1; }
            #modalQuickProd .modal-backdrop { display: none !important; }
            #modalQuickProd .modal-dialog { margin: 0; max-width: 800px; width: 100%; }
        </style>
    </head>
    <body>
        <div class="full-page-wrapper">
    <?php
}
?>

<div class="modal fade <?php echo $is_direct_access ? 'show' : ''; ?>" id="modalQuickProd" tabindex="-1" <?php echo $is_direct_access ? '' : 'data-bs-backdrop="static" aria-hidden="true"'; ?> style="<?php echo $is_direct_access ? 'display:block; position:relative;' : ''; ?>">
    <div class="modal-dialog modal-lg <?php echo $is_direct_access ? '' : 'modal-dialog-centered'; ?>">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-box-open me-2"></i> Nuevo Producto</h5>
                <?php if (!$is_direct_access): ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                <?php endif; ?>
            </div>
            <div class="modal-body bg-light">
                <form id="formQuickProd" enctype="multipart/form-data">
                    <input type="hidden" name="save_new_product" value="1">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="small fw-bold">Código SKU</label>
                            <div class="input-group">
                                <input type="text" class="form-control fw-bold text-center" name="codigo" id="qp_codigo" required placeholder="Cargando...">
                                <button class="btn btn-outline-secondary" type="button" onclick="loadNextSku()" title="Generar Automático"><i class="fas fa-sync"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Nombre del Producto</label>
                            <input type="text" class="form-control" name="nombre" required placeholder="Ej: Coca Cola 330ml">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Categoría</label>
                            <input type="text" class="form-control" name="categoria" id="qp_categoria" list="cat_list_qp" placeholder="Seleccione...">
                            <datalist id="cat_list_qp">
                                <!-- Poblado dinámicamente -->
                            </datalist>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="small fw-bold">Código de Barras 1</label>
                            <input type="text" class="form-control font-monospace" name="codigo_barra_1" maxlength="64" placeholder="EAN/UPC principal">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Código de Barras 2</label>
                            <input type="text" class="form-control font-monospace" name="codigo_barra_2" maxlength="64" placeholder="Código alternativo">
                        </div>
                    </div>

                    <div class="card p-3 mb-3 border shadow-sm bg-white">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted">Costo Compra</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" class="form-control" name="costo" value="0.00">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-success">Precio Venta</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" class="form-control fw-bold" name="precio" value="0.00" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-info">Precio Mayorista</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" class="form-control" name="precio_mayorista" value="0.00">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted">Impuesto %</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="impuesto" value="0">
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-danger">Stock Mínimo</label>
                                <input type="number" step="0.01" class="form-control form-control-sm" name="stock_minimo" value="5">
                            </div>
                        </div>
                    </div>

                    <ul class="nav nav-tabs nav-fill small mb-3" id="qpTabs" role="tablist">
                        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_detalles">Detalles</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_combo">Combo</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_visibilidad">Visibilidad & Sucursales</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_precios_suc">Precios x Suc.</a></li>
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_web">Web Info</a></li>
                    </ul>

                    <div class="tab-content border border-top-0 p-3 bg-white rounded-bottom">
                        
                        <div class="tab-pane fade show active" id="tab_detalles">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="small">Unidad Medida</label>
                                    <select class="form-select form-select-sm" name="unidad_medida">
                                        <option value="U">Unidad (U)</option>
                                        <option value="kg">Kilogramo (kg)</option>
                                        <option value="lb">Libra (lb)</option>
                                        <option value="lt">Litro (lt)</option>
                                        <option value="m">Metro (m)</option>
                                        <option value="MANGA">Manga</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="small">Peso (kg/lb)</label>
                                    <input type="number" step="0.01" class="form-control form-control-sm" name="peso" value="0">
                                </div>
                                <div class="col-md-4">
                                    <label class="small">Color Etiqueta</label>
                                    <input type="color" class="form-control form-control-sm w-100" name="color" value="#cccccc">
                                </div>
                                <div class="col-12 mt-2">
                                    <label class="small">Imagen (Opcional)</label>
                                    <input type="file" class="form-control form-control-sm" name="foto" accept="image/*">
                                </div>
                                <div class="col-12 mt-2 d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="es_materia_prima" id="qp_mp">
                                        <label class="form-check-label small" for="qp_mp">Es Materia Prima</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="es_servicio" id="qp_serv">
                                        <label class="form-check-label small" for="qp_serv">Es Servicio (No Stock)</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab_combo">
                            <input type="hidden" name="combo_items_json" id="qp_combo_items_json" value="[]">
                            <div class="form-check form-switch mb-3 p-2 rounded border" style="background:#f8fafc;">
                                <input class="form-check-input" type="checkbox" name="es_combo" id="qp_es_combo">
                                <label class="form-check-label fw-bold" for="qp_es_combo">Este producto es un combo</label>
                                <div class="form-text mb-0">El combo se vende como un solo producto, pero el inventario se descuenta de sus componentes.</div>
                            </div>
                            <div id="comboConfigBox" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small text-muted">Componentes del combo</div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addComboRow()">
                                        <i class="fas fa-plus me-1"></i>Agregar componente
                                    </button>
                                </div>
                                <datalist id="combo_product_list"></datalist>
                                <div id="comboRows" class="d-grid gap-2"></div>
                                <div class="alert alert-light border mt-3 mb-0 small">
                                    <strong>Costo calculado:</strong> <span id="comboAutoCost">$0.00</span><br>
                                    <span class="text-muted">El costo del producto combo se sincroniza con la suma de sus componentes.</span>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab_visibilidad">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <div class="form-check form-switch p-2 bg-light rounded border">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="es_pos" id="qp_es_pos" checked>
                                        <label class="form-check-label fw-bold" for="qp_es_pos">Disponible en Caja (POS)</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold mb-2">Disponible en Sucursales:</label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php for($i=1; $i<=6; $i++): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="es_suc<?php echo $i; ?>" id="qp_suc<?php echo $i; ?>" checked>
                                            <label class="form-check-label small" for="qp_suc<?php echo $i; ?>">Suc <?php echo $i; ?></label>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: PRECIOS POR SUCURSAL -->
                        <div class="tab-pane fade" id="tab_precios_suc">
                            <p class="small text-muted mb-2"><i class="fas fa-info-circle me-1"></i>Deja en 0.00 para usar el precio general. Solo se guarda si al menos un campo es mayor que 0.</p>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="small">Sucursal</th>
                                            <th class="small text-center">Costo</th>
                                            <th class="small text-center text-success">Precio Venta</th>
                                            <th class="small text-center text-info">Mayorista</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for($s=1; $s<=6; $s++): ?>
                                        <tr>
                                            <td class="fw-bold small">Suc <?= $s ?></td>
                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="suc_costo[<?= $s ?>]" value="0.00"></td>
                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end fw-bold" name="suc_precio[<?= $s ?>]" value="0.00"></td>
                                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="suc_mayorista[<?= $s ?>]" value="0.00"></td>
                                        </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab_web">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" name="es_web" id="qp_es_web">
                                        <label class="form-check-label fw-bold" for="qp_es_web">Visible en Web</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch mb-2 p-2 rounded" style="background:#fff8e1;border:1px solid #ffe082;">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="es_reservable" id="qp_es_reservable">
                                        <label class="form-check-label fw-bold" for="qp_es_reservable">
                                            📅 Reservable (sin existencia)
                                        </label>
                                        <div class="form-text text-muted mt-0">Si se activa, los clientes podrán <strong>reservar</strong> este producto aunque no tenga stock disponible.</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="small">Descripción Web</label>
                                    <textarea class="form-control form-control-sm" name="descripcion" rows="2"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="small">IDs Sucursales Web (ej: 1,3)</label>
                                    <input type="text" class="form-control form-control-sm" name="sucursales_web" value="1" placeholder="1,2,3">
                                </div>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <?php if (!$is_direct_access): ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <?php else: ?>
                    <a href="dashboard.php" class="btn btn-secondary">Volver al Menú</a>
                <?php endif; ?>
                <button type="button" class="btn btn-success fw-bold px-4" onclick="submitNewProduct()">
                    <i class="fas fa-save me-2"></i> CREAR PRODUCTO
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($is_direct_access): ?>
    </div> <!-- .full-page-wrapper -->
    <script>
        // Si es acceso directo, inicializar todo automáticamente
        document.addEventListener('DOMContentLoaded', () => {
            loadNextSku();
            loadCategoriesQP();
        });
    </script>
<?php endif; ?>

<script>
    // Variable para almacenar el callback (función que se ejecuta al terminar)
    let productCreatedCallback = null;
    let comboCatalog = [];
    let comboCatalogMap = {};

    function openProductCreator(callback = null) {
        productCreatedCallback = callback;
        
        // Resetear formulario
        document.getElementById('formQuickProd').reset();
        
        // Cargar SKU automático (con formato 6 dígitos)
        loadNextSku();

        // Cargar categorías desde la tabla
        loadCategoriesQP();
        loadComboCatalog();
        resetComboBuilder();
        
        // Mostrar Modal (solo si no es directo)
        const modalEl = document.getElementById('modalQuickProd');
        if (modalEl.classList.contains('fade')) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    }

    async function loadCategoriesQP() {
        try {
            const res = await fetch('pos_newprod.php?action=get_categories');
            const data = await res.json();
            if(data.status === 'success') {
                const datalist = document.getElementById('cat_list_qp');
                datalist.innerHTML = '';
                data.categories.forEach(cat => {
                    const opt = document.createElement('option');
                    opt.value = cat;
                    datalist.appendChild(opt);
                });
            }
        } catch(e) {
            console.error("Error cargando categorías para modal", e);
        }
    }

    async function loadNextSku() {
        try {
            const res = await fetch('pos_newprod.php?action=get_next_sku'); 
            const data = await res.json();
            
            if(data.status === 'success') {
                let prefix = data.prefix;
                let seq = parseInt(data.next_seq);
                let sku = prefix + seq.toString().padStart(4, '0');
                document.getElementById('qp_codigo').value = sku;
            }
        } catch(e) {
            console.error("Error cargando SKU", e);
        }
    }

    async function loadComboCatalog() {
        if (comboCatalog.length > 0) return;
        try {
            const res = await fetch('pos_newprod.php?action=get_combo_products');
            const data = await res.json();
            if (data.status !== 'success') return;
            comboCatalog = data.products || [];
            comboCatalogMap = {};
            const datalist = document.getElementById('combo_product_list');
            datalist.innerHTML = '';
            comboCatalog.forEach(prod => {
                comboCatalogMap[prod.codigo] = prod;
                const opt = document.createElement('option');
                opt.value = prod.codigo;
                opt.label = `${prod.nombre} (${prod.categoria || 'General'})`;
                datalist.appendChild(opt);
            });
        } catch (e) {
            console.error('Error cargando catálogo de combos', e);
        }
    }

    function resetComboBuilder() {
        const holder = document.getElementById('comboRows');
        if (holder) holder.innerHTML = '';
        const hidden = document.getElementById('qp_combo_items_json');
        if (hidden) hidden.value = '[]';
        const toggle = document.getElementById('qp_es_combo');
        const box = document.getElementById('comboConfigBox');
        if (toggle) toggle.checked = false;
        if (box) box.style.display = 'none';
        updateComboCost();
    }

    function addComboRow(item = {}) {
        const holder = document.getElementById('comboRows');
        if (!holder) return;
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center combo-row border rounded p-2 bg-light';
        row.innerHTML = `
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm combo-code" list="combo_product_list" placeholder="Código del producto" value="${item.codigo || ''}">
            </div>
            <div class="col-md-5">
                <div class="small text-muted combo-name">${item.nombre || 'Seleccione un producto existente'}</div>
            </div>
            <div class="col-md-2">
                <div class="input-group input-group-sm">
                    <input type="number" min="0.001" step="0.001" class="form-control text-end combo-qty" value="${item.cantidad || 1}">
                    <button type="button" class="btn btn-outline-danger" title="Eliminar" onclick="this.closest('.combo-row').remove(); syncComboRows();">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        holder.appendChild(row);

        const codeInput = row.querySelector('.combo-code');
        const qtyInput = row.querySelector('.combo-qty');
        codeInput.addEventListener('input', () => syncComboRows());
        codeInput.addEventListener('change', () => syncComboRows());
        qtyInput.addEventListener('input', () => syncComboRows());
        syncComboRows();
    }

    function syncComboRows() {
        const sku = document.getElementById('qp_codigo')?.value || '';
        const rows = [];
        document.querySelectorAll('#comboRows .combo-row').forEach(row => {
            const code = row.querySelector('.combo-code').value.trim();
            const qty = parseFloat(row.querySelector('.combo-qty').value || '0');
            const nameEl = row.querySelector('.combo-name');
            const prod = comboCatalogMap[code];
            nameEl.textContent = prod ? `${prod.nombre}${prod.es_servicio == 1 ? ' · Servicio' : ''}` : 'Producto no encontrado';
            if (!code || !prod || code === sku || qty <= 0) return;
            rows.push({ codigo: code, cantidad: qty, nombre: prod.nombre, costo: parseFloat(prod.costo || 0) });
        });
        document.getElementById('qp_combo_items_json').value = JSON.stringify(rows);
        updateComboCost(rows);
    }

    function updateComboCost(rows = null) {
        const data = rows || JSON.parse(document.getElementById('qp_combo_items_json')?.value || '[]');
        const total = data.reduce((sum, item) => sum + ((parseFloat(item.costo || 0) || 0) * (parseFloat(item.cantidad || 0) || 0)), 0);
        const costEl = document.getElementById('comboAutoCost');
        if (costEl) costEl.textContent = '$' + total.toFixed(2);
    }

    async function submitNewProduct() {
        const form = document.getElementById('formQuickProd');
        syncComboRows();
        const comboEnabled = document.getElementById('qp_es_combo')?.checked;
        const comboItems = JSON.parse(document.getElementById('qp_combo_items_json')?.value || '[]');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        if (comboEnabled && comboItems.length === 0) {
            alert('Agregue al menos un componente válido al combo.');
            return;
        }

        const btn = document.querySelector('#modalQuickProd .btn-success');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        try {
            const formData = new FormData(form);
            const res = await fetch('pos_newprod.php', {
                method: 'POST',
                body: formData
            });
            
            let data;
            const text = await res.text();
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error("Respuesta no JSON:", text);
                throw new Error("Respuesta inválida del servidor");
            }

            if (data.status === 'success') {
                alert("✅ Producto creado correctamente: " + data.data.codigo);
                
                // Si es modal, cerrarlo
                const modalEl = document.getElementById('modalQuickProd');
                if (modalEl.classList.contains('fade')) {
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                }

                if (typeof productCreatedCallback === 'function') {
                    productCreatedCallback(data.data);
                } else if (typeof window.reloadTable === 'function') {
                    window.reloadTable(); 
                } else {
                    // Si es acceso directo, tal vez no queramos recargar la página completa
                    // o sí, para limpiar el formulario.
                    location.reload(); 
                }

            } else {
                alert("❌ Error: " + data.msg);
            }

        } catch (e) {
            alert("Error de conexión: " + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const comboToggle = document.getElementById('qp_es_combo');
        if (!comboToggle) return;
        comboToggle.addEventListener('change', async function() {
            document.getElementById('comboConfigBox').style.display = this.checked ? 'block' : 'none';
            if (this.checked) {
                await loadComboCatalog();
                if (document.querySelectorAll('#comboRows .combo-row').length === 0) {
                    addComboRow();
                }
                const svc = document.getElementById('qp_serv');
                const mp = document.getElementById('qp_mp');
                if (svc) svc.checked = false;
                if (mp) mp.checked = false;
            } else {
                document.getElementById('comboRows').innerHTML = '';
                syncComboRows();
            }
        });
    });
</script>

<?php if ($is_direct_access): ?>
</body>
</html>
<?php endif; ?>
