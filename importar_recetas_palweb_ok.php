<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

use PhpOffice\\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$defaultFile = '/home/ubuntu/Recetas_Palweb_ok.xlsx';
$isCli = (PHP_SAPI === 'cli');
$action = $isCli ? '' : (string)($_REQUEST['action'] ?? '');

if (!$isCli) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

$args = $isCli ? getopt('', ['file::', 'apply', 'create-missing']) : [];
$file = $args['file'] ?? ($_REQUEST['file'] ?? $defaultFile);
$apply = $isCli ? isset($args['apply']) : (isset($_GET['apply']) && $_GET['apply'] === '1');
$createMissingAllowed = $isCli ? isset($args['create-missing']) : false;
$EMP_ID = intval($config['id_empresa'] ?? 1);
$now = time();

function jsonOut(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function out(string $msg): void
{
    echo $msg . PHP_EOL;
    flush();
}

function normalizeName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = preg_replace('/\s*\((g|gr|gramos|ml|l|u|ud|unidad|unidades)\)\s*$/iu', '', $name) ?? $name;
    return mb_strtolower(trim($name), 'UTF-8');
}

function cleanIngredientName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

function parseNum($v): ?float
{
    if ($v === null) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return floatval($v);
    }
    $s = trim((string)$v);
    if ($s === '' || stripos($s, '#N/A') !== false || stripos($s, '#REF!') !== false) {
        return null;
    }
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $s)) {
        $s = str_replace(',', '.', $s);
        return floatval($s);
    }
    return null;
}

function parseQtyValue($calcValue, $rawValue): ?float
{
    $calc = parseNum($calcValue);
    if ($calc !== null) {
        return $calc;
    }
    if ($rawValue === null) {
        return null;
    }
    $raw = trim((string)$rawValue);
    if ($raw === '' || stripos($raw, '#N/A') !== false || stripos($raw, '#REF!') !== false) {
        return null;
    }
    if (isset($raw[0]) && $raw[0] === '=') {
        return null;
    }
    if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)/', $raw, $m)) {
        return floatval(str_replace(',', '.', $m[1]));
    }
    return null;
}

function firstNumInCols(Worksheet $sheet, int $row, array $cols): ?float
{
    foreach ($cols as $col) {
        $num = parseNum($sheet->getCell($col . $row)->getCalculatedValue());
        if ($num !== null) {
            return $num;
        }
    }
    return null;
}

function findHeaderColumn(array $headerMap, array $keywords): ?string
{
    foreach ($headerMap as $col => $val) {
        foreach ($keywords as $kw) {
            if (mb_stripos($val, $kw, 0, 'UTF-8') !== false) {
                return $col;
            }
        }
    }
    return null;
}

function inferUnit(string $name, ?string $unitCell): string
{
    $unitCell = trim((string)$unitCell);
    if ($unitCell !== '') {
        return substr($unitCell, 0, 20);
    }
    if (preg_match('/\((ml|l)\)/iu', $name, $m)) {
        return strtolower($m[1]);
    }
    if (preg_match('/\((u|ud|unidad|unidades)\)/iu', $name)) {
        return 'u';
    }
    return 'g';
}

