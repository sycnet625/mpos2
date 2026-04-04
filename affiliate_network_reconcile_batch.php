<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';

date_default_timezone_set('Europe/Berlin');

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$generated = aff_generate_billing_charges($pdo);
$reconciled = aff_auto_reconcile_pending_imports($pdo);
$status = [
    'ok' => true,
    'generated' => $generated,
    'reconciled' => $reconciled,
    'executed_at' => date('Y-m-d H:i:s'),
];

$statusPath = __DIR__ . '/tmp/rac_reconcile_status.json';
if (!is_dir(dirname($statusPath))) {
    @mkdir(dirname($statusPath), 0775, true);
}
@file_put_contents($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

header('Content-Type: application/json; charset=utf-8');
echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
