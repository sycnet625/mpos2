<?php
// sitemap.php — Genera el sitemap XML dinámicamente
// Accesible en /marinero/sitemap.xml (via rewrite Nginx)
header('Content-Type: application/xml; charset=UTF-8');

require_once 'db.php';
require_once 'config_loader.php';

$base    = 'https://www.palweb.net/marinero';
$EMP_ID  = intval($config['id_empresa']);
$today   = date('Y-m-d');

// Páginas estáticas públicas
$pages = [
    ['loc' => $base . '/shop.php',         'changefreq' => 'daily',   'priority' => '1.0'],
    ['loc' => $base . '/shop2.php',        'changefreq' => 'weekly',  'priority' => '0.7'],
    ['loc' => $base . '/quienes_somos.php','changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => $base . '/como_comprar.php', 'changefreq' => 'monthly', 'priority' => '0.5'],
];

// Productos web activos
$productos = [];
try {
    $stmt = $pdo->prepare(
        "SELECT codigo FROM productos WHERE es_web = 1 AND id_empresa = ? ORDER BY nombre"
    );
    $stmt->execute([$EMP_ID]);
    $productos = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($pages as $p) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($p['loc']) . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>{$p['changefreq']}</changefreq>\n";
    echo "    <priority>{$p['priority']}</priority>\n";
    echo "  </url>\n";
}

foreach ($productos as $codigo) {
    $url = $base . '/shop.php?producto=' . urlencode($codigo);
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url) . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
