<?php
// ARCHIVO: reservas.php v1.1
ini_set('display_errors', 0);
require_once 'db.php';

// Configuración básica
require_once 'config_loader.php';
$sucursalID = intval($config['id_sucursal']);

// 1. PROCESAR ACCIONES (Entregar / Cancelar / Enviar a Cocina)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && isset($input['id'])) {
        $idVenta = intval($input['id']);
        try {
            // ACCIÓN: ENTRAGAR O CANCELAR
            if ($input['action'] === 'complete' || $input['action'] === 'cancel') {
                $newState = ($input['action'] === 'complete') ? 'ENTREGADO' : 'CANCELADO';
                $stmtUpd = $pdo->prepare("UPDATE ventas_cabecera SET estado_reserva = ? WHERE id = ?");
                $stmtUpd->execute([$newState, $idVenta]);
                echo json_encode(['status' => 'success']);
            } 
            // ACCIÓN NUEVA: ENVIAR A COCINA
            elseif ($input['action'] === 'send_to_kitchen') {
                // Verificar si ya existe en cocina para evitar duplicados
                $stmtCheck = $pdo->prepare("SELECT id FROM comandas WHERE id_venta = ?");
                $stmtCheck->execute([$idVenta]);
                if ($stmtCheck->fetch()) throw new Exception("Este pedido ya está en cocina.");

                // Obtener solo productos elaborados de la venta
                $stmtItems = $pdo->prepare("SELECT d.cantidad, p.nombre, p.es_elaborado 
                                            FROM ventas_detalle d 
                                            JOIN productos p ON d.id_producto = p.codigo 
                                            WHERE d.id_venta_cabecera = ? AND p.es_elaborado = 1");
                $stmtItems->execute([$idVenta]);
                $elaborados = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                if (empty($elaborados)) throw new Exception("No hay productos que requieran elaboración en esta reserva.");

                $itemsJson = [];
                foreach ($elaborados as $it) {
                    $itemsJson[] = ['qty' => (float)$it['cantidad'], 'name' => $it['nombre'], 'note' => 'RESERVA'];
                }

                // Insertar en tabla de cocina
                $stmtComanda = $pdo->prepare("INSERT INTO comandas (id_venta, items_json, estado, fecha_creacion) VALUES (?, ?, 'pendiente', NOW())");
                $stmtComanda->execute([$idVenta, json_encode($itemsJson)]);
                
                echo json_encode(['status' => 'success']);
            }
            // ACCIÓN: IMPORTAR .ICS
            elseif ($input['action'] === 'import_ics') {
                $content = $input['content'] ?? '';
                if (empty($content)) throw new Exception("Archivo vacío.");

                // Parser básico de ICS
                preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $content, $matches);
                $count = 0;
                foreach ($matches[0] as $event) {
                    preg_match('/SUMMARY[^:]*:(.*)/', $event, $summary);
                    preg_match('/DTSTART[^:]*:(.*)/', $event, $dtstart);
                    preg_match('/UID[^:]*:(.*)/', $event, $uid);

                    $title = trim($summary[1] ?? 'Reserva Importada');
                    $startStr = trim($dtstart[1] ?? '');
                    $uuid = trim($uid[1] ?? uniqid('ics_', true));

                    if (!$startStr) continue;

                    // Formatos: 20260217T140000Z o 20260217
                    $date = null;
                    if (preg_match('/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', $startStr, $d)) {
                        $date = "{$d[1]}-{$d[2]}-{$d[3]} {$d[4]}:{$d[5]}:00";
                    } elseif (preg_match('/(\d{4})(\d{2})(\d{2})/', $startStr, $d)) {
                        $date = "{$d[1]}-{$d[2]}-{$d[3]} 12:00:00"; // Default noon
                    }

                    if ($date) {
                        // Verificar duplicados
                        $stmtCheck = $pdo->prepare("SELECT id FROM ventas_cabecera WHERE uuid_venta = ?");
                        $stmtCheck->execute([$uuid]);
                        if ($stmtCheck->fetch()) continue;

                        $sqlIns = "INSERT INTO ventas_cabecera (uuid_venta, fecha, total, metodo_pago, id_sucursal, id_almacen, tipo_servicio, cliente_nombre, fecha_reserva, id_empresa, estado_reserva, sincronizado, id_caja) 
                                   VALUES (?, NOW(), 0, 'Sincronizado', ?, ?, 'reserva', ?, ?, ?, 'PENDIENTE', 1, 1)";
                        $stmtIns = $pdo->prepare($sqlIns);
                        $stmtIns->execute([$uuid, $config['id_sucursal'], $config['id_almacen'], $title, $date, $config['id_empresa']]);
                        $count++;
                    }
                }
                echo json_encode(['status' => 'success', 'count' => $count]);
            }
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}

