<?php
// ARCHIVO: products_table.php v3.2 (CON FUENTE ROBOTO EN PRECIO)
ini_set('display_errors', 0);
require_once 'db.php';
require_once 'pos_audit.php'; 

// ---------------------------------------------------------
// 1. CARGAR CONFIGURACIÓN
// ---------------------------------------------------------
require_once 'config_loader.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
$localPath = '/home/marinero/product_images/'; 

// ---------------------------------------------------------
// 2. FUNCIÓN DE RENDERIZADO (CORE)
// ---------------------------------------------------------
function renderProductRows($rows, $localPath) {
    ob_start();
    if(empty($rows)): ?>
        <tr><td colspan="11" class="text-center py-5 text-muted">No se encontraron productos.</td></tr>
    <?php else: 
        foreach($rows as $p): 
            $hasImg = file_exists($localPath . $p['codigo'] . '.jpg'); 
            $stock = floatval($p['stock_total']);
            $isActive = intval($p['activo'] ?? 1);
            $rowClass = $isActive ? '' : 'row-inactive';
    ?>
    <tr class="<?php echo $rowClass; ?>">
        <td class="no-print ps-3"><input type="checkbox" class="form-check-input bulk-check" value="<?php echo $p['codigo']; ?>"></td>
        <td class="text-center no-print"><a href="product_history.php?sku=<?php echo urlencode($p['codigo']); ?>" class="btn btn-outline-secondary btn-action border-0" title="Kardex"><i class="fas fa-history"></i></a></td>
        <td class="ps-2 no-print"><img src="<?php echo $hasImg ? 'image.php?code='.$p['codigo'] : 'https://via.placeholder.com/50?text=IMG'; ?>" class="prod-img-table" onclick="triggerUpload('<?php echo $p['codigo']; ?>')"></td>
        <td class="small font-monospace"><?php echo $p['codigo']; ?></td>
        <td onclick="openEditor('<?php echo $p['codigo']; ?>')" style="cursor:pointer;">
            <div class="fw-bold text-primary"><?php echo $p['nombre']; ?></div>
            <div class="d-flex mt-1 opacity-75">
                <?php if($p['es_materia_prima']): ?><span class="emoji-span" title="Materia Prima">🧱</span><?php endif; ?>
                <?php if($p['es_servicio']): ?><span class="emoji-span" title="Servicio">🛠️</span><?php endif; ?>
                <?php if($p['es_cocina']): ?><span class="emoji-span" title="Cocina">👨‍🍳</span><?php endif; ?>
                <?php if(!$isActive): ?><span class="badge bg-danger text-white border ms-1" style="font-size:0.6rem;">INACTIVO</span><?php endif; ?>
            </div>
        </td>
        <td class="text-center">
            <div class="form-check form-switch d-flex justify-content-center">
                <input class="form-check-input" type="checkbox" onchange="toggleWeb('<?php echo $p['codigo']; ?>', this)" <?php echo $p['es_web'] ? 'checked' : ''; ?>>
            </div>
        </td>
        <td class="small text-muted"><?php echo $p['categoria']; ?></td>
        
        <td class="text-center editable-cell" data-sku="<?php echo $p['codigo']; ?>" data-field="stock" data-value="<?php echo $stock; ?>">
            <span class="badge badge-stock <?php echo ($stock <= 0) ? 'bg-danger' : 'bg-success-subtle text-success'; ?>"><?php echo number_format($stock, 1); ?></span>
        </td>
        
        <td class="text-end price-style editable-cell" data-sku="<?php echo $p['codigo']; ?>" data-field="price" data-value="<?php echo $p['precio']; ?>">
            $<?php echo number_format($p['precio'], 2); ?>
            <i class="fas fa-history history-btn" onclick="showHistory('<?php echo $p['codigo']; ?>')"></i>
        </td>
        
        <td class="text-end fw-bold text-success bg-light">$<?php echo number_format($p['ganancia_neta'], 2); ?></td>
        <td class="text-center no-print">
            <div class="btn-group">
                <button class="btn btn-outline-primary btn-action" onclick="openEditor('<?php echo $p['codigo']; ?>')" title="Editar"><i class="fas fa-edit"></i></button>
                <a href="pos_shrinkage.php?prefill_sku=<?php echo urlencode($p['codigo']); ?>" class="btn btn-outline-danger btn-action" title="Merma"><i class="fas fa-trash-alt"></i></a>
            </div>
        </td>
    </tr>
    <?php endforeach; 
    endif;
    return ob_get_clean();
}

