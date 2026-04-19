<?php
// ARCHIVO: guia_reservas.php — Guía de operador para el sistema de Reservas v3.0
session_start();
require_once 'config_loader.php';
$tienda = htmlspecialchars($config['tienda_nombre'] ?? 'Marinero');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guía de Reservas — <?= $tienda ?></title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/all.min.css">
<style>
    :root {
        --navy: #0f172a;
        --indigo: #6366f1;
        --blue: #3b82f6;
        --amber: #f59e0b;
        --green: #22c55e;
        --red: #ef4444;
        --slate: #64748b;
        --light: #f8fafc;
    }
    body { background:#f1f5f9; font-family:'Segoe UI',system-ui,sans-serif; color:#1e293b; }

    /* ── Top bar ── */
    .top-bar {
        background:var(--navy); color:white; padding:14px 24px;
        display:flex; align-items:center; justify-content:space-between;
        position:sticky; top:0; z-index:1000; box-shadow:0 2px 12px rgba(0,0,0,.3);
    }
    .top-bar h1 { font-size:1.1rem; font-weight:800; margin:0; letter-spacing:.02em; }
    .top-bar .badge-version { background:var(--indigo); color:white; font-size:.7rem; padding:3px 8px; border-radius:20px; font-weight:700; }

    /* ── Layout ── */
    .page-wrap { display:flex; gap:0; max-width:1300px; margin:0 auto; padding:28px 16px; }
    .sidebar {
        width:240px; flex-shrink:0; margin-right:24px;
        position:sticky; top:76px; align-self:flex-start; max-height:calc(100vh - 90px); overflow-y:auto;
    }
    .sidebar-inner { background:white; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:16px 0; }
    .sidebar h6 { font-size:.65rem; font-weight:800; text-transform:uppercase; letter-spacing:.1em; color:var(--slate); padding:8px 16px 4px; margin:0; }
    .sidebar a {
        display:flex; align-items:center; gap:8px; padding:6px 16px;
        font-size:.82rem; font-weight:600; color:#475569; text-decoration:none;
        border-left:3px solid transparent; transition:all .15s;
    }
    .sidebar a:hover, .sidebar a.active { color:var(--indigo); border-left-color:var(--indigo); background:#f0f4ff; }
    .sidebar a i { width:14px; text-align:center; font-size:.75rem; opacity:.7; }
    .content { flex:1; min-width:0; }

    /* ── Sections ── */
    .section-card {
        background:white; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.06);
        margin-bottom:28px; overflow:hidden;
    }
    .section-header {
        padding:16px 22px; border-bottom:2px solid var(--light);
        display:flex; align-items:center; gap:12px;
    }
    .section-icon {
        width:38px; height:38px; border-radius:10px;
        display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;
    }
    .section-header h2 { font-size:1.05rem; font-weight:800; margin:0; color:var(--navy); }
    .section-header .sec-num { font-size:.7rem; font-weight:900; color:var(--slate); letter-spacing:.05em; }
    .section-body { padding:20px 22px; }

    /* ── Steps ── */
    .step {
        display:flex; gap:14px; margin-bottom:18px; align-items:flex-start;
    }
    .step-num {
        width:28px; height:28px; border-radius:50%; background:var(--navy); color:white;
        font-size:.75rem; font-weight:900; display:flex; align-items:center; justify-content:center;
        flex-shrink:0; margin-top:1px;
    }
    .step-body h4 { font-size:.9rem; font-weight:700; margin:0 0 4px; color:var(--navy); }
    .step-body p  { font-size:.83rem; color:#475569; margin:0; line-height:1.55; }

    /* ── Tips / warnings ── */
    .tip, .warn, .info, .danger-box {
        border-radius:10px; padding:12px 16px; margin:14px 0;
        font-size:.83rem; display:flex; align-items:flex-start; gap:10px;
    }
    .tip    { background:#f0fdf4; border:1px solid #86efac; color:#14532d; }
    .warn   { background:#fffbeb; border:1px solid #fcd34d; color:#78350f; }
    .info   { background:#eff6ff; border:1px solid #93c5fd; color:#1e3a5f; }
    .danger-box { background:#fff1f2; border:1px solid #fca5a5; color:#7f1d1d; }
    .tip i, .warn i, .info i, .danger-box i { font-size:1rem; flex-shrink:0; margin-top:1px; }

    /* ── Badges ── */
    .badge-demo {
        display:inline-flex; align-items:center; gap:5px;
        font-size:.72rem; font-weight:700; padding:3px 9px; border-radius:20px;
        letter-spacing:.02em;
    }
    .bd-pendiente { background:#dbeafe; color:#1d4ed8; }
    .bd-hoy       { background:#fef9c3; color:#854d0e; }
    .bd-vencida   { background:#fee2e2; color:#991b1b; }
    .bd-entregado { background:#dcfce7; color:#166534; }
    .bd-cancelado { background:#f1f5f9; color:#64748b; }
    .bd-verificando { background:#fef9c3; color:#854d0e; border:1px solid #fcd34d; }
    .bd-confirmado  { background:#dcfce7; color:#166534; }
    .bd-pendpago    { background:#f1f5f9; color:#64748b; }

    /* ── Screen mockup ── */
    .mockup {
        background:var(--navy); border-radius:12px; padding:16px;
        font-family:'Courier New', monospace; font-size:.72rem; color:#94a3b8;
        line-height:1.7; margin:14px 0; overflow-x:auto;
    }
    .mockup .m-title { color:#f8fafc; font-weight:700; font-size:.75rem; }
    .mockup .m-green { color:#4ade80; }
    .mockup .m-amber { color:#fbbf24; }
    .mockup .m-red   { color:#f87171; }
    .mockup .m-blue  { color:#60a5fa; }
    .mockup .m-gray  { color:#64748b; }
    .mockup .m-btn   { background:#334155; color:#e2e8f0; padding:1px 6px; border-radius:4px; }

    /* ── Feature table ── */
    .feat-table { width:100%; font-size:.82rem; border-collapse:collapse; }
    .feat-table th { background:var(--navy); color:white; padding:8px 12px; font-weight:700; font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; }
    .feat-table td { padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .feat-table tr:hover td { background:#f8fafc; }
    .feat-table .btn-icon { font-size:1rem; }

    /* ── KPI demo ── */
    .kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin:14px 0; }
    .kpi-demo {
        flex:1; min-width:110px; background:var(--light); border-radius:10px;
        padding:10px 14px; border-left:4px solid;
    }
    .kpi-demo .num { font-size:1.6rem; font-weight:900; line-height:1; }
    .kpi-demo .lbl { font-size:.72rem; color:var(--slate); font-weight:600; margin-top:4px; }

    /* ── Scenario cards ── */
    .scenario {
        border-radius:10px; border:1px solid #e2e8f0; overflow:hidden; margin-bottom:14px;
    }
    .scenario-head {
        background:var(--light); padding:10px 16px;
        display:flex; align-items:center; gap:10px; border-bottom:1px solid #e2e8f0;
    }
    .scenario-head .s-icon { font-size:1.2rem; }
    .scenario-head strong { font-size:.88rem; font-weight:700; color:var(--navy); }
    .scenario-body { padding:12px 16px; font-size:.83rem; color:#475569; }
    .scenario-body ol { margin:0; padding-left:18px; }
    .scenario-body li { margin-bottom:5px; }

    /* ── Keyboard shortcut ── */
    kbd { background:#e2e8f0; color:#1e293b; border-radius:5px; padding:2px 6px; font-size:.75rem; font-family:monospace; border:1px solid #cbd5e1; box-shadow:0 1px 0 #94a3b8; }

    /* ── Print ── */
    @media print {
        .top-bar { position:static; }
        .sidebar { display:none; }
        .page-wrap { display:block; padding:10px; }
        .section-card { box-shadow:none; border:1px solid #e2e8f0; page-break-inside:avoid; }
    }

    /* ── Footer ── */
    .guide-footer { text-align:center; color:var(--slate); font-size:.72rem; padding:24px 0 10px; }
</style>
</head>
<body>

<!-- ── Top bar ──────────────────────────────────────────────────────────────── -->
<div class="top-bar">
    <div class="d-flex align-items-center gap-3">
        <i class="fas fa-book-open" style="font-size:1.2rem; color:#818cf8;"></i>
        <h1><i class="far fa-calendar-check me-2"></i>Guía de Operador — Gestión de Reservas</h1>
        <span class="badge-version">v3.0</span>
    </div>
    <div class="d-flex gap-2">
        <a href="reservas.php" class="btn btn-sm" style="background:#6366f1;color:white;font-weight:700;border-radius:8px;">
            <i class="fas fa-calendar-alt me-1"></i>Ir a Reservas
        </a>
        <a href="dashboard.php" class="btn btn-sm btn-outline-light">
            <i class="fas fa-home me-1"></i>Dashboard
        </a>
        <button class="btn btn-sm btn-outline-light" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Imprimir
        </button>
    </div>
</div>

<div class="page-wrap">

    <!-- ════════════════════════════════ SIDEBAR ════════════════════════════════ -->
    <aside class="sidebar d-none d-lg-block">
        <div class="sidebar-inner">
            <h6>Contenido</h6>
            <a href="#s1" onclick="setActive(this)"><i class="fas fa-eye"></i>1. Vista general</a>
            <a href="#s2" onclick="setActive(this)"><i class="fas fa-filter"></i>2. Filtros y búsqueda</a>
            <a href="#s3" onclick="setActive(this)"><i class="fas fa-plus-circle"></i>3. Crear reserva</a>
            <a href="#s4" onclick="setActive(this)"><i class="fas fa-edit"></i>4. Editar reserva</a>
            <a href="#s5" onclick="setActive(this)"><i class="fas fa-check-double"></i>5. Entregar</a>
            <a href="#s6" onclick="setActive(this)"><i class="fas fa-utensils"></i>6. Enviar a cocina</a>
            <a href="#s7" onclick="setActive(this)"><i class="fas fa-credit-card"></i>7. Confirmar pago</a>
            <a href="#s8" onclick="setActive(this)"><i class="fas fa-times-circle"></i>8. Cancelar</a>
            <a href="#s9" onclick="setActive(this)"><i class="fas fa-calendar-alt"></i>9. Almanaque</a>
            <a href="#s10" onclick="setActive(this)"><i class="fas fa-user-plus"></i>10. Nuevo cliente</a>
            <a href="#s11" onclick="setActive(this)"><i class="fas fa-file-import"></i>11. Importar .ICS</a>
            <a href="#s12" onclick="setActive(this)"><i class="fas fa-exclamation-triangle"></i>12. Alertas</a>
            <a href="#s13" onclick="setActive(this)"><i class="fas fa-star"></i>13. Escenarios frecuentes</a>
            <div style="border-top:1px solid #f1f5f9; margin:10px 0;"></div>
            <h6>Estado de reservas</h6>
            <div style="padding:8px 16px 4px; display:flex; flex-direction:column; gap:6px;">
                <span class="badge-demo bd-pendiente"><i class="fas fa-clock"></i>Pendiente</span>
                <span class="badge-demo bd-hoy"><i class="fas fa-calendar-day"></i>Para hoy</span>
                <span class="badge-demo bd-vencida"><i class="fas fa-exclamation"></i>Vencida</span>
                <span class="badge-demo bd-entregado"><i class="fas fa-check"></i>Entregado</span>
                <span class="badge-demo bd-cancelado"><i class="fas fa-ban"></i>Cancelado</span>
            </div>
        </div>
    </aside>

    <!-- ═══════════════════════════════ CONTENT ════════════════════════════════ -->
    <div class="content">

        <!-- ── Introducción ─────────────────────────────────────────────────── -->
        <div class="section-card" id="s0">
            <div class="section-body" style="padding:22px 24px;">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="font-size:2.5rem;">📋</div>
                    <div>
                        <h2 style="margin:0; font-size:1.2rem; font-weight:900; color:var(--navy);">
                            Manual de Operador — Sistema de Reservas
                        </h2>
                        <p class="text-muted mb-0" style="font-size:.85rem;">
                            <?= $tienda ?> · <?= htmlspecialchars(config_loader_system_name()) ?> v3.0 · Esta guía explica cada función del módulo
                            <strong>reservas.php</strong> paso a paso para cajeros y operadores.
                        </p>
                    </div>
                </div>
                <div class="info">
                    <i class="fas fa-info-circle text-primary"></i>
                    <span>Una <strong>reserva</strong> es un pedido anticipado: el cliente aparta productos o servicios
                    para una fecha futura. A diferencia de una venta normal, el inventario <em>no se descuenta</em>
                    hasta que se marca como <strong>Entregado</strong>.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 1 — VISTA GENERAL
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s1">
            <div class="section-header">
                <div class="section-icon" style="background:#ede9fe;"><i class="fas fa-eye" style="color:#6366f1;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 01</div>
                    <h2>Vista general de la pantalla</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Al abrir <code>reservas.php</code> encontrarás tres zonas principales:
                </p>

                <div class="mockup">
<span class="m-title">┌─────────────────────────────────────────────────────────────────────┐</span>
<span class="m-title">│  📅 Gestión de Reservas       [CRM] [Dashboard] [Importar] [+ Nueva] │</span>
<span class="m-title">└─────────────────────────────────────────────────────────────────────┘</span>
<span class="m-amber">  ⚠ 2 reservas con productos sin existencias   ← alerta (si hay)</span>

<span class="m-blue">  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐</span>
<span class="m-blue">  │    12    │  │    3     │  │    1     │  │    2     │</span>
<span class="m-blue">  │Pendientes│  │ Para hoy │  │ Vencidas │  │Verif.pago│</span>
<span class="m-blue">  └──────────┘  └──────────┘  └──────────┘  └──────────┘</span>
                        ↑ KPIs de estado

<span class="m-green">  [Lista|Almanaque] [Pendientes|Entregados|Cancelados|Todos] [Hoy|7 días|Vencidas]</span>
<span class="m-gray">  🔍 Buscar por cliente...                           Total: 12</span>
                        ↑ Barra de filtros

  ID │ Cliente    │ Fecha Reserva    │ Productos │ Total/Deuda │ Pago │ Estado │ Acciones
  ───┼────────────┼──────────────────┼───────────┼─────────────┼──────┼────────┼─────────
  42 │ Ana López  │ 22 feb 09:00     │ Pastel x1 │ $500 / $300 │ Ef.  │<span class="m-amber">Hoy</span>    │ <span class="m-btn">✏ ✓ 🍳 ✕</span>
  38 │ Carlos Ruiz│ 25 feb 14:00     │ Cake x2   │ $800 / $800 │ Tr.  │<span class="m-blue">Pend.</span>  │ <span class="m-btn">✏ ✓ 🍳 ✕</span>
                </div>

                <div class="kpi-row">
                    <div class="kpi-demo" style="border-color:#6366f1;">
                        <div class="num" style="color:#6366f1;">12</div>
                        <div class="lbl">📋 Pendientes</div>
                    </div>
                    <div class="kpi-demo" style="border-color:#f59e0b;">
                        <div class="num" style="color:#f59e0b;">3</div>
                        <div class="lbl">📅 Para hoy</div>
                    </div>
                    <div class="kpi-demo" style="border-color:#ef4444;">
                        <div class="num" style="color:#ef4444;">1</div>
                        <div class="lbl">⏰ Vencidas</div>
                    </div>
                    <div class="kpi-demo" style="border-color:#22c55e;">
                        <div class="num" style="color:#22c55e;">2</div>
                        <div class="lbl">💳 Verif. pago</div>
                    </div>
                </div>

                <div class="tip">
                    <i class="fas fa-lightbulb text-success"></i>
                    <span>Los KPIs se actualizan cada vez que recargas la página o aplicas un filtro.
                    Si el número de <em>Vencidas</em> sube, atiende esas reservas primero.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 2 — FILTROS
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s2">
            <div class="section-header">
                <div class="section-icon" style="background:#fef3c7;"><i class="fas fa-filter" style="color:#d97706;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 02</div>
                    <h2>Filtros y búsqueda</h2>
                </div>
            </div>
            <div class="section-body">
                <table class="feat-table">
                    <thead>
                        <tr>
                            <th>Filtro</th>
                            <th>Opciones</th>
                            <th>Para qué sirve</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Vista</strong></td>
                            <td><kbd>Lista</kbd> / <kbd>Almanaque</kbd></td>
                            <td>Cambia entre tabla de filas y vista de calendario mensual</td>
                        </tr>
                        <tr>
                            <td><strong>Estado</strong></td>
                            <td>Pendientes · Entregados · Cancelados · Todos</td>
                            <td>Muestra solo reservas en ese estado. Por defecto: <em>Pendientes</em></td>
                        </tr>
                        <tr>
                            <td><strong>Fecha</strong></td>
                            <td>Todas · Hoy · Próx. 7 días · Vencidas</td>
                            <td>Filtra por la fecha de entrega programada</td>
                        </tr>
                        <tr>
                            <td><strong>Buscar</strong></td>
                            <td>Texto libre</td>
                            <td>Busca por nombre o teléfono del cliente. Presiona <kbd>Enter</kbd> o clic en <em>Buscar</em></td>
                        </tr>
                        <tr>
                            <td><strong>✕ Limpiar</strong></td>
                            <td>Botón rojo</td>
                            <td>Borra todos los filtros aplicados y vuelve a la vista por defecto</td>
                        </tr>
                    </tbody>
                </table>

                <div class="tip mt-3">
                    <i class="fas fa-lightbulb text-success"></i>
                    <span>Al inicio de cada turno usa <strong>Hoy</strong> en el filtro de fecha para ver solo
                    las entregas programadas para este día.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 3 — CREAR RESERVA
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s3">
            <div class="section-header">
                <div class="section-icon" style="background:#dbeafe;"><i class="fas fa-plus-circle" style="color:#2563eb;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 03</div>
                    <h2>Crear una nueva reserva</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Haz clic en el botón azul <strong>+ Nueva Reserva</strong> (esquina superior derecha).
                    Se abre un formulario dividido en dos columnas:
                </p>

                <div class="row g-3 mb-3">
                    <!-- Columna izquierda -->
                    <div class="col-md-6">
                        <div style="background:var(--light); border-radius:10px; padding:14px; border:1px solid #e2e8f0;">
                            <div class="fw-bold mb-2" style="font-size:.82rem; color:var(--navy);">
                                <i class="fas fa-user me-1 text-primary"></i> COLUMNA IZQUIERDA — Cliente + Detalles
                            </div>
                            <div class="step">
                                <div class="step-num">1</div>
                                <div class="step-body">
                                    <h4>Buscar cliente existente</h4>
                                    <p>Escribe el nombre o teléfono en el campo de búsqueda. Aparecerán sugerencias en tiempo real. Haz clic en el cliente para seleccionarlo — sus datos se rellenan solos.</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">2</div>
                                <div class="step-body">
                                    <h4>O créalo nuevo</h4>
                                    <p>Si el cliente no existe, clic en <strong>+ Nuevo</strong> (botón verde). Se abre un mini-formulario. Ver <a href="#s10">Sección 10</a>.</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">3</div>
                                <div class="step-body">
                                    <h4>Fecha y hora de entrega</h4>
                                    <p>Selecciona el día y la hora exacta en que el cliente vendrá a recoger o recibirá la entrega. <strong>Este campo es obligatorio.</strong></p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">4</div>
                                <div class="step-body">
                                    <h4>Método de pago</h4>
                                    <p>
                                        <strong>Efectivo</strong> — el cliente paga en efectivo<br>
                                        <strong>Transferencia</strong> — pagó por Enzona o Transfermovil<br>
                                        <strong>Pendiente</strong> — se definirá al entregar
                                    </p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">5</div>
                                <div class="step-body">
                                    <h4>Abono recibido</h4>
                                    <p>Si el cliente dejó un adelanto, escríbelo aquí. La deuda restante se calcula automáticamente (<em>Total − Abono</em>).</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">6</div>
                                <div class="step-body">
                                    <h4>Notas internas</h4>
                                    <p>Instrucciones especiales: color de pastel, dedicatoria, alergias, dirección de entrega extra, etc. El cliente no verá estas notas.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Columna derecha -->
                    <div class="col-md-6">
                        <div style="background:var(--light); border-radius:10px; padding:14px; border:1px solid #e2e8f0;">
                            <div class="fw-bold mb-2" style="font-size:.82rem; color:var(--navy);">
                                <i class="fas fa-box me-1 text-warning"></i> COLUMNA DERECHA — Productos
                            </div>
                            <div class="step">
                                <div class="step-num">7</div>
                                <div class="step-body">
                                    <h4>Buscar producto</h4>
                                    <p>Escribe el nombre o código del producto en el campo de búsqueda. Aparecen sugerencias con el stock actual. Haz clic para agregar al pedido.</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">8</div>
                                <div class="step-body">
                                    <h4>Ajustar cantidad y precio</h4>
                                    <p>Cada fila de la tabla tiene los campos de <em>Cantidad</em> y <em>Precio</em> editables directamente. El total se recalcula solo.</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">9</div>
                                <div class="step-body">
                                    <h4>Quitar un producto</h4>
                                    <p>Clic en el botón <strong style="color:#ef4444;">🗑</strong> a la derecha de la fila para eliminarlo del pedido.</p>
                                </div>
                            </div>
                            <div class="step">
                                <div class="step-num">10</div>
                                <div class="step-body">
                                    <h4>Guardar la reserva</h4>
                                    <p>Haz clic en <strong>Guardar Reserva</strong> (botón azul en el pie del modal). El sistema crea la reserva y la agrega a la lista con estado <span class="badge-demo bd-pendiente">Pendiente</span>.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="warn">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    <span>Debes agregar <strong>al menos un producto</strong>. Si intentas guardar sin productos, el sistema mostrará un error en rojo.</span>
                </div>
                <div class="info">
                    <i class="fas fa-info-circle text-primary"></i>
                    <span>Los productos con stock 0 se pueden agregar igualmente a una reserva —
                    el inventario se descuenta solo al momento de la entrega, no al crear la reserva.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 4 — EDITAR
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s4">
            <div class="section-header">
                <div class="section-icon" style="background:#e0f2fe;"><i class="fas fa-edit" style="color:#0284c7;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 04</div>
                    <h2>Editar una reserva existente</h2>
                </div>
            </div>
            <div class="section-body">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Botón ✏ Editar</h4>
                        <p>En la columna <em>Acciones</em> de cada fila, haz clic en el ícono de lápiz <strong>✏</strong>.
                        Se abre el mismo formulario de creación pero con los datos actuales de la reserva precargados.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Modificar lo que necesites</h4>
                        <p>Puedes cambiar: cliente, fecha de entrega, método de pago, abono, notas, y los productos del pedido (agregar, quitar, cambiar cantidades o precios).</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h4>Guardar cambios</h4>
                        <p>Clic en <strong>Guardar Reserva</strong>. El sistema <em>reemplaza</em> los productos anteriores por los nuevos y actualiza todos los campos. La tabla se refresca automáticamente.</p>
                    </div>
                </div>
                <div class="warn">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    <span>Editar una reserva <strong>ya entregada o cancelada</strong> puede causar inconsistencias.
                    Si necesitas corregir una reserva cerrada, consulta al supervisor.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 5 — ENTREGAR
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s5">
            <div class="section-header">
                <div class="section-icon" style="background:#dcfce7;"><i class="fas fa-check-double" style="color:#16a34a;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 05</div>
                    <h2>Marcar una reserva como Entregada</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Esta es la acción más importante. Al marcar <em>Entregado</em> el sistema:
                </p>
                <ul style="font-size:.85rem; color:#475569; margin-bottom:14px;">
                    <li>Cambia el estado a <span class="badge-demo bd-entregado"><i class="fas fa-check"></i>Entregado</span></li>
                    <li>Descuenta las cantidades del inventario (Kardex de salida tipo <code>VENTA</code>)</li>
                    <li>Registra la referencia <code>ENTREGA-RESERVA-{id}</code> en el kardex</li>
                </ul>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Verificar con el cliente</h4>
                        <p>Confirma que el cliente recibió todos los productos y que el pago está completo.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Clic en ✓ Entregar</h4>
                        <p>En la columna <em>Acciones</em>, clic en el ícono de check verde <strong>✓</strong>.
                        El sistema pedirá confirmación.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h4>Leer las advertencias de stock</h4>
                        <p>Si algún producto no tenía suficiente stock al momento de la entrega, aparecerá un aviso en naranja con el detalle. <strong>La entrega se registra igual</strong>, pero debes reportarlo al supervisor.</p>
                    </div>
                </div>
                <div class="danger-box">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><strong>Importante:</strong> esta acción <u>no se puede deshacer</u> fácilmente.
                    Asegúrate de que la reserva correcta está marcada antes de confirmar.
                    Los movimientos de Kardex quedan registrados de forma permanente.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 6 — ENVIAR A COCINA
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s6">
            <div class="section-header">
                <div class="section-icon" style="background:#fef9c3;"><i class="fas fa-utensils" style="color:#ca8a04;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 06</div>
                    <h2>Enviar a cocina</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Algunas reservas incluyen productos elaborados (pasteles, tortas, comida preparada).
                    Este botón envía una <strong>comanda</strong> al sistema de cocina (<code>cocina.php</code>)
                    para que el equipo de producción comience a prepararlo.
                </p>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Clic en 🍳 Cocina</h4>
                        <p>En la columna <em>Acciones</em>, clic en el ícono de sartén <strong>🍳</strong>.
                        El sistema filtra automáticamente solo los productos marcados como <em>elaborados</em>.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Confirmar</h4>
                        <p>La comanda se registra con estado <em>pendiente</em> y el cocinero la verá en <code>cocina.php</code>.
                        Cada reserva solo puede enviarse a cocina una vez.</p>
                    </div>
                </div>
                <div class="tip">
                    <i class="fas fa-lightbulb text-success"></i>
                    <span>Si la reserva no tiene productos elaborados, el botón mostrará un error informativo.
                    No tienes que hacer nada en ese caso.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 7 — CONFIRMAR PAGO
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s7">
            <div class="section-header">
                <div class="section-icon" style="background:#fef9c3;"><i class="fas fa-credit-card" style="color:#d97706;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 07</div>
                    <h2>Confirmar pago por transferencia</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Cuando un cliente paga por Enzona o Transfermovil, su reserva queda con estado
                    <span class="badge-demo bd-verificando">💳 Verificando</span>.
                    El operador debe verificar la transferencia en la app bancaria y luego confirmarla aquí.
                </p>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Identificar la reserva</h4>
                        <p>Usa el filtro <em>Verificando pago</em> del KPI (clic en la tarjeta verde) o busca
                        al cliente por nombre. Las reservas con pago pendiente muestran el badge
                        <span class="badge-demo bd-verificando">💳 Verificando</span>.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Verificar en la app del banco</h4>
                        <p>Comprueba que recibiste el monto correcto en Enzona o Transfermovil antes de confirmar.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h4>Clic en 💳 Confirmar pago</h4>
                        <p>Aparece en el menú de acciones cuando el estado_pago es <em>verificando</em>.
                        El sistema cambia el estado a <span class="badge-demo bd-confirmado">✓ Confirmado</span>
                        y envía una notificación automática al cliente por el chat del sistema.</p>
                    </div>
                </div>
                <div class="warn">
                    <i class="fas fa-exclamation-triangle text-warning"></i>
                    <span>Nunca confirmes un pago sin verificar primero el recibo bancario.
                    Una vez confirmado, el sistema notifica al cliente que su pedido está activo.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 8 — CANCELAR
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s8">
            <div class="section-header">
                <div class="section-icon" style="background:#fee2e2;"><i class="fas fa-times-circle" style="color:#dc2626;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 08</div>
                    <h2>Cancelar una reserva</h2>
                </div>
            </div>
            <div class="section-body">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Clic en ✕ Cancelar</h4>
                        <p>En la columna <em>Acciones</em>, clic en la X roja. El sistema pedirá confirmación en el navegador antes de proceder.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>La reserva queda marcada como Cancelada</h4>
                        <p>Aparece con el badge <span class="badge-demo bd-cancelado"><i class="fas fa-ban"></i>Cancelado</span>
                        y deja de aparecer en la vista principal (filtro <em>Pendientes</em>).
                        Usa <em>Cancelados</em> en los filtros de estado para verla.</p>
                    </div>
                </div>
                <div class="info">
                    <i class="fas fa-info-circle text-primary"></i>
                    <span>Cancelar una reserva <strong>no mueve el inventario</strong> porque nunca se descontó.
                    Si el cliente hizo un abono, debes gestionar la devolución de forma manual.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 9 — ALMANAQUE
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s9">
            <div class="section-header">
                <div class="section-icon" style="background:#ede9fe;"><i class="fas fa-calendar-alt" style="color:#7c3aed;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 09</div>
                    <h2>Vista Almanaque (calendario mensual)</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    El almanaque muestra las reservas distribuidas en una cuadrícula de días del mes.
                    Es ideal para ver de un vistazo qué días están más ocupados.
                </p>

                <div class="mockup">
<span class="m-title">   [Lista] [Almanaque ▶]          ← botones toggle (arriba izquierda)</span>

<span class="m-blue">  ◀    Febrero 2026    [Hoy] ▶</span>
<span class="m-gray">  ┌──────────────────────────────────────────────────────────────────┐</span>
<span class="m-gray">  │ Lun   Mar   Mié   Jue   Vie   Sáb   Dom                        │</span>
<span class="m-gray">  ├──────────────────────────────────────────────────────────────────┤</span>
<span class="m-gray">  │  27    28    29    30    31   </span> <span class="m-gray">  1     2                          │</span>
<span class="m-gray">  │                                  </span><span class="m-blue">AnaL</span>                             <span class="m-gray">│</span>
<span class="m-gray">  ├──────────────────────────────────────────────────────────────────┤</span>
<span class="m-gray">  │   3     4     5     6     7     8     9                         │</span>
<span class="m-amber">  │         22</span><span class="m-gray">          </span><span class="m-green">Carl</span>                                           <span class="m-gray">│</span>
<span class="m-amber">  │        Hoy</span><span class="m-gray">                                                       │</span>
<span class="m-gray">  └──────────────────────────────────────────────────────────────────┘</span>
                </div>

                <table class="feat-table mb-3">
                    <thead><tr><th>Elemento</th><th>Descripción</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><span class="badge-demo bd-pendiente">NombreCliente</span></td>
                            <td>Reserva pendiente (entrega futura)</td>
                        </tr>
                        <tr>
                            <td><span class="badge-demo bd-hoy">NombreCliente</span></td>
                            <td>Entrega programada para hoy</td>
                        </tr>
                        <tr>
                            <td><span class="badge-demo bd-vencida">NombreCliente</span></td>
                            <td>Fecha de entrega ya pasó y no se marcó entregado</td>
                        </tr>
                        <tr>
                            <td><span class="badge-demo bd-entregado">NombreCliente</span></td>
                            <td>Ya entregado</td>
                        </tr>
                        <tr>
                            <td><span class="badge-demo bd-cancelado" style="text-decoration:line-through;">NombreCliente</span></td>
                            <td>Cancelado</td>
                        </tr>
                        <tr>
                            <td><span class="badge-demo" style="background:#f1f5f9;color:#6366f1;border-left:3px solid #6366f1;">+2 más</span></td>
                            <td>Hay más reservas ese día. Clic para elegir cuál ver.</td>
                        </tr>
                    </tbody>
                </table>

                <div class="step">
                    <div class="step-num">→</div>
                    <div class="step-body">
                        <h4>Navegar entre meses</h4>
                        <p>Usa los botones <strong>◀</strong> y <strong>▶</strong> para ir al mes anterior o siguiente.
                        Clic en <strong>Hoy</strong> para volver al mes actual.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">→</div>
                    <div class="step-body">
                        <h4>Ver detalle de una reserva</h4>
                        <p>Haz clic sobre el chip de nombre del cliente. Se abre el modal de detalle
                        con todos los datos de esa reserva y los botones de acción.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">→</div>
                    <div class="step-body">
                        <h4>Días con más de 3 reservas</h4>
                        <p>Si un día tiene más de 3 reservas, aparece el chip <strong>+N más</strong> en morado.
                        Al hacer clic, se abre un cuadro de diálogo donde puedes escribir el ID de la reserva
                        que quieres ver.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 10 — NUEVO CLIENTE
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s10">
            <div class="section-header">
                <div class="section-icon" style="background:#dcfce7;"><i class="fas fa-user-plus" style="color:#16a34a;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 10</div>
                    <h2>Crear un cliente nuevo rápido</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Si el cliente no existe en el sistema, puedes crearlo sin salir del formulario de reserva.
                </p>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Clic en + Nuevo (botón verde)</h4>
                        <p>Dentro del formulario de reserva, en la sección <em>Cliente</em>, aparece el botón verde <strong>+ Nuevo</strong>.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Llenar el mini-formulario</h4>
                        <p>Nombre completo (obligatorio), teléfono, dirección y categoría (Regular, VIP, Corporativo, etc.).</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h4>Guardar cliente</h4>
                        <p>El cliente se crea en la base de datos y se selecciona automáticamente para la reserva actual.</p>
                    </div>
                </div>
                <div class="tip">
                    <i class="fas fa-lightbulb text-success"></i>
                    <span>Para gestionar clientes en profundidad (historial, crédito, notas), usa
                    <a href="crm_clients.php" target="_blank">CRM Clientes</a> desde el botón de la barra superior.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 11 — IMPORTAR ICS
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s11">
            <div class="section-header">
                <div class="section-icon" style="background:#f3e8ff;"><i class="fas fa-file-import" style="color:#9333ea;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 11</div>
                    <h2>Importar reservas desde archivo .ICS</h2>
                </div>
            </div>
            <div class="section-body">
                <p style="font-size:.85rem; color:#475569;">
                    Los archivos <code>.ics</code> son exportaciones de calendarios (Google Calendar, Outlook, iPhone).
                    Si tienes reservas anotadas allí, puedes importarlas de golpe.
                </p>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-body">
                        <h4>Exportar el calendario</h4>
                        <p>En Google Calendar: <em>Ajustes → Importar y exportar → Exportar</em>. Descarga el archivo <code>.ics</code>.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-body">
                        <h4>Clic en Importar .ICS</h4>
                        <p>En la barra superior de <code>reservas.php</code>, clic en el botón amarillo <strong>Importar .ICS</strong>.
                        Selecciona el archivo en tu computadora.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-body">
                        <h4>Revisar lo importado</h4>
                        <p>El sistema confirmará cuántas reservas se crearon. Los eventos duplicados (mismo UID) se saltan automáticamente. Las reservas importadas no tienen productos — deberás editarlas para agregarlos.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 12 — ALERTAS
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s12">
            <div class="section-header">
                <div class="section-icon" style="background:#fef2f2;"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 12</div>
                    <h2>Alertas del sistema</h2>
                </div>
            </div>
            <div class="section-body">
                <table class="feat-table">
                    <thead>
                        <tr>
                            <th>Alerta</th>
                            <th>Qué significa</th>
                            <th>Qué hacer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div style="background:#fee2e2; border-radius:6px; padding:6px 10px; font-size:.78rem; font-weight:700; color:#991b1b;">
                                    ⚠ N reservas con productos sin existencias
                                </div>
                            </td>
                            <td>Hay reservas pendientes cuyo stock era 0 al momento de reservar.</td>
                            <td>Gestionar compra o producción del producto antes de la fecha de entrega. Las filas afectadas aparecen resaltadas en rojo en la tabla.</td>
                        </tr>
                        <tr>
                            <td>
                                <span class="badge-demo bd-vencida" style="font-size:.8rem;">⏰ Vencida</span>
                            </td>
                            <td>La fecha de entrega ya pasó y la reserva sigue en estado Pendiente.</td>
                            <td>Contactar al cliente para reagendar o marcar como entregado/cancelado según corresponda.</td>
                        </tr>
                        <tr>
                            <td>
                                <span class="badge-demo bd-verificando" style="font-size:.8rem;">💳 Verificando</span>
                            </td>
                            <td>El cliente declaró que pagó por transferencia pero aún no se verificó.</td>
                            <td>Comprobar en la app bancaria y usar <em>Confirmar pago</em> si es correcto. Ver <a href="#s7">Sección 7</a>.</td>
                        </tr>
                        <tr>
                            <td>
                                <div style="background:#fffbeb; border-radius:6px; padding:6px 10px; font-size:.78rem; font-weight:700; color:#78350f;">
                                    ⚠ Entregado con advertencias de stock
                                </div>
                            </td>
                            <td>Al entregar, algún producto no tenía stock suficiente y se generó saldo negativo en inventario.</td>
                            <td>Reportar al supervisor. Verificar físicamente el inventario y hacer un ajuste si es necesario.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             SECCIÓN 13 — ESCENARIOS FRECUENTES
        ═══════════════════════════════════════════════════════════════════════ -->
        <div class="section-card" id="s13">
            <div class="section-header">
                <div class="section-icon" style="background:#fef9c3;"><i class="fas fa-star" style="color:#ca8a04;"></i></div>
                <div>
                    <div class="sec-num">SECCIÓN 13</div>
                    <h2>Escenarios frecuentes — paso a paso</h2>
                </div>
            </div>
            <div class="section-body">

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">📞</div>
                        <strong>Un cliente llama para reservar un pastel para el sábado</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>Clic en <strong>+ Nueva Reserva</strong></li>
                            <li>Buscar el cliente por teléfono en el campo de búsqueda. Si no existe, crear nuevo (<a href="#s10">Sección 10</a>)</li>
                            <li>Seleccionar la fecha: sábado a la hora acordada</li>
                            <li>Método de pago: <em>Pendiente</em> (pagará al recoger)</li>
                            <li>Buscar "pastel" en la sección de productos, agregar cantidad</li>
                            <li>Si dejó señal (abono): anotar el monto en <em>Abono recibido</em></li>
                            <li>Notas: escribir cualquier detalle (sabor, color, dedicatoria)</li>
                            <li>Clic en <strong>Guardar Reserva</strong></li>
                            <li>Enviarlo a cocina con <strong>🍳 Cocina</strong> para que comiencen a prepararlo</li>
                        </ol>
                    </div>
                </div>

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">🚪</div>
                        <strong>El cliente llegó a recoger su reserva</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>Buscar la reserva por nombre del cliente (filtro de búsqueda)</li>
                            <li>Verificar los productos y el monto total</li>
                            <li>Cobrar la deuda restante (<em>Total − Abono ya pagado</em>)</li>
                            <li>Clic en <strong>✓ Entregar</strong> en la columna de acciones</li>
                            <li>Confirmar en el diálogo. El inventario se descuenta automáticamente</li>
                        </ol>
                    </div>
                </div>

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">💳</div>
                        <strong>Un cliente pagó por transferencia desde shop.php</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>En el KPI, observar el número en <em>Verificando pago</em></li>
                            <li>Abrir la app bancaria y verificar que el monto coincide</li>
                            <li>En reservas.php, buscar la reserva del cliente</li>
                            <li>En <em>Acciones</em>, clic en <strong>💳 Confirmar pago</strong></li>
                            <li>El estado cambia a <span class="badge-demo bd-confirmado">✓ Confirmado</span> y el cliente recibe notificación en el chat</li>
                        </ol>
                    </div>
                </div>

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">❌</div>
                        <strong>El cliente canceló su reserva con abono pendiente</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>Abrir la reserva con el botón <strong>✏ Editar</strong></li>
                            <li>Tomar nota del abono registrado (referencia para la devolución)</li>
                            <li>Cerrar el modal, luego clic en <strong>✕ Cancelar</strong></li>
                            <li>Confirmar la cancelación</li>
                            <li>La devolución del abono se gestiona en caja manualmente</li>
                        </ol>
                    </div>
                </div>

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">📅</div>
                        <strong>Revisar la carga de trabajo de la semana</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>En la barra de filtros, clic en <strong>Almanaque</strong></li>
                            <li>Navegar al mes actual si no está ya</li>
                            <li>Ver de un vistazo qué días tienen más reservas</li>
                            <li>Los días con chips amarillos (hoy) o rojos (vencidas) requieren atención inmediata</li>
                            <li>Clic en cualquier chip de nombre para ver el detalle de esa reserva</li>
                        </ol>
                    </div>
                </div>

                <div class="scenario">
                    <div class="scenario-head">
                        <div class="s-icon">📦</div>
                        <strong>Reserva con advertencia de "sin stock"</strong>
                    </div>
                    <div class="scenario-body">
                        <ol>
                            <li>La fila de la reserva aparece resaltada en rojo en la tabla</li>
                            <li>Abrir la reserva con <strong>✏ Editar</strong> para ver qué producto falta</li>
                            <li>Coordinar con el encargado de compras o producción para abastecerse antes de la fecha</li>
                            <li>Una vez que llegue el producto, el stock se actualizará solo en el sistema</li>
                            <li>Cuando el stock sea suficiente, el resaltado rojo desaparece al recargar</li>
                        </ol>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Referencia rápida de acciones ──────────────────────────────── -->
        <div class="section-card">
            <div class="section-header">
                <div class="section-icon" style="background:#f0fdf4;"><i class="fas fa-table" style="color:#16a34a;"></i></div>
                <div>
                    <div class="sec-num">REFERENCIA</div>
                    <h2>Botones de acción en la tabla</h2>
                </div>
            </div>
            <div class="section-body">
                <table class="feat-table">
                    <thead>
                        <tr><th>Icono</th><th>Nombre</th><th>Qué hace</th><th>Disponible cuando</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="font-size:1.1rem;">✏</td>
                            <td><strong>Editar</strong></td>
                            <td>Abre el formulario de edición con los datos actuales</td>
                            <td>Siempre</td>
                        </tr>
                        <tr>
                            <td style="font-size:1.1rem; color:#22c55e;">✓</td>
                            <td><strong>Entregar</strong></td>
                            <td>Marca como entregado y deduce el inventario (Kardex)</td>
                            <td>Estado = Pendiente</td>
                        </tr>
                        <tr>
                            <td style="font-size:1.1rem;">🍳</td>
                            <td><strong>Cocina</strong></td>
                            <td>Envía productos elaborados a la pantalla de cocina</td>
                            <td>Siempre (muestra error si no hay elaborados)</td>
                        </tr>
                        <tr>
                            <td style="font-size:1.1rem; color:#d97706;">💳</td>
                            <td><strong>Confirmar pago</strong></td>
                            <td>Valida el pago por transferencia y notifica al cliente</td>
                            <td>estado_pago = verificando</td>
                        </tr>
                        <tr>
                            <td style="font-size:1.1rem; color:#ef4444;">✕</td>
                            <td><strong>Cancelar</strong></td>
                            <td>Marca la reserva como cancelada (sin efecto en inventario)</td>
                            <td>Estado = Pendiente</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /page-wrap -->

<div class="guide-footer">
    Sistema <?= htmlspecialchars(config_loader_system_name()) ?> v3.0 — <?= $tienda ?> · guia_reservas.php
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
function setActive(el) {
    document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
    el.classList.add('active');
}

// Highlight active section on scroll
const sections = document.querySelectorAll('[id^="s"]');
const links    = document.querySelectorAll('.sidebar a');

window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(sec => {
        if (window.scrollY >= sec.offsetTop - 100) current = sec.id;
    });
    links.forEach(a => {
        a.classList.toggle('active', a.getAttribute('href') === '#' + current);
    });
}, { passive: true });
</script>
</body>
</html>
