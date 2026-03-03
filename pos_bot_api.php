<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

function bot_require_admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
        exit;
    }
}

function bot_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_config (
        id TINYINT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        verify_token VARCHAR(120) DEFAULT NULL,
        wa_phone_number_id VARCHAR(80) DEFAULT NULL,
        wa_access_token TEXT DEFAULT NULL,
        business_name VARCHAR(120) DEFAULT 'PalWeb POS',
        welcome_message TEXT,
        menu_intro TEXT,
        no_match_message TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_sessions (
        wa_user_id VARCHAR(40) PRIMARY KEY,
        wa_name VARCHAR(120) DEFAULT NULL,
        cart_json TEXT,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        wa_user_id VARCHAR(40) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        msg_type VARCHAR(20) DEFAULT 'text',
        message_text TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_wa_created (wa_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_orders (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        id_pedido INT NOT NULL,
        wa_user_id VARCHAR(40) NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pedido (id_pedido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO pos_bot_config
        (id,enabled,verify_token,business_name,welcome_message,menu_intro,no_match_message)
        VALUES (1,0,'palweb_bot_verify','PalWeb POS',
        'Hola {{name}}. Bienvenido a {{business}}. Escribe MENU.',
        'Usa AGREGAR CODIGO CANTIDAD | CARRITO | CONFIRMAR',
        'No te entendi. Usa MENU, AGREGAR, CARRITO o CONFIRMAR')");
}

function bot_cfg(PDO $pdo): array {
    $c = $pdo->query("SELECT * FROM pos_bot_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    return $c ?: [];
}

function bot_log(PDO $pdo, string $wa, string $dir, string $txt, string $type='text'): void {
    $st = $pdo->prepare("INSERT INTO pos_bot_messages (wa_user_id,direction,msg_type,message_text) VALUES (?,?,?,?)");
    $st->execute([$wa,$dir,$type,$txt]);
}

function bot_send(PDO $pdo, array $cfg, string $wa, string $text): void {
    bot_log($pdo, $wa, 'out', $text);
    // Modo local (si no hay token/phoneId). Integración Meta opcional.
}

function bot_get_cart(PDO $pdo, string $wa, string $name=''): array {
    $st = $pdo->prepare("SELECT cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cart = ['items' => []];
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
        return $cart;
    }
    $cart = json_decode((string)$row['cart_json'], true);
    return is_array($cart) && isset($cart['items']) ? $cart : ['items' => []];
}

function bot_save_cart(PDO $pdo, string $wa, string $name, array $cart): void {
    $st = $pdo->prepare("UPDATE pos_bot_sessions SET wa_name=?, cart_json=?, last_seen=NOW() WHERE wa_user_id=?");
    $st->execute([$name, json_encode($cart, JSON_UNESCAPED_UNICODE), $wa]);
}

function bot_menu(PDO $pdo, int $emp, int $suc): array {
    $st = $pdo->prepare("SELECT codigo,nombre,precio FROM productos
                         WHERE activo=1 AND es_web=1 AND id_empresa=?
                           AND (sucursales_web='' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web)>0)
                         ORDER BY categoria,nombre LIMIT 40");
    $st->execute([$emp,$suc]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bot_cart_text(PDO $pdo, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['text' => 'Carrito vacio.', 'total' => 0];
    $lines = ['Tu carrito:'];
    $tot = 0.0;
    foreach ($items as $sku => $qty) {
        $st = $pdo->prepare("SELECT nombre,precio FROM productos WHERE codigo=? LIMIT 1");
        $st->execute([$sku]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $sub = (float)$p['precio'] * (float)$qty;
        $tot += $sub;
        $lines[] = "- {$p['nombre']} ({$sku}) x{$qty} = $" . number_format($sub,2);
    }
    $lines[] = "TOTAL: $" . number_format($tot,2);
    $lines[] = "Escribe CONFIRMAR para crear el pedido.";
    return ['text' => implode("\n", $lines), 'total' => $tot];
}

function bot_create_order(PDO $pdo, array $appCfg, string $wa, string $name, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['ok'=>false, 'msg'=>'Carrito vacio'];

    $pdo->beginTransaction();
    try {
        $total = 0.0; $valid = [];
        foreach ($items as $sku => $qty) {
            $st = $pdo->prepare("SELECT codigo,nombre,precio FROM productos WHERE codigo=? AND id_empresa=? LIMIT 1");
            $st->execute([$sku, (int)$appCfg['id_empresa']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;
            $q = max(1, (int)$qty);
            $total += (float)$p['precio'] * $q;
            $valid[] = ['sku'=>$p['codigo'], 'qty'=>$q, 'price'=>(float)$p['precio']];
        }
        if (!$valid) throw new Exception('Sin productos validos');

        $notes = "WHATSAPP_BOT\nwa_user={$wa}";
        $h = $pdo->prepare("INSERT INTO pedidos_cabecera
            (cliente_nombre,cliente_telefono,total,estado,fecha,id_empresa,id_sucursal,notas)
            VALUES (?,?,?,'pendiente',NOW(),?,?,?)");
        $h->execute([$name ?: 'Cliente WhatsApp', $wa, $total, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal'], $notes]);
        $idp = (int)$pdo->lastInsertId();

        $d = $pdo->prepare("INSERT INTO pedidos_detalle (id_pedido,id_producto,cantidad,precio_unitario) VALUES (?,?,?,?)");
        foreach ($valid as $it) $d->execute([$idp, $it['sku'], $it['qty'], $it['price']]);

        $pdo->prepare("INSERT INTO pos_bot_orders (id_pedido,wa_user_id,total) VALUES (?,?,?)")->execute([$idp,$wa,$total]);
        $pdo->commit();
        return ['ok'=>true, 'id_pedido'=>$idp, 'total'=>$total];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'msg'=>$e->getMessage()];
    }
}

function bot_handle_text(PDO $pdo, array $cfg, array $appCfg, string $wa, string $name, string $text): void {
    $msg = trim($text);
    $cmd = mb_strtoupper($msg);
    $cart = bot_get_cart($pdo, $wa, $name);

    if (in_array($cmd, ['HOLA','START','MENU','MENÚ'], true)) {
        $menu = bot_menu($pdo, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal']);
        $hello = str_replace(['{{name}}','{{business}}'], [$name ?: 'Cliente', $cfg['business_name'] ?? 'PalWeb POS'], (string)($cfg['welcome_message'] ?? ''));
        $lines = [$hello, '', (string)($cfg['menu_intro'] ?? 'Menu:')];
        foreach ($menu as $p) $lines[] = "{$p['codigo']} - {$p['nombre']} - $" . number_format((float)$p['precio'],2);
        $lines[] = "";
        $lines[] = "Comandos: AGREGAR CODIGO CANTIDAD | QUITAR CODIGO | CARRITO | CONFIRMAR";
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        return;
    }

    if (str_starts_with($cmd, 'AGREGAR ')) {
        $parts = preg_split('/\s+/', $msg);
        $sku = $parts[1] ?? '';
        $qty = isset($parts[2]) ? max(1, (int)$parts[2]) : 1;
        if ($sku === '') { bot_send($pdo,$cfg,$wa,'Formato: AGREGAR CODIGO CANTIDAD'); return; }
        $st = $pdo->prepare("SELECT nombre FROM productos WHERE codigo=? AND activo=1 AND es_web=1 AND id_empresa=? LIMIT 1");
        $st->execute([$sku, (int)$appCfg['id_empresa']]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { bot_send($pdo,$cfg,$wa,"No encontre el producto {$sku}. Escribe MENU."); return; }
        $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + $qty;
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo,$cfg,$wa,"Agregado: {$p['nombre']} x{$qty}.");
        return;
    }

    if (str_starts_with($cmd, 'QUITAR ')) {
        $sku = trim((string)(preg_split('/\s+/', $msg)[1] ?? ''));
        if ($sku !== '' && isset($cart['items'][$sku])) {
            unset($cart['items'][$sku]);
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo,$cfg,$wa,"Producto {$sku} eliminado del carrito.");
        } else {
            bot_send($pdo,$cfg,$wa,'Ese producto no esta en tu carrito.');
        }
        return;
    }

    if ($cmd === 'CARRITO') { bot_send($pdo,$cfg,$wa, bot_cart_text($pdo,$cart)['text']); return; }
    if ($cmd === 'CANCELAR') { $cart=['items'=>[]]; bot_save_cart($pdo,$wa,$name,$cart); bot_send($pdo,$cfg,$wa,'Carrito cancelado.'); return; }

    if (in_array($cmd, ['CONFIRMAR','ORDENAR','PEDIR'], true)) {
        $res = bot_create_order($pdo, $appCfg, $wa, $name, $cart);
        if ($res['ok']) {
            bot_save_cart($pdo, $wa, $name, ['items'=>[]]);
            bot_send($pdo,$cfg,$wa,"Pedido creado #{$res['id_pedido']}. Total: $" . number_format((float)$res['total'],2));
        } else {
            bot_send($pdo,$cfg,$wa,"No pude crear el pedido: {$res['msg']}");
        }
        return;
    }

    bot_send($pdo, $cfg, $wa, (string)($cfg['no_match_message'] ?? 'No te entendi.'));
}

bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = ['get_config','save_config','stats','recent_messages','recent_orders','test_incoming'];
if (in_array($action, $adminActions, true)) bot_require_admin_session();

if ($action === 'webhook_verify' || ($_SERVER['REQUEST_METHOD']==='GET' && (isset($_GET['hub.mode']) || isset($_GET['hub_mode'])))) {
    $mode = $_GET['hub.mode'] ?? $_GET['hub_mode'] ?? '';
    $token = $_GET['hub.verify_token'] ?? $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub.challenge'] ?? $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $token !== '' && hash_equals((string)($cfg['verify_token'] ?? ''), (string)$token)) {
        header('Content-Type: text/plain; charset=utf-8'); echo $challenge; exit;
    }
    http_response_code(403); echo json_encode(['status'=>'error','msg'=>'verify failed']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='get_config') {
    $safe = $cfg; if (!empty($safe['wa_access_token'])) $safe['wa_access_token'] = '************';
    echo json_encode(['status'=>'success','config'=>$safe]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='save_config') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string)($in['wa_access_token'] ?? ''));
    if ($token === '') $token = (string)($cfg['wa_access_token'] ?? '');
    $st = $pdo->prepare("UPDATE pos_bot_config SET enabled=?,verify_token=?,wa_phone_number_id=?,wa_access_token=?,business_name=?,welcome_message=?,menu_intro=?,no_match_message=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        substr(trim((string)($in['verify_token'] ?? $cfg['verify_token'] ?? 'palweb_bot_verify')),0,120),
        substr(trim((string)($in['wa_phone_number_id'] ?? $cfg['wa_phone_number_id'] ?? '')),0,80),
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

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_messages') {
    $rows = $pdo->query("SELECT wa_user_id,direction,msg_type,message_text,created_at FROM pos_bot_messages ORDER BY id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_orders') {
    $rows = $pdo->query("SELECT bo.id,bo.id_pedido,bo.wa_user_id,bo.total,bo.created_at,pc.cliente_nombre FROM pos_bot_orders bo LEFT JOIN pedidos_cabecera pc ON pc.id=bo.id_pedido ORDER BY bo.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='test_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = preg_replace('/\D+/', '', (string)($in['wa_user_id'] ?? '5350000000'));
    $name = trim((string)($in['wa_name'] ?? 'Cliente Test'));
    $text = trim((string)($in['text'] ?? 'MENU'));
    bot_log($pdo, $wa, 'in', $text);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (empty($cfg['enabled'])) { echo json_encode(['status'=>'ok','msg'=>'bot disabled']); exit; }
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
                bot_handle_text($pdo, $cfg, $config, $from, $name, $text);
            }
        }
    }
    echo json_encode(['status'=>'ok']); exit;
}

echo json_encode(['status'=>'error','msg'=>'invalid request']);
