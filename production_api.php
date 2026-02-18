<?php
// ARCHIVO: /var/www/palweb/api/production_api.php
// VERSIÓN: FINAL ESTABLE (API + REPORTES + CSV + DEBUG)
// CORREGIDO: Cierres de bloque catch y manejo de errores JSON

// 1. LIMPIEZA Y CONFIGURACIÓN
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpiar buffer de salida para evitar JSON corrupto
if (ob_get_length()) ob_clean();

require_once 'db.php';
// Cargar motor Kardex si existe
if (file_exists(__DIR__ . '/kardex_engine.php')) {
    require_once __DIR__ . '/kardex_engine.php';
}

header('Content-Type: application/json; charset=utf-8');

// Configuración de entorno
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);
$SUC_ID = intval($config['id_sucursal']);
$ALM_ID = intval($config['id_almacen']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

try {
    if ($method === 'POST') {

        // 1. GUARDAR / EDITAR RECETA
        if ($action === 'save_recipe') {
            $pdo->beginTransaction();
            if (empty($input['nombre_receta'])) throw new Exception("Nombre obligatorio.");

            if (empty($input['id'])) {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO recetas_cabecera (id_producto_final, nombre_receta, unidades_resultantes, costo_total_lote, costo_unitario, descripcion) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$input['id_producto_final'], $input['nombre_receta'], $input['unidades'], $input['costo_lote'], $input['costo_unitario'], $input['descripcion']]);
                $id = $pdo->lastInsertId();
            } else {
                // UPDATE
                $id = $input['id'];
                $stmt = $pdo->prepare("UPDATE recetas_cabecera SET id_producto_final=?, nombre_receta=?, unidades_resultantes=?, costo_total_lote=?, costo_unitario=?, descripcion=? WHERE id=?");
                $stmt->execute([$input['id_producto_final'], $input['nombre_receta'], $input['unidades'], $input['costo_lote'], $input['costo_unitario'], $input['descripcion'], $id]);
                $pdo->prepare("DELETE FROM recetas_detalle WHERE id_receta = ?")->execute([$id]);
            }

            // Insertar Detalles
            $stmtDet = $pdo->prepare("INSERT INTO recetas_detalle (id_receta, id_ingrediente, cantidad, costo_calculado) VALUES (?, ?, ?, ?)");
            foreach ($input['ingredientes'] as $ing) {
                $stmtDet->execute([$id, $ing['id'], $ing['cant'], $ing['costo_total']]);
            }
            $pdo->commit();
            echo json_encode(['status' => 'success']); exit;
        }

        // 2. OBTENER DETALLES (CRÍTICO PARA EDITAR)
        if ($action === 'get_details') {
            $sql = "SELECT d.id_ingrediente, d.cantidad, d.costo_calculado,
                           COALESCE(p.nombre, 'ITEM BORRADO') as nombre, 
                           COALESCE(p.unidad_medida, 'U') as unidad_medida, 
                           COALESCE(p.costo, 0) as costo_actual, 
                           (SELECT COALESCE(SUM(s.cantidad),0) FROM stock_almacen s WHERE s.id_producto=d.id_ingrediente AND s.id_almacen=?) as stock_real 
                    FROM recetas_detalle d 
                    LEFT JOIN productos p ON d.id_ingrediente = p.codigo 
                    WHERE d.id_receta = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ALM_ID, $input['id']]);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ob_clean(); // Limpieza final antes de enviar
            echo json_encode($resultados); exit;
        }

        // 3. CLONAR RECETA
        if ($action === 'clone_recipe') {
            $pdo->beginTransaction();
            $idOrg = $input['id'];
            $pdo->prepare("INSERT INTO recetas_cabecera (id_producto_final, nombre_receta, unidades_resultantes, costo_total_lote, costo_unitario, descripcion) 
                           SELECT id_producto_final, CONCAT(nombre_receta, ' (Copia)'), unidades_resultantes, costo_total_lote, costo_unitario, descripcion FROM recetas_cabecera WHERE id=?")->execute([$idOrg]);
            $newId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO recetas_detalle (id_receta, id_ingrediente, cantidad, costo_calculado) 
                           SELECT ?, id_ingrediente, cantidad, costo_calculado FROM recetas_detalle WHERE id_receta=?")->execute([$newId, $idOrg]);
            $pdo->commit();
            echo json_encode(['status' => 'success']); exit;
        }

        // 4. BORRAR RECETA
        if ($action === 'delete_recipe') {
            $pdo->prepare("DELETE FROM recetas_cabecera WHERE id = ?")->execute([$input['id']]);
            echo json_encode(['status' => 'success']); exit;
        }

        // 5. PRODUCIR LOTE
        if ($action === 'produce_batch') {
            $idReceta = $input['id'];
            $lotes = floatval($input['lotes']);
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);
            $fechaProduccion = date('Y-m-d H:i:s'); // Fecha actual para el Kardex

            $receta = $pdo->query("SELECT * FROM recetas_cabecera WHERE id=$idReceta")->fetch(PDO::FETCH_ASSOC);
            $ingredientes = $pdo->query("SELECT d.*, p.costo as costo_actual FROM recetas_detalle d JOIN productos p ON d.id_ingrediente = p.codigo WHERE d.id_receta = $idReceta")->fetchAll(PDO::FETCH_ASSOC);

            $snapshot = [];
            $costoRealTotal = 0;

            foreach ($ingredientes as $ing) {
                $consumo = $ing['cantidad'] * $lotes;
                $costoLinea = $consumo * $ing['costo_actual'];
                $costoRealTotal += $costoLinea;

                // CORREGIDO: 9 Parámetros
                $kardex->registrarMovimiento(
                    $ing['id_ingrediente'], // 1
                    $ALM_ID,                // 2
                    $SUC_ID,                // 3
                    'SALIDA',               // 4
                    -$consumo,              // 5
                    "PROD_RECETA_#$idReceta",// 6
                    $ing['costo_actual'],   // 7
                    "PRODUCCION",           // 8
                    $fechaProduccion        // 9 (Fecha)
                );
                
                $snapshot[] = ['id' => $ing['id_ingrediente'], 'cant' => $consumo, 'costo' => $ing['costo_actual']];
            }

            $unidades = $receta['unidades_resultantes'] * $lotes;
            $costoUnitarioResultante = ($unidades > 0) ? $costoRealTotal / $unidades : 0;

            // CORREGIDO: 9 Parámetros (Entrada de producto terminado)
            $kardex->registrarMovimiento(
                $receta['id_producto_final'], // 1
                $ALM_ID,                      // 2
                $SUC_ID,                      // 3
                'PRODUCCION',                 // 4
                $unidades,                    // 5
                "PROD_LOTE_#$idReceta",       // 6
                $costoUnitarioResultante,     // 7
                "PRODUCCION",                 // 8
                $fechaProduccion              // 9 (Fecha)
            );

            $pdo->prepare("UPDATE productos SET costo = ? WHERE codigo = ? AND id_empresa = ?")->execute([$costoUnitarioResultante, $receta['id_producto_final'], $EMP_ID]);

            $stmtH = $pdo->prepare("INSERT INTO producciones_historial (id_receta, nombre_receta, id_producto_final, cantidad_lotes, unidades_creadas, costo_total, usuario, json_snapshot) VALUES (?, ?, ?, ?, ?, ?, 'Admin', ?)");
            $stmtH->execute([$idReceta, $receta['nombre_receta'], $receta['id_producto_final'], $lotes, $unidades, $costoRealTotal, json_encode($snapshot)]);

            $pdo->commit();
            echo json_encode(['status' => 'success']); exit;
        }

        // 6. REVERTIR
        if ($action === 'revert_production') {
            $idHist = $input['id'];
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);
            $fechaReversion = date('Y-m-d H:i:s'); // Fecha actual para reversión

            $prod = $pdo->query("SELECT * FROM producciones_historial WHERE id=$idHist AND revertido=0")->fetch(PDO::FETCH_ASSOC);
            if (!$prod) throw new Exception("No reversible.");

            // Devolver Ingredientes
            foreach (json_decode($prod['json_snapshot'], true) as $ing) {
                // CORREGIDO: 9 Parámetros
                $kardex->registrarMovimiento(
                    $ing['id'],             // 1
                    $ALM_ID,                // 2
                    $SUC_ID,                // 3
                    'ENTRADA',              // 4
                    $ing['cant'],           // 5
                    "REVERT_PROD_#$idHist", // 6
                    $ing['costo'],          // 7
                    "REVERSION",            // 8
                    $fechaReversion         // 9 (Fecha)
                );
            }

            // Quitar Producto Terminado
            // CORREGIDO: 9 Parámetros
            $kardex->registrarMovimiento(
                $prod['id_producto_final'],   // 1
                $ALM_ID,                      // 2
                $SUC_ID,                      // 3
                'SALIDA',                     // 4
                -$prod['unidades_creadas'],   // 5
                "REVERT_PROD_#$idHist",       // 6
                0,                            // 7 (Costo ref 0 al sacar por reversión)
                "REVERSION",                  // 8
                $fechaReversion               // 9 (Fecha)
            );

            $pdo->prepare("UPDATE producciones_historial SET revertido=1 WHERE id=?")->execute([$idHist]);
            $pdo->commit();
            echo json_encode(['status' => 'success']); exit;
        }

        // 7. ANÁLISIS DE CAPACIDAD
        if ($action === 'analyze_production') {
            $idReceta = $input['id'];
            $lotes = floatval($input['lotes']);

            $sql = "SELECT d.id_ingrediente, p.nombre, p.unidad_medida, d.cantidad as req_unitario,
                    (SELECT COALESCE(SUM(s.cantidad),0) FROM stock_almacen s WHERE s.id_producto=d.id_ingrediente AND s.id_almacen=?) as stock
                    FROM recetas_detalle d LEFT JOIN productos p ON d.id_ingrediente=p.codigo WHERE d.id_receta=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ALM_ID, $idReceta]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $maxLotes = 999999;
            $faltantes = [];

            foreach ($rows as $r) {
                $totalReq = $r['req_unitario'] * $lotes;
                $capacidadIng = ($r['req_unitario'] > 0) ? $r['stock'] / $r['req_unitario'] : 999999;
                if ($capacidadIng < $maxLotes) $maxLotes = $capacidadIng;

                if ($r['stock'] < $totalReq) {
                    $faltantes[] = ['nombre' => $r['nombre'], 'req' => $totalReq, 'stock' => $r['stock'], 'falta' => $totalReq - $r['stock'], 'unidad' => $r['unidad_medida']];
                }
            }
            if (empty($rows)) $maxLotes = 0;

            echo json_encode(['max_lotes' => floor($maxLotes), 'faltantes' => $faltantes]); exit;
        }

        // 8. OBTENER REPORTES (6 TIPOS)
        if ($action === 'get_reports') {
            $data = [];
            // R1: Rentabilidad
            $data['rentabilidad'] = $pdo->query("SELECT nombre_receta, costo_unitario, p.precio, ROUND(((p.precio-r.costo_unitario)/p.precio)*100,1) as margen FROM recetas_cabecera r JOIN productos p ON r.id_producto_final=p.codigo WHERE p.precio>0 ORDER BY margen DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            // R2: Volumen
            $data['volumen'] = $pdo->query("SELECT nombre_receta, SUM(unidades_creadas) as total FROM producciones_historial WHERE revertido=0 GROUP BY nombre_receta ORDER BY total DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            // R3: Insumos
            $data['insumos'] = $pdo->query("SELECT p.nombre, COUNT(d.id_receta) as freq FROM recetas_detalle d JOIN productos p ON d.id_ingrediente=p.codigo GROUP BY p.nombre ORDER BY freq DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            // R4: Pérdidas
            $data['perdidas'] = $pdo->query("SELECT nombre_receta, costo_unitario, p.precio FROM recetas_cabecera r JOIN productos p ON r.id_producto_final=p.codigo WHERE r.costo_unitario > p.precio AND p.precio > 0")->fetchAll(PDO::FETCH_ASSOC);
            // R5: Valor
            $data['valor_inventario'] = $pdo->query("SELECT p.categoria, SUM(s.cantidad * p.costo) as valor_total FROM stock_almacen s JOIN productos p ON s.id_producto=p.codigo WHERE p.es_elaborado=1 AND s.id_almacen=$ALM_ID GROUP BY p.categoria")->fetchAll(PDO::FETCH_ASSOC);
            // R6: Recientes
            $data['recent'] = $pdo->query("SELECT DATE(fecha) as dia, COUNT(*) as lotes, SUM(costo_total) as costo FROM producciones_historial WHERE revertido=0 GROUP BY DATE(fecha) ORDER BY dia DESC LIMIT 7")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($data); exit;
        }

        // 9. HISTORIAL
        if ($action === 'get_history') {
            $stmt = $pdo->prepare("SELECT * FROM producciones_historial WHERE DATE(fecha) BETWEEN ? AND ? ORDER BY fecha DESC");
            $stmt->execute([$input['start'], $input['end']]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
        }
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Limpiamos cualquier salida previa
    ob_clean();
    // Enviamos JSON de error válido en lugar de un código HTTP puro
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>

