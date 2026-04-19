<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/product_image_pipeline.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

$defaultFile = '/home/ubuntu/Recetas_Palweb_ok.xlsx';
$uploadBaseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/palweb_recetas_upload_import';
$uploadNotice = '';
$uploadNoticeType = 'info';
$selectedFileLabel = '';
$isCli = (PHP_SAPI === 'cli');
$action = $isCli ? '' : (string)($_REQUEST['action'] ?? '');
$uploadDirCandidates = [
    __DIR__ . '/tmp/recetas_upload_import',
    $uploadBaseDir,
    rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/reservas_upload_import',
    '/tmp/recetas_upload_import',
];
$uploadDir = '';

if (!$isCli) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    foreach ($uploadDirCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $parent = dirname($candidate);
        if (!is_dir($candidate)) {
            if (!is_dir($parent) || !is_writable($parent)) {
                continue;
            }
            if (!@mkdir($candidate, 0755, true) && !is_dir($candidate)) {
                continue;
            }
        }
        if (is_writable($candidate)) {
            $uploadDir = $candidate;
            break;
        }
    }

    if ($uploadDir === '') {
        $uploadNotice = 'No se pudo inicializar el directorio temporal de carga (permisos insuficientes).';
        $uploadNoticeType = 'danger';
    }
}

$args = $isCli ? getopt('', ['file::', 'apply', 'create-missing']) : [];
$file = $isCli ? ($args['file'] ?? $defaultFile) : $defaultFile;
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

function cleanupRecipeUpload(?string $path, string $uploadDir): void
{
    if ($path === null || $path === '') {
        return;
    }
    $realBase = realpath($uploadDir);
    $realPath = realpath($path);
    if ($realBase === false || $realPath === false) {
        return;
    }
    if (strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0 && $realPath !== $realBase) {
        return;
    }
    if (is_file($realPath)) {
        @unlink($realPath);
    }
}

function nextRecipeUploadPath(string $uploadDir, string $originalName): string
{
    $base = preg_replace('/[^a-zA-Z0-9._-]+/u', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'xlsx';
    }
    if (!in_array($ext, ['xls', 'xlsx'], true)) {
        $ext = 'xlsx';
    }
    $token = bin2hex(random_bytes(4));
    $safeBase = trim($base, '._-');
    if ($safeBase === '') {
        $safeBase = 'recetas_palweb';
    }
    return $uploadDir . '/' . $safeBase . '_' . date('Ymd_His') . '_' . $token . '.' . $ext;
}

function isValidExcelUpload(array $file): bool
{
    if (!isset($file['error']) || !isset($file['name']) || !isset($file['tmp_name'])) {
        return false;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    return in_array($ext, ['xls', 'xlsx'], true);
}

function loadRecipeSpreadsheet(string $file): Spreadsheet
{
    $reader = IOFactory::createReaderForFile($file);
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
    }
    if (method_exists($reader, 'setReadEmptyCells')) {
        $reader->setReadEmptyCells(false);
    }
    return $reader->load($file);
}

function normalizeName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = preg_replace('/\s*\((g|gr|gramos|ml|l|u|ud|unidad|unidades)\)\s*$/iu', '', $name) ?? $name;
    return mb_strtolower(trim($name), 'UTF-8');
}

function stripAccents(string $value): string
{
    $map = [
        'a' => ['á', 'à', 'ä', 'â', 'ã', 'å'],
        'e' => ['é', 'è', 'ë', 'ê'],
        'i' => ['í', 'ì', 'ï', 'î'],
        'o' => ['ó', 'ò', 'ö', 'ô', 'õ'],
        'u' => ['ú', 'ù', 'ü', 'û'],
        'n' => ['ñ'],
        'c' => ['ç'],
    ];
    foreach ($map as $to => $chars) {
        $value = str_replace($chars, $to, $value);
    }
    return $value;
}

