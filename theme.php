<?php
/**
 * theme.php — Inyector de variables CSS del tema desde pos.cfg
 * Incluir con: require_once/include_once 'theme.php';
 * Debe ejecutarse DESPUÉS de cargar $config (config_loader.php).
 */
global $config, $currentConfig, $pdo;
$_t = $config ?? $currentConfig ?? [];

// Obtener banner y opciones de presentación de la sucursal activa desde DB
if ((empty($_t['sucursal_banner']) || empty($_t['banner_bg_size'])) && !empty($_t['id_sucursal'])) {
    try {
        $_pdo = $pdo ?? null;
        if ($_pdo) {
            $_stmtBnr = $_pdo->prepare("SELECT imagen_banner, banner_bg_size, banner_scale FROM sucursales WHERE id = ? LIMIT 1");
            $_stmtBnr->execute([intval($_t['id_sucursal'])]);
            $_bnrRow = $_stmtBnr->fetch(PDO::FETCH_ASSOC);
            if ($_bnrRow && !empty($_bnrRow['imagen_banner']) && file_exists(__DIR__ . '/' . $_bnrRow['imagen_banner'])) {
                $_t['sucursal_banner']  = $_bnrRow['imagen_banner'];
                $_t['banner_bg_size']   = $_bnrRow['banner_bg_size']  ?? 'cover';
                $_t['banner_scale']     = (int)($_bnrRow['banner_scale'] ?? 100);
            }
        }
    } catch (Exception $_e) { /* silenciar errores de DB */ }
}

$c1  = preg_match('/^#[0-9a-fA-F]{3,8}$/', $_t['hero_color_1'] ?? '') ? $_t['hero_color_1'] : '#0d6efd';
$c2  = preg_match('/^#[0-9a-fA-F]{3,8}$/', $_t['hero_color_2'] ?? '') ? $_t['hero_color_2'] : '#0f766e';

// Calcular versión oscurecida del color 1 para hover/active
function _theme_darken(string $hex, int $pct = 15): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = max(0, hexdec(substr($hex,0,2)) - (int)(255 * $pct / 100));
    $g = max(0, hexdec(substr($hex,2,2)) - (int)(255 * $pct / 100));
    $b = max(0, hexdec(substr($hex,4,2)) - (int)(255 * $pct / 100));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$c1Dark = _theme_darken($c1, 18);
$c2Dark = _theme_darken($c2, 18);
?>
<style id="palweb-theme-vars">
:root {
    --primary-color:      <?= $c1 ?>;
    --primary-dark:       <?= $c1Dark ?>;
    --secondary-color:    <?= $c2 ?>;
    --secondary-dark:     <?= $c2Dark ?>;
    --hero-gradient:      linear-gradient(135deg, <?= $c1 ?> 0%, <?= $c2 ?> 100%);
    --hero-gradient-rev:  linear-gradient(135deg, <?= $c2 ?> 0%, <?= $c1 ?> 100%);
}

/* Parches globales para que los módulos que usan clases Bootstrap o custom
   reflejen automáticamente el color configurado */
.bg-primary-custom  { background: var(--primary-color) !important; }
.btn-primary-custom { background: var(--hero-gradient) !important; border-color: var(--primary-color) !important; color: #fff !important; }
.text-primary-custom { color: var(--primary-color) !important; }
.border-primary-custom { border-color: var(--primary-color) !important; }

/* Modal headers que usan bg-primary / bg-primary-custom */
.modal-header.bg-primary { background: var(--hero-gradient) !important; }

/* Botones Bootstrap primary */
.btn-primary {
    background: var(--hero-gradient) !important;
    border-color: var(--primary-color) !important;
}
.btn-primary:hover, .btn-primary:focus, .btn-primary:active {
    background: var(--hero-gradient-rev) !important;
    border-color: var(--primary-dark) !important;
}

/* Nav/tabs activos */
.nav-link.active, .nav-pills .nav-link.active {
    background: var(--hero-gradient) !important;
}

/* Progress bar primary */
.progress-bar.bg-primary, .progress-bar:not([class*="bg-"]) {
    background: var(--hero-gradient) !important;
}

/* ── Hero banner — override directo para evitar caché del CSS externo ── */
.inventory-hero,
section.inventory-hero {
    background:
        linear-gradient(135deg, <?= $c1 ?> 0%, <?= $c2 ?> 100%),
        linear-gradient(120deg, rgba(255,255,255,.12), rgba(255,255,255,0)) !important;
}

<?php if (!empty($_t['sucursal_banner']) && (($_t['hero_mostrar_banner_sucursal'] ?? true) !== false)): ?>
<?php
    $_bgMode  = $_t['banner_bg_size'] ?? 'cover';
    $_bgScale = (int)($_t['banner_scale'] ?? 100);
    // Calcular el valor CSS de background-size
    if ($_bgMode === 'cover') {
        // Escala 100% = cover normal; otros valores = "zoom" via percentage
        $_bgSizeCss = ($_bgScale === 100) ? 'cover' : ($_bgScale . '% auto');
    } elseif ($_bgMode === 'contain') {
        $_bgSizeCss = $_bgScale . '%';
    } else {
        // auto = tamaño real, escala modifica con porcentaje
        $_bgSizeCss = ($_bgScale === 100) ? 'auto' : ($_bgScale . '%');
    }
?>
.inventory-hero, section.inventory-hero {
    background-image:
        linear-gradient(rgba(0,0,0,0.3), rgba(0,0,0,0.3)),
        url(<?= htmlspecialchars($_t['sucursal_banner']) ?>) !important;
    background-size: <?= $_bgSizeCss ?> !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
}
<?php endif; ?>

/* Hero de dashboard.php si usa clases propias */
.hero-panel,
.dash-hero {
    background: linear-gradient(135deg, <?= $c1 ?> 0%, <?= $c2 ?> 100%) !important;
}

/* Logo corporativo en hero — posición esquina inferior derecha */
.pw-hero-logo {
    position: absolute;
    bottom: 14px;
    right: 20px;
    max-height: 68px;
    max-width: 150px;
    object-fit: contain;
    opacity: 0.85;
    pointer-events: none;
    mix-blend-mode: screen;   /* fondo blanco/claro desaparece sobre el gradiente oscuro */
    /* Oculto en móvil — visible solo en md+ */
    display: none;
}
@media (min-width: 768px) {
    .pw-hero-logo { display: block; }
}
</style>
<?php
$_heroLogo       = trim((string)($_t['marca_empresa_logo'] ?? ''));
$_heroMostrarLogo = ($_t['hero_mostrar_logo'] ?? true) !== false;
if ($_heroLogo && $_heroMostrarLogo && file_exists(__DIR__ . '/' . $_heroLogo)):
?>
<script>
(function(){
    function _injectHeroLogo() {
        const src   = <?= json_encode($_heroLogo) ?>;
        const heros = document.querySelectorAll('.inventory-hero, .hero-panel, .dash-hero');
        heros.forEach(function(h) {
            if (h.querySelector('.pw-hero-logo')) return; // ya inyectado
            const pos = getComputedStyle(h).position;
            if (pos === 'static') h.style.position = 'relative';
            const img = document.createElement('img');
            img.src       = src;
            img.alt       = '';
            img.className = 'pw-hero-logo';
            h.appendChild(img);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _injectHeroLogo);
    } else {
        _injectHeroLogo();
    }
})();
</script>
<?php endif; ?>
