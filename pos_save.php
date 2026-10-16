<?php
// ARCHIVO: /var/www/palweb/api/pos_save.php
// VERSIÓN: 4.6 (FIX: GUARDA VENTAS_PAGOS PARA KPIS MIXTOS)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once 'pos_security.php';
pos_security_bootstrap_session();
require_once 'db.php';
require_once 'config_loader.php';
require_once 'push_notify.php';
require_once 'pos_audit.php';
require_once 'combo_helper.php';

// Verificar motor de inventario
$kardexAvailable = false;
if (file_exists('kardex_engine.php')) {
    require_once 'kardex_engine.php';
    if (class_exists('KardexEngine')) {
        $kardexAvailable = true;
    }
}

// Función auxiliar segura
function safe_str($str, $len = 250) {
    return substr((string)($str ?? ''), 0, $len);
}

function pos_save_context_name(PDO $pdo, string $table, int $id): string {
    if ($id <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT nombre FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

try {
    pos_security_enforce_session(false);

    // 1. Recibir Datos
    $input = pos_security_json_input();
    pos_security_require_csrf($input);

    if (!$input) throw new Exception("Datos vacíos o JSON inválido.");

    // 2. Configuración (dinámica por sesión/cajero; fallback a pos.cfg en config_loader)
    $config = array_merge([
        "id_almacen" => 1,
        "id_sucursal" => 1,
        "id_empresa" => 1
    ], $config ?? []);

    $idAlmacen = intval($config['id_almacen']);
    $idSucursal = intval($config['id_sucursal']);
    $idEmpresa = intval($config['id_empresa']);

    // En el POS físico manda el contexto del cajero autenticado. config_loader puede
    // traer contexto de usuario/admin, pero al cobrar no debe reemplazar al almacén
    // elegido en el login/selector del POS.
    if (!empty($_SESSION['id_almacen'])) {
        $idAlmacen = (int)$_SESSION['id_almacen'];
    } elseif (isset($input['id_almacen']) && is_numeric($input['id_almacen'])) {
        $idAlmacen = (int)$input['id_almacen'];
    }
    if (!empty($_SESSION['id_sucursal'])) {
        $idSucursal = (int)$_SESSION['id_sucursal'];
    } elseif (isset($input['id_sucursal']) && is_numeric($input['id_sucursal'])) {
        $idSucursal = (int)$input['id_sucursal'];
    }
    if (!empty($_SESSION['id_empresa'])) {
        $idEmpresa = (int)$_SESSION['id_empresa'];
    } elseif (isset($input['id_empresa']) && is_numeric($input['id_empresa'])) {
        $idEmpresa = (int)$input['id_empresa'];
    }

    if ($idAlmacen <= 0 || $idSucursal <= 0 || $idEmpresa <= 0) {
        throw new Exception("Contexto POS inválido. Empresa: {$idEmpresa}. Sucursal: {$idSucursal}. Almacén: {$idAlmacen}.");
    }

    try {
        $stmtCtx = $pdo->prepare("SELECT id FROM almacenes WHERE id = ? AND id_sucursal = ? AND COALESCE(activo, 1) = 1 LIMIT 1");
        $stmtCtx->execute([$idAlmacen, $idSucursal]);
        if (!$stmtCtx->fetchColumn()) {
            throw new Exception("Contexto POS inválido: el almacén {$idAlmacen} no pertenece a la sucursal {$idSucursal} o está inactivo.");
        }
    } catch (PDOException $e) {
        // Si la tabla no existe en una instalación vieja, continuar con el contexto resuelto.
    }

    // 3. Preparar Datos Generales
    $idSesion = isset($input['id_caja']) && is_numeric($input['id_caja']) ? (int)$input['id_caja'] : 0;
    $usuarioNombre = pos_security_clean_text($_SESSION['cajero'] ?? $_SESSION['admin_user'] ?? 'Sistema', 100);
    $fechaContable = date('Y-m-d'); // Fallback

    // Recuperar sesión y su fecha contable
    if ($idSesion > 0) {
        $stmtSesion = $pdo->prepare("SELECT id, id_empresa, id_sucursal, id_almacen, nombre_cajero, fecha_apertura, fecha_contable FROM caja_sesiones WHERE id = ? AND estado = 'ABIERTA' AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? LIMIT 1");
        $stmtSesion->execute([$idSesion, $idEmpresa, $idSucursal, $idAlmacen]);
    } else {
        $stmtSesion = $pdo->prepare("SELECT id, id_empresa, id_sucursal, id_almacen, nombre_cajero, fecha_apertura, fecha_contable FROM caja_sesiones WHERE estado = 'ABIERTA' AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? ORDER BY id DESC LIMIT 1");
        $stmtSesion->execute([$idEmpresa, $idSucursal, $idAlmacen]);
    }

    $row = $stmtSesion->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("No hay una sesión de caja abierta para la empresa {$idEmpresa}, sucursal {$idSucursal} y almacén {$idAlmacen}.");
    }

    // Una sesión que permanece abierta al cambiar el día puede seguir vendiendo.
    // Se agrega un aviso informativo a la respuesta exitosa, sin bloquear la venta.
    $fechaSesion = substr((string)($row['fecha_contable'] ?? ''), 0, 10);
    $fechaHoy = date('Y-m-d');
    $fechaAperturaDia = substr((string)($row['fecha_apertura'] ?? ''), 0, 10);
    $fechaDiaSesion = $fechaAperturaDia !== '' ? $fechaAperturaDia : $fechaSesion;
    $avisoSesionDiaSiguiente = $fechaDiaSesion !== '' && $fechaDiaSesion < $fechaHoy
        ? [
            'id' => (int)$row['id'],
            'nombre_cajero' => (string)($row['nombre_cajero'] ?? ''),
            'fecha_apertura' => (string)($row['fecha_apertura'] ?? ''),
            'fecha_contable' => $fechaSesion,
            'fecha_hoy' => $fechaHoy,
            'id_empresa' => $idEmpresa,
            'id_sucursal' => $idSucursal,
            'id_almacen' => $idAlmacen,
        ]
        : null;

    $idSesion = (int)$row['id'];
    $usuarioNombre = $row['nombre_cajero'];
    $fechaContable = $row['fecha_contable'];

    // La fecha de la venta será la fecha contable + la hora actual
    $fechaVenta = $fechaContable . ' ' . date('H:i:s');
    
    // Si viene un timestamp (ej. ventas offline sincronizadas), podríamos querer respetarlo 
    // pero el requerimiento es usar la fecha contable de la sesión.
    // Para no perder la "hora" exacta si es importante:
    if (!empty($input['timestamp'])) {
        $horaReal = date('H:i:s', $input['timestamp'] / 1000);
        $fechaVenta = $fechaContable . ' ' . $horaReal;
    }

    $uuid = pos_security_clean_text($input['uuid'] ?? uniqid('pos_', true), 80);
    if ($uuid === '') {
        $uuid = uniqid('pos_', true);
    }

    $tipoServicio = pos_security_clean_text($input['tipo_servicio'] ?? 'mostrador', 50);
    // El POS utiliza "mensajeria" para las ventas con entrega a domicilio.
    // Debe conservarse en la cabecera para que el ticket pueda mostrar todos
    // los datos de la entrega.
    if (!in_array($tipoServicio, ['mostrador', 'mensajeria', 'delivery', 'reserva', 'mesa', 'recoger'], true)) {
        $tipoServicio = 'mostrador';
    }

    // Campos nuevos: pago online y reserva sin stock
    $codigoPago    = isset($input['codigo_pago']) ? pos_security_clean_text($input['codigo_pago'], 100) : null;
    // Las ventas del POS físico ya están cobradas en persona → 'confirmado'.
    // Solo las ventas web envían estado_pago explícitamente ('verificando' o 'pendiente').
    $estadoPago = isset($input['estado_pago']) ? pos_security_clean_text($input['estado_pago'], 20) : 'confirmado';
    if (!in_array($estadoPago, ['confirmado', 'pendiente', 'verificando'], true)) {
        $estadoPago = 'confirmado';
    }
    $canalOrigen = isset($input['canal_origen']) ? pos_security_clean_text($input['canal_origen'], 30) : 'POS';
    if ($canalOrigen === '') {
        $canalOrigen = 'POS';
    }
    $esReserva     = ($tipoServicio === 'reserva');
    $sinExistencia = 0; // se calcula en el loop de items

    // Campos de moneda (multi-divisa)
    $moneda = in_array($input['moneda'] ?? 'CUP', ['CUP', 'USD', 'MLC'], true) ? (string)$input['moneda'] : 'CUP';
    $tipoCambio = isset($input['tipo_cambio']) && is_numeric($input['tipo_cambio']) ? (float)$input['tipo_cambio'] : 1.0;
    if (!is_finite($tipoCambio) || $tipoCambio <= 0) {
        $tipoCambio = 1.0;
    }
    $montoMonedaOriginal = isset($input['monto_moneda_original']) && is_numeric($input['monto_moneda_original'])
        ? (float)$input['monto_moneda_original']
        : 0.0;
    $saleTotal = isset($input['total']) && is_numeric($input['total']) ? (float)$input['total'] : null;
    if ($saleTotal === null || !is_finite($saleTotal)) {
        throw new Exception('Total de venta inválido');
    }
    $saleItems = is_array($input['items'] ?? null) ? $input['items'] : [];
    if (empty($saleItems)) {
        throw new Exception('La venta no contiene productos');
    }

    // El navegador solo propone precios y cantidades. La venta se recalcula aquí
    // contra el catálogo de la empresa/sucursal/almacén de la sesión.
    $priceMode = (string)($input['price_mode'] ?? 'sucursal');
    if (!in_array($priceMode, ['sucursal', 'mayorista_suc', 'custom_pct'], true)) {
        $priceMode = 'sucursal';
    }
    $priceAdjustPct = isset($input['price_adjust_pct']) && is_numeric($input['price_adjust_pct'])
        ? (float)$input['price_adjust_pct'] : 0.0;
    if (!is_finite($priceAdjustPct) || $priceAdjustPct < -99.99 || $priceAdjustPct > 999.99) {
        throw new Exception('Porcentaje de precio inválido');
    }
    $globalDiscountPct = isset($input['descuento_global']) && is_numeric($input['descuento_global'])
        ? (float)$input['descuento_global'] : 0.0;
    if (!is_finite($globalDiscountPct) || $globalDiscountPct < -100 || $globalDiscountPct > 100) {
        throw new Exception('Descuento global inválido');
    }

    $requestedCodes = [];
    foreach ($saleItems as $item) {
        if (!is_array($item)) {
            throw new Exception('Detalle de venta inválido');
        }
        $sku = pos_security_clean_code($item['id'] ?? $item['codigo'] ?? '', 64);
        $qty = isset($item['qty']) && is_numeric($item['qty']) ? (float)$item['qty'] : 0.0;
        $clientPrice = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : 0.0;
        if ($sku === '' || !is_finite($qty) || $qty <= 0 || !is_finite($clientPrice) || $clientPrice < 0) {
            throw new Exception('Detalle de venta inválido');
        }
        $requestedCodes[$sku] = true;
    }

    $codeList = array_keys($requestedCodes);
    $codePlaceholders = implode(',', array_fill(0, count($codeList), '?'));
    $catalogScopeSql = "((p.es_servicio = 1 AND p.id_sucursal_origen = ?)
                OR EXISTS (
                    SELECT 1 FROM stock_almacen scope_stock
                    WHERE scope_stock.id_producto = p.codigo
                      AND scope_stock.id_sucursal = ?
                      AND scope_stock.id_almacen = ?
                )";
    if ($esReserva) {
        // Una reserva puede no tener existencia todavía, pero debe seguir
        // perteneciendo a la sucursal de origen; nunca se acepta un producto
        // global de otra sucursal.
        $catalogScopeSql .= " OR p.id_sucursal_origen = ?";
    }
    $catalogScopeSql .= ')';
    $stmtCatalog = $pdo->prepare(
        "SELECT p.codigo, p.nombre, p.activo, p.es_servicio, p.es_elaborado,
                COALESCE(p.es_combo, 0) AS es_combo, COALESCE(p.costo, 0) AS costo,
                COALESCE(ps.precio_venta, p.precio) AS precio_suc,
                COALESCE(ps.precio_mayorista, p.precio_mayorista, p.precio) AS precio_mayorista_suc
         FROM productos p
         LEFT JOIN productos_precios_sucursal ps
                ON ps.codigo_producto = p.codigo AND ps.id_sucursal = ?
         WHERE p.id_empresa = ?
           AND p.codigo IN ($codePlaceholders)
           AND p.activo = 1
           AND {$catalogScopeSql}"
    );
    $catalogParams = [$idSucursal, $idEmpresa];
    $catalogParams = array_merge($catalogParams, $codeList, [$idSucursal, $idSucursal, $idAlmacen]);
    if ($esReserva) $catalogParams[] = $idSucursal;
    $stmtCatalog->execute($catalogParams);
    $catalog = [];
    foreach ($stmtCatalog->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $catalog[(string)$product['codigo']] = $product;
    }
    if (count($catalog) !== count($codeList)) {
        $missing = array_values(array_diff($codeList, array_keys($catalog)));
        throw new Exception('Producto fuera del contexto POS o inactivo: ' . implode(', ', $missing));
    }

    $isAdmin = !empty($_SESSION['admin_logged_in'])
        || (string)($_SESSION['pos_rol'] ?? '') === 'admin';
    $canCustomPrice = $isAdmin
        || (string)($_SESSION['pos_rol'] ?? '') === 'supervisor';
    $normalizedSaleItems = [];
    $computedSubtotal = 0.0;
    foreach ($saleItems as $item) {
        $sku = pos_security_clean_code($item['id'] ?? $item['codigo'] ?? '', 64);
        $product = $catalog[$sku];
        $qty = (float)$item['qty'];
        $discountPct = isset($item['discountPct']) && is_numeric($item['discountPct'])
            ? (float)$item['discountPct'] : 0.0;
        if (!is_finite($discountPct) || $discountPct < 0 || $discountPct > 100) {
            throw new Exception("Descuento inválido para '{$product['nombre']}'");
        }
        $customPrice = !empty($item['customPrice']);
        if ($customPrice && !$canCustomPrice) {
            throw new Exception('Solo un supervisor o administrador puede modificar precios.');
        }

        $basePrice = (float)$product['precio_suc'];
        $serverPrice = $basePrice;
        if ($priceMode === 'mayorista_suc') {
            $serverPrice = (float)$product['precio_mayorista_suc'];
        } elseif ($priceMode === 'custom_pct') {
            $serverPrice = $basePrice * (1 + ($priceAdjustPct / 100));
        }
        if ($customPrice) {
            $serverPrice = (float)$item['price'];
        }
        if (!is_finite($serverPrice) || $serverPrice < 0) {
            throw new Exception("Precio inválido para '{$product['nombre']}'");
        }

        $computedSubtotal += $serverPrice * (1 - ($discountPct / 100)) * $qty;
        $normalizedSaleItems[] = [
            'id' => $sku,
            'name' => safe_str(pos_security_clean_text($product['nombre'] ?? $sku, 150), 150),
            'qty' => $qty,
            'price' => round($serverPrice, 2),
            'discountPct' => $discountPct,
            'customPrice' => $customPrice,
            'note' => safe_str(pos_security_clean_text($item['note'] ?? '', 255), 255),
        ];
    }
    $computedTotal = round($computedSubtotal * (1 - ($globalDiscountPct / 100)), 2);
    if (abs($computedTotal - $saleTotal) > 0.01 && !$isAdmin) {
        throw new Exception('El total de la venta no coincide con los precios autorizados. Actualice el carrito.');
    }
    // El administrador puede confirmar el total que aparece en el POS,
    // incluso si incluye un ajuste manual o un importe adicional del cobro.
    $saleTotal = $isAdmin ? round($saleTotal, 2) : $computedTotal;
    $saleItems = $normalizedSaleItems;

    // 4. TRANSACCIÓN GLOBAL
    $pdo->beginTransaction();

    // Inicializar motor Kardex
    $kardex = ($kardexAvailable) ? new KardexEngine($pdo) : null;

    // A. Verificar Duplicados (Idempotencia)
    $stmtCheck = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta = ?");
    $stmtCheck->execute([$uuid]);
    if ($stmtCheck->fetch()) {
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => 0, 'msg' => 'Venta ya registrada']);
        exit;
    }

    // --- PROCESAMIENTO DE PAGOS (NUEVO: CRÍTICO PARA EL HISTORIAL) ---
    $payments = is_array($input['payments'] ?? null) ? $input['payments'] : [];
    $mainMethod = 'Efectivo'; // Default

    // Si es legacy (no envía array payments), crearlo
    if (empty($payments) && !empty($input['metodo_pago'])) {
        $legacyMethod = pos_security_clean_text($input['metodo_pago'], 50);
        $payments[] = ['method' => $legacyMethod, 'amount' => $saleTotal];
        $mainMethod = $legacyMethod !== '' ? $legacyMethod : 'Efectivo';
    } elseif (count($payments) > 1) {
        $mainMethod = 'Mixto';
    } elseif (count($payments) === 1) {
        $mainMethod = pos_security_clean_text($payments[0]['method'] ?? '', 50) ?: 'Efectivo';
    }

    $paymentTotal = 0.0;
    foreach ($payments as $pay) {
        if (!is_array($pay)) {
            throw new Exception('Pago inválido');
        }
        $payAmount = isset($pay['amount']) && is_numeric($pay['amount']) ? (float)$pay['amount'] : 0.0;
        $payMethod = pos_security_clean_text($pay['method'] ?? '', 50);
        if (!is_finite($payAmount) || $payAmount <= 0 || $payMethod === '') {
            throw new Exception('Pago inválido');
        }
        $paymentTotal += $payAmount;
    }
    if ($saleTotal > 0 && abs(round($paymentTotal, 2) - $saleTotal) > 0.01) {
        throw new Exception('La suma de los pagos no coincide con el total de la venta.');
    }

    // B. Insertar Cabecera
    $sqlCab = "INSERT INTO ventas_cabecera (
        uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, id_caja,
        tipo_servicio, cliente_nombre, cliente_telefono, cliente_direccion,
        id_empresa, mensajero_nombre, fecha_reserva, sincronizado, id_sesion_caja,
        abono, codigo_pago, estado_pago, canal_origen,
        moneda, tipo_cambio, monto_moneda_original
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtCab = $pdo->prepare($sqlCab);
    try {
        $stmtCab->execute([
        $uuid,
        $fechaVenta,
        $saleTotal,
        safe_str($mainMethod, 50),
        $idSucursal,
        $idAlmacen,
        $idSesion,
        safe_str($tipoServicio, 50),
        safe_str(pos_security_clean_text($input['cliente_nombre'] ?? 'Mostrador', 100), 100),
        safe_str(pos_security_clean_text($input['cliente_telefono'] ?? '', 50), 50),
        safe_str(pos_security_clean_text($input['cliente_direccion'] ?? '', 200), 200),
        $idEmpresa,
        safe_str(pos_security_clean_text($input['mensajero_nombre'] ?? '', 100), 100),
        !empty($input['fecha_reserva']) ? pos_security_clean_text($input['fecha_reserva'], 25) : null,
        $idSesion,
        isset($input['abono']) && is_numeric($input['abono']) ? (float)$input['abono'] : 0.0,
        $codigoPago,
        $estadoPago,
        $canalOrigen,
        $moneda,
        $tipoCambio,
            $montoMonedaOriginal > 0 ? $montoMonedaOriginal : $saleTotal
        ]);
    } catch (PDOException $duplicateError) {
        // El índice único uk_uuid_venta protege también dos peticiones simultáneas.
        if ((string)$duplicateError->getCode() === '23000'
            && stripos($duplicateError->getMessage(), 'uuid') !== false) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'success', 'id' => 0, 'uuid' => $uuid, 'msg' => 'Venta ya registrada']);
            exit;
        }
        throw $duplicateError;
    }

    $idVenta = $pdo->lastInsertId();

    // C. Guardar Desglose de Pagos (VENTAS_PAGOS)
    // Esto es lo que permite que el KPI del historial funcione
    $stmtPay = $pdo->prepare("INSERT INTO ventas_pagos (id_venta_cabecera, metodo_pago, monto) VALUES (?, ?, ?)");
    foreach ($payments as $pay) {
        $payAmount = isset($pay['amount']) && is_numeric($pay['amount']) ? (float)$pay['amount'] : 0.0;
        $payMethod = pos_security_clean_text($pay['method'] ?? '', 50);
        if ($payAmount > 0 && $payMethod !== '') {
            $stmtPay->execute([
                $idVenta, 
                safe_str($payMethod, 50), 
                $payAmount
            ]);
        }
    }

    // D. Procesar Detalles e Inventario
    $sqlDet = "INSERT INTO ventas_detalle (
        id_venta_cabecera, id_producto, cantidad, precio, costo_unitario,
        nombre_producto, codigo_producto, nota_cocina
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtDet = $pdo->prepare($sqlDet);
    $stmtCostoProducto = $pdo->prepare("SELECT costo FROM productos WHERE codigo = ? AND id_empresa = ? LIMIT 1");

    $itemsCocina = [];

    $resolvedSale = combo_expand_sale_items($pdo, $idEmpresa, $saleItems);

    foreach ($saleItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sku = pos_security_clean_code($item['id'] ?? '', 64);
        $qty = isset($item['qty']) && is_numeric($item['qty']) ? (float)$item['qty'] : 0.0;
        $price = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : 0.0;
        $name = safe_str(pos_security_clean_text($item['name'] ?? $sku, 150), 150);
        $note = safe_str(pos_security_clean_text($item['note'] ?? '', 255), 255);
        if ($sku === '' || !is_finite($qty) || !is_finite($price) || $qty == 0.0) {
            throw new Exception('Detalle de venta inválido');
        }

        $stmtCostoProducto->execute([$sku, $idEmpresa]);
        $costoUnitario = $stmtCostoProducto->fetchColumn();
        $costoUnitario = is_numeric($costoUnitario) ? (float)$costoUnitario : 0.0;

        // Insertar Detalle
        $stmtDet->execute([$idVenta, $sku, $qty, $price, $costoUnitario, $name, $sku, $note !== '' ? $note : null]);

    }

    // Bloquea las filas de stock durante la venta para evitar dos cobros
    // simultáneos que consuman la misma existencia.
    $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ? AND id_sucursal = ? FOR UPDATE");
    foreach ($resolvedSale['inventory_items'] as $resolvedItem) {
        $realSku = $resolvedItem['id'];
        $realQty = floatval($resolvedItem['qty'] ?? 0);
        $realName = safe_str($resolvedItem['nombre'] ?? $realSku, 150);
        if ($realQty <= 0) {
            continue;
        }

        $stmtStock->execute([$realSku, $idAlmacen, $idSucursal]);
        $stockActual = 0.0;
        foreach ($stmtStock->fetchAll(PDO::FETCH_COLUMN) as $stockRow) {
            $stockActual += (float)$stockRow;
        }

        if ($esReserva) {
            if ($stockActual < $realQty) {
                $sinExistencia = 1;
            }
            continue;
        }

        if ($stockActual < $realQty) {
            $almNombre = pos_save_context_name($pdo, 'almacenes', $idAlmacen);
            $sucNombre = pos_save_context_name($pdo, 'sucursales', $idSucursal);
            $debugContext = "Debug existencia: almacén #{$idAlmacen}"
                . ($almNombre !== '' ? " ({$almNombre})" : '')
                . ", sucursal #{$idSucursal}"
                . ($sucNombre !== '' ? " ({$sucNombre})" : '')
                . ", SKU {$realSku}.";
            throw new Exception("Stock insuficiente para '" . $realName . "'. Disponible: " . $stockActual . ". Requerido: " . $realQty . ". " . $debugContext);
        }
        if ($kardexAvailable) {
            $registered = $kardex->registrarVenta(
                $realSku,
                $realQty,
                $idVenta,
                $usuarioNombre,
                $fechaVenta,
                $idAlmacen,
                $idSucursal,
                $idEmpresa
            );
            if ($registered !== true) {
                throw new Exception("No se pudo registrar el movimiento de kardex para {$realSku}.");
            }
        }
    }

    foreach ($resolvedSale['kitchen_items'] as $kitchenItem) {
        $itemsCocina[] = [
            'qty' => floatval($kitchenItem['qty'] ?? 0),
            'name' => safe_str($kitchenItem['name'] ?? '', 150),
            'note' => safe_str($kitchenItem['note'] ?? '', 100),
        ];
    }

    // E. Guardar Comanda
    if (!empty($itemsCocina) && $tipoServicio !== 'reserva') {
        $stmtCom = $pdo->prepare("INSERT INTO comandas (id_venta, items_json, estado, fecha_creacion) VALUES (?, ?, 'pendiente', ?)");
        $stmtCom->execute([$idVenta, json_encode($itemsCocina), $fechaVenta]);
    }

    // F. Actualizar sin_existencia si aplica
    if ($sinExistencia) {
        $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia = 1 WHERE id = ?")
            ->execute([$idVenta]);
    }

    $pdo->commit();

    // F-2. Audit de descuentos (fuera de transacción — silencioso)
    // El frontend envía descuentos_items[] y descuento_global con cada venta
    try {
        $descuentosItems  = $input['descuentos_items']  ?? [];
        $descuentoGlobal  = floatval($input['descuento_global'] ?? 0);

        foreach ($descuentosItems as $d) {
            log_audit($pdo, AUDIT_DESCUENTO_ITEM, $usuarioNombre, [
                'id_venta'        => $idVenta,
                'codigo'          => safe_str($d['codigo']  ?? '', 50),
                'producto'        => safe_str($d['nombre']  ?? '', 150),
                'precio_original' => floatval($d['precio_original'] ?? 0),
                'descuento_pct'   => floatval($d['descuento_pct']   ?? 0),
                'precio_final'    => floatval($d['precio_final']     ?? 0),
            ]);
        }

        if ($descuentoGlobal > 0) {
            $subtotalBruto = $descuentoGlobal < 100
                ? round(floatval($input['total']) / (1 - $descuentoGlobal / 100), 2)
                : 0;
            log_audit($pdo, AUDIT_DESCUENTO_GLOBAL, $usuarioNombre, [
                'id_venta'       => $idVenta,
                'descuento_pct'  => $descuentoGlobal,
                'subtotal_bruto' => $subtotalBruto,
                'total_neto'     => floatval($input['total']),
            ]);
        }
    } catch (Throwable $auditErr) {
        error_log("pos_save audit error: " . $auditErr->getMessage());
    }

    // G. Notificaciones de chat + Push (fuera de transacción para no bloquear)
    $clienteNombre = safe_str($input['cliente_nombre'] ?? 'Cliente', 100);
    try {
        $stmtChat = $pdo->prepare(
            "INSERT INTO chat_messages (client_uuid, sender, message, is_read) VALUES (?, ?, ?, 0)"
        );
        if ($sinExistencia) {
            $stmtChat->execute([
                'SISTEMA_NOTIF', 'client',
                "⚠️ RESERVA SIN STOCK: Pedido #{$idVenta} ({$clienteNombre}) tiene productos sin existencia suficiente. Revisar antes de confirmar."
            ]);
            push_notify($pdo, 'operador',
                '📦 Reserva sin stock',
                "Pedido #{$idVenta} — {$clienteNombre} tiene productos sin existencia.",
                '/marinero/reservas.php',
                'reservation_no_stock'
            );
        }
        if ($estadoPago === 'verificando') {
            $stmtChat->execute([
                'SISTEMA_NOTIF', 'client',
                "💳 PAGO PENDIENTE: Pedido #{$idVenta} ({$clienteNombre}) — Código enviado: {$codigoPago}. Por favor verificar la transferencia."
            ]);
            push_notify($pdo, 'operador',
                '💳 Transferencia pendiente de verificar',
                "Pedido #{$idVenta} — {$clienteNombre}. Código: {$codigoPago}",
                '/marinero/reservas.php',
                'payment_transfer_pending'
            );
        }
        // Nuevo pedido web
        if ($canalOrigen === 'Web' && $estadoPago !== 'verificando') {
            push_notify($pdo, 'operador',
                ($tipoServicio === 'reserva' ? '📅 Nueva reserva web' : '🛒 Nuevo pedido web'),
                "#{$idVenta} — {$clienteNombre} — " . number_format($saleTotal, 2) . ' CUP',
                '/marinero/reservas.php',
                ($tipoServicio === 'reserva' ? 'reservation_web_new' : 'web_order_new')
            );
        }
        if ($canalOrigen !== 'Web' && $tipoServicio === 'reserva') {
            push_notify($pdo, 'operador',
                '📅 Nueva reserva',
                "#{$idVenta} — {$clienteNombre}",
                '/marinero/reservas.php',
                'reservation_new'
            );
        }
        if ($canalOrigen !== 'Web' && $tipoServicio !== 'reserva' && $estadoPago !== 'verificando') {
            push_notify($pdo, 'operador',
                '🧾 Compra nueva',
                "#{$idVenta} — {$clienteNombre} — " . number_format($saleTotal, 2) . ' CUP',
                '/marinero/pos.php',
                'purchase_new'
            );
        }
        // Nueva comanda a cocina
        if (!empty($itemsCocina) && $tipoServicio !== 'reserva') {
            $resumenCocina = implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], array_slice($itemsCocina, 0, 3)));
            push_notify($pdo, 'cocina',
                '🍳 Nueva comanda #' . $idVenta,
                $resumenCocina,
                '/marinero/cocina.php',
                'kitchen_new_ticket'
            );
        }
    } catch (Throwable $chatErr) {
        // No bloquear la respuesta por fallo en notificación
        error_log("pos_save notifications error: " . $chatErr->getMessage());
    }

    // Informar al POS todos los productos cuyo stock cambió, no solo las
    // líneas visibles del carrito. En un combo, el inventario real son sus
    // componentes y también deben actualizarse sus tarjetas.
    $affectedCodes = array_values(array_unique(array_filter(array_merge(
        array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $saleItems),
        array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $resolvedSale['inventory_items'] ?? [])
    ))));

    echo json_encode([
        'status' => 'success',
        'id' => $idVenta,
        'uuid' => $uuid,
        'affected_codes' => $affectedCodes,
        'aviso_sesion_dia_siguiente' => $avisoSesionDiaSiguiente,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>
