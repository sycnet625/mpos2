<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(600);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/kardex_engine.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const POS_PURCHASE_IMPORT_HEADERS = [
    'sku',
    'empresa',
    'sucursal',
    'almacen',
    'nombre',
    'cantidad',
    'precio_compra',
    'precio_venta',
    'precio_mayorista',
    'categoria',
];

function purchase_import_bootstrap_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_import_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        user_ref VARCHAR(100) NOT NULL,
        existing_mode VARCHAR(20) NOT NULL DEFAULT 'full',
        total_rows INT NOT NULL DEFAULT 0,
        created_products INT NOT NULL DEFAULT 0,
        updated_products INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'APLICADA',
        purchases_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        reverted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_import_run_rows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        line_no INT NOT NULL,
        empresa_id INT NOT NULL,
        sucursal_id INT NOT NULL,
        almacen_id INT NOT NULL,
        source_sku VARCHAR(50) NULL,
        target_sku VARCHAR(50) NOT NULL,
        resolution_mode VARCHAR(30) NOT NULL,
        product_action VARCHAR(20) NOT NULL,
        purchase_id INT NOT NULL,
        quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
        cost_price DECIMAL(15,2) NOT NULL DEFAULT 0,
        previous_product_json LONGTEXT NULL,
        new_product_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_run_id (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

purchase_import_bootstrap_tables($pdo);

function purchase_import_normalize(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim($value);
}

function purchase_import_number(mixed $value): float
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    if (substr_count($value, ',') > 0 && substr_count($value, '.') > 0) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } else {
        $value = str_replace(',', '.', $value);
    }
    return (float)$value;
}

function purchase_import_find_header_map(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $index => $value) {
        $normalized = str_replace(' ', '_', purchase_import_normalize((string)$value));
        if ($normalized !== '') {
            $map[$normalized] = $index;
        }
    }
    return $map;
}

function purchase_import_get_cell(array $row, array $headerMap, string $header): string
{
    $index = $headerMap[$header] ?? null;
    if ($index === null) {
        return '';
    }
    return trim((string)($row[$index] ?? ''));
}

function purchase_import_load_locations(PDO $pdo): array
{
    $empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $sucursales = $pdo->query("SELECT id, id_empresa, nombre FROM sucursales ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $almacenes = $pdo->query("SELECT id, id_sucursal, nombre FROM almacenes ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

    $empresaById = [];
    $empresaByName = [];
    foreach ($empresas as $empresa) {
        $empresaById[(int)$empresa['id']] = $empresa;
        $empresaByName[purchase_import_normalize((string)$empresa['nombre'])] = $empresa;
    }

    $sucursalById = [];
    $sucursalByEmpresaName = [];
    foreach ($sucursales as $sucursal) {
        $sucursalById[(int)$sucursal['id']] = $sucursal;
        $sucursalByEmpresaName[(int)$sucursal['id_empresa'] . '|' . purchase_import_normalize((string)$sucursal['nombre'])] = $sucursal;
    }

    $almacenById = [];
    $almacenBySucursalName = [];
    foreach ($almacenes as $almacen) {
        $almacenById[(int)$almacen['id']] = $almacen;
        $almacenBySucursalName[(int)$almacen['id_sucursal'] . '|' . purchase_import_normalize((string)$almacen['nombre'])] = $almacen;
    }

    return [
        'empresaById' => $empresaById,
        'empresaByName' => $empresaByName,
        'sucursalById' => $sucursalById,
        'sucursalByEmpresaName' => $sucursalByEmpresaName,
        'almacenById' => $almacenById,
        'almacenBySucursalName' => $almacenBySucursalName,
    ];
}

function purchase_import_resolve_empresa(string $value, array $maps): ?array
{
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value) && isset($maps['empresaById'][(int)$value])) {
        return $maps['empresaById'][(int)$value];
    }
    return $maps['empresaByName'][purchase_import_normalize($value)] ?? null;
}

function purchase_import_resolve_sucursal(string $value, int $empresaId, array $maps): ?array
{
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value) && isset($maps['sucursalById'][(int)$value])) {
        $row = $maps['sucursalById'][(int)$value];
        return ((int)$row['id_empresa'] === $empresaId) ? $row : null;
    }
    return $maps['sucursalByEmpresaName'][$empresaId . '|' . purchase_import_normalize($value)] ?? null;
}

function purchase_import_resolve_almacen(string $value, int $sucursalId, array $maps): ?array
{
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value) && isset($maps['almacenById'][(int)$value])) {
        $row = $maps['almacenById'][(int)$value];
        return ((int)$row['id_sucursal'] === $sucursalId) ? $row : null;
    }
    return $maps['almacenBySucursalName'][$sucursalId . '|' . purchase_import_normalize($value)] ?? null;
}

function purchase_import_load_products(PDO $pdo): array
{
    $rows = $pdo->query("SELECT codigo, nombre, id_empresa, categoria, costo, precio, precio_mayorista, activo FROM productos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $byCompany = [];
    foreach ($rows as $row) {
        $row['normalized_name'] = purchase_import_normalize((string)$row['nombre']);
        $byCompany[(int)$row['id_empresa']][] = $row;
    }
    return $byCompany;
}

function purchase_import_similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    similar_text($a, $b, $pct);
    $score = (float)$pct;
    if ($a === $b) {
        $score += 40;
    } elseif (str_contains($a, $b) || str_contains($b, $a)) {
        $score += 15;
    }
    $tokensA = array_values(array_filter(explode(' ', $a)));
    $tokensB = array_values(array_filter(explode(' ', $b)));
    if ($tokensA && $tokensB) {
        $common = array_intersect($tokensA, $tokensB);
        $score += count($common) * 8;
    }
    return $score;
}

function purchase_import_find_candidates(string $name, array $productsForCompany): array
{
    $normalized = purchase_import_normalize($name);
    $scored = [];
    foreach ($productsForCompany as $product) {
        $score = purchase_import_similarity($normalized, (string)$product['normalized_name']);
        if ($score >= 18) {
            $product['score'] = round($score, 1);
            $scored[] = $product;
        }
    }
    usort($scored, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['nombre'], (string)$b['nombre']));
    return array_slice($scored, 0, 12);
}

function purchase_import_default_sale(float $cost): float
{
    return round($cost * 1.30, 2);
}

function purchase_import_default_wholesale(float $cost): float
{
    return round($cost * 1.10, 2);
}

