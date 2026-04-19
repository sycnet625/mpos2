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
                            COALESCE(p.precio, 0) as precio_venta,
                            COALESCE((
                                SELECT GROUP_CONCAT(TRIM(COALESCE(pp.nombre, 'Sin nombre')) ORDER BY pp.nombre SEPARATOR '|')
                                FROM recetas_detalle rd
                                JOIN productos pp ON rd.id_ingrediente = pp.codigo
                                WHERE rd.id_receta = r.id
                            ), '') as ingredientes_texto
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
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <script src="assets/js/vue.min.js"></script>
    <style>
        .table thead th { white-space: nowrap; }
        .card-custom { border: none; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .card-custom:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .table-hover tbody tr:hover { background-color: rgba(59, 130, 246, 0.1); cursor: pointer; }
        .selected-row { background-color: rgba(59, 130, 246, 0.2) !important; border-left: 4px solid #3b82f6; }
        .nav-tabs .nav-link { color: #64748b; font-weight: 500; border: none; border-bottom: 3px solid transparent; }
        .nav-tabs .nav-link:hover { color: #3b82f6; }
        .nav-tabs .nav-link.active { color: #3b82f6; border-bottom: 3px solid #3b82f6; background: transparent; font-weight: bold; }
        .search-list { position: absolute; width: 100%; max-height: 250px; overflow-y: auto; z-index: 2000; background: white; border: 1px solid #e2e8f0; list-style: none; padding: 0; border-radius: 0 0 8px 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .search-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .search-item:hover { background-color: #f8fafc; color: #3b82f6; }
        .input-qty { border: 1px solid transparent; background: transparent; text-align: center; font-weight: bold; width: 100%; border-radius: 4px; }
        .input-qty:focus { background: white; border-color: #3b82f6; outline: none; }
        .input-qty:hover { background: rgba(255,255,255,0.5); border-color: #e2e8f0; }
        [v-cloak] { display: none; }
        .inventory-hero {
            background: linear-gradient(135deg, <?php echo $config['hero_color_1'] ?? '#0f766e'; ?>ee, <?php echo $config['hero_color_2'] ?? '#15803d'; ?>c6) !important;
        }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5" id="app" v-cloak>

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4 inventory-fade-in">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Producción / Recetas</div>
                <?php if (!empty($config['hero_mostrar_usuario']) && !empty($_SESSION['admin_user_name'])): ?>
                    <div class="badge bg-white bg-opacity-10 text-white mb-2" style="font-size:0.7rem; border:1px solid rgba(255,255,255,0.2);">
                        <i class="fas fa-user-circle me-1"></i> Sesión: <?php echo htmlspecialchars($_SESSION['admin_user_name']); ?>
                    </div>
                <?php endif; ?>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-industry me-2"></i>Centro de Producción</h1>
                <p class="mb-3 text-white-50">Gestión de recetas, fórmulas, producción y análisis de insumos.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-flask me-1"></i><?php echo count($recetas); ?> recetas</span>
                    <span class="kpi-chip"><i class="fas fa-boxes me-1"></i><?php echo count($productosFinales); ?> elaborados</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="importar_recetas_palweb_ok.php" class="btn btn-light" target="_blank"><i class="fas fa-file-excel me-1"></i>Importar</a>
                <a href="dashboard.php" class="btn btn-outline-light"><i class="fas fa-home me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <ul class="nav nav-tabs mb-4 inventory-tablist d-inline-flex">
        <li class="nav-item">
            <a class="nav-link px-4 py-2 fw-bold" :class="{active: currentTab==='master' && !isEditing}" @click="currentTab='master'; isEditing=false">
                <i class="fas fa-flask me-2"></i> Recetas y Fórmulas
            </a>
        </li>
        <li class="nav-item" v-if="isEditing">
            <a class="nav-link active px-4 py-2 fw-bold text-primary">
                <i class="fas fa-edit me-2"></i> {{ form.id ? 'Editando: ' + form.nombre_receta : 'Nueva Receta' }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-4 py-2 fw-bold" :class="{active: currentTab==='history'}" @click="loadHistory">
                <i class="fas fa-history me-2"></i> Historial Producción
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link px-4 py-2 fw-bold" :class="{active: currentTab==='reports'}" @click="loadReports">
                <i class="fas fa-chart-pie me-2"></i> Informes BI
            </a>
        </li>
    </ul>

    <div v-show="currentTab==='master' && !isEditing">
        <div class="row">
            <div class="col-12 mb-4">
                <div class="glass-card inventory-fade-in">
                    <div class="card-header bg-transparent fw-bold d-flex justify-content-between align-items-center py-3">
                        <span><i class="fas fa-list text-primary me-2"></i> Recetas Configuradas</span>
                        <div>
                            <button class="btn btn-warning btn-sm fw-bold me-2" v-if="selectedRecipes.length > 0" @click="printConsolidatedReport">
                                <i class="fas fa-clipboard-list me-1"></i> Planif. Insumos ({{selectedRecipes.length}})
                            </button>
                            <button class="btn btn-primary btn-sm fw-bold me-2" @click="startCreate"><i class="fas fa-plus me-1"></i> Nueva Receta</button>
                            <button class="btn btn-outline-success btn-sm" @click="exportCSV"><i class="fas fa-file-excel me-1"></i> Excel</button>
                        </div>
                    </div>
                    <div class="card-body border-top">
                        <div class="row g-2">
                            <div class="col-lg-6">
                                <input type="text" class="form-control form-control-sm" v-model="searchRecipeByName" placeholder="🔍 Buscar por nombre de receta">
                            </div>
                            <div class="col-lg-6">
                                <input type="text" class="form-control form-control-sm" v-model="searchRecipeByIngredient" placeholder="🔍 Filtrar por producto dentro de la receta">
                            </div>
                        </div>
                        <div class="mt-2 d-flex flex-wrap align-items-center gap-2" v-if="hasRecipeFilters">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Filtros activos</span>
                            <button class="btn btn-outline-secondary btn-sm" @click="clearRecipeFilters">Limpiar filtros</button>
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 450px;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light sticky-top shadow-sm">
                                <tr>
                                    <th style="width: 40px;" class="text-center">
                                        <input type="checkbox" class="form-check-input cursor-pointer" 
                                               :checked="filteredRecetas.length > 0 && allFilteredRecipesSelected" 
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
                                <tr v-for="r in filteredRecetas" :key="r.id" :class="{'selected-row': selectedRecipe && selectedRecipe.id === r.id}" @click="selectRecipe(r)">
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
                                <tr v-if="filteredRecetas.length === 0">
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <template v-if="searchRecipeByName || searchRecipeByIngredient">
                                            No hay recetas que coincidan con los filtros.
                                        </template>
                                        <template v-else>
                                            No hay recetas. Crea una nueva.
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4" v-if="selectedRecipe">
            <div class="col-lg-8">
                <div class="glass-card h-100 inventory-fade-in">
                    <div class="card-header bg-transparent fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-list text-warning me-2"></i> Ingredientes del Lote</span>
                                 <div>
                                     <button class="btn btn-info btn-sm text-white fw-bold me-2" @click="openAnalyzeModal"><i class="fas fa-calculator me-1"></i> Analizar Capacidad</button>
                                     <button class="btn btn-primary btn-sm fw-bold shadow-sm" @click="confirmProduce"><i class="fas fa-cogs me-1"></i> PRODUCIR</button>
                                     <button class="btn btn-dark btn-sm ms-2" @click="printReport(selectedRecipe.id)"><i class="fas fa-print"></i></button>
                                     <button class="btn btn-success btn-sm ms-2" @click="generateCostSheet(selectedRecipe.id)"><i class="fas fa-file-invoice-dollar me-1"></i> Ficha de Costo</button>
                                 </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light text-muted small">
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
                <div class="glass-card h-100 inventory-fade-in border-start border-4 border-success">
                    <div class="card-header bg-transparent fw-bold text-success"><i class="fas fa-coins me-2"></i> Rentabilidad Estimada</div>
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

        <div class="row mt-4" v-if="pendingReservations.length > 0">
            <div class="col-12">
                <div class="glass-card border-top border-4 border-warning inventory-fade-in">
                    <div class="card-header bg-transparent fw-bold d-flex justify-content-between align-items-center py-3">
                        <span><i class="fas fa-calendar-check text-warning me-2"></i> Reservas Pendientes — {{ selectedRecipe.nombre_producto_final }}</span>
                        <span class="badge bg-warning text-dark fs-6">{{ pendingReservations.length }} reserva{{ pendingReservations.length !== 1 ? 's' : '' }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-3">#Ticket</th>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Fecha Entrega</th>
                                    <th class="text-center">Cant.</th>
                                    <th class="text-center">Origen</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-end pe-3">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in pendingReservations" :key="r.id">
                                    <td class="ps-3">
                                        <a :href="'ticket_view.php?id='+r.id" target="_blank" class="fw-bold text-primary text-decoration-none">
                                            #{{ String(r.id).padStart(5,'0') }}
                                        </a>
                                    </td>
                                    <td class="fw-bold">{{ r.cliente_nombre || 'Sin nombre' }}</td>
                                    <td class="text-muted small">{{ r.cliente_telefono || '-' }}</td>
                                    <td v-html="formatFechaRes(r.fecha_reserva)"></td>
                                    <td class="text-center fw-bold text-warning fs-5">{{ parseFloat(r.cantidad_reservada) }}</td>
                                    <td class="text-center" v-html="canalBadge(r.canal_origen || 'POS')"></td>
                                    <td class="text-center" v-html="estadoBadge(r.estado_reserva)"></td>
                                    <td class="text-end fw-bold pe-3">${{ parseFloat(r.total).toFixed(2) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-warning bg-opacity-10">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold text-muted ps-3 py-2">Total unidades reservadas:</td>
                                    <td class="text-center fw-bold text-warning fs-5 py-2">{{ totalReservado() }}</td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-3" v-else-if="selectedRecipe && pendingReservations !== null">
            <div class="col-12">
                <div class="alert alert-light border d-flex align-items-center gap-2 py-2 mb-0">
                    <i class="fas fa-calendar-check text-muted"></i>
                    <span class="text-muted small">Sin reservas pendientes para <strong>{{ selectedRecipe.nombre_producto_final }}</strong></span>
                </div>
            </div>
        </div>
    </div>

    <div v-if="isEditing">
        <div class="glass-card border-top border-4 border-primary shadow-sm inventory-fade-in">
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
                            <div class="d-flex align-items-center gap-3">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn fw-bold" :class="modoCreacion==='clasico' ? 'btn-dark' : 'btn-outline-dark'" @click="switchMode('clasico')">
                                        <i class="fas fa-list-ol me-1"></i> Clásico
                                    </button>
                                    <button type="button" class="btn fw-bold" :class="modoCreacion==='porcentual' ? 'btn-info text-white' : 'btn-outline-info'" @click="switchMode('porcentual')">
                                        <i class="fas fa-percent me-1"></i> Por %
                                    </button>
                                </div>
                                <div class="bg-light px-3 py-2 rounded border">
                                    Costo Total Lote: <strong class="text-primary fs-5 ms-2">${{ formCostoLote.toFixed(2) }}</strong>
                                </div>
                            </div>
                        </div>

                        <div v-if="modoCreacion==='porcentual'" class="alert alert-info border-info py-2 mb-3 d-flex align-items-center gap-4 flex-wrap">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-flask text-info"></i>
                                <strong class="small text-uppercase">Cantidad Total del Lote:</strong>
                                <input type="number" class="form-control form-control-sm text-center fw-bold" style="width:100px" v-model.number="totalFormula" min="0.001" step="0.001" @change="recalcCantidades">
                                <span class="text-muted small">(unidad base)</span>
                            </div>
                            <div class="ms-auto d-flex align-items-center gap-2">
                                <span class="small fw-bold text-muted">% acumulado:</span>
                                <span class="badge fs-6 px-3 py-2" :class="Math.abs(pctTotal-100)<0.1 ? 'bg-success' : (pctTotal>100 ? 'bg-danger' : 'bg-warning text-dark')">
                                    {{ pctTotal.toFixed(1) }}%
                                </span>
                                <span v-if="Math.abs(pctTotal-100)<0.1" class="text-success small"><i class="fas fa-check-circle"></i> Correcto</span>
                                <span v-else-if="pctTotal>100" class="text-danger small"><i class="fas fa-exclamation-circle"></i> Supera 100%</span>
                                <span v-else class="text-warning small">Faltan {{ (100-pctTotal).toFixed(1) }}%</span>
                            </div>
                        </div>

                        <div class="card bg-primary bg-opacity-10 border-0 p-3 mb-4">
                            <div class="d-flex gap-3 position-relative">
                                <div class="flex-grow-1">
                                    <input type="text" class="form-control form-control-lg border-0 shadow-sm" v-model="searchIng" @input="filterIngs" :placeholder="modoCreacion==='porcentual' ? '🔍 Buscar ingrediente (luego indica su %)...' : '🔍 Buscar y agregar ingrediente...'" id="ingSearch" autocomplete="off">
                                    <ul class="search-list w-100 shadow-lg" v-if="filteredIngs.length > 0" style="top: 100%;">
                                        <li class="search-item py-2" v-for="i in filteredIngs" @click="selectIng(i)">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="fw-bold">{{ i.nombre }}</span>
                                                <span class="badge bg-light text-dark border">${{ i.costo }} / {{i.unidad_medida}}</span>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                                <div v-if="modoCreacion==='clasico'" style="width: 130px;">
                                    <input type="number" class="form-control form-control-lg border-0 shadow-sm text-center" v-model.number="ingCant" placeholder="Cant" step="0.001">
                                </div>
                                <div v-else style="width: 130px;">
                                    <div class="input-group">
                                        <input type="number" class="form-control form-control-lg border-0 shadow-sm text-center fw-bold" v-model.number="ingPct" placeholder="%" step="0.1" min="0" max="100">
                                        <span class="input-group-text bg-white border-0 fw-bold text-info">%</span>
                                    </div>
                                    <div class="text-center" style="font-size:0.7rem; color:#666;" v-if="ingPct>0 && totalFormula>0">
                                        = {{ (totalFormula * ingPct / 100).toFixed(3) }} u
                                    </div>
                                </div>
                                <button class="btn btn-primary btn-lg shadow-sm px-4" @click="addIngrediente" :disabled="!tempIng">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive border rounded bg-white shadow-sm" style="min-height: 300px;">
                            <table class="table table-striped table-hover align-middle mb-0">
                                <thead class="table-dark small text-uppercase">
                                    <tr>
                                        <th style="width: 38%;" class="ps-3">Ingrediente</th>
                                        <th style="width: 18%; text-align: center;">Cantidad</th>
                                        <th style="width: 12%; text-align: center;">% Fórmula</th>
                                        <th style="width: 14%; text-align: right;">Costo U.</th>
                                        <th style="width: 14%; text-align: right;">Subtotal</th>
                                        <th style="width: 4%;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(ing, idx) in form.ingredientes" :key="idx">
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark">{{ ing.nombre }}</div>
                                            <small class="text-muted font-monospace" style="font-size: 0.75rem;">{{ ing.id }}</small>
                                        </td>
                                        <td class="text-center p-1">
                                            <div v-if="modoCreacion==='clasico'">
                                                <input type="number" class="input-qty fs-6" v-model.number="ing.cant" step="0.001">
                                            </div>
                                            <div v-else class="fw-bold text-dark">{{ parseFloat(ing.cant).toFixed(3) }}</div>
                                            <small class="text-muted" style="font-size:0.7rem">{{ing.unidad}}</small>
                                        </td>
                                        <td class="text-center p-1">
                                            <div v-if="modoCreacion==='porcentual'">
                                                <input type="number" class="input-qty fs-6 text-info fw-bold" v-model.number="ing.pct" step="0.1" min="0" @input="ing.cant = totalFormula * (parseFloat(ing.pct)||0) / 100">
                                                <small class="text-info" style="font-size:0.7rem">%</small>
                                            </div>
                                            <div v-else class="text-muted small">
                                                {{ totalCantFormula > 0 ? (ing.cant / totalCantFormula * 100).toFixed(1) : '0.0' }}%
                                            </div>
                                        </td>
                                        <td class="text-end small text-muted">${{ parseFloat(ing.costo_base).toFixed(2) }}</td>
                                        <td class="text-end fw-bold text-primary">${{ (ing.cant * ing.costo_base).toFixed(2) }}</td>
                                        <td class="text-center">
                                            <button class="btn btn-link btn-sm text-danger p-0" @click="form.ingredientes.splice(idx,1)"><i class="fas fa-times-circle fa-lg"></i></button>
                                        </td>
                                    </tr>
                                    <tr v-if="form.ingredientes.length === 0">
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <div class="py-4">
                                                <i class="fas fa-basket-shopping fa-3x mb-3 opacity-25"></i>
                                                <h5>Lista de Ingredientes Vacía</h5>
                                                <p class="mb-0" v-if="modoCreacion==='clasico'">Usa el buscador azul para agregar materias primas con su cantidad.</p>
                                                <p class="mb-0" v-else>Establece la cantidad total del lote y agrega ingredientes con su % en la fórmula.</p>
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
        <div class="glass-card inventory-fade-in">
            <div class="card-header bg-transparent py-3 row g-2 align-items-center">
                <div class="col-md-3"><input type="date" class="form-control" v-model="historyFilter.start"></div>
                <div class="col-md-3"><input type="date" class="form-control" v-model="historyFilter.end"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100 fw-bold" @click="loadHistory"><i class="fas fa-search me-1"></i> Filtrar</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light"><tr><th>Fecha</th><th>Receta</th><th>Lotes</th><th>Unidades</th><th>Costo</th><th>Estado</th><th class="text-end"></th></tr></thead>
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
            <div class="col-md-4"><div class="glass-card h-100 border-start border-4 border-success inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-success mb-3">🏆 Top Rentabilidad</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="r in reports.rentabilidad"><span>{{r.nombre_receta}}</span> <span class="badge bg-success">{{r.margen}}%</span></li></ul></div></div></div>
            <div class="col-md-4"><div class="glass-card h-100 border-start border-4 border-primary inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-primary mb-3">🏭 Top Volumen</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="p in reports.volumen"><span>{{p.nombre_receta}}</span> <strong>{{parseFloat(p.total)}} u</strong></li></ul></div></div></div>
            <div class="col-md-4"><div class="glass-card h-100 border-start border-4 border-warning inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-warning mb-3">📦 Insumos Clave</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="i in reports.insumos"><span>{{i.nombre}}</span> <span class="text-muted">{{i.freq}} recetas</span></li></ul></div></div></div>
            <div class="col-md-4"><div class="glass-card h-100 border-start border-4 border-danger inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-danger mb-3">⚠️ Alerta Pérdidas (Costo > Precio)</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="x in reports.perdidas"><span>{{x.nombre_receta}}</span> <span class="text-danger fw-bold">Pérdida</span></li><li v-if="reports.perdidas.length===0" class="text-muted text-center py-3">¡Excelente! Todo es rentable.</li></ul></div></div></div>
            <div class="col-md-4"><div class="glass-card h-100 border-start border-4 border-info inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-info mb-3">💰 Valor Inventario (Elaborado)</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="v in reports.valor_inventario"><span>{{v.categoria || 'Sin Cat.'}}</span> <strong>${{parseFloat(v.valor_total).toFixed(2)}}</strong></li></ul></div></div></div>
            <div class="col-md-4"><div class="glass-card h-100 inventory-fade-in"><div class="card-body"><h6 class="fw-bold text-muted mb-3">📅 Producción Reciente</h6><ul class="list-group list-group-flush small"><li class="list-group-item d-flex justify-content-between px-0" v-for="d in reports.recent"><span>{{d.dia}}</span> <span>{{d.lotes}} lotes (${{parseFloat(d.costo).toFixed(0)}})</span></li></ul></div></div></div>
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
            recetas: recetasInit, selectedRecipe: null, details: [], pendingReservations: [], history: [], reports: null,
            historyFilter: { start: '<?php echo date('Y-m-d', strtotime('-7 days')); ?>', end: '<?php echo date('Y-m-d'); ?>' },
            form: { id: null, nombre_receta: '', id_producto_final: '', unidades: 1, descripcion: '', ingredientes: [] },
            searchRecipeByName: '',
            searchRecipeByIngredient: '',
            searchFinal: '', filteredFinales: [], selectedFinalName: '',
            searchIng: '', filteredIngs: [], tempIng: null, ingCant: 1,
            modoCreacion: 'clasico',
            totalFormula: 1000,
            ingPct: 0,
            modalAnalyze: null, analyzeLots: 1, analysisResult: null,
            selectedRecipes: [],
        },
        computed: {
            filteredRecetas() {
                const queryNombre = this.normalizeText(this.searchRecipeByName).trim();
                const queryIngrediente = this.normalizeText(this.searchRecipeByIngredient).trim();
                if (!queryNombre && !queryIngrediente) return this.recetas;
                return this.recetas.filter(r => {
                    const nombre = this.normalizeText(r.nombre_receta);
                    const ingredientes = this.normalizeText(r.ingredientes_texto || '');
                    if (queryNombre && nombre.indexOf(queryNombre) === -1) return false;
                    if (queryIngrediente && ingredientes.indexOf(queryIngrediente) === -1) return false;
                    return true;
                });
            },
            allFilteredRecipesSelected() {
                const selected = this.selectedRecipes.map(id => String(id));
                return this.filteredRecetas.length > 0 && this.filteredRecetas.every(r => selected.includes(String(r.id)));
            },
            hasRecipeFilters() {
                return this.searchRecipeByName.trim() !== '' || this.searchRecipeByIngredient.trim() !== '';
            },
            formCostoLote() { 
                if (!this.form.ingredientes) return 0;
                return this.form.ingredientes.reduce((a, i) => a + (i.cant * i.costo_base), 0); 
            },
            formCostoUnit() { return this.form.unidades > 0 ? this.formCostoLote / this.form.unidades : 0; },
            totalCantFormula() { return this.form.ingredientes.reduce((a, i) => a + (parseFloat(i.cant) || 0), 0); },
            pctTotal() { return this.form.ingredientes.reduce((a, i) => a + (parseFloat(i.pct) || 0), 0); }
        },
        mounted() {
            this.modalAnalyze = new bootstrap.Modal(document.getElementById('analyzeModal'));
        },
        watch: {
            totalFormula(val) {
                if (this.modoCreacion === 'porcentual') {
                    this.form.ingredientes.forEach(ing => {
                        ing.cant = parseFloat(val) * (parseFloat(ing.pct) || 0) / 100;
                    });
                }
            }
        },
        methods: {
            normalizeText(v) {
                return (v || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            },
            clearRecipeFilters() {
                this.searchRecipeByName = '';
                this.searchRecipeByIngredient = '';
            },
            async api(action, body = {}) {
                try {
                    const res = await fetch(`production_api.php?action=${action}`, { method: 'POST', body: JSON.stringify(body) });
                    const txt = await res.text();
                    try { return JSON.parse(txt); } catch(e) { console.error("API Error (Raw):", txt); alert("Error en servidor. Ver consola."); return []; }
                } catch(e) { alert("Error de red"); return []; }
            },
            toggleSelectAll() {
                const filteredIds = this.filteredRecetas.map(r => r.id);
                if (filteredIds.length === 0) return;
                const allSelected = this.allFilteredRecipesSelected;
                if (allSelected) {
                    this.selectedRecipes = this.selectedRecipes.filter(id => filteredIds.indexOf(Number(id)) === -1);
                    return;
                }
                const nextSelection = new Set(this.selectedRecipes);
                filteredIds.forEach(id => nextSelection.add(id));
                this.selectedRecipes = Array.from(nextSelection);
            },
            printConsolidatedReport() {
                if (this.selectedRecipes.length === 0) return;
                const ids = this.selectedRecipes.join(',');
                window.open(`pos_production_consolidated_report.php?ids=${ids}`, '_blank', 'width=1000,height=800');
            },
            selectRecipe(r) {
                this.selectedRecipe = r;
                this.pendingReservations = [];
                this.api('get_details', {id: r.id}).then(d => this.details = d);
                if (r.id_producto_final) {
                    this.api('get_pending_reservations', {id_producto: r.id_producto_final})
                        .then(d => this.pendingReservations = Array.isArray(d) ? d : []);
                }
            },
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
                this.form = { ...r, id_producto_final: r.id_producto_final, unidades: parseFloat(r.unidades_resultantes), ingredientes: [] };
                this.form.id = r.id; 
                this.api('get_details', {id: r.id}).then(d => {
                    const hasPct = d.some(x => parseFloat(x.pct_formula) > 0);
                    this.modoCreacion = hasPct ? 'porcentual' : 'clasico';
                    if (hasPct) {
                        const anchor = d.find(x => parseFloat(x.pct_formula) > 0 && parseFloat(x.cantidad) > 0);
                        if (anchor) {
                            this.totalFormula = parseFloat((parseFloat(anchor.cantidad) / (parseFloat(anchor.pct_formula) / 100)).toFixed(3));
                        }
                    }
                    this.form.ingredientes = d.map(x => ({
                        id: x.id_ingrediente,
                        nombre: x.nombre,
                        cant: parseFloat(x.cantidad),
                        costo_base: parseFloat(x.costo_actual || 0),
                        unidad: x.unidad_medida,
                        pct: parseFloat(x.pct_formula || 0)
                    }));
                });
            },
            cancelEdit() {
                this.isEditing = false;
                this.resetForm();
            },
            saveRecipe() {
                const costoTotal = this.formCostoLote;
                const payload = {
                    ...this.form,
                    costo_lote: costoTotal,
                    costo_unitario: this.formCostoUnit,
                    ingredientes: this.form.ingredientes.map(i => {
                        const totalCant = this.totalCantFormula;
                        const pctFormula = this.modoCreacion === 'porcentual'
                            ? (parseFloat(i.pct) || 0)
                            : (totalCant > 0 ? parseFloat(((i.cant / totalCant) * 100).toFixed(2)) : 0);
                        return { id: i.id, cant: i.cant, costo_total: i.cant * i.costo_base, pct_formula: pctFormula };
                    })
                };
                this.api('save_recipe', payload).then(d => {
                    if(d.status === 'success') location.reload(); else alert('Error al guardar: ' + d.msg);
                });
            },
            resetForm() {
                this.form = { id: null, nombre_receta: '', id_producto_final: '', unidades: 1, descripcion: '', ingredientes: [] };
                this.searchFinal = ''; this.selectedFinalName = '';
                this.modoCreacion = 'clasico'; this.totalFormula = 1000; this.ingPct = 0;
            },
            switchMode(mode) {
                if (mode === this.modoCreacion) return;
                if (mode === 'porcentual' && this.form.ingredientes.length > 0) {
                    const n = this.form.ingredientes.length;
                    const pctCada = parseFloat((100 / n).toFixed(2));
                    this.form.ingredientes.forEach(ing => { ing.pct = pctCada; });
                }
                if (mode === 'clasico') {
                    this.form.ingredientes.forEach(ing => { ing.pct = 0; });
                }
                this.modoCreacion = mode;
            },
            recalcCantidades() {
                if (this.modoCreacion === 'porcentual') {
                    this.form.ingredientes.forEach(ing => {
                        ing.cant = parseFloat(this.totalFormula) * (parseFloat(ing.pct) || 0) / 100;
                    });
                }
            },
            filterFinales() { const q=this.searchFinal.toLowerCase(); this.filteredFinales=allFinales.filter(p=>p.nombre.toLowerCase().includes(q)).slice(0,10); },
            selectFinal(p) { this.form.id_producto_final=p.codigo; this.selectedFinalName=p.nombre; this.filteredFinales=[]; this.searchFinal=''; },
            clearFinal() { this.form.id_producto_final=''; this.selectedFinalName=''; },
            filterIngs() { const q=this.searchIng.toLowerCase(); this.filteredIngs=allInsumos.filter(p=>p.nombre.toLowerCase().includes(q)).slice(0,10); },
            selectIng(p) { this.tempIng=p; this.searchIng=p.nombre; this.filteredIngs=[]; document.getElementById('ingSearch').focus(); },
            addIngrediente() {
                if (!this.tempIng) return;
                let cant, pct;
                if (this.modoCreacion === 'porcentual') {
                    pct  = parseFloat(this.ingPct) || 0;
                    cant = parseFloat(this.totalFormula) * pct / 100;
                } else {
                    pct  = 0;
                    cant = this.ingCant;
                }
                this.form.ingredientes.push({
                    id: this.tempIng.codigo,
                    nombre: this.tempIng.nombre,
                    cant: cant,
                    costo_base: parseFloat(this.tempIng.costo),
                    unidad: this.tempIng.unidad_medida,
                    pct: pct
                });
                this.searchIng = ''; this.tempIng = null; this.ingPct = 0;
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
            generateCostSheet(id) {
                const r = this.selectedRecipe;
                const d = this.details;
                if (!r || !d || d.length === 0) { alert('Seleccione una receta con ingredientes.'); return; }

                const empresa  = <?php echo json_encode($config['nombre_empresa'] ?? $config['shop_name'] ?? ''); ?>;
                const today    = new Date().toLocaleDateString('es-ES', {day:'2-digit', month:'2-digit', year:'numeric'});
                const unidades = parseFloat(r.unidades_resultantes) || 1;
                const precioVenta = parseFloat(r.precio_venta) || 0;

                // Fila 1.1 — Insumos (materias primas): suma de ingredientes de la receta
                const costoInsumos = d.reduce((a, i) => a + (parseFloat(i.cantidad) * parseFloat(i.costo_actual)), 0);
                // Fila 1 = 1.1 + 1.2 + 1.3 + 1.4 (1.2/1.3/1.4 vacíos → igual a 1.1)
                const costoMatPrima = costoInsumos;
                // Fila 5 parcial (solo con lo que tenemos; filas 2,3,4 las completa el usuario)
                const costoTotalParcial = costoMatPrima;
                const costoUnitario = costoTotalParcial / unidades;

                // ── Anexo Desagregación de Insumos ───────────────────────────────────────
                const ingRows = d.map(i => {
                    const cant   = parseFloat(i.cantidad);
                    const precio = parseFloat(i.costo_actual);
                    return `<tr>
                        <td>${i.codigo || ''}</td>
                        <td>${i.nombre || ''}</td>
                        <td class="tc">${i.unidad_medida || 'u'}</td>
                        <td class="tc"></td>
                        <td class="tr">${cant.toFixed(4)}</td>
                        <td class="tr">${precio.toFixed(4)}</td>
                        <td class="tr bold">${(cant * precio).toFixed(2)}</td>
                    </tr>`;
                }).join('');

                const css = `
                    *{margin:0;padding:0;box-sizing:border-box}
                    body{font-family:Arial,sans-serif;font-size:9.5pt;color:#000;background:#fff;padding:14mm 16mm}
                    h1{font-size:11.5pt;text-align:center;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
                    h2{font-size:9.5pt;text-align:center;font-weight:normal;margin-bottom:10px}
                    .page-break{page-break-before:always;padding-top:12mm}
                    table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:6px}
                    th,td{border:1px solid #000;padding:2px 4px;vertical-align:middle}
                    th{background:#d8d8d8;font-weight:bold;text-align:center;white-space:nowrap}
                    .tc{text-align:center}.tr{text-align:right}.bold{font-weight:bold}
                    .head-box{border:1px solid #000;padding:4px 8px;margin-bottom:8px;font-size:9pt;line-height:1.7}
                    .head-box table{margin:0;font-size:9pt} .head-box td{border:none;padding:1px 4px}
                    .row-total td{background:#e4e4e4;font-weight:bold}
                    .row-sub td{background:#f2f2f2;font-weight:bold}
                    .row-indent td:first-child{padding-left:18px}
                    .row-indent2 td:first-child{padding-left:32px}
                    .sign-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:18px;font-size:8.5pt}
                    .sign-cell{border:1px solid #000;padding:4px 8px;line-height:1.6}
                    .sign-cell .line{border-bottom:1px solid #555;min-height:22px;margin-bottom:2px}
                    .note{font-size:7.5pt;color:#444;margin-top:4px;font-style:italic}
                    .res-note{font-size:7pt;text-align:center;color:#555;margin-top:10px}
                    @media print{.print-btn{display:none}body{padding:8mm 10mm}}
                    .print-btn{text-align:center;margin-bottom:12px}
                    .print-btn button{padding:5px 18px;font-size:10pt;cursor:pointer;background:#1a5276;color:#fff;border:none;border-radius:4px}
                `;

                // ── Ficha principal ───────────────────────────────────────────────────────
                const mainFicha = `
                <div style="text-align:center;font-weight:bold;font-size:10pt;border:2px solid #000;padding:4px;margin-bottom:6px">
                    MINISTERIO DE FINANZAS Y PRECIOS<br>
                    <span style="font-size:9pt">FICHA DE COSTOS Y GASTOS DE PRODUCTOS Y SERVICIOS<br>PARA LA EVALUACIÓN DE PRECIOS Y TARIFAS</span>
                </div>

                <div class="head-box">
                    <table style="width:100%">
                        <tr>
                            <td style="width:60%"><b>Producto o Servicio:</b> ${r.nombre_receta}</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><b>Código Prod. o Serv.:</b> ${r.id_producto_final || ''}</td>
                            <td><b>UM:</b> lote (${unidades} unid./lote)</td>
                            <td><b>Nivel de Producción:</b></td>
                            <td><b>% utilización capacidad:</b></td>
                        </tr>
                    </table>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width:58%">CONCEPTOS</th>
                            <th style="width:6%">Fila</th>
                            <th style="width:18%">Costo Base ($)</th>
                            <th style="width:18%">Costo Nuevo ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td class="bold">Gasto Material</td><td class="tc bold">1</td><td class="tr bold">${costoMatPrima.toFixed(2)}</td><td></td></tr>
                        <tr class="row-indent2"><td>De ello: Insumos (Materias primas y materiales)</td><td class="tc">1.1</td><td class="tr">${costoInsumos.toFixed(2)}</td><td></td></tr>
                        <tr class="row-indent2"><td>Combustibles y lubricantes</td><td class="tc">1.2</td><td></td><td></td></tr>
                        <tr class="row-indent2"><td>Energía</td><td class="tc">1.3</td><td></td><td></td></tr>
                        <tr class="row-indent2"><td>Agua</td><td class="tc">1.4</td><td></td><td></td></tr>
                        <tr><td>Salario Directo o retribución directa</td><td class="tc bold">2</td><td></td><td></td></tr>
                        <tr><td>Otros Gastos Directos (Desglosar)</td><td class="tc bold">3</td><td></td><td></td></tr>
                        <tr><td>Gastos asociados a la producción</td><td class="tc bold">4</td><td></td><td></td></tr>
                        <tr class="row-indent2"><td>De ello, salarios</td><td class="tc">4.1</td><td></td><td></td></tr>
                        <tr class="row-total"><td>COSTO TOTAL (1+2+3+4)</td><td class="tc">5</td><td class="tr">${costoTotalParcial.toFixed(2)} *</td><td></td></tr>
                        <tr><td>Gastos Generales y de Administración</td><td class="tc bold">6</td><td></td><td></td></tr>
                        <tr class="row-indent2"><td>De ello, salarios</td><td class="tc">6.1</td><td></td><td></td></tr>
                        <tr><td>Gastos de Distribución y Venta</td><td class="tc bold">7</td><td></td><td></td></tr>
                        <tr class="row-indent2"><td>De ello, salarios</td><td class="tc">7.1</td><td></td><td></td></tr>
                        <tr><td>Gastos Financieros</td><td class="tc bold">8</td><td></td><td></td></tr>
                        <tr><td>Gastos por Financiamiento entregado a la OSDE</td><td class="tc bold">9</td><td></td><td></td></tr>
                        <tr><td style="line-height:1.3">Gastos Tributarios (Contribución a la Seguridad Social e Impuesto sobre la Utilización de la Fuerza de Trabajo. Otros autorizados)</td><td class="tc bold">10</td><td></td><td></td></tr>
                        <tr class="row-sub"><td>TOTAL DE GASTOS (suma de las filas 6, 7, 8, 9 y 10)</td><td class="tc">11</td><td></td><td></td></tr>
                        <tr class="row-total"><td>TOTAL DE COSTOS Y GASTOS (5+11)</td><td class="tc">12</td><td></td><td></td></tr>
                        <tr><td>Utilidad</td><td class="tc bold">13</td>
                            <td class="tr">${precioVenta > 0 ? (precioVenta * unidades - costoTotalParcial).toFixed(2) : ''}</td><td></td></tr>
                        <tr class="row-total"><td class="bold">PRECIO O TARIFA</td><td class="tc">14</td>
                            <td class="tr bold">${precioVenta > 0 ? (precioVenta * unidades).toFixed(2) : ''}</td><td></td></tr>
                        <tr class="row-total"><td class="bold">PRECIO O TARIFA UNITARIO AJUSTADO</td><td class="tc">15</td>
                            <td class="tr bold">${precioVenta > 0 ? precioVenta.toFixed(2) : ''}</td><td></td></tr>
                        <tr><td>Datos sobre precios de referencia</td><td class="tc">16</td><td colspan="2"></td></tr>
                    </tbody>
                </table>

                <p class="note">* Fila 5 calculada sólo con Materias Primas (Fila 1). Complete las Filas 2, 3 y 4 para obtener el Costo Total definitivo.</p>

                <div class="sign-grid">
                    <div class="sign-cell"><div class="line"></div><b>Elaborado por:</b> &nbsp;&nbsp;&nbsp; <b>Firma:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Cargo:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Fecha:</b></div>
                    <div class="sign-cell"><div class="line"></div><b>Aprobado por:</b> &nbsp;&nbsp;&nbsp; <b>Firma:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Cargo:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Fecha:</b></div>
                </div>`;

                // ── Anexo I: Desagregación de Insumos ────────────────────────────────────
                const anexoInsumos = `
                <div class="page-break">
                <div style="text-align:center;font-weight:bold;font-size:10pt;border:2px solid #000;padding:3px;margin-bottom:6px">
                    DESAGREGACIÓN DE LOS INSUMOS FUNDAMENTALES
                </div>
                <div class="head-box">
                    <b>EMPRESA:</b> ${empresa} &nbsp;&nbsp;&nbsp;
                    <b>CÓDIGO DEL PRODUCTO:</b> ${r.id_producto_final || ''} &nbsp;&nbsp;&nbsp;
                    <b>DESCRIPCIÓN DEL PROD.:</b> ${r.nombre_receta}<br>
                    <b>UNIDAD DE MEDIDA:</b> lote &nbsp;&nbsp;&nbsp;
                    <b>CANTIDADES FÍSICAS:</b> ${unidades} unid./lote
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:10%">CÓDIGO<br>(1)</th>
                            <th>PRODUCTOS<br>(2)</th>
                            <th style="width:7%">UM<br>(3)</th>
                            <th style="width:10%">COSTO BASE<br>(4)</th>
                            <th style="width:12%">NORMA DE CONSUMO<br>(5)</th>
                            <th style="width:12%">PRECIO UNITARIO<br>(6)</th>
                            <th style="width:12%">COSTO PROPUESTO<br>7 (5×6)</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${ingRows}
                        <tr class="row-sub">
                            <td colspan="6" class="tr bold">TOTAL</td>
                            <td class="tr bold">${costoInsumos.toFixed(2)}</td>
                        </tr>
                        <tr class="row-total">
                            <td colspan="6" class="tr bold">TOTAL INSUMOS</td>
                            <td class="tr bold">${costoInsumos.toFixed(2)}</td>
                        </tr>
                        <tr><td></td><td>Combustibles y lubricantes</td><td class="tc">LITROS</td><td></td><td></td><td></td><td></td></tr>
                        <tr><td></td><td>Energía eléctrica</td><td class="tc">kW</td><td></td><td></td><td></td><td></td></tr>
                    </tbody>
                </table>
                <div class="sign-grid">
                    <div class="sign-cell"><div class="line"></div><b>Elaborado por:</b> Nombre y apellidos &nbsp;&nbsp; <b>Cargo:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Firma:</b></div>
                    <div class="sign-cell"><div class="line"></div><b>Aprobado por:</b> Nombre y apellidos &nbsp;&nbsp; <b>Cargo:</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>Firma:</b></div>
                </div>
                </div>`;

                // ── Anexo II: Gasto de Salario ────────────────────────────────────────────
                const anexoSalario = `
                <div class="page-break">
                <div style="text-align:center;font-weight:bold;font-size:10pt;border:2px solid #000;padding:3px;margin-bottom:6px">
                    GASTO DE SALARIO DE LOS OBREROS DE LA PRODUCCIÓN O LOS SERVICIOS
                </div>
                <div class="head-box">
                    <b>Entidad:</b> ${empresa} &nbsp;&nbsp;&nbsp; <b>Órgano/Organismo:</b><br>
                    <b>Descripción del producto o servicio:</b> ${r.nombre_receta} &nbsp;&nbsp;&nbsp;
                    <b>Código:</b> ${r.id_producto_final || ''}<br>
                    <b>Cantidad de U.F. a producir:</b> ${unidades} &nbsp;&nbsp;&nbsp; <b>UM:</b> lote
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Descripción de las operaciones<br>(1)</th>
                            <th style="width:9%">Gasto de salario del Costo Base<br>(2)</th>
                            <th style="width:9%">Cantidad de trabajadores por operación o actividad<br>(3)</th>
                            <th style="width:9%">Categoría ocupacional<br>(4)</th>
                            <th style="width:7%">Grupo escala<br>(5)</th>
                            <th style="width:10%">Salario/hora por categoría y grupo (pesos y ctvos)<br>(6)</th>
                            <th style="width:10%">Pagos adicionales (por hora)<br>(7)</th>
                            <th style="width:9%">Norma de tiempo (en horas)<br>(8)</th>
                            <th style="width:11%">Gasto de Salario del costo propuesto (pesos y ctvos)<br>(9) = 3×(6+7)×8</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td style="height:22px"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr><td style="height:22px"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr><td style="height:22px"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr><td style="height:22px"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr><td style="height:22px"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <tr class="row-total"><td class="bold">TOTAL</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    </tbody>
                </table>
                <div class="sign-grid">
                    <div class="sign-cell"><div class="line"></div><b>Confeccionado por:</b> Nombre y apellidos &nbsp;&nbsp; <b>FIRMA:</b> &nbsp;&nbsp;&nbsp; <b>DÍA:</b> ___ <b>MES:</b> ___ <b>AÑO:</b> ____</div>
                    <div class="sign-cell"><div class="line"></div><b>Aprobado por:</b> Nombre y apellidos &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <b>FIRMA:</b> &nbsp;&nbsp;&nbsp; <b>DÍA:</b> ___ <b>MES:</b> ___ <b>AÑO:</b> ____</div>
                </div>
                </div>`;

                const w = window.open('', '_blank', 'width=1000,height=850');
                w.document.write(`<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
                <title>Ficha de Costos — ${r.nombre_receta}</title>
                <style>${css}</style></head><body>
                <div class="print-btn"><button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button></div>
                ${mainFicha}
                ${anexoInsumos}
                ${anexoSalario}
                <p class="res-note">Confeccionada conforme a la Resolución No. 148/2023 del Ministerio de Finanzas y Precios de la República de Cuba — GOC-2023-593-O64</p>
                </body></html>`);
                w.document.close();
            },
            loadHistory() { this.currentTab = 'history'; this.isEditing=false; this.api('get_history', this.historyFilter).then(d => this.history = d); },
            loadReports() { this.currentTab = 'reports'; this.isEditing=false; this.api('get_reports').then(d => this.reports = d); },
            canalBadge(canal) {
                const map = {
                    'Web':       ['#0ea5e9','🌐','Web'],
                    'POS':       ['#6366f1','🖥️','POS'],
                    'WhatsApp':  ['#22c55e','💬','WhatsApp'],
                    'Teléfono':  ['#f59e0b','📞','Teléfono'],
                    'Kiosko':    ['#8b5cf6','📱','Kiosko'],
                    'Presencial':['#475569','🙋','Presencial'],
                    'ICS':       ['#94a3b8','📥','ICS'],
                    'Otro':      ['#94a3b8','❓','Otro'],
                };
                const [bg, emoji, label] = map[canal] || map['Otro'];
                return `<span style="background-color:${bg}!important;color:white!important;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:bold;display:inline-block;">${emoji} ${label}</span>`;
            },
            estadoBadge(estado) {
                const map = {
                    'PENDIENTE':      ['#f59e0b','Pendiente'],
                    'EN_PREPARACION': ['#3b82f6','En Prep.'],
                    'EN_CAMINO':      ['#8b5cf6','En Camino'],
                };
                const [bg, label] = map[estado] || ['#94a3b8', estado];
                return `<span style="background-color:${bg}!important;color:white!important;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:bold;display:inline-block;">${label}</span>`;
            },
            formatFechaRes(fecha) {
                if (!fecha) return '-';
                const d = new Date(fecha);
                const today = new Date(); today.setHours(0,0,0,0);
                const dDate = new Date(d); dDate.setHours(0,0,0,0);
                const dateStr = d.toLocaleDateString('es-ES');
                const timeStr = d.toLocaleTimeString('es-ES',{hour:'2-digit',minute:'2-digit'});
                if (dDate < today) return `<span style="background:#dc3545;color:white;padding:1px 5px;border-radius:3px;font-size:10px;">VENCIDA</span> ${dateStr}`;
                if (dDate.getTime()===today.getTime()) return `<span style="background:#f59e0b;color:white;padding:1px 5px;border-radius:3px;font-size:10px;">HOY</span> ${timeStr}`;
                return `${dateStr} ${timeStr}`;
            },
            totalReservado() { return this.pendingReservations.reduce((a,r)=>a+parseFloat(r.cantidad_reservada||0),0).toFixed(1); },
            calculateMargin(r) { const p = parseFloat(r.precio_venta), c = parseFloat(r.costo_unitario); return (p>0) ? (((p-c)/p)*100).toFixed(1) : 0; }
        }
    });
</script>

<?php include_once 'pos_newprod.php'; ?>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
