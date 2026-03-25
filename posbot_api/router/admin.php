<?php
if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='get_config') {
    $safe = $cfg;
    $safe['wa_mode'] = in_array((string)($safe['wa_mode'] ?? ''), ['web','meta_api'], true) ? $safe['wa_mode'] : 'web';
    $safe['bot_tone'] = in_array((string)($safe['bot_tone'] ?? ''), ['premium','popular_cubano','formal_comercial','muy_cercano'], true) ? $safe['bot_tone'] : 'muy_cercano';
    $safe['auto_schedule_enabled'] = !empty($safe['auto_schedule_enabled']) ? 1 : 0;
    $safe['auto_off_start'] = bot_valid_time_hhmm((string)($safe['auto_off_start'] ?? '07:00'), '07:00');
    $safe['auto_off_end'] = bot_valid_time_hhmm((string)($safe['auto_off_end'] ?? '20:00'), '20:00');
    $safe['auto_reply_state'] = bot_autoreply_state($safe);
    if (!empty($safe['wa_access_token'])) $safe['wa_access_token'] = '************';
    echo json_encode(['status'=>'success','config'=>$safe]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_config') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $waMode = (string)($in['wa_mode'] ?? $cfg['wa_mode'] ?? 'web');
    if (!in_array($waMode, ['web','meta_api'], true)) $waMode = 'web';
    $token = trim((string)($in['wa_access_token'] ?? ''));
    if ($token === '') $token = (string)($cfg['wa_access_token'] ?? '');
    if ($waMode === 'web') {
        $token = '';
    }
    $botTone = (string)($in['bot_tone'] ?? $cfg['bot_tone'] ?? 'muy_cercano');
    if (!in_array($botTone, ['premium','popular_cubano','formal_comercial','muy_cercano'], true)) $botTone = 'muy_cercano';
    $autoScheduleEnabled = !empty($in['auto_schedule_enabled']) ? 1 : 0;
    $autoOffStart = bot_valid_time_hhmm((string)($in['auto_off_start'] ?? $cfg['auto_off_start'] ?? '07:00'), '07:00');
    $autoOffEnd = bot_valid_time_hhmm((string)($in['auto_off_end'] ?? $cfg['auto_off_end'] ?? '20:00'), '20:00');
    $st = $pdo->prepare("UPDATE pos_bot_config SET enabled=?,auto_schedule_enabled=?,auto_off_start=?,auto_off_end=?,wa_mode=?,bot_tone=?,verify_token=?,wa_phone_number_id=?,wa_access_token=?,business_name=?,welcome_message=?,menu_intro=?,no_match_message=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        $autoScheduleEnabled,
        $autoOffStart,
        $autoOffEnd,
        $waMode,
        $botTone,
        substr(trim((string)($in['verify_token'] ?? $cfg['verify_token'] ?? 'palweb_bot_verify')),0,120),
        $waMode === 'web' ? '' : substr(trim((string)($in['wa_phone_number_id'] ?? $cfg['wa_phone_number_id'] ?? '')),0,80),
        $token,
        substr(trim((string)($in['business_name'] ?? $cfg['business_name'] ?? 'PalWeb POS')),0,120),
        trim((string)($in['welcome_message'] ?? $cfg['welcome_message'] ?? '')),
        trim((string)($in['menu_intro'] ?? $cfg['menu_intro'] ?? '')),
        trim((string)($in['no_match_message'] ?? $cfg['no_match_message'] ?? '')),
    ]);
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='stats') {
    $s = [
        'sessions' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_sessions")->fetchColumn(),
        'msgs_today' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_messages WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'orders_today' => (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'sales_today' => (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
    ];
    echo json_encode(['status'=>'success','stats'=>$s]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='conversation_list') {
    echo json_encode(['status' => 'success', 'rows' => bot_build_conversation_rows($pdo)]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_pause') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 1;
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_resume') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 0;
        $cart['escalation_active'] = 0;
        $cart['escalation_reason'] = '';
        $cart['escalation_label'] = '';
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='conversation_send_manual') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    $text = trim((string)($in['text'] ?? ''));
    $sendQuick = !empty($in['send_quick_actions']);
    if ($wa === '') { echo json_encode(['status'=>'error','msg'=>'wa_user_id requerido']); exit; }
    if ($text === '' && !$sendQuick) { echo json_encode(['status'=>'error','msg'=>'Mensaje vacío']); exit; }
    if ($text !== '' && !bot_enqueue_bridge_job([
        'target_id' => $wa,
        'type' => 'text',
        'text' => $text
    ])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo encolar mensaje']); exit;
    }
    if ($text !== '') bot_log($pdo, $wa, 'out', $text, 'manual');
    if ($sendQuick) {
        bot_enqueue_bridge_job([
            'target_id' => $wa,
            'type' => 'buttons',
            'text' => 'Accesos rápidos:',
            'buttons' => ['Menu', 'Repetir pedido', 'Carrito'],
            'title' => 'Atajos',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($config))
        ]);
        bot_enqueue_bridge_job([
            'target_id' => $wa,
            'type' => 'buttons',
            'text' => 'Más opciones:',
            'buttons' => ['Confirmar', 'Comprar en web'],
            'title' => 'Atajos',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($config))
        ]);
    }
    bot_conversation_update($pdo, $wa, static function (array $cart) {
        $cart['bot_paused'] = 1;
        $cart['last_manual_reply_at'] = date('c');
        return $cart;
    });
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_messages') {
    $rows = $pdo->query("SELECT m.wa_user_id,m.direction,m.msg_type,m.message_text,m.created_at,COALESCE(s.wa_name,'') AS wa_name
        FROM pos_bot_messages m
        LEFT JOIN pos_bot_sessions s ON s.wa_user_id = m.wa_user_id
        ORDER BY m.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='clear_message_logs') {
    $deleted = (int)$pdo->query("SELECT COUNT(*) FROM pos_bot_messages")->fetchColumn();
    $pdo->exec("TRUNCATE TABLE pos_bot_messages");
    echo json_encode(['status'=>'success','deleted'=>$deleted]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_orders') {
    $rows = $pdo->query("SELECT bo.id,bo.id_pedido,bo.wa_user_id,bo.total,bo.created_at,
            COALESCE(vc.cliente_nombre, pc.cliente_nombre, '') AS cliente_nombre
        FROM pos_bot_orders bo
        LEFT JOIN pedidos_cabecera pc ON pc.id=bo.id_pedido
        LEFT JOIN ventas_cabecera vc ON vc.id=bo.id_pedido AND vc.tipo_servicio='reserva'
        ORDER BY bo.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