function normalizeSearchText(string $name): string
{
    $name = normalizeName($name);
    $name = stripAccents($name);
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

function normalizeToken(string $token): string
{
    $token = normalizeSearchText($token);
    if ($token === '') {
        return '';
    }
    $stopWords = [
        'de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'con', 'sin', 'para', 'por', 'en', 'al',
        'un', 'una', 'unos', 'unas',
    ];
    if (in_array($token, $stopWords, true)) {
        return '';
    }
    if (mb_strlen($token, 'UTF-8') > 4 && preg_match('/(es|s)$/u', $token)) {
        $trimmed = preg_replace('/(es|s)$/u', '', $token) ?? $token;
        if (mb_strlen($trimmed, 'UTF-8') >= 3) {
            $token = $trimmed;
        }
    }
    return $token;
}

function tokenizeName(string $name): array
{
    $tokens = preg_split('/\s+/', normalizeSearchText($name)) ?: [];
    $out = [];
    foreach ($tokens as $token) {
        $normalized = normalizeToken($token);
        if ($normalized !== '') {
            $out[$normalized] = true;
        }
    }
    $result = array_keys($out);
    sort($result, SORT_STRING);
    return $result;
}

function buildNameFingerprint(string $name): string
{
    return implode(' ', tokenizeName($name));
}

function compactName(string $name): string
{
    return str_replace(' ', '', normalizeSearchText($name));
}

function compareCompactStrings(string $left, string $right): float
{
    if ($left === '' || $right === '') {
        return 0.0;
    }
    if ($left === $right) {
        return 1.0;
    }
    similar_text($left, $right, $percent);
    return max(0.0, min(1.0, $percent / 100.0));
}

function scoreTokens(array $leftTokens, array $rightTokens): float
{
    if (count($leftTokens) === 0 || count($rightTokens) === 0) {
        return 0.0;
    }
    $leftMap = array_fill_keys($leftTokens, true);
    $rightMap = array_fill_keys($rightTokens, true);
    $intersection = count(array_intersect_key($leftMap, $rightMap));
    $union = count($leftMap + $rightMap);
    $containment = $intersection / max(1, min(count($leftTokens), count($rightTokens)));
    $jaccard = $intersection / max(1, $union);
    return max($containment, ($jaccard * 0.7) + ($containment * 0.3));
}

function explainProductSimilarity(array $product, string $rawName): array
{
    $targetNorm = normalizeSearchText($rawName);
    $targetFingerprint = buildNameFingerprint($rawName);
    $targetCompact = compactName($rawName);
    $targetTokens = tokenizeName($rawName);
    if ($targetNorm === '') {
        return [
            'score' => 0.0,
            'token_score' => 0.0,
            'compact_score' => 0.0,
            'excel_tokens' => [],
            'product_tokens' => [],
            'common_tokens' => [],
            'reason' => 'texto_vacio',
        ];
    }

    $nameNorm = (string)($product['_search_norm'] ?? normalizeSearchText((string)($product['nombre'] ?? '')));
    $fingerprint = (string)($product['_search_fingerprint'] ?? buildNameFingerprint((string)($product['nombre'] ?? '')));
    $compact = (string)($product['_search_compact'] ?? compactName((string)($product['nombre'] ?? '')));
    $tokens = (array)($product['_search_tokens'] ?? tokenizeName((string)($product['nombre'] ?? '')));
    $commonTokens = array_values(array_intersect($targetTokens, $tokens));

    $reason = 'similaridad';
    $tokenScore = 0.0;
    $compactScore = 0.0;
    $finalScore = 0.0;

    if ($targetNorm === $nameNorm || ($targetFingerprint !== '' && $targetFingerprint === $fingerprint)) {
        $reason = $targetNorm === $nameNorm ? 'exacto' : 'frase';
        $tokenScore = 1.0;
        $compactScore = 1.0;
        $finalScore = 1.0;
    } else {
        $tokenScore = scoreTokens($targetTokens, $tokens);
        $compactScore = compareCompactStrings($targetCompact, $compact);

        if ($targetCompact !== '' && $compact !== '' && (str_contains($compact, $targetCompact) || str_contains($targetCompact, $compact))) {
            $compactScore = max($compactScore, 0.92);
            $reason = 'contencion';
        }

        $finalScore = max($tokenScore, ($tokenScore * 0.65) + ($compactScore * 0.35), $compactScore);
    }

    return [
        'score' => round($finalScore, 4),
        'token_score' => round($tokenScore, 4),
        'compact_score' => round($compactScore, 4),
        'excel_tokens' => $targetTokens,
        'product_tokens' => $tokens,
        'common_tokens' => $commonTokens,
        'reason' => $reason,
    ];
}

function scoreProductSimilarity(array $product, string $rawName): float
{
    $explanation = explainProductSimilarity($product, $rawName);
    return floatval($explanation['score'] ?? 0.0);
}

function publicProductSuggestion(array $product): array
{
    return [
        'codigo' => (string)$product['codigo'],
        'nombre' => (string)$product['nombre'],
        'precio' => floatval($product['precio'] ?? 0),
        'costo' => floatval($product['costo'] ?? 0),
        'unidad' => (string)($product['unidad_medida'] ?? ''),
        'categoria' => (string)($product['categoria'] ?? ''),
        'image' => productImage((string)$product['codigo']),
        'score' => round(floatval($product['_score'] ?? 0), 3),
        'why' => is_array($product['_why'] ?? null) ? $product['_why'] : null,
    ];
}

function hasExactProductConflict(array $productsByNorm, array $productsByFingerprint, array $productsByCode, string $name, ?string $code = null): bool
{
    $normalized = normalizeName($name);
    $fingerprint = buildNameFingerprint($name);
    if ($code !== null && $code !== '' && isset($productsByCode[$code])) {
        return true;
    }
    if ($normalized !== '' && isset($productsByNorm[$normalized])) {
        return true;
    }
    if ($fingerprint !== '' && isset($productsByFingerprint[$fingerprint])) {
        return true;
    }
    return false;
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

function findSuggestedProducts(array $productsList, string $rawName, int $limit = 5, float $minScore = 0.55): array
{
    $raw = trim($rawName);
    if ($raw === '') {
        return [];
    }

    $scored = [];
    foreach ($productsList as $product) {
        $why = explainProductSimilarity($product, $raw);
        $score = floatval($why['score'] ?? 0);
        if ($score < $minScore) {
            continue;
        }
        $product['_score'] = $score;
        $product['_why'] = $why;
        $scored[] = $product;
    }

    usort($scored, static function (array $left, array $right): int {
        $scoreCmp = ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return strcmp((string)($left['nombre'] ?? ''), (string)($right['nombre'] ?? ''));
    });

    return array_slice($scored, 0, $limit);
}

function resolveProductByNameOrCode(
    array $productsByNorm,
    array $productsByCode,
    array $productsByFingerprint,
    array $productsList,
    string $rawName
): ?array
{
    $raw = trim($rawName);
    if ($raw === '') {
        return null;
    }

    if (isset($productsByCode[$raw])) {
        $match = $productsByCode[$raw];
        $match['_match_mode'] = 'codigo';
        $match['_match_score'] = 1.0;
        $match['_match_why'] = [
            'score' => 1.0,
            'token_score' => 1.0,
            'compact_score' => 1.0,
            'excel_tokens' => tokenizeName($raw),
            'product_tokens' => (array)($match['_search_tokens'] ?? tokenizeName((string)($match['nombre'] ?? ''))),
            'common_tokens' => (array)($match['_search_tokens'] ?? tokenizeName((string)($match['nombre'] ?? ''))),
            'reason' => 'codigo',
        ];
        return $match;
    }

    $norm = normalizeName(cleanIngredientName($raw));
    if (isset($productsByNorm[$norm])) {
        $match = $productsByNorm[$norm];
        $match['_match_mode'] = 'exacto';
        $match['_match_score'] = 1.0;
        $match['_match_why'] = explainProductSimilarity($match, $raw);
        return $match;
    }

    $fingerprint = buildNameFingerprint($raw);
    if ($fingerprint !== '' && isset($productsByFingerprint[$fingerprint])) {
        $match = $productsByFingerprint[$fingerprint];
        $match['_match_mode'] = 'frase';
        $match['_match_score'] = 0.99;
        $match['_match_why'] = explainProductSimilarity($match, $raw);
        return $match;
    }

    $suggestions = findSuggestedProducts($productsList, $raw, 2, 0.78);
    if (count($suggestions) === 0) {
        return null;
    }

    $best = $suggestions[0];
    $secondScore = isset($suggestions[1]['_score']) ? floatval($suggestions[1]['_score']) : 0.0;
    $bestScore = floatval($best['_score'] ?? 0);
    if ($bestScore >= 0.93 && ($bestScore - $secondScore) >= 0.08) {
        $best['_match_mode'] = 'similar';
        $best['_match_score'] = $bestScore;
        $best['_match_why'] = is_array($best['_why'] ?? null) ? $best['_why'] : explainProductSimilarity($best, $raw);
        return $best;
    }

    return null;
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
    $productsByFingerprint = [];
    $productsByCode = [];
    $productsList = [];
    foreach ($stmtAllProducts->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $norm = normalizeName((string)$p['nombre']);
        $fingerprint = buildNameFingerprint((string)$p['nombre']);
        $p['_search_norm'] = normalizeSearchText((string)$p['nombre']);
        $p['_search_tokens'] = tokenizeName((string)$p['nombre']);
        $p['_search_fingerprint'] = $fingerprint;
        $p['_search_compact'] = compactName((string)$p['nombre']);
        if (!isset($productsByNorm[$norm])) {
            $productsByNorm[$norm] = $p;
        }
        if ($fingerprint !== '' && !isset($productsByFingerprint[$fingerprint])) {
            $productsByFingerprint[$fingerprint] = $p;
        }
        $productsByCode[$p['codigo']] = $p;
        $productsList[] = $p;
    }

    return ['byNorm' => $productsByNorm, 'byFingerprint' => $productsByFingerprint, 'byCode' => $productsByCode, 'list' => $productsList];
}

function parseDrafts(Spreadsheet $spreadsheet, array $productsByNorm, array $productsByCode, PDO $pdo, int $empId): array
{
    $loadedProductsMeta = [
        'byFingerprint' => [],
        'list' => array_values($productsByCode),
    ];
    if (isset($GLOBALS['recipe_import_loaded_products']) && is_array($GLOBALS['recipe_import_loaded_products'])) {
        $loadedProductsMeta = array_merge($loadedProductsMeta, $GLOBALS['recipe_import_loaded_products']);
    }
    $productsByFingerprint = (array)($loadedProductsMeta['byFingerprint'] ?? []);
    $productsList = (array)($loadedProductsMeta['list'] ?? []);

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
            $match = resolveProductByNameOrCode($productsByNorm, $productsByCode, $productsByFingerprint, $productsList, $name);
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
            $match = resolveProductByNameOrCode($productsByNorm, $productsByCode, $productsByFingerprint, $productsList, $nameRaw);
            $suggestions = [];
            if (!$match) {
                $suggestions = array_map('publicProductSuggestion', findSuggestedProducts($productsList, $nameRaw));
            }

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
                'resolved_mode' => (string)($match['_match_mode'] ?? ''),
                'resolved_score' => isset($match['_match_score']) ? round(floatval($match['_match_score']), 3) : null,
                'resolved_why' => is_array($match['_match_why'] ?? null) ? $match['_match_why'] : null,
                'suggestions' => $suggestions,
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

        $finalMatch = resolveProductByNameOrCode($productsByNorm, $productsByCode, $productsByFingerprint, $productsList, $recipeName);
        $finalCode = is_array($finalMatch) ? (string)$finalMatch['codigo'] : null;
        $finalSuggestions = [];
        if (!$finalMatch) {
            $finalSuggestions = array_map('publicProductSuggestion', findSuggestedProducts($productsList, $recipeName));
        }

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
                'resolved_mode' => (string)($finalMatch['_match_mode'] ?? ''),
                'resolved_score' => isset($finalMatch['_match_score']) ? round(floatval($finalMatch['_match_score']), 3) : null,
                'resolved_why' => is_array($finalMatch['_match_why'] ?? null) ? $finalMatch['_match_why'] : null,
                'suggestions' => $finalSuggestions,
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
        $selectedCode = trim((string)($manualMap['ingredients'][$idx] ?? ($it['resolved_code'] ?? '')));
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

if (isset($_GET['clear_uploaded']) && !$isCli) {
    $old = $_SESSION['recipe_import_file'] ?? null;
    if (is_string($old)) {
        cleanupRecipeUpload($old, $uploadDir);
    }
    unset($_SESSION['recipe_import_file']);
    unset($_SESSION['recipe_import_file_name']);
    header('Location: importar_recetas_palweb_ok.php');
    exit;
}

if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_recipe_file'])) {
    if ($uploadDir === '') {
        $uploadNotice = 'No hay directorio temporal disponible para guardar el archivo subido.';
        $uploadNoticeType = 'danger';
    } elseif (!isset($_FILES['excel_file'])) {
        $uploadNotice = 'No se envió ningún archivo.';
        $uploadNoticeType = 'danger';
    } elseif (!isValidExcelUpload($_FILES['excel_file'])) {
        $uploadNotice = 'Formato inválido. Sube solo archivos .xlsx o .xls';
        $uploadNoticeType = 'danger';
    } else {
        $fileUpload = $_FILES['excel_file'];
        $target = nextRecipeUploadPath($uploadDir, (string)$fileUpload['name']);
        if (!move_uploaded_file((string)$fileUpload['tmp_name'], $target)) {
            $uploadNotice = 'No se pudo guardar el archivo subido.';
            $uploadNoticeType = 'danger';
        } else {
            $old = $_SESSION['recipe_import_file'] ?? null;
            if (is_string($old)) {
                cleanupRecipeUpload($old, $uploadDir);
            }
            $_SESSION['recipe_import_file'] = $target;
            $_SESSION['recipe_import_file_name'] = $fileUpload['name'];
            header('Location: importar_recetas_palweb_ok.php?uploaded=1');
            exit;
        }
    }
}

$jsonActions = ['search_products', 'create_product', 'load_draft', 'apply_recipes'];

if (!$isCli) {
    $sessionFile = $_SESSION['recipe_import_file'] ?? null;
    if (is_string($sessionFile) && is_file($sessionFile)) {
        $file = $sessionFile;
        $selectedFileLabel = $_SESSION['recipe_import_file_name'] ?? basename($sessionFile);
    } elseif (isset($_REQUEST['file']) && is_string($_REQUEST['file']) && is_file($_REQUEST['file'])) {
        $file = $_REQUEST['file'];
        $selectedFileLabel = basename($file);
    } elseif ($defaultFile !== '' && is_file($defaultFile)) {
        $file = $defaultFile;
        $selectedFileLabel = basename($defaultFile);
    } else {
        $file = $defaultFile;
        $selectedFileLabel = '';
    }

    if ($action === 'upload_file') {
        jsonOut(['status' => 'error', 'msg' => 'Usa el formulario de carga para seleccionar el archivo.'], 400);
    }
}

if (!$isCli && in_array($action, $jsonActions, true)) {
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
            $productsByFingerprint = &$loaded['byFingerprint'];
            $productsList = &$loaded['list'];

            if (hasExactProductConflict($productsByNorm, $productsByFingerprint, $productsByCode, $name, trim((string)($in['code'] ?? '')))) {
                jsonOut(['status' => 'error', 'msg' => 'Ya existe un producto con ese nombre o código']);
            }

            $cost = floatval($in['cost'] ?? 0);
            $price = floatval($in['price'] ?? 0);
            $unit = trim((string)($in['unit'] ?? 'u'));
            $cat = trim((string)($in['category'] ?? 'Insumos'));
            if ($cat === '') {
                $cat = 'Insumos';
            }
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
            product_image_pipeline_ensure_placeholder($code, mb_substr($name, 0, 200, 'UTF-8'));

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
        $GLOBALS['recipe_import_loaded_products'] = $loaded;

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

    $defaultStats = [
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
    $stats = $defaultStats;
    $drafts = [];
    $insumosUnmatched = [];
    $pendingFinals = [];
    $pendingIngredients = 0;
    $token = '';
    $recipeImportCategories = ['Insumos'];

    if (file_exists($file)) {
        ensureRecetaSchema($pdo);
        $loadedProducts = loadProducts($pdo, $EMP_ID);
        $productsByNorm = $loadedProducts['byNorm'];
        $productsByCode = $loadedProducts['byCode'];
        $GLOBALS['recipe_import_loaded_products'] = $loadedProducts;
        $stmtCategories = $pdo->prepare("SELECT DISTINCT TRIM(categoria) AS categoria FROM productos WHERE id_empresa = ? AND categoria IS NOT NULL AND TRIM(categoria) <> ''");
        $stmtCategories->execute([$EMP_ID]);
        $recipeImportCategories = [];
        while (($cat = $stmtCategories->fetchColumn()) !== false) {
            $cat = trim((string)$cat);
            if ($cat !== '') {
                $recipeImportCategories[] = $cat;
            }
        }
        if (empty($recipeImportCategories)) {
            $recipeImportCategories = ['Insumos'];
        } elseif (!in_array('Insumos', $recipeImportCategories, true)) {
            array_unshift($recipeImportCategories, 'Insumos');
        }
        sort($recipeImportCategories, SORT_NATURAL | SORT_FLAG_CASE);

        try {
            $spreadsheet = loadRecipeSpreadsheet($file);
        } catch (Throwable $e) {
            $uploadNotice = 'No se pudo leer el archivo: ' . $e->getMessage();
            $uploadNoticeType = 'danger';
        }

        if (!($uploadNotice !== '' && $uploadNoticeType === 'danger')) {
            $parsed = parseDrafts($spreadsheet, $productsByNorm, $productsByCode, $pdo, $EMP_ID);
            $stats = $parsed['stats'];
            $drafts = $parsed['drafts'];
            $insumosUnmatched = $parsed['insumos_unmatched'];
            $productsByCode = $parsed['products_by_code'];
            $productsByNorm = $parsed['products_by_norm'];
            if (!$isCli) {
                $token = bin2hex(random_bytes(16));
                $_SESSION['recipe_import_drafts'][$token] = [
                    'created_at' => $now,
                    'file' => $file,
                    'stats' => $stats,
                    'drafts' => $drafts,
                    'insumos_unmatched' => $insumosUnmatched,
                ];
            }
        }
    } else {
        if (!$isCli && $uploadNotice === '') {
            $uploadNotice = 'No se encontró el archivo para procesar. Sube un archivo Excel desde esta misma pantalla.';
            $uploadNoticeType = 'warning';
        }
    }
if ($isCli && !file_exists($file)) {
    out("ERROR: no existe el archivo: $file");
    exit(1);
}

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

$uploadedSuccess = (!$isCli && isset($_GET['uploaded']) && $_GET['uploaded'] === '1');
if ($uploadedSuccess && $uploadNotice === '') {
    $uploadNotice = 'Archivo cargado. Se está analizando para generar el dashboard.';
    $uploadNoticeType = 'success';
}

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
$currentFileLabel = $selectedFileLabel !== '' ? $selectedFileLabel : (is_file($file) ? basename((string)$file) : 'Sin archivo');
$hasUploadedFile = isset($_SESSION['recipe_import_file']) && is_string($_SESSION['recipe_import_file']) && is_file($_SESSION['recipe_import_file']);
$isParsingReady = (bool)(!$isCli && is_file($file) && $uploadNoticeType !== 'danger');
$allowedPerPage = [10, 20, 50, 100, 0];
$rawPerPage = (int)($_GET['per_page'] ?? 10);
if ($rawPerPage === 0) {
    $perPage = 0;
} elseif (in_array($rawPerPage, [10, 20, 50, 100], true)) {
    $perPage = $rawPerPage;
} else {
    $perPage = 10;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRecipes = count($drafts);
$isAll = ($perPage === 0);
$totalPages = $isAll ? 1 : max(1, (int)ceil($totalRecipes / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$pageOffset = $isAll ? 0 : (($page - 1) * $perPage);
$pagedDrafts = $isAll ? $drafts : array_slice($drafts, $pageOffset, $perPage);
$pageFrom = $totalRecipes > 0 ? ($pageOffset + 1) : 0;
$pageTo = $isAll ? $totalRecipes : min($pageOffset + $perPage, $totalRecipes);

$bootstrapJs = 'assets/js/bootstrap.bundle.min.js';

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Importar recetas desde Excel (interactivo)</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
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
        .suggestion-chip {
            border: 1px solid #cfd8e3;
            background: #f8fafc;
            color: #1f2937;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: .8rem;
            cursor: pointer;
        }
        .suggestion-chip:hover {
            border-color: #0d6efd;
            color: #0d6efd;
        }
        .similarity-details {
            margin-top: 6px;
        }
        .similarity-details summary {
            cursor: pointer;
            color: #0d6efd;
            font-size: .8rem;
            user-select: none;
            list-style: none;
        }
        .similarity-details summary::-webkit-details-marker {
            display: none;
        }
        .similarity-details summary::before {
            content: '+ ';
            font-weight: 700;
        }
        .similarity-details[open] summary::before {
            content: '- ';
        }
        .similarity-panel {
            margin-top: 6px;
            padding: 8px 10px;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            font-size: .8rem;
            color: #475569;
        }
        .similarity-panel strong {
            color: #0f172a;
        }
        .similarity-line + .similarity-line {
            margin-top: 2px;
        }
        .floating-import-btn {
            position: fixed;
            left: 16px;
            bottom: 16px;
            z-index: 1080;
            border-radius: 999px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, .25);
            padding: 10px 16px;
        }
        .replicate-action-btn {
            min-width: 32px;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3 gap-2">
        <div>
            <h4 class="mb-1">Importar recetas desde Excel</h4>
            <div class="text-muted">Archivo activo: <code><?php echo htmlspecialchars($currentFileLabel); ?></code></div>
            <?php if ($hasUploadedFile): ?>
                <div class="small text-success">Archivo cargado desde tu equipo.</div>
            <?php endif; ?>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm me-2" title="Menú principal">
                <i class="fas fa-home me-1"></i> Inicio
            </a>
            <a href="main_menu.php" class="btn btn-outline-info btn-sm me-2" title="Main menu">
                <i class="fas fa-th-large me-1"></i> Main Menu
            </a>
            <button type="button" class="btn btn-outline-warning btn-sm me-2" data-bs-toggle="modal" data-bs-target="#helpModal">
                <i class="fa-solid fa-circle-question me-1"></i> Ayuda
            </button>
            <button class="btn btn-outline-primary" id="openReload" <?php echo $isParsingReady ? '' : 'disabled'; ?>>Reanalizar</button>
        </div>
    </div>

    <div class="card card-body mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <div class="fw-semibold">Subir archivo de recetas (.xls/.xlsx)</div>
                <div class="small text-muted">Selecciona el archivo del disco del cliente para procesarlo y crear/actualizar el dashboard.</div>
            </div>
            <form id="uploadForm" method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-start flex-grow-1 justify-content-end">
                <input type="file" name="excel_file" id="excelFileInput" class="form-control" accept=".xlsx,.xls" required>
                <button class="btn btn-primary" name="upload_recipe_file" value="1" type="submit">Cargar y analizar</button>
            </form>
        </div>
        <?php if ($hasUploadedFile): ?>
            <a class="btn btn-sm btn-outline-secondary mt-2" href="importar_recetas_palweb_ok.php?clear_uploaded=1">Quitar archivo cargado</a>
        <?php endif; ?>
    </div>

    <?php if ($uploadNotice !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($uploadNoticeType); ?>" style="font-size: .92rem;">
            <?php echo htmlspecialchars($uploadNotice); ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" style="font-size: .92rem;">
            No se crean productos nuevos automáticamente.
            Para productos finales faltantes puedes crear uno nuevo o escoger uno existente.
        </div>
    <?php endif; ?>

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

    <div class="row g-2 mb-3 align-items-center">
        <div class="col-12 col-md-3">
            <label class="form-label mb-0">Registros por página</label>
            <select id="perPageSelect" class="form-select form-select-sm">
                <?php foreach ($allowedPerPage as $v): ?>
                    <option value="<?php echo (int)$v; ?>" <?php echo $perPage === $v ? 'selected' : ''; ?>>
                        <?php echo $v === 0 ? 'Todas' : (int)$v; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-9">
            <div class="d-flex justify-content-md-end align-items-center flex-wrap gap-2">
                <span class="text-muted small">
                    <?php if ($isAll): ?>
                        Mostrando todo: <?php echo (int)$pageFrom; ?> - <?php echo (int)$pageTo; ?> de <?php echo (int)$totalRecipes; ?>
                    <?php else: ?>
                        Página <?php echo (int)$page; ?> de <?php echo (int)$totalPages; ?> · Mostrando <?php echo (int)$pageFrom; ?> - <?php echo (int)$pageTo; ?> de <?php echo (int)$totalRecipes; ?>
                    <?php endif; ?>
                </span>
                <?php if (!$isAll): ?>
                    <a class="btn btn-outline-secondary btn-sm <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $token === '' ? '#' : 'importar_recetas_palweb_ok.php?' . http_build_query(['per_page' => $perPage, 'page' => $page - 1]); ?>">Anterior</a>
                    <a class="btn btn-outline-secondary btn-sm <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo $token === '' ? '#' : 'importar_recetas_palweb_ok.php?' . http_build_query(['per_page' => $perPage, 'page' => $page + 1]); ?>">Siguiente</a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleCardsBtn">Expandir todas</button>
            </div>
            <span id="applyStatus" class="small text-muted ms-md-2"></span>
        </div>
    </div>

    <?php if (!$isAll): ?>
    <div class="d-flex justify-content-center mb-2">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a
                class="btn btn-sm btn-<?php echo $p === $page ? 'primary' : 'outline-secondary'; ?> me-1"
                href="<?php echo $token === '' ? '#' : 'importar_recetas_palweb_ok.php?' . http_build_query(['per_page' => $perPage, 'page' => $p]); ?>"
            >
                <?php echo (int)$p; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <div id="recipesWrap" class="row g-2">
        <?php foreach ($pagedDrafts as $localIdx => $draft):
            $i = $pageOffset + $localIdx;
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
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary ms-2"
                                data-bs-toggle="collapse"
                                data-bs-target="#recipeBody-<?php echo $i; ?>"
                                aria-expanded="false"
                                aria-controls="recipeBody-<?php echo $i; ?>"
                            >
                                Ver detalle
                            </button>
                        </div>
                    </div>
                    <div class="collapse recipe-collapse" id="recipeBody-<?php echo $i; ?>">
                        <div class="card-body">
                        <div class="mb-2">
                            <strong>Producto final:</strong>
                            <span id="finalLabel-<?php echo $i; ?>"><?php echo htmlspecialchars($finalCode === '' ? 'Pendiente asignar' : $finalCode . ' - ' . ($final['resolved_name'] ?? $final['name'] ?? 'Producto encontrado')); ?></span>
                                <?php if (!empty($final['resolved_mode']) && !in_array($final['resolved_mode'], ['exacto', 'codigo'], true)): ?>
                                    <span class="badge text-bg-info ms-1">
                                        <?php echo htmlspecialchars('Auto: ' . $final['resolved_mode'] . ' ' . number_format((float)($final['resolved_score'] ?? 0), 2)); ?>
                                    </span>
                                <?php endif; ?>
                                <input type="hidden" id="finalCode-<?php echo $i; ?>" value="<?php echo htmlspecialchars($finalCode); ?>">
                                <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="openProductPicker(<?php echo $i; ?>, null)">Seleccionar</button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info ms-1 d-none replicate-action-btn replicate-final-btn"
                                    id="replicateFinalBtn-<?php echo $i; ?>"
                                    data-recipe="<?php echo $i; ?>"
                                    data-ingredient=""
                                    data-code="<?php echo htmlspecialchars((string)$finalCode, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-name="<?php echo urlencode((string)($final['resolved_name'] ?? $final['name'] ?? '')); ?>"
                                    title="Replicar este producto final en otras recetas pendientes"
                                >
                                    ↻
                                </button>
                                <?php if ($finalNeeds): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success ms-1" onclick="openFinalCreate(<?php echo $i; ?>)">Crear nuevo</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="openProductPicker(<?php echo $i; ?>, null)">Cambiar</button>
                                <?php endif; ?>
                            <?php if ($finalNeeds && !empty($final['suggestions'])): ?>
                                <div class="small text-muted mt-2">Sugerencias parecidas:</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <?php foreach ((array)$final['suggestions'] as $suggestion): ?>
                                        <div>
                                            <button
                                                type="button"
                                                class="suggestion-chip suggestion-pill"
                                                data-recipe="<?php echo $i; ?>"
                                                data-ingredient=""
                                                data-code="<?php echo htmlspecialchars((string)($suggestion['codigo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-name="<?php echo urlencode((string)($suggestion['nombre'] ?? '')); ?>"
                                            >
                                                <?php echo htmlspecialchars($suggestion['codigo'] . ' · ' . $suggestion['nombre'] . ' · ' . number_format((float)($suggestion['score'] ?? 0), 2)); ?>
                                            </button>
                                            <?php if (!empty($suggestion['why']) && is_array($suggestion['why'])): ?>
                                                <details class="similarity-details">
                                                    <summary>Ver por que</summary>
                                                    <div class="similarity-panel">
                                                        <div class="similarity-line"><strong>Score:</strong> <?php echo number_format((float)($suggestion['why']['score'] ?? 0), 2); ?> | <strong>Motivo:</strong> <?php echo htmlspecialchars((string)($suggestion['why']['reason'] ?? 'similaridad')); ?></div>
                                                        <div class="similarity-line"><strong>Coinciden:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['common_tokens'] ?? [])) ?: 'sin tokens comunes'); ?></div>
                                                        <div class="similarity-line"><strong>Excel:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['excel_tokens'] ?? []))); ?></div>
                                                        <div class="similarity-line"><strong>BD:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['product_tokens'] ?? []))); ?></div>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($finalNeeds): ?>
                                <div class="small text-danger mt-2">Sin parecido claro. El sistema necesita que el usuario seleccione el producto correcto.</div>
                            <?php endif; ?>
                            <?php if (!$finalNeeds && !empty($final['resolved_why']) && is_array($final['resolved_why']) && !in_array($final['resolved_mode'], ['exacto', 'codigo'], true)): ?>
                                <details class="similarity-details">
                                    <summary>Ver por que</summary>
                                    <div class="similarity-panel">
                                        <div class="similarity-line"><strong>Por que se parecio:</strong> score <?php echo number_format((float)($final['resolved_why']['score'] ?? 0), 2); ?> | <?php echo htmlspecialchars((string)($final['resolved_why']['reason'] ?? 'similaridad')); ?></div>
                                        <div class="similarity-line"><strong>Coinciden:</strong> <?php echo htmlspecialchars(implode(', ', (array)($final['resolved_why']['common_tokens'] ?? [])) ?: 'sin tokens comunes'); ?></div>
                                        <div class="similarity-line"><strong>Excel:</strong> <?php echo htmlspecialchars(implode(', ', (array)($final['resolved_why']['excel_tokens'] ?? []))); ?></div>
                                        <div class="similarity-line"><strong>BD:</strong> <?php echo htmlspecialchars(implode(', ', (array)($final['resolved_why']['product_tokens'] ?? []))); ?></div>
                                    </div>
                                </details>
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
                                        : $current . ' · ' . ($it['resolved_name'] ?? $it['name']);
                                ?>
                                    <div class="ingredient-row" data-recipe="<?php echo $i; ?>" data-index="<?php echo $j; ?>">
                                        <div>
                                            <div class="small text-muted"><?php echo number_format((float)$it['qty'], 3); ?> <?php echo htmlspecialchars($it['unit']); ?></div>
                                            <div class="text-nowrap"><?php echo htmlspecialchars($it['name']); ?></div>
                                            <div id="ingLabel-<?php echo $i; ?>-<?php echo $j; ?>" class="small <?php echo $needsManual ? 'text-danger' : 'text-success'; ?>"><?php echo htmlspecialchars($display); ?></div>
                                            <?php if (!$needsManual && !empty($it['resolved_mode']) && !in_array($it['resolved_mode'], ['exacto', 'codigo'], true)): ?>
                                                <div class="small text-info">Auto reconocido por similitud: <?php echo htmlspecialchars((string)$it['resolved_mode']); ?> <?php echo number_format((float)($it['resolved_score'] ?? 0), 2); ?></div>
                                            <?php endif; ?>
                                            <?php if (!$needsManual && !empty($it['resolved_why']) && is_array($it['resolved_why']) && !in_array($it['resolved_mode'], ['exacto', 'codigo'], true)): ?>
                                                <details class="similarity-details">
                                                    <summary>Ver por que</summary>
                                                    <div class="similarity-panel">
                                                        <div class="similarity-line"><strong>Por que se parecio:</strong> score <?php echo number_format((float)($it['resolved_why']['score'] ?? 0), 2); ?> | <?php echo htmlspecialchars((string)($it['resolved_why']['reason'] ?? 'similaridad')); ?></div>
                                                        <div class="similarity-line"><strong>Coinciden:</strong> <?php echo htmlspecialchars(implode(', ', (array)($it['resolved_why']['common_tokens'] ?? [])) ?: 'sin tokens comunes'); ?></div>
                                                        <div class="similarity-line"><strong>Excel:</strong> <?php echo htmlspecialchars(implode(', ', (array)($it['resolved_why']['excel_tokens'] ?? []))); ?></div>
                                                        <div class="similarity-line"><strong>BD:</strong> <?php echo htmlspecialchars(implode(', ', (array)($it['resolved_why']['product_tokens'] ?? []))); ?></div>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                            <?php if ($needsManual && !empty($it['suggestions'])): ?>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                        <?php foreach ((array)$it['suggestions'] as $suggestion): ?>
                                                        <div>
                                                            <button
                                                                type="button"
                                                                class="suggestion-chip suggestion-pill"
                                                                data-recipe="<?php echo $i; ?>"
                                                                data-ingredient="<?php echo $j; ?>"
                                                                data-code="<?php echo htmlspecialchars((string)($suggestion['codigo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-name="<?php echo urlencode((string)($suggestion['nombre'] ?? '')); ?>"
                                                            >
                                                                <?php echo htmlspecialchars($suggestion['codigo'] . ' · ' . $suggestion['nombre'] . ' · ' . number_format((float)($suggestion['score'] ?? 0), 2)); ?>
                                                            </button>
                                                            <?php if (!empty($suggestion['why']) && is_array($suggestion['why'])): ?>
                                                                <details class="similarity-details">
                                                                    <summary>Ver por que</summary>
                                                                    <div class="similarity-panel">
                                                                        <div class="similarity-line"><strong>Score:</strong> <?php echo number_format((float)($suggestion['why']['score'] ?? 0), 2); ?> | <strong>Motivo:</strong> <?php echo htmlspecialchars((string)($suggestion['why']['reason'] ?? 'similaridad')); ?></div>
                                                                        <div class="similarity-line"><strong>Coinciden:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['common_tokens'] ?? [])) ?: 'sin tokens comunes'); ?></div>
                                                                        <div class="similarity-line"><strong>Excel:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['excel_tokens'] ?? []))); ?></div>
                                                                        <div class="similarity-line"><strong>BD:</strong> <?php echo htmlspecialchars(implode(', ', (array)($suggestion['why']['product_tokens'] ?? []))); ?></div>
                                                                    </div>
                                                                </details>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($needsManual): ?>
                                                <div class="small text-danger mt-1">No se encontró un parecido confiable. Selección manual requerida.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openProductPicker(<?php echo $i; ?>, <?php echo $j; ?>)">
                                            <?php echo $needsManual ? 'Seleccionar producto correcto' : 'Cambiar'; ?>
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-info mt-1 replicate-action-btn replicate-ingredient-btn d-none"
                                            id="replicateIngBtn-<?php echo $i; ?>-<?php echo $j; ?>"
                                            data-recipe="<?php echo $i; ?>"
                                            data-ingredient="<?php echo $j; ?>"
                                            data-code="<?php echo htmlspecialchars((string)$current, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-name="<?php echo urlencode((string)($it['resolved_name'] ?? $it['name'] ?? '')); ?>"
                                            title="Replicar este ingrediente en otras recetas pendientes"
                                        >
                                            ↻
                                        </button>
                                    </div>
                                    <div>
                                        <input type="hidden" id="ingCode-<?php echo $i; ?>-<?php echo $j; ?>" value="<?php echo htmlspecialchars($current); ?>">
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<button id="applySelected" class="btn btn-success floating-import-btn" type="button" <?php echo $token !== '' ? '' : 'disabled'; ?>>
    <i class="fas fa-file-import me-1"></i> Importar recetas aprobadas