// 2. OBTENER RESERVAS ACTIVAS (SQL CORREGIDO PARA SKU)
try {
    $sql = "SELECT c.id, c.cliente_nombre, c.cliente_telefono, c.fecha_reserva, c.total, c.abono,
                   (c.total - COALESCE(c.abono, 0)) as deuda,
                   GROUP_CONCAT(
                        CONCAT(d.cantidad, 'x ', COALESCE(p.nombre, 'Producto Eliminado')) 
                        SEPARATOR ', '
                   ) as resumen_items,
                   (SELECT COUNT(*) FROM comandas com WHERE com.id_venta = c.id) as enviado_cocina
            FROM ventas_cabecera c
            LEFT JOIN ventas_detalle d ON c.id = d.id_venta_cabecera
            LEFT JOIN productos p ON d.id_producto = p.codigo 
            WHERE c.tipo_servicio = 'reserva' 
              AND c.id_sucursal = ?
              AND (c.estado_reserva = 'PENDIENTE' OR c.estado_reserva IS NULL)
            GROUP BY c.id
            ORDER BY c.fecha_reserva ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sucursalID]);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventos = [];
    foreach ($reservas as $r) {
        $eventos[] = [
            'id' => $r['id'],
            'title' => $r['cliente_nombre'] . " ($" . number_format($r['total'], 0) . ")",
            'start' => $r['fecha_reserva'],
            'backgroundColor' => $r['enviado_cocina'] > 0 ? '#198754' : '#0d6efd',
            'extendedProps' => [
                'items' => $r['resumen_items'] ?? 'Sin detalles',
                'deuda' => $r['deuda']
            ]
        ];
    }
} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>GESTIÓN DE RESERVAS v1.1</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .fc-event { cursor: pointer; border: none; padding: 3px 5px; font-size: 0.9rem; border-radius: 4px; }
        .reserva-item { cursor: pointer; transition: background 0.2s; position: relative; }
        .reserva-item:hover { background-color: #f8f9fa; }
    </style>
</head>
<body class="p-3">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0"><i class="far fa-calendar-alt text-primary me-2"></i> GESTIÓN DE RESERVAS v1.1</h3>
            <p class="text-muted mb-0">Control de entregas y pedidos a cocina</p>
        </div>
        <div>
            <a href="pos.php" class="btn btn-outline-secondary me-2"><i class="fas fa-cash-register"></i> POS</a>
            <button class="btn btn-outline-primary me-2" onclick="importICS()"><i class="fas fa-file-import"></i> Importar .ics</button>
            <button class="btn btn-primary" onclick="location.reload()"><i class="fas fa-sync"></i></button>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0"><div class="card-body p-2"><div id="calendar"></div></div></div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 border-bottom"><h5 class="m-0 fw-bold">Próximas Entregas</h5></div>
                <div class="card-body p-0 overflow-auto" style="max-height: 650px;">
                    <?php if (empty($reservas)): ?>
                        <div class="text-center py-5 text-muted"><i class="fas fa-check-circle fa-3x mb-3 opacity-25"></i><p>Sin reservas pendientes.</p></div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($reservas as $r): 
                                $fecha = new DateTime($r['fecha_reserva']);
                                $hoy = new DateTime();
                                $diff = $hoy->diff($fecha);
                                $dias = $diff->invert ? -$diff->days : $diff->days;
                                
                                $badgeCls = 'bg-primary';
                                $textoTiempo = ($dias === 0) ? 'HOY' : ($dias == 1 ? 'Mañana' : "En $dias días");
                                if ($dias < 0) { $badgeCls = 'bg-danger'; $textoTiempo = 'VENCIDO'; }
                                elseif ($dias === 0) { $badgeCls = 'bg-warning text-dark'; }
                            ?>
                            <div class="list-group-item reserva-item p-3" onclick="verTicket(<?php echo $r['id']; ?>, <?php echo $r['deuda']; ?>)">
                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($r['cliente_nombre']); ?></h6>
                                    <span class="badge <?php echo $badgeCls; ?>"><?php echo $textoTiempo; ?></span>
                                </div>
                                <div class="small text-muted mb-2"><i class="far fa-clock"></i> <?php echo $fecha->format('d/m/Y h:i A'); ?></div>
                                
                                <?php if ($dias === 0 && $r['enviado_cocina'] == 0): ?>
                                    <button class="btn btn-sm btn-info text-white fw-bold mb-2 w-100" onclick="event.stopPropagation(); enviarACocina(<?php echo $r['id']; ?>)">
                                        <i class="fas fa-fire"></i> MANDAR A COCINA HOY
                                    </button>
                                <?php elseif ($r['enviado_cocina'] > 0): ?>
                                    <div class="alert alert-success py-1 px-2 mb-2 small text-center fw-bold"><i class="fas fa-check"></i> YA EN COCINA</div>
                                <?php endif; ?>

                                <p class="mb-2 small text-dark border-start border-3 border-info ps-2 bg-light py-1">
                                    <?php echo htmlspecialchars(mb_strimwidth($r['resumen_items'] ?? '', 0, 60, "...")); ?>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ticketModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title">Gestión #<span id="modalId"></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-0"><iframe id="ticketFrame" src="" style="width:100%; height:350px; border:none;"></iframe>
                <div class="p-3 border-top bg-white">
                    <div class="d-flex justify-content-between mb-3"><span class="badge bg-warning text-dark p-2" id="modalDeudaDisplay"></span></div>
                    <div class="d-grid gap-2 d-flex">
                        <button class="btn btn-outline-danger flex-fill" onclick="procesarReserva('cancel')"><i class="fas fa-times"></i> Cancelar</button>
                        <button class="btn btn-success flex-fill fw-bold" onclick="procesarReserva('complete')"><i class="fas fa-check-circle"></i> ENTREGAR</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-1 bg-light"><button class="btn btn-sm btn-secondary" onclick="imprimirTicket()">🖨️ Ticket</button></div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
    var currentTicketId = 0;
    const ticketModal = new bootstrap.Modal(document.getElementById('ticketModal'));

    document.addEventListener('DOMContentLoaded', function() {
        var calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
            initialView: 'dayGridMonth', locale: 'es', themeSystem: 'bootstrap5',
            events: <?php echo json_encode($eventos); ?>,
            eventClick: function(info) { verTicket(info.event.id, info.event.extendedProps.deuda); }
        });
        calendar.render();
    });

    async function enviarACocina(id) {
        if(!confirm("¿Enviar los productos elaborados de esta reserva a la pantalla de cocina?")) return;
        try {
            const resp = await fetch('reservas.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: id, action: 'send_to_kitchen' })
            });
            const data = await resp.json();
            if (data.status === 'success') { alert("Pedido enviado a cocina"); location.reload(); } 
            else alert("Error: " + data.msg);
        } catch (e) { alert("Error de conexión"); }
    }

    function verTicket(id, deuda = 0) {
        currentTicketId = id;
        document.getElementById('modalId').innerText = id;
        document.getElementById('ticketFrame').src = `ticket_view.php?id=${id}`;
        const b = document.getElementById('modalDeudaDisplay');
        b.innerText = deuda > 0 ? `DEBE: $${parseFloat(deuda).toFixed(2)}` : 'PAGADO';
        b.className = deuda > 0 ? 'badge bg-danger p-2' : 'badge bg-success p-2';
        ticketModal.show();
    }

    async function procesarReserva(a) {
        if (!confirm("¿Confirmar acción?")) return;
        const res = await fetch('reservas.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id: currentTicketId, action: a }) });
        const data = await res.json();
        if (data.status === 'success') location.reload(); else alert("Error: " + data.msg);
    }

    function imprimirTicket() { window.open('ticket_view.php?id=' + currentTicketId, 'Ticket', 'width=380,height=600'); }

    async function importICS() {
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '.ics';
        fileInput.onchange = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = async (event) => {
                const content = event.target.result;
                try {
                    const resp = await fetch('reservas.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'import_ics', content: content })
                    });
                    const data = await resp.json();
                    if (data.status === 'success') {
                        alert("Importación completada: " + data.count + " nuevas reservas.");
                        location.reload();
                    } else {
                        alert("Error: " + data.msg);
                    }
                } catch (err) {
                    alert("Error de conexión al importar.");
                }
            };
            reader.readAsText(file);
        };
        fileInput.click();
    }
</script>



<?php include_once 'menu_master.php'; ?>
</body>
</html>

