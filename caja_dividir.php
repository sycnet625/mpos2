<?php
// Módulo administrativo para separar una sesión de caja por número de ticket.
ini_set('display_errors', '0');
require_once 'db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: login.php');
    }
    exit;
}

require_once 'config_loader.php';
require_once 'pos_audit.php';

$empresaID = (int)($_SESSION['id_empresa'] ?? $config['id_empresa'] ?? 1);
$csrfToken = (string)($_SESSION['pos_csrf_token'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['pos_csrf_token'] = $csrfToken;
}

function caja_dividir_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

function caja_dividir_require_csrf(array $input): void
{
    $expected = (string)($_SESSION['pos_csrf_token'] ?? '');
    $provided = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '')));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        throw new RuntimeException('Token CSRF inválido.');
    }
}

function caja_dividir_date(string $value): ?string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function caja_dividir_session(PDO $pdo, int $sessionId, int $empresaID, bool $forUpdate = false): ?array
{
    $sql = "SELECT * FROM caja_sesiones WHERE id = ? AND id_empresa = ? LIMIT 1";
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $empresaID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function caja_dividir_stats(PDO $pdo, array $session, int $splitTicket, string $side): array
{
    if (!in_array($side, ['before', 'move'], true)) {
        throw new InvalidArgumentException('Lado de división inválido.');
    }
    $operator = $side === 'before' ? '<' : '>=';
    $params = [
        (int)$session['id_empresa'],
        (int)$session['id_sucursal'],
        (int)$session['id_almacen'],
        (int)$session['id'],
        (int)$session['id'],
        $splitTicket,
    ];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS tickets, MIN(id) AS min_ticket, MAX(id) AS max_ticket,
                COALESCE(SUM(total), 0) AS total
         FROM ventas_cabecera
         WHERE id_empresa = ? AND id_sucursal = ? AND id_almacen = ?
           AND (id_caja = ? OR id_sesion_caja = ?) AND id {$operator} ?"
    );
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $cashStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(
                    CASE WHEN EXISTS (
                        SELECT 1 FROM ventas_pagos p0
                        WHERE p0.id_venta_cabecera = v.id
                    ) THEN (
                        SELECT COALESCE(SUM(p1.monto), 0)
                        FROM ventas_pagos p1
                        WHERE p1.id_venta_cabecera = v.id
                          AND LOWER(p1.metodo_pago) LIKE '%efectivo%'
                    ) ELSE CASE WHEN LOWER(COALESCE(v.metodo_pago, '')) LIKE '%efectivo%'
                                THEN v.total ELSE 0 END
                    END
                ), 0) AS efectivo
         FROM ventas_cabecera v
         WHERE v.id_empresa = ? AND v.id_sucursal = ? AND v.id_almacen = ?
           AND (v.id_caja = ? OR v.id_sesion_caja = ?) AND v.id {$operator} ?"
    );
    $cashStmt->execute($params);
    $stats['efectivo'] = (float)($cashStmt->fetchColumn() ?: 0);
    $stats['tickets'] = (int)($stats['tickets'] ?? 0);
    $stats['total'] = (float)($stats['total'] ?? 0);
    return $stats;
}