</button>

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

<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cómo usar Importar Recetas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <ol class="mb-0">
          <li><strong>Cargar archivo:</strong> Sube el archivo <code>.xls/.xlsx</code> desde el botón <strong>Cargar y analizar</strong>.</li>
          <li><strong>Revisar resumen:</strong> Verifica el total de recetas, ingredientes y pendientes desde las tarjetas iniciales.</li>
          <li><strong>Editar coincidencias:</strong>
            <ul class="mt-1">
              <li>Si un ingrediente o producto final ya se resolvió, aparece como verde.</li>
              <li>Si aparece <strong>Pendiente asignar</strong>, haz clic en <strong>Seleccionar producto correcto</strong> o <strong>Seleccionar</strong> para buscar en la base.</li>
            </ul>
          </li>
          <li><strong>Selección masiva:</strong>
            <ul class="mt-1">
              <li>Después de una selección manual, usa el botón <strong>↻</strong> para replicar ese mismo producto al resto de recetas con el mismo nombre de ingrediente/receta pendiente.</li>
              <li>Esto reduce tiempo cuando hay varios errores repetidos.</li>
            </ul>
          </li>
          <li><strong>Importar:</strong> Marca las recetas y pulsa <strong>Importar recetas aprobadas</strong>.</li>
          <li><strong>Paginación:</strong> Usa <strong>Registros por página</strong> o el selector <strong>Todas</strong> si quieres ver todo el lote en una sola vista.</li>
          <li><strong>Consola:</strong> Revisa el estado en la barra inferior (éxitos/errores) mientras se aplica la importación.</li>
        </ol>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
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
        </div>
        <div class="mt-2">
          <label class="form-label">Categoría</label>
          <select id="createCategorySelect" class="form-select">
            <?php foreach ($recipeImportCategories as $cat): ?>
              <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $cat === 'Insumos' ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
            <option value="__custom__">Otra categoría...</option>
          </select>
        </div>
        <div class="mt-2 d-none" id="createCategoryCustomWrap">
          <label class="form-label">Nueva categoría</label>
          <input id="createCategory" class="form-control" placeholder="Escribe la categoría">
        </div>
        <div class="row g-2 mt-2">
          <div class="col-8">
            <label class="form-label">Unidad de medida</label>
            <select id="createUnitSelect" class="form-select">
              <option value="u" selected>u (unidad)</option>
              <option value="kg">kg</option>
              <option value="g">g</option>
              <option value="gr">gr</option>
              <option value="lb">lb</option>
              <option value="L">L</option>
              <option value="ml">ml</option>
              <option value="cc">cc</option>
              <option value="pz">pz</option>
              <option value="pc">pc</option>
              <option value="otro">Otro...</option>
            </select>
          </div>
          <div class="col-4 d-none" id="createUnitCustomWrap">
            <label class="form-label">Otra unidad</label>
            <input id="createUnitOther" class="form-control" placeholder="Ej: tazas">
          </div>
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

