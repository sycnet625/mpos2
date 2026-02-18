<!-- Modal de Gestión de Categorías -->
<div class="modal fade" id="categoriesModal" tabindex="-1" aria-labelledby="categoriesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="categoriesModalLabel"><i class="fas fa-tags me-2"></i> Gestión de Categorías</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="catName" class="form-label small fw-bold">Nombre</label>
                        <input type="text" class="form-control" id="catName" placeholder="Ej: Bebidas">
                    </div>
                    <div class="col-md-3">
                        <label for="catEmoji" class="form-label small fw-bold">Emoji</label>
                        <input type="text" class="form-control text-center" id="catEmoji" placeholder="🥤" maxlength="2">
                    </div>
                    <div class="col-md-3">
                        <label for="catColor" class="form-label small fw-bold">Color</label>
                        <input type="color" class="form-control form-control-color w-100" id="catColor" value="#ffffff" title="Elige un color">
                    </div>
                    <div class="col-12 text-end">
                        <input type="hidden" id="catId">
                        <button type="button" class="btn btn-secondary btn-sm me-2" onclick="resetCatForm()" id="btnResetCat" style="display:none;">Cancelar Edición</button>
                        <button type="button" class="btn btn-success fw-bold w-100" onclick="saveCategory()" id="btnSaveCat"><i class="fas fa-plus me-1"></i> Agregar Categoría</button>
                    </div>
                </div>

                <hr>

                <div class="list-group list-group-flush" id="categoriesList" style="max-height: 300px; overflow-y: auto;">
                    <!-- Lista de categorías se renderiza aquí -->
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let allCategories = [];

function openCategoriesModal() {
    const modal = new bootstrap.Modal(document.getElementById('categoriesModal'));
    modal.show();
    loadCategories();
}

async function loadCategories() {
    const list = document.getElementById('categoriesList');
    list.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
    
    try {
        const res = await fetch('categories_api.php');
        allCategories = await res.json();
        renderCategoriesList();
    } catch (e) {
        list.innerHTML = '<div class="alert alert-danger">Error cargando categorías</div>';
    }
}

function renderCategoriesList() {
    const list = document.getElementById('categoriesList');
    list.innerHTML = '';
    
    if (allCategories.length === 0) {
        list.innerHTML = '<div class="text-center text-muted py-3">No hay categorías registradas.</div>';
        return;
    }

    allCategories.forEach(cat => {
        const item = document.createElement('div');
        item.className = 'list-group-item d-flex justify-content-between align-items-center';
        // Mostrar color como un pequeño círculo o borde
        const colorStyle = cat.color ? `background-color: ${cat.color}; width: 20px; height: 20px; border-radius: 50%; display: inline-block; margin-right: 10px; border: 1px solid #ddd;` : '';
        
        item.innerHTML = `
            <div class="d-flex align-items-center">
                <span style="${colorStyle}"></span>
                <span class="fs-5 me-2">${cat.emoji || ''}</span>
                <span class="fw-bold">${cat.nombre}</span>
            </div>
            <div>
                <button class="btn btn-sm btn-outline-primary me-1" onclick='editCategory(${JSON.stringify(cat)})'><i class="fas fa-edit"></i></button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(${cat.id})"><i class="fas fa-trash"></i></button>
            </div>
        `;
        list.appendChild(item);
    });
}

function editCategory(cat) {
    document.getElementById('catId').value = cat.id;
    document.getElementById('catName').value = cat.nombre;
    document.getElementById('catEmoji').value = cat.emoji || '';
    document.getElementById('catColor').value = cat.color || '#ffffff';
    
    document.getElementById('btnSaveCat').innerHTML = '<i class="fas fa-save me-1"></i> Actualizar';
    document.getElementById('btnSaveCat').className = 'btn btn-primary fw-bold w-100';
    document.getElementById('btnResetCat').style.display = 'inline-block';
}

function resetCatForm() {
    document.getElementById('catId').value = '';
    document.getElementById('catName').value = '';
    document.getElementById('catEmoji').value = '';
    document.getElementById('catColor').value = '#ffffff';
    
    document.getElementById('btnSaveCat').innerHTML = '<i class="fas fa-plus me-1"></i> Agregar Categoría';
    document.getElementById('btnSaveCat').className = 'btn btn-success fw-bold w-100';
    document.getElementById('btnResetCat').style.display = 'none';
}

async function saveCategory() {
    const id = document.getElementById('catId').value;
    const nombre = document.getElementById('catName').value.trim();
    const emoji = document.getElementById('catEmoji').value.trim();
    const color = document.getElementById('catColor').value;

    if (!nombre) return alert("El nombre es obligatorio");

    const action = id ? 'update' : 'create';
    const payload = { action, id, nombre, emoji, color };

    try {
        const res = await fetch('categories_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            resetCatForm();
            loadCategories();
            // Opcional: Recargar selects de categorías en la página principal si existen
            if (typeof reloadCategorySelects === 'function') reloadCategorySelects();
        } else {
            alert('Error: ' + data.msg);
        }
    } catch (e) {
        alert('Error de conexión');
    }
}

async function deleteCategory(id) {
    if (!confirm("¿Seguro de eliminar esta categoría?")) return;
    
    try {
        const res = await fetch('categories_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id })
        });
        const data = await res.json();
        
        if (data.status === 'success') {
            loadCategories();
            if (typeof reloadCategorySelects === 'function') reloadCategorySelects();
        } else {
            alert('Error: ' + data.msg);
        }
    } catch (e) {
        alert('Error de conexión');
    }
}
</script>
