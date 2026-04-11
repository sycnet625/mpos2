<?php
/**
 * WIDGET: Últimas Ventas con Generador de Comprobantes
 * Se incluye en el dashboard para mostrar últimas ventas y generar comprobantes
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config_loader.php';

// Obtener últimas 10 ventas
$sqlVentas = "
    SELECT
        v.id,
        v.uuid,
        v.fecha,
        v.cliente_nombre,
        v.total,
        v.metodo_pago,
        COUNT(d.id) as items_count
    FROM ventas_cabecera v
    LEFT JOIN ventas_detalle d ON v.id = d.id_venta_cabecera
    WHERE v.activo = 1
    GROUP BY v.id
    ORDER BY v.fecha DESC
    LIMIT 10
";

$stmtVentas = $pdo->query($sqlVentas);
$ultimas_ventas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Widget: Últimas Ventas con Comprobante -->
<div class="card shadow-sm mb-3 border-0" style="border-left: 4px solid #17a2b8;">
    <div class="card-header bg-light py-2">
        <h6 class="mb-0 text-dark">
            <i class="fas fa-receipt text-info me-2"></i>
            <strong>Últimas Ventas</strong>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Cliente</th>
                        <th>Fecha</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($ultimas_ventas)): ?>
                        <?php foreach ($ultimas_ventas as $venta): ?>
                        <tr>
                            <td class="ps-3">
                                <span class="badge bg-secondary">#<?php echo $venta['id']; ?></span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars(mb_strimwidth($venta['cliente_nombre'], 0, 30, '...')); ?></small>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></small>
                            </td>
                            <td class="text-end">
                                <strong>$<?php echo number_format($venta['total'], 2, ',', '.'); ?></strong>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button
                                        type="button"
                                        class="btn btn-outline-info"
                                        title="Ver comprobante"
                                        onclick="window.open('comprobante_ventas.php?id=<?php echo $venta['id']; ?>', '_blank', 'width=1000,height=800')">
                                        <i class="fas fa-file-pdf"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        title="Imprimir ticket"
                                        onclick="window.open('ticket_view.php?id=<?php echo $venta['id']; ?>', '_blank', 'width=380,height=600')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">
                                <small>Sin ventas registradas</small>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-light py-2 text-end">
        <a href="pos.php" class="btn btn-sm btn-primary">
            <i class="fas fa-cash-register me-1"></i> Ir al POS
        </a>
    </div>
</div>