<div class="modal fade" id="replicateReportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="replicateReportTitle">Resultado de réplica</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="replicateReportMessage" class="fw-semibold mb-2"></div>
        <div id="replicateReportStats" class="small text-muted mb-2"></div>
        <div class="mb-2">
          <strong>Aplicadas:</strong>
          <ul id="replicateReportApplied" class="small mb-0"></ul>
        </div>
        <div class="mb-2">
          <strong>Sin cambios:</strong>
          <ul id="replicateReportSkipped" class="small mb-0"></ul>
        </div>
        <div class="small text-danger">
          <strong>Errores:</strong>
          <ul id="replicateReportErrors" class="small mb-0"></ul>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
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
const statusEl = document.getElementById('applyStatus');

const searchModalEl = document.getElementById('searchPickerModal');
const searchModal = new bootstrap.Modal(searchModalEl);
const createModal = new bootstrap.Modal(document.getElementById('createFinalModal'));
const replicateReportModal = new bootstrap.Modal(document.getElementById('replicateReportModal'));
const resultWrap = document.getElementById('searchResults');
const queryInput = document.getElementById('productSearchInput');
const toggleCardsBtn = document.getElementById('toggleCardsBtn');
const recipeCollapses = document.querySelectorAll('.recipe-collapse');
const createUnitSelect = document.getElementById('createUnitSelect');
const createUnitOther = document.getElementById('createUnitOther');
const createUnitCustomWrap = document.getElementById('createUnitCustomWrap');
const createCategorySelect = document.getElementById('createCategorySelect');
const createCategory = document.getElementById('createCategory');
const createCategoryCustomWrap = document.getElementById('createCategoryCustomWrap');
const replicateReport = {
    title: document.getElementById('replicateReportTitle'),
    message: document.getElementById('replicateReportMessage'),
    stats: document.getElementById('replicateReportStats'),
    applied: document.getElementById('replicateReportApplied'),
    skipped: document.getElementById('replicateReportSkipped'),
    errors: document.getElementById('replicateReportErrors'),
};

