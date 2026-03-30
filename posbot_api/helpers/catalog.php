<?php
function bot_send_quick_actions(PDO $pdo, array $cfg, array $appCfg, string $wa, string $context='main'): void {
    $title = 'Accesos rápidos';
    $footer = preg_replace('~^https?://~i', '', bot_site_url($appCfg));
    $body1 = $context === 'recovery'
        ? 'Retoma tu compra con un toque:'
        : 'Usa estos botones para ir más rápido:';
    bot_send_buttons($pdo, $wa, $body1, ['Menu', 'Repetir pedido', 'Carrito'], $title, $footer);
    bot_send_buttons($pdo, $wa, 'Más opciones:', $context === 'recovery' ? ['Confirmar', 'Cancelar compra'] : ['Confirmar', 'Comprar en web'], $title, $footer);
}

function bot_mark_cart_activity(array &$cart): void {
    $cart['last_cart_activity_at'] = date('c');
    $cart['last_cart_reminder_at'] = '';
    $cart['cart_recovery_first_day'] = '';
    $cart['cart_recovery_day_key'] = '';
    $cart['cart_recovery_day_count'] = 0;
}

function bot_default_cart(string $name=''): array {
    return [
        'items' => [],
        'item_notes' => [],
        'customer_name' => $name,
        'customer_address' => '',
        'fulfillment_mode' => '',
        'address_validation' => null,
        'delivery_fee' => 0.0,
        'delivery_distance_km' => 0.0,
        'delivery_rate_per_km' => 100.0,
        'requested_datetime' => '',
        'requested_datetime_label' => '',
        'escalation_active' => 0,
        'escalation_reason' => '',
        'escalation_label' => '',
        'escalation_at' => '',
        'awaiting_field' => '',
        'pending_choice' => null,
        'last_order' => null,
        'greet_count' => 0,
        'bot_paused' => 0,
        'last_cart_activity_at' => '',
        'last_cart_reminder_at' => '',
        'cart_recovery_first_day' => '',
        'cart_recovery_day_key' => '',
        'cart_recovery_day_count' => 0,
        'last_manual_reply_at' => ''
    ];
}

function bot_merge_cart_shape(array $cart, string $name=''): array {
    $base = bot_default_cart($name);
    $cart = array_merge($base, $cart);
    if (!is_array($cart['items'] ?? null)) $cart['items'] = [];
    if (!is_array($cart['item_notes'] ?? null)) $cart['item_notes'] = [];
    if (!is_array($cart['last_order'] ?? null)) $cart['last_order'] = $base['last_order'];
    if (!is_array($cart['pending_choice'] ?? null)) $cart['pending_choice'] = null;
    $cart['bot_paused'] = !empty($cart['bot_paused']) ? 1 : 0;
    $cart['cart_recovery_day_count'] = max(0, (int)($cart['cart_recovery_day_count'] ?? 0));
    if (($cart['customer_name'] ?? '') === '' && $name !== '') $cart['customer_name'] = $name;
    return $cart;
}

function bot_get_cart(PDO $pdo, string $wa, string $name=''): array {
    $st = $pdo->prepare("SELECT cart_json FROM pos_bot_sessions WHERE wa_user_id=? LIMIT 1");
    $st->execute([$wa]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cart = bot_default_cart($name);
        $ins = $pdo->prepare("INSERT INTO pos_bot_sessions (wa_user_id,wa_name,cart_json) VALUES (?,?,?)");
        $ins->execute([$wa,$name,json_encode($cart, JSON_UNESCAPED_UNICODE)]);
        return $cart;
    }
    $cart = json_decode((string)$row['cart_json'], true);
    if (!is_array($cart)) $cart = [];
    return bot_merge_cart_shape($cart, $name);
}

