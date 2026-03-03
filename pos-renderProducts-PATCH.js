// ==========================================
// 🔧 PARCHE PARA RENDERPRODUCTS
// Agregar ANTES de cargar pos.js
// ==========================================

(function() {
    'use strict';
    
    console.log('🔧 Aplicando parche renderProducts...');
    
    // Esperar a que el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyPatch);
    } else {
        applyPatch();
    }
    
    function applyPatch() {
        // Esperar un momento para que pos.php defina su renderProducts
        setTimeout(() => {
            // Guardar la función original de pos.php si existe
            const originalRenderProducts = window.renderProducts;
            
            // Crear versión segura de renderProducts
            window.renderProducts = function(category) {
                console.log('🎨 renderProducts llamado con:', category);
                
                const grid = document.getElementById('productContainer');
                if (!grid) {
                    console.warn('❌ productContainer no encontrado');
                    return;
                }
                
                // Si no hay categoría, usar 'all'
                if (typeof category === 'undefined') {
                    const activeBtn = document.querySelector('.category-btn.active');
                    category = activeBtn ? (activeBtn.innerText === 'TODOS' ? 'all' : activeBtn.innerText) : 'all';
                }
                
                const searchInput = document.getElementById('searchInput');
                const term = searchInput ? searchInput.value.toLowerCase() : '';
                
                // CRÍTICO: Usar productsDB o PRODUCTS_DATA, lo que esté disponible
                let sourceData = null;
                
                if (typeof window.productsDB !== 'undefined' && Array.isArray(window.productsDB) && window.productsDB.length > 0) {
                    sourceData = window.productsDB;
                    console.log('✅ Usando productsDB:', sourceData.length);
                } else if (typeof window.PRODUCTS_DATA !== 'undefined' && Array.isArray(window.PRODUCTS_DATA) && window.PRODUCTS_DATA.length > 0) {
                    sourceData = window.PRODUCTS_DATA;
                    // Copiar a productsDB para futuras llamadas
                    window.productsDB = window.PRODUCTS_DATA;
                    console.log('✅ Usando PRODUCTS_DATA:', sourceData.length);
                } else {
                    console.warn('⚠️ No hay productos disponibles aún');
                    grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-hourglass-half fa-3x mb-2"></i><p>Cargando productos...</p></div>';
                    return;
                }
                
                // Limpiar grid
                grid.innerHTML = '';
                
                // Filtrar por categoría y búsqueda
                let filtered = sourceData.filter(p => {
                    const matchCat = category === 'all' || p.categoria === category;
                    const matchSearch = !term || p.nombre.toLowerCase().includes(term) || p.codigo.toLowerCase().includes(term);
                    return matchCat && matchSearch;
                });
                
                // Aplicar filtro de stock si existe
                if (typeof window.shouldShowProduct === 'function') {
                    filtered = filtered.filter(p => window.shouldShowProduct(p));
                }
                
                console.log(`📦 Mostrando ${filtered.length} productos`);
                
                // Renderizar productos
                filtered.forEach(p => {
                    const stock = parseFloat(p.stock) || 0;
                    const hasStock = stock > 0 || p.es_servicio == 1;
                    
                    const card = document.createElement('div');
                    card.className = `product-card ${hasStock ? '' : 'disabled'}`;
                    
                    if (hasStock && typeof window.addToCart === 'function') {
                        card.onclick = () => window.addToCart(p);
                    }
                    
                    // Badge de stock
                    const badgeClass = hasStock ? 'stock-ok' : 'stock-zero';
                    const stockText = p.es_servicio == 1 ? '∞' : stock;
                    
                    // Imagen o placeholder
                    let imgHtml = '';
                    if (p.has_image) {
                        const cod = encodeURIComponent(p.codigo);
                        imgHtml = `<div class="product-img-container">
                            <picture>
                                <source type="image/avif" srcset="image.php?code=${cod}&fmt=avif">
                                <source type="image/webp" srcset="image.php?code=${cod}&fmt=webp">
                                <img src="image.php?code=${cod}&fmt=jpg"
                                     class="product-img" loading="lazy"
                                     onerror="this.closest('.product-img-container').style.display='none'">
                            </picture>
                        </div>`;
                    } else {
                        const color = p.color || '#999';
                        const initials = p.nombre.substring(0, 2).toUpperCase();
                        imgHtml = `<div class="product-img-container" style="background:${color}">
                            <span class="placeholder-text">${initials}</span>
                        </div>`;
                    }
                    
                    card.innerHTML = `
                        <div class="stock-badge ${badgeClass}">${stockText}</div>
                        ${imgHtml}
                        <div class="product-info">
                            <div class="product-name">${p.nombre}</div>
                            <div class="product-price">$${parseFloat(p.precio).toFixed(2)}</div>
                        </div>
                    `;
                    
                    grid.appendChild(card);
                });
                
                // Si no hay productos después de filtrar
                if (filtered.length === 0) {
                    grid.innerHTML = '<div class="text-center text-muted p-4"><i class="fas fa-search fa-3x mb-2"></i><p>No se encontraron productos</p></div>';
                }
            };
            
            console.log('✅ Parche renderProducts aplicado');
            
        }, 100);
    }
    
})();

