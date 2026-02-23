<?php
// ARCHIVO: ventas_api.php
// API para procesar ventas de la tienda web (shop.php)
// id_sesion_caja siempre = 0 (ventas web no pertenecen a sesiones de caja)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
require_once 'push_notify.php';

if (file_exists('kardex_engine.php')) {
    require_once 'kardex_engine.php';
}

function vapi_str($val, $len = 250) {
    return substr((string)($val ?? ''), 0, $len);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception("JSON inválido o vacío.");
    if (empty($input['items'])) throw new Exception("Se requiere al menos un item.");

    // Configuración desde pos.cfg
    $config = ['id_almacen' => 1, 'id_sucursal' => 1, 'id_empresa' => 1];
    $cfgFile = __DIR__ . '/pos.cfg';
    if (file_exists($cfgFile)) {
        $loaded = json_decode(file_get_contents($cfgFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    $idAlmacen  = intval($input['id_almacen']  ?? $config['id_almacen']);
    $idSucursal = intval($input['id_sucursal']  ?? $config['id_sucursal']);
    $idEmpresa  = intval($config['id_empresa']);

    // Campos de la venta
    $uuid          = vapi_str($input['uuid'] ?? uniqid('WEB-', true), 100);
    $total         = floatval($input['total'] ?? 0);
    $tipoServicio  = vapi_str($input['tipo_servicio'] ?? 'mostrador', 50);
    $canalOrigen   = vapi_str($input['canal_origen']  ?? 'Web', 30);
    $metodoPago    = vapi_str($input['metodo_pago']   ?? 'Efectivo', 50);
    $clienteNombre = vapi_str($input['cliente_nombre']    ?? 'Mostrador', 100);
    $clienteTel    = vapi_str($input['cliente_telefono']  ?? '', 50);
    $clienteDir    = vapi_str($input['cliente_direccion'] ?? '', 200);
    $fechaReserva  = !empty($input['fecha_reserva']) ? $input['fecha_reserva'] : null;
    $mensajero     = vapi_str($input['mensajero_nombre'] ?? '', 100);
    $codigoPago    = vapi_str($input['codigo_pago']   ?? '', 100);
    $estadoPago    = vapi_str($input['estado_pago']   ?? 'confirmado', 20);
    $abono         = floatval($input['abono'] ?? 0);
    $esReserva     = ($tipoServicio === 'reserva');
    $fechaVenta    = date('Y-m-d H:i:s');

    // Campos de moneda (multi-divisa)
    $moneda              = in_array($input['moneda'] ?? 'CUP', ['CUP','USD','MLC']) ? $input['moneda'] : 'CUP';
    $tipoCambio          = floatval($input['tipo_cambio'] ?? 1.0);
    $montoMonedaOriginal = floatval($input['monto_moneda_original'] ?? 0);

    // Agregar columnas de moneda si no existen (idempotente)
    try {
        $pdo->exec("ALTER TABLE ventas_cabecera
            ADD COLUMN IF NOT EXISTS moneda CHAR(3) NOT NULL DEFAULT 'CUP',
            ADD COLUMN IF NOT EXISTS tipo_cambio DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            ADD COLUMN IF NOT EXISTS monto_moneda_original DECIMAL(12,2) NOT NULL DEFAULT 0.00");
    } catch (Throwable $_e) {}

    // Pagos desglosados
    $payments = $input['payments'] ?? [];
    if (empty($payments) && !empty($input['metodo_pago'])) {
        $payments[] = ['method' => $metodoPago, 'amount' => $total];
    } elseif (count($payments) > 1) {
        $metodoPago = 'Mixto';
    } elseif (count($payments) === 1) {
        $metodoPago = vapi_str($payments[0]['method'], 50);
    }

    $kardex = (class_exists('KardexEngine')) ? new KardexEngine($pdo) : null;

    $pdo->beginTransaction();

    // Idempotencia: evitar duplicados por UUID
    $stmtChk = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta = ?");
    $stmtChk->execute([$uuid]);
    if ($stmtChk->fetch()) {
        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => 0, 'uuid' => $uuid, 'msg' => 'Venta ya registrada']);
        exit;
    }

    // Insertar cabecera — id_sesion_caja = 0 (ventas web no tienen sesión de caja)
    $stmtCab = $pdo->prepare("
        INSERT INTO ventas_cabecera (
            uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, id_caja,
            tipo_servicio, cliente_nombre, cliente_telefono, cliente_direccion,
            id_empresa, mensajero_nombre, fecha_reserva, sincronizado, id_sesion_caja,
            abono, codigo_pago, estado_pago, canal_origen,
            moneda, tipo_cambio, monto_moneda_original
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtCab->execute([
        $uuid, $fechaVenta, $total, $metodoPago, $idSucursal, $idAlmacen,
        $tipoServicio, $clienteNombre, $clienteTel, $clienteDir,
        $idEmpresa, $mensajero, $fechaReserva,
        $abono, $codigoPago, $estadoPago, $canalOrigen,
        $moneda, $tipoCambio, $montoMonedaOriginal > 0 ? $montoMonedaOriginal : $total
    ]);
    $idVenta = $pdo->lastInsertId();

    // Desglose de pagos
    $stmtPay = $pdo->prepare("INSERT INTO ventas_pagos (id_venta_cabecera, metodo_pago, monto) VALUES (?, ?, ?)");
    foreach ($payments as $pay) {
        if (floatval($pay['amount']) > 0) {
            $stmtPay->execute([$idVenta, vapi_str($pay['method'], 50), floatval($pay['amount'])]);
        }
    }

    // Procesar ítems
    $stmtDet = $pdo->prepare("
        INSERT INTO ventas_detalle (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtProd = $pdo->prepare("SELECT es_servicio, es_elaborado FROM productos WHERE codigo = ?");
    $stmtStock = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");

    $sinExistencia = 0;
    $itemsCocina   = [];

    foreach ($input['items'] as $item) {
        // shop.php envía 'id' como clave del SKU
        $sku   = vapi_str($item['id'] ?? $item['codigo'] ?? '', 50);
        $qty   = floatval($item['qty']);
        $price = floatval($item['price']);
        $name  = vapi_str($item['name'] ?? $sku, 150);

        $stmtDet->execute([$idVenta, $sku, $qty, $price, $name, $sku]);

        $stmtProd->execute([$sku]);
        $prodData    = $stmtProd->fetch(PDO::FETCH_ASSOC);
        $esServicio  = $prodData ? intval($prodData['es_servicio'])  : 0;
        $esElaborado = $prodData ? intval($prodData['es_elaborado']) : 0;

        if ($esServicio === 0) {
            $stmtStock->execute([$sku, $idAlmacen]);
            $stockActual = floatval($stmtStock->fetchColumn());

            if ($esReserva) {
                if ($stockActual < $qty) $sinExistencia = 1;
            } else {
                if ($stockActual < $qty) {
                    throw new Exception("Stock insuficiente para '{$name}'. Disponible: {$stockActual}. Requerido: {$qty}");
                }
                if ($kardex) {
                    $kardex->registrarVenta($sku, $qty, $idVenta, 'Web', $fechaVenta, $idAlmacen);
                }
            }
        }

        if ($esElaborado === 1) {
            $itemsCocina[] = ['qty' => $qty, 'name' => $name, 'note' => vapi_str($item['note'] ?? '', 100)];
        }
    }

    // Comanda para cocina
    if (!empty($itemsCocina) && !$esReserva) {
        $pdo->prepare("INSERT INTO comandas (id_venta, items_json, estado, fecha_creacion) VALUES (?, ?, 'pendiente', ?)")
            ->execute([$idVenta, json_encode($itemsCocina), $fechaVenta]);
    }

    if ($sinExistencia) {
        $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia = 1 WHERE id = ?")
            ->execute([$idVenta]);
    }

    $pdo->commit();

    // Notificaciones (fuera de la transacción para no bloquear)
    try {
        $stmtChat = $pdo->prepare("INSERT INTO chat_messages (client_uuid, sender, message, is_read) VALUES (?, ?, ?, 0)");

        if ($sinExistencia) {
            $stmtChat->execute(['SISTEMA_NOTIF', 'client',
                "⚠️ RESERVA SIN STOCK: Pedido #{$idVenta} ({$clienteNombre}) tiene productos sin existencia suficiente. Revisar antes de confirmar."
            ]);
            push_notify($pdo, 'operador', '📦 Reserva sin stock',
                "Pedido #{$idVenta} — {$clienteNombre} tiene productos sin existencia.",
                '/marinero/reservas.php'
            );
        }
        if ($estadoPago === 'verificando') {
            $stmtChat->execute(['SISTEMA_NOTIF', 'client',
                "💳 PAGO PENDIENTE: Pedido #{$idVenta} ({$clienteNombre}) — Código enviado: {$codigoPago}. Por favor verificar la transferencia."
            ]);
            push_notify($pdo, 'operador', '💳 Transferencia pendiente de verificar',
                "Pedido #{$idVenta} — {$clienteNombre}. Código: {$codigoPago}",
                '/marinero/reservas.php'
            );
        }
        if ($estadoPago !== 'verificando') {
            push_notify($pdo, 'operador', '🛒 Nuevo pedido web',
                "#{$idVenta} — {$clienteNombre} — " . number_format($total, 2) . ' CUP',
                '/marinero/reservas.php'
            );
        }
        if (!empty($itemsCocina) && !$esReserva) {
            $resumen = implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], array_slice($itemsCocina, 0, 3)));
            push_notify($pdo, 'cocina', '🍳 Nueva comanda #' . $idVenta, $resumen, '/marinero/cocina.php');
        }
    } catch (Throwable $notifErr) {
        error_log("ventas_api notifications error: " . $notifErr->getMessage());
    }

    echo json_encode(['status' => 'success', 'id' => $idVenta, 'uuid' => $uuid]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("ventas_api error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
