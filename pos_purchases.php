<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
require_once 'config_loader.php';
require_once 'kardex_engine.php';
require_once 'pos_audit.php';

header('Content-Type: text/html; charset=utf-8');

define('PURCHASE_HISTORY_LIMIT', 80);
define('PURCHASE_UPLOAD_DIR', __DIR__ . '/uploads/purchase_docs');
define('PURCHASE_UPLOAD_WEB', 'uploads/purchase_docs');

function purchase_json_response(array $payload, int $status = 200): void
{
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function purchase_actor_label(): string
{
    $candidates = [
        $_SESSION['username'] ?? null,
        $_SESSION['nombre'] ?? null,
        $_SESSION['user_name'] ?? null,
        $_SESSION['user_id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== null && trim((string)$candidate) !== '') {
            return trim((string)$candidate);
        }
    }
    return 'Admin';
}

function purchase_user_role(): string
{
    $candidates = [
        $_SESSION['rol'] ?? null,
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['cajero_rol'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== null && trim((string)$candidate) !== '') {
            return strtolower(trim((string)$candidate));
        }
    }
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true ? 'admin' : 'viewer';
}

function purchase_permissions(): array
{
    $role = purchase_user_role();
    $isAdmin = in_array($role, ['admin', 'administrador', 'superadmin', 'owner'], true);
    return [
        'create' => true,
        'process' => $isAdmin,
        'revert' => $isAdmin,
        'duplicate' => true,
        'upload' => true,
    ];
}

function purchase_require_permission(string $permission): void
{
    $permissions = purchase_permissions();
    if (empty($permissions[$permission])) {
        throw new RuntimeException('Tu usuario no tiene permisos para esta accion.');
    }
}

function purchase_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function purchase_extract_location_from_notes(?string $notes): array
{
    $location = ['id_sucursal' => 0, 'id_almacen' => 0];
    if (!$notes) {
        return $location;
    }
    if (preg_match('/\[Suc:(\d+)\s+Alm:(\d+)\]/', $notes, $match)) {
        $location['id_sucursal'] = (int)$match[1];
        $location['id_almacen'] = (int)$match[2];
    }
    return $location;
}

function purchase_bootstrap_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_cabecera (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proveedor VARCHAR(100) NULL,
        total DECIMAL(12,2) DEFAULT 0,
        total_original DECIMAL(12,2) DEFAULT NULL,
        usuario VARCHAR(50) NULL,
        created_by VARCHAR(100) NULL,
        notas TEXT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        numero_factura VARCHAR(50) NULL,
        estado VARCHAR(20) DEFAULT 'PROCESADA',
        id_empresa INT DEFAULT 1,
        id_sucursal INT DEFAULT 1,
        id_almacen INT DEFAULT 1,
        duplicated_from_id INT NULL,
        factura_adjunto VARCHAR(255) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_compra_scope (id_empresa, id_sucursal, id_almacen),
        INDEX idx_compra_estado (estado),
        INDEX idx_compra_factura (numero_factura),
        INDEX idx_compra_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_detalle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_compra INT NOT NULL,
        id_producto VARCHAR(50) NOT NULL,
        cantidad DECIMAL(12,2) NOT NULL,
        costo_unitario DECIMAL(12,2) NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        costo_anterior DECIMAL(12,2) DEFAULT NULL,
        costo_resultante DECIMAL(12,2) DEFAULT NULL,
        stock_antes DECIMAL(12,2) DEFAULT NULL,
        stock_despues DECIMAL(12,2) DEFAULT NULL,
        estado_item VARCHAR(20) DEFAULT 'ACTIVO',
        revertido_at DATETIME NULL,
        revertido_by VARCHAR(100) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_compra (id_compra),
        INDEX idx_producto (id_producto),
        INDEX idx_estado_item (estado_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $alterCabecera = [
        "ADD COLUMN total_original DECIMAL(12,2) DEFAULT NULL AFTER total",
        "ADD COLUMN created_by VARCHAR(100) NULL AFTER usuario",
        "ADD COLUMN id_empresa INT DEFAULT 1 AFTER estado",
        "ADD COLUMN id_sucursal INT DEFAULT 1 AFTER id_empresa",
        "ADD COLUMN id_almacen INT DEFAULT 1 AFTER id_sucursal",
        "ADD COLUMN duplicated_from_id INT NULL AFTER id_almacen",
        "ADD COLUMN factura_adjunto VARCHAR(255) NULL AFTER duplicated_from_id",
        "ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER factura_adjunto",
        "ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        "ADD INDEX idx_compra_scope (id_empresa, id_sucursal, id_almacen)",
        "ADD INDEX idx_compra_estado (estado)",
        "ADD INDEX idx_compra_factura (numero_factura)",
        "ADD INDEX idx_compra_fecha (fecha)",
    ];
    foreach ($alterCabecera as $alter) {
        try {
            $pdo->exec("ALTER TABLE compras_cabecera {$alter}");
        } catch (Throwable $ignored) {
        }
    }

    $alterDetalle = [
        "ADD COLUMN costo_anterior DECIMAL(12,2) DEFAULT NULL AFTER subtotal",
        "ADD COLUMN costo_resultante DECIMAL(12,2) DEFAULT NULL AFTER costo_anterior",
        "ADD COLUMN stock_antes DECIMAL(12,2) DEFAULT NULL AFTER costo_resultante",
        "ADD COLUMN stock_despues DECIMAL(12,2) DEFAULT NULL AFTER stock_antes",
        "ADD COLUMN estado_item VARCHAR(20) DEFAULT 'ACTIVO' AFTER stock_despues",
        "ADD COLUMN revertido_at DATETIME NULL AFTER estado_item",
        "ADD COLUMN revertido_by VARCHAR(100) NULL AFTER revertido_at",
        "ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER revertido_by",
        "ADD INDEX idx_producto (id_producto)",
        "ADD INDEX idx_estado_item (estado_item)",
    ];
    foreach ($alterDetalle as $alter) {
        try {
            $pdo->exec("ALTER TABLE compras_detalle {$alter}");
        } catch (Throwable $ignored) {
        }
    }

    if (!purchase_table_has_column($pdo, 'compras_cabecera', 'numero_factura')) {
        $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN numero_factura VARCHAR(50) NULL");
    }
    if (!purchase_table_has_column($pdo, 'compras_cabecera', 'fecha')) {
        $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN fecha DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
    if (!purchase_table_has_column($pdo, 'compras_cabecera', 'estado')) {
        $pdo->exec("ALTER TABLE compras_cabecera ADD COLUMN estado VARCHAR(20) DEFAULT 'PROCESADA'");
    }

    $legacyCabeceras = $pdo->query(
        "SELECT id, notas FROM compras_cabecera
         WHERE COALESCE(id_sucursal, 0) = 0 OR COALESCE(id_almacen, 0) = 0 OR COALESCE(id_empresa, 0) = 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmtEmpresaBySucursal = $pdo->prepare('SELECT id_empresa FROM sucursales WHERE id = ? LIMIT 1');
    $stmtFixHeader = $pdo->prepare("UPDATE compras_cabecera SET id_empresa = ?, id_sucursal = ?, id_almacen = ?, total_original = COALESCE(total_original, total), created_by = COALESCE(NULLIF(created_by, ''), usuario) WHERE id = ?");
    foreach ($legacyCabeceras as $row) {
        $loc = purchase_extract_location_from_notes((string)($row['notas'] ?? ''));
        $sucursalId = (int)$loc['id_sucursal'];
        $almacenId = (int)$loc['id_almacen'];
        $empresaId = 1;
        if ($sucursalId > 0) {
            $stmtEmpresaBySucursal->execute([$sucursalId]);
            $empresaId = (int)($stmtEmpresaBySucursal->fetchColumn() ?: 1);
        }
        $stmtFixHeader->execute([$empresaId, max(1, $sucursalId), max(1, $almacenId), (int)$row['id']]);
    }

    try {
        $pdo->exec("UPDATE compras_detalle SET estado_item = 'ACTIVO' WHERE estado_item IS NULL OR estado_item = ''");
    } catch (Throwable $ignored) {
    }
}

function purchase_load_context(PDO $pdo, array $config): array
{
    $context = [
        'id_empresa' => (int)($config['id_empresa'] ?? 1),
        'id_sucursal' => (int)($config['id_sucursal'] ?? 1),
        'id_almacen' => (int)($config['id_almacen'] ?? 1),
    ];

    try {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare(
                'SELECT id_empresa, id_sucursal, id_almacen FROM pos_user_contexts WHERE user_id = ? AND COALESCE(activo, 1) = 1 LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $context = [
                    'id_empresa' => (int)$row['id_empresa'],
                    'id_sucursal' => (int)$row['id_sucursal'],
                    'id_almacen' => (int)$row['id_almacen'],
                ];
            }
        }
    } catch (Throwable $ignored) {
    }

    return $context;
}

function purchase_load_warehouses(PDO $pdo, int $sucursalId): array
{
    try {
        $stmt = $pdo->prepare('SELECT id, nombre FROM almacenes WHERE id_sucursal = ? ORDER BY nombre ASC');
        $stmt->execute([$sucursalId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ignored) {
        return [];
    }
}

function purchase_normalize_date(?string $date): string
{
    $value = trim((string)$date);
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Fecha invalida.');
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function purchase_clean_notes(?string $notes, int $sucursalId, int $almacenId): string
{
    $base = trim((string)$notes);
    $base = preg_replace('/\s*\[Suc:\d+\s+Alm:\d+\]\s*/', ' ', $base ?? '');
    $base = trim((string)$base);
    return trim($base . ' [Suc:' . $sucursalId . ' Alm:' . $almacenId . ']');
}

function purchase_validate_item(array $item): array
{
    $sku = trim((string)($item['sku'] ?? ''));
    $nombre = trim((string)($item['nombre'] ?? ''));
    $categoria = trim((string)($item['categoria'] ?? 'General'));
    $cantidad = (float)($item['cantidad'] ?? 0);
    $costo = (float)($item['costo'] ?? 0);
    $precioVenta = (float)($item['precio_venta'] ?? 0);
    $tipoCosto = strtolower(trim((string)($item['tipo_costo'] ?? 'promedio')));
    if (!in_array($tipoCosto, ['promedio', 'ultimo'], true)) {
        $tipoCosto = 'promedio';
    }

    if ($sku === '') {
        throw new InvalidArgumentException('SKU obligatorio en todos los items.');
    }
    if ($nombre === '') {
        throw new InvalidArgumentException('Nombre obligatorio para el producto ' . $sku . '.');
    }
    if ($cantidad <= 0) {
        throw new InvalidArgumentException('La cantidad del producto ' . $sku . ' debe ser mayor que cero.');
    }
    if ($costo < 0) {
        throw new InvalidArgumentException('El costo del producto ' . $sku . ' no puede ser negativo.');
    }

    return [
        'sku' => $sku,
        'nombre' => $nombre,
        'categoria' => $categoria !== '' ? $categoria : 'General',
        'cantidad' => round($cantidad, 2),
        'costo' => round($costo, 2),
        'precio_venta' => round(max(0, $precioVenta), 2),
        'tipo_costo' => $tipoCosto,
        'subtotal' => round($cantidad * $costo, 2),
    ];
}

function purchase_fetch_product(PDO $pdo, string $sku, int $empresaId): ?array
{
    $stmt = $pdo->prepare('SELECT codigo, nombre, costo, precio, precio_mayorista, categoria, activo FROM productos WHERE codigo = ? AND id_empresa = ? LIMIT 1');
    $stmt->execute([$sku, $empresaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purchase_fetch_stock(PDO $pdo, string $sku, int $almacenId): float
{
    $stmt = $pdo->prepare('SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ? LIMIT 1');
    $stmt->execute([$sku, $almacenId]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function purchase_next_sku(PDO $pdo, int $empresaId, int $sucursalId): array
{
    $prefix = str_repeat((string)$sucursalId, 2);
    $lenPrefix = strlen($prefix);
    $sql = 'SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) FROM productos WHERE codigo LIKE CONCAT(:prefix, "%") AND id_empresa = :emp';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':len' => $lenPrefix, ':prefix' => $prefix, ':emp' => $empresaId]);
    $nextSeq = (int)($stmt->fetchColumn() ?: 0) + 1;
    return ['prefix' => $prefix, 'next_seq' => $nextSeq];
}

function purchase_check_duplicate_invoice(PDO $pdo, string $proveedor, string $numeroFactura, int $empresaId, int $sucursalId, ?int $excludeId = null): ?array
{
    $proveedor = trim($proveedor);
    $numeroFactura = trim($numeroFactura);
    if ($proveedor === '' || $numeroFactura === '') {
        return null;
    }

    $sql = "SELECT id, fecha, total, estado
        FROM compras_cabecera
        WHERE proveedor = ?
          AND numero_factura = ?
          AND id_empresa = ?
          AND id_sucursal = ?
          AND COALESCE(estado, 'PROCESADA') != 'CANCELADA'";
    $params = [$proveedor, $numeroFactura, $empresaId, $sucursalId];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purchase_resolve_header_location(PDO $pdo, array $header): array
{
    $empresaId = (int)($header['id_empresa'] ?? 0);
    $sucursalId = (int)($header['id_sucursal'] ?? 0);
    $almacenId = (int)($header['id_almacen'] ?? 0);

    if ($sucursalId <= 0 || $almacenId <= 0) {
        $loc = purchase_extract_location_from_notes((string)($header['notas'] ?? ''));
        if ($sucursalId <= 0) {
            $sucursalId = (int)$loc['id_sucursal'];
        }
        if ($almacenId <= 0) {
            $almacenId = (int)$loc['id_almacen'];
        }
    }
    if ($empresaId <= 0 && $sucursalId > 0) {
        $stmt = $pdo->prepare('SELECT id_empresa FROM sucursales WHERE id = ? LIMIT 1');
        $stmt->execute([$sucursalId]);
        $empresaId = (int)($stmt->fetchColumn() ?: 1);
    }

    return [
        'id_empresa' => max(1, $empresaId),
        'id_sucursal' => max(1, $sucursalId),
        'id_almacen' => max(1, $almacenId),
    ];
}

function purchase_load_purchase_header(PDO $pdo, int $purchaseId, int $empresaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM compras_cabecera WHERE id = ? AND id_empresa = ? LIMIT 1');
    $stmt->execute([$purchaseId, $empresaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $fallback = $pdo->prepare('SELECT * FROM compras_cabecera WHERE id = ? LIMIT 1');
        $fallback->execute([$purchaseId]);
        $row = $fallback->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        throw new RuntimeException('Compra no encontrada.');
    }
    $row += purchase_resolve_header_location($pdo, $row);
    if ((int)$row['id_empresa'] !== (int)$empresaId) {
        throw new RuntimeException('La compra no pertenece al contexto activo.');
    }
    return $row;
}

function purchase_recalculate_header_totals(PDO $pdo, int $purchaseId): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM compras_detalle WHERE id_compra = ? AND COALESCE(estado_item, 'ACTIVO') = 'ACTIVO'");
    $stmt->execute([$purchaseId]);
    $currentTotal = (float)$stmt->fetchColumn();

    $stmtOrig = $pdo->prepare('SELECT total_original FROM compras_cabecera WHERE id = ? LIMIT 1');
    $stmtOrig->execute([$purchaseId]);
    $original = $stmtOrig->fetchColumn();
    $update = $pdo->prepare('UPDATE compras_cabecera SET total = ?, total_original = COALESCE(total_original, ?), updated_at = NOW() WHERE id = ?');
    $update->execute([$currentTotal, $original !== false ? $original : $currentTotal, $purchaseId]);
}

function purchase_save_purchase(PDO $pdo, array $payload, array $context): array
{
    purchase_require_permission('create');

    $estado = strtoupper(trim((string)($payload['estado'] ?? 'PROCESADA')));
    if (!in_array($estado, ['BORRADOR', 'PENDIENTE', 'PROCESADA'], true)) {
        $estado = 'PROCESADA';
    }

    $empresaId = (int)$context['id_empresa'];
    $sucursalId = (int)$context['id_sucursal'];
    $almacenId = (int)$context['id_almacen'];
    $actor = purchase_actor_label();
    $fechaCompra = purchase_normalize_date((string)($payload['fecha'] ?? ''));
    $proveedor = trim((string)($payload['proveedor'] ?? 'Proveedor General'));
    $numeroFactura = trim((string)($payload['factura'] ?? ''));
    $facturaAdjunto = trim((string)($payload['factura_adjunto'] ?? ''));
    $duplicatedFrom = (int)($payload['duplicated_from_id'] ?? 0);
    $forceDuplicate = !empty($payload['force_duplicate_invoice']);

    $itemsIn = $payload['items'] ?? [];
    if (!is_array($itemsIn) || count($itemsIn) === 0) {
        throw new RuntimeException('La compra no puede guardarse sin items.');
    }

    $duplicateInvoice = purchase_check_duplicate_invoice($pdo, $proveedor, $numeroFactura, $empresaId, $sucursalId);
    if ($duplicateInvoice && !$forceDuplicate) {
        return [
            'status' => 'confirm_duplicate_invoice',
            'msg' => 'Ya existe una compra con esa factura para este proveedor en esta sucursal.',
            'duplicate' => $duplicateInvoice,
        ];
    }

    $validatedItems = [];
    $total = 0.0;
    foreach ($itemsIn as $item) {
        $validated = purchase_validate_item((array)$item);
        $validatedItems[] = $validated;
        $total += $validated['subtotal'];
    }
    $total = round($total, 2);

    $notas = purchase_clean_notes((string)($payload['notas'] ?? ''), $sucursalId, $almacenId);

    $pdo->beginTransaction();
    $kardex = new KardexEngine($pdo);

    $stmtInsertHead = $pdo->prepare(
        'INSERT INTO compras_cabecera (proveedor, total, total_original, usuario, created_by, notas, fecha, numero_factura, estado, id_empresa, id_sucursal, id_almacen, duplicated_from_id, factura_adjunto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtInsertHead->execute([
        $proveedor !== '' ? $proveedor : 'Proveedor General',
        $total,
        $total,
        $actor,
        $actor,
        $notas,
        $fechaCompra,
        $numeroFactura,
        $estado,
        $empresaId,
        $sucursalId,
        $almacenId,
        $duplicatedFrom > 0 ? $duplicatedFrom : null,
        $facturaAdjunto !== '' ? $facturaAdjunto : null,
    ]);
    $purchaseId = (int)$pdo->lastInsertId();

    $stmtInsertDetail = $pdo->prepare(
        'INSERT INTO compras_detalle (id_compra, id_producto, cantidad, costo_unitario, subtotal, costo_anterior, costo_resultante, stock_antes, stock_despues, estado_item, revertido_at, revertido_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmtInsertProduct = $pdo->prepare(
        'INSERT INTO productos (codigo, nombre, costo, precio, precio_mayorista, categoria, activo, id_empresa) VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmtUpdateProductCost = $pdo->prepare('UPDATE productos SET costo = ?, nombre = ?, categoria = ?, precio = ?, precio_mayorista = ? WHERE codigo = ? AND id_empresa = ?');

    foreach ($validatedItems as $item) {
        $existing = purchase_fetch_product($pdo, $item['sku'], $empresaId);
        $costoAnterior = $existing ? (float)$existing['costo'] : null;
        $stockAntes = purchase_fetch_stock($pdo, $item['sku'], $almacenId);
        $stockDespues = $stockAntes;
        $costoResultante = $item['costo'];
        $estadoItem = 'ACTIVO';

        if (!$existing) {
            $precioMayorista = $item['precio_venta'] > 0 ? round($item['precio_venta'] * 0.95, 2) : round($item['costo'] * 1.10, 2);
            $stmtInsertProduct->execute([
                $item['sku'],
                $item['nombre'],
                $item['costo'],
                $item['precio_venta'],
                $precioMayorista,
                $item['categoria'],
                $empresaId,
            ]);
        }

        if ($estado === 'PROCESADA') {
            if ($item['tipo_costo'] === 'promedio' && $stockAntes > 0) {
                $baseCost = $existing ? (float)$existing['costo'] : $item['costo'];
                $costoResultante = round((($stockAntes * $baseCost) + ($item['cantidad'] * $item['costo'])) / ($stockAntes + $item['cantidad']), 4);
            }
            $stockDespues = $stockAntes + $item['cantidad'];
            $precioMayorista = $item['precio_venta'] > 0 ? round($item['precio_venta'] * 0.95, 2) : round($costoResultante * 1.10, 2);
            $stmtUpdateProductCost->execute([
                $costoResultante,
                $item['nombre'],
                $item['categoria'],
                max(0, $item['precio_venta']),
                $precioMayorista,
                $item['sku'],
                $empresaId,
            ]);

            $reference = 'COMPRA #' . $purchaseId . ($numeroFactura !== '' ? ' (' . $numeroFactura . ')' : '');
            $kardex->registrarMovimiento(
                $item['sku'],
                $almacenId,
                $sucursalId,
                'ENTRADA',
                $item['cantidad'],
                $reference,
                $item['costo'],
                $actor,
                $fechaCompra
            );
        } elseif ($estado === 'BORRADOR') {
            $estadoItem = 'BORRADOR';
            $stockAntes = null;
            $stockDespues = null;
            $costoResultante = null;
        } else {
            $estadoItem = 'PENDIENTE';
            $stockAntes = null;
            $stockDespues = null;
            $costoResultante = null;
        }

        $stmtInsertDetail->execute([
            $purchaseId,
            $item['sku'],
            $item['cantidad'],
            $item['costo'],
            $item['subtotal'],
            $costoAnterior,
            $costoResultante,
            $stockAntes,
            $stockDespues,
            $estadoItem,
            null,
            null,
        ]);
    }

    $pdo->commit();

    $auditAction = match ($estado) {
        'BORRADOR' => 'COMPRA_BORRADOR_GUARDADO',
        'PENDIENTE' => 'LISTA_COMPRA_GUARDADA',
        default => 'COMPRA_REGISTRADA',
    };
    log_audit($pdo, $auditAction, $actor, ['id' => $purchaseId, 'estado' => $estado, 'total' => $total]);

    return [
        'status' => 'success',
        'msg' => match ($estado) {
            'BORRADOR' => 'Borrador guardado #' . $purchaseId,
            'PENDIENTE' => 'Lista guardada #' . $purchaseId,
            default => 'Compra registrada #' . $purchaseId,
        },
        'id' => $purchaseId,
    ];
}

function purchase_process_pending(PDO $pdo, int $purchaseId, array $context): array
{
    purchase_require_permission('process');

    $actor = purchase_actor_label();
    $header = purchase_load_purchase_header($pdo, $purchaseId, (int)$context['id_empresa']);
    if (($header['estado'] ?? '') !== 'PENDIENTE') {
        throw new RuntimeException('Esta compra no esta en estado pendiente.');
    }

    $empresaId = (int)$header['id_empresa'];
    $sucursalId = (int)$header['id_sucursal'];
    $almacenId = (int)$header['id_almacen'];

    $stmtItems = $pdo->prepare("SELECT * FROM compras_detalle WHERE id_compra = ? AND COALESCE(estado_item, 'ACTIVO') IN ('ACTIVO', 'PENDIENTE', 'BORRADOR') ORDER BY id ASC");
    $stmtItems->execute([$purchaseId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new RuntimeException('La compra no tiene items activos para procesar.');
    }

    $pdo->beginTransaction();
    $kardex = new KardexEngine($pdo);
    $stmtUpdateProduct = $pdo->prepare('UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?');
    $stmtUpdateDetail = $pdo->prepare('UPDATE compras_detalle SET costo_anterior = ?, costo_resultante = ?, stock_antes = ?, stock_despues = ?, estado_item = ? WHERE id = ?');

    foreach ($items as $item) {
        $sku = (string)$item['id_producto'];
        $cantidad = (float)$item['cantidad'];
        $costoNuevo = (float)$item['costo_unitario'];
        $existing = purchase_fetch_product($pdo, $sku, $empresaId);
        $costoAnterior = $existing ? (float)$existing['costo'] : null;
        $stockAntes = purchase_fetch_stock($pdo, $sku, $almacenId);
        $stockDespues = $stockAntes + $cantidad;

        $costoResultante = $costoNuevo;
        if ($stockAntes > 0) {
            $baseCost = $existing ? (float)$existing['costo'] : $costoNuevo;
            $costoResultante = round((($stockAntes * $baseCost) + ($cantidad * $costoNuevo)) / ($stockAntes + $cantidad), 4);
        }

        $stmtUpdateProduct->execute([$costoResultante, $sku, $empresaId]);
        $kardex->registrarMovimiento(
            $sku,
            $almacenId,
            $sucursalId,
            'ENTRADA',
            $cantidad,
            'COMPRA #' . $purchaseId . ' (PROCESADA DESDE LISTA)',
            $costoNuevo,
            $actor,
            $header['fecha'] ?: date('Y-m-d H:i:s')
        );
        $stmtUpdateDetail->execute([$costoAnterior, $costoResultante, $stockAntes, $stockDespues, 'ACTIVO', (int)$item['id']]);
    }

    $pdo->prepare("UPDATE compras_cabecera SET estado = 'PROCESADA', updated_at = NOW() WHERE id = ?")->execute([$purchaseId]);
    purchase_recalculate_header_totals($pdo, $purchaseId);
    $pdo->commit();

    log_audit($pdo, 'COMPRA_PENDIENTE_PROCESADA', $actor, ['id' => $purchaseId]);
    return ['status' => 'success', 'msg' => 'Lista #' . $purchaseId . ' convertida en compra real.'];
}

function purchase_revert_item(PDO $pdo, int $purchaseId, int $detailId, array $context): array
{
    purchase_require_permission('revert');

    $actor = purchase_actor_label();
    $header = purchase_load_purchase_header($pdo, $purchaseId, (int)$context['id_empresa']);
    if (($header['estado'] ?? '') === 'CANCELADA') {
        throw new RuntimeException('Esta compra ya fue cancelada.');
    }
    if (($header['estado'] ?? '') === 'BORRADOR') {
        throw new RuntimeException('Un borrador no tiene stock aplicado para revertir.');
    }

    $stmt = $pdo->prepare("SELECT * FROM compras_detalle WHERE id = ? AND id_compra = ? AND COALESCE(estado_item, 'ACTIVO') = 'ACTIVO' LIMIT 1");
    $stmt->execute([$detailId, $purchaseId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new RuntimeException('Detalle no encontrado o ya revertido.');
    }

    $pdo->beginTransaction();
    if (($header['estado'] ?? '') === 'PROCESADA') {
        $kardex = new KardexEngine($pdo);
        $kardex->registrarMovimiento(
            (string)$item['id_producto'],
            (int)$header['id_almacen'],
            (int)$header['id_sucursal'],
            'SALIDA',
            -abs((float)$item['cantidad']),
            'REVERSION PARCIAL COMPRA #' . $purchaseId . ' (Item #' . $detailId . ')',
            (float)$item['costo_unitario'],
            $actor,
            date('Y-m-d H:i:s')
        );
    }

    $pdo->prepare("UPDATE compras_detalle SET estado_item = 'REVERTIDO', revertido_at = NOW(), revertido_by = ?, stock_despues = ?, costo_resultante = COALESCE(costo_resultante, costo_unitario) WHERE id = ?")
        ->execute([$actor, purchase_fetch_stock($pdo, (string)$item['id_producto'], (int)$header['id_almacen']), $detailId]);

    $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM compras_detalle WHERE id_compra = ? AND COALESCE(estado_item, 'ACTIVO') = 'ACTIVO'");
    $stmtActive->execute([$purchaseId]);
    $activeCount = (int)$stmtActive->fetchColumn();
    $newStatus = $activeCount > 0 ? (($header['estado'] ?? '') === 'PENDIENTE' ? 'PENDIENTE' : 'PROCESADA') : 'CANCELADA';
    $pdo->prepare('UPDATE compras_cabecera SET estado = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $purchaseId]);
    purchase_recalculate_header_totals($pdo, $purchaseId);
    $pdo->commit();

    log_audit($pdo, 'COMPRA_ITEM_REVERTIDO', $actor, ['id_compra' => $purchaseId, 'id_detalle' => $detailId, 'sku' => $item['id_producto']]);
    return ['status' => 'success', 'msg' => 'Producto revertido correctamente.'];
}

function purchase_cancel(PDO $pdo, int $purchaseId, array $context): array
{
    purchase_require_permission('revert');

    $actor = purchase_actor_label();
    $header = purchase_load_purchase_header($pdo, $purchaseId, (int)$context['id_empresa']);
    if (($header['estado'] ?? '') === 'CANCELADA') {
        throw new RuntimeException('Esta compra ya fue cancelada.');
    }

    $stmt = $pdo->prepare("SELECT * FROM compras_detalle WHERE id_compra = ? AND COALESCE(estado_item, 'ACTIVO') = 'ACTIVO' ORDER BY id ASC");
    $stmt->execute([$purchaseId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    if (($header['estado'] ?? '') === 'PROCESADA') {
        $kardex = new KardexEngine($pdo);
        foreach ($items as $item) {
            $kardex->registrarMovimiento(
                (string)$item['id_producto'],
                (int)$header['id_almacen'],
                (int)$header['id_sucursal'],
                'SALIDA',
                -abs((float)$item['cantidad']),
                'CANCELACION COMPRA #' . $purchaseId,
                (float)$item['costo_unitario'],
                $actor,
                date('Y-m-d H:i:s')
            );
        }
    }

    $pdo->prepare("UPDATE compras_detalle SET estado_item = 'REVERTIDO', revertido_at = NOW(), revertido_by = ? WHERE id_compra = ? AND COALESCE(estado_item, 'ACTIVO') IN ('ACTIVO', 'PENDIENTE', 'BORRADOR')")
        ->execute([$actor, $purchaseId]);
    $pdo->prepare("UPDATE compras_cabecera SET estado = 'CANCELADA', total = 0, updated_at = NOW() WHERE id = ?")->execute([$purchaseId]);
    $pdo->commit();

    log_audit($pdo, 'COMPRA_CANCELADA', $actor, ['id' => $purchaseId]);
    return ['status' => 'success', 'msg' => 'Compra cancelada correctamente.'];
}

function purchase_handle_attachment_upload(): array
{
    purchase_require_permission('upload');

    if (empty($_FILES['attachment']) || !is_uploaded_file($_FILES['attachment']['tmp_name'])) {
        throw new RuntimeException('No se recibio ningun archivo valido.');
    }

    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Error subiendo el adjunto. Codigo: ' . (int)$file['error']);
    }

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Solo se permiten PDF, JPG, PNG o WEBP.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('El adjunto excede el maximo de 5 MB.');
    }

    if (!is_dir(PURCHASE_UPLOAD_DIR) && !mkdir(PURCHASE_UPLOAD_DIR, 0775, true) && !is_dir(PURCHASE_UPLOAD_DIR)) {
        throw new RuntimeException('No se pudo crear la carpeta de adjuntos.');
    }

    $name = 'compra_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = PURCHASE_UPLOAD_DIR . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('No se pudo guardar el adjunto.');
    }

    return [
        'status' => 'success',
        'path' => PURCHASE_UPLOAD_WEB . '/' . $name,
        'name' => $name,
    ];
}

function purchase_load_history(PDO $pdo, array $filters, int $empresaId): array
{
    $where = ['COALESCE(c.id_empresa, 0) IN (0, ?)'];
    $params = [$empresaId];

    if (!empty($filters['fecha_desde'])) {
        $where[] = 'c.fecha >= ?';
        $params[] = trim((string)$filters['fecha_desde']) . ' 00:00:00';
    }
    if (!empty($filters['fecha_hasta'])) {
        $where[] = 'c.fecha <= ?';
        $params[] = trim((string)$filters['fecha_hasta']) . ' 23:59:59';
    }
    if (!empty($filters['estado'])) {
        $where[] = 'COALESCE(c.estado, "PROCESADA") = ?';
        $params[] = trim((string)$filters['estado']);
    }
    if (!empty($filters['proveedor'])) {
        $where[] = 'c.proveedor LIKE ?';
        $params[] = '%' . trim((string)$filters['proveedor']) . '%';
    }
    if (!empty($filters['almacen'])) {
        $where[] = 'COALESCE(c.id_almacen, 0) = ?';
        $params[] = (int)$filters['almacen'];
    }

    $sql = 'SELECT c.* FROM compras_cabecera c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT ' . PURCHASE_HISTORY_LIMIT;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $headers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$headers) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $headers);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $detailSql = "SELECT d.*, p.nombre
        FROM compras_detalle d
        LEFT JOIN productos p ON d.id_producto = p.codigo
        WHERE d.id_compra IN ($placeholders)
        ORDER BY d.id_compra DESC, d.id ASC";
    $detailStmt = $pdo->prepare($detailSql);
    $detailStmt->execute($ids);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($details as $detail) {
        $purchaseId = (int)$detail['id_compra'];
        $grouped[$purchaseId][] = $detail;
    }

    foreach ($headers as &$header) {
        $header += purchase_resolve_header_location($pdo, $header);
        if (!isset($header['estado']) || $header['estado'] === null || $header['estado'] === '') {
            $header['estado'] = 'PROCESADA';
        }
        $header['expanded'] = false;
        $header['details'] = $grouped[(int)$header['id']] ?? [];
    }
    unset($header);

    return $headers;
}

function purchase_load_products(PDO $pdo, int $empresaId, int $almacenId): array
{
    $sql = "SELECT p.codigo, p.nombre, p.costo, p.categoria, p.precio,
                   COALESCE(sa.cantidad, 0) AS stock_actual
            FROM productos p
            LEFT JOIN stock_almacen sa ON sa.id_producto = p.codigo AND sa.id_almacen = ?
            WHERE p.activo = 1 AND p.id_empresa = ?
            ORDER BY p.nombre ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$almacenId, $empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function purchase_load_categories(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT nombre FROM categorias ORDER BY nombre ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: ['General'];
    } catch (Throwable $ignored) {
        return ['General'];
    }
}

try {
    purchase_bootstrap_tables($pdo);
} catch (Throwable $e) {
    error_log('pos_purchases bootstrap error: ' . $e->getMessage());
}

$context = purchase_load_context($pdo, $config);
$EMP_ID = (int)$context['id_empresa'];
$SUC_ID = (int)$context['id_sucursal'];
$ALM_ID = (int)$context['id_almacen'];
$actor = purchase_actor_label();
$permissions = purchase_permissions();
$almacenes = purchase_load_warehouses($pdo, $SUC_ID);
if ($almacenes) {
    $warehouseIds = array_map(static fn(array $row): int => (int)$row['id'], $almacenes);
    if (!in_array($ALM_ID, $warehouseIds, true)) {
        $ALM_ID = (int)$almacenes[0]['id'];
        $context['id_almacen'] = $ALM_ID;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'get_next_sku')) {
    try {
        if (isset($_GET['action']) && $_GET['action'] === 'get_next_sku') {
            purchase_json_response(['status' => 'success'] + purchase_next_sku($pdo, $EMP_ID, $SUC_ID));
        }

        if (isset($_GET['action']) && $_GET['action'] === 'upload_attachment') {
            purchase_json_response(purchase_handle_attachment_upload());
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST ?: [];
        }

        $requestAlmacen = (int)($input['almacen'] ?? 0);
        if ($requestAlmacen > 0) {
            $context['id_almacen'] = $requestAlmacen;
        }

        $action = (string)($input['action'] ?? 'save');
        $result = match ($action) {
            'cancel' => purchase_cancel($pdo, (int)($input['id'] ?? 0), $context),
            'process_pending' => purchase_process_pending($pdo, (int)($input['id'] ?? 0), $context),
            'revert_item' => purchase_revert_item($pdo, (int)($input['id_compra'] ?? 0), (int)($input['id_detalle'] ?? 0), $context),
            default => purchase_save_purchase($pdo, $input, $context),
        };
        purchase_json_response($result);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        purchase_json_response(['status' => 'error', 'msg' => $e->getMessage()], 400);
    }
}

$historyFilters = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'estado' => trim((string)($_GET['estado'] ?? '')),
    'proveedor' => trim((string)($_GET['proveedor'] ?? '')),
    'almacen' => (int)($_GET['almacen'] ?? 0),
];

try {
    $products = purchase_load_products($pdo, $EMP_ID, $ALM_ID);
    $categories = purchase_load_categories($pdo);
    $recentPurchases = purchase_load_history($pdo, $historyFilters, $EMP_ID);
    $historyError = '';
} catch (Throwable $e) {
    $products = [];
    $categories = ['General'];
    $recentPurchases = [];
    $historyError = $e->getMessage();
}

$today = date('Y-m-d');
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Compras POS V3</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        .table thead th {
            white-space: nowrap;
        }
        .history-row-cancelled {
            background: rgba(180,35,24,.06);
        }
        .history-row-pending {
            background: rgba(245,158,11,.10);
        }
        .history-row-draft {
            background: rgba(15,118,110,.06);
        }
        .detail-wrap {
            background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(247,248,250,.92));
            border-left: 4px solid var(--pw-accent);
        }
        .pointer { cursor: pointer; }
        .suggestion-list {
            position: absolute;
            inset: auto 0 auto 0;
            z-index: 10;
            max-height: 280px;
            overflow: auto;
        }
        .summary-total {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
        }
        .tiny { font-size: .78rem; }
        .inventory-hero {
            background: linear-gradient(135deg, <?php echo $config['hero_color_1'] ?? '#0f766e'; ?>ee, <?php echo $config['hero_color_2'] ?? '#15803d'; ?>c6) !important;
        }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div id="app" class="container-fluid shell inventory-shell py-4 py-lg-5">
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Compras / Inventario</div>
                <?php if (!empty($config['hero_mostrar_usuario']) && !empty($_SESSION['admin_user_name'])): ?>
                    <div class="badge bg-white bg-opacity-10 text-white mb-2" style="font-size:0.7rem; border:1px solid rgba(255,255,255,0.2);">
                        <i class="fas fa-user-circle me-1"></i> Sesión: <?php echo htmlspecialchars($_SESSION['admin_user_name']); ?>
                    </div>
                <?php endif; ?>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-dolly-flatbed me-2"></i>Compras POS V3</h1>
                <p class="mb-3 text-white-50">Entrada multi-almacen con borrador local, validacion dura, historial filtrable, duplicado y reversión trazable.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($actor) ?></span>
                    <span class="kpi-chip"><i class="fas fa-building me-1"></i>Sucursal <?= (int)$SUC_ID ?></span>
                    <span class="kpi-chip"><i class="fas fa-warehouse me-1"></i>Almacen <?= (int)$ALM_ID ?></span>
                    <span class="kpi-chip"><i class="fas fa-boxes-stacked me-1"></i>{{ recentStats.totalCompras }} compras en historial</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="pos_import_compras_excel.php" class="btn btn-success"><i class="fas fa-file-excel me-1"></i>Importar Excel</a>
                <button type="button" class="btn btn-light" onclick="openCategoriesModal()"><i class="fas fa-tags me-1"></i>Categorias</button>
                <button type="button" class="btn btn-warning" onclick="openProductCreator(alCrearProducto)"><i class="fas fa-plus me-1"></i>Nuevo producto</button>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <div v-if="flash.msg" class="alert mb-4" :class="flash.ok ? 'alert-success' : 'alert-danger'">
        {{ flash.msg }}
    </div>

    <div class="row g-4 align-items-stretch">
        <div class="col-12 col-xl-4">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Contexto</div>
                        <h2 class="h5 fw-bold mb-0">Cabecera de compra</h2>
                    </div>
                    <span class="soft-pill" v-if="draftMeta.updatedAt"><i class="fas fa-cloud"></i>Borrador local {{ draftMeta.updatedAt }}</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Fecha compra</label>
                        <input type="date" class="form-control" v-model="header.fecha">
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Estado de guardado</label>
                        <select class="form-select" v-model="header.estado">
                            <option value="BORRADOR">Borrador</option>
                            <option value="PENDIENTE">Lista de compra</option>
                            <option value="PROCESADA">Procesada</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Proveedor</label>
                        <input type="text" class="form-control" v-model.trim="header.proveedor" placeholder="Proveedor principal">
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Factura</label>
                        <input type="text" class="form-control" v-model.trim="header.factura" placeholder="F-000123">
                        <div class="tiny text-warning mt-1" v-if="duplicateInvoiceWarning">{{ duplicateInvoiceWarning }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Notas</label>
                        <textarea class="form-control" rows="2" v-model.trim="header.notas" placeholder="Observaciones"></textarea>
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Almacen destino</label>
                        <select class="form-select" v-model.number="almacenActual" @change="changeAlmacen(almacenActual)">
                            <option v-for="alm in almacenes" :value="parseInt(alm.id)">{{ alm.nombre }}</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-12">
                        <label class="form-label small fw-bold">Adjunto de factura</label>
                        <div class="d-flex gap-2">
                            <input ref="attachmentInput" type="file" class="form-control" accept="application/pdf,image/jpeg,image/png,image/webp" @change="uploadAttachment">
                            <button class="btn btn-outline-secondary" type="button" @click="clearAttachment" v-if="header.factura_adjunto"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="tiny mt-1" v-if="header.factura_adjunto">
                            <a :href="header.factura_adjunto" target="_blank" rel="noopener">Ver adjunto actual</a>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-outline-secondary" type="button" @click="saveDraftLocal"><i class="fas fa-save me-1"></i>Guardar borrador local</button>
                    <button class="btn btn-outline-danger" type="button" @click="clearDraftLocal"><i class="fas fa-trash me-1"></i>Limpiar borrador local</button>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="glass-card p-4 h-100 inventory-fade-in">
                <div class="row g-4">
                    <div class="col-12 col-lg-5">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="section-title">Items</div>
                                <h2 class="h5 fw-bold mb-0">Agregar producto</h2>
                            </div>
                            <span class="soft-pill" v-if="isExisting"><i class="fas fa-link"></i>Producto existente</span>
                            <span class="soft-pill" v-else><i class="fas fa-sparkles"></i>Alta rapida</span>
                        </div>

                        <div class="mb-3 position-relative">
                            <label class="form-label small fw-bold">Buscar por SKU o nombre</label>
                            <input type="text" class="form-control" v-model.trim="search" @input="filterProducts" placeholder="660012 o Dona glaseada" autocomplete="off">
                            <div class="suggestion-list glass-card p-2" v-if="filteredProds.length">
                                <button type="button" class="list-group-item list-group-item-action border rounded-3 mb-1" v-for="p in filteredProds" :key="p.codigo" @click="selectProd(p)">
                                    <div class="fw-semibold">{{ p.nombre }}</div>
                                    <div class="tiny text-muted d-flex justify-content-between">
                                        <span>{{ p.codigo }}</span>
                                        <span>Stock actual: {{ numberFmt(p.stock_actual) }}</span>
                                    </div>
                                </button>
                            </div>
                            <div class="tiny text-primary mt-2 pointer" v-if="search.length > 2 && !filteredProds.length" @click="setNewProduct">
                                <i class="fas fa-wand-magic-sparkles me-1"></i>Crear nuevo SKU automatico para "{{ search }}"
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-5"><label class="form-label small fw-bold">SKU</label><input type="text" class="form-control" v-model.trim="form.sku" :readonly="isExisting"></div>
                            <div class="col-7"><label class="form-label small fw-bold">Nombre</label><input type="text" class="form-control" v-model.trim="form.nombre" :readonly="isExisting"></div>
                            <div class="col-4"><label class="form-label small fw-bold">Cantidad</label><input type="number" class="form-control" v-model.number="form.cantidad" min="0.01" step="0.01"></div>
                            <div class="col-4"><label class="form-label small fw-bold">Costo</label><input type="number" class="form-control" v-model.number="form.costo" min="0" step="0.01"></div>
                            <div class="col-4"><label class="form-label small fw-bold">Venta</label><input type="number" class="form-control" v-model.number="form.precio_venta" min="0" step="0.01"></div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Categoria</label>
                                <select class="form-select" v-model="form.categoria">
                                    <option v-for="cat in categories" :value="cat">{{ cat }}</option>
                                </select>
                            </div>
                            <div class="col-md-6" v-if="isExisting">
                                <label class="form-label small fw-bold">Politica de costo</label>
                                <select class="form-select" v-model="form.tipo_costo">
                                    <option value="promedio">Promedio ponderado</option>
                                    <option value="ultimo">Ultimo costo</option>
                                </select>
                            </div>
                        </div>

                        <div class="stat-box mb-3">
                            <div class="d-flex justify-content-between tiny text-muted mb-1"><span>Stock antes</span><span>{{ numberFmt(costPreview.stockAntes) }}</span></div>
                            <div class="d-flex justify-content-between tiny text-muted mb-1"><span>Movimiento</span><span>+{{ numberFmt(form.cantidad) }}</span></div>
                            <div class="d-flex justify-content-between tiny text-muted mb-1"><span>Stock despues</span><span>{{ numberFmt(costPreview.stockDespues) }}</span></div>
                            <div class="d-flex justify-content-between fw-semibold"><span>Costo resultante</span><span>${{ numberFmt(costPreview.costoResultante) }}</span></div>
                        </div>

                        <button class="btn btn-success w-100 fw-bold" type="button" @click="addItem"><i class="fas fa-plus me-1"></i>Agregar al carrito</button>
                    </div>

                    <div class="col-12 col-lg-7">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="section-title">Resumen</div>
                                <h2 class="h5 fw-bold mb-0">Compra en armado</h2>
                            </div>
                            <span class="soft-pill"><i class="fas fa-layer-group me-1"></i>{{ cart.length }} lineas</span>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="tiny text-muted">Total actual</div>
                                    <div class="summary-total">${{ numberFmt(totalCart) }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="tiny text-muted">Items activos</div>
                                    <div class="summary-total">{{ cart.length }}</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-box">
                                    <div class="tiny text-muted">SKU unicos</div>
                                    <div class="summary-total">{{ uniqueSkuCount }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive border rounded-4 bg-white mb-3" style="max-height: 390px; overflow:auto;">
                            <table class="table align-middle mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>SKU</th>
                                        <th>Producto</th>
                                        <th class="text-end">Cant</th>
                                        <th class="text-end">Costo</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-center">Accion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!cart.length">
                                        <td colspan="6" class="text-center py-5 text-muted">No hay items agregados.</td>
                                    </tr>
                                    <tr v-for="(item, idx) in cart" :key="item.sku + '-' + idx">
                                        <td class="tiny">{{ item.sku }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ item.nombre }}</div>
                                            <div class="tiny text-muted">{{ item.categoria }} · {{ item.tipo_costo }}</div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ numberFmt(item.cantidad) }}</td>
                                        <td class="text-end">${{ numberFmt(item.costo) }}</td>
                                        <td class="text-end fw-bold">${{ numberFmt(item.subtotal || (item.cantidad * item.costo)) }}</td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" @click="editCartItem(idx)"><i class="fas fa-pen"></i></button>
                                            <button class="btn btn-sm btn-outline-danger" type="button" @click="removeCartItem(idx)"><i class="fas fa-times"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <div class="tiny text-muted" v-if="duplicatedFromId">Duplicando compra #{{ duplicatedFromId }}</div>
                            <div class="btn-group flex-wrap">
                                <button class="btn btn-outline-secondary" type="button" @click="submitPurchase('BORRADOR')" :disabled="!canCreate || isProcessing || !cart.length">Guardar en servidor</button>
                                <button class="btn btn-outline-warning" type="button" @click="submitPurchase('PENDIENTE')" :disabled="!canCreate || isProcessing || !cart.length">Guardar lista</button>
                                <button class="btn btn-primary" type="button" @click="submitPurchase('PROCESADA')" :disabled="!canCreate || isProcessing || !cart.length">Procesar compra</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="glass-card p-4 mt-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3 align-items-lg-end">
            <div>
                <div class="section-title">Historial</div>
                <h2 class="h5 fw-bold mb-0">Compras recientes</h2>
                <div class="tiny text-muted" v-if="historyErr">{{ historyErr }}</div>
            </div>
            <div class="row g-2 w-100 justify-content-lg-end">
                <div class="col-6 col-lg-2"><label class="tiny text-muted d-block mb-1">Desde</label><input type="date" class="form-control form-control-sm" v-model="filters.fecha_desde"></div>
                <div class="col-6 col-lg-2"><label class="tiny text-muted d-block mb-1">Hasta</label><input type="date" class="form-control form-control-sm" v-model="filters.fecha_hasta"></div>
                <div class="col-6 col-lg-2"><label class="tiny text-muted d-block mb-1">Proveedor</label><input type="text" class="form-control form-control-sm" v-model.trim="filters.proveedor" placeholder="Buscar"></div>
                <div class="col-6 col-lg-2"><label class="tiny text-muted d-block mb-1">Estado</label>
                    <select class="form-select form-select-sm" v-model="filters.estado">
                        <option value="">Todos</option>
                        <option value="BORRADOR">Borrador</option>
                        <option value="PENDIENTE">Pendiente</option>
                        <option value="PROCESADA">Procesada</option>
                        <option value="CANCELADA">Cancelada</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><label class="tiny text-muted d-block mb-1">Almacen</label>
                    <select class="form-select form-select-sm" v-model.number="filters.almacen">
                        <option :value="0">Todos</option>
                        <option v-for="alm in almacenes" :value="parseInt(alm.id)">{{ alm.nombre }}</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2 d-flex align-items-end gap-2">
                    <button class="btn btn-sm btn-outline-secondary w-100" type="button" @click="resetFilters">Limpiar</button>
                    <button class="btn btn-sm btn-outline-dark w-100" type="button" @click="reloadPage"><i class="fas fa-rotate"></i></button>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px"></th>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Proveedor</th>
                        <th>Factura</th>
                        <th>Ubicacion</th>
                        <th>Estado</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody v-if="!filteredHistory.length">
                    <tr><td colspan="9" class="text-center py-5 text-muted">No hay compras que coincidan con los filtros.</td></tr>
                </tbody>
                <tbody v-for="compra in filteredHistory" :key="compra.id" :class="historyRowClass(compra)">
                    <tr class="pointer" @click="toggleDetails(compra)">
                        <td class="text-center"><i class="fas" :class="compra.expanded ? 'fa-chevron-up' : 'fa-chevron-down'"></i></td>
                        <td class="fw-bold">#{{ compra.id }}</td>
                        <td>{{ formatDate(compra.fecha) }}</td>
                        <td>{{ compra.proveedor || 'General' }}</td>
                        <td>
                            <div>{{ compra.numero_factura || 'S/N' }}</div>
                            <div class="tiny" v-if="compra.factura_adjunto"><a :href="compra.factura_adjunto" target="_blank" rel="noopener" @click.stop>Adjunto</a></div>
                        </td>
                        <td class="tiny">Suc {{ compra.id_sucursal }} · Alm {{ compra.id_almacen }}</td>
                        <td><span class="badge" :class="statusBadge(compra.estado)">{{ compra.estado }}</span></td>
                        <td class="text-end fw-bold">${{ numberFmt(compra.total) }}</td>
                        <td class="text-center" @click.stop>
                            <div class="btn-group btn-group-sm flex-wrap justify-content-center">
                                <button class="btn btn-outline-secondary" type="button" @click="duplicatePurchase(compra)" v-if="permissions.duplicate"><i class="fas fa-copy"></i></button>
                                <button class="btn btn-outline-success" type="button" @click="processPending(compra.id)" v-if="compra.estado === 'PENDIENTE' && permissions.process"><i class="fas fa-check"></i></button>
                                <button class="btn btn-outline-danger" type="button" @click="cancelPurchase(compra.id)" v-if="compra.estado !== 'CANCELADA' && compra.estado !== 'BORRADOR' && permissions.revert"><i class="fas fa-undo"></i></button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="compra.expanded">
                        <td colspan="9" class="p-0">
                            <div class="detail-wrap p-3">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-2">
                                    <div class="tiny text-muted">
                                        Registrada por {{ compra.created_by || compra.usuario || 'Sistema' }}
                                        <span v-if="compra.duplicated_from_id">· Duplicada desde #{{ compra.duplicated_from_id }}</span>
                                    </div>
                                    <div class="tiny text-muted">Notas: {{ cleanNotes(compra.notas) || 'Sin notas' }}</div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0 bg-white rounded-3 overflow-hidden">
                                        <thead>
                                            <tr>
                                                <th>SKU</th>
                                                <th>Producto</th>
                                                <th class="text-end">Cant</th>
                                                <th class="text-end">Costo U.</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">Antes</th>
                                                <th class="text-end">Despues</th>
                                                <th class="text-end">Costo final</th>
                                                <th class="text-center">Estado</th>
                                                <th class="text-center">Accion</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="det in compra.details" :key="det.id" :class="{'table-danger': detailIsReverted(det)}">
                                                <td class="tiny">{{ det.id_producto }}</td>
                                                <td>{{ det.nombre || 'Producto eliminado' }}</td>
                                                <td class="text-end">{{ numberFmt(det.cantidad) }}</td>
                                                <td class="text-end">${{ numberFmt(det.costo_unitario) }}</td>
                                                <td class="text-end">${{ numberFmt(det.subtotal) }}</td>
                                                <td class="text-end tiny text-muted">{{ nullableNumber(det.stock_antes) }}</td>
                                                <td class="text-end tiny text-muted">{{ nullableNumber(det.stock_despues) }}</td>
                                                <td class="text-end tiny text-muted">{{ nullableMoney(det.costo_resultante) }}</td>
                                                <td class="text-center"><span class="badge bg-light text-dark border">{{ det.estado_item || 'ACTIVO' }}</span></td>
                                                <td class="text-center">
                                                    <button class="btn btn-outline-warning btn-sm" type="button" @click.stop="revertItem(compra.id, det.id, det)" v-if="canRevertDetail(compra, det)"><i class="fas fa-undo"></i></button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
const initialProducts = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const initialCategories = <?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const initialHistory = <?= json_encode($recentPurchases, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const initialFilters = <?= json_encode($historyFilters, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const initialWarehouses = <?= json_encode($almacenes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const initialPermissions = <?= json_encode($permissions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const historyErr = <?= json_encode($historyError, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const today = <?= json_encode($today) ?>;
const defaultWarehouse = <?= (int)$ALM_ID ?>;
const draftStorageKey = 'pos_purchases_v3_draft_' + defaultWarehouse;

const app = new Vue({
    el: '#app',
    data: {
        products: initialProducts,
        categories: initialCategories,
        recentList: initialHistory,
        almacenes: initialWarehouses,
        permissions: initialPermissions,
        historyErr,
        search: '',
        filteredProds: [],
        isExisting: false,
        isProcessing: false,
        duplicateInvoiceWarning: '',
        duplicatedFromId: null,
        flash: { ok: true, msg: '' },
        draftMeta: { updatedAt: '' },
        filters: {
            fecha_desde: initialFilters.fecha_desde || '',
            fecha_hasta: initialFilters.fecha_hasta || '',
            estado: initialFilters.estado || '',
            proveedor: initialFilters.proveedor || '',
            almacen: parseInt(initialFilters.almacen || 0)
        },
        almacenActual: defaultWarehouse,
        form: {
            sku: '', nombre: '', cantidad: 1, costo: 0, precio_venta: 0, categoria: initialCategories[0] || 'General', tipo_costo: 'promedio', stock_actual: 0
        },
        header: {
            fecha: today,
            proveedor: '',
            factura: '',
            notas: '',
            estado: 'PROCESADA',
            factura_adjunto: ''
        },
        cart: []
    },
    computed: {
        totalCart() {
            return this.cart.reduce((sum, item) => sum + Number(item.subtotal || (item.cantidad * item.costo) || 0), 0);
        },
        uniqueSkuCount() {
            return new Set(this.cart.map(item => item.sku)).size;
        },
        filteredHistory() {
            return this.recentList.filter((compra) => {
                if (this.filters.fecha_desde && (compra.fecha || '').substring(0, 10) < this.filters.fecha_desde) return false;
                if (this.filters.fecha_hasta && (compra.fecha || '').substring(0, 10) > this.filters.fecha_hasta) return false;
                if (this.filters.estado && compra.estado !== this.filters.estado) return false;
                if (this.filters.proveedor) {
                    const needle = this.filters.proveedor.toLowerCase();
                    if (!(String(compra.proveedor || '').toLowerCase().includes(needle) || String(compra.numero_factura || '').toLowerCase().includes(needle))) return false;
                }
                if (this.filters.almacen && parseInt(compra.id_almacen || 0) !== parseInt(this.filters.almacen)) return false;
                return true;
            });
        },
        recentStats() {
            const totalCompras = this.filteredHistory.length;
            const totalImporte = this.filteredHistory.reduce((sum, compra) => sum + Number(compra.total || 0), 0);
            return { totalCompras, totalImporte };
        },
        canCreate() {
            return !!this.permissions.create;
        },
        costPreview() {
            const stockAntes = Number(this.form.stock_actual || 0);
            const cantidad = Number(this.form.cantidad || 0);
            const costoNuevo = Number(this.form.costo || 0);
            const stockDespues = stockAntes + cantidad;
            let costoResultante = costoNuevo;
            if (this.isExisting && this.form.tipo_costo === 'promedio' && stockAntes > 0 && stockDespues > 0) {
                const current = Number(this.selectedProductCost());
                costoResultante = ((stockAntes * current) + (cantidad * costoNuevo)) / stockDespues;
            }
            return { stockAntes, stockDespues, costoResultante };
        }
    },
    watch: {
        cart: { handler() { this.persistDraftSnapshot(); }, deep: true },
        header: { handler() { this.persistDraftSnapshot(); this.updateDuplicateInvoiceWarning(); }, deep: true },
        almacenActual(value) {
            const key = 'pos_purchases_v3_draft_' + value;
            localStorage.setItem('pos_purchases_v3_last_warehouse', String(value));
            this.draftMeta.updatedAt = localStorage.getItem(key + '_updated') || '';
        }
    },
    mounted() {
        const rememberedWarehouse = parseInt(localStorage.getItem('pos_purchases_v3_last_warehouse') || defaultWarehouse);
        if (this.almacenes.some(alm => parseInt(alm.id) === rememberedWarehouse)) {
            this.almacenActual = rememberedWarehouse;
        }
        this.restoreDraftIfAny();
        this.updateDuplicateInvoiceWarning();
    },
    methods: {
        numberFmt(value) {
            const num = Number(value || 0);
            return num.toFixed(2);
        },
        nullableNumber(value) {
            if (value === null || value === undefined || value === '') return '-';
            return this.numberFmt(value);
        },
        nullableMoney(value) {
            if (value === null || value === undefined || value === '') return '-';
            return '$' + this.numberFmt(value);
        },
        cleanNotes(notes) {
            return String(notes || '').replace(/\s*\[Suc:\d+\s+Alm:\d+\]\s*/g, ' ').trim();
        },
        statusBadge(status) {
            return {
                'bg-success': status === 'PROCESADA',
                'bg-warning text-dark': status === 'PENDIENTE',
                'bg-danger': status === 'CANCELADA',
                'bg-secondary': status === 'BORRADOR'
            };
        },
        historyRowClass(compra) {
            return {
                'history-row-cancelled': compra.estado === 'CANCELADA',
                'history-row-pending': compra.estado === 'PENDIENTE',
                'history-row-draft': compra.estado === 'BORRADOR'
            };
        },
        detailIsReverted(det) {
            return (det.estado_item || 'ACTIVO') === 'REVERTIDO';
        },
        canRevertDetail(compra, det) {
            return !!this.permissions.revert && compra.estado === 'PROCESADA' && !this.detailIsReverted(det);
        },
        formatDate(d) {
            if (!d) return '-';
            const parsed = new Date(String(d).replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) return d;
            return parsed.toLocaleDateString('es-ES') + ' ' + parsed.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        },
        selectedProductCost() {
            const product = this.products.find(prod => prod.codigo === this.form.sku);
            return product ? Number(product.costo || 0) : Number(this.form.costo || 0);
        },
        filterProducts() {
            if (this.search.length < 2) {
                this.filteredProds = [];
                return;
            }
            const q = this.search.toLowerCase();
            this.filteredProds = this.products.filter(p =>
                String(p.codigo).toLowerCase().includes(q) || String(p.nombre).toLowerCase().includes(q)
            ).slice(0, 8);
        },
        selectProd(p) {
            this.form.sku = p.codigo;
            this.form.nombre = p.nombre;
            this.form.costo = Number(p.costo || 0);
            this.form.precio_venta = Number(p.precio || 0);
            this.form.categoria = p.categoria || (this.categories[0] || 'General');
            this.form.tipo_costo = 'promedio';
            this.form.stock_actual = Number(p.stock_actual || 0);
            this.isExisting = true;
            this.search = '';
            this.filteredProds = [];
        },
        async setNewProduct() {
            this.resetForm();
            this.form.nombre = this.search;
            this.form.sku = 'Generando...';
            try {
                const res = await fetch('pos_purchases.php?action=get_next_sku');
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo generar SKU');
                let seq = parseInt(data.next_seq, 10);
                const prefix = data.prefix;
                const build = (n) => prefix + String(n).padStart(4, '0');
                let candidate = build(seq);
                while (this.cart.some(item => item.sku === candidate)) {
                    seq += 1;
                    candidate = build(seq);
                }
                this.form.sku = candidate;
                this.isExisting = false;
                this.filteredProds = [];
                this.search = '';
            } catch (error) {
                this.form.sku = '';
                this.raise(error.message || 'No se pudo generar el SKU');
            }
        },
        resetForm() {
            this.form = {
                sku: '', nombre: '', cantidad: 1, costo: 0, precio_venta: 0, categoria: this.categories[0] || 'General', tipo_costo: 'promedio', stock_actual: 0
            };
            this.isExisting = false;
            this.search = '';
            this.filteredProds = [];
        },
        validateFormItem() {
            if (!this.form.sku.trim()) throw new Error('SKU obligatorio');
            if (!this.form.nombre.trim()) throw new Error('Nombre obligatorio');
            if (Number(this.form.cantidad || 0) <= 0) throw new Error('Cantidad debe ser mayor que cero');
            if (Number(this.form.costo || 0) < 0) throw new Error('Costo no puede ser negativo');
        },
        addItem() {
            try {
                this.validateFormItem();
                const payload = {
                    sku: this.form.sku.trim(),
                    nombre: this.form.nombre.trim(),
                    cantidad: Number(this.form.cantidad),
                    costo: Number(this.form.costo),
                    precio_venta: Number(this.form.precio_venta || 0),
                    categoria: this.form.categoria || 'General',
                    tipo_costo: this.form.tipo_costo || 'promedio',
                    subtotal: Number(this.form.cantidad) * Number(this.form.costo),
                    stock_actual: Number(this.form.stock_actual || 0)
                };
                this.cart.push(payload);
                this.resetForm();
            } catch (error) {
                this.raise(error.message);
            }
        },
        editCartItem(index) {
            const item = this.cart[index];
            if (!item) return;
            this.form = { ...item };
            this.isExisting = this.products.some(prod => prod.codigo === item.sku);
            this.cart.splice(index, 1);
        },
        removeCartItem(index) {
            this.cart.splice(index, 1);
        },
        toggleDetails(compra) {
            compra.expanded = !compra.expanded;
            this.$forceUpdate();
        },
        duplicatePurchase(compra) {
            const items = (compra.details || []).filter(det => (det.estado_item || 'ACTIVO') !== 'REVERTIDO').map(det => ({
                sku: det.id_producto,
                nombre: det.nombre || det.id_producto,
                cantidad: Number(det.cantidad),
                costo: Number(det.costo_unitario),
                precio_venta: 0,
                categoria: 'General',
                tipo_costo: 'promedio',
                subtotal: Number(det.subtotal),
                stock_actual: Number(det.stock_despues || 0)
            }));
            if (!items.length) {
                this.raise('La compra no tiene items activos para duplicar.');
                return;
            }
            this.cart = items;
            this.header.proveedor = compra.proveedor || '';
            this.header.factura = '';
            this.header.notas = this.cleanNotes(compra.notas || '');
            this.header.estado = 'BORRADOR';
            this.header.factura_adjunto = '';
            this.duplicatedFromId = compra.id;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        async uploadAttachment(event) {
            const file = event.target.files && event.target.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('attachment', file);
            try {
                const res = await fetch('pos_purchases.php?action=upload_attachment', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo subir el adjunto');
                this.header.factura_adjunto = data.path;
                this.ok('Adjunto cargado correctamente.');
            } catch (error) {
                this.raise(error.message || 'Error subiendo adjunto');
            }
        },
        clearAttachment() {
            this.header.factura_adjunto = '';
            if (this.$refs.attachmentInput) this.$refs.attachmentInput.value = '';
        },
        updateDuplicateInvoiceWarning() {
            if (!this.header.factura || !this.header.proveedor) {
                this.duplicateInvoiceWarning = '';
                return;
            }
            const found = this.recentList.find(compra =>
                compra.estado !== 'CANCELADA' &&
                String(compra.proveedor || '').toLowerCase() === String(this.header.proveedor || '').toLowerCase() &&
                String(compra.numero_factura || '').toLowerCase() === String(this.header.factura || '').toLowerCase()
            );
            this.duplicateInvoiceWarning = found
                ? 'Atencion: ya existe la compra #' + found.id + ' con la misma factura y proveedor.'
                : '';
        },
        buildPayload(estado) {
            return {
                fecha: this.header.fecha,
                proveedor: this.header.proveedor,
                factura: this.header.factura,
                notas: this.header.notas,
                estado,
                items: this.cart,
                almacen: this.almacenActual,
                factura_adjunto: this.header.factura_adjunto,
                duplicated_from_id: this.duplicatedFromId || null
            };
        },
        async submitPurchase(estado) {
            if (!this.canCreate) {
                this.raise('Tu usuario no puede crear compras.');
                return;
            }
            if (!this.cart.length) {
                this.raise('Agrega al menos un item antes de guardar.');
                return;
            }
            const prompts = {
                BORRADOR: 'Guardar compra como borrador en servidor?',
                PENDIENTE: 'Guardar la compra como lista pendiente?',
                PROCESADA: 'Procesar la compra y mover stock ahora?'
            };
            if (!confirm(prompts[estado] || 'Confirmar compra?')) return;
            this.isProcessing = true;
            try {
                let payload = this.buildPayload(estado);
                let res = await fetch('pos_purchases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                let data = await res.json();
                if (data.status === 'confirm_duplicate_invoice') {
                    const ok = confirm(data.msg + '\n\nCompra previa #' + data.duplicate.id + ' del ' + this.formatDate(data.duplicate.fecha) + '.\n\nDeseas guardar de todos modos?');
                    if (!ok) return;
                    payload.force_duplicate_invoice = true;
                    res = await fetch('pos_purchases.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    data = await res.json();
                }
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo guardar');
                this.clearDraftLocal(false);
                this.ok(data.msg);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                this.raise(error.message || 'Error guardando compra');
            } finally {
                this.isProcessing = false;
            }
        },
        async processPending(id) {
            if (!this.permissions.process) {
                this.raise('Sin permiso para procesar compras pendientes.');
                return;
            }
            if (!confirm('Procesar esta lista y aumentar existencias ahora?')) return;
            try {
                const res = await fetch('pos_purchases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'process_pending', id, almacen: this.almacenActual })
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo procesar la lista');
                this.ok(data.msg);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                this.raise(error.message || 'Error procesando compra');
            }
        },
        async cancelPurchase(id) {
            if (!this.permissions.revert) {
                this.raise('Sin permiso para revertir compras.');
                return;
            }
            if (!confirm('Cancelar esta compra completa? Se revertiran todos los items activos.')) return;
            try {
                const res = await fetch('pos_purchases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'cancel', id, almacen: this.almacenActual })
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo cancelar la compra');
                this.ok(data.msg);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                this.raise(error.message || 'Error cancelando compra');
            }
        },
        async revertItem(compraId, detailId, det) {
            if (!this.permissions.revert) {
                this.raise('Sin permiso para revertir items.');
                return;
            }
            const name = det.nombre || det.id_producto;
            if (!confirm('Revertir solo el item ' + name + ' por ' + this.numberFmt(det.cantidad) + ' unidades?')) return;
            try {
                const res = await fetch('pos_purchases.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'revert_item', id_compra: compraId, id_detalle: detailId, almacen: this.almacenActual })
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.msg || 'No se pudo revertir el item');
                this.ok(data.msg);
                setTimeout(() => window.location.reload(), 700);
            } catch (error) {
                this.raise(error.message || 'Error revirtiendo item');
            }
        },
        changeAlmacen(almId) {
            this.almacenActual = parseInt(almId, 10);
            localStorage.setItem('pos_purchases_v3_last_warehouse', String(this.almacenActual));
            const draftKey = 'pos_purchases_v3_draft_' + this.almacenActual;
            this.draftMeta.updatedAt = localStorage.getItem(draftKey + '_updated') || '';
        },
        draftKey() {
            return 'pos_purchases_v3_draft_' + this.almacenActual;
        },
        persistDraftSnapshot() {
            const snapshot = {
                header: this.header,
                cart: this.cart,
                duplicatedFromId: this.duplicatedFromId,
                almacenActual: this.almacenActual
            };
            localStorage.setItem(this.draftKey(), JSON.stringify(snapshot));
            const updatedAt = new Date().toLocaleString('es-ES');
            localStorage.setItem(this.draftKey() + '_updated', updatedAt);
            this.draftMeta.updatedAt = updatedAt;
        },
        saveDraftLocal() {
            this.persistDraftSnapshot();
            this.ok('Borrador local actualizado.');
        },
        restoreDraftIfAny() {
            const snapshot = localStorage.getItem(this.draftKey());
            const updatedAt = localStorage.getItem(this.draftKey() + '_updated') || '';
            this.draftMeta.updatedAt = updatedAt;
            if (!snapshot) return;
            try {
                const parsed = JSON.parse(snapshot);
                if (parsed && Array.isArray(parsed.cart) && parsed.cart.length) {
                    this.header = Object.assign({}, this.header, parsed.header || {});
                    this.cart = parsed.cart;
                    this.duplicatedFromId = parsed.duplicatedFromId || null;
                }
            } catch (error) {
                console.error(error);
            }
        },
        clearDraftLocal(showMessage = true) {
            localStorage.removeItem(this.draftKey());
            localStorage.removeItem(this.draftKey() + '_updated');
            this.draftMeta.updatedAt = '';
            if (showMessage) this.ok('Borrador local eliminado.');
        },
        resetFilters() {
            this.filters = { fecha_desde: '', fecha_hasta: '', proveedor: '', estado: '', almacen: 0 };
        },
        reloadPage() {
            window.location.reload();
        },
        ok(msg) {
            this.flash = { ok: true, msg };
        },
        raise(msg) {
            this.flash = { ok: false, msg };
        },
        async reloadCats() {
            try {
                const res = await fetch('categories_api.php');
                const data = await res.json();
                if (Array.isArray(data)) {
                    this.categories = data.map(c => c.nombre);
                    if (!this.form.categoria && this.categories.length) this.form.categoria = this.categories[0];
                }
            } catch (error) {
                console.error(error);
            }
        }
    }
});

function alCrearProducto(nuevoProducto) {
    if (!nuevoProducto) return;
    window.location.reload();
}

function reloadCategorySelects() {
    if (typeof app !== 'undefined' && typeof app.reloadCats === 'function') {
        app.reloadCats();
    }
    if (typeof loadCategoriesQP === 'function') {
        loadCategoriesQP();
    }
}
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'modal_categories.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
