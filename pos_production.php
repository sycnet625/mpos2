<?php
// ARCHIVO: /var/www/palweb/api/pos_production.php
// VERSIÓN: FINAL BIZLAND READY + REPORTE CONSOLIDADO DE INSUMOS
// ACTUALIZACIÓN: Se agregó selección múltiple de recetas para análisis de stock masivo

ini_set('display_errors', 0);
require_once 'db.php';

// 1. CARGAR CONFIGURACIÓN
require_once 'config_loader.php';
$EMP_ID = intval($config['id_empresa']);

// 2. CARGA DE DATOS MAESTROS (Para inicializar Vue rápidamente)
try {
    // Recetas (Cabecera + Precio Venta Actual del Producto Final)
    $recetas = $pdo->query("SELECT r.*, 
                            COALESCE(p.nombre, 'Producto Borrado') as nombre_producto_final, 
                            COALESCE(p.precio, 0) as precio_venta 
                            FROM recetas_cabecera r 
                            LEFT JOIN productos p ON r.id_producto_final = p.codigo 
                            ORDER BY r.nombre_receta ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Productos Finales (Solo los que son 'Elaborados' por nosotros)
    $productosFinales = $pdo->query("SELECT codigo, nombre, unidad_medida 
                                     FROM productos 
                                     WHERE activo=1 AND id_empresa=$EMP_ID AND es_elaborado=1 
                                     ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Insumos (Materia Prima y otros, excluyendo servicios)
    $insumos = $pdo->query("SELECT codigo, nombre, costo, unidad_medida 
                            FROM productos 
                            WHERE activo=1 AND id_empresa=$EMP_ID AND es_servicio=0 
                            ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error de conexión o base de datos: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Producción</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="assets/js/vue.min.js"></script>
    
    <style>
        /* Estilos Base (Theme Friendly) */
        body { background-color: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; padding-bottom: 80px; }
        
        /* Navbar */
        .navbar-custom { background-color: #1e293b; color: white; padding: 0.8rem 1.5rem; border-bottom: 3px solid #3b82f6; }
        
        /* Tarjetas */
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .card-custom:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        
        /* Tablas */
        .table-hover tbody tr:hover { background-color: rgba(59, 130, 246, 0.1); cursor: pointer; }
        .selected-row { background-color: rgba(59, 130, 246, 0.2) !important; border-left: 4px solid #3b82f6; }
        
        /* Tabs de Navegación */
        .nav-tabs .nav-link { color: #64748b; font-weight: 500; border: none; border-bottom: 3px solid transparent; }
        .nav-tabs .nav-link:hover { color: #3b82f6; }
        .nav-tabs .nav-link.active { color: #3b82f6; border-bottom: 3px solid #3b82f6; background: transparent; font-weight: bold; }
        
        /* Autocomplete List */
        .search-list { position: absolute; width: 100%; max-height: 250px; overflow-y: auto; z-index: 2000; background: white; border: 1px solid #e2e8f0; list-style: none; padding: 0; border-radius: 0 0 8px 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .search-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .search-item:hover { background-color: #f8fafc; color: #3b82f6; }
        
        /* Inputs Tabla */
        .input-qty { border: 1px solid transparent; background: transparent; text-align: center; font-weight: bold; width: 100%; border-radius: 4px; }
        .input-qty:focus { background: white; border-color: #3b82f6; outline: none; }
        .input-qty:hover { background: rgba(255,255,255,0.5); border-color: #e2e8f0; }
    </style>
</head>
<body class="bg-light">

<div id="app">
    
    <nav class="navbar-custom mb-4 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h5 class="m-0 fw-bold"><i class="fas fa-industry me-2"></i> CENTRO DE PRODUCCIÓN</h5>
        </div>
        <div>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm fw-bold"><i class="fas fa-home me-1"></i> Inicio</a>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link" :class="{active: currentTab==='master' && !isEditing}" @click="currentTab='master'; isEditing=false">
                    <i class="fas fa-flask me-2"></i> Recetas y Fórmulas
                </a>
            </li>
            
            <li class="nav-item" v-if="isEditing">
                <a class="nav-link active text-primary">
                    <i class="fas fa-edit me-2"></i> {{ form.id ? 'Editando: ' + form.nombre_receta : 'Nueva Receta' }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link" :class="{active: currentTab==='history'}" @click="loadHistory">
                    <i class="fas fa-history me-2"></i> Historial Producción
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{active: currentTab==='reports'}" @click="loadReports">
                    <i class="fas fa-chart-pie me-2"></i> Informes BI
                </a>
            </li>
        </ul>

        <div v-show="currentTab==='master' && !isEditing">
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="card card-custom">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                            <h6 class="m-0 fw-bold text-muted">Recetas Configuradas</h6>
                            <div>
                                <button class="btn btn-warning btn-sm fw-bold me-2" v-if="selectedRecipes.length > 0" @click="printConsolidatedReport">
                                    <i class="fas fa-clipboard-list me-1"></i> Planif. Insumos ({{selectedRecipes.length}})
                                </button>
                                
                                <button class="btn btn-primary btn-sm fw-bold me-2" @click="startCreate"><i class="fas fa-plus me-1"></i> Nueva Receta</button>
                                <button class="btn btn-outline-success btn-sm" @click="exportCSV"><i class="fas fa-file-excel me-1"></i> Excel</button>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height: 450px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light sticky-top shadow-sm">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">
                                            <input type="checkbox" class="form-check-input cursor-pointer" 
                                                   :checked="recetas.length > 0 && selectedRecipes.length === recetas.length" 
                                                   @click.stop="toggleSelectAll">
                                        </th>
                                        <th>Nombre Receta</th>
                                        <th>Producto Final (Output)</th>
                                        <th class="text-center">Rendimiento</th>
                                        <th class="text-end">Costo Lote</th>
                                        <th class="text-end">Costo Unit.</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="r in recetas" :key="r.id" :class="{'selected-row': selectedRecipe && selectedRecipe.id === r.id}" @click="selectRecipe(r)">
                                        <td class="text-center" @click.stop>
                                            <input type="checkbox" class="form-check-input cursor-pointer" :value="r.id" v-model="selectedRecipes">
                                        </td>
                                        
                                        <td class="fw-bold text-primary">{{ r.nombre_receta }}</td>
                                        <td>
                                            {{ r.nombre_producto_final }}
                                            <span class="text-muted small d-block" v-if="r.id_producto_final">{{ r.id_producto_final }}</span>
                                        </td>
                                        <td class="text-center"><span class="badge bg-secondary">{{ parseFloat(r.unidades_resultantes) }} u</span></td>
                                        <td class="text-end text-muted">${{ parseFloat(r.costo_total_lote).toFixed(2) }}</td>
                                        <td class="text-end fw-bold text-dark">${{ parseFloat(r.costo_unitario).toFixed(2) }}</td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-light border" @click.stop="cloneRecipe(r)" title="Clonar"><i class="fas fa-copy"></i></button>
                                                <button class="btn btn-sm btn-light border" @click.stop="startEdit(r)" title="Editar"><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-sm btn-light border text-danger" @click.stop="deleteRecipe(r)" title="Eliminar"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="recetas.length === 0">
                                        <td colspan="7" class="text-center py-5 text-muted">No hay recetas. Crea una nueva.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4" v-if="selectedRecipe">
                <div class="col-lg-8">
                    <div class="card card-custom h-100">
                        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-list text-warning me-2"></i> Ingredientes del Lote</span>
                            <div>
                                <button class="btn btn-info btn-sm text-white fw-bold me-2" @click="openAnalyzeModal"><i class="fas fa-calculator me-1"></i> Analizar Capacidad</button>
                                <button class="btn btn-primary btn-sm fw-bold shadow-sm" @click="confirmProduce"><i class="fas fa-cogs me-1"></i> PRODUCIR</button>
                                <button class="btn btn-dark btn-sm ms-2" @click="printReport(selectedRecipe.id)"><i class="fas fa-print"></i></button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="bg-light text-muted small">
                                    <tr>
                                        <th>Insumo</th>
                                        <th class="text-center">Cant. Req.</th>
                                        <th class="text-center">Stock Actual</th>
                                        <th class="text-end">Costo Unit.</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="d in details">
                                        <td>{{ d.nombre }}</td>
                                        <td class="text-center fw-bold">{{ parseFloat(d.cantidad) }} {{d.unidad_medida}}</td>
                                        <td class="text-center">
                                            <span :class="parseFloat(d.stock_real) >= parseFloat(d.cantidad) ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger'">
                                                {{ parseFloat(d.stock_real) }}
                                            </span>
                                        </td>
                                        <td class="text-end text-muted">${{ parseFloat(d.costo_actual).toFixed(2) }}</td>
                                        <td class="text-end fw-bold">${{ (d.cantidad * d.costo_actual).toFixed(2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card card-custom h-100 bg-white">
                        <div class="card-header bg-success text-white fw-bold"><i class="fas fa-coins me-2"></i> Rentabilidad Estimada</div>
                        <div class="card-body text-center d-flex flex-column justify-content-center">
                            <h1 class="display-4 fw-bold text-success mb-0">{{ calculateMargin(selectedRecipe) }}%</h1>
                            <p class="text-muted text-uppercase small letter-spacing-1">Margen Bruto</p>
                            <hr class="w-50 mx-auto my-3">
                            <div class="d-flex justify-content-between px-4 mb-2">
                                <span>Precio Venta:</span> 
                                <strong class="text-dark">${{ parseFloat(selectedRecipe.precio_venta).toFixed(2) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between px-4">
                                <span>Costo Unitario:</span> 
                                <strong class="text-danger">${{ parseFloat(selectedRecipe.costo_unitario).toFixed(2) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isEditing">
            <div class="card card-custom border-top border-4 border-primary shadow-sm">
                <div class="card-body p-4">
                    
                    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                        <h4 class="fw-bold m-0 text-primary">{{ form.id ? 'Editar Receta' : 'Crear Nueva Fórmula' }}</h4>
                        <button class="btn btn-outline-secondary btn-sm" @click="cancelEdit"><i class="fas fa-times me-1"></i> Cerrar Editor</button>
                    </div>

                    <div class="row g-5">
                        <div class="col-lg-4 border-end">
                            <h6 class="text-uppercase text-muted fw-bold small mb-3">Datos Generales</h6>
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Nombre de la Receta</label>
                                <input type="text" class="form-control form-control-lg" v-model="form.nombre_receta" placeholder="Ej: Pizza Familiar">
                            </div>

                            <div class="mb-4 position-relative">
                                <label class="form-label fw-bold text-success">Producto Final (Output)</label>
                                
                                <div class="input-group" v-if="!form.id_producto_final">
                                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" v-model="searchFinal" @input="filterFinales" placeholder="Buscar producto terminado..." autocomplete="off">
                                </div>
                                
                                <ul class="search-list w-100" v-if="filteredFinales.length > 0 && !form.id_producto_final">
                                    <li class="search-item" v-for="p in filteredFinales" @click="selectFinal(p)">
                                        <div class="fw-bold">{{ p.nombre }}</div>
                                        <small class="text-muted">{{ p.unidad_medida }}</small>
                                    </li>
                                </ul>

                                <div v-if="form.id_producto_final" class="alert alert-success d-flex justify-content-between align-items-center p-3 mb-0 shadow-sm">
                                    <div>
                                        <small class="text-uppercase text-muted" style="font-size:0.7rem;">Seleccionado:</small><br>
                                        <strong class="fs-5">{{ selectedFinalName }}</strong>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger bg-white border-0 rounded-circle" @click="clearFinal" title="Cambiar producto"><i class="fas fa-times"></i></button>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <label class="form-label fw-bold">Rendimiento (Und)</label>
                                    <input type="number" class="form-control text-center fw-bold border-success fs-5" v-model.number="form.unidades" step="0.01">
                                    <div class="form-text small">Unidades por lote.</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-bold text-muted">Costo Unitario</label>
                                    <input type="text" class="form-control text-center bg-light fs-5" :value="'$' + formCostoUnit.toFixed(2)" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Instrucciones</label>
                                <textarea class="form-control" rows="5" v-model="form.descripcion" placeholder="Pasos de preparación..."></textarea>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-uppercase text-muted fw-bold small mb-0">Composición del Lote</h6>
                                <div class="bg-light px-3 py-2 rounded border">
                                    Costo Total Lote: <strong class="text-primary fs-5 ms-2">${{ formCostoLote.toFixed(2) }}</strong>
                                </div>
                            </div>

                            <div class="card bg-primary bg-opacity-10 border-0 p-3 mb-4">
                                <div class="d-flex gap-3 position-relative">
                                    <div class="flex-grow-1">
                                        <input type="text" class="form-control form-control-lg border-0 shadow-sm" v-model="searchIng" @input="filterIngs" placeholder="🔍 Buscar y agregar ingrediente..." id="ingSearch" autocomplete="off">
                                        
                                        <ul class="search-list w-100 shadow-lg" v-if="filteredIngs.length > 0" style="top: 100%;">
                                            <li class="search-item py-2" v-for="i in filteredIngs" @click="selectIng(i)">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="fw-bold">{{ i.nombre }}</span>
                                                    <span class="badge bg-light text-dark border">${{ i.costo }} / {{i.unidad_medida}}</span>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                    <div style="width: 120px;">
                                        <input type="number" class="form-control form-control-lg border-0 shadow-sm text-center" v-model.number="ingCant" placeholder="Cant" step="0.001">
                                    </div>
                                    <button class="btn btn-primary btn-lg shadow-sm px-4" @click="addIngrediente" :disabled="!tempIng">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive border rounded bg-white shadow-sm" style="min-height: 300px;">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <thead class="bg-dark text-white small text-uppercase">
                                        <tr>
                                            <th style="width: 45%;" class="ps-3">Ingrediente</th>
                                            <th style="width: 20%; text-align: center;">Cantidad</th>
                                            <th style="width: 15%; text-align: right;">Costo U.</th>
                                            <th style="width: 15%; text-align: right;">Subtotal</th>
                                            <th style="width: 5%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(ing, idx) in form.ingredientes" :key="idx">
                                            <td class="ps-3">
                                                <div class="fw-bold text-dark">{{ ing.nombre }}</div>
                                                <small class="text-muted font-monospace" style="font-size: 0.75rem;">{{ ing.id }}</small>
                                            </td>
                                            <td class="text-center p-1">
                                                <input type="number" class="input-qty fs-6" v-model.number="ing.cant" step="0.001">
                                                <small class="text-muted" style="font-size:0.7rem">{{ing.unidad}}</small>
                                            </td>
                                            <td class="text-end small text-muted">${{ parseFloat(ing.costo_base).toFixed(2) }}</td>
                                            <td class="text-end fw-bold text-primary">${{ (ing.cant * ing.costo_base).toFixed(2) }}</td>
                                            <td class="text-center">
                                                <button class="btn btn-link btn-sm text-danger p-0" @click="form.ingredientes.splice(idx,1)"><i class="fas fa-times-circle fa-lg"></i></button>
                                            </td>
                                        </tr>
                                        <tr v-if="form.ingredientes.length === 0">
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <div class="py-4">
                                                    <i class="fas fa-basket-shopping fa-3x mb-3 opacity-25"></i>
                                                    <h5>Lista de Ingredientes Vacía</h5>
                                                    <p class="mb-0">Usa el buscador azul de arriba para agregar materias primas.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 mt-4 pt-3 border-top">
                        <button class="btn btn-outline-secondary px-4 fw-bold" @click="cancelEdit">CANCELAR</button>
                        <button class="btn btn-success px-5 fw-bold py-2 shadow" @click="saveRecipe">
                            <i class="fas fa-save me-2"></i> GUARDAR CAMBIOS
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <div v-show="currentTab==='history'">
            <div class="card card-custom">
                <div class="card-header bg-white py-3 row g-2 align-items-center">
                    <div class="col-md-3"><input type="date" class="form-control" v-model="historyFilter.start"></div>
                    <div class="col-md-3"><input type="date" class="form-control" v-model="historyFilter.end"></div>
                    <div class="col-md-2"><button class="btn btn-primary w-100 fw-bold" @click="loadHistory"><i class="fas fa-search me-1"></i> Filtrar</button></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="bg-light"><tr><th>Fecha</th><th>Receta</th><th>Lotes</th><th>Unidades</th><th>Costo</th><th>Estado</th><th class="text-end"></th></tr></thead>
                        <tbody>
                            <tr v-for="h in history" :class="{'table-danger': h.revertido==1}">
                                <td>{{ h.fecha }}</td><td class="fw-bold">{{ h.nombre_receta }}</td>
                                <td class="text-center">{{ parseFloat(h.cantidad_lotes) }}</td>
                                <td class="text-center text-success fw-bold">+{{ parseFloat(h.unidades_creadas) }}</td>
                                <td class="text-end text-muted">${{ parseFloat(h.costo_total).toFixed(2) }}</td>
                                <td class="text-center"><span v-if="h.revertido==1" class="badge bg-danger">REVERTIDO</span><span v-else class="badge bg-success">OK</span></td>
                                <td class="text-end"><button v-if="h.revertido==0" class="btn btn-sm btn-outline-danger" @click="revertProduction(h)" title="Revertir Lote"><i class="fas fa-undo"></i></button></td>
                            </tr>
                            <tr v-if="history.length===0"><td colspan="7" class="text-center py-5 text-muted">No hay movimientos en este periodo.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-show="currentTab==='reports'">
            <div class="row g-4" v-if="reports">
                <div class="col-md-4"><div class="card card-custom h-100 border-start border-4 border-success"><div class="card-body"><h6 class="fw-bold text-success mb-3">🏆 Top Rentabilidad</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="r in reports.rentabilidad"><span>{{r.nombre_receta}}</span> <span class="badge bg-success">{{r.margen}}%</span></li></ul></div></div></div>
                <div class="col-md-4"><div class="card card-custom h-100 border-start border-4 border-primary"><div class="card-body"><h6 class="fw-bold text-primary mb-3">🏭 Top Volumen</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="p in reports.volumen"><span>{{p.nombre_receta}}</span> <strong>{{parseFloat(p.total)}} u</strong></li></ul></div></div></div>
                <div class="col-md-4"><div class="card card-custom h-100 border-start border-4 border-warning"><div class="card-body"><h6 class="fw-bold text-warning mb-3">📦 Insumos Clave</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="i in reports.insumos"><span>{{i.nombre}}</span> <span class="text-muted">{{i.freq}} recetas</span></li></ul></div></div></div>
                <div class="col-md-4"><div class="card card-custom h-100 border-start border-4 border-danger"><div class="card-body"><h6 class="fw-bold text-danger mb-3">⚠️ Alerta Pérdidas (Costo > Precio)</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="x in reports.perdidas"><span>{{x.nombre_receta}}</span> <span class="text-danger fw-bold">Pérdida</span></li><li v-if="reports.perdidas.length===0" class="text-muted text-center py-3">¡Excelente! Todo es rentable.</li></ul></div></div></div>
                <div class="col-md-4"><div class="card card-custom h-100 border-start border-4 border-info"><div class="card-body"><h6 class="fw-bold text-info mb-3">💰 Valor Inventario (Elaborado)</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="v in reports.valor_inventario"><span>{{v.categoria || 'Sin Cat.'}}</span> <strong>${{parseFloat(v.valor_total).toFixed(2)}}</strong></li></ul></div></div></div>
                <div class="col-md-4"><div class="card card-custom h-100"><div class="card-body"><h6 class="fw-bold text-muted mb-3">📅 Producción Reciente</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="d in reports.recent"><span>{{d.dia}}</span> <span>{{d.lotes}} lotes (${{parseFloat(d.costo).toFixed(0)}})</span></li></ul></div></div></div>
            </div>
        </div>

    </div>

    <div class="modal fade" id="analyzeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0">
                <div class="modal-header bg-info text-white"><h5 class="modal-title fw-bold">Simulador de Producción</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center p-4">
                    <div class="input-group justify-content-center mb-4 shadow-sm"><span class="input-group-text bg-white fw-bold">Quiero producir</span><input type="number" class="form-control text-center fs-4 fw-bold text-primary" style="max-width:120px" v-model.number="analyzeLots" min="1"><span class="input-group-text bg-white fw-bold">lotes</span><button class="btn btn-info text-white fw-bold px-4" @click="runAnalysis">CALCULAR</button></div>
                    <div v-if="analysisResult">
                        <div class="card bg-light border-0 mb-4 py-3">
                            <small class="text-muted text-uppercase fw-bold">Capacidad Máxima (Stock Actual)</small>
                            <h1 class="display-3 fw-bold text-primary mb-0">{{ analysisResult.max_lotes }} <span class="fs-5 text-muted">lotes</span></h1>
                        </div>
                        <div v-if="analysisResult.faltantes.length > 0" class="text-start">
                            <h6 class="text-danger fw-bold border-bottom pb-2 mb-3">⚠️ Ingredientes Faltantes para {{analyzeLots}} lotes</h6>
                            <table class="table table-sm table-striped"><thead><tr><th>Insumo</th><th>Requerido</th><th>Disponible</th><th>Falta</th></tr></thead><tbody><tr v-for="f in analysisResult.faltantes"><td>{{f.nombre}}</td><td>{{parseFloat(f.req)}}</td><td>{{parseFloat(f.stock)}}</td><td class="fw-bold text-danger">{{parseFloat(f.falta)}} {{f.unidad}}</td></tr></tbody></table>
                        </div>
                        <div v-else class="alert alert-success fw-bold"><i class="fas fa-check-circle me-2"></i> ¡Stock suficiente para producir {{analyzeLots}} lotes!</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    const allFinales = <?php echo json_encode($productosFinales); ?>;
    const allInsumos = <?php echo json_encode($insumos); ?>;
    const recetasInit = <?php echo json_encode($recetas); ?>;

    new Vue({
        el: '#app',
        data: {
            currentTab: 'master', isEditing: false,
            recetas: recetasInit, selectedRecipe: null, details: [], history: [], reports: null,
            historyFilter: { start: '<?php echo date('Y-m-d', strtotime('-7 days')); ?>', end: '<?php echo date('Y-m-d'); ?>' },
            form: { id: null, nombre_receta: '', id_producto_final: '', unidades: 1, descripcion: '', ingredientes: [] },
            searchFinal: '', filteredFinales: [], selectedFinalName: '',
            searchIng: '', filteredIngs: [], tempIng: null, ingCant: 1,
            modalAnalyze: null, analyzeLots: 1, analysisResult: null,
            // Selección múltiple
            selectedRecipes: [],
        },
        computed: {
            formCostoLote() { 
                if (!this.form.ingredientes) return 0;
                return this.form.ingredientes.reduce((a, i) => a + (i.cant * i.costo_base), 0); 
            },
            formCostoUnit() { return this.form.unidades > 0 ? this.formCostoLote / this.form.unidades : 0; }
        },
        mounted() {
            this.modalAnalyze = new bootstrap.Modal(document.getElementById('analyzeModal'));
        },
        methods: {
            async api(action, body = {}) {
                try {
                    const res = await fetch(`production_api.php?action=${action}`, { method: 'POST', body: JSON.stringify(body) });
                    const txt = await res.text();
                    try { return JSON.parse(txt); } catch(e) { console.error("API Error (Raw):", txt); alert("Error en servidor. Ver consola."); return []; }
                } catch(e) { alert("Error de red"); return []; }
            },
            
            // --- NUEVO: GESTIÓN DE SELECCIÓN MÚLTIPLE ---
            toggleSelectAll() {
                if (this.selectedRecipes.length === this.recetas.length) {
                    this.selectedRecipes = [];
                } else {
                    this.selectedRecipes = this.recetas.map(r => r.id);
                }
            },
            printConsolidatedReport() {
                if (this.selectedRecipes.length === 0) return;
                const ids = this.selectedRecipes.join(',');
                window.open(`pos_production_consolidated_report.php?ids=${ids}`, '_blank', 'width=1000,height=800');
            },
            // ----------------------------------------------

            selectRecipe(r) {
                this.selectedRecipe = r;
                this.api('get_details', {id: r.id}).then(d => this.details = d);
            },

            // --- GESTOR DE EDICIÓN ---
            startCreate() {
                this.resetForm();
                this.isEditing = true;
                this.currentTab = 'master'; 
            },
            startEdit(r) {
                this.resetForm();
                this.isEditing = true;
                
                const pf = allFinales.find(p => p.codigo == r.id_producto_final);
                this.selectedFinalName = pf ? pf.nombre : r.id_producto_final;
                
                this.form = { 
                    ...r, 
                    id_producto_final: r.id_producto_final, 
                    unidades: parseFloat(r.unidades_resultantes),
                    ingredientes: [] 
                };
                this.form.id = r.id; 

                this.api('get_details', {id: r.id}).then(d => {
                    this.form.ingredientes = d.map(x => ({
                        id: x.id_ingrediente,
                        nombre: x.nombre,
                        cant: parseFloat(x.cantidad), 
                        costo_base: parseFloat(x.costo_actual || 0),
                        unidad: x.unidad_medida
                    }));
                });
            },
            cancelEdit() {
                this.isEditing = false;
                this.resetForm();
            },
            saveRecipe() {
                const payload = {
                    ...this.form,
                    costo_lote: this.formCostoLote,
                    costo_unitario: this.formCostoUnit,
                    ingredientes: this.form.ingredientes.map(i => ({
                        id: i.id,
                        cant: i.cant,
                        costo_total: i.cant * i.costo_base
                    }))
                };
                
                this.api('save_recipe', payload).then(d => {
                    if(d.status === 'success') location.reload(); else alert('Error al guardar: ' + d.msg);
                });
            },

            resetForm() { this.form = { id: null, nombre_receta: '', id_producto_final: '', unidades: 1, descripcion: '', ingredientes: [] }; this.searchFinal = ''; this.selectedFinalName = ''; },
            
            filterFinales() { const q=this.searchFinal.toLowerCase(); this.filteredFinales=allFinales.filter(p=>p.nombre.toLowerCase().includes(q)).slice(0,10); },
            selectFinal(p) { this.form.id_producto_final=p.codigo; this.selectedFinalName=p.nombre; this.filteredFinales=[]; this.searchFinal=''; },
            clearFinal() { this.form.id_producto_final=''; this.selectedFinalName=''; },
            
            filterIngs() { const q=this.searchIng.toLowerCase(); this.filteredIngs=allInsumos.filter(p=>p.nombre.toLowerCase().includes(q)).slice(0,10); },
            selectIng(p) { this.tempIng=p; this.searchIng=p.nombre; this.filteredIngs=[]; document.getElementById('ingSearch').focus(); },
            addIngrediente() { 
                if(this.tempIng) { 
                    this.form.ingredientes.push({
                        id: this.tempIng.codigo, 
                        nombre: this.tempIng.nombre, 
                        cant: this.ingCant, 
                        costo_base: parseFloat(this.tempIng.costo),
                        unidad: this.tempIng.unidad_medida
                    }); 
                    this.searchIng=''; this.tempIng=null; 
                } 
            },

            cloneRecipe(r) { if(confirm("¿Clonar esta receta?")) this.api('clone_recipe', {id: r.id}).then(() => location.reload()); },
            deleteRecipe(r) { if(confirm("¿Borrar permanentemente?")) this.api('delete_recipe', {id: r.id}).then(() => location.reload()); },
            confirmProduce() { const l = prompt("¿Cuántos lotes deseas producir?", "1"); if(l && confirm("¿Confirmar producción? Se descontará inventario.")) this.api('produce_batch', {id: this.selectedRecipe.id, lotes: l}).then(() => location.reload()); },
            revertProduction(h) { if(confirm("¿Revertir esta producción? Se devolverán los insumos.")) this.api('revert_production', {id: h.id}).then(() => this.loadHistory()); },
            runAnalysis() { this.api('analyze_production', {id: this.selectedRecipe.id, lotes: this.analyzeLots}).then(d => { this.analysisResult = d; }); },
            openAnalyzeModal() { this.analyzeLots = 1; this.analysisResult = null; this.modalAnalyze.show(); },
            exportCSV() {
                let csv = "\uFEFFReceta;Producto Final;Unidades Lote;Ingrediente;Cantidad Req;Costo Unit\n";
                this.recetas.forEach(r => csv += `${r.nombre_receta};${r.nombre_producto_final};${r.unidades_resultantes};-;-;-\n`);
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = "recetas_produccion.csv"; link.click();
            },
            printReport(id) { window.open(`pos_production_report.php?id=${id}`, '_blank', 'width=900,height=800'); },
            loadHistory() { this.currentTab = 'history'; this.isEditing=false; this.api('get_history', this.historyFilter).then(d => this.history = d); },
            loadReports() { this.currentTab = 'reports'; this.isEditing=false; this.api('get_reports').then(d => this.reports = d); },
            calculateMargin(r) { const p = parseFloat(r.precio_venta), c = parseFloat(r.costo_unitario); return (p>0) ? (((p-c)/p)*100).toFixed(1) : 0; }
        }
    });
</script>


<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>

