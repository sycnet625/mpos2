<?php
// ARCHIVO: self_order_api.php
ini_set('display_errors', 0); // Evitar que errores PHP rompan el JSON
header('Content-Type: application/json');
require_once 'db.php';
session_start();

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ($_GET['action'] ?? '');

if ($action === 'get_config') {
    $configFile = 'pos.cfg';
    $config = ["kiosco_aceptar_pedidos" => true];
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) $config = array_merge($config, $loaded);
    }
    echo json_encode(['status' => 'success', 'config' => $config]);
    exit;
}

if ($action === 'set_config') {
    $configFile = 'pos.cfg';
    $config = [];
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true) ?: [];
    }
    
    // Solo actualizamos este valor específico
    if (isset($input['aceptar_pedidos'])) {
        $config['kiosco_aceptar_pedidos'] = (bool)$input['aceptar_pedidos'];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'create') {
    try {
        if (empty($input['items'])) throw new Exception("El pedido está vacío");

        $pdo->beginTransaction();
        
        // 1. Calcular total y preparar notas
        $total = 0;
        $orderNotes = "AUTOPEDIDO KIOSCO\n";
        
        foreach($input['items'] as $it) {
            $stmtP = $pdo->prepare("SELECT nombre, precio FROM productos WHERE codigo = ?");
            $stmtP->execute([$it['codigo']]);
            $pData = $stmtP->fetch(PDO::FETCH_ASSOC);
            
            if (!$pData) continue; // Si producto no existe, saltar
            
            $total += $pData['precio'] * $it['qty'];
            
            if(!empty($it['notes'])) {
                $orderNotes .= "- " . $pData['nombre'] . ": " . $it['notes'] . "\n";
            }
        }

        // 2. Insertar Cabecera
        $stmt = $pdo->prepare("INSERT INTO pedidos_cabecera (cliente_nombre, fecha, estado, total, id_empresa, notas) VALUES (?, NOW(), 'pendiente', ?, 1, ?)");
        $stmt->execute([$input['name'], $total, $orderNotes]);
        $id = $pdo->lastInsertId();

        // 3. Insertar Detalle (Corregido precio -> precio_unitario)
        $stmtD = $pdo->prepare("INSERT INTO pedidos_detalle (id_pedido, id_producto, cantidad, precio_unitario) SELECT ?, codigo, ?, precio FROM productos WHERE codigo = ?");
        
        foreach($input['items'] as $it) {
            $stmtD->execute([$id, $it['qty'], $it['codigo']]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'id' => $id]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'check_new') {
    $lastId = $_GET['last_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT id, cliente_nombre, total FROM pedidos_cabecera WHERE id > ? AND notas LIKE 'AUTOPEDIDO%' AND estado = 'pendiente'");
    $stmt->execute([$lastId]);
    echo json_encode(['status' => 'success', 'orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'get_pending') {
    $stmt = $pdo->prepare("SELECT id, cliente_nombre, total, fecha FROM pedidos_cabecera WHERE estado = 'pendiente' AND notas LIKE 'AUTOPEDIDO%' ORDER BY id DESC");
    $stmt->execute();
    echo json_encode(['status' => 'success', 'orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'get_details') {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT d.cantidad, d.precio_unitario as precio, p.nombre, p.codigo FROM pedidos_detalle d JOIN productos p ON d.id_producto = p.codigo WHERE d.id_pedido = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success', 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'complete') {
    $id = $input['id'];
    $stmt = $pdo->prepare("UPDATE pedidos_cabecera SET estado = 'completado' WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
}