function generateCode(string $name, array &$productsByCode, PDO $pdo, int $empId): string
{
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', mb_substr($name, 0, 12, 'UTF-8')) ?? '');
    if ($base === '') {
        $base = 'PROD';
    }
    $code = $base;
    $i = 1;
    while (isset($productsByCode[$code])) {
        $code = $base . $i;
        $i++;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE codigo = ? AND id_empresa = ?');
    while (true) {
        $stmt->execute([$code, $empId]);
        if (intval($stmt->fetchColumn()) === 0) {
            return $code;
        }
        $code = $base . $i;
        $i++;
    }
}

function resolveProductByNameOrCode(array $productsByNorm, array $productsByCode, string $rawName): ?array
{
    $raw = trim($rawName);
    if ($raw === '') {
        return null;
    }

    if (isset($productsByCode[$raw])) {
        return $productsByCode[$raw];
    }

    $norm = normalizeName(cleanIngredientName($raw));
    return $productsByNorm[$norm] ?? null;
}

function productImage(string $code): string
{
    return 'image.php?code=' . rawurlencode($code);
}

function deriveFlags(?string $categoria, bool $isFinal): array
{
    $cat = mb_strtolower(trim((string)$categoria), 'UTF-8');
    if ($isFinal) {
        return [1, 0];
    }
    if (strpos($cat, 'insumo') !== false) {
        return [0, 1];
    }
    if (strpos($cat, 'intermedio') !== false || strpos($cat, 'terminado') !== false || strpos($cat, 'elaborado') !== false) {
        return [1, 0];
    }
    return [0, 1];
}

function ensureRecetaSchema(PDO $pdo): void
{
    try {
        $pdo->exec('ALTER TABLE recetas_detalle ADD COLUMN IF NOT EXISTS pct_formula DECIMAL(5,2) DEFAULT 0');
    } catch (Throwable $e) {
        // no-op
    }
}

function loadProducts(PDO $pdo, int $empId): array
{
    $stmtAllProducts = $pdo->prepare('SELECT codigo, nombre, costo, precio, unidad_medida, categoria, es_elaborado, es_materia_prima
                                      FROM productos WHERE id_empresa = ?');
    $stmtAllProducts->execute([$empId]);
    $productsByNorm = [];
    $productsByCode = [];
    foreach ($stmtAllProducts->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $norm = normalizeName((string)$p['nombre']);
        if (!isset($productsByNorm[$norm])) {
            $productsByNorm[$norm] = $p;
        }
        $productsByCode[$p['codigo']] = $p;
    }

    return ['byNorm' => $productsByNorm, 'byCode' => $productsByCode];
}

function parseDrafts(Spreadsheet $spreadsheet, array $productsByNorm, array $productsByCode, PDO $pdo, int $empId): array
{
    $stmtFindRecipe = $pdo->prepare('SELECT id FROM recetas_cabecera WHERE nombre_receta = ? LIMIT 1');

    $stats = [
        'sheets_total' => 0,
        'recipes_total' => 0,
        'recipes_ready' => 0,
        'recipes_skipped' => 0,
        'ingredients' => 0,
        'ingredients_resolved' => 0,
        'ingredients_unresolved' => 0,
        'insumos_rows' => 0,
        'insumos_matched' => 0,
        'insumos_unmatched' => 0,
        'errors' => 0,
    ];

    $insumoProblems = [];

    $insumosSheet = $spreadsheet->getSheetByName('Insumos') ?: $spreadsheet->getSheetByName('Inventario');
    if ($insumosSheet instanceof Worksheet) {
        $header = $insumosSheet->rangeToArray('A1:Z1', null, true, true, true)[1];
        $headerMap = [];
        foreach ($header as $col => $value) {
            $headerMap[$col] = mb_strtolower(trim((string)$value), 'UTF-8');
        }
        $colName = findHeaderColumn($headerMap, ['producto']) ?? 'A';
        $colCost = findHeaderColumn($headerMap, ['costo']) ?? 'B';
        $colPrice = findHeaderColumn($headerMap, ['precio']) ?? 'C';
        $colUnit = findHeaderColumn($headerMap, ['unit', 'unidad']) ?? 'D';
        $colCat = findHeaderColumn($headerMap, ['categoria', 'categor']) ?? 'E';

        for ($row = 2; $row <= $insumosSheet->getHighestRow(); $row++) {
            $stats['insumos_rows']++;
            $name = trim((string)$insumosSheet->getCell($colName . $row)->getValue());
            if ($name === '') {
                continue;
            }
            $cost = parseNum($insumosSheet->getCell($colCost . $row)->getCalculatedValue());
            $price = parseNum($insumosSheet->getCell($colPrice . $row)->getCalculatedValue());
            if ($cost === null && $price !== null) {
                $cost = $price;
            }
            $unit = trim((string)$insumosSheet->getCell($colUnit . $row)->getValue());
            $cat = trim((string)$insumosSheet->getCell($colCat . $row)->getValue());
            $match = resolveProductByNameOrCode($productsByNorm, $productsByCode, $name);
            if ($match) {
                $stats['insumos_matched']++;
            } else {
                $stats['insumos_unmatched']++;
                $insumoProblems[] = $name;
            }
            if ($match && $match['codigo'] ?? null) {
                $rowCode = $match['codigo'];
                if (!isset($productsByCode[$rowCode])) {
                    continue;
                }
                $productsByCode[$rowCode]['nombre'] = cleanIngredientName($name);
                $productsByCode[$rowCode]['costo'] = $cost ?? 0.0;
                $productsByCode[$rowCode]['precio'] = $price ?? floatval($match['precio'] ?? 0);
            }
        }
    }

    $recipeSheets = [];
    foreach ($spreadsheet->getWorksheetIterator() as $ws) {
        $name = trim($ws->getTitle());
        $low = mb_strtolower($name, 'UTF-8');
        if ($low === 'insumos' || $low === 'inventario' || strpos($low, 'contabilidad') !== false) {
            continue;
        }
        $recipeSheets[] = $ws;
    }

    $stats['sheets_total'] = count($recipeSheets);
    $drafts = [];

    foreach ($recipeSheets as $sheetIndex => $ws) {
        $recipeName = trim($ws->getTitle());
        $highestRow = $ws->getHighestRow();
        $headerRow = null;

        for ($r = 1; $r <= min($highestRow, 20); $r++) {
            $rowVals = $ws->rangeToArray("A{$r}:Z{$r}", null, true, true, true)[$r];
            foreach ($rowVals as $cellValue) {
                $low = mb_strtolower(trim((string)$cellValue), 'UTF-8');
                if ($low === 'ingredientes' || $low === 'ingrediente') {
                    $headerRow = $r;
                    break 2;
                }
            }
        }

        if ($headerRow === null) {
            $stats['recipes_skipped']++;
            continue;
        }

        $headerVals = $ws->rangeToArray("A{$headerRow}:Z{$headerRow}", null, true, true, true)[$headerRow];
        $headerMap = [];
        foreach ($headerVals as $col => $value) {
            $headerMap[$col] = mb_strtolower(trim((string)$value), 'UTF-8');
        }

        $colIng = findHeaderColumn($headerMap, ['ingrediente']) ?? 'A';
        $colQty = findHeaderColumn($headerMap, ['formula', 'cant', 'cantidad']) ?? 'B';
        $colUnit = findHeaderColumn($headerMap, ['unit', 'unidad']);
        $colCost = findHeaderColumn($headerMap, ['costo']);

        $ingredients = [];
        $qtyTotal = 0.0;
        $summaryMarkerRegex = '/(masa|rendimiento|costo|venta|margen|retorno|centro|ganancia|cantidad de masa|peso por|area a cubrir)/iu';

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $nameRaw = trim((string)$ws->getCell($colIng . $r)->getValue());
            $qty = parseQtyValue(
                $ws->getCell($colQty . $r)->getCalculatedValue(),
                $ws->getCell($colQty . $r)->getValue()
            ) ?? 0.0;

            if ($nameRaw !== '' && preg_match($summaryMarkerRegex, mb_strtolower($nameRaw, 'UTF-8'))) {
                if (count($ingredients) > 0) {
                    break;
                }
                continue;
            }
            if ($nameRaw === '' || $qty <= 0) {
                continue;
            }

            $stats['ingredients']++;
            $unitCell = $colUnit ? (string)$ws->getCell($colUnit . $r)->getCalculatedValue() : '';
            $unit = inferUnit($nameRaw, $unitCell);
            $lineCost = $colCost ? parseNum($ws->getCell($colCost . $r)->getCalculatedValue()) : null;
            $match = resolveProductByNameOrCode($productsByNorm, $productsByCode, $nameRaw);

            $resolvedCode = null;
            $resolved = null;
            if (is_array($match)) {
                $resolvedCode = (string)$match['codigo'];
                $resolved = [
                    'codigo' => (string)$match['codigo'],
                    'nombre' => (string)$match['nombre'],
                    'precio' => floatval($match['precio'] ?? 0),
                    'costo' => floatval($match['costo'] ?? 0),
                    'unidad' => (string)($match['unidad_medida'] ?? ''),
                ];
                $stats['ingredients_resolved']++;
            } else {
                $stats['ingredients_unresolved']++;
            }

            $realLineCost = $lineCost;
            if ($realLineCost === null) {
                $realLineCost = ($match !== null && isset($match['costo']) ? (float)$match['costo'] * $qty : 0.0);
            }

            $ingredients[] = [
                'name' => $nameRaw,
                'qty' => $qty,
                'unit' => $unit,
                'line_cost' => $realLineCost,
                'resolved_code' => $resolvedCode,
                'resolved_name' => $resolved['nombre'] ?? null,
                'resolved_price' => $resolved['precio'] ?? null,
                'resolved_cost' => $resolved['costo'] ?? null,
                'needs_manual' => $resolvedCode === null,
            ];

            $qtyTotal += $qty;
        }

        if (count($ingredients) === 0) {
            $stats['recipes_skipped']++;
            continue;
        }

        $units = 1.0;
        $costTotal = 0.0;
        $salePrice = 0.0;
        $costTotalFromIngredients = 0.0;
        foreach ($ingredients as $it) {
            $costTotalFromIngredients += floatval($it['line_cost']);
        }

        for ($r = 1; $r <= min($highestRow, 120); $r++) {
            $label = mb_strtolower(trim((string)$ws->getCell('A' . $r)->getValue()), 'UTF-8');
            if ($label === '') {
                continue;
            }
            if (strpos($label, 'masa') !== false || strpos($label, 'rendimiento') !== false) {
                $u = firstNumInCols($ws, $r, ['D', 'C', 'B', 'E', 'F', 'G', 'H']);
                if ($u !== null && $u > 0) {
                    $units = $u;
                }
            }
            if ($label === 'costo' || strpos($label, 'costo') === 0) {
                $nums = [];
                foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
                    $n = parseNum($ws->getCell($col . $r)->getCalculatedValue());
                    if ($n !== null && $n > 0) {
                        $nums[] = $n;
                    }
                }
                if (!empty($nums)) {
                    $costTotal = max($nums);
                }
            }
            if (strpos($label, 'venta bruta') !== false) {
                $nums = [];
                foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
                    $n = parseNum($ws->getCell($col . $r)->getCalculatedValue());
                    if ($n !== null && $n > 0) {
                        $nums[] = $n;
                    }
                }
                if (!empty($nums)) {
                    $salePrice = min($nums);
                }
            }
        }

        if ($costTotal <= 0) {
            $costTotal = $costTotalFromIngredients;
        }
        $costUnit = ($units > 0) ? ($costTotal / $units) : $costTotal;

        $finalMatch = resolveProductByNameOrCode($productsByNorm, $productsByCode, $recipeName);
        $finalCode = is_array($finalMatch) ? (string)$finalMatch['codigo'] : null;

        $stmtFindRecipe->execute([$recipeName]);
        $existingRecipeId = $stmtFindRecipe->fetchColumn();

        $drafts[] = [
            'sheet' => $ws->getTitle(),
            'recipe_name' => $recipeName,
            'ingredients' => $ingredients,
            'units' => $units,
            'cost_total' => round($costTotal, 6),
            'sale_price' => round($salePrice, 6),
            'cost_unit' => round($costUnit, 6),
            'final_product' => [
                'name' => $recipeName,
                'code' => $finalCode,
                'resolved_name' => $finalMatch['nombre'] ?? null,
                'resolved_price' => isset($finalMatch['precio']) ? floatval($finalMatch['precio']) : null,
                'resolved_cost' => isset($finalMatch['costo']) ? floatval($finalMatch['costo']) : null,
                'needs_manual' => $finalCode === null,
                'unit' => 'u',
            ],
            'existing_recipe_id' => $existingRecipeId !== false ? (int)$existingRecipeId : null,
            'overwrites' => $existingRecipeId !== false,
            'ready' => true,
            'reason' => null,
        ];

        $stats['recipes_total']++;
        $stats['recipes_ready']++;
    }

    return [
        'stats' => $stats,
        'drafts' => $drafts,
        'insumos_unmatched' => $insumoProblems,
        'products_by_code' => $productsByCode,
        'products_by_norm' => $productsByNorm,
    ];
}

