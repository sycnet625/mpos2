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
    <title>Red de Afiliados</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root{
            --bg:#0a0800;
            --panel:#151100;
            --panel-soft:rgba(255,215,0,.04);
            --border:rgba(255,215,0,.12);
            --gold:#ffd700;
            --amber:#ff8c00;
            --fire:#ff4500;
            --text:#f7f3d4;
            --muted:#9c9270;
            --success:#22c55e;
            --danger:#ef5350;
        }
        *{box-sizing:border-box}
        body{margin:0;background:radial-gradient(circle at top, rgba(255,140,0,.10), transparent 26%),linear-gradient(180deg,#0a0800 0%,#0f0b00 100%);color:var(--text);font-family:Georgia, "Times New Roman", serif}
        a{text-decoration:none;color:inherit}
        .wrap{max-width:1240px;margin:0 auto;padding:20px 16px 40px}
        .topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.28);backdrop-filter:blur(10px);position:sticky;top:0;z-index:20}
        .brand{display:flex;align-items:center;gap:14px}
        .logo{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--gold),var(--amber));display:grid;place-items:center;color:#130d00;font-size:24px;box-shadow:0 0 30px rgba(255,140,0,.25)}
        .brand h1{margin:0;font-size:18px;line-height:1.1;font-weight:900;letter-spacing:.4px;background:linear-gradient(135deg,var(--gold),var(--amber),var(--fire));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .brand p{margin:3px 0 0;color:var(--muted);font:12px/1.2 system-ui,sans-serif}
        .top-actions{display:flex;gap:10px;flex-wrap:wrap}
        .segmented,.tabs{display:flex;gap:8px;flex-wrap:wrap}
        .segmented button,.tabs button,.btn{border:1px solid var(--border);background:rgba(255,215,0,.05);color:var(--text);border-radius:999px;padding:10px 16px;font:700 12px/1 system-ui,sans-serif;cursor:pointer;transition:.18s}
        .segmented button.active,.tabs button.active{background:linear-gradient(135deg,var(--gold),var(--amber));color:#130d00;border-color:transparent}
        .btn.primary{background:linear-gradient(135deg,var(--gold),var(--amber));color:#130d00;border:none}
        .btn.ghost{background:rgba(255,255,255,.02)}
        .hero{display:grid;grid-template-columns:1.3fr .7fr;gap:18px;margin:22px 0}
        .card{background:var(--panel-soft);border:1px solid var(--border);border-radius:20px;padding:20px 22px;box-shadow:0 12px 50px rgba(0,0,0,.22)}
        .hero-copy h2{margin:0 0 10px;font-size:34px;line-height:1;font-weight:900}
        .hero-copy p{margin:0;color:var(--muted);font:15px/1.7 system-ui,sans-serif;max-width:72ch}
        .hero-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .stat{background:rgba(255,255,255,.02);border:1px solid rgba(255,215,0,.08);border-radius:16px;padding:16px}
        .stat .k{font:700 11px/1 system-ui,sans-serif;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
        .stat .v{margin-top:8px;font-size:26px;font-weight:900;color:var(--gold)}
        .stat .s{margin-top:4px;font:12px/1.4 system-ui,sans-serif;color:#7f775d}
        .panel{display:none}
        .panel.active{display:block}
        .grid{display:grid;gap:14px}
        .grid.kpis{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:18px}
        .mini{display:flex;gap:14px;align-items:center}
        .mini .icon{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:22px;flex:0 0 auto}
        .mini .meta{min-width:0}
        .mini .meta .l{font:700 11px/1 system-ui,sans-serif;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
        .mini .meta .n{margin-top:6px;font-size:24px;font-weight:900}
        .warning{display:flex;gap:12px;background:rgba(255,140,0,.08);border:1px solid rgba(255,140,0,.22)}
        .section-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:18px 0 12px}
        .section-title h3{margin:0;font-size:16px;font-weight:900}
        .list{display:grid;gap:10px}
        .item{background:rgba(255,255,255,.02);border:1px solid rgba(255,215,0,.08);border-radius:16px;padding:16px}
        .item-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
        .item-title{font-weight:800;font-size:14px}
        .sub{font:12px/1.5 system-ui,sans-serif;color:var(--muted)}
        .money{font-weight:900;color:var(--gold)}
        .badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;border:1px solid transparent;font:700 11px/1 system-ui,sans-serif;letter-spacing:.2px}
        .badge.sold{color:var(--gold);background:rgba(255,215,0,.12);border-color:rgba(255,215,0,.25)}
        .badge.pending{color:var(--amber);background:rgba(255,140,0,.12);border-color:rgba(255,140,0,.25)}
        .badge.no_sale{color:var(--danger);background:rgba(239,83,80,.12);border-color:rgba(239,83,80,.25)}
        .badge.active{color:var(--success);background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.25)}
        .badge.suspended{color:var(--danger);background:rgba(239,83,80,.12);border-color:rgba(239,83,80,.25)}
        .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
        .actions .btn{padding:8px 12px;border-radius:12px}
        .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
        .product-card{position:relative;overflow:hidden}
        .emoji{font-size:42px;line-height:1}
        .trend{position:absolute;top:14px;right:14px;background:var(--amber);color:#150b00;padding:4px 8px;border-radius:8px;font:800 10px/1 system-ui,sans-serif}
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .pill-row{display:flex;gap:8px;overflow:auto;padding-bottom:4px}
        .pill-row button{white-space:nowrap}
        .code{display:inline-block;background:rgba(255,215,0,.06);border-radius:8px;padding:6px 8px;font:12px/1.4 ui-monospace,SFMono-Regular,monospace;color:var(--amber);word-break:break-all}
        .toast{position:fixed;right:20px;top:20px;z-index:60;padding:12px 16px;border-radius:14px;font:700 13px/1.4 system-ui,sans-serif;color:#140b00;background:var(--gold);box-shadow:0 10px 40px rgba(0,0,0,.35)}
        .splash,.home{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden}
        .splash{position:fixed;inset:0;background:#0a0800;z-index:120;flex-direction:column}
        .home-card{width:min(440px,100%);position:relative;z-index:2}
        .home-role{width:100%;display:flex;align-items:center;gap:14px;text-align:left;background:rgba(255,215,0,.02);border:1px solid rgba(255,215,0,.12);border-radius:18px;padding:18px 20px;color:var(--text);cursor:pointer;margin-bottom:12px}
        .home-role:hover{background:rgba(255,140,0,.08);border-color:rgba(255,140,0,.24)}
        .home-role .r-icon{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;font-size:22px;flex:0 0 auto}
        .back-btn{position:fixed;top:12px;left:12px;z-index:40}
        .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.82);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:16px;z-index:80}
        .modal-backdrop.active{display:flex}
        .modal{width:min(520px,100%);max-height:90vh;overflow:auto;background:#110d00;border:1px solid rgba(255,215,0,.18);border-radius:22px;padding:24px}
        .modal header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
        .modal h3{margin:0;font-size:18px;font-weight:900}
        .close{background:none;border:none;color:#8a8165;font-size:24px;cursor:pointer}
        .field{margin-bottom:14px}
        .field label{display:block;margin-bottom:6px;color:var(--muted);font:700 11px/1 system-ui,sans-serif;letter-spacing:1px;text-transform:uppercase}
        .input,.select,.textarea{width:100%;border:1px solid rgba(255,215,0,.15);background:rgba(255,255,255,.05);border-radius:12px;padding:11px 13px;color:#fff;font:14px/1.4 system-ui,sans-serif;outline:none}
        .textarea{min-height:88px;resize:vertical}
        .footer-actions{display:flex;gap:10px}
        .hidden{display:none!important}
        @media (max-width:960px){
            .hero,.two-col{grid-template-columns:1fr}
        }
        @media (max-width:640px){
            .topbar{padding:14px}
            .hero-copy h2{font-size:26px}
            .wrap{padding:14px 12px 34px}
            .card{padding:16px}
            .footer-actions{flex-direction:column}
        }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/menu_master.php'; ?>
<div id="toast" class="toast hidden"></div>

<div id="mainApp" class="hidden">
<div class="topbar">
    <div class="brand">
        <div class="logo"><i class="fa-solid fa-genie-lamp"></i></div>
        <div>
            <h1>La Lámpara del Genio</h1>
            <p>Red de afiliados integrada al ERP</p>
        </div>
    </div>
    <div class="segmented" id="roleSwitcher">
        <button class="active" data-role="dueno">🏪 Dueño</button>
        <button data-role="gestor">🪔 Genio</button>
        <button data-role="admin">🛡️ Admin</button>
    </div>
</div>

<div class="wrap">
    <section class="hero">
        <div class="card hero-copy">
            <h2>Marketplace afiliado con trazabilidad, comisiones y control anti fraude.</h2>
            <p>Este módulo monta una red de dueños, genios afiliados y supervisión administrativa dentro del ERP. Puedes publicar productos comisionables, generar links de traza, revisar leads, bloquear saldo como garantía y vigilar tasas de conversión por usuario.</p>
        </div>
        <div class="hero-stats">
            <div class="stat"><div class="k">Volumen total</div><div class="v" id="heroTotalVolume">1,847,500 CUP</div><div class="s">Red activa</div></div>
            <div class="stat"><div class="k">Revenue plataforma</div><div class="v" id="heroRevenue">92,375 CUP</div><div class="s">LAG</div></div>
            <div class="stat"><div class="k">Dueños</div><div class="v">24</div><div class="s">Con catálogo activo</div></div>
            <div class="stat"><div class="k">Genios</div><div class="v">87</div><div class="s">Afiliados publicados</div></div>
        </div>
    </section>

    <section id="panel-dueno" class="panel active"></section>
    <section id="panel-gestor" class="panel"></section>
    <section id="panel-admin" class="panel"></section>
</div>
</div>

<div id="homeScreen" class="home hidden">
    <div style="position:absolute;top:30%;left:50%;transform:translate(-50%,-50%);width:520px;height:520px;border-radius:50%;background:radial-gradient(circle,rgba(255,140,0,.08) 0%,transparent 68%);pointer-events:none"></div>
    <div class="home-card">
        <div style="text-align:center;margin-bottom:34px">
            <div class="logo" style="margin:0 auto 16px"><i class="fa-solid fa-genie-lamp"></i></div>
            <h1 style="margin:0;font-size:30px;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--amber),var(--fire));-webkit-background-clip:text;-webkit-text-fill-color:transparent">La Lámpara del Genio</h1>
            <div class="sub" style="margin-top:8px;letter-spacing:2px;text-transform:uppercase">Red de Afiliados Cuba</div>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px">
                <span class="badge sold">Privado</span>
                <span class="badge pending">Comisiones</span>
                <span class="badge active">Seguro</span>
                <span class="badge no_sale">PWA</span>
            </div>
        </div>
        <div class="sub" style="text-align:center;margin-bottom:14px;letter-spacing:2px;text-transform:uppercase">Acceder como</div>
        <button class="home-role" onclick="openRole('dueno')"><div class="r-icon" style="background:rgba(255,140,0,.18)">🏪</div><div style="flex:1"><div class="item-title">Dueño / Tienda</div><div class="sub">Publica productos y gestiona tu wallet</div></div><div style="color:var(--amber);font-size:20px">→</div></button>
        <button class="home-role" onclick="openRole('gestor')"><div class="r-icon" style="background:rgba(255,215,0,.18)">✨</div><div style="flex:1"><div class="item-title">Genio / Afiliado</div><div class="sub">Genera links y cobra tus deseos cumplidos</div></div><div style="color:var(--gold);font-size:20px">→</div></button>
        <button class="home-role" onclick="openRole('admin')"><div class="r-icon" style="background:rgba(255,69,0,.18)">🔮</div><div style="flex:1"><div class="item-title">Oráculo / Admin</div><div class="sub">Panel de control y auditoría</div></div><div style="color:var(--fire);font-size:20px">→</div></button>
        <div class="sub" style="text-align:center;margin-top:24px">🪔 LAG v1.0 · PWA · Móvil + escritorio</div>
    </div>
</div>

<div id="splashScreen" class="splash">
    <div style="position:absolute;width:320px;height:320px;border-radius:50%;background:radial-gradient(circle,rgba(255,180,0,.15) 0%,transparent 70%)"></div>
    <div class="logo" style="width:120px;height:120px;border-radius:36px;font-size:52px;position:relative;z-index:2"><i class="fa-solid fa-genie-lamp"></i></div>
    <h1 style="margin:22px 0 0;font-size:30px;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent;position:relative;z-index:2">La Lámpara del Genio</h1>
    <div class="sub" style="margin-top:8px;letter-spacing:2px;text-transform:uppercase;position:relative;z-index:2">Red de Afiliados Cuba</div>
    <div class="sub" style="margin-top:26px;position:relative;z-index:2">✨ Frota la lámpara. Cobra tu deseo. ✨</div>
</div>

<div id="productModalWrap" class="modal-backdrop"></div>
<div id="linkModalWrap" class="modal-backdrop"></div>

<script>
const MOCK_PRODUCTS = [
  { id: "P001", name: "iPhone 13 Pro 256GB", category: "Tecnología", price: 85000, stock: 3, commission: 3000, commissionPct: 3.5, image: "📱", brand: "Apple", description: "Cámara triple 12MP, chip A15 Bionic, pantalla ProMotion 120Hz. Excelente estado.", clicks: 142, leads: 28, sales: 9, trending: true },
  { id: "P002", name: "Samsung Galaxy S22", category: "Tecnología", price: 62000, stock: 5, commission: 2200, commissionPct: 3.5, image: "📲", brand: "Samsung", description: "6.1 pulgadas AMOLED, 128GB, 8GB RAM. Color negro. Desbloqueado.", clicks: 98, leads: 19, sales: 6, trending: false },
  { id: "P003", name: "Nevera LG 12 pies", category: "Electrodomésticos", price: 120000, stock: 2, commission: 6000, commissionPct: 5, image: "🧊", brand: "LG", description: "No frost, dispensador de agua, eficiencia energética A++. Entrega incluida.", clicks: 201, leads: 41, sales: 15, trending: true },
  { id: "P004", name: "Aire Acondicionado 12000 BTU", category: "Electrodomésticos", price: 95000, stock: 4, commission: 4750, commissionPct: 5, image: "❄️", brand: "Midea", description: "Inverter, bajo consumo, control remoto. Incluye instalación.", clicks: 315, leads: 67, sales: 22, trending: true },
  { id: "P005", name: "Moto G82 5G", category: "Tecnología", price: 45000, stock: 8, commission: 1800, commissionPct: 4, image: "📳", brand: "Motorola", description: "Pantalla pOLED 6.6, 128GB, NFC, batería 5000mAh.", clicks: 77, leads: 14, sales: 4, trending: false },
  { id: "P006", name: "Sofá 3 Plazas", category: "Muebles", price: 38000, stock: 1, commission: 1900, commissionPct: 5, image: "🛋️", brand: "Local", description: "Tela microfibra gris, estructura de madera maciza. Excelente calidad.", clicks: 55, leads: 9, sales: 2, trending: false },
  { id: "P007", name: "Laptop ASUS VivoBook 15", category: "Tecnología", price: 73000, stock: 3, commission: 2920, commissionPct: 4, image: "💻", brand: "ASUS", description: "Intel Core i5, 8GB RAM, SSD 512GB, pantalla Full HD. Windows 11.", clicks: 189, leads: 38, sales: 11, trending: true },
  { id: "P008", name: "Bicicleta Eléctrica", category: "Transporte", price: 55000, stock: 2, commission: 2750, commissionPct: 5, image: "🚲", brand: "Generic", description: "Batería 48V, autonomía 60km, velocidad máx 35km/h. Con cargador.", clicks: 134, leads: 26, sales: 8, trending: false },
];
const MOCK_LEADS = [
  { id: "L001", product: "Nevera LG 12 pies", productId: "P003", gestorId: "G001", client: "+53 5xxx-1234", date: "2025-06-01", status: "sold", commission: 6000, traceCode: "#LG-8821" },
  { id: "L002", product: "iPhone 13 Pro 256GB", productId: "P001", gestorId: "G002", client: "+53 5xxx-5678", date: "2025-06-02", status: "pending", commission: 3000, traceCode: "#LG-8835" },
  { id: "L003", product: "Aire Acondicionado 12000 BTU", productId: "P004", gestorId: "G001", client: "+53 5xxx-9012", date: "2025-06-03", status: "sold", commission: 4750, traceCode: "#LG-8847" },
  { id: "L004", product: "Moto G82 5G", productId: "P005", gestorId: "G003", client: "+53 5xxx-3456", date: "2025-06-04", status: "no_sale", commission: 1800, traceCode: "#LG-8861" },
  { id: "L005", product: "Samsung Galaxy S22", productId: "P002", gestorId: "G001", client: "+53 5xxx-7890", date: "2025-06-05", status: "pending", commission: 2200, traceCode: "#LG-8872" },
];
const MOCK_GESTORES = [
  { id: "G001", name: "Carlos Méndez", earnings: 48750, links: 23, conversions: 15, rating: 4.9 },
  { id: "G002", name: "Lisandra Pérez", earnings: 32100, links: 18, conversions: 9, rating: 4.7 },
  { id: "G003", name: "Yordanis Cruz", earnings: 19800, links: 11, conversions: 5, rating: 4.3 },
];

const state = {
  role: null,
  ownerTab: 'dashboard',
  gestorTab: 'marketplace',
  adminTab: 'dashboard',
  wallet: { available: 47500, blocked: 12750, total: 60250 },
  products: [...MOCK_PRODUCTS],
  leads: [...MOCK_LEADS],
  gestores: [...MOCK_GESTORES],
  generatedLink: null,
  ownerNewProduct: { name: "", category: "Tecnología", price: "", stock: "", commission: "", description: "" },
  gestorFilter: { category: 'Todos', sort: 'trending' }
};

const fmt = new Intl.NumberFormat('es-CU');
const formatCUP = (n) => `${fmt.format(Number(n || 0))} CUP`;
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

function toast(msg, type='info'){
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.classList.remove('hidden');
  el.style.background = type === 'error' ? '#ef5350' : (type === 'success' ? '#ffd700' : '#ff8c00');
  el.style.color = '#140b00';
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.add('hidden'), 3000);
}

function badge(status){
  const cls = ['sold','pending','no_sale','active','suspended'].includes(status) ? status : 'pending';
  const map = { sold:'Vendido', pending:'Pendiente', no_sale:'No concretado', active:'Activo', suspended:'Suspendido' };
  return `<span class="badge ${cls}">${map[cls]}</span>`;
}

function renderRoleSwitcher(){
  document.querySelectorAll('#roleSwitcher button').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.role === state.role);
  });
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  if (state.role) document.getElementById(`panel-${state.role}`).classList.add('active');
}

function renderOwner(){
  const root = document.getElementById('panel-dueno');
  const sold = state.leads.filter(l => l.status === 'sold').length;
  const pending = state.leads.filter(l => l.status === 'pending').length;
  const tabs = [
    ['dashboard','📊 Dashboard'],
    ['inventario','📦 Inventario'],
    ['leads','📋 Leads'],
    ['wallet','💳 Wallet']
  ];
  let body = '';
  if (state.ownerTab === 'dashboard') {
    body = `
      <div class="grid kpis">
        ${kpi('💳','Saldo disponible',formatCUP(state.wallet.available),'Tus productos siguen visibles','#ffd700')}
        ${kpi('🔒','Saldo bloqueado',formatCUP(state.wallet.blocked),'Garantías pendientes','#ff8c00')}
        ${kpi('📦','Productos activos',state.products.length,'Catálogo afiliado','#ff4500')}
        ${kpi('✅','Ventas este mes',sold,'Conversión reportada','#ffd700')}
      </div>
      <div class="card warning">
        <div style="font-size:24px">⚠️</div>
        <div><div style="font-weight:800;color:var(--amber);margin-bottom:6px">Tasa de conversión monitorizada</div><div class="sub">Tienes <b style="color:#fff">${pending} leads pendientes</b>. Si recibes muchos contactos y reportas 0 ventas, el sistema puede marcar tu cuenta como <b style="color:var(--fire)">Fraude Estadístico</b>.</div></div>
      </div>
      <div class="section-title"><h3>Leads recientes</h3></div>
      <div class="list">
        ${state.leads.slice(0,3).map(lead => `
          <div class="item">
            <div class="item-head">
              <div><div class="item-title">${esc(lead.product)}</div><div class="sub">${esc(lead.traceCode)} · ${esc(lead.date)}</div></div>
              <div style="display:flex;gap:8px;align-items:center">${badge(lead.status)}</div>
            </div>
            ${lead.status === 'pending' ? `<div class="actions">
              <button class="btn primary" onclick="setLeadStatus('${lead.id}','sold')">✓ Vendido</button>
              <button class="btn ghost" onclick="setLeadStatus('${lead.id}','no_sale')">✗ No vendido</button>
            </div>` : ''}
          </div>`).join('')}
      </div>`;
  } else if (state.ownerTab === 'inventario') {
    body = `
      <div class="section-title"><h3>Mis productos (${state.products.length})</h3><button class="btn primary" onclick="openProductModal()">+ Nuevo producto</button></div>
      <div class="cards">${state.products.map(p => `
        <div class="card product-card">
          <div class="emoji">${p.image}</div>
          <div class="item-title" style="margin-top:10px">${esc(p.name)}</div>
          <div class="sub">${esc(p.category)} · Stock: ${p.stock}</div>
          <div class="money" style="margin-top:8px">${formatCUP(p.price)}</div>
          <div class="two-col" style="margin-top:14px">
            <div><div class="sub">Comisión gestor</div><div class="money">${formatCUP(p.commission)}</div><div class="sub">${p.commissionPct}%</div></div>
            <div><div class="sub">Clics · Leads · Ventas</div><div style="font-weight:800;color:var(--fire)">${p.clicks} · ${p.leads} · ${p.sales}</div></div>
          </div>
        </div>`).join('')}</div>`;
  } else if (state.ownerTab === 'leads') {
    body = `<div class="section-title"><h3>Todos los leads (${state.leads.length})</h3></div><div class="list">
      ${state.leads.map(lead => `
        <div class="item">
          <div class="item-head">
            <div>
              <div class="item-title">${esc(lead.product)}</div>
              <div class="sub">Código: <b style="color:var(--amber)">${esc(lead.traceCode)}</b> · ${esc(lead.date)}</div>
              <div class="sub">Cliente: ${esc(lead.client)}</div>
            </div>
            <div style="text-align:right">
              <div class="sub">Comisión en juego</div>
              <div class="money">${formatCUP(lead.commission)}</div>
              <div style="margin-top:6px">${badge(lead.status)}</div>
            </div>
          </div>
          ${lead.status === 'pending' ? `<div class="actions">
            <button class="btn primary" onclick="setLeadStatus('${lead.id}','sold')">✓ Marcar como vendido</button>
            <button class="btn ghost" onclick="setLeadStatus('${lead.id}','no_sale')">✗ No se concretó</button>
          </div>` : ''}
        </div>`).join('')}
    </div>`;
  } else {
    body = `
      <div class="card" style="background:linear-gradient(135deg,rgba(255,215,0,.08),rgba(255,69,0,.06));text-align:center;margin-bottom:16px">
        <div class="logo" style="margin:0 auto 10px"><i class="fa-solid fa-wallet"></i></div>
        <div class="sub">Saldo total en tu lámpara</div>
        <div style="font-size:34px;font-weight:900;background:linear-gradient(135deg,var(--gold),var(--amber));-webkit-background-clip:text;-webkit-text-fill-color:transparent">${formatCUP(state.wallet.total)}</div>
      </div>
      <div class="two-col" style="margin-bottom:20px">
        <div class="card"><div class="sub">Disponible</div><div class="money" style="font-size:24px">${formatCUP(state.wallet.available)}</div><div class="sub">Tus productos están visibles</div></div>
        <div class="card"><div class="sub">Bloqueado (garantía)</div><div style="font-size:24px;font-weight:900;color:var(--amber)">${formatCUP(state.wallet.blocked)}</div><div class="sub">Pendiente de confirmación</div></div>
      </div>
      <div class="section-title"><h3>Recargar saldo</h3></div>
      <div class="two-col">
        <button class="card btn" style="padding:20px;color:var(--gold)" onclick="toast('Recarga por Transfermóvil simulada','success')"><div style="font-size:26px">📲</div><div style="margin-top:6px;font-weight:800">Transfermóvil</div></button>
        <button class="card btn" style="padding:20px;color:var(--gold)" onclick="toast('Recarga por EnZona simulada','success')"><div style="font-size:26px">💰</div><div style="margin-top:6px;font-weight:800">EnZona</div></button>
      </div>
      <div class="card" style="margin-top:14px;background:rgba(255,69,0,.05);border-color:rgba(255,69,0,.2)"><div style="color:var(--fire);font-weight:800;margin-bottom:6px">🪔 Importante</div><div class="sub">Si tu saldo llega a 0 CUP, la llama de tu lámpara se apagará y tus productos desaparecerán del catálogo hasta que recargues.</div></div>`;
  }
  root.innerHTML = `${panelHeader('🏪 Panel del Dueño · ElectroHavana · D-0042', formatCUP(state.wallet.available), 'Saldo disponible')}${tabRow('owner', tabs, state.ownerTab)}${body}`;
}

function renderGestor(){
  const root = document.getElementById('panel-gestor');
  const cats = ['Todos','Tecnología','Electrodomésticos','Muebles','Transporte'];
  const tabs = [['marketplace','🛍️ Marketplace'],['mis_links','🔗 Mis Links'],['ganancias','✨ Ganancias']];
  const myLinks = [
    { product: "Nevera LG 12 pies", link: "lamparagenio.cu/ref/P003?g=G001", clicks: 41, leads: 8, earned: 10750 },
    { product: "Aire Acondicionado 12000 BTU", link: "lamparagenio.cu/ref/P004?g=G001", clicks: 67, leads: 15, earned: 22000 },
    { product: "iPhone 13 Pro 256GB", link: "lamparagenio.cu/ref/P001?g=G001", clicks: 28, leads: 6, earned: 9000 }
  ];
  const products = [...state.products]
    .filter(p => state.gestorFilter.category === 'Todos' || p.category === state.gestorFilter.category)
    .sort((a,b) => state.gestorFilter.sort === 'commission' ? b.commission - a.commission : state.gestorFilter.sort === 'clicks' ? b.clicks - a.clicks : Number(b.trending) - Number(a.trending));
  let body = '';
  if (state.gestorTab === 'marketplace') {
    body = `
      <div class="pill-row" style="margin-bottom:10px">${cats.map(c => `<button class="${state.gestorFilter.category===c?'active':''}" onclick="setGestorCategory('${c.replace(/'/g,"\\'")}')">${esc(c)}</button>`).join('')}</div>
      <div class="pill-row" style="margin-bottom:18px">
        <button class="${state.gestorFilter.sort==='trending'?'active':''}" onclick="setGestorSort('trending')">🔥 Tendencia</button>
        <button class="${state.gestorFilter.sort==='commission'?'active':''}" onclick="setGestorSort('commission')">✨ Mayor comisión</button>
        <button class="${state.gestorFilter.sort==='clicks'?'active':''}" onclick="setGestorSort('clicks')">👁️ Más visto</button>
      </div>
      <div class="cards">${products.map(p => `
        <div class="card product-card">
          ${p.trending ? '<div class="trend">🔥 TREND</div>' : ''}
          <div class="emoji">${p.image}</div>
          <div class="item-title" style="margin:10px 0 4px">${esc(p.name)}</div>
          <div class="sub">${esc(p.category)} · ${esc(p.brand)}</div>
          <div style="margin-top:8px;font-size:15px;font-weight:900;color:var(--amber)">${formatCUP(p.price)}</div>
          <div class="two-col" style="margin-top:14px">
            <div><div class="sub">Tu comisión</div><div class="money">${formatCUP(p.commission * 0.8)}</div></div>
            <div><div class="sub">Conversión</div><div style="font-weight:800;color:var(--fire)">${((p.sales / Math.max(p.leads,1))*100).toFixed(0)}%</div></div>
          </div>
          <div class="sub" style="margin:12px 0">${esc(p.description.substring(0,70))}...</div>
          <button class="btn primary" style="width:100%" onclick="generateLink('${p.id}')">🔗 Generar enlace de traza</button>
        </div>`).join('')}</div>`;
  } else if (state.gestorTab === 'mis_links') {
    body = `<div class="section-title"><h3>Mis enlaces activos</h3></div><div class="list">${myLinks.map(l => `
      <div class="item">
        <div class="item-head">
          <div style="flex:1">
            <div class="item-title">${esc(l.product)}</div>
            <div class="code" style="margin-top:6px">${esc(l.link)}</div>
          </div>
          <div style="display:flex;gap:16px">
            <div style="text-align:center"><div class="sub">Clics</div><div style="font-weight:900">${l.clicks}</div></div>
            <div style="text-align:center"><div class="sub">Leads</div><div style="font-weight:900">${l.leads}</div></div>
            <div style="text-align:center"><div class="sub">Ganado</div><div class="money">${formatCUP(l.earned)}</div></div>
          </div>
        </div>
        <div class="actions">
          <button class="btn ghost" onclick="toast('Link copiado al portapapeles (simulado)','success')">📋 Copiar link</button>
          <button class="btn ghost" onclick="toast('Estadísticas detalladas pendientes de integrar','info')">📊 Estadísticas</button>
          <button class="btn ghost" style="color:#25d366;border-color:rgba(37,211,102,.25)" onclick="toast('Compartir por WhatsApp (simulado)','success')">💬 WhatsApp</button>
        </div>
      </div>`).join('')}</div>`;
  } else {
    body = `
      <div class="grid kpis">
        ${kpi('✨','Este mes',formatCUP(48750),'Comisión acumulada','#ffd700')}
        ${kpi('🔗','Links activos','3','Trazas publicadas','#ff8c00')}
        ${kpi('👆','Total clics','136','Actividad','#ff4500')}
        ${kpi('✅','Ventas cerradas','15','Conversión aprobada','#ffd700')}
      </div>
      <div class="section-title"><h3>Historial de comisiones</h3></div>
      <div class="list">${state.leads.filter(l => l.gestorId === 'G001').map(lead => `
        <div class="item">
          <div class="item-head">
            <div><div class="item-title">${esc(lead.product)}</div><div class="sub">${esc(lead.traceCode)} · ${esc(lead.date)}</div></div>
            <div style="text-align:right"><div style="font-weight:900;color:${lead.status === 'sold' ? 'var(--gold)' : lead.status === 'pending' ? 'var(--amber)' : 'var(--danger)'}">${lead.status === 'sold' ? '+' : lead.status === 'pending' ? '⏳ ' : '—'}${formatCUP(lead.commission * 0.8)}</div>${badge(lead.status)}</div>
          </div>
        </div>`).join('')}</div>`;
  }
  root.innerHTML = `${panelHeader('🪔 Panel del Genio · Carlos Méndez · G-001 · ⭐ 4.9', formatCUP(48750), 'Ganado este mes')}${tabRow('gestor', tabs, state.gestorTab)}${body}`;
}

function renderAdmin(){
  const root = document.getElementById('panel-admin');
  const tabs = [['dashboard','📊 BI Dashboard'],['users','👥 Usuarios'],['transactions','💸 Transacciones'],['audit','🔍 Auditoría']];
  const alerts = [
    { dueno: "D-0078 (Electrónica Sur)", metric: "0 ventas / 47 contactos", risk: "ALTO", color: "var(--danger)" },
    { dueno: "D-0112 (TechStore Oriente)", metric: "1 venta / 38 contactos", risk: "MEDIO", color: "var(--amber)" },
    { dueno: "D-0031 (Tienda Miramar)", metric: "Saldo: 0 CUP", risk: "SALDO AGOTADO", color: "#8a8165" }
  ];
  let body = '';
  if (state.adminTab === 'dashboard') {
    body = `
      <div class="grid kpis">
        ${kpi('💸','Volumen total',formatCUP(1847500),'Red completa','#ffd700')}
        ${kpi('✨','Revenue LAG',formatCUP(92375),'Plataforma','#ff8c00')}
        ${kpi('🏪','Dueños activos','24','Con catálogo','#ff4500')}
        ${kpi('🤝','Genios activos','87','Afiliados','#ffd700')}
        ${kpi('📩','Leads hoy','43','Entradas','#ff8c00')}
        ${kpi('✅','Ventas hoy','11','Cierres','#ffd700')}
      </div>
      <div class="two-col">
        <div class="card"><div class="section-title"><h3>🔥 Top productos por clics</h3></div><div class="list">
          ${[...state.products].sort((a,b) => b.clicks - a.clicks).slice(0,5).map((p,i) => `<div class="item" style="padding:12px 0;border:none;background:none;box-shadow:none"><div class="item-head"><div style="display:flex;gap:10px;align-items:center"><span style="font:900 11px/1 system-ui,sans-serif;color:#8a8165;width:16px">#${i+1}</span><span>${p.image}</span><div><div class="item-title">${esc(p.name.substring(0,22))}...</div><div class="sub">${esc(p.category)}</div></div></div><div style="text-align:right"><div style="font-weight:900;color:var(--amber)">${p.clicks}</div><div class="sub">clics</div></div></div></div>`).join('')}
        </div></div>
        <div class="card"><div class="section-title"><h3>🏆 Top genios</h3></div><div class="list">
          ${state.gestores.map((g,i) => `<div class="item" style="padding:12px 0;border:none;background:none;box-shadow:none"><div class="item-head"><div style="display:flex;gap:10px;align-items:center"><div style="width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:${['#FFD700','#C0C0C0','#CD7F32'][i]};color:#000;font:900 12px/1 system-ui,sans-serif">#${i+1}</div><div><div class="item-title">${esc(g.name)}</div><div class="sub">⭐ ${g.rating} · ${g.conversions} ventas</div></div></div><div class="money">${formatCUP(g.earnings)}</div></div></div>`).join('')}
        </div></div>
      </div>`;
  } else if (state.adminTab === 'users') {
    body = `
      <div class="two-col">
        <div class="card"><div class="section-title"><h3>🏪 Dueños registrados</h3></div><div class="list">
          ${["ElectroHavana (D-0042)", "TechStore Oriente (D-0078)", "Tienda Miramar (D-0031)", "La Habana Electronics (D-0055)"].map((d,i) => `<div class="item"><div class="item-head"><span class="item-title">${esc(d)}</span>${badge(i===2?'suspended':'active')}</div></div>`).join('')}
        </div></div>
        <div class="card"><div class="section-title"><h3>✨ Top genios</h3></div><div class="list">
          ${state.gestores.map(g => `<div class="item"><div class="item-head"><div><div class="item-title">${esc(g.name)}</div><div class="sub">${g.links} links · ⭐ ${g.rating}</div></div><div class="money">${formatCUP(g.earnings)}</div></div></div>`).join('')}
        </div></div>
      </div>`;
  } else if (state.adminTab === 'transactions') {
    body = `<div class="section-title"><h3>Todas las transacciones</h3></div><div class="list">
      ${state.leads.map(lead => `<div class="item"><div class="item-head"><div><div class="item-title">${esc(lead.product)}</div><div class="sub">${esc(lead.traceCode)} · Genio: ${esc(lead.gestorId)} · ${esc(lead.date)}</div></div><div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap"><div style="text-align:right"><div class="sub">Total comisión</div><div style="font-weight:900;color:var(--amber)">${formatCUP(lead.commission)}</div></div><div style="text-align:right"><div class="sub">LAG recibe (20%)</div><div class="money">${formatCUP(lead.commission * 0.2)}</div></div>${badge(lead.status)}</div></div></div>`).join('')}
    </div>`;
  } else {
    body = `<div class="section-title"><h3>Alertas y auditoría</h3></div><div class="list">
      ${alerts.map(a => `<div class="item" style="border-color:${a.color}40;background:${a.color}10"><div class="item-head"><div><div style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><span style="color:${a.color};font-size:18px">${a.color === '#8a8165' ? '💤' : '⚠️'}</span><span class="item-title">${esc(a.dueno)}</span></div><div class="sub">${a.color === '#8a8165' ? 'Saldo agotado' : 'Posible evasión de comisión'}: <b style="color:#fff">${esc(a.metric)}</b></div></div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span class="badge ${a.color === '#8a8165' ? 'pending' : 'no_sale'}">RIESGO ${esc(a.risk)}</span>${a.color !== '#8a8165' ? `<button class="btn" style="background:var(--danger);color:#fff;padding:6px 12px">🔴 Suspender</button>` : ''}<button class="btn ghost" style="color:var(--gold)">🔍 Auditar</button></div></div></div>`).join('')}
      <div class="card" style="background:rgba(255,215,0,.04);border-color:rgba(255,215,0,.12)"><div class="item-title" style="color:var(--gold);margin-bottom:12px">📊 Resumen de integridad</div><div class="grid kpis" style="margin-bottom:0">${[['Tasa de conversión media','31.2%'],['Dueños en vigilancia','2'],['Auditorías activas','1'],['Fraudes detectados','0']].map(([label,val]) => `<div class="stat"><div class="v" style="font-size:22px">${esc(val)}</div><div class="s">${esc(label)}</div></div>`).join('')}</div></div>
    </div>`;
  }
  root.innerHTML = `${panelHeader('🛡️ Panel de Control · Acceso restringido', formatCUP(92375), 'Revenue plataforma')}${tabRow('admin', tabs, state.adminTab)}${body}`;
}

function kpi(icon,label,value,sub,color){
  return `<div class="card mini"><div class="icon" style="background:${color}20;color:${color}">${icon}</div><div class="meta"><div class="l">${label}</div><div class="n" style="color:${color}">${value}</div><div class="s">${sub||''}</div></div></div>`;
}
function panelHeader(title,balance,balanceLabel){
  return `<div class="section-title" style="margin-top:0"><div><h3 style="font-size:18px">${title}</h3></div><div style="text-align:right"><div class="sub">${balanceLabel}</div><div class="money" style="font-size:18px">${balance}</div></div></div>`;
}
function tabRow(kind, tabs, current){
  return `<div class="tabs" style="margin-bottom:18px">${tabs.map(([id,label]) => `<button class="${current===id?'active':''}" onclick="setTab('${kind}','${id}')">${label}</button>`).join('')}</div>`;
}

function setTab(kind,id){
  if (kind === 'owner') state.ownerTab = id;
  if (kind === 'gestor') state.gestorTab = id;
  if (kind === 'admin') state.adminTab = id;
  render();
}
function setLeadStatus(id,status){
  state.leads = state.leads.map(l => l.id === id ? { ...l, status } : l);
  toast(status === 'sold' ? 'Venta confirmada. Comisión procesada.' : 'Saldo desbloqueado.', 'success');
  render();
}
function setGestorCategory(category){ state.gestorFilter.category = category; renderGestor(); }
function setGestorSort(sort){ state.gestorFilter.sort = sort; renderGestor(); }
function openProductModal(){
  document.getElementById('productModalWrap').innerHTML = `
    <div class="modal active"><header><h3>🪔 Publicar nuevo producto</h3><button class="close" onclick="closeModal('productModalWrap')">×</button></header>
      ${field('name','Nombre del producto', state.ownerNewProduct.name)}
      <div class="field"><label>Categoría</label><select class="select" onchange="setNewProductField('category',this.value)">${["Tecnología","Electrodomésticos","Muebles","Transporte","Ropa","Alimentación","Otros"].map(c => `<option value="${esc(c)}" ${state.ownerNewProduct.category===c?'selected':''}>${esc(c)}</option>`).join('')}</select></div>
      ${field('price','Precio (CUP)', state.ownerNewProduct.price, 'number')}
      ${field('stock','Stock disponible', state.ownerNewProduct.stock, 'number')}
      ${field('commission','Comisión por venta (CUP)', state.ownerNewProduct.commission, 'number')}
      <div class="field"><label>Descripción</label><textarea class="textarea" oninput="setNewProductField('description',this.value)">${esc(state.ownerNewProduct.description)}</textarea></div>
      ${state.ownerNewProduct.price && state.ownerNewProduct.commission ? `<div class="card" style="background:rgba(255,215,0,.05);border-color:rgba(255,215,0,.18);margin-bottom:14px"><div class="sub">Comisión equivale al <b style="color:var(--gold)">${((Number(state.ownerNewProduct.commission||0)/Math.max(Number(state.ownerNewProduct.price||1),1))*100).toFixed(1)}%</b> del precio</div></div>`:''}
      <div class="footer-actions"><button class="btn primary" style="width:100%" onclick="saveNewProduct()">✨ Publicar producto</button></div>
    </div>`;
  document.getElementById('productModalWrap').classList.add('active');
}
function field(key,label,value,type='text'){
  return `<div class="field"><label>${label}</label><input class="input" type="${type}" value="${esc(value)}" oninput="setNewProductField('${key}',this.value)"></div>`;
}
function setNewProductField(key,val){ state.ownerNewProduct[key] = val; openProductModal(); }
function saveNewProduct(){
  const p = state.ownerNewProduct;
  if (!String(p.name).trim() || !String(p.price).trim() || !String(p.stock).trim() || !String(p.commission).trim()) {
    toast('Completa nombre, precio, stock y comisión.', 'error');
    return;
  }
  const price = Number(p.price), stock = Number(p.stock), commission = Number(p.commission);
  state.products.push({
    id: 'P' + Date.now(),
    name: p.name.trim(),
    category: p.category || 'Tecnología',
    price, stock, commission,
    commissionPct: Number(((commission / Math.max(price,1)) * 100).toFixed(1)),
    image: '📦', brand: 'Nuevo', description: p.description || '', clicks: 0, leads: 0, sales: 0, trending: false
  });
  state.ownerNewProduct = { name: "", category: "Tecnología", price: "", stock: "", commission: "", description: "" };
  closeModal('productModalWrap');
  toast('Producto publicado en el catálogo.', 'success');
  renderOwner();
}
function generateLink(productId){
  const product = state.products.find(p => p.id === productId);
  if (!product) return;
  state.generatedLink = { product, link: `lamparagenio.cu/ref/${product.id}?g=G001-${Math.random().toString(36).slice(2,8).toUpperCase()}` };
  document.getElementById('linkModalWrap').innerHTML = `
    <div class="modal active"><header><h3>🔗 Enlace del Genio generado</h3><button class="close" onclick="closeModal('linkModalWrap')">×</button></header>
      <div style="text-align:center;margin-bottom:18px"><div style="font-size:50px">${state.generatedLink.product.image}</div><div class="item-title" style="margin-top:8px">${esc(state.generatedLink.product.name)}</div></div>
      <div class="card" style="background:rgba(255,140,0,.08);border-color:rgba(255,140,0,.25);margin-bottom:14px"><div class="sub">Tu enlace único de traza</div><div class="code" style="margin-top:8px">${esc(state.generatedLink.link)}</div></div>
      <div class="two-col" style="margin-bottom:14px">
        <div class="card" style="text-align:center"><div class="sub">Tu comisión (80%)</div><div class="money" style="font-size:20px">${formatCUP(state.generatedLink.product.commission * 0.8)}</div></div>
        <div class="card" style="text-align:center"><div class="sub">Precio al cliente</div><div style="font-size:20px;font-weight:900">${formatCUP(state.generatedLink.product.price)}</div></div>
      </div>
      <div class="footer-actions">
        <button class="btn primary" style="flex:1" onclick="toast('Link copiado (simulado)','success')">📋 Copiar enlace</button>
        <button class="btn ghost" style="flex:1;color:#25d366;border-color:rgba(37,211,102,.25)" onclick="toast('Compartir por WhatsApp (simulado)','success')">💬 WhatsApp</button>
      </div>
    </div>`;
  document.getElementById('linkModalWrap').classList.add('active');
}
function closeModal(id){ document.getElementById(id).classList.remove('active'); document.getElementById(id).innerHTML = ''; }
function openRole(role){
  state.role = role;
  document.getElementById('homeScreen').classList.add('hidden');
  document.getElementById('mainApp').classList.remove('hidden');
  render();
}
function goHome(){
  state.role = null;
  document.getElementById('mainApp').classList.add('hidden');
  document.getElementById('homeScreen').classList.remove('hidden');
  render();
}

function render(){
  renderRoleSwitcher();
  renderOwner();
  renderGestor();
  renderAdmin();
  let back = document.getElementById('affBackBtn');
  if (!back) {
    back = document.createElement('div');
    back.id = 'affBackBtn';
    back.className = 'back-btn hidden';
    back.innerHTML = '<button class="btn ghost" onclick="goHome()">← Inicio</button>';
    document.body.appendChild(back);
  }
  back.classList.toggle('hidden', !state.role);
}

document.querySelectorAll('#roleSwitcher button').forEach(btn => {
  btn.addEventListener('click', () => {
    state.role = btn.dataset.role;
    document.getElementById('homeScreen').classList.add('hidden');
    document.getElementById('mainApp').classList.remove('hidden');
    render();
  });
});

setTimeout(() => {
  document.getElementById('splashScreen').classList.add('hidden');
  document.getElementById('homeScreen').classList.remove('hidden');
}, 2600);
</script>
</body>
</html>
