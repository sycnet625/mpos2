<?php
// ARCHIVO: offers_api.php
// DESCRIPCIÓN: API para CRUD de Ofertas y conversión a Factura
require_once 'db.php';
require_once 'config_loader.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Acceso denegado");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- BÚSQUEDA DE PRODUCTOS (AJAX) ---
if ($action === 'search_products') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) { echo '[]'; exit; }
    $stmt = $pdo->prepare(
        "SELECT codigo, nombre, COALESCE(unidad_medida,'UND') AS unidad_medida, precio
         FROM productos WHERE activo=1 AND (nombre LIKE ? OR codigo LIKE ?)
         ORDER BY nombre LIMIT 15"
    );
    $stmt->execute(["%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// --- CREAR CLIENTE CRM INLINE ---
if ($action === 'create_client') {
    header('Content-Type: application/json');
    $nombre = trim($_POST['nombre'] ?? '');
    if (!$nombre) { echo json_encode(['ok' => false, 'error' => 'Nombre requerido']); exit; }
    $stmt = $pdo->prepare("INSERT INTO clientes (nombre, telefono, nit_ci, direccion, email, tipo_cliente, activo) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->execute([
        $nombre,
        trim($_POST['telefono']  ?? ''),
        trim($_POST['nit_ci']    ?? ''),
        trim($_POST['direccion'] ?? ''),
        trim($_POST['email']     ?? ''),
        ($_POST['tipo'] ?? 'Persona') === 'Negocio' ? 'Negocio' : 'Persona',
    ]);
    $newId = $pdo->lastInsertId();
    echo json_encode([
        'ok'       => true,
        'id'       => $newId,
        'nombre'   => $nombre,
        'telefono' => trim($_POST['telefono']  ?? ''),
        'direccion'=> trim($_POST['direccion'] ?? ''),
        'nit_ci'   => trim($_POST['nit_ci']    ?? ''),
    ]);
    exit;
}

if ($action === 'create' || $action === 'update') {
    $id = intval($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'offer'; // offer or invoice
    $numero = $_POST['numero'];
    $fecha = $_POST['fecha'];
    $cliente_nombre = $_POST['cliente_nombre'];
    $cliente_telefono = $_POST['cliente_telefono'];
    $cliente_direccion = $_POST['cliente_direccion'];
    $notas = $_POST['notas'];
    $id_cliente = intval($_POST['id_cliente'] ?? 0) ?: null;
    $admin = $_SESSION['admin_name'] ?? 'Administrador';

    $descs = $_POST['desc'];
    $cants = $_POST['cant'];
    $ums = $_POST['um'];
    $precios = $_POST['precio'];
    $importes = $_POST['importe'];

    $subtotal = 0;
    foreach ($importes as $imp) {
        $subtotal += floatval($imp);
    }
    $total = $subtotal;

    try {
        $pdo->beginTransaction();

        if ($type === 'offer') {
            if ($action === 'create') {
                $sql = "INSERT INTO ofertas (numero_oferta, fecha_emision, cliente_nombre, cliente_direccion, cliente_telefono, subtotal, total, creado_por, notas, id_cliente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $fecha, $cliente_nombre, $cliente_direccion, $cliente_telefono, $subtotal, $total, $admin, $notas, $id_cliente]);
                $mainId = $pdo->lastInsertId();
            } else {
                $sql = "UPDATE ofertas SET numero_oferta = ?, fecha_emision = ?, cliente_nombre = ?, cliente_direccion = ?, cliente_telefono = ?, subtotal = ?, total = ?, notas = ?, id_cliente = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $fecha, $cliente_nombre, $cliente_direccion, $cliente_telefono, $subtotal, $total, $notas, $id_cliente, $id]);
                $mainId = $id;
                // Borrar detalles anteriores
                $pdo->prepare("DELETE FROM ofertas_detalle WHERE id_oferta = ?")->execute([$mainId]);
            }

            $sqlDet = "INSERT INTO ofertas_detalle (id_oferta, descripcion, unidad_medida, cantidad, precio_unitario, importe) VALUES (?, ?, ?, ?, ?, ?)";
            $stmtDet = $pdo->prepare($sqlDet);
            foreach ($descs as $k => $desc) {
                if (trim($desc) === '') continue;
                $stmtDet->execute([$mainId, $desc, $ums[$k], $cants[$k], $precios[$k], $importes[$k]]);
            }
        } else {
            // Guardar como FACTURA
            $mensajero = trim($_POST['mensajero_nombre'] ?? '');
            $vehiculo  = trim($_POST['vehiculo']         ?? '');
            $costoEnvio = floatval($_POST['costo_envio'] ?? 0);
            $totalConEnvio = $subtotal + $costoEnvio;

            if ($action === 'create') {
                $sql = "INSERT INTO facturas (numero_factura, fecha_emision, cliente_nombre, cliente_direccion, cliente_telefono, subtotal, total, costo_envio, notas, mensajero_nombre, vehiculo, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $fecha, $cliente_nombre, $cliente_direccion, $cliente_telefono, $subtotal, $totalConEnvio, $costoEnvio, $notas, $mensajero, $vehiculo, $admin]);
                $mainId = $pdo->lastInsertId();
            } else {
                $sql = "UPDATE facturas SET numero_factura = ?, fecha_emision = ?, cliente_nombre = ?, cliente_direccion = ?, cliente_telefono = ?, subtotal = ?, total = ?, costo_envio = ?, notas = ?, mensajero_nombre = ?, vehiculo = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$numero, $fecha, $cliente_nombre, $cliente_direccion, $cliente_telefono, $subtotal, $totalConEnvio, $costoEnvio, $notas, $mensajero, $vehiculo, $id]);
                $mainId = $id;
                $pdo->prepare("DELETE FROM facturas_detalle WHERE id_factura = ?")->execute([$mainId]);
            }

            $sqlDet = "INSERT INTO facturas_detalle (id_factura, descripcion, unidad_medida, cantidad, precio_unitario, importe) VALUES (?, ?, ?, ?, ?, ?)";
            $stmtDet = $pdo->prepare($sqlDet);
            foreach ($descs as $k => $desc) {
                if (trim($desc) === '') continue;
                $stmtDet->execute([$mainId, $desc, $ums[$k], $cants[$k], $precios[$k], $importes[$k]]);
            }
            
            // Si veníamos de una oferta, marcarla como FACTURADA
            if (isset($_POST['convert_from_offer_id'])) {
                $offerId = intval($_POST['convert_from_offer_id']);
                $pdo->prepare("UPDATE ofertas SET estado = 'FACTURADA', id_factura_generada = ? WHERE id = ?")->execute([$mainId, $offerId]);
            }
        }

        $pdo->commit();
        header("Location: invoices.php?msg=success");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}

if ($action === 'delete_offer') {
    $id = intval($_GET['id']);
    $pdo->prepare("DELETE FROM ofertas WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM ofertas_detalle WHERE id_oferta = ?")->execute([$id]);
    header("Location: invoices.php?msg=deleted");
    exit;
}

if ($action === 'update_offer_status') {
    $id = intval($_GET['id']);
    $status = $_GET['status'];
    $pdo->prepare("UPDATE ofertas SET estado = ? WHERE id = ?")->execute([$status, $id]);
    header("Location: invoices.php?msg=updated");
    exit;
}
