<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';

$BOT_OUTBOX = [];
$BOT_BRIDGE_STATUS_FILE = __DIR__ . '/wa_web_bridge/status.json';
$BOT_BRIDGE_CHATS_FILE = '/tmp/palweb_wa_chats.json';
$BOT_PROMO_QUEUE_FILE = '/tmp/palweb_wa_promo_queue.json';
$BOT_PROMO_TEMPLATES_FILE = '/tmp/palweb_wa_promo_templates.json';

function bot_require_admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
        exit;
    }
}

function bot_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function bot_clean_wa_id(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits !== '') return substr($digits, 0, 40);
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $raw) ?? '';
    return substr($safe, 0, 40);
}

function bot_read_json_file(string $file, $default = []): array {
    if (!is_file($file)) return is_array($default) ? $default : [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return is_array($default) ? $default : [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : (is_array($default) ? $default : []);
}

function bot_write_json_file(string $file, array $data): bool {
    $tmp = $file . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $file);
}

function bot_public_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
    return $scheme . '://' . $host;
}

function bot_sanitize_shell_output(string $text): string {
    $lines = preg_split('/\R+/', trim($text)) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $l = trim($line);
        if ($l === '') continue;
        if (stripos($l, 'sudo: a password is required') !== false) continue;
        if (stripos($l, 'sudo: no tty present') !== false) continue;
        if (stripos($l, 'a terminal is required to read the password') !== false) continue;
        $clean[] = $l;
    }
    return trim(implode("\n", $clean));
}

