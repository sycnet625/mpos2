<?php
// ARCHIVO: client_order.php
require_once 'db.php';
require_once 'config_loader.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kiosco de Pedidos | <?php echo $config['nombre_empresa'] ?? 'PalWeb'; ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/animate.min.css">
    <style>
        :root { --odoo-purple: #714B67; --odoo-dark: #212529; --odoo-light: #F8F9FA; --odoo-accent: #017E84; }
        
        body { 
            background-color: var(--odoo-light); 
            font-family: 'Inter', 'Segoe UI', sans-serif; 
            height: 100vh; 
            overflow: hidden; 
            user-select: none;
        }

        /* Scrollbars para pantallas táctiles (Anchas) */
        ::-webkit-scrollbar { width: 12px; height: 12px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; border: 3px solid #f1f1f1; }
        ::-webkit-scrollbar-thumb:hover { background: #888; }

        .kiosk-container { display: flex; height: 100vh; }
        
        /* Sidebar mejorado */
        .sidebar { 
            width: 130px; 
            background: white; 
            border-right: 1px solid #ddd; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            padding: 20px 0;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .cat-item {
            width: 90px;
            min-height: 90px;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            color: #555;
            text-align: center;
            padding: 10px;
            background: #fdfdfd;
            border: 1px solid #eee;
        }
        .cat-item .cat-emoji { font-size: 2.2rem; margin-bottom: 5px; transition: transform 0.2s; }
        .cat-item:active .cat-emoji { transform: scale(1.2); }
        .cat-item span { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; line-height: 1.1; }
        .cat-item.active { background: var(--odoo-purple) !important; color: white !important; border-color: var(--odoo-purple); box-shadow: 0 8px 15px rgba(113, 75, 103, 0.2); transform: translateY(-2px); }

        .main-content { flex-grow: 1; display: flex; flex-direction: column; background: #f4f6f9; min-width: 0; }
        .kiosk-header { 
            background: white; 
            padding: 20px 35px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            z-index: 10;
        }

        /* Grid de Productos - NO ACHICAR IMAGENES */
        .product-grid { 
            flex-grow: 1; 
            overflow-y: auto; 
            padding: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); /* Tamaño fijo mínimo */
            gap: 30px;
            align-content: start;
        }

        .product-card {
            background: white;
            border-radius: 25px;
            overflow: hidden;
            border: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            height: 320px; /* Altura fija para consistencia */
        }
        .product-card:active { transform: scale(0.95); }
        .product-img-wrapper { position: relative; width: 100%; height: 200px; background: #f8f9fa; flex-shrink: 0; }
        .product-img { width: 100%; height: 100%; object-fit: cover; }
        
        .product-info { padding: 18px; text-align: center; flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }
        .product-name { font-weight: 800; font-size: 1.15rem; color: var(--odoo-dark); margin-bottom: 8px; line-height: 1.2; }
        .product-price { color: var(--odoo-accent); font-weight: 900; font-size: 1.4rem; }

        .qty-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--odoo-purple);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            opacity: 0;
            transform: scale(0);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            z-index: 5;
            border: 3px solid white;
        }
        .product-card.in-cart .qty-badge { opacity: 1; transform: scale(1); }

        .kiosk-footer {
            background: white;
            padding: 20px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 2px solid #eee;
            cursor: pointer;
            box-shadow: 0 -5px 15px rgba(0,0,0,0.03);
        }
        .btn-checkout {
            background: var(--odoo-accent);
            color: white;
            border: none;
            padding: 18px 45px;
            border-radius: 50px;
            font-weight: 800;
            font-size: 1.3rem;
            transition: all 0.2s;
            box-shadow: 0 10px 20px rgba(1, 126, 132, 0.2);
        }
        .btn-checkout:disabled { background: #ccc; box-shadow: none; }

        .modal-odoo { border-radius: 30px; border: none; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.2); }
        .modal-odoo .modal-header { background: var(--odoo-purple); color: white; padding: 25px; border: none; }
    </style>
</head>
<body>

<div class="kiosk-container">
    <div class="sidebar" id="categorySidebar">
        <div class="cat-item active" onclick="filterCategory('all', this)" style="background-color: #E0F2F1;">
            <span class="cat-emoji">😋</span>
            <span>Todos</span>
        </div>
    </div>

    <div class="main-content">
        <div class="kiosk-header">
            <div>
                <h3 class="fw-bold m-0 text-uppercase" style="letter-spacing: 2px; color: var(--odoo-purple);"><?php echo $config['nombre_empresa'] ?? 'PalWeb'; ?></h3>
                <small class="text-muted fw-bold">AUTOPEDIDO TÁCTIL</small>
            </div>
            <div class="d-flex align-items-center gap-3">
                <i class="fas fa-clock text-muted"></i>
                <span id="kioskTime" class="fw-black fs-4" style="font-weight: 900;">00:00</span>
            </div>
        </div>

        <div class="product-grid" id="productGrid"></div>

        <div class="kiosk-footer" onclick="showCart()">
            <div class="d-flex align-items-center">
                <div class="me-4 position-relative">
                    <div class="bg-light p-3 rounded-circle">
                        <i class="fas fa-shopping-basket fs-2 text-muted"></i>
                    </div>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger fs-6" id="cartCount">0</span>
                </div>
                <div>
                    <div class="text-muted small fw-black">MI PEDIDO (Toca para ver)</div>
                    <div class="fs-1" id="footerTotal" style="font-weight: 950; color: var(--odoo-purple); line-height: 1;">$0.00</div>
                </div>
            </div>
            <button class="btn-checkout" id="btnPay" onclick="openCheckout(); event.stopPropagation();" disabled>
                FINALIZAR <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>
    </div>
</div>

<!-- Modales se mantienen igual estructuralmente pero con estilos actualizados ... -->
<div class="modal fade" id="cartModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-odoo">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2"></i> Revisar Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="cartListItems" style="max-height: 45vh; overflow-y: auto; padding-right: 10px;"></div>
                <div class="text-center mt-4">
                    <button class="btn btn-link text-danger fw-bold" onclick="clearCart()">Vaciar todo el carrito</button>
                </div>
            </div>
            <div class="modal-footer border-0 p-4">
                <button class="btn btn-lg w-100 rounded-pill fw-bold py-3" style="background:var(--odoo-accent); color:white;" data-bs-dismiss="modal">CONTINUAR COMPRANDO</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-odoo">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane me-2"></i> Enviar Pedido</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="checkoutSummary" class="mb-4 bg-light p-3 rounded-4" style="max-height: 150px; overflow-y: auto;"></div>
                <div class="mb-4 text-center">
                    <label class="form-label fw-black fs-5 mb-3">TU NOMBRE / NÚMERO DE MESA</label>
                    <input type="text" id="clientName" class="form-control form-control-lg rounded-4 text-center border-3 py-3" style="font-weight: 900; font-size: 1.8rem; border-color: var(--odoo-purple);" placeholder="Escribe aquí...">
                </div>
                <button class="btn btn-lg w-100 rounded-pill fw-bold py-3 fs-4" style="background:var(--odoo-accent); color:white; box-shadow: 0 10px 20px rgba(1, 126, 132, 0.3);" onclick="submitOrder()">
                    CONFIRMAR Y PEDIR
                </button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    let products = [];
    let cart = {};
    let currentCategory = 'all';
    let categoriesData = [];
    const CONFIG_SOLO_STOCK = <?php echo ($config['kiosco_solo_stock'] ?? false) ? 'true' : 'false'; ?>;
    let kioskEnabled = true;

    const funnyApologies = [
        "Nuestros chefs están en una competencia de pulsos y nadie quiere perder. ¡Vuelve pronto!",
        "El horno decidió tomarse unas vacaciones sorpresa a la Antártida. Estamos negociando su regreso.",
        "Un grupo de pingüinos hambrientos secuestró nuestra cocina. Estamos esperando el rescate.",
        "Estamos actualizando el sabor de nuestra comida a la versión 2.0. ¡Paciencia deliciosa!",
        "Nuestra cafetera se cree ahora una máquina del tiempo y no para de viajar al 1800. Estamos ajustándola.",
        "El chef principal está convencido de que es un pirata y salió a buscar tesoros... o perejil. Volvemos pronto.",
        "Los cubiertos se declararon en huelga exigiendo más brillo. Estamos en negociaciones sindicales.",
        "Un gato entró a la cocina y ahora él es el dueño. Estamos esperando que termine su siesta.",
        "Se nos acabó la gravedad en la cocina y las pizzas están flotando. ¡Peligro de pepperoni volador!",
        "Estamos persiguiendo a una lechuga que se escapó del refrigerador. Es muy rápida."
    ];

    async function checkKioskStatus() {
        try {
            const res = await fetch('self_order_api.php?action=get_config');
            const data = await res.json();
            if(data.status === 'success') {
                const isEnabled = data.config.kiosco_aceptar_pedidos !== false;
                if (isEnabled !== kioskEnabled) {
                    kioskEnabled = isEnabled;
                    toggleKioskBlock(!isEnabled);
                }
            }
        } catch(e) { console.error("Error checking kiosk status", e); }
    }

    function toggleKioskBlock(blocked) {
        let blockOverlay = document.getElementById('kioskBlockOverlay');
        if (blocked) {
            if (!blockOverlay) {
                blockOverlay = document.createElement('div');
                blockOverlay.id = 'kioskBlockOverlay';
                blockOverlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:var(--odoo-purple); color:white; z-index:10000; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; padding:40px;';
                
                const randomMsg = funnyApologies[Math.floor(Math.random() * funnyApologies.length)];
                
                blockOverlay.innerHTML = `
                    <i class="fas fa-ghost fa-6x mb-4 animate__animated animate__bounce animate__infinite"></i>
                    <h1 class="display-3 fw-black mb-4">¡UPS! UN MOMENTITO...</h1>
                    <p class="fs-2 fw-light italic mb-5" style="max-width:800px;">${randomMsg}</p>
                    <div class="bg-white text-dark p-4 rounded-4 shadow-lg">
                        <h4 class="fw-bold m-0"><i class="fas fa-info-circle text-primary me-2"></i> No estamos aceptando nuevos pedidos por ahora.</h4>
                    </div>
                `;
                document.body.appendChild(blockOverlay);
            }
        } else {
            if (blockOverlay) blockOverlay.remove();
        }
    }

    async function initKiosk() {
        await checkKioskStatus();
        setInterval(checkKioskStatus, 5000); // Chequear cada 5 segundos

        const [resProd, resCat] = await Promise.all([
            fetch('get_products.php?all=1'),
            fetch('categories_api.php')
        ]);
        products = await resProd.json();
        categoriesData = await resCat.json();
        
        renderProducts(products);
        renderCategories();
        updateTime();
        setInterval(updateTime, 1000);
    }

    function updateTime() {
        const now = new Date();
        document.getElementById('kioskTime').innerText = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    function renderCategories() {
        // FILTRAR PRODUCTOS PARA LAS CATEGORÍAS SEGÚN EL STOCK
        let visibleProducts = products;
        if (CONFIG_SOLO_STOCK) {
            visibleProducts = products.filter(p => parseFloat(p.stock) > 0 || p.es_servicio == 1);
        }

        const cats = [...new Set(visibleProducts.map(p => p.categoria).filter(c => c))].sort();
        const container = document.getElementById('categorySidebar');
        
        // Actualizar el item de "Todos"
        const allItem = container.querySelector('.cat-item');
        if(allItem) {
            allItem.innerHTML = `<span class="cat-emoji">😋</span><span>Todos</span>`;
            allItem.style.backgroundColor = "#E0F2F1";
        }

        cats.forEach((c) => {
            // Buscar info de la categoría en categoriesData
            const catInfo = categoriesData.find(cd => cd.nombre.toLowerCase() === c.toLowerCase());
            const icon = catInfo ? catInfo.emoji : "🍽️";
            const color = catInfo ? catInfo.color : "#fdfdfd";

            const div = document.createElement('div');
            div.className = 'cat-item';
            div.style.backgroundColor = color;
            div.innerHTML = `<span class="cat-emoji">${icon}</span><span>${c}</span>`;
            div.onclick = () => filterCategory(c, div);
            container.appendChild(div);
        });
    }

    function filterCategory(cat, el) {
        currentCategory = cat;
        document.querySelectorAll('.cat-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        const filtered = cat === 'all' ? products : products.filter(p => p.categoria === cat);
        renderProducts(filtered);
    }

    function getFilteredProducts() {
        return currentCategory === 'all' ? products : products.filter(p => p.categoria === currentCategory);
    }

    function renderProducts(list) {
        const grid = document.getElementById('productGrid');
        grid.innerHTML = '';
        
        // FILTRAR POR STOCK SI LA CONFIGURACIÓN ESTÁ ACTIVA
        let filteredList = list;
        if (CONFIG_SOLO_STOCK) {
            filteredList = list.filter(p => parseFloat(p.stock) > 0 || p.es_servicio == 1);
        }

        filteredList.forEach(p => {
            const qty = cart[p.codigo] ? cart[p.codigo].qty : 0;
            const div = document.createElement('div');
            div.className = `product-card ${qty > 0 ? 'in-cart' : ''} animate__animated animate__fadeIn`;
            div.onclick = () => addToCart(p.codigo);
            div.innerHTML = `
                <div class="qty-badge">${qty}</div>
                <div class="product-img-wrapper">
                    <img src="image.php?code=${p.codigo}" class="product-img" onerror="this.src='assets/img/no-image.png'">
                </div>
                <div class="product-info">
                    <div class="product-name text-truncate">${p.nombre}</div>
                    <div class="product-price">$${parseFloat(p.precio).toFixed(2)}</div>
                </div>
            `;
            grid.appendChild(div);
        });
    }

    function addToCart(code) {
        const p = products.find(i => i.codigo == code);
        if(!cart[code]) cart[code] = { ...p, qty: 0, notes: "" };
        cart[code].qty++;
        updateCartUI();
        // MANTENER CATEGORÍA: Renderizar solo los productos de la categoría actual
        renderProducts(getFilteredProducts());
    }

    function updateCartUI() {
        let total = 0, count = 0;
        Object.values(cart).forEach(i => {
            total += i.precio * i.qty;
            count += i.qty;
        });
        document.getElementById('footerTotal').innerText = '$' + total.toFixed(2);
        document.getElementById('cartCount').innerText = count;
        document.getElementById('btnPay').disabled = count === 0;
    }

    function showCart() {
        if(Object.keys(cart).length === 0) return;
        const container = document.getElementById('cartListItems');
        container.innerHTML = '';
        Object.values(cart).forEach(i => {
            const row = document.createElement('div');
            row.className = 'border-bottom py-3';
            row.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center">
                        <span class="badge bg-purple me-2" style="background:var(--odoo-purple); font-size:1rem; padding:8px 12px;">${i.qty}x</span>
                        <strong class="fs-5">${i.nombre}</strong>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="fw-black fs-5">$${(i.precio * i.qty).toFixed(2)}</span>
                        <button class="btn btn-outline-danger rounded-circle p-2" style="width:35px; height:35px;" onclick="removeFromCart('${i.codigo}')"><i class="fas fa-minus"></i></button>
                    </div>
                </div>
                <input type="text" class="form-control rounded-3 bg-light border-0 small py-2" style="font-size:0.9rem;" placeholder="Añadir nota especial..." value="${i.notes}" onchange="updateNote('${i.codigo}', this.value)">
            `;
            container.appendChild(row);
        });
        new bootstrap.Modal(document.getElementById('cartModal')).show();
    }

    function removeFromCart(code) {
        if(cart[code]) {
            cart[code].qty--;
            if(cart[code].qty <= 0) delete cart[code];
            updateCartUI();
            if(Object.keys(cart).length === 0) {
                bootstrap.Modal.getInstance(document.getElementById('cartModal')).hide();
            } else {
                showCart();
            }
            renderProducts(getFilteredProducts());
        }
    }

    function clearCart() {
        if(!confirm("¿Deseas vaciar todo el pedido?")) return;
        cart = {};
        updateCartUI();
        bootstrap.Modal.getInstance(document.getElementById('cartModal')).hide();
        renderProducts(getFilteredProducts());
    }

    function updateNote(code, val) { if(cart[code]) cart[code].notes = val; }

    function openCheckout() {
        const summary = document.getElementById('checkoutSummary');
        summary.innerHTML = '';
        Object.values(cart).forEach(i => {
            summary.innerHTML += `
                <div class="d-flex justify-content-between py-1 border-bottom border-white">
                    <span><strong style="color:var(--odoo-purple)">${i.qty}x</strong> ${i.nombre}</span>
                    <span class="fw-bold">$${(i.precio * i.qty).toFixed(2)}</span>
                </div>
            `;
        });
        new bootstrap.Modal(document.getElementById('checkoutModal')).show();
    }

    async function submitOrder() {
        const name = document.getElementById('clientName').value.trim();
        if(!name) return alert("Por favor indica tu nombre o mesa");
        
        const btn = document.querySelector('#checkoutModal .btn-lg'); // Select the button correctly
        const originalText = btn.innerHTML;
        btn.disabled = true; 
        btn.innerHTML = "ENVIANDO...";
        
        const items = Object.values(cart).map(i => ({ codigo: i.codigo, qty: i.qty, notes: i.notes }));
        try {
            const res = await fetch('self_order_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', name, items }) });
            const data = await res.json();
            
            if(data.status === 'success') {
                showSuccessScreen(data.id);
            } else {
                alert("Error: " + (data.msg || "No se pudo crear el pedido"));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch(e) { 
            console.error(e);
            alert("Error de conexión"); 
            btn.disabled = false; 
            btn.innerHTML = originalText;
        }
    }

    function showSuccessScreen(id) {
        document.body.innerHTML = `
            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-center p-5 animate__animated animate__zoomIn" style="background:white;">
                <i class="fas fa-check-circle text-success" style="font-size: 10rem;"></i>
                <h1 class="display-1 fw-black mt-4" style="color:var(--odoo-purple); font-weight:950;">#${id}</h1>
                <h2 class="fw-bold display-4">ORDEN RECIBIDA</h2>
                <p class="fs-2 text-muted mt-3">Por favor espera tu pedido con este número.</p>
                <button class="btn btn-lg btn-dark px-5 py-4 mt-5 rounded-pill shadow-lg fs-3 fw-bold" onclick="location.reload()">INICIO</button>
            </div>
        `;
    }
    initKiosk();
</script>
</body>
</html>
