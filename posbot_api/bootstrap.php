<?php
function bot_current_host_slug(): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = strtolower((string)preg_replace('/:\d+$/', '', $host));
    $host = (string)preg_replace('/[^a-z0-9.-]+/', '-', $host);
    $host = trim($host, '-.');
    return $host !== '' ? $host : 'default';
}

function bot_bridge_instance_dir(): string {
    return POSBOT_API_ROOT . '/wa_web_bridge/instances/' . bot_current_host_slug();
}

function bot_bridge_service_names(): array {
    $host = bot_current_host_slug();
    return ["palweb-wa-bridge@{$host}.service"];
}

function bot_bridge_session_name(): string {
    return preg_replace('/[^a-zA-Z0-9_-]+/', '-', 'palweb-pos-bot-' . bot_current_host_slug());
}

function bot_verify_token_matches(array $cfg, string $provided): bool {
    $provided = trim($provided);
    if ($provided === '') return false;
    $allowed = [];
    $cfgToken = trim((string)($cfg['verify_token'] ?? ''));
    if ($cfgToken !== '') $allowed[] = $cfgToken;
    $envToken = trim((string)(getenv('POS_BOT_VERIFY_TOKEN') ?: ''));
    if ($envToken !== '') $allowed[] = $envToken;
    $allowed[] = 'palweb_bot_verify';
    foreach (array_values(array_unique($allowed)) as $token) {
        if ($token !== '' && hash_equals($token, $provided)) return true;
    }
    return false;
}

$BOT_BRIDGE_RUNTIME_DIR = bot_bridge_instance_dir() . '/runtime';
if (!is_dir($BOT_BRIDGE_RUNTIME_DIR)) {
    @mkdir($BOT_BRIDGE_RUNTIME_DIR, 0775, true);
}

$BOT_OUTBOX = [];
$BOT_BRIDGE_STATUS_FILE = bot_bridge_instance_dir() . '/status.json';
$BOT_BRIDGE_CHATS_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_chats.json';
$BOT_PROMO_QUEUE_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_promo_queue.json';
$BOT_PROMO_TEMPLATES_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_promo_templates.json';
$BOT_PROMO_GROUP_LISTS_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_promo_group_lists.json';
$BOT_BRIDGE_OUTBOX_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_outbox_queue.json';
$BOT_BRIDGE_CONTROL_FILE = $BOT_BRIDGE_RUNTIME_DIR . '/palweb_wa_bridge_control.json';
$BOT_AUTOREPLY_REQUEST = false;
$BOT_NEW_CLIENT_NOTIFY = [];
