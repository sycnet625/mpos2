<?php
// ARCHIVO: products_table.php v3.2 (MEJORAS DE AUDITORÍA, KPI, IMPORT/EXPORT, MOBILE)
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Si es AJAX, registrar un manejador de errores que devuelva JSON
if (isset($_GET['ajax_load'])) {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false;
        echo json_encode(['status' => 'error', 'msg' => "PHP Error [$errno]: $errstr in $errfile on line $errline"]);
        exit;
    });
}

require_once 'db.php';
require_once 'pos_audit.php'; 

// ---------------------------------------------------------
// 1. CARGAR CONFIGURACIÓN
// ---------------------------------------------------------
require_once 'config_loader.php';
require_once 'inventory_suite_layout.php';

$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);
$localPath = __DIR__ . '/assets/product_images/';

function ptable_scope_sql(string $alias, int $sucursalId, int $almacenId): string {
    $sucursalId = (int)$sucursalId;
    $almacenId = (int)$almacenId;
    return "(
        COALESCE({$alias}.id_sucursal_origen, 0) = {$sucursalId}
        OR EXISTS (
            SELECT 1
            FROM stock_almacen sa_scope
            WHERE sa_scope.id_producto = {$alias}.codigo
              AND sa_scope.id_almacen = {$almacenId}
        )
    )";
}

function ptable_dir_is_really_writable(string $path): bool {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            return false;
        }
    }
    $probe = rtrim($path, '/') . '/.writetest_' . uniqid('', true);
    $ok = @file_put_contents($probe, '1') !== false;
    if ($ok) {
        @unlink($probe);
    }
    return $ok;
}

function ptable_detect_upload_image_extension(string $tmpPath, string $originalName = ''): string {
    $mime = '';
    if (function_exists('getimagesize')) {
        $info = @getimagesize($tmpPath);
        if (is_array($info) && !empty($info['mime'])) {
            $mime = strtolower((string)$info['mime']);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = strtolower((string)@mime_content_type($tmpPath));
    }

    $map = [
        'image/jpeg' => '.jpg',
        'image/jpg'  => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
        'image/avif' => '.avif',
    ];
    if (isset($map[$mime])) {
        return $map[$mime];
    }

    $nameExt = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if (in_array($nameExt, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
        return $nameExt === 'jpeg' ? '.jpg' : '.' . $nameExt;
    }

    return '';
}

const PRODUCT_AUDIT_ACTIONS = [
    'PRODUCTO_WEB_CHANGED',
    'PRODUCTO_POS_CHANGED',
    'PRODUCTO_ACTIVO_CHANGED',
    'PRODUCTO_RESERVABLE_CHANGED',
    'PRODUCTO_CATEGORIA_CHANGED',
    'PRODUCTO_STOCK_MIN_CHANGED',
    'PRODUCTO_STOCK_INLINE_CHANGED',
    'PRODUCTO_PRECIO_CHANGED',
    'PRODUCTO_CLONADO',
    'PRODUCTO_IMPORT_UPDATE',
    'PRODUCTO_IMPORT_CREATE',
    'PRODUCTO_BULK_CLONADO',
    'PRODUCTO_BULK_OPERATION'
];

function ptable_get_actor(): string {
    if (!empty($_SESSION['admin_user'])) return (string)$_SESSION['admin_user'];
    if (!empty($_SESSION['admin_name'])) return (string)$_SESSION['admin_name'];
    if (!empty($_SESSION['user_name'])) return (string)$_SESSION['user_name'];
    if (!empty($_SESSION['user'])) return (string)$_SESSION['user'];
    if (!empty($_SESSION['user_id'])) return (string)$_SESSION['user_id'];
    return 'admin';
}

function ptable_next_product_code(PDO $pdo, int $empId, int $sucId): string {
    $prefix = str_repeat((string)$sucId, 2);
    $lenPrefix = strlen($prefix);
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) AS max_seq
         FROM productos
         WHERE id_empresa = :emp AND codigo LIKE CONCAT(:prefix, '%')"
    );
    $stmt->execute([':len' => $lenPrefix, ':emp' => $empId, ':prefix' => $prefix]);
    $next = (int)$stmt->fetchColumn();
    $seq = $next > 0 ? $next + 1 : 1;
    $attempt = 0;
    while (true) {
        $candidate = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT 1 FROM productos WHERE codigo = ? AND id_empresa = ?");
        $check->execute([$candidate, $empId]);
        if (!$check->fetchColumn()) return $candidate;
        $seq++;
        $attempt++;
        if ($attempt > 200) return $prefix . '_' . time() . '_' . mt_rand(100, 999);
    }
}

function ptable_image_meta(string $code): array {
    $safe = trim($code);
    if ($safe === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $safe)) return [false, 0];
    $bases = [
        __DIR__ . '/assets/product_images/' . $safe,
        dirname(__DIR__) . '/assets/product_images/' . $safe,
        '/tmp/palweb_product_images/' . $safe,
    ];
    $exts = ['.avif', '.webp', '.jpg', '.jpeg', '.png'];
    foreach ($bases as $base) {
        foreach ($exts as $ext) {
            foreach ([$ext, strtoupper($ext)] as $candidateExt) {
                $f = $base . $candidateExt;
                if (file_exists($f)) return [true, (int)filemtime($f)];
            }
        }
    }
    return [false, 0];
}

