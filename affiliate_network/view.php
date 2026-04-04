<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ff8c00">
  <meta name="rac-csrf" content="<?= htmlspecialchars($affiliateView['csrf'], ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($affiliateView['title'], ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="manifest" href="<?= htmlspecialchars($affiliateView['manifest'], ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" href="<?= htmlspecialchars($affiliateView['icon'], ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/all.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($affiliateView['styles'], ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<?php include_once __DIR__ . '/../menu_master.php'; ?>
<div id="toast" class="toast hidden"></div>
<div id="pwaInstallNotice" class="pwa-install hidden"><div><strong>Instala RAC como aplicación</strong><div class="sub">Acceso rápido, pantalla completa y mejor experiencia offline.</div></div><button id="pwaInstallBtn" class="btn primary" type="button">📲 Instalar app</button></div>
<section id="splashScreen" class="splash"><div class="splash-glow"></div><div class="splash-logo"><i class="fa-solid fa-genie-lamp"></i></div><h1>RAC</h1><p>Red de Afiliados Cuba</p><div class="splash-sub">Catálogo privado · trazas · garantía prepaga</div></section>
<section id="homeScreen" class="home hidden"><div class="home-card"><div class="hero-lite"><div class="aladdin-hero" aria-hidden="true"><div class="smoke s1"></div><div class="smoke s2"></div><div class="smoke s3"></div><div class="smoke s4"></div><div class="wish" id="lampWish" aria-hidden="true"></div><div class="lamp-base"></div><div class="lamp-handle"></div><div class="lamp-neck"></div><div class="lamp-lid"></div><div class="lamp-spout"></div><div class="lamp-foot"></div><div class="lamp-glow"></div></div><h1>RAC · Red de Afiliados Cuba</h1><p>Dueños publican. Gestores promueven. La plataforma bloquea garantía, audita y liquida.</p><div class="home-tags"><span class="pill pill-pwa">📲 PWA</span><span class="pill pill-offline">📡 Offline</span><span class="pill pill-trace">🔗 Trazabilidad</span><span class="pill pill-guard">🛡️ Anti-salto</span></div></div><div class="role-grid"><button class="home-role" data-open-role="dueno"><span class="r-icon owner"><i class="fa-solid fa-store"></i></span><span class="r-copy"><strong>Dueño / Tienda</strong><small>Inventario invisible, wallet prepaga, comisión y cierre móvil.</small></span></button><button class="home-role" data-open-role="gestor"><span class="r-icon gestor"><i class="fa-solid fa-wand-magic-sparkles"></i></span><span class="r-copy"><strong>Genio / Gestor</strong><small>Marketplace privado, enlaces de traza y métricas de conversión.</small></span></button><button class="home-role" data-open-role="admin"><span class="r-icon admin"><i class="fa-solid fa-shield-halved"></i></span><span class="r-copy"><strong>Administrador</strong><small>BI, auditoría, revenue y vigilancia de fraude estadístico.</small></span></button></div></div></section>
<main id="mainApp" class="hidden"><header class="topbar"><div class="brand"><div class="logo"><i class="fa-solid fa-genie-lamp"></i></div><div><h1>RAC · Red de Afiliados Cuba</h1><p>Catálogo privado con traza, garantía y sincronización offline</p></div></div><div class="topbar-actions"><a class="btn ghost" href="/affiliate_network_help.php"><i class="fa-solid fa-circle-question"></i> Ayuda RAC</a><div class="segmented" id="roleSwitcher"><button class="active" data-role="dueno">🏪 Dueño</button><button data-role="gestor">🪔 Gestor</button><button data-role="admin">🛡️ Admin</button></div></div></header><div class="wrap"><section class="hero"><div class="card hero-copy"><h2>RAC: catálogo privado con traza, garantía prepaga y protección anti-salto.</h2><p>El dueño publica inventario invisible para gestores. El contacto se dispara con garantía bloqueada y la PWA sigue operando offline con sincronización diferida al volver internet.</p><div class="statusbar"><div id="netStatus" class="status-online">● En línea</div><div id="syncStatus" class="sub">Sin cambios pendientes</div></div></div><div class="hero-stats"><div class="stat"><div class="k">Volumen total</div><div class="v" id="heroTotalVolume">0 CUP</div><div class="s">Red activa</div></div><div class="stat"><div class="k">Revenue plataforma</div><div class="v" id="heroRevenue">0 CUP</div><div class="s">LAG</div></div><div class="stat"><div class="k">Dueños activos</div><div class="v" id="heroOwners">0</div><div class="s">Tiendas visibles</div></div><div class="stat"><div class="k">Gestores activos</div><div class="v" id="heroGestores">0</div><div class="s">Fuerza de ventas</div></div></div></section><section id="panel-dueno" class="panel active"></section><section id="panel-gestor" class="panel"></section><section id="panel-admin" class="panel"></section></div></main>
<div id="affBackBtn" class="back-btn hidden"><button class="btn ghost" data-go-home><i class="fa-solid fa-arrow-left"></i> Inicio</button></div>
<div id="productModalWrap" class="modal-backdrop"></div>
<div id="linkModalWrap" class="modal-backdrop"></div>
<div id="integrationModalWrap" class="modal-backdrop"></div>
<div id="flowModalWrap" class="modal-backdrop"></div>
<div id="entityModalWrap" class="modal-backdrop"></div>
<div id="walletModalWrap" class="modal-backdrop"></div>
<script>
(function(){
  const wishes=['🏠','🚗','🧀','🧺','🛋️','🧊','🍞','🥛','🍎','🍗','🪑','📺','🛏️','🚲','🧴','🍳','🥖','🫒','🍅','🧽','🪣','🚪','🪟','🧸','🛒','🧈','🥫','🫓','🍚','🧼'];
  function emitWish(){
    const node=document.getElementById('lampWish');
    if(!node){return;}
    node.textContent=wishes[Math.floor(Math.random()*wishes.length)];
    node.style.animation='none';
    node.offsetWidth;
    node.style.animation='genieWish 5.8s ease-out forwards';
    const next=3400+Math.floor(Math.random()*4200);
    window.setTimeout(emitWish,next);
  }
  window.addEventListener('load',function(){
    window.setTimeout(emitWish,2200);
  });
})();
</script>
<?php foreach (($affiliateView['scripts'] ?? []) as $script): ?>
<script src="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
