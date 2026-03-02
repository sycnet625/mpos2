<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$defaultFile = '/home/ubuntu/Recetas_Palweb_ok.xlsx';
$isCli = (PHP_SAPI === 'cli');
$args = $isCli ? getopt('', ['file::', 'apply']) : [];
$file = $args['file'] ?? ($_GET['file'] ?? $defaultFile);
$apply = $isCli ? isset($args['apply']) : (isset($_GET['apply']) && $_GET['apply'] === '1');
$EMP_ID = intval($config['id_empresa'] ?? 1);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
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
    // Si es una formula pura, no intentar inferir numero para evitar errores (ej: "=C11*450").
    if (isset($raw[0]) && $raw[0] === '=') {
        return null;
    }
    // Caso mixto como "20 =VLOOKUP(...)" -> tomar solo el valor inicial.
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

function upsertProduct(
    PDO $pdo,
    int $empId,
    array &$productsByNorm,
    array &$productsByCode,
    string $name,
    ?float $cost,
    ?float $price,
    ?string $unit,
    ?string $category,
    bool $isFinal,
    bool $apply
): array {
    $rawName = cleanIngredientName($name);
    $norm = normalizeName($rawName);
    $unit = $unit ? substr(trim($unit), 0, 20) : 'g';
    $category = $category ? trim($category) : ($isFinal ? 'Elaborado' : 'Insumo');
    [$isElab, $isMp] = deriveFlags($category, $isFinal);
    $nowVersion = time();

    $existing = $productsByNorm[$norm] ?? null;
    if ($existing) {
        $newCost = ($cost !== null) ? $cost : floatval($existing['costo'] ?? 0);
        $newPrice = ($price !== null) ? $price : floatval($existing['precio'] ?? 0);
        if ($apply) {
            $sql = "UPDATE productos
                    SET nombre = ?, costo = ?, precio = ?, unidad_medida = ?, categoria = ?,
                        activo = 1, es_elaborado = ?, es_materia_prima = ?, es_servicio = 0, version_row = ?
                    WHERE codigo = ? AND id_empresa = ?";
            $pdo->prepare($sql)->execute([
                mb_substr($rawName, 0, 200, 'UTF-8'),
                $newCost,
                $newPrice,
                $unit,
                mb_substr($category, 0, 100, 'UTF-8'),
                $isElab,
                $isMp,
                $nowVersion,
                $existing['codigo'],
                $empId
            ]);
        }
        $existing['nombre'] = $rawName;
        $existing['costo'] = $newCost;
        $existing['precio'] = $newPrice;
        $existing['unidad_medida'] = $unit;
        $existing['categoria'] = $category;
        $existing['es_elaborado'] = $isElab;
        $existing['es_materia_prima'] = $isMp;
        $productsByNorm[$norm] = $existing;
        $productsByCode[$existing['codigo']] = $existing;
        return ['codigo' => $existing['codigo'], 'created' => false, 'updated' => true, 'costo' => $newCost];
    }

    $code = generateCode($rawName, $productsByCode, $pdo, $empId);
    $newCost = $cost ?? 0.0;
    $newPrice = $price ?? 0.0;
    if ($apply) {
        $sql = "INSERT INTO productos
                (codigo, nombre, precio, costo, categoria, activo, es_elaborado, es_materia_prima, es_servicio, es_cocina, id_empresa, version_row, unidad_medida)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, 0, 0, ?, ?, ?)";
        $pdo->prepare($sql)->execute([
            $code,
            mb_substr($rawName, 0, 200, 'UTF-8'),
            $newPrice,
            $newCost,
            mb_substr($category, 0, 100, 'UTF-8'),
            $isElab,
            $isMp,
            $empId,
            $nowVersion,
            $unit
        ]);
    }
    $row = [
        'codigo' => $code,
        'nombre' => $rawName,
        'costo' => $newCost,
        'precio' => $newPrice,
        'unidad_medida' => $unit,
        'categoria' => $category,
        'es_elaborado' => $isElab,
        'es_materia_prima' => $isMp
    ];
    $productsByNorm[$norm] = $row;
    $productsByCode[$code] = $row;
    return ['codigo' => $code, 'created' => true, 'updated' => false, 'costo' => $newCost];
}

