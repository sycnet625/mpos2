<?php
/**
 * SCRIPT: importar_recetas_excel.php
 * DESCRIPCIÓN: Importa recetas desde el archivo Excel al sistema PalWeb
 * USO: http://tusitio.com/importar_recetas_excel.php
 * 
 * PROCESO:
 * 1. Lee el archivo /home/ubuntu/Recetas Palweb 20260203.xlsx
 * 2. Importa ingredientes del Inventario si no existen
 * 3. Importa cada receta con sus ingredientes y costos
 * 4. Calcula pct_formula para modo porcentual
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

require_once 'vendor/autoload.php';
require_once 'db.php';
require_once 'config_loader.php';
require_once 'product_image_pipeline.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$EMP_ID = intval($config['id_empresa'] ?? 1);
$ARCHIVO_EXCEL = '/home/ubuntu/Recetas Palweb 20260203.xlsx';

// Configuración de importación
$CONFIG = [
    'dry_run' => false,  // true = solo simular, no insertar
    'skip_errores' => true,  // Continuar si hay errores en una receta
    'default_unidad' => 'g',  // Unidad por defecto para ingredientes
    'crear_productos_faltantes' => true,  // Crear productos si no existen
];

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function log_msg($msg, $type = 'info') {
    $colors = [
        'info' => '#333',
        'success' => 'green',
        'warning' => 'orange',
        'error' => 'red',
        'header' => '#1e3a5f'
    ];
    $color = $colors[$type] ?? '#333';
    echo "<div style='color: $color; margin: 2px 0;'>$msg</div>";
    flush();
}

function normalizar_nombre($nombre) {
    // Quitar unidades entre paréntesis al final (ej: "Agua (g)", "Azúcar blanca (g)")
    $nombre = preg_replace('/\s*\([^)]+\)\s*$/', '', trim($nombre));
    // Quitar espacios múltiples
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    // Convertir a minúsculas para comparación
    return mb_strtolower(trim($nombre));
}

function limpiar_nombre_ingrediente($nombre) {
    // Limpiar nombre del Excel para buscar en BD
    $nombre = trim($nombre);
    // Quitar sufijos de unidades
    $nombre = preg_replace('/\s*\(g\)\s*$/i', '', $nombre);
    $nombre = preg_replace('/\s*\(ml\)\s*$/i', '', $nombre);
    $nombre = preg_replace('/\s*\(u\)\s*$/i', '', $nombre);
    return trim($nombre);
}

function generar_codigo_producto($nombre, $pdo, $emp_id) {
    // Generar código único basado en el nombre
    $base = preg_replace('/[^a-zA-Z0-9]/', '', substr($nombre, 0, 8));
    $base = strtoupper($base);
    $codigo = $base;
    $contador = 1;
    
    // Verificar que no exista
    while (true) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE codigo = ? AND id_empresa = ?");
        $stmt->execute([$codigo, $emp_id]);
        if ($stmt->fetchColumn() == 0) break;
        $codigo = $base . $contador;
        $contador++;
    }
    
    return $codigo;
}

function buscar_o_crear_producto($nombre_producto, $costo, $unidad, $pdo, $emp_id, $crear = true) {
    // Limpiar nombre del ingrediente
    $nombre_limpio = limpiar_nombre_ingrediente($nombre_producto);
    $nombre_normalizado = normalizar_nombre($nombre_limpio);
    
    // Buscar por nombre exacto o similar (primero intentar exacto)
    $stmt = $pdo->prepare("SELECT codigo, nombre, costo FROM productos 
                           WHERE id_empresa = ? AND (nombre = ? OR nombre LIKE ? OR nombre LIKE ?) 
                           LIMIT 1");
    $stmt->execute([$emp_id, $nombre_limpio, '%' . $nombre_limpio . '%', '%' . $nombre_producto . '%']);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($producto) {
        return [
            'codigo' => $producto['codigo'],
            'nombre' => $producto['nombre'],
            'existe' => true,
            'costo' => $producto['costo']
        ];
    }
    
    // No existe, crear si está permitido
    if (!$crear) {
        return null;
    }
    
    $codigo = generar_codigo_producto($nombre_limpio, $pdo, $emp_id);
    
    // Determinar categoría
    $categoria = 'Insumo';
    if (stripos($nombre_producto, 'panetela') !== false || 
        stripos($nombre_producto, 'cake') !== false ||
        stripos($nombre_producto, 'minicake') !== false) {
        $categoria = 'Elaborado';
    }
    
    $stmt = $pdo->prepare("INSERT INTO productos 
        (codigo, nombre, costo, precio, unidad_medida, categoria, activo, id_empresa, es_materia_prima) 
        VALUES (?, ?, ?, ?, ?, ?, 1, ?, 1)");
    
    $precio_venta = $costo > 0 ? $costo * 1.5 : 0; // Precio estimado
    
    $stmt->execute([
        $codigo,
        substr($nombre_limpio, 0, 100),
        $costo,
        $precio_venta,
        $unidad,
        $categoria,
        $emp_id
    ]);
    product_image_pipeline_ensure_placeholder($codigo, substr($nombre_limpio, 0, 100));
    
    return [
        'codigo' => $codigo,
        'nombre' => $nombre_limpio,
        'existe' => false,
        'costo' => $costo
    ];
}

function extraer_cantidad($valor_celda) {
    // Extraer número de la celda (puede ser fórmula o valor)
    if (is_numeric($valor_celda)) {
        return floatval($valor_celda);
    }
    // Si es string con número, extraer
    if (preg_match('/(\d+\.?\d*)/', $valor_celda, $matches)) {
        return floatval($matches[1]);
    }
    return 0;
}

// ============================================
// INICIO DEL SCRIPT
// ============================================

echo "<!DOCTYPE html>
<html>
<head>
    <title>Importador de Recetas desde Excel</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #1e3a5f; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
        h2 { color: #333; margin-top: 30px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border-left: 4px solid #3b82f6; }
        .stat-value { font-size: 24px; font-weight: bold; color: #1e3a5f; }
        .stat-label { color: #666; font-size: 12px; }
        .receta-box { background: #f8fafc; margin: 10px 0; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981; }
        .receta-error { border-left-color: #ef4444; }
        .receta-warning { border-left-color: #f59e0b; }
        .ingrediente { padding: 3px 10px; font-size: 13px; color: #666; }
        .error-msg { color: #ef4444; background: #fef2f2; padding: 8px; border-radius: 4px; margin: 5px 0; }
        .warning-msg { color: #f59e0b; background: #fffbeb; padding: 8px; border-radius: 4px; margin: 5px 0; }
        pre { background: #f1f5f9; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>
    <h1>📊 Importador de Recetas desde Excel</h1>
";

// Verificar archivo
if (!file_exists($ARCHIVO_EXCEL)) {
    log_msg("❌ ERROR: No se encuentra el archivo: $ARCHIVO_EXCEL", 'error');
    exit;
}

log_msg("✅ Archivo encontrado: $ARCHIVO_EXCEL", 'success');

try {
    $spreadsheet = IOFactory::load($ARCHIVO_EXCEL);
    $sheetNames = $spreadsheet->getSheetNames();
    
    // Filtrar solo hojas de recetas (excluir Inventario y Contabilidad)
    $recetasHojas = array_filter($sheetNames, function($name) {
        return !in_array($name, ['Inventario', 'Contabilidad']);
    });
    
    log_msg("📋 Total hojas en Excel: " . count($sheetNames), 'info');
    log_msg("📝 Recetas a importar: " . count($recetasHojas), 'info');
    
    // ============================================
    // PASO 1: IMPORTAR INVENTARIO
    // ============================================
    log_msg("<br><h2>📦 Paso 1: Importando Inventario</h2>", 'header');
    
    $invSheet = $spreadsheet->getSheetByName('Inventario');
    $productosImportados = 0;
    $productosExistentes = 0;
    
    if ($invSheet) {
        $highestRow = $invSheet->getHighestRow();
        
        for ($row = 3; $row <= $highestRow; $row++) {
            $nombre = $invSheet->getCell('A' . $row)->getValue();
            if (empty($nombre)) continue;
            
            $costo = $invSheet->getCell('C' . $row)->getCalculatedValue();
            $unidad = $invSheet->getCell('D' . $row)->getValue() ?: 'g';
            $categoria = $invSheet->getCell('E' . $row)->getValue() ?: 'Insumo';
            
            if ($costo === null || $costo === '') continue;
            
            $resultado = buscar_o_crear_producto($nombre, $costo, $unidad, $pdo, $EMP_ID, $CONFIG['crear_productos_faltantes']);
            
            if ($resultado) {
                if ($resultado['existe']) {
                    $productosExistentes++;
                } else {
                    $productosImportados++;
                }
            }
        }
        
        log_msg("✅ Productos importados: $productosImportados", 'success');
        log_msg("ℹ️ Productos existentes: $productosExistentes", 'info');
    }
    
    // ============================================
    // PASO 2: IMPORTAR RECETAS
    // ============================================
    log_msg("<br><h2>🍰 Paso 2: Importando Recetas</h2>", 'header');
    
    $estadisticas = [
        'total' => 0,
        'exitosas' => 0,
        'errores' => 0,
        'advertencias' => 0,
        'ingredientes_totales' => 0
    ];
    
    foreach ($recetasHojas as $nombreReceta) {
        $estadisticas['total']++;
        $sheet = $spreadsheet->getSheetByName($nombreReceta);
        
        if (!$sheet) continue;
        
        echo "<div class='receta-box'>";
        echo "<strong>📝 $nombreReceta</strong><br>";
        
        try {
            $highestRow = $sheet->getHighestRow();
            
            // Buscar campos clave
            $filaIngredientesInicio = null;
            $filaIngredientesFin = null;
            $filaRendimiento = null;
            $filaCosto = null;
            $filaVenta = null;
            $filaMargen = null;
            $filaGananciaNeta = null;
            
            for ($row = 1; $row <= $highestRow; $row++) {
                $valorA = $sheet->getCell('A' . $row)->getValue();
                if ($valorA === null) continue;
                
                $valorALower = mb_strtolower($valorA);
                
                // Detectar inicio de ingredientes (después de "Ingredientes")
                if ($valorALower === 'ingredientes') {
                    $filaIngredientesInicio = $row + 2; // Saltar encabezado y fila vacía
                }
                
                // Detectar fin de ingredientes y otros campos
                if (strpos($valorALower, 'rendimiento') !== false || strpos($valorALower, 'masa') !== false) {
                    $filaRendimiento = $row;
                    if ($filaIngredientesFin === null) {
                        $filaIngredientesFin = $row - 1;
                    }
                }
                if (strpos($valorALower, 'costo') !== false && $filaCosto === null) {
                    $filaCosto = $row;
                }
                if ((strpos($valorALower, 'venta') !== false || strpos($valorALower, 'venta bruta') !== false) && 
                    strpos($valorALower, 'marinero') === false && $filaVenta === null) {
                    $filaVenta = $row;
                }
                if (strpos($valorALower, 'margen bruto') !== false && $filaMargen === null) {
                    $filaMargen = $row;
                }
                if (strpos($valorALower, 'ganancia neta') !== false && $filaGananciaNeta === null) {
                    $filaGananciaNeta = $row;
                }
            }
            
            // Si no se detectó fin de ingredientes, usar heurística
            if ($filaIngredientesFin === null && $filaRendimiento !== null) {
                $filaIngredientesFin = $filaRendimiento - 1;
            }
            
            // Extraer datos de la receta
            $rendimiento = 1;
            $costoTotal = 0;
            $precioVenta = 0;
            
            if ($filaRendimiento) {
                $valorC = $sheet->getCell('C' . $filaRendimiento)->getCalculatedValue();
                if ($valorC && is_numeric($valorC)) {
                    $rendimiento = floatval($valorC);
                }
            }
            
            if ($filaCosto) {
                $costoTotal = floatval($sheet->getCell('C' . $filaCosto)->getCalculatedValue() ?: 0);
            }
            
            if ($filaVenta) {
                // El precio de venta unitario está en columna B, el total en C
                $precioVenta = floatval($sheet->getCell('B' . $filaVenta)->getCalculatedValue() ?: 0);
            }
            
            // Verificar si la receta tiene datos válidos
            if ($filaIngredientesInicio === null || $filaIngredientesFin === null || $filaIngredientesFin < $filaIngredientesInicio) {
                echo "<div class='warning-msg'>⚠️ Estructura no reconocida o receta vacía</div>";
                $estadisticas['advertencias']++;
                echo "</div>";
                continue;
            }
            
            // Extraer ingredientes
            $ingredientes = [];
            $cantidadTotal = 0;
            
            for ($row = $filaIngredientesInicio; $row <= $filaIngredientesFin; $row++) {
                $nombreIng = $sheet->getCell('A' . $row)->getValue();
                $cantidad = $sheet->getCell('B' . $row)->getCalculatedValue();
                $costo = $sheet->getCell('C' . $row)->getCalculatedValue();
                
                if (empty($nombreIng)) continue;
                
                // Normalizar cantidad
                $cantidad = floatval($cantidad ?: 0);
                $costo = floatval($costo ?: 0);
                
                if ($cantidad <= 0) continue;
                
                $cantidadTotal += $cantidad;
                
                // Buscar/crear producto
                $producto = buscar_o_crear_producto($nombreIng, $costo / max($cantidad, 0.001), $CONFIG['default_unidad'], $pdo, $EMP_ID, $CONFIG['crear_productos_faltantes']);
                
                if ($producto) {
                    $ingredientes[] = [
                        'codigo' => $producto['codigo'],
                        'nombre' => $producto['nombre'],
                        'cantidad' => $cantidad,
                        'costo_total' => $costo,
                        'existe' => $producto['existe']
                    ];
                    
                    echo "<div class='ingrediente'>• {$producto['nombre']}: $cantidad g - \$" . number_format($costo, 2) . 
                         (!$producto['existe'] ? ' <span style="color:orange">(nuevo)</span>' : '') . 
                         "</div>";
                } else {
                    echo "<div class='warning-msg'>⚠️ No se pudo procesar ingrediente: $nombreIng</div>";
                }
            }
            
            if (count($ingredientes) === 0) {
                echo "<div class='error-msg'>❌ No se encontraron ingredientes válidos</div>";
                $estadisticas['errores']++;
                echo "</div>";
                continue;
            }
            
            $estadisticas['ingredientes_totales'] += count($ingredientes);
            
            // Buscar o crear producto final
            $productoFinal = buscar_o_crear_producto(
                $nombreReceta, 
                $costoTotal / max($rendimiento, 1), 
                'u', 
                $pdo, 
                $EMP_ID, 
                $CONFIG['crear_productos_faltantes']
            );
            
            if (!$productoFinal) {
                echo "<div class='error-msg'>❌ No se pudo crear el producto final</div>";
                $estadisticas['errores']++;
                echo "</div>";
                continue;
            }
            
            // Actualizar precio de venta del producto final
            if (!$CONFIG['dry_run'] && $precioVenta > 0) {
                $stmt = $pdo->prepare("UPDATE productos SET precio = ?, es_elaborado = 1 WHERE codigo = ? AND id_empresa = ?");
                $stmt->execute([$precioVenta, $productoFinal['codigo'], $EMP_ID]);
            }
            
            // Insertar receta
            if (!$CONFIG['dry_run']) {
                // Verificar si ya existe
                $stmt = $pdo->prepare("SELECT id FROM recetas_cabecera WHERE nombre_receta = ?");
                $stmt->execute([$nombreReceta]);
                $existente = $stmt->fetchColumn();
                
                if ($existente) {
                    // Actualizar receta existente
                    $stmt = $pdo->prepare("UPDATE recetas_cabecera SET 
                        id_producto_final = ?, unidades_resultantes = ?, 
                        costo_total_lote = ?, costo_unitario = ?, descripcion = ?
                        WHERE id = ?");
                    $stmt->execute([
                        $productoFinal['codigo'],
                        $rendimiento,
                        $costoTotal,
                        $costoTotal / max($rendimiento, 1),
                        "Importado desde Excel el " . date('Y-m-d'),
                        $existente
                    ]);
                    $recetaId = $existente;
                    
                    // Eliminar detalles anteriores
                    $pdo->prepare("DELETE FROM recetas_detalle WHERE id_receta = ?")->execute([$recetaId]);
                } else {
                    // Crear nueva receta
                    $stmt = $pdo->prepare("INSERT INTO recetas_cabecera 
                        (id_producto_final, nombre_receta, unidades_resultantes, costo_total_lote, costo_unitario, descripcion) 
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $productoFinal['codigo'],
                        $nombreReceta,
                        $rendimiento,
                        $costoTotal,
                        $costoTotal / max($rendimiento, 1),
                        "Importado desde Excel el " . date('Y-m-d')
                    ]);
                    $recetaId = $pdo->lastInsertId();
                }
                
                // Insertar ingredientes
                $stmtDet = $pdo->prepare("INSERT INTO recetas_detalle 
                    (id_receta, id_ingrediente, cantidad, costo_calculado, pct_formula) 
                    VALUES (?, ?, ?, ?, ?)");
                
                foreach ($ingredientes as $ing) {
                    $pctFormula = $cantidadTotal > 0 ? ($ing['cantidad'] / $cantidadTotal) * 100 : 0;
                    $stmtDet->execute([
                        $recetaId,
                        $ing['codigo'],
                        $ing['cantidad'],
                        $ing['costo_total'],
                        $pctFormula
                    ]);
                }
                
                echo "<div style='color:green; margin-top:10px;'>✅ Receta importada: $rendimiento unidades, Costo: \$" . 
                     number_format($costoTotal, 2) . ", Venta: \$" . number_format($precioVenta, 2) . "</div>";
                $estadisticas['exitosas']++;
            } else {
                echo "<div style='color:blue; margin-top:10px;'>[DRY RUN] Simulación: $rendimiento unidades, Costo: \$" . 
                     number_format($costoTotal, 2) . 
                     ", Venta: \$" . number_format($precioVenta, 2) . "</div>";
                $estadisticas['exitosas']++;
            }
            
        } catch (Exception $e) {
            echo "<div class='error-msg'>❌ Error: " . $e->getMessage() . "</div>";
            $estadisticas['errores']++;
        }
        
        echo "</div>";
    }
    
    // ============================================
    // RESUMEN FINAL
    // ============================================
    echo "<br><h2>📊 Resumen de Importación</h2>";
    echo "<div class='stats'>";
    echo "<div class='stat-box'><div class='stat-value'>{$estadisticas['total']}</div><div class='stat-label'>Recetas Procesadas</div></div>";
    echo "<div class='stat-box'><div class='stat-value'>{$estadisticas['exitosas']}</div><div class='stat-label'>Importadas Exitosamente</div></div>";
    echo "<div class='stat-box'><div class='stat-value'>{$estadisticas['errores']}</div><div class='stat-label'>Con Errores</div></div>";
    echo "<div class='stat-box'><div class='stat-value'>{$estadisticas['ingredientes_totales']}</div><div class='stat-label'>Ingredientes Importados</div></div>";
    echo "</div>";
    
    if ($CONFIG['dry_run']) {
        echo "<div class='warning-msg'>⚠️ ESTO FUE UNA SIMULACIÓN (dry_run = true). No se insertaron datos reales.</div>";
        echo "<p>Para ejecutar la importación real, cambia <code>'dry_run' => false</code> en la configuración.</p>";
    }
    
} catch (Exception $e) {
    log_msg("❌ ERROR CRÍTICO: " . $e->getMessage(), 'error');
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "</div></body></html>";
