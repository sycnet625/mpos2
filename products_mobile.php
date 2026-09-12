<?php
// Inventario móvil — interfaz compacta para teléfonos y tablets.
// Comparte las operaciones y validaciones del módulo products_table.php.
declare(strict_types=1);

ini_set('display_errors', '0');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: /login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/products_mobile.php'));
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
session_write_close();

$mobileEmpresa = (int)($config['id_empresa'] ?? 1);
$mobileSucursal = (int)($_SESSION['id_sucursal'] ?? $config['id_sucursal'] ?? 1);
$mobileAlmacen = (int)($_SESSION['id_almacen'] ?? $config['id_almacen'] ?? 1);
$mobileBrand = trim((string)($config['marca_empresa_nombre'] ?? $config['tienda_nombre'] ?? 'PalWeb POS')) ?: 'PalWeb POS';

// Carga ligera para móvil: evita ejecutar el render completo de products_table.php.
if (isset($_GET['mobile_data'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $productColumns = array_map('strval', $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN));
        $barcode1Sql = in_array('codigo_barra_1', $productColumns, true) ? 'p.codigo_barra_1' : "''";
        $barcode2Sql = in_array('codigo_barra_2', $productColumns, true) ? 'p.codigo_barra_2' : "''";
        $reservableSql = in_array('es_reservable', $productColumns, true) ? 'p.es_reservable' : '0';
        $warehouseColumns = array_map('strval', $pdo->query("SHOW COLUMNS FROM almacenes")->fetchAll(PDO::FETCH_COLUMN));
        $warehouseEmpresaFilter = in_array('id_empresa', $warehouseColumns, true) ? 'id_empresa = ? AND ' : '';
        $warehouseParams = in_array('id_empresa', $warehouseColumns, true) ? [$mobileEmpresa, $mobileSucursal] : [$mobileSucursal];
        $stmtAlm = $pdo->prepare("SELECT id FROM almacenes WHERE {$warehouseEmpresaFilter} id_sucursal = ? AND COALESCE(activo, 1) = 1 ORDER BY id ASC");
        $stmtAlm->execute($warehouseParams);
        $mobileAlmIds = array_values(array_filter(array_map('intval', $stmtAlm->fetchAll(PDO::FETCH_COLUMN)), static fn(int $id): bool => $id > 0));
        if (!$mobileAlmIds) $mobileAlmIds = [$mobileAlmacen];
        $almPlaceholders = implode(',', array_fill(0, count($mobileAlmIds), '?'));
        $stockColumns = array_map('strval', $pdo->query("SHOW COLUMNS FROM stock_almacen")->fetchAll(PDO::FETCH_COLUMN));
        $stockSucursalFilter = in_array('id_sucursal', $stockColumns, true) ? ' AND sa.id_sucursal = ?' : '';
        $stockSucursalFilterExists = in_array('id_sucursal', $stockColumns, true) ? ' AND sx.id_sucursal = ?' : '';
        $sql = "SELECT p.codigo, p.nombre, p.categoria, p.precio, p.costo, p.precio_mayorista,
                       p.stock_minimo, p.unidad_medida, p.activo, p.es_web, p.es_pos,
                       {$reservableSql} AS es_reservable,
                       {$barcode1Sql} AS codigo_barra_1, {$barcode2Sql} AS codigo_barra_2,
                       (SELECT COALESCE(SUM(sa.cantidad), 0) FROM stock_almacen sa
                        WHERE sa.id_producto = p.codigo{$stockSucursalFilter}
                          AND sa.id_almacen IN ($almPlaceholders)) AS stock_total
                FROM productos p
                WHERE p.id_empresa = ?
                  AND (COALESCE(p.id_sucursal_origen, 0) = ? OR EXISTS (
                      SELECT 1 FROM stock_almacen sx
                      WHERE sx.id_producto = p.codigo{$stockSucursalFilterExists}
                        AND sx.id_almacen IN ($almPlaceholders)
                  ))
                ORDER BY p.nombre ASC";
        $params = [];
        if ($stockSucursalFilter !== '') $params[] = $mobileSucursal;
        $params = array_merge($params, $mobileAlmIds, [$mobileEmpresa, $mobileSucursal]);
        if ($stockSucursalFilterExists !== '') $params[] = $mobileSucursal;
        $params = array_merge($params, $mobileAlmIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('products_mobile data: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo cargar el inventario móvil'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#080b12">
    <title>Inventario móvil | <?= htmlspecialchars($mobileBrand, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --m-bg: #080b12;
            --m-panel: #101621;
            --m-panel-2: #151d2b;
            --m-line: rgba(255,255,255,.09);
            --m-text: #f8fafc;
            --m-muted: #94a3b8;
            --m-accent: #62e6c5;
            --m-accent-2: #8b7cff;
            --m-danger: #fb7185;
            --m-warning: #fbbf24;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; background: var(--m-bg); }
        body { margin: 0; color: var(--m-text); font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding-bottom: env(safe-area-inset-bottom); }
        .mobile-shell { width: min(100%, 920px); margin: auto; padding: max(14px, env(safe-area-inset-top)) 14px 28px; }
        .mobile-topbar { position: sticky; top: 0; z-index: 20; margin: -14px -14px 16px; padding: 14px; background: rgba(8,11,18,.92); backdrop-filter: blur(18px); border-bottom: 1px solid var(--m-line); }
        .brand-title { font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; }
        .context-pills { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .context-pill { color: var(--m-muted); border: 1px solid var(--m-line); border-radius: 999px; padding: 4px 9px; font-size: .7rem; }
        .hero { background: linear-gradient(135deg, #172c38, #17142c 72%); border: 1px solid rgba(98,230,197,.18); border-radius: 22px; padding: 18px; box-shadow: 0 18px 45px rgba(0,0,0,.28); }
        .hero h1 { font-size: clamp(1.45rem, 6vw, 2.2rem); margin: 0; font-weight: 850; letter-spacing: -.05em; }
        .hero p { color: var(--m-muted); margin: 7px 0 0; font-size: .86rem; }
        .hero-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 16px; }
        .btn-mobile { min-height: 44px; border-radius: 13px; font-weight: 750; font-size: .82rem; border: 1px solid var(--m-line); }
        .btn-accent { background: var(--m-accent); color: #071412; border-color: var(--m-accent); }
        .btn-dark-soft { background: rgba(255,255,255,.06); color: var(--m-text); }
        .search-wrap { position: relative; margin: 14px 0; }
        .search-wrap i { position: absolute; left: 15px; top: 14px; color: var(--m-muted); }
        #mobileSearch { width: 100%; min-height: 50px; color: var(--m-text); background: var(--m-panel); border: 1px solid var(--m-line); border-radius: 16px; padding: 0 44px; font-size: 1rem; outline: none; }
        #mobileSearch:focus { border-color: var(--m-accent); box-shadow: 0 0 0 3px rgba(98,230,197,.12); }
        .toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
        .toolbar select { min-height: 40px; flex: 1; color: var(--m-text); background: var(--m-panel); border-color: var(--m-line); border-radius: 12px; }
        .result-count { color: var(--m-muted); font-size: .75rem; white-space: nowrap; }
        .product-card { display: grid; grid-template-columns: 78px minmax(0,1fr) auto; gap: 12px; align-items: center; background: linear-gradient(145deg, var(--m-panel-2), var(--m-panel)); border: 1px solid var(--m-line); border-radius: 18px; padding: 10px; margin-bottom: 10px; box-shadow: 0 10px 25px rgba(0,0,0,.18); }
        .product-img { width: 78px; height: 78px; border-radius: 14px; object-fit: cover; background: #202938; }
        .product-name { font-weight: 800; font-size: .93rem; line-height: 1.2; margin-bottom: 4px; overflow-wrap: anywhere; }
        .product-meta { color: var(--m-muted); font-size: .72rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .product-price { color: var(--m-accent); font-size: 1rem; font-weight: 850; margin-top: 7px; }
        .stock-badge { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 4px 8px; font-size: .7rem; font-weight: 800; margin-top: 7px; }
        .stock-ok { color: #86efac; background: rgba(34,197,94,.14); }
        .stock-low { color: var(--m-warning); background: rgba(251,191,36,.14); }
        .stock-zero { color: #fda4af; background: rgba(251,113,133,.14); }
        .card-actions { display: flex; flex-direction: column; gap: 6px; }
        .icon-btn { width: 39px; height: 39px; border: 1px solid var(--m-line); border-radius: 12px; color: var(--m-text); background: rgba(255,255,255,.06); }
        .icon-btn:active, .btn-mobile:active { transform: scale(.97); }
        .switch-line { display: flex; gap: 8px; margin-top: 7px; color: var(--m-muted); font-size: .68rem; }
        .switch-line input { accent-color: var(--m-accent); width: 17px; height: 17px; }
        .empty-state { color: var(--m-muted); text-align: center; padding: 48px 18px; border: 1px dashed var(--m-line); border-radius: 18px; }
        .loading { text-align: center; padding: 38px; color: var(--m-muted); }
        .modal-content { color: #172033; border: 0; border-radius: 18px; overflow: hidden; }
        .modal-body { max-height: 78vh; overflow-y: auto; }
        .modal-dialog { margin: 10px; }
        .toast-container { z-index: 2050; }
        @media (min-width: 600px) { .hero-actions { grid-template-columns: repeat(4, minmax(0, 1fr)); } .product-card { grid-template-columns: 90px minmax(0,1fr) auto; padding: 13px; } .product-img { width: 90px; height: 90px; } }
        @media (max-width: 360px) { .product-card { grid-template-columns: 64px minmax(0,1fr) auto; gap: 8px; } .product-img { width: 64px; height: 64px; } .product-name { font-size: .84rem; } .icon-btn { width: 36px; height: 36px; } }
    </style>
</head>
<body>
<main class="mobile-shell">
    <header class="mobile-topbar d-flex justify-content-between align-items-start">
        <div>
            <div class="brand-title"><i class="fas fa-boxes-stacked me-2" style="color:var(--m-accent)"></i>Inventario móvil</div>
            <div class="context-pills"><span class="context-pill">Empresa <?= $mobileEmpresa ?></span><span class="context-pill">Sucursal <?= $mobileSucursal ?></span><span class="context-pill">Almacén <?= $mobileAlmacen ?></span></div>
        </div>
        <a class="btn btn-mobile btn-dark-soft" href="products_table.php" title="Vista escritorio"><i class="fas fa-desktop"></i></a>
    </header>

    <section class="hero">
        <h1>Productos, sin complicaciones.</h1>
        <p>Busca, edita precios y stock, gestiona imágenes y controla la disponibilidad desde una pantalla pequeña.</p>
        <div class="hero-actions">
            <button class="btn btn-mobile btn-accent" onclick="refreshProducts()"><i class="fas fa-rotate me-1"></i>Actualizar</button>
            <button class="btn btn-mobile btn-dark-soft" onclick="document.getElementById('importInput').click()"><i class="fas fa-file-import me-1"></i>Importar</button>
            <a class="btn btn-mobile btn-dark-soft text-decoration-none text-center" href="products_table.php?action=export_csv"><i class="fas fa-file-export me-1"></i>Exportar</a>
            <a class="btn btn-mobile btn-dark-soft text-decoration-none text-center" href="products_table.php"><i class="fas fa-sliders me-1"></i>Avanzado</a>
            <a class="btn btn-mobile btn-dark-soft text-decoration-none text-center" href="dashboard.php"><i class="fas fa-house me-1"></i>Inicio</a>
        </div>
    </section>

    <div class="search-wrap"><i class="fas fa-search"></i><input id="mobileSearch" type="search" autocomplete="off" placeholder="Buscar por nombre, SKU o código de barras..."></div>
    <div class="toolbar"><select id="mobileFilter"><option value="active">Solo activos</option><option value="all">Todos</option><option value="inactive">Inactivos</option><option value="stock">Con stock</option></select><span class="result-count" id="resultCount">Cargando...</span></div>
    <section id="productList" aria-live="polite"><div class="loading"><i class="fas fa-circle-notch fa-spin fa-2x"></i><div class="mt-2">Cargando productos...</div></div></section>
</main>

<input id="importInput" type="file" accept=".csv,text/csv" hidden>
<input id="imageInput" type="file" accept="image/jpeg,image/webp,image/png" hidden>
<div class="modal fade" id="mobileEditorModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title"><i class="fas fa-pen-to-square me-2"></i>Editar producto</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="mobileEditorBody"></div></div></div></div>
<div class="modal fade" id="mobileHistoryModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Historial</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="mobileHistoryBody"></div></div></div></div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="mobileToasts"></div>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const mobileState = { products: [], filtered: [], editorSku: '', imageSku: '' };
const $m = (id) => document.getElementById(id);
const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const money = (v) => Number(v || 0).toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2});
function toast(message, type = 'info') { const id = 'mt' + Date.now(); const el = document.createElement('div'); el.id = id; el.className = `toast show text-bg-${type} border-0 mb-2`; el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(message)}</div><button class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.remove()"></button></div>`; $m('mobileToasts').appendChild(el); setTimeout(() => el.remove(), 3500); }
async function postToCore(formData) { const res = await fetch('products_table.php', {method:'POST', body:formData}); const data = await res.json(); if (!res.ok || data.status === 'error') throw new Error(data.msg || 'Operación no completada'); return data; }
async function refreshProducts() { $m('productList').innerHTML = '<div class="loading"><i class="fas fa-circle-notch fa-spin fa-2x"></i><div class="mt-2">Actualizando...</div></div>'; try { const res = await fetch('products_mobile.php?mobile_data=1', {cache:'no-store'}); const data = await res.json(); if (!res.ok || data.status === 'error') throw new Error(data.msg || 'No se pudo cargar el inventario'); mobileState.products = Array.isArray(data.products) ? data.products : []; applyFilter(); toast('Inventario actualizado', 'success'); } catch (e) { $m('productList').innerHTML = `<div class="empty-state"><i class="fas fa-triangle-exclamation fa-2x mb-2"></i><br>${esc(e.message)}</div>`; toast('No se pudo cargar el inventario', 'danger'); } }
function applyFilter() { const q = ($m('mobileSearch').value || '').trim().toLowerCase(); const mode = $m('mobileFilter').value; mobileState.filtered = mobileState.products.filter(p => { const hay = [p.codigo,p.nombre,p.categoria,p.codigo_barra_1,p.codigo_barra_2].join(' ').toLowerCase(); const stock = Number(p.stock_total || 0); return (!q || hay.includes(q)) && (mode === 'all' || (mode === 'active' && Number(p.activo ?? 1) === 1) || (mode === 'inactive' && Number(p.activo ?? 1) === 0) || (mode === 'stock' && stock > 0)); }); renderProducts(); }
function renderProducts() { const rows = mobileState.filtered; $m('resultCount').textContent = `${rows.length} producto${rows.length === 1 ? '' : 's'}`; if (!rows.length) { $m('productList').innerHTML = '<div class="empty-state"><i class="fas fa-box-open fa-2x mb-2"></i><br>No hay productos para esta búsqueda.</div>'; return; } $m('productList').innerHTML = rows.map(renderProduct).join(''); }
function renderProduct(p) { const sku = esc(p.codigo); const stock = Number(p.stock_total || 0); const min = Number(p.stock_minimo || 0); const stockClass = stock <= 0 ? 'stock-zero' : (stock <= min ? 'stock-low' : 'stock-ok'); const image = `image.php?code=${encodeURIComponent(p.codigo)}&t=${Date.now()}`; return `<article class="product-card" data-sku="${sku}"><img class="product-img" src="${image}" onerror="this.src='assets/img/no-image-50.png'" alt="${esc(p.nombre)}" onclick="uploadImage('${sku}')"><div class="min-w-0"><div class="product-name">${esc(p.nombre)} ${Number(p.activo ?? 1) ? '' : '<span class="badge text-bg-danger ms-1">OFF</span>'}</div><div class="product-meta">${sku} · ${esc(p.categoria || 'Sin categoría')}</div><div class="product-price">$${money(p.precio)}</div><span class="stock-badge ${stockClass}"><i class="fas fa-cubes"></i>${stock.toFixed(1)} en stock</span><div class="switch-line"><label><input type="checkbox" ${Number(p.es_web) ? 'checked' : ''} onchange="toggleFlag('${sku}','toggle_web',this)"> Web</label><label><input type="checkbox" ${Number(p.es_reservable) ? 'checked' : ''} onchange="toggleFlag('${sku}','toggle_reservable',this)"> Reserva</label></div></div><div class="card-actions"><button class="icon-btn" onclick="openEditor('${sku}')" title="Editar"><i class="fas fa-pen"></i></button><button class="icon-btn" onclick="quickEdit('${sku}','price')" title="Cambiar precio"><i class="fas fa-dollar-sign" style="color:var(--m-accent)"></i></button><button class="icon-btn" onclick="quickEdit('${sku}','stock')" title="Cambiar stock"><i class="fas fa-boxes-stacked" style="color:var(--m-warning)"></i></button><button class="icon-btn" onclick="toggleActive('${sku}')" title="Activar/desactivar"><i class="fas fa-power-off" style="color:var(--m-danger)"></i></button><button class="icon-btn" onclick="showHistory('${sku}')" title="Historial"><i class="fas fa-clock-rotate-left"></i></button><button class="icon-btn" onclick="cloneProduct('${sku}')" title="Clonar"><i class="fas fa-copy"></i></button><a class="icon-btn d-flex align-items-center justify-content-center" href="pos_shrinkage.php?prefill_sku=${encodeURIComponent(p.codigo)}" title="Registrar merma"><i class="fas fa-trash-can" style="color:var(--m-danger)"></i></a></div></article>`; }
function findProduct(sku) { return mobileState.products.find(p => String(p.codigo) === String(sku)); }
async function quickEdit(sku, field) { const p = findProduct(sku); if (!p) return; const current = field === 'price' ? p.precio : p.stock_total; const label = field === 'price' ? 'Nuevo precio' : 'Nuevo stock'; const value = prompt(`${label} para ${p.nombre}:`, Number(current || 0).toFixed(field === 'price' ? 2 : 1)); if (value === null || value.trim() === '' || !isFinite(Number(value)) || Number(value) < 0) return toast('Valor inválido', 'danger'); const fd = new FormData(); fd.append('action','inline_edit'); fd.append('sku',sku); fd.append('field',field); fd.append('value',value); try { await postToCore(fd); toast('Producto actualizado','success'); await refreshProducts(); } catch(e) { toast(e.message,'danger'); } }
async function toggleFlag(sku, action, checkbox) { const fd = new FormData(); fd.append('action',action); fd.append('sku',sku); fd.append('val',checkbox.checked ? '1' : '0'); try { await postToCore(fd); toast('Disponibilidad actualizada','success'); } catch(e) { checkbox.checked = !checkbox.checked; toast(e.message,'danger'); } }
async function toggleActive(sku) { const p = findProduct(sku); if (!p) return; const fd = new FormData(); fd.append('bulk_action', Number(p.activo ?? 1) ? 'active_off' : 'active_on'); fd.append('skus', JSON.stringify([sku])); try { await postToCore(fd); toast(Number(p.activo ?? 1) ? 'Producto desactivado' : 'Producto activado','success'); refreshProducts(); } catch(e) { toast(e.message,'danger'); } }
async function cloneProduct(sku) { if (!confirm('¿Clonar este producto?')) return; const fd = new FormData(); fd.append('action','clone_product'); fd.append('sku',sku); try { const data = await postToCore(fd); toast(`Clonado como ${data.new_sku || 'nuevo producto'}`,'success'); refreshProducts(); } catch(e) { toast(e.message,'danger'); } }
async function openEditor(sku) { mobileState.editorSku = sku; $m('mobileEditorBody').innerHTML = '<div class="text-center p-5"><div class="spinner-border"></div></div>'; bootstrap.Modal.getOrCreateInstance($m('mobileEditorModal')).show(); try { const html = await (await fetch(`product_editor.php?sku=${encodeURIComponent(sku)}`)).text(); $m('mobileEditorBody').innerHTML = html; const form = $m('formEditProduct'); if (form) form.addEventListener('submit', saveEditor); } catch(e) { $m('mobileEditorBody').innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; } }
async function saveEditor(event) { event.preventDefault(); const form = event.currentTarget; const fd = new FormData(form); try { const data = await postToCore(new FormData(form)); toast(data.msg || 'Producto guardado','success'); bootstrap.Modal.getInstance($m('mobileEditorModal')).hide(); refreshProducts(); } catch(e) { toast(e.message,'danger'); } }
async function showHistory(sku) { $m('mobileHistoryBody').innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>'; bootstrap.Modal.getOrCreateInstance($m('mobileHistoryModal')).show(); try { const data = await (await fetch(`products_table.php?action=get_history&sku=${encodeURIComponent(sku)}`)).json(); $m('mobileHistoryBody').innerHTML = Array.isArray(data) && data.length ? data.map(x => `<div class="border-bottom pb-2 mb-2"><strong>${esc(x.fecha)}</strong><br><small>${esc(x.usuario)} · ${esc(x.accion)}</small><br><span>${esc(x.datos)}</span></div>`).join('') : '<div class="text-muted">Sin cambios recientes.</div>'; } catch(e) { $m('mobileHistoryBody').innerHTML = '<div class="text-danger">No se pudo cargar el historial.</div>'; } }
function uploadImage(sku) { mobileState.imageSku = sku; $m('imageInput').click(); }
async function sendImage() { const file = $m('imageInput').files[0]; if (!file || !mobileState.imageSku) return; const fd = new FormData(); fd.append('new_photo', file); fd.append('prod_code', mobileState.imageSku); try { await postToCore(fd); toast('Imagen guardada','success'); refreshProducts(); } catch(e) { toast(e.message,'danger'); } finally { $m('imageInput').value = ''; } }
async function importCsv() { const file = $m('importInput').files[0]; if (!file) return; const fd = new FormData(); fd.append('action','import_products'); fd.append('import_mode','update_create'); fd.append('products_file',file); try { const data = await postToCore(fd); toast(`Importación: ${data.imported?.inserted || 0} creados, ${data.imported?.updated || 0} actualizados`,'success'); refreshProducts(); } catch(e) { toast(e.message,'danger'); } finally { $m('importInput').value = ''; } }
$m('mobileSearch').addEventListener('input', applyFilter); $m('mobileFilter').addEventListener('change', applyFilter); $m('imageInput').addEventListener('change', sendImage); $m('importInput').addEventListener('change', importCsv); refreshProducts();
</script>
</body>
</html>
