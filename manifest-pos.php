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
$posScope = $prefix . '/pos/';

$tienda = trim((string)($cfg['marca_sistema_nombre'] ?? ($cfg['tienda_nombre'] ?? 'PalWeb POS')));
$short = preg_split('/\s+/', $tienda)[0] ?? 'PalWeb';

$manifest = [
    'id' => $prefix . '/pos',
    'name' => $tienda,
    'short_name' => mb_substr($short, 0, 12),
    'start_url' => $posScope,
    'scope' => $posScope,
    'display' => 'standalone',
    'orientation' => 'any',        // Permite landscape en tablets/POS
    'lang' => 'es',
    'background_color' => '#2c3e50',
    'theme_color' => '#2c3e50',
    'description' => 'Punto de venta ' . $tienda,
    'icons' => [
        ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        // Icono maskable: requerido por Android para instalación PWA correcta
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