function bot_save_cart(PDO $pdo, string $wa, string $name, array $cart): void {
    $cart = bot_merge_cart_shape($cart, $name);
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
    if (preg_match('/^\[[a-z0-9_]+\]$/iu', $raw)) return '';
    $patterns = [
        '/^(mi nombre es|soy|me llamo)\s+/iu',
        '/^(nombre)\s*[:\-]?\s*/iu',
    ];
    foreach ($patterns as $p) $raw = preg_replace($p, '', $raw) ?? $raw;
    $val = bot_clean_value($raw);
    if ($val === '' || mb_strlen($val) < 2) return '';
    if (!bot_is_plausible_person_name($val)) return '';
    return mb_substr($val, 0, 120);
}

function bot_is_plausible_person_name(string $text): bool {
    $val = bot_clean_value($text);
    if ($val === '' || mb_strlen($val) < 2) return false;
    if (preg_match('/\d/', $val)) return false;
    $norm = bot_norm($val);
    if ($norm === '') return false;
    $invalidExact = ['confirmar','menu','carrito','cancelar','pedido','cliente'];
    if (in_array($norm, $invalidExact, true)) return false;
    $invalidStarts = [
        'quiero','dame','ponme','mandame','manda','agrega','agregar','anade','añade',
        'me das','me pones','preparame','prepárame','lo mismo','repetir','comprar',
        'direccion','dirección','entrega','delivery','recoger','reserva'
    ];
    foreach ($invalidStarts as $prefix) {
        if ($norm === $prefix || str_starts_with($norm, $prefix . ' ')) return false;
    }
    if (preg_match('/\b(croqueta|croquetas|pizza|refresco|espagueti|espaguetis|combo|pedido|reserva|producto|productos|menu|menu|catalogo|catalogo|delivery|domicilio|tienda)\b/u', $norm)) {
        return false;
    }
    return (bool)preg_match('/[[:alpha:]]/u', $val);
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
    if (in_array(bot_norm($val), ['confirmar','menu','carrito','cancelar','pedido'], true)) return '';
    $normVal = bot_norm($val);
    $hasAddressHint = preg_match('/\d/', $val) || preg_match('/\b(calle|callejon|avenida|ave|av|reparto|edificio|apto|apartamento|casa|km|kilometro|barrio|entre|esquina|carretera|pasaje|zona)\b/u', $normVal);
    if (!$hasAddressHint) return '';
    return mb_substr($val, 0, 220);
}

function bot_delivery_rate_per_km(): float {
    return 100.0;
}

function bot_detect_fulfillment_mode(string $norm): string {
    if (bot_intent_has($norm, ['recoger','recojo','recogida','recoger en tienda','paso a buscar','voy a buscar','retiro','retirar'])) {
        return 'pickup';
    }
    if (bot_intent_has($norm, ['mensajeria','mensajería','mensajero','envio','envío','domicilio','delivery','entrega'])) {
        return 'delivery';
    }
    return '';
}

function bot_validate_habana_address(string $address): array {
    $result = palweb_habana_address_resolve($address);
    if (!$result['ok']) return $result;
    $result['rate_per_km'] = bot_delivery_rate_per_km();
    $result['delivery_fee'] = palweb_habana_delivery_fee((float)$result['distance_km'], (float)$result['rate_per_km']);
    return $result;
}

function bot_fulfillment_label(string $mode): string {
    return $mode === 'delivery' ? 'Mensajería' : ($mode === 'pickup' ? 'Recoger en tienda' : 'Sin definir');
}

function bot_delivery_summary_line(array $cart): string {
    if (($cart['fulfillment_mode'] ?? '') !== 'delivery') return 'Entrega: recoger en tienda';
    $km = (float)($cart['delivery_distance_km'] ?? 0);
    $fee = (float)($cart['delivery_fee'] ?? 0);
    $validation = is_array($cart['address_validation'] ?? null) ? $cart['address_validation'] : [];
    $zone = trim((string)(($validation['municipio'] ?? '') . ' / ' . ($validation['barrio'] ?? '')), ' /');
    $suffix = $zone !== '' ? " ({$zone})" : '';
    return "Mensajería: {$km} km{$suffix} = $" . number_format($fee, 2);
}

