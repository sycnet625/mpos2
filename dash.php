<?php
// ARCHIVO: dashboard.php
require_once 'db.php';

// --- FUNCIONES DE CONSULTA (DATA FETCHING) ---
function getScalar($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getRows($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// 1. FILTROS BÁSICOS
$empresaId = isset($_GET['empresa']) ? intval($_GET['empresa']) : 1;

// ============================================================================
//   CÁLCULOS KPI
// ============================================================================

// 1. INVENTARIO (Lo que tienes en bodega)
// -------------------------------------

// Valor del Inventario (Costo) Global
$valorInventarioCosto = getScalar($pdo, "
    SELECT SUM(s.cantidad * p.costo) 
    FROM stock_almacen s 
    JOIN productos p ON s.id_producto = p.id 
    WHERE p.id_empresa = ?", [$empresaId]);

// Valor del Inventario (Venta/Precio) Global
$valorInventarioVenta = getScalar($pdo, "
    SELECT SUM(s.cantidad * p.precio) 
    FROM stock_almacen s 
    JOIN productos p ON s.id_producto = p.id 
    WHERE p.id_empresa = ?", [$empresaId]);

// Margen Potencial (Diferencia) - Ganancia futura si vendes todo el stock
$margenPotencial = $valorInventarioVenta - $valorInventarioCosto;

// Stock Crítico
$stockCritico = getScalar($pdo, "
    SELECT COUNT(*) 
    FROM stock_almacen s 
    JOIN productos p ON s.id_producto = p.id 
    WHERE p.id_empresa = ? AND s.cantidad <= p.stock_minimo", [$empresaId]);


// 2. VENTAS Y GANANCIAS (Lo que ya vendiste)
// -------------------------------------

// Ventas de Hoy
$ventasHoy = getScalar($pdo, "SELECT SUM(total) FROM ventas_cabecera WHERE id_empresa = ? AND DATE(fecha) = CURDATE()", [$empresaId]);

// Ventas del Mes
$ventasMes = getScalar($pdo, "SELECT SUM(total) FROM ventas_cabecera WHERE id_empresa = ? AND MONTH(fecha) = MONTH(CURRENT_DATE()) AND YEAR(fecha) = YEAR(CURRENT_DATE())", [$empresaId]);

// Ticket Promedio
$ticketPromedio = getScalar($pdo, "SELECT AVG(total) FROM ventas_cabecera WHERE id_empresa = ?", [$empresaId]);

// --- NUEVO KPI: GANANCIA GLOBAL REAL ---
// Fórmula: Suma de [(Precio Venta - Costo Producto) * Cantidad] de todas las ventas históricas
$gananciaGlobal = getScalar($pdo, "
    SELECT SUM((d.precio - p.costo) * d.cantidad)
    FROM ventas_detalle d
    JOIN productos p ON d.id_producto = p.id
    JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
    WHERE v.id_empresa = ?", [$empresaId]);

// Cálculo de Rentabilidad % (Margen sobre ventas)
$totalVentasHistorico = getScalar($pdo, "SELECT SUM(total) FROM ventas_cabecera WHERE id_empresa = ?", [$empresaId]);
$porcentajeRentabilidad = ($totalVentasHistorico > 0) ? ($gananciaGlobal / $totalVentasHistorico) * 100 : 0;


// 3. DESGLOSES (TABLAS/GRÁFICOS)
// -------------------------------------

// Valor al Costo por Almacén
$costoPorAlmacen = getRows($pdo, "
    SELECT s.id_almacen, SUM(s.cantidad * p.costo) as total_costo
    FROM stock_almacen s
    JOIN productos p ON s.id_producto = p.id
    WHERE p.id_empresa = ?
    GROUP BY s.id_almacen", [$empresaId]);

// Ventas por Sucursal
$ventasPorSucursal = getRows($pdo, "
    SELECT id_sucursal, SUM(total) as total_ventas, COUNT(*) as transacciones
    FROM ventas_cabecera
    WHERE id_empresa = ?
    GROUP BY id_sucursal", [$empresaId]);

// Top 5 Productos
$topProductos = getRows($pdo, "
    SELECT p.nombre, SUM(d.cantidad) as vendidos, SUM(d.cantidad * (d.precio - p.costo)) as ganancia_generada
    FROM ventas_detalle d
    JOIN productos p ON d.id_producto = p.id
    JOIN ventas_cabecera v ON d.id_venta_cabecera = v.id
    WHERE v.id_empresa = ?
    GROUP BY p.id
    ORDER BY vendidos DESC LIMIT 5", [$empresaId]);

// Inventario por Categoría
$stockPorCategoria = getRows($pdo, "
    SELECT p.categoria, COUNT(*) as cantidad
    FROM productos p
    WHERE p.id_empresa = ?
    GROUP BY p.categoria", [$empresaId]);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PalWeb Analytics 🚀</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { background-color: #f4f6f9; }
        .card-stat { border: none; border-radius: 12px; transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .icon-box { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 24px; }
        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); color: white; }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); color: white; }
        .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); color: white; }
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); color: white; }
        .bg-gradient-info { background: linear-gradient(45deg, #36b9cc, #258391); color: white; }
        .bg-gradient-dark { background: linear-gradient(45deg, #5a5c69, #373840); color: white; }
        .table-custom { background: white; border-radius: 10px; overflow: hidden; }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark"><i class="fas fa-chart-line text-primary"></i> PalWeb Dashboard</h2>
            <p class="text-muted">Resumen ejecutivo de la empresa #<?php echo $empresaId; ?></p>
        </div>
        <button class="btn btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Actualizar</button>
    </div>

    <h6 class="text-uppercase text-muted fw-bold mb-3 fs-7">📦 Estado del Inventario</h6>
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="icon-box bg-gradient-primary me-3"><i class="fas fa-dollar-sign"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Valor Inv. (Costo)</h6>
                        <h4 class="fw-bold mb-0">$<?php echo number_format($valorInventarioCosto, 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="icon-box bg-gradient-info me-3"><i class="fas fa-tags"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Valor Inv. (Venta)</h6>
                        <h4 class="fw-bold mb-0">$<?php echo number_format($valorInventarioVenta, 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="icon-box bg-gradient-success me-3"><i class="fas fa-piggy-bank"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Margen Potencial</h6>
                        <h4 class="fw-bold mb-0 text-success">$<?php echo number_format($margenPotencial, 2); ?></h4>
                        <small>Si vendes todo el stock</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat shadow-sm h-100 border-start border-5 border-danger">
                <div class="card-body d-flex align-items-center">
                    <div class="icon-box bg-gradient-danger me-3"><i class="fas fa-exclamation-triangle"></i></div>
                    <div>
                        <h6 class="text-muted mb-0">Stock Crítico</h6>
                        <h4 class="fw-bold mb-0 text-danger"><?php echo $stockCritico; ?></h4>
                        <small>Productos bajo mínimo</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h6 class="text-uppercase text-muted fw-bold mb-3 fs-7">💰 Ventas y Rentabilidad</h6>
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card card-stat shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted">Ventas Hoy</h6>
                            <h3>$<?php echo number_format($ventasHoy, 2); ?></h3>
                        </div>
                        <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted">Ventas Mes</h6>
                            <h3>$<?php echo number_format($ventasMes, 2); ?></h3>
                        </div>
                        <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card card-stat shadow-sm bg-gradient-dark text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-white-50">Ganancia Neta Global</h6>
                            <h3 class="fw-bold">$<?php echo number_format($gananciaGlobal, 2); ?></h3>
                            <small class="text-white-50"><i class="fas fa-percentage"></i> Rentabilidad: <?php echo number_format($porcentajeRentabilidad, 1); ?>%</small>
                        </div>
                        <i class="fas fa-wallet fa-2x text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card card-stat shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted">Ticket Promedio</h6>
                            <h3>$<?php echo number_format($ticketPromedio, 2); ?></h3>
                        </div>
                        <i class="fas fa-receipt fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-white">
                    <h6 class="m-0 fw-bold text-primary">🏭 Valor Inv. por Almacén</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Almacén</th>
                                <th class="text-end">Valor Costo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($costoPorAlmacen as $row): ?>
                            <tr>
                                <td>Almacén #<?php echo $row['id_almacen']; ?></td>
                                <td class="text-end fw-bold text-dark">$<?php echo number_format($row['total_costo'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($costoPorAlmacen)) echo "<tr><td colspan='2' class='text-center p-3'>Sin datos</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow">
                <div class="card-header py-3 bg-white">
                    <h6 class="m-0 fw-bold text-success">🏪 Ventas por Sucursal</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Sucursal</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasPorSucursal as $row): ?>
                            <tr>
                                <td>Sucursal #<?php echo $row['id_sucursal']; ?></td>
                                <td class="text-end text-success fw-bold">$<?php echo number_format($row['total_ventas'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-white">
                    <h6 class="m-0 fw-bold text-info">📊 Mix de Categorías</h6>
                </div>
                <div class="card-body">
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="chartCategorias"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow mb-4 h-100">
                <div class="card-header py-3 bg-white">
                    <h6 class="m-0 fw-bold text-warning">🏆 Top 5 Productos</h6>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($topProductos as $idx => $prod): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-secondary rounded-pill me-2">#<?php echo $idx + 1; ?></span>
                                <?php echo htmlspecialchars($prod['nombre']); ?>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary rounded-pill"><?php echo number_format($prod['vendidos'], 0); ?> uds</span>
                                <div style="font-size: 0.75rem" class="text-success fw-bold">
                                    +$<?php echo number_format($prod['ganancia_generada'], 2); ?> ganancia
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php if(empty($topProductos)) echo "<div class='text-center p-3 text-muted'>No hay ventas registradas aún</div>"; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Datos PHP a JS
    const categoriasLabels = <?php echo json_encode(array_column($stockPorCategoria, 'categoria')); ?>;
    const categoriasData = <?php echo json_encode(array_column($stockPorCategoria, 'cantidad')); ?>;

    // Gráfico de Barras (Categorías)
    const ctx = document.getElementById('chartCategorias').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: categoriasLabels,
            datasets: [{
                label: 'Cantidad de Productos',
                data: categoriasData,
                backgroundColor: 'rgba(54, 185, 204, 0.7)',
                borderColor: 'rgba(54, 185, 204, 1)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false, // CRUCIAL: Se adapta al div de 300px
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { borderDash: [2, 4] }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

