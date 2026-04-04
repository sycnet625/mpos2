<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';

header('Content-Type: text/plain; charset=utf-8');

function smoke_assert(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "OK: {$label}\n";
}

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$bootstrap = aff_bootstrap($pdo);
smoke_assert(!empty($bootstrap['owner']['id']), 'bootstrap owner');
smoke_assert(isset($bootstrap['summary']['volumeTotal']), 'bootstrap summary');
smoke_assert(array_key_exists('telegramConfigured', $bootstrap['integrations'] ?? []), 'bootstrap integrations');

$trace = aff_create_trace_link($pdo, 'P001', AFF_DEFAULT_GESTOR);
smoke_assert(!empty($trace['ref']), 'trace link ref');
smoke_assert(!empty($trace['link']), 'trace link url');

$refer = aff_refer_bootstrap($pdo, 'P001', (string)$trace['ref']);
smoke_assert(($refer['product']['id'] ?? '') === 'P001', 'refer bootstrap product');
smoke_assert(!empty($refer['maskedRef']), 'refer bootstrap masked ref');

$pdo->prepare("UPDATE affiliate_owners SET available_balance=100000, blocked_balance=0, total_balance=100000 WHERE owner_code=?")->execute([AFF_OWNER_CODE]);

$trigger = aff_trigger_contact($pdo, 'P001', (string)$trace['ref'], [
    'client_name' => 'Smoke RAC',
    'client_phone' => '+53 5000 0000',
]);
smoke_assert(!empty($trigger['lead_id']), 'trigger contact lead');
smoke_assert(!empty($trigger['redirect_url']), 'trigger contact redirect');

$updated = aff_update_lead_status($pdo, (string)$trigger['lead_id'], 'sold');
smoke_assert(($updated['status'] ?? '') === 'sold', 'lead sold update');

echo "SMOKE COMPLETO\n";
