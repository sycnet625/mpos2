<?php
// ARCHIVO: /var/www/palweb/api/pos_import.php
// VERSIÓN MULTISUCURSAL - KARDEX V2 (CORREGIDO 9 PARAMS)

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once __DIR__ . '/vendor/autoload.php';

// Cargar Configuración para valores por defecto y visualización
require_once 'config_loader.php';
require_once 'product_image_pipeline.php';
// Variables para el HTML
$DISPLAY_SUC = $config['id_sucursal'];
$DISPLAY_ALM = $config['id_almacen'];
$DISPLAY_EMP = $config['id_empresa'];

if (!file_exists('kardex_engine.php')) { die("Error Crítico: Falta 'kardex_engine.php'."); }
require_once 'kardex_engine.php';

const POS_IMPORT_HEADERS = [
    'sku',
    'nombre',
    'precio',
    'costo',
    'categoria',
    'activo',
    'columna_7',
    'fecha_vencimiento',
    'es_elaborado',
    'es_materia_prima',
    'es_servicio',
    'es_cocina',
    'columna_13',
    'columna_14',
    'columna_15',
    'columna_16',
    'columna_17',
    'stock',
];

function pos_import_template_filename(): string
{
    return 'plantilla_importacion_productos_' . date('Y-m-d') . '.xlsx';
}

function pos_import_next_product_code(PDO $pdo, int $empId, int $sucId, int $almId): string
{
    $prefix = (string)$sucId . (string)$almId;
    $lenPrefix = strlen($prefix);
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(codigo, :len + 1) AS UNSIGNED)) AS max_seq
         FROM productos
         WHERE id_empresa = :emp AND codigo LIKE CONCAT(:prefix, '%')"
    );
    $stmt->execute([':len' => $lenPrefix, ':emp' => $empId, ':prefix' => $prefix]);
    $next = (int)$stmt->fetchColumn();
    $seq = $next > 0 ? $next + 1 : 1;
    $attempt = 0;
    while (true) {
        $candidate = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT 1 FROM productos WHERE codigo = ? AND id_empresa = ?");
        $check->execute([$candidate, $empId]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
        $seq++;
        $attempt++;
        if ($attempt > 200) {
            return $prefix . '_' . time() . '_' . mt_rand(100, 999);
        }
    }
}

