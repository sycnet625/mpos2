<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cakes_storage.php';

$token = (string)($_GET['token'] ?? '');
$pedido = false;
if (preg_match('/\A[a-f0-9]{32}\z/', $token)) {
    try {
        $stmt = $pdo->prepare("SELECT c.id, c.estado_reserva, c.fecha_reserva, c.total, c.abono, o.opciones
            FROM cakes_web_orders o JOIN ventas_cabecera c ON c.id=o.id_venta AND c.id_empresa=o.id_empresa
            WHERE o.token_hash=? AND c.canal_origen='Web Cakes' LIMIT 1");
        $stmt->execute([cakes_token_hash($token)]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Cakes tracking: ' . $e->getMessage());
    }
}
if (!$pedido) http_response_code(404);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$estados = ['PENDIENTE'=>'Pendiente de confirmación','CONFIRMADO'=>'Confirmada','EN_PREPARACION'=>'En preparación','LISTO'=>'Lista para recoger','ENTREGADO'=>'Entregada','CANCELADO'=>'Cancelada','EN_CAMINO'=>'En camino'];
$options = $pedido ? (json_decode($pedido['opciones'], true) ?: []) : [];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Estado de mi cake</title>
<style>body{font-family:system-ui,sans-serif;background:#fff8ed;color:#40243c;margin:0;padding:24px}.card{max-width:560px;margin:6vh auto;background:white;border:1px solid #ead8da;border-radius:24px;padding:28px;box-shadow:0 12px 35px #40243c18}h1{color:#e33679}.status{background:#fff2d2;padding:16px;border-radius:12px;font-weight:700;font-size:1.2em}.row{padding:9px 0;border-bottom:1px solid #eee}a{color:#b92560;font-weight:700}</style></head><body><main class="card">
<?php if (!$pedido): ?><h1>Reserva no encontrada</h1><p>Comprueba el enlace privado que recibiste al crear tu pedido.</p><a href="cakes.php">Volver al catálogo</a>
<?php else: ?><h1>Tu reserva #<?= $h(str_pad((string)$pedido['id'],5,'0',STR_PAD_LEFT)) ?></h1><p class="status"><?= $h($estados[$pedido['estado_reserva'] ?? 'PENDIENTE'] ?? 'En revisión') ?></p>
<?php $cakes = isset($options['items']) && is_array($options['items']) ? $options['items'] : [$options]; foreach ($cakes as $index=>$cake): ?>
<div class="row"><strong>Cake <?= (int)$index+1 ?>:</strong> <?= $h($cake['nombre'] ?? 'Cake a medida') ?></div>
<?php foreach (['flavor'=>'Panetela o sabor','filling'=>'Relleno','coverage'=>'Cobertura','meringue_color'=>'Color del merengue','extra'=>'Extra'] as $key=>$label): if (!empty($cake[$key])): ?><div class="row"><strong><?= $h($label) ?>:</strong> <?= $h($cake[$key]) ?></div><?php endif; endforeach; endforeach; ?>
<?php if (!empty($options['nota'])): ?><div class="row"><strong>Nota:</strong> <?= $h($options['nota']) ?></div><?php endif; ?>
<div class="row"><strong>Fecha solicitada:</strong> <?= $h(!empty($options['fecha_solicitada']) ? date('d/m/Y',strtotime($options['fecha_solicitada'])) : 'Por confirmar') ?></div>
<div class="row"><strong>Total:</strong> <?= $h(number_format((float)$pedido['total'],2)) ?> CUP · <strong>Abonado:</strong> <?= $h(number_format((float)$pedido['abono'],2)) ?> CUP</div>
<p>Guarda este enlace privado para consultar tu pedido.</p><a href="ticket_view.php?id=<?= (int)$pedido['id'] ?>&amp;token=<?= $h($token) ?>" target="_blank" rel="noopener">Abrir e imprimir el ticket</a>
<?php endif; ?></main></body></html>
