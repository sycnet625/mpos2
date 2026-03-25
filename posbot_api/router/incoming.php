<?php
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='test_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>$replyState['reason'],'responses'=>[]]); exit;
    }
    $wa = preg_replace('/\D+/', '', (string)($in['wa_user_id'] ?? '5350000000'));
    $name = trim((string)($in['wa_name'] ?? 'Cliente Test'));
    $text = trim((string)($in['text'] ?? 'MENU'));
    bot_log($pdo, $wa, 'in', $text);
    bot_begin_autoreply_request($pdo, $wa);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    bot_end_autoreply_request($wa);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='web_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $provided = (string)($in['verify_token'] ?? '');
    if (!bot_verify_token_matches($cfg, $provided)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','msg'=>'invalid token']); exit;
    }
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>$replyState['reason'],'responses'=>[]]); exit;
    }
    if (($cfg['wa_mode'] ?? 'web') !== 'web') {
        echo json_encode(['status'=>'ok','msg'=>'wa_mode is not web','responses'=>[]]); exit;
    }
    $wa = bot_clean_wa_id((string)($in['wa_user_id'] ?? ''));
    if ($wa === '') {
        http_response_code(400);
        echo json_encode(['status'=>'error','msg'=>'wa_user_id required']); exit;
    }
    $name = trim((string)($in['wa_name'] ?? 'Cliente'));
    $text = trim((string)($in['text'] ?? ''));
    $ignoredPayloads = ['[e2e_notification]','[notification_template]','[protocol]','[ciphertext]','[gp2]','[revoked]'];
    if (in_array(strtolower($text), array_map('strtolower', $ignoredPayloads), true)) {
        echo json_encode(['status'=>'ok','msg'=>'technical payload ignored','responses'=>[]]); exit;
    }
    if ($text === '') {
        echo json_encode(['status'=>'ok','msg'=>'empty text ignored','responses'=>[]]); exit;
    }
    bot_log($pdo, $wa, 'in', $text);
    bot_begin_autoreply_request($pdo, $wa);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    bot_end_autoreply_request($wa);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) { echo json_encode(['status'=>'ok','msg'=>$replyState['reason']]); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $chg) {
            $value = $chg['value'] ?? [];
            foreach (($value['messages'] ?? []) as $m) {
                $from = preg_replace('/\D+/', '', (string)($m['from'] ?? ''));
                if ($from === '') continue;
                $name = (string)($value['contacts'][0]['profile']['name'] ?? 'Cliente');
                $type = (string)($m['type'] ?? 'text');
                $text = $type === 'text' ? (string)($m['text']['body'] ?? '') : (string)($m['button']['text'] ?? '[non-text]');
                bot_log($pdo, $from, 'in', $text, $type);
                bot_begin_autoreply_request($pdo, $from);
                bot_handle_text($pdo, $cfg, $config, $from, $name, $text);
                bot_end_autoreply_request($from);
            }
        }
    }
    echo json_encode(['status'=>'ok']); exit;
}

echo json_encode(['status'=>'error','msg'=>'invalid request']);