function purchase_import_generate_next_sku(PDO $pdo, int $empresaId, array &$reserved = []): string
{
    static $lastNumericSkuByCompany = [];

    if (!isset($lastNumericSkuByCompany[$empresaId])) {
        $stmt = $pdo->prepare("
            SELECT codigo
            FROM productos
            WHERE id_empresa = ?
            ORDER BY CHAR_LENGTH(codigo) DESC, codigo DESC
        ");
        $stmt->execute([$empresaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $max = 0;
        foreach ($rows as $codigo) {
            $codigo = trim((string)$codigo);
            if ($codigo === '') {
                continue;
            }
            if (preg_match('/(\d+)$/', $codigo, $m)) {
                $num = (int)$m[1];
                if ($num > $max) {
                    $max = $num;
                }
            }
        }
        $lastNumericSkuByCompany[$empresaId] = $max;
    }

    $reserved[$empresaId] = $reserved[$empresaId] ?? [];

    do {
        $lastNumericSkuByCompany[$empresaId]++;
        $candidate = (string)$lastNumericSkuByCompany[$empresaId];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE id_empresa = ? AND codigo = ?");
        $stmt->execute([$empresaId, $candidate]);
        $exists = ((int)$stmt->fetchColumn() > 0);
        $alreadyReserved = isset($reserved[$empresaId][$candidate]);
    } while ($exists || $alreadyReserved);

    $reserved[$empresaId][$candidate] = true;
    return $candidate;
}

function purchase_import_build_product_diff(?array $existing, array $incoming): array
{
    if (!$existing) {
        return [];
    }
    $fields = [
        'nombre' => ['old' => (string)$existing['nombre'], 'new' => (string)$incoming['nombre']],
        'costo' => ['old' => (float)$existing['costo'], 'new' => (float)$incoming['precio_compra']],
        'precio' => ['old' => (float)$existing['precio'], 'new' => (float)$incoming['precio_venta']],
        'precio_mayorista' => ['old' => (float)$existing['precio_mayorista'], 'new' => (float)$incoming['precio_mayorista']],
        'categoria' => ['old' => (string)($existing['categoria'] ?? ''), 'new' => (string)$incoming['categoria']],
        'activo' => ['old' => (int)($existing['activo'] ?? 1), 'new' => 1],
    ];
    $diff = [];
    foreach ($fields as $field => $values) {
        if ((string)$values['old'] !== (string)$values['new']) {
            $diff[$field] = $values;
        }
    }
    return $diff;
}

function purchase_import_ensure_category(PDO $pdo, string $category, array &$cache): void
{
    $normalized = purchase_import_normalize($category);
    if ($normalized === '' || isset($cache[$normalized])) {
        return;
    }
    $stmt = $pdo->prepare("SELECT id FROM categorias WHERE LOWER(TRIM(nombre)) = ? LIMIT 1");
    $stmt->execute([function_exists('mb_strtolower') ? mb_strtolower(trim($category), 'UTF-8') : strtolower(trim($category))]);
    if (!$stmt->fetchColumn()) {
        $insert = $pdo->prepare("INSERT INTO categorias (nombre, emoji, color) VALUES (?, ?, ?)");
        $insert->execute([$category, '📦', 'primary']);
    }
    $cache[$normalized] = true;
}

function purchase_import_parse_uploaded_file(string $tmpFile, PDO $pdo): array
{
    $spreadsheet = IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray('', true, true, false);
    if (count($rows) < 2) {
        throw new RuntimeException('El archivo no tiene filas suficientes.');
    }

    $headerMap = purchase_import_find_header_map($rows[0]);
    $missing = [];
    foreach (POS_PURCHASE_IMPORT_HEADERS as $requiredHeader) {
        if (!array_key_exists($requiredHeader, $headerMap)) {
            $missing[] = $requiredHeader;
        }
    }
    if ($missing) {
        throw new RuntimeException('Faltan columnas requeridas: ' . implode(', ', $missing));
    }

    $maps = purchase_import_load_locations($pdo);
    $productsByCompany = purchase_import_load_products($pdo);
    $parsedRows = [];
    $errors = [];
    $needsResolution = 0;
    $seenSkuByCompany = [];
    $reservedGeneratedSkus = [];

    foreach (array_slice($rows, 1) as $index => $row) {
        $line = $index + 2;
        $sku = purchase_import_get_cell($row, $headerMap, 'sku');
        $nombre = purchase_import_get_cell($row, $headerMap, 'nombre');
        if ($sku === '' && $nombre === '') {
            continue;
        }

        $empresaValue = purchase_import_get_cell($row, $headerMap, 'empresa');
        $sucursalValue = purchase_import_get_cell($row, $headerMap, 'sucursal');
        $almacenValue = purchase_import_get_cell($row, $headerMap, 'almacen');
        $cantidadValue = purchase_import_get_cell($row, $headerMap, 'cantidad');
        $precioCompraValue = purchase_import_get_cell($row, $headerMap, 'precio_compra');
        $precioVentaValue = purchase_import_get_cell($row, $headerMap, 'precio_venta');
        $precioMayoristaValue = purchase_import_get_cell($row, $headerMap, 'precio_mayorista');
        $categoria = purchase_import_get_cell($row, $headerMap, 'categoria');

        foreach ([
            'empresa' => $empresaValue,
            'sucursal' => $sucursalValue,
            'almacen' => $almacenValue,
            'nombre' => $nombre,
            'cantidad' => $cantidadValue,
            'precio_compra' => $precioCompraValue,
            'categoria' => $categoria,
        ] as $field => $value) {
            if (trim((string)$value) === '') {
                $errors[] = "Fila {$line}: {$field} no puede estar en blanco.";
                continue 2;
            }
        }

        $empresa = purchase_import_resolve_empresa($empresaValue, $maps);
        if (!$empresa) {
            $errors[] = "Fila {$line}: empresa inválida ({$empresaValue}).";
            continue;
        }
        $sucursal = purchase_import_resolve_sucursal($sucursalValue, (int)$empresa['id'], $maps);
        if (!$sucursal) {
            $errors[] = "Fila {$line}: sucursal inválida ({$sucursalValue}) para la empresa {$empresa['nombre']}.";
            continue;
        }
        $almacen = purchase_import_resolve_almacen($almacenValue, (int)$sucursal['id'], $maps);
        if (!$almacen) {
            $errors[] = "Fila {$line}: almacén inválido ({$almacenValue}) para la sucursal {$sucursal['nombre']}.";
            continue;
        }

        $cantidad = purchase_import_number($cantidadValue);
        $precioCompra = purchase_import_number($precioCompraValue);
        if ($cantidad <= 0) {
            $errors[] = "Fila {$line}: cantidad inválida.";
            continue;
        }
        if ($precioCompra <= 0) {
            $errors[] = "Fila {$line}: precio_compra inválido.";
            continue;
        }

        $precioVentaDefaulted = trim($precioVentaValue) === '';
        $precioMayoristaDefaulted = trim($precioMayoristaValue) === '';
        $precioVenta = $precioVentaDefaulted ? purchase_import_default_sale($precioCompra) : purchase_import_number($precioVentaValue);
        $precioMayorista = $precioMayoristaDefaulted ? purchase_import_default_wholesale($precioCompra) : purchase_import_number($precioMayoristaValue);
        if ($precioVenta <= 0 || $precioMayorista <= 0) {
            $errors[] = "Fila {$line}: precios inválidos después del cálculo.";
            continue;
        }

        $resolutionMode = 'sku_create';
        $resolvedSku = $sku;
        $suggestions = [];
        $selectedCandidate = '';
        $matchedProduct = null;
        $duplicateKey = (int)$empresa['id'] . '|' . $sku;
        $companyProducts = $productsByCompany[(int)$empresa['id']] ?? [];

        if ($sku !== '') {
            if (isset($seenSkuByCompany[$duplicateKey])) {
                $errors[] = "Fila {$line}: SKU duplicado dentro del mismo Excel ({$sku}) para la empresa {$empresa['nombre']}.";
                continue;
            }
            $seenSkuByCompany[$duplicateKey] = true;
            foreach ($companyProducts as $product) {
                if ((string)$product['codigo'] === $sku) {
                    $matchedProduct = $product;
                    $resolutionMode = 'sku_update';
                    break;
                }
            }
        } else {
            $resolutionMode = 'name_match_required';
            $suggestions = purchase_import_find_candidates($nombre, $companyProducts);
            if (!empty($suggestions)) {
                $selectedCandidate = (string)$suggestions[0]['codigo'];
                $matchedProduct = $suggestions[0];
            }
            $resolvedSku = '';
            $needsResolution++;
        }

        $incoming = [
            'nombre' => $nombre,
            'precio_compra' => $precioCompra,
            'precio_venta' => $precioVenta,
            'precio_mayorista' => $precioMayorista,
            'categoria' => $categoria,
        ];

        $parsedRows[] = [
            'line' => $line,
            'sku' => $sku,
            'resolved_sku' => $resolvedSku,
            'empresa_id' => (int)$empresa['id'],
            'empresa_nombre' => (string)$empresa['nombre'],
            'sucursal_id' => (int)$sucursal['id'],
            'sucursal_nombre' => (string)$sucursal['nombre'],
            'almacen_id' => (int)$almacen['id'],
            'almacen_nombre' => (string)$almacen['nombre'],
            'nombre' => $nombre,
            'cantidad' => $cantidad,
            'precio_compra' => $precioCompra,
            'precio_venta' => $precioVenta,
            'precio_mayorista' => $precioMayorista,
            'precio_venta_defaulted' => $precioVentaDefaulted,
            'precio_mayorista_defaulted' => $precioMayoristaDefaulted,
            'categoria' => $categoria,
            'resolution_mode' => $resolutionMode,
            'selected_candidate' => $selectedCandidate,
            'suggestions' => array_map(static fn(array $item): array => [
                'codigo' => (string)$item['codigo'],
                'nombre' => (string)$item['nombre'],
                'categoria' => (string)($item['categoria'] ?? ''),
                'score' => (float)$item['score'],
            ], $suggestions),
            'matched_product' => $matchedProduct ? [
                'codigo' => (string)$matchedProduct['codigo'],
                'nombre' => (string)$matchedProduct['nombre'],
                'categoria' => (string)($matchedProduct['categoria'] ?? ''),
                'costo' => (float)($matchedProduct['costo'] ?? 0),
                'precio' => (float)($matchedProduct['precio'] ?? 0),
                'precio_mayorista' => (float)($matchedProduct['precio_mayorista'] ?? 0),
                'activo' => (int)($matchedProduct['activo'] ?? 1),
            ] : null,
            'diff' => purchase_import_build_product_diff($matchedProduct, $incoming),
        ];
    }

    return [
        'rows' => $parsedRows,
        'errors' => $errors,
        'needs_resolution' => $needsResolution,
    ];
}

function purchase_import_build_template(): void
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(POS_PURCHASE_IMPORT_HEADERS, null, 'A1');
    $sheet->fromArray([
        'SKU-001',
        'Empresa Demo',
        'Sucursal Centro',
        'Almacén Principal',
        'Producto Demo',
        12,
        100,
        '',
        '',
        'General',
    ], null, 'A2');
    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_importar_compras.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function purchase_import_restore_product(PDO $pdo, array $rowLog): void
{
    $previous = json_decode((string)($rowLog['previous_product_json'] ?? ''), true);
    $current = json_decode((string)($rowLog['new_product_json'] ?? ''), true);
    $empresaId = (int)$rowLog['empresa_id'];
    $targetSku = (string)$rowLog['target_sku'];

    if (is_array($previous) && !empty($previous)) {
        $stmt = $pdo->prepare("UPDATE productos SET nombre = ?, costo = ?, precio = ?, precio_mayorista = ?, categoria = ?, activo = ?, version_row = ? WHERE codigo = ? AND id_empresa = ?");
        $stmt->execute([
            $previous['nombre'],
            $previous['costo'],
            $previous['precio'],
            $previous['precio_mayorista'],
            $previous['categoria'],
            $previous['activo'],
            time(),
            $targetSku,
            $empresaId,
        ]);
        return;
    }

    try {
        $delete = $pdo->prepare("DELETE FROM productos WHERE codigo = ? AND id_empresa = ?");
        $delete->execute([$targetSku, $empresaId]);
    } catch (Throwable $e) {
        if (is_array($current) && !empty($current)) {
            $stmt = $pdo->prepare("UPDATE productos SET activo = 0, version_row = ? WHERE codigo = ? AND id_empresa = ?");
            $stmt->execute([time(), $targetSku, $empresaId]);
        }
    }
}

$notice = '';
$noticeType = 'info';
$preview = null;
$importResult = null;
$recentRuns = $pdo->query("SELECT * FROM purchase_import_runs ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$runDetail = null;
$previewGroups = [];

if (isset($_GET['run_detail']) && ctype_digit((string)$_GET['run_detail'])) {
    $detailRunId = (int)$_GET['run_detail'];
    $stmtRunDetail = $pdo->prepare("SELECT * FROM purchase_import_runs WHERE id = ? LIMIT 1");
    $stmtRunDetail->execute([$detailRunId]);
    $runDetailHead = $stmtRunDetail->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($runDetailHead) {
        $stmtRunRows = $pdo->prepare("SELECT * FROM purchase_import_run_rows WHERE run_id = ? ORDER BY id ASC");
        $stmtRunRows->execute([$detailRunId]);
        $runDetail = [
            'head' => $runDetailHead,
            'rows' => $stmtRunRows->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    purchase_import_build_template();
}

if (isset($_POST['action']) && $_POST['action'] === 'analyze_excel') {
    try {
        if (!isset($_FILES['excel_file']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            throw new RuntimeException('Sube un archivo Excel válido.');
        }
        $ext = strtolower(pathinfo((string)$_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new RuntimeException('Formato inválido. Sube solo archivos .xlsx o .xls');
        }
        $parsed = purchase_import_parse_uploaded_file($_FILES['excel_file']['tmp_name'], $pdo);
        if (!$parsed['rows']) {
            throw new RuntimeException('No se encontraron filas válidas para importar.');
        }
        $_SESSION['purchase_excel_import_draft'] = [
            'file_name' => (string)$_FILES['excel_file']['name'],
            'created_at' => date('Y-m-d H:i:s'),
            'rows' => $parsed['rows'],
            'errors' => $parsed['errors'],
            'needs_resolution' => $parsed['needs_resolution'],
        ];
        $preview = $_SESSION['purchase_excel_import_draft'];
        $notice = $parsed['needs_resolution'] > 0
            ? 'Archivo analizado. Hay filas sin SKU que requieren resolución manual.'
            : 'Archivo analizado. Revisa el diff y luego importa.';
        $noticeType = 'success';
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeType = 'danger';
    }
} elseif (isset($_POST['action']) && $_POST['action'] === 'apply_import') {
    try {
        $draft = $_SESSION['purchase_excel_import_draft'] ?? null;
        if (!$draft || empty($draft['rows'])) {
            throw new RuntimeException('No hay una importación analizada para aplicar.');
        }

        $existingMode = in_array((string)($_POST['existing_mode'] ?? 'full'), ['full', 'stock_cost'], true)
            ? (string)$_POST['existing_mode']
            : 'full';
        if (($rawExistingMode = (string)($_POST['existing_mode'] ?? '')) === 'stock_only') {
            $existingMode = 'stock_only';
        }

        $rows = $draft['rows'];
        $resolutions = $_POST['resolution'] ?? [];
        $newSkus = $_POST['new_sku'] ?? [];
        $reservedGeneratedSkus = [];

        foreach ($rows as &$row) {
            if ($row['resolution_mode'] !== 'name_match_required') {
                continue;
            }
            $lineKey = (string)$row['line'];
            $selected = trim((string)($resolutions[$lineKey] ?? $row['selected_candidate'] ?? ''));
            if ($selected === '') {
                throw new RuntimeException('Debes resolver la fila ' . $row['line'] . ' antes de importar.');
            }
            if ($selected === '__new__') {
                $newSku = trim((string)($newSkus[$lineKey] ?? ''));
                if ($newSku === '') {
                    $newSku = purchase_import_generate_next_sku($pdo, (int)$row['empresa_id'], $reservedGeneratedSkus);
                }
                $row['resolved_sku'] = $newSku;
                $row['resolution_mode'] = 'name_create';
            } else {
                $row['resolved_sku'] = $selected;
                $row['resolution_mode'] = 'name_update';
            }
        }
        unset($row);

        $pdo->beginTransaction();
        $kardex = new KardexEngine($pdo);
        $usuario = (string)($_SESSION['user_id'] ?? 'Admin');
        $fechaImport = date('Y-m-d H:i:s');
        $categoryCache = [];
        $groups = [];

        foreach ($rows as $row) {
            $groupKey = $row['empresa_id'] . '|' . $row['sucursal_id'] . '|' . $row['almacen_id'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'empresa_id' => $row['empresa_id'],
                    'empresa_nombre' => $row['empresa_nombre'],
                    'sucursal_id' => $row['sucursal_id'],
                    'sucursal_nombre' => $row['sucursal_nombre'],
                    'almacen_id' => $row['almacen_id'],
                    'almacen_nombre' => $row['almacen_nombre'],
                    'rows' => [],
                    'total' => 0.0,
                ];
            }
            $groups[$groupKey]['rows'][] = $row;
            $groups[$groupKey]['total'] += $row['cantidad'] * $row['precio_compra'];
        }

        $createdProducts = 0;
        $updatedProducts = 0;
        $createdPurchases = [];
        $runRows = [];

        $stmtRun = $pdo->prepare("INSERT INTO purchase_import_runs (file_name, user_ref, existing_mode, total_rows, created_products, updated_products, status, purchases_json, created_at) VALUES (?, ?, ?, ?, 0, 0, 'APLICADA', ?, ?)");
        $stmtRun->execute([$draft['file_name'], $usuario, $existingMode, count($rows), '[]', $fechaImport]);
        $runId = (int)$pdo->lastInsertId();

        foreach ($groups as $group) {
            $provider = 'IMPORTACION EXCEL';
            $notes = sprintf('Importado desde Excel %s [Run:%d Emp:%d Suc:%d Alm:%d]', $draft['file_name'], $runId, $group['empresa_id'], $group['sucursal_id'], $group['almacen_id']);
            $stmtHead = $pdo->prepare("INSERT INTO compras_cabecera (proveedor, total, usuario, notas, fecha, numero_factura, estado) VALUES (?, ?, ?, ?, ?, ?, 'PROCESADA')");
            $stmtHead->execute([$provider, $group['total'], $usuario, $notes, $fechaImport, 'IMP-' . $runId . '-' . date('His')]);
            $purchaseId = (int)$pdo->lastInsertId();
            $createdPurchases[] = [
                'id' => $purchaseId,
                'empresa' => $group['empresa_nombre'],
                'sucursal' => $group['sucursal_nombre'],
                'almacen' => $group['almacen_nombre'],
                'rows' => count($group['rows']),
            ];

            foreach ($group['rows'] as $row) {
                $targetSku = trim((string)$row['resolved_sku']);
                if ($targetSku === '') {
                    throw new RuntimeException('Fila ' . $row['line'] . ': no se resolvió el SKU destino.');
                }

                purchase_import_ensure_category($pdo, (string)$row['categoria'], $categoryCache);

                $stmtProduct = $pdo->prepare("SELECT codigo, nombre, costo, precio, precio_mayorista, categoria, activo FROM productos WHERE codigo = ? AND id_empresa = ? LIMIT 1");
                $stmtProduct->execute([$targetSku, $row['empresa_id']]);
                $existing = $stmtProduct->fetch(PDO::FETCH_ASSOC) ?: null;
                $productAction = $existing ? 'updated' : 'created';
                $previousJson = $existing ? json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

                if ($existing) {
                    if ($existingMode === 'full') {
                        $stmtUpdate = $pdo->prepare("UPDATE productos SET nombre = ?, costo = ?, precio = ?, precio_mayorista = ?, categoria = ?, activo = 1, version_row = ? WHERE codigo = ? AND id_empresa = ?");
                        $stmtUpdate->execute([$row['nombre'], $row['precio_compra'], $row['precio_venta'], $row['precio_mayorista'], $row['categoria'], time(), $targetSku, $row['empresa_id']]);
                    } elseif ($existingMode === 'stock_cost') {
                        $stmtUpdate = $pdo->prepare("UPDATE productos SET costo = ?, precio = ?, precio_mayorista = ?, activo = 1, version_row = ? WHERE codigo = ? AND id_empresa = ?");
                        $stmtUpdate->execute([$row['precio_compra'], $row['precio_venta'], $row['precio_mayorista'], time(), $targetSku, $row['empresa_id']]);
                    } else {
                        $stmtUpdate = $pdo->prepare("UPDATE productos SET costo = ?, activo = 1, version_row = ? WHERE codigo = ? AND id_empresa = ?");
                        $stmtUpdate->execute([$row['precio_compra'], time(), $targetSku, $row['empresa_id']]);
                    }
                    $updatedProducts++;
                } else {
                    $stmtInsert = $pdo->prepare("INSERT INTO productos (codigo, nombre, precio, costo, precio_mayorista, categoria, activo, id_empresa, version_row) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
                    $stmtInsert->execute([$targetSku, $row['nombre'], $row['precio_venta'], $row['precio_compra'], $row['precio_mayorista'], $row['categoria'], $row['empresa_id'], time()]);
                    $createdProducts++;
                }

                $stmtDetail = $pdo->prepare("INSERT INTO compras_detalle (id_compra, id_producto, cantidad, costo_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmtDetail->execute([$purchaseId, $targetSku, $row['cantidad'], $row['precio_compra'], $row['cantidad'] * $row['precio_compra']]);

                $kardex->registrarMovimiento(
                    $targetSku,
                    $row['almacen_id'],
                    $row['sucursal_id'],
                    'ENTRADA',
                    $row['cantidad'],
                    'IMPORTACION EXCEL #' . $purchaseId,
                    $row['precio_compra'],
                    $usuario,
                    $fechaImport
                );

                $stmtCurrent = $pdo->prepare("SELECT codigo, nombre, costo, precio, precio_mayorista, categoria, activo FROM productos WHERE codigo = ? AND id_empresa = ? LIMIT 1");
                $stmtCurrent->execute([$targetSku, $row['empresa_id']]);
                $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: [];

                $runRows[] = [
                    'line_no' => $row['line'],
                    'empresa_id' => $row['empresa_id'],
                    'sucursal_id' => $row['sucursal_id'],
                    'almacen_id' => $row['almacen_id'],
                    'source_sku' => $row['sku'],
                    'target_sku' => $targetSku,
                    'resolution_mode' => $row['resolution_mode'],
                    'product_action' => $productAction,
                    'purchase_id' => $purchaseId,
                    'quantity' => $row['cantidad'],
                    'cost_price' => $row['precio_compra'],
                    'previous_json' => $previousJson,
                    'new_json' => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        $stmtRunRow = $pdo->prepare("INSERT INTO purchase_import_run_rows (run_id, line_no, empresa_id, sucursal_id, almacen_id, source_sku, target_sku, resolution_mode, product_action, purchase_id, quantity, cost_price, previous_product_json, new_product_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($runRows as $item) {
            $stmtRunRow->execute([$runId, $item['line_no'], $item['empresa_id'], $item['sucursal_id'], $item['almacen_id'], $item['source_sku'], $item['target_sku'], $item['resolution_mode'], $item['product_action'], $item['purchase_id'], $item['quantity'], $item['cost_price'], $item['previous_json'], $item['new_json'], $fechaImport]);
        }

        $stmtRunUpdate = $pdo->prepare("UPDATE purchase_import_runs SET created_products = ?, updated_products = ?, purchases_json = ? WHERE id = ?");
        $stmtRunUpdate->execute([$createdProducts, $updatedProducts, json_encode($createdPurchases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $runId]);

        $pdo->commit();
        $importResult = [
            'run_id' => $runId,
            'purchases' => $createdPurchases,
            'created_products' => $createdProducts,
            'updated_products' => $updatedProducts,
            'rows' => count($rows),
            'errors' => count($draft['errors'] ?? []),
        ];
        unset($_SESSION['purchase_excel_import_draft']);
        $notice = 'Importación aplicada correctamente.';
        $noticeType = 'success';
        $recentRuns = $pdo->query("SELECT * FROM purchase_import_runs ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $notice = $e->getMessage();
        $noticeType = 'danger';
        $preview = $_SESSION['purchase_excel_import_draft'] ?? null;
    }
} elseif (isset($_POST['action']) && $_POST['action'] === 'undo_import') {
    try {
        $runId = (int)($_POST['run_id'] ?? 0);
        if ($runId <= 0) {
            throw new RuntimeException('Importación inválida para revertir.');
        }

        $stmtRun = $pdo->prepare("SELECT * FROM purchase_import_runs WHERE id = ? LIMIT 1");
        $stmtRun->execute([$runId]);
        $run = $stmtRun->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new RuntimeException('No existe esa importación.');
        }
        if ((string)$run['status'] === 'REVERTIDA') {
            throw new RuntimeException('Esa importación ya fue revertida.');
        }

        $stmtRows = $pdo->prepare("SELECT * FROM purchase_import_run_rows WHERE run_id = ? ORDER BY id DESC");
        $stmtRows->execute([$runId]);
        $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            throw new RuntimeException('No hay filas registradas para revertir.');
        }

        $pdo->beginTransaction();
        $kardex = new KardexEngine($pdo);
        $usuario = (string)($_SESSION['user_id'] ?? 'Admin');
        $fechaUndo = date('Y-m-d H:i:s');
        $purchaseIds = [];

        foreach ($rows as $row) {
            $kardex->registrarMovimiento(
                (string)$row['target_sku'],
                (int)$row['almacen_id'],
                (int)$row['sucursal_id'],
                'SALIDA',
                -abs((float)$row['quantity']),
                'REVERSA IMPORT RUN #' . $runId,
                (float)$row['cost_price'],
                $usuario,
                $fechaUndo
            );
            purchase_import_restore_product($pdo, $row);
            $purchaseIds[(int)$row['purchase_id']] = true;
        }

        foreach (array_keys($purchaseIds) as $purchaseId) {
            $pdo->prepare("DELETE FROM compras_detalle WHERE id_compra = ?")->execute([$purchaseId]);
            $pdo->prepare("UPDATE compras_cabecera SET estado = 'CANCELADA', total = 0, notas = CONCAT(COALESCE(notas,''), ' [REVERTIDA RUN #{$runId}]') WHERE id = ?")->execute([$purchaseId]);
        }

        $pdo->prepare("UPDATE purchase_import_runs SET status = 'REVERTIDA', reverted_at = ? WHERE id = ?")->execute([$fechaUndo, $runId]);
        $pdo->commit();
        $notice = 'Importación revertida correctamente.';
        $noticeType = 'success';
        $recentRuns = $pdo->query("SELECT * FROM purchase_import_runs ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $notice = $e->getMessage();
        $noticeType = 'danger';
        $preview = $_SESSION['purchase_excel_import_draft'] ?? null;
    }
} else {
    $preview = $_SESSION['purchase_excel_import_draft'] ?? null;
}

if ($preview && !empty($preview['rows'])) {
    $previewLowerPriceCount = 0;
    foreach ($preview['rows'] as $row) {
        $groupKey = $row['empresa_nombre'] . '|' . $row['sucursal_nombre'] . '|' . $row['almacen_nombre'];
        if (!isset($previewGroups[$groupKey])) {
            $previewGroups[$groupKey] = [
                'empresa' => $row['empresa_nombre'],
                'sucursal' => $row['sucursal_nombre'],
                'almacen' => $row['almacen_nombre'],
                'rows' => [],
                'total' => 0.0,
            ];
        }
        $previewGroups[$groupKey]['rows'][] = $row;
        $previewGroups[$groupKey]['total'] += $row['cantidad'] * $row['precio_compra'];
        if (!empty($row['matched_product'])) {
            $oldPrice = (float)($row['matched_product']['precio'] ?? 0);
            $oldWholesale = (float)($row['matched_product']['precio_mayorista'] ?? 0);
            if ($row['precio_venta'] < $oldPrice || $row['precio_mayorista'] < $oldWholesale) {
                $previewLowerPriceCount++;
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Importar compras desde Excel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%); }
        .shell { max-width: 1500px; }
        .hero { border-radius: 24px; padding: 24px 28px; background: linear-gradient(135deg, #0f172a, #1d4ed8 60%, #0ea5e9); color: #fff; box-shadow: 0 22px 50px rgba(30,41,59,.24); }
        .hero-kpi { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18); border-radius: 16px; padding: 12px 14px; min-width: 150px; }
        .card { border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 10px 28px rgba(15,23,42,.06); }
        .section-title { font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; font-weight: 800; color: #64748b; }
        .chip { display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid #dbeafe; background:#eff6ff; color:#1d4ed8; font-size:.78rem; margin:0 6px 6px 0; }
        .chip-warning { background:#fff7ed; border-color:#fed7aa; color:#c2410c; }
        .chip-success { background:#ecfdf5; border-color:#bbf7d0; color:#15803d; }
        .chip-dark { background:#e2e8f0; border-color:#cbd5e1; color:#0f172a; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .table thead th { white-space: nowrap; }
        .resolution-card { border:1px dashed #cbd5e1; border-radius:16px; padding:14px; background:#f8fafc; }
        .hierarchy-badge { display:inline-block; padding:6px 10px; border-radius:10px; font-size:.8rem; font-weight:700; margin:0 6px 6px 0; }
        .hierarchy-empresa { background:#dbeafe; color:#1d4ed8; }
        .hierarchy-sucursal { background:#ede9fe; color:#6d28d9; }
        .hierarchy-almacen { background:#dcfce7; color:#15803d; }
        .diff-list { font-size:.83rem; margin:0; padding-left:18px; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid shell">
    <div class="hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4">
            <div>
                <div class="section-title text-white-50 mb-2">Compras / Utilidad</div>
                <h2 class="mb-2"><i class="fas fa-file-excel me-2"></i>Importar compras desde Excel</h2>
                <div class="text-white-50">Plantilla descargable, validación fuerte, matching por nombre, diff previo, modo de actualización y bitácora reversible.</div>
            </div>
            <div class="d-flex flex-wrap gap-3 align-self-start">
                <div class="hero-kpi"><div class="small text-white-50">Columnas requeridas</div><div class="fs-5 fw-bold">10</div></div>
                <div class="hero-kpi"><div class="small text-white-50">Defaults</div><div class="fs-6 fw-bold mono">venta +30% · mayorista +10%</div></div>
                <a href="?action=download_template" class="btn btn-light"><i class="fas fa-download me-2"></i>Descargar plantilla</a>
            </div>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($noticeType) ?> shadow-sm"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if ($importResult): ?>
        <div class="card mb-4">
            <div class="card-body p-4">
                <div class="section-title mb-3">Resultado</div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Run</div><div class="fs-4 fw-bold mono">#<?= (int)$importResult['run_id'] ?></div></div></div>
                    <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Filas importadas</div><div class="fs-4 fw-bold"><?= (int)$importResult['rows'] ?></div></div></div>
                    <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Productos creados</div><div class="fs-4 fw-bold text-success"><?= (int)$importResult['created_products'] ?></div></div></div>
                    <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Productos actualizados</div><div class="fs-4 fw-bold text-primary"><?= (int)$importResult['updated_products'] ?></div></div></div>
                </div>
                <?php foreach ($importResult['purchases'] as $purchase): ?>
                    <span class="chip">Compra #<?= (int)$purchase['id'] ?> · <?= htmlspecialchars($purchase['empresa']) ?> · <?= htmlspecialchars($purchase['sucursal']) ?> · <?= htmlspecialchars($purchase['almacen']) ?> · <?= (int)$purchase['rows'] ?> filas</span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="section-title mb-3">Carga</div>
                    <?php foreach (POS_PURCHASE_IMPORT_HEADERS as $header): ?>
                        <span class="chip mono"><?= htmlspecialchars($header) ?></span>
                    <?php endforeach; ?>
                    <hr>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="analyze_excel">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Archivo Excel</label>
                            <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Analizar Excel</button>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="section-title mb-3">Reglas</div>
                    <ul class="small mb-0">
                        <li>Detecta duplicados de SKU dentro del mismo Excel por empresa.</li>
                        <li>Si falta `precio_venta`: aplica `+30%`.</li>
                        <li>Si falta `precio_mayorista`: aplica `+10%`.</li>
                        <li>Si la categoría no existe, se crea.</li>
                        <li>Para productos existentes puedes importar en modo `sobrescritura completa`, `stock y costo` o `solo costo`.</li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <div class="section-title mb-3">Bitácora / Undo</div>
                    <?php if ($recentRuns): ?>
                        <?php foreach ($recentRuns as $run): ?>
                            <div class="border rounded p-3 mb-2">
                                <div class="d-flex justify-content-between gap-2 flex-wrap">
                                    <div>
                                        <div class="fw-bold mono">Run #<?= (int)$run['id'] ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars((string)$run['file_name']) ?> · <?= htmlspecialchars((string)$run['created_at']) ?></div>
                                        <div class="small text-muted">Modo: <?= htmlspecialchars((string)$run['existing_mode']) ?> · Estado: <?= htmlspecialchars((string)$run['status']) ?></div>
                                    </div>
                                    <?php if ((string)$run['status'] !== 'REVERTIDA'): ?>
                                        <form method="post" onsubmit="return confirm('Esto revertirá stock, kardex y compras registradas por esta importación. ¿Continuar?');">
                                            <input type="hidden" name="action" value="undo_import">
                                            <input type="hidden" name="run_id" value="<?= (int)$run['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-undo me-1"></i>Deshacer</button>
                                        </form>
                                        <a href="?run_detail=<?= (int)$run['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye me-1"></i>Ver detalle</a>
                                    <?php else: ?>
                                        <span class="chip chip-dark">Revertida</span>
                                        <a href="?run_detail=<?= (int)$run['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye me-1"></i>Ver detalle</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted small">Aún no hay importaciones registradas.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <?php if ($runDetail): ?>
                <div class="card mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                            <div>
                                <div class="section-title">Detalle de run</div>
                                <div class="fw-bold mono">#<?= (int)$runDetail['head']['id'] ?> · <?= htmlspecialchars((string)$runDetail['head']['file_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars((string)$runDetail['head']['created_at']) ?> · <?= htmlspecialchars((string)$runDetail['head']['status']) ?> · modo <?= htmlspecialchars((string)$runDetail['head']['existing_mode']) ?></div>
                            </div>
                            <a href="pos_import_compras_excel.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times me-1"></i>Cerrar detalle</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Línea</th>
                                        <th>Compra</th>
                                        <th>SKU origen</th>
                                        <th>SKU destino</th>
                                        <th>Acción</th>
                                        <th>Resolución</th>
                                        <th>Cantidad</th>
                                        <th>Costo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($runDetail['rows'] as $detailRow): ?>
                                        <tr>
                                            <td><?= (int)$detailRow['line_no'] ?></td>
                                            <td>#<?= (int)$detailRow['purchase_id'] ?></td>
                                            <td class="mono"><?= htmlspecialchars((string)$detailRow['source_sku']) ?></td>
                                            <td class="mono"><?= htmlspecialchars((string)$detailRow['target_sku']) ?></td>
                                            <td><?= htmlspecialchars((string)$detailRow['product_action']) ?></td>
                                            <td><?= htmlspecialchars((string)$detailRow['resolution_mode']) ?></td>
                                            <td><?= number_format((float)$detailRow['quantity'], 2) ?></td>
                                            <td><?= number_format((float)$detailRow['cost_price'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                        <div>
                            <div class="section-title">Vista previa</div>
                            <div class="text-muted small">
                                <?php if ($preview): ?>
                                    Archivo: <strong><?= htmlspecialchars((string)$preview['file_name']) ?></strong> · Analizado: <?= htmlspecialchars((string)$preview['created_at']) ?>
                                <?php else: ?>
                                    Aún no hay una importación analizada.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($preview && !empty($preview['rows'])): ?>
                        <form method="post" id="purchaseImportForm">
                            <input type="hidden" name="action" value="apply_import">
                            <div class="row g-3 mb-3">
                                <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Filas válidas</div><div class="fs-4 fw-bold"><?= count($preview['rows']) ?></div></div></div>
                                <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Pendientes de resolución</div><div class="fs-4 fw-bold text-warning"><?= (int)($preview['needs_resolution'] ?? 0) ?></div></div></div>
                                <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Errores de análisis</div><div class="fs-4 fw-bold text-danger"><?= count($preview['errors'] ?? []) ?></div></div></div>
                                <div class="col-md-3"><div class="p-3 rounded bg-light border"><div class="small text-muted">Modo productos existentes</div><select name="existing_mode" id="existing_mode" class="form-select mt-2"><option value="full">Sobrescritura completa</option><option value="stock_cost">Stock y costo</option><option value="stock_only">Solo costo</option></select></div></div>
                            </div>

                            <?php if (!empty($preview['errors'])): ?>
                                <div class="alert alert-warning">
                                    <div class="fw-bold mb-2">Filas omitidas por validación</div>
                                    <ul class="small mb-0">
                                        <?php foreach ($preview['errors'] as $error): ?>
                                            <li><?= htmlspecialchars((string)$error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($previewGroups)): ?>
                                <div class="card mb-4 border-0 bg-light">
                                    <div class="card-body p-3">
                                        <div class="section-title mb-3">Vista previa agrupada por compra</div>
                                        <?php foreach ($previewGroups as $group): ?>
                                            <div class="border rounded p-3 mb-3 bg-white">
                                                <div class="d-flex justify-content-between gap-3 flex-wrap">
                                                    <div>
                                                        <span class="hierarchy-badge hierarchy-empresa"><?= htmlspecialchars((string)$group['empresa']) ?></span>
                                                        <span class="hierarchy-badge hierarchy-sucursal"><?= htmlspecialchars((string)$group['sucursal']) ?></span>
                                                        <span class="hierarchy-badge hierarchy-almacen"><?= htmlspecialchars((string)$group['almacen']) ?></span>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="small text-muted"><?= count($group['rows']) ?> filas</div>
                                                        <div class="fw-bold"><?= number_format((float)$group['total'], 2) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($preview['rows'] as $row): ?>
                                <?php if ($row['resolution_mode'] === 'name_match_required'): ?>
                                    <div class="resolution-card mb-3">
                                        <div class="d-flex justify-content-between gap-3 flex-wrap mb-2">
                                            <div>
                                                <div class="fw-bold">Fila <?= (int)$row['line'] ?> · <?= htmlspecialchars((string)$row['nombre']) ?></div>
                                                <div>
                                                    <span class="hierarchy-badge hierarchy-empresa">Empresa: <?= htmlspecialchars((string)$row['empresa_nombre']) ?></span>
                                                    <span class="hierarchy-badge hierarchy-sucursal">Sucursal: <?= htmlspecialchars((string)$row['sucursal_nombre']) ?></span>
                                                    <span class="hierarchy-badge hierarchy-almacen">Almacén: <?= htmlspecialchars((string)$row['almacen_nombre']) ?></span>
                                                </div>
                                            </div>
                                            <span class="chip chip-warning">SKU vacío · requiere resolución</span>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-lg-4">
                                                <label class="form-label small fw-bold">Buscar entre sugerencias</label>
                                                <input type="text" class="form-control resolution-search" data-target="resolution-<?= (int)$row['line'] ?>" placeholder="Filtrar opciones...">
                                            </div>
                                            <div class="col-lg-5">
                                                <label class="form-label small fw-bold">Producto destino</label>
                                                <select class="form-select" id="resolution-<?= (int)$row['line'] ?>" name="resolution[<?= (int)$row['line'] ?>]">
                                                    <?php foreach ($row['suggestions'] as $candidate): ?>
                                                        <option value="<?= htmlspecialchars((string)$candidate['codigo']) ?>" <?= ((string)$row['selected_candidate'] === (string)$candidate['codigo']) ? 'selected' : '' ?>><?= htmlspecialchars((string)$candidate['codigo']) ?> · <?= htmlspecialchars((string)$candidate['nombre']) ?> · <?= htmlspecialchars((string)$candidate['categoria']) ?> · score <?= number_format((float)$candidate['score'], 1) ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__new__">Crear como producto nuevo</option>
                                                </select>
                                            </div>
                                            <div class="col-lg-3">
                                                <label class="form-label small fw-bold">SKU nuevo si crea</label>
                                                <input type="text" class="form-control mono" name="new_sku[<?= (int)$row['line'] ?>]" placeholder="Automático si lo dejas vacío">
                                                <div class="small text-muted mt-1">Si no escribes SKU, el sistema genera uno único incrementando el último SKU de la empresa.</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>SKU origen</th>
                                            <th>Acción</th>
                                            <th>Contexto</th>
                                            <th>Nombre</th>
                                            <th>Cantidad</th>
                                            <th>Compra</th>
                                            <th>Venta</th>
                                            <th>Mayorista</th>
                                            <th>Diff</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($preview['rows'] as $row): ?>
                                            <tr>
                                                <td><?= (int)$row['line'] ?></td>
                                                <td class="mono"><?= htmlspecialchars((string)$row['sku']) ?></td>
                                                <td>
                                                    <?php if ($row['resolution_mode'] === 'sku_update'): ?>
                                                        <span class="chip chip-success">Actualizar por SKU</span>
                                                    <?php elseif ($row['resolution_mode'] === 'sku_create'): ?>
                                                        <span class="chip">Crear por SKU</span>
                                                    <?php elseif ($row['matched_product']): ?>
                                                        <span class="chip chip-warning">Resolver por nombre</span>
                                                    <?php else: ?>
                                                        <span class="chip chip-warning">Crear nuevo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="hierarchy-badge hierarchy-empresa"><?= htmlspecialchars((string)$row['empresa_nombre']) ?></span>
                                                    <span class="hierarchy-badge hierarchy-sucursal"><?= htmlspecialchars((string)$row['sucursal_nombre']) ?></span>
                                                    <span class="hierarchy-badge hierarchy-almacen"><?= htmlspecialchars((string)$row['almacen_nombre']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars((string)$row['nombre']) ?><br><small class="text-muted"><?= htmlspecialchars((string)$row['categoria']) ?></small></td>
                                                <td><?= number_format((float)$row['cantidad'], 2) ?></td>
                                                <td><?= number_format((float)$row['precio_compra'], 2) ?></td>
                                                <td><?= number_format((float)$row['precio_venta'], 2) ?><?php if (!empty($row['precio_venta_defaulted'])): ?><br><span class="chip chip-warning">+30%</span><?php endif; ?></td>
                                                <td><?= number_format((float)$row['precio_mayorista'], 2) ?><?php if (!empty($row['precio_mayorista_defaulted'])): ?><br><span class="chip chip-warning">+10%</span><?php endif; ?></td>
                                                <td>
                                                    <?php if (!empty($row['diff'])): ?>
                                                        <ul class="diff-list">
                                                            <?php foreach ($row['diff'] as $field => $values): ?>
                                                                <li><strong><?= htmlspecialchars((string)$field) ?></strong>: <?= htmlspecialchars((string)$values['old']) ?> → <?= htmlspecialchars((string)$values['new']) ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Sin diff o producto nuevo</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-success"><i class="fas fa-file-import me-2"></i>Importar</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-file-upload fa-3x mb-3 opacity-25"></i>
                            <div>Sube un archivo Excel para ver la vista previa.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.resolution-search').forEach((input) => {
    input.addEventListener('input', () => {
        const target = document.getElementById(input.dataset.target || '');
        if (!target) return;
        const term = input.value.trim().toLowerCase();
        Array.from(target.options).forEach((option) => {
            if (option.value === '__new__') {
                option.hidden = false;
                return;
            }
            option.hidden = term !== '' && !option.text.toLowerCase().includes(term);
        });
    });
});

(() => {
    const form = document.getElementById('purchaseImportForm');
    if (!form) return;
    const lowerCount = <?= isset($previewLowerPriceCount) ? (int)$previewLowerPriceCount : 0 ?>;
    form.addEventListener('submit', (event) => {
        const mode = document.getElementById('existing_mode')?.value || 'full';
        let message = 'Esto importará compras, actualizará productos y moverá stock. ¿Continuar?';
        if (mode !== 'stock_only' && lowerCount > 0) {
            message = 'Hay ' + lowerCount + ' fila(s) donde el precio de venta o mayorista bajará respecto al producto actual. ¿Confirmas que quieres continuar?';
        }
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
PHP