function bot_extract_schedule_datetime(string $text, string $timezone='America/Havana'): ?array {
    $raw = trim($text);
    if ($raw === '') return null;
    $norm = bot_norm($raw);
    $normLoose = mb_strtolower($raw, 'UTF-8');
    $normLoose = strtr($normLoose, ['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u']);
    $tz = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now', $tz);

    $setTime = static function (DateTimeImmutable $base, int $hour, int $minute): DateTimeImmutable {
        return $base->setTime(max(0, min(23, $hour)), max(0, min(59, $minute)), 0);
    };

    if (preg_match('/\b(hoy|manana|mañana)\b/u', $normLoose, $dMatch)) {
        $dayWord = $dMatch[1];
        $base = $dayWord === 'hoy' ? $now : $now->modify('+1 day');
        $hour = null;
        $minute = 0;
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/u', $normLoose, $m) || preg_match('/\b(\d{1,2})(?::(\d{2}))\b/u', $normLoose, $m) || preg_match('/\b(\d{1,2})\s*(am|pm)\b/u', $normLoose, $m)) {
            $hour = (int)$m[1];
            if (isset($m[3]) || isset($m[2])) {
                if (isset($m[3]) && in_array(strtolower((string)$m[3]), ['am','pm'], true)) {
                    $minute = isset($m[2]) && ctype_digit((string)$m[2]) ? (int)$m[2] : 0;
                    $ampm = strtolower((string)$m[3]);
                } else {
                    $minute = isset($m[2]) && ctype_digit((string)$m[2]) ? (int)$m[2] : 0;
                    $ampm = isset($m[2]) && in_array(strtolower((string)$m[2]), ['am','pm'], true) ? strtolower((string)$m[2]) : '';
                }
            } else {
                $ampm = '';
            }
            if ($ampm === 'pm' && $hour < 12) $hour += 12;
            if ($ampm === 'am' && $hour === 12) $hour = 0;
        }
        if ($hour !== null) {
            $dt = $setTime($base, $hour, $minute);
            if ($dt <= $now) $dt = $dt->modify('+1 day');
            return [
                'value' => $dt->format('Y-m-d H:i:s'),
                'label' => $dt->format('d/m/Y h:i A'),
                'date' => $dt->format('Y-m-d'),
                'time' => $dt->format('H:i')
            ];
        }
    }

    $formats = [
        'd/m/Y H:i', 'd-m-Y H:i', 'Y-m-d H:i',
        'd/m/Y g:i A', 'd-m-Y g:i A', 'Y-m-d g:i A',
        'd/m/Y g A', 'd-m-Y g A'
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0 && $dt > $now) {
                return [
                    'value' => $dt->format('Y-m-d H:i:s'),
                    'label' => $dt->format('d/m/Y h:i A'),
                    'date' => $dt->format('Y-m-d'),
                    'time' => $dt->format('H:i')
                ];
            }
        }
    }

    if (preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*$/iu', $raw, $m)) {
        $hour = (int)$m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $ampm = strtolower((string)($m[3] ?? ''));
        if ($ampm === 'pm' && $hour < 12) $hour += 12;
        if ($ampm === 'am' && $hour === 12) $hour = 0;
        $dt = $setTime($now, $hour, $minute);
        if ($dt <= $now) $dt = $dt->modify('+1 day');
        return [
            'value' => $dt->format('Y-m-d H:i:s'),
            'label' => $dt->format('d/m/Y h:i A'),
            'date' => $dt->format('Y-m-d'),
            'time' => $dt->format('H:i')
        ];
    }

    return null;
}

function bot_delivery_address_prompt(): string {
    return "Compárteme la dirección completa en La Habana, Cuba.\nEjemplo: Calle 10 #45 entre A y B, Vedado, Plaza de la Revolución, La Habana.";
}

function bot_datetime_prompt(string $mode): string {
    $target = $mode === 'delivery' ? 'entrega' : 'recogida en tienda';
    return "Ahora dime la fecha y hora para la {$target}.\nEjemplos válidos:\n- hoy 5:30 pm\n- mañana 10:00 am\n- 15/03/2026 14:30";
}

