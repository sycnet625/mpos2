<?php
// ARCHIVO: quienes_somos.php
// Página institucional de PalWeb SURL — Quiénes Somos
require_once 'config_loader.php';
$storeName = $config['tienda_nombre'] ?? 'PalWeb';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiénes Somos · <?= htmlspecialchars($storeName) ?></title>
    <meta name="description" content="PalWeb SURL — MIPYME cubana productora, importadora y distribuidora de alimentos. Elaboramos dulces y comidas semi-elaboradas congeladas listas para comer. Servicio a domicilio en La Habana.">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --azul:    #3b82f6;
            --verde:   #22c55e;
            --ambar:   #f59e0b;
            --rosado:  #ec4899;
            --morado:  #8b5cf6;
            --cielo:   #06b6d4;
            --naranja: #f97316;
            --rojo:    #ef4444;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(160deg, #eff6ff 0%, #fdf4ff 50%, #fff7ed 100%);
            min-height: 100vh;
            padding-bottom: 80px;
        }

        @keyframes flotar {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-10px); }
        }
        @keyframes aparecer {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulsar {
            0%, 100% { transform: scale(1); }
            50%       { transform: scale(1.06); }
        }

        .flotar   { animation: flotar 3s ease-in-out infinite; }
        .aparecer { animation: aparecer 0.6s ease both; }
        .pulsar   { animation: pulsar 2s ease-in-out infinite; }

        /* ── Hero ───────────────────────── */
        .hero {
            background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 60%, #db2777 100%);
            color: white;
            padding: 60px 20px 90px;
            text-align: center;
            border-radius: 0 0 50% 50% / 0 0 50px 50px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute; inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none'%3E%3Cg fill='%23ffffff' fill-opacity='0.06'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }
        .hero-logo  { font-size: 5rem; display: block; }
        .hero h1   { font-size: 2.6rem; font-weight: 900; margin: 15px 0 8px; letter-spacing: -0.5px; }
        .hero .badge-mipyme {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 30px;
            padding: 4px 18px;
            font-size: 0.85rem;
            letter-spacing: 1px;
            backdrop-filter: blur(4px);
            margin-bottom: 12px;
        }
        .hero p { font-size: 1.15rem; opacity: 0.92; max-width: 560px; margin: 0 auto; }

        /* ── Botón volver ───────────────── */
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            background: white; color: #1e3a8a;
            border: none; border-radius: 30px;
            padding: 10px 24px; font-weight: 700; font-size: 0.95rem;
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 28px;
        }
        .btn-back:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.15); color: #1e3a8a; }

        /* ── Sección general ────────────── */
        .section { padding: 48px 0 20px; }
        .section-title {
            font-size: 1.7rem; font-weight: 900; color: #1e293b;
            margin-bottom: 8px;
        }
        .section-subtitle { color: #64748b; font-size: 1rem; margin-bottom: 32px; }

        /* ── Tarjetas pilar ─────────────── */
        .pillar-card {
            background: white;
            border-radius: 20px;
            padding: 32px 24px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.07);
            transition: transform 0.25s, box-shadow 0.25s;
            height: 100%;
            border-top: 4px solid;
        }
        .pillar-card:hover { transform: translateY(-6px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
        .pillar-card .icon { font-size: 3rem; margin-bottom: 16px; display: block; }
        .pillar-card h4 { font-weight: 800; font-size: 1.15rem; margin-bottom: 10px; color: #1e293b; }
        .pillar-card p  { color: #64748b; font-size: 0.93rem; line-height: 1.6; margin: 0; }

        .border-azul   { border-top-color: var(--azul); }
        .border-verde  { border-top-color: var(--verde); }
        .border-ambar  { border-top-color: var(--ambar); }
        .border-morado { border-top-color: var(--morado); }
        .border-rosado { border-top-color: var(--rosado); }
        .border-naranja{ border-top-color: var(--naranja); }

        /* ── Estadísticas ───────────────── */
        .stats-band {
            background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
            color: white;
            border-radius: 24px;
            padding: 40px 30px;
            margin: 40px 0;
        }
        .stat-item { text-align: center; padding: 16px 8px; }
        .stat-num {
            font-size: 2.8rem; font-weight: 900;
            display: block; line-height: 1;
        }
        .stat-label { font-size: 0.9rem; opacity: 0.85; margin-top: 6px; }

        /* ── Productos destacados ────────── */
        .product-pill {
            display: inline-flex; align-items: center; gap: 8px;
            background: white;
            border-radius: 40px;
            padding: 10px 20px;
            margin: 6px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            font-weight: 600; font-size: 0.92rem; color: #1e293b;
            transition: transform 0.18s;
        }
        .product-pill:hover { transform: scale(1.04); }
        .product-pill .dot {
            width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
        }

        /* ── Misión / Visión ────────────── */
        .mv-card {
            background: white;
            border-radius: 20px;
            padding: 28px 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            height: 100%;
        }
        .mv-card h5 { font-weight: 800; font-size: 1.1rem; margin-bottom: 12px; }
        .mv-card p  { color: #475569; font-size: 0.95rem; line-height: 1.65; margin: 0; }

        /* ── Timeline ───────────────────── */
        .timeline { position: relative; padding-left: 28px; }
        .timeline::before {
            content: '';
            position: absolute; left: 10px; top: 0; bottom: 0;
            width: 3px;
            background: linear-gradient(to bottom, var(--azul), var(--morado));
            border-radius: 3px;
        }
        .tl-item {
            position: relative; padding: 0 0 28px 24px;
        }
        .tl-item::before {
            content: '';
            position: absolute; left: -18px; top: 4px;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: white;
            border: 3px solid var(--morado);
        }
        .tl-year { font-size: 0.78rem; font-weight: 700; color: var(--morado); text-transform: uppercase; letter-spacing: 1px; }
        .tl-item h6 { font-weight: 800; margin: 4px 0 6px; color: #1e293b; }
        .tl-item p  { color: #64748b; font-size: 0.9rem; margin: 0; }

        /* ── Footer mini ────────────────── */
        .footer-mini {
            background: #1e293b; color: #94a3b8;
            text-align: center; padding: 24px 20px;
            font-size: 0.88rem; margin-top: 60px;
        }
        .footer-mini a { color: var(--azul); text-decoration: none; }

        /* ── Responsivo ─────────────────── */
        @media(max-width: 576px) {
            .hero h1 { font-size: 1.9rem; }
            .stat-num { font-size: 2.2rem; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════ -->
<div class="hero aparecer">
    <span class="hero-logo flotar">🏭</span>
    <div class="badge-mipyme">MIPYME · SURL · REGISTRO OFICIAL CUBA</div>
    <h1>PalWeb SURL</h1>
    <p>Productores, importadores y distribuidores de alimentos en Cuba.<br>
       Calidad garantizada, directo a tu puerta.</p>
    <a href="shop.php" class="btn-back">
        <i class="fas fa-arrow-left"></i> Volver a la Tienda
    </a>
</div>

<div class="container">

    <!-- ── ESTADÍSTICAS ─────────────────────────────── -->
    <div class="stats-band aparecer">
        <div class="row g-0">
            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <span class="stat-num pulsar">3+</span>
                    <div class="stat-label">Años operando</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <span class="stat-num pulsar">2</span>
                    <div class="stat-label">Almacenes propios</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <span class="stat-num pulsar">500+</span>
                    <div class="stat-label">Clientes satisfechos</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-item">
                    <span class="stat-num pulsar">15</span>
                    <div class="stat-label">Municipios cubiertos</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── QUIÉNES SOMOS ────────────────────────────── -->
    <div class="section">
        <div class="text-center mb-4">
            <div class="section-title">¿Quiénes Somos?</div>
            <p class="section-subtitle">Una empresa cubana con visión, capacidad y compromiso con la alimentación de nuestro pueblo.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-12 col-md-8">
                <div class="mv-card">
                    <p style="font-size:1.05rem; line-height:1.8; color:#334155;">
                        <strong>PalWeb SURL</strong> es una Micro, Pequeña y Mediana Empresa privada cubana,
                        constituida como <em>Sociedad Unipersonal de Responsabilidad Limitada</em>,
                        debidamente registrada y autorizada para operar en la República de Cuba.<br><br>
                        Somos una empresa integrada verticalmente: <strong>producimos</strong> nuestros propios
                        alimentos, <strong>importamos</strong> materias primas e insumos de calidad,
                        y <strong>distribuimos</strong> directamente al consumidor final, eliminando
                        intermediarios y garantizando el mejor precio y frescura en cada producto.<br><br>
                        Desde nuestra fundación, hemos trabajado con un solo objetivo:
                        <strong>poner alimentos de calidad al alcance de todos los cubanos</strong>,
                        con la eficiencia de una empresa moderna y el calor humano que nos caracteriza.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ── PILARES / LO QUE HACEMOS ─────────────────── -->
    <div class="section">
        <div class="text-center mb-4">
            <div class="section-title">Lo Que Hacemos</div>
            <p class="section-subtitle">Tres pilares que nos hacen únicos en el mercado cubano.</p>
        </div>
        <div class="row g-4">
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-rosado">
                    <span class="icon">🍰</span>
                    <h4>Producción Propia</h4>
                    <p>Elaboramos <strong>dulces artesanales</strong> y <strong>comidas semi-elaboradas congeladas</strong>
                       listas para comer. Todo producido en nuestras instalaciones con ingredientes de primera calidad,
                       bajo estrictos controles de higiene y inocuidad alimentaria.</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-azul">
                    <span class="icon">🌍</span>
                    <h4>Importación</h4>
                    <p>Contamos con <strong>autorización oficial para importar</strong> alimentos,
                       materias primas e insumos. Traemos productos de calidad internacional para
                       complementar nuestra oferta y garantizar disponibilidad todo el año,
                       independientemente de la estacionalidad local.</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-verde">
                    <span class="icon">🚚</span>
                    <h4>Distribución</h4>
                    <p>Operamos una <strong>flota propia de transporte</strong> y un equipo de
                       <strong>mensajeros especializados</strong> que cubre toda La Habana.
                       Entregamos en tiempo récord, con seguimiento de pedido en tiempo real
                       y atención personalizada a cada cliente.</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-ambar">
                    <span class="icon">🏪</span>
                    <h4>Almacenes Propios</h4>
                    <p>Contamos con <strong>almacenes equipados con cámaras de frío</strong>
                       que garantizan la conservación óptima de todos nuestros productos.
                       Nuestra infraestructura nos permite mantener grandes volúmenes de stock
                       y responder con agilidad a la demanda del mercado.</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-morado">
                    <span class="icon">❄️</span>
                    <h4>Congelados Listos</h4>
                    <p>Nuestra línea estrella: <strong>comidas semi-elaboradas congeladas</strong>.
                       Croquetas, empanadas, masas fritas, dulces de todo tipo y mucho más.
                       Preparadas con recetas tradicionales cubanas, solo necesitas calentarlas
                       para disfrutar de una comida casera y deliciosa.</p>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="pillar-card border-naranja">
                    <span class="icon">🛵</span>
                    <h4>Entrega a Domicilio</h4>
                    <p>Realizamos <strong>entregas a domicilio en todos los municipios de La Habana</strong>.
                       Nuestros mensajeros conocen cada rincón de la ciudad y garantizan
                       que tu pedido llegue fresco, seguro y en el tiempo prometido.
                       También puedes recoger en nuestras instalaciones.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ── PRODUCTOS DESTACADOS ──────────────────────── -->
    <div class="section text-center">
        <div class="section-title">Nuestros Productos</div>
        <p class="section-subtitle">Una selección de lo que encontrarás en nuestra tienda.</p>
        <div class="py-2">
            <span class="product-pill"><span class="dot" style="background:#ec4899"></span> Croquetas de Jamón</span>
            <span class="product-pill"><span class="dot" style="background:#f97316"></span> Empanadas Rellenas</span>
            <span class="product-pill"><span class="dot" style="background:#8b5cf6"></span> Dulces Finos</span>
            <span class="product-pill"><span class="dot" style="background:#22c55e"></span> Masas Fritas</span>
            <span class="product-pill"><span class="dot" style="background:#3b82f6"></span> Carne de Cerdo</span>
            <span class="product-pill"><span class="dot" style="background:#f59e0b"></span> Pollo Marinado</span>
            <span class="product-pill"><span class="dot" style="background:#ef4444"></span> Pizzas Congeladas</span>
            <span class="product-pill"><span class="dot" style="background:#06b6d4"></span> Arroz con Pollo</span>
            <span class="product-pill"><span class="dot" style="background:#10b981"></span> Tamales</span>
            <span class="product-pill"><span class="dot" style="background:#a855f7"></span> Natillas y Flanes</span>
            <span class="product-pill"><span class="dot" style="background:#f43f5e"></span> Pastelitos</span>
            <span class="product-pill"><span class="dot" style="background:#0ea5e9"></span> Bebidas Importadas</span>
        </div>
        <div class="mt-4">
            <a href="shop.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold">
                <i class="fas fa-shopping-bag me-2"></i> Ver Catálogo Completo
            </a>
        </div>
    </div>

    <!-- ── MISIÓN Y VISIÓN ───────────────────────────── -->
    <div class="section">
        <div class="row g-4">
            <div class="col-12 col-md-4">
                <div class="mv-card" style="border-top: 4px solid var(--azul);">
                    <h5><i class="fas fa-bullseye me-2" style="color:var(--azul)"></i>Misión</h5>
                    <p>Proveer a las familias cubanas de alimentos de calidad, accesibles y convenientes,
                       producidos con amor y rigor, entregados con rapidez y honestidad.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="mv-card" style="border-top: 4px solid var(--morado);">
                    <h5><i class="fas fa-eye me-2" style="color:var(--morado)"></i>Visión</h5>
                    <p>Ser la empresa de alimentos de referencia en Cuba, reconocida por su
                       innovación, calidad y compromiso inquebrantable con sus clientes y con la comunidad.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="mv-card" style="border-top: 4px solid var(--verde);">
                    <h5><i class="fas fa-star me-2" style="color:var(--verde)"></i>Valores</h5>
                    <p>Calidad · Honestidad · Puntualidad · Servicio al cliente · Responsabilidad social ·
                       Innovación cubana.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ── CÓMO LLEGAR / CONTACTO ───────────────────── -->
    <div class="section">
        <div class="text-center mb-4">
            <div class="section-title">Contáctanos</div>
            <p class="section-subtitle">Estamos para servirte. Escríbenos por WhatsApp o visítanos.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-12 col-md-5">
                <div class="mv-card text-center">
                    <div style="font-size:2.5rem; margin-bottom:12px;">📍</div>
                    <h5 class="fw-bold">Ubicación</h5>
                    <p><?= htmlspecialchars($config['direccion'] ?? 'La Habana, Cuba') ?></p>
                </div>
            </div>
            <div class="col-12 col-md-5">
                <div class="mv-card text-center">
                    <div style="font-size:2.5rem; margin-bottom:12px;">📱</div>
                    <h5 class="fw-bold">WhatsApp</h5>
                    <p>
                        <a href="https://wa.me/5352783083?text=Hola%2C%20quiero%20más%20información%20sobre%20PalWeb"
                           class="btn btn-success rounded-pill px-4 mt-2" target="_blank" rel="noopener noreferrer">
                            <i class="fab fa-whatsapp me-2"></i> Escríbenos
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ── MARCO LEGAL ──────────────────────────────── -->
    <div class="section">
        <div class="mv-card text-center" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7); border:1px solid #86efac;">
            <div style="font-size:2.5rem; margin-bottom:12px;">🇨🇺</div>
            <h5 class="fw-bold text-success">Marco Legal</h5>
            <p class="text-success" style="max-width:600px; margin:0 auto;">
                <strong>PalWeb SURL</strong> es una empresa constituida al amparo del
                <em>Decreto-Ley 46/2021</em> sobre MIPYMES en Cuba, con objeto social
                autorizado para la producción, importación, exportación y distribución
                de alimentos y bebidas. Operamos bajo estricto cumplimiento de la
                legislación cubana vigente.
            </p>
        </div>
    </div>

</div>

<!-- ── FOOTER ────────────────────────────────────── -->
<div class="footer-mini">
    <p class="mb-1">
        <strong>PalWeb SURL</strong> · MIPYME Cubana · <?= htmlspecialchars($config['direccion'] ?? 'La Habana, Cuba') ?>
    </p>
    <p class="mb-0">
        <a href="shop.php"><i class="fas fa-store me-1"></i>Tienda Online</a>
        &nbsp;·&nbsp;
        <a href="como_comprar.php"><i class="fas fa-question-circle me-1"></i>¿Cómo Comprar?</a>
        &nbsp;·&nbsp;
        <a href="https://wa.me/5352783083" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp me-1"></i>WhatsApp</a>
    </p>
</div>

</body>
</html>
