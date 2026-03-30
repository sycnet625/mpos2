<?php
function bot_build_conversation_rows(PDO $pdo): array {
    $rows = $pdo->query("SELECT wa_user_id, wa_name, cart_json, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
    $latestMsgSt = $pdo->prepare("SELECT direction, msg_type, message_text, created_at FROM pos_bot_messages WHERE wa_user_id=? ORDER BY id DESC LIMIT 1");
    $out = [];
    foreach ($rows as $row) {
        $cart = json_decode((string)($row['cart_json'] ?? ''), true);
        if (!is_array($cart)) $cart = [];
        $cart = bot_merge_cart_shape($cart, (string)($row['wa_name'] ?? ''));
        $latestMsgSt->execute([(string)$row['wa_user_id']]);
        $lastMsg = $latestMsgSt->fetch(PDO::FETCH_ASSOC) ?: [];
        $itemsCount = 0;
        foreach ((array)$cart['items'] as $qty) $itemsCount += max(0, (int)$qty);
        $out[] = [
            'wa_user_id' => (string)$row['wa_user_id'],
            'wa_name' => (string)($cart['customer_name'] ?: ($row['wa_name'] ?? '')),
            'customer_address' => (string)($cart['customer_address'] ?? ''),
            'items_count' => $itemsCount,
            'awaiting_field' => (string)($cart['awaiting_field'] ?? ''),
            'bot_paused' => !empty($cart['bot_paused']) ? 1 : 0,
            'escalation_active' => !empty($cart['escalation_active']) ? 1 : 0,
            'escalation_reason' => (string)($cart['escalation_reason'] ?? ''),
            'escalation_label' => (string)($cart['escalation_label'] ?? ''),
            'escalation_at' => (string)($cart['escalation_at'] ?? ''),
            'last_seen' => (string)($row['last_seen'] ?? ''),
            'last_message_text' => (string)($lastMsg['message_text'] ?? ''),
            'last_message_dir' => (string)($lastMsg['direction'] ?? ''),
            'last_message_at' => (string)($lastMsg['created_at'] ?? ''),
            'last_cart_activity_at' => (string)($cart['last_cart_activity_at'] ?? ''),
            'last_cart_reminder_at' => (string)($cart['last_cart_reminder_at'] ?? ''),
            'last_order' => is_array($cart['last_order'] ?? null) ? $cart['last_order'] : null
        ];
    }
    return $out;
}

function bot_conversation_update(PDO $pdo, string $wa, callable $mutator): array {
    $st = $pdo->prepare("SELECT wa_name, cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $name = (string)($row['wa_name'] ?? '');
    $cart = is_array(json_decode((string)($row['cart_json'] ?? ''), true)) ? json_decode((string)($row['cart_json'] ?? ''), true) : [];
    $cart = bot_merge_cart_shape($cart, $name);
    $cart = $mutator($cart) ?: $cart;
    if ($row) {
        bot_save_cart($pdo, $wa, $name, $cart);
    } else {
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
    }
    return $cart;
}

function bot_enqueue_cart_recovery_jobs(PDO $pdo, array $cfg, array $appCfg): int {
    $replyState = bot_autoreply_state($cfg);
    if (empty($replyState['effective_enabled'])) {
        return 0;
    }
    $rows = $pdo->query("SELECT wa_user_id, wa_name, cart_json, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
    $enqueued = 0;
    $now = time();
    $tz = new DateTimeZone('America/Havana');
    $todayKey = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    foreach ($rows as $row) {
        $cart = json_decode((string)($row['cart_json'] ?? ''), true);
        if (!is_array($cart)) continue;
        $cart = bot_merge_cart_shape($cart, (string)($row['wa_name'] ?? ''));
        if (!empty($cart['bot_paused'])) continue;
        if (empty($cart['items'])) continue;
        $lastSeenTs = strtotime((string)($row['last_seen'] ?? '')) ?: 0;
        $lastActivityTs = strtotime((string)($cart['last_cart_activity_at'] ?? '')) ?: $lastSeenTs;
        if ($lastActivityTs <= 0 || ($now - $lastActivityTs) < 20 * 60) continue;
        $lastReminderTs = strtotime((string)($cart['last_cart_reminder_at'] ?? '')) ?: 0;
        if ($lastReminderTs > 0 && ($now - $lastReminderTs) < 10 * 3600) continue;
        $firstDayKey = trim((string)($cart['cart_recovery_first_day'] ?? ''));
        $dayKey = trim((string)($cart['cart_recovery_day_key'] ?? ''));
        $dayCount = max(0, (int)($cart['cart_recovery_day_count'] ?? 0));
        if ($firstDayKey !== '') {
            try {
                $firstDay = new DateTimeImmutable($firstDayKey . ' 00:00:00', $tz);
                $todayDay = new DateTimeImmutable($todayKey . ' 00:00:00', $tz);
                if ((int)$firstDay->diff($todayDay)->format('%a') >= 3) continue;
            } catch (Throwable $_) {}
        }
        if ($dayKey !== $todayKey) {
            $dayKey = $todayKey;
            $dayCount = 0;
        }
        if ($dayCount >= 2) continue;
        $name = bot_customer_display_name($cart, (string)($row['wa_name'] ?? 'Cliente'));
        $text = bot_pick([
            "Hola {$name}, dejaste un pedido a medias. Si quieres, te ayudo a terminarlo ahora mismo o puedes cancelar la compra.",
            "{$name}, tu carrito sigue guardado. Puedes retomarlo cuando quieras o cancelar la compra si ya no lo deseas.",
            "Seguimos teniendo guardado tu carrito, {$name}. Si quieres, lo cerramos enseguida o cancelas la compra con un toque."
        ], $row['wa_user_id'] . date('YmdH'));
        $text .= "\n" . bot_site_promo($cfg, $appCfg, $row['wa_user_id'] . 'recovery');
        bot_enqueue_bridge_job([
            'target_id' => (string)$row['wa_user_id'],
            'type' => 'text',
            'text' => $text,
            'job_kind' => 'cart_recovery'
        ]);
        bot_enqueue_bridge_job([
            'target_id' => (string)$row['wa_user_id'],
            'type' => 'buttons',
            'text' => 'Retoma tu compra con un toque:',
            'buttons' => ['Carrito', 'Confirmar', 'Cancelar compra'],
            'title' => 'Carrito pendiente',
            'footer' => preg_replace('~^https?://~i', '', bot_site_url($appCfg)),
            'job_kind' => 'cart_recovery'
        ]);
        $cart['last_cart_reminder_at'] = date('c', $now);
        if ($firstDayKey === '') $cart['cart_recovery_first_day'] = $todayKey;
        $cart['cart_recovery_day_key'] = $todayKey;
        $cart['cart_recovery_day_count'] = $dayCount + 1;
        bot_save_cart($pdo, (string)$row['wa_user_id'], (string)($row['wa_name'] ?? ''), $cart);
        $enqueued++;
    }
    return $enqueued;
}

function bot_add_items_to_cart(array &$cart, array $items): array {
    $added = [];
    foreach ($items as $it) {
        $sku = (string)($it['sku'] ?? '');
        $qty = max(1, (int)($it['qty'] ?? 1));
        $name = (string)($it['name'] ?? $sku);
        $note = trim((string)($it['note'] ?? ''));
        if ($sku === '') continue;
        $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + $qty;
        if ($note !== '') $cart['item_notes'][$sku] = $note;
        $added[] = ['sku' => $sku, 'qty' => $qty, 'name' => $name, 'note' => $note];
    }
    return $added;
}

function bot_pending_choice_prompt(array $pending): string {
    $options = is_array($pending['options'] ?? null) ? $pending['options'] : [];
    if (!$options) return 'Necesito que me digas mejor cual producto quieres.';
    $lines = ["Quiero asegurarme de entenderte bien. ¿Cual de estos productos quieres?"];
    foreach ($options as $i => $op) {
        $lines[] = ($i + 1) . ". {$op['name']} ({$op['sku']})";
    }
    $lines[] = 'Respóndeme con el número o el nombre exacto.';
    return implode("\n", $lines);
}

function bot_try_resolve_pending_choice(array $menu, array &$cart, string $msg): ?array {
    $pending = is_array($cart['pending_choice'] ?? null) ? $cart['pending_choice'] : null;
    if (!$pending) return null;
    $norm = bot_norm($msg);
    if (bot_intent_has($norm, ['cancelar','ninguno','olvida','dejalo'])) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $options = is_array($pending['options'] ?? null) ? $pending['options'] : [];
    if (!$options) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $pick = null;
    if (preg_match('/^\s*([1-3])\s*$/', $msg, $m)) {
        $idx = max(0, ((int)$m[1]) - 1);
        $pick = $options[$idx] ?? null;
    }
    if (!$pick) {
        foreach ($options as $op) {
            $skuNorm = bot_norm((string)$op['sku']);
            $nameNorm = bot_norm((string)$op['name']);
            if ($skuNorm !== '' && str_contains($norm, $skuNorm)) { $pick = $op; break; }
            if ($nameNorm !== '' && (str_contains($norm, $nameNorm) || bot_similarity_score($norm, $nameNorm) >= 70)) { $pick = $op; break; }
        }
    }
    if (!$pick) return ['status' => 'retry'];
    $product = null;
    foreach ($menu as $p) {
        if ((string)($p['codigo'] ?? '') === (string)$pick['sku']) { $product = $p; break; }
    }
    if (!$product) {
        $cart['pending_choice'] = null;
        return ['status' => 'cancelled'];
    }
    $added = bot_add_items_to_cart($cart, [[
        'sku' => (string)$product['codigo'],
        'qty' => max(1, (int)($pending['qty'] ?? 1)),
        'name' => (string)$product['nombre'],
        'note' => trim((string)($pending['note'] ?? ''))
    ]]);
    bot_mark_cart_activity($cart);
    $cart['pending_choice'] = null;
    return ['status' => 'resolved', 'added' => $added];
}

function bot_handle_text(PDO $pdo, array $cfg, array $appCfg, string $wa, string $name, string $text): void {
    $liveCfg = bot_cfg($pdo);
    $liveReplyState = bot_autoreply_state($liveCfg);
    if (empty($liveReplyState['effective_enabled'])) {
        return;
    }
    if (!empty($liveCfg)) {
        $cfg = array_merge($cfg, $liveCfg);
    }

    $msg = trim($text);
    if ($msg === '') return;
    $norm = bot_norm($msg);
    $cart = bot_get_cart($pdo, $wa, $name);
    $menu = bot_menu($pdo, (int)$appCfg['id_empresa'], (int)$appCfg['id_sucursal']);
    if (($cart['customer_name'] ?? '') === '' && $name !== '') $cart['customer_name'] = $name;
    $isGreeting = bot_intent_has($norm, ['hola','buenas','buen dia','buenas tardes','buenas noches','start','iniciar','hello','saludos']);
    $isMenuReq = bot_intent_has($norm, ['menu','menú','catalogo','catálogo','carta','productos','que tienes','que venden','muestrame']);
    $isCartReq = bot_intent_has($norm, ['carrito','mi pedido','resumen','que llevo']);
    $isCancel = bot_intent_has($norm, ['cancelar','vaciar carrito','borrar carrito','anular pedido']);
    $isConfirm = bot_intent_has($norm, ['confirmar','ordenar','pedir','finalizar','checkout','cerrar pedido']);
    $isAdd = bot_intent_has($norm, ['agregar','añadir','anadir','sumar','quiero','dame','deseo','me das','ponme','mandame','preparame','me pones']);
    $isRemove = bot_intent_has($norm, ['quitar','eliminar','sacar','remover']);
    $isRepeat = bot_intent_has($norm, ['lo mismo','lo de siempre','repite el ultimo','repite mi ultimo','igual que la vez pasada','igual que siempre','repetir pedido']);
    $isWebBuyReq = bot_intent_has($norm, ['comprar en web','comprar web','tienda web','catalogo web','catálogo web','comprar online']);
    $looksLikeName = bot_intent_has($norm, ['mi nombre es','soy','me llamo','nombre']);
    $looksLikeAddress = bot_intent_has($norm, ['direccion','dirección','mi direccion es','vivo en','entrega en','dir']);
    $fulfillmentDetected = bot_detect_fulfillment_mode($norm);
    $scheduleDetected = bot_extract_schedule_datetime($msg);
    $escalationDetected = bot_detect_escalation($norm);

    if ($escalationDetected) {
        bot_mark_escalation($cart, $escalationDetected);
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, "Entendido. Ya pasé tu caso a atención humana por: {$escalationDetected['label']}.\nUn operador revisará tu chat lo antes posible.");
        return;
    }

    if (!empty($cart['bot_paused'])) {
        bot_save_cart($pdo, $wa, $name, $cart);
        return;
    }

    $faq = ((string)($cart['awaiting_field'] ?? '') === '') ? bot_faq_answer($norm, $cfg, $appCfg) : null;
    if ($faq && !$isAdd && !$isConfirm && !$isCartReq) {
        bot_send($pdo, $cfg, $wa, $faq);
        return;
    }

    $pendingResolved = bot_try_resolve_pending_choice($menu, $cart, $msg);
    if ($pendingResolved) {
        if (($pendingResolved['status'] ?? '') === 'resolved') {
            bot_save_cart($pdo, $wa, $name, $cart);
            $addedLines = array_map(static function ($it) {
                $line = "{$it['name']} x{$it['qty']}";
                if (($it['note'] ?? '') !== '') $line .= " [{$it['note']}]";
                return $line;
            }, $pendingResolved['added'] ?? []);
            bot_send($pdo, $cfg, $wa, "Perfecto, ya lo entendí.\nAgregué:\n- " . implode("\n- ", $addedLines) . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'resolved'));
            return;
        }
        if (($pendingResolved['status'] ?? '') === 'retry') {
            bot_send($pdo, $cfg, $wa, bot_pending_choice_prompt($cart['pending_choice'] ?? []));
            return;
        }
        if (($pendingResolved['status'] ?? '') === 'cancelled') {
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Perfecto, descarto esa selección. Si quieres, dime el producto otra vez con otras palabras.');
            return;
        }
    }

    if (($cart['awaiting_field'] ?? '') === 'name' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            $cart['awaiting_field'] = 'fulfillment';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, $candidateName, (string)($cart['customer_address'] ?? ''), $cart, 'Nombre actualizado desde conversación.');
            bot_send($pdo, $cfg, $wa, "Gracias, {$candidateName}. Ahora voy a preparar tu reserva.");
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        bot_send($pdo, $cfg, $wa, 'Para continuar necesito tu nombre completo. Ejemplo: Mi nombre es Juan Perez.');
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'fulfillment' && !$isMenuReq && !$isCartReq && !$isCancel) {
        if ($fulfillmentDetected === '') {
            bot_send($pdo, $cfg, $wa, 'Necesito que me digas si prefieres recoger en tienda o mensajería.');
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        $cart['fulfillment_mode'] = $fulfillmentDetected;
        $cart['awaiting_field'] = '';
        if ($fulfillmentDetected === 'pickup') {
            $cart['address_validation'] = null;
            $cart['delivery_distance_km'] = 0.0;
            $cart['delivery_fee'] = 0.0;
            $cart['requested_datetime'] = '';
            $cart['requested_datetime_label'] = '';
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Perfecto, será para recoger en tienda.');
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt('pickup'));
            return;
        }
        if (trim((string)($cart['customer_address'] ?? '')) !== '') {
            $validation = bot_validate_habana_address((string)$cart['customer_address']);
            if (!empty($validation['ok'])) {
                $cart['address_validation'] = $validation;
                $cart['delivery_distance_km'] = (float)$validation['distance_km'];
                $cart['delivery_fee'] = (float)$validation['delivery_fee'];
                $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
                $cart['requested_datetime'] = '';
                $cart['requested_datetime_label'] = '';
                $cart['awaiting_field'] = 'datetime';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, "Perfecto, será por mensajería.\nDirección validada en {$validation['municipio']} / {$validation['barrio']}.\nDistancia estimada: " . number_format((float)$validation['distance_km'], 2) . " km.\nCosto de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . '.');
                bot_send($pdo, $cfg, $wa, bot_datetime_prompt('delivery'));
                return;
            }
        }
        $cart['awaiting_field'] = 'address';
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, 'Perfecto, será por mensajería.');
        bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'address' && !$isMenuReq && !$isCartReq && !$isCancel) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            $validation = bot_validate_habana_address($candidateAddress);
            if (empty($validation['ok'])) {
                $cart['address_validation'] = null;
                $cart['delivery_distance_km'] = 0.0;
                $cart['delivery_fee'] = 0.0;
                $cart['awaiting_field'] = 'address';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, ($validation['msg'] ?? 'No pude validar esa dirección.') . "\n" . bot_delivery_address_prompt());
                return;
            }
            $cart['address_validation'] = $validation;
            $cart['delivery_distance_km'] = (float)$validation['distance_km'];
            $cart['delivery_fee'] = (float)$validation['delivery_fee'];
            $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), $candidateAddress, $cart, 'Dirección validada para reserva por WhatsApp.');
            bot_send($pdo, $cfg, $wa, "Perfecto. Validé tu dirección en {$validation['municipio']} / {$validation['barrio']}.\nDistancia estimada: " . number_format((float)$validation['distance_km'], 2) . " km.\nCosto de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . ".\n" . bot_datetime_prompt('delivery'));
            return;
        }
        bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
        return;
    }

    if (($cart['awaiting_field'] ?? '') === 'datetime' && !$isMenuReq && !$isCartReq && !$isCancel) {
        if (!$scheduleDetected) {
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt((string)($cart['fulfillment_mode'] ?? 'pickup')));
            return;
        }
        $cart['requested_datetime'] = (string)$scheduleDetected['value'];
        $cart['requested_datetime_label'] = (string)$scheduleDetected['label'];
        $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        $res = bot_create_reserva($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = bot_default_cart((string)$cart['customer_name']);
            $cartAfter['customer_address'] = (string)$cart['customer_address'];
            $cartAfter['fulfillment_mode'] = (string)($cart['fulfillment_mode'] ?? '');
            $cartAfter['address_validation'] = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : null;
            $cartAfter['delivery_fee'] = (float)($cart['delivery_fee'] ?? 0);
            $cartAfter['delivery_distance_km'] = (float)($cart['delivery_distance_km'] ?? 0);
            $cartAfter['requested_datetime'] = (string)$scheduleDetected['value'];
            $cartAfter['requested_datetime_label'] = (string)$scheduleDetected['label'];
            $cartAfter['greet_count'] = (int)($cart['greet_count'] ?? 0);
            $cartAfter['last_order'] = is_array($res['order_snapshot'] ?? null) ? $res['order_snapshot'] : null;
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            bot_sync_crm_client($pdo, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cartAfter, 'Reserva confirmada desde WhatsApp Bot.');
            bot_send($pdo, $cfg, $wa, "Perfecto, {$cart['customer_name']}. Ya quedó creada tu reserva #{$res['id_reserva']}.\nFecha y hora: {$scheduleDetected['label']}.\n" . bot_delivery_summary_line($cart) . "\nTotal: $" . number_format((float)$res['total'], 2) . "\nTicket: {$res['ticket_url']}\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'reserva'));
        } else {
            bot_send($pdo, $cfg, $wa, "No pude crear la reserva: {$res['msg']}");
        }
        return;
    }

    if ($looksLikeName) {
        $candidateName = bot_extract_name($msg);
        if ($candidateName !== '') {
            $cart['customer_name'] = $candidateName;
            if (($cart['awaiting_field'] ?? '') === 'name') $cart['awaiting_field'] = '';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, $candidateName, (string)($cart['customer_address'] ?? ''), $cart, 'Nombre confirmado desde conversación.');
            bot_send($pdo, $cfg, $wa, "Perfecto, {$candidateName}. Ya guardé tu nombre.");
            return;
        }
    }

    if ($looksLikeAddress) {
        $candidateAddress = bot_extract_address($msg);
        if ($candidateAddress !== '') {
            $cart['customer_address'] = $candidateAddress;
            if (($cart['awaiting_field'] ?? '') === 'address') $cart['awaiting_field'] = '';
            if (($cart['fulfillment_mode'] ?? '') === 'delivery') {
                $validation = bot_validate_habana_address($candidateAddress);
                if (!empty($validation['ok'])) {
                    $cart['address_validation'] = $validation;
                    $cart['delivery_distance_km'] = (float)$validation['distance_km'];
                    $cart['delivery_fee'] = (float)$validation['delivery_fee'];
                    $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
                } else {
                    $cart['address_validation'] = null;
                    $cart['delivery_distance_km'] = 0.0;
                    $cart['delivery_fee'] = 0.0;
                }
            }
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), $candidateAddress, $cart, 'Dirección confirmada desde conversación.');
            if (($cart['fulfillment_mode'] ?? '') === 'delivery' && !empty($cart['address_validation']['ok'])) {
                $validation = $cart['address_validation'];
                bot_send($pdo, $cfg, $wa, "Direccion guardada y validada en {$validation['municipio']} / {$validation['barrio']}.\nCosto estimado de mensajería: $" . number_format((float)$validation['delivery_fee'], 2) . '.');
            } else {
                bot_send($pdo, $cfg, $wa, "Direccion guardada: {$candidateAddress}.");
            }
            return;
        }
    }

    if ($fulfillmentDetected !== '') {
        $cart['fulfillment_mode'] = $fulfillmentDetected;
        if ($fulfillmentDetected === 'pickup') {
            $cart['address_validation'] = null;
            $cart['delivery_distance_km'] = 0.0;
            $cart['delivery_fee'] = 0.0;
        }
        if (($cart['awaiting_field'] ?? '') === 'fulfillment') $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        if ($fulfillmentDetected === 'pickup') {
            bot_send($pdo, $cfg, $wa, 'Perfecto, te dejo el pedido como recogida en tienda.');
        } else {
            bot_send($pdo, $cfg, $wa, 'Perfecto, te lo dejo por mensajería. Cuando quieras, envíame la dirección completa en La Habana.');
        }
        return;
    }

    if ($scheduleDetected && !empty($cart['items'])) {
        $cart['requested_datetime'] = (string)$scheduleDetected['value'];
        $cart['requested_datetime_label'] = (string)$scheduleDetected['label'];
        if (($cart['awaiting_field'] ?? '') === 'datetime') $cart['awaiting_field'] = '';
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, (($cart['fulfillment_mode'] ?? '') === 'delivery' ? 'Entrega' : 'Recogida') . " programada para {$scheduleDetected['label']}.");
        return;
    }

    if (
        $isGreeting ||
        (
            ($cart['greet_count'] ?? 0) === 0 &&
            empty($cart['items']) &&
            trim((string)($cart['customer_address'] ?? '')) === '' &&
            !$isAdd && !$isConfirm && !$isCartReq
        )
    ) {
        $cart['greet_count'] = ((int)($cart['greet_count'] ?? 0)) + 1;
        bot_save_cart($pdo, $wa, $name, $cart);
        $greetingText = bot_greeting_text($cfg, $appCfg, $cart, $name);
        bot_send($pdo, $cfg, $wa, $greetingText);
        $logo = bot_business_logo_url($appCfg);
        if ($logo !== '') bot_send_image($pdo, $wa, $logo, "Compra fácil por chat o en " . bot_site_url($appCfg));
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isMenuReq) {
        $menuVisible = array_values(array_filter($menu, static fn($p) => bot_product_in_stock($p, 1)));
        if (!$menuVisible) $menuVisible = $menu;
        $lines = [bot_greeting_text($cfg, $appCfg, $cart, $name), '', (string)($cfg['menu_intro'] ?? 'Menu:')];
        foreach (array_slice($menuVisible, 0, 40) as $p) {
            $suffix = !empty($p['es_servicio']) ? '' : (' · stock ' . max(0, (int)($p['stock_total'] ?? 0)));
            $lines[] = "{$p['codigo']} - {$p['nombre']} - $" . number_format((float)$p['precio'],2) . $suffix;
        }
        $lines[] = '';
        $lines[] = 'Puedes pedirme algo natural, por ejemplo: "quiero 2 croquetas y 1 refresco sin hielo".';
        $lines[] = 'Tambien puedo repetir tu ultimo pedido si me dices: "lo de siempre".';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'menu');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        bot_send_catalog_showcase($pdo, $wa, $appCfg, $menuVisible, 'Aquí tienes una vista rápida del catálogo.');
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'menu');
        return;
    }

    if ($isWebBuyReq) {
        bot_send($pdo, $cfg, $wa, "Compra directa: " . bot_site_url($appCfg) . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'webbuy'));
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isRepeat) {
        $repeated = bot_repeat_last_order($cart);
        if ($repeated) {
            bot_mark_cart_activity($cart);
            bot_save_cart($pdo, $wa, $name, $cart);
            $summary = bot_cart_text($pdo, $cart);
            bot_send($pdo, $cfg, $wa, "Te repetí el ultimo pedido que tenías guardado.\n\n" . $summary['text'] . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'repeat'));
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        } else {
            bot_send($pdo, $cfg, $wa, "Todavia no tengo un pedido anterior guardado para repetir. Si quieres, te muestro el menu o puedes pedirme algo natural.");
        }
        return;
    }

    if ($isAdd || str_starts_with(mb_strtoupper($msg), 'AGREGAR ')) {
        $parsed = bot_parse_add_request($menu, $msg);
        if (!empty($parsed['ambiguous'])) {
            $cart['pending_choice'] = $parsed['ambiguous'][0];
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_pending_choice_prompt($cart['pending_choice']));
            return;
        }
        if (empty($parsed['items'])) {
            $extra = !empty($parsed['unknown']) ? ("\nNo reconocí bien: " . implode(', ', $parsed['unknown']) . '.') : '';
            bot_send($pdo, $cfg, $wa, 'No logré identificar bien los productos.' . $extra . "\nPrueba algo como: \"quiero 2 empanadas y 1 refresco\" o pideme el menu.");
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
            return;
        }
        $catalogBySku = [];
        foreach ($menu as $p) $catalogBySku[(string)$p['codigo']] = $p;
        $availableItems = [];
        $unavailable = [];
        foreach ($parsed['items'] as $it) {
            $sku = (string)($it['sku'] ?? '');
            $product = $catalogBySku[$sku] ?? null;
            if (!$product) continue;
            $qty = max(1, (int)($it['qty'] ?? 1));
            if (!bot_product_in_stock($product, $qty)) {
                if (bot_product_is_reservable($product)) {
                    $it['reserved_warning'] = true;
                    $availableItems[] = $it;
                    continue;
                }
                $unavailable[] = ['product' => $product, 'qty' => $qty];
                continue;
            }
            $availableItems[] = $it;
        }
        $added = bot_add_items_to_cart($cart, $availableItems);
        if (!$added && $unavailable) {
            $lines = ['Ahora mismo no tengo existencia suficiente de ese producto.'];
            foreach ($unavailable as $row) {
                $product = $row['product'];
                $subs = bot_find_substitutes($menu, $product, 3);
                $lines[] = "- {$product['nombre']} no disponible.";
                if ($subs) {
                    $lines[] = 'Sustituciones: ' . implode(' | ', array_map(static fn($p) => "{$p['nombre']} ({$p['codigo']})", $subs));
                }
            }
            $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'stock');
            bot_send($pdo, $cfg, $wa, implode("\n", $lines));
            bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
            return;
        }
        if ($added) bot_mark_cart_activity($cart);
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_sync_crm_client($pdo, $wa, (string)($cart['customer_name'] ?: $name), (string)($cart['customer_address'] ?? ''), $cart, 'Preferencias de compra actualizadas por actividad del carrito.');
        $lines = ['Perfecto, ya te lo agregué:'];
        foreach ($added as $it) {
            $line = "- {$it['name']} x{$it['qty']}";
            if ($it['note'] !== '') $line .= " [{$it['note']}]";
            $lines[] = $line;
            if (!empty($it['reserved_warning'])) {
                $lines[] = "  Aviso: {$it['name']} no tiene existencia para despacho inmediato. Se acepta como reserva para su próxima producción.";
            }
        }
        if ($unavailable) {
            foreach ($unavailable as $row) {
                $subs = bot_find_substitutes($menu, $row['product'], 3);
                $lines[] = "{$row['product']['nombre']} no tenía stock suficiente.";
                if ($subs) $lines[] = 'Te puedo sugerir: ' . implode(' | ', array_map(static fn($p) => "{$p['nombre']} ({$p['codigo']})", $subs));
            }
        }
        $suggest = bot_suggest_item($menu, $cart);
        if ($suggest) $lines[] = "Sugerencia: ¿quieres añadir {$suggest['nombre']} por $" . number_format((float)$suggest['precio'],2) . '?';
        $lines[] = bot_site_promo($cfg, $appCfg, $wa . 'add');
        bot_send($pdo, $cfg, $wa, implode("\n", $lines));
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isRemove || str_starts_with(mb_strtoupper($msg), 'QUITAR ')) {
        $sku = bot_parse_remove_item($menu, $msg);
        if (!$sku && preg_match('/^QUITAR\s+(\S+)/iu', $msg, $m)) $sku = (string)$m[1];
        if ($sku !== null && $sku !== '' && isset($cart['items'][$sku])) {
            unset($cart['items'][$sku], $cart['item_notes'][$sku]);
            bot_mark_cart_activity($cart);
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, "Listo, quité {$sku} de tu carrito.");
        } else {
            bot_send($pdo, $cfg, $wa, 'Ese producto no lo tengo ahora mismo en tu carrito. Si quieres, te muestro el resumen.');
        }
        return;
    }

    if ($isCartReq || mb_strtoupper($msg) === 'CARRITO') {
        $summary = bot_cart_text($pdo, $cart);
        bot_send($pdo, $cfg, $wa, $summary['text'] . "\n" . bot_site_promo($cfg, $appCfg, $wa . 'cart'));
        if (!empty($cart['items'])) bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isCancel || mb_strtoupper($msg) === 'CANCELAR') {
        $lastOrder = $cart['last_order'] ?? null;
        $greetCount = (int)($cart['greet_count'] ?? 0);
        $cart = bot_default_cart((string)($cart['customer_name'] ?? $name));
        if (($name !== '') && ($cart['customer_name'] ?? '') === '') $cart['customer_name'] = $name;
        $cart['last_order'] = is_array($lastOrder) ? $lastOrder : null;
        $cart['greet_count'] = $greetCount;
        bot_save_cart($pdo, $wa, $name, $cart);
        bot_send($pdo, $cfg, $wa, 'Listo, dejé el carrito vacío. Cuando quieras, empezamos de nuevo.');
        bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
        return;
    }

    if ($isConfirm || in_array(mb_strtoupper($msg), ['CONFIRMAR','ORDENAR','PEDIR'], true)) {
        if (empty($cart['items'])) {
            bot_send($pdo, $cfg, $wa, 'Tu carrito está vacío. Pídeme el menu o escríbeme lo que deseas comprar.');
            return;
        }
        if (trim((string)($cart['customer_name'] ?? '')) === '') {
            $cart['awaiting_field'] = 'name';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, 'Antes de confirmar, necesito tu nombre completo.');
            return;
        }
        if (!in_array((string)($cart['fulfillment_mode'] ?? ''), ['pickup', 'delivery'], true)) {
            $cart['awaiting_field'] = 'fulfillment';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send_fulfillment_prompt($pdo, $wa);
            return;
        }
        if (($cart['fulfillment_mode'] ?? '') === 'delivery' && trim((string)($cart['customer_address'] ?? '')) === '') {
            $cart['awaiting_field'] = 'address';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_delivery_address_prompt());
            return;
        }
        if (($cart['fulfillment_mode'] ?? '') === 'delivery' && empty($cart['address_validation']['ok'])) {
            $validation = bot_validate_habana_address((string)$cart['customer_address']);
            if (empty($validation['ok'])) {
                $cart['awaiting_field'] = 'address';
                bot_save_cart($pdo, $wa, $name, $cart);
                bot_send($pdo, $cfg, $wa, ($validation['msg'] ?? 'No pude validar la dirección.') . "\n" . bot_delivery_address_prompt());
                return;
            }
            $cart['address_validation'] = $validation;
            $cart['delivery_distance_km'] = (float)$validation['distance_km'];
            $cart['delivery_fee'] = (float)$validation['delivery_fee'];
            $cart['delivery_rate_per_km'] = (float)$validation['rate_per_km'];
            bot_save_cart($pdo, $wa, $name, $cart);
        }
        if (trim((string)($cart['requested_datetime'] ?? '')) === '') {
            $cart['awaiting_field'] = 'datetime';
            bot_save_cart($pdo, $wa, $name, $cart);
            bot_send($pdo, $cfg, $wa, bot_datetime_prompt((string)($cart['fulfillment_mode'] ?? 'pickup')));
            return;
        }
        $summary = bot_cart_text($pdo, $cart);
        $res = bot_create_reserva($pdo, $appCfg, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cart);
        if ($res['ok']) {
            $cartAfter = bot_default_cart((string)$cart['customer_name']);
            $cartAfter['customer_address'] = (string)$cart['customer_address'];
            $cartAfter['fulfillment_mode'] = (string)($cart['fulfillment_mode'] ?? '');
            $cartAfter['address_validation'] = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : null;
            $cartAfter['delivery_fee'] = (float)($cart['delivery_fee'] ?? 0);
            $cartAfter['delivery_distance_km'] = (float)($cart['delivery_distance_km'] ?? 0);
            $cartAfter['requested_datetime'] = (string)($cart['requested_datetime'] ?? '');
            $cartAfter['requested_datetime_label'] = (string)($cart['requested_datetime_label'] ?? '');
            $cartAfter['greet_count'] = (int)($cart['greet_count'] ?? 0);
            $cartAfter['last_order'] = is_array($res['order_snapshot'] ?? null) ? $res['order_snapshot'] : null;
            bot_save_cart($pdo, $wa, $name, $cartAfter);
            bot_sync_crm_client($pdo, $wa, (string)$cart['customer_name'], (string)$cart['customer_address'], $cartAfter, 'Reserva confirmada desde WhatsApp Bot.');
            $summaryText = preg_replace('/\nSi lo ves bien, escribe CONFIRMAR y lo cierro por ti\.\s*$/u', '', $summary['text']) ?? $summary['text'];
            bot_send($pdo, $cfg, $wa, "Perfecto, {$cart['customer_name']}. Ya quedó creada tu reserva #{$res['id_reserva']} por $" . number_format((float)$res['total'],2) . ".\n\nResumen final:\n" . $summaryText . "\n\nTicket: {$res['ticket_url']}\n" . bot_site_promo($cfg, $appCfg, $wa . 'confirm'));
        } else {
            bot_send($pdo, $cfg, $wa, "No pude crear la reserva: {$res['msg']}");
        }
        return;
    }

    bot_send(
        $pdo,
        $cfg,
        $wa,
        "No te entendí del todo, pero te ayudo.\nPuedes escribirme cosas como:\n- quiero 2 croquetas y 1 refresco sin hielo\n- quita la pizza\n- carrito\n- confirmar\n- lo de siempre\n\n" . bot_site_promo($cfg, $appCfg, $wa . 'fallback')
    );
    bot_send_quick_actions($pdo, $cfg, $appCfg, $wa, 'main');
}