function applyFromDraft(array $draft, array $manualMap, array &$productsByCode, PDO $pdo, int $empId, int $now): array
{
    if (!isset($draft['existing_product_cache'])) {
        $draft['existing_product_cache'] = [];
    }

    $ingredients = $draft['ingredients'] ?? [];
    $linePrepared = [];
    $qtyTotal = 0.0;

    foreach ($ingredients as $idx => $it) {
        $selectedCode = trim((string)($manualMap['ingredients'][$idx] ?? ($it['resolved_code'] ?? ''));
        if ($selectedCode === '') {
            return [
                'status' => 'error',
                'message' => 'Ingrediente sin producto asignado: ' . $it['name'],
                'recipe' => $draft['recipe_name'],
            ];
        }

        if (!isset($productsByCode[$selectedCode])) {
            return [
                'status' => 'error',
                'message' => 'Ingrediente no existe en BD: ' . $selectedCode . ' (' . $it['name'] . ')',
                'recipe' => $draft['recipe_name'],
            ];
        }

        $linePrepared[] = [
            'code' => $selectedCode,
            'qty' => floatval($it['qty']),
            'cost' => floatval($it['line_cost']),
        ];

        $qtyTotal += floatval($it['qty']);
    }

    $finalCode = trim((string)($manualMap['final_code'] ?? ($draft['final_product']['code'] ?? '')));
    if ($finalCode === '') {
        return [
            'status' => 'error',
            'message' => 'Producto final sin asignar',
            'recipe' => $draft['recipe_name'],
        ];
    }
    if (!isset($productsByCode[$finalCode])) {
        return [
            'status' => 'error',
            'message' => 'Producto final no existe en BD: ' . $finalCode,
            'recipe' => $draft['recipe_name'],
        ];
    }

    $recipeName = trim((string)$draft['recipe_name']);
    if ($recipeName === '') {
        return [
            'status' => 'error',
            'message' => 'Nombre de receta vacío',
            'recipe' => $draft['recipe_name'],
        ];
    }

    $units = floatval($draft['units'] ?? 1.0);
    $costTotal = floatval($draft['cost_total'] ?? 0.0);
    $costUnit = floatval($draft['cost_unit'] ?? 0.0);
    $salePrice = floatval($draft['sale_price'] ?? 0.0);

    $unit = 'u';
    $existingFinal = $productsByCode[$finalCode];

    $stmtUpdFinal = $pdo->prepare("UPDATE productos
        SET nombre = ?, costo = ?, precio = ?, categoria = ?, activo = 1, es_elaborado = ?, es_materia_prima = ?, version_row = ?
        WHERE codigo = ? AND id_empresa = ?");
    $stmtFindRecipe = $pdo->prepare('SELECT id FROM recetas_cabecera WHERE nombre_receta = ? LIMIT 1');
    $stmtUpdateRecipe = $pdo->prepare("UPDATE recetas_cabecera
        SET id_producto_final = ?, unidades_resultantes = ?, costo_total_lote = ?, costo_unitario = ?, descripcion = ?, activo = 1
        WHERE id = ?");
    $stmtInsertRecipe = $pdo->prepare("INSERT INTO recetas_cabecera
        (id_producto_final, nombre_receta, unidades_resultantes, costo_total_lote, costo_unitario, descripcion, activo)
        VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmtDeleteDet = $pdo->prepare('DELETE FROM recetas_detalle WHERE id_receta = ?');
    $stmtInsDet = $pdo->prepare('INSERT INTO recetas_detalle (id_receta, id_ingrediente, cantidad, costo_calculado, pct_formula) VALUES (?, ?, ?, ?, ?)');

    [$isElab, $isMp] = deriveFlags('Elaborado', true);
    $stmtUpdFinal->execute([
        mb_substr($recipeName, 0, 200, 'UTF-8'),
        $costUnit,
        $salePrice > 0 ? $salePrice : floatval($existingFinal['precio'] ?? 0),
        (string)($existingFinal['categoria'] ?? 'Elaborado'),
        $isElab,
        $isMp,
        $now,
        $finalCode,
        $empId,
    ]);

    $desc = 'Importado desde Excel Recetas_Palweb_ok.php el ' . date('Y-m-d H:i:s');
    $stmtFindRecipe->execute([$recipeName]);
    $existingRecipeId = $stmtFindRecipe->fetchColumn();

    if ($existingRecipeId) {
        $stmtUpdateRecipe->execute([
            $finalCode,
            $units,
            $costTotal,
            $costUnit,
            $desc,
            (int)$existingRecipeId,
        ]);
        $recipeId = (int)$existingRecipeId;
    } else {
        $stmtInsertRecipe->execute([
            $finalCode,
            $recipeName,
            $units,
            $costTotal,
            $costUnit,
            $desc,
        ]);
        $recipeId = (int)$pdo->lastInsertId();
    }

    $stmtDeleteDet->execute([$recipeId]);
    foreach ($linePrepared as $it) {
        $pct = ($qtyTotal > 0) ? (($it['qty'] / $qtyTotal) * 100.0) : 0.0;
        $stmtInsDet->execute([
            $recipeId,
            $it['code'],
            $it['qty'],
            $it['cost'],
            $pct,
        ]);
    }

    return [
        'status' => 'success',
        'message' => 'Receta guardada',
        'recipe' => $recipeName,
        'recipe_id' => $recipeId,
        'final_code' => $finalCode,
        'ingredients' => count($linePrepared),
        'overwrote' => (bool)$existingRecipeId,
    ];
}

if (!file_exists($file)) {
    if ($isCli) {
        out("ERROR: no existe el archivo: $file");
        exit(1);
    }
    jsonOut(['status' => 'error', 'msg' => "No existe el archivo: $file"], 404);
}

if (!$isCli) {
    if ($action === 'search_products') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        if ($q === '') {
            jsonOut(['status' => 'ok', 'rows' => []]);
        }

        try {
            $pdo->query('SELECT 1')->execute();
        } catch (Throwable $e) {
            jsonOut(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }

        $products = [];
        $stmt = $pdo->prepare(
            'SELECT codigo, nombre, precio, costo, unidad_medida, categoria
             FROM productos
             WHERE id_empresa = ? AND (nombre LIKE ? OR codigo LIKE ?)
             ORDER BY nombre ASC
             LIMIT 15'
        );
        $term = '%' . $q . '%';
        $stmt->execute([$EMP_ID, $term, $term]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $products[] = [
                'id' => (string)$r['codigo'],
                'name' => (string)$r['nombre'],
                'price' => floatval($r['precio'] ?? 0),
                'cost' => floatval($r['costo'] ?? 0),
                'unit' => (string)($r['unidad_medida'] ?? ''),
                'category' => (string)($r['categoria'] ?? ''),
                'image' => productImage((string)$r['codigo']),
            ];
        }
        jsonOut(['status' => 'ok', 'rows' => $products]);
    }

    if ($action === 'create_product') {
        $raw = trim((string)(file_get_contents('php://input') ?: ''));
        $in = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                $in = $tmp;
            }
        }
        $in += $_POST;

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            jsonOut(['status' => 'error', 'msg' => 'El nombre del producto es obligatorio']);
        }

        $product = [];
        try {
            $loaded = loadProducts($pdo, $EMP_ID);
            $productsByCode = &$loaded['byCode'];
            $productsByNorm = &$loaded['byNorm'];

            if (resolveProductByNameOrCode($productsByNorm, $productsByCode, $name)) {
                jsonOut(['status' => 'error', 'msg' => 'Ya existe un producto con ese nombre o código']);
            }

            $cost = floatval($in['cost'] ?? 0);
            $price = floatval($in['price'] ?? 0);
            $unit = trim((string)($in['unit'] ?? 'u'));
            $cat = trim((string)($in['category'] ?? 'Elaborado'));
            $code = trim((string)($in['code'] ?? '')) ?: generateCode($name, $productsByCode, $pdo, $EMP_ID);

            [$isElab, $isMp] = deriveFlags($cat, true);
            $stmt = $pdo->prepare('INSERT INTO productos
                (codigo, nombre, precio, costo, categoria, activo, es_elaborado, es_materia_prima, es_servicio, es_cocina, id_empresa, version_row, unidad_medida)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, 0, 0, ?, ?, ?)');
            $stmt->execute([
                $code,
                mb_substr($name, 0, 200, 'UTF-8'),
                $price,
                $cost,
                mb_substr($cat, 0, 100, 'UTF-8'),
                $isElab,
                $isMp,
                $EMP_ID,
                $now,
                $unit,
            ]);

            $product = [
                'id' => $code,
                'name' => $name,
                'price' => $price,
                'cost' => $cost,
                'unit' => $unit,
                'category' => $cat,
                'image' => productImage($code),
            ];

            if (!isset($_SESSION['recipe_import_products'])) {
                $_SESSION['recipe_import_products'] = [];
            }
            $_SESSION['recipe_import_products'][$code] = $product;
            jsonOut(['status' => 'ok', 'product' => $product]);
        } catch (Throwable $e) {
            jsonOut(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }
    }

    if ($action === 'load_draft') {
        $token = trim((string)($_GET['token'] ?? ''));
        $draft = $_SESSION['recipe_import_drafts'][$token] ?? null;
        if (!$draft) {
            jsonOut(['status' => 'error', 'msg' => 'Borrador expirado']);
        }
        jsonOut(['status' => 'ok', 'data' => [
            'token' => $token,
            'stats' => $draft['stats'],
            'drafts' => $draft['drafts'],
            'file' => $draft['file'],
            'insumos_unmatched' => $draft['insumos_unmatched'],
        ]]);
    }

    if ($action === 'apply_recipes') {
        $payloadRaw = file_get_contents('php://input');
        $payload = json_decode($payloadRaw ?: '{}', true);
        if (!is_array($payload)) {
            jsonOut(['status' => 'error', 'msg' => 'JSON inválido']);
        }

        $token = trim((string)($payload['token'] ?? ''));
        $approved = array_values(array_filter(array_map('intval', (array)($payload['approved'] ?? []))));
        $ingredientMap = (array)($payload['ingredient_codes'] ?? []);
        $finalMap = (array)($payload['final_codes'] ?? []);

        if ($token === '' || !isset($_SESSION['recipe_import_drafts'][$token])) {
            jsonOut(['status' => 'error', 'msg' => 'Borrador inválido']);
        }

        $draftSet = $_SESSION['recipe_import_drafts'][$token];
        $drafts = (array)($draftSet['drafts'] ?? []);

        if (count($drafts) === 0) {
            jsonOut(['status' => 'ok', 'results' => []]);
        }

        $loaded = loadProducts($pdo, $EMP_ID);
        $productsByCode = $loaded['byCode'];
        $productsByNorm = $loaded['byNorm'];

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->beginTransaction();

        try {
            $results = [];
            $saved = 0;
            $errors = 0;

            foreach ($drafts as $idx => $d) {
                if (!in_array((int)$idx, $approved, true)) {
                    continue;
                }

                $map = [
                    'ingredients' => $ingredientMap[(string)$idx] ?? ($ingredientMap[$idx] ?? []),
                    'final_code' => trim((string)($finalMap[(string)$idx] ?? ($finalMap[$idx] ?? ''))),
                ];

                $res = applyFromDraft($d, $map, $productsByCode, $pdo, $EMP_ID, $now);
                $results[] = $res;
                if (($res['status'] ?? 'error') === 'success') {
                    $saved++;
                } else {
                    $errors++;
                }
            }

            if ($errors > 0 && $saved === 0) {
                $pdo->rollBack();
                jsonOut(['status' => 'error', 'results' => $results, 'msg' => 'No se importó ninguna receta']);
            }
            $pdo->commit();

            $_SESSION['recipe_import_drafts'][$token]['applied_count'] = $saved;
            $_SESSION['recipe_import_drafts'][$token]['errors_count'] = $errors;

            jsonOut([
                'status' => 'ok',
                'message' => "Recetas procesadas: $saved | Errores: $errors",
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonOut(['status' => 'error', 'msg' => $e->getMessage()]);
        }
    }
}

ensureRecetaSchema($pdo);
$loadedProducts = loadProducts($pdo, $EMP_ID);
$productsByNorm = $loadedProducts['byNorm'];
$productsByCode = $loadedProducts['byCode'];

try {
    $spreadsheet = IOFactory::load($file);
} catch (Throwable $e) {
    if ($isCli) {
        out('ERROR leyendo Excel: ' . $e->getMessage());
        exit(1);
    }
    jsonOut(['status' => 'error', 'msg' => 'ERROR leyendo Excel: ' . $e->getMessage()], 500);
}

$parsed = parseDrafts($spreadsheet, $productsByNorm, $productsByCode, $pdo, $EMP_ID);
$stats = $parsed['stats'];
$drafts = $parsed['drafts'];
$insumosUnmatched = $parsed['insumos_unmatched'];
$productsByCode = $parsed['products_by_code'];
$productsByNorm = $parsed['products_by_norm'];

if ($isCli) {
    out("Archivo: $file");
    out('Modo: ' . ($apply ? 'APPLY (escribe en BD)' : 'DRY-RUN (sin cambios)'));
    out('Empresa ID: ' . $EMP_ID);

    if ($apply) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->beginTransaction();

        try {
            $results = [
                'created' => 0,
                'updated' => 0,
                'applied' => 0,
                'errors' => 0,
            ];

            foreach ($drafts as $idx => $draft) {
                $res = applyFromDraft($draft, [], $productsByCode, $pdo, $EMP_ID, $now);
                if ($res['status'] === 'success') {
                    $results['applied']++;
                    if (($res['overwrote'] ?? false)) {
                        $results['updated']++;
                    } else {
                        $results['created']++;
                    }
                } else {
                    out('ERROR en ' . $draft['recipe_name'] . ': ' . $res['message']);
                    $results['errors']++;
                }
            }

            if ($results['errors'] > 0 && $results['applied'] === 0) {
                $pdo->rollBack();
                out('Fallo: no se aplicó ninguna receta.');
            } else {
                $pdo->commit();
                out('APPLY OK. Recetas guardadas: ' . $results['applied']);
            }

            out('Insumos procesados: ' . $stats['insumos_rows']);
            out('Insumos encontrados: ' . $stats['insumos_matched']);
            out('Insumos no encontrados: ' . $stats['insumos_unmatched']);
            out('Recetas aplicadas: ' . $results['applied']);
            out('Errores: ' . $results['errors']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            out('ERROR: ' . $e->getMessage());
            exit(1);
        }

        exit;
    }

    out('=== RESUMEN ===');
    out('Hojas detectadas: ' . $stats['sheets_total']);
    out('Recetas procesadas: ' . $stats['recipes_total']);
    out('Recetas omitidas: ' . $stats['recipes_skipped']);
    out('Ingredientes: ' . $stats['ingredients']);
    out('Ingredientes resueltos: ' . $stats['ingredients_resolved']);
    out('Ingredientes sin resolver: ' . $stats['ingredients_unresolved']);
    out('Insumos sin match: ' . $stats['insumos_unmatched']);
    out('Insumos no encontrados: ' . implode(", ", $insumosUnmatched));
    out($apply ? 'APLICADO: cambios guardados.' : 'DRY-RUN: no se guardaron cambios.');
    exit;
}

$token = bin2hex(random_bytes(16));
$_SESSION['recipe_import_drafts'][$token] = [
    'created_at' => $now,
    'file' => $file,
    'stats' => $stats,
    'drafts' => $drafts,
    'insumos_unmatched' => $insumosUnmatched,
];

$pendingFinals = [];
foreach ($drafts as $i => $draft) {
    if (($draft['final_product']['needs_manual'] ?? false) && $draft['final_product']['code'] === null) {
        $pendingFinals[] = $i;
    }
}

$pendingIngredients = 0;
foreach ($drafts as $draft) {
    foreach ((array)$draft['ingredients'] as $it) {
        if (($it['needs_manual'] ?? false)) {
            $pendingIngredients++;
        }
    }
}

$bootstrapJs = 'assets/js/bootstrap.bundle.min.js';

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Importar recetas desde Excel (interactivo)</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f6f8fb;
            margin: 0;
            padding: 16px;
        }
        .card-recipe {
            box-shadow: 0 1px 6px rgba(0, 0, 0, .07);
            border: 1px solid #dee2e6;
        }
        .ingredient-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 6px;
            border-bottom: 1px solid #f1f3f5;
        }
        .ingredient-row:last-child {
            border-bottom: none;
        }
        .search-result-item {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 6px;
            background: #fff;
            cursor: pointer;
        }
        .search-result-item:hover {
            border-color: #0d6efd;
        }
        .thumb {
            width: 34px;
            height: 34px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #d6dce5;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3 gap-2">
        <div>
            <h4 class="mb-1">Importar recetas desde Excel</h4>
            <div class="text-muted">Archivo: <code><?php echo htmlspecialchars($file); ?></code></div>
        </div>
        <div>
            <button class="btn btn-outline-primary" id="openReload">Reanalizar</button>
            <a class="btn btn-link" href="<?php echo htmlspecialchars($file); ?>" target="_blank">Abrir archivo</a>
        </div>
    </div>

    <div class="alert alert-warning" style="font-size: .92rem;">
        No se crean productos nuevos automáticamente.
        Para productos finales faltantes puedes crear uno nuevo o escoger uno existente.
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card card-body">
                <div class="text-muted">Recetas detectadas</div>
                <h5><?php echo (int)$stats['recipes_total']; ?></h5>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body">
                <div class="text-muted">Ingredientes sin coincidencia</div>
                <h5 class="text-danger"><?php echo (int)$pendingIngredients; ?></h5>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body">
                <div class="text-muted">Productos finales sin coincidencia</div>
                <h5 class="text-danger"><?php echo count($pendingFinals); ?></h5>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-body">
                <div class="text-muted">Insumos sin match</div>
                <h5 class="text-danger"><?php echo (int)$stats['insumos_unmatched']; ?></h5>
            </div>
        </div>
    </div>

    <?php if (count($insumosUnmatched) > 0): ?>
        <div class="alert alert-danger">
            Productos base sin coincidencia en BD: <?php echo count($insumosUnmatched); ?>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <button class="btn btn-success" id="applySelected">Importar recetas aprobadas</button>
        <span id="applyStatus" class="ms-2 text-muted"></span>
    </div>

    <div id="recipesWrap" class="row g-2">
        <?php foreach ($drafts as $i => $draft):
            $final = $draft['final_product'];
            $finalCode = $final['code'] ?? '';
            $finalNeeds = (bool)($final['needs_manual'] ?? false);
        ?>
            <div class="col-12 recipe-card" data-index="<?php echo $i; ?>">
                <div class="card card-recipe">
                    <div class="card-header d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <input type="checkbox" class="form-check-input approve-recipe" id="approve-<?php echo $i; ?>" data-index="<?php echo $i; ?>">
                                <label class="form-check-label fw-bold" for="approve-<?php echo $i; ?>">
                                    <?php echo htmlspecialchars($draft['recipe_name']); ?>
                                </label>
                            </div>
                            <div class="small text-muted">Hoja: <?php echo htmlspecialchars($draft['sheet']); ?> · Unidades: <?php echo (float)$draft['units']; ?></div>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo $draft['overwrites'] ? 'text-bg-warning' : 'text-bg-success'; ?>">
                                <?php echo $draft['overwrites'] ? 'Sobrescribir existente' : 'Nueva'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Producto final:</strong>
                            <span id="finalLabel-<?php echo $i; ?>"><?php echo htmlspecialchars($finalCode === '' ? 'Pendiente asignar' : $finalCode . ' - ' . ($final['resolved_name'] ?? 'Producto encontrado')); ?></span>
                            <input type="hidden" id="finalCode-<?php echo $i; ?>" value="<?php echo htmlspecialchars($finalCode); ?>">
                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="openProductPicker(<?php echo $i; ?>, null)">Seleccionar</button>
                            <?php if ($finalNeeds): ?>
                                <button type="button" class="btn btn-sm btn-outline-success ms-1" onclick="openFinalCreate(<?php echo $i; ?>)">Crear nuevo</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="openProductPicker(<?php echo $i; ?>, null)">Cambiar</button>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">Costo lote: <strong><?php echo number_format((float)$draft['cost_total'], 2); ?></strong> · Costo unitario: <strong><?php echo number_format((float)$draft['cost_unit'], 2); ?></strong> · Venta sugerida: <strong><?php echo number_format((float)$draft['sale_price'], 2); ?></strong></div>

                        <div class="mt-3">
                            <div class="fw-semibold mb-1">Ingredientes</div>
                            <div class="border rounded bg-white">
                                <?php foreach ((array)$draft['ingredients'] as $j => $it):
                                    $needsManual = (bool)($it['needs_manual'] ?? false);
                                    $current = trim((string)($it['resolved_code'] ?? ''));
                                    $display = $current === ''
                                        ? 'Pendiente asignar: ' . $it['name']
                                        : $current . ' · ' . $it['name'];
                                ?>
                                    <div class="ingredient-row" data-recipe="<?php echo $i; ?>" data-index="<?php echo $j; ?>">
                                        <div>
                                            <div class="small text-muted"><?php echo number_format((float)$it['qty'], 3); ?> <?php echo htmlspecialchars($it['unit']); ?></div>
                                            <div class="text-nowrap"><?php echo htmlspecialchars($it['name']); ?></div>
                                            <div id="ingLabel-<?php echo $i; ?>-<?php echo $j; ?>" class="small <?php echo $needsManual ? 'text-danger' : 'text-success'; ?>"><?php echo htmlspecialchars($display); ?></div>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openProductPicker(<?php echo $i; ?>, <?php echo $j; ?>)">
                                                <?php echo $needsManual ? 'Seleccionar producto correcto' : 'Cambiar'; ?>
                                            </button>
                                        </div>
                                        <input type="hidden" id="ingCode-<?php echo $i; ?>-<?php echo $j; ?>" value="<?php echo htmlspecialchars($current); ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="searchPickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Seleccionar producto desde base</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="input-group mb-2">
            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input id="productSearchInput" class="form-control" placeholder="Buscar por nombre, sku o precio" autocomplete="off">
        </div>
        <div id="searchResults" style="max-height: 420px; overflow: auto;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="createFinalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crear producto final</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Nombre</label>
          <input id="createName" class="form-control">
        </div>
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label">Costo</label>
            <input id="createCost" type="number" class="form-control" min="0" step="0.01" value="0">
          </div>
          <div class="col-4">
            <label class="form-label">Precio</label>
            <input id="createPrice" type="number" class="form-control" min="0" step="0.01" value="0">
          </div>
          <div class="col-4">
            <label class="form-label">Unidad</label>
            <input id="createUnit" class="form-control" value="u">
          </div>
        </div>
        <div class="mt-2">
          <label class="form-label">Categoría</label>
          <input id="createCategory" class="form-control" value="Elaborado">
        </div>
        <div class="text-muted small mt-2">Crea el producto final y lo asigna a la receta actual.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="saveNewFinalBtn">Guardar producto</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const recipes = <?php echo json_encode($drafts, JSON_UNESCAPED_UNICODE); ?>;
const token = '<?php echo $token; ?>';
let pickerMode = {
    type: null,
    recipe: null,
    ingredient: null
};

const searchModalEl = document.getElementById('searchPickerModal');
const searchModal = new bootstrap.Modal(searchModalEl);
const createModal = new bootstrap.Modal(document.getElementById('createFinalModal'));
const resultWrap = document.getElementById('searchResults');
const queryInput = document.getElementById('productSearchInput');

function setFinalLabel(recipeIdx, code, name) {
    const label = document.getElementById('finalLabel-' + recipeIdx);
    const inp = document.getElementById('finalCode-' + recipeIdx);
    inp.value = code;
    label.textContent = code + ' - ' + (name || 'Producto seleccionado');
    if (label.classList) {
        label.classList.remove('text-danger');
        label.classList.add('text-success');
    }
}

function setIngredientLabel(recipeIdx, ingIdx, code, name) {
    const label = document.getElementById('ingLabel-' + recipeIdx + '-' + ingIdx);
    const inp = document.getElementById('ingCode-' + recipeIdx + '-' + ingIdx);
    inp.value = code;
    label.textContent = code + ' · ' + name;
    label.classList.remove('text-danger');
    label.classList.add('text-success');
}

function openProductPicker(recipeIdx, ingredientIdx) {
    pickerMode = {
        type: ingredientIdx === null ? 'final' : 'ingredient',
        recipe: recipeIdx,
        ingredient: ingredientIdx
    };
    queryInput.value = '';
    resultWrap.innerHTML = '<div class="text-muted small">Escribe para buscar productos</div>';
    searchModal.show();
    queryInput.focus();
    window.__searchTicker = 0;
}

function openFinalCreate(recipeIdx) {
    document.getElementById('createName').value = recipes[recipeIdx].recipe_name || '';
    document.getElementById('createCost').value = 0;
    document.getElementById('createPrice').value = 0;
    document.getElementById('createUnit').value = 'u';
    document.getElementById('createCategory').value = 'Elaborado';
    pickerMode = {
        type: 'create_final',
        recipe: recipeIdx,
        ingredient: null
    };
    createModal.show();
}

async function searchProducts(q) {
    if (!q || q.length < 2) {
        resultWrap.innerHTML = '';
        return;
    }
    const res = await fetch('importar_recetas_palweb_ok.php?action=search_products&q=' + encodeURIComponent(q), {credentials: 'same-origin'});
    const data = await res.json();
    const rows = Array.isArray(data.rows) ? data.rows : [];
    if (!rows.length) {
        resultWrap.innerHTML = '<div class="text-danger small">Sin resultados.</div>';
        return;
    }
    resultWrap.innerHTML = rows.map((r, idx) => {
        const safeCode = encodeURIComponent((r.id || '').toString());
        const safeName = encodeURIComponent((r.name || '').toString());
        const label = `${r.id || ''} · Precio: ${Number(r.price || 0).toFixed(2)} · Unidad: ${r.unit || ''}`;
        return `
            <div class="search-result-item" data-idx="${idx}">
                <div class="d-flex align-items-center gap-2">
                    <img src="${r.image || ''}" class="thumb" alt="img">
                    <div>
                        <div class="fw-semibold">${r.name || ''}</div>
                        <div class="small text-muted">${label}</div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-primary pick-product-btn" data-code="${safeCode}" data-name="${safeName}">Usar</button>
            </div>
        `;
    }).join('');

    document.querySelectorAll('.pick-product-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            pickProduct(decodeURIComponent(btn.dataset.code || ''), decodeURIComponent(btn.dataset.name || ''));
        });
    });
}

