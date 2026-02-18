<?php
// ===================================================================
// ARCHIVO: tools_image_converter.php
// PROPÓSITO: Convierte imágenes JPG de productos a WebP y AVIF
//            con mayor compresión para reducir ancho de banda.
// USO: php tools_image_converter.php   (CLI)
//      https://dominio/marinero/tools_image_converter.php  (navegador)
// ===================================================================

require_once 'db.php';
require_once 'config_loader.php';

// ── Configuración ────────────────────────────────────────────────────────────
define('IMG_DIR',      '/home/marinero/product_images/');
define('WEBP_QUALITY', 82);    // 0-100 (mayor = mejor calidad, mayor tamaño)
define('AVIF_QUALITY', 60);    // 0-100 (AVIF logra calidad equivalente a JPEG-85 con 55-65)
define('AVIF_SPEED',   6);     // 0-10 (0=lento/mejor compresión, 10=rápido/peor compresión)
define('MAX_EXEC_SEC', 300);   // Tiempo máximo de ejecución en segundos

$isCli = (php_sapi_name() === 'cli');

// ── Opciones por argumento/querystring ───────────────────────────────────────
$forceReconvert = $isCli
    ? in_array('--force', $argv ?? [])
    : isset($_GET['force']);

$soloFormato = $isCli
    ? (in_array('--webp-only', $argv ?? []) ? 'webp' : (in_array('--avif-only', $argv ?? []) ? 'avif' : 'ambos'))
    : ($_GET['formato'] ?? 'ambos');

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmt_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function pct_saved(int $original, int $nuevo): string {
    if ($original === 0) return '0%';
    $pct = (1 - $nuevo / $original) * 100;
    return ($pct >= 0 ? '-' : '+') . abs(round($pct, 1)) . '%';
}

function load_image(string $path): GdImage|false {
    $mime = mime_content_type($path);
    return match($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png'  => imagecreatefrompng($path),
        'image/gif'  => imagecreatefromgif($path),
        'image/webp' => imagecreatefromwebp($path),
        default      => false,
    };
}

// ── Inicio ───────────────────────────────────────────────────────────────────
set_time_limit(MAX_EXEC_SEC);
ini_set('memory_limit', '512M');

if (!extension_loaded('gd')) {
    die("ERROR: La extensión GD de PHP no está disponible.\n");
}
if (!function_exists('imagewebp')) {
    die("ERROR: GD no tiene soporte para WebP. Instala php-gd con soporte WebP.\n");
}

// Obtener todas las imágenes fuente (JPG/PNG/GIF)
$archivos = glob(IMG_DIR . '*.{jpg,jpeg,png,gif}', GLOB_BRACE);
sort($archivos);
$total = count($archivos);

