<?php
// ARCHIVO: /var/www/palweb/api/web_manager.php
// GESTOR DE CONTENIDO WEB (E-COMMERCE MANAGER)
// VERSIÓN: CON EDICIÓN DE PROPIEDADES (COLOR, TAMAÑO, PESO)

// ---------------------------------------------------------
// 🔒 SEGURIDAD: VERIFICACIÓN DE SESIÓN
// ---------------------------------------------------------
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CONFIGURACIÓN
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);
$localPath = '/home/marinero/product_images/'; 

// 2. PROCESAR PETICIONES AJAX (GUARDADO AUTOMÁTICO)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Si es subida de imagen (Form Data)
    if (isset($_FILES['new_photo'])) {
        try {
            $code = $_POST['prod_code'];
            $ext = strtolower(pathinfo($_FILES['new_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) throw new Exception("Formato inválido");
            
            $src = ($ext=='png') ? imagecreatefrompng($_FILES['new_photo']['tmp_name']) : imagecreatefromjpeg($_FILES['new_photo']['tmp_name']);
            // Redimensionar a 800x800 max
            $w = imagesx($src); $h = imagesy($src); $max=800;
            if ($w > $max || $h > $max) {
                $ratio = $w/$h; $newW = ($w>$h)?$max:$max*$ratio; $newH = ($w>$h)?$max/$ratio:$max;
                $dst = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src); $src = $dst;
            }
            imagejpeg($src, $localPath . $code . '.jpg', 85);
            imagedestroy($src);
            echo json_encode(['status'=>'success']);
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }

    // Si es actualización de datos (JSON)
    if (isset($input['action'])) {
        try {
            $code = $input['id'];
            if ($input['action'] === 'update_web_status') {
                $val = intval($input['value']);
                $pdo->prepare("UPDATE productos SET es_web = ? WHERE codigo = ? AND id_empresa = ?")->execute([$val, $code, $EMP_ID]);
            }
            elseif ($input['action'] === 'update_sucursales') {
                $val = implode(',', $input['value']); 
                $pdo->prepare("UPDATE productos SET sucursales_web = ? WHERE codigo = ? AND id_empresa = ?")->execute([$val, $code, $EMP_ID]);
            }
            // NUEVO: ACTUALIZACIÓN COMPLETA DE DETALLES
            elseif ($input['action'] === 'update_details') {
                $desc = trim($input['descripcion']);
                $color = trim($input['color']);
                $unit = trim($input['unidad']);
                $peso = floatval($input['peso']);
                $etiqueta = trim($input['etiqueta'] ?? '');
                $etiquetaColor = trim($input['etiqueta_color'] ?? '');
                
                $sql = "UPDATE productos SET descripcion = ?, color = ?, unidad_medida = ?, peso = ?, etiqueta_web = ?, etiqueta_color = ? WHERE codigo = ? AND id_empresa = ?";
                $pdo->prepare($sql)->execute([$desc, $color, $unit, $peso, $etiqueta, $etiquetaColor, $code, $EMP_ID]);
            }
            echo json_encode(['status'=>'success']);
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }
}

// 3. FILTROS Y CONSULTA
$search = trim($_GET['q'] ?? '');
$cat = $_GET['cat'] ?? '';
$filterStatus = $_GET['status'] ?? 'all'; 
$filterType = $_GET['prod_type'] ?? '';

$where = "WHERE p.activo = 1 AND p.id_empresa = $EMP_ID";

if ($search) {
    if (strpos($search, ',') !== false) {
        $codes = array_map('trim', explode(',', $search));
        $safeCodes = [];
        foreach($codes as $c) { if($c) $safeCodes[] = $pdo->quote($c); }
        if(!empty($safeCodes)) {
            $where .= " AND p.codigo IN (" . implode(',', $safeCodes) . ")";
        }
    } else {
        $where .= " AND (p.nombre LIKE '%$search%' OR p.codigo LIKE '%$search%')";
    }
}

if ($cat) $where .= " AND p.categoria = '$cat'";

if ($filterType === 'service')     $where .= " AND p.es_servicio = 1";
if ($filterType === 'raw')         $where .= " AND p.es_materia_prima = 1";
if ($filterType === 'elaborated')  $where .= " AND p.es_elaborado = 1";
if ($filterType === 'merchandise') $where .= " AND p.es_servicio = 0 AND p.es_materia_prima = 0 AND p.es_elaborado = 0";

if ($filterStatus === 'web_only') $where .= " AND p.es_web = 1";
if ($filterStatus === 'no_desc')  $where .= " AND (p.descripcion IS NULL OR p.descripcion = '')";

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$total = $pdo->query("SELECT COUNT(*) FROM productos p $where")->fetchColumn();
$totalPages = ceil($total / $perPage);

// SELECCIONAMOS LAS NUEVAS COLUMNAS (color, unidad_medida, peso)
$sql = "SELECT p.codigo, p.nombre, p.precio, p.categoria, p.es_web, p.sucursales_web, p.descripcion,
               p.es_servicio, p.es_materia_prima, p.es_elaborado,
               p.color, p.unidad_medida, p.peso 
        FROM productos p 
        $where 
        ORDER BY p.nombre ASC 
        LIMIT $perPage OFFSET $offset";
$products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$cats = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE activo=1 AND id_empresa=$EMP_ID ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestor Web</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 80px; }
        .sticky-top-custom { position: sticky; top: 0; z-index: 1020; background: white; border-bottom: 1px solid #ddd; padding: 15px 0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .img-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #dee2e6; transition: transform 0.2s; }
        .img-thumb:hover { transform: scale(1.5); z-index: 10; position: relative; }
        .suc-btn { width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; border-radius: 50%; border: 1px solid #dee2e6; background: #fff; color: #ccc; cursor: pointer; transition: all 0.2s; font-weight: bold; }
        .suc-btn.active { background: #0d6efd; color: white; border-color: #0d6efd; box-shadow: 0 2px 5px rgba(13,110,253,0.4); }
        .suc-btn:hover { border-color: #0d6efd; color: #0d6efd; }
        .suc-btn.active:hover { color: white; }
        .table-align-middle td { vertical-align: middle; }
        .desc-truncate { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; color: #6c757d; font-size: 0.9em; }
        .badge-type { font-size: 0.7rem; padding: 2px 5px; margin-right: 3px; }
        .bg-orange { background-color: #fd7e14 !important; color: white !important; }
    </style>
</head>
<body>



<div class="container-fluid px-4">
    
    <div class="sticky-top-custom mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="m-0 fw-bold text-primary"><i class="fas fa-globe me-2"></i> Gestor E-commerce</h4>
                <small class="text-muted">Administra la visibilidad y contenido web</small>
            </div>

            <form class="d-flex gap-2 flex-grow-1 flex-wrap justify-content-end" style="max-width: 1000px;">
                <select class="form-select w-auto" name="prod_type" onchange="this.form.submit()">
                    <option value="">Todos los Tipos</option>
                    <option value="merchandise" <?php echo $filterType=='merchandise'?'selected':''; ?>>📦 Mercancía General</option>
                    <option value="elaborated" <?php echo $filterType=='elaborated'?'selected':''; ?>>🍳 Elaborados</option>
                    <option value="service" <?php echo $filterType=='service'?'selected':''; ?>>🛠️ Servicios</option>
                    <option value="raw" <?php echo $filterType=='raw'?'selected':''; ?>>🥩 Materias Primas</option>
                </select>

                <select class="form-select w-auto" name="status" onchange="this.form.submit()">
                    <option value="all">Estado: Todos</option>
                    <option value="web_only" <?php echo $filterStatus=='web_only'?'selected':''; ?>>🟢 Solo Web Activos</option>
                    <option value="no_desc" <?php echo $filterStatus=='no_desc'?'selected':''; ?>>⚠️ Sin Descripción</option>
                </select>
                
                <select class="form-select w-auto" name="cat" onchange="this.form.submit()">
                    <option value="">Todas las Categorías</option>
                    <?php foreach($cats as $c): ?><option value="<?php echo $c; ?>" <?php echo $cat==$c?'selected':''; ?>><?php echo $c; ?></option><?php endforeach; ?>
                </select>

                <div class="input-group" style="min-width: 250px;">
                    <input type="text" class="form-control" name="q" placeholder="Nombre o SKUs (sep. comas)" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-align-middle mb-0">
                <thead class="table-light text-uppercase small text-muted">
                    <tr>
                        <th class="text-center" width="80">Foto</th>
                        <th>Producto</th>
                        <th class="text-center" width="100">Estado Web</th>
                        <th class="text-center" width="250">Sucursales Visibles (1-6)</th>
                        <th>Detalles Web</th>
                        <th class="text-end" width="100">Precio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $hasImg = file_exists($localPath . $p['codigo'] . '.jpg');
                        $imgUrl = $hasImg ? "image.php?code=" . $p['codigo'] : "https://via.placeholder.com/50?text=IMG";
                        $sucs = explode(',', $p['sucursales_web'] ?? '');
                        $desc = $p['descripcion'] ?? '';
                        $color = $p['color'] ?? '';
                        $unit = $p['unidad_medida'] ?? '';
                        $peso = $p['peso'] ?? 0;
                    ?>
                    <tr>
                        <td class="text-center">
                            <img src="<?php echo $imgUrl; ?>" class="img-thumb shadow-sm" onclick="triggerUpload('<?php echo $p['codigo']; ?>')" title="Clic para cambiar foto">
                            <?php if(!$hasImg): ?><div class="text-danger small fw-bold mt-1" style="font-size:0.6rem">FALTA</div><?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">
                                <?php echo htmlspecialchars($p['nombre']); ?>
                                <?php if($p['es_servicio']): ?><span class="badge bg-info text-dark badge-type">SERV</span><?php endif; ?>
                                <?php if($p['es_materia_prima']): ?><span class="badge bg-secondary badge-type">MP</span><?php endif; ?>
                                <?php if($p['es_elaborado']): ?><span class="badge bg-warning text-dark badge-type">ELAB</span><?php endif; ?>
                            </div>
                            <span class="badge bg-light text-dark border"><?php echo $p['categoria']; ?></span>
                            <small class="text-muted font-monospace ms-2"><?php echo $p['codigo']; ?></small>
                        </td>
                        
                        <td class="text-center">
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input" type="checkbox" style="cursor:pointer; transform: scale(1.4);" 
                                       onchange="toggleWeb('<?php echo $p['codigo']; ?>', this)"
                                       <?php echo $p['es_web'] ? 'checked' : ''; ?>>
                            </div>
                        </td>

                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1">
                                <?php for($i=1; $i<=6; $i++): 
                                    $active = in_array($i, $sucs) ? 'active' : '';
                                ?>
                                    <button class="suc-btn <?php echo $active; ?>" 
                                            onclick="toggleSucursal('<?php echo $p['codigo']; ?>', <?php echo $i; ?>, this)">
                                        <?php echo $i; ?>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </td>

                        <td>
                            <div class="d-flex align-items-center">
                                <button class="btn btn-sm btn-outline-secondary me-2" 
                                        title="Editar Detalles"
                                        onclick="openDescEditor(
                                            '<?php echo $p['codigo']; ?>', 
                                            `<?php echo addslashes($desc); ?>`,
                                            `<?php echo addslashes($color); ?>`,
                                            `<?php echo addslashes($unit); ?>`,
                                            '<?php echo $peso; ?>',
                                            `<?php echo addslashes($p['etiqueta_web'] ?? ''); ?>`,
                                            '<?php echo $p['etiqueta_color'] ?? ''; ?>'
                                        )">
                                    <i class="fas fa-pen"></i>
                                </button>
                                
                                <div class="d-flex flex-column lh-1">
                                    <?php if($desc): ?>
                                        <span class="desc-truncate"><?php echo htmlspecialchars($desc); ?></span>
                                    <?php else: ?>
                                        <span class="text-danger small fst-italic mb-1"><i class="fas fa-exclamation-circle"></i> Sin desc.</span>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted" style="font-size:0.75rem;">
                                        <?php 
                                            $props = [];
                                            if($color) $props[] = "Col: $color";
                                            if($unit) $props[] = "Tam: $unit";
                                            if($peso > 0) $props[] = "Peso: {$peso}kg";
                                            echo implode(' | ', $props);
                                        ?>
                                    </small>
                                </div>
                            </div>
                        </td>

                        <td class="text-end fw-bold text-dark">$<?php echo number_format($p['precio'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
            <small class="text-muted">Total: <?php echo $total; ?> productos</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo $filterStatus; ?>&cat=<?php echo urlencode($cat); ?>&prod_type=<?php echo $filterType; ?>">Anterior</a>
                        </li>
                    <?php endif; ?>
                    
                    <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                    
                    <?php if($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo $filterStatus; ?>&cat=<?php echo urlencode($cat); ?>&prod_type=<?php echo $filterType; ?>">Siguiente</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </div>
</div>

<input type="file" id="uploadInput" style="display:none" accept="image/*">

<div class="modal fade" id="descModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold">Editar Detalles Web</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label small text-muted fw-bold">Color</label>
                        <input type="text" id="editColor" class="form-control form-control-sm" placeholder="Ej: Rojo">
                    </div>
                    <div class="col-4">
                        <label class="form-label small text-muted fw-bold">Tamaño/Unidad</label>
                        <input type="text" id="editUnit" class="form-control form-control-sm" placeholder="Ej: XL / Litro">
                    </div>
                    <div class="col-4">
                        <label class="form-label small text-muted fw-bold">Peso (kg)</label>
                        <input type="number" step="0.01" id="editWeight" class="form-control form-control-sm" placeholder="0.00">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small text-muted fw-bold">Cinta/Etiqueta (E-commerce)</label>
                        <select id="editEtiqueta" class="form-select form-select-sm">
                            <option value="">Sin Cinta</option>
                            <option value="OFERTA">🔥 OFERTA</option>
                            <option value="NUEVO">✨ NUEVO</option>
                            <option value="ESPECIAL">💎 ESPECIAL</option>
                            <option value="TOP VENTAS">🏆 TOP VENTAS</option>
                            <option value="ÚLTIMAS UNIDADES">📢 ÚLTIMAS UNIDADES</option>
                            <option value="LIQUIDACIÓN">♻️ LIQUIDACIÓN</option>
                            <option value="RECOMENDADO">👍 RECOMENDADO</option>
                            <option value="EDICIÓN LIMITADA">⭐ LIMITADO</option>
                            <option value="IMPORTADO">✈️ IMPORTADO</option>
                            <option value="ECOLÓGICO">🌿 ECOLÓGICO</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small text-muted fw-bold">Color Cinta</label>
                        <select id="editEtiquetaColor" class="form-select form-select-sm">
                            <option value="bg-danger">Rojo</option>
                            <option value="bg-warning text-dark">Amarillo</option>
                            <option value="bg-orange">Naranja</option>
                        </select>
                    </div>
                </div>

                <label class="form-label small text-muted fw-bold">Descripción Detallada</label>
                <textarea id="descText" class="form-control" rows="5" placeholder="Escribe detalles atractivos del producto..."></textarea>
                <input type="hidden" id="descCode">
            </div>
            <div class="modal-footer p-2 bg-light">
                <button class="btn btn-primary w-100" onclick="saveDetails()">
                    <i class="fas fa-save me-2"></i> Guardar Cambios
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    // --- 1. TOGGLE ESTADO WEB ---
    async function toggleWeb(id, checkbox) {
        const val = checkbox.checked ? 1 : 0;
        try {
            await fetch('web_manager.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_web_status', id: id, value: val })
            });
        } catch(e) { 
            checkbox.checked = !val; 
            alert('Error conexión'); 
        }
    }

    // --- 2. GESTIÓN SUCURSALES ---
    async function toggleSucursal(id, num, btn) {
        btn.classList.toggle('active');
        const parent = btn.parentElement;
        const activeNums = [];
        parent.querySelectorAll('.suc-btn').forEach(b => {
            if (b.classList.contains('active')) activeNums.push(b.innerText);
        });

        try {
            await fetch('web_manager.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_sucursales', id: id, value: activeNums })
            });
        } catch(e) { alert('Error guardando sucursal'); }
    }

    // --- 3. EDITOR DE DETALLES (DESC + PROPIEDADES) ---
    const descModal = new bootstrap.Modal('#descModal');
    
    function openDescEditor(id, text, color, unit, weight, etiqueta, eColor) {
        document.getElementById('descCode').value = id;
        document.getElementById('descText').value = text;
        document.getElementById('editColor').value = color;
        document.getElementById('editUnit').value = unit;
        document.getElementById('editWeight').value = weight;
        document.getElementById('editEtiqueta').value = etiqueta || "";
        document.getElementById('editEtiquetaColor').value = eColor || "bg-danger";
        descModal.show();
    }
    
    async function saveDetails() {
        const id = document.getElementById('descCode').value;
        
        const data = {
            action: 'update_details',
            id: id,
            descripcion: document.getElementById('descText').value,
            color: document.getElementById('editColor').value,
            unidad: document.getElementById('editUnit').value,
            peso: document.getElementById('editWeight').value,
            etiqueta: document.getElementById('editEtiqueta').value,
            etiqueta_color: document.getElementById('editEtiquetaColor').value
        };
        
        try {
            const res = await fetch('web_manager.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            const json = await res.json();
            
            if(json.status === 'success') {
                descModal.hide();
                location.reload(); 
            } else {
                alert('Error al guardar: ' + (json.msg || 'Desconocido'));
            }
        } catch(e) { alert('Error de conexión'); }
    }

    // --- 4. SUBIDA DE FOTOS ---
    let currentUploadCode = null;
    function triggerUpload(code) {
        currentUploadCode = code;
        document.getElementById('uploadInput').click();
    }
    document.getElementById('uploadInput').addEventListener('change', async function() {
        if (!this.files[0]) return;
        const formData = new FormData();
        formData.append('new_photo', this.files[0]);
        formData.append('prod_code', currentUploadCode);
        
        try {
            const res = await fetch('web_manager.php', { method: 'POST', body: formData });
            if ((await res.json()).status === 'success') location.reload();
        } catch (e) { alert("Error subiendo imagen."); }
    });
</script>



<?php include_once 'menu_master.php'; ?>
</body>
</html>

