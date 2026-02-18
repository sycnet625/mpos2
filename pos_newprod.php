<?php
// ARCHIVO: /var/www/palweb/api/pos_newprod.php
// DESCRIPCIÓN: Modal universal para creación rápida de productos (Backend + UI)
// VERSIÓN: V2.0 (SKU AUTOMÁTICO POR SUCURSAL 6 DÍGITOS)

// 1. LÓGICA DE BACKEND (Solo se ejecuta si hay petición AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && in_array($_GET['action'], ['get_next_sku', 'get_categories']))) {
    
    // Evitar conflictos si ya se incluyó db.php antes
    if (!isset($pdo)) {
        ini_set('display_errors', 0);
        require_once 'db.php';
        require_once 'config_loader.php';
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
                precio, costo, precio_mayorista, impuesto, activo, stock_minimo, 
                es_materia_prima, es_servicio, unidad_medida, peso, color,
                es_web, sucursales_web, es_pos, 
                es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6,
                id_sucursal_origen
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, ?, ?, 1, ?, 
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
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
                floatval($_POST['precio_mayorista'] ?? 0),
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
                isset($_POST['es_suc6']) ? 1 : 0,
                $SUC_ID // Guardar origen
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
        
        // Cargar SKU automático (con formato 6 dígitos)
        loadNextSku();

        // Cargar categorías desde la tabla
        loadCategoriesQP();
        
        // Mostrar Modal
        const modal = new bootstrap.Modal(document.getElementById('modalQuickProd'));
        modal.show();
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
            // IMPORTANTE: Asegúrate de que el nombre del archivo aquí coincida con el real.
            // Si el archivo se llama 'pos_newprod.php', usaremos esa ruta.
            const res = await fetch('pos_newprod.php?action=get_next_sku'); 
            const data = await res.json();
            
            if(data.status === 'success') {
                let prefix = data.prefix;
                let seq = parseInt(data.next_seq);
                
                // Formato: SS + 000N (Total 6 chars aprox, asegurando relleno de 4 ceros)
                // Ej: 44 + 0001 = 440001
                let sku = prefix + seq.toString().padStart(4, '0');
                
                document.getElementById('qp_codigo').value = sku;
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
            // Asegurarse de enviar a este mismo archivo
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
                
                const modalEl = document.getElementById('modalQuickProd');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                modalInstance.hide();

                if (typeof productCreatedCallback === 'function') {
                    productCreatedCallback(data.data);
                } else if (typeof window.reloadTable === 'function') {
                    window.reloadTable(); 
                } else {
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
</script>

