<?php

function palweb_habana_locations(): array {
    return [
        'Cerro' => [
            'El Canal' => 0.5, 'Pilar-Atares' => 2.0, 'Cerro' => 1.5, 'Las Cañas' => 2.5,
            'Palatino' => 3.0, 'Armada' => 2.0, 'Latinoamericano' => 1.0
        ],
        'Plaza de la Revolución' => [
            'Rampa' => 4.0, 'Vedado' => 4.5, 'Carmelo' => 5.5, 'Príncipe' => 2.5,
            'Plaza' => 3.0, 'Nuevo Vedado' => 4.0, 'Colón' => 3.5, 'Puentes Grandes' => 4.5
        ],
        'Centro Habana' => [
            'Cayo Hueso' => 3.0, 'Pueblo Nuevo' => 3.5, 'Los Sitios' => 3.0, 'Dragones' => 3.5,
            'Colón' => 4.0
        ],
        'La Habana Vieja' => [
            'Prado' => 4.5, 'Catedral' => 5.0, 'Plaza Vieja' => 5.0, 'Belén' => 5.0,
            'San Isidro' => 5.5, 'Jesús María' => 5.0, 'Tallapiedra' => 4.5
        ],
        'Diez de Octubre' => [
            'Luyanó' => 4.0, 'Jesús del Monte' => 3.5, 'Lawton' => 5.0, 'Víbora' => 4.5,
            'Santos Suárez' => 3.5, 'Sevillano' => 4.5, 'Vista Alegre' => 5.0, 'Tamarindo' => 3.0, 'Acosta' => 4.0
        ],
        'Playa' => [
            'Miramar' => 7.0, 'Buena Vista' => 6.0, 'Ceiba' => 5.0, 'Ampliación Almendares' => 6.0,
            'Siboney' => 9.0, 'Atabey' => 10.0, 'Santa Fe' => 12.0, 'Jaimanitas' => 11.0
        ],
        'Marianao' => [
            'CAI - Los Ángeles' => 6.0, 'Pocito - Palmar' => 7.0, 'Zamora - Cocosolo' => 6.5,
            'Libertad' => 6.0, 'Pogolotti - Finlay' => 6.5, 'Santa Felicia' => 6.0
        ],
        'La Lisa' => [
            'Alturas de La Lisa' => 9.0, 'Balcón Arimao' => 9.5, 'El Cano' => 11.0,
            'Punta Brava' => 12.0, 'Arroyo Arenas' => 10.0, 'San Agustín' => 10.0, 'Versalles' => 8.5
        ],
        'Boyeros' => [
            'Santiago de las Vegas' => 15.0, 'Nuevo Santiago' => 14.0, 'Boyeros' => 12.0,
            'Wajay' => 11.0, 'Calabazar' => 9.0, 'Altahabana' => 7.0, 'Armada' => 5.0
        ],
        'Arroyo Naranjo' => [
            'Los Pinos' => 7.0, 'Poey' => 8.0, 'Vibora Park' => 7.0, 'Mantilla' => 9.0,
            'Párraga' => 10.0, 'Callejas' => 6.5, 'Guinera' => 8.0, 'Managua' => 15.0, 'Eléctrico' => 11.0
        ],
        'San Miguel del Padrón' => [
            'Rocafort' => 6.0, 'Luyanó Moderno' => 7.0, 'Diezmero' => 8.0, 'San Francisco de Paula' => 10.0,
            'Dolores' => 6.5, 'Jacomino' => 6.0
        ],
        'Cotorro' => [
            'San Pedro' => 16.0, 'Cuatro Caminos' => 18.0, 'Magdalena' => 15.0, 'Alberro' => 16.0,
            'Santa Maria del Rosario' => 14.0, 'Lotería' => 15.0
        ],
        'Regla' => [
            'Guaicanamar' => 8.0, 'Loma Modelo' => 8.5, 'Casablanca' => 9.0
        ],
        'Guanabacoa' => [
            'Villa I' => 10.0, 'Villa II' => 10.5, 'Chibas' => 9.5, "D'Beche" => 10.0,
            'Mañana' => 10.0, 'Minas' => 12.0, 'Peñalver' => 14.0
        ],
        'La Habana del Este' => [
            'Camilo Cienfuegos' => 9.0, 'Cojímar' => 11.0, 'Guiteras' => 10.0, 'Alamar' => 12.0,
            'Guanabo' => 25.0, 'Campo Florido' => 20.0
        ]
    ];
}

