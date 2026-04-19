<?php
// ============================================================================
// ARCHIVO: shop_skins.php
// Sistema de apariencias (skins) para shop.php — por sucursal
// Añadir más skins: simplemente agregar una entrada al array $SHOP_SKINS
// Un futuro editor visual podrá escribir directamente en este archivo o en un
// archivo JSON complementario.
// ============================================================================

define('SHOP_SKINS_CUSTOM_FILE', __DIR__ . '/shop_skins_custom.json');

if (!function_exists('shop_skin_all')) {

    function shop_skin_builtin(): array {
        return [
            // ═════════════════════ 1. CLÁSICO MARINO (default) ══════════════
            'clasico_marino' => [
                'nombre'       => 'Clásico Marino',
                'descripcion'  => 'Diseño original azul/morado con tipografía Inter. Profesional y equilibrado.',
                'preview'      => ['#667eea', '#764ba2', '#0d6efd'],
                'font_google'  => 'Inter:wght@400;500;600;700',
                'body_font'    => "'Inter', system-ui, sans-serif",
                'heading_font' => "'Inter', system-ui, sans-serif",
                'vars' => [
                    '--primary'        => '#0d6efd',
                    '--secondary'      => '#6c757d',
                    '--success'        => '#10b981',
                    '--danger'         => '#ef4444',
                    '--body-bg'        => '#f9fafb',
                    '--body-fg'        => '#1f2937',
                    '--nav-grad-1'     => '#667eea',
                    '--nav-grad-2'     => '#764ba2',
                    '--card-radius'    => '16px',
                    '--card-shadow'    => '0 4px 12px rgba(0,0,0,0.06)',
                ],
                'body_class' => 'skin-clasico',
                'extra_css'  => '',
            ],

            // ═════════════════════ 2. ELEGANTE OSCURO ════════════════════════
            'elegante_oscuro' => [
                'nombre'       => 'Elegante Oscuro',
                'descripcion'  => 'Modo oscuro premium, tipografías serif para títulos, acentos dorados.',
                'preview'      => ['#111827', '#fbbf24', '#f3f4f6'],
                'font_google'  => 'Playfair+Display:wght@600;700&family=Inter:wght@400;500;600',
                'body_font'    => "'Inter', system-ui, sans-serif",
                'heading_font' => "'Playfair Display', Georgia, serif",
                'vars' => [
                    '--primary'     => '#fbbf24',
                    '--secondary'   => '#9ca3af',
                    '--success'     => '#34d399',
                    '--danger'      => '#f87171',
                    '--body-bg'     => '#111827',
                    '--body-fg'     => '#f3f4f6',
                    '--nav-grad-1'  => '#000000',
                    '--nav-grad-2'  => '#1f2937',
                    '--card-radius' => '8px',
                    '--card-shadow' => '0 10px 30px rgba(0,0,0,0.5)',
                ],
                'body_class' => 'skin-oscuro',
                'extra_css' => "
                    body.skin-oscuro .card, body.skin-oscuro .bg-light { background: #1f2937 !important; color: #f3f4f6 !important; }
                    body.skin-oscuro .text-muted { color: #9ca3af !important; }
                    body.skin-oscuro h1, body.skin-oscuro h2, body.skin-oscuro h3, body.skin-oscuro h4, body.skin-oscuro h5 { font-family: 'Playfair Display', Georgia, serif; letter-spacing: -0.02em; }
                    body.skin-oscuro .product-card { background: #1f2937; border: 1px solid #374151; }
                    body.skin-oscuro .modal-content { background: #1f2937; color: #f3f4f6; }
                    body.skin-oscuro .form-control, body.skin-oscuro .form-select { background: #374151; color: #f3f4f6; border-color: #4b5563; }
                ",
            ],

            // ═════════════════════ 3. TROPICAL VIBRANTE ══════════════════════
            'tropical_vibrante' => [
                'nombre'       => 'Tropical Vibrante',
                'descripcion'  => 'Naranjas y verdes brillantes, inspirado en frutas cubanas, Poppins rounded.',
                'preview'      => ['#f59e0b', '#10b981', '#ef4444'],
                'font_google'  => 'Poppins:wght@400;500;600;700;800',
                'body_font'    => "'Poppins', sans-serif",
                'heading_font' => "'Poppins', sans-serif",
                'vars' => [
                    '--primary'     => '#f59e0b',
                    '--secondary'   => '#10b981',
                    '--success'     => '#22c55e',
                    '--danger'      => '#ef4444',
                    '--body-bg'     => '#fffbeb',
                    '--body-fg'     => '#1c1917',
                    '--nav-grad-1'  => '#f59e0b',
                    '--nav-grad-2'  => '#10b981',
                    '--card-radius' => '24px',
                    '--card-shadow' => '0 8px 24px rgba(245,158,11,0.15)',
                ],
                'body_class' => 'skin-tropical',
                'extra_css' => "
                    body.skin-tropical h1, body.skin-tropical h2, body.skin-tropical h3 { font-weight: 800; }
                    body.skin-tropical .btn { border-radius: 999px !important; font-weight: 600; }
                    body.skin-tropical .badge { border-radius: 999px !important; }
                ",
            ],

            // ═════════════════════ 4. MINIMALISTA MONO ═══════════════════════
            'minimalista_mono' => [
                'nombre'       => 'Minimalista Mono',
                'descripcion'  => 'Blanco y negro puro, sin adornos, bordes rectos, Space Grotesk.',
                'preview'      => ['#000000', '#ffffff', '#e5e7eb'],
                'font_google'  => 'Space+Grotesk:wght@400;500;600;700',
                'body_font'    => "'Space Grotesk', system-ui, sans-serif",
                'heading_font' => "'Space Grotesk', system-ui, sans-serif",
                'vars' => [
                    '--primary'     => '#000000',
                    '--secondary'   => '#525252',
                    '--success'     => '#059669',
                    '--danger'      => '#dc2626',
                    '--body-bg'     => '#ffffff',
                    '--body-fg'     => '#0a0a0a',
                    '--nav-grad-1'  => '#000000',
                    '--nav-grad-2'  => '#262626',
                    '--card-radius' => '2px',
                    '--card-shadow' => 'none',
                ],
                'body_class' => 'skin-mono',
                'extra_css' => "
                    body.skin-mono .card, body.skin-mono .product-card { border: 1px solid #e5e7eb !important; box-shadow: none !important; }
                    body.skin-mono .btn { border-radius: 2px !important; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.85rem; }
                    body.skin-mono .badge { border-radius: 2px !important; }
                    body.skin-mono h1, body.skin-mono h2, body.skin-mono h3 { letter-spacing: -0.04em; font-weight: 700; }
                ",
            ],

            // ═════════════════════ 5. ARTESANAL RÚSTICO ══════════════════════
            'artesanal_rustico' => [
                'nombre'       => 'Artesanal Rústico',
                'descripcion'  => 'Tonos tierra, cálido, Merriweather serif. Ideal para productos artesanales.',
                'preview'      => ['#78350f', '#fef3c7', '#a16207'],
                'font_google'  => 'Merriweather:wght@400;700;900&family=Caveat:wght@400;700',
                'body_font'    => "'Merriweather', Georgia, serif",
                'heading_font' => "'Caveat', cursive",
                'vars' => [
                    '--primary'     => '#78350f',
                    '--secondary'   => '#a16207',
                    '--success'     => '#65a30d',
                    '--danger'      => '#b91c1c',
                    '--body-bg'     => '#fef3c7',
                    '--body-fg'     => '#451a03',
                    '--nav-grad-1'  => '#78350f',
                    '--nav-grad-2'  => '#a16207',
                    '--card-radius' => '12px',
                    '--card-shadow' => '0 6px 14px rgba(120,53,15,0.18)',
                ],
                'body_class' => 'skin-rustico',
                'extra_css' => "
                    body.skin-rustico h1, body.skin-rustico h2 { font-family: 'Caveat', cursive; font-size: 2.4em; }
                    body.skin-rustico .card { background: #fffbeb; border: 1px solid #fde68a; }
                    body.skin-rustico .btn-primary { background: #78350f; border-color: #78350f; }
                ",
            ],

            // ═════════════════════ 6. MODERNO NEON ═══════════════════════════
            'moderno_neon' => [
                'nombre'       => 'Moderno Neón',
                'descripcion'  => 'Futurista, gradientes púrpura/cian, tipografía técnica Rajdhani, glow.',
                'preview'      => ['#a855f7', '#06b6d4', '#0f172a'],
                'font_google'  => 'Rajdhani:wght@400;500;600;700&family=Inter:wght@400;500',
                'body_font'    => "'Inter', system-ui, sans-serif",
                'heading_font' => "'Rajdhani', system-ui, sans-serif",
                'vars' => [
                    '--primary'     => '#a855f7',
                    '--secondary'   => '#06b6d4',
                    '--success'     => '#22d3ee',
                    '--danger'      => '#f43f5e',
                    '--body-bg'     => '#0f172a',
                    '--body-fg'     => '#e2e8f0',
                    '--nav-grad-1'  => '#a855f7',
                    '--nav-grad-2'  => '#06b6d4',
                    '--card-radius' => '10px',
                    '--card-shadow' => '0 0 20px rgba(168,85,247,0.35)',
                ],
                'body_class' => 'skin-neon',
                'extra_css' => "
                    body.skin-neon { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
                    body.skin-neon h1, body.skin-neon h2, body.skin-neon h3 { font-family: 'Rajdhani', sans-serif; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; }
                    body.skin-neon .card, body.skin-neon .product-card { background: #1e293b; border: 1px solid #334155; color: #e2e8f0; }
                    body.skin-neon .btn-primary { background: linear-gradient(135deg, #a855f7, #06b6d4); border: none; box-shadow: 0 0 15px rgba(168,85,247,0.5); }
                    body.skin-neon .text-muted { color: #94a3b8 !important; }
                    body.skin-neon .modal-content { background: #1e293b; color: #e2e8f0; }
                    body.skin-neon .form-control, body.skin-neon .form-select { background: #0f172a; color: #e2e8f0; border-color: #334155; }
                ",
            ],
        ];
    }

    function shop_skin_custom(): array {
        if (!file_exists(SHOP_SKINS_CUSTOM_FILE)) return [];
        $data = json_decode(file_get_contents(SHOP_SKINS_CUSTOM_FILE), true);
        return is_array($data) ? $data : [];
    }

    function shop_skin_all(): array {
        return array_merge(shop_skin_builtin(), shop_skin_custom());
    }

    function shop_skin_default(): string { return 'clasico_marino'; }

    function shop_skin_get(string $id): array {
        $all = shop_skin_all();
        return $all[$id] ?? $all[shop_skin_default()];
    }

    // Devuelve el skin asignado a una sucursal (según pos.cfg → sucursal_skins)
    function shop_skin_for_sucursal(int $sucId, array $config): string {
        $map = $config['sucursal_skins'] ?? [];
        $skinId = (string)($map[(string)$sucId] ?? $map[$sucId] ?? '');
        $all = shop_skin_all();
        return isset($all[$skinId]) ? $skinId : shop_skin_default();
    }

    // Renderiza el <link> de Google Fonts + <style> con variables y extras
    // Mapa: clave font_google → archivo CSS local en assets/fonts/
    // Permite servir fuentes sin internet (LAN offline).
    function shop_skin_local_font_css(string $fontGoogle): string {
        static $map = [
            'Inter'            => 'inter.css',
            'Playfair+Display' => 'playfair.css',
            'Playfair Display' => 'playfair.css',
            'Poppins'          => 'poppins.css',
            'Space+Grotesk'    => 'space-grotesk.css',
            'Space Grotesk'    => 'space-grotesk.css',
            'Merriweather'     => 'merriweather.css',
            'Caveat'           => 'caveat.css',
            'Rajdhani'         => 'rajdhani.css',
        ];
        // Extraer familias únicas mencionadas en font_google
        $files = [];
        foreach ($map as $name => $file) {
            if (stripos($fontGoogle, $name) !== false) {
                $files[$file] = true;
            }
        }
        return implode('', array_map(
            fn($f) => "assets/fonts/{$f}",
            array_keys($files)
        ));
    }

    function shop_skin_render(string $skinId): string {
        $skin = shop_skin_get($skinId);
        $out  = '';

        if (!empty($skin['font_google'])) {
            // Servir fuentes en local — sin petición a fonts.googleapis.com
            $localFiles = [];
            static $fontMap = [
                'Inter'            => 'inter.css',
                'Playfair+Display' => 'playfair.css',
                'Playfair Display' => 'playfair.css',
                'Poppins'          => 'poppins.css',
                'Space+Grotesk'    => 'space-grotesk.css',
                'Space Grotesk'    => 'space-grotesk.css',
                'Merriweather'     => 'merriweather.css',
                'Caveat'           => 'caveat.css',
                'Rajdhani'         => 'rajdhani.css',
            ];
            foreach ($fontMap as $name => $file) {
                if (stripos($skin['font_google'], $name) !== false) {
                    $localFiles[$file] = true;
                }
            }
            foreach (array_keys($localFiles) as $file) {
                $out .= "<link rel=\"preload\" href=\"assets/fonts/{$file}\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\">\n";
                $out .= "<noscript><link rel=\"stylesheet\" href=\"assets/fonts/{$file}\"></noscript>\n";
            }
        }

        $vars = '';
        foreach (($skin['vars'] ?? []) as $k => $v) {
            $vars .= "            $k: $v;\n";
        }

        $bodyClass   = $skin['body_class']   ?? '';
        $bodyFont    = $skin['body_font']    ?? "'Inter', sans-serif";
        $headingFont = $skin['heading_font'] ?? $bodyFont;
        $extra       = $skin['extra_css']    ?? '';

        $out .= "<style id=\"shop-skin-style\">\n";
        $out .= "        :root {\n{$vars}        }\n";
        $out .= "        body { font-family: {$bodyFont}; background: var(--body-bg, #f9fafb); color: var(--body-fg, #1f2937); }\n";
        $out .= "        h1,h2,h3,h4,h5,h6 { font-family: {$headingFont}; }\n";
        $out .= "        .navbar-premium { background: linear-gradient(135deg, var(--nav-grad-1) 0%, var(--nav-grad-2) 100%) !important; }\n";
        $out .= $extra . "\n";
        $out .= "    </style>\n";

        // Meta tag con la clase para que shop.php la pueda aplicar al <body>
        $out .= "<meta name=\"shop-skin-body-class\" content=\"" . htmlspecialchars($bodyClass, ENT_QUOTES) . "\">\n";

        return $out;
    }
}