function ptable_fetch_categories(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT nombre FROM categorias WHERE nombre IS NOT NULL AND TRIM(nombre) <> '' ORDER BY nombre")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string)$r);
            if ($name !== '') $out[] = $name;
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

function ptable_ensure_barcode_columns(PDO $pdo): void {
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

function ptable_is_allowed_category(array $allowed, string $category): bool {
    if ($category === '' || in_array($category, $allowed, true)) return true;
    $lower = mb_strtolower(trim($category), 'UTF-8');
    foreach ($allowed as $item) {
        if (mb_strtolower($item, 'UTF-8') === $lower) return true;
    }
    return false;
}

function ptable_get_product_row(PDO $pdo, int $empId, string $sku): array {
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE codigo = ? AND id_empresa = ?");
    $stmt->execute([$sku, $empId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function ptable_log_change(PDO $pdo, string $action, string $actor, array $payload): void {
    log_audit($pdo, $action, $actor, $payload);
}

function ptable_history_actions(): array {
    return array_values(array_unique(array_filter(array_merge(
        PRODUCT_AUDIT_ACTIONS,
        ['PRECIO_UPDATE', 'STOCK_AJUSTE_INLINE', 'STOCK_AJUSTE_KARDEX']
    ))));
}

function ptable_like_patterns(string $sku): array {
    $esc = addslashes($sku);
    return [
        "%\"sku\":\"$esc\"%",
        "%'sku':'$esc'%",
        "%\"sku_origen\":\"$esc\"%",
        "%\"sku_nuevo\":\"$esc\"%",
    ];
}

function ptable_json_exit(array $payload, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ptable_register_upload_shutdown(): void {
    register_shutdown_function(static function () {
        $error = error_get_last();
        if (!$error) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)$error['type'], $fatalTypes, true)) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status' => 'error',
            'msg' => 'Fallo procesando la imagen: ' . trim((string)($error['message'] ?? 'error interno')),
        ], JSON_UNESCAPED_UNICODE);
    });
}

$allowedCategories = ptable_fetch_categories($pdo);
ptable_ensure_barcode_columns($pdo);

// ---------------------------------------------------------
// 2. FUNCIÓN DE RENDERIZADO (CORE)
// ---------------------------------------------------------
function renderProductRows($rows, $localPath) {
    ob_start();
    if(empty($rows)): ?>
        <tr><td colspan="11" class="text-center py-5 text-muted">No se encontraron productos.</td></tr>
    <?php else: 
        foreach($rows as $p):
            [$hasImg, $mtime] = ptable_image_meta((string)$p['codigo']);
            $imgV = $mtime ? '&v=' . $mtime : '';
            $stock = floatval($p['stock_total']);
            $isActive = intval($p['activo'] ?? 1);
            $rowClass = $isActive ? '' : 'row-inactive';
    ?>
    <tr class="<?php echo $rowClass; ?>">
        <td class="no-print ps-3"><input type="checkbox" class="form-check-input bulk-check" value="<?php echo $p['codigo']; ?>"></td>
        <td class="text-center no-print"><a href="product_history.php?sku=<?php echo urlencode($p['codigo']); ?>" class="btn btn-outline-secondary btn-action border-0" title="Kardex"><i class="fas fa-history"></i></a></td>
        <td class="ps-2 no-print"><img src="<?php echo $hasImg ? 'image.php?code='.urlencode($p['codigo']).$imgV : 'assets/img/no-image-50.png'; ?>" class="prod-img-table" data-code="<?php echo $p['codigo']; ?>" onclick="openImageSourceModal('<?php echo $p['codigo']; ?>')"></td>
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
                <button class="btn btn-outline-secondary btn-action" onclick="cloneProduct('<?php echo $p['codigo']; ?>')" title="Clonar">
                    <i class="fas fa-clone"></i>
                </button>
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
            $sku = trim((string)($_POST['sku'] ?? ''));
            $field = $_POST['field'] ?? '';
            $rawValue = trim((string)($_POST['value'] ?? ''));

            if (!$sku) throw new Exception('SKU vacío.');
            if (!in_array($field, ['price', 'stock'], true)) throw new Exception('Campo inválido.');
            if ($rawValue === '' || !is_numeric($rawValue)) throw new Exception('Valor inválido.');

            $newValue = round((float)$rawValue, 2);
            if ($newValue < 0) throw new Exception('Valor no puede ser negativo.');

            $actor = ptable_get_actor();
            $oldProduct = ptable_get_product_row($pdo, $EMP_ID, $sku);
            if (!$oldProduct) throw new Exception('Producto no encontrado.');

            if ($field === 'price') {
                $oldValue = (float)$oldProduct['precio'];
                if ($oldValue === $newValue) {
                    echo json_encode(['status' => 'success', 'msg' => 'Sin cambios']);
                    exit;
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE productos SET precio = ? WHERE codigo = ? AND id_empresa = ?");
                $stmt->execute([$newValue, $sku, $EMP_ID]);
                ptable_log_change($pdo, 'PRODUCTO_PRECIO_CHANGED', $actor, [
                    'sku' => $sku,
                    'precio_anterior' => $oldValue,
                    'precio_nuevo' => $newValue
                ]);
                if ($pdo->inTransaction()) $pdo->commit();
                echo json_encode(['status' => 'success', 'msg' => 'Precio actualizado']);
                exit;
            }

            $stmtCheck = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
            $stmtCheck->execute([$sku, $ALM_ID]);
            $hasStockRow = (bool)$stmtCheck->fetch();

            $oldStock = 0.0;
            if ($hasStockRow) {
                $stmtOldStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                $stmtOldStock->execute([$sku, $ALM_ID]);
                $oldStock = (float)$stmtOldStock->fetchColumn();
            }

            if ($hasStockRow) {
                $stmt = $pdo->prepare("UPDATE stock_almacen SET cantidad = ? WHERE id_producto = ? AND id_almacen = ?");
                $stmt->execute([$newValue, $sku, $ALM_ID]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO stock_almacen (id_producto, id_almacen, id_sucursal, cantidad) VALUES (?, ?, ?, ?)");
                $stmt->execute([$sku, $ALM_ID, $SUC_ID, $newValue]);
            }

            ptable_log_change($pdo, 'PRODUCTO_STOCK_INLINE_CHANGED', $actor, [
                'sku' => $sku,
                'almacen_id' => $ALM_ID,
                'stock_anterior' => $oldStock,
                'stock_nuevo' => $newValue
            ]);
            echo json_encode(['status' => 'success', 'msg' => 'Stock actualizado']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // CLONAR PRODUCTO DESDE LISTA
    if (isset($_POST['action']) && $_POST['action'] === 'clone_product') {
        try {
            $sku = trim((string)($_POST['sku'] ?? ''));
            if (!$sku) throw new Exception('SKU origen vacío.');

            $newSku = ptable_next_product_code($pdo, $EMP_ID, $SUC_ID);

            $stmt = $pdo->prepare("
                INSERT INTO productos (
                    codigo, id_empresa, nombre, precio, costo, precio_mayorista, activo, version_row,
                    categoria, stock_minimo, fecha_vencimiento, es_elaborado, es_materia_prima, es_servicio, es_cocina,
                    unidad_medida, descripcion, impuesto, peso, color, es_web, es_reservable, es_pos, tiene_variantes, variantes_json,
                    es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6, sucursales_web, id_sucursal_origen,
                    uuid, etiqueta_web, etiqueta_color, precio_oferta, favorito
                )
                SELECT
                    ?, id_empresa, CONCAT(nombre, ' (Copia)'), precio, costo, precio_mayorista, activo, 0,
                    categoria, stock_minimo, fecha_vencimiento, es_elaborado, es_materia_prima, es_servicio, es_cocina,
                    unidad_medida, descripcion, impuesto, peso, color, es_web, es_reservable, es_pos, tiene_variantes, variantes_json,
                    es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6, sucursales_web, id_sucursal_origen,
                    NULL, etiqueta_web, etiqueta_color, precio_oferta, favorito
                FROM productos
                WHERE codigo = ? AND id_empresa = ?
            ");
            $stmt->execute([$newSku, $sku, $EMP_ID]);

            if ($stmt->rowCount() < 1) throw new Exception('No se pudo clonar el producto.');

            log_audit($pdo, 'PRODUCTO_CLONADO', ptable_get_actor(), [
                'sku_origen' => $sku,
                'sku_nuevo' => $newSku
            ]);
            echo json_encode(['status' => 'success', 'new_sku' => $newSku, 'msg' => 'Producto clonado']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
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
            ptable_register_upload_shutdown();
            $code = trim((string)($_POST['prod_code'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
                throw new Exception("Código de producto inválido.");
            }
            if (!$code) throw new Exception("Código ausente.");
            $file = $_FILES['new_photo'];
            if (!is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new Exception("Archivo no recibido correctamente.");
            }
            if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error subida.");
            $imgData = file_get_contents($file['tmp_name']);
            if ($imgData === false || $imgData === '') {
                throw new Exception("No se pudo leer la imagen subida.");
            }

            if (!ptable_dir_is_really_writable($localPath)) {
                throw new Exception("La carpeta de imágenes no tiene permisos de escritura. Ruta: " . $localPath);
            }

            $base = $localPath . $code;

            // Eliminar archivos anteriores para evitar que formatos de mayor
            // prioridad (avif > webp > jpg) en image.php sirvan versiones viejas.
            foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png', '_thumb.avif', '_thumb.webp', '_thumb.jpg', '_thumb.png'] as $ext) {
                if (file_exists($base . $ext)) @unlink($base . $ext);
            }

            if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
                $ext = ptable_detect_upload_image_extension($file['tmp_name'], (string)($file['name'] ?? ''));
                if (!in_array($ext, ['.jpg', '.png', '.webp', '.avif'], true)) {
                    throw new Exception("Formato no soportado sin GD. Instale php-gd o suba JPG, PNG o WebP.");
                }
                if (@file_put_contents($base . $ext, $imgData) === false) {
                    throw new Exception("No se pudo guardar la imagen en disco.");
                }
                ptable_json_exit(['status' => 'success', 'mode' => 'original']);
            }

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
            imagefill($master, 0, 0, imagecolorallocate($master, 255, 255, 255));
            imagecopyresampled($master, $src, 0, 0, $x, $y, 800, 800, $size, $size);

            // ── Thumb 200 px ──────────────────────────────────────────────────
            $thumb = imagecreatetruecolor(200, 200);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $src, 0, 0, $x, $y, 200, 200, $size, $size);
            imagedestroy($src);

            if (!imagejpeg($master, $base . '.jpg', 85)) {
                throw new Exception("No se pudo guardar el .jpg.");
            }

            if (function_exists('imagewebp')) {
                if (!imagewebp($master, $base . '.webp', 82)) {
                    throw new Exception("No se pudo guardar el .webp.");
                }
            }

            if (function_exists('imageavif')) {
                if (!imageavif($master, $base . '.avif', 60, 6)) {
                    throw new Exception("No se pudo guardar el .avif.");
                }
            }

            if (!imagejpeg($thumb,  $base . '_thumb.jpg',  80)) {
                throw new Exception("No se pudo guardar el thumbnail .jpg.");
            }
            if (function_exists('imagewebp')) {
                if (!imagewebp($thumb, $base . '_thumb.webp', 78)) {
                    throw new Exception("No se pudo guardar el thumbnail .webp.");
                }
            }

            imagedestroy($master);
            imagedestroy($thumb);

            ptable_json_exit(['status' => 'success', 'mode' => 'processed']);
        } catch (Throwable $e) {
            ptable_json_exit(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }
        exit;
    }

    // TOGGLE WEB
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_web') {
        try {
            $sku = $_POST['sku'];
            $val = intval($_POST['val']);
            if ($sku === '' || !in_array($val, [0,1], true)) throw new Exception('Parámetros inválidos.');
            $old = ptable_get_product_row($pdo, $EMP_ID, $sku);
            if (!$old) throw new Exception('Producto no encontrado.');
            if ((int)($old['es_web'] ?? 0) === $val) {
                echo json_encode(['status'=>'success']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE productos SET es_web = ? WHERE codigo = ? AND id_empresa = ?");
            $stmt->execute([$val, $sku, $EMP_ID]);
            ptable_log_change($pdo, 'PRODUCTO_WEB_CHANGED', ptable_get_actor(), [
                'sku' => $sku,
                'es_web_anterior' => (int)($old['es_web'] ?? 0),
                'es_web_nuevo' => $val
            ]);
            echo json_encode(['status'=>'success']);
        } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
        exit;
    }

    // TOGGLE RESERVABLE
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_reservable') {
        try {
            $sku = $_POST['sku'];
            $val = intval($_POST['val']);
            if ($sku === '' || !in_array($val, [0,1], true)) throw new Exception('Parámetros inválidos.');
            $old = ptable_get_product_row($pdo, $EMP_ID, $sku);
            if (!$old) throw new Exception('Producto no encontrado.');
            if ((int)($old['es_reservable'] ?? 0) === $val) {
                echo json_encode(['status'=>'success']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE productos SET es_reservable = ? WHERE codigo = ? AND id_empresa = ?");
            $stmt->execute([$val, $sku, $EMP_ID]);
            ptable_log_change($pdo, 'PRODUCTO_RESERVABLE_CHANGED', ptable_get_actor(), [
                'sku' => $sku,
                'es_reservable_anterior' => (int)($old['es_reservable'] ?? 0),
                'es_reservable_nuevo' => $val
            ]);
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
            foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png', '_thumb.avif', '_thumb.webp', '_thumb.jpg', '_thumb.png'] as $ext) {
                if (file_exists($base . $ext)) @unlink($base . $ext);
            }
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_main_img') {
        try {
            $sku = trim((string)($_POST['sku'] ?? ''));
            if ($sku === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $sku)) {
                throw new Exception("SKU inválido.");
            }
            $base = $localPath . $sku;
            foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png', '_thumb.avif', '_thumb.webp', '_thumb.jpg', '_thumb.png'] as $ext) {
                if (file_exists($base . $ext)) {
                    @unlink($base . $ext);
                }
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

            $action = trim((string)$action);
            $actor = ptable_get_actor();

            $inQuery = implode(',', array_fill(0, count($skus), '?'));
            $params = $skus; 
            array_push($params, $EMP_ID);

            $stmtBefore = $pdo->prepare("SELECT codigo, nombre, es_web, es_pos, activo, es_reservable, categoria, stock_minimo FROM productos WHERE codigo IN ($inQuery) AND id_empresa = ?");
            $stmtBefore->execute(array_merge($skus, [$EMP_ID]));
            $beforeRows = $stmtBefore->fetchAll(PDO::FETCH_ASSOC);
            $beforeMap = [];
            foreach ($beforeRows as $row) {
                $beforeMap[$row['codigo']] = $row;
            }

            switch ($action) {
                case 'web_on':
                    $sql = "UPDATE productos SET es_web = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 1; $bulkActionLabel = 'PRODUCTO_WEB_CHANGED';
                    break;
                case 'web_off':
                    $sql = "UPDATE productos SET es_web = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 0; $bulkActionLabel = 'PRODUCTO_WEB_CHANGED';
                    break;
                case 'reservable_on':
                    $sql = "UPDATE productos SET es_reservable = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 1; $bulkActionLabel = 'PRODUCTO_RESERVABLE_CHANGED';
                    break;
                case 'reservable_off':
                    $sql = "UPDATE productos SET es_reservable = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 0; $bulkActionLabel = 'PRODUCTO_RESERVABLE_CHANGED';
                    break;
                case 'pos_on':
                    $sql = "UPDATE productos SET es_pos = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 1; $bulkActionLabel = 'PRODUCTO_POS_CHANGED';
                    break;
                case 'pos_off':
                    $sql = "UPDATE productos SET es_pos = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 0; $bulkActionLabel = 'PRODUCTO_POS_CHANGED';
                    break;
                case 'active_on':
                    $sql = "UPDATE productos SET activo = 1 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 1; $bulkActionLabel = 'PRODUCTO_ACTIVO_CHANGED';
                    break;
                case 'active_off':
                    $sql = "UPDATE productos SET activo = 0 WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    $bulkNewValue = 0; $bulkActionLabel = 'PRODUCTO_ACTIVO_CHANGED';
                    break;
                case 'change_cat':
                    $newCat = $_POST['new_cat_val'] ?? 'General';
                    $newCat = trim((string)$newCat);
                    if (!ptable_is_allowed_category($allowedCategories, $newCat)) {
                        throw new Exception('Categoría no válida. Debe existir en el gestor de categorías.');
                    }
                    $sql = "UPDATE productos SET categoria = ? WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    array_unshift($params, $newCat);
                    $bulkNewValue = $newCat;
                    $bulkActionLabel = 'PRODUCTO_CATEGORIA_CHANGED';
                    break;
                case 'set_stock_min':
                    if (!isset($_POST['new_stock_min_val']) || $_POST['new_stock_min_val'] === '' || !is_numeric($_POST['new_stock_min_val'])) {
                        throw new Exception("Valor de stock mínimo inválido.");
                    }
                    $newStockMin = round((float)$_POST['new_stock_min_val'], 2);
                    if ($newStockMin < 0) throw new Exception('Stock mínimo inválido.');
                    $sql = "UPDATE productos SET stock_minimo = ? WHERE codigo IN ($inQuery) AND id_empresa = ?";
                    array_unshift($params, $newStockMin);
                    $bulkNewValue = $newStockMin;
                    $bulkActionLabel = 'PRODUCTO_STOCK_MIN_CHANGED';
                    break;
                case 'clone_selected':
                    $created = [];
                    $pdo->beginTransaction();
                    $stmtClone = $pdo->prepare("
                        INSERT INTO productos (
                            codigo, id_empresa, nombre, precio, costo, precio_mayorista, activo, version_row,
                            categoria, stock_minimo, fecha_vencimiento, es_elaborado, es_materia_prima, es_servicio, es_cocina,
                            unidad_medida, descripcion, impuesto, peso, color, es_web, es_reservable, es_pos, tiene_variantes, variantes_json,
                            es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6, sucursales_web, id_sucursal_origen,
                            uuid, etiqueta_web, etiqueta_color, precio_oferta, favorito
                        )
                        SELECT
                            ?, id_empresa, CONCAT(nombre, ' (Copia)'), precio, costo, precio_mayorista, activo, 0,
                            categoria, stock_minimo, fecha_vencimiento, es_elaborado, es_materia_prima, es_servicio, es_cocina,
                            unidad_medida, descripcion, impuesto, peso, color, es_web, es_reservable, es_pos, tiene_variantes, variantes_json,
                            es_suc1, es_suc2, es_suc3, es_suc4, es_suc5, es_suc6, sucursales_web, id_sucursal_origen,
                            NULL, etiqueta_web, etiqueta_color, precio_oferta, favorito
                        FROM productos
                        WHERE codigo = ? AND id_empresa = ?
                    ");

                    foreach ($skus as $sku) {
                        $src = trim((string)$sku);
                        if ($src === '') continue;
                        $newSku = ptable_next_product_code($pdo, $EMP_ID, $SUC_ID);
                        $stmtClone->execute([$newSku, $src, $EMP_ID]);
                        if ($stmtClone->rowCount() < 1) throw new Exception("No se pudo clonar SKU $src.");
                        $created[] = $newSku;
                        ptable_log_change($pdo, 'PRODUCTO_CLONADO', ptable_get_actor(), [
                            'sku_origen' => $src,
                            'sku_nuevo' => $newSku,
                            'modo' => 'bulk'
                        ]);
                    }
                    $pdo->commit();
                    ptable_log_change($pdo, 'PRODUCTO_BULK_CLONADO', ptable_get_actor(), [
                        'accion_masiva' => 'clone_selected',
                        'cantidad' => count($created),
                        'creados' => $created,
                        'origenes' => $skus
                    ]);
                    echo json_encode(['status' => 'success', 'mode' => 'clone', 'count' => count($created), 'created' => $created]);
                    exit;
                default: throw new Exception("Acción inválida.");
            }
            if (!isset($bulkActionLabel) || !isset($bulkNewValue)) {
                throw new Exception("Acción inválida.");
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            ptable_log_change($pdo, 'PRODUCTO_BULK_OPERATION', ptable_get_actor(), [
                'accion_masiva' => $action,
                'cantidad' => count($skus),
                'skus' => $skus,
                'valor' => $bulkNewValue
            ]);
            foreach ($skus as $sku) {
                $before = $beforeMap[$sku] ?? [];
                if (!$before) continue;
                $payload = ['sku' => $sku, 'accion_masiva' => $action, 'valor' => $bulkNewValue];
                if (array_key_exists('es_web', $before)) {
                    $payload['es_web_anterior'] = (int)$before['es_web'];
                    $payload['es_web_nuevo'] = (int)$bulkNewValue;
                }
                if (array_key_exists('es_reservable', $before)) {
                    $payload['es_reservable_anterior'] = (int)$before['es_reservable'];
                    $payload['es_reservable_nuevo'] = (int)$bulkNewValue;
                }
                if (array_key_exists('es_pos', $before)) {
                    $payload['es_pos_anterior'] = (int)$before['es_pos'];
                    $payload['es_pos_nuevo'] = (int)$bulkNewValue;
                }
                if (array_key_exists('activo', $before)) {
                    $payload['activo_anterior'] = (int)$before['activo'];
                    $payload['activo_nuevo'] = (int)$bulkNewValue;
                }
                if (array_key_exists('categoria', $before)) {
                    $payload['categoria_anterior'] = $before['categoria'];
                    $payload['categoria_nueva'] = (string)$bulkNewValue;
                }
                if (array_key_exists('stock_minimo', $before)) {
                    $payload['stock_minimo_anterior'] = (float)$before['stock_minimo'];
                    $payload['stock_minimo_nuevo'] = (float)$bulkNewValue;
                }
                ptable_log_change($pdo, $bulkActionLabel, $actor, $payload);
            }
            echo json_encode(['status'=>'success', 'count'=>count($skus)]); 
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
        }
        exit;
    }

    // IMPORTAR PRODUCTOS POR CSV
    if (isset($_POST['action']) && $_POST['action'] === 'import_products') {
        try {
            if (!isset($_FILES['products_file']) || $_FILES['products_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Sube un archivo CSV válido.');
            }

            $mode = $_POST['import_mode'] ?? 'update_create';
            $fileName = $_FILES['products_file']['tmp_name'];
            if (!is_readable($fileName)) throw new Exception('No se pudo leer el archivo.');
            $handle = fopen($fileName, 'r');
            if (!$handle) throw new Exception('No se pudo abrir el archivo.');

            $actor = ptable_get_actor();
            $firstLine = fgetcsv($handle);
            if ($firstLine === false) throw new Exception('Archivo vacío.');

            $headers = array_map(fn($h) => mb_strtolower(trim((string)$h), 'UTF-8'), $firstLine);
            $map = [];
            foreach ($headers as $idx => $header) {
                if ($header !== '') $map[$header] = $idx;
            }

            $required = ['sku', 'nombre'];
            foreach ($required as $need) {
                if (!array_key_exists($need, $map)) {
                    throw new Exception("Falta la columna: $need");
                }
            }

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $line = 1;

            $stmtExists = $pdo->prepare("SELECT codigo FROM productos WHERE codigo = ? AND id_empresa = ? LIMIT 1");
            $stmtInsert = $pdo->prepare(
                "INSERT INTO productos (
                    codigo, id_empresa, nombre, categoria, precio, costo, precio_mayorista,
                    stock_minimo, unidad_medida, activo, es_web, es_pos, es_materia_prima,
                    es_servicio, es_cocina, es_reservable, descripcion, impuesto, peso, color
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ;

            $stmtUpdate = $pdo->prepare(
                "UPDATE productos SET
                    nombre = ?, categoria = ?, precio = ?, costo = ?, precio_mayorista = ?,
                    stock_minimo = ?, unidad_medida = ?, activo = ?, es_web = ?, es_pos = ?,
                    es_materia_prima = ?, es_servicio = ?, es_cocina = ?, es_reservable = ?,
                    descripcion = ?, impuesto = ?, peso = ?, color = ?, version_row = ?
                 WHERE codigo = ? AND id_empresa = ?"
            );

            $pdo->beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                $sku = trim((string)($row[$map['sku']] ?? ''));
                $name = trim((string)($row[$map['nombre']] ?? ''));
                if ($sku === '' || $name === '') {
                    $errors[] = "Línea $line: SKU o nombre vacío.";
                    $skipped++;
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9_.-]+$/', $sku)) {
                    $errors[] = "Línea $line: SKU inválido ($sku).";
                    $skipped++;
                    continue;
                }

                $precio = isset($map['precio']) ? (float)str_replace(',', '.', trim((string)$row[$map['precio']] ?? '0')) : 0;
                $costo = isset($map['costo']) ? (float)str_replace(',', '.', trim((string)$row[$map['costo']] ?? '0')) : 0;
                $precioMayorista = isset($map['precio_mayorista']) ? (float)str_replace(',', '.', trim((string)$row[$map['precio_mayorista']] ?? '0')) : 0;
                $stockMin = isset($map['stock_minimo']) ? (float)str_replace(',', '.', trim((string)$row[$map['stock_minimo']] ?? '0')) : 0;
                $categoria = trim((string)($row[$map['categoria']] ?? 'General'));

                if (!ptable_is_allowed_category($allowedCategories, $categoria)) {
                    $errors[] = "Línea $line: categoría inválida ($categoria).";
                    $skipped++;
                    continue;
                }

                $unidad = trim((string)($row[$map['unidad_medida']] ?? 'UNIDAD'));
                $activo = isset($map['activo']) ? (int)str_replace(',', '.', trim((string)$row[$map['activo']] ?? '1')) : 1;
                $es_web = isset($map['es_web']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_web']] ?? '0')) : 1;
                $es_pos = isset($map['es_pos']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_pos']] ?? '1')) : 1;
                $es_mat = isset($map['es_materia_prima']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_materia_prima']] ?? '0')) : 0;
                $es_serv = isset($map['es_servicio']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_servicio']] ?? '0')) : 0;
                $es_coc = isset($map['es_cocina']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_cocina']] ?? '0')) : 0;
                $es_res = isset($map['es_reservable']) ? (int)str_replace(',', '.', trim((string)$row[$map['es_reservable']] ?? '0')) : 0;
                $descripcion = trim((string)($row[$map['descripcion']] ?? ''));
                $impuesto = isset($map['impuesto']) ? (float)str_replace(',', '.', trim((string)$row[$map['impuesto']] ?? '0')) : 0;
                $peso = isset($map['peso']) ? (float)str_replace(',', '.', trim((string)$row[$map['peso']] ?? '0')) : 0;
                $color = trim((string)($row[$map['color']] ?? ''));

                if ($precio < 0 || $costo < 0 || $precioMayorista < 0 || $stockMin < 0 || $impuesto < 0 || $peso < 0) {
                    $errors[] = "Línea $line: valores numéricos inválidos para $sku.";
                    $skipped++;
                    continue;
                }
                if (!in_array($activo, [0,1], true) || !in_array($es_web, [0,1], true) || !in_array($es_pos, [0,1], true) || !in_array($es_mat, [0,1], true) || !in_array($es_serv, [0,1], true) || !in_array($es_coc, [0,1], true) || !in_array($es_res, [0,1], true)) {
                    $errors[] = "Línea $line: campos booleanos inválidos para $sku.";
                    $skipped++;
                    continue;
                }

                $stmtExists->execute([$sku, $EMP_ID]);
                $exists = (bool)$stmtExists->fetchColumn();

                if ($exists) {
                    $old = ptable_get_product_row($pdo, $EMP_ID, $sku);
                    $stmtUpdate->execute([
                        $name, $categoria, $precio, $costo, $precioMayorista, $stockMin, $unidad,
                        $activo, $es_web, $es_pos, $es_mat, $es_serv, $es_coc, $es_res,
                        $descripcion, $impuesto, $peso, $color, time(), $sku, $EMP_ID
                    ]);
                    ptable_log_change($pdo, 'PRODUCTO_IMPORT_UPDATE', $actor, [
                        'sku' => $sku,
                        'linea' => $line,
                        'anterior' => $old,
                        'nuevo' => [
                            'nombre' => $name,
                            'categoria' => $categoria,
                            'precio' => $precio,
                            'costo' => $costo,
                            'precio_mayorista' => $precioMayorista,
                            'stock_minimo' => $stockMin,
                            'unidad_medida' => $unidad
                        ]
                    ]);
                    $updated++;
                    continue;
                }

                if ($mode === 'update_only') {
                    $errors[] = "Línea $line: SKU no encontrado ($sku).";
                    $skipped++;
                    continue;
                }

                $stmtInsert->execute([
                    $sku, $EMP_ID, $name, $categoria, $precio, $costo, $precioMayorista,
                    $stockMin, $unidad, $activo, $es_web, $es_pos, $es_mat,
                    $es_serv, $es_coc, $es_res, $descripcion, $impuesto, $peso, $color
                ]);
                ptable_log_change($pdo, 'PRODUCTO_IMPORT_CREATE', $actor, [
                    'sku' => $sku,
                    'linea' => $line,
                    'nombre' => $name,
                    'categoria' => $categoria
                ]);
                $inserted++;
            }

            fclose($handle);

            if ($pdo->inTransaction()) $pdo->commit();

            ptable_log_change($pdo, 'PRODUCTO_BULK_OPERATION', $actor, [
                'accion_masiva' => 'import_products',
                'insertados' => $inserted,
                'actualizados' => $updated,
                'omitidos' => $skipped
            ]);

            echo json_encode([
                'status' => 'success',
                'imported' => ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped],
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}

// ---------------------------------------------------------
// 4. API: HISTORIAL (GET)
// ---------------------------------------------------------
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'get_history') {
        $sku = $_GET['sku'];
        try {
            header('Content-Type: application/json');
            $auditActions = ptable_history_actions();
            $placeholders = implode(',', array_fill(0, count($auditActions), '?'));
            $patterns = ptable_like_patterns((string)$sku);

            $stmt = $pdo->prepare("
                SELECT created_at AS fecha, usuario, accion, datos
                FROM auditoria_pos
                WHERE accion IN ($placeholders)
                  AND (JSON_UNQUOTE(JSON_EXTRACT(datos, '$.sku')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(datos, '$.sku_origen')) = ? OR JSON_UNQUOTE(JSON_EXTRACT(datos, '$.sku_nuevo')) = ? OR datos LIKE ? OR datos LIKE ?)
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute(array_merge(
                $auditActions,
                [(string)$sku, (string)$sku, (string)$sku, $patterns[0], $patterns[2]]
            ));
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($logs);
        } catch(Exception $e) { echo json_encode([]); }
        exit;
    }

    if ($action === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        try {
            $filterCode = trim((string)($_GET['code'] ?? ''));
            $filterName = trim((string)($_GET['name'] ?? ''));
            $filterStatus = $_GET['status'] ?? 'active';
            $filterStockRange = trim((string)($_GET['stock_range'] ?? ''));
            $onlyProd = isset($_GET['only_prod']);

            $whereClauses = ["p.id_empresa = $EMP_ID", ptable_scope_sql('p', $SUC_ID, $ALM_ID)];
            $params = [];

            if ($filterStatus === 'active') $whereClauses[] = "p.activo = 1";
            elseif ($filterStatus === 'inactive') $whereClauses[] = "p.activo = 0";

            if ($onlyProd) $whereClauses[] = "p.es_materia_prima = 0 AND p.es_servicio = 0";
            if ($filterCode !== '') { $whereClauses[] = "p.codigo LIKE :c"; $params[':c'] = "%$filterCode%"; }
            if ($filterName !== '') { $whereClauses[] = "p.nombre LIKE :n"; $params[':n'] = "%$filterName%"; }

            $whereSQL = implode(" AND ", $whereClauses);
            $havingClause = '';
            if ($filterStockRange !== '') {
                $val = $filterStockRange;
                if (strpos($val, '-') !== false) {
                    $parts = explode('-', $val, 2);
                    $havingClause = "HAVING stock_total BETWEEN " . floatval($parts[0]) . " AND " . floatval($parts[1]);
                } elseif (strpos($val, '<') === 0) {
                    $havingClause = "HAVING stock_total < " . floatval(substr($val, 1));
                } elseif (strpos($val, '>') === 0) {
                    $havingClause = "HAVING stock_total > " . floatval(substr($val, 1));
                } else {
                    $havingClause = "HAVING stock_total = " . floatval($val);
                }
            }

            $sqlBase = "SELECT p.codigo, p.nombre, p.categoria, p.precio, p.costo, p.precio_mayorista,
                        p.stock_minimo, p.unidad_medida, p.activo, p.es_web, p.es_pos,
                        p.es_materia_prima, p.es_servicio, p.es_cocina, p.es_reservable,
                        p.descripcion, p.impuesto, p.peso, p.color,
                        (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = $ALM_ID) AS stock_total
                        FROM productos p
                        WHERE $whereSQL
                        $havingClause";
            $stmt = $pdo->prepare($sqlBase . " ORDER BY p.codigo ASC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filename = 'productos_' . date('Y-m-d_H-i-s') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Pragma: no-cache');
            header('Expires: 0');

            $out = fopen('php://output', 'w');
            if (!$out) throw new Exception('No se pudo generar archivo CSV.');
            fputcsv($out, ['sku','nombre','categoria','precio','costo','precio_mayorista','stock_minimo','unidad_medida','activo','es_web','es_pos','es_materia_prima','es_servicio','es_cocina','es_reservable','descripcion','impuesto','peso','color','stock_total']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['codigo'] ?? '',
                    $row['nombre'] ?? '',
                    $row['categoria'] ?? '',
                    $row['precio'] ?? 0,
                    $row['costo'] ?? 0,
                    $row['precio_mayorista'] ?? 0,
                    $row['stock_minimo'] ?? 0,
                    $row['unidad_medida'] ?? 'UNIDAD',
                    $row['activo'] ?? 1,
                    $row['es_web'] ?? 1,
                    $row['es_pos'] ?? 1,
                    $row['es_materia_prima'] ?? 0,
                    $row['es_servicio'] ?? 0,
                    $row['es_cocina'] ?? 0,
                    $row['es_reservable'] ?? 0,
                    $row['descripcion'] ?? '',
                    $row['impuesto'] ?? 0,
                    $row['peso'] ?? 0,
                    $row['color'] ?? '',
                    $row['stock_total'] ?? 0
                ]);
            }
            fclose($out);
        } catch(Exception $e) {
            http_response_code(500);
            echo 'Error al exportar CSV: ' . $e->getMessage();
            exit;
        }
        exit;
    }

    if ($action === 'get_full_active_list') {
        header('Content-Type: application/json');
        try {
            $sql = "SELECT p.codigo, p.nombre, p.categoria, 
                    (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = :alm) as stock_total
                    FROM productos p 
                    WHERE p.id_empresa = :emp AND " . ptable_scope_sql('p', $SUC_ID, $ALM_ID) . " AND p.activo = 1 
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
$whereClauses = ["p.id_empresa = $EMP_ID", ptable_scope_sql('p', $SUC_ID, $ALM_ID)];
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

$kpiData = [
    'total_empresa' => 0,
    'activos' => 0,
    'inactivos' => 0,
    'sin_stock' => 0
];
try {
    $stmtKpi = $pdo->prepare("
        SELECT
            COUNT(*) AS total_empresa,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) AS activos,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) AS inactivos
        FROM productos
        WHERE id_empresa = ?
          AND " . str_replace('p.', '', ptable_scope_sql('productos', $SUC_ID, $ALM_ID)) . "
    ");
    $stmtKpi->execute([$EMP_ID]);
    $k = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpiData = [
        'total_empresa' => (int)($k['total_empresa'] ?? 0),
        'activos' => (int)($k['activos'] ?? 0),
        'inactivos' => (int)($k['inactivos'] ?? 0),
        'sin_stock' => 0
    ];
    $stmtSinStock = $pdo->prepare("
        SELECT COUNT(*)
        FROM productos p
        WHERE p.id_empresa = ?
          AND " . ptable_scope_sql('p', $SUC_ID, $ALM_ID) . "
          AND (SELECT COALESCE(SUM(s.cantidad), 0) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?) <= 0
    ");
    $stmtSinStock->execute([$EMP_ID, $ALM_ID]);
    $kpiData['sin_stock'] = (int)$stmtSinStock->fetchColumn();
} catch (Exception $e) {
    // no-op, deja valores por defecto
}

// QUERY VALOR TOTAL (Global)
$stmtValor = $pdo->prepare("SELECT SUM(s.cantidad * p.costo) FROM stock_almacen s JOIN productos p ON s.id_producto = p.codigo WHERE s.id_almacen = ? AND p.id_empresa = ?");
$stmtValor->execute([$ALM_ID, $EMP_ID]);
$valorInventario = floatval($stmtValor->fetchColumn() ?: 0);

// --- RESPUESTA AJAX ---
if ($isAjax) {
    try {
        echo json_encode([
            'status' => 'success',
            'html' => renderProductRows($productosPagina, $localPath),
            'total' => $totalProducts,
            'page' => $page,
            'pages' => $totalPages,
            'valor' => number_format($valorInventario, 2),
            'kpi' => $kpiData
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
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
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        body.inventory-suite { font-family: 'Segoe UI', sans-serif; color: #1e293b; padding-bottom: 80px; }
        .inventory-products-shell { max-width: 1520px; }
        .filter-section { background: #ffffff; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .card-table { border: 1px solid var(--pw-line); border-radius: 24px; overflow: hidden; background: var(--pw-card); box-shadow: 0 18px 45px rgba(15, 23, 42, .08); }
        .table thead th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px; background: rgba(248, 250, 252, .88); }
        .prod-img-table { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; border: 1px solid #eee; cursor: pointer; transition: transform 0.2s; }
        .prod-img-table:hover { transform: scale(1.5); border-color: #3b82f6; }
        .badge-stock { padding: 5px 10px; border-radius: 15px; font-weight: 700; font-size: 0.8rem; }
        .kpi-row { margin-bottom: 1rem; }
        .kpi-card { border: 1px solid var(--pw-line); border-radius: 18px; padding: 0.85rem 1rem; background: rgba(255,255,255,.72); box-shadow: 0 8px 18px rgba(15,23,42,.06); }
        .context-badge { background: rgba(255,255,255,0.15); padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.1); }
        .row-inactive { background-color: rgba(248, 113, 113, 0.05) !important; opacity: 0.95; }
        .row-inactive td { color: #64748b; }
        .editable-cell { position: relative; cursor: cell; transition: background 0.2s; }
        .editable-cell:hover { background-color: #eef2ff; }
        .editable-cell:hover::after { content: '✎'; position: absolute; right: 5px; top: 50%; transform: translateY(-50%); color: #3b82f6; font-size: 0.8rem; opacity: 0.5; }
        .history-btn { font-size: 0.7rem; color: #64748b; cursor: pointer; margin-left: 5px; }
        .history-btn:hover { color: #3b82f6; }
        #printableArea .table > :not(caption) > * > * { background: transparent; }
        @media (max-width: 991px) {
            .inventory-products-shell { padding-top: 1rem !important; padding-bottom: 1.5rem !important; }
            .inventory-products-shell .inventory-hero { border-radius: 20px; }
            .inventory-products-shell .inventory-hero h1 { font-size: 1.5rem; }
            .inventory-products-shell .inventory-toolbar { padding: .85rem .9rem; }
            .inventory-products-shell .inventory-toolbar .btn,
            .inventory-products-shell .inventory-toolbar .form-control,
            .inventory-products-shell .inventory-toolbar .form-select { min-height: 40px; }
            .card-table { border-radius: 18px; }
        }
        @media (max-width: 767px) {
            .inventory-products-shell .inventory-hero { padding: 1rem !important; }
            .inventory-products-shell .kpi-chip { font-size: .78rem; padding: .3rem .6rem; }
            .kpi-card { border-radius: 14px; padding: .7rem .8rem; }
            .card-table { border-radius: 14px; }
            #productsTable th, #productsTable td { font-size: .81rem; }
            .prod-img-table { width: 40px; height: 40px; }
            .inventory-toolbar__bulk-row { gap: .5rem; }
            .inventory-toolbar__bulk-row .form-select,
            .inventory-toolbar__bulk-row .form-control,
            .inventory-toolbar__bulk-row .btn { width: 100%; max-width: none !important; }
        }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="pb-5 inventory-suite">

<div class="container-fluid shell inventory-shell inventory-products-shell py-4 py-lg-5">
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in no-print">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
        <div>
            <div class="section-title text-white-50 mb-2">Catalogo / Inventario</div>
            <h1 class="h2 fw-bold mb-2"><i class="fas fa-boxes me-2"></i>Inventario</h1>
            <p class="mb-3 text-white-50">Gestion de productos, stock, precios, importaciones y operaciones masivas.</p>
            <div class="d-flex flex-wrap gap-2">
                <span class="kpi-chip"><i class="fas fa-building me-1"></i>Suc <?= (int)$SUC_ID ?></span>
                <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Alm <?= (int)$ALM_ID ?></span>
                <span class="kpi-chip"><i class="fas fa-dollar-sign me-1"></i>Valor <strong id="totalValueDisplay" class="ms-1">$<?= number_format($valorInventario, 2) ?></strong></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-1 mt-lg-0">
            <a href="inventory_report.php" class="btn btn-light"><i class="fas fa-chart-pie me-1"></i>Informe</a>
            <button onclick="forceImageCacheReset()" class="btn btn-outline-light" title="Limpia caché de imágenes y recarga miniaturas"><i class="fas fa-broom me-1"></i>Limpiar cache</button>
            <button onclick="printInventoryCount()" class="btn btn-warning text-dark"><i class="fas fa-clipboard-list me-1"></i>Conteo</button>
            <button onclick="printTable()" class="btn btn-outline-light"><i class="fas fa-print me-1"></i>Lista</button>
            <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i></a>
        </div>
    </div>
</section>
    <div class="row g-2 kpi-row inventory-fade-in">
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="small text-muted">Total productos</div>
                <div class="h5 mb-0" id="kpiTotal">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="small text-muted">Activos</div>
                <div class="h5 mb-0 text-success" id="kpiActive">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="small text-muted">Inactivos</div>
                <div class="h5 mb-0 text-danger" id="kpiInactive">0</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="small text-muted">Sin stock</div>
                <div class="h5 mb-0 text-warning" id="kpiNoStock">0</div>
            </div>
        </div>
    </div>

    <?php
    $filterCodeEsc = htmlspecialchars((string)$filterCode, ENT_QUOTES, 'UTF-8');
    $filterNameEsc = htmlspecialchars((string)$filterName, ENT_QUOTES, 'UTF-8');
    $totalProductsInt = (int)$totalProducts;
    $pageInt = (int)$page;
    $toolbarFilterForm = <<<HTML
<form id="filterForm" class="inventory-toolbar__filter-grid" onsubmit="event.preventDefault(); loadData(1);">
    <div><label class="small fw-bold text-muted mb-1">SKU</label><input type="text" id="f_code" class="form-control form-control-sm" value="{$filterCodeEsc}"></div>
    <div><label class="small fw-bold text-muted mb-1">Nombre</label><input type="text" id="f_name" class="form-control form-control-sm" value="{$filterNameEsc}"></div>
    <div><label class="small fw-bold text-muted mb-1">Estado</label><select id="f_status" class="form-select form-select-sm"><option value="active">✅ Activos</option><option value="inactive">❌ Inactivos</option><option value="all">♾️ Todos</option></select></div>
    <div><label class="small fw-bold text-muted mb-1">Rango Stock</label><input type="text" id="f_stock" class="form-control form-control-sm" placeholder="Ej: <5, >10, 0, 10-20"></div>
    <div><label class="small fw-bold text-muted mb-1">Mostrar</label><select id="f_limit" class="form-select form-select-sm" onchange="loadData(1)"><option value="10">10 por pág</option><option value="20">20 por pág</option><option value="50">50 por pág</option><option value="100">100 por pág</option></select></div>
    <div class="inventory-toolbar__stack"><button type="submit" class="btn btn-dark btn-sm inventory-btn w-100"><i class="fas fa-filter me-1"></i>Filtrar</button><button type="button" class="btn btn-success btn-sm inventory-btn w-100 mt-2" onclick="openProductCreator(productoCreadoExito)"><i class="fas fa-plus me-1"></i>Nuevo</button></div>
    <div class="inventory-toolbar__stack"><button type="button" class="btn btn-outline-primary btn-sm inventory-btn w-100" onclick="openCategoriesModal()"><i class="fas fa-tags me-1"></i>Categorías</button><button type="button" class="btn btn-outline-secondary btn-sm inventory-btn w-100 mt-2" onclick="exportCsv()"><i class="fas fa-file-export me-1"></i>Exportar CSV</button></div>
    <div class="inventory-toolbar__stack"><button type="button" class="btn btn-outline-info btn-sm inventory-btn w-100" onclick="document.getElementById('importFileInput').click()"><i class="fas fa-file-import me-1"></i>Importar CSV</button><select id="importMode" class="form-select form-select-sm mt-2"><option value="update_create">Actualizar o crear</option><option value="update_only">Solo actualizar</option></select></div>
</form>
HTML;
    $toolbarBulk = <<<HTML
<div class="inventory-toolbar__bulk-row">
    <div class="form-check"><input class="form-check-input" type="checkbox" id="selectAll"><label class="form-check-label small fw-bold" for="selectAll">Todos</label></div>
    <div class="vr"></div>
    <select class="form-select form-select-sm" style="max-width: 220px;" id="bulkActionSelect"><option value="">-- Acción Masiva --</option><option value="print_labels">🏷️ Imprimir Etiquetas</option><option value="web_on">🌐 Activar en WEB</option><option value="web_off">🚫 Ocultar de WEB</option><option value="reservable_on">📅 Activar Reservable</option><option value="reservable_off">📅 Desactivar Reservable</option><option value="pos_on">🧾 Mostrar en POS</option><option value="pos_off">🧾 Ocultar en POS</option><option value="active_on">✅ Activar Producto</option><option value="active_off">❌ Desactivar Producto</option><option value="set_stock_min">📌 Cambiar Stock Mínimo</option><option value="change_cat">📂 Cambiar Categoría</option><option value="clone_selected">📑 Clonar Seleccionados</option></select>
    <input type="text" class="form-control form-control-sm d-none" id="bulkCatInput" list="bulk_cat_list" placeholder="Nueva Categoría" style="max-width: 150px;">
    <input type="number" step="0.01" class="form-control form-control-sm d-none" id="bulkStockMinInput" placeholder="Stock mínimo" style="max-width: 150px;">
    <datalist id="bulk_cat_list"></datalist>
    <button class="btn btn-secondary btn-sm inventory-btn" onclick="applyBulkAction()">Aplicar</button>
    <div class="ms-auto text-muted small"><strong id="selectedCount">0 sel</strong> | Total: <strong id="totalCountDisplay">{$totalProductsInt}</strong> | Pág <span id="currentPageDisplay">{$pageInt}</span></div>
</div>
HTML;
    inventory_suite_render_toolbar([
        'title' => 'Filtros y acciones',
        'subtitle' => 'Búsqueda operativa, importación y acciones masivas sobre el catálogo.',
        'class' => 'mb-4 products-toolbar no-print inventory-fade-in',
        'left' => [
            $toolbarFilterForm,
            $toolbarBulk,
        ],
    ]);
    ?>

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

<input type="file" id="fileInput"       accept="image/jpeg, image/webp, image/png" style="display:none" onchange="uploadPhoto()">
<input type="file" id="editorFileInput" accept="image/jpeg,image/webp,image/png" style="display:none" onchange="handleEditorUpload()">
<input type="file" id="importFileInput" accept=".csv,text/csv" style="display:none" onchange="runImportCsv()">
<div id="uploadProgressBox" style="display:none;position:fixed;right:18px;bottom:18px;z-index:2055;width:min(340px,calc(100vw - 36px));background:#fff;border:1px solid #dbeafe;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.18);padding:14px 14px 12px">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="fw-semibold text-dark"><i class="fas fa-cloud-upload-alt text-primary me-2"></i>Subiendo imagen</div>
        <div id="uploadProgressPct" class="small text-muted">0%</div>
    </div>
    <div class="progress" style="height:12px;background:#e5e7eb">
        <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div>
    </div>
    <div id="uploadProgressText" class="small text-muted mt-2">Preparando archivo...</div>
</div>

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

<div class="modal fade" id="imageSourceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-image me-2"></i> Cambiar imagen del producto</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Seleccione cómo quiere cargar la nueva imagen del producto <strong id="imageSourceSkuLabel">-</strong>.</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary btn-lg" onclick="chooseLocalImage()">
                        <i class="fas fa-folder-open me-2"></i> Seleccionar archivo local
                    </button>
                    <button type="button" class="btn btn-outline-success btn-lg" onclick="chooseInternetImage()">
                        <i class="fas fa-globe me-2"></i> Buscar nueva imagen en internet
                    </button>
                </div>
                <div class="small text-muted mt-3">
                    Si escoge internet, se borrará la imagen actual del producto y se abrirá el buscador inteligente para ese SKU.
                </div>
            </div>
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

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

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

    if(!qty || qty <= 0) return showToast("⚠️ Ingrese una cantidad válida");
    if(!note) return showToast("⚠️ Ingrese el motivo del ajuste");

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
            showToast("❌ Error: " + data.msg);
        }
    } catch(e) { showToast("❌ Error de conexión"); }
}

function showToast(msg) {
    const container = document.getElementById('toastContainer');
    if (!container) return alert(msg);
    const id = `pt-toast-${Date.now()}`;
    const type = String(msg).toLowerCase().includes('error') || String(msg).toLowerCase().includes('fallo')
        ? 'bg-danger'
        : 'bg-dark';
    const html = `
        <div id="${id}" class="toast align-items-center text-white border-0 ${type} mb-2" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">${msg}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    const t = new bootstrap.Toast(el, { delay: 2500 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

// VARIABLES GLOBALES
let currentPage = 1;
let currentCode = '';
let imageSourceModalInstance = null;
let currentSort = 'nombre';
let currentDir = 'ASC';
const initialKpi = <?php echo json_encode($kpiData, JSON_UNESCAPED_UNICODE); ?>;

function renderKpi(kpi) {
    if (!kpi) return;
    const kTotal = document.getElementById('kpiTotal');
    const kActive = document.getElementById('kpiActive');
    const kInactive = document.getElementById('kpiInactive');
    const kNoStock = document.getElementById('kpiNoStock');
    if (kTotal) kTotal.innerText = Number(kpi.total_empresa || 0).toLocaleString('en-US');
    if (kActive) kActive.innerText = Number(kpi.activos || 0).toLocaleString('en-US');
    if (kInactive) kInactive.innerText = Number(kpi.inactivos || 0).toLocaleString('en-US');
    if (kNoStock) kNoStock.innerText = Number(kpi.sin_stock || 0).toLocaleString('en-US');
}

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
        
        if (data.status === 'error') {
            showToast('❌ Error del servidor: ' + data.msg);
            return;
        }

        document.getElementById('tableBody').innerHTML = data.html;
        document.getElementById('totalCountDisplay').innerText = data.total;
        document.getElementById('currentPageDisplay').innerText = data.page;
        document.getElementById('totalValueDisplay').innerText = '$' + data.valor;
        if (data.kpi) {
            document.getElementById('kpiTotal').innerText = Number(data.kpi.total_empresa || 0).toLocaleString('en-US');
            document.getElementById('kpiActive').innerText = Number(data.kpi.activos || 0).toLocaleString('en-US');
            document.getElementById('kpiInactive').innerText = Number(data.kpi.inactivos || 0).toLocaleString('en-US');
            document.getElementById('kpiNoStock').innerText = Number(data.kpi.sin_stock || 0).toLocaleString('en-US');
        }
        
        renderPagination(data.page, data.pages);
        
        document.getElementById('tableBody').style.opacity = '1';
        initInlineEdit();
        updateSortIcons();
        
    } catch(e) { 
        console.error(e); 
        showToast('❌ Error cargando datos'); 
    } finally {
        document.getElementById('tableBody').style.opacity = '1';
    }
}

function exportCsv() {
    const limit = document.getElementById('f_limit').value;
    const code = document.getElementById('f_code').value;
    const name = document.getElementById('f_name').value;
    const status = document.getElementById('f_status').value;
    const stockRange = document.getElementById('f_stock').value;
    const url = `products_table.php?action=export_csv&code=${encodeURIComponent(code)}&name=${encodeURIComponent(name)}&status=${status}&stock_range=${encodeURIComponent(stockRange)}&sort=${currentSort}&dir=${currentDir}&limit=${limit}`;
    window.location.href = url;
}

async function runImportCsv() {
    const input = document.getElementById('importFileInput');
    const file = input.files[0];
    if (!file) return;
    input.value = '';
    const formData = new FormData();
    const mode = document.getElementById('importMode').value || 'update_create';
    formData.append('action', 'import_products');
    formData.append('import_mode', mode);
    formData.append('products_file', file);

    try {
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            const { inserted = 0, updated = 0, skipped = 0 } = data.imported || {};
            showToast(`✅ Importación: nuevos ${inserted}, actualizados ${updated}, omitidos ${skipped}`);
            if (Array.isArray(data.errors) && data.errors.length) {
                showToast('⚠️ Detalle de errores: ' + data.errors.slice(0, 4).join(' | '));
            }
            loadData(1);
        } else {
            showToast('❌ Error importando: ' + (data.msg || 'desconocido'));
        }
    } catch (e) {
        showToast('❌ Error de conexión al importar.');
    }
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

function parseLogPayload(raw) {
    if (typeof raw !== 'string' || !raw) return {};
    try { return JSON.parse(raw) || {}; } catch (e) { return {}; }
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
            showToast('❌ Error: ' + data.msg);
            cell.innerHTML = oldVal;
        }
    } catch(e) { showToast('❌ Error de conexión'); }
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
        else list.innerHTML = logs.map(l => {
            const row = formatHistoryLine(l);
            return `<li class="list-group-item"><div class="fw-bold">${l.fecha}</div><div class="text-muted small">Por: ${l.usuario}</div><div class="small">${row}</div></li>`;
        }).join('');
    } catch(e) { list.innerHTML = '<li class="list-group-item text-danger">Error cargando.</li>'; }
}

function formatHistoryLine(logRow) {
    const data = parseLogPayload(logRow.datos);
    if (data.precio_anterior !== undefined || data.precio_nuevo !== undefined) {
        const prev = data.precio_anterior !== undefined ? `$${Number(data.precio_anterior).toFixed(2)}` : '—';
        const next = data.precio_nuevo !== undefined ? `$${Number(data.precio_nuevo).toFixed(2)}` : '—';
        return `Precio: ${prev} → ${next}`;
    }
    if (data.stock_anterior !== undefined || data.stock_nuevo !== undefined) {
        return `Stock: ${Number(data.stock_anterior || 0).toFixed(1)} → ${Number(data.stock_nuevo || 0).toFixed(1)}`;
    }
    if (data.es_web_anterior !== undefined || data.es_web_nuevo !== undefined) {
        return `Web: ${Number(data.es_web_anterior || 0)} → ${Number(data.es_web_nuevo || 0)}`;
    }
    if (data.es_reservable_anterior !== undefined || data.es_reservable_nuevo !== undefined) {
        return `Reservable: ${Number(data.es_reservable_anterior || 0)} → ${Number(data.es_reservable_nuevo || 0)}`;
    }
    if (data.es_pos_anterior !== undefined || data.es_pos_nuevo !== undefined) {
        return `POS: ${Number(data.es_pos_anterior || 0)} → ${Number(data.es_pos_nuevo || 0)}`;
    }
    if (data.activo_anterior !== undefined || data.activo_nuevo !== undefined) {
        return `Activo: ${Number(data.activo_anterior || 0)} → ${Number(data.activo_nuevo || 0)}`;
    }
    if (data.categoria_anterior !== undefined || data.categoria_nueva !== undefined) {
        return `Categoría: ${data.categoria_anterior || '—'} → ${data.categoria_nueva || '—'}`;
    }
    if (data.stock_minimo_anterior !== undefined || data.stock_minimo_nuevo !== undefined) {
        return `Stock mínimo: ${data.stock_minimo_anterior ?? '—'} → ${data.stock_minimo_nuevo ?? '—'}`;
    }
    if (data.sku_origen && data.sku_nuevo) return `Clonado: ${data.sku_origen} → ${data.sku_nuevo}`;
    return logRow.accion ? `Acción: ${logRow.accion}` : 'Sin detalle';
}

// --- 4. ACCIONES MASIVAS ---
const checks = document.querySelectorAll('.bulk-check');
const selectAll = document.getElementById('selectAll');
if(selectAll) selectAll.addEventListener('change', function() { document.querySelectorAll('.bulk-check').forEach(c => c.checked = this.checked); updateCount(); });
document.addEventListener('change', e => { if(e.target.classList.contains('bulk-check')) updateCount(); });
function updateCount() { 
    const selected = document.querySelectorAll('.bulk-check:checked').length;
    const el = document.getElementById('selectedCount');
    if (el) el.innerText = selected + ' sel'; 
}

const bulkSelect = document.getElementById('bulkActionSelect');
if(bulkSelect) {
    bulkSelect.addEventListener('change', function() {
        const catInput = document.getElementById('bulkCatInput');
        const stockInput = document.getElementById('bulkStockMinInput');
        if(this.value === 'change_cat') {
            catInput.classList.remove('d-none');
            stockInput.classList.add('d-none');
        } else if(this.value === 'set_stock_min') {
            catInput.classList.add('d-none');
            stockInput.classList.remove('d-none');
        } else {
            catInput.classList.add('d-none');
            stockInput.classList.add('d-none');
        }
    });
}

async function applyBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    const selected = Array.from(document.querySelectorAll('.bulk-check:checked')).map(c => c.value);
    if(selected.length === 0) return showToast("⚠️ Selecciona productos.");

    if (action === 'print_labels') {
        const copies = prompt('Cantidad de copias por producto (1-10):', '1');
        const copiesInt = Math.min(Math.max(parseInt(copies || '1', 10), 1), 10);
        const url = `print_labels.php?skus=${selected.join(',')}&copies=${copiesInt}`;
        window.open(url, 'Etiquetas', 'width=800,height=600');
        return;
    }

    if(!confirm(`¿Aplicar a ${selected.length} productos?`)) return;

    const formData = new FormData();
    formData.append('bulk_action', action);
    formData.append('skus', JSON.stringify(selected));
    if(action === 'change_cat') formData.append('new_cat_val', document.getElementById('bulkCatInput').value);
    if(action === 'set_stock_min') {
        const val = document.getElementById('bulkStockMinInput').value;
        if(val === '') return showToast('⚠️ Ingrese el stock mínimo.');
        formData.append('new_stock_min_val', val);
    }
    if(action === 'clone_selected') {
        if(!confirm(`¿Clonar ${selected.length} productos como copias?`)) return;
    }

    try {
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.status === 'success') {
            if(data.mode === 'clone') showToast(`✅ ${data.count} productos clonados.`);
            else showToast("✅ Listo");
            loadData(currentPage);
        } else {
            showToast("❌ Error: " + data.msg);
        }
    } catch(e) { showToast("❌ Error conexión"); }
}

async function cloneProduct(sku) {
    if (!confirm(`¿Clonar este producto (${sku})?`)) return;
    try {
        const formData = new FormData();
        formData.append('action', 'clone_product');
        formData.append('sku', sku);
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(`✅ Producto clonado con SKU ${data.new_sku}`);
            loadData(currentPage);
        } else {
            showToast("❌ Error: " + data.msg);
        }
    } catch (e) { showToast("❌ Error de conexión."); }
}

// --- IMÁGENES DEL EDITOR ---
let _editorSlot = '', _editorSku = '';
function setUploadProgress(visible, percent = 0, text = 'Subiendo imagen...') {
    const box = document.getElementById('uploadProgressBox');
    const bar = document.getElementById('uploadProgressBar');
    const pct = document.getElementById('uploadProgressPct');
    const label = document.getElementById('uploadProgressText');
    if (!box || !bar || !pct || !label) return;
    box.style.display = visible ? 'block' : 'none';
    if (!visible) return;
    const safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
    bar.style.width = safePercent + '%';
    bar.setAttribute('aria-valuenow', String(safePercent));
    pct.textContent = safePercent + '%';
    label.textContent = text || 'Subiendo imagen...';
}

function ptableUploadRequest(formData, onProgressText = 'Subiendo imagen...') {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'products_table.php', true);
        xhr.responseType = 'text';
        xhr.upload.onprogress = (event) => {
            if (!event.lengthComputable) {
                setUploadProgress(true, 15, onProgressText);
                return;
            }
            const percent = event.total > 0 ? (event.loaded / event.total) * 100 : 0;
            setUploadProgress(true, percent, onProgressText);
        };
        xhr.onload = () => {
            const raw = xhr.responseText || '';
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseError) {
                reject(new Error(raw && raw.trim() ? raw.trim().slice(0, 220) : 'Respuesta inválida del servidor.'));
                return;
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                setUploadProgress(true, 100, 'Imagen subida. Procesando respuesta...');
                resolve(data);
                return;
            }
            reject(new Error((data && data.msg) || `Error HTTP ${xhr.status}`));
        };
        xhr.onerror = () => reject(new Error('No se pudo conectar con el servidor.'));
        xhr.onabort = () => reject(new Error('Subida cancelada.'));
        setUploadProgress(true, 0, onProgressText);
        xhr.send(formData);
    });
}

function triggerEditorImg(sku, slot) {
    _editorSku  = sku;
    _editorSlot = slot;
    document.getElementById('editorFileInput').click();
}

async function ptablePrepareUploadFile(file) {
    if (!file || !file.type || !file.type.startsWith('image/')) return file;
    const compressBySize = file.size > (4 * 1024 * 1024);
    const maxSide = compressBySize ? 1600 : 2200;
    const quality = compressBySize ? 0.82 : 0.9;

    const dataUrl = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('No se pudo leer la imagen local.'));
        reader.readAsDataURL(file);
    });

    const img = await new Promise((resolve, reject) => {
        const node = new Image();
        node.onload = () => resolve(node);
        node.onerror = () => reject(new Error('La imagen seleccionada no es válida.'));
        node.src = dataUrl;
    });

    const width = img.naturalWidth || img.width || 0;
    const height = img.naturalHeight || img.height || 0;
    if (!width || !height) return file;

    const needsResize = width > maxSide || height > maxSide;
    if (!needsResize && file.size <= (2 * 1024 * 1024)) return file;

    const scale = Math.min(1, maxSide / Math.max(width, height));
    const targetW = Math.max(1, Math.round(width * scale));
    const targetH = Math.max(1, Math.round(height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = targetW;
    canvas.height = targetH;
    const ctx = canvas.getContext('2d', { alpha: false });
    if (!ctx) return file;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, targetW, targetH);
    ctx.drawImage(img, 0, 0, targetW, targetH);

    const blob = await new Promise((resolve, reject) => {
        canvas.toBlob(
            (out) => out ? resolve(out) : reject(new Error('No se pudo optimizar la imagen antes de subirla.')),
            'image/jpeg',
            quality
        );
    });

    const safeName = (file.name || 'producto').replace(/\.[^.]+$/, '') + '.jpg';
    return new File([blob], safeName, { type: 'image/jpeg', lastModified: Date.now() });
}

async function handleEditorUpload() {
    const input = document.getElementById('editorFileInput');
    const selectedFile  = input.files[0];
    setUploadProgress(true, 0, 'Optimizando imagen...');
    const file = selectedFile ? await ptablePrepareUploadFile(selectedFile) : null;
    if (!file) return;
    input.value = '';

    const prodCode = (_editorSlot === 'main') ? _editorSku : _editorSku + '_' + _editorSlot;
    const formData = new FormData();
    formData.append('new_photo', file);
    formData.append('prod_code', prodCode);

    const imgEl = document.getElementById('img_' + _editorSlot);
    if (imgEl) imgEl.style.opacity = '0.4';

    try {
        const data = await ptableUploadRequest(formData, 'Subiendo imagen del editor...');
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
            setTimeout(() => setUploadProgress(false), 500);
        } else {
            if (imgEl) imgEl.style.opacity = '1';
            setUploadProgress(false);
            showToast('❌ Error al guardar imagen: ' + (data.msg || 'desconocido'));
        }
    } catch (e) {
        if (imgEl) imgEl.style.opacity = '1';
        setUploadProgress(false);
        showToast('❌ Error al subir imagen: ' + (e.message || 'desconocido'));
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
            showToast('❌ Error al eliminar imagen: ' + (data.msg || 'desconocido'));
        }
    } catch (e) {
        showToast('❌ Error de conexión al eliminar imagen.');
    }
}

// --- VARIOS ---
function openImageSourceModal(code) {
    currentCode = code;
    const label = document.getElementById('imageSourceSkuLabel');
    if (label) label.textContent = code;
    const modalEl = document.getElementById('imageSourceModal');
    if (!imageSourceModalInstance && modalEl) {
        imageSourceModalInstance = new bootstrap.Modal(modalEl);
    }
    if (imageSourceModalInstance) {
        imageSourceModalInstance.show();
    }
}

function chooseLocalImage() {
    if (imageSourceModalInstance) imageSourceModalInstance.hide();
    document.getElementById('fileInput').click();
}

async function chooseInternetImage() {
    if (!currentCode) return;
    if (imageSourceModalInstance) imageSourceModalInstance.hide();

    try {
        const formData = new FormData();
        formData.append('action', 'delete_main_img');
        formData.append('sku', currentCode);
        const res = await fetch('products_table.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status !== 'success') {
            showToast('❌ Error al preparar búsqueda web: ' + (data.msg || 'desconocido'));
            return;
        }

        const img = document.querySelector(`.prod-img-table[data-code="${currentCode}"]`);
        if (img) {
            img.src = 'assets/img/no-image-50.png?t=' + Date.now();
        }
        const targetUrl = `image_smart_hunter.php?focus=${encodeURIComponent(currentCode)}&autorun=sku`;
        window.open(targetUrl, '_blank');
        showToast('🌐 Abriendo buscador web para ' + currentCode);
    } catch (e) {
        showToast('❌ Error al abrir búsqueda web.');
    }
}

function triggerUpload(code) { currentCode = code; document.getElementById('fileInput').click(); }
function uploadPhoto() {
    (async () => {
        const selectedFile = document.getElementById('fileInput').files[0];
        setUploadProgress(true, 0, 'Optimizando imagen...');
        const file = selectedFile ? await ptablePrepareUploadFile(selectedFile) : null;
        if(!file) return;
        const formData = new FormData();
        formData.append('new_photo', file);
        formData.append('prod_code', currentCode);
        document.getElementById('fileInput').value = '';
        ptableUploadRequest(formData, 'Subiendo imagen del producto...')
            .then(res => {
                if(res.status === 'success') {
                    const img = document.querySelector(`.prod-img-table[data-code="${currentCode}"]`);
                    if(img) img.src = `image.php?code=${encodeURIComponent(currentCode)}&t=${Date.now()}`;
                    setTimeout(() => setUploadProgress(false), 500);
                } else {
                    setUploadProgress(false);
                    showToast('❌ Error al guardar imagen: ' + res.msg);
                }
            })
            .catch(e => {
                setUploadProgress(false);
                showToast('❌ Error de conexión: ' + e.message);
            });
    })().catch(e => {
        setUploadProgress(false);
        showToast('❌ Error preparando imagen: ' + (e.message || 'desconocido'));
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
        if(data.status !== 'success') { checkbox.checked = !checkbox.checked; showToast("❌ Error: " + data.msg); }
    } catch(e) { checkbox.checked = !checkbox.checked; showToast('❌ Error de conexión'); }
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
            showToast("❌ Error: " + data.msg);
        }
    } catch(e) { checkbox.checked = !checkbox.checked; showToast('❌ Error de conexión'); }
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

async function forceImageCacheReset() {
    if (!confirm('Se limpiará la caché local de imágenes y se recargará la tabla. ¿Continuar?')) return;
    let deleted = 0;
    try {
        if ('caches' in window) {
            const cacheNames = await caches.keys();
            for (const cacheName of cacheNames) {
                const cache = await caches.open(cacheName);
                const requests = await cache.keys();
                for (const req of requests) {
                    const u = req.url || '';
                    if (
                        u.includes('/image.php?') ||
                        u.includes('/assets/product_images/') ||
                        u.includes('via.placeholder.com/50?text=IMG')
                    ) {
                        if (await cache.delete(req)) deleted++;
                    }
                }
            }
        }
    } catch (e) {
        console.warn('No se pudo limpiar Cache Storage', e);
    }

    const bust = Date.now();
    try {
        document.querySelectorAll('img.prod-img-table').forEach(img => {
            const code = img.dataset.code || '';
            if (!code) return;
            img.src = `image.php?code=${encodeURIComponent(code)}&t=${bust}`;
        });
    } catch (e) {
        console.warn('No se pudieron refrescar miniaturas', e);
    }

    await loadData(currentPage);
    showToast(`✅ Caché de imágenes limpiada. Elementos eliminados: ${deleted}`);
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    initInlineEdit();
    updateCount();
        // Marcar el ícono por defecto (Nombre ASC)
    updateSortIcons();
    renderKpi(initialKpi);
    // Cargar categorías
    reloadCategorySelects();
    loadData(<?php echo (int)$page; ?>);
    // sincronizar selects para estado previo
    const statusEl = document.getElementById('f_status');
    if (statusEl) statusEl.value = '<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>';
    const limitEl = document.getElementById('f_limit');
    if (limitEl) limitEl.value = '<?php echo (int)$limit; ?>';
    const stockEl = document.getElementById('f_stock');
    if (stockEl) stockEl.value = '<?php echo htmlspecialchars($filterStockRange, ENT_QUOTES, 'UTF-8'); ?>';
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
