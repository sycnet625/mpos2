<?php
// ARCHIVO: /var/www/palweb/api/product_editor.php
// FRAMEWORK DE EDICIÓN DE PRODUCTOS (REUTILIZABLE)

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);

// --- ACCIÓN: GUARDAR CAMBIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $sku = $_POST['codigo'] ?? '';
        if (!$sku) throw new Exception("SKU no especificado.");

        // Validar propiedad
        $stmtCheck = $pdo->prepare("SELECT 1 FROM productos WHERE codigo = ? AND id_empresa = ?");
        $stmtCheck->execute([$sku, $EMP_ID]);
        if (!$stmtCheck->fetch()) throw new Exception("Producto no encontrado o acceso denegado.");

        // Preparar Update Dinámico
        // Mapeamos los campos del formulario a la BD
        $sql = "UPDATE productos SET 
                nombre = ?, categoria = ?, precio = ?, costo = ?, 
                stock_minimo = ?, unidad_medida = ?, descripcion = ?, 
                peso = ?, color = ?, impuesto = ?,
                activo = ?, es_web = ?, es_materia_prima = ?, 
                es_elaborado = ?, es_servicio = ?, es_cocina = ?,
                version_row = ?
                WHERE codigo = ? AND id_empresa = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['nombre'],
            $_POST['categoria'],
            floatval($_POST['precio']),
            floatval($_POST['costo']),
            floatval($_POST['stock_minimo']),
            $_POST['unidad_medida'],
            $_POST['descripcion'],
            floatval($_POST['peso']),
            $_POST['color'],
            floatval($_POST['impuesto']),
            isset($_POST['activo']) ? 1 : 0,
            isset($_POST['es_web']) ? 1 : 0,
            isset($_POST['es_materia_prima']) ? 1 : 0,
            isset($_POST['es_elaborado']) ? 1 : 0,
            isset($_POST['es_servicio']) ? 1 : 0,
            isset($_POST['es_cocina']) ? 1 : 0,
            time(), // Actualizar versión
            $sku,
            $EMP_ID
        ]);

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
?>

<form id="formEditProduct" class="p-2">
    <input type="hidden" name="codigo" value="<?php echo htmlspecialchars($p['codigo']); ?>">
    
    <div class="row g-3">
        <div class="col-md-12">
            <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-info-circle"></i> Datos Principales</h6>
        </div>
        
        <div class="col-md-3">
            <label class="form-label small fw-bold">Código SKU</label>
            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($p['codigo']); ?>" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold">Nombre del Producto</label>
            <input type="text" name="nombre" class="form-control fw-bold" value="<?php echo htmlspecialchars($p['nombre']); ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Categoría</label>
            <input type="text" name="categoria" class="form-control" value="<?php echo htmlspecialchars($p['categoria']); ?>" list="catListEdit">
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-bold text-success">Precio Venta</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="precio" step="0.01" class="form-control border-success" value="<?php echo floatval($p['precio']); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-danger">Costo Unit.</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="costo" step="0.01" class="form-control" value="<?php echo floatval($p['costo']); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Impuesto %</label>
            <input type="number" name="impuesto" step="0.01" class="form-control" value="<?php echo floatval($p['impuesto']); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Stock Min.</label>
            <input type="number" name="stock_minimo" step="0.01" class="form-control" value="<?php echo floatval($p['stock_minimo']); ?>">
        </div>

        <div class="col-md-12 mt-4">
            <h6 class="text-primary border-bottom pb-2 mb-3"><i class="fas fa-sliders-h"></i> Propiedades y Web</h6>
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-bold">Unidad</label>
            <select name="unidad_medida" class="form-select">
                <option <?php echo $p['unidad_medida']=='UNIDAD'?'selected':''; ?>>UNIDAD</option>
                <option <?php echo $p['unidad_medida']=='KG'?'selected':''; ?>>KG</option>
                <option <?php echo $p['unidad_medida']=='LITRO'?'selected':''; ?>>LITRO</option>
                <option <?php echo $p['unidad_medida']=='METRO'?'selected':''; ?>>METRO</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Peso (kg/lb)</label>
            <input type="number" name="peso" step="0.01" class="form-control" value="<?php echo floatval($p['peso']); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Color</label>
            <input type="text" name="color" class="form-control" value="<?php echo htmlspecialchars($p['color']); ?>">
        </div>
        
        <div class="col-md-12">
            <label class="form-label small fw-bold">Descripción (Web)</label>
            <textarea name="descripcion" class="form-control" rows="2"><?php echo htmlspecialchars($p['descripcion']); ?></textarea>
        </div>

        <div class="col-12 mt-3">
            <div class="d-flex flex-wrap gap-4 p-3 bg-light rounded border">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="activo" id="swAct" <?php echo $p['activo']?'checked':''; ?>>
                    <label class="form-check-label fw-bold text-dark" for="swAct">Activo (General)</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="es_web" id="swWeb" <?php echo $p['es_web']?'checked':''; ?>>
                    <label class="form-check-label fw-bold text-primary" for="swWeb">Visible en Web</label>
                </div>
                <div class="vr"></div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_elaborado" id="swElab" <?php echo $p['es_elaborado']?'checked':''; ?>>
                    <label class="form-check-label small" for="swElab">Es Elaborado</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_materia_prima" id="swMat" <?php echo $p['es_materia_prima']?'checked':''; ?>>
                    <label class="form-check-label small" for="swMat">Materia Prima</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_servicio" id="swServ" <?php echo $p['es_servicio']?'checked':''; ?>>
                    <label class="form-check-label small" for="swServ">Servicio</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="es_cocina" id="swCoc" <?php echo $p['es_cocina']?'checked':''; ?>>
                    <label class="form-check-label small" for="swCoc">Enviar a Cocina</label>
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
// Manejar el envío del formulario vía AJAX dentro del mismo framework
document.getElementById('formEditProduct').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

    const formData = new FormData(this);

    try {
        const resp = await fetch('product_editor.php', {
            method: 'POST',
            body: formData
        });
        const res = await resp.json();
        
        if (res.status === 'success') {
            // Cerrar modal y recargar tabla
            const modalEl = document.getElementById('editProductModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            
            // Recargar página o tabla (según implementación padre)
            if(typeof window.reloadTable === 'function') window.reloadTable();
            else window.location.reload();
            
            alert("✅ " + res.msg);
        } else {
            alert("❌ Error: " + res.msg);
        }
    } catch (err) {
        alert("Error de conexión al guardar.");
    } finally {
        btn.disabled = false; btn.innerHTML = originalText;
    }
});
</script>

