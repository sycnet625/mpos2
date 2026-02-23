<?php
// ARCHIVO: products_table.php v3.2 (CON ORDENAMIENTO POR COLUMNAS)
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
            $imgBase  = $localPath . $p['codigo'];
            $hasImg   = false; $imgV = '';
            foreach (['.avif','.webp','.jpg'] as $_e) {
                if (file_exists($imgBase.$_e)) { $hasImg = true; $imgV = '&v='.filemtime($imgBase.$_e); break; }
            }
            $stock = floatval($p['stock_total']);
            $isActive = intval($p['activo'] ?? 1);
            $rowClass = $isActive ? '' : 'row-inactive';
    ?>
    <tr class="<?php echo $rowClass; ?>">
        <td class="no-print ps-3"><input type="checkbox" class="form-check-input bulk-check" value="<?php echo $p['codigo']; ?>"></td>
        <td class="text-center no-print"><a href="product_history.php?sku=<?php echo urlencode($p['codigo']); ?>" class="btn btn-outline-secondary btn-action border-0" title="Kardex"><i class="fas fa-history"></i></a></td>
        <td class="ps-2 no-print"><img src="<?php echo $hasImg ? 'image.php?code='.urlencode($p['codigo']).$imgV : 'https://via.placeholder.com/50?text=IMG'; ?>" class="prod-img-table" data-code="<?php echo $p['codigo']; ?>" onclick="triggerUpload('<?php echo $p['codigo']; ?>')"></td>
        <td class="small font-monospace"><?php echo $p['codigo']; ?></td>
        <td onclick="openEditor('<?php echo $p['codigo']; ?>')" style="cursor:pointer;">
            <div class="fw-bold text-primary"><?php echo $p['nombre']; ?></div>
            <div class="d-flex mt-1 opacity-75">
                <?php if($p['es_materia_prima']): ?><span class="emoji-span" title="Materia Prima">🧱</span><?php endif; ?>
                <?php if($p['es_servicio']): ?><span class="emoji-span" title="Servicio">🛠️</span><?php endif; ?>
                <?php if($p['es_cocina']): ?><span class="emoji-span" title="Cocina">👨‍🍳</span><?php endif; ?>
                <?php if($p['es_reservable'] ?? false): ?><span class="emoji-span" title="Reservable (sin stock)">📅</span><?php endif; ?>
                <?php if(!$isActive): ?><span class="badge bg-danger text-white border ms-1" style="font-size:0.6rem;">INACTIVO</span><?php endif; ?>
            </div>
        </td>
        <td class="text-center">
            <div class="form-check form-switch d-flex justify-content-center" title="Visible en tienda web">
                <input class="form-check-input" type="checkbox" onchange="toggleWeb('<?php echo $p['codigo']; ?>', this)" <?php echo $p['es_web'] ? 'checked' : ''; ?>>
            </div>
            <div class="form-check form-switch d-flex justify-content-center align-items-center mt-1" title="Aceptar reservas sin stock">
                <input class="form-check-input" type="checkbox" style="<?php echo ($p['es_reservable'] ?? 0) ? 'background-color:#f59e0b;border-color:#d97706;' : ''; ?>"
                       onchange="toggleReservable('<?php echo $p['codigo']; ?>', this)"
                       <?php echo ($p['es_reservable'] ?? 0) ? 'checked' : ''; ?>>
                <span class="ms-1" style="font-size:0.6rem; line-height:1; color:#9ca3af;">📅</span>
            </div>
        </td>
        <td class="small text-muted"><?php echo $p['categoria']; ?></td>
        
        <td class="text-center">
            <div class="d-flex align-items-center justify-content-center">
                <button class="btn btn-sm btn-outline-warning border-0 me-1 p-0 px-1 no-print" style="font-size: 0.7rem;" 
                        onclick="openKardexAdj('<?php echo $p['codigo']; ?>', '<?php echo addslashes($p['nombre']); ?>')" title="Ajustar Stock">
                    <i class="fas fa-tools"></i>
                </button>
                <div class="editable-cell flex-grow-1" data-sku="<?php echo $p['codigo']; ?>" data-field="stock" data-value="<?php echo $stock; ?>">
                    <span class="badge badge-stock <?php echo ($stock <= 0) ? 'bg-danger' : 'bg-success-subtle text-success'; ?>"><?php echo number_format($stock, 1); ?></span>
                </div>
            </div>
        </td>
        
        <td class="text-end fw-bold editable-cell" data-sku="<?php echo $p['codigo']; ?>" data-field="price" data-value="<?php echo $p['precio']; ?>">
            $<?php echo number_format($p['precio'], 2); ?>
            <i class="fas fa-history history-btn" onclick="showHistory('<?php echo $p['codigo']; ?>')"></i>
        </td>
        <td class="text-end">$<?php echo number_format($p['precio_mayorista'], 2); ?></td>
        
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
        // ... (existing code)
    }

    // AJUSTE DE KARDEX MANUAL
    if (isset($_POST['action']) && $_POST['action'] === 'kardex_adj') {
        try {
            require_once 'kardex_engine.php';
            $sku = $_POST['sku'];
            $qty = floatval($_POST['qty']);
            $type = $_POST['type']; // 'IN' o 'OUT'
            $note = $_POST['note'];
            
            if ($type === 'OUT') $qty = -$qty;

            // Registrar en Kardex usando el engine
            KardexEngine::registrarMovimiento($pdo, $sku, $ALM_ID, $qty, 'AJUSTE_INTERNO', $note, null, $SUC_ID);
            
            if ($pdo->inTransaction()) $pdo->commit();

            log_audit($pdo, 'STOCK_AJUSTE_KARDEX', $_SESSION['user_id'] ?? 'Admin', ['sku'=>$sku, 'qty'=>$qty, 'note'=>$note]);
            echo json_encode(['status'=>'success']);
        } catch (Exception $e) { 
            if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); 
        }
        exit;
    }

    // SUBIDA DE IMAGEN — guarda en .jpg, .webp y .avif
    if (isset($_FILES['new_photo'])) {
        try {
            $code = $_POST['prod_code'] ?? '';
            if (!$code) throw new Exception("Código ausente.");
            $file = $_FILES['new_photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error subida.");
            $imgData = file_get_contents($file['tmp_name']);
            $src = @imagecreatefromstring($imgData);
            if (!$src) throw new Exception("Imagen inválida o formato no soportado.");

            // Recorte cuadrado centrado → 800×800 (master) y 200×200 (thumb)
            $width  = imagesx($src);
            $height = imagesy($src);
            $size   = min($width, $height);
            $x      = (int)(($width  - $size) / 2);
            $y      = (int)(($height - $size) / 2);

            // ── Master 800 px ─────────────────────────────────────────────────
            $master = imagecreatetruecolor(800, 800);
            imagecopyresampled($master, $src, 0, 0, $x, $y, 800, 800, $size, $size);

            // ── Thumb 200 px ──────────────────────────────────────────────────
            $thumb = imagecreatetruecolor(200, 200);
            imagecopyresampled($thumb, $src, 0, 0, $x, $y, 200, 200, $size, $size);
            imagedestroy($src);

            if (!is_dir($localPath)) @mkdir($localPath, 0777, true);

            $base = $localPath . $code;

            // Eliminar archivos anteriores para evitar que formatos de mayor
            // prioridad (avif > webp > jpg) en image.php sirvan versiones viejas.
            foreach (['.avif', '.webp', '.jpg', '.jpeg', '_thumb.avif', '_thumb.webp', '_thumb.jpg'] as $ext) {
                if (file_exists($base . $ext)) @unlink($base . $ext);
            }

            // JPEG — compatibilidad universal (baseline)
            if (!imagejpeg($master, $base . '.jpg', 85))
                throw new Exception("No se pudo guardar el .jpg.");

            // WebP — soporte amplio, ~30 % más ligero que JPEG
            if (function_exists('imagewebp'))
                imagewebp($master, $base . '.webp', 82);

            // AVIF — soporte moderno, ~50 % más ligero que JPEG
            if (function_exists('imageavif'))
                imageavif($master, $base . '.avif', 60, 6);

            // Thumbs (para cards pequeñas si se necesitan en el futuro)
            imagejpeg($thumb,  $base . '_thumb.jpg',  80);
            if (function_exists('imagewebp')) imagewebp($thumb, $base . '_thumb.webp', 78);

            imagedestroy($master);
            imagedestroy($thumb);

            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
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

    // TOGGLE RESERVABLE
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_reservable') {
        try {
            $sku = $_POST['sku'];
            $val = intval($_POST['val']);
            $stmt = $pdo->prepare("UPDATE productos SET es_reservable = ? WHERE codigo = ? AND id_empresa = ?");
            $stmt->execute([$val, $sku, $EMP_ID]);
            echo json_encode(['status'=>'success']);
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }

    // ELIMINAR IMAGEN EXTRA
    if (isset($_POST['action']) && $_POST['action'] === 'delete_extra_img') {
        try {
            $sku  = $_POST['sku']  ?? '';
            $slot = $_POST['slot'] ?? '';
            if (!$sku) throw new Exception("SKU ausente.");
            if (!in_array($slot, ['extra1', 'extra2'])) throw new Exception("Slot inválido.");
            $base = $localPath . $sku . '_' . $slot;
            foreach (['.avif', '.webp', '.jpg', '.jpeg', '_thumb.avif', '_thumb.webp', '_thumb.jpg'] as $ext) {
                if (file_exists($base . $ext)) @unlink($base . $ext);
            }
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
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
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_history') {
        $sku = $_GET['sku'];
        try {
            $stmt = $pdo->prepare("SELECT fecha, usuario, detalles FROM pos_audit WHERE tipo = 'PRECIO_UPDATE' AND detalles LIKE ? ORDER BY fecha DESC LIMIT 10");
            $stmt->execute(['%"sku":"'.$sku.' "%']);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($logs);
        } catch(Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($action === 'get_full_active_list') {
        try {
            $sql = "SELECT p.codigo, p.nombre, p.categoria, 
                    (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = :alm) as stock_total
                    FROM productos p 
                    WHERE p.id_empresa = :emp AND p.activo = 1 
                    ORDER BY p.categoria ASC, p.nombre ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':emp' => $EMP_ID, ':alm' => $ALM_ID]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e) { echo json_encode([]); }
        exit;
    }
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

// Paginación y Ordenamiento
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Validación de ordenamiento para evitar SQL Injection
$allowedSorts = ['codigo', 'nombre', 'categoria', 'stock_total', 'precio', 'ganancia_neta'];
$sort = $_GET['sort'] ?? 'nombre';
if (!in_array($sort, $allowedSorts)) $sort = 'nombre';

$dir = strtoupper($_GET['dir'] ?? 'ASC');
if ($dir !== 'ASC' && $dir !== 'DESC') $dir = 'ASC';

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
            (p.precio - p.costo) as ganancia_neta, p.precio_mayorista
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
                <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-1" onclick="openCategoriesModal()"><i class="fas fa-tags"></i> Categorías</button>
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
            <input type="text" class="form-control form-control-sm d-none" id="bulkCatInput" list="bulk_cat_list" placeholder="Nueva Categoría" style="max-width: 150px;">
            <datalist id="bulk_cat_list"></datalist>
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
                        
                        <th onclick="sortBy('codigo')" style="cursor:pointer">SKU <i id="icon_codigo" class="fas fa-sort text-muted small"></i></th>
                        <th onclick="sortBy('nombre')" style="cursor:pointer">Producto <i id="icon_nombre" class="fas fa-sort-up text-primary small"></i></th>
                        
                        <th class="text-center" style="width: 80px;" title="Web visible / 📅 Reservable sin stock">Web / 📅</th>
                        
                        <th onclick="sortBy('categoria')" style="cursor:pointer">Categoría <i id="icon_categoria" class="fas fa-sort text-muted small"></i></th>
                        <th onclick="sortBy('stock_total')" style="cursor:pointer" class="text-center">Stock <i id="icon_stock_total" class="fas fa-sort text-muted small"></i></th>
                        <th onclick="sortBy('precio')" style="cursor:pointer" class="text-end">Venta <i id="icon_precio" class="fas fa-sort text-muted small"></i></th>
                        <th class="text-end">Mayorista</th>
                        <th onclick="sortBy('ganancia_neta')" style="cursor:pointer" class="text-end bg-light">Utilidad <i id="icon_ganancia_neta" class="fas fa-sort text-muted small"></i></th>
                        
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

<input type="file" id="fileInput"       accept="image/jpeg, image/webp" style="display:none" onchange="uploadPhoto()">
<input type="file" id="editorFileInput" accept="image/jpeg,image/webp,image/png" style="display:none" onchange="handleEditorUpload()">

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

<!-- MODAL AJUSTE KARDEX -->
<div class="modal fade" id="kardexAdjModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i> AJUSTE DE KARDEX</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning border-warning">
                    <i class="fas fa-info-circle me-2"></i> <strong>ADVERTENCIA:</strong> Esta acción forzará el stock del producto. Use esto solo para correcciones excepcionales de inventario.
                </div>
                
                <h6 class="fw-bold mb-3" id="adjProdName">---</h6>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Tipo de Movimiento:</label>
                    <select class="form-select" id="adjType">
                        <option value="IN">➕ Entrada (Suma al stock)</option>
                        <option value="OUT">➖ Salida (Resta del stock)</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Cantidad:</label>
                    <input type="number" class="form-control form-control-lg fw-bold" id="adjQty" placeholder="0.00" step="0.01" min="0.01">
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Motivo / Observación:</label>
                    <textarea class="form-control" id="adjNote" rows="2" placeholder="Ej: Ajuste de inventario físico, error de ingreso..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger px-4 fw-bold" onclick="processKardexAdj()">EJECUTAR AJUSTE</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// --- AJUSTE DE KARDEX ---
let adjSku = '';
function openKardexAdj(sku, nombre) {
    if(!confirm("¡PELIGRO! Ajustar el kardex manualmente puede causar discrepancias contables. ¿Está seguro de que desea continuar?")) return;
    adjSku = sku;
    document.getElementById('adjProdName').innerText = nombre;
    document.getElementById('adjQty').value = '';
    document.getElementById('adjNote').value = '';
    new bootstrap.Modal(document.getElementById('kardexAdjModal')).show();
}

async function processKardexAdj() {
    const qty = parseFloat(document.getElementById('adjQty').value);
    const type = document.getElementById('adjType').value;
    const note = document.getElementById('adjNote').value;

    if(!qty || qty <= 0) return alert("Ingrese una cantidad válida");
    if(!note) return alert("Ingrese el motivo del ajuste");

    try {
        const formData = new FormData();
        formData.append('action', 'kardex_adj');
        formData.append('sku', adjSku);
        formData.append('qty', qty);
        formData.append('type', type);
        formData.append('note', note);

        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();

        if(data.status === 'success') {
            bootstrap.Modal.getInstance(document.getElementById('kardexAdjModal')).hide();
            showToast('Ajuste realizado correctamente');
            loadData(currentPage);
        } else {
            alert("Error: " + data.msg);
        }
    } catch(e) { alert("Error de conexión"); }
}

function showToast(msg) {
    // Implementación básica si no existe un toast global
    alert(msg);
}

// VARIABLES GLOBALES
let currentPage = 1;
let currentCode = '';
let currentSort = 'nombre';
let currentDir = 'ASC';

// --- 1. CARGA AJAX CON ORDENAMIENTO ---
async function loadData(page) {
    currentPage = page;
    const limit = document.getElementById('f_limit').value;
    const code = document.getElementById('f_code').value;
    const name = document.getElementById('f_name').value;
    const status = document.getElementById('f_status').value;
    const stockRange = document.getElementById('f_stock').value;

    const url = `products_table.php?ajax_load=1&page=${page}&limit=${limit}&code=${encodeURIComponent(code)}&name=${encodeURIComponent(name)}&status=${status}&stock_range=${encodeURIComponent(stockRange)}&sort=${currentSort}&dir=${currentDir}`;
    
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
        updateSortIcons();
        
    } catch(e) { console.error(e); alert('Error cargando datos'); }
}

function sortBy(field) {
    if (currentSort === field) {
        currentDir = (currentDir === 'ASC') ? 'DESC' : 'ASC';
    } else {
        currentSort = field;
        currentDir = 'ASC';
    }
    loadData(1);
}

function updateSortIcons() {
    // Resetear todos
    document.querySelectorAll('.fa-sort, .fa-sort-up, .fa-sort-down').forEach(i => {
        if (i.id.startsWith('icon_')) {
            i.className = 'fas fa-sort text-muted small';
        }
    });
    // Activar el actual
    const activeIcon = document.getElementById('icon_' + currentSort);
    if (activeIcon) {
        activeIcon.className = `fas fa-sort-${currentDir === 'ASC' ? 'up' : 'down'} text-primary small`;
    }
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

// --- IMÁGENES DEL EDITOR ---
let _editorSlot = '', _editorSku = '';

function triggerEditorImg(sku, slot) {
    _editorSku  = sku;
    _editorSlot = slot;
    document.getElementById('editorFileInput').click();
}

async function handleEditorUpload() {
    const input = document.getElementById('editorFileInput');
    const file  = input.files[0];
    if (!file) return;
    input.value = '';

    const prodCode = (_editorSlot === 'main') ? _editorSku : _editorSku + '_' + _editorSlot;
    const formData = new FormData();
    formData.append('new_photo', file);
    formData.append('prod_code', prodCode);

    const imgEl = document.getElementById('img_' + _editorSlot);
    if (imgEl) imgEl.style.opacity = '0.4';

    try {
        const res  = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            if (imgEl) {
                imgEl.src = `image.php?code=${encodeURIComponent(prodCode)}&t=${Date.now()}`;
                imgEl.style.opacity = '1';
            }
            const btnWrap = document.getElementById('btnWrap_' + _editorSlot);
            if (btnWrap) {
                const firstBtn = btnWrap.querySelector('button');
                if (firstBtn) firstBtn.innerHTML = '<i class="fas fa-camera me-1"></i> Cambiar';
                if (_editorSlot !== 'main' && !btnWrap.querySelector('.btn-outline-danger')) {
                    const delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-sm btn-outline-danger';
                    delBtn.title = 'Eliminar imagen';
                    delBtn.innerHTML = '<i class="fas fa-trash"></i>';
                    const slot = _editorSlot, sku = _editorSku;
                    delBtn.onclick = () => deleteEditorImg(sku, slot);
                    btnWrap.appendChild(delBtn);
                }
            }
            // Refrescar miniatura de la tabla si es imagen principal
            if (_editorSlot === 'main') {
                const tableImg = document.querySelector(`.prod-img-table[data-code="${_editorSku}"]`);
                if (tableImg) tableImg.src = `image.php?code=${encodeURIComponent(_editorSku)}&t=${Date.now()}`;
            }
        } else {
            if (imgEl) imgEl.style.opacity = '1';
            alert('Error al guardar imagen: ' + (data.msg || 'desconocido'));
        }
    } catch (e) {
        if (imgEl) imgEl.style.opacity = '1';
        alert('Error de conexión al subir imagen.');
    }
}

async function deleteEditorImg(sku, slot) {
    const label = slot === 'extra1' ? 'Extra 1' : 'Extra 2';
    if (!confirm(`¿Eliminar imagen ${label} del producto?`)) return;

    const formData = new FormData();
    formData.append('action', 'delete_extra_img');
    formData.append('sku',    sku);
    formData.append('slot',   slot);

    try {
        const res  = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            const imgEl = document.getElementById('img_' + slot);
            if (imgEl) imgEl.src = `image.php?code=${encodeURIComponent(sku + '_' + slot)}&t=${Date.now()}`;
            const btnWrap = document.getElementById('btnWrap_' + slot);
            if (btnWrap) btnWrap.innerHTML =
                `<button type="button" class="btn btn-sm btn-outline-primary" onclick="triggerEditorImg('${sku}','${slot}')"><i class="fas fa-upload me-1"></i> Subir</button>`;
        } else {
            alert('Error al eliminar imagen: ' + (data.msg || 'desconocido'));
        }
    } catch (e) {
        alert('Error de conexión al eliminar imagen.');
    }
}

// --- VARIOS ---
function triggerUpload(code) { currentCode = code; document.getElementById('fileInput').click(); }
function uploadPhoto() {
    const file = document.getElementById('fileInput').files[0];
    if(!file) return;
    const formData = new FormData();
    formData.append('new_photo', file);
    formData.append('prod_code', currentCode);
    // Resetear el input para que el mismo archivo se pueda subir de nuevo
    document.getElementById('fileInput').value = '';
    fetch('products_table.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                // Actualizar la imagen en la tabla con cache-buster (sin recargar página)
                const img = document.querySelector(`.prod-img-table[data-code="${currentCode}"]`);
                if(img) img.src = `image.php?code=${encodeURIComponent(currentCode)}&t=${Date.now()}`;
            } else {
                alert('Error al guardar imagen: ' + res.msg);
            }
        })
        .catch(e => alert('Error de conexión: ' + e.message));
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

async function toggleReservable(sku, checkbox) {
    const val = checkbox.checked ? 1 : 0;
    const formData = new FormData();
    formData.append('action', 'toggle_reservable');
    formData.append('sku', sku);
    formData.append('val', val);
    try {
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            checkbox.style.backgroundColor = val ? '#f59e0b' : '';
            checkbox.style.borderColor     = val ? '#d97706' : '';
        } else {
            checkbox.checked = !checkbox.checked;
            alert("Error: " + data.msg);
        }
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

// Imprimir conteo (Listado para conteo manual ordenado por categoría y nombre)
async function printInventoryCount() {
    const res = await fetch('products_table.php?action=get_full_active_list');
    const products = await res.json();
    
    let html = `
    <html>
    <head>
        <title>Listado de Conteo - ${new Date().toLocaleDateString()}</title>
        <link href="assets/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { padding: 20px; font-size: 12px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 6px; }
            th { background: #f0f0f0; }
            .cat-header { background: #e9ecef; font-weight: bold; font-size: 14px; }
            .real-col { width: 100px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>HOJA DE CONTEO FÍSICO - INVENTARIO</h4>
            <div class="text-end">Fecha: ${new Date().toLocaleString()}</div>
        </div>
        
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th class="text-center">Stock Sistema</th>
                    <th class="real-col text-center">EXISTENCIA REAL</th>
                </tr>
            </thead>
            <tbody>`;
            
    let currentCat = '';
    products.forEach(p => {
        if (p.categoria !== currentCat) {
            currentCat = p.categoria;
            html += `<tr class="cat-header"><td colspan="4"><i class="fas fa-folder me-2"></i> CATEGORÍA: ${currentCat}</td></tr>`;
        }
        html += `
            <tr>
                <td class="font-monospace">${p.codigo}</td>
                <td>${p.nombre}</td>
                <td class="text-center fw-bold">${parseFloat(p.stock_total).toFixed(1)}</td>
                <td class="real-col">_________________</td>
            </tr>`;
    });
    
    html += `
            </tbody>
        </table>
        <div class="mt-4 no-print text-center">
            <button onclick="window.print()" class="btn btn-primary btn-lg px-5">IMPRIMIR AHORA</button>
        </div>

</body>
    </html>`;
    
    const win = window.open('', '_blank');
    win.document.write(html);
    win.document.close();
}
function printTable() {
    window.print();
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    initInlineEdit();
    // Marcar el ícono por defecto (Nombre ASC)
    updateSortIcons();
    // Cargar categorías
    reloadCategorySelects();
});

async function reloadCategorySelects() {
    try {
        const res = await fetch('categories_api.php');
        const cats = await res.json();
        const datalist = document.getElementById('bulk_cat_list');
        if (datalist) {
            datalist.innerHTML = '';
            cats.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.nombre;
                datalist.appendChild(opt);
            });
        }
        if (typeof loadCategoriesQP === 'function') {
            loadCategoriesQP();
        }
    } catch(e) { console.error("Error recargando categorías", e); }
}
</script>


<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'modal_categories.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

