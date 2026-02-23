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

$base      = '/home/marinero/product_images/' . $code;
$accept    = $_SERVER['HTTP_ACCEPT'] ?? '';
$supAvif   = str_contains($accept, 'image/avif');
$supWebp   = str_contains($accept, 'image/webp');

// fmt=avif|webp|jpg permite que <picture><source type="..."> solicite el formato
// exacto sin depender del header Accept (útil también para CDNs y tests).
$fmt = $_GET['fmt'] ?? '';
if     ($fmt === 'avif')              { $supAvif = true;  $supWebp = false; }
elseif ($fmt === 'webp')              { $supAvif = false; $supWebp = true;  }
elseif ($fmt === 'jpg' || $fmt === 'jpeg') { $supAvif = false; $supWebp = false; }

// ── Selección de formato óptimo ───────────────────────────────────────────────
if ($supAvif && file_exists($base . '.avif')) {
    $path      = $base . '.avif';
    $mime      = 'image/avif';
} elseif ($supWebp && file_exists($base . '.webp')) {
    $path      = $base . '.webp';
    $mime      = 'image/webp';
} elseif (file_exists($base . '.jpg')) {
    $path      = $base . '.jpg';
    $mime      = 'image/jpeg';
} elseif (file_exists($base . '.jpeg')) {
    $path      = $base . '.jpeg';
    $mime      = 'image/jpeg';
} else {
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

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Vary: Accept');

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
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('Vary: Accept');
header('Content-Length: ' . filesize($path));
readfile($path);