function pickProduct(code, name) {
    if (pickerMode.type === 'final') {
        setFinalLabel(pickerMode.recipe, code, name);
    } else if (pickerMode.type === 'ingredient') {
        setIngredientLabel(pickerMode.recipe, pickerMode.ingredient, code, name);
    }
    searchModal.hide();
}

let searchTimer = null;
queryInput.addEventListener('input', () => {
    const q = queryInput.value || '';
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        searchProducts(q);
    }, 220);
});

document.getElementById('saveNewFinalBtn').addEventListener('click', async () => {
    const name = (document.getElementById('createName').value || '').trim();
    const cost = parseFloat(document.getElementById('createCost').value || '0');
    const price = parseFloat(document.getElementById('createPrice').value || '0');
    const unit = (document.getElementById('createUnit').value || 'u').trim();
    const category = (document.getElementById('createCategory').value || 'Elaborado').trim();

    if (!name) {
        alert('Ingrese nombre');
        return;
    }

    const res = await fetch('importar_recetas_palweb_ok.php?action=create_product', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            name,
            cost,
            price,
            unit,
            category
        })
    });
    const data = await res.json();
    if (!data || data.status !== 'ok') {
        alert(data && data.msg ? data.msg : 'No se pudo crear');
        return;
    }

    setFinalLabel(pickerMode.recipe, data.product.id, data.product.name);
    createModal.hide();
});

