<?php
function bot_merge_text_block(string $existing, string $append): string {
    $existing = trim($existing);
    $append = trim($append);
    if ($append === '') return $existing;
    if ($existing === '') return $append;
    $lines = preg_split('/\R+/', $existing . "\n" . $append) ?: [];
    $seen = [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $key = mb_strtolower($line, 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $line;
    }
    return implode("\n", $out);
}

function bot_crm_preferences_from_cart(array $cart): string {
    $parts = [];
    $last = is_array($cart['last_order'] ?? null) ? $cart['last_order'] : [];
    $items = is_array($last['items'] ?? null) && $last['items'] ? $last['items'] : (is_array($cart['items'] ?? null) ? $cart['items'] : []);
    $itemNotes = is_array($last['item_notes'] ?? null) && $last['item_notes'] ? $last['item_notes'] : (is_array($cart['item_notes'] ?? null) ? $cart['item_notes'] : []);
    if ($items) {
        $itemParts = [];
        foreach ($items as $sku => $qty) {
            $entry = trim((string)$sku) . ' x' . max(1, (int)$qty);
            $note = trim((string)($itemNotes[$sku] ?? ''));
            if ($note !== '') $entry .= " [{$note}]";
            $itemParts[] = $entry;
        }
        if ($itemParts) $parts[] = 'Preferencias de compra: ' . implode(' | ', $itemParts);
    }
    if (!empty($cart['customer_address'])) {
        $parts[] = 'Dirección habitual: ' . trim((string)$cart['customer_address']);
    }
    return implode("\n", $parts);
}

function bot_sync_crm_client(PDO $pdo, string $wa, string $name, string $address='', array $cart=[], string $extraNote=''): void {
    if (!bot_table_exists($pdo, 'clientes')) return;
    if (!bot_has_column($pdo, 'clientes', 'preferencias')) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN preferencias TEXT NULL");
    }
    $phone = substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50);
    if ($phone === '') return;
    $hasOrigen = bot_has_column($pdo, 'clientes', 'origen');
    $hasCategoria = bot_has_column($pdo, 'clientes', 'categoria');
    $hasNotas = bot_has_column($pdo, 'clientes', 'notas');
    $hasPreferencias = bot_has_column($pdo, 'clientes', 'preferencias');
    $hasFechaRegistro = bot_has_column($pdo, 'clientes', 'fecha_registro');
    $st = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $name = trim($name) !== '' ? trim($name) : 'Cliente WhatsApp';
    $address = trim($address);
    $notes = 'Cliente sincronizado desde pos_bot / WhatsApp.';
    if (trim($extraNote) !== '') $notes .= "\n" . trim($extraNote);
    $preferences = bot_crm_preferences_from_cart($cart);
    if ($row) {
        $fields = ['nombre = ?', 'telefono = ?'];
        $params = [$name, $phone];
        if ($address !== '' && bot_has_column($pdo, 'clientes', 'direccion')) {
            $fields[] = 'direccion = ?';
            $params[] = $address;
        }
        if ($hasOrigen) {
            $fields[] = 'origen = ?';
            $params[] = 'WhatsApp Bot';
        }
        if ($hasCategoria && trim((string)($row['categoria'] ?? '')) === '') {
            $fields[] = 'categoria = ?';
            $params[] = 'Regular';
        }
        if ($hasNotas) {
            $fields[] = 'notas = ?';
            $params[] = bot_merge_text_block((string)($row['notas'] ?? ''), $notes);
        }
        if ($hasPreferencias) {
            $fields[] = 'preferencias = ?';
            $params[] = bot_merge_text_block((string)($row['preferencias'] ?? ''), $preferences);
        }
        $params[] = (int)$row['id'];
        $pdo->prepare("UPDATE clientes SET " . implode(',', $fields) . " WHERE id = ?")->execute($params);
        return;
    }
    $cols = ['nombre', 'telefono'];
    $vals = [$name, $phone];
    if (bot_has_column($pdo, 'clientes', 'direccion')) { $cols[] = 'direccion'; $vals[] = $address; }
    if ($hasOrigen) { $cols[] = 'origen'; $vals[] = 'WhatsApp Bot'; }
    if ($hasCategoria) { $cols[] = 'categoria'; $vals[] = 'Regular'; }
    if ($hasNotas) { $cols[] = 'notas'; $vals[] = $notes; }
    if ($hasPreferencias) { $cols[] = 'preferencias'; $vals[] = $preferences; }
    if ($hasFechaRegistro) { $cols[] = 'fecha_registro'; $vals[] = date('Y-m-d H:i:s'); }
    if (bot_has_column($pdo, 'clientes', 'activo')) { $cols[] = 'activo'; $vals[] = 1; }
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO clientes (" . implode(',', $cols) . ") VALUES ({$placeholders})")->execute($vals);
    push_notify(
        $pdo,
        'operador',
        '🤖 Nuevo cliente atendido por auto bot',
        "{$name} — {$phone}",
        '/marinero/pos_bot.php',
        'bot_new_client'
    );
}

