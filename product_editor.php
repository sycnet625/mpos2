<?php
// ARCHIVO: product_editor.php v3.3 — Editor completo de producto

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);

function product_editor_ensure_barcode_columns(PDO $pdo): void {
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

product_editor_ensure_barcode_columns($pdo);

// --- ACCIÓN: GUARDAR CAMBIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $sku = $_POST['codigo'] ?? '';
        if (!$sku) throw new Exception("SKU no especificado.");

        $stmtCheck = $pdo->prepare("SELECT 1 FROM productos WHERE codigo = ? AND id_empresa = ?");
        $stmtCheck->execute([$sku, $EMP_ID]);
        if (!$stmtCheck->fetch()) throw new Exception("Producto no encontrado o acceso denegado.");

        // Fecha de vencimiento: NULL si vacío
        $fechaVenc = !empty($_POST['fecha_vencimiento']) ? $_POST['fecha_vencimiento'] : null;
        // Etiqueta web: NULL si vacío
        $etiqWeb   = !empty($_POST['etiqueta_web'])  ? trim($_POST['etiqueta_web'])  : null;
        $etiqColor = !empty($_POST['etiqueta_color']) ? trim($_POST['etiqueta_color']) : null;

        $sql = "UPDATE productos SET
                    nombre            = ?,
                    codigo_barra_1    = ?,
                    codigo_barra_2    = ?,
                    categoria         = ?,
                    precio            = ?,
                    precio_mayorista  = ?,
                    costo             = ?,
                    impuesto          = ?,
                    stock_minimo      = ?,
                    unidad_medida     = ?,
                    peso              = ?,
                    color             = ?,
                    descripcion       = ?,
                    fecha_vencimiento = ?,
                    etiqueta_web      = ?,
                    etiqueta_color    = ?,
                    activo            = ?,
                    es_pos            = ?,
                    es_web            = ?,
                    es_elaborado      = ?,
                    es_materia_prima  = ?,
                    es_servicio       = ?,
                    es_cocina         = ?,
                    tiene_variantes   = ?,
                    es_suc1           = ?,
                    es_suc2           = ?,
                    es_suc3           = ?,
                    es_suc4           = ?,
                    es_suc5           = ?,
                    es_suc6           = ?,
                    version_row       = ?
                WHERE codigo = ? AND id_empresa = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['nombre'],
            trim((string)($_POST['codigo_barra_1'] ?? '')) ?: null,
            trim((string)($_POST['codigo_barra_2'] ?? '')) ?: null,
            $_POST['categoria'],
            floatval($_POST['precio']),
            floatval($_POST['precio_mayorista']),
            floatval($_POST['costo']),
            floatval($_POST['impuesto']),
            floatval($_POST['stock_minimo']),
            $_POST['unidad_medida'],
            floatval($_POST['peso']),
            $_POST['color'],
            $_POST['descripcion'],
            $fechaVenc,
            $etiqWeb,
            $etiqColor,
            isset($_POST['activo'])          ? 1 : 0,
            isset($_POST['es_pos'])          ? 1 : 0,
            isset($_POST['es_web'])          ? 1 : 0,
            isset($_POST['es_elaborado'])    ? 1 : 0,
            isset($_POST['es_materia_prima'])? 1 : 0,
            isset($_POST['es_servicio'])     ? 1 : 0,
            isset($_POST['es_cocina'])       ? 1 : 0,
            isset($_POST['tiene_variantes']) ? 1 : 0,
            isset($_POST['es_suc1'])         ? 1 : 0,
            isset($_POST['es_suc2'])         ? 1 : 0,
            isset($_POST['es_suc3'])         ? 1 : 0,
            isset($_POST['es_suc4'])         ? 1 : 0,
            isset($_POST['es_suc5'])         ? 1 : 0,
            isset($_POST['es_suc6'])         ? 1 : 0,
            time(),
            $sku,
            $EMP_ID
        ]);

        // Guardar/actualizar precios por sucursal
        if (!empty($_POST['suc_precio'])) {
            $stmtSucPrc = $pdo->prepare(
                "INSERT INTO productos_precios_sucursal (codigo_producto, id_sucursal, precio_costo, precio_venta, precio_mayorista)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE precio_costo=VALUES(precio_costo), precio_venta=VALUES(precio_venta), precio_mayorista=VALUES(precio_mayorista)"
            );
            $stmtDel = $pdo->prepare("DELETE FROM productos_precios_sucursal WHERE codigo_producto=? AND id_sucursal=?");
            for ($s = 1; $s <= 6; $s++) {
                $sv = floatval($_POST['suc_precio'][$s]    ?? 0);
                $sc = floatval($_POST['suc_costo'][$s]     ?? 0);
                $sm = floatval($_POST['suc_mayorista'][$s] ?? 0);
                if ($sv > 0 || $sc > 0 || $sm > 0) {
                    $stmtSucPrc->execute([$sku, $s, $sc ?: null, $sv ?: null, $sm ?: null]);
                } else {
                    // Si todos son 0, eliminar la fila (vuelve al precio general)
                    $stmtDel->execute([$sku, $s]);
                }
            }
        }

        echo json_encode(['status' => 'success', 'msg' => 'Producto actualizado correctamente.']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// --- ACCIÓN: MOSTRAR FORMULARIO (GET) ---
$sku = $_GET['sku'] ?? '';
if (!$sku) die('<div class="alert alert-danger">SKU requerido.</div>');

$stmt = $pdo->prepare("SELECT * FROM productos WHERE codigo = ? AND id_empresa = ?");
$stmt->execute([$sku, $EMP_ID]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) die('<div class="alert alert-warning">Producto no encontrado.</div>');

$localPath = '/var/www/assets/product_images/';

// Cargar precios por sucursal
$stmtSucPrc = $pdo->prepare("SELECT id_sucursal, precio_costo, precio_venta, precio_mayorista FROM productos_precios_sucursal WHERE codigo_producto = ?");
$stmtSucPrc->execute([$sku]);
$preciosSucursal = [];
foreach ($stmtSucPrc->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $preciosSucursal[intval($row['id_sucursal'])] = $row;
}

// Colores predefinidos para etiqueta
$etiqColores = ['#ef4444'=>'Rojo','#f97316'=>'Naranja','#eab308'=>'Amarillo','#22c55e'=>'Verde','#3b82f6'=>'Azul','#8b5cf6'=>'Morado','#ec4899'=>'Rosa'];
?>

<form id="formEditProduct" class="p-2">
    <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($p['codigo']); ?>">

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 1 — DATOS PRINCIPALES
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle me-1"></i> Datos Principales</h6>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label small fw-bold">Código SKU</label>
            <input type="text" class="form-control bg-light font-monospace" value="<?php echo htmlspecialchars($p['codigo']); ?>" readonly>
        </div>
        <div class="col-md-5">
            <label class="form-label small fw-bold">Nombre del Producto</label>
            <input type="text" name="nombre" class="form-control fw-bold" value="<?php echo htmlspecialchars($p['nombre']); ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold">Categoría</label>
            <input type="text" name="categoria" class="form-control" value="<?php echo htmlspecialchars($p['categoria']); ?>" list="catListEdit">
            <datalist id="catListEdit"></datalist>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label small fw-bold">Código de Barras 1</label>
            <input type="text" name="codigo_barra_1" class="form-control font-monospace" maxlength="64" value="<?php echo htmlspecialchars($p['codigo_barra_1'] ?? ''); ?>" placeholder="EAN/UPC principal">
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold">Código de Barras 2</label>
            <input type="text" name="codigo_barra_2" class="form-control font-monospace" maxlength="64" value="<?php echo htmlspecialchars($p['codigo_barra_2'] ?? ''); ?>" placeholder="Código alternativo">
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN — IMÁGENES
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-warning border-bottom pb-2 mb-3 mt-4"><i class="fas fa-images me-1"></i> Imágenes del Producto</h6>

    <div class="row g-3 mb-2">
    <?php
    $imgSlots = [
        'main'   => 'Imagen Principal',
        'extra1' => 'Extra 1 (detalles web)',
        'extra2' => 'Extra 2 (detalles web)',
    ];
    foreach ($imgSlots as $slot => $slotLabel):
        $imgCode = ($slot === 'main') ? $sku : $sku . '_' . $slot;
        $hasImg  = false; $imgV = '';
        foreach (['.avif','.webp','.jpg'] as $_e) {
            $f = $localPath . $imgCode . $_e;
            if (file_exists($f)) { $hasImg = true; $imgV = '&v='.filemtime($f); break; }
        }
    ?>
    <div class="col-md-4">
        <div class="border rounded p-2 bg-light text-center h-100">
            <div class="small fw-bold text-muted mb-2"><?php echo $slotLabel; ?></div>
            <img id="img_<?php echo $slot; ?>"
                 src="image.php?code=<?php echo urlencode($imgCode) . $imgV; ?>"
                 class="img-thumbnail mb-2"
                 style="width:130px;height:130px;object-fit:cover;cursor:pointer;border-radius:8px;"
                 onclick="triggerEditorImg('<?php echo addslashes($sku); ?>','<?php echo $slot; ?>')"
                 title="Clic para cambiar imagen">
            <div id="btnWrap_<?php echo $slot; ?>" class="d-flex gap-1 justify-content-center flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="triggerEditorImg('<?php echo addslashes($sku); ?>','<?php echo $slot; ?>')">
                    <i class="fas fa-<?php echo $hasImg ? 'camera' : 'upload'; ?> me-1"></i>
                    <?php echo $hasImg ? 'Cambiar' : 'Subir'; ?>
                </button>
                <?php if ($hasImg && $slot !== 'main'): ?>
                <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="deleteEditorImg('<?php echo addslashes($sku); ?>','<?php echo $slot; ?>')">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 2 — PRECIOS
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-success border-bottom pb-2 mb-3 mt-4"><i class="fas fa-dollar-sign me-1"></i> Precios y Costos</h6>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-success">Precio Venta</label>
            <div class="input-group">
                <span class="input-group-text bg-success-subtle">$</span>
                <input type="number" name="precio" step="0.01" min="0" class="form-control border-success fw-bold" value="<?php echo floatval($p['precio']); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-warning">Precio Mayorista</label>
            <div class="input-group">
                <span class="input-group-text bg-warning-subtle">$</span>
                <input type="number" name="precio_mayorista" step="0.01" min="0" class="form-control border-warning" value="<?php echo floatval($p['precio_mayorista']); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-danger">Costo Unitario</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="costo" step="0.01" min="0" class="form-control" value="<?php echo floatval($p['costo']); ?>">
            </div>
            <?php
                $margen = $p['precio'] > 0 ? round((($p['precio'] - $p['costo']) / $p['precio']) * 100, 1) : 0;
                $margenColor = $margen >= 30 ? 'success' : ($margen >= 10 ? 'warning' : 'danger');
            ?>
            <div class="small mt-1">Margen: <span class="badge bg-<?php echo $margenColor; ?>"><?php echo $margen; ?>%</span></div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Impuesto %</label>
            <div class="input-group">
                <input type="number" name="impuesto" step="0.01" min="0" max="100" class="form-control" value="<?php echo floatval($p['impuesto']); ?>">
                <span class="input-group-text">%</span>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 2b — PRECIOS POR SUCURSAL
    ═══════════════════════════════════════════════════ -->
    <h6 class="border-bottom pb-2 mb-3 mt-4" style="color:#7c3aed"><i class="fas fa-store me-1"></i> Precios por Sucursal <small class="text-muted fw-normal">(opcional — sobrescribe el precio general)</small></h6>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th class="small">Sucursal</th>
                    <th class="small text-center">Costo ($)</th>
                    <th class="small text-center text-success">Precio Venta ($)</th>
                    <th class="small text-center text-warning">Mayorista ($)</th>
                </tr>
            </thead>
            <tbody>
                <?php for($s=1; $s<=6; $s++): $sp = $preciosSucursal[$s] ?? []; ?>
                <tr>
                    <td class="fw-bold small">Suc <?= $s ?></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="suc_costo[<?= $s ?>]" value="<?= number_format(floatval($sp['precio_costo'] ?? 0), 2, '.', '') ?>"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end fw-bold" name="suc_precio[<?= $s ?>]" value="<?= number_format(floatval($sp['precio_venta'] ?? 0), 2, '.', '') ?>"></td>
                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="suc_mayorista[<?= $s ?>]" value="<?= number_format(floatval($sp['precio_mayorista'] ?? 0), 2, '.', '') ?>"></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <div class="small text-muted"><i class="fas fa-info-circle me-1"></i>Deja en 0.00 para usar el precio general en esa sucursal.</div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 3 — LOGÍSTICA
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-secondary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-boxes me-1"></i> Logística</h6>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label small fw-bold">Unidad de Medida</label>
            <select name="unidad_medida" class="form-select">
                <?php foreach(['UNIDAD','KG','GRAMO','LITRO','ML','METRO','CM','CAJA','PAQUETE','DOCENA','MANGA','SERVICIO'] as $u): ?>
                    <option <?php echo $p['unidad_medida']===$u?'selected':''; ?>><?php echo $u; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Stock Mínimo</label>
            <input type="number" name="stock_minimo" step="0.01" min="0" class="form-control" value="<?php echo floatval($p['stock_minimo']); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Peso (kg)</label>
            <input type="number" name="peso" step="0.001" min="0" class="form-control" value="<?php echo floatval($p['peso']); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Fecha de Vencimiento</label>
            <input type="date" name="fecha_vencimiento" class="form-control" value="<?php echo htmlspecialchars($p['fecha_vencimiento'] ?? ''); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Color</label>
            <input type="text" name="color" class="form-control" value="<?php echo htmlspecialchars($p['color'] ?? ''); ?>" placeholder="ej: Rojo, Azul...">
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 4 — DESCRIPCIÓN Y ETIQUETA WEB
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-primary border-bottom pb-2 mb-3 mt-4"><i class="fas fa-globe me-1"></i> Web</h6>

    <div class="row g-3 mb-3">
        <div class="col-md-12">
            <label class="form-label small fw-bold">Descripción (visible en tienda online)</label>
            <textarea name="descripcion" class="form-control" rows="2"><?php echo htmlspecialchars($p['descripcion'] ?? ''); ?></textarea>
        </div>
        <div class="col-md-5">
            <label class="form-label small fw-bold">Etiqueta Web <span class="text-muted fw-normal">(ej: "Nuevo", "Oferta", "Agotado")</span></label>
            <input type="text" name="etiqueta_web" class="form-control" value="<?php echo htmlspecialchars($p['etiqueta_web'] ?? ''); ?>" placeholder="Dejar vacío = sin etiqueta" maxlength="50">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold">Color de Etiqueta</label>
            <div class="d-flex gap-2 align-items-center flex-wrap mt-1">
                <?php foreach($etiqColores as $hex => $label): ?>
                    <label class="mb-0" title="<?php echo $label; ?>">
                        <input type="radio" name="etiqueta_color" value="<?php echo $hex; ?>" class="d-none etiq-radio"
                               <?php echo ($p['etiqueta_color'] ?? '') === $hex ? 'checked' : ''; ?>>
                        <span class="d-inline-block rounded-circle border" style="width:24px;height:24px;background:<?php echo $hex; ?>;cursor:pointer;border-width:3px!important;border-color:<?php echo ($p['etiqueta_color'] ?? '') === $hex ? '#000' : 'transparent'; ?>!important;"></span>
                    </label>
                <?php endforeach; ?>
                <label class="mb-0 ms-1" title="Sin color">
                    <input type="radio" name="etiqueta_color" value="" class="d-none etiq-radio" <?php echo empty($p['etiqueta_color']) ? 'checked' : ''; ?>>
                    <span class="d-inline-block rounded-circle border border-secondary" style="width:24px;height:24px;background:#fff;cursor:pointer;"></span>
                </label>
            </div>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <?php
                $prevEtiq  = $p['etiqueta_web']  ?? '';
                $prevColor = $p['etiqueta_color'] ?? '#22c55e';
            ?>
            <div>
                <label class="form-label small fw-bold d-block">Vista previa</label>
                <span id="etiqPreview" class="badge rounded-pill px-3 py-2"
                      style="background:<?php echo htmlspecialchars($prevColor ?: '#22c55e'); ?>;color:#fff;font-size:0.85rem;">
                    <?php echo $prevEtiq ?: 'Sin etiqueta'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════
         SECCIÓN 5 — DISPONIBILIDAD (FLAGS)
    ═══════════════════════════════════════════════════ -->
    <h6 class="text-dark border-bottom pb-2 mb-3 mt-4"><i class="fas fa-toggle-on me-1"></i> Disponibilidad y Tipo</h6>

    <div class="row g-2 mb-3">
        <!-- Switches principales -->
        <div class="col-12">
            <div class="d-flex flex-wrap gap-3 p-3 bg-light rounded border">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="activo" id="swAct" <?php echo $p['activo'] ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold" for="swAct">✅ Activo</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="es_pos" id="swPos" <?php echo $p['es_pos'] ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold text-warning" for="swPos">🖥️ POS</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="es_web" id="swWeb" <?php echo $p['es_web'] ? 'checked' : ''; ?>>
                    <label class="form-check-label fw-bold text-primary" for="swWeb">🌐 Web</label>
                </div>
                <div class="vr"></div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_elaborado" id="swElab" <?php echo $p['es_elaborado'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="swElab">🔧 Elaborado</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_materia_prima" id="swMat" <?php echo $p['es_materia_prima'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="swMat">🧱 Materia Prima</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_servicio" id="swServ" <?php echo $p['es_servicio'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="swServ">🛠️ Servicio</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_cocina" id="swCoc" <?php echo $p['es_cocina'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="swCoc">👨‍🍳 Cocina</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="tiene_variantes" id="swVar" <?php echo $p['tiene_variantes'] ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="swVar">🎨 Variantes</label>
                </div>
            </div>
        </div>

        <!-- Sucursales -->
        <div class="col-12">
            <div class="p-3 bg-light rounded border">
                <div class="small fw-bold text-muted mb-2"><i class="fas fa-store me-1"></i> Disponible en sucursales:</div>
                <div class="d-flex flex-wrap gap-3">
                    <?php for($s = 1; $s <= 6; $s++): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="es_suc<?php echo $s; ?>" id="swSuc<?php echo $s; ?>"
                                   <?php echo $p["es_suc$s"] ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="swSuc<?php echo $s; ?>">Sucursal <?php echo $s; ?></label>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="text-end mt-4 pt-2 border-top">
        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success fw-bold px-4"><i class="fas fa-save me-2"></i> Guardar Cambios</button>
    </div>
</form>

<script>
// ── Envío AJAX ──────────────────────────────────────────────────────────────
document.getElementById('formEditProduct').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    try {
        const resp = await fetch('product_editor.php', { method: 'POST', body: new FormData(this) });
        const res  = await resp.json();
        if (res.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('editProductModal')).hide();
            if (typeof window.reloadTable === 'function') window.reloadTable();
            else window.location.reload();
            alert("✅ " + res.msg);
        } else {
            alert("❌ Error: " + res.msg);
        }
    } catch (err) {
        alert("Error de conexión al guardar.");
    } finally {
        btn.disabled = false; btn.innerHTML = orig;
    }
});

