<?php
// ARCHIVO: inventory_suite_layout.php
// UI COMPONENTS FOR INVENTORY SUITE

// Inyectar CSS dinámico basado en configuración si existe
// Evitar inyectar en respuestas AJAX o POST (que suelen ser API)
if ((isset($config) || isset($currentConfig)) 
    && !isset($_GET['ajax_load']) 
    && !isset($_GET['inventario_api'])
    && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $_c = $config ?? $currentConfig;
    $c1 = $_c['hero_color_1'] ?? '#0f766e';
    $c2 = $_c['hero_color_2'] ?? '#15803d';
    echo "<style>
        .inventory-hero {
            background: linear-gradient(135deg, {$c1}ee, {$c2}c6) !important;
        }
    </style>";
}

if (!function_exists('inventory_suite_shell_open')) {
    function inventory_suite_shell_open(string $extraClasses = ''): void {
        $classes = trim('inventory-shell ' . $extraClasses);
        echo '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('inventory_suite_shell_close')) {
    function inventory_suite_shell_close(): void {
        echo '</div>';
    }
}

if (!function_exists('inventory_suite_render_hero')) {
    function inventory_suite_render_hero(array $config): void {
        $eyebrow = (string)($config['eyebrow'] ?? '');
        $title = (string)($config['title'] ?? '');
        $description = (string)($config['description'] ?? '');
        $chips = is_array($config['chips'] ?? null) ? $config['chips'] : [];
        $actions = is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $heroClass = trim('inventory-hero ' . (string)($config['class'] ?? ''));

        echo '<section class="' . htmlspecialchars($heroClass, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="inventory-hero__content">';
        if ($eyebrow !== '') {
            echo '<div class="inventory-hero__eyebrow">' . $eyebrow . '</div>';
        }

        // Mostrar usuario si está configurado
        global $config, $currentConfig;
        $_c = $config ?? $currentConfig;
        if (!empty($_c['hero_mostrar_usuario']) && !empty($_SESSION['admin_user_name'])) {
            echo '<div class="badge bg-white bg-opacity-10 text-white mb-2" style="font-size:0.7rem; border:1px solid rgba(255,255,255,0.2);">
                    <i class="fas fa-user-circle me-1"></i> Sesión: ' . htmlspecialchars($_SESSION['admin_user_name']) . '
                  </div>';
        }

        if ($title !== '') {
            echo '<h1 class="inventory-hero__title">' . $title . '</h1>';
        }
        if ($description !== '') {
            echo '<p class="inventory-hero__description">' . $description . '</p>';
        }
        if ($chips) {
            echo '<div class="inventory-hero__chips">';
            foreach ($chips as $chip) {
                echo '<span class="kpi-chip">' . $chip . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
        if ($actions) {
            echo '<div class="inventory-hero__actions">';
            foreach ($actions as $action) {
                echo $action;
            }
            echo '</div>';
        }
        echo '</section>';
    }
}

if (!function_exists('inventory_suite_render_toolbar')) {
    function inventory_suite_render_toolbar(array $config): void {
        $class = trim('inventory-toolbar glass-card ' . (string)($config['class'] ?? ''));
        $left = is_array($config['left'] ?? null) ? $config['left'] : [];
        $right = is_array($config['right'] ?? null) ? $config['right'] : [];
        $title = (string)($config['title'] ?? '');
        $subtitle = (string)($config['subtitle'] ?? '');

        echo '<section class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="inventory-toolbar__main">';
        if ($title !== '' || $subtitle !== '') {
            echo '<div class="inventory-toolbar__intro">';
            if ($title !== '') {
                echo '<div class="section-title mb-1">' . $title . '</div>';
            }
            if ($subtitle !== '') {
                echo '<div class="inventory-toolbar__subtitle">' . $subtitle . '</div>';
            }
            echo '</div>';
        }
        if ($left) {
            echo '<div class="inventory-toolbar__left">';
            foreach ($left as $item) {
                echo $item;
            }
            echo '</div>';
        }
        echo '</div>';
        if ($right) {
            echo '<div class="inventory-toolbar__right">';
            foreach ($right as $item) {
                echo $item;
            }
            echo '</div>';
        }
        echo '</section>';
    }
}
