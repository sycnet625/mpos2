<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$read = static fn(string $name): string => (string)file_get_contents($root . '/' . $name);
$mustContain = static function (string $file, string $needle) use ($read): void {
    if (!str_contains($read($file), $needle)) throw new RuntimeException("Falta control esperado en {$file}: {$needle}");
};
foreach (['db_debug.php', 'reset2.php', 'fix_password.php'] as $file) {
    $source = $read($file);
    $closedAt = strpos($source, 'http_response_code(404);');
    $exitAt = strpos($source, 'exit;', max(0, $closedAt));
    $databaseAt = strpos($source, "require_once 'db.php';");
    if ($closedAt === false || $exitAt === false || ($databaseAt !== false && $exitAt > $databaseAt)) {
        throw new RuntimeException("La utilidad sensible no está deshabilitada: {$file}");
    }
}
foreach (['product_editor.php', 'pos_newprod.php', 'pos_import.php', 'categories_api.php', 'update_order.php'] as $file) {
    $mustContain($file, "require_once __DIR__ . '/admin_security.php'");
}
$mustContain('pos_kitchen_api.php', 'pos_security_require_csrf($input)');
$mustContain('pos_kitchen_api.php', "'Se requiere POST.'");
$mustContain('chat_api.php', "require_once __DIR__ . '/admin_security.php'");
$mustContain('chat_api.php', "\$_SESSION['admin_logged_in'] ?? false");
$mustContain('pos_import.php', "\$_SESSION['id_empresa'] ?? \$config['id_empresa']");
$mustContain('pos_import.php', '10 * 1024 * 1024');
$mustContain('pos_import.php', '5000 filas');
foreach (['shop.php', 'shop_premium.php', 's.php'] as $file) {
    $source = $read($file);
    if (str_contains($source, 'htmlspecialchars($p[\'nombre\'], ENT_QUOTES)')) {
        throw new RuntimeException("Salida inline vulnerable pendiente en {$file}");
    }
    if (!str_contains($source, 'JSON_HEX_APOS')) throw new RuntimeException("Falta serialización JavaScript segura en {$file}");
}
echo "Controles de rutas heredadas, importación y XSS correctos.\n";