// ── Vista previa de etiqueta web ────────────────────────────────────────────
function updateEtiqPreview() {
    const txt   = document.querySelector('[name="etiqueta_web"]').value.trim() || 'Sin etiqueta';
    const color = document.querySelector('.etiq-radio:checked')?.value || '#6b7280';
    const prev  = document.getElementById('etiqPreview');
    prev.textContent  = txt;
    prev.style.background = color || '#6b7280';
}

document.querySelector('[name="etiqueta_web"]').addEventListener('input', updateEtiqPreview);
document.querySelectorAll('.etiq-radio').forEach(r => {
    r.addEventListener('change', function() {
        // Resaltar círculo seleccionado
        document.querySelectorAll('.etiq-radio').forEach(x => {
            const span = x.nextElementSibling;
            if (span) span.style.borderColor = 'transparent';
        });
        if (this.nextElementSibling) this.nextElementSibling.style.borderColor = '#000';
        updateEtiqPreview();
    });
});

// ── Cargar categorías en datalist ────────────────────────────────────────────
(async () => {
    try {
        const res  = await fetch('categories_api.php');
        const cats = await res.json();
        const dl   = document.getElementById('catListEdit');
        if (dl) cats.forEach(c => { const o = document.createElement('option'); o.value = c.nombre; dl.appendChild(o); });
    } catch(e) {}
})();

// Nota: triggerEditorImg, handleEditorUpload y deleteEditorImg están definidos
// en products_table.php (script de la página padre).
</script>
