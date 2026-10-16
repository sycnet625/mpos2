<?php
// Reglas compartidas por catálogo y checkout: el servidor decide precios y envío.
function shop_round_price_up_to_5(float $value): float {
    if ($value <= 0) {
        return 0.0;
    }
    return (float)(ceil($value / 5) * 5);
}

function shop_apply_price_rule(float $price, array $config, ?float $wholesalePrice = null): float {
    $mode = trim((string)($config['shop_price_mode'] ?? 'normal'));
    $customPct = (float)($config['shop_price_custom_pct'] ?? 0);
    $roundTo5 = !empty($config['shop_price_round_to_5']);
    $result = $price;

    if ($mode === 'mayorista') {
        $candidate = (float)($wholesalePrice ?? 0);
        if ($candidate > 0) {
            $result = $candidate;
        }
    } elseif ($mode === 'custom') {
        $result = $price * (1 + ($customPct / 100));
    }

    if ($roundTo5) {
        $result = shop_round_price_up_to_5($result);
    }

    return round(max(0, $result), 2);
}

function shop_apply_price_config_to_row(array &$row, array $config): void {
    $basePrice = (float)($row['precio_base'] ?? $row['precio'] ?? 0);
    $wholesalePrice = (float)($row['precio_mayorista_base'] ?? 0);
    $offerBase = (float)($row['precio_oferta_base'] ?? $row['precio_oferta'] ?? 0);

    $row['precio'] = shop_apply_price_rule($basePrice, $config, $wholesalePrice);
    $row['precio_oferta'] = $offerBase > 0
        ? shop_apply_price_rule($offerBase, $config, $wholesalePrice)
        : 0.0;

    if ($row['precio_oferta'] > 0 && $row['precio_oferta'] >= $row['precio']) {
        $row['precio_oferta'] = 0.0;
    }
}

function shop_delivery_locations(): array {
    return [
        "Cerro" => ["El Canal" => 0.5, "Pilar-Atares" => 2.0, "Cerro" => 1.5, "Las Cañas" => 2.5, "Palatino" => 3.0, "Armada" => 2.0, "Latinoamericano" => 1.0],
        "Plaza" => ["Rampa" => 4.0, "Vedado" => 4.5, "Carmelo" => 5.5, "Príncipe" => 2.5, "Plaza" => 3.0, "Nuevo Vedado" => 4.0],
        "Centro Habana" => ["Cayo Hueso" => 3.0, "Pueblo Nuevo" => 3.5, "Los Sitios" => 3.0, "Dragones" => 3.5, "Colón" => 4.0],
        "Habana Vieja" => ["Prado" => 4.5, "Catedral" => 5.0, "Plaza Vieja" => 5.0, "Belén" => 5.0, "San Isidro" => 5.5],
        "Diez de Octubre" => ["Luyanó" => 4.0, "Jesús del Monte" => 3.5, "Lawton" => 5.0, "Víbora" => 4.5, "Santos Suárez" => 3.5, "Sevillano" => 4.5],
        "Playa" => ["Miramar" => 7.0, "Buena Vista" => 6.0, "Ceiba" => 5.0, "Siboney" => 9.0, "Santa Fe" => 12.0],
        "Marianao" => ["Los Ángeles" => 6.0, "Pocito" => 7.0, "Zamora" => 6.5, "Libertad" => 6.0],
        "La Lisa" => ["La Lisa" => 9.0, "El Cano" => 11.0, "Punta Brava" => 12.0, "San Agustín" => 10.0],
        "Boyeros" => ["Santiago de las Vegas" => 15.0, "Boyeros" => 12.0, "Wajay" => 11.0, "Calabazar" => 9.0, "Altahabana" => 7.0],
        "Arroyo Naranjo" => ["Los Pinos" => 7.0, "Poey" => 8.0, "Vibora Park" => 7.0, "Mantilla" => 9.0, "Managua" => 15.0],
        "San Miguel" => ["Rocafort" => 6.0, "Luyanó Moderno" => 7.0, "Diezmero" => 8.0, "San Francisco" => 10.0],
        "Cotorro" => ["San Pedro" => 16.0, "Cuatro Caminos" => 18.0, "Santa Maria" => 14.0],
        "Regla" => ["Guaicanamar" => 8.0, "Casablanca" => 9.0],
        "Guanabacoa" => ["Villa I" => 10.0, "Chibas" => 9.5, "Minas" => 12.0],
        "Habana del Este" => ["Camilo Cienfuegos" => 9.0, "Cojímar" => 11.0, "Guiteras" => 10.0, "Alamar" => 12.0, "Guanabo" => 25.0]
    ];
}