function bot_tone(array $cfg): string {
    $tone = trim((string)($cfg['bot_tone'] ?? 'muy_cercano'));
    return in_array($tone, ['premium','popular_cubano','formal_comercial','muy_cercano'], true) ? $tone : 'muy_cercano';
}

function bot_tone_variants(array $cfg): array {
    switch (bot_tone($cfg)) {
        case 'premium':
            return [
                'greetings' => [
                    'Hola %s, es un placer atenderte.',
                    'Bienvenido %s, estoy a tu disposición para ayudarte con tu compra.',
                    'Hola %s, con gusto te ayudo a realizar tu pedido.'
                ],
                'followup' => [
                    'Puedo mostrarte el catálogo, ayudarte a repetir tu última compra o tomar tu pedido paso a paso.',
                    'Si lo deseas, te enseño el menú o preparo tu pedido de forma rápida.',
                    'Cuéntame qué necesitas y me encargo de guiarte.'
                ],
                'site' => [
                    'También puedes consultar el catálogo y comprar automáticamente en %s.',
                    'Si prefieres una experiencia directa, en %s puedes comprar automáticamente.',
                    'Recuerda que en %s puedes revisar productos y comprar de forma automática.'
                ]
            ];
        case 'popular_cubano':
            return [
                'greetings' => [
                    'Hola %s, qué bolá, aquí te atiendo rapidito.',
                    'Asere %s, dime qué te hace falta y te ayudo enseguida.',
                    'Hola %s, tranquilo, que yo te armo el pedido.'
                ],
                'followup' => [
                    'Si quieres, te saco el menú, te repito lo de siempre o te monto el pedido al momento.',
                    'Escríbeme como hablas normal y yo te resuelvo.',
                    'Tú dime lo que quieres comer o comprar y yo lo voy armando.'
                ],
                'site' => [
                    'Oye, en %s también puedes mirar el catálogo y comprar automático.',
                    'Si quieres ir más rápido, entra en %s y haces la compra automática.',
                    'Acuérdate que por %s puedes comprar automático sin esperar.'
                ]
            ];
        case 'formal_comercial':
            return [
                'greetings' => [
                    'Buenas %s, gracias por contactarnos.',
                    'Estimado %s, estamos listos para atender su solicitud.',
                    'Hola %s, con gusto gestiono su pedido.'
                ],
                'followup' => [
                    'Puedo mostrarle el catálogo, registrar su pedido o ayudarle a repetir una compra anterior.',
                    'Indíqueme los productos que desea y con gusto los organizo.',
                    'Si lo prefiere, le muestro el menú disponible.'
                ],
                'site' => [
                    'Puede consultar el catálogo y comprar automáticamente en %s.',
                    'Para una compra más directa, utilice %s.',
                    'Le recordamos que en %s puede comprar de forma automática.'
                ]
            ];
        default:
            return [
                'greetings' => [
                    'Hola %s, qué bueno tenerte por aquí.',
                    'Hola %s, aquí estoy para ayudarte con tu pedido.',
                    'Buenas %s, dime qué te apetece y te ayudo enseguida.'
                ],
                'followup' => [
                    'Puedo mostrarte el catálogo, repetir tu pedido anterior o ayudarte paso a paso.',
                    'Escríbeme como hablas normalmente y yo te ayudo.',
                    'Si quieres, te enseño el menú o vamos armando el pedido juntos.'
                ],
                'site' => [
                    'También puedes ver el catálogo y comprar automático en %s.',
                    'Si prefieres comprar más rápido, entra en %s y haz tu pedido automático.',
                    'Recuerda que en %s puedes ver productos y comprar automáticamente.'
                ]
            ];
    }
}