if (!file_exists($file)) {
    out("ERROR: no existe el archivo: $file");
    exit(1);
}

out("Archivo: $file");
out("Modo: " . ($apply ? 'APPLY (escribe en BD)' : 'DRY-RUN (sin cambios)'));
out("Empresa ID: $EMP_ID");

try {
    $spreadsheet = IOFactory::load($file);
} catch (Throwable $e) {
    out('ERROR leyendo Excel: ' . $e->getMessage());
    exit(1);
}

try {
    $pdo->exec("ALTER TABLE recetas_detalle ADD COLUMN IF NOT EXISTS pct_formula DECIMAL(5,2) DEFAULT 0");
} catch (Throwable $e) {
    // no-op
}

$stmtAllProducts = $pdo->prepare("SELECT codigo, nombre, costo, precio, unidad_medida, categoria, es_elaborado, es_materia_prima
                                  FROM productos WHERE id_empresa = ?");
$stmtAllProducts->execute([$EMP_ID]);
$productsByNorm = [];
$productsByCode = [];
foreach ($stmtAllProducts->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $norm = normalizeName((string)$p['nombre']);
    if (!isset($productsByNorm[$norm])) {
        $productsByNorm[$norm] = $p;
    }
    $productsByCode[$p['codigo']] = $p;
}

$stats = [
    'prod_created' => 0,
    'prod_updated' => 0,
    'recipes_created' => 0,
    'recipes_updated' => 0,
    'recipes_skipped' => 0,
    'details_rows' => 0,
    'errors' => 0
];

if ($apply) {
    $pdo->beginTransaction();
}

try {
    $insumosSheet = $spreadsheet->getSheetByName('Insumos') ?: $spreadsheet->getSheetByName('Inventario');
    if ($insumosSheet instanceof Worksheet) {
        out("Procesando hoja de productos base: " . $insumosSheet->getTitle());
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

            $up = upsertProduct($pdo, $EMP_ID, $productsByNorm, $productsByCode, $name, $cost, $price, $unit, $cat, false, $apply);
            $stats[$up['created'] ? 'prod_created' : 'prod_updated']++;
        }
    } else {
        out('Aviso: no se encontro hoja Insumos/Inventario.');
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

    out("Hojas de receta detectadas: " . count($recipeSheets));
    $stmtFindRecipe = $pdo->prepare("SELECT id FROM recetas_cabecera WHERE nombre_receta = ? LIMIT 1");
    $stmtUpdateRecipe = $pdo->prepare("UPDATE recetas_cabecera
                                       SET id_producto_final = ?, unidades_resultantes = ?, costo_total_lote = ?, costo_unitario = ?, descripcion = ?, activo = 1
                                       WHERE id = ?");
    $stmtInsertRecipe = $pdo->prepare("INSERT INTO recetas_cabecera
                                       (id_producto_final, nombre_receta, unidades_resultantes, costo_total_lote, costo_unitario, descripcion, activo)
                                       VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmtDeleteDet = $pdo->prepare("DELETE FROM recetas_detalle WHERE id_receta = ?");
    $stmtInsDet = $pdo->prepare("INSERT INTO recetas_detalle (id_receta, id_ingrediente, cantidad, costo_calculado, pct_formula) VALUES (?, ?, ?, ?, ?)");

    foreach ($recipeSheets as $ws) {
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

            $unitCell = $colUnit ? (string)$ws->getCell($colUnit . $r)->getCalculatedValue() : '';
            $unit = inferUnit($nameRaw, $unitCell);
            $lineCost = $colCost ? parseNum($ws->getCell($colCost . $r)->getCalculatedValue()) : null;
            $costPerUnit = ($lineCost !== null && $qty > 0) ? ($lineCost / $qty) : null;

            $upIng = upsertProduct(
                $pdo,
                $EMP_ID,
                $productsByNorm,
                $productsByCode,
                $nameRaw,
                $costPerUnit,
                null,
                $unit,
                null,
                false,
                $apply
            );
            $stats[$upIng['created'] ? 'prod_created' : 'prod_updated']++;
            $realLineCost = ($lineCost !== null) ? $lineCost : ($qty * floatval($upIng['costo']));

            $ingredients[] = [
                'codigo' => $upIng['codigo'],
                'cantidad' => $qty,
                'costo_linea' => $realLineCost
            ];
            $qtyTotal += $qty;
        }

        if (count($ingredients) === 0) {
            $stats['recipes_skipped']++;
            out("Receta omitida (sin ingredientes validos): $recipeName");
            continue;
        }

        $units = 1.0;
        $costTotal = 0.0;
        $salePrice = 0.0;
        $costTotalFromIngredients = 0.0;
        foreach ($ingredients as $it) {
            $costTotalFromIngredients += floatval($it['costo_linea']);
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

        $upFinal = upsertProduct(
            $pdo,
            $EMP_ID,
            $productsByNorm,
            $productsByCode,
            $recipeName,
            $costUnit,
            ($salePrice > 0 ? $salePrice : null),
            'u',
            'Elaborado',
            true,
            $apply
        );
        $stats[$upFinal['created'] ? 'prod_created' : 'prod_updated']++;

        $desc = 'Importado desde Excel Recetas_Palweb_ok.xlsx el ' . date('Y-m-d H:i:s');
        $stmtFindRecipe->execute([$recipeName]);
        $existingRecipeId = $stmtFindRecipe->fetchColumn();
        if ($existingRecipeId) {
            if ($apply) {
                $stmtUpdateRecipe->execute([$upFinal['codigo'], $units, $costTotal, $costUnit, $desc, $existingRecipeId]);
                $stmtDeleteDet->execute([$existingRecipeId]);
            }
            $recipeId = intval($existingRecipeId);
            $stats['recipes_updated']++;
        } else {
            if ($apply) {
                $stmtInsertRecipe->execute([$upFinal['codigo'], $recipeName, $units, $costTotal, $costUnit, $desc]);
                $recipeId = intval($pdo->lastInsertId());
            } else {
                $recipeId = -1;
            }
            $stats['recipes_created']++;
        }

        if ($apply) {
            foreach ($ingredients as $it) {
                $pct = ($qtyTotal > 0) ? (($it['cantidad'] / $qtyTotal) * 100.0) : 0.0;
                $stmtInsDet->execute([
                    $recipeId,
                    $it['codigo'],
                    $it['cantidad'],
                    $it['costo_linea'],
                    $pct
                ]);
                $stats['details_rows']++;
            }
        } else {
            $stats['details_rows'] += count($ingredients);
        }

        out("Receta OK: $recipeName | ing=" . count($ingredients) . " | unidades=$units | costo_lote=" . round($costTotal, 2));
    }

    if ($apply && $pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $stats['errors']++;
    out('ERROR: ' . $e->getMessage());
    out($e->getTraceAsString());
    exit(1);
}

out('');
out('=== RESUMEN ===');
out('Productos creados: ' . $stats['prod_created']);
out('Productos actualizados: ' . $stats['prod_updated']);
out('Recetas creadas: ' . $stats['recipes_created']);
out('Recetas actualizadas: ' . $stats['recipes_updated']);
out('Recetas omitidas: ' . $stats['recipes_skipped']);
out('Filas detalle procesadas: ' . $stats['details_rows']);
out('Errores: ' . $stats['errors']);
out($apply ? 'APLICADO: cambios guardados en BD.' : 'DRY-RUN: no se guardaron cambios.');
