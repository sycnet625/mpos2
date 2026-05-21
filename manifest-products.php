<?php
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
require_once 'config_loader.php';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$basePath = rtrim($scriptDir === '.' ? '/' : $scriptDir, '/');
if ($basePath === '') $basePath = '/';
$prefix = $basePath === '/' ? '' : $basePath;
$productsUrl = $prefix . '/products/';

$name = "Inventario " . ($config['nombre_negocio'] ?? 'Marinero');

$manifest = [
    'id' => $productsUrl,
    'name' => $name,
    'short_name' => 'Inventario',
    'description' => 'Gestión Profesional de Productos e Inventarios',
    'start_url' => $productsUrl,
    'scope' => $productsUrl,
    'display' => 'standalone',
    'background_color' => '#1a1d21',
    'theme_color' => '#0d6efd',
    'icons' => [
        [
            'src' => $prefix . '/icon-products-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $prefix . '/icon-products-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
