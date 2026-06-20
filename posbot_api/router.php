<?php
if (!defined('POSBOT_API_ROOT')) {
    define('POSBOT_API_ROOT', dirname(__DIR__));
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    require_once POSBOT_API_ROOT . '/db.php';
    require_once POSBOT_API_ROOT . '/config_loader.php';
}

require_once POSBOT_API_ROOT . '/habana_delivery.php';
require_once POSBOT_API_ROOT . '/push_notify.php';
require_once POSBOT_API_ROOT . '/posbot_api/bootstrap.php';
require_once POSBOT_API_ROOT . '/posbot_api/repository.php';
require_once POSBOT_API_ROOT . '/posbot_api/helpers.php';
require_once __DIR__ . '/helpers/runtime.php';
bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

// CSRF ligero: verifica header X-Requested-With en POST (no se puede enviar cross-origin sin CORS)
function bot_require_ajax_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $header = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($header !== 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'CSRF: header X-Requested-With requerido']);
        exit;
    }
}

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'conversation_list','conversation_pause','conversation_resume','conversation_send_manual','client_activity_list','client_activity_detail',
    'promo_chats','promo_products','promo_my_group_payload','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete','promo_pause','promo_clone',
    'promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'promo_group_lists','promo_group_list_save','promo_group_list_delete',
    'bridge_restart','bridge_reset_session','bridge_logs','clear_message_logs'
];
if (in_array($action, $adminActions, true)) {
    bot_require_admin_session();
    bot_require_ajax_csrf();
}

require __DIR__ . '/router/bridge.php';
require __DIR__ . '/router/admin.php';
require __DIR__ . '/router/promo.php';
require __DIR__ . '/router/incoming.php';
