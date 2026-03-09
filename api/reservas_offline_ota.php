<?php
header('Content-Type: application/json');

$versionFile = __DIR__ . '/../apk/ReservasOffline/version.properties';
$versionCode = 1;
$versionName = '1.0.0';

if (file_exists($versionFile)) {
    $props = parse_ini_file($versionFile);
    if (isset($props['VERSION_CODE'])) $versionCode = intval($props['VERSION_CODE']);
    if (isset($props['VERSION_NAME'])) $versionName = (string)$props['VERSION_NAME'];
}

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$apkUrl = $base . '/apk/reservas.apk';
$apkPath = __DIR__ . '/../apk/reservas.apk';
$sha256 = file_exists($apkPath) ? hash_file('sha256', $apkPath) : '';

echo json_encode([
    'status' => 'success',
    'app' => 'reservas-offline',
    'version_code' => $versionCode,
    'version_name' => $versionName,
    'apk_url' => $apkUrl,
    'apk_sha256' => $sha256,
    'notes' => 'Actualizacion OTA disponible',
    'generated_at' => date('c'),
]);
