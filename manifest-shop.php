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

$scriptDir   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath    = rtrim($scriptDir === '.' ? '/' : $scriptDir, '/');
if ($basePath === '') $basePath = '/';
$prefix      = $basePath === '/' ? '' : $basePath;
$shopScope   = $prefix . '/shop/';
$nombre      = $cfg['tienda_nombre'] ?? 'Tienda PalWeb';
$nombreCorto = explode(' ', $nombre)[0]; // Primera palabra

$manifest = [
    'id'               => $prefix . '/shop',
    'name'             => $nombre,
    'short_name'       => $nombreCorto,
    'start_url'        => $shopScope,
    'scope'            => $shopScope,
    'display'          => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'orientation'      => 'portrait-primary',
    'lang'             => 'es',
    'background_color' => '#0d6efd',
    'theme_color'      => '#0d6efd',
    'description'      => 'Tienda en línea — ' . $nombre,
    'categories'       => ['shopping', 'food'],
    'icons'            => [
        ['src' => 'icon-shop-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icon-shop-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable any'],
    ],
    'screenshots'      => [
        [
            'src'          => $prefix . '/assets/product_images/screenshot-wide.jpg',
            'sizes'        => '1280x720',
            'type'         => 'image/jpeg',
            'form_factor'  => 'wide',
            'label'        => 'Catálogo de productos — ' . $nombre,
        ],
        [
            'src'          => $prefix . '/assets/product_images/screenshot-mobile.jpg',
            'sizes'        => '390x844',
            'type'         => 'image/jpeg',
            'form_factor'  => 'narrow',
            'label'        => 'Tienda móvil — ' . $nombre,
        ],
    ],
    'share_target'     => [
        'action'  => $shopScope,
        'method'  => 'GET',
        'enctype' => 'application/x-www-form-urlencoded',
        'params'  => ['title' => 'q', 'text' => 'q', 'url' => 'q'],
    ],
    'shortcuts'        => [
        [
            'name'      => 'Ver catálogo',
            'url'       => $shopScope,
            'icons'     => [['src' => 'icon-shop-192.png', 'sizes' => '192x192']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
