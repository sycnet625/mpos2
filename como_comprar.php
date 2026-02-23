<?php
// ARCHIVO: como_comprar.php
// Guía visual para clientes: cómo comprar o reservar en shop.php
require_once 'config_loader.php';
$storeName = $config['tienda_nombre'] ?? 'Marinero';
$TARIFA_KM = floatval($config['mensajeria_tarifa_km'] ?? 150);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¿Cómo comprar? · <?= htmlspecialchars($storeName) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        /* ── Variables de color ─────────────────────────────── */
        :root {
            --azul:    #3b82f6;
            --verde:   #22c55e;
            --ambar:   #f59e0b;
            --rosado:  #ec4899;
            --morado:  #8b5cf6;
            --cielo:   #06b6d4;
            --naranja: #f97316;
        }

        body {
            font-family: 'Segoe UI', 'Comic Sans MS', sans-serif;
            background: linear-gradient(160deg, #eff6ff 0%, #fdf4ff 50%, #fff7ed 100%);
            min-height: 100vh;
            padding-bottom: 80px;
        }

        /* ── Animaciones ────────────────────────────────────── */
        @keyframes flotar {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-10px); }
        }
        @keyframes girar-lento {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        @keyframes aparecer {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulsar {
            0%, 100% { transform: scale(1); }
            50%       { transform: scale(1.08); }
        }
        @keyframes rebote {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-20px); }
            60% { transform: translateY(-10px); }
        }

        .flotar     { animation: flotar 2.5s ease-in-out infinite; }
        .aparecer   { animation: aparecer 0.6s ease both; }
        .pulsar-btn { animation: pulsar 1.8s ease-in-out infinite; }
        .rebote     { animation: rebote 2s ease infinite; }

        /* ── Hero ───────────────────────────────────────────── */
        .hero {
            background: linear-gradient(135deg, var(--azul) 0%, var(--morado) 100%);
            color: white;
            padding: 60px 20px 80px;
            text-align: center;
            border-radius: 0 0 50% 50% / 0 0 40px 40px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute; inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .hero-emoji { font-size: 5rem; display: block; }
        .hero h1   { font-size: 2.4rem; font-weight: 900; margin: 15px 0 10px; }
        .hero p    { font-size: 1.2rem; opacity: 0.9; max-width: 550px; margin: 0 auto; }

        /* ── Sección de pasos ───────────────────────────────── */
        .paso {
            background: white;
            border-radius: 24px;
            padding: 35px 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            animation: aparecer 0.5s ease both;
        }
        .paso-numero {
            width: 56px; height: 56px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; font-weight: 900; color: white;
            margin-bottom: 15px; flex-shrink: 0;
        }
        .paso-titulo {
            font-size: 1.5rem; font-weight: 800; margin-bottom: 10px;
        }
        .paso-texto {
            font-size: 1.05rem; color: #4b5563; line-height: 1.7;
        }

        /* ── Colores de paso ─────────────────────────────────── */
        .paso-1 .paso-numero { background: var(--azul); }
        .paso-2 .paso-numero { background: var(--verde); }
        .paso-3 .paso-numero { background: var(--ambar); }
        .paso-4 .paso-numero { background: var(--rosado); }
        .paso-5 .paso-numero { background: var(--morado); }
        .paso-6 .paso-numero { background: var(--cielo); }

        .paso-1 { border-left: 6px solid var(--azul); }
        .paso-2 { border-left: 6px solid var(--verde); }
        .paso-3 { border-left: 6px solid var(--ambar); }
        .paso-4 { border-left: 6px solid var(--rosado); }
        .paso-5 { border-left: 6px solid var(--morado); }
        .paso-6 { border-left: 6px solid var(--cielo); }

        /* ── Maqueta de tienda ─────────────────────────────── */
        .mock-shop {
            background: #f1f5f9;
            border-radius: 16px;
            padding: 15px;
            border: 2px solid #e2e8f0;
            font-size: 0.85rem;
        }
        .mock-navbar {
            background: linear-gradient(135deg, #0f172a, #1e3a5f);
            color: white;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 12px;
            display: flex; align-items: center; gap: 10px;
            font-weight: 700;
        }
        .mock-search {
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 5px 14px;
            flex: 1;
            font-size: 0.78rem; color: rgba(255,255,255,0.6);
        }
        .mock-cats {
            display: flex; gap: 8px; flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .mock-cat {
            padding: 5px 14px; border-radius: 20px;
            font-size: 0.75rem; font-weight: 700;
            cursor: default;
        }
        .mock-cat.active { background: #3b82f6; color: white; }
        .mock-cat.inactive { background: white; color: #374151; border: 1px solid #d1d5db; }

        .mock-productos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        @media(max-width: 500px) { .mock-productos { grid-template-columns: repeat(2, 1fr); } }

        .mock-card {
            background: white; border-radius: 12px;
            padding: 10px; text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            position: relative;
        }
        .mock-card .prod-emoji { font-size: 2.2rem; display: block; margin-bottom: 5px; }
        .mock-card .prod-name  { font-size: 0.7rem; font-weight: 700; color: #1e293b; margin-bottom: 3px; }
        .mock-card .prod-price { font-size: 0.8rem; font-weight: 900; color: #0d6efd; }
        .mock-card .prod-stock {
            font-size: 0.6rem; font-weight: 700;
            padding: 2px 7px; border-radius: 20px; display: inline-block;
            margin-bottom: 6px;
        }
        .mock-card .stock-ok    { background: #dcfce7; color: #16a34a; }
        .mock-card .stock-no    { background: #fee2e2; color: #dc2626; }
        .mock-card .stock-res   { background: #fef3c7; color: #92400e; }
        .mock-card .btn-agregar {
            width: 100%; padding: 5px; border-radius: 8px; border: none;
            font-size: 0.68rem; font-weight: 700; cursor: default;
        }
        .mock-card .btn-verde  { background: #22c55e; color: white; }
        .mock-card .btn-ambar  { background: #f59e0b; color: white; }
        .mock-card .btn-gris   { background: #e5e7eb; color: #9ca3af; }

        /* ── Carrito mockup ──────────────────────────────────── */
        .mock-carrito {
            background: white; border-radius: 16px;
            padding: 15px; border: 2px solid #e2e8f0;
        }
        .mock-carrito-header {
            font-weight: 800; font-size: 0.9rem; margin-bottom: 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .mock-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; border-bottom: 1px solid #f3f4f6;
            font-size: 0.8rem;
        }
        .mock-item-emoji { font-size: 1.6rem; }
        .mock-item-info  { flex: 1; }
        .mock-item-name  { font-weight: 700; color: #1e293b; }
        .mock-item-price { color: #6b7280; font-size: 0.72rem; }
        .mock-badge-reserva {
            background: #fef3c7; color: #92400e;
            font-size: 0.6rem; font-weight: 700;
            padding: 1px 6px; border-radius: 10px;
        }
        .mock-dual-btns {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
            margin-top: 12px;
        }
        .mock-btn-res {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: white; border: none; border-radius: 10px;
            padding: 9px 5px; font-size: 0.72rem; font-weight: 800;
            text-align: center; cursor: default;
        }
        .mock-btn-pay {
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: white; border: none; border-radius: 10px;
            padding: 9px 5px; font-size: 0.72rem; font-weight: 800;
            text-align: center; cursor: default;
        }

        /* ── Comparativa comprar vs reservar ─────────────────── */
        .compare-box {
            border-radius: 20px; padding: 25px 20px; text-align: center;
        }
        .compare-box h4 { font-size: 1.2rem; font-weight: 900; margin: 10px 0 8px; }
        .compare-box p  { font-size: 0.9rem; margin: 0; }
        .box-reservar { background: #fffbeb; border: 3px solid #f59e0b; }
        .box-pagar    { background: #eff6ff; border: 3px solid #3b82f6; }
        .compare-emoji { font-size: 3rem; }

        /* ── Formulario mockup ────────────────────────────────── */
        .mock-form { background: white; border-radius: 16px; padding: 20px; border: 2px solid #e2e8f0; }
        .mock-input {
            background: #f9fafb; border: 1.5px solid #d1d5db;
            border-radius: 8px; padding: 8px 12px;
            font-size: 0.8rem; color: #374151; margin-bottom: 8px; width: 100%;
        }
        .mock-input.filled { border-color: #22c55e; color: #166534; background: #f0fdf4; }
        .mock-label { font-size: 0.7rem; font-weight: 700; color: #6b7280; margin-bottom: 3px; display: block; }

        /* ── Métodos de pago ──────────────────────────────────── */
        .pago-card {
            background: white; border-radius: 16px;
            padding: 20px 15px; text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.07);
            border: 2px solid transparent;
            transition: border-color 0.2s;
        }
        .pago-card:hover { border-color: var(--azul); }
        .pago-card .pago-emoji { font-size: 2.8rem; margin-bottom: 10px; display: block; }
        .pago-card h5 { font-size: 0.95rem; font-weight: 800; margin-bottom: 6px; }
        .pago-card p  { font-size: 0.82rem; color: #6b7280; margin: 0; }

        /* ── Tarjeta de transferencia ─────────────────────────── */
        .mock-tarjeta {
            background: linear-gradient(135deg, #1e3a5f, #0f172a);
            color: white; border-radius: 14px;
            padding: 18px; margin-top: 12px;
            font-size: 0.78rem;
        }
        .mock-tarjeta .num { font-size: 1rem; font-family: monospace; letter-spacing: 3px; margin: 8px 0; }
        .mock-tarjeta .label { opacity: 0.6; font-size: 0.65rem; text-transform: uppercase; }

        /* ── Pantalla de éxito ────────────────────────────────── */
        .exito-box {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white; border-radius: 24px;
            padding: 35px 25px; text-align: center;
        }
        .exito-box .exito-emoji { font-size: 4rem; display: block; margin-bottom: 12px; }
        .exito-box h3 { font-size: 1.6rem; font-weight: 900; }
        .exito-box p  { opacity: 0.9; font-size: 1rem; }

        /* ── Burbuja de consejo ───────────────────────────────── */
        .consejo {
            background: #eff6ff; border-left: 4px solid var(--azul);
            border-radius: 0 12px 12px 0;
            padding: 12px 16px; margin-top: 15px;
            font-size: 0.9rem; color: #1e40af;
        }
        .consejo-warning {
            background: #fff7ed; border-left: 4px solid var(--naranja);
            color: #9a3412;
        }
        .consejo strong { display: block; margin-bottom: 3px; }

        /* ── Flecha conectora ─────────────────────────────────── */
        .flecha-abajo {
            text-align: center; font-size: 2.5rem;
            margin: -10px 0; color: #94a3b8;
        }

        /* ── Botón de regreso ─────────────────────────────────── */
        .btn-volver {
            position: fixed; bottom: 25px; right: 25px;
            background: linear-gradient(135deg, var(--azul), var(--morado));
            color: white; border: none; border-radius: 50px;
            padding: 13px 22px; font-size: 0.9rem; font-weight: 700;
            box-shadow: 0 6px 20px rgba(99,102,241,0.45);
            text-decoration: none; z-index: 999;
            display: flex; align-items: center; gap: 8px;
        }
        .btn-volver:hover { color: white; transform: translateY(-2px); }

        /* ── Footer ───────────────────────────────────────────── */
        .footer-palweb {
            text-align: center; padding: 20px;
            color: #94a3b8; font-size: 0.75rem;
        }

        /* ── Destacado de número ──────────────────────────────── */
        .highlight-num {
            display: inline-block;
            background: linear-gradient(135deg, var(--azul), var(--morado));
            color: white; border-radius: 50%;
            width: 32px; height: 32px;
            line-height: 32px; text-align: center;
            font-weight: 900; font-size: 0.95rem;
            margin-right: 6px;
        }

        /* ── Pestañas ─────────────────────────────────────────── */
        .nav-pills .nav-link { font-weight: 700; border-radius: 50px; }
        .nav-pills .nav-link.active { background: var(--azul); }
    </style>
</head>
<body>

<!-- ════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════ -->
<div class="hero">
    <span class="hero-emoji flotar">🛍️</span>
    <h1>¡Aprende a comprar<br>en nuestra tienda!</h1>
    <p>Te explicamos todo paso a paso, ¡súper fácil! Así puedes pedir lo que quieras desde tu casa 🏠</p>
    <a href="shop.php" class="btn btn-light btn-lg rounded-pill px-4 mt-4 fw-bold">
        <i class="fas fa-store me-2 text-primary"></i> Ir a la tienda →
    </a>
</div>

<div class="container py-5" style="max-width: 820px;">

    <!-- ══════════════════════════
         INTRODUCCIÓN
    ══════════════════════════════ -->
    <div class="text-center mb-5 aparecer">
        <h2 style="font-size:1.8rem; font-weight:900; color:#1e293b;">
            ¿Qué vas a aprender hoy? 🤔
        </h2>
        <p class="text-muted" style="font-size:1.05rem;">
            En 6 pasos cortos te enseñamos cómo pedir tus productos favoritos.
            ¡Es tan fácil como contar del 1 al 6!
        </p>
        <!-- Resumen visual -->
        <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
            <?php
            $resumen = [
                ['🔍','Buscar'],['🛒','Carrito'],['🤔','¿Reservar\no Comprar?'],
                ['📝','Tus datos'],['💳','Pagar'],['🎉','¡Listo!']
            ];
            foreach ($resumen as $i => [$e, $t]):
            ?>
            <div class="d-flex flex-column align-items-center" style="width:90px;">
                <div style="
                    width:62px; height:62px; border-radius:50%;
                    background:<?= ['#eff6ff','#f0fdf4','#fffbeb','#fdf4ff','#fff7ed','#ecfdf5'][$i] ?>;
                    border:3px solid <?= ['#3b82f6','#22c55e','#f59e0b','#8b5cf6','#f97316','#22c55e'][$i] ?>;
                    display:flex; align-items:center; justify-content:center; font-size:1.8rem;
                "><?= $e ?></div>
                <span style="font-size:0.72rem; font-weight:700; color:#374151; margin-top:6px; text-align:center; line-height:1.3;"><?= $t ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════════════════════
         PASO 1: BUSCAR
    ══════════════════════════════ -->
    <div class="paso paso-1">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="paso-numero">1</div>
            <div>
                <div class="paso-titulo" style="color:var(--azul)">🔍 Busca lo que quieres</div>
                <div class="paso-texto">
                    Cuando entras a la tienda, ves <strong>muchos productos</strong>. Puedes buscarlos de dos maneras:
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div style="background:#eff6ff; border-radius:14px; padding:15px;">
                    <p style="font-size:0.95rem; font-weight:700; margin-bottom:8px;">
                        <span class="highlight-num">A</span>Escribe en el buscador
                    </p>
                    <p style="font-size:0.85rem; color:#374151; margin:0;">
                        Escribe el nombre del producto, ¡como buscas en Google! Por ejemplo: <em>"donas"</em> o <em>"pizza"</em>.
                    </p>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background:#f0fdf4; border-radius:14px; padding:15px;">
                    <p style="font-size:0.95rem; font-weight:700; margin-bottom:8px;">
                        <span class="highlight-num" style="background:linear-gradient(135deg,#22c55e,#16a34a)">B</span>Toca una categoría
                    </p>
                    <p style="font-size:0.85rem; color:#374151; margin:0;">
                        Hay botones con el nombre de cada grupo de productos. ¡Tócalos para ver solo lo que quieres!
                    </p>
                </div>
            </div>
        </div>

        <!-- Maqueta del navbar y categorías -->
        <div class="mock-shop">
            <div class="mock-navbar">
                <span style="font-size:1.2rem;">🏪</span>
                <span style="flex:1; font-size:0.85rem;"><?= htmlspecialchars($storeName) ?></span>
                <div class="mock-search">🔍 Buscar productos...</div>
            </div>
            <div class="mock-cats">
                <span class="mock-cat active">🍕 Todos</span>
                <span class="mock-cat inactive">🍰 Pasteles</span>
                <span class="mock-cat inactive">🍺 Bebidas</span>
                <span class="mock-cat inactive">🍩 Dulces</span>
                <span class="mock-cat inactive">🛠️ Servicios</span>
            </div>
            <p style="font-size:0.72rem; color:#64748b; margin:0;">👆 Toca el botón de la categoría que quieres</p>
        </div>

        <div class="consejo">
            <strong>💡 Consejo:</strong>
            ¿No encuentras lo que buscas? ¡Prueba escribir solo las primeras letras! Si buscas "cerveza", puedes escribir solo "cerv".
        </div>
    </div>

    <div class="flecha-abajo">⬇️</div>

    <!-- ══════════════════════════
         PASO 2: CARRITO
    ══════════════════════════════ -->
    <div class="paso paso-2">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="paso-numero">2</div>
            <div>
                <div class="paso-titulo" style="color:var(--verde)">🛒 Elige y pon en el carrito</div>
                <div class="paso-texto">
                    Cada producto tiene una <strong>tarjeta</strong> con su foto, nombre y precio. Fíjate en el botón de abajo de cada tarjeta:
                </div>
            </div>
        </div>

        <!-- Maqueta de 3 tarjetas de producto -->
        <div class="mock-shop mb-3">
            <div class="mock-productos">
                <!-- Disponible -->
                <div class="mock-card">
                    <span class="prod-emoji">🎂</span>
                    <div class="prod-name">Torta Grande</div>
                    <span class="prod-stock stock-ok">✓ Disponible</span>
                    <div class="prod-price">$450.00</div>
                    <button class="btn-agregar btn-verde mt-1">🛒 Agregar</button>
                </div>
                <!-- Reservable -->
                <div class="mock-card" style="border:2px solid #f59e0b;">
                    <span class="prod-emoji">🍣</span>
                    <div class="prod-name">Sushi Mix</div>
                    <span class="prod-stock stock-res">📅 Reservable</span>
                    <div class="prod-price">$320.00</div>
                    <button class="btn-agregar btn-ambar mt-1">📅 Reservar</button>
                </div>
                <!-- Agotado -->
                <div class="mock-card" style="opacity:0.7;">
                    <span class="prod-emoji">🥩</span>
                    <div class="prod-name">Filete</div>
                    <span class="prod-stock stock-no">✗ Agotado</span>
                    <div class="prod-price">$280.00</div>
                    <button class="btn-agregar btn-gris mt-1">Agotado</button>
                </div>
            </div>
        </div>

        <!-- Leyenda de los 3 estados -->
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div style="background:#f0fdf4; border-radius:10px; padding:10px; text-align:center; border:1px solid #86efac;">
                    <div style="font-size:1.4rem;">✅</div>
                    <div style="font-size:0.72rem; font-weight:700; color:#166534;">DISPONIBLE</div>
                    <div style="font-size:0.7rem; color:#374151;">Hay en almacén. ¡Puedes comprarlo ya!</div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#fffbeb; border-radius:10px; padding:10px; text-align:center; border:1px solid #fcd34d;">
                    <div style="font-size:1.4rem;">📅</div>
                    <div style="font-size:0.72rem; font-weight:700; color:#92400e;">RESERVABLE</div>
                    <div style="font-size:0.7rem; color:#374151;">Se puede pedir para más adelante.</div>
                </div>
            </div>
            <div class="col-4">
                <div style="background:#fef2f2; border-radius:10px; padding:10px; text-align:center; border:1px solid #fca5a5;">
                    <div style="font-size:1.4rem;">❌</div>
                    <div style="font-size:0.72rem; font-weight:700; color:#991b1b;">AGOTADO</div>
                    <div style="font-size:0.7rem; color:#374151;">No hay disponible ni para reservar.</div>
                </div>
            </div>
        </div>

        <div class="consejo">
            <strong>💡 Consejo:</strong>
            Puedes agregar varios productos distintos al carrito antes de hacer el pedido. ¡Como llenar una bolsa de supermercado!
        </div>
    </div>

    <div class="flecha-abajo">⬇️</div>

    <!-- ══════════════════════════
         PASO 3: RESERVAR vs PAGAR
    ══════════════════════════════ -->
    <div class="paso paso-3">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="paso-numero">3</div>
            <div>
                <div class="paso-titulo" style="color:var(--ambar)">🤔 ¿Reservar o Pagar Ahora?</div>
                <div class="paso-texto">
                    Cuando ya tienes todo en el carrito, verás <strong>dos botones grandes</strong>.
                    Elige el que más te conviene:
                </div>
            </div>
        </div>

        <!-- Maqueta del carrito con los dos botones -->
        <div class="mock-carrito mb-4">
            <div class="mock-carrito-header">
                🛒 Mi Carrito
                <span style="background:#3b82f6; color:white; border-radius:50%; width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:900;">2</span>
            </div>
            <div class="mock-item">
                <span class="mock-item-emoji">🎂</span>
                <div class="mock-item-info">
                    <div class="mock-item-name">Torta Grande</div>
                    <div class="mock-item-price">$450.00 × 1</div>
                </div>
                <strong style="font-size:0.85rem; color:#0d6efd;">$450</strong>
            </div>
            <div class="mock-item">
                <span class="mock-item-emoji">🍣</span>
                <div class="mock-item-info">
                    <div class="mock-item-name">Sushi Mix <span class="mock-badge-reserva">📅 RESERVA</span></div>
                    <div class="mock-item-price">$320.00 × 1</div>
                </div>
                <strong style="font-size:0.85rem; color:#0d6efd;">$320</strong>
            </div>
            <div class="mock-dual-btns pulsar-btn">
                <div class="mock-btn-res">📅 Reservar<br><span style="font-weight:400; font-size:0.6rem;">Sin existencias OK</span></div>
                <div class="mock-btn-pay">💳 Pagar Ahora<br><span style="font-weight:400; font-size:0.6rem;">Solo con stock</span></div>
            </div>
        </div>

        <!-- Comparativa -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="compare-box box-reservar">
                    <div class="compare-emoji rebote">📅</div>
                    <h4 style="color:#92400e;">RESERVAR</h4>
                    <p style="color:#78350f;">
                        Es como <strong>apartar</strong> un producto para después. Puedes reservar incluso si no hay stock ahora mismo.<br><br>
                        <strong>Pagas cuando te lo entreguen.</strong> ¡Sin prisa!
                    </p>
                    <div style="background:#fef3c7; border-radius:10px; padding:10px; margin-top:12px; font-size:0.82rem; color:#78350f;">
                        <strong>✅ Úsalo cuando:</strong><br>
                        • El producto dice "📅 Reservable"<br>
                        • Quieres pedir con anticipación<br>
                        • No tienes efectivo ahora
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="compare-box box-pagar">
                    <div class="compare-emoji rebote" style="animation-delay:0.3s">💳</div>
                    <h4 style="color:#1e40af;">PAGAR AHORA</h4>
                    <p style="color:#1e3a8a;">
                        Compras el producto <strong>de inmediato</strong>. Solo funciona si el producto tiene stock disponible.<br><br>
                        <strong>Eliges cómo pagar antes de confirmar.</strong>
                    </p>
                    <div style="background:#dbeafe; border-radius:10px; padding:10px; margin-top:12px; font-size:0.82rem; color:#1e40af;">
                        <strong>✅ Úsalo cuando:</strong><br>
                        • El producto dice "✓ Disponible"<br>
                        • Quieres confirmar tu pedido ya<br>
                        • Tienes cómo pagar listo
                    </div>
                </div>
            </div>
        </div>

        <div class="consejo consejo-warning">
            <strong>⚠️ Importante:</strong>
            Si tu carrito tiene productos de <strong>reserva</strong> y productos <strong>disponibles</strong> mezclados, solo podrás usar el botón <strong>Reservar</strong>. ¡Haz pedidos separados si quieres usar los dos!
        </div>
    </div>

    <div class="flecha-abajo">⬇️</div>

    <!-- ══════════════════════════
         PASO 4: TUS DATOS
    ══════════════════════════════ -->
    <div class="paso paso-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="paso-numero">4</div>
            <div>
                <div class="paso-titulo" style="color:var(--rosado)">📝 Cuéntanos quién eres y dónde estás</div>
                <div class="paso-texto">
                    Aparece un formulario. ¡No te asustes, es cortito! Solo necesitamos saber dónde entregarte el pedido.
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-7">
                <div class="mock-form">
                    <p style="font-size:0.78rem; font-weight:700; color:#6b7280; margin-bottom:12px; text-transform:uppercase;">Formulario de pedido</p>

                    <label class="mock-label">Tu nombre completo *</label>
                    <div class="mock-input filled">María González Pérez ✓</div>

                    <label class="mock-label">Tu teléfono *</label>
                    <div class="mock-input filled">+53 5 555 0000 ✓</div>

                    <label class="mock-label">¿Cómo quieres recibir tu pedido? *</label>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <div style="flex:1; background:#eff6ff; border:2px solid #3b82f6; border-radius:8px; padding:8px; text-align:center; font-size:0.75rem; font-weight:700; color:#1d4ed8;">🚚 A domicilio</div>
                        <div style="flex:1; background:#f9fafb; border:1.5px solid #d1d5db; border-radius:8px; padding:8px; text-align:center; font-size:0.75rem; font-weight:700; color:#9ca3af;">🏪 Recojo yo</div>
                    </div>

                    <label class="mock-label">Tu dirección (calle, número, municipio)</label>
                    <div class="mock-input filled">Calle 23 #456, Vedado, La Habana ✓</div>

                    <label class="mock-label">¿Cuándo quieres recibirlo?</label>
                    <div class="mock-input filled">📅 25 de febrero, 2026 ✓</div>

                    <label class="mock-label" style="margin-top:6px;">⏰ Horario de entrega *</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-bottom:8px;">
                        <div style="border:2px solid #d1d5db; border-radius:10px; padding:7px 5px; text-align:center; font-size:0.7rem; color:#9ca3af;">
                            <div style="font-size:1.1rem;">🌅</div>
                            <div style="font-weight:700;">Mañana</div>
                            <div style="font-size:0.62rem;">9am – 12pm</div>
                        </div>
                        <div style="border:2px solid #3b82f6; border-radius:10px; padding:7px 5px; text-align:center; font-size:0.7rem; background:#dbeafe; color:#1d4ed8; box-shadow:0 0 0 2px rgba(59,130,246,.2);">
                            <div style="font-size:1.1rem;">☀️</div>
                            <div style="font-weight:800;">Mediodía ✓</div>
                            <div style="font-size:0.62rem;">12pm – 3pm</div>
                        </div>
                        <div style="border:2px solid #d1d5db; border-radius:10px; padding:7px 5px; text-align:center; font-size:0.7rem; color:#9ca3af;">
                            <div style="font-size:1.1rem;">🌆</div>
                            <div style="font-weight:700;">Tarde</div>
                            <div style="font-size:0.62rem;">3pm – 6pm</div>
                        </div>
                        <div style="border:2px solid #d1d5db; border-radius:10px; padding:7px 5px; text-align:center; font-size:0.7rem; color:#9ca3af;">
                            <div style="font-size:1.1rem;">🌙</div>
                            <div style="font-weight:700;">Noche</div>
                            <div style="font-size:0.62rem;">6pm – 9pm</div>
                        </div>
                    </div>

                    <label class="mock-label">Mensaje para nosotros (opcional)</label>
                    <div class="mock-input" style="height:40px; color:#9ca3af;">Ej: Llamen antes de llegar...</div>
                </div>
            </div>
            <div class="col-md-5">
                <div style="background:#fdf4ff; border-radius:16px; padding:20px; border:2px solid #d8b4fe; height:100%;">
                    <h5 style="font-size:1rem; font-weight:800; color:#7c3aed; margin-bottom:15px;">📋 ¿Qué te pedimos?</h5>
                    <div style="font-size:0.85rem; color:#374151;">
                        <div style="margin-bottom:10px;">
                            <strong>🙋 Tu nombre</strong><br>
                            <span style="color:#6b7280;">Para saber a quién le llega el pedido.</span>
                        </div>
                        <div style="margin-bottom:10px;">
                            <strong>📱 Tu teléfono</strong><br>
                            <span style="color:#6b7280;">Para avisarte cuando tu pedido va en camino.</span>
                        </div>
                        <div style="margin-bottom:10px;">
                            <strong>🏠 Tu dirección</strong><br>
                            <span style="color:#6b7280;">Solo si quieres que te lo llevemos. Si vas a buscarlo tú, no hace falta.</span>
                        </div>
                        <div style="margin-bottom:10px;">
                            <strong>📅 La fecha</strong><br>
                            <span style="color:#6b7280;">¿Para qué día quieres el pedido?</span>
                        </div>
                        <div style="background:#eff6ff; border-radius:10px; padding:10px; border-left:3px solid #3b82f6;">
                            <strong>⏰ El horario</strong><br>
                            <span style="color:#6b7280;">Elige la franja del día en que quieres recibir tu pedido: mañana, mediodía, tarde o noche. Si seleccionas <em>hoy</em>, los horarios que ya pasaron aparecen deshabilitados automáticamente.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="consejo">
            <strong>💡 Consejo:</strong>
            Los campos con <span style="color:#dc2626; font-weight:700;">*</span> son obligatorios. El horario es importante para que nuestros mensajeros organicen bien la ruta del día — ¡elige el que más te convenga!
        </div>
    </div>

    <div class="flecha-abajo">⬇️</div>

    <!-- ══════════════════════════
         PASO 5: PAGAR
    ══════════════════════════════ -->
    <div class="paso paso-5">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="paso-numero">5</div>
            <div>
                <div class="paso-titulo" style="color:var(--morado)">💰 Elige cómo pagar</div>
                <div class="paso-texto">
                    Si elegiste <strong>Pagar Ahora</strong>, verás tres formas de pagar. Elige la que prefieras:
                </div>
            </div>
        </div>

        <!-- Tres métodos de pago -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="pago-card">
                    <span class="pago-emoji">💵</span>
                    <h5>Efectivo al mensajero</h5>
                    <p>Pagas en efectivo cuando el mensajero llega a tu puerta. ¡Tienes que tener el dinero listo!</p>
                    <div style="background:#f0fdf4; border-radius:8px; padding:8px; margin-top:10px; font-size:0.78rem; color:#166534; font-weight:700;">
                        ✅ Lo más común y fácil
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="pago-card">
                    <span class="pago-emoji">🏪</span>
                    <h5>Efectivo en el local</h5>
                    <p>Pagas cuando vayas a buscar tu pedido directamente en nuestra tienda.</p>
                    <div style="background:#eff6ff; border-radius:8px; padding:8px; margin-top:10px; font-size:0.78rem; color:#1d4ed8; font-weight:700;">
                        ✅ Para cuando vienen a recoger
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="pago-card">
                    <span class="pago-emoji">📲</span>
                    <h5>Transferencia</h5>
                    <p>Pagas por Enzona o Transfermovil. Te mostramos el número de tarjeta y tú escribes el código de tu pago.</p>
                    <div style="background:#fffbeb; border-radius:8px; padding:8px; margin-top:10px; font-size:0.78rem; color:#92400e; font-weight:700;">
                        ✅ Sin salir de casa
                    </div>
                </div>
            </div>
        </div>

        <!-- Guía de transferencia -->
        <div style="background:#fffbeb; border-radius:16px; padding:20px; border:2px solid #fcd34d;">
            <h5 style="color:#92400e; font-weight:800; margin-bottom:15px;">
                📲 ¿Cómo funciona pagar por transferencia?
            </h5>
            <div class="row g-3 align-items-center">
                <div class="col-md-7">
                    <div style="font-size:0.9rem; color:#78350f;">
                        <p><span class="highlight-num" style="background:linear-gradient(135deg,#f59e0b,#d97706)">1</span>
                        Selecciona <strong>"Transferencia Enzona / Transfermovil"</strong></p>
                        <p><span class="highlight-num" style="background:linear-gradient(135deg,#f59e0b,#d97706)">2</span>
                        Te mostramos nuestra <strong>tarjeta</strong> con el número al que tienes que enviar el dinero</p>
                        <p><span class="highlight-num" style="background:linear-gradient(135deg,#f59e0b,#d97706)">3</span>
                        Haz la transferencia desde tu app bancaria (Enzona, Transfermovil, etc.)</p>
                        <p><span class="highlight-num" style="background:linear-gradient(135deg,#f59e0b,#d97706)">4</span>
                        Copia el <strong>código de confirmación</strong> que te da tu aplicación y pégalo en el campo</p>
                        <p style="margin:0;"><span class="highlight-num" style="background:linear-gradient(135deg,#f59e0b,#d97706)">5</span>
                        ¡Listo! Nuestro equipo verifica el pago y confirma tu pedido 👍</p>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="mock-tarjeta">
                        <div class="label">Número de tarjeta</div>
                        <div class="num">9225 •••• •••• ••••</div>
                        <div style="display:flex; justify-content:space-between; margin-top:8px;">
                            <div>
                                <div class="label">Titular</div>
                                <div style="font-size:0.82rem; font-weight:700;"><?= htmlspecialchars($config['titular_tarjeta'] ?: 'TITULAR') ?></div>
                            </div>
                            <div>
                                <div class="label">Banco</div>
                                <div style="font-size:0.82rem; font-weight:700;"><?= htmlspecialchars($config['banco_tarjeta'] ?: 'Bandec') ?></div>
                            </div>
                        </div>
                    </div>
                    <div style="background:white; border-radius:10px; padding:12px; margin-top:10px; border:1.5px solid #fcd34d;">
                        <div class="mock-label">Tu código de confirmación</div>
                        <div class="mock-input" style="margin:0; background:#f9fafb;">Ej: 33010 ✍️</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="consejo consejo-warning mt-3">
            <strong>⚠️ Ojo con las transferencias:</strong>
            Después de enviar el pago, verás una pantalla de espera ⏳. Un operador revisará tu transferencia y te confirmará en minutos. ¡No cierres la pantalla todavía!
        </div>
    </div>

    <div class="flecha-abajo">⬇️</div>

    <!-- ══════════════════════════
         PASO 6: ÉXITO
    ══════════════════════════════ -->
    <div class="paso paso-6">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="paso-numero">6</div>
            <div>
                <div class="paso-titulo" style="color:var(--cielo)">🎉 ¡Tu pedido está listo!</div>
                <div class="paso-texto">
                    Cuando todo esté bien, verás una pantalla de confirmación verde. ¡Eso significa que recibimos tu pedido!
                </div>
            </div>
        </div>

        <div class="exito-box mb-4">
            <span class="exito-emoji flotar">🎉</span>
            <h3>¡Pedido recibido!</h3>
            <p>Tu pedido fue guardado correctamente. Te llamaremos al teléfono que escribiste para confirmar los detalles.</p>
            <div style="background:rgba(255,255,255,0.2); border-radius:12px; padding:12px 20px; margin-top:15px; font-size:0.9rem; display:inline-block;">
                📦 Número de pedido: <strong>#V-17456382</strong>
            </div>
        </div>

        <!-- ¿Qué pasa después? -->
        <h5 style="font-weight:800; color:#0f172a; margin-bottom:15px;">¿Qué pasa después de pedir? 📋</h5>
        <div class="row g-3">
            <?php
            $despues = [
                ['📞','Te llamamos','Nuestro equipo se comunica contigo para confirmar la hora y lugar de entrega.','#eff6ff','#1d4ed8'],
                ['👨‍🍳','Preparamos tu pedido','Empezamos a preparar o reunir todo lo que pediste con mucho cuidado.','#f0fdf4','#166534'],
                ['🚚','Te lo llevamos','Un mensajero lleva el pedido a tu casa (o tú vienes a buscarlo, como prefieras).','#fdf4ff','#7c3aed'],
                ['⭐','¡Disfrútalo!','Recibe tu pedido, paga si quedó pendiente y... ¡a disfrutar!','#fffbeb','#92400e'],
            ];
            foreach ($despues as [$e,$t,$d,$bg,$col]):
            ?>
            <div class="col-md-3 col-6">
                <div style="background:<?= $bg ?>; border-radius:14px; padding:15px; text-align:center; height:100%;">
                    <div style="font-size:2rem; margin-bottom:8px;"><?= $e ?></div>
                    <div style="font-size:0.82rem; font-weight:800; color:<?= $col ?>; margin-bottom:6px;"><?= $t ?></div>
                    <div style="font-size:0.76rem; color:#374151;"><?= $d ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if($TARIFA_KM > 0): ?>
        <div class="consejo mt-3">
            <strong>🚚 ¿Cuánto cuesta el envío?</strong>
            El costo del envío a domicilio depende de la distancia hasta tu casa: <strong>$<?= number_format($TARIFA_KM, 0) ?> CUP por kilómetro</strong>. El sistema lo calcula automáticamente cuando escribes tu dirección.
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════
         PREGUNTAS FRECUENTES
    ══════════════════════════════ -->
    <div style="background:white; border-radius:24px; padding:30px; box-shadow:0 8px 30px rgba(0,0,0,0.08); margin-bottom:30px;">
        <h3 style="font-weight:900; color:#0f172a; margin-bottom:20px;">❓ Preguntas que todos hacen</h3>

        <div class="accordion" id="faqAccordion">
            <?php
            $faqs = [
                ['¿Puedo cambiar mi pedido después de hacerlo?',
                 'Por el momento, para cambiar un pedido debes comunicarte directamente con nosotros por teléfono o por el chat de la tienda. ¡Escríbenos lo antes posible!'],
                ['¿Cuánto tiempo tarda en llegar mi pedido?',
                 'Depende del producto y de la distancia. Normalmente entre 1 y 3 horas para pedidos del mismo día. Para reservas futuras, te lo confirmamos al llamarte.'],
                ['¿Qué significa "📅 Reservable"?',
                 'Significa que puedes pedir ese producto aunque no haya stock ahora mismo. La tienda lo preparará especialmente para ti. Te avisamos cuando esté listo.'],
                ['¿Mi dinero está seguro si pago por transferencia?',
                 'Sí. Solo transfieres cuando tú decides hacerlo. Un operador real revisa tu transferencia y confirma el pedido. Si hay algún problema, te contactamos directamente.'],
                ['¿Puedo hacer un pedido para regalárselo a alguien?',
                 '¡Por supuesto! Solo pon la dirección de esa persona en el formulario y escribe en el campo de mensaje: "Es un regalo para [nombre]". Nosotros nos encargamos.'],
            ];
            foreach ($faqs as $i => [$q, $a]):
            ?>
            <div class="accordion-item border-0 mb-2" style="border-radius:12px; overflow:hidden; border:1px solid #e2e8f0 !important;">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $i>0?'collapsed':'' ?> fw-bold" type="button"
                            data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"
                            style="font-size:0.95rem; background:<?= $i===0?'#eff6ff':'#f9fafb' ?>;">
                        <?= $q ?>
                    </button>
                </h2>
                <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i===0?'show':'' ?>" data-bs-parent="#faqAccordion">
                    <div class="accordion-body" style="font-size:0.9rem; color:#374151; line-height:1.7;">
                        <?= $a ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════════════════════
         CTA FINAL
    ══════════════════════════════ -->
    <div style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); border-radius:24px; padding:40px 25px; text-align:center; color:white;">
        <div style="font-size:3.5rem; margin-bottom:15px;" class="flotar">🛍️</div>
        <h3 style="font-size:1.8rem; font-weight:900; margin-bottom:10px;">¡Ya sabes todo lo que necesitas!</h3>
        <p style="opacity:0.9; font-size:1.05rem; margin-bottom:25px;">
            ¿Tienes alguna duda? Usa el chat de la tienda (el botón azul en la esquina). Siempre hay alguien que puede ayudarte 😊
        </p>
        <a href="shop.php" class="btn btn-white btn-lg rounded-pill px-5 fw-bold"
           style="background:white; color:#3b82f6; font-size:1.1rem;">
            <i class="fas fa-shopping-cart me-2"></i> ¡Ir a comprar ahora! 🎉
        </a>
    </div>

</div><!-- /container -->

<!-- Botón flotante de regreso -->
<a href="shop.php" class="btn-volver">
    <i class="fas fa-arrow-left"></i> Volver a la tienda
</a>

<!-- Footer -->
<div class="footer-palweb">
    Sistema PALWEB POS v3.0
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
