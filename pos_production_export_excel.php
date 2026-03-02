<?php
/**
 * ARCHIVO: pos_production_export_excel.php
 * DESCRIPCION: Exporta una receta completa a Excel (.xlsx) con datos y estructura profesional
 * NOTA: Incluye gráficos automáticos en el archivo Excel generado
 * REQUIERE: PhpSpreadsheet ^5.0
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'db.php';
require_once 'config_loader.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

$EMP_ID = intval($config['id_empresa'] ?? 1);
$SUC_ID = intval($config['id_sucursal'] ?? 1);
$ALM_ID = intval($config['id_almacen'] ?? 1);

$idReceta = intval($_GET['id'] ?? 0);

if ($idReceta <= 0) {
    die("Error: ID de receta no válido");
}

try {
    // 1. OBTENER DATOS
    $stmt = $pdo->prepare("SELECT r.*, 
                           COALESCE(p.nombre, 'Producto Borrado') as nombre_producto_final,
                           COALESCE(p.precio, 0) as precio_venta,
                           COALESCE(p.costo, 0) as costo_actual_producto,
                           p.unidad_medida, p.categoria
                           FROM recetas_cabecera r 
                           LEFT JOIN productos p ON r.id_producto_final = p.codigo 
                           WHERE r.id = ?");
    $stmt->execute([$idReceta]);
    $receta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receta) {
        die("Error: Receta no encontrada");
    }

    // Ingredientes
    $stmtIng = $pdo->prepare("SELECT d.*, 
                              COALESCE(p.nombre, 'ITEM BORRADO') as nombre_ingrediente,
                              p.unidad_medida as unidad_ingrediente,
                              p.costo as costo_actual,
                              (SELECT COALESCE(SUM(s.cantidad),0) 
                               FROM stock_almacen s 
                               WHERE s.id_producto = d.id_ingrediente AND s.id_almacen = ?) as stock_real
                              FROM recetas_detalle d 
                              LEFT JOIN productos p ON d.id_ingrediente = p.codigo 
                              WHERE d.id_receta = ?
                              ORDER BY d.costo_calculado DESC");
    $stmtIng->execute([$ALM_ID, $idReceta]);
    $ingredientes = $stmtIng->fetchAll(PDO::FETCH_ASSOC);

    // Reservas
    $stmtRes = $pdo->prepare("SELECT vc.id, vc.cliente_nombre, vc.cliente_telefono,
                              vc.fecha_reserva, vc.estado_reserva, vc.total,
                              COALESCE(vc.canal_origen, 'POS') as canal_origen,
                              SUM(vd.cantidad) as cantidad_reservada,
                              DATE(vc.fecha_reserva) as fecha_entrega
                              FROM ventas_cabecera vc
                              JOIN ventas_detalle vd ON vd.id_venta_cabecera = vc.id
                              WHERE vc.tipo_servicio = 'reserva'
                                AND vc.estado_reserva IN ('PENDIENTE','EN_PREPARACION','EN_CAMINO')
                                AND vd.id_producto = ?
                              GROUP BY vc.id
                              ORDER BY vc.fecha_reserva ASC");
    $stmtRes->execute([$receta['id_producto_final']]);
    $reservas = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

    // Historial
    $stmtHist = $pdo->prepare("SELECT * FROM producciones_historial 
                               WHERE id_receta = ? AND revertido = 0
                               ORDER BY fecha DESC LIMIT 15");
    $stmtHist->execute([$idReceta]);
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // CÁLCULOS
    $unidadesResultantes = floatval($receta['unidades_resultantes'] ?? 1);
    $costoTotalLote = floatval($receta['costo_total_lote'] ?? 0);
    $costoUnitario = floatval($receta['costo_unitario'] ?? 0);
    $precioVenta = floatval($receta['precio_venta'] ?? 0);
    
    $costoManoObra = $costoTotalLote * 0.20;
    $costoDepreciacion = $costoTotalLote * 0.05;
    $costosIndirectos = $costoTotalLote * 0.15;
    $costoTotalReal = $costoTotalLote + $costoManoObra + $costoDepreciacion + $costosIndirectos;
    $costoUnitarioReal = $unidadesResultantes > 0 ? $costoTotalReal / $unidadesResultantes : 0;
    
    $gananciaUnitaria = $precioVenta - $costoUnitario;
    $gananciaRealUnitaria = $precioVenta - $costoUnitarioReal;
    $margenBruto = ($precioVenta > 0) ? ($gananciaUnitaria / $precioVenta) * 100 : 0;
    $margenReal = ($precioVenta > 0) ? ($gananciaRealUnitaria / $precioVenta) * 100 : 0;
    
    $totalReservado = array_sum(array_column($reservas, 'cantidad_reservada'));
    $valorReservas = array_sum(array_column($reservas, 'total'));

    // ============================================
    // CREAR SPREADSHEET
    // ============================================
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    
    // ============================================
    // HOJA 1: DASHBOARD
    // ============================================
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Dashboard');
    
    // Configurar anchos de columna
    $sheet1->getColumnDimension('A')->setWidth(45);
    $sheet1->getColumnDimension('B')->setWidth(20);
    $sheet1->getColumnDimension('C')->setWidth(20);
    $sheet1->getColumnDimension('D')->setWidth(20);
    $sheet1->getColumnDimension('E')->setWidth(5);
    $sheet1->getColumnDimension('F')->setWidth(35);
    $sheet1->getColumnDimension('G')->setWidth(18);
    
    // Título
    $sheet1->mergeCells('A1:G1');
    $sheet1->setCellValue('A1', 'ANÁLISIS DE RECETA: ' . strtoupper($receta['nombre_receta']));
    $sheet1->getStyle('A1')->getFont()->setSize(18)->setBold(true);
    $sheet1->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
    $sheet1->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
    
    $sheet1->setCellValue('A2', 'Producto Final: ' . $receta['nombre_producto_final']);
    $sheet1->setCellValue('A3', 'Generado: ' . date('d/m/Y H:i:s'));
    
    // MÉTRICAS CLAVE
    $row = 5;
    $sheet1->setCellValue('A' . $row, 'MÉTRICAS CLAVE');
    $sheet1->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
    $sheet1->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
    $sheet1->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');
    $row++;
    
    $metricas = [
        ['Precio de Venta', $precioVenta, '$#,##0.00'],
        ['Costo Unitario (Materia Prima)', $costoUnitario, '$#,##0.00'],
        ['Costo Unitario Real (con MO, Deprec, Indirectos)', $costoUnitarioReal, '$#,##0.00'],
        ['Ganancia por Unidad', $gananciaRealUnitaria, '$#,##0.00'],
        ['Margen de Rentabilidad', $margenReal / 100, '0.0%'],
        ['Unidades por Lote', $unidadesResultantes, '#,##0.00'],
    ];
    
    foreach ($metricas as $m) {
        $sheet1->setCellValue('A' . $row, $m[0]);
        $sheet1->setCellValue('B' . $row, $m[1]);
        $sheet1->getStyle('A' . $row)->getFont()->setBold(true);
        $sheet1->getStyle('B' . $row)->getNumberFormat()->setFormatCode($m[2]);
        $sheet1->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }
    
    // DISTRIBUCIÓN DE COSTOS
    $row = 5;
    $sheet1->setCellValue('F' . $row, 'DISTRIBUCIÓN DE COSTOS');
    $sheet1->getStyle('F' . $row)->getFont()->setBold(true)->setSize(13);
    $sheet1->getStyle('F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('70AD47');
    $sheet1->getStyle('F' . $row)->getFont()->getColor()->setRGB('FFFFFF');
    $row++;
    
    $sheet1->setCellValue('F' . $row, 'Concepto');
    $sheet1->setCellValue('G' . $row, 'Monto');
    $sheet1->getStyle('F' . $row . ':G' . $row)->getFont()->setBold(true);
    $sheet1->getStyle('F' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
    $row++;
    
    $dataInicio = $row;
    $costosData = [
        ['Materia Prima', $costoTotalLote],
        ['Mano de Obra (20%)', $costoManoObra],
        ['Depreciación (5%)', $costoDepreciacion],
        ['Costos Indirectos (15%)', $costosIndirectos],
    ];
    
    foreach ($costosData as $c) {
        $sheet1->setCellValue('F' . $row, $c[0]);
        $sheet1->setCellValue('G' . $row, $c[1]);
        $sheet1->getStyle('G' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet1->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }
    $dataFin = $row - 1;
    
    // Total
    $sheet1->getStyle('F' . $row . ':G' . $row)->getFont()->setBold(true);
    $sheet1->getStyle('F' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC000');
    $sheet1->setCellValue('F' . $row, 'COSTO TOTAL REAL');
    $sheet1->setCellValue('G' . $row, $costoTotalReal);
    $sheet1->getStyle('G' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
    
    // PROYECCIONES
    $row = 14;
    $sheet1->setCellValue('A' . $row, 'PROYECCIONES POR VOLUMEN');
    $sheet1->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
    $sheet1->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('5B9BD5');
    $sheet1->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');
    $row++;
    
    $sheet1->setCellValue('A' . $row, 'Lotes');
    $sheet1->setCellValue('B' . $row, 'Inversión');
    $sheet1->setCellValue('C' . $row, 'Ingreso');
    $sheet1->setCellValue('D' . $row, 'Ganancia');
    $sheet1->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
    $sheet1->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
    $row++;
    
    $escenarios = [1, 5, 10, 50, 100];
    $proyDataInicio = $row;
    foreach ($escenarios as $lotes) {
        $inversion = $costoTotalReal * $lotes;
        $ingreso = ($precioVenta * $unidadesResultantes) * $lotes;
        $ganancia = $gananciaRealUnitaria * $unidadesResultantes * $lotes;
        
        $sheet1->setCellValue('A' . $row, $lotes);
        $sheet1->setCellValue('B' . $row, $inversion);
        $sheet1->setCellValue('C' . $row, $ingreso);
        $sheet1->setCellValue('D' . $row, $ganancia);
        $sheet1->getStyle('B' . $row . ':D' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet1->getStyle('B' . $row . ':D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }
    $proyDataFin = $row - 1;

    // Gráfico 1: Distribución de costos
    $pieLabels = [
        new DataSeriesValues('String', "'Dashboard'!\$F\$$dataInicio:\$F\$$dataFin", null, ($dataFin - $dataInicio + 1)),
    ];
    $pieValues = [
        new DataSeriesValues('Number', "'Dashboard'!\$G\$$dataInicio:\$G\$$dataFin", null, ($dataFin - $dataInicio + 1)),
    ];
    $pieSeries = new DataSeries(
        DataSeries::TYPE_PIECHART,
        null,
        range(0, count($pieValues) - 1),
        [],
        $pieLabels,
        $pieValues
    );
    $pieChart = new Chart(
        'chart_distribucion_costos',
        new Title('Distribución de costos'),
        new Legend(Legend::POSITION_RIGHT, null, false),
        new PlotArea(null, [$pieSeries]),
        true,
        0,
        null,
        null
    );
    $pieChart->setTopLeftPosition('I5');
    $pieChart->setBottomRightPosition('N20');
    $sheet1->addChart($pieChart);

    // Gráfico 2: Proyección financiera por lotes
    $proyCategories = [
        new DataSeriesValues('String', "'Dashboard'!\$A\$$proyDataInicio:\$A\$$proyDataFin", null, ($proyDataFin - $proyDataInicio + 1)),
    ];
    $proyLabels = [
        new DataSeriesValues('String', "'Dashboard'!\$B\$15", null, 1),
        new DataSeriesValues('String', "'Dashboard'!\$C\$15", null, 1),
        new DataSeriesValues('String', "'Dashboard'!\$D\$15", null, 1),
    ];
    $proyValues = [
        new DataSeriesValues('Number', "'Dashboard'!\$B\$$proyDataInicio:\$B\$$proyDataFin", null, ($proyDataFin - $proyDataInicio + 1)),
        new DataSeriesValues('Number', "'Dashboard'!\$C\$$proyDataInicio:\$C\$$proyDataFin", null, ($proyDataFin - $proyDataInicio + 1)),
        new DataSeriesValues('Number', "'Dashboard'!\$D\$$proyDataInicio:\$D\$$proyDataFin", null, ($proyDataFin - $proyDataInicio + 1)),
    ];
    $proySeries = new DataSeries(
        DataSeries::TYPE_BARCHART,
        DataSeries::GROUPING_CLUSTERED,
        range(0, count($proyValues) - 1),
        $proyLabels,
        $proyCategories,
        $proyValues
    );
    $proySeries->setPlotDirection(DataSeries::DIRECTION_COL);
    $proyChart = new Chart(
        'chart_proyecciones',
        new Title('Proyección por volumen (USD)'),
        new Legend(Legend::POSITION_BOTTOM, null, false),
        new PlotArea(null, [$proySeries]),
        true,
        0,
        new Title('Lotes'),
        new Title('Monto')
    );
    $proyChart->setTopLeftPosition('A22');
    $proyChart->setBottomRightPosition('H38');
    $sheet1->addChart($proyChart);

    // ============================================
    // HOJA 2: COSTOS DETALLADOS
    // ============================================
    $sheet3 = $spreadsheet->createSheet();
    $sheet3->setTitle('Análisis de Costos');
    
    $sheet3->getColumnDimension('A')->setWidth(50);
    $sheet3->getColumnDimension('B')->setWidth(20);
    $sheet3->getColumnDimension('C')->setWidth(20);
    $sheet3->getColumnDimension('D')->setWidth(18);
    
    $sheet3->mergeCells('A1:D1');
    $sheet3->setCellValue('A1', 'ANÁLISIS DETALLADO DE COSTOS');
    $sheet3->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet3->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet3->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2E7D32');
    $sheet3->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
    
    $row = 3;
    $sheet3->setCellValue('A' . $row, 'CONCEPTO');
    $sheet3->setCellValue('B' . $row, 'COSTO TOTAL');
    $sheet3->setCellValue('C' . $row, 'COSTO UNITARIO');
    $sheet3->setCellValue('D' . $row, '% DEL TOTAL');
    $sheet3->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true)->setSize(11);
    $sheet3->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C8E6C9');
    $row++;
    
    $costosDetalle = [
        ['Materia Prima', $costoTotalLote, $costoUnitario, ($costoTotalLote/$costoTotalReal)*100],
        ['Mano de Obra (Estimado 20%)', $costoManoObra, $costoManoObra/$unidadesResultantes, ($costoManoObra/$costoTotalReal)*100],
        ['Depreciación Equipos (5%)', $costoDepreciacion, $costoDepreciacion/$unidadesResultantes, ($costoDepreciacion/$costoTotalReal)*100],
        ['Costos Indirectos (15%)', $costosIndirectos, $costosIndirectos/$unidadesResultantes, ($costosIndirectos/$costoTotalReal)*100],
    ];
    
    foreach ($costosDetalle as $c) {
        $sheet3->setCellValue('A' . $row, $c[0]);
        $sheet3->setCellValue('B' . $row, $c[1]);
        $sheet3->setCellValue('C' . $row, $c[2]);
        $sheet3->setCellValue('D' . $row, $c[3] / 100);
        $sheet3->getStyle('B' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet3->getStyle('C' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet3->getStyle('D' . $row)->getNumberFormat()->setFormatCode('0.0%');
        $sheet3->getStyle('B' . $row . ':D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }
    $costosDetalleInicio = 4;
    $costosDetalleFin = $row - 1;
    
    $sheet3->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true)->setSize(11);
    $sheet3->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC000');
    $sheet3->setCellValue('A' . $row, 'COSTO TOTAL REAL');
    $sheet3->setCellValue('B' . $row, $costoTotalReal);
    $sheet3->setCellValue('C' . $row, $costoUnitarioReal);
    $sheet3->setCellValue('D' . $row, 1);
    $sheet3->getStyle('B' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
    $sheet3->getStyle('C' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
    $sheet3->getStyle('D' . $row)->getNumberFormat()->setFormatCode('0.0%');
    
    // Rentabilidad
    $row += 3;
    $sheet3->setCellValue('A' . $row, 'RENTABILIDAD');
    $sheet3->getStyle('A' . $row)->getFont()->setBold(true)->setSize(13);
    $sheet3->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1976D2');
    $sheet3->getStyle('A' . $row)->getFont()->getColor()->setRGB('FFFFFF');
    $row++;
    
    $rentabilidad = [
        ['Precio de Venta', $precioVenta],
        ['Costo de Materia Prima', $costoUnitario],
        ['Costo Total Real', $costoUnitarioReal],
        ['Ganancia Bruta (Materia Prima)', $gananciaUnitaria],
        ['Ganancia Real (Total)', $gananciaRealUnitaria],
        ['Margen Bruto (%)', $margenBruto / 100],
        ['Margen Real (%)', $margenReal / 100],
    ];
    
    foreach ($rentabilidad as $r) {
        $sheet3->setCellValue('A' . $row, $r[0]);
        $sheet3->setCellValue('B' . $row, $r[1]);
        if (strpos($r[0], '%') !== false) {
            $sheet3->getStyle('B' . $row)->getNumberFormat()->setFormatCode('0.0%');
        } else {
            $sheet3->getStyle('B' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        }
        $sheet3->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row++;
    }

    // Gráfico: Distribución del costo total por concepto
    $costosCategories = [
        new DataSeriesValues('String', "'Análisis de Costos'!\$A\$$costosDetalleInicio:\$A\$$costosDetalleFin", null, ($costosDetalleFin - $costosDetalleInicio + 1)),
    ];
    $costosValues = [
        new DataSeriesValues('Number', "'Análisis de Costos'!\$B\$$costosDetalleInicio:\$B\$$costosDetalleFin", null, ($costosDetalleFin - $costosDetalleInicio + 1)),
    ];
    $costosSeries = new DataSeries(
        DataSeries::TYPE_PIECHART,
        null,
        range(0, count($costosValues) - 1),
        [],
        $costosCategories,
        $costosValues
    );
    $costosChart = new Chart(
        'chart_analisis_costos',
        new Title('Distribución del costo total'),
        new Legend(Legend::POSITION_RIGHT, null, false),
        new PlotArea(null, [$costosSeries]),
        true,
        0,
        null,
        null
    );
    // Ubicado a la derecha para no superponerse con tablas de A:D
    $costosChart->setTopLeftPosition('F3');
    $costosChart->setBottomRightPosition('M18');
    $sheet3->addChart($costosChart);

    // ============================================
    // HOJA 3: INGREDIENTES
    // ============================================
    $sheet4 = $spreadsheet->createSheet();
    $sheet4->setTitle('Ingredientes');
    
    $sheet4->getColumnDimension('A')->setWidth(6);
    $sheet4->getColumnDimension('B')->setWidth(18);
    $sheet4->getColumnDimension('C')->setWidth(35);
    $sheet4->getColumnDimension('D')->setWidth(12);
    $sheet4->getColumnDimension('E')->setWidth(14);
    $sheet4->getColumnDimension('F')->setWidth(14);
    $sheet4->getColumnDimension('G')->setWidth(16);
    $sheet4->getColumnDimension('H')->setWidth(16);
    $sheet4->getColumnDimension('I')->setWidth(14);
    $sheet4->getColumnDimension('J')->setWidth(18);
    
    $sheet4->mergeCells('A1:J1');
    $sheet4->setCellValue('A1', 'DETALLE DE INGREDIENTES - ' . $receta['nombre_receta']);
    $sheet4->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet4->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet4->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F57C00');
    $sheet4->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
    
    $row = 3;
    $headers = ['#', 'ID', 'Nombre', 'Unidad', 'Cantidad', '% Fórmula', 'Costo Unit.', 'Costo Total', 'Stock', 'Estado'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet4->setCellValue($col . $row, $header);
        $sheet4->getStyle($col . $row)->getFont()->setBold(true)->setSize(11);
        $sheet4->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE0B2');
        $sheet4->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $col++;
    }
    $row++;
    
    $num = 1;
    foreach ($ingredientes as $ing) {
        $cantidad = floatval($ing['cantidad'] ?? 0);
        $costoUnit = floatval($ing['costo_actual'] ?? 0);
        $costoTotalIng = $cantidad * $costoUnit;
        $pctFormula = floatval($ing['pct_formula'] ?? 0);
        $stockReal = floatval($ing['stock_real'] ?? 0);
        $estadoStock = $stockReal >= $cantidad ? 'SUFICIENTE' : 'INSUFICIENTE';
        
        $sheet4->setCellValue('A' . $row, $num++);
        $sheet4->setCellValue('B' . $row, $ing['id_ingrediente']);
        $sheet4->setCellValue('C' . $row, $ing['nombre_ingrediente']);
        $sheet4->setCellValue('D' . $row, $ing['unidad_ingrediente']);
        $sheet4->setCellValue('E' . $row, $cantidad);
        $sheet4->setCellValue('F' . $row, $pctFormula / 100);
        $sheet4->setCellValue('G' . $row, $costoUnit);
        $sheet4->setCellValue('H' . $row, $costoTotalIng);
        $sheet4->setCellValue('I' . $row, $stockReal);
        $sheet4->setCellValue('J' . $row, $estadoStock);
        
        $sheet4->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0.000');
        $sheet4->getStyle('F' . $row)->getNumberFormat()->setFormatCode('0.00%');
        $sheet4->getStyle('G' . $row . ':H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet4->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet4->getStyle('E' . $row . ':I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        
        if ($estadoStock === 'INSUFICIENTE') {
            $sheet4->getStyle('J' . $row)->getFont()->getColor()->setRGB('C62828');
            $sheet4->getStyle('J' . $row)->getFont()->setBold(true);
        } else {
            $sheet4->getStyle('J' . $row)->getFont()->getColor()->setRGB('2E7D32');
        }
        
        $row++;
    }
    $ingredientesDataInicio = 4;
    $ingredientesDataFin = $row - 1;
    
    $sheet4->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true)->setSize(11);
    $sheet4->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF9C4');
    $sheet4->setCellValue('A' . $row, 'TOTAL');
    $sheet4->setCellValue('H' . $row, $costoTotalLote);
    $sheet4->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');

    if ($ingredientesDataFin >= $ingredientesDataInicio) {
        // Gráfico: Costo total por ingrediente (Top 10)
        $ingredientesChartFin = min($ingredientesDataInicio + 9, $ingredientesDataFin);
        $ingCategories = [
            new DataSeriesValues('String', "'Ingredientes'!\$C\$$ingredientesDataInicio:\$C\$$ingredientesChartFin", null, ($ingredientesChartFin - $ingredientesDataInicio + 1)),
        ];
        $ingLabels = [
            new DataSeriesValues('String', "'Ingredientes'!\$H\$3", null, 1),
        ];
        $ingValues = [
            new DataSeriesValues('Number', "'Ingredientes'!\$H\$$ingredientesDataInicio:\$H\$$ingredientesChartFin", null, ($ingredientesChartFin - $ingredientesDataInicio + 1)),
        ];
        $ingSeries = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($ingValues) - 1),
            $ingLabels,
            $ingCategories,
            $ingValues
        );
        $ingSeries->setPlotDirection(DataSeries::DIRECTION_BAR);
        $ingChart = new Chart(
            'chart_ingredientes_costos',
            new Title('Top ingredientes por costo total'),
            new Legend(Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$ingSeries]),
            true,
            0,
            new Title('Ingrediente'),
            new Title('Costo total')
        );
        // Ubicado en columnas derechas para no invadir la tabla A:J
        $ingChartTopRow = 3;
        $ingChartHeight = max(14, min(26, (($ingredientesChartFin - $ingredientesDataInicio + 1) * 2) + 6));
        $ingChartBottomRow = $ingChartTopRow + $ingChartHeight;
        $ingChart->setTopLeftPosition('L' . $ingChartTopRow);
        $ingChart->setBottomRightPosition('T' . $ingChartBottomRow);
        $sheet4->addChart($ingChart);
    }

    // ============================================
    // HOJA 4: RESERVAS
    // ============================================
    $sheet5 = $spreadsheet->createSheet();
    $sheet5->setTitle('Reservas');
    
    $sheet5->getColumnDimension('A')->setWidth(12);
    $sheet5->getColumnDimension('B')->setWidth(35);
    $sheet5->getColumnDimension('C')->setWidth(18);
    $sheet5->getColumnDimension('D')->setWidth(16);
    $sheet5->getColumnDimension('E')->setWidth(18);
    $sheet5->getColumnDimension('F')->setWidth(14);
    $sheet5->getColumnDimension('G')->setWidth(14);
    $sheet5->getColumnDimension('H')->setWidth(16);
    
    $sheet5->mergeCells('A1:H1');
    $sheet5->setCellValue('A1', 'RESERVAS PENDIENTES - ' . $receta['nombre_producto_final']);
    $sheet5->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet5->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet5->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C62828');
    $sheet5->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
    
    if (count($reservas) > 0) {
        $row = 3;
        $headers = ['#Ticket', 'Cliente', 'Teléfono', 'Fecha Entrega', 'Estado', 'Canal', 'Cantidad', 'Total'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet5->setCellValue($col . $row, $header);
            $sheet5->getStyle($col . $row)->getFont()->setBold(true)->setSize(11);
            $sheet5->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFCDD2');
            $col++;
        }
        $row++;
        
        foreach ($reservas as $res) {
            $sheet5->setCellValue('A' . $row, '#' . str_pad($res['id'], 5, '0', STR_PAD_LEFT));
            $sheet5->setCellValue('B' . $row, $res['cliente_nombre']);
            $sheet5->setCellValue('C' . $row, $res['cliente_telefono']);
            $sheet5->setCellValue('D' . $row, $res['fecha_entrega']);
            $sheet5->setCellValue('E' . $row, $res['estado_reserva']);
            $sheet5->setCellValue('F' . $row, $res['canal_origen']);
            $sheet5->setCellValue('G' . $row, $res['cantidad_reservada']);
            $sheet5->setCellValue('H' . $row, $res['total']);
            $sheet5->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $row++;
        }
        
        $row++;
        $sheet5->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true)->setSize(11);
        $sheet5->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB3B');
        $sheet5->setCellValue('A' . $row, 'TOTALES');
        $sheet5->setCellValue('G' . $row, $totalReservado);
        $sheet5->setCellValue('H' . $row, $valorReservas);
        $sheet5->getStyle('H' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
        
        $row += 2;
        $sheet5->setCellValue('A' . $row, 'Lotes necesarios para cubrir demanda:');
        $sheet5->setCellValue('B' . $row, ceil($totalReservado / $unidadesResultantes));
        $sheet5->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true)->setSize(12);
    } else {
        $sheet5->setCellValue('A3', 'No hay reservas pendientes para este producto');
    }

    // ============================================
    // HOJA 5: HISTORIAL
    // ============================================
    $sheet6 = $spreadsheet->createSheet();
    $sheet6->setTitle('Historial');
    
    $sheet6->getColumnDimension('A')->setWidth(20);
    $sheet6->getColumnDimension('B')->setWidth(14);
    $sheet6->getColumnDimension('C')->setWidth(18);
    $sheet6->getColumnDimension('D')->setWidth(16);
    $sheet6->getColumnDimension('E')->setWidth(16);
    
    $sheet6->mergeCells('A1:E1');
    $sheet6->setCellValue('A1', 'HISTORIAL DE PRODUCCIÓN');
    $sheet6->getStyle('A1')->getFont()->setSize(16)->setBold(true);
    $sheet6->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet6->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('6A1B9A');
    $sheet6->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
    
    if (count($historial) > 0) {
        $row = 3;
        $headers = ['Fecha', 'Lotes', 'Unidades', 'Costo Total', 'Costo Unit.'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet6->setCellValue($col . $row, $header);
            $sheet6->getStyle($col . $row)->getFont()->setBold(true)->setSize(11);
            $sheet6->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E1BEE7');
            $col++;
        }
        $row++;
        
        foreach ($historial as $hist) {
            $lotes = floatval($hist['cantidad_lotes'] ?? 0);
            $unidades = floatval($hist['unidades_creadas'] ?? 0);
            $costo = floatval($hist['costo_total'] ?? 0);
            $costoUnitHist = $unidades > 0 ? $costo / $unidades : 0;
            
            $sheet6->setCellValue('A' . $row, date('d/m/Y H:i', strtotime($hist['fecha'])));
            $sheet6->setCellValue('B' . $row, $lotes);
            $sheet6->setCellValue('C' . $row, $unidades);
            $sheet6->setCellValue('D' . $row, $costo);
            $sheet6->setCellValue('E' . $row, $costoUnitHist);
            
            $sheet6->getStyle('B' . $row . ':C' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet6->getStyle('D' . $row . ':E' . $row)->getNumberFormat()->setFormatCode('$#,##0.00');
            $row++;
        }
        $histDataInicio = 4;
        $histDataFin = $row - 1;
        
        $sheet6->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true)->setSize(11);
        $sheet6->getStyle('A' . $row . ':E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB3B');
        $sheet6->setCellValue('A' . $row, 'TOTALES');
        $sheet6->setCellValue('B' . $row, count($historial));
        $sheet6->setCellValue('C' . $row, array_sum(array_column($historial, 'unidades_creadas')));
        $sheet6->setCellValue('D' . $row, array_sum(array_column($historial, 'costo_total')));

        // Gráfico 3: Tendencia de unidades producidas
        $histCategories = [
            new DataSeriesValues('String', "'Historial'!\$A\$$histDataInicio:\$A\$$histDataFin", null, ($histDataFin - $histDataInicio + 1)),
        ];
        $histLabels = [
            new DataSeriesValues('String', "'Historial'!\$C\$3", null, 1),
        ];
        $histValues = [
            new DataSeriesValues('Number', "'Historial'!\$C\$$histDataInicio:\$C\$$histDataFin", null, ($histDataFin - $histDataInicio + 1)),
        ];
        $histSeries = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            range(0, count($histValues) - 1),
            $histLabels,
            $histCategories,
            $histValues
        );
        $histChart = new Chart(
            'chart_tendencia_unidades',
            new Title('Tendencia de unidades producidas'),
            new Legend(Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$histSeries]),
            true,
            0,
            new Title('Fecha'),
            new Title('Unidades')
        );
        $histChart->setTopLeftPosition('G3');
        $histChart->setBottomRightPosition('N20');
        $sheet6->addChart($histChart);
    } else {
        $sheet6->setCellValue('A3', 'No hay producciones registradas');
    }

    // ============================================
    // GUARDAR ARCHIVO
    // ============================================
    $nombreArchivo = 'Receta_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $receta['nombre_receta']) . '_' . date('Ymd_His') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->setIncludeCharts(true);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    header('Content-Type: text/plain');
    die("Error al generar el reporte: " . $e->getMessage() . "\n\n" . $e->getTraceAsString());
}