function normalizeItemName(recipe) {
    return (recipe && recipe.recipe_name ? recipe.recipe_name : '').toString().trim() || 'Sin nombre';
}

function setListItems(listEl, items) {
    if (!listEl) {
        return;
    }
    listEl.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
        const li = document.createElement('li');
        li.className = 'text-muted';
        li.textContent = 'Ninguno';
        listEl.appendChild(li);
        return;
    }

    items.forEach(item => {
        const li = document.createElement('li');
        li.textContent = item;
        listEl.appendChild(li);
    });
}

function showReplicationReport({ title, message, totals, applied, skipped, errors }) {
    if (replicateReport.title) {
        replicateReport.title.textContent = title || 'Resultado de réplica';
    }
    if (replicateReport.message) {
        replicateReport.message.textContent = message || '';
    }
    if (replicateReport.stats) {
        const total = Number(totals?.total || 0);
        const appliedCount = Number(totals?.applied || 0);
        const skippedCount = Number(totals?.skipped || 0);
        const errorCount = Number(totals?.errors || 0);
        replicateReport.stats.textContent = `Coincidencias encontradas: ${total} | Aplicadas: ${appliedCount} | Sin cambios: ${skippedCount} | Errores: ${errorCount}`;
    }
    setListItems(replicateReport.applied, applied);
    setListItems(replicateReport.skipped, skipped);
    setListItems(replicateReport.errors, errors);
    replicateReportModal.show();
}