function caja_dividir_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? ''));
if ($action !== '') {
    try {
        $input = caja_dividir_input();

        if ($action === 'list') {
            $stmt = $pdo->prepare(
                "SELECT s.id, s.nombre_cajero, s.fecha_apertura, s.fecha_cierre,
                        s.fecha_contable, s.estado, s.id_sucursal, s.id_almacen,
                        COALESCE(suc.nombre, CONCAT('Sucursal ', s.id_sucursal)) AS sucursal_nombre,
                        COALESCE(alm.nombre, CONCAT('Almacén ', s.id_almacen)) AS almacen_nombre,
                        COUNT(v.id) AS tickets, MIN(v.id) AS min_ticket, MAX(v.id) AS max_ticket,
                        COALESCE(SUM(v.total), 0) AS total
                 FROM caja_sesiones s
                 LEFT JOIN sucursales suc ON suc.id = s.id_sucursal
                 LEFT JOIN almacenes alm ON alm.id = s.id_almacen
                 LEFT JOIN ventas_cabecera v ON v.id_empresa = s.id_empresa
                    AND v.id_sucursal = s.id_sucursal AND v.id_almacen = s.id_almacen
                    AND (v.id_caja = s.id OR v.id_sesion_caja = s.id)
                 WHERE s.id_empresa = ?
                 GROUP BY s.id
                 ORDER BY s.id DESC
                 LIMIT 150"
            );
            $stmt->execute([$empresaID]);
            caja_dividir_json(['status' => 'success', 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if (!in_array($action, ['preview', 'split'], true)) {
            caja_dividir_json(['status' => 'error', 'msg' => 'Acción desconocida.'], 400);
        }

        caja_dividir_require_csrf($input);
        $sessionId = (int)($input['session_id'] ?? 0);
        $splitTicket = (int)($input['split_ticket'] ?? 0);
        if ($sessionId <= 0 || $splitTicket <= 0) {
            caja_dividir_json(['status' => 'error', 'msg' => 'Indica la sesión y el ticket inicial de la nueva sesión.'], 422);
        }

        $session = caja_dividir_session($pdo, $sessionId, $empresaID);
        if (!$session) {
            caja_dividir_json(['status' => 'error', 'msg' => 'Sesión no encontrada para la empresa actual.'], 404);
        }

        $before = caja_dividir_stats($pdo, $session, $splitTicket, 'before');
        $toMove = caja_dividir_stats($pdo, $session, $splitTicket, 'move');
        $systemTotal = (float)$session['monto_inicial'] + (float)$before['efectivo'];

        if ($action === 'preview') {
            $baseDate = caja_dividir_date(substr((string)($session['fecha_contable'] ?? ''), 0, 10));
            $defaultNewDate = $baseDate
                ? (new DateTimeImmutable($baseDate))->modify('+1 day')->format('Y-m-d')
                : date('Y-m-d');
            caja_dividir_json([
                'status' => 'success',
                'session' => $session,
                'before' => $before,
                'to_move' => $toMove,
                'system_close_total' => round($systemTotal, 2),
                'default_new_date' => $defaultNewDate,
                'warning_closed' => strtoupper((string)$session['estado']) === 'CERRADA',
            ]);
        }

        $newDate = caja_dividir_date((string)($input['new_date'] ?? ''));
        if (!$newDate) {
            caja_dividir_json(['status' => 'error', 'msg' => 'La fecha contable de la nueva sesión no es válida.'], 422);
        }
        if ((int)$toMove['tickets'] < 1) {
            caja_dividir_json(['status' => 'error', 'msg' => 'No hay tickets de esta sesión desde el número indicado.'], 422);
        }

        $initialAmount = isset($input['monto_inicial']) && is_numeric($input['monto_inicial'])
            ? (float)$input['monto_inicial'] : 0.0;
        $realCash = isset($input['efectivo_real']) && $input['efectivo_real'] !== '' && is_numeric($input['efectivo_real'])
            ? (float)$input['efectivo_real'] : $systemTotal;
        if ($initialAmount < 0 || $realCash < 0) {
            caja_dividir_json(['status' => 'error', 'msg' => 'Los importes no pueden ser negativos.'], 422);
        }
        $note = trim((string)($input['nota'] ?? ''));
        $note = mb_substr($note, 0, 300, 'UTF-8');

        $lockName = 'caja_dividir_' . (int)$session['id_empresa'] . '_' . (int)$session['id_sucursal'] . '_' . (int)$session['id_almacen'];
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lockStmt->execute([$lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            caja_dividir_json(['status' => 'error', 'msg' => 'No se pudo bloquear el contexto de caja. Intenta nuevamente.'], 409);
        }

        $newSessionId = 0;
        try {
            $pdo->beginTransaction();
            $session = caja_dividir_session($pdo, $sessionId, $empresaID, true);
            if (!$session) throw new RuntimeException('La sesión dejó de estar disponible.');

            $openOther = $pdo->prepare(
                "SELECT id FROM caja_sesiones
                 WHERE estado = 'ABIERTA' AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ? AND id <> ?
                 LIMIT 1"
            );
            $openOther->execute([(int)$session['id_empresa'], (int)$session['id_sucursal'], (int)$session['id_almacen'], $sessionId]);
            if ($openOther->fetchColumn()) {
                throw new RuntimeException('Ya existe otra sesión abierta en la misma sucursal y almacén.');
            }

            $before = caja_dividir_stats($pdo, $session, $splitTicket, 'before');
            $toMove = caja_dividir_stats($pdo, $session, $splitTicket, 'move');
            if ((int)$toMove['tickets'] < 1) {
                throw new RuntimeException('No hay tickets para mover desde el número indicado.');
            }
            $systemTotal = (float)$session['monto_inicial'] + (float)$before['efectivo'];
            $difference = $realCash - $systemTotal;
            $oldNote = trim((string)($session['nota'] ?? ''));
            $splitNote = 'Sesión dividida desde ticket #' . $splitTicket . '. Nueva sesión pendiente.';
            $sourceNote = mb_substr(trim($oldNote . ($oldNote !== '' ? ' | ' : '') . $splitNote), 0, 500, 'UTF-8');

            $close = $pdo->prepare(
                "UPDATE caja_sesiones
                 SET fecha_cierre = COALESCE(fecha_cierre, NOW()),
                     monto_final_sistema = ?, monto_final_real = ?, diferencia = ?,
                     nota = ?, estado = 'CERRADA'
                 WHERE id = ? AND id_empresa = ? AND id_sucursal = ? AND id_almacen = ?"
            );
            $close->execute([
                round($systemTotal, 2), round($realCash, 2), round($difference, 2), $sourceNote,
                $sessionId, (int)$session['id_empresa'], (int)$session['id_sucursal'], (int)$session['id_almacen'],
            ]);

            $newNote = 'Creada al dividir la sesión #' . $sessionId . ' desde ticket #' . $splitTicket . '.';
            if ($note !== '') $newNote .= ' ' . $note;
            $insert = $pdo->prepare(
                "INSERT INTO caja_sesiones
                    (id_usuario, id_empresa, nombre_cajero, monto_inicial, fecha_contable,
                     id_sucursal, id_almacen, estado, fecha_apertura, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'ABIERTA', NOW(), ?)"
            );
            $insert->execute([
                (int)($session['id_usuario'] ?? 0) ?: null,
                (int)$session['id_empresa'],
                (string)($session['nombre_cajero'] ?? 'Cajero'),
                round($initialAmount, 2), $newDate,
                (int)$session['id_sucursal'], (int)$session['id_almacen'], $newNote,
            ]);
            $newSessionId = (int)$pdo->lastInsertId();
            if ($newSessionId <= 0) throw new RuntimeException('No se pudo crear la nueva sesión.');

            $move = $pdo->prepare(
                "UPDATE ventas_cabecera
                 SET id_caja = ?, id_sesion_caja = ?
                 WHERE id_empresa = ? AND id_sucursal = ? AND id_almacen = ?
                   AND (id_caja = ? OR id_sesion_caja = ?) AND id >= ?"
            );
            $move->execute([
                $newSessionId, $newSessionId,
                (int)$session['id_empresa'], (int)$session['id_sucursal'], (int)$session['id_almacen'],
                $sessionId, $sessionId, $splitTicket,
            ]);
            if ($move->rowCount() !== (int)$toMove['tickets']) {
                throw new RuntimeException('El número de tickets reasignados no coincide con la vista previa.');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }

        log_audit($pdo, 'SESION_DIVIDIDA', (string)($_SESSION['admin_user'] ?? 'Admin'), [
            'sesion_original' => $sessionId,
            'nueva_sesion' => $newSessionId,
            'ticket_inicio_nueva' => $splitTicket,
            'tickets_reasignados' => (int)$toMove['tickets'],
            'fecha_contable_nueva' => $newDate,
            'monto_sistema_cierre' => round($systemTotal, 2),
            'efectivo_real_cierre' => round($realCash, 2),
            'diferencia' => round($difference, 2),
        ]);

        caja_dividir_json([
            'status' => 'success',
            'msg' => 'Sesión dividida correctamente.',
            'original_session' => $sessionId,
            'new_session' => $newSessionId,
            'split_ticket' => $splitTicket,
            'moved_tickets' => (int)$toMove['tickets'],
            'close_system_total' => round($systemTotal, 2),
            'close_real_total' => round($realCash, 2),
            'difference' => round($difference, 2),
        ]);
    } catch (Throwable $e) {
        caja_dividir_json(['status' => 'error', 'msg' => $e->getMessage()], 400);
    }
}

$stmtSessions = $pdo->prepare(
    "SELECT s.id, s.nombre_cajero, s.fecha_apertura, s.fecha_cierre,
            s.fecha_contable, s.estado, s.id_sucursal, s.id_almacen,
            COALESCE(suc.nombre, CONCAT('Sucursal ', s.id_sucursal)) AS sucursal_nombre,
            COALESCE(alm.nombre, CONCAT('Almacén ', s.id_almacen)) AS almacen_nombre,
            COUNT(v.id) AS tickets, MIN(v.id) AS min_ticket, MAX(v.id) AS max_ticket,
            COALESCE(SUM(v.total), 0) AS total
     FROM caja_sesiones s
     LEFT JOIN sucursales suc ON suc.id = s.id_sucursal
     LEFT JOIN almacenes alm ON alm.id = s.id_almacen
     LEFT JOIN ventas_cabecera v ON v.id_empresa = s.id_empresa
        AND v.id_sucursal = s.id_sucursal AND v.id_almacen = s.id_almacen
        AND (v.id_caja = s.id OR v.id_sesion_caja = s.id)
     WHERE s.id_empresa = ?
     GROUP BY s.id
     ORDER BY s.id DESC
     LIMIT 150"
);
$stmtSessions->execute([$empresaID]);
$sessions = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dividir sesión de caja</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background:#f4f7fb; color:#1f2937; }
        .page-wrap { max-width:1180px; margin:30px auto 100px; padding:0 16px; }
        .hero { background:linear-gradient(135deg,#0d3b66,#146c94); color:#fff; border-radius:16px; padding:24px; box-shadow:0 8px 24px rgba(13,59,102,.18); }
        .card { border:0; border-radius:14px; box-shadow:0 4px 16px rgba(15,23,42,.07); }
        .preview-box { min-height:130px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px; }
        .mono { font-variant-numeric:tabular-nums; }
        .session-open { color:#146c43; font-weight:700; }
        .session-closed { color:#6b7280; font-weight:700; }
        .table td, .table th { vertical-align:middle; }
    </style>
</head>
<body>
<main class="page-wrap">
    <section class="hero mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <div class="text-uppercase small fw-bold opacity-75">Herramientas de caja</div>
                <h1 class="h3 fw-bold mb-2"><i class="fas fa-code-branch me-2"></i>Dividir sesión de caja</h1>
                <p class="mb-0 opacity-75">Cierra una sesión hasta un ticket y crea otra sesión con los tickets desde el número indicado.</p>
            </div>
            <a href="pos.php" class="btn btn-light fw-bold"><i class="fas fa-cash-register me-1"></i> Volver al POS</a>
        </div>
    </section>

    <div class="alert alert-warning border-0 shadow-sm">
        <i class="fas fa-triangle-exclamation me-2"></i>
        La división mueve los tickets por su <strong>número de ticket</strong>. La sesión original quedará cerrada y la nueva quedará abierta.
    </div>

    <section class="card p-4 mb-4">
        <form id="splitForm" novalidate>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label for="sessionId" class="form-label fw-bold">Sesión original</label>
                    <select id="sessionId" class="form-select" required>
                        <option value="">Seleccione una sesión...</option>
                        <?php foreach ($sessions as $session): ?>
                            <option value="<?= (int)$session['id'] ?>">
                                #<?= (int)$session['id'] ?> · <?= htmlspecialchars((string)$session['sucursal_nombre']) ?> / <?= htmlspecialchars((string)$session['almacen_nombre']) ?> · <?= htmlspecialchars((string)$session['fecha_contable']) ?> · <?= strtoupper((string)$session['estado']) ?> · <?= (int)$session['tickets'] ?> tickets
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label for="splitTicket" class="form-label fw-bold">Dividir desde ticket #</label>
                    <input type="number" id="splitTicket" class="form-control" min="1" step="1" required placeholder="Ej. 5585">
                </div>
                <div class="col-lg-3">
                    <label for="newDate" class="form-label fw-bold">Fecha contable nueva</label>
                    <input type="date" id="newDate" class="form-control" required value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4">
                    <label for="initialAmount" class="form-label fw-bold">Fondo inicial nueva sesión</label>
                    <input type="number" id="initialAmount" class="form-control" min="0" step="0.01" value="0">
                </div>
                <div class="col-md-4">
                    <label for="realCash" class="form-label fw-bold">Efectivo real al cerrar original</label>
                    <input type="number" id="realCash" class="form-control" min="0" step="0.01" placeholder="Igual al sistema si se deja vacío">
                </div>
                <div class="col-md-4">
                    <label for="splitNote" class="form-label fw-bold">Nota opcional</label>
                    <input type="text" id="splitNote" class="form-control" maxlength="300" placeholder="Motivo del corte">
                </div>
            </div>
            <div class="d-flex gap-2 mt-4 flex-wrap">
                <button type="button" id="previewBtn" class="btn btn-primary fw-bold"><i class="fas fa-eye me-1"></i> Ver vista previa</button>
                <button type="submit" id="splitBtn" class="btn btn-danger fw-bold" disabled><i class="fas fa-code-branch me-1"></i> Dividir sesión</button>
            </div>
        </form>
    </section>

    <section id="previewBox" class="preview-box p-4 mb-4 text-muted">
        Selecciona una sesión y escribe el ticket desde el cual comenzará la nueva sesión.
    </section>

    <section class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 gap-2 flex-wrap">
            <h2 class="h5 fw-bold mb-0"><i class="fas fa-list me-2 text-primary"></i>Sesiones de la empresa</h2>
            <span class="small text-muted">Mostrando las últimas <?= count($sessions) ?> sesiones</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Sesión</th><th>Contexto</th><th>Fecha contable</th><th>Estado</th><th>Tickets</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                    <tr>
                        <td class="fw-bold">#<?= (int)$session['id'] ?><br><small class="text-muted"><?= htmlspecialchars((string)$session['nombre_cajero']) ?></small></td>
                        <td><?= htmlspecialchars((string)$session['sucursal_nombre']) ?><br><small class="text-muted"><?= htmlspecialchars((string)$session['almacen_nombre']) ?></small></td>
                        <td class="mono"><?= htmlspecialchars((string)$session['fecha_contable']) ?></td>
                        <td class="<?= strtoupper((string)$session['estado']) === 'ABIERTA' ? 'session-open' : 'session-closed' ?>"><?= htmlspecialchars(strtoupper((string)$session['estado'])) ?></td>
                        <td class="mono"><?= (int)$session['tickets'] ?><?= $session['min_ticket'] !== null ? ' · #' . (int)$session['min_ticket'] . '–#' . (int)$session['max_ticket'] : '' ?></td>
                        <td class="mono">$<?= number_format((float)$session['total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sessions): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay sesiones disponibles.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const sessionSelect = document.getElementById('sessionId');
const splitTicketInput = document.getElementById('splitTicket');
const newDateInput = document.getElementById('newDate');
const initialInput = document.getElementById('initialAmount');
const realInput = document.getElementById('realCash');
const previewBox = document.getElementById('previewBox');
const previewBtn = document.getElementById('previewBtn');
const splitBtn = document.getElementById('splitBtn');
let latestPreview = null;
let newDateEdited = false;

function money(value) { return '$' + Number(value || 0).toLocaleString('es', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function headers() { return {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN}; }
function formData() {
    return {
        session_id: Number(sessionSelect.value || 0),
        split_ticket: Number(splitTicketInput.value || 0),
        new_date: newDateInput.value,
        monto_inicial: initialInput.value,
        efectivo_real: realInput.value,
        nota: document.getElementById('splitNote').value
    };
}
function renderPreview(data) {
    latestPreview = data;
    const s = data.session || {};
    const b = data.before || {};
    const m = data.to_move || {};
    previewBox.className = 'preview-box p-4 mb-4';
    previewBox.innerHTML = `
        <div class="d-flex justify-content-between gap-2 flex-wrap mb-3">
            <div><strong>Sesión #${s.id}</strong> · ${String(s.estado || '').toUpperCase()} · ${s.fecha_contable || ''}</div>
            <div class="text-muted">${s.nombre_cajero || 'Sin cajero'} · Sucursal ${s.id_sucursal} / Almacén ${s.id_almacen}</div>
        </div>
        <div class="row g-2 small">
            <div class="col-md-3"><div class="bg-white rounded p-2">Se quedan<br><strong>${b.tickets || 0} tickets</strong><br>${money(b.total)}</div></div>
            <div class="col-md-3"><div class="bg-white rounded p-2">Se mueven<br><strong>${m.tickets || 0} tickets</strong><br>${money(m.total)}</div></div>
            <div class="col-md-3"><div class="bg-white rounded p-2">Efectivo sistema<br><strong>${money(data.system_close_total)}</strong></div></div>
            <div class="col-md-3"><div class="bg-white rounded p-2">Nueva fecha<br><strong>${newDateInput.value || data.default_new_date}</strong></div></div>
        </div>
        ${data.warning_closed ? '<div class="alert alert-warning mt-3 mb-0 py-2"><i class="fas fa-triangle-exclamation me-1"></i>La sesión original ya está cerrada; se actualizará su cierre con los tickets que permanezcan en ella.</div>' : ''}
    `;
    splitBtn.disabled = Number(m.tickets || 0) < 1;
    if (!realInput.value) realInput.value = Number(data.system_close_total || 0).toFixed(2);
}
async function preview() {
    const data = formData();
    if (!data.session_id || !data.split_ticket) {
        previewBox.innerHTML = '<span class="text-danger">Selecciona la sesión e indica el ticket inicial.</span>';
        splitBtn.disabled = true;
        return null;
    }
    previewBtn.disabled = true;
    previewBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Calculando...';
    try {
        const response = await fetch('caja_dividir.php?action=preview', {method:'POST', headers:headers(), body:JSON.stringify(data)});
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.msg || 'No se pudo calcular la vista previa.');
        if (!newDateEdited && result.default_new_date) newDateInput.value = result.default_new_date;
        renderPreview(result);
        return result;
    } catch (error) {
        latestPreview = null;
        splitBtn.disabled = true;
        previewBox.innerHTML = '<span class="text-danger"><i class="fas fa-circle-exclamation me-1"></i>' + String(error.message || error) + '</span>';
        return null;
    } finally {
        previewBtn.disabled = false;
        previewBtn.innerHTML = '<i class="fas fa-eye me-1"></i> Ver vista previa';
    }
}
previewBtn.addEventListener('click', preview);
sessionSelect.addEventListener('change', () => { latestPreview = null; splitBtn.disabled = true; realInput.value = ''; newDateEdited = false; });
splitTicketInput.addEventListener('change', () => { latestPreview = null; splitBtn.disabled = true; });
newDateInput.addEventListener('change', () => { newDateEdited = true; if (latestPreview) renderPreview(latestPreview); });
document.getElementById('splitForm').addEventListener('submit', async event => {
    event.preventDefault();
    const checked = latestPreview || await preview();
    if (!checked || Number(checked.to_move?.tickets || 0) < 1) return;
    const data = formData();
    const ok = confirm(`Se cerrará la sesión #${data.session_id} y se moverán ${checked.to_move.tickets} tickets desde el #${data.split_ticket} a una nueva sesión del ${data.new_date}.\n\n¿Continuar?`);
    if (!ok) return;
    splitBtn.disabled = true;
    splitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Aplicando...';
    try {
        const response = await fetch('caja_dividir.php?action=split', {method:'POST', headers:headers(), body:JSON.stringify(data)});
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.msg || 'No se pudo dividir la sesión.');
        alert(`División completada. Nueva sesión #${result.new_session}. Tickets reasignados: ${result.moved_tickets}.`);
        location.reload();
    } catch (error) {
        alert(error.message || error);
        splitBtn.disabled = false;
        splitBtn.innerHTML = '<i class="fas fa-code-branch me-1"></i> Dividir sesión';
    }
});
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
