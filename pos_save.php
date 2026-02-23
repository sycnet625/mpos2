<?php
// ARCHIVO: /var/www/palweb/api/pos_save.php
// VERSIÓN: 4.6 (FIX: GUARDA VENTAS_PAGOS PARA KPIS MIXTOS)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once 'db.php';
require_once 'push_notify.php';
require_once 'pos_audit.php';

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
    // 1. Recibir Datos
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) throw new Exception("Datos vacíos o JSON inválido.");

    // 2. Configuración
    $configFile = __DIR__ . '/pos.cfg';
    $config = ["id_almacen" => 1, "id_sucursal" => 1, "id_empresa" => 1];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }

    $idAlmacen = intval($config['id_almacen']);
    $idSucursal = intval($config['id_sucursal']);
    $idEmpresa = intval($config['id_empresa']);

    // 3. Preparar Datos Generales
    $idSesion = intval($input['id_caja'] ?? 0);
    $usuarioNombre = 'Sistema';
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

    $uuid = $input['uuid'] ?? uniqid('pos_', true);
    $tipoServicio = $input['tipo_servicio'] ?? 'mostrador';

    // Campos nuevos: pago online y reserva sin stock
    $codigoPago    = isset($input['codigo_pago']) ? substr((string)$input['codigo_pago'], 0, 100) : null;
    // Las ventas del POS físico ya están cobradas en persona → 'confirmado'.
    // Solo las ventas web envían estado_pago explícitamente ('verificando' o 'pendiente').
    $estadoPago    = isset($input['estado_pago'])  ? substr((string)$input['estado_pago'],  0, 20)  : 'confirmado';
    $canalOrigen   = isset($input['canal_origen']) ? substr((string)$input['canal_origen'], 0, 30)  : 'POS';
    $esReserva     = ($tipoServicio === 'reserva');
    $sinExistencia = 0; // se calcula en el loop de items

    // Campos de moneda (multi-divisa)
    $moneda               = in_array($input['moneda'] ?? 'CUP', ['CUP','USD','MLC']) ? $input['moneda'] : 'CUP';
    $tipoCambio           = floatval($input['tipo_cambio'] ?? 1.0);
    $montoMonedaOriginal  = floatval($input['monto_moneda_original'] ?? 0);

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
    $payments = $input['payments'] ?? [];
    $mainMethod = 'Efectivo'; // Default

    // Si es legacy (no envía array payments), crearlo
    if (empty($payments) && !empty($input['metodo_pago'])) {
        $payments[] = ['method' => $input['metodo_pago'], 'amount' => floatval($input['total'])];
        $mainMethod = $input['metodo_pago'];
    } elseif (count($payments) > 1) {
        $mainMethod = 'Mixto';
    } elseif (count($payments) === 1) {
        $mainMethod = $payments[0]['method'];
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
        floatval($input['total']),
        safe_str($mainMethod, 50),
        $idSucursal,
        $idAlmacen,
        $idSesion,
        safe_str($tipoServicio, 50),
        safe_str($input['cliente_nombre'] ?? 'Mostrador', 100),
        safe_str($input['cliente_telefono'] ?? '', 50),
        safe_str($input['cliente_direccion'] ?? '', 200),
        $idEmpresa,
        safe_str($input['mensajero_nombre'] ?? '', 100),
        !empty($input['fecha_reserva']) ? $input['fecha_reserva'] : null,
        $idSesion,
        floatval($input['abono'] ?? 0),
        $codigoPago,
        $estadoPago,
        $canalOrigen,
        $moneda,
        $tipoCambio,
        $montoMonedaOriginal > 0 ? $montoMonedaOriginal : floatval($input['total'])
    ]);

    $idVenta = $pdo->lastInsertId();

    // C. Guardar Desglose de Pagos (VENTAS_PAGOS)
    // Esto es lo que permite que el KPI del historial funcione
    $stmtPay = $pdo->prepare("INSERT INTO ventas_pagos (id_venta_cabecera, metodo_pago, monto) VALUES (?, ?, ?)");
    foreach ($payments as $pay) {
        if (floatval($pay['amount']) > 0) {
            $stmtPay->execute([
                $idVenta, 
                safe_str($pay['method'], 50), 
                floatval($pay['amount'])
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

    foreach ($input['items'] as $item) {
        $sku = $item['id'];
        $qty = floatval($item['qty']);
        $price = floatval($item['price']);
        $name = safe_str($item['name'] ?? $sku, 150);

        // Insertar Detalle
        $stmtDet->execute([$idVenta, $sku, $qty, $price, $name, $sku]);

        // Verificar Tipo Producto
        $stmtProd = $pdo->prepare("SELECT es_servicio, es_elaborado FROM productos WHERE codigo = ?");
        $stmtProd->execute([$sku]);
        $prodData = $stmtProd->fetch(PDO::FETCH_ASSOC);
        
        $esServicio = $prodData ? intval($prodData['es_servicio']) : 0;
        $esElaborado = $prodData ? intval($prodData['es_elaborado']) : 0;

        // Mover Inventario (Solo productos físicos)
        if ($esServicio === 0) {
            $stmtStock = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
            $stmtStock->execute([$sku, $idAlmacen]);
            $stockActual = floatval($stmtStock->fetchColumn());

            if ($esReserva) {
                // En reservas NO se deduce kardex; solo se marca si hay déficit
                if ($stockActual < $qty) {
                    $sinExistencia = 1;
                }
            } else {
                // Venta normal: verificar stock y registrar movimiento
                if ($stockActual < $qty) {
                    throw new Exception("Stock insuficiente para '" . $name . "'. Disponible: " . $stockActual . ". Requerido: " . $qty);
                }
                if ($kardexAvailable) {
                    $kardex->registrarVenta($sku, $qty, $idVenta, $usuarioNombre, $fechaVenta, $idAlmacen);
                }
            }
        }

        // Comanda (Elaborados)
        if ($esElaborado === 1) {
            $itemsCocina[] = ['qty' => $qty, 'name' => $name, 'note' => safe_str($item['note'] ?? '', 100)];
        }
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
                '/marinero/reservas.php'
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
                '/marinero/reservas.php'
            );
        }
        // Nuevo pedido web
        if ($canalOrigen === 'Web' && $estadoPago !== 'verificando') {
            push_notify($pdo, 'operador',
                '🛒 Nuevo pedido web',
                "#{$idVenta} — {$clienteNombre} — " . number_format(floatval($input['total']), 2) . ' CUP',
                '/marinero/reservas.php'
            );
        }
        // Nueva comanda a cocina
        if (!empty($itemsCocina) && $tipoServicio !== 'reserva') {
            $resumenCocina = implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'], array_slice($itemsCocina, 0, 3)));
            push_notify($pdo, 'cocina',
                '🍳 Nueva comanda #' . $idVenta,
                $resumenCocina,
                '/marinero/cocina.php'
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

