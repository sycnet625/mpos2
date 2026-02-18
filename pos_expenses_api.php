<?php
// ARCHIVO: /var/www/palweb/api/pos_expenses_api.php
// API para funcionalidades avanzadas de gastos

session_start();
require_once 'db.php';
header('Content-Type: application/json');

date_default_timezone_set('America/Havana');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {
        
        // ==========================================
        // COPIAR GASTOS FIJOS DEL MES ANTERIOR
        // ==========================================
        case 'copy_previous_month':
            $mesActual = intval(date('m'));
            $anioActual = intval(date('Y'));
            
            // Calcular mes anterior
            if ($mesActual == 1) {
                $mesAnterior = 12;
                $anioAnterior = $anioActual - 1;
            } else {
                $mesAnterior = $mesActual - 1;
                $anioAnterior = $anioActual;
            }
            
            // Días en el mes actual (para validar fechas)
            $diasMesActual = intval(date('t'));
            
            // Obtener gastos FIJOS del mes anterior CON EL DÍA
            $stmt = $pdo->prepare("
                SELECT concepto, monto, categoria, tipo, notas, DAY(fecha) as dia_original
                FROM gastos_historial 
                WHERE tipo = 'FIJO' 
                AND MONTH(fecha) = ? 
                AND YEAR(fecha) = ?
            ");
            $stmt->execute([$mesAnterior, $anioAnterior]);
            $gastosFijos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($gastosFijos)) {
                echo json_encode([
                    'status' => 'warning',
                    'msg' => 'No se encontraron gastos fijos en el mes anterior',
                    'copied' => 0
                ]);
                exit;
            }
            
            // Verificar si ya existen gastos fijos este mes (evitar duplicados)
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) FROM gastos_historial 
                WHERE tipo = 'FIJO' 
                AND MONTH(fecha) = ? 
                AND YEAR(fecha) = ?
            ");
            $stmtCheck->execute([$mesActual, $anioActual]);
            $existentes = $stmtCheck->fetchColumn();
            
            if ($existentes > 0 && !($input['force'] ?? false)) {
                echo json_encode([
                    'status' => 'confirm',
                    'msg' => "Ya existen $existentes gastos fijos este mes. ¿Desea agregar los del mes anterior de todas formas?",
                    'existentes' => $existentes,
                    'a_copiar' => count($gastosFijos)
                ]);
                exit;
            }
            
            $usuario = $_SESSION['user_id'] ?? 'Admin';
            $copiados = 0;
            
            $stmtInsert = $pdo->prepare("
                INSERT INTO gastos_historial (fecha, concepto, monto, categoria, tipo, id_usuario, notas) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($gastosFijos as $gasto) {
                // Usar el mismo día del mes, ajustando si el mes actual tiene menos días
                $diaOriginal = intval($gasto['dia_original']);
                $diaFinal = min($diaOriginal, $diasMesActual); // Si el día no existe, usar el último día del mes
                
                // Construir fecha con el mismo día
                $fechaActual = sprintf('%04d-%02d-%02d', $anioActual, $mesActual, $diaFinal);
                
                $stmtInsert->execute([
                    $fechaActual,
                    $gasto['concepto'],
                    $gasto['monto'],
                    $gasto['categoria'],
                    'FIJO',
                    $usuario,
                    $gasto['notas']
                ]);
                $copiados++;
            }
            
            echo json_encode([
                'status' => 'success',
                'msg' => "Se copiaron $copiados gastos fijos del mes anterior (mismas fechas)",
                'copied' => $copiados
            ]);
            break;
            
        // ==========================================
        // OBTENER RESUMEN DEL MES ANTERIOR (preview)
        // ==========================================
        case 'preview_previous_month':
            $mesActual = intval(date('m'));
            $anioActual = intval(date('Y'));
            
            if ($mesActual == 1) {
                $mesAnterior = 12;
                $anioAnterior = $anioActual - 1;
            } else {
                $mesAnterior = $mesActual - 1;
                $anioAnterior = $anioActual;
            }
            
            $stmt = $pdo->prepare("
                SELECT id, concepto, monto, categoria, DAY(fecha) as dia
                FROM gastos_historial 
                WHERE tipo = 'FIJO' 
                AND MONTH(fecha) = ? 
                AND YEAR(fecha) = ?
                ORDER BY DAY(fecha), monto DESC
            ");
            $stmt->execute([$mesAnterior, $anioAnterior]);
            $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total = array_sum(array_column($gastos, 'monto'));
            
            echo json_encode([
                'status' => 'success',
                'mes' => str_pad($mesAnterior, 2, '0', STR_PAD_LEFT) . '/' . $anioAnterior,
                'gastos' => $gastos,
                'total' => $total,
                'count' => count($gastos)
            ]);
            break;
            
        // ==========================================
        // GUARDAR GASTOS OPERATIVOS DIARIOS
        // ==========================================
        case 'save_daily_expenses':
            $fecha = $input['fecha'] ?? date('Y-m-d');
            $gastos = $input['gastos'] ?? [];
            $usuario = $_SESSION['user_id'] ?? 'Admin';
            
            if (empty($gastos)) {
                echo json_encode(['status' => 'error', 'msg' => 'No hay gastos para guardar']);
                exit;
            }
            
            $guardados = 0;
            $stmtInsert = $pdo->prepare("
                INSERT INTO gastos_historial (fecha, concepto, monto, categoria, tipo, id_usuario, notas) 
                VALUES (?, ?, ?, ?, 'VARIABLE', ?, ?)
            ");
            
            foreach ($gastos as $gasto) {
                if (floatval($gasto['monto']) > 0) {
                    $stmtInsert->execute([
                        $fecha,
                        $gasto['concepto'],
                        $gasto['monto'],
                        $gasto['categoria'] ?? 'OPERATIVO',
                        $usuario,
                        $gasto['notas'] ?? ''
                    ]);
                    $guardados++;
                }
            }
            
            echo json_encode([
                'status' => 'success',
                'msg' => "Se guardaron $guardados gastos operativos",
                'saved' => $guardados
            ]);
            break;
            
        // ==========================================
        // OBTENER GASTOS OPERATIVOS DE UN DÍA
        // ==========================================
        case 'get_daily_expenses':
            $fecha = $input['fecha'] ?? $_GET['fecha'] ?? date('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT * FROM gastos_historial 
                WHERE fecha = ? AND tipo = 'VARIABLE'
                ORDER BY id DESC
            ");
            $stmt->execute([$fecha]);
            $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'fecha' => $fecha,
                'gastos' => $gastos,
                'total' => array_sum(array_column($gastos, 'monto'))
            ]);
            break;
            
        // ==========================================
        // CRUD PLANTILLAS DE GASTOS OPERATIVOS
        // ==========================================
        case 'get_templates':
            $stmt = $pdo->query("SELECT * FROM gastos_plantillas_operativos ORDER BY orden, nombre");
            $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'plantillas' => $plantillas]);
            break;
            
        case 'save_template':
            $id = $input['id'] ?? null;
            
            // Verificar si la tabla tiene los campos de salario
            $hasPayrollFields = true;
            try {
                $pdo->query("SELECT es_salario FROM gastos_plantillas_operativos LIMIT 1");
            } catch (Exception $e) {
                $hasPayrollFields = false;
            }
            
            if ($hasPayrollFields) {
                // Tabla con campos de salario
                if ($id) {
                    $stmt = $pdo->prepare("
                        UPDATE gastos_plantillas_operativos 
                        SET nombre=?, categoria=?, monto_default=?, descripcion=?, activo=?, orden=?,
                            es_salario=?, tipo_calculo_salario=?, salario_fijo=?, porcentaje_ventas=?,
                            meta_ventas=?, porcentaje_sobre_meta=?, valor_hora=?, config_escalonado=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $input['nombre'],
                        $input['categoria'],
                        $input['monto_default'] ?? 0,
                        $input['descripcion'] ?? '',
                        $input['activo'] ?? 1,
                        $input['orden'] ?? 0,
                        $input['es_salario'] ?? 0,
                        $input['tipo_calculo_salario'] ?? 'FIJO',
                        $input['salario_fijo'] ?? 0,
                        $input['porcentaje_ventas'] ?? 0,
                        $input['meta_ventas'] ?? 0,
                        $input['porcentaje_sobre_meta'] ?? 0,
                        $input['valor_hora'] ?? 0,
                        $input['config_escalonado'] ? json_encode($input['config_escalonado']) : null,
                        $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO gastos_plantillas_operativos 
                        (nombre, categoria, monto_default, descripcion, activo, orden,
                         es_salario, tipo_calculo_salario, salario_fijo, porcentaje_ventas,
                         meta_ventas, porcentaje_sobre_meta, valor_hora, config_escalonado)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $input['nombre'],
                        $input['categoria'],
                        $input['monto_default'] ?? 0,
                        $input['descripcion'] ?? '',
                        $input['activo'] ?? 1,
                        $input['orden'] ?? 0,
                        $input['es_salario'] ?? 0,
                        $input['tipo_calculo_salario'] ?? 'FIJO',
                        $input['salario_fijo'] ?? 0,
                        $input['porcentaje_ventas'] ?? 0,
                        $input['meta_ventas'] ?? 0,
                        $input['porcentaje_sobre_meta'] ?? 0,
                        $input['valor_hora'] ?? 0,
                        $input['config_escalonado'] ? json_encode($input['config_escalonado']) : null
                    ]);
                }
            } else {
                // Tabla sin campos de salario (versión antigua)
                if ($id) {
                    $stmt = $pdo->prepare("
                        UPDATE gastos_plantillas_operativos 
                        SET nombre=?, categoria=?, monto_default=?, descripcion=?, activo=?, orden=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                        $input['nombre'],
                        $input['categoria'],
                        $input['monto_default'] ?? 0,
                        $input['descripcion'] ?? '',
                        $input['activo'] ?? 1,
                        $input['orden'] ?? 0,
                        $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO gastos_plantillas_operativos 
                        (nombre, categoria, monto_default, descripcion, activo, orden)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $input['nombre'],
                        $input['categoria'],
                        $input['monto_default'] ?? 0,
                        $input['descripcion'] ?? '',
                        $input['activo'] ?? 1,
                        $input['orden'] ?? 0
                    ]);
                }
            }
            
            $msg = $id ? 'Plantilla actualizada' : 'Plantilla creada';
            echo json_encode(['status' => 'success', 'msg' => $msg]);
            break;
            
        case 'delete_template':
            $stmt = $pdo->prepare("DELETE FROM gastos_plantillas_operativos WHERE id=?");
            $stmt->execute([$input['id']]);
            echo json_encode(['status' => 'success', 'msg' => 'Plantilla eliminada']);
            break;
            
        // ==========================================
        // OBTENER VENTAS DEL DÍA (para cálculo de salarios)
        // ==========================================
        case 'get_daily_sales':
            $fecha = $input['fecha'] ?? $_GET['fecha'] ?? date('Y-m-d');
            
            // Obtener ventas del día desde ventas_cabecera
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(total), 0) as total_ventas,
                    COUNT(*) as num_tickets,
                    COALESCE(SUM(CASE WHEN total > 0 THEN total ELSE 0 END), 0) as ventas_brutas,
                    COALESCE(SUM(CASE WHEN total < 0 THEN ABS(total) ELSE 0 END), 0) as devoluciones
                FROM ventas_cabecera 
                WHERE DATE(fecha) = ?
            ");
            $stmt->execute([$fecha]);
            $ventas = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'fecha' => $fecha,
                'total_ventas' => floatval($ventas['total_ventas']),
                'num_tickets' => intval($ventas['num_tickets']),
                'ventas_brutas' => floatval($ventas['ventas_brutas']),
                'devoluciones' => floatval($ventas['devoluciones'])
            ]);
            break;
            
        // ==========================================
        // CALCULAR SALARIO SEGÚN TIPO
        // ==========================================
        case 'calculate_salary':
            $plantillaId = $input['plantilla_id'] ?? 0;
            $ventasTotales = floatval($input['ventas_totales'] ?? 0);
            $horasTrabajadas = floatval($input['horas_trabajadas'] ?? 8);
            
            // Obtener configuración de la plantilla
            $stmt = $pdo->prepare("SELECT * FROM gastos_plantillas_operativos WHERE id = ?");
            $stmt->execute([$plantillaId]);
            $plantilla = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plantilla) {
                echo json_encode(['status' => 'error', 'msg' => 'Plantilla no encontrada']);
                exit;
            }
            
            $salarioCalculado = calcularSalario($plantilla, $ventasTotales, $horasTrabajadas);
            
            echo json_encode([
                'status' => 'success',
                'plantilla' => $plantilla['nombre'],
                'tipo_calculo' => $plantilla['tipo_calculo_salario'],
                'ventas_totales' => $ventasTotales,
                'salario_calculado' => $salarioCalculado['total'],
                'desglose' => $salarioCalculado['desglose']
            ]);
            break;
            
        // ==========================================
        // CALCULAR TODOS LOS SALARIOS DEL DÍA
        // ==========================================
        case 'calculate_all_salaries':
            $fecha = $input['fecha'] ?? date('Y-m-d');
            $ventasTotales = floatval($input['ventas_totales'] ?? 0);
            $horasPersonal = $input['horas_personal'] ?? []; // Array con id_plantilla => horas
            
            // Obtener todas las plantillas de salario activas
            $stmt = $pdo->query("
                SELECT * FROM gastos_plantillas_operativos 
                WHERE es_salario = 1 AND activo = 1 
                ORDER BY orden
            ");
            $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $salarios = [];
            $totalSalarios = 0;
            
            foreach ($plantillas as $p) {
                $horas = floatval($horasPersonal[$p['id']] ?? 8);
                $calculo = calcularSalario($p, $ventasTotales, $horas);
                
                $salarios[] = [
                    'id' => $p['id'],
                    'nombre' => $p['nombre'],
                    'tipo_calculo' => $p['tipo_calculo_salario'],
                    'salario' => $calculo['total'],
                    'desglose' => $calculo['desglose'],
                    'horas' => $horas
                ];
                
                $totalSalarios += $calculo['total'];
            }
            
            echo json_encode([
                'status' => 'success',
                'fecha' => $fecha,
                'ventas_totales' => $ventasTotales,
                'salarios' => $salarios,
                'total_salarios' => $totalSalarios
            ]);
            break;
            
        default:
            echo json_encode(['status' => 'error', 'msg' => 'Acción no válida']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}

// ==========================================
// FUNCIÓN PARA CALCULAR SALARIO
// ==========================================
function calcularSalario($plantilla, $ventasTotales, $horasTrabajadas = 8) {
    $tipo = $plantilla['tipo_calculo_salario'] ?? 'FIJO';
    $salarioFijo = floatval($plantilla['salario_fijo'] ?? 0);
    $porcentajeVentas = floatval($plantilla['porcentaje_ventas'] ?? 0);
    $metaVentas = floatval($plantilla['meta_ventas'] ?? 0);
    $porcentajeSobreMeta = floatval($plantilla['porcentaje_sobre_meta'] ?? 0);
    $valorHora = floatval($plantilla['valor_hora'] ?? 0);
    $configEscalonado = json_decode($plantilla['config_escalonado'] ?? '[]', true);
    
    $total = 0;
    $desglose = [];
    
    switch ($tipo) {
        case 'FIJO':
            // Solo monto fijo
            $total = $salarioFijo;
            $desglose[] = "Salario fijo: \${$salarioFijo}";
            break;
            
        case 'PORCENTAJE_VENTAS':
            // Solo % de ventas
            $total = $ventasTotales * ($porcentajeVentas / 100);
            $desglose[] = "{$porcentajeVentas}% de \${$ventasTotales} = \$" . number_format($total, 2);
            break;
            
        case 'FIJO_MAS_PORCENTAJE':
            // Fijo + % de ventas
            $comision = $ventasTotales * ($porcentajeVentas / 100);
            $total = $salarioFijo + $comision;
            $desglose[] = "Base fija: \${$salarioFijo}";
            $desglose[] = "Comisión ({$porcentajeVentas}%): \$" . number_format($comision, 2);
            break;
            
        case 'FIJO_MAS_PORCENTAJE_SOBRE_META':
            // Fijo + % solo sobre lo que supere la meta
            $total = $salarioFijo;
            $desglose[] = "Base fija: \${$salarioFijo}";
            
            if ($ventasTotales > $metaVentas) {
                $excedente = $ventasTotales - $metaVentas;
                $bono = $excedente * ($porcentajeSobreMeta / 100);
                $total += $bono;
                $desglose[] = "Meta: \${$metaVentas}";
                $desglose[] = "Excedente: \$" . number_format($excedente, 2);
                $desglose[] = "Bono ({$porcentajeSobreMeta}% excedente): \$" . number_format($bono, 2);
            } else {
                $desglose[] = "Meta: \${$metaVentas} (no alcanzada)";
                $desglose[] = "Bono: \$0.00";
            }
            break;
            
        case 'PORCENTAJE_ESCALONADO':
            // % diferente según rangos de venta
            $total = 0;
            if (!empty($configEscalonado)) {
                foreach ($configEscalonado as $rango) {
                    $desde = floatval($rango['desde'] ?? 0);
                    $hasta = $rango['hasta'] !== null ? floatval($rango['hasta']) : PHP_FLOAT_MAX;
                    $pct = floatval($rango['pct'] ?? 0);
                    
                    if ($ventasTotales > $desde) {
                        $montoEnRango = min($ventasTotales, $hasta) - $desde;
                        $comisionRango = $montoEnRango * ($pct / 100);
                        $total += $comisionRango;
                        $desglose[] = "Rango \${$desde}-" . ($hasta < PHP_FLOAT_MAX ? "\${$hasta}" : "∞") . " ({$pct}%): \$" . number_format($comisionRango, 2);
                    }
                }
            }
            break;
            
        case 'POR_HORA':
            // Pago por hora trabajada
            $total = $horasTrabajadas * $valorHora;
            $desglose[] = "{$horasTrabajadas} horas x \${$valorHora}/hora = \$" . number_format($total, 2);
            break;
            
        case 'PERSONALIZADO':
            // Por ahora igual que fijo, se puede expandir
            $total = $salarioFijo;
            $desglose[] = "Monto personalizado: \${$salarioFijo}";
            break;
            
        default:
            $total = floatval($plantilla['monto_default'] ?? 0);
            $desglose[] = "Monto default: \${$total}";
    }
    
    return [
        'total' => round($total, 2),
        'desglose' => $desglose
    ];
}
?>

