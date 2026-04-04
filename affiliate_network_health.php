<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/affiliate_network_smoke.php');
$output = [];
$code = 1;
exec($cmd . ' 2>&1', $output, $code);

$summary = [
    'timestamp' => date('c'),
    'ok' => $code === 0,
    'mode' => 'smoke_wrapper',
    'exit_code' => $code,
    'output' => $output,
];

$token = aff_telegram_bot_token($pdo);
$adminChatId = trim((string)getenv('AFFILIATE_ADMIN_TELEGRAM_CHAT_ID'));
if ($token !== '' && $adminChatId !== '') {
    $text = ($summary['ok'] ? "✅ RAC health OK\n" : "❌ RAC health FAIL\n") . implode("\n", array_slice($output, 0, 20));
    $payload = json_encode(['chat_id' => $adminChatId, 'text' => $text], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage', false, $ctx);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
