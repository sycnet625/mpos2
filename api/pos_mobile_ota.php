<?php
/** Manifiesto público de actualización de PalWeb POS.
 * No contiene credenciales; el APK se valida además por la firma Android.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config_loader.php';

$apk = dirname(__DIR__) . '/pos-mobile.apk';
$sha256 = is_file($apk) ? hash_file('sha256', $apk) : '';
echo json_encode([
    'status' => 'success',
    'package' => 'com.palweb.posoffline',
    'versionCode' => 9,
    'versionName' => '1.8',
    'channel' => 'stable',
    'apkUrl' => config_loader_public_url('/pos-mobile.apk'),
    'sha256' => $sha256,
], JSON_UNESCAPED_SLASHES);
