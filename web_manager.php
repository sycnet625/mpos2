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
$localPath = __DIR__ . '/assets/product_images/';

function webmgr_has_image(string $code): bool {
    $safe = trim($code);
    if ($safe === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $safe)) return false;
    $bases = [
        __DIR__ . '/assets/product_images/' . $safe,
        dirname(__DIR__) . '/assets/product_images/' . $safe,
    ];
    foreach ($bases as $base) {
        foreach (['.avif', '.webp', '.jpg', '.jpeg'] as $ext) {
            if (file_exists($base . $ext)) return true;
        }
    }
    return false;
}

// 2. PROCESAR PETICIONES AJAX (GUARDADO AUTOMÁTICO)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    // Acción masiva: desmarcar sucursal entera (todos los productos)
    if (isset($input['action']) && $input['action'] === 'bulk_unmark_sucursal_all') {
        try {
            $suc = intval($input['sucursal']);
            if ($suc < 1 || $suc > 6) throw new Exception("Sucursal inválida");
            $prods = $pdo->query("SELECT codigo, sucursales_web FROM productos WHERE activo=1 AND id_empresa=$EMP_ID")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("UPDATE productos SET sucursales_web = ? WHERE codigo = ? AND id_empresa = ?");
            $count = 0;
            foreach ($prods as $pr) {
                $parts = array_filter(array_map('trim', explode(',', $pr['sucursales_web'] ?? '')));
                if (in_array((string)$suc, $parts)) {
                    $parts = array_values(array_filter($parts, fn($v) => $v !== (string)$suc));
                    $stmt->execute([implode(',', $parts), $pr['codigo'], $EMP_ID]);
                    $count++;
                }
            }
            echo json_encode(['status' => 'success', 'affected' => $count]);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        exit;
    }

    // Acción masiva sobre productos seleccionados
    if (isset($input['action']) && $input['action'] === 'bulk_action') {
        try {
            $codes = $input['codes'] ?? [];
            if (empty($codes)) throw new Exception("Sin productos seleccionados");
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $params = array_merge($codes, [$EMP_ID]);

            switch ($input['bulk'] ?? '') {
                case 'web_on':
                    $pdo->prepare("UPDATE productos SET es_web=1 WHERE codigo IN ($placeholders) AND id_empresa=?")->execute($params);
                    break;
                case 'web_off':
                    $pdo->prepare("UPDATE productos SET es_web=0 WHERE codigo IN ($placeholders) AND id_empresa=?")->execute($params);
                    break;
                case 'suc_add':
                    $suc = intval($input['sucursal'] ?? 0);
                    if ($suc < 1 || $suc > 6) throw new Exception("Sucursal inválida");
                    $rows = $pdo->prepare("SELECT codigo, sucursales_web FROM productos WHERE codigo IN ($placeholders) AND id_empresa=?");
                    $rows->execute($params);
                    $stmt = $pdo->prepare("UPDATE productos SET sucursales_web=? WHERE codigo=? AND id_empresa=?");
                    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                        $parts = array_filter(array_map('trim', explode(',', $pr['sucursales_web'] ?? '')));
                        if (!in_array((string)$suc, $parts)) $parts[] = (string)$suc;
                        $stmt->execute([implode(',', $parts), $pr['codigo'], $EMP_ID]);
                    }
                    break;
                case 'suc_remove':
                    $suc = intval($input['sucursal'] ?? 0);
                    if ($suc < 1 || $suc > 6) throw new Exception("Sucursal inválida");
                    $rows = $pdo->prepare("SELECT codigo, sucursales_web FROM productos WHERE codigo IN ($placeholders) AND id_empresa=?");
                    $rows->execute($params);
                    $stmt = $pdo->prepare("UPDATE productos SET sucursales_web=? WHERE codigo=? AND id_empresa=?");
                    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                        $parts = array_values(array_filter(array_map('trim', explode(',', $pr['sucursales_web'] ?? '')), fn($v) => $v !== (string)$suc));
                        $stmt->execute([implode(',', $parts), $pr['codigo'], $EMP_ID]);
                    }
                    break;
                default:
                    throw new Exception("Acción desconocida");
            }
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) { echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); }
        exit;
    }

    // Si es subida de imagen (Form Data)
    if (isset($_FILES['new_photo'])) {
        try {
            $code = trim((string)($_POST['prod_code'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $code)) throw new Exception("Código inválido");
            $ext = strtolower(pathinfo($_FILES['new_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) throw new Exception("Formato inválido");
            if (!is_dir($localPath) && !@mkdir($localPath, 0775, true)) throw new Exception("No se pudo crear carpeta de imágenes");
            if (!is_writable($localPath)) throw new Exception("Carpeta de imágenes sin permisos de escritura");
            
            $src = ($ext=='png') ? imagecreatefrompng($_FILES['new_photo']['tmp_name']) : imagecreatefromjpeg($_FILES['new_photo']['tmp_name']);
            // Redimensionar a 800x800 max
            $w = imagesx($src); $h = imagesy($src); $max=800;
            if ($w > $max || $h > $max) {
                $ratio = $w/$h; $newW = ($w>$h)?$max:$max*$ratio; $newH = ($w>$h)?$max/$ratio:$max;
                $dst = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src); $src = $dst;
            }
            // Limpiar variantes anteriores para evitar desincronía de formatos
            $base = $localPath . $code;
            foreach (['.avif', '.webp', '.jpg', '.jpeg'] as $oldExt) {
                if (file_exists($base . $oldExt)) @unlink($base . $oldExt);
            }

            imagejpeg($src, $base . '.jpg', 85);
            if (function_exists('imagewebp')) imagewebp($src, $base . '.webp', 82);
            if (function_exists('imageavif')) imageavif($src, $base . '.avif', 60, 6);
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
            elseif ($input['action'] === 'update_reservable') {
                $val = intval($input['value']);
                $pdo->prepare("UPDATE productos SET es_reservable = ? WHERE codigo = ? AND id_empresa = ?")->execute([$val, $code, $EMP_ID]);
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
$filterSucursal = intval($_GET['suc'] ?? 0);

$allowedPerPage = [25, 50, 100, 200];
$perPage = in_array(intval($_GET['pp'] ?? 0), $allowedPerPage) ? intval($_GET['pp']) : 50;

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

if ($cat) $where .= " AND p.categoria = " . $pdo->quote($cat);

if ($filterType === 'service')     $where .= " AND p.es_servicio = 1";
if ($filterType === 'raw')         $where .= " AND p.es_materia_prima = 1";
if ($filterType === 'elaborated')  $where .= " AND p.es_elaborado = 1";
if ($filterType === 'merchandise') $where .= " AND p.es_servicio = 0 AND p.es_materia_prima = 0 AND p.es_elaborado = 0";

if ($filterStatus === 'web_only')  $where .= " AND p.es_web = 1";
if ($filterStatus === 'no_web')    $where .= " AND p.es_web = 0";
if ($filterStatus === 'no_desc')   $where .= " AND (p.descripcion IS NULL OR p.descripcion = '')";
if ($filterStatus === 'no_img')    $where .= " AND p.es_web = 1";

if ($filterSucursal >= 1 && $filterSucursal <= 6)
    $where .= " AND FIND_IN_SET($filterSucursal, p.sucursales_web) > 0";

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$total = $pdo->query("SELECT COUNT(*) FROM productos p $where")->fetchColumn();
$totalPages = ceil($total / $perPage);

// SELECCIONAMOS LAS NUEVAS COLUMNAS (color, unidad_medida, peso, es_reservable)
$sql = "SELECT p.codigo, p.nombre, p.precio, p.categoria, p.es_web, p.sucursales_web, p.descripcion,
               p.es_servicio, p.es_materia_prima, p.es_elaborado,
               p.color, p.unidad_medida, p.peso, p.es_reservable
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor E-commerce</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <?php require_once __DIR__ . '/theme.php'; ?>
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .table thead th { white-space: nowrap; }
        .img-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid var(--pw-line); transition: transform 0.2s; }
        .img-thumb:hover { transform: scale(1.5); z-index: 10; position: relative; }
        .suc-btn { width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; border-radius: 50%; border: 1px solid var(--pw-line); background: var(--pw-card); color: #ccc; cursor: pointer; transition: all 0.2s; font-weight: bold; }
        .suc-btn.active { background: #0d6efd; color: white; border-color: #0d6efd; box-shadow: 0 2px 5px rgba(13,110,253,0.4); }
        .suc-btn:hover { border-color: #0d6efd; color: #0d6efd; }
        .suc-btn.active:hover { color: white; }
        .table-align-middle td { vertical-align: middle; }
        .desc-truncate { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; color: var(--pw-muted); font-size: 0.9em; }
        .badge-type { font-size: 0.7rem; padding: 2px 5px; margin-right: 3px; }
        .bg-orange { background-color: #fd7e14 !important; color: white !important; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">E-commerce / Catálogo</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-globe me-2"></i>Gestor E-commerce</h1>
                <p class="mb-3 text-white-50">Administra la visibilidad, contenido y propiedades de productos en la tienda web.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-box me-1"></i><?= $total ?> productos</span>
                    <span class="kpi-chip"><i class="fas fa-layer-group me-1"></i>Pág <?= $page ?>/<?= $totalPages ?></span>
                    <span class="kpi-chip"><i class="fas fa-tags me-1"></i><?= count($cats) ?> categorías</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <!-- ── Filtros ─────────────────────────────────────────────────────────── -->
    <div class="glass-card p-3 mb-3 inventory-fade-in no-print">
        <form class="row g-2 align-items-center" method="get" id="filterForm">
            <div class="col-auto">
                <select class="form-select form-select-sm" name="prod_type" onchange="this.form.submit()">
                    <option value="">Todos los Tipos</option>
                    <option value="merchandise" <?php echo $filterType=='merchandise'?'selected':''; ?>>📦 Mercancía</option>
                    <option value="elaborated" <?php echo $filterType=='elaborated'?'selected':''; ?>>🍳 Elaborados</option>
                    <option value="service" <?php echo $filterType=='service'?'selected':''; ?>>🛠️ Servicios</option>
                    <option value="raw" <?php echo $filterType=='raw'?'selected':''; ?>>🥩 Materias Primas</option>
                </select>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                    <option value="all">Estado: Todos</option>
                    <option value="web_only" <?php echo $filterStatus=='web_only'?'selected':''; ?>>🟢 Web Activos</option>
                    <option value="no_web" <?php echo $filterStatus=='no_web'?'selected':''; ?>>🔴 Sin Web</option>
                    <option value="no_desc" <?php echo $filterStatus=='no_desc'?'selected':''; ?>>⚠️ Sin Descripción</option>
                </select>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" name="suc" onchange="this.form.submit()" title="Filtrar por sucursal asignada">
                    <option value="0">Todas las Sucursales</option>
                    <?php for($i=1; $i<=6; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $filterSucursal==$i?'selected':''; ?>>Sucursal <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" name="cat" onchange="this.form.submit()">
                    <option value="">Todas las Categorías</option>
                    <?php foreach($cats as $c): ?><option value="<?php echo htmlspecialchars($c); ?>" <?php echo $cat==$c?'selected':''; ?>><?php echo htmlspecialchars($c); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control" name="q" placeholder="Nombre o SKUs (sep. comas)" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" name="pp" onchange="this.form.submit()" title="Productos por página">
                    <?php foreach([25,50,100,200] as $pp): ?>
                    <option value="<?php echo $pp; ?>" <?php echo $perPage==$pp?'selected':''; ?>><?php echo $pp; ?>/pág</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($search || $cat || $filterStatus !== 'all' || $filterType || $filterSucursal): ?>
            <div class="col-auto">
                <a class="btn btn-outline-danger btn-sm" href="web_manager.php">✕ Limpiar</a>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Acciones Masivas ───────────────────────────────────────────────── -->
    <div class="glass-card p-3 mb-3 inventory-fade-in no-print" id="bulkBar">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-bold small text-muted me-1"><i class="fas fa-layer-group me-1"></i>Acciones masivas:</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="selectAll(true)"><i class="fas fa-check-square me-1"></i>Sel. todos</button>
            <button class="btn btn-sm btn-outline-secondary" onclick="selectAll(false)"><i class="fas fa-square me-1"></i>Desel. todos</button>
            <div class="vr mx-1"></div>
            <button class="btn btn-sm btn-success" onclick="bulkAction('web_on')"><i class="fas fa-globe me-1"></i>Activar web</button>
            <button class="btn btn-sm btn-danger" onclick="bulkAction('web_off')"><i class="fas fa-eye-slash me-1"></i>Desactivar web</button>
            <div class="vr mx-1"></div>
            <div class="d-flex align-items-center gap-1">
                <select id="bulkSuc" class="form-select form-select-sm" style="width:auto">
                    <?php for($i=1; $i<=6; $i++): ?>
                    <option value="<?php echo $i; ?>">Suc. <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn btn-sm btn-primary" onclick="bulkAction('suc_add')"><i class="fas fa-plus me-1"></i>Asignar suc.</button>
                <button class="btn btn-sm btn-warning text-dark" onclick="bulkAction('suc_remove')"><i class="fas fa-minus me-1"></i>Quitar suc.</button>
            </div>
            <div class="vr mx-1"></div>
            <div class="d-flex align-items-center gap-1">
                <select id="unmarkAllSuc" class="form-select form-select-sm" style="width:auto">
                    <?php for($i=1; $i<=6; $i++): ?>
                    <option value="<?php echo $i; ?>">Suc. <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn btn-sm btn-outline-danger" onclick="unmarkAllSucursal()" title="Quita esta sucursal de TODOS los productos">
                    <i class="fas fa-trash-alt me-1"></i>Desmarcar suc. entera
                </button>
            </div>
            <span id="bulkCount" class="badge bg-secondary ms-auto">0 seleccionados</span>
        </div>
    </div>

    <!-- ── Tabla ───────────────────────────────────────────────────────────── -->
    <div class="glass-card p-0 inventory-fade-in">
        <div class="table-responsive">
            <table class="table table-hover table-align-middle mb-0">
                <thead class="table-light small text-muted">
                    <tr>
                        <th class="text-center" width="36"><input type="checkbox" id="checkAll" onchange="selectAll(this.checked)" title="Seleccionar todos"></th>
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
                        $hasImg = webmgr_has_image((string)$p['codigo']);
                        $imgUrl = $hasImg ? "image.php?code=" . $p['codigo'] : "assets/img/no-image-50.png";
                        $sucs = explode(',', $p['sucursales_web'] ?? '');
                        $desc = $p['descripcion'] ?? '';
                        $color = $p['color'] ?? '';
                        $unit = $p['unidad_medida'] ?? '';
                        $peso = $p['peso'] ?? 0;
                    ?>
                    <tr data-code="<?php echo htmlspecialchars($p['codigo']); ?>">
                        <td class="text-center">
                            <input type="checkbox" class="row-check" value="<?php echo htmlspecialchars($p['codigo']); ?>" onchange="updateBulkCount()">
                        </td>
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
                            <div class="form-check form-switch d-inline-block" title="Visible en tienda web">
                                <input class="form-check-input" type="checkbox" style="cursor:pointer; transform: scale(1.4);"
                                       onchange="toggleWeb('<?php echo $p['codigo']; ?>', this)"
                                       <?php echo $p['es_web'] ? 'checked' : ''; ?>>
                            </div>
                            <div class="mt-2 d-flex align-items-center justify-content-center gap-1" title="Aceptar reservas sin stock">
                                <input class="form-check-input" type="checkbox" style="cursor:pointer; <?php echo ($p['es_reservable'] ?? 0) ? 'background-color:#f59e0b;border-color:#d97706;' : ''; ?>"
                                       onchange="toggleReservable('<?php echo $p['codigo']; ?>', this)"
                                       <?php echo ($p['es_reservable'] ?? 0) ? 'checked' : ''; ?>>
                                <span style="font-size:0.75rem;" title="Reservable">📅</span>
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
                    <?php if (empty($products)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-globe fa-3x mb-3 d-block opacity-25"></i>
                        No hay productos con estos filtros.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center py-3 px-3" style="border-top:1px solid var(--pw-line)">
            <small class="text-muted">Total: <?php echo $total; ?> productos · <?php echo $perPage; ?>/pág</small>
            <?php
                $pgBase = '?q='.urlencode($search).'&status='.$filterStatus.'&cat='.urlencode($cat).'&prod_type='.$filterType.'&suc='.$filterSucursal.'&pp='.$perPage;
            ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $pgBase; ?>&page=<?php echo $page-1; ?>">Anterior</a></li>
                    <?php endif; ?>
                    <li class="page-item disabled"><span class="page-link"><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                    <?php if($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $pgBase; ?>&page=<?php echo $page+1; ?>">Siguiente</a></li>
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
                <h6 class="modal-title fw-bold"><i class="fas fa-pen me-2"></i>Editar Detalles Web</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label small fw-bold text-muted">Color</label>
                        <input type="text" id="editColor" class="form-control form-control-sm" placeholder="Ej: Rojo">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold text-muted">Tamaño/Unidad</label>
                        <input type="text" id="editUnit" class="form-control form-control-sm" placeholder="Ej: XL / Litro">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold text-muted">Peso (kg)</label>
                        <input type="number" step="0.01" id="editWeight" class="form-control form-control-sm" placeholder="0.00">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small fw-bold text-muted">Cinta/Etiqueta (E-commerce)</label>
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
                        <label class="form-label small fw-bold text-muted">Color Cinta</label>
                        <select id="editEtiquetaColor" class="form-select form-select-sm">
                            <option value="bg-danger">Rojo</option>
                            <option value="bg-warning text-dark">Amarillo</option>
                            <option value="bg-orange">Naranja</option>
                        </select>
                    </div>
                </div>

                <label class="form-label small fw-bold text-muted">Descripción Detallada</label>
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

    async function toggleReservable(id, checkbox) {
        const val = checkbox.checked ? 1 : 0;
        try {
            const res = await fetch('web_manager.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_reservable', id: id, value: val })
            });
            const json = await res.json();
            if (json.status === 'success') {
                checkbox.style.backgroundColor = val ? '#f59e0b' : '';
                checkbox.style.borderColor     = val ? '#d97706' : '';
            } else {
                checkbox.checked = !checkbox.checked;
                alert('Error: ' + (json.msg || 'Desconocido'));
            }
        } catch(e) {
            checkbox.checked = !checkbox.checked;
            alert('Error conexión');
        }
    }

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

    function selectAll(checked) {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
        document.getElementById('checkAll').checked = checked;
        updateBulkCount();
    }

    function updateBulkCount() {
        const n = document.querySelectorAll('.row-check:checked').length;
        document.getElementById('bulkCount').textContent = n + ' seleccionados';
        const allChecked = n === document.querySelectorAll('.row-check').length;
        document.getElementById('checkAll').indeterminate = n > 0 && !allChecked;
        document.getElementById('checkAll').checked = allChecked && n > 0;
    }

    function getSelectedCodes() {
        return [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    }

    async function bulkAction(type) {
        const codes = getSelectedCodes();
        if (!codes.length) { alert('Selecciona al menos un producto.'); return; }
        const suc = document.getElementById('bulkSuc').value;
        const labels = { web_on: 'activar en web', web_off: 'desactivar de web', suc_add: `asignar sucursal ${suc}`, suc_remove: `quitar sucursal ${suc}` };
        if (!confirm(`¿${labels[type]} en ${codes.length} producto(s)?`)) return;
        try {
            const res = await fetch('web_manager.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'bulk_action', bulk: type, codes, sucursal: suc })
            });
            const json = await res.json();
            if (json.status === 'success') location.reload();
            else alert('Error: ' + (json.msg || 'Desconocido'));
        } catch(e) { alert('Error de conexión'); }
    }

    async function unmarkAllSucursal() {
        const suc = document.getElementById('unmarkAllSuc').value;
        if (!confirm(`¿Quitar la sucursal ${suc} de TODOS los productos? Esta acción afecta a toda la base de datos.`)) return;
        try {
            const res = await fetch('web_manager.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'bulk_unmark_sucursal_all', sucursal: suc })
            });
            const json = await res.json();
            if (json.status === 'success') {
                alert(`Sucursal ${suc} desmarcada en ${json.affected} producto(s).`);
                location.reload();
            } else alert('Error: ' + (json.msg || 'Desconocido'));
        } catch(e) { alert('Error de conexión'); }
    }

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
