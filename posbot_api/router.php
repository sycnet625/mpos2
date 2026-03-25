<?php
bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'conversation_list','conversation_pause','conversation_resume','conversation_send_manual','client_activity_list','client_activity_detail',
    'promo_chats','promo_products','promo_my_group_payload','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete','promo_clone',
    'promo_templates','promo_template_save','promo_template_delete','promo_upload_image',
    'promo_group_lists','promo_group_list_save','promo_group_list_delete',
    'bridge_restart','bridge_logs','clear_message_logs'
];
if (in_array($action, $adminActions, true)) bot_require_admin_session();

require __DIR__ . '/router/bridge.php';
require __DIR__ . '/router/admin.php';
require __DIR__ . '/router/promo.php';
require __DIR__ . '/router/incoming.php';
