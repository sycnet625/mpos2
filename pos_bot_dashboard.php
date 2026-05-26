<?php
define('PALWEB_DB_ERROR_FORMAT', 'html');
define('POSBOT_API_ROOT', __DIR__);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/habana_delivery.php';
require_once __DIR__ . '/push_notify.php';
require_once __DIR__ . '/posbot_api/bootstrap.php';
require_once __DIR__ . '/posbot_api/repository.php';
require_once __DIR__ . '/posbot_api/helpers.php';

bot_ensure_tables($pdo);
$cfg = bot_cfg($pdo);
$bridge = bot_validate_bridge_for_campaign();

function pbd_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pbd_dt($value): string {
    $raw = trim((string)$value);
    if ($raw === '') return '-';
    $ts = strtotime($raw);
    return $ts ? date('d/m/Y H:i', $ts) : $raw;
}

function pbd_time_ago($value): string {
    $ts = strtotime((string)$value);
    if (!$ts) return 'Sin dato';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Hace ' . $diff . 's';
    if ($diff < 3600) return 'Hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'Hace ' . floor($diff / 3600) . ' h';
    return 'Hace ' . floor($diff / 86400) . ' d';
}

function pbd_status_label(string $status): string {
    $map = [
        'scheduled' => 'Programada',
        'queued' => 'En cola',
        'running' => 'Ejecutando',
        'waiting' => 'Esperando',
        'paused' => 'Pausada',
        'done' => 'Finalizada',
        'error' => 'Error',
        'failed' => 'Fallida',
        'missed' => 'Perdida',
    ];
    return $map[$status] ?? ($status !== '' ? ucfirst($status) : 'Sin estado');
}

function pbd_status_class(string $status): string {
    if (in_array($status, ['running', 'queued', 'waiting'], true)) return 'is-live';
    if ($status === 'scheduled') return 'is-scheduled';
    if (in_array($status, ['error', 'failed', 'missed'], true)) return 'is-danger';
    if ($status === 'paused') return 'is-paused';
    if ($status === 'done') return 'is-done';
    return 'is-muted';
}

function pbd_error_kind(string $error): string {
    $e = strtolower($error);
    if ($e === '') return 'Sin detalle';
    if (str_contains($e, 'qr') || str_contains($e, 'session') || str_contains($e, 'whatsapp web') || str_contains($e, 'bridge')) return 'Bridge / sesión';
    if (str_contains($e, 'timeout') || str_contains($e, 'protocol') || str_contains($e, 'watchdog')) return 'Timeout';
    if (str_contains($e, 'media') || str_contains($e, 'image') || str_contains($e, 'archivo') || str_contains($e, 'enoent')) return 'Imagen / media';
    if (str_contains($e, 'target') || str_contains($e, 'chat') || str_contains($e, 'invalid') || str_contains($e, 'destino')) return 'Destino inválido';
    if (str_contains($e, 'auth') || str_contains($e, 'login') || str_contains($e, 'unauthorized')) return 'Autenticación';
    if (str_contains($e, 'horario') || str_contains($e, 'ventana') || str_contains($e, 'schedule')) return 'Programación';
    return mb_substr(trim($error), 0, 48);
}

function pbd_next_runs(array $jobs, int $limit = 10): array {
    $dayNames = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
    $now = time();
    $out = [];
    foreach ($jobs as $job) {
        if (empty($job['schedule_enabled']) || (string)($job['status'] ?? '') === 'paused') continue;
        $time = substr(trim((string)($job['schedule_time'] ?? '')), 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) continue;
        $days = is_array($job['schedule_days'] ?? null) ? array_map('intval', $job['schedule_days']) : [];
        if (!$days) continue;
        for ($i = 0; $i < 8; $i++) {
            $base = strtotime('today +' . $i . ' day');
            $w = (int)date('w', $base);
            if (!in_array($w, $days, true)) continue;
            $ts = strtotime(date('Y-m-d', $base) . ' ' . $time);
            if ($ts && $ts >= ($now - 60)) {
                $out[] = [
                    'ts' => $ts,
                    'name' => (string)($job['name'] ?? 'Campaña'),
                    'group' => (string)($job['campaign_group'] ?? 'General'),
                    'label' => $dayNames[$w] . ' ' . date('H:i', $ts),
                    'targets' => count((array)($job['targets'] ?? [])),
                    'status' => (string)($job['status'] ?? ''),
                ];
                break;
            }
        }
    }
    usort($out, static fn($a, $b) => $a['ts'] <=> $b['ts']);
    return array_slice($out, 0, $limit);
}