function pos_import_format_sheet(Spreadsheet $spreadsheet): void
{
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Importacion');
    foreach (POS_IMPORT_HEADERS as $idx => $header) {
        $col = Coordinate::stringFromColumnIndex($idx + 1);
        $sheet->setCellValue($col . '1', strtoupper(str_replace('_', ' ', $header)));
    }

    $sheet->getStyle('A1:R1')->getFont()->setBold(true);
    $sheet->getStyle('A1:R1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
    $sheet->getStyle('A1:R1')->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:R1');
    foreach (range(1, 18) as $i) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }

    $notes = $spreadsheet->createSheet();
    $notes->setTitle('Ayuda');
    $notes->setCellValue('A1', 'Columnas compatibles con la importacion');
    $notes->setCellValue('A3', 'La hoja principal debe conservar la fila 1 con estos encabezados. No renombre columnas.');
    $notes->setCellValue('A5', 'Campos usados por la importacion:');
    $notes->setCellValue('A6', 'sku');
    $notes->setCellValue('B6', 'Obligatorio. Codigo unico del producto.');
    $notes->setCellValue('A7', 'nombre');
    $notes->setCellValue('B7', 'Obligatorio. Nombre del producto.');
    $notes->setCellValue('A8', 'precio');
    $notes->setCellValue('B8', 'Precio de venta.');
    $notes->setCellValue('A9', 'costo');
    $notes->setCellValue('B9', 'Costo del producto.');
    $notes->setCellValue('A10', 'categoria');
    $notes->setCellValue('B10', 'Categoria libre.');
    $notes->setCellValue('A11', 'activo');
    $notes->setCellValue('B11', '1 activo, 0 inactivo.');
    $notes->setCellValue('A12', 'fecha_vencimiento');
            $notes->setCellValue('B12', 'YYYY-MM-DD o DD/MM/YYYY.');
            $notes->setCellValue('A13', 'es_elaborado / es_materia_prima / es_servicio / es_cocina');
            $notes->setCellValue('B13', 'Use 1 o 0.');
            $notes->setCellValue('A14', 'stock');
            $notes->setCellValue('B14', 'Stock inicial o ajuste deseado.');
    $notes->setCellValue('A16', 'SKU en blanco');
    $notes->setCellValue('B16', 'Se genera automaticamente con el prefijo sucursal+almacen.');
    $notes->getStyle('A1:B14')->getAlignment()->setWrapText(true);
    $notes->getStyle('A1:B1')->getFont()->setBold(true)->setSize(13);
    $notes->getStyle('A1:B16')->getAlignment()->setWrapText(true);
    foreach (['A' => 16, 'B' => 90] as $col => $width) {
        $notes->getColumnDimension($col)->setWidth($width);
    }

    $examples = $spreadsheet->createSheet();
    $examples->setTitle('Ejemplos');
    foreach (POS_IMPORT_HEADERS as $idx => $header) {
        $col = Coordinate::stringFromColumnIndex($idx + 1);
        $examples->setCellValue($col . '1', strtoupper(str_replace('_', ' ', $header)));
    }
    $examples->setCellValue('A2', 'EJEMPLO-001');
    $examples->setCellValue('B2', 'Pan integral');
    $examples->setCellValue('C2', 25.00);
    $examples->setCellValue('D2', 12.50);
    $examples->setCellValue('E2', 'Panadería');
    $examples->setCellValue('F2', 1);
    $examples->setCellValue('H2', '2026-12-31');
    $examples->setCellValue('I2', 1);
    $examples->setCellValue('J2', 0);
    $examples->setCellValue('K2', 0);
    $examples->setCellValue('L2', 0);
    $examples->setCellValue('R2', 50);
    $examples->setCellValue('A3', 'EJEMPLO-002');
    $examples->setCellValue('B3', 'Jugo natural');
    $examples->setCellValue('C3', 18.50);
    $examples->setCellValue('D3', 8.25);
    $examples->setCellValue('E3', 'Bebidas');
    $examples->setCellValue('F3', 1);
    $examples->setCellValue('H3', '');
    $examples->setCellValue('I3', 0);
    $examples->setCellValue('J3', 1);
    $examples->setCellValue('K3', 0);
    $examples->setCellValue('L3', 0);
    $examples->setCellValue('R3', 24);
    $examples->getStyle('A1:R3')->getAlignment()->setWrapText(true);
    $examples->getStyle('A1:R1')->getFont()->setBold(true);
    $examples->getStyle('A1:R1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('14532D');
    $examples->getStyle('A1:R1')->getFont()->getColor()->setRGB('FFFFFF');
    $examples->getStyle('A2:R3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDF4');
    $examples->freezePane('A2');
    foreach (range(1, 18) as $i) {
        $examples->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
}

function pos_import_send_template(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    pos_import_format_sheet($spreadsheet);
    $writer = new Xlsx($spreadsheet);
    $filename = pos_import_template_filename();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    $writer->save('php://output');
    exit;
}

function pos_import_normalize_header(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function pos_import_find_header_map(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $idx => $value) {
        $key = pos_import_normalize_header((string)$value);
        if ($key !== '') {
            $map[$key] = $idx;
        }
    }
    return $map;
}

function pos_import_get_value(array $row, array $headerMap, string $header, int $fallbackIndex): string
{
    if (isset($headerMap[$header])) {
        $idx = $headerMap[$header];
        return trim((string)($row[$idx] ?? ''));
    }
    return trim((string)($row[$fallbackIndex] ?? ''));
}

function pos_import_row_values(array $row): array
{
    if (isset($row['values']) && is_array($row['values'])) {
        return array_values($row['values']);
    }
    if (array_is_list($row)) {
        return $row;
    }
    return array_values($row);
}

function pos_import_row_line(array $row, int $defaultLine): int
{
    return isset($row['line']) ? max(1, (int)$row['line']) : $defaultLine;
}

function pos_import_load_rows_from_csv(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('No se pudo leer el archivo CSV.');
    }
    $delimiter = ',';
    $firstLine = fgets($handle);
    if ($firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
        $delimiter = ';';
    }
    rewind($handle);
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) > 0) {
            $rows[] = $row;
        }
    }
    fclose($handle);
    return $rows;
}