function setStatus(message) {
    if (statusEl) {
        statusEl.textContent = message;
    }
}

function normalizeTextForMatch(value) {
    const source = (value || '').toString().normalize('NFD');
    return source
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function setIngredientDraft(recipeIdx, ingIdx, code, name) {
    if (!recipes[recipeIdx] || !recipes[recipeIdx].ingredients || !recipes[recipeIdx].ingredients[ingIdx]) {
        return;
    }
    recipes[recipeIdx].ingredients[ingIdx].resolved_code = code;
    recipes[recipeIdx].ingredients[ingIdx].resolved_name = name;
    recipes[recipeIdx].ingredients[ingIdx].needs_manual = false;
    recipes[recipeIdx].ingredients[ingIdx].resolved_mode = 'manual';
    recipes[recipeIdx].ingredients[ingIdx].resolved_score = 1.0;
    recipes[recipeIdx].ingredients[ingIdx].resolved_why = null;
}

function setFinalDraft(recipeIdx, code, name) {
    if (!recipes[recipeIdx] || !recipes[recipeIdx].final_product) {
        return;
    }
    recipes[recipeIdx].final_product.code = code;
    recipes[recipeIdx].final_product.resolved_name = name;
    recipes[recipeIdx].final_product.resolved_mode = 'manual';
    recipes[recipeIdx].final_product.resolved_score = 1.0;
    recipes[recipeIdx].final_product.needs_manual = false;
    recipes[recipeIdx].final_product.resolved_why = null;
}

function setFinalLabel(recipeIdx, code, name, isManual = false) {
    const label = document.getElementById('finalLabel-' + recipeIdx);
    const inp = document.getElementById('finalCode-' + recipeIdx);
    const repBtn = document.getElementById('replicateFinalBtn-' + recipeIdx);
    if (inp) {
        inp.value = code;
    }
    if (label) {
        label.textContent = code + ' - ' + (name || 'Producto seleccionado');
        label.classList.remove('text-danger');
        label.classList.add('text-success');
    }
    setFinalDraft(recipeIdx, code, name);
    if (repBtn) {
        repBtn.dataset.code = code;
        repBtn.dataset.name = encodeURIComponent(name || '');
        if (isManual) {
            repBtn.classList.remove('d-none');
        }
    }
}

function setIngredientLabel(recipeIdx, ingIdx, code, name, isManual = false) {
    const label = document.getElementById('ingLabel-' + recipeIdx + '-' + ingIdx);
    const inp = document.getElementById('ingCode-' + recipeIdx + '-' + ingIdx);
    const repBtn = document.getElementById('replicateIngBtn-' + recipeIdx + '-' + ingIdx);
    if (inp) {
        inp.value = code;
    }
    if (label) {
        label.textContent = code + ' · ' + name;
        label.classList.remove('text-danger');
        label.classList.add('text-success');
    }
    setIngredientDraft(recipeIdx, ingIdx, code, name);
    if (repBtn) {
        repBtn.dataset.code = code;
        repBtn.dataset.name = encodeURIComponent(name || '');
        if (isManual) {
            repBtn.classList.remove('d-none');
        }
    }
}

function pickSuggestedProduct(recipeIdx, ingredientIdx, code, name) {
    if (ingredientIdx === null || ingredientIdx === undefined) {
        setFinalLabel(recipeIdx, code, name, true);
        return;
    }
    setIngredientLabel(recipeIdx, ingredientIdx, code, name, true);
    const sourceDraft = recipes[recipeIdx];
    if (sourceDraft && sourceDraft.ingredients && sourceDraft.ingredients[ingredientIdx]) {
        sourceDraft.ingredients[ingredientIdx].needs_manual = false;
    }
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
    const unitSelect = document.getElementById('createUnitSelect');
    const customWrap = document.getElementById('createUnitCustomWrap');
    const customUnit = document.getElementById('createUnitOther');
    if (unitSelect) {
        unitSelect.value = 'u';
    }
    if (customWrap) {
        customWrap.classList.add('d-none');
    }
    if (customUnit) {
        customUnit.value = '';
    }
    if (createCategorySelect) {
        createCategorySelect.value = 'Insumos';
    }
    if (createCategoryCustomWrap) {
        createCategoryCustomWrap.classList.add('d-none');
    }
    if (createCategory) {
        createCategory.value = '';
    }
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
        setFinalLabel(pickerMode.recipe, code, name, true);
    } else if (pickerMode.type === 'ingredient') {
        setIngredientLabel(pickerMode.recipe, pickerMode.ingredient, code, name, true);
    }
    searchModal.hide();
}

