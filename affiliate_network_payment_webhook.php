<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';

header('Content-Type: application/json; charset=utf-8');

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$token = trim((string)($_SERVER['HTTP_X_AFFILIATE_WEBHOOK_TOKEN'] ?? ''));
$expected = aff_payment_webhook_token();
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$batch = [];
if (isset($input['payments']) && is_array($input['payments'])) {
    $batch = $input['payments'];
} else {
    $batch = [$input];
}

$stored = 0;
$duplicates = 0;
$matched = 0;
$unmatched = 0;

foreach ($batch as $row) {
    if (!is_array($row)) {
        continue;
    }
    $saved = aff_store_external_payment($pdo, array_merge($row, ['source_type' => 'gateway']));
    if (($saved['status'] ?? '') === 'duplicate') {
        $duplicates++;
        continue;
    }
    $stored++;
    try {
        aff_reconcile_payment_reference($pdo, [
            'payment_channel' => (string)($row['payment_channel'] ?? 'Transfermóvil'),
            'reference_code' => (string)($row['reference_code'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'note' => (string)($row['note'] ?? 'Webhook gateway'),
        ]);
        $matched++;
    } catch (Throwable $e) {
        $unmatched++;
    }
}

echo json_encode([
    'status' => 'success',
    'stored' => $stored,
    'duplicates' => $duplicates,
    'matched' => $matched,
    'unmatched' => $unmatched,
], JSON_UNESCAPED_UNICODE);