function bot_customer_display_name(array $cart, string $fallback='Cliente'): string {
    $name = trim((string)($cart['customer_name'] ?? ''));
    if ($name === '') $name = trim($fallback);
    if ($name === '') $name = 'Cliente';
    $parts = preg_split('/\s+/', $name) ?: [$name];
    return ucfirst(mb_strtolower((string)$parts[0], 'UTF-8'));
}

function bot_send_catalog_showcase(PDO $pdo, string $wa, array $appCfg, array $menu, string $intro=''): void {
    $logo = bot_business_logo_url($appCfg);
    if ($logo !== '') {
        bot_send_image($pdo, $wa, $logo, $intro);
        $intro = '';
    }
    $count = 0;
    foreach ($menu as $p) {
        $sku = trim((string)($p['codigo'] ?? ''));
        if ($sku === '') continue;
        $caption = "{$p['nombre']}\nPrecio: $" . number_format((float)$p['precio'], 2);
        if ($count === 0 && $intro !== '') $caption = $intro . "\n\n" . $caption;
        bot_send_image($pdo, $wa, bot_public_product_image($sku), $caption);
        $count++;
        if ($count >= 3) break;
    }
    if ($count === 0 && $intro !== '') {
        bot_send($pdo, [], $wa, $intro);
    }
}

function bot_greeting_text(array $cfg, array $appCfg, array $cart, string $fallbackName='Cliente'): string {
    $name = bot_customer_display_name($cart, $fallbackName);
    $business = trim((string)($cfg['business_name'] ?? ($appCfg['marca_sistema_nombre'] ?? ($appCfg['tienda_nombre'] ?? 'Sistema POS'))));
    $tone = bot_tone_variants($cfg);
    $greet = sprintf(bot_pick($tone['greetings'], $name . date('YmdH')), $name);
    $extra = ((int)($cart['greet_count'] ?? 0) > 0)
        ? bot_pick($tone['followup'], $name . 'repeat')
        : "Estás hablando con {$business}. Puedes pedir escribiendo natural, por ejemplo: \"quiero 2 pizzas y 1 refresco\".";
    return $greet . "\n" . $extra . "\n" . bot_site_promo($cfg, $appCfg, $name . 'promo');
}

function bot_format_item_line(array $product, int $qty, string $note=''): string {
    $line = "- {$product['nombre']} ({$product['codigo']}) x{$qty}";
    if ($note !== '') $line .= " [{$note}]";
    $line .= " = $" . number_format(((float)$product['precio']) * $qty, 2);
    return $line;
}

function bot_suggest_item(array $menu, array $cart): ?array {
    $existing = array_keys((array)($cart['items'] ?? []));
    $preferredWords = ['refresco','bebida','jugo','agua','postre','cafe','café'];
    foreach ($menu as $p) {
        $sku = (string)$p['codigo'];
        if (in_array($sku, $existing, true)) continue;
        $nameNorm = bot_norm((string)$p['nombre']);
        foreach ($preferredWords as $word) {
            if (str_contains($nameNorm, bot_norm($word))) return $p;
        }
    }
    foreach ($menu as $p) {
        if (!in_array((string)$p['codigo'], $existing, true)) return $p;
    }
    return null;
}

function bot_repeat_last_order(array &$cart): array {
    $last = is_array($cart['last_order'] ?? null) ? $cart['last_order'] : [];
    $items = is_array($last['items'] ?? null) ? $last['items'] : [];
    if (!$items) return [];
    foreach ($items as $sku => $qty) {
        $cart['items'][$sku] = ($cart['items'][$sku] ?? 0) + max(1, (int)$qty);
    }
    foreach ((array)($last['item_notes'] ?? []) as $sku => $note) {
        if (trim((string)$note) !== '') $cart['item_notes'][$sku] = trim((string)$note);
    }
    return $items;
}