function replicateIngredient(recipeIdx, ingredientIdx, code, name) {
    const source = recipes[recipeIdx]?.ingredients?.[ingredientIdx];
    if (!source) {
        setStatus('No se pudo leer el ingrediente origen.');
        showReplicationReport({
            title: 'Error de réplica',
            message: 'No se pudo leer el ingrediente origen.',
            totals: { total: 0, applied: 0, skipped: 0, errors: 1 },
            applied: [],
            skipped: ['No se pudo identificar la fila origen.'],
            errors: ['No se pudo leer el ingrediente origen.'],
        });
        return;
    }
    const normalizedSource = normalizeTextForMatch(source.name || '');
    if (!normalizedSource) {
        setStatus('No se pudo identificar el nombre del ingrediente para replicar.');
        showReplicationReport({
            title: 'Error de réplica',
            message: 'No se pudo identificar el nombre del ingrediente para replicar.',
            totals: { total: 0, applied: 0, skipped: 0, errors: 1 },
            applied: [],
            skipped: ['No se pudo normalizar el nombre del ingrediente origen.'],
            errors: ['No se pudo normalizar el nombre del ingrediente origen.'],
        });
        return;
    }
    const targetCode = (code || '').trim();
    if (!targetCode) {
        setStatus('Selecciona un producto primero para poder replicarlo.');
        showReplicationReport({
            title: 'Réplica no ejecutada',
            message: 'Selecciona un producto primero para poder replicarlo.',
            totals: { total: 0, applied: 0, skipped: 0, errors: 1 },
            applied: [],
            skipped: ['No hay código de producto origen.'],
            errors: ['No hay código de producto seleccionado para replicar.'],
        });
        return;
    }
    const targetName = (name || '').trim() || (source.resolved_name || source.name || '');

    let totalMatches = 0;
    let affected = 0;
    let skippedCount = 0;
    const applied = [];
    const skipped = [];
    const errors = [];
    recipes.forEach((draft, targetRecipeIdx) => {
        if (!draft || !Array.isArray(draft.ingredients)) {
            return;
        }
        draft.ingredients.forEach((it, targetIngIdx) => {
            if (!it) {
                return;
            }
            if (targetRecipeIdx === recipeIdx && targetIngIdx === ingredientIdx) {
                return;
            }
            if (normalizeTextForMatch(it.name || '') !== normalizedSource) {
                return;
            }
            totalMatches++;
            const alreadyHasCode = (it.resolved_code || '').trim();
            const isPending = Boolean(it.needs_manual) || !alreadyHasCode;
            const recipeName = normalizeItemName(draft);
            const lineLabel = `${recipeName} · ${it.name}`;
            if (!isPending) {
                skippedCount++;
                skipped.push(`${lineLabel} (ya estaba resuelto con ${alreadyHasCode || 'código vacío'})`);
                return;
            }
            try {
                setIngredientLabel(targetRecipeIdx, targetIngIdx, targetCode, targetName, true);
                affected++;
                applied.push(`${lineLabel} -> ${targetCode}`);
            } catch (err) {
                errors.push(`${lineLabel} (${err && err.message ? err.message : 'error al aplicar' })`);
            }
        });
    });

    if (affected > 0) {
        setStatus(`Se replicó el ingrediente en ${affected} receta(s) pendiente(s).`);
    } else {
        setStatus('No había ingredientes pendientes iguales para replicar.');
    }
    showReplicationReport({
        title: 'Resultado de réplica de ingrediente',
        message: `Ingrediente origen: ${source.name} -> ${targetCode} ${targetName ? `(${targetName})` : ''}`,
        totals: {
            total: totalMatches,
            applied: affected,
            skipped: skippedCount,
            errors: errors.length,
        },
        applied,
        skipped,
        errors,
    });
}

function replicateFinal(recipeIdx, code, name) {
    const source = recipes[recipeIdx];
    if (!source || !source.final_product) {
        setStatus('No se pudo leer el producto final origen.');
        showReplicationReport({
            title: 'Error de réplica',
            message: 'No se pudo leer el producto final origen.',
            totals: { total: 0, applied: 0, skipped: 0, errors: 1 },
            applied: [],
            skipped: ['No se pudo identificar la receta de origen.'],
            errors: ['No se pudo leer el producto final origen.'],
        });
        return;
    }
    const targetCode = (code || '').trim();
    const targetName = (name || '').trim();
    if (!targetCode) {
        setStatus('Selecciona un producto final primero para replicarlo.');
        showReplicationReport({
            title: 'Réplica no ejecutada',
            message: 'Selecciona un producto final primero para replicarlo.',
            totals: { total: 0, applied: 0, skipped: 0, errors: 1 },
            applied: [],
            skipped: ['No hay código de producto final origen.'],
            errors: ['No hay código de producto final para replicar.'],
        });
        return;
    }

    let totalMatches = 0;
    let affected = 0;
    let skippedCount = 0;
    const applied = [];
    const skipped = [];
    const errors = [];
    recipes.forEach((draft, targetRecipeIdx) => {
        if (!draft || !draft.final_product) {
            return;
        }
        if (targetRecipeIdx === recipeIdx) {
            return;
        }
        const recipeName = normalizeItemName(draft);
        const finalName = draft.final_product.code || '';
        const needsFinal = Boolean(draft.final_product.needs_manual);
        const currentCode = (draft.final_product.code || '').trim();
        if (!needsFinal && currentCode) {
            skippedCount++;
            skipped.push(`${recipeName} (ya tenía final: ${currentCode})`);
            return;
        }
        totalMatches++;
        try {
            setFinalLabel(targetRecipeIdx, targetCode, targetName, true);
            affected++;
            applied.push(`${recipeName} (${finalName || 'sin final previo'} -> ${targetCode})`);
        } catch (err) {
            errors.push(`${recipeName} (${err && err.message ? err.message : 'error al aplicar'})`);
        }
    });

    if (affected > 0) {
        setStatus(`Se replicó el final en ${affected} receta(s) pendiente(s).`);
    } else {
        setStatus('No había productos finales pendientes para replicar.');
    }
    showReplicationReport({
        title: 'Resultado de réplica de producto final',
        message: `Producto origen: ${source.final_product.code || '(sin código)'} -> ${targetCode} ${targetName ? `(${targetName})` : ''}`,
        totals: {
            total: totalMatches,
            applied: affected,
            skipped: skippedCount,
            errors: errors.length,
        },
        applied,
        skipped,
        errors,
    });
}