function bot_is_bridge_process_running(): bool {
    $out = [];
    $code = 1;
    @exec('pgrep -f "wa_web_bridge/bridge.js" 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

function bot_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_bot_config (
        id TINYINT PRIMARY KEY DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        wa_mode ENUM('web','meta_api') NOT NULL DEFAULT 'web',
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

    if (!bot_has_column($pdo, 'pos_bot_config', 'wa_mode')) {
        $pdo->exec("ALTER TABLE pos_bot_config ADD COLUMN wa_mode ENUM('web','meta_api') NOT NULL DEFAULT 'web' AFTER enabled");
    }
    $pdo->exec("UPDATE pos_bot_config SET wa_mode='web' WHERE wa_mode IS NULL OR wa_mode=''");
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
    global $BOT_OUTBOX;
    $BOT_OUTBOX[] = ['wa_user_id' => $wa, 'text' => $text];
    bot_log($pdo, $wa, 'out', $text);
    // Modo local (si no hay token/phoneId). Integración Meta opcional.
}

function bot_take_outbox(): array {
    global $BOT_OUTBOX;
    $out = $BOT_OUTBOX;
    $BOT_OUTBOX = [];
    return $out;
}

function bot_get_cart(PDO $pdo, string $wa, string $name=''): array {
    $st = $pdo->prepare("SELECT cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cart = ['items' => [], 'customer_name' => $name, 'customer_address' => '', 'awaiting_field' => 'name'];
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
        return $cart;
    }
    $cart = json_decode((string)$row['cart_json'], true);
    if (!is_array($cart) || !isset($cart['items']) || !is_array($cart['items'])) {
        return ['items' => [], 'customer_name' => $name, 'customer_address' => '', 'awaiting_field' => 'name'];
    }
    if (!array_key_exists('customer_name', $cart)) $cart['customer_name'] = $name;
    if (!array_key_exists('customer_address', $cart)) $cart['customer_address'] = '';
    if (!array_key_exists('awaiting_field', $cart)) $cart['awaiting_field'] = '';
    return $cart;
}

function bot_save_cart(PDO $pdo, string $wa, string $name, array $cart): void {
    $st = $pdo->prepare("UPDATE pos_bot_sessions SET wa_name=?, cart_json=?, last_seen=NOW() WHERE wa_user_id=?");
    $st->execute([$name, json_encode($cart, JSON_UNESCAPED_UNICODE), $wa]);
}

function bot_norm(string $text): string {
    $t = mb_strtolower($text, 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n'
    ];
    $t = strtr($t, $map);
    $t = preg_replace('/[^a-z0-9\s]/', ' ', $t) ?? '';
    $t = preg_replace('/\s+/', ' ', trim($t)) ?? '';
    return $t;
}

function bot_clean_value(string $text): string {
    $v = trim($text);
    $v = preg_replace('/\s+/', ' ', $v) ?? '';
    return trim($v, " \t\n\r\0\x0B,.;:-");
}

function bot_extract_name(string $text): string {
    $raw = trim($text);
    if ($raw === '') return '';
    $patterns = [
        '/^(mi nombre es|soy|me llamo)\s+/iu',
        '/^(nombre)\s*[:\-]?\s*/iu',
    ];
    foreach ($patterns as $p) $raw = preg_replace($p, '', $raw) ?? $raw;
    $val = bot_clean_value($raw);
    if ($val === '' || mb_strlen($val) < 2) return '';
    return mb_substr($val, 0, 120);
}

function bot_extract_address(string $text): string {
    $raw = trim($text);
    if ($raw === '') return '';
    $patterns = [
        '/^(mi direccion es|direccion|dir)\s*[:\-]?\s*/iu',
        '/^(vivo en|entrega en)\s+/iu',
    ];
    foreach ($patterns as $p) $raw = preg_replace($p, '', $raw) ?? $raw;
    $val = bot_clean_value($raw);
    if ($val === '' || mb_strlen($val) < 6) return '';
    return mb_substr($val, 0, 220);
}

function bot_intent_has(string $norm, array $needles): bool {
    foreach ($needles as $n) {
        if (str_contains($norm, bot_norm($n))) return true;
    }
    return false;
}

function bot_extract_qty(string $normText): int {
    if (preg_match('/\b(\d{1,2})\b/', $normText, $m)) return max(1, min(99, (int)$m[1]));
    return 1;
}

function bot_find_product_by_text(array $menu, string $normText): ?array {
    $best = null;
    $bestScore = 0;
    $tokens = array_values(array_filter(explode(' ', $normText), static fn($x) => mb_strlen($x) >= 2));
    foreach ($menu as $p) {
        $sku = (string)($p['codigo'] ?? '');
        $name = (string)($p['nombre'] ?? '');
        $skuNorm = bot_norm($sku);
        $nameNorm = bot_norm($name);
        if ($skuNorm !== '' && preg_match('/\b'.preg_quote($skuNorm, '/').'\b/', $normText)) {
            return $p;
        }
        $nameTokens = array_values(array_filter(explode(' ', $nameNorm), static fn($x) => mb_strlen($x) >= 3));
        if (!$nameTokens) continue;
        $common = 0;
        foreach ($nameTokens as $tk) {
            if (in_array($tk, $tokens, true)) $common++;
        }
        if ($common === 0) continue;
        $ratio = $common / max(1, count($nameTokens));
        $score = (int)round($ratio * 100) + $common;
        if ($ratio >= 0.5 && $score > $bestScore) {
            $best = $p;
            $bestScore = $score;
        }
    }
    return $best;
}

function bot_parse_add_items(array $menu, string $text): array {
    $norm = bot_norm($text);
    if ($norm === '') return [];
    $segments = preg_split('/\s*(?:,| y | e |;|\+)\s*/u', $norm) ?: [$norm];
    $out = [];
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $p = bot_find_product_by_text($menu, $seg);
        if (!$p) continue;
        $qty = bot_extract_qty($seg);
        $sku = (string)$p['codigo'];
        $out[$sku] = ($out[$sku] ?? 0) + $qty;
    }
    if (!$out) {
        $single = bot_find_product_by_text($menu, $norm);
        if ($single) {
            $qty = bot_extract_qty($norm);
            $out[(string)$single['codigo']] = $qty;
        }
    }
    $items = [];
    foreach ($out as $sku => $qty) $items[] = ['sku' => $sku, 'qty' => $qty];
    return $items;
}

function bot_parse_remove_item(array $menu, string $text): ?string {
    $norm = bot_norm($text);
    $p = bot_find_product_by_text($menu, $norm);
    return $p ? (string)$p['codigo'] : null;
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
    if (!empty($cart['customer_name'])) $lines[] = "Nombre: " . $cart['customer_name'];
    if (!empty($cart['customer_address'])) $lines[] = "Direccion: " . $cart['customer_address'];
    $lines[] = "Escribe CONFIRMAR para crear el pedido.";
    return ['text' => implode("\n", $lines), 'total' => $tot];
}

function bot_create_order(PDO $pdo, array $appCfg, string $wa, string $name, string $address, array $cart): array {
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

        $notes = "WHATSAPP_BOT\nwa_user={$wa}\nwa_name={$name}\nwa_address={$address}";
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
    if ($msg === '') return;
    $norm = bot_norm($msg);
    $cart = bot_get_cart($pdo, $wa, $name);
    $menu = bot_menu($pdo, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal']);
    if (($cart['customer_name'] ?? '') === '' && $name !== '') $cart['customer_name'] = $name;

    $isGreeting = bot_intent_has($norm, ['hola','buenas','start','iniciar']);
    $isMenuReq = bot_intent_has($norm, ['menu','menú','catalogo','carta','productos']);
    $isCartReq = bot_intent_has($norm, ['carrito','mi pedido','resumen']);
    $isCancel = bot_intent_has($norm, ['cancelar','vaciar carrito','borrar carrito']);
    $isConfirm = bot_intent_has($norm, ['confirmar','ordenar','pedir','finalizar','checkout']);
    $isAdd = bot_intent_has($norm, ['agregar','añadir','anadir','sumar','quiero','dame','deseo','me das','ponme','mandame']);
    $isRemove = bot_intent_has($norm, ['quitar','eliminar','sacar','remover']);

    $looksLikeName = bot_intent_has($norm, ['mi nombre es','soy','me llamo','nombre']);
    $looksLikeAddress = bot_intent_has($norm, ['direccion','dirección','mi direccion es','vivo en','entrega en','dir']);

    if (($cart['awaiting_field'] ?? '') === 'name' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Gracias, {$candidateName}. Ahora comparte la direccion de entrega.");
            return;
        }
        bot_send($pdo, $cfg, $wa, "Para continuar necesito tu nombre. Ejemplo: Mi nombre es Juan Perez.");
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'address' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Perfecto. Direccion guardada: {$candidateAddress}. Cuando quieras, escribe CONFIRMAR.");
            return;
        }
        bot_send($pdo, $cfg, $wa, "Necesito una direccion valida para entrega. Ejemplo: Direccion: Calle 10 #45 entre A y B.");
        return;
    }

    if ($looksLikeName) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            if (($cart['awaiting_field'] ?? '') === 'name') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Nombre guardado: {$candidateName}.");
            return;
        }
    }

    if ($looksLikeAddress) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            if (($cart['awaiting_field'] ?? '') === 'address') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Direccion guardada: {$candidateAddress}.");
            return;
        }
    }

    if ($isGreeting || $isMenuReq) {
        $hello = str_replace(['{{name}}','{{business}}'], [$name ?: 'Cliente', $cfg['business_name'] ?? 'PalWeb POS'], (string)($cfg['welcome_message'] ?? ''));
        $lines = [$hello, '', (string)($cfg['menu_intro'] ?? 'Menu:')];
        foreach ($menu as $p) $lines[] = "{$p['codigo']} - {$p['nombre']} - $" . number_format((float)$p['precio'],2);
        $lines[] = "";
        $lines[] = "Puedes escribir natural: \"quiero 2 pizzas y 1 refresco\".";
        $lines[] = "Comandos: AGREGAR CODIGO CANTIDAD | QUITAR CODIGO | CARRITO | CONFIRMAR";
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        return;
    }

    if ($isAdd || str_starts_with(mb_strtoupper($msg), 'AGREGAR ')) {
        $items = bot_parse_add_items($menu, $msg);
        if (!$items && preg_match('/^AGREGAR\s+(\S+)(?:\s+(\d{1,2}))?$/iu', $msg, $m)) {
            $items = [['sku' => (string)$m[1], 'qty' => isset($m[2]) ? max(1, (int)$m[2]) : 1]];
        }
        if (!$items) {
            bot_send($pdo,$cfg,$wa,'No pude identificar productos. Ejemplo: "quiero 2 empanadas y 1 refresco" o AGREGAR CODIGO 2.');
            return;
        }
        $catalogBySku = [];
        foreach ($menu as $p) $catalogBySku[(string)$p['codigo']] = $p;
        $added = [];
        foreach ($items as $it) {
            $sku = (string)($it['sku'] ?? '');
            $qty = max(1, (int)($it['qty'] ?? 1));
            if ($sku === '' || !isset($catalogBySku[$sku])) continue;
            $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + $qty;
            $added[] = "{$catalogBySku[$sku]['nombre']} x{$qty}";
        }
        if (!$added) {
            bot_send($pdo,$cfg,$wa,'No encontre esos productos en el menu web. Escribe MENU para ver codigos.');
            return;
        }
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo,$cfg,$wa,"Agregado al carrito:\n- " . implode("\n- ", $added) . "\nEscribe CARRITO para revisar.");
        return;
    }

    if ($isRemove || str_starts_with(mb_strtoupper($msg), 'QUITAR ')) {
        $sku = bot_parse_remove_item($menu, $msg);
        if (!$sku && preg_match('/^QUITAR\s+(\S+)/iu', $msg, $m)) $sku = (string)$m[1];
        if ($sku !== null && $sku !== '' && isset($cart['items'][$sku])) {
            unset($cart['items'][$sku]);
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo,$cfg,$wa,"Producto {$sku} eliminado del carrito.");
        } else {
            bot_send($pdo,$cfg,$wa,'Ese producto no esta en tu carrito.');
        }
        return;
    }

    if ($isCartReq || mb_strtoupper($msg) === 'CARRITO') { bot_send($pdo,$cfg,$wa, bot_cart_text($pdo,$cart)['text']); return; }
    if ($isCancel || mb_strtoupper($msg) === 'CANCELAR') {
        $cart=['items'=>[], 'customer_name'=>($cart['customer_name'] ?? $name), 'customer_address'=>'', 'awaiting_field'=>''];
        bot_save_cart($pdo,$wa,$name,$cart);
        bot_send($pdo,$cfg,$wa,'Carrito cancelado.');
        return;
    }

    if ($isConfirm || in_array(mb_strtoupper($msg), ['CONFIRMAR','ORDENAR','PEDIR'], true)) {
        if (empty($cart['items'])) {
            bot_send($pdo,$cfg,$wa,'Tu carrito esta vacio. Escribe MENU para comenzar.');
            return;
        }
        if (trim((string)($cart['customer_name'] ?? '')) === '') {
            $cart['awaiting_field'] = 'name';
            bot_save_cart($pdo,$wa,$name,$cart);
            bot_send($pdo,$cfg,$wa,'Antes de confirmar, dime tu nombre completo.');
            return;
        }
        if (trim((string)($cart['customer_address'] ?? '')) === '') {
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo,$wa,$name,$cart);
            bot_send($pdo,$cfg,$wa,'Ahora necesito la direccion de entrega.');
            return;
        }
        $res = bot_create_order($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = ['items'=>[], 'customer_name'=>(string)$cart['customer_name'], 'customer_address'=>(string)$cart['customer_address'], 'awaiting_field'=>''];
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            bot_send($pdo,$cfg,$wa,"Pedido creado #{$res['id_pedido']}. Total: $" . number_format((float)$res['total'],2));
        } else {
            bot_send($pdo,$cfg,$wa,"No pude crear el pedido: {$res['msg']}");
        }
        return;
    }

    bot_send($pdo, $cfg, $wa, "No te entendi del todo. Puedes escribir:\n- MENU\n- quiero 2 croquetas y 1 refresco\n- quitar croquetas\n- carrito\n- confirmar");
}

bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$action = $_GET['action'] ?? '';

$adminActions = [
    'get_config','save_config','stats','recent_messages','recent_orders','test_incoming','bridge_status',
    'promo_chats','promo_products','promo_create','promo_list','promo_detail','promo_force_now','promo_update','promo_delete',
    'promo_templates','promo_template_save','promo_template_delete',
    'bridge_restart','bridge_logs'
];
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
    $safe = $cfg;
    $safe['wa_mode'] = in_array((string)($safe['wa_mode'] ?? ''), ['web','meta_api'], true) ? $safe['wa_mode'] : 'web';
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
    $st = $pdo->prepare("UPDATE pos_bot_config SET enabled=?,wa_mode=?,verify_token=?,wa_phone_number_id=?,wa_access_token=?,business_name=?,welcome_message=?,menu_intro=?,no_match_message=? WHERE id=1");
    $st->execute([
        !empty($in['enabled']) ? 1 : 0,
        $waMode,
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

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_messages') {
    $rows = $pdo->query("SELECT m.wa_user_id,m.direction,m.msg_type,m.message_text,m.created_at,COALESCE(s.wa_name,'') AS wa_name
        FROM pos_bot_messages m
        LEFT JOIN pos_bot_sessions s ON s.wa_user_id = m.wa_user_id
        ORDER BY m.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='recent_orders') {
    $rows = $pdo->query("SELECT bo.id,bo.id_pedido,bo.wa_user_id,bo.total,bo.created_at,pc.cliente_nombre FROM pos_bot_orders bo LEFT JOIN pedidos_cabecera pc ON pc.id=bo.id_pedido ORDER BY bo.id DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','rows'=>$rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='bridge_status') {
    $statusFile = $BOT_BRIDGE_STATUS_FILE;
    if (!is_file($statusFile)) {
        $running = bot_is_bridge_process_running();
        echo json_encode([
            'status'=>'success',
            'bridge'=>[
                'state'=>$running ? 'starting' : 'stopped',
                'msg'=>$running ? 'Bridge ejecutándose sin status.json aún' : 'Bridge detenido o sin status.json'
            ]
        ]);
        exit;
    }
    $raw = @file_get_contents($statusFile);
    $bridge = json_decode((string)$raw, true);
    if (!is_array($bridge)) {
        $running = bot_is_bridge_process_running();
        echo json_encode([
            'status'=>'success',
            'bridge'=>[
                'state'=>$running ? 'starting' : 'unknown',
                'msg'=>'Estado invalido en status.json'
            ]
        ]);
        exit;
    }
    $updatedAt = strtotime((string)($bridge['updated_at'] ?? '')) ?: 0;
    $ageSec = $updatedAt > 0 ? (time() - $updatedAt) : PHP_INT_MAX;
    if ($ageSec > 45) {
        $bridge['state'] = 'disconnected';
        $bridge['msg'] = 'Estado del bridge desactualizado';
        $bridge['stale_seconds'] = $ageSec;
    }
    echo json_encode(['status'=>'success','bridge'=>$bridge]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='bridge_restart') {
    $commands = [
        'systemctl restart palweb-wa-bridge.service 2>&1',
        'sudo -n systemctl restart palweb-wa-bridge.service 2>&1'
    ];
    $lastOut = '';
    $ok = false;
    foreach ($commands as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $lastOut = bot_sanitize_shell_output(implode("\n", $out));
        if ($code === 0) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $detail = $lastOut !== '' ? $lastOut : 'Permisos insuficientes para systemctl desde PHP.';
        echo json_encode([
            'status' => 'error',
            'msg' => 'No se pudo reiniciar el bridge desde PHP. Revisa permisos de systemctl/sudo.',
            'detail' => $detail
        ]);
        exit;
    }

    $activeOut = [];
    $activeCode = 1;
    @exec('systemctl is-active palweb-wa-bridge.service 2>&1', $activeOut, $activeCode);
    $active = trim(implode("\n", $activeOut));
    echo json_encode([
        'status' => 'success',
        'msg' => 'Bridge reiniciado. Estado: ' . ($active !== '' ? $active : 'desconocido')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='bridge_logs') {
    $cmds = [
        'journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1',
        'sudo -n journalctl -q -u palweb-wa-bridge.service -n 120 --no-pager 2>&1'
    ];
    $logText = '';
    foreach ($cmds as $cmd) {
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $txt = bot_sanitize_shell_output(implode("\n", $out));
        if ($txt !== '') {
            $logText = $txt;
            if ($code === 0) break;
        }
    }
    if ($logText === '') {
        $logText = 'Sin logs disponibles (o sin permisos de journal para el usuario web).';
    }
    $statusRaw = @file_get_contents($BOT_BRIDGE_STATUS_FILE);
    $status = json_decode((string)$statusRaw, true);
    echo json_encode([
        'status' => 'success',
        'logs' => $logText,
        'bridge' => is_array($status) ? $status : null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_chats') {
    $data = bot_read_json_file($BOT_BRIDGE_CHATS_FILE, ['updated_at' => null, 'rows' => []]);
    $rows = [];
    foreach (($data['rows'] ?? []) as $r) {
        $id = substr(trim((string)($r['id'] ?? '')), 0, 120);
        if ($id === '') continue;
        $rows[] = [
            'id' => $id,
            'name' => substr(trim((string)($r['name'] ?? $id)), 0, 200),
            'is_group' => !empty($r['is_group']) ? 1 : 0,
            'is_contact' => !empty($r['is_contact']) ? 1 : 0,
        ];
    }
    usort($rows, static function ($a, $b) {
        if ((int)$a['is_group'] !== (int)$b['is_group']) return (int)$b['is_group'] <=> (int)$a['is_group'];
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    echo json_encode(['status' => 'success', 'updated_at' => $data['updated_at'] ?? null, 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_products') {
    $q = trim((string)($_GET['q'] ?? ''));
    $lim = max(1, min(30, (int)($_GET['limit'] ?? 20)));
    $like = '%' . $q . '%';
    $sql = "SELECT codigo,nombre,precio
            FROM productos
            WHERE activo=1 AND id_empresa=?
              AND (nombre LIKE ? OR codigo LIKE ?)
            ORDER BY nombre ASC
            LIMIT {$lim}";
    $st = $pdo->prepare($sql);
    $st->execute([(int)$config['id_empresa'], $like, $like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    $base = bot_public_base_url();
    foreach ($rows as $r) {
        $sku = (string)$r['codigo'];
        $img = $base . "/image.php?code=" . rawurlencode($sku);
        $out[] = [
            'id' => $sku,
            'name' => (string)$r['nombre'],
            'price' => (float)$r['precio'],
            'image' => $img
        ];
    }
    echo json_encode(['status'=>'success','rows'=>$out]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_templates') {
    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $text = trim((string)($in['text'] ?? ''));
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($name === '') { echo json_encode(['status' => 'error', 'msg' => 'Nombre de plantilla obligatorio']); exit; }
    if ($text === '' && count($products) === 0) { echo json_encode(['status' => 'error', 'msg' => 'Plantilla vacía']); exit; }

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'image' => trim((string)($p['image'] ?? ''))
        ];
    }

    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $nowIso = date('c');
    if ($templateId === '') $templateId = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

    $updated = false;
    foreach ($rows as &$row) {
        if ((string)($row['id'] ?? '') !== $templateId) continue;
        $row['name'] = $name;
        $row['text'] = $text;
        $row['products'] = $cleanProducts;
        $row['updated_at'] = $nowIso;
        $updated = true;
        break;
    }
    unset($row);
    if (!$updated) {
        $rows[] = [
            'id' => $templateId,
            'name' => $name,
            'text' => $text,
            'products' => $cleanProducts,
            'updated_at' => $nowIso,
            'created_by' => $_SESSION['admin_name'] ?? 'admin'
        ];
    }
    if (!bot_write_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar plantilla']); exit;
    }
    echo json_encode(['status' => 'success', 'id' => $templateId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($templateId === '') { echo json_encode(['status' => 'error', 'msg' => 'id requerido']); exit; }
    $data = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
    $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn($r) => (string)($r['id'] ?? '') !== $templateId));
    if (!bot_write_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo borrar plantilla']); exit;
    }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_list') {
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = array_slice(array_reverse($queue['jobs'] ?? []), 0, 25);
    echo json_encode(['status' => 'success', 'rows' => $jobs]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_detail') {
    $jobId = substr(trim((string)($_GET['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    foreach ($jobs as $job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        echo json_encode(['status' => 'success', 'row' => $job]); exit;
    }
    echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_force_now') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;
    $now = time();
    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;
        $wasDone = (($job['status'] ?? '') === 'done');
        $reachedEnd = ((int)($job['current_index'] ?? 0) >= count((array)($job['targets'] ?? [])));
        $job['status'] = 'queued';
        $job['next_run_at'] = $now;
        $job['forced_at'] = date('c', $now);
        $job['last_schedule_key'] = '';
        if ($wasDone || $reachedEnd) {
            $job['current_index'] = 0;
        }
        if (!is_array($job['log'] ?? null)) $job['log'] = [];
        $job['log'][] = [
            'at' => date('c', $now),
            'type' => 'forced_start',
            'ok' => true,
            'target_id' => '',
            'target_name' => '',
            'messages_sent' => 0,
            'error' => ''
        ];
        break;
    }
    unset($job);
    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo forzar la campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_update') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }

    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;

    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;

        if (array_key_exists('name', $in)) {
            $job['name'] = substr(trim((string)$in['name']), 0, 120);
        }
        if (array_key_exists('campaign_group', $in)) {
            $job['campaign_group'] = substr(trim((string)$in['campaign_group']), 0, 80);
            if ($job['campaign_group'] === '') $job['campaign_group'] = 'General';
        }
        if (array_key_exists('schedule_enabled', $in)) {
            $job['schedule_enabled'] = !empty($in['schedule_enabled']) ? 1 : 0;
        }
        if (array_key_exists('schedule_time', $in)) {
            $tm = substr(trim((string)$in['schedule_time']), 0, 5);
            if ($tm !== '' && !preg_match('/^\d{2}:\d{2}$/', $tm)) {
                echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
            }
            $job['schedule_time'] = $tm;
        }
        if (array_key_exists('schedule_days', $in)) {
            $daysIn = is_array($in['schedule_days']) ? $in['schedule_days'] : [];
            $days = [];
            foreach ($daysIn as $d) {
                $n = (int)$d;
                if ($n >= 0 && $n <= 6) $days[$n] = $n;
            }
            $days = array_values($days);
            sort($days);
            $job['schedule_days'] = $days;
        }
        if (array_key_exists('status', $in)) {
            $status = strtolower(trim((string)$in['status']));
            if (in_array($status, ['scheduled','queued','running','paused','done','error'], true)) {
                $job['status'] = $status;
            }
        }

        if (!array_key_exists('next_run_at', $job) || !is_numeric($job['next_run_at'])) {
            $job['next_run_at'] = time() + 20;
        }
        if ((int)($job['schedule_enabled'] ?? 0) === 1 && ($job['status'] ?? '') === 'done') {
            $job['status'] = 'scheduled';
            $job['current_index'] = 0;
            $job['next_run_at'] = time() + 20;
        }
        break;
    }
    unset($job);

    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo actualizar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $before = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['id'] ?? '') !== $jobId));
    if ($before === count($jobs)) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo eliminar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_create') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($in['text'] ?? ''));
    $targets = is_array($in['targets'] ?? null) ? $in['targets'] : [];
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $minSec = max(60, min(180, (int)($in['min_seconds'] ?? 60)));
    $maxSec = max($minSec, min(300, (int)($in['max_seconds'] ?? 120)));
    $campaignName = substr(trim((string)($in['campaign_name'] ?? '')), 0, 120);
    $campaignGroup = substr(trim((string)($in['campaign_group'] ?? 'General')), 0, 80);
    $scheduleTime = substr(trim((string)($in['schedule_time'] ?? '')), 0, 5);
    $scheduleDays = is_array($in['schedule_days'] ?? null) ? $in['schedule_days'] : [];
    $templateId = substr(trim((string)($in['template_id'] ?? '')), 0, 80);
    $scheduleEnabled = !empty($in['schedule_enabled']) ? 1 : 0;

    if ($templateId !== '' && ($text === '' || count($products) === 0)) {
        $tplData = bot_read_json_file($BOT_PROMO_TEMPLATES_FILE, ['rows' => []]);
        foreach (($tplData['rows'] ?? []) as $tpl) {
            if ((string)($tpl['id'] ?? '') !== $templateId) continue;
            if ($text === '') $text = trim((string)($tpl['text'] ?? ''));
            if (count($products) === 0 && is_array($tpl['products'] ?? null)) $products = $tpl['products'];
            if ($campaignName === '') $campaignName = substr(trim((string)($tpl['name'] ?? '')), 0, 120);
            break;
        }
    }

    $cleanTargetsMap = [];
    foreach ($targets as $t) {
        $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
        if ($id === '') continue;
        $name = substr(trim((string)($t['name'] ?? $id)), 0, 200);
        $cleanTargetsMap[$id] = $name !== '' ? $name : $id;
    }
    $targetsFinal = [];
    foreach ($cleanTargetsMap as $id => $name) $targetsFinal[] = ['id' => $id, 'name' => $name];

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'image' => trim((string)($p['image'] ?? ('image.php?code=' . rawurlencode($pid))))
        ];
    }

    if ($text === '') { echo json_encode(['status'=>'error','msg'=>'Texto obligatorio']); exit; }
    if (count($targetsFinal) === 0) { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un grupo/chat']); exit; }
    if (count($cleanProducts) === 0) { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un producto']); exit; }

    $daysFinal = [];
    foreach ($scheduleDays as $d) {
        $n = (int)$d;
        if ($n >= 0 && $n <= 6) $daysFinal[$n] = $n;
    }
    $daysFinal = array_values($daysFinal);
    sort($daysFinal);
    if ($scheduleEnabled) {
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
            echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
        }
        if (count($daysFinal) === 0) {
            echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un día']); exit;
        }
    } else {
        $scheduleTime = '';
        $daysFinal = [];
    }

    $queue = bot_read_json_file($BOT_PROMO_QUEUE_FILE, ['jobs' => []]);
    $jobId = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $now = time();
    $queue['jobs'][] = [
        'id' => $jobId,
        'status' => $scheduleEnabled ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => $campaignName !== '' ? $campaignName : ('Campaña ' . date('d/m H:i')),
        'campaign_group' => $campaignGroup !== '' ? $campaignGroup : 'General',
        'template_id' => $templateId,
        'text' => $text,
        'targets' => $targetsFinal,
        'products' => $cleanProducts,
        'min_seconds' => $minSec,
        'max_seconds' => $maxSec,
        'schedule_enabled' => $scheduleEnabled,
        'schedule_time' => $scheduleTime,
        'schedule_days' => $daysFinal,
        'last_schedule_key' => '',
        'next_run_at' => $scheduleEnabled ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => []
    ];
    if (!bot_write_json_file($BOT_PROMO_QUEUE_FILE, $queue)) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo guardar cola de promoción']); exit;
    }
    echo json_encode(['status'=>'success','id'=>$jobId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='test_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $wa = preg_replace('/\D+/', '', (string)($in['wa_user_id'] ?? '5350000000'));
    $name = trim((string)($in['wa_name'] ?? 'Cliente Test'));
    $text = trim((string)($in['text'] ?? 'MENU'));
    bot_log($pdo, $wa, 'in', $text);
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='web_incoming') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $provided = (string)($in['verify_token'] ?? '');
    $expected = (string)($cfg['verify_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','msg'=>'invalid token']); exit;
    }
    if (empty($cfg['enabled'])) {
        echo json_encode(['status'=>'ok','msg'=>'bot disabled','responses'=>[]]); exit;
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
    bot_handle_text($pdo, $cfg, $config, $wa, $name, $text);
    echo json_encode(['status'=>'success','responses'=>bot_take_outbox()]); exit;
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
