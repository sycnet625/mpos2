<?php
// Manifiesto PWA para la tienda pública (shop.php).
// Genera JSON dinámico para tomar el nombre de la tienda desde pos.cfg.
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$cfg = [];
$cfgFile = __DIR__ . '/pos.cfg';
if (file_exists($cfgFile)) {
    $cfg = json_decode(file_get_contents($cfgFile), true) ?? [];
}

$nombre      = $cfg['tienda_nombre'] ?? 'Tienda PalWeb';
$nombreCorto = explode(' ', $nombre)[0]; // Primera palabra

$manifest = [
    'id'               => '/marinero/shop',
    'name'             => $nombre,
    'short_name'       => $nombreCorto,
    'start_url'        => '/marinero/shop.php',
    'scope'            => '/marinero/shop.php',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'lang'             => 'es',
    'background_color' => '#0d6efd',
    'theme_color'      => '#0d6efd',
    'description'      => 'Tienda en línea — ' . $nombre,
    'icons'            => [
        ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
