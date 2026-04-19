<?php

const PRODUCT_IMAGE_PIPELINE_DIR = '/var/www/assets/product_images/';
const PRODUCT_IMAGE_PIPELINE_SIZES = [800, 400, 200, 192, 96];
const PRODUCT_IMAGE_PIPELINE_CLEAN_EXTS = [
    '.avif', '.webp', '.jpg', '.jpeg', '.png',
    '_thumb.avif', '_thumb.webp', '_thumb.jpg', '_thumb.jpeg', '_thumb.png',
    '_w400.avif', '_w400.webp', '_w400.jpg', '_w400.jpeg', '_w400.png',
    '_w200.avif', '_w200.webp', '_w200.jpg', '_w200.jpeg', '_w200.png',
    '_w192.avif', '_w192.webp', '_w192.jpg', '_w192.jpeg', '_w192.png',
    '_w96.avif', '_w96.webp', '_w96.jpg', '_w96.jpeg', '_w96.png',
];

function product_image_pipeline_safe_code(string $code): string
{
    $safe = trim($code);
    return preg_match('/^[A-Za-z0-9_.-]+$/', $safe) ? $safe : '';
}

function product_image_pipeline_base_path(string $code): string
{
    return rtrim(PRODUCT_IMAGE_PIPELINE_DIR, '/') . '/' . $code;
}

function product_image_pipeline_cleanup(string $code): void
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return;
    $base = product_image_pipeline_base_path($safe);
    foreach (PRODUCT_IMAGE_PIPELINE_CLEAN_EXTS as $suffix) {
        if (file_exists($base . $suffix)) {
            @unlink($base . $suffix);
        }
    }
}

function product_image_pipeline_has_any(string $code): bool
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return false;
    $base = product_image_pipeline_base_path($safe);
    foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png'] as $ext) {
        if (file_exists($base . $ext)) return true;
    }
    return false;
}

function product_image_pipeline_find_existing_path(string $code): ?string
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return null;
    $base = product_image_pipeline_base_path($safe);
    foreach (['.avif', '.webp', '.jpg', '.jpeg', '.png'] as $ext) {
        if (file_exists($base . $ext)) {
            return $base . $ext;
        }
    }
    return null;
}

function product_image_pipeline_ensure_dir(): void
{
    if (!is_dir(PRODUCT_IMAGE_PIPELINE_DIR)) {
        @mkdir(PRODUCT_IMAGE_PIPELINE_DIR, 0777, true);
    }
}

function product_image_pipeline_target_name(string $base, int $size, string $ext): string
{
    if ($size >= 800) {
        return $base . $ext;
    }
    return $base . '_w' . $size . $ext;
}

function product_image_pipeline_write_one($canvas, string $target, string $mime): bool
{
    return match ($mime) {
        'image/avif' => function_exists('imageavif') ? @imageavif($canvas, $target, 60, 6) : false,
        'image/webp' => function_exists('imagewebp') ? @imagewebp($canvas, $target, 82) : false,
        default      => @imagejpeg($canvas, $target, 85),
    };
}

function product_image_pipeline_make_square_canvas($src, int $target): ?GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) return null;
    $side = min($w, $h);
    $ox = (int)(($w - $side) / 2);
    $oy = (int)(($h - $side) / 2);
    $canvas = imagecreatetruecolor($target, $target);
    if (!$canvas) return null;
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $src, 0, 0, $ox, $oy, $target, $target, $side, $side);
    return $canvas;
}

function product_image_pipeline_write_variants($src, string $code): bool
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return false;

    product_image_pipeline_ensure_dir();
    product_image_pipeline_cleanup($safe);

    $base = product_image_pipeline_base_path($safe);
    $okMain = false;
    foreach (PRODUCT_IMAGE_PIPELINE_SIZES as $size) {
        $canvas = product_image_pipeline_make_square_canvas($src, $size);
        if (!$canvas) continue;

        $jpgOk = product_image_pipeline_write_one($canvas, product_image_pipeline_target_name($base, $size, '.jpg'), 'image/jpeg');
        $webpOk = product_image_pipeline_write_one($canvas, product_image_pipeline_target_name($base, $size, '.webp'), 'image/webp');
        $avifOk = product_image_pipeline_write_one($canvas, product_image_pipeline_target_name($base, $size, '.avif'), 'image/avif');

        if ($size === 200) {
            @copy(product_image_pipeline_target_name($base, $size, '.jpg'), $base . '_thumb.jpg');
            if ($webpOk) @copy(product_image_pipeline_target_name($base, $size, '.webp'), $base . '_thumb.webp');
            if ($avifOk) @copy(product_image_pipeline_target_name($base, $size, '.avif'), $base . '_thumb.avif');
        }

        if ($size === 800 && ($jpgOk || $webpOk || $avifOk)) {
            $okMain = true;
        }
        imagedestroy($canvas);
    }

    return $okMain;
}

function product_image_pipeline_store_upload(string $tmpName, string $code): bool
{
    if (!is_file($tmpName) || !function_exists('imagecreatefromstring')) return false;
    $bytes = @file_get_contents($tmpName);
    if ($bytes === false || $bytes === '') return false;
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    try {
        return product_image_pipeline_write_variants($src, $code);
    } finally {
        imagedestroy($src);
    }
}

function product_image_pipeline_rebuild_existing(string $code, string $label = ''): string
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return 'invalid';

    $existingPath = product_image_pipeline_find_existing_path($safe);
    if ($existingPath !== null && function_exists('imagecreatefromstring')) {
        $bytes = @file_get_contents($existingPath);
        if ($bytes !== false && $bytes !== '') {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                try {
                    return product_image_pipeline_write_variants($src, $safe) ? 'rebuilt' : 'failed';
                } finally {
                    imagedestroy($src);
                }
            }
        }
    }

    return product_image_pipeline_ensure_placeholder($safe, $label !== '' ? $label : $safe) ? 'placeholder' : 'failed';
}

function product_image_pipeline_placeholder_source(string $code, string $label)
{
    $canvas = imagecreatetruecolor(800, 800);
    if (!$canvas) return null;
    $hash = substr(md5($code), 0, 6);
    [$r, $g, $b] = [hexdec(substr($hash, 0, 2)), hexdec(substr($hash, 2, 2)), hexdec(substr($hash, 4, 2))];
    $bg = imagecolorallocate($canvas, $r, $g, $b);
    $fg = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, 800, 800, $bg);
    $text = trim($label) !== '' ? $label : $code;
    $parts = preg_split('/\s+/', trim($text)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = strtoupper(substr($code, 0, 2));
    $initials = substr($initials, 0, 2);
    imagestring($canvas, 5, 365, 388, $initials, $fg);
    return $canvas;
}

function product_image_pipeline_ensure_placeholder(string $code, string $label): bool
{
    $safe = product_image_pipeline_safe_code($code);
    if ($safe === '') return false;
    if (product_image_pipeline_has_any($safe)) return true;
    $src = product_image_pipeline_placeholder_source($safe, $label);
    if (!$src) return false;
    try {
        return product_image_pipeline_write_variants($src, $safe);
    } finally {
        imagedestroy($src);
    }
}