// ── Salida HTML ───────────────────────────────────────────────────────────────
if (!$isCli): ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conversor de Imágenes – PALWEB POS</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
    body { background:#f8f9fa; }
    .savings-good  { color:#198754; font-weight:600; }
    .savings-bad   { color:#dc3545; font-weight:600; }
    .savings-ok    { color:#0d6efd; font-weight:600; }
    pre { font-size:.78rem; }
</style>
</head>
<body class="p-4">
<div class="container-fluid">
<h2 class="mb-1"><i class="fa fa-image"></i> Conversor de Imágenes de Productos</h2>
<p class="text-muted mb-3">Directorio: <code><?= IMG_DIR ?></code> &nbsp;|&nbsp;
   Total imágenes fuente: <strong><?= $total ?></strong> &nbsp;|&nbsp;
   Formato destino: <strong><?= strtoupper($soloFormato) ?></strong> &nbsp;|&nbsp;
   Modo: <strong><?= $forceReconvert ? 'Forzar reconversión' : 'Solo nuevas' ?></strong>
</p>

<?php if (!$forceReconvert): ?>
<div class="alert alert-info py-2">
    <strong>Tip:</strong> Añade <code>?force=1</code> a la URL para reconvertir todas las imágenes aunque ya existan.
    Añade <code>?formato=webp</code> o <code>?formato=avif</code> para convertir solo un formato.
</div>
<?php endif; ?>

<div class="table-responsive">
<table class="table table-sm table-bordered table-hover align-middle" style="font-size:.82rem">
<thead class="table-dark">
<tr>
  <th>#</th><th>Archivo</th>
  <th>Original</th>
  <th>WebP</th><th>Ahorro WebP</th>
  <th>AVIF</th><th>Ahorro AVIF</th>
  <th>Estado</th>
</tr>
</thead>
<tbody>
<?php
// Flush del encabezado de tabla para que se muestre el progreso
ob_flush(); flush();
endif; // fin !$isCli

// ── Bucle de conversión ───────────────────────────────────────────────────────
$stats = [
    'procesados'    => 0,
    'omitidos'      => 0,
    'errores'       => 0,
    'orig_bytes'    => 0,
    'webp_bytes'    => 0,
    'avif_bytes'    => 0,
];

foreach ($archivos as $i => $rutaOrig) {
    $nombre    = basename($rutaOrig);
    $sinExt    = pathinfo($rutaOrig, PATHINFO_FILENAME);
    $origSize  = filesize($rutaOrig);
    $rutaWebp  = IMG_DIR . $sinExt . '.webp';
    $rutaAvif  = IMG_DIR . $sinExt . '.avif';

    $webpSize  = 0;
    $avifSize  = 0;
    $webpNuevo = false;
    $avifNuevo = false;
    $errores   = [];

    // Decidir si omitir
    $necesitaWebp = ($soloFormato !== 'avif') && ($forceReconvert || !file_exists($rutaWebp));
    $necesitaAvif = ($soloFormato !== 'webp') && ($forceReconvert || !file_exists($rutaAvif));

    if (!$necesitaWebp && !$necesitaAvif) {
        // Ya existen ambos formatos y no se fuerza reconversión
        $webpSize = file_exists($rutaWebp) ? filesize($rutaWebp) : 0;
        $avifSize = file_exists($rutaAvif) ? filesize($rutaAvif) : 0;
        $stats['omitidos']++;
        $estado = 'omitido';
    } else {
        // Cargar imagen fuente
        $img = load_image($rutaOrig);

        if (!$img) {
            $errores[] = "No se pudo cargar la imagen fuente";
            $stats['errores']++;
            $estado = 'error';
        } else {
            // Las imágenes de paleta (GIF, algunos PNG) no son compatibles con
            // WebP/AVIF. Se convierten a truecolor antes de codificar.
            if (!imageistruecolor($img)) {
                $tc = imagecreatetruecolor(imagesx($img), imagesy($img));
                imagecopy($tc, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
                imagedestroy($img);
                $img = $tc;
            }
            $estado = 'ok';

            // Convertir a WebP
            if ($necesitaWebp) {
                if (imagewebp($img, $rutaWebp, WEBP_QUALITY)) {
                    $webpSize  = filesize($rutaWebp);
                    $webpNuevo = true;
                } else {
                    $errores[] = "Fallo al escribir WebP";
                }
            } else {
                $webpSize = file_exists($rutaWebp) ? filesize($rutaWebp) : 0;
            }

            // Convertir a AVIF
            if ($necesitaAvif) {
                if (imageavif($img, $rutaAvif, AVIF_QUALITY, AVIF_SPEED)) {
                    $avifSize  = filesize($rutaAvif);
                    $avifNuevo = true;
                } else {
                    $errores[] = "Fallo al escribir AVIF";
                }
            } else {
                $avifSize = file_exists($rutaAvif) ? filesize($rutaAvif) : 0;
            }

            imagedestroy($img);
            $stats['procesados']++;
            if ($errores) { $stats['errores']++; $estado = 'parcial'; }
        }
    }

    $stats['orig_bytes'] += $origSize;
    $stats['webp_bytes'] += $webpSize;
    $stats['avif_bytes'] += $avifSize;

    // ── Salida de fila ────────────────────────────────────────────────────────
    if ($isCli) {
        $num    = str_pad($i + 1, 4, ' ', STR_PAD_LEFT);
        $pctW   = $webpSize ? pct_saved($origSize, $webpSize) : 'N/A';
        $pctA   = $avifSize ? pct_saved($origSize, $avifSize) : 'N/A';
        $err    = $errores ? ' [!] ' . implode(', ', $errores) : '';
        printf(
            "%s  %-30s  orig:%-8s  webp:%-8s(%s)  avif:%-8s(%s)  [%s]%s\n",
            $num, $nombre,
            fmt_bytes($origSize),
            $webpSize ? fmt_bytes($webpSize) : '-', $pctW,
            $avifSize ? fmt_bytes($avifSize) : '-', $pctA,
            $estado, $err
        );
    } else {
        $rowClass = match($estado) {
            'error'   => 'table-danger',
            'parcial' => 'table-warning',
            'omitido' => 'table-secondary',
            default   => '',
        };
        $pctW = $webpSize ? pct_saved($origSize, $webpSize) : '-';
        $pctA = $avifSize ? pct_saved($origSize, $avifSize) : '-';

        $clsW = $webpSize ? (($webpSize < $origSize) ? 'savings-good' : 'savings-bad') : '';
        $clsA = $avifSize ? (($avifSize < $origSize) ? 'savings-good' : 'savings-bad') : '';

        $estadoBadge = match($estado) {
            'ok'      => '<span class="badge bg-success">✓ OK</span>',
            'omitido' => '<span class="badge bg-secondary">Omitido</span>',
            'error'   => '<span class="badge bg-danger">Error</span>',
            'parcial' => '<span class="badge bg-warning text-dark">Parcial</span>',
            default   => $estado,
        };

        echo "<tr class=\"$rowClass\">";
        echo "<td>" . ($i + 1) . "</td>";
        echo "<td><code>$nombre</code></td>";
        echo "<td>" . fmt_bytes($origSize) . "</td>";
        echo "<td>" . ($webpSize ? fmt_bytes($webpSize) : '-') . ($webpNuevo ? ' <span class="badge bg-info text-dark">nuevo</span>' : '') . "</td>";
        echo "<td class=\"$clsW\">$pctW</td>";
        echo "<td>" . ($avifSize ? fmt_bytes($avifSize) : '-') . ($avifNuevo ? ' <span class="badge bg-info text-dark">nuevo</span>' : '') . "</td>";
        echo "<td class=\"$clsA\">$pctA</td>";
        echo "<td>$estadoBadge";
        if ($errores) echo '<br><small class="text-danger">' . implode('<br>', $errores) . '</small>';
        echo "</td></tr>\n";
        ob_flush(); flush();
    }
}

// ── Resumen final ─────────────────────────────────────────────────────────────
$ahorroWebp = $stats['orig_bytes'] > 0
    ? round((1 - $stats['webp_bytes'] / $stats['orig_bytes']) * 100, 1)
    : 0;
$ahorroAvif = $stats['orig_bytes'] > 0
    ? round((1 - $stats['avif_bytes'] / $stats['orig_bytes']) * 100, 1)
    : 0;

if ($isCli) {
    echo "\n";
    echo "─────────────────────────────────────────────────────────────────────\n";
    echo "RESUMEN\n";
    echo "  Total imágenes fuente : $total\n";
    echo "  Procesadas            : {$stats['procesados']}\n";
    echo "  Omitidas (ya existen) : {$stats['omitidos']}\n";
    echo "  Con errores           : {$stats['errores']}\n";
    echo "  Tamaño original total : " . fmt_bytes($stats['orig_bytes']) . "\n";
    echo "  Tamaño WebP total     : " . fmt_bytes($stats['webp_bytes']) . " (ahorro: $ahorroWebp%)\n";
    echo "  Tamaño AVIF total     : " . fmt_bytes($stats['avif_bytes']) . " (ahorro: $ahorroAvif%)\n";
    echo "─────────────────────────────────────────────────────────────────────\n";
    echo "Uso de memoria pico     : " . fmt_bytes(memory_get_peak_usage(true)) . "\n";
} else {
    echo "</tbody></table></div>";
    echo "<div class=\"card mt-3\"><div class=\"card-body\">";
    echo "<h5>Resumen</h5>";
    echo "<table class=\"table table-sm w-auto\">";
    echo "<tr><td>Total imágenes fuente</td><td><strong>$total</strong></td></tr>";
    echo "<tr><td>Procesadas</td><td><strong>{$stats['procesados']}</strong></td></tr>";
    echo "<tr><td>Omitidas (ya existen)</td><td>{$stats['omitidos']}</td></tr>";
    echo "<tr><td>Con errores</td><td><strong class=\"text-danger\">{$stats['errores']}</strong></td></tr>";
    echo "<tr><td>Tamaño JPEG total</td><td>" . fmt_bytes($stats['orig_bytes']) . "</td></tr>";
    echo "<tr><td>Tamaño WebP total</td><td><strong class=\"text-success\">" . fmt_bytes($stats['webp_bytes']) . "</strong> <span class=\"badge bg-success\">$ahorroWebp% ahorrado</span></td></tr>";
    echo "<tr><td>Tamaño AVIF total</td><td><strong class=\"text-success\">" . fmt_bytes($stats['avif_bytes']) . "</strong> <span class=\"badge bg-success\">$ahorroAvif% ahorrado</span></td></tr>";
    echo "<tr><td>Uso de memoria pico</td><td>" . fmt_bytes(memory_get_peak_usage(true)) . "</td></tr>";
    echo "</table>";
    echo "<p class='text-muted mb-0'>Sistema PALWEB POS v3.0</p>";
    echo "</div></div>";
    echo "</div></body></html>";
}