function bot_ticket_url(int $idReserva, array $appCfg = []): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host ?? '');
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443') || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $host . '/ticket_view.php?id=' . $idReserva . '&source=qr';
    }
    return rtrim(bot_site_url($appCfg), '/') . '/ticket_view.php?id=' . $idReserva . '&source=qr';
}

function bot_send_fulfillment_prompt(PDO $pdo, string $wa): void {
    bot_send_buttons($pdo, $wa, 'Antes de cerrar la reserva, dime cómo la quieres recibir:', ['Recoger en tienda', 'Mensajeria'], 'Tipo de entrega', 'La Habana / Cuba');
}

function bot_detect_escalation(string $norm): ?array {
    $rules = [
        ['label' => 'No ha llegado', 'reason' => 'delivery_delay', 'patterns' => ['no ha llegado', 'todavia no llega', 'todavia no ha llegado', 'mi pedido no llega', 'no me ha llegado', 'demora pedido']],
        ['label' => 'Cobro incorrecto', 'reason' => 'billing_issue', 'patterns' => ['me cobraron mal', 'cobro mal', 'cobro incorrecto', 'precio mal', 'me cobraron de mas', 'me cobraron demás']],
        ['label' => 'Reclamación', 'reason' => 'complaint', 'patterns' => ['quiero reclamar', 'quiero poner una queja', 'tengo una queja', 'necesito reclamar', 'reclamo', 'queja']],
    ];
    foreach ($rules as $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (str_contains($norm, bot_norm($pattern))) return $rule;
        }
    }
    return null;
}

function bot_mark_escalation(array &$cart, array $escalation): void {
    $cart['bot_paused'] = 1;
    $cart['escalation_active'] = 1;
    $cart['escalation_reason'] = (string)($escalation['reason'] ?? 'complaint');
    $cart['escalation_label'] = (string)($escalation['label'] ?? 'Atención humana');
    $cart['escalation_at'] = date('c');
}

function bot_intent_has(string $norm, array $needles): bool {
    foreach ($needles as $n) {
        if (str_contains($norm, bot_norm($n))) return true;
    }
    return false;
}

function bot_extract_qty(string $normText): int {
    if (preg_match('/\b(\d{1,2})\b/', $normText, $m)) return max(1, min(99, (int)$m[1]));
    $map = [
        'un'=>1,'uno'=>1,'una'=>1,
        'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10,
        'par'=>2
    ];
    foreach ($map as $word => $qty) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normText)) return $qty;
    }
    return 1;
}