function bot_faq_answer(string $norm, array $cfg, array $appCfg): ?string {
    $answers = [];
    if (bot_intent_has($norm, ['horario','abren','abierto','cierran','hora'])) {
        $answers[] = "Puedo ayudarte a tomar el pedido ahora mismo por este chat.";
        $answers[] = "Si quieres confirmar disponibilidad inmediata, pideme el menu o dime lo que necesitas.";
    }
    if (bot_intent_has($norm, ['direccion','dirección','donde estan','ubicacion','ubicación','local'])) {
        $dir = trim((string)($appCfg['direccion'] ?? ''));
        $answers[] = $dir !== '' ? "Nuestra direccion es: {$dir}." : "La direccion del negocio no esta configurada todavia en el sistema.";
    }
    if (bot_intent_has($norm, ['telefono','teléfono','llamar','contacto'])) {
        $tel = trim((string)($appCfg['telefono'] ?? ''));
        $answers[] = $tel !== '' ? "Puedes llamarnos o escribirnos al {$tel}." : "El telefono del negocio no esta configurado aun.";
    }
    if (bot_intent_has($norm, ['correo','email'])) {
        $email = trim((string)($appCfg['email'] ?? ''));
        if ($email !== '') $answers[] = "Nuestro correo es {$email}.";
    }
    if (bot_intent_has($norm, ['web','sitio','pagina','pagina web','catalogo','catálogo online'])) {
        $answers[] = "Puedes ver el catalogo y comprar automaticamente en " . bot_site_url($appCfg) . ".";
    }
    if (bot_intent_has($norm, ['pago','pagos','transferencia','efectivo','tarjeta'])) {
        $answers[] = "Aceptamos pagos segun la configuracion del negocio y tambien puedes revisar las opciones al comprar en " . bot_site_url($appCfg) . ".";
    }
    if (bot_intent_has($norm, ['envio','envío','domicilio','delivery','entrega'])) {
        $tarifa = (float)($appCfg['mensajeria_tarifa_km'] ?? 0);
        $answers[] = $tarifa > 0 ? "Hacemos entregas a domicilio. La tarifa base configurada es {$tarifa} por km." : "Podemos gestionar entrega a domicilio. Si quieres, comparte tu direccion y te guiamos.";
    }
    if (!$answers) return null;
    $answers[] = bot_site_promo($cfg, $appCfg, $norm . 'faq');
    return implode("\n", array_unique($answers));
}

