<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ff8c00">
  <title>Ayuda RAC</title>
  <link rel="manifest" href="/affiliate_network_manifest.json">
  <link rel="icon" href="/affiliate_network_icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/all.min.css">
  <style>
    :root{--bg:#0a0800;--panel:rgba(255,215,0,.04);--border:rgba(255,215,0,.12);--gold:#ffd700;--amber:#ff8c00;--fire:#ff4500;--text:#f7f3d4;--muted:#9c9270;--success:#22c55e;--danger:#ef5350}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,rgba(255,140,0,.10),transparent 25%),linear-gradient(180deg,#0a0800,#100b00);color:var(--text);font-family:Georgia,"Times New Roman",serif}
    .topbar{position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 20px;background:rgba(0,0,0,.35);backdrop-filter:blur(10px);border-bottom:1px solid var(--border)}
    .brand{display:flex;align-items:center;gap:12px}.logo{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--gold),var(--amber));display:grid;place-items:center;color:#140c00;font-size:22px}.brand h1{margin:0;font-size:18px;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--amber),var(--fire));-webkit-background-clip:text;-webkit-text-fill-color:transparent}.brand p{margin:3px 0 0;font:12px/1.2 system-ui,sans-serif;color:var(--muted)}
    .wrap{max-width:1100px;margin:0 auto;padding:24px 16px 40px}.hero,.card{background:var(--panel);border:1px solid var(--border);border-radius:20px;padding:22px}.hero{margin-bottom:18px}.hero h2{margin:0 0 10px;font-size:32px;line-height:1;font-weight:900}.hero p,.card p,.card li{font:14px/1.75 system-ui,sans-serif;color:var(--muted)}
    .grid{display:grid;gap:14px}.grid.two{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}.section-title{margin:0 0 12px;font-size:18px;font-weight:900}.sub{font:700 11px/1 system-ui,sans-serif;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:10px}.pill{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;border:1px solid rgba(255,215,0,.14);background:rgba(255,255,255,.03);font:700 11px/1 system-ui,sans-serif;color:var(--gold);margin:0 8px 8px 0}.flow{display:grid;gap:10px}.step{display:flex;gap:12px;align-items:flex-start;padding:14px;border-radius:16px;background:rgba(255,255,255,.02);border:1px solid rgba(255,215,0,.08)}.step .num{width:34px;height:34px;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--amber));display:grid;place-items:center;color:#130c00;font:900 14px/1 system-ui,sans-serif;flex:0 0 auto}
    .btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border);background:rgba(255,215,0,.06);color:var(--text);padding:10px 14px;border-radius:999px;font:700 12px/1 system-ui,sans-serif;text-decoration:none}.btn.primary{background:linear-gradient(135deg,var(--gold),var(--amber));color:#140c00;border:none}
    code{background:rgba(255,215,0,.08);padding:2px 6px;border-radius:6px;color:var(--amber)}
  </style>
</head>
<body>
<?php include_once __DIR__ . '/menu_master.php'; ?>
<div class="topbar">
  <div class="brand">
    <div class="logo"><i class="fa-solid fa-genie-lamp"></i></div>
    <div>
      <h1>RAC · Ayuda operativa</h1>
      <p>Guía funcional y estratégica de la Red de Afiliados Cuba</p>
    </div>
  </div>
  <div>
    <a class="btn" href="affiliate_network.php"><i class="fa-solid fa-arrow-left"></i> Volver al módulo</a>
  </div>
</div>
<div class="wrap">
  <section class="hero">
    <div class="sub">Visión general</div>
    <h2>RAC es un catálogo privado con traza, garantía prepaga y fuerza de ventas distribuida.</h2>
    <p>El dueño publica inventario invisible para el cliente final. El gestor promueve mediante enlaces únicos. La plataforma bloquea comisión al abrir el contacto, liquida 80% al gestor y 20% a la plataforma si se concreta la venta, y devuelve la garantía si no se concretó.</p>
    <div style="margin-top:14px">
      <span class="pill">🏪 Dueños publican</span>
      <span class="pill">🪔 Gestores promueven</span>
      <span class="pill">🛡️ RAC audita y liquida</span>
      <span class="pill">📶 PWA offline-first</span>
    </div>
  </section>

  <div class="grid two">
    <section class="card">
      <h3 class="section-title">Panel del Dueño</h3>
      <p>El dueño controla inventario, comisión, saldo de garantía y tasa de conversión. Sus productos solo aparecen en el marketplace de gestores si tiene saldo disponible.</p>
      <ul>
        <li><strong>Inventario invisible:</strong> productos con ficha técnica, stock, precio, marca y foto.</li>
        <li><strong>Comisión configurable:</strong> fija en CUP o equivalente porcentual visible al gestor.</li>
        <li><strong>Wallet prepaga:</strong> si el saldo llega a cero, el catálogo del dueño deja de mostrarse.</li>
        <li><strong>Asistente de precios:</strong> el sistema sugiere <em>moda</em> y <em>media ponderada</em> para productos similares.</li>
        <li><strong>Cierre de venta móvil:</strong> un lead se resuelve con un toque en <code>Vendido</code> o <code>No concretado</code>.</li>
      </ul>
    </section>

    <section class="card">
      <h3 class="section-title">Panel del Gestor</h3>
      <p>El gestor ve comisiones, genera enlaces enmascarados y mide su rendimiento sin acceso directo al contacto del dueño hasta que el cliente pulsa el disparador de contacto.</p>
      <ul>
        <li><strong>Marketplace de comisiones:</strong> filtrado por categoría, marca, precio, tendencia y rentabilidad.</li>
        <li><strong>Enlace de traza:</strong> formato lógico <code>dominio/refer/ID_PRODUCTO?ref=ID_GESTOR_ENMASCARADO</code>.</li>
        <li><strong>Dashboard:</strong> clics, contactos, conversión y comisiones por cobrar.</li>
        <li><strong>Reputación del dueño:</strong> el gestor decide qué catálogo vale la pena mover.</li>
      </ul>
    </section>

    <section class="card">
      <h3 class="section-title">Panel del Administrador</h3>
      <p>La plataforma centraliza tendencias, revenue y auditoría. También puede monetizar por suscripción de gestión y por visibilidad prioritaria.</p>
      <ul>
        <li><strong>BI oculto:</strong> productos con más interés y dueños con mejor conversión.</li>
        <li><strong>Suscripción de gestión:</strong> servicio para dueños que delegan inventario.</li>
        <li><strong>Auditoría:</strong> alertas por fraude estadístico, saldo agotado y baja conversión.</li>
        <li><strong>Revenue:</strong> 20% de cada comisión confirmada.</li>
      </ul>
    </section>

    <section class="card">
      <h3 class="section-title">Protección anti-salto</h3>
      <p>La comisión se protege en el momento del acceso al contacto. No se espera al cierre para reservar garantía.</p>
      <ul>
        <li><strong>Bloqueo de comisión:</strong> pasa de saldo disponible a saldo bloqueado al abrir contacto.</li>
        <li><strong>Tasa de conversión:</strong> si un dueño recibe muchos leads y reporta cero ventas, sube el riesgo.</li>
        <li><strong>Cupón o beneficio:</strong> fuerza validación del lead desde el lado del cliente.</li>
        <li><strong>Mystery shopping:</strong> mecanismo operativo para comprobar evasión.</li>
      </ul>
    </section>
  </div>

  <section class="card" style="margin-top:18px">
    <div class="sub">Flujo operativo</div>
    <h3 class="section-title">Paso a paso del lead con garantía</h3>
    <div class="flow">
      <div class="step"><div class="num">1</div><div><strong>Activación de tienda.</strong><p>El dueño crea cuenta y carga saldo mínimo de garantía. Sin saldo no hay visibilidad para gestores.</p></div></div>
      <div class="step"><div class="num">2</div><div><strong>Selección y promoción.</strong><p>El gestor elige producto, genera enlace único y lo difunde por WhatsApp, Facebook, Revolico u otros canales.</p></div></div>
      <div class="step"><div class="num">3</div><div><strong>Click del cliente.</strong><p>El cliente ve ficha del producto pero todavía no ve teléfono ni dirección del dueño.</p></div></div>
      <div class="step"><div class="num">4</div><div><strong>Trigger de contacto.</strong><p>Al pulsar <em>Contactar al vendedor</em>, RAC bloquea la comisión, registra el lead y redirige al WhatsApp del dueño con código de traza.</p></div></div>
      <div class="step"><div class="num">5</div><div><strong>Cierre.</strong><p>Si se vende, 80% de la comisión va al gestor y 20% a la plataforma. Si no se vende, la garantía vuelve al saldo disponible del dueño.</p></div></div>
    </div>
  </section>

  <section class="card" style="margin-top:18px">
    <div class="sub">Tecnología</div>
    <h3 class="section-title">Cómo está montado el módulo actual</h3>
    <ul>
      <li><strong>PWA:</strong> instalable, con manifest, icono y service worker.</li>
      <li><strong>Offline:</strong> el shell se cachea, el último bootstrap queda local y las acciones se encolan hasta recuperar internet.</li>
      <li><strong>Sincronización:</strong> cuando vuelve conexión, las mutaciones pendientes se envían al backend y la interfaz se refresca.</li>
      <li><strong>Base de datos real:</strong> el módulo ya usa tablas propias de afiliados y rellena demo inicial automáticamente.</li>
      <li><strong>Optimización móvil:</strong> interfaz pensada para uso rápido en 4G y operación desde teléfono.</li>
    </ul>
  </section>
</div>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/affiliate_network_sw.js').catch(function () {});
  });
}
</script>
</body>
</html>