function bot_strip_filler_words(string $normText): string {
    $patterns = [
        '/\b(agregar|anadir|añadir|sumar|quiero|dame|deseo|mandame|manda|ponme|pon|me das|favor|por favor|necesito|para mi|para)\b/u',
        '/\b(un|una|unos|unas)\b/u'
    ];
    $clean = $normText;
    foreach ($patterns as $p) $clean = preg_replace($p, ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
    return $clean;
}

function bot_extract_item_note(string $normText): array {
    $notes = [];
    $clean = $normText;
    $patterns = [
        '/\bsin\s+([a-z0-9 ]{2,40})/u' => 'Sin %s',
        '/\bcon\s+extra\s+([a-z0-9 ]{2,40})/u' => 'Con extra %s',
        '/\bextra\s+([a-z0-9 ]{2,40})/u' => 'Extra %s',
        '/\bbien\s+([a-z0-9 ]{2,30})/u' => 'Bien %s',
        '/\bpoco\s+([a-z0-9 ]{2,30})/u' => 'Poco %s',
        '/\bsin\s+([a-z0-9 ]{2,25})\s+y\s+sin\s+([a-z0-9 ]{2,25})/u' => 'Sin %s y sin %s'
    ];
    foreach ($patterns as $regex => $fmt) {
        if (preg_match_all($regex, $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $parts = [];
                for ($i = 1; $i < count($m); $i++) {
                    $parts[] = trim((string)$m[$i]);
                }
                $notes[] = vsprintf($fmt, $parts);
            }
            $clean = preg_replace($regex, ' ', $clean) ?? $clean;
        }
    }
    $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
    return ['text' => $clean, 'note' => trim(implode('; ', array_unique(array_filter($notes))))];
}

function bot_similarity_score(string $a, string $b): int {
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') return 0;
    if ($a === $b) return 1000;
    if (str_contains($a, $b) || str_contains($b, $a)) return 850;
    $aCompact = str_replace(' ', '', $a);
    $bCompact = str_replace(' ', '', $b);
    $maxLen = max(strlen($aCompact), strlen($bCompact));
    $lev = levenshtein($aCompact, $bCompact);
    $levScore = $maxLen > 0 ? (int)round((1 - min($lev, $maxLen) / $maxLen) * 100) : 0;
    $aTokens = array_values(array_filter(explode(' ', $a), static fn($x) => mb_strlen($x) >= 2));
    $bTokens = array_values(array_filter(explode(' ', $b), static fn($x) => mb_strlen($x) >= 2));
    $common = count(array_intersect($aTokens, $bTokens));
    $ratio = $common > 0 ? $common / max(1, count($bTokens)) : 0;
    return max($levScore, (int)round($ratio * 100) + ($common * 10));
}

function bot_find_product_candidates(array $menu, string $normText): array {
    $normText = bot_strip_filler_words($normText);
    $scored = [];
    foreach ($menu as $p) {
        $sku = (string)($p['codigo'] ?? '');
        $name = (string)($p['nombre'] ?? '');
        $skuNorm = bot_norm($sku);
        $nameNorm = bot_norm($name);
        $score = 0;
        if ($skuNorm !== '' && preg_match('/\b' . preg_quote($skuNorm, '/') . '\b/u', $normText)) {
            $score = 1100;
        } else {
            $score = max(bot_similarity_score($normText, $nameNorm), bot_similarity_score($normText, $skuNorm));
        }
        if ($score < 45) continue;
        $scored[] = ['product' => $p, 'score' => $score];
    }
    usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcasecmp((string)$a['product']['nombre'], (string)$b['product']['nombre']));
    return array_slice($scored, 0, 4);
}

function bot_parse_add_request(array $menu, string $text): array {
    $norm = bot_norm($text);
    if ($norm === '') return ['items' => [], 'ambiguous' => [], 'unknown' => []];
    $segments = preg_split('/\s*(?:,| y | e |;|\+| mas )\s*/u', $norm) ?: [$norm];
    $items = [];
    $ambiguous = [];
    $unknown = [];
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        $qty = bot_extract_qty($seg);
        $noteInfo = bot_extract_item_note($seg);
        $lookup = bot_strip_filler_words($noteInfo['text']);
        $lookup = preg_replace('/\b(\d{1,2}|un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|par)\b/u', ' ', $lookup) ?? $lookup;
        $lookup = preg_replace('/\s+/', ' ', trim($lookup)) ?? '';
        $candidates = bot_find_product_candidates($menu, $lookup);
        if (!$candidates) {
            $unknown[] = $seg;
            continue;
        }
        $best = $candidates[0];
        $second = $candidates[1] ?? null;
        $isAmbiguous = $second && ($best['score'] - $second['score'] <= 10) && $best['score'] < 900;
        if ($isAmbiguous) {
            $ambiguous[] = [
                'text' => $seg,
                'qty' => $qty,
                'note' => $noteInfo['note'],
                'options' => array_map(static fn($row) => [
                    'sku' => (string)$row['product']['codigo'],
                    'name' => (string)$row['product']['nombre']
                ], array_slice($candidates, 0, 3))
            ];
            continue;
        }
        $sku = (string)$best['product']['codigo'];
        $items[] = [
            'sku' => $sku,
            'qty' => $qty,
            'name' => (string)$best['product']['nombre'],
            'note' => $noteInfo['note']
        ];
    }
    return ['items' => $items, 'ambiguous' => $ambiguous, 'unknown' => $unknown];
}

