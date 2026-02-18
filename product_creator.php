<?php
// ARCHIVO: /var/www/palweb/api/product_creator.php
// DESCRIPCIÓN: Modal universal para creación rápida de productos (Backend + UI)

// 1. LÓGICA DE BACKEND (Solo se ejecuta si hay petición AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] == 'get_next_sku')) {
    
    // Evitar conflictos si ya se incluyó db.php antes
    if (!isset($pdo)) {
        ini_set('display_errors', 0);
        require_once 'db.php';
        require_once 'config_loader.php';
        $EMP_ID = intval($config['id_empresa']);
    }

    // A. OBTENER PRÓXIMO SKU
    if (isset($_GET['action']) && $_GET['action'] === 'get_next_sku') {
        header('Content-Type: application/json');
        try {
            // Buscamos el código más alto que sea numérico
            $sql = "SELECT MAX(CAST(codigo AS UNSIGNED)) as max_code FROM productos WHERE id_empresa = $EMP_ID AND codigo REGEXP '^[0-9]+$'";
            $max = $pdo->query($sql)->fetchColumn();
            $next = $max ? ($max + 1) : 1000; // Si no hay, empieza en 1000
            echo json_encode(['status' => 'success', 'next_sku' => $next]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // B. GUARDAR NUEVO PRODUCTO
    if (isset($_POST['save_new_product'])) {
        header('Content-Type: application/json');
        try {
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
                precio, costo, impuesto, activo, stock_minimo, 
                es_materia_prima, es_servicio, unidad_medida, peso, color,
                es_web, sucursales_web, es_pos, 
                es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, ?, ?, 1, ?, 
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $codigo,
                $EMP_ID,
                $_POST['nombre'],
                $_POST['descripcion'] ?? '',
                $_POST['categoria'] ?? 'General',
                floatval($_POST['precio']),
                floatval($_POST['costo']),
                floatval($_POST['impuesto'] ?? 0),
                floatval($_POST['stock_minimo'] ?? 0),
                isset($_POST['es_materia_prima']) ? 1 : 0,
                isset($_POST['es_servicio']) ? 1 : 0,
                $_POST['unidad_medida'] ?? 'U',
                floatval($_POST['peso'] ?? 0),
                $_POST['color'] ?? '#cccccc',
                isset($_POST['es_web']) ? 1 : 0,
                $_POST['sucursales_web'] ?? '1',
                isset($_POST['es_pos']) ? 1 : 0,
                isset($_POST['es_suc1']) ? 1 : 0,
                isset($_POST['es_suc2']) ? 1 : 0,
                isset($_POST['es_suc3']) ? 1 : 0,
                isset($_POST['es_suc4']) ? 1 : 0,
                isset($_POST['es_suc5']) ? 1 : 0,
                isset($_POST['es_suc6']) ? 1 : 0
            ]);

            // Guardar Imagen si viene
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $path = __DIR__ . '/../product_images/' . $codigo . '.jpg';
                move_uploaded_file($_FILES['foto']['tmp_name'], $path);
            }

            echo json_encode(['status' => 'success', 'msg' => 'Producto creado', 'data' => [
                'codigo' => $codigo, 'nombre' => $_POST['nombre']
            ]]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}
?>

<div class="modal fade" id="modalQuickProd" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-box-open me-2"></i> Nuevo Producto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <form id="formQuickProd" enctype="multipart/form-data">
                    <input type="hidden" name="save_new_product" value="1">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="small fw-bold">Código SKU</label>
                            <div class="input-group">
                                <input type="text" class="form-control fw-bold text-center" name="codigo" id="qp_codigo" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="loadNextSku()" title="Generar Automático"><i class="fas fa-sync"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Nombre del Producto</label>
                            <input type="text" class="form-control" name="nombre" required placeholder="Ej: Coca Cola 330ml">
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold">Categoría</label>
                            <input type="text" class="form-control" name="categoria" list="cat_list_qp" placeholder="Seleccione...">
                            <datalist id="cat_list_qp">
                                <option value="General">
                                <option value="Bebidas">
                                <option value="Alimentos">
                                <option value="Insumos">
                            </datalist>
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
                        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_visibilidad">Visibilidad & Sucursales</a></li>
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

                        <div class="tab-pane fade" id="tab_web">
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" name="es_web" id="qp_es_web">
                                        <label class="form-check-label fw-bold" for="qp_es_web">Visible en Web</label>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold px-4" onclick="submitNewProduct()">
                    <i class="fas fa-save me-2"></i> CREAR PRODUCTO
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Variable para almacenar el callback (función que se ejecuta al terminar)
    let productCreatedCallback = null;

    function openProductCreator(callback = null) {
        productCreatedCallback = callback;
        
        // Resetear formulario
        document.getElementById('formQuickProd').reset();
        
        // Cargar SKU automático
        loadNextSku();
        
        // Mostrar Modal
        const modal = new bootstrap.Modal(document.getElementById('modalQuickProd'));
        modal.show();
    }

    async function loadNextSku() {
        try {
            // Usamos la ruta relativa correcta asumiendo que estamos en /api/
            // Si este archivo se incluye, la ruta es relativa al archivo padre.
            // Para seguridad, usamos el nombre del archivo actual.
            const res = await fetch('product_creator.php?action=get_next_sku'); 
            const data = await res.json();
            if(data.status === 'success') {
                document.getElementById('qp_codigo').value = data.next_sku;
            }
        } catch(e) {
            console.error("Error cargando SKU", e);
        }
    }

    async function submitNewProduct() {
        const form = document.getElementById('formQuickProd');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const btn = document.querySelector('#modalQuickProd .btn-success');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        try {
            const formData = new FormData(form);
            const res = await fetch('product_creator.php', {
                method: 'POST',
                body: formData
            });
            
            // Intentar parsear JSON (si el servidor devuelve HTML por error, lo capturamos)
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
                
                // Cerrar modal
                const modalEl = document.getElementById('modalQuickProd');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                modalInstance.hide();

                // Ejecutar callback si existe (para actualizar la tabla padre)
                if (typeof productCreatedCallback === 'function') {
                    productCreatedCallback(data.data);
                } else if (typeof window.reloadTable === 'function') {
                    window.reloadTable(); // Si existe una función global de recarga
                } else {
                    location.reload(); // Fallback: recargar página
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
</script>

