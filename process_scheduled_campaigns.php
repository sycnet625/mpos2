#!/usr/bin/env php
<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

$token = $argv[1] ?? '';
if ($token === '') {
    $cfgFile = __DIR__ . '/pos.cfg';
    if (is_file($cfgFile)) {
        $json = @file_get_contents($cfgFile);
        $cfg = json_decode((string)$json, true);
        if (is_array($cfg) && !empty($cfg['bot_token'])) {
            $token = trim((string)($cfg['bot_token']));
        }
    }
}
if ($token === '') {
    try {
        $st = $pdo->prepare("SELECT verify_token FROM pos_bot_config WHERE id=1 LIMIT 1");
        $st->execute();
        $dbToken = $st->fetchColumn();
        if ($dbToken !== false && $dbToken !== '') {
            $token = trim((string)$dbToken);
        }
    } catch (Throwable $e) {
    }
}
if ($token === '') {
    fwrite(STDERR, "Token requerido como argumento, pos.cfg (bot_token) o pos_bot_config.verify_token\n");
    exit(1);
}

$ch = curl_init('http://localhost/posbot_api/router.php?action=promo_validate_bridge');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    $data = json_decode($resp ?: '', true);
    $bridge = $data['bridge'] ?? [];
    $bridgeOk = !empty($bridge['ok']);
} else {
    $bridgeOk = false;
}

$postData = json_encode(['verify_token' => $token]);
$ch = curl_init('http://localhost/posbot_api/router.php?action=promo_process_scheduled');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($resp ?: '', true);
$processed = (int)($result['processed'] ?? 0);
$bridgeState = is_array($result['bridge'] ?? []) ? ($result['bridge']['state'] ?? 'unknown') : 'unknown';

echo date('Y-m-d H:i:s') . " | Bridge: {$bridgeState} | Processed: {$processed}\n";
exit(0);