function pbd_scalar(PDO $pdo, string $sql, $fallback = 0) {
    try {
        return $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return $fallback;
    }
}

$queue = bot_repo_read('promo_queue_file', ['jobs' => []]);
$jobs = array_map('bot_promo_job_defaults', is_array($queue['jobs'] ?? null) ? $queue['jobs'] : []);
usort($jobs, static function ($a, $b) {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

$now = time();
$hourBuckets = [];
for ($i = 23; $i >= 0; $i--) {
    $ts = strtotime('-' . $i . ' hour', $now);
    $key = date('Y-m-d H:00', $ts);
    $hourBuckets[$key] = ['label' => date('H:00', $ts), 'sent' => 0, 'ok' => 0, 'fail' => 0, 'scheduled' => 0];
}

$statusCounts = [];
$groupStats = [];
$errorKinds = [];
$recentEvents = [];
$campaignRows = [];
$totalMessagesSent = 0;
$totalTargetOk = 0;
$totalTargetFail = 0;
$totalRunSuccess = 0;
$totalRunFail = 0;
$activeCampaigns = 0;
$scheduledCampaigns = 0;
$pausedCampaigns = 0;
$targetsTotal = 0;
$lastRunTs = 0;

foreach ($jobs as $job) {
    $status = (string)($job['status'] ?? '');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    if (in_array($status, ['queued', 'running', 'waiting'], true)) $activeCampaigns++;
    if (!empty($job['schedule_enabled'])) $scheduledCampaigns++;
    if ($status === 'paused') $pausedCampaigns++;

    $group = trim((string)($job['campaign_group'] ?? 'General')) ?: 'General';
    $targets = count((array)($job['targets'] ?? []));
    $targetsTotal += $targets;
    if (!isset($groupStats[$group])) $groupStats[$group] = ['campaigns' => 0, 'sent' => 0, 'ok' => 0, 'fail' => 0, 'targets' => 0];
    $groupStats[$group]['campaigns']++;
    $groupStats[$group]['targets'] += $targets;

    $logs = is_array($job['log'] ?? null) ? $job['log'] : [];
    $sent = 0;
    $ok = 0;
    $fail = 0;
    foreach ($logs as $log) {
        if (!is_array($log)) continue;
        $logTs = strtotime((string)($log['at'] ?? '')) ?: 0;
        $messages = (int)($log['messages_sent'] ?? 0);
        $sent += $messages;
        if (($log['ok'] ?? null) === true) $ok++;
        if (($log['ok'] ?? null) === false) {
            $fail++;
            $kind = pbd_error_kind((string)($log['error_verbose'] ?? $log['error'] ?? 'Error desconocido'));
            $errorKinds[$kind] = ($errorKinds[$kind] ?? 0) + 1;
        }
        if ($logTs > 0) {
            $bucket = date('Y-m-d H:00', $logTs);
            if (isset($hourBuckets[$bucket])) {
                $hourBuckets[$bucket]['sent'] += $messages;
                if (($log['ok'] ?? null) === true) $hourBuckets[$bucket]['ok']++;
                if (($log['ok'] ?? null) === false) $hourBuckets[$bucket]['fail']++;
            }
            $recentEvents[] = [
                'ts' => $logTs,
                'at' => pbd_dt($log['at'] ?? ''),
                'campaign' => (string)($job['name'] ?? 'Campaña'),
                'group' => $group,
                'type' => (string)($log['type'] ?? (($log['ok'] ?? null) === false ? 'error' : 'envio')),
                'ok' => ($log['ok'] ?? null) === true,
                'target' => (string)($log['target_name'] ?? $log['target_id'] ?? ''),
                'messages' => $messages,
                'error' => (string)($log['error'] ?? ''),
            ];
        }
    }
    if (!empty($job['schedule_enabled'])) {
        $days = is_array($job['schedule_days'] ?? null) ? $job['schedule_days'] : [];
        $time = substr(trim((string)($job['schedule_time'] ?? '')), 0, 5);
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            foreach ($days as $d) {
                $candidate = strtotime(date('Y-m-d') . ' ' . $time);
                if ($candidate && (int)date('w', $candidate) === (int)$d) {
                    $bucket = date('Y-m-d H:00', $candidate);
                    if (isset($hourBuckets[$bucket])) $hourBuckets[$bucket]['scheduled']++;
                }
            }
        }
    }

    $runSuccess = (int)($job['total_success_runs'] ?? 0);
    $runFail = (int)($job['total_failed_runs'] ?? 0);
    $totalRunSuccess += $runSuccess;
    $totalRunFail += $runFail;
    $totalMessagesSent += $sent;
    $totalTargetOk += $ok;
    $totalTargetFail += $fail;
    $groupStats[$group]['sent'] += $sent;
    $groupStats[$group]['ok'] += $ok;
    $groupStats[$group]['fail'] += $fail;

    $jobLastRunTs = max(
        strtotime((string)($job['last_run_finished_at'] ?? '')) ?: 0,
        strtotime((string)($job['last_success_at'] ?? '')) ?: 0,
        strtotime((string)($job['last_error_at'] ?? '')) ?: 0
    );
    if ($jobLastRunTs > $lastRunTs) $lastRunTs = $jobLastRunTs;

    $campaignRows[] = [
        'name' => (string)($job['name'] ?? 'Campaña'),
        'group' => $group,
        'status' => $status,
        'schedule' => !empty($job['schedule_enabled']) ? trim((string)($job['schedule_time'] ?? '')) : 'Manual',
        'targets' => $targets,
        'sent' => $sent,
        'ok' => $ok,
        'fail' => $fail,
        'success_runs' => $runSuccess,
        'failed_runs' => $runFail,
        'last_run' => pbd_dt($job['last_run_finished_at'] ?: ($job['last_success_at'] ?: $job['last_error_at'] ?? '')),
        'last_ago' => pbd_time_ago($job['last_run_finished_at'] ?: ($job['last_success_at'] ?: $job['last_error_at'] ?? '')),
        'last_error' => (string)($job['last_error'] ?? ''),
        'note' => (string)($job['queue_note'] ?? ''),
    ];
}

arsort($errorKinds);
uasort($groupStats, static fn($a, $b) => ($b['sent'] + $b['targets']) <=> ($a['sent'] + $a['targets']));
usort($recentEvents, static fn($a, $b) => $b['ts'] <=> $a['ts']);
$recentEvents = array_slice($recentEvents, 0, 12);
$upcomingRuns = pbd_next_runs($jobs, 10);

$sessions = (int)pbd_scalar($pdo, "SELECT COUNT(*) FROM pos_bot_sessions", 0);
$msgsToday = (int)pbd_scalar($pdo, "SELECT COUNT(*) FROM pos_bot_messages WHERE DATE(created_at)=CURDATE()", 0);
$ordersToday = (int)pbd_scalar($pdo, "SELECT COUNT(*) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()", 0);
$salesToday = (float)pbd_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM pos_bot_orders WHERE DATE(created_at)=CURDATE()", 0);

$chartData = [
    'hours' => array_values($hourBuckets),
    'campaigns' => array_slice(array_map(static fn($r) => [
        'name' => $r['name'],
        'sent' => $r['sent'],
        'ok' => $r['ok'],
        'fail' => $r['fail'],
    ], $campaignRows), 0, 12),
    'groups' => array_slice(array_map(static fn($name, $r) => [
        'name' => $name,
        'sent' => $r['sent'],
        'ok' => $r['ok'],
        'fail' => $r['fail'],
        'targets' => $r['targets'],
    ], array_keys($groupStats), $groupStats), 0, 10),
    'errors' => array_slice(array_map(static fn($name, $count) => ['name' => $name, 'count' => $count], array_keys($errorKinds), $errorKinds), 0, 8),
];

$bridgeState = (string)($bridge['state'] ?? 'unknown');
$bridgeOk = !empty($bridge['ok']);
$botAutoState = bot_autoreply_state($cfg);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard POS BOT</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/all.min.css">
<style>
:root{
    --bg:#eef3f8;
    --panel:#ffffff;
    --panel-soft:#f8fafc;
    --ink:#102033;
    --muted:#667085;
    --line:#d9e2ec;
    --brand:#0f766e;
    --brand-2:#2563eb;
    --ok:#12b76a;
    --warn:#f59e0b;
    --danger:#d92d20;
    --shadow:0 18px 45px rgba(16,32,51,.10);
}
body{min-height:100vh;background:linear-gradient(180deg,#f6f9fc 0%,var(--bg) 100%);color:var(--ink);font-family:"Segoe UI",Tahoma,sans-serif;}
.dash-shell{max-width:1680px;margin:0 auto;padding:24px;}
.hero{background:linear-gradient(135deg,#0f766e 0%,#175cd3 100%);color:#fff;border-radius:18px;padding:24px;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.hero:after{content:"";position:absolute;right:-80px;bottom:-120px;width:320px;height:320px;background:rgba(255,255,255,.12);transform:rotate(24deg);border-radius:42px;}
.hero-main{position:relative;z-index:1;display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;}
.hero h1{font-weight:800;font-size:1.8rem;margin:0 0 6px;}
.hero p{margin:0;color:rgba(255,255,255,.78);}
.hero-actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn-hero{background:rgba(255,255,255,.95);border:0;color:#0f172a;font-weight:700;}
.btn-ghost{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.28);color:#fff;font-weight:700;}
.kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin-top:16px;}
.kpi{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 10px 28px rgba(16,32,51,.06);}
.kpi .label{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:800;margin-bottom:8px;}
.kpi .value{font-size:1.72rem;line-height:1;font-weight:850;}
.kpi .hint{font-size:.84rem;color:var(--muted);margin-top:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 32px rgba(16,32,51,.07);}
.panel-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:center;}
.panel-title{font-size:.95rem;font-weight:850;margin:0;}
.panel-sub{font-size:.82rem;color:var(--muted);margin-top:2px;}
.panel-body{padding:18px;}
.dashboard-grid{display:grid;grid-template-columns:1.35fr .65fr;gap:16px;margin-top:16px;}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;}
.canvas-wrap{height:260px;position:relative;}
canvas{width:100%;height:100%;display:block;}
.status-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:.78rem;font-weight:800;border:1px solid var(--line);}
.status-pill:before{content:"";width:8px;height:8px;border-radius:999px;background:var(--muted);}
.is-live{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.is-live:before{background:var(--warn);}
.is-scheduled{background:#eff6ff;color:#175cd3;border-color:#bfdbfe}.is-scheduled:before{background:var(--brand-2);}
.is-danger{background:#fef3f2;color:#b42318;border-color:#fecaca}.is-danger:before{background:var(--danger);}
.is-paused{background:#f2f4f7;color:#475467}.is-paused:before{background:#667085;}
.is-done{background:#ecfdf3;color:#027a48;border-color:#bbf7d0}.is-done:before{background:var(--ok);}
.is-muted{background:#f8fafc;color:#667085;}
.table-dashboard{margin:0;font-size:.9rem;}
.table-dashboard thead th{color:var(--muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--line);white-space:nowrap;}
.table-dashboard td{vertical-align:middle;border-color:#edf2f7;}
.name-cell{font-weight:800;color:#102033;}
.muted{color:var(--muted);}
.error-line{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.schedule-list{display:grid;gap:10px;}
.schedule-item{display:grid;grid-template-columns:70px 1fr auto;gap:10px;align-items:center;padding:11px;border:1px solid var(--line);border-radius:12px;background:var(--panel-soft);}
.schedule-time{font-weight:850;color:var(--brand-2);}
.event-list{display:grid;gap:9px;max-height:420px;overflow:auto;padding-right:4px;}
.event{display:grid;grid-template-columns:32px 1fr auto;gap:10px;align-items:start;padding:10px;border-radius:12px;background:var(--panel-soft);border:1px solid #edf2f7;}
.event-icon{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:#e0f2fe;color:#075985;}
.event.is-error .event-icon{background:#fee2e2;color:#b42318;}
.bar-list{display:grid;gap:10px;}
.bar-row{display:grid;grid-template-columns:minmax(110px,1fr) 2fr 42px;gap:10px;align-items:center;font-size:.86rem;}
.bar-track{height:10px;background:#e7eef6;border-radius:999px;overflow:hidden;}
.bar-fill{height:100%;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:999px;}
.bridge-card{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px;border-radius:14px;background:var(--panel-soft);border:1px solid var(--line);}
.bridge-led{width:14px;height:14px;border-radius:50%;background:var(--danger);box-shadow:0 0 0 5px rgba(217,45,32,.12);}
.bridge-led.ok{background:var(--ok);box-shadow:0 0 0 5px rgba(18,183,106,.12);}
@media (max-width:1200px){.kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr));}.dashboard-grid,.chart-grid{grid-template-columns:1fr;}}
@media (max-width:720px){.dash-shell{padding:14px}.hero{padding:18px}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.kpi .value{font-size:1.35rem}.schedule-item{grid-template-columns:1fr}.table-responsive{font-size:.82rem;}}
</style>
</head>
<body>
<main class="dash-shell">
    <section class="hero">
        <div class="hero-main">
            <div>
                <div class="text-uppercase fw-bold opacity-75 small mb-2">POS BOT / Operaciones de campañas</div>
                <h1><i class="fab fa-whatsapp me-2"></i>Dashboard de campañas</h1>
                <p>Vista ejecutiva de ejecución, fallos, mensajes, grupos y programación.</p>
            </div>
            <div class="hero-actions">
                <a class="btn btn-hero" href="pos_bot.php"><i class="fas fa-sliders me-1"></i>Gestionar bot</a>
                <a class="btn btn-ghost" href="pos_bot_dashboard.php"><i class="fas fa-sync me-1"></i>Refrescar</a>
                <a class="btn btn-ghost" href="dashboard.php"><i class="fas fa-arrow-left me-1"></i>Volver</a>
            </div>
        </div>
    </section>

    <section class="kpi-grid">
        <div class="kpi"><div class="label">Campañas</div><div class="value"><?php echo count($jobs); ?></div><div class="hint"><?php echo $scheduledCampaigns; ?> programadas</div></div>
        <div class="kpi"><div class="label">Activas ahora</div><div class="value"><?php echo $activeCampaigns; ?></div><div class="hint"><?php echo $pausedCampaigns; ?> pausadas</div></div>
        <div class="kpi"><div class="label">Ejecuciones OK</div><div class="value"><?php echo $totalRunSuccess; ?></div><div class="hint"><?php echo $totalTargetOk; ?> destinos OK</div></div>
        <div class="kpi"><div class="label">Ejecuciones fallo</div><div class="value"><?php echo $totalRunFail; ?></div><div class="hint"><?php echo $totalTargetFail; ?> fallos destino</div></div>
        <div class="kpi"><div class="label">Mensajes campaña</div><div class="value"><?php echo $totalMessagesSent; ?></div><div class="hint"><?php echo $targetsTotal; ?> destinos configurados</div></div>
        <div class="kpi"><div class="label">Mensajes hoy</div><div class="value"><?php echo $msgsToday; ?></div><div class="hint"><?php echo $sessions; ?> sesiones / <?php echo $ordersToday; ?> pedidos</div></div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">Histograma por hora</h2>
                    <div class="panel-sub">Mensajes enviados, destinos OK, fallos y campañas programadas en las últimas 24 horas.</div>
                </div>
                <span class="status-pill <?php echo $bridgeOk ? 'is-done' : 'is-danger'; ?>"><?php echo pbd_h($bridgeState); ?></span>
            </div>
            <div class="panel-body">
                <div class="canvas-wrap"><canvas id="hourChart"></canvas></div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">Estado operativo</h2>
                    <div class="panel-sub">Bridge, autorespuesta y última actividad.</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="bridge-card mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <span class="bridge-led <?php echo $bridgeOk ? 'ok' : ''; ?>"></span>
                        <div>
                            <div class="fw-bold">WhatsApp Web</div>
                            <div class="small muted"><?php echo pbd_h($bridge['message'] ?? $bridge['reason'] ?? $bridgeState); ?></div>
                        </div>
                    </div>
                    <span class="status-pill <?php echo $bridgeOk ? 'is-done' : 'is-danger'; ?>"><?php echo $bridgeOk ? 'Conectado' : 'Revisar'; ?></span>
                </div>
                <div class="bridge-card mb-3">
                    <div>
                        <div class="fw-bold">Autorespuesta</div>
                        <div class="small muted"><?php echo pbd_h($botAutoState['reason'] ?? 'Sin estado'); ?></div>
                    </div>
                    <span class="status-pill <?php echo !empty($botAutoState['effective_enabled']) ? 'is-done' : 'is-paused'; ?>"><?php echo !empty($botAutoState['effective_enabled']) ? 'Activa' : 'Apagada'; ?></span>
                </div>
                <div class="row g-2">
                    <div class="col-6"><div class="kpi m-0"><div class="label">Ventas bot hoy</div><div class="value">$<?php echo number_format($salesToday, 2); ?></div><div class="hint">Pedidos POS Bot</div></div></div>
                    <div class="col-6"><div class="kpi m-0"><div class="label">Última ejecución</div><div class="value" style="font-size:1.05rem"><?php echo $lastRunTs ? date('d/m H:i', $lastRunTs) : '-'; ?></div><div class="hint"><?php echo $lastRunTs ? pbd_time_ago(date('c', $lastRunTs)) : 'Sin historial'; ?></div></div></div>
                </div>
            </div>
        </div>
    </section>

    <section class="chart-grid">
        <div class="panel">
            <div class="panel-head"><div><h2 class="panel-title">Mensajes por campaña</h2><div class="panel-sub">Volumen enviado y fallos por campaña.</div></div></div>
            <div class="panel-body"><div class="canvas-wrap"><canvas id="campaignChart"></canvas></div></div>
        </div>
        <div class="panel">
            <div class="panel-head"><div><h2 class="panel-title">Grupos y destinos</h2><div class="panel-sub">Histograma por grupo de campaña.</div></div></div>
            <div class="panel-body"><div class="canvas-wrap"><canvas id="groupChart"></canvas></div></div>
        </div>
    </section>

    <section class="dashboard-grid">
        <div class="panel">
            <div class="panel-head"><div><h2 class="panel-title">Campañas</h2><div class="panel-sub">Estado, schedule, ejecución y errores resumidos.</div></div></div>
            <div class="table-responsive">
                <table class="table table-dashboard">
                    <thead><tr><th>Campaña</th><th>Grupo</th><th>Estado</th><th>Schedule</th><th>Destinos</th><th>Mensajes</th><th>OK / Fallos</th><th>Última vez</th><th>Error</th></tr></thead>
                    <tbody>
                    <?php if (!$campaignRows): ?>
                        <tr><td colspan="9" class="text-center muted p-4">Sin campañas registradas.</td></tr>
                    <?php endif; ?>
                    <?php foreach (array_slice($campaignRows, 0, 18) as $row): ?>
                        <tr>
                            <td><div class="name-cell"><?php echo pbd_h($row['name']); ?></div><?php if ($row['note']): ?><div class="small muted"><?php echo pbd_h($row['note']); ?></div><?php endif; ?></td>
                            <td><?php echo pbd_h($row['group']); ?></td>
                            <td><span class="status-pill <?php echo pbd_status_class($row['status']); ?>"><?php echo pbd_status_label($row['status']); ?></span></td>
                            <td><?php echo pbd_h($row['schedule']); ?></td>
                            <td><?php echo (int)$row['targets']; ?></td>
                            <td><?php echo (int)$row['sent']; ?></td>
                            <td><span class="text-success fw-bold"><?php echo (int)$row['ok']; ?></span> / <span class="text-danger fw-bold"><?php echo (int)$row['fail']; ?></span></td>
                            <td><div><?php echo pbd_h($row['last_run']); ?></div><div class="small muted"><?php echo pbd_h($row['last_ago']); ?></div></td>
                            <td><div class="error-line text-danger"><?php echo pbd_h($row['last_error'] ?: '-'); ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-grid gap-3">
            <div class="panel">
                <div class="panel-head"><div><h2 class="panel-title">Próximas programaciones</h2><div class="panel-sub">Schedule más cercano.</div></div></div>
                <div class="panel-body">
                    <div class="schedule-list">
                    <?php if (!$upcomingRuns): ?>
                        <div class="muted">No hay campañas programadas activas.</div>
                    <?php endif; ?>
                    <?php foreach ($upcomingRuns as $run): ?>
                        <div class="schedule-item">
                            <div class="schedule-time"><?php echo pbd_h($run['label']); ?></div>
                            <div>
                                <div class="fw-bold"><?php echo pbd_h($run['name']); ?></div>
                                <div class="small muted"><?php echo pbd_h($run['group']); ?> · <?php echo (int)$run['targets']; ?> destinos</div>
                            </div>
                            <span class="status-pill <?php echo pbd_status_class($run['status']); ?>"><?php echo pbd_status_label($run['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><div><h2 class="panel-title">Causas de fallos</h2><div class="panel-sub">Clasificación rápida de errores.</div></div></div>
                <div class="panel-body">
                    <div class="bar-list">
                    <?php $maxErr = max(1, $errorKinds ? max($errorKinds) : 1); ?>
                    <?php if (!$errorKinds): ?><div class="muted">No hay fallos registrados.</div><?php endif; ?>
                    <?php foreach (array_slice($errorKinds, 0, 8, true) as $name => $count): ?>
                        <div class="bar-row">
                            <div class="text-truncate"><?php echo pbd_h($name); ?></div>
                            <div class="bar-track"><div class="bar-fill" style="width:<?php echo max(4, round(($count / $maxErr) * 100)); ?>%"></div></div>
                            <div class="fw-bold text-end"><?php echo (int)$count; ?></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="chart-grid">
        <div class="panel">
            <div class="panel-head"><div><h2 class="panel-title">Eventos recientes</h2><div class="panel-sub">Últimos movimientos de campañas.</div></div></div>
            <div class="panel-body">
                <div class="event-list">
                <?php if (!$recentEvents): ?><div class="muted">Sin eventos recientes.</div><?php endif; ?>
                <?php foreach ($recentEvents as $event): ?>
                    <div class="event <?php echo $event['ok'] ? '' : 'is-error'; ?>">
                        <div class="event-icon"><i class="fas <?php echo $event['ok'] ? 'fa-check' : 'fa-triangle-exclamation'; ?>"></i></div>
                        <div>
                            <div class="fw-bold"><?php echo pbd_h($event['campaign']); ?></div>
                            <div class="small muted"><?php echo pbd_h($event['at']); ?> · <?php echo pbd_h($event['group']); ?> · <?php echo pbd_h($event['target'] ?: $event['type']); ?></div>
                            <?php if ($event['error']): ?><div class="small text-danger"><?php echo pbd_h($event['error']); ?></div><?php endif; ?>
                        </div>
                        <div class="fw-bold"><?php echo (int)$event['messages']; ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><div><h2 class="panel-title">Estados</h2><div class="panel-sub">Distribución de campañas por estado actual.</div></div></div>
            <div class="panel-body">
                <div class="bar-list">
                <?php $maxStatus = max(1, $statusCounts ? max($statusCounts) : 1); ?>
                <?php foreach ($statusCounts as $status => $count): ?>
                    <div class="bar-row">
                        <div><span class="status-pill <?php echo pbd_status_class((string)$status); ?>"><?php echo pbd_status_label((string)$status); ?></span></div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?php echo max(4, round(($count / $maxStatus) * 100)); ?>%"></div></div>
                        <div class="fw-bold text-end"><?php echo (int)$count; ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$statusCounts): ?><div class="muted">Sin campañas.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
const dashData = <?php echo json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function drawBars(canvasId, rows, opts = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const rect = canvas.parentElement.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.max(320, rect.width) * dpr;
    canvas.height = Math.max(220, rect.height) * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    const w = canvas.width / dpr;
    const h = canvas.height / dpr;
    ctx.clearRect(0, 0, w, h);
    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#667085';
    const pad = {l: opts.horizontal ? 120 : 34, r: 18, t: 16, b: 34};
    const plotW = w - pad.l - pad.r;
    const plotH = h - pad.t - pad.b;
    const max = Math.max(1, ...rows.map(r => Math.max(Number(r.sent || 0), Number(r.targets || 0), Number(r.ok || 0), Number(r.fail || 0), Number(r.scheduled || 0))));
    ctx.strokeStyle = '#e5edf5';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + plotH - (plotH * i / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
    }
    if (opts.horizontal) {
        const rowH = plotH / Math.max(1, rows.length);
        rows.forEach((r, i) => {
            const y = pad.t + i * rowH + 6;
            const label = String(r.name || '').slice(0, 18);
            const sentW = plotW * Number(r.sent || r.targets || 0) / max;
            const failW = plotW * Number(r.fail || 0) / max;
            ctx.fillStyle = '#475467';
            ctx.fillText(label, 8, y + 14);
            ctx.fillStyle = '#0f766e';
            ctx.fillRect(pad.l, y, Math.max(2, sentW), Math.max(8, rowH - 12));
            if (failW > 0) {
                ctx.fillStyle = '#d92d20';
                ctx.fillRect(pad.l, y + Math.max(8, rowH - 12) - 4, Math.max(2, failW), 4);
            }
        });
        return;
    }
    const n = Math.max(1, rows.length);
    const slot = plotW / n;
    rows.forEach((r, i) => {
        const x = pad.l + i * slot;
        const barW = Math.max(5, slot / 5);
        const values = [
            {v:Number(r.sent || 0), c:'#0f766e'},
            {v:Number(r.ok || 0), c:'#12b76a'},
            {v:Number(r.fail || 0), c:'#d92d20'},
            {v:Number(r.scheduled || 0), c:'#2563eb'}
        ];
        values.forEach((item, j) => {
            const bh = plotH * item.v / max;
            ctx.fillStyle = item.c;
            ctx.fillRect(x + j * (barW + 2), pad.t + plotH - bh, barW, bh);
        });
        if (i % Math.ceil(n / 8) === 0) {
            ctx.save();
            ctx.translate(x, h - 12);
            ctx.rotate(-Math.PI / 8);
            ctx.fillStyle = '#667085';
            ctx.fillText(String(r.label || r.name || '').slice(0, 8), 0, 0);
            ctx.restore();
        }
    });
}

function redrawCharts(){
    drawBars('hourChart', dashData.hours || []);
    drawBars('campaignChart', dashData.campaigns || [], {horizontal:true});
    drawBars('groupChart', dashData.groups || [], {horizontal:true});
}
window.addEventListener('resize', redrawCharts);
redrawCharts();
</script>
</body>
</html>