function palweb_habana_norm(string $text): string {
    $t = mb_strtolower(trim($text), 'UTF-8');
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n'
    ];
    $t = strtr($t, $map);
    $t = preg_replace('/[^a-z0-9\s#,-]/u', ' ', $t) ?? '';
    $t = preg_replace('/\s+/', ' ', trim($t)) ?? '';
    return $t;
}

function palweb_habana_address_resolve(string $address): array {
    $raw = trim($address);
    if ($raw === '') {
        return ['ok' => false, 'msg' => 'Dirección vacía.'];
    }

    $norm = palweb_habana_norm($raw);
    $locations = palweb_habana_locations();
    $municipio = null;
    $barrio = null;
    $km = null;
    $municipioMatches = [];
    $barrioMatches = [];

    foreach ($locations as $mun => $barrios) {
        $munNorm = palweb_habana_norm($mun);
        if ($munNorm !== '' && str_contains($norm, $munNorm)) {
            $municipioMatches[] = $mun;
        }
        foreach ($barrios as $bar => $dist) {
            $barNorm = palweb_habana_norm($bar);
            if ($barNorm !== '' && str_contains($norm, $barNorm)) {
                $barrioMatches[] = ['municipio' => $mun, 'barrio' => $bar, 'km' => (float)$dist];
            }
        }
    }

    if (count($barrioMatches) === 1) {
        $municipio = $barrioMatches[0]['municipio'];
        $barrio = $barrioMatches[0]['barrio'];
        $km = $barrioMatches[0]['km'];
    } elseif (count($barrioMatches) > 1) {
        if (count($municipioMatches) === 1) {
            foreach ($barrioMatches as $match) {
                if ($match['municipio'] === $municipioMatches[0]) {
                    $municipio = $match['municipio'];
                    $barrio = $match['barrio'];
                    $km = $match['km'];
                    break;
                }
            }
        }
    }

    if ($municipio === null && count($municipioMatches) === 1) {
        $municipio = $municipioMatches[0];
    }

    $hasStreetHint = preg_match('/\b(calle|calz|calzada|avenida|ave|entre|esquina|reparto|edificio|edif|apartamento|apto|no|num|numero|#|casa|piso|bloque)\b/u', $norm)
        || preg_match('/\d/', $norm);

    if ($municipio === null && $barrio === null) {
        return [
            'ok' => false,
            'msg' => 'No pude ubicar esa dirección dentro de La Habana.',
            'need' => 'municipio_barrio',
            'address' => $raw
        ];
    }

    if ($barrio === null) {
        return [
            'ok' => false,
            'msg' => 'Necesito también el barrio o consejo popular para calcular la mensajería.',
            'need' => 'barrio',
            'municipio' => $municipio,
            'address' => $raw
        ];
    }

    if (!$hasStreetHint) {
        return [
            'ok' => false,
            'msg' => 'La dirección parece incompleta. Falta calle, número o referencia.',
            'need' => 'street_hint',
            'municipio' => $municipio,
            'barrio' => $barrio,
            'address' => $raw
        ];
    }

    return [
        'ok' => true,
        'address' => $raw,
        'municipio' => $municipio,
        'barrio' => $barrio,
        'distance_km' => (float)$km,
        'city' => 'La Habana',
        'country' => 'Cuba'
    ];
}

function palweb_habana_delivery_fee(float $distanceKm, float $ratePerKm = 100.0): float {
    $distanceKm = max(0.0, $distanceKm);
    return round($distanceKm * max(0.0, $ratePerKm), 2);
}