async function applySelected(forceSingle) {
    const approved = [];
    const ingredient_codes = {};
    const final_codes = {};

    document.querySelectorAll('.approve-recipe:checked').forEach(el => {
        const recipeIdx = parseInt(el.getAttribute('data-index') || '0', 10);
        approved.push(recipeIdx);

        const finalCode = (document.getElementById('finalCode-' + recipeIdx)?.value || '').trim();
        final_codes[String(recipeIdx)] = finalCode;

        const payloadMap = {};
        document.querySelectorAll('[id^="ingCode-' + recipeIdx + '-"]').forEach(inp => {
            const m = inp.id.match(/ingCode-(\d+)-(\d+)/);
            const ingIdx = m ? m[2] : null;
            if (ingIdx !== null) {
                payloadMap[String(ingIdx)] = inp.value.trim();
            }
        });
        ingredient_codes[String(recipeIdx)] = payloadMap;
    });

    if (approved.length === 0) {
        alert('Selecciona al menos una receta para importar');
        return;
    }

    const res = await fetch('importar_recetas_palweb_ok.php?action=apply_recipes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            token,
            approved,
            ingredient_codes,
            final_codes
        })
    });
    const data = await res.json();
    if (!data || data.status !== 'ok') {
        document.getElementById('applyStatus').textContent = (data && data.msg) ? data.msg : 'No se pudo importar';
        return;
    }

    const results = Array.isArray(data.results) ? data.results : [];
    const ok = results.filter(r => r.status === 'success').length;
    const fail = results.filter(r => r.status !== 'success').length;

    document.getElementById('applyStatus').textContent = 'Aplicadas: ' + ok + ' | Errores: ' + fail;

    results.forEach(r => {
        const recipeName = r.recipe || '';
        const idx = recipes.findIndex(d => d.recipe_name === recipeName);
        if (idx >= 0) {
            const el = document.querySelector('.recipe-card[data-index="' + idx + '"]');
            if (el) {
                const status = r.status === 'success' ? 'success' : 'danger';
                const badge = `<span class="badge text-bg-${status} ms-2">${r.message || ''}</span>`;
                el.querySelector('.card-header > div:first-child')?.insertAdjacentHTML('beforeend', badge);
            }
        }
    });
}

document.getElementById('applySelected').addEventListener('click', () => applySelected(false));

document.getElementById('openReload').addEventListener('click', () => location.reload());
</script>
</body>
</html>
