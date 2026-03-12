<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$cfg = [];
$cfgFile = __DIR__ . '/pos.cfg';
if (is_file($cfgFile)) {
    $cfg = json_decode(file_get_contents($cfgFile), true) ?? [];
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = rtrim($scriptDir === '.' ? '/' : $scriptDir, '/');
if ($basePath === '') $basePath = '/';
$prefix = $basePath === '/' ? '' : $basePath;

$tienda = trim((string)($cfg['tienda_nombre'] ?? 'PalWeb POS'));
$short = preg_split('/\s+/', $tienda)[0] ?? 'PalWeb';

$manifest = [
    'id' => $prefix . '/pos',
    'name' => $tienda,
    'short_name' => mb_substr($short, 0, 12),
    'start_url' => $prefix . '/pos.php',
    'scope' => ($prefix === '' ? '/' : $prefix . '/'),
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'lang' => 'es',
    'background_color' => '#2c3e50',
    'theme_color' => '#2c3e50',
    'description' => 'Punto de venta ' . $tienda,
    'icons' => [
        ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
