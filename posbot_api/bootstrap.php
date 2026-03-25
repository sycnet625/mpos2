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

$botBridgeRuntimeDir = bot_bridge_instance_dir() . '/runtime';
if (!is_dir($botBridgeRuntimeDir)) {
    @mkdir($botBridgeRuntimeDir, 0775, true);
}

$BOT_CONTEXT = [
    'bridge_runtime_dir' => $botBridgeRuntimeDir,
    'bridge_status_file' => bot_bridge_instance_dir() . '/status.json',
    'bridge_chats_file' => $botBridgeRuntimeDir . '/palweb_wa_chats.json',
    'promo_queue_file' => $botBridgeRuntimeDir . '/palweb_wa_promo_queue.json',
    'promo_templates_file' => $botBridgeRuntimeDir . '/palweb_wa_promo_templates.json',
    'promo_group_lists_file' => $botBridgeRuntimeDir . '/palweb_wa_promo_group_lists.json',
    'bridge_outbox_file' => $botBridgeRuntimeDir . '/palweb_wa_outbox_queue.json',
    'bridge_control_file' => $botBridgeRuntimeDir . '/palweb_wa_bridge_control.json',
    'outbox' => [],
    'autoreply_request' => false,
    'new_client_notify' => [],
];

function bot_context_get(string $key, $default = null) {
    global $BOT_CONTEXT;
    if (!isset($BOT_CONTEXT) || !is_array($BOT_CONTEXT) || !array_key_exists($key, $BOT_CONTEXT)) {
        return $default;
    }
    return $BOT_CONTEXT[$key];
}

function bot_context_set(string $key, $value): void {
    global $BOT_CONTEXT;
    if (!isset($BOT_CONTEXT) || !is_array($BOT_CONTEXT)) {
        $BOT_CONTEXT = [];
    }
    $BOT_CONTEXT[$key] = $value;
}
