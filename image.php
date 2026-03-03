<?php
// image.php — Sirve imágenes de productos en el formato óptimo según el navegador.
// Prioridad: AVIF > WebP > JPEG original.
// Requiere haber ejecutado tools_image_converter.php previamente.

$code = $_GET['code'] ?? '';
$thumb = isset($_GET['thumb']);

if (empty($code)) {
    http_response_code(400);
    exit;
}

$safeCode = trim((string)$code);
if (!preg_match('/^[A-Za-z0-9_.-]+$/', $safeCode)) {
    http_response_code(400);
    exit;
}

$bases     = [
    __DIR__ . '/assets/product_images/' . $safeCode,
    dirname(__DIR__) . '/assets/product_images/' . $safeCode,
];
$accept    = $_SERVER['HTTP_ACCEPT'] ?? '';
$supAvif   = str_contains($accept, 'image/avif');
$supWebp   = str_contains($accept, 'image/webp');

// fmt=avif|webp|jpg permite que <picture><source type="..."> solicite el formato
// exacto sin depender del header Accept (útil también para CDNs y tests).
$fmt = $_GET['fmt'] ?? '';
if     ($fmt === 'avif')              { $supAvif = true;  $supWebp = false; }
elseif ($fmt === 'webp')              { $supAvif = false; $supWebp = true;  }
elseif ($fmt === 'jpg' || $fmt === 'jpeg') { $supAvif = false; $supWebp = false; }

// ── Selección de formato óptimo (busca en sucursal y raíz compartida) ───────
$path = null;
$mime = null;
$tryExts = [];
if ($supAvif) $tryExts[] = ['.avif', 'image/avif'];
if ($supWebp) $tryExts[] = ['.webp', 'image/webp'];
$tryExts[] = ['.jpg', 'image/jpeg'];
$tryExts[] = ['.jpeg', 'image/jpeg'];

foreach ($bases as $base) {
    foreach ($tryExts as [$ext, $extMime]) {
        $candidate = $base . $ext;
        if (file_exists($candidate)) {
            $path = $candidate;
            $mime = $extMime;
            break 2;
        }
    }
}

if ($path === null) {
    // Sin imagen: devolver placeholder SVG
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=3600');
    $bg   = substr(md5($code), 0, 6);
    $text = strtoupper(substr($code, 0, 2));
    echo <<<SVG
<svg width="400" height="400" xmlns="http://www.w3.org/2000/svg">
    <rect width="100%" height="100%" fill="#$bg"/>
    <text x="50%" y="50%" font-family="Arial" font-size="100"
          fill="white" text-anchor="middle" dy=".3em">$text</text>
</svg>
SVG;
    exit;
}

function sendConditionalCacheHeaders(string $path, string $mime): bool {
    $mtime = filemtime($path) ?: time();
    $size = filesize($path) ?: 0;
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size . '|' . $mime) . '"';
    $lastMod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
    header('Vary: Accept');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastMod);

    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ifNoneMatch === $etag || $ifModifiedSince === $lastMod) {
        http_response_code(304);
        return true;
    }
    return false;
}

// ── Thumbnail (redimensionado a 200 px de ancho) ──────────────────────────────
if ($thumb) {
    $image = match($mime) {
        'image/avif' => imagecreatefromavif($path),
        'image/webp' => imagecreatefromwebp($path),
        default      => imagecreatefromjpeg($path),
    };

    $w = imagesx($image);
    $h = imagesy($image);
    $newW = 200;
    $newH = (int) floor($h * ($newW / $w));

    $thumb_img = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($thumb_img, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);

    if (sendConditionalCacheHeaders($path, $mime)) {
        imagedestroy($thumb_img);
        imagedestroy($image);
        exit;
    }

    match($mime) {
        'image/avif' => imageavif($thumb_img, null, 60, 6),
        'image/webp' => imagewebp($thumb_img, null, 82),
        default      => imagejpeg($thumb_img, null, 85),
    };

    imagedestroy($thumb_img);
    imagedestroy($image);
    exit;
}

// ── Servir archivo completo ───────────────────────────────────────────────────
if (sendConditionalCacheHeaders($path, $mime)) {
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