function bot_cart_text(PDO $pdo, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['text' => 'Tu carrito esta vacio por ahora.', 'total' => 0];
    $lines = ['Asi va tu pedido:'];
    $tot = 0.0;
    $notes = (array)($cart['item_notes'] ?? []);
    foreach ($items as $sku => $qty) {
        $st = $pdo->prepare("SELECT nombre,precio FROM productos WHERE codigo=? LIMIT 1");
        $st->execute([$sku]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $sub = (float)$p['precio'] * (float)$qty;
        $tot += $sub;
        $note = trim((string)($notes[$sku] ?? ''));
        $line = "- {$p['nombre']} ({$sku}) x{$qty}";
        if ($note !== '') $line .= " [{$note}]";
        $line .= " = $" . number_format($sub,2);
        $lines[] = $line;
    }
    $deliveryFee = (($cart['fulfillment_mode'] ?? '') === 'delivery') ? (float)($cart['delivery_fee'] ?? 0) : 0.0;
    if ($deliveryFee > 0) $lines[] = "Mensajería: $" . number_format($deliveryFee, 2);
    $grandTotal = $tot + $deliveryFee;
    $lines[] = "TOTAL: $" . number_format($grandTotal,2);
    if (!empty($cart['customer_name'])) $lines[] = "Nombre: " . $cart['customer_name'];
    if (!empty($cart['customer_address'])) $lines[] = "Direccion: " . $cart['customer_address'];
    if (!empty($cart['fulfillment_mode'])) $lines[] = bot_delivery_summary_line($cart);
    if (!empty($cart['requested_datetime_label'])) $lines[] = (($cart['fulfillment_mode'] ?? '') === 'delivery' ? 'Entrega: ' : 'Recogida: ') . $cart['requested_datetime_label'];
    $lines[] = "Si lo ves bien, escribe CONFIRMAR y lo cierro por ti.";
    return ['text' => implode("\n", $lines), 'total' => $grandTotal, 'subtotal' => $tot, 'delivery_fee' => $deliveryFee];
}

function bot_find_cliente_by_phone(PDO $pdo, string $wa): ?array {
    if (!bot_table_exists($pdo, 'clientes') || !bot_has_column($pdo, 'clientes', 'telefono')) return null;
    $phone = substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50);
    if ($phone === '') return null;
    $st = $pdo->prepare("SELECT * FROM clientes WHERE telefono = ? LIMIT 1");
    $st->execute([$phone]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bot_upsert_cliente_for_reserva(PDO $pdo, string $wa, string $name, string $address, array $cart): int {
    bot_sync_crm_client($pdo, $wa, $name, $address, $cart, 'Reserva generada desde WhatsApp Bot.');
    $row = bot_find_cliente_by_phone($pdo, $wa);
    return (int)($row['id'] ?? 0);
}

function bot_create_reserva(PDO $pdo, array $appCfg, string $wa, string $name, string $address, array $cart): array {
    $items = $cart['items'] ?? [];
    if (!$items) return ['ok'=>false, 'msg'=>'Carrito vacio'];
    $fulfillmentMode = (string)($cart['fulfillment_mode'] ?? '');
    if (!in_array($fulfillmentMode, ['pickup', 'delivery'], true)) {
        return ['ok' => false, 'msg' => 'Falta definir si es recogida o mensajería'];
    }
    if (trim((string)($cart['requested_datetime'] ?? '')) === '') {
        return ['ok' => false, 'msg' => 'Falta fecha y hora de entrega'];
    }
    if ($fulfillmentMode === 'delivery' && trim($address) === '') {
        return ['ok' => false, 'msg' => 'Falta dirección para la mensajería'];
    }

    $pdo->beginTransaction();
    try {
        $total = 0.0; $valid = [];
        $sinExistencia = 0;
        $itemNotes = (array)($cart['item_notes'] ?? []);
        $deliveryFee = $fulfillmentMode === 'delivery' ? (float)($cart['delivery_fee'] ?? 0) : 0.0;
        $validation = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : [];
        $requestedAt = trim((string)($cart['requested_datetime'] ?? ''));
        $requestedLabel = trim((string)($cart['requested_datetime_label'] ?? ''));
        foreach ($items as $sku => $qty) {
            $st = $pdo->prepare("SELECT codigo,nombre,precio,categoria,COALESCE(es_servicio,0) AS es_servicio, COALESCE(es_reservable,0) AS es_reservable FROM productos WHERE codigo=? AND id_empresa=? LIMIT 1");
            $st->execute([$sku, (int)$appCfg['id_empresa']]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) continue;
            $q = max(1, (int)$qty);
            if (empty($p['es_servicio'])) {
                $stockSt = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                $stockSt->execute([$sku, (int)($appCfg['id_almacen'] ?? 1)]);
                $stock = (float)$stockSt->fetchColumn();
                if ($stock < $q) {
                    if (!empty($p['es_reservable'])) {
                        $sinExistencia = 1;
                    } else {
                        throw new Exception("Sin existencia suficiente para {$p['nombre']}.");
                    }
                }
            }
            $total += (float)$p['precio'] * $q;
            $valid[] = [
                'sku'=>$p['codigo'],
                'name'=>(string)$p['nombre'],
                'qty'=>$q,
                'price'=>(float)$p['precio'],
                'note'=>trim((string)($itemNotes[(string)$p['codigo']] ?? '')),
                'category'=>(string)($p['categoria'] ?? 'General')
            ];
        }
        if (!$valid) throw new Exception('Sin productos validos');
        $idCliente = bot_upsert_cliente_for_reserva($pdo, $wa, $name, $address, $cart);
        $totalFinal = $total + $deliveryFee;
        $addressForReserva = trim($address);
        $notes = [
            'Reserva creada desde WhatsApp Bot',
            'Tipo: ' . bot_fulfillment_label($fulfillmentMode),
            'Fecha solicitada: ' . ($requestedLabel !== '' ? $requestedLabel : $requestedAt),
            'Cliente WA: ' . $wa
        ];
        if ($fulfillmentMode === 'delivery') {
            $municipio = trim((string)($validation['municipio'] ?? ''));
            $barrio = trim((string)($validation['barrio'] ?? ''));
            $distanceKm = (float)($cart['delivery_distance_km'] ?? ($validation['distance_km'] ?? 0));
            $notes[] = 'Mensajería: ' . number_format($distanceKm, 2) . ' km x 100 CUP';
            if ($municipio !== '' || $barrio !== '') {
                $notes[] = 'Zona: ' . trim($municipio . ' / ' . $barrio, ' /');
                $addressForReserva = '[MENSAJERÍA: ' . trim($municipio . ' - ' . $barrio, ' -') . '] ' . $addressForReserva;
            }
        } else {
            $addressForReserva = trim($addressForReserva) !== '' ? '[RECOGER EN TIENDA] ' . $addressForReserva : 'RECOGER EN TIENDA';
        }

        $uuid = uniqid('R-WA-', true);
        $h = $pdo->prepare("INSERT INTO ventas_cabecera
            (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, tipo_servicio,
             fecha_reserva, notas, cliente_nombre, cliente_telefono, cliente_direccion,
             abono, estado_pago, id_empresa, estado_reserva, sincronizado, id_caja, id_cliente, canal_origen)
            VALUES (?, NOW(), ?, 'Pendiente', ?, ?, 'reserva', ?, ?, ?, ?, ?, 0, 'pendiente', ?, 'PENDIENTE', 0, 1, ?, 'WhatsApp')");
        $h->execute([
            $uuid,
            $totalFinal,
            (int)$appCfg['id_sucursal'],
            (int)$appCfg['id_almacen'],
            $requestedAt,
            implode("\n", $notes),
            $name ?: 'Cliente WhatsApp',
            substr(preg_replace('/\D+/', '', $wa) ?? '', 0, 50),
            mb_substr($addressForReserva, 0, 255),
            (int)$appCfg['id_empresa'],
            $idCliente
        ]);
        $idReserva = (int)$pdo->lastInsertId();

        $d = $pdo->prepare("INSERT INTO ventas_detalle
            (id_venta_cabecera, id_producto, cantidad, precio, nombre_producto, codigo_producto, categoria_producto)
            VALUES (?,?,?,?,?,?,?)");
        foreach ($valid as $it) {
            $d->execute([$idReserva, $it['sku'], $it['qty'], $it['price'], $it['name'], $it['sku'], $it['category']]);
        }
        if ($sinExistencia) {
            $pdo->prepare("UPDATE ventas_cabecera SET sin_existencia=1 WHERE id=?")->execute([$idReserva]);
        }

        $pdo->prepare("INSERT INTO pos_bot_orders (id_pedido,wa_user_id,total) VALUES (?,?,?)")->execute([$idReserva, $wa, $totalFinal]);
        $pdo->commit();
        $resumenPush = ($name ?: 'Cliente WhatsApp') . ' — $' . number_format($totalFinal, 2);
        push_notify(
            $pdo,
            'operador',
            '📅 Nueva reserva por WhatsApp Bot',
            $resumenPush,
            '/marinero/reservas.php',
            'bot_new_reservation'
        );
        if ($sinExistencia) {
            push_notify(
                $pdo,
                'operador',
                '📦 Reserva WhatsApp con productos sin stock',
                $resumenPush,
                '/marinero/reservas.php',
                'bot_reservation_no_stock'
            );
        }
        return [
            'ok'=>true,
            'id_reserva'=>$idReserva,
            'id_pedido'=>$idReserva,
            'total'=>$totalFinal,
            'ticket_url'=>bot_ticket_url($idReserva, $appCfg),
            'order_snapshot'=>[
                'items' => $items,
                'item_notes' => $itemNotes,
                'customer_name' => $name,
                'customer_address' => $address,
                'fulfillment_mode' => $fulfillmentMode,
                'delivery_fee' => $deliveryFee,
                'delivery_distance_km' => (float)($cart['delivery_distance_km'] ?? 0),
                'address_validation' => $validation,
                'requested_datetime' => $requestedAt,
                'requested_datetime_label' => $requestedLabel,
                'created_at' => date('c'),
                'id_reserva' => $idReserva
            ]
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'msg'=>$e->getMessage()];
    }
}