function pos_import_load_rows_from_excel(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
    }
    if (method_exists($reader, 'setReadEmptyCells')) {
        $reader->setReadEmptyCells(false);
    }
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getSheetByName('Importacion');
    if (!$sheet) {
        $sheet = $spreadsheet->getActiveSheet();
    }
    $rows = [];
    foreach ($sheet->toArray(null, true, true, false) as $row) {
        if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) > 0) {
            $rows[] = $row;
        }
    }
    $spreadsheet->disconnectWorksheets();
    return $rows;
}

function pos_import_load_batch_from_file(array $file): array
{
    if (!isset($file['error'], $file['tmp_name'], $file['name']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se recibió un archivo válido.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('El archivo no se recibió correctamente.');
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $tmp = (string)$file['tmp_name'];

    if (in_array($ext, ['xlsx', 'xls'], true)) {
        $rows = pos_import_load_rows_from_excel($tmp);
    } elseif ($ext === 'csv') {
        $rows = pos_import_load_rows_from_csv($tmp);
    } else {
        throw new RuntimeException('Formato no soportado. Use CSV, XLS o XLSX.');
    }

    if (count($rows) < 2) {
        throw new RuntimeException('La plantilla está vacía o no tiene filas de datos.');
    }

    $headerRow = $rows[0];
    $headerMap = pos_import_find_header_map($headerRow);
    $batch = [];
    $lineNo = 1;

    foreach (array_slice($rows, 1) as $row) {
        $lineNo++;
        $normalized = [];
        for ($i = 0; $i < 18; $i++) {
            $normalized[$i] = trim((string)($row[$i] ?? ''));
        }

        $mapped = [
            $normalized[0],
            $normalized[1],
            $normalized[2],
            $normalized[3],
            $normalized[4],
            $normalized[5],
            $normalized[6],
            $normalized[7],
            $normalized[8],
            $normalized[9],
            $normalized[10],
            $normalized[11],
            $normalized[12],
            $normalized[13],
            $normalized[14],
            $normalized[15],
            $normalized[16],
            $normalized[17],
        ];

        if (!empty($headerMap)) {
            $mapped[0]  = pos_import_get_value($row, $headerMap, 'sku', 0);
            $mapped[1]  = pos_import_get_value($row, $headerMap, 'nombre', 1);
            $mapped[2]  = pos_import_get_value($row, $headerMap, 'precio', 2);
            $mapped[3]  = pos_import_get_value($row, $headerMap, 'costo', 3);
            $mapped[4]  = pos_import_get_value($row, $headerMap, 'categoria', 4);
            $mapped[5]  = pos_import_get_value($row, $headerMap, 'activo', 5);
            $mapped[7]  = pos_import_get_value($row, $headerMap, 'fecha_vencimiento', 7);
            $mapped[8]  = pos_import_get_value($row, $headerMap, 'es_elaborado', 8);
            $mapped[9]  = pos_import_get_value($row, $headerMap, 'es_materia_prima', 9);
            $mapped[10] = pos_import_get_value($row, $headerMap, 'es_servicio', 10);
            $mapped[11] = pos_import_get_value($row, $headerMap, 'es_cocina', 11);
            $mapped[17] = pos_import_get_value($row, $headerMap, 'stock', 17);
        }

        if (trim((string)$mapped[0]) === '' && trim((string)$mapped[1]) === '') {
            continue;
        }
        $batch[] = [
            'line' => $lineNo,
            'values' => $mapped,
        ];
    }

    return $batch;
}

function normalizeDate($dateStr) {
    if (empty(trim($dateStr))) return null;
    $dateStr = str_replace(['/', '.'], '-', trim($dateStr));
    $parts = explode('-', $dateStr);
    if (count($parts) === 3 && strlen($parts[0]) <= 2 && strlen($parts[2]) == 4) {
        $dateStr = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }
    $timestamp = strtotime($dateStr);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function cleanNum($val) {
    if ($val === '' || $val === null) return null;
    $val = str_replace(',', '.', $val);
    return floatval(preg_replace('/[^0-9.-]/', '', $val));
}

function pos_import_process_rows(PDO $pdo, array $rows, bool $isDryRun, int $empID, int $almID, int $sucID): array
{
    $results = [];

    if (!$isDryRun) {
        $pdo->beginTransaction();
        $kardex = new KardexEngine($pdo);
    } else {
        $kardex = null;
    }

    try {
        $rowIndex = 0;
        foreach ($rows as $row) {
            $rowIndex++;
            $values = pos_import_row_values($row);
            $lineNo = pos_import_row_line($row, $rowIndex + 1);
            $rawSku = trim((string)($values[0] ?? ''));
            $nombre = trim((string)($values[1] ?? ''));
            $log = ['line' => $lineNo, 'sku' => $rawSku, 'action' => '', 'stock_upd' => false, 'props_upd' => false, 'type' => []];

            try {
                if ($nombre === '') {
                    throw new Exception("Nombre vacío.");
                }
                if ($rawSku !== '' && !preg_match('/^[A-Za-z0-9_.-]+$/', $rawSku)) {
                    throw new Exception("SKU inválido: {$rawSku}");
                }

                $generatedSku = false;
                $sku = $rawSku;
                if ($sku === '') {
                    $sku = pos_import_next_product_code($pdo, $empID, $sucID, $almID);
                    $generatedSku = true;
                }

                $newData = [
                    'precio' => cleanNum($values[2] ?? 0),
                    'costo'  => cleanNum($values[3] ?? 0),
                    'cat'    => trim((string)($values[4] ?? 'General')),
                    'act'    => intval($values[5] ?? 1),
                    'venc'   => normalizeDate($values[7] ?? ''),
                    'elab'   => intval($values[8] ?? 1),
                    'mat'    => intval($values[9] ?? 0),
                    'serv'   => intval($values[10] ?? 0),
                    'coc'    => intval($values[11] ?? 0)
                ];

                if ($newData['mat']) $log['type'][] = 'MP';
                if ($newData['serv']) $log['type'][] = 'SRV';
                if ($newData['elab']) $log['type'][] = 'ELB';
                if ($newData['coc']) $log['type'][] = 'COC';

                $stmt = $pdo->prepare("SELECT * FROM productos WHERE codigo = ? AND id_empresa = ?");
                $stmt->execute([$sku, $empID]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($current) {
                    $log['action'] = 'UPDATE';
                    if ($current['precio'] != $newData['precio'] || $current['nombre'] != $nombre) {
                        $log['props_upd'] = true;
                    }
                    if (!$isDryRun) {
                        $sql = "UPDATE productos SET nombre=?, precio=?, costo=?, categoria=?, activo=?, es_elaborado=?, es_materia_prima=?, es_servicio=?, es_cocina=?, version_row=? WHERE codigo=? AND id_empresa=?";
                        $pdo->prepare($sql)->execute([$nombre, $newData['precio'], $newData['costo'], $newData['cat'], $newData['act'], $newData['elab'], $newData['mat'], $newData['serv'], $newData['coc'], time(), $sku, $empID]);
                    }
                } else {
                    $log['action'] = 'INSERT';
                    if ($generatedSku) {
                        $log['generated_sku'] = $sku;
                    }
                    if (!$isDryRun) {
                        $sql = "INSERT INTO productos (codigo, nombre, precio, costo, categoria, activo, es_elaborado, es_materia_prima, es_servicio, es_cocina, id_empresa, version_row) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $pdo->prepare($sql)->execute([$sku, $nombre, $newData['precio'], $newData['costo'], $newData['cat'], $newData['act'], $newData['elab'], $newData['mat'], $newData['serv'], $newData['coc'], $empID, time()]);
                        product_image_pipeline_ensure_placeholder($sku, $nombre);
                    }
                }

                if (isset($values[17]) && trim((string)$values[17]) !== '') {
                    $log['stock_upd'] = true;
                    if (!$isDryRun) {
                        $stkNuevo = cleanNum($values[17]);
                        $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                        $stmtStock->execute([$sku, $almID]);
                        $stkActual = floatval($stmtStock->fetchColumn() ?: 0);
                        $diferencia = $stkNuevo - $stkActual;

                        if (abs($diferencia) > 0.0001 && $kardex) {
                            $kardex->registrarMovimiento(
                                $sku,
                                $almID,
                                $sucID,
                                'AJUSTE',
                                $diferencia,
                                "IMPORT_CSV_" . date('His'),
                                $newData['costo'],
                                "ADMIN_IMPORT",
                                date('Y-m-d H:i:s')
                            );
                        }
                    }
                }
                $log['sku'] = $sku;
                $log['status'] = 'success';
            } catch (Exception $e) {
                $log['status'] = 'error';
                $log['msg'] = $e->getMessage();
            }
            $results[] = $log;
        }

        if (!$isDryRun && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if (!$isDryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [['status' => 'error', 'msg' => 'Error General: ' . $e->getMessage()]];
    }

    return $results;
}

// --- PROCESAMIENTO AJAX ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    pos_import_send_template();
}

if (isset($_GET['action']) && $_GET['action'] === 'process_file') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $input = [
            'dry_run' => !empty($_POST['dry_run']),
            'id_empresa' => $_POST['id_empresa'] ?? 1,
            'id_almacen' => $_POST['id_almacen'] ?? 1,
            'id_sucursal' => $_POST['id_sucursal'] ?? 1,
        ];
        $rows = pos_import_load_batch_from_file($_FILES['import_file'] ?? []);
        $results = pos_import_process_rows(
            $pdo,
            $rows,
            (bool)$input['dry_run'],
            intval($input['id_empresa']),
            intval($input['id_almacen']),
            intval($input['id_sucursal'])
        );
        echo json_encode([
            'status' => 'success',
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode([
            'status' => 'error',
            'msg' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'process_batch') {
    ob_clean(); 
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $batch = is_array($input['batch'] ?? null) ? $input['batch'] : [];
    $results = pos_import_process_rows(
        $pdo,
        $batch,
        (bool)($input['dry_run'] ?? false),
        intval($input['id_empresa'] ?? 1),
        intval($input['id_almacen'] ?? 1),
        intval($input['id_sucursal'] ?? 1)
    );
    echo json_encode($results);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Importador Maestro (Kardex)</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        .card-main { max-width: 1000px; margin: 30px auto; border-radius: 12px; border:none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .console-log { 
            height: 350px; overflow-y: scroll; background: #0f172a; color: #cbd5e1; 
            font-family: 'Fira Code', 'Courier New', monospace; font-size: 0.8rem; padding: 15px; 
            border-radius: 8px; border: 1px solid #1e293b;
        }
        .log-new { color: #4ade80; } .log-upd { color: #60a5fa; } .log-stk { color: #f59e0b; } 
        .stats-box { background: #fff; border-radius: 8px; padding: 15px; border: 1px solid #e2e8f0; }
        .stat-val { font-size: 1.2rem; font-weight: bold; display: block; }
        .context-badge { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; color: #eee; border: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="card card-main">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
            <div class="d-flex align-items-center gap-3">
                <h5 class="mb-0"><i class="fas fa-microchip me-2"></i> Importador Maestro</h5>
                <div class="context-badge">
                    <i class="fas fa-building me-1 text-info"></i> Suc: <strong><?php echo $DISPLAY_SUC; ?></strong>
                    <span class="mx-2 opacity-50">|</span>
                    <i class="fas fa-warehouse me-1 text-warning"></i> Alm: <strong><?php echo $DISPLAY_ALM; ?></strong>
                </div>
            </div>
            <div class="form-check form-switch bg-primary px-3 py-1 rounded">
                <input class="form-check-input" type="checkbox" id="dryRun" checked>
                <label class="form-check-label text-white small fw-bold" for="dryRun">SIMULACIÓN</label>
            </div>
        </div>
        
        <div class="card-body p-4">
            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Empresa ID</label>
                    <input type="number" id="empId" class="form-control form-control-sm" value="<?php echo $DISPLAY_EMP; ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Almacén Destino</label>
                    <input type="number" id="almId" class="form-control form-control-sm" value="<?php echo $DISPLAY_ALM; ?>">
                </div>
                <input type="hidden" id="sucId" value="<?php echo $DISPLAY_SUC; ?>">
                
                <div class="col-md-5">
                    <label class="small fw-bold text-muted">Archivo CSV o Excel</label>
                    <input type="file" id="csvFile" class="form-control form-control-sm" accept=".csv,.xls,.xlsx">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="button" id="btnDownloadTemplate" class="btn btn-outline-secondary fw-bold shadow-sm flex-fill">
                        <i class="fas fa-file-excel me-2"></i> PLANTILLA
                    </button>
                    <button type="button" id="btnStart" class="btn btn-primary fw-bold shadow-sm flex-fill">
                        <i class="fas fa-play me-2"></i> INICIAR
                    </button>
                </div>
            </div>

            <div id="statsPanel" class="row g-2 mb-4 d-none">
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">TOTAL</small><span id="sTotal" class="stat-val">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">NUEVOS</small><span id="sNew" class="stat-val text-success">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">ACTUALIZADOS</small><span id="sUpd" class="stat-val text-info">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">ERRORES</small><span id="sErr" class="stat-val text-danger">0</span></div></div>
            </div>

            <div id="errorSummary" class="alert alert-danger d-none mb-3"></div>

            <div id="processArea" class="d-none">
                <div class="progress mb-3" style="height: 10px;"><div id="progressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: 0%"></div></div>
                <div id="consoleLog" class="console-log"></div>
            </div>
        </div>
    </div>
</div>

<script>
const log = document.getElementById('consoleLog');
let counts = { total: 0, new: 0, upd: 0, err: 0 };

function writeLog(text, colorClass = '') {
    const div = document.createElement('div');
    div.className = `mb-1 ${colorClass}`;
    div.innerHTML = `[${new Date().toLocaleTimeString()}] ${text}`;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function updateStats() {
    document.getElementById('sTotal').innerText = counts.total;
    document.getElementById('sNew').innerText = counts.new;
    document.getElementById('sUpd').innerText = counts.upd;
    document.getElementById('sErr').innerText = counts.err;
}

document.getElementById('btnStart').addEventListener('click', async () => {
    const file = document.getElementById('csvFile').files[0];
    if (!file) return alert("Seleccione un archivo CSV o Excel.");

    counts = { total: 0, new: 0, upd: 0, err: 0 };
    updateStats();
    document.getElementById('statsPanel').classList.remove('d-none');
    document.getElementById('processArea').classList.remove('d-none');
    document.getElementById('errorSummary').classList.add('d-none');
    document.getElementById('errorSummary').innerHTML = '';
    log.innerHTML = "";
    writeLog("--- ANALIZANDO ARCHIVO ---", "text-warning fw-bold");

    const form = new FormData();
    form.append('import_file', file);
    form.append('dry_run', document.getElementById('dryRun').checked ? '1' : '0');
    form.append('id_empresa', document.getElementById('empId').value);
    form.append('id_almacen', document.getElementById('almId').value);
    form.append('id_sucursal', document.getElementById('sucId').value);

    try {
        const resp = await fetch('pos_import.php?action=process_file', {
            method: 'POST',
            body: form
        });
        const payload = await resp.json();

        if (payload.status === 'error') {
            writeLog(`❌ ${payload.msg}`, 'text-danger');
            document.getElementById('errorSummary').innerHTML = `<strong>Error:</strong> ${payload.msg}`;
            document.getElementById('errorSummary').classList.remove('d-none');
        } else {
            const res = Array.isArray(payload.results) ? payload.results : [];
            const errorItems = [];
            res.forEach(item => {
                counts.total++;
                if (item.status === 'error') {
                    counts.err++;
                    const line = item.line ? `L${item.line} · ` : '';
                    const sku = item.sku ? `SKU ${item.sku}: ` : '';
                    const msg = `${line}${sku}${item.msg}`;
                    errorItems.push(msg);
                    writeLog(`❌ ${msg}`, 'text-danger');
                } else {
                    if (item.action === 'INSERT') counts.new++; else counts.upd++;
                    let msg = `${item.line ? 'L' + item.line + ' · ' : ''}SKU ${item.sku}: `;
                    if (item.generated_sku) msg += `<span class="badge bg-secondary me-1">SKU AUTO</span> `;
                    msg += (item.action === 'INSERT') ? `<span class="log-new">[NUEVO]</span> ` : `<span class="log-upd">[OK]</span> `;
                    if (item.stock_upd) msg += `<span class="log-stk">(Stock)</span>`;
                    writeLog(msg);
                }
            });
            if (errorItems.length > 0) {
                document.getElementById('errorSummary').innerHTML = `
                    <strong>Errores detectados (${errorItems.length}):</strong>
                    <div class="mt-2 small">${errorItems.map(t => `<div>• ${t}</div>`).join('')}</div>
                `;
                document.getElementById('errorSummary').classList.remove('d-none');
            }
            document.getElementById('progressBar').style.width = '100%';
            updateStats();
        }
    } catch (e) {
        writeLog("❌ Error de comunicación", "text-danger");
    }
    writeLog("--- FINALIZADO ---", "text-success fw-bold");
});

document.getElementById('btnDownloadTemplate').addEventListener('click', () => {
    window.location.href = 'pos_import.php?action=download_template';
});
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
