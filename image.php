<?php
// image.php — Sirve imágenes de productos en el formato óptimo según el navegador.
// Prioridad: AVIF > WebP > JPEG original.
// Requiere haber ejecutado tools_image_converter.php previamente.

$code = $_GET['code'] ?? ($_GET['id'] ?? '');
$thumb = isset($_GET['thumb']);
$reqW = isset($_GET['w']) ? max(16, min(1200, (int)$_GET['w'])) : 0; // ?w=N redimensiona al ancho N
if ($reqW > 0) $thumb = true; // reutilizar el flujo de thumb

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
    '/tmp/palweb_product_images/' . $safeCode,
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
$prebuiltVariantWidth = 0;
$tryExts = [];
if ($supAvif) $tryExts[] = ['.avif', 'image/avif'];
if ($supWebp) $tryExts[] = ['.webp', 'image/webp'];
$tryExts[] = ['.jpg', 'image/jpeg'];
$tryExts[] = ['.jpeg', 'image/jpeg'];
$tryExts[] = ['.png', 'image/png'];

$requestedPresetWidth = 0;
if ($reqW > 0) {
    foreach ([96, 192, 200, 400, 800] as $candidateWidth) {
        if ($candidateWidth >= $reqW) {
            $requestedPresetWidth = $candidateWidth;
            break;
        }
    }
    if ($requestedPresetWidth === 0) {
        $requestedPresetWidth = 800;
    }
}

foreach ($bases as $base) {
    $candidateBases = [$base];
    if ($requestedPresetWidth > 0 && $requestedPresetWidth < 800) {
        array_unshift($candidateBases, $base . '_w' . $requestedPresetWidth);
    }
    foreach ($candidateBases as $candidateBase) {
        foreach ($tryExts as [$ext, $extMime]) {
            $candidate = $candidateBase . $ext;
            if (file_exists($candidate)) {
                $path = $candidate;
                $mime = $extMime;
                if ($candidateBase !== $base && preg_match('/_w(\d+)$/', $candidateBase, $m)) {
                    $prebuiltVariantWidth = (int)$m[1];
                }
                break 3;
            }
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

function sendConditionalCacheHeaders(string $path, string $mime, string $variantKey = ''): bool {
    $mtime = filemtime($path) ?: time();
    $size = filesize($path) ?: 0;
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size . '|' . $mime . '|' . $variantKey) . '"';
    $lastMod = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400, immutable');
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

function canDecodeImageMime(string $mime): bool {
    return match($mime) {
        'image/avif' => function_exists('imagecreatefromavif'),
        'image/webp' => function_exists('imagecreatefromwebp'),
        'image/png'  => function_exists('imagecreatefrompng'),
        default      => function_exists('imagecreatefromjpeg'),
    };
}

function canEncodeImageMime(string $mime): bool {
    return match($mime) {
        'image/avif' => function_exists('imageavif'),
        'image/webp' => function_exists('imagewebp'),
        'image/png'  => function_exists('imagepng'),
        default      => function_exists('imagejpeg'),
    };
}

// ── Thumbnail (redimensionado a 200 px de ancho) ──────────────────────────────
if ($thumb && $prebuiltVariantWidth === 0) {
    if (!function_exists('imagecreatetruecolor') || !canDecodeImageMime($mime) || !canEncodeImageMime($mime)) {
        if (sendConditionalCacheHeaders($path, $mime, 'orig-fallback')) {
            exit;
        }
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    $image = match($mime) {
        'image/avif' => imagecreatefromavif($path),
        'image/webp' => imagecreatefromwebp($path),
        'image/png'  => imagecreatefrompng($path),
        default      => imagecreatefromjpeg($path),
    };

    if (!$image) {
        if (sendConditionalCacheHeaders($path, $mime, 'orig-fallback')) {
            exit;
        }
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    $w = imagesx($image);
    $h = imagesy($image);
    $newW = $reqW > 0 ? $reqW : 200;
    $newH = (int) floor($h * ($newW / $w));

    $thumb_img = imagecreatetruecolor($newW, $newH);
    if (in_array($mime, ['image/png', 'image/webp', 'image/avif'], true)) {
        imagealphablending($thumb_img, false);
        imagesavealpha($thumb_img, true);
        $transparent = imagecolorallocatealpha($thumb_img, 0, 0, 0, 127);
        imagefilledrectangle($thumb_img, 0, 0, $newW, $newH, $transparent);
    }
    imagecopyresampled($thumb_img, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);

    if (sendConditionalCacheHeaders($path, $mime, 'w=' . $newW)) {
        imagedestroy($thumb_img);
        imagedestroy($image);
        exit;
    }

    match($mime) {
        'image/avif' => imageavif($thumb_img, null, 60, 6),
        'image/webp' => imagewebp($thumb_img, null, 82),
        'image/png'  => imagepng($thumb_img),
        default      => imagejpeg($thumb_img, null, 85),
    };

    imagedestroy($thumb_img);
    imagedestroy($image);
    exit;
}

// ── Servir archivo completo ───────────────────────────────────────────────────
if (sendConditionalCacheHeaders($path, $mime, 'orig')) {
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
