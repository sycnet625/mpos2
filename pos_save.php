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

    // 3. Preparar Datos Generales
    $idSesion = isset($input['id_caja']) && is_numeric($input['id_caja']) ? (int)$input['id_caja'] : 0;
    $usuarioNombre = pos_security_clean_text($_SESSION['cajero'] ?? $_SESSION['admin_user'] ?? 'Sistema', 100);
    $fechaContable = date('Y-m-d'); // Fallback

    // Recuperar sesión y su fecha contable
    if ($idSesion > 0) {
        $stmtSesion = $pdo->prepare("SELECT id, nombre_cajero, fecha_contable FROM caja_sesiones WHERE id = ?");
        $stmtSesion->execute([$idSesion]);
    } else {
        $stmtSesion = $pdo->query("SELECT id, nombre_cajero, fecha_contable FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
    }

    if ($row = $stmtSesion->fetch(PDO::FETCH_ASSOC)) {
        $idSesion = $row['id'];
        $usuarioNombre = $row['nombre_cajero'];
        $fechaContable = $row['fecha_contable'];
    }

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
    if (!in_array($tipoServicio, ['mostrador', 'delivery', 'reserva', 'mesa', 'recoger'], true)) {
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

    // Agregar columnas de moneda si no existen (idempotente)
    try {
        $pdo->exec("ALTER TABLE ventas_cabecera
            ADD COLUMN IF NOT EXISTS moneda CHAR(3) NOT NULL DEFAULT 'CUP',
            ADD COLUMN IF NOT EXISTS tipo_cambio DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            ADD COLUMN IF NOT EXISTS monto_moneda_original DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    } catch (Throwable $_e) {}

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

    // B. Insertar Cabecera
    $sqlCab = "INSERT INTO ventas_cabecera (
        uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, id_caja,
        tipo_servicio, cliente_nombre, cliente_telefono, cliente_direccion,
        id_empresa, mensajero_nombre, fecha_reserva, sincronizado, id_sesion_caja,
        abono, codigo_pago, estado_pago, canal_origen,
        moneda, tipo_cambio, monto_moneda_original
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmtCab = $pdo->prepare($sqlCab);
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
        id_venta_cabecera, id_producto, cantidad, precio, 
        nombre_producto, codigo_producto
    ) VALUES (?, ?, ?, ?, ?, ?)";
    $stmtDet = $pdo->prepare($sqlDet);

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
        if ($sku === '' || !is_finite($qty) || !is_finite($price) || $qty == 0.0) {
            throw new Exception('Detalle de venta inválido');
        }

        // Insertar Detalle
        $stmtDet->execute([$idVenta, $sku, $qty, $price, $name, $sku]);

    }

    $stmtStock = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
    foreach ($resolvedSale['inventory_items'] as $resolvedItem) {
        $realSku = $resolvedItem['id'];
        $realQty = floatval($resolvedItem['qty'] ?? 0);
        $realName = safe_str($resolvedItem['nombre'] ?? $realSku, 150);
        if ($realQty <= 0) {
            continue;
        }

        $stmtStock->execute([$realSku, $idAlmacen]);
        $stockActual = floatval($stmtStock->fetchColumn());

        if ($esReserva) {
            if ($stockActual < $realQty) {
                $sinExistencia = 1;
            }
            continue;
        }

        if ($stockActual < $realQty) {
            throw new Exception("Stock insuficiente para '" . $realName . "'. Disponible: " . $stockActual . ". Requerido: " . $realQty);
        }
        if ($kardexAvailable) {
            $kardex->registrarVenta($realSku, $realQty, $idVenta, $usuarioNombre, $fechaVenta, $idAlmacen);
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

    echo json_encode(['status' => 'success', 'id' => $idVenta, 'uuid' => $uuid]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'msg' => 'Error: ' . $e->getMessage()]);
}
?>