// ---------------------------------------------------------
// 3. PROCESAMIENTO POST (EDICIÓN, IMÁGENES, BULK)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // EDICIÓN RÁPIDA (INLINE EDIT)
    if (isset($_POST['action']) && $_POST['action'] === 'inline_edit') {
        try {
            $sku = $_POST['sku'];
            $field = $_POST['field']; // 'price' o 'stock'
            $value = floatval($_POST['value']);
            
            if ($field === 'price') {
                $stmt = $pdo->prepare("UPDATE productos SET precio = ? WHERE codigo = ? AND id_empresa = ?");
                $stmt->execute([$value, $sku, $EMP_ID]);
                log_audit($pdo, 'PRECIO_UPDATE', $_SESSION['user_id'] ?? 'Admin', ['sku'=>$sku, 'nuevo_precio'=>$value]);
                echo json_encode(['status'=>'success', 'msg'=>'Precio actualizado']);
            } elseif ($field === 'stock') {
                $chk = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
                $chk->execute([$sku, $ALM_ID]);
                if ($chk->fetch()) {
                    $stmt = $pdo->prepare("UPDATE stock_almacen SET cantidad = ? WHERE id_producto = ? AND id_almacen = ?");
                    $stmt->execute([$value, $sku, $ALM_ID]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO stock_almacen (id_producto, id_almacen, cantidad) VALUES (?, ?, ?)");
                    $stmt->execute([$sku, $ALM_ID, $value]);
                }
                log_audit($pdo, 'STOCK_AJUSTE_INLINE', $_SESSION['user_id'] ?? 'Admin', ['sku'=>$sku, 'nuevo_stock'=>$value]);
                echo json_encode(['status'=>'success', 'msg'=>'Stock ajustado']);
            }
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }

    // SUBIDA DE IMAGEN
    if (isset($_FILES['new_photo'])) {
        try {
            $code = $_POST['prod_code'] ?? '';
            if (!$code) throw new Exception("Código ausente.");
            $file = $_FILES['new_photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error subida.");
            $imgData = file_get_contents($file['tmp_name']);
            $src = @imagecreatefromstring($imgData);
            if (!$src) throw new Exception("Imagen corrupta.");
            $width = imagesx($src); $height = imagesy($src);
            $size = min($width, $height);
            $x = ($width - $size) / 2; $y = ($height - $size) / 2;
            $dest = imagecreatetruecolor(200, 200);
            imagecopyresampled($dest, $src, 0, 0, $x, $y, 200, 200, $size, $size);
            if (!is_dir($localPath)) @mkdir($localPath, 0777, true);
            imagejpeg($dest, $localPath . $code . '.jpg', 85);
            imagedestroy($src); imagedestroy($dest);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        exit;
    }

    // TOGGLE WEB
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_web') {
        try {
            $sku = $_POST['sku'];
            $val = intval($_POST['val']);
            $stmt = $pdo->prepare("UPDATE productos SET es_web = ? WHERE codigo = ? AND id_empresa = ?");
            $stmt->execute([$val, $sku, $EMP_ID]);
            echo json_encode(['status'=>'success']); 
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }

    // ACCIONES MASIVAS
    if (isset($_POST['bulk_action'])) {
        try {
            $action = $_POST['bulk_action'];
            $skus = json_decode($_POST['skus'], true);
            if (empty($skus)) throw new Exception("Sin selección.");
            $inQuery = implode(',', array_fill(0, count($skus), '?'));
            $params = $skus; 
            array_push($params, $EMP_ID);
            switch ($action) {
                case 'web_on': $sql = "UPDATE productos SET es_web = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?"; break;
                case 'web_off': $sql = "UPDATE productos SET es_web = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?"; break;
                case 'active_on': $sql = "UPDATE productos SET activo = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?"; break;
                case 'active_off': $sql = "UPDATE productos SET activo = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?"; break;
                case 'change_cat':
                    $newCat = $_POST['new_cat_val'] ?? 'General';
                    $sql = "UPDATE productos SET categoria = ? WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    array_unshift($params, $newCat);
                    break;
                default: throw new Exception("Acción inválida.");
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['status'=>'success', 'count'=>count($skus)]); 
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }
}

// ---------------------------------------------------------
// 4. API: HISTORIAL (GET)
// ---------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $sku = $_GET['sku'];
    try {
        $stmt = $pdo->prepare("SELECT fecha, usuario, detalles FROM pos_audit WHERE tipo = 'PRECIO_UPDATE' AND detalles LIKE ? ORDER BY fecha DESC LIMIT 10");
        $stmt->execute(['%"sku":"'.$sku.' "%']);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($logs);
    } catch(Exception $e) { echo json_encode([]); }
    exit;
}

// ---------------------------------------------------------
// 5. CONSULTA DE DATOS (COMÚN PARA HTML Y AJAX)
// ---------------------------------------------------------
$isAjax = isset($_GET['ajax_load']);

// Filtros
$filterCode  = $_GET['code'] ?? '';
$filterName  = $_GET['name'] ?? '';
$filterStatus = $_GET['status'] ?? 'active';
$filterStockRange = $_GET['stock_range'] ?? '';
$onlyLatest  = isset($_GET['latest']);
$onlyProd    = isset($_GET['only_prod']);

// Paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sort = $_GET['sort'] ?? 'nombre';
$dir  = $_GET['dir'] ?? 'ASC';

// WHERE
$whereClauses = ["p.id_empresa = $EMP_ID"];
$params = [];

if ($filterStatus === 'active') $whereClauses[] = "p.activo = 1";
elseif ($filterStatus === 'inactive') $whereClauses[] = "p.activo = 0";

if ($onlyProd) $whereClauses[] = "p.es_materia_prima = 0 AND p.es_servicio = 0";
if ($filterCode) { $whereClauses[] = "p.codigo LIKE :c"; $params[':c'] = "%$filterCode%"; }
if ($filterName) { $whereClauses[] = "p.nombre LIKE :n"; $params[':n'] = "%$filterName%"; }

$whereSQL = implode(" AND ", $whereClauses);

// HAVING (Stock)
$havingClause = "";
if ($filterStockRange !== '') {
    $val = $filterStockRange;
    if (strpos($val, '-') !== false) {
        $parts = explode('-', $val);
        $havingClause = "HAVING stock_total BETWEEN " . floatval($parts[0]) . " AND " . floatval($parts[1]);
    } elseif (strpos($val, '<') === 0) {
        $havingClause = "HAVING stock_total < " . floatval(substr($val, 1));
    } elseif (strpos($val, '>') === 0) {
        $havingClause = "HAVING stock_total > " . floatval(substr($val, 1));
    } else {
        $havingClause = "HAVING stock_total = " . floatval($val);
    }
}

// QUERY DATOS
$sqlBase = "SELECT p.*, 
            (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = $ALM_ID) as stock_total,
            (p.precio - p.costo) as ganancia_neta
            FROM productos p 
            WHERE $whereSQL 
            $havingClause";

$stmtAll = $pdo->prepare($sqlBase . " ORDER BY $sort $dir");
$stmtAll->execute($params);
$allRows = $stmtAll->fetchAll(PDO::FETCH_ASSOC); // Traemos todo para poder filtrar con HAVING y paginar en PHP
$totalProducts = count($allRows);
$totalPages = ceil($totalProducts / $limit);
$productosPagina = array_slice($allRows, $offset, $limit);

// QUERY VALOR TOTAL (Global)
$stmtValor = $pdo->prepare("SELECT SUM(s.cantidad * p.costo) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE s.id_almacen = ? AND p.id_empresa = ?");
$stmtValor->execute([$ALM_ID, $EMP_ID]);
$valorInventario = floatval($stmtValor->fetchColumn() ?: 0);

// --- RESPUESTA AJAX ---
if ($isAjax) {
    echo json_encode([
        'html' => renderProductRows($productosPagina, $localPath),
        'total' => $totalProducts,
        'page' => $page,
        'pages' => $totalPages,
        'valor' => number_format($valorInventario, 2)
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario & Web</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', sans-serif; color: #1e293b; padding-bottom: 80px; }
        .navbar-custom { background: #0f172a; color: white; padding: 0.8rem 1.5rem; border-bottom: 3px solid #3b82f6; }
        .filter-section { background: #ffffff; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .card-table { border: none; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .table thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; background: #f8fafc; }
        .prod-img-table { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; border: 1px solid #eee; cursor: pointer; transition: transform 0.2s; }
        .prod-img-table:hover { transform: scale(1.5); border-color: #3b82f6; }
        .badge-stock { padding: 5px 10px; border-radius: 15px; font-weight: 700; font-size: 0.8rem; }
        .context-badge { background: rgba(255,255,255,0.15); padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.1); }
        .row-inactive { background-color: #fff5f5 !important; opacity: 0.8; }
        
        .editable-cell { position: relative; cursor: cell; transition: background 0.2s; }
        .editable-cell:hover { background-color: #eef2ff; }
        .editable-cell:hover::after { content: '✎'; position: absolute; right: 5px; top: 50%; transform: translateY(-50%); color: #3b82f6; font-size: 0.8rem; opacity: 0.5; }
        
        /* ESTILO ESPECÍFICO PARA EL PRECIO */
        .price-style {
            font-family: 'Roboto', sans-serif;
            font-size: 1.15rem; /* Un poco más grande */
            font-weight: 700;
            color: #0f172a;
        }

        .history-btn { font-size: 0.7rem; color: #64748b; cursor: pointer; margin-left: 5px; }
        .history-btn:hover { color: #3b82f6; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="pb-5">

<nav class="navbar-custom mb-4 d-flex justify-content-between align-items-center no-print">
    <div class="d-flex align-items-center">
        <h4 class="m-0 fw-bold"><i class="fas fa-boxes me-2"></i> INVENTARIO</h4>
        <div class="d-none d-md-block ms-4 context-badge text-white">
            <i class="fas fa-building me-1 text-info"></i> Suc: <strong><?php echo $SUC_ID; ?></strong> | 
            <i class="fas fa-warehouse me-1 text-warning"></i> Alm: <strong><?php echo $ALM_ID; ?></strong>
        </div>
        <div class="ms-3 context-badge" style="background: rgba(34, 197, 94, 0.2); border: 1px solid rgba(34, 197, 94, 0.4);">
            <i class="fas fa-dollar-sign me-1 text-success"></i>
            <span class="text-white">Valor: </span>
            <strong class="text-success" id="totalValueDisplay">$<?php echo number_format($valorInventario, 2); ?></strong>
        </div>
    </div>
    <div>
        <a href="inventory_report.php" class="btn btn-info btn-sm px-3 fw-bold me-2 text-white"><i class="fas fa-chart-pie me-1"></i> Informe</a>
        <button onclick="printInventoryCount()" class="btn btn-warning btn-sm px-3 fw-bold me-2 text-dark"><i class="fas fa-clipboard-list me-1"></i> Conteo</button>
        <button onclick="printTable()" class="btn btn-light btn-sm px-3 fw-bold me-2"><i class="fas fa-print me-1"></i> Lista</button>
        <a href="dashboard.php" class="btn btn-primary btn-sm px-3 fw-bold"><i class="fas fa-home"></i></a>
    </div>
</nav>

<div class="container-fluid px-4">
    <div class="filter-section shadow-sm no-print">
        <form id="filterForm" class="row g-3 align-items-end" onsubmit="event.preventDefault(); loadData(1);">
            <div class="col-md-2"><label class="small fw-bold text-muted mb-1">SKU</label><input type="text" id="f_code" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterCode); ?>"></div>
            <div class="col-md-2"><label class="small fw-bold text-muted mb-1">Nombre</label><input type="text" id="f_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterName); ?>"></div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Estado</label>
                <select id="f_status" class="form-select form-select-sm">
                    <option value="active">✅ Activos</option>
                    <option value="inactive">❌ Inactivos</option>
                    <option value="all">♾️ Todos</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Rango Stock</label>
                <input type="text" id="f_stock" class="form-control form-control-sm" placeholder="Ej: <5, >10, 0, 10-20">
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">Mostrar</label>
                <select id="f_limit" class="form-select form-select-sm" onchange="loadData(1)">
                    <option value="10">10 por pág</option>
                    <option value="20">20 por pág</option>
                    <option value="50">50 por pág</option>
                    <option value="100">100 por pág</option>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <button type="submit" class="btn btn-dark btn-sm fw-bold w-100"><i class="fas fa-filter"></i> Filtrar</button>
                <button type="button" class="btn btn-success btn-sm w-100 mt-1" onclick="openProductCreator(productoCreadoExito)"><i class="fas fa-plus"></i> Nuevo</button>
            </div>
        </form>
        
        <hr class="my-3">
        
        <div class="d-flex align-items-center bg-light p-2 rounded border gap-2">
            <div class="form-check ms-2"><input class="form-check-input" type="checkbox" id="selectAll"><label class="form-check-label small fw-bold" for="selectAll">Todos</label></div>
            <div class="vr mx-2"></div>
            <select class="form-select form-select-sm" style="max-width: 200px;" id="bulkActionSelect">
                <option value="">-- Acción Masiva --</option>
                <option value="print_labels">🏷️ Imprimir Etiquetas</option>
                <option value="web_on">🌐 Activar en WEB</option>
                <option value="web_off">🚫 Ocultar de WEB</option>
                <option value="active_on">✅ Activar Producto</option>
                <option value="active_off">❌ Desactivar Producto</option>
                <option value="change_cat">📂 Cambiar Categoría</option>
            </select>
            <input type="text" class="form-control form-control-sm d-none" id="bulkCatInput" placeholder="Nueva Categoría" style="max-width: 150px;">
            <button class="btn btn-secondary btn-sm" onclick="applyBulkAction()">Aplicar</button>
            <div class="ms-auto text-muted small">
                Total: <strong id="totalCountDisplay"><?php echo $totalProducts; ?></strong> | Pág <span id="currentPageDisplay"><?php echo $page; ?></span>
            </div>
        </div>
    </div>

    <div class="card card-table shadow-sm" id="printableArea">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="productsTable">
                <thead class="bg-light">
                    <tr>
                        <th class="no-print" style="width: 30px;">#</th>
                        <th class="text-center no-print" style="width: 50px;">Hist</th>
                        <th class="ps-2 no-print" style="width: 60px;">Img</th>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th class="text-center" style="width: 80px;">Web</th>
                        <th>Categoría</th>
                        <th class="text-center">Stock</th>
                        <th class="text-end">Venta</th>
                        <th class="text-end bg-light">Utilidad</th>
                        <th class="text-center no-print" style="width: 50px;">Acción</th>
                    </tr>
                </thead>
                <tbody class="bg-white" id="tableBody">
                    <?php echo renderProductRows($productosPagina, $localPath); ?>
                </tbody>
            </table>
        </div>
    </div>

    <nav class="mt-4 no-print" id="paginationNav">
        <?php if($totalPages > 1): ?>
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo $page<=1?'disabled':''; ?>">
                <button class="page-link" onclick="loadData(<?php echo $page-1; ?>)">Anterior</button>
            </li>
            <li class="page-item disabled"><span class="page-link">Pág <?php echo $page; ?> de <?php echo $totalPages; ?></span></li>
            <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>">
                <button class="page-link" onclick="loadData(<?php echo $page+1; ?>)">Siguiente</button>
            </li>
        </ul>
        <?php endif; ?>
    </nav>
</div>

<input type="file" id="fileInput" accept="image/jpeg, image/webp" style="display:none" onchange="uploadPhoto()">

<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Editar Producto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalEditorContent">
                <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="priceHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h6 class="modal-title mb-0">Historial Precios</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body small p-0"><ul class="list-group list-group-flush" id="historyList"></ul></div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// VARIABLES GLOBALES
let currentPage = 1;
let currentCode = '';
// Variable para imprimir (solo página actual por ahora para simplificar)
const jsProds = []; // (Opcional: Si quieres imprimir todo desde JS, necesitarías cargarlo aparte)

// --- 1. CARGA AJAX ---
async function loadData(page) {
    currentPage = page;
    const limit = document.getElementById('f_limit').value;
    const code = document.getElementById('f_code').value;
    const name = document.getElementById('f_name').value;
    const status = document.getElementById('f_status').value;
    const stockRange = document.getElementById('f_stock').value;

    const url = `products_table.php?ajax_load=1&page=${page}&limit=${limit}&code=${encodeURIComponent(code)}&name=${encodeURIComponent(name)}&status=${status}&stock_range=${encodeURIComponent(stockRange)}`;
    
    document.getElementById('tableBody').style.opacity = '0.5';
    
    try {
        const res = await fetch(url);
        const data = await res.json();
        
        document.getElementById('tableBody').innerHTML = data.html;
        document.getElementById('totalCountDisplay').innerText = data.total;
        document.getElementById('currentPageDisplay').innerText = data.page;
        document.getElementById('totalValueDisplay').innerText = '$' + data.valor;
        
        renderPagination(data.page, data.pages);
        
        document.getElementById('tableBody').style.opacity = '1';
        initInlineEdit();
        
    } catch(e) { console.error(e); alert('Error cargando datos'); }
}

function renderPagination(curr, total) {
    let html = '<ul class="pagination justify-content-center">';
    if(curr > 1) html += `<li class="page-item"><button class="page-link" onclick="loadData(${curr-1})">Anterior</button></li>`;
    html += `<li class="page-item disabled"><span class="page-link">Pág ${curr} de ${total}</span></li>`;
    if(curr < total) html += `<li class="page-item"><button class="page-link" onclick="loadData(${curr+1})">Siguiente</button></li>`;
    html += '</ul>';
    document.getElementById('paginationNav').innerHTML = html;
}

// --- 2. EDICIÓN EN LÍNEA ---
function initInlineEdit() {
    const editCells = document.querySelectorAll('.editable-cell');
    editCells.forEach(cell => {
        cell.ondblclick = function() {
            if(this.querySelector('input')) return;
            const originalVal = this.dataset.value;
            const sku = this.dataset.sku;
            const field = this.dataset.field;
            
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm p-0 text-end';
            input.value = originalVal;
            input.style.width = '80px';
            
            this.innerHTML = '';
            this.appendChild(input);
            input.focus();
            
            input.onblur = async () => saveInline(this, sku, field, input.value, originalVal);
            input.onkeydown = (e) => { if(e.key === 'Enter') input.blur(); if(e.key === 'Escape') { this.innerHTML = field==='price' ? '$'+originalVal : originalVal; } };
        };
    });
}

async function saveInline(cell, sku, field, newVal, oldVal) {
    if(newVal == oldVal) { 
        cell.innerHTML = field==='price' ? '$'+parseFloat(newVal).toFixed(2) : parseFloat(newVal).toFixed(1);
        if(field === 'price') cell.innerHTML += ` <i class="fas fa-history history-btn" onclick="showHistory('${sku}')"></i>`;
        return; 
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'inline_edit');
        formData.append('sku', sku);
        formData.append('field', field);
        formData.append('value', newVal);
        
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if(data.status === 'success') {
            const displayVal = field==='price' ? '$'+parseFloat(newVal).toFixed(2) : parseFloat(newVal).toFixed(1);
            cell.innerHTML = displayVal;
            cell.dataset.value = newVal;
            if(field === 'price') cell.innerHTML += ` <i class="fas fa-history history-btn" onclick="showHistory('${sku}')"></i>`;
            if(field === 'stock') cell.innerHTML = `<span class="badge badge-stock ${newVal>0?'bg-success-subtle text-success':'bg-danger'}">${displayVal}</span>`;
        } else {
            alert('Error: ' + data.msg);
            cell.innerHTML = oldVal;
        }
    } catch(e) { alert('Error de conexión'); }
}

// --- 3. HISTORIAL ---
async function showHistory(sku) {
    const list = document.getElementById('historyList');
    list.innerHTML = '<li class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div></li>';
    new bootstrap.Modal(document.getElementById('priceHistoryModal')).show();
    
    try {
        const res = await fetch(`products_table.php?action=get_history&sku=${sku}`);
        const logs = await res.json();
        if(logs.length === 0) list.innerHTML = '<li class="list-group-item text-muted">Sin cambios recientes.</li>';
        else list.innerHTML = logs.map(l => `<li class="list-group-item"><div class="fw-bold">${l.fecha}</div><div class="text-muted small">Por: ${l.usuario}</div></li>`).join('');
    } catch(e) { list.innerHTML = '<li class="list-group-item text-danger">Error cargando.</li>'; }
}

// --- 4. ACCIONES MASIVAS ---
const checks = document.querySelectorAll('.bulk-check');
const selectAll = document.getElementById('selectAll');
if(selectAll) selectAll.addEventListener('change', function() { document.querySelectorAll('.bulk-check').forEach(c => c.checked = this.checked); updateCount(); });
document.addEventListener('change', e => { if(e.target.classList.contains('bulk-check')) updateCount(); });
function updateCount() { document.getElementById('selectedCount').innerText = document.querySelectorAll('.bulk-check:checked').length + ' sel'; }

const bulkSelect = document.getElementById('bulkActionSelect');
if(bulkSelect) {
    bulkSelect.addEventListener('change', function() {
        const catInput = document.getElementById('bulkCatInput');
        if(this.value === 'change_cat') catInput.classList.remove('d-none'); else catInput.classList.add('d-none');
    });
}

async function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    const selected = Array.from(document.querySelectorAll('.bulk-check:checked')).map(c => c.value);
    if(selected.length === 0) return alert("Selecciona productos.");

    if (action === 'print_labels') {
        const url = `print_labels.php?skus=${selected.join(',')}`;
        window.open(url, 'Etiquetas', 'width=800,height=600');
        return;
    }

    if(!confirm(`¿Aplicar a ${selected.length} productos?`)) return;

    const formData = new FormData();
    formData.append('bulk_action', action);
    formData.append('skus', JSON.stringify(selected));
    if(action === 'change_cat') formData.append('new_cat_val', document.getElementById('bulkCatInput').value);

    try {
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.status === 'success') { alert("✅ Listo"); loadData(currentPage); } else alert("❌ Error: " + data.msg);
    } catch(e) { alert("Error conexión"); }
}

// --- VARIOS ---
function triggerUpload(code) { currentCode = code; document.getElementById('fileInput').click(); }
function uploadPhoto() {
    const file = document.getElementById('fileInput').files[0];
    if(!file) return;
    const formData = new FormData();
    formData.append('new_photo', file);
    formData.append('prod_code', currentCode);
    fetch('products_table.php', { method: 'POST', body: formData }).then(r => r.json()).then(res => {
        if(res.status==='success') location.reload(); else alert('Error: ' + res.msg);
    });
}
async function toggleWeb(sku, checkbox) {
    const val = checkbox.checked ? 1 : 0;
    const formData = new FormData();
    formData.append('action', 'toggle_web');
    formData.append('sku', sku);
    formData.append('val', val);
    try {
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.status !== 'success') { checkbox.checked = !checkbox.checked; alert("Error: " + data.msg); }
    } catch(e) { checkbox.checked = !checkbox.checked; }
}

// Editor Modal
let editModalInstance = null;
function openEditor(sku) {
    const modalElement = document.getElementById('editProductModal');
    if (!editModalInstance) { if(modalElement) editModalInstance = new bootstrap.Modal(modalElement); else return; }
    document.getElementById('modalEditorContent').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    editModalInstance.show();
    fetch(`product_editor.php?sku=${sku}`).then(r => r.text()).then(html => {
        document.getElementById('modalEditorContent').innerHTML = html;
        executeScripts(html);
    }).catch(e => document.getElementById('modalEditorContent').innerHTML = '<div class="alert alert-danger">Error carga.</div>');
}
function executeScripts(html) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    tempDiv.querySelectorAll('script').forEach(s => {
        const newScript = document.createElement('script');
        newScript.text = s.text;
        document.body.appendChild(newScript);
    });
}
window.reloadTable = function() { loadData(currentPage); }
function productoCreadoExito(nuevoProducto) { loadData(1); }

// Imprimir conteo (Nota: Imprime lo que se ve en pantalla para ser consistente con filtros)
function printInventoryCount() {
    window.print();
}
function printTable() {
    window.print();
}

// Inicializar
document.addEventListener('DOMContentLoaded', initInlineEdit);
</script>


<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>