function bot_find_product_by_text(array $menu, string $normText): ?array {
    $candidates = bot_find_product_candidates($menu, bot_norm($normText));
    return $candidates[0]['product'] ?? null;
}

function bot_parse_remove_item(array $menu, string $text): ?string {
    $norm = bot_norm($text);
    $p = bot_find_product_by_text($menu, $norm);
    return $p ? (string)$p['codigo'] : null;
}

function bot_menu(PDO $pdo, int $emp, int $suc): array {
    global $config;
    $idAlmacen = (int)($config['id_almacen'] ?? 1);
    $st = $pdo->prepare("SELECT p.codigo,p.nombre,p.precio,p.categoria,COALESCE(p.es_servicio,0) AS es_servicio,
                            COALESCE(p.es_reservable,0) AS es_reservable,
                            COALESCE((SELECT SUM(s.cantidad) FROM stock_almacen s WHERE s.id_producto = p.codigo AND s.id_almacen = ?),0) AS stock_total
                         FROM productos p
                         WHERE activo=1 AND es_web=1 AND id_empresa=?
                           AND (sucursales_web='' OR sucursales_web IS NULL OR FIND_IN_SET(?, sucursales_web)>0)
                         ORDER BY categoria,nombre LIMIT 80");
    $st->execute([$idAlmacen,$emp,$suc]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bot_product_in_stock(array $product, int $qty=1): bool {
    if (!empty($product['es_servicio'])) return true;
    return (float)($product['stock_total'] ?? 0) >= max(1, $qty);
}

function bot_product_is_reservable(array $product): bool {
    return !empty($product['es_reservable']);
}

function bot_find_substitutes(array $menu, array $product, int $limit=3): array {
    $category = trim((string)($product['categoria'] ?? ''));
    $currentSku = (string)($product['codigo'] ?? '');
    $out = [];
    foreach ($menu as $p) {
        if ((string)($p['codigo'] ?? '') === $currentSku) continue;
        if ($category !== '' && trim((string)($p['categoria'] ?? '')) !== $category) continue;
        if (!bot_product_in_stock($p, 1)) continue;
        $out[] = $p;
        if (count($out) >= $limit) break;
    }
    if (!$out) {
        foreach ($menu as $p) {
            if ((string)($p['codigo'] ?? '') === $currentSku) continue;
            if (!bot_product_in_stock($p, 1)) continue;
            $out[] = $p;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

function bot_pick(array $options, string $seed=''): string {
    if (!$options) return '';
    $idx = abs(crc32($seed !== '' ? $seed : (string)microtime(true))) % count($options);
    return (string)$options[$idx];
}

function bot_site_url(array $appCfg): string {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host ?? '');
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        return ($https ? 'https' : 'http') . '://' . $host;
    }
    $url = trim((string)($appCfg['website'] ?? ''));
    if ($url === '') $url = 'https://www.palweb.net';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    return $url;
}

function bot_site_promo(array $cfg, array $appCfg, string $seed=''): string {
    $site = preg_replace('~^https?://~i', '', bot_site_url($appCfg));
    $tone = bot_tone_variants($cfg);
    return sprintf(bot_pick($tone['site'], $seed), $site);
}

function bot_business_logo_url(array $appCfg): string {
    $logo = trim((string)($appCfg['ticket_logo'] ?? ''));
    if ($logo === '') return '';
    if (preg_match('~^https?://~i', $logo)) {
        $parts = parse_url($logo);
        $path = (string)($parts['path'] ?? '');
        if ($path !== '') {
            $rebased = rtrim(bot_site_url($appCfg), '/') . $path;
            if (!empty($parts['query'])) $rebased .= '?' . $parts['query'];
            return $rebased;
        }
        return $logo;
    }
    $base = rtrim(bot_site_url($appCfg), '/');
    return $logo[0] === '/' ? ($base . $logo) : ($base . '/' . ltrim($logo, '/'));
}