document.addEventListener('click', (ev) => {
    const button = ev.target.closest('.suggestion-pill');
    if (!button) {
        return;
    }
    ev.preventDefault();
    const recipeIdxRaw = button.getAttribute('data-recipe');
    const ingredientRaw = button.getAttribute('data-ingredient');
    const code = button.getAttribute('data-code') || '';
    const name = decodeURIComponent(button.getAttribute('data-name') || '');
    const recipeIdx = recipeIdxRaw !== null ? parseInt(recipeIdxRaw, 10) : NaN;
    if (!Number.isInteger(recipeIdx)) {
        return;
    }
    const ingredientIdx = (ingredientRaw === null || ingredientRaw === '') ? null : parseInt(ingredientRaw, 10);
    pickSuggestedProduct(recipeIdx, ingredientIdx, code, name);
});

document.addEventListener('click', (ev) => {
    const finalRepBtn = ev.target.closest('.replicate-final-btn');
    if (finalRepBtn) {
        ev.preventDefault();
        const recipeIdx = parseInt(finalRepBtn.dataset.recipe || '0', 10);
        const code = (finalRepBtn.dataset.code || '').trim();
        const name = decodeURIComponent(finalRepBtn.dataset.name || '');
        replicateFinal(recipeIdx, code, name);
        return;
    }

    const ingRepBtn = ev.target.closest('.replicate-ingredient-btn');
    if (ingRepBtn) {
        ev.preventDefault();
        const recipeIdx = parseInt(ingRepBtn.dataset.recipe || '0', 10);
        const ingIdx = parseInt(ingRepBtn.dataset.ingredient || '0', 10);
        const code = (ingRepBtn.dataset.code || '').trim();
        const name = decodeURIComponent(ingRepBtn.dataset.name || '');
        replicateIngredient(recipeIdx, ingIdx, code, name);
    }
});

let searchTimer = null;
queryInput.addEventListener('input', () => {
    const q = queryInput.value || '';
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        searchProducts(q);
    }, 220);
});

const perPageSelect = document.getElementById('perPageSelect');
if (perPageSelect) {
    perPageSelect.addEventListener('change', () => {
        const val = parseInt(perPageSelect.value || '20', 10);
        const params = new URLSearchParams(window.location.search);
        params.set('per_page', String(isNaN(val) ? 10 : val));
        params.set('page', '1');
        window.location.href = 'importar_recetas_palweb_ok.php?' + params.toString();
    });
}

function updateCardsToggleLabel() {
    if (!toggleCardsBtn) {
        return;
    }
    const total = recipeCollapses.length;
    if (total === 0) {
        toggleCardsBtn.textContent = 'Expandir todas';
        return;
    }
    let shown = 0;
    recipeCollapses.forEach(el => {
        if (el.classList.contains('show')) {
            shown++;
        }
    });
    if (shown === 0) {
        toggleCardsBtn.textContent = 'Expandir todas';
    } else if (shown === total) {
        toggleCardsBtn.textContent = 'Colapsar todas';
    } else {
        toggleCardsBtn.textContent = 'Expandir todas';
    }
}

function toggleAllRecipesCardState() {
    const total = recipeCollapses.length;
    if (total === 0 || !toggleCardsBtn) {
        return;
    }
    let shouldExpandAll = true;
    let allExpanded = 0;
    recipeCollapses.forEach(el => {
        if (el.classList.contains('show')) {
            allExpanded++;
        }
    });
    if (allExpanded === total && total > 0) {
        shouldExpandAll = false;
    }

    recipeCollapses.forEach(el => {
        const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
        if (shouldExpandAll) {
            bsCollapse.show();
        } else {
            bsCollapse.hide();
        }
    });
}

if (toggleCardsBtn) {
    toggleCardsBtn.addEventListener('click', toggleAllRecipesCardState);
}

recipeCollapses.forEach(el => {
    el.addEventListener('shown.bs.collapse', updateCardsToggleLabel);
    el.addEventListener('hidden.bs.collapse', updateCardsToggleLabel);
});
updateCardsToggleLabel();

document.getElementById('saveNewFinalBtn').addEventListener('click', async () => {
    const name = (document.getElementById('createName').value || '').trim();
    const cost = parseFloat(document.getElementById('createCost').value || '0');
    const price = parseFloat(document.getElementById('createPrice').value || '0');
    let unit = (document.getElementById('createUnitSelect')?.value || 'u').trim();
    if (unit === 'otro') {
        unit = (document.getElementById('createUnitOther')?.value || '').trim();
    }
    if (!name) {
        alert('Ingrese nombre');
        return;
    }
    if (!unit) {
        alert('Seleccione o escriba la unidad de medida');
        return;
    }

    let category = 'Insumos';
    if (createCategorySelect) {
        if (createCategorySelect.value === '__custom__') {
            category = (createCategory?.value || '').trim();
        } else {
            category = (createCategorySelect.value || 'Insumos').trim();
        }
    }
    if (!category) {
        alert('Seleccione o ingrese una categoría');
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

if (createUnitSelect) {
    createUnitSelect.addEventListener('change', () => {
        if (!createUnitCustomWrap || !createUnitOther) {
            return;
        }
        if (createUnitSelect.value === 'otro') {
            createUnitCustomWrap.classList.remove('d-none');
            createUnitOther.focus();
        } else {
            createUnitCustomWrap.classList.add('d-none');
            createUnitOther.value = '';
        }
    });
}

if (createCategorySelect) {
    createCategorySelect.addEventListener('change', () => {
        if (!createCategoryCustomWrap || !createCategory) {
            return;
        }
        if (createCategorySelect.value === '__custom__') {
            createCategoryCustomWrap.classList.remove('d-none');
            createCategory.focus();
        } else {
            createCategoryCustomWrap.classList.add('d-none');
            createCategory.value = '';
        }
    });
}

function getFinalCodeForRecipe(recipeIdx) {
    const domCode = (document.getElementById('finalCode-' + recipeIdx)?.value || '').trim();
    if (domCode) {
        return domCode;
    }
    const draft = recipes[recipeIdx];
    return draft && draft.final_product ? (draft.final_product.code || '').toString().trim() : '';
}

function getIngredientCodeForRecipe(recipeIdx, ingIdx) {
    const domCode = (document.getElementById('ingCode-' + recipeIdx + '-' + ingIdx)?.value || '').trim();
    if (domCode) {
        return domCode;
    }
    const draft = recipes[recipeIdx];
    if (!draft || !draft.ingredients || !draft.ingredients[ingIdx]) {
        return '';
    }
    return (draft.ingredients[ingIdx].resolved_code || '').toString().trim();
}

async function applySelected(forceSingle) {
    const approved = [];
    const ingredient_codes = {};
    const final_codes = {};

    document.querySelectorAll('.approve-recipe:checked').forEach(el => {
        const recipeIdx = parseInt(el.getAttribute('data-index') || '0', 10);
        approved.push(recipeIdx);

        const finalCode = getFinalCodeForRecipe(recipeIdx);
        final_codes[String(recipeIdx)] = finalCode;

        const payloadMap = {};
        document.querySelectorAll('[id^="ingCode-' + recipeIdx + '-"]').forEach(inp => {
            const m = inp.id.match(/ingCode-(\d+)-(\d+)/);
            const ingIdx = m ? m[2] : null;
            if (ingIdx !== null) {
                const code = getIngredientCodeForRecipe(recipeIdx, ingIdx);
                payloadMap[String(ingIdx)] = code;
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
<?php if (!$isCli): ?>
    <?php include_once __DIR__ . '/menu_master.php'; ?>
<?php endif; ?>
</body>
</html>
