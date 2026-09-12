<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ignore_user_abort(true);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function out_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_valid_dest(string $dest): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+$/', $dest);
}

function is_valid_ssl_domains(string $domains): bool {
    if ($domains === '' || !preg_match('/^[A-Za-z0-9.-]+(?:,[A-Za-z0-9.-]+)*$/', $domains)) {
        return false;
    }
    foreach (explode(',', $domains) as $domain) {
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/', $domain)) {
            return false;
        }
    }
    return true;
}

function is_valid_ssl_email(string $email): bool {
    return (bool)preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $email);
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{32}$/', $jobId);
}

function migration_meta_path(string $jobId): string {
    return '/tmp/palweb-migration-' . $jobId . '.json';
}

function migration_log_path(string $jobId): string {
    return '/tmp/palweb-migration-' . $jobId . '.log';
}

function migration_read_meta(string $jobId): ?array {
    if (!is_valid_job_id($jobId)) {
        return null;
    }
    $path = migration_meta_path($jobId);
    if (!is_file($path)) {
        return null;
    }
    $meta = json_decode((string)@file_get_contents($path), true);
    return is_array($meta) ? $meta : null;
}

function migration_log_state(string $jobId, array $meta, bool $includeTail = false): array {
    $logPath = (string)($meta['log'] ?? '');
    if (!preg_match('/^\/tmp\/palweb-migration-[a-f0-9]{32}\.log$/', $logPath)) {
        $logPath = migration_log_path($jobId);
    }
    $log = is_file($logPath) ? (string)@file_get_contents($logPath) : '';
    $logSize = is_file($logPath) ? (int)@filesize($logPath) : 0;
    $exitCode = null;
    if (preg_match('/MIGRATION_EXIT:(\d+)/', $log, $match)) {
        $exitCode = (int)$match[1];
    }
    $pid = (int)($meta['pid'] ?? 0);
    $alive = $pid > 0 && is_dir('/proc/' . $pid);
    $createdAt = (int)($meta['created_at'] ?? time());
    $done = $exitCode !== null || (!$alive && time() > $createdAt + 5);
    if ($done && $exitCode === null) {
        $exitCode = 255;
    }

    $steps = [];
    for ($i = 1; $i <= 10; $i++) {
        $steps[(string)$i] = ['state' => 'pending', 'label' => 'Pendiente'];
    }
    if (preg_match_all('/STEP_START:(\d+):([^\r\n]*)/', $log, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $stepNo = (int)$match[1];
            if ($stepNo >= 1 && $stepNo <= 10) {
                $steps[(string)$stepNo] = ['state' => 'running', 'label' => trim($match[2]) . '…'];
            }
        }
    }
    if (preg_match_all('/STEP_DONE:(\d+)/', $log, $matches)) {
        foreach ($matches[1] as $stepNo) {
            $stepNo = (int)$stepNo;
            if ($stepNo >= 1 && $stepNo <= 10) {
                $steps[(string)$stepNo] = ['state' => 'done', 'label' => 'Completado'];
            }
        }
    }

    $software = [];
    if (preg_match_all('/MIGRATION_STATUS\|([^|\r\n]+)\|([^|\r\n]+)\|([^\r\n]*)/', $log, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $software[$match[1]] = ['state' => $match[2], 'detail' => trim($match[3])];
        }
    }

    $progress = [];
    if (preg_match_all('/MIGRATION_PROGRESS\|([^|\r\n]+)\|(\d+)(?:\|([^\r\n]*))?/', $log, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $progress[$match[1]] = [
                'percent' => min(100, max(0, (int)$match[2])),
                'detail' => trim((string)($match[3] ?? ''))
            ];
        }
    }

    $mode = (string)($meta['mode'] ?? '');
    if ($mode === '' && preg_match('/modo:\s*(dry-run|run)/', $log, $match)) {
        $mode = $match[1];
    }
    $dest = (string)($meta['dest'] ?? '');
    if ($dest === '' && preg_match('/Destino:\s*([^|\r\n]+)/', $log, $match)) {
        $dest = trim($match[1]);
    }

    $result = [
        'done' => $done,
        'running' => !$done,
        'exit_code' => $exitCode,
        'pid' => $pid,
        'log_size' => $logSize,
        'steps' => $steps,
        'software' => $software,
        'progress' => $progress,
        'mode' => $mode,
        'dest' => $dest,
        'step' => (string)($meta['step'] ?? 'all'),
        'target_mode' => (string)($meta['target_mode'] ?? 'update'),
        'created_at' => $createdAt,
        'cancelled' => isset($meta['cancelled_at'])
    ];
    if ($includeTail) {
        $tailLength = min(65536, $logSize);
        $result['tail'] = $tailLength > 0 ? substr($log, -$tailLength) : '';
        $result['tail_offset'] = $logSize - $tailLength;
    }
    return $result;
}

function migration_child_pids(int $parentPid): array {
    $children = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $statPath) {
        $stat = @file_get_contents($statPath);
        if ($stat === false) {
            continue;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            continue;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 2)));
        $pid = (int)basename(dirname($statPath));
        $ppid = (int)($fields[1] ?? 0);
        if ($ppid === $parentPid && $pid > 1) {
            $children[] = $pid;
        }
    }
    return $children;
}

function migration_process_tree(int $rootPid): array {
    $result = [$rootPid];
    $queue = [$rootPid];
    while ($queue) {
        $parent = array_shift($queue);
        foreach (migration_child_pids($parent) as $child) {
            if (!in_array($child, $result, true)) {
                $result[] = $child;
                $queue[] = $child;
            }
        }
    }
    return $result;
}

function migration_signal_pids(array $pids, int $signal): void {
    foreach ($pids as $pid) {
        $pid = (int)$pid;
        if ($pid > 1 && function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        }
    }
}

function migration_cancel_process(array $meta): bool {
    $rootPid = (int)($meta['pid'] ?? 0);
    if ($rootPid <= 1 || !is_dir('/proc/' . $rootPid)) {
        return false;
    }
    $cmdline = (string)@file_get_contents('/proc/' . $rootPid . '/cmdline');
    if ($cmdline === '' || !str_contains($cmdline, 'migrar_espejo.sh')) {
        return false;
    }
    $processGroup = (int)($meta['process_group'] ?? 0);
    if ($processGroup > 1 && $processGroup === $rootPid && function_exists('posix_kill')) {
        $termSignal = defined('SIGTERM') ? SIGTERM : 15;
        @posix_kill(-$processGroup, $termSignal);
        usleep(400000);
        if (is_dir('/proc/' . $rootPid)) {
            @posix_kill(-$processGroup, defined('SIGKILL') ? SIGKILL : 9);
        }
        return true;
    }
    $pids = migration_process_tree($rootPid);
    migration_signal_pids(array_reverse($pids), defined('SIGTERM') ? SIGTERM : 15);
    usleep(400000);
    $remaining = array_values(array_filter($pids, static fn(int $pid): bool => is_dir('/proc/' . $pid)));
    if ($remaining) {
        migration_signal_pids(array_reverse($remaining), defined('SIGKILL') ? SIGKILL : 9);
    }
    return true;
}

function migration_find_active_job(): ?array {
    $files = glob('/tmp/palweb-migration-*.json') ?: [];
    usort($files, static fn(string $a, string $b): int => (int)@filemtime($b) <=> (int)@filemtime($a));
    foreach ($files as $metaPath) {
        if (!preg_match('/palweb-migration-([a-f0-9]{32})\.json$/', $metaPath, $match)) {
            continue;
        }
        $jobId = $match[1];
        $meta = migration_read_meta($jobId);
        if (!is_array($meta)) {
            continue;
        }
        $state = migration_log_state($jobId, $meta, true);
        if (!$state['done']) {
            return ['job_id' => $jobId, 'meta' => $meta, 'state' => $state];
        }
    }
    return null;
}

if ($action === 'check') {
    $script = '/var/www/migrar_espejo.sh';
    $exists = file_exists($script) && is_executable($script);
    $sshpass = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
    out_json([
        'ok' => true,
        'script_ready' => $exists,
        'script_path' => $script,
        'sshpass_available' => $sshpass !== ''
    ]);
}

// Sin acción: mostrar el dashboard. Las acciones POST siguen devolviendo JSON
// para que el propio dashboard y cualquier integración existente puedan usarlo.
if ($action === '') {
    header('Content-Type: text/html; charset=utf-8');
    $self = htmlspecialchars($_SERVER['PHP_SELF'] ?? 'admin_migracion_api.php', ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Panel de migración y actualización de servidores PalWeb POS">
        <title>Migración de servidor | PalWeb POS</title>
        <link rel="stylesheet" href="assets/css/bootstrap.min.css">
        <link rel="stylesheet" href="assets/css/all.min.css">
        <link rel="stylesheet" href="assets/css/inventory-suite.css">
        <style>
            :root { --mig-bg:#07111f; --mig-card:rgba(15,31,52,.82); --mig-line:rgba(148,163,184,.16); --mig-blue:#38bdf8; --mig-green:#34d399; --mig-amber:#fbbf24; --mig-red:#fb7185; }
            body.mig-page { min-height:100vh; background:radial-gradient(circle at 5% 0%,#163456 0,transparent 34%),radial-gradient(circle at 100% 20%,#15304e 0,transparent 30%),var(--mig-bg); color:#e5eefb; }
            .mig-shell { max-width:1500px; margin:0 auto; padding:28px 18px 70px; }
            .mig-card { background:var(--mig-card); border:1px solid var(--mig-line); border-radius:22px; box-shadow:0 20px 60px rgba(0,0,0,.2); backdrop-filter:blur(16px); }
            .mig-hero { padding:28px; position:relative; overflow:hidden; }
            .mig-hero:after { content:""; width:260px; height:260px; position:absolute; right:-100px; top:-120px; border-radius:50%; background:rgba(56,189,248,.13); filter:blur(3px); }
            .eyebrow { color:#7dd3fc; font-size:.72rem; letter-spacing:.16em; text-transform:uppercase; font-weight:700; }
            .muted { color:#9fb2c9; }
            .control-label { color:#b9c9dc; font-size:.76rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.4rem; }
            .mig-input, .mig-select { background:#0b1a2d!important; border:1px solid #29415d!important; color:#edf6ff!important; border-radius:11px!important; min-height:45px; }
            .mig-input:focus, .mig-select:focus { border-color:var(--mig-blue)!important; box-shadow:0 0 0 .2rem rgba(56,189,248,.15)!important; }
            .mode-choice { border:1px solid var(--mig-line); border-radius:16px; padding:14px; height:100%; cursor:pointer; transition:.2s; background:rgba(2,8,23,.22); }
            .mode-choice:hover, .mode-choice.active { border-color:var(--mig-blue); background:rgba(14,116,144,.16); }
            .mode-choice input { accent-color:var(--mig-blue); }
            .status-pill { display:inline-flex; align-items:center; gap:7px; padding:7px 12px; border-radius:999px; background:rgba(148,163,184,.1); color:#bad0e6; font-size:.78rem; }
            .status-pill.ok { color:#a7f3d0; background:rgba(16,185,129,.14); }
            .status-pill.warn { color:#fde68a; background:rgba(245,158,11,.14); }
            .status-pill.error { color:#fecdd3; background:rgba(244,63,94,.14); }
            .gauge-wrap { display:grid; place-items:center; min-height:178px; position:relative; }
            .gauge { width:150px; height:150px; border-radius:50%; background:conic-gradient(var(--mig-blue) var(--gauge,0%),rgba(148,163,184,.13) 0); display:grid; place-items:center; position:relative; }
            .gauge:after { content:""; position:absolute; inset:11px; border-radius:50%; background:#0b1a2d; border:1px solid var(--mig-line); }
            .gauge-value { position:relative; z-index:1; text-align:center; font-size:1.55rem; font-weight:800; }
            .gauge-value small { display:block; color:#9fb2c9; font-size:.68rem; font-weight:600; }
            .step-card { border:1px solid var(--mig-line); border-left:4px solid #64748b; border-radius:15px; padding:14px; height:100%; background:rgba(2,8,23,.2); transition:.2s; }
            .step-card.pending { border-left-color:var(--mig-amber); }
            .step-card.running { border-left-color:var(--mig-blue); box-shadow:0 0 0 1px rgba(56,189,248,.25); }
            .step-card.done { border-left-color:var(--mig-green); }
            .step-card.error { border-left-color:var(--mig-red); }
            .step-number { width:31px; height:31px; border-radius:10px; display:grid; place-items:center; background:rgba(56,189,248,.12); color:#7dd3fc; font-weight:800; }
            .software-card { border:1px solid var(--mig-line); border-radius:14px; padding:13px; background:rgba(2,8,23,.2); min-height:92px; }
            .software-state { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; font-weight:800; }
            .software-state.pending,.software-state.checking { color:#fcd34d; }
            .software-state.installing { color:#7dd3fc; }
            .software-state.ok { color:#86efac; }
            .software-state.failed { color:#fda4af; }
            .transfer-card { border:1px solid var(--mig-line); border-radius:14px; padding:13px; background:rgba(2,8,23,.2); }
            .transfer-track { height:9px; border-radius:999px; overflow:hidden; background:rgba(148,163,184,.16); }
            .transfer-fill { height:100%; width:0; border-radius:inherit; background:linear-gradient(90deg,#38bdf8,#34d399); transition:width .35s ease; }
            .log-box { min-height:170px; max-height:360px; overflow:auto; background:#030914; border:1px solid var(--mig-line); color:#cce3fa; border-radius:15px; padding:14px; font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace; white-space:pre-wrap; }
            .btn-mig-primary { background:linear-gradient(135deg,#0284c7,#2563eb); border:0; color:#fff; }
            .btn-mig-primary:hover { color:#fff; filter:brightness(1.1); }
            .btn-mig-green { background:linear-gradient(135deg,#059669,#16a34a); border:0; color:#fff; }
            .btn-mig-green:hover { color:#fff; filter:brightness(1.1); }
            .form-check-input { background-color:#0b1a2d; border-color:#42607d; }
            .form-check-input:checked { background-color:#0284c7; border-color:#0284c7; }
            @media (max-width:700px) { .mig-shell{padding:15px 10px 60px}.mig-hero{padding:20px}.gauge-wrap{min-height:150px}.mig-hero h1{font-size:1.55rem} }
        </style>
    </head>
    <body class="mig-page">
    <main class="mig-shell">
        <section class="mig-card mig-hero mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 position-relative" style="z-index:1">
                <div>
                    <div class="eyebrow mb-2"><i class="fa-solid fa-server me-1"></i>Infraestructura / PalWeb POS</div>
                    <h1 class="h2 fw-bold mb-2">Centro de migración de servidores</h1>
                    <p class="muted mb-0">Crea un servidor nuevo desde este origen o actualiza uno existente con los últimos cambios del sistema.</p>
                </div>
                <div class="text-lg-end">
                    <span id="systemStatus" class="status-pill"><i class="fa-solid fa-circle-notch fa-spin"></i>Preparando panel</span>
                    <div class="small muted mt-2">Modo seguro: simulación antes de aplicar</div>
                </div>
            </div>
            <div id="activeNotice" class="alert alert-warning d-none mt-4 mb-0" role="status"></div>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="mig-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div><div class="eyebrow">Destino</div><h2 class="h5 mb-0">Configurar servidor remoto</h2></div>
                        <i class="fa-solid fa-network-wired text-info fs-4"></i>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="control-label" for="dest">Usuario y destino SSH</label><input id="dest" class="form-control mig-input" placeholder="root@203.0.113.10" autocomplete="username"></div>
                        <div class="col-md-3"><label class="control-label" for="sshPort">Puerto SSH</label><input id="sshPort" class="form-control mig-input" value="22" inputmode="numeric"></div>
                        <div class="col-md-3"><label class="control-label" for="sshPassword">Contraseña SSH <span class="text-secondary">(opcional)</span></label><input id="sshPassword" type="password" class="form-control mig-input" placeholder="••••••••" autocomplete="current-password"><div class="small muted mt-1">No se guarda ni se muestra en el registro.</div></div>
                        <div class="col-12"><div class="control-label">Tipo de operación</div><div class="row g-2">
                            <div class="col-md-6"><label class="mode-choice active d-block" data-choice="new"><input type="radio" name="targetMode" value="new" checked> <strong class="ms-1">Servidor nuevo</strong><div class="small muted mt-2 ms-4">Primera copia completa mediante tar+zstd, más rápida.</div></label></div>
                            <div class="col-md-6"><label class="mode-choice d-block" data-choice="update"><input type="radio" name="targetMode" value="update"> <strong class="ms-1">Actualizar existente</strong><div class="small muted mt-2 ms-4">Actualización incremental mediante rsync, sin borrar archivos.</div></label></div>
                        </div></div>
                        <div class="col-md-6"><label class="control-label" for="sslDomain">Dominio(s) HTTPS</label><input id="sslDomain" class="form-control mig-input" placeholder="ejemplo.com,www.ejemplo.com"><div class="small muted mt-1">Debe apuntar al servidor nuevo; separa varios dominios con comas.</div></div>
                        <div class="col-md-6"><label class="control-label" for="sslEmail">Correo para Certbot</label><input id="sslEmail" type="email" class="form-control mig-input" placeholder="admin@ejemplo.com"><div class="small muted mt-1">Certbot lo usa para avisos de renovación.</div></div>
                        <div class="col-md-4"><label class="control-label" for="dbScope">Base de datos</label><select id="dbScope" class="form-select mig-select"><option value="app">Solo base de datos PalWeb</option><option value="all">Todas las bases de datos</option></select></div>
                        <div class="col-md-4"><label class="control-label" for="runMode">Modo</label><select id="runMode" class="form-select mig-select"><option value="dry-run">Simulación segura</option><option value="run">Aplicar cambios</option></select></div>
                        <div class="col-md-4"><label class="control-label" for="stepSelect">Alcance</label><select id="stepSelect" class="form-select mig-select"><option value="all">Todos los pasos</option><option value="1">Solo instalación del stack</option><option value="2">Solo preflight</option><option value="3">Solo aplicación</option><option value="4">Solo dependencias PHP/Node</option><option value="5">Solo configuraciones</option><option value="6">Solo servicios y cron</option><option value="7">Solo usuarios</option><option value="8">Solo base de datos</option><option value="9">Solo reinicio y validación</option><option value="10">Solo smoke test</option></select></div>
                        <div class="col-12"><div class="d-flex flex-wrap gap-3 pt-1">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="syncImages" checked><label class="form-check-label" for="syncImages">Imágenes externas</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="syncConfigs" checked><label class="form-check-label" for="syncConfigs">Configuraciones</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="syncUsers"><label class="form-check-label" for="syncUsers">Usuarios y grupos</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="allowDelete"><label class="form-check-label" for="allowDelete">Borrar sobrantes en /var/www</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="setupSsl"><label class="form-check-label" for="setupSsl">Ejecutar Certbot y activar HTTPS después de instalar</label></div>
                        </div></div>
                        <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                            <button id="btnPreflight" class="btn btn-outline-info"><i class="fa-solid fa-stethoscope me-1"></i>Verificar conexión</button>
                            <button id="btnSimulate" class="btn btn-mig-primary"><i class="fa-solid fa-flask me-1"></i>Simular migración</button>
                            <button id="btnRun" class="btn btn-mig-green"><i class="fa-solid fa-rocket me-1"></i>Aplicar migración</button>
                            <button id="btnCancel" class="btn btn-outline-danger d-none"><i class="fa-solid fa-stop me-1"></i>Detener migración activa</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="mig-card p-4 h-100">
                    <div class="eyebrow mb-1">Estado de ejecución</div><h2 class="h5">Progreso global</h2>
                    <div class="gauge-wrap"><div id="gauge" class="gauge" style="--gauge:0%"><div class="gauge-value"><span id="gaugeValue">0%</span><small id="gaugeLabel">Listo</small></div></div></div>
                    <div class="row g-2 text-center small"><div class="col-4"><div id="doneCount" class="h5 mb-0">0</div><div class="muted">Completados</div></div><div class="col-4"><div id="pendingCount" class="h5 mb-0">10</div><div class="muted">Pendientes</div></div><div class="col-4"><div id="errorCount" class="h5 mb-0 text-danger">0</div><div class="muted">Errores</div></div></div>
                </div>
            </div>
        </section>

        <section class="mig-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="eyebrow">Pipeline</div><h2 class="h5 mb-0">Pasos de la migración</h2></div><span id="operationBadge" class="status-pill">Sin ejecutar</span></div>
            <div id="stepsGrid" class="row g-3"></div>
        </section>

        <section class="mig-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="eyebrow">Instalación remota</div><h2 class="h5 mb-0">Estado de cada software</h2></div><span class="small muted">Actualización en tiempo real</span></div>
            <div id="softwareGrid" class="row g-2"></div>
        </section>

        <section class="mig-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="eyebrow">Transferencia inicial</div><h2 class="h5 mb-0">Progreso tar+zstd</h2></div><span class="small muted">Se recupera al volver a abrir el panel</span></div>
            <div id="transferProgressGrid" class="row g-2"></div>
        </section>

        <section class="mig-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3"><div><div class="eyebrow">Auditoría</div><h2 class="h5 mb-0">Registro de ejecución</h2></div><button id="btnClearLog" class="btn btn-sm btn-outline-secondary">Limpiar</button></div>
            <div id="logBox" class="log-box" role="log" aria-live="polite">Panel listo. Introduce el destino SSH y ejecuta una simulación.</div>
        </section>
    </main>
    <script>
    (() => {
        const endpoint = <?= json_encode($self, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const steps = [
            ['Instalar stack','Ubuntu 24.04, nginx, PHP, MySQL, Certbot y Node'],
            ['Preflight remoto','Permisos, comandos, espacio y respaldo'],
            ['Aplicación','/var/www e imágenes externas'],
            ['Dependencias','Node/WhatsApp y Composer si existen'],
            ['Configuraciones','nginx, PHP, Apache, MySQL y certificados'],
            ['Servicios','systemd, cron y recarga de unidades'],
            ['Usuarios','Usuarios y grupos del sistema'],
            ['Base de datos','Dump y restore de PalWeb'],
            ['Reinicio','Validación y reinicio de servicios'],
            ['Smoke test','PHP, nginx, PDO, archivos e imágenes']
        ];
        const software = [
            ['ubuntu','Ubuntu 24.04 LTS'], ['apt','Paquetes APT'], ['nginx','nginx'], ['certbot','Certbot'],
            ['mysql','MySQL'], ['php-fpm','PHP-FPM 8.3'], ['node','Node.js 22'], ['npm','npm'],
            ['composer','Composer'], ['rsync','rsync'], ['services','Servicios']
        ];
        const transferPhases = [
            ['tar','Crear tar','Empaquetando los ficheros de origen'],
            ['zstd','Comprimir zstd','Comprimiendo el archivo tar'],
            ['network','Enviar por red','Transmitiendo el tar.zst al destino'],
            ['decompress','Descomprimir destino','Restaurando ficheros en /var/www']
        ];
        const transferLabels = Object.fromEntries(transferPhases.map(([phase, title]) => [phase, title]));
        const $ = id => document.getElementById(id);
        let running = false;
        let activeJobId = null;
        let lastProgressDebug = {};

        function renderSteps() {
            $('stepsGrid').innerHTML = steps.map((s, i) => `<div class="col-md-6 col-xl-3"><div class="step-card pending" id="step-card-${i+1}"><div class="d-flex gap-2 align-items-start"><div class="step-number">${i+1}</div><div><div class="fw-bold">${s[0]}</div><div class="small muted mt-1">${s[1]}</div></div></div><div class="small mt-3 text-secondary" id="step-status-${i+1}">Pendiente</div></div></div>`).join('');
        }
        function renderSoftware() {
            $('softwareGrid').innerHTML = software.map(s => `<div class="col-6 col-md-4 col-xl-3"><div class="software-card" id="software-${s[0]}"><div class="fw-bold small">${s[1]}</div><div class="software-state pending mt-2" id="software-state-${s[0]}">Pendiente</div><div class="small muted mt-1" id="software-detail-${s[0]}">Esperando ejecución</div></div></div>`).join('');
        }
        function renderTransferProgress() {
            $('transferProgressGrid').innerHTML = transferPhases.map(([phase, title, detail]) => `<div class="col-12 col-md-6"><div class="transfer-card"><div class="d-flex justify-content-between gap-2"><div class="fw-bold small">${title}</div><div class="small" id="transfer-percent-${phase}">0%</div></div><div class="transfer-track mt-2"><div class="transfer-fill" id="transfer-fill-${phase}"></div></div><div class="small muted mt-2" id="transfer-detail-${phase}">${detail}</div></div></div>`).join('');
        }
        function log(message, type='info') {
            const line = document.createElement('div');
            line.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
            line.style.color = type === 'error' ? '#fda4af' : type === 'ok' ? '#86efac' : '#cce3fa';
            $('logBox').appendChild(line); $('logBox').scrollTop = $('logBox').scrollHeight;
        }
        function setStatus(text, type='') {
            const el = $('systemStatus'); el.className = 'status-pill ' + type;
            el.innerHTML = `<i class="fa-solid ${type === 'ok' ? 'fa-circle-check' : type === 'error' ? 'fa-circle-xmark' : 'fa-circle-notch fa-spin'}"></i>${text}`;
        }
        function setActiveNotice(text, visible=true) {
            const el = $('activeNotice');
            el.textContent = text;
            el.classList.toggle('d-none', !visible);
        }
        function resetSteps() { renderSteps(); renderTransferProgress(); lastProgressDebug = {}; $('doneCount').textContent='0'; $('pendingCount').textContent='10'; $('errorCount').textContent='0'; $('gauge').style.setProperty('--gauge','0%'); $('gaugeValue').textContent='0%'; $('gaugeLabel').textContent='Listo'; }
        function markStep(n, state, label) { const card=$(`step-card-${n}`); if(!card)return; card.className='step-card '+state; $(`step-status-${n}`).textContent=label; }
        function updateSoftware(line) {
            const match = line.match(/^MIGRATION_STATUS\|([^|]+)\|([^|]+)\|(.*)$/);
            if (!match) return false;
            const [, name, state, detail] = match; const stateEl=$(`software-state-${name}`); const detailEl=$(`software-detail-${name}`);
            if (stateEl) { stateEl.className='software-state '+state; stateEl.textContent=state === 'ok' ? 'Correcto' : state === 'failed' ? 'Falló' : state === 'installing' ? 'Instalando' : state === 'checking' ? 'Verificando' : 'Pendiente'; }
            if (detailEl) detailEl.textContent=detail;
            return true;
        }
        function updateTransferProgress(line) {
            const match = line.match(/^MIGRATION_PROGRESS\|([^|]+)\|(\d+)(?:\|(.*))?$/);
            if (!match) return false;
            const [, phase, rawPercent, detail] = match; const percent = Math.min(100, Math.max(0, Number(rawPercent)));
            const fill = $(`transfer-fill-${phase}`); const percentEl = $(`transfer-percent-${phase}`); const detailEl = $(`transfer-detail-${phase}`);
            if (fill) fill.style.width = percent + '%';
            if (percentEl) percentEl.textContent = percent + '%';
            if (detailEl && detail) detailEl.textContent = detail;
            return true;
        }
        function transferPercent(progress) {
            const phases = ['tar','zstd','network','decompress'];
            return Math.round(phases.reduce((sum, phase) => sum + Number(progress?.[phase]?.percent || 0), 0) / phases.length);
        }
        function consumeLogChunk(chunk) {
            String(chunk || '').replace(/\r/g, '\n').split('\n').filter(Boolean).forEach(line => {
                if(updateTransferProgress(line)) {
                    const progressMatch = line.match(/^MIGRATION_PROGRESS\|([^|]+)\|(\d+)/);
                    if (progressMatch) {
                        const phase = progressMatch[1]; const percent = Number(progressMatch[2]); const bucket = Math.floor(percent / 10) * 10;
                        if (lastProgressDebug[phase] !== bucket) { lastProgressDebug[phase] = bucket; log(`${transferLabels[phase] || phase}: ${percent}%`); }
                    }
                    return;
                }
                if(updateSoftware(line)){ if(line.includes('|failed|')) log(line,'error'); }
                else log(line,line.includes('ERROR')?'error':'info');
            });
        }
        function applyJobState(state) {
            if (!state) return;
            Object.entries(state.steps || {}).forEach(([n, item]) => markStep(Number(n), item.state, item.label));
            Object.entries(state.software || {}).forEach(([name, item]) => updateSoftware(`MIGRATION_STATUS|${name}|${item.state}|${item.detail}`));
            Object.entries(state.progress || {}).forEach(([phase, item]) => updateTransferProgress(`MIGRATION_PROGRESS|${phase}|${item.percent}|${item.detail || ''}`));
            const completed = Object.values(state.steps || {}).filter(item => item.state === 'done').length;
            const current = Object.values(state.steps || {}).some(item => item.state === 'running');
            const step3Running = state.steps?.['3']?.state === 'running';
            const overall = Math.min(100, completed * 10 + (step3Running ? transferPercent(state.progress || {}) / 10 : 0));
            $('doneCount').textContent = completed;
            $('pendingCount').textContent = 10 - completed;
            $('gauge').style.setProperty('--gauge', (completed === 10 ? 100 : Math.round(overall)) + '%');
            $('gaugeValue').textContent = (completed === 10 ? '100%' : Math.round(overall) + '%');
            $('gaugeLabel').textContent = current ? 'En curso' : completed === 10 ? 'Correcto' : 'Recuperado';
        }
        function formData(mode, step) {
            const fd = new FormData(); fd.set('action','run_step'); fd.set('async','1'); fd.set('dest',$('dest').value.trim()); fd.set('ssh_port',$('sshPort').value.trim() || '22'); fd.set('ssh_password',$('sshPassword').value); fd.set('mode',mode); fd.set('target_mode',document.querySelector('input[name="targetMode"]:checked').value); fd.set('step',step); fd.set('db_scope',$('dbScope').value); fd.set('sync_images',$('syncImages').checked?'yes':'no'); fd.set('sync_configs',$('syncConfigs').checked?'yes':'no'); fd.set('sync_users',$('syncUsers').checked?'yes':'no'); fd.set('allow_delete',$('allowDelete').checked?'yes':'no'); fd.set('setup_ssl',$('setupSsl').checked?'yes':'no'); fd.set('ssl_domain',$('sslDomain').value.trim()); fd.set('ssl_email',$('sslEmail').value.trim()); return fd;
        }
        function validate() { if(!$('dest').value.trim()){ log('Debes indicar usuario@host de destino.','error'); $('dest').focus(); return false; } if($('allowDelete').checked && document.querySelector('input[name="targetMode"]:checked').value === 'new'){ log('Para un servidor nuevo no se permite borrar sobrantes.','error'); return false; } if($('setupSsl').checked){ const domains=$('sslDomain').value.trim(); const email=$('sslEmail').value.trim(); if(!/^[A-Za-z0-9.-]+(?:,[A-Za-z0-9.-]+)*$/.test(domains)){ log('Indica uno o más dominios válidos separados por comas.','error'); $('sslDomain').focus(); return false; } if(!/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(email)){ log('Indica un correo válido para Certbot.','error'); $('sslEmail').focus(); return false; } } return true; }
        async function pollJob(jobId, initialOffset=0) {
            let offset = initialOffset;
            while (true) {
                await new Promise(resolve => setTimeout(resolve, 900));
                const fd = new FormData(); fd.set('action','job_status'); fd.set('job_id',jobId); fd.set('offset',String(offset));
                const response = await fetch(endpoint,{method:'POST',body:fd});
                if (!response.ok) throw new Error('HTTP '+response.status);
                const data = await response.json();
                offset = Number(data.offset || offset);
                applyJobState(data.state);
                consumeLogChunk(data.chunk);
                if (data.done) return data;
            }
        }
        function setRunButtons(disabled) {
            ['btnPreflight','btnSimulate','btnRun'].forEach(id => $(id).disabled = disabled);
            $('btnCancel').classList.toggle('d-none', !disabled || !activeJobId);
            $('btnCancel').disabled = !disabled || !activeJobId;
        }
        async function resumeActiveJob() {
            try {
                const response = await fetch(endpoint + '?action=active_job', {cache:'no-store'});
                if (!response.ok) return;
                const data = await response.json();
                if (!data.active || !data.job_id) { setStatus('Panel listo','ok'); setActiveNotice('',false); return; }
                activeJobId = data.job_id; running = true; resetSteps();
                $('operationBadge').textContent = data.mode === 'run' ? 'Migración activa' : 'Simulación activa';
                setStatus('Migración activa recuperada','warn');
                setActiveNotice(`Hay una ${data.mode === 'run' ? 'migración' : 'simulación'} activa${data.dest ? ' hacia '+data.dest : ''}. El progreso fue recuperado y continuará aquí. Puedes detenerla desde el botón rojo.`);
                if (data.dest) $('dest').value = data.dest;
                if (data.target_mode) { const radio = document.querySelector(`input[name="targetMode"][value="${data.target_mode}"]`); if (radio) radio.checked = true; }
                applyJobState(data.state);
                const tail = String(data.state?.tail || '');
                consumeLogChunk(tail);
                log(`Trabajo activo recuperado: ${data.job_id}. El progreso continuará actualizándose.`,'ok');
                setRunButtons(true);
                const result = await pollJob(data.job_id, Number(data.state?.log_size || 0));
                activeJobId = null; running = false; setRunButtons(false);
                setActiveNotice('',false);
                if (result.ok) { $('operationBadge').textContent='Finalizado'; setStatus('Operación completada','ok'); log('Operación completada mientras la página estaba cerrada.','ok'); }
                else { $('operationBadge').textContent=result.cancelled ? 'Detenido' : 'Error'; setStatus(result.cancelled ? 'Migración detenida' : 'Operación con errores', result.cancelled ? 'warn' : 'error'); log(result.msg || `Proceso terminó con código ${result.exit_code}`,'error'); }
            } catch (error) {
                activeJobId = null; running = false; setRunButtons(false); setActiveNotice('',false); setStatus('Panel listo','ok'); log('No se pudo recuperar el trabajo anterior: '+error.message,'error');
            }
        }
        async function cancelActiveJob() {
            if (!activeJobId || !confirm('¿Detener la migración activa? Se interrumpirán sus procesos locales y SSH.')) return;
            $('btnCancel').disabled = true; log('Solicitando detención de la migración…','error');
            try {
                const fd = new FormData(); fd.set('action','cancel_job'); fd.set('job_id',activeJobId);
                const response = await fetch(endpoint,{method:'POST',body:fd}); const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.msg || 'No se pudo detener la migración');
                log('Detención solicitada correctamente.','ok');
            } catch (error) { $('btnCancel').disabled=false; log(error.message,'error'); }
        }
        async function execute(mode, step) {
            if(running || !validate()) return; running=true; resetSteps(); setActiveNotice('',false); $('operationBadge').textContent = mode === 'run' ? 'Aplicando cambios' : 'Simulación en curso'; setStatus(mode === 'run' ? 'Migración ejecutándose' : 'Simulación ejecutándose','warn'); log(`${mode === 'run' ? 'APLICACIÓN' : 'SIMULACIÓN'} iniciada hacia ${$('dest').value.trim()}`); document.querySelectorAll('button').forEach(b=>b.disabled=true);
            try { const response=await fetch(endpoint,{method:'POST',body:formData(mode,step)}); if(!response.ok) throw new Error('HTTP '+response.status); const start=await response.json(); if(!start.ok || !start.job_id) throw new Error(start.msg || 'No se pudo iniciar el trabajo'); activeJobId=start.job_id; setRunButtons(true); log('Trabajo en segundo plano iniciado: '+start.job_id); const data=await pollJob(start.job_id); activeJobId=null; if(data.ok){ $('operationBadge').textContent='Finalizado'; setStatus('Operación completada','ok'); log('Operación finalizada correctamente.','ok'); } else { $('errorCount').textContent='1'; $('operationBadge').textContent=data.cancelled ? 'Detenido' : 'Error'; setStatus(data.cancelled ? 'Migración detenida' : 'Operación con errores', data.cancelled ? 'warn' : 'error'); log(data.msg || `Proceso terminó con código ${data.exit_code}`,'error'); } } catch(e){ setStatus('Error de comunicación','error'); log(e.message,'error'); } finally { running=false; activeJobId=null; setRunButtons(false); }
        }
        document.querySelectorAll('.mode-choice').forEach(label=>label.addEventListener('click',()=>{document.querySelectorAll('.mode-choice').forEach(x=>x.classList.remove('active'));label.classList.add('active'); if(label.dataset.choice==='new'){$('allowDelete').checked=false;$('allowDelete').disabled=true;}else{$('allowDelete').disabled=false;}}));
        $('btnPreflight').addEventListener('click',()=>execute('dry-run','1'));
        $('btnSimulate').addEventListener('click',()=>execute('dry-run',$('stepSelect').value));
        $('btnRun').addEventListener('click',()=>{ if(confirm('¿Confirmas la migración real hacia '+$('dest').value.trim()+'? Se modificarán servicios, archivos y base de datos del destino.')) execute('run',$('stepSelect').value); });
        $('btnCancel').addEventListener('click',cancelActiveJob);
        $('btnClearLog').addEventListener('click',()=>{$('logBox').textContent='Registro limpiado.';});
        $('runMode').addEventListener('change',e=>{if(e.target.value==='run') $('btnRun').focus();});
        resetSteps(); renderSoftware(); $('allowDelete').disabled=true; setStatus('Panel listo','ok'); resumeActiveJob();
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'active_job') {
    $active = migration_find_active_job();
    if ($active === null) {
        out_json(['ok' => true, 'active' => false]);
    }
    out_json([
        'ok' => true,
        'active' => true,
        'job_id' => $active['job_id'],
        'mode' => $active['state']['mode'],
        'dest' => $active['state']['dest'],
        'step' => $active['state']['step'],
        'target_mode' => $active['state']['target_mode'],
        'state' => $active['state']
    ]);
}

if ($action === 'cancel_job') {
    $jobId = trim((string)($_POST['job_id'] ?? ''));
    if (!is_valid_job_id($jobId)) {
        out_json(['ok' => false, 'msg' => 'Identificador de trabajo inválido'], 400);
    }
    $meta = migration_read_meta($jobId);
    if (!is_array($meta)) {
        out_json(['ok' => false, 'msg' => 'Trabajo de migración no encontrado'], 404);
    }
    $state = migration_log_state($jobId, $meta);
    if ($state['done']) {
        out_json(['ok' => false, 'msg' => 'La migración ya terminó', 'exit_code' => $state['exit_code']], 409);
    }
    if (!migration_cancel_process($meta)) {
        out_json(['ok' => false, 'msg' => 'No se pudo verificar o detener el proceso de migración'], 409);
    }
    $meta['cancelled_at'] = time();
    @file_put_contents(migration_meta_path($jobId), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @file_put_contents(migration_log_path($jobId), "\nMIGRATION_CANCELLED\nMIGRATION_EXIT:130\n", FILE_APPEND | LOCK_EX);
    out_json(['ok' => true, 'cancelled' => true, 'job_id' => $jobId]);
}

if ($action === 'job_status') {
    $jobId = trim((string)($_POST['job_id'] ?? $_GET['job_id'] ?? ''));
    $offset = max(0, (int)($_POST['offset'] ?? $_GET['offset'] ?? 0));
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        out_json(['ok' => false, 'msg' => 'Identificador de trabajo inválido'], 400);
    }
    $metaPath = '/tmp/palweb-migration-' . $jobId . '.json';
    if (!is_file($metaPath)) {
        out_json(['ok' => false, 'msg' => 'Trabajo de migración no encontrado'], 404);
    }
    $meta = json_decode((string)file_get_contents($metaPath), true);
    $logPath = is_array($meta) ? (string)($meta['log'] ?? '') : '';
    if ($logPath === '') {
        out_json(['ok' => false, 'msg' => 'Registro de migración no disponible'], 404);
    }
    if (!is_file($logPath)) {
        $createdAt = (int)(is_array($meta) ? ($meta['created_at'] ?? time()) : time());
        if (time() > $createdAt + 15) {
            out_json(['ok' => false, 'done' => true, 'running' => false, 'job_id' => $jobId, 'offset' => $offset, 'chunk' => 'No se pudo crear el registro del proceso remoto.\n', 'exit_code' => 255]);
        }
        out_json(['ok' => true, 'done' => false, 'running' => true, 'job_id' => $jobId, 'offset' => $offset, 'chunk' => '', 'exit_code' => null]);
    }
    $logSize = (int)filesize($logPath);
    $offset = min($offset, $logSize);
    $handle = fopen($logPath, 'rb');
    fseek($handle, $offset);
    $chunk = fread($handle, 262144);
    fclose($handle);
    $newOffset = $offset + strlen((string)$chunk);
    $allLog = (string)file_get_contents($logPath);
    $exitCode = null;
    if (preg_match('/MIGRATION_EXIT:(\d+)/', $allLog, $match)) {
        $exitCode = (int)$match[1];
    }
    $pid = (int)($meta['pid'] ?? 0);
    $alive = $pid > 0 && is_dir('/proc/' . $pid);
    $done = $exitCode !== null || (!$alive && time() > ((int)($meta['created_at'] ?? time()) + 5));
    if ($done && $exitCode === null) {
        $exitCode = 255;
    }
    out_json([
        'ok' => !$done || $exitCode === 0,
        'done' => $done,
        'running' => !$done,
        'job_id' => $jobId,
        'offset' => $newOffset,
        'chunk' => (string)$chunk,
        'exit_code' => $exitCode,
        'cancelled' => is_array($meta) && isset($meta['cancelled_at']),
        'msg' => $exitCode === 75 ? 'Ya hay otra migración en ejecución' : null,
        'state' => is_array($meta) ? migration_log_state($jobId, $meta) : null
    ]);
}

if ($action !== 'run_step') {
    out_json(['ok' => false, 'msg' => 'Acción inválida'], 400);
}

$dest = trim((string)($_POST['dest'] ?? ''));
$sshPort = trim((string)($_POST['ssh_port'] ?? '22'));
$mode = trim((string)($_POST['mode'] ?? 'dry-run'));
$targetMode = trim((string)($_POST['target_mode'] ?? 'update'));
$syncUsers = trim((string)($_POST['sync_users'] ?? 'yes'));
$syncImages = trim((string)($_POST['sync_images'] ?? 'yes'));
$syncConfigs = trim((string)($_POST['sync_configs'] ?? 'yes'));
$dbScope = trim((string)($_POST['db_scope'] ?? 'app'));
$allowDelete = trim((string)($_POST['allow_delete'] ?? 'no'));
$setupSsl = trim((string)($_POST['setup_ssl'] ?? 'no'));
$sslDomain = trim((string)($_POST['ssl_domain'] ?? ''));
$sslEmail = trim((string)($_POST['ssl_email'] ?? ''));
$step = trim((string)($_POST['step'] ?? 'all'));
$sshPassword = (string)($_POST['ssh_password'] ?? '');
$streamMode = ($_POST['stream'] ?? '') === '1';
$asyncMode = ($_POST['async'] ?? '') === '1';

if (!is_valid_dest($dest)) {
    out_json(['ok' => false, 'msg' => 'Destino inválido. Usa formato usuario@host'], 400);
}
if (!preg_match('/^\d{1,5}$/', $sshPort)) {
    out_json(['ok' => false, 'msg' => 'Puerto SSH inválido'], 400);
}
if (!in_array($mode, ['dry-run', 'run'], true)) {
    out_json(['ok' => false, 'msg' => 'Modo inválido'], 400);
}
if (!in_array($targetMode, ['new', 'update'], true)) {
    out_json(['ok' => false, 'msg' => 'target_mode inválido'], 400);
}
if (!in_array($syncUsers, ['yes', 'no'], true)) {
    out_json(['ok' => false, 'msg' => 'sync_users inválido'], 400);
}
if (!in_array($syncImages, ['yes', 'no'], true) || !in_array($syncConfigs, ['yes', 'no'], true)) {
    out_json(['ok' => false, 'msg' => 'Opción de sincronización inválida'], 400);
}
if (!in_array($dbScope, ['app', 'all'], true)) {
    out_json(['ok' => false, 'msg' => 'db_scope inválido'], 400);
}
if (!in_array($allowDelete, ['yes', 'no'], true)) {
    out_json(['ok' => false, 'msg' => 'allow_delete inválido'], 400);
}
if (!in_array($setupSsl, ['yes', 'no'], true)) {
    out_json(['ok' => false, 'msg' => 'setup_ssl inválido'], 400);
}
if ($setupSsl === 'yes' && (!is_valid_ssl_domains($sslDomain) || !is_valid_ssl_email($sslEmail))) {
    out_json(['ok' => false, 'msg' => 'Indica dominios y correo válidos para Certbot'], 400);
}
if ($step !== 'all' && !preg_match('/^(10|[1-9])$/', $step)) {
    out_json(['ok' => false, 'msg' => 'Paso inválido (1..10 o all)'], 400);
}

$scriptPath = '/var/www/migrar_espejo.sh';
if (!file_exists($scriptPath)) {
    out_json(['ok' => false, 'msg' => 'No existe migrar_espejo.sh'], 500);
}

$cmd = '/usr/bin/env bash ' . escapeshellarg($scriptPath)
    . ' --dest ' . escapeshellarg($dest)
    . ' --ssh-port ' . escapeshellarg($sshPort)
    . ' --mode ' . escapeshellarg($mode)
    . ' --target-mode ' . escapeshellarg($targetMode)
    . ' --sync-users ' . escapeshellarg($syncUsers)
    . ' --sync-images ' . escapeshellarg($syncImages)
    . ' --sync-configs ' . escapeshellarg($syncConfigs)
    . ' --db-scope ' . escapeshellarg($dbScope)
    . ' --allow-delete ' . escapeshellarg($allowDelete)
    . ' --only-step ' . escapeshellarg($step);

$lockPath = '/tmp/palweb-migration.lock';
$lock = null;
if (!$asyncMode) {
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        out_json(['ok' => false, 'msg' => 'Ya hay una migración en ejecución'], 409);
    }
}

// La contraseña viaja por el entorno del proceso hijo, no como argumento visible
// en ps. El script la consume mediante SSHPASS para sshpass -e.
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['PATH'] = $env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
if ($sshPassword !== '') {
    $env['MIRROR_SSHPASS'] = $sshPassword;
    $env['SSHPASS'] = $sshPassword;
}
$env['MIRROR_SETUP_SSL'] = $setupSsl;
$env['MIRROR_SSL_DOMAIN'] = $sslDomain;
$env['MIRROR_SSL_EMAIL'] = $sslEmail;

$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

if ($asyncMode) {
    $jobId = bin2hex(random_bytes(16));
    $logPath = '/tmp/palweb-migration-' . $jobId . '.log';
    $metaPath = '/tmp/palweb-migration-' . $jobId . '.json';
    $runningLock = '/tmp/palweb-migration-running.lock';

    // El panel suele ejecutarse como nobody/www-data, pero las pruebas de CLI
    // pueden dejar este archivo creado por root con permisos 0644. En ese caso
    // el hijo moría antes de poder crear el registro de salida.
    $lockProbe = @fopen($runningLock, 'c');
    if (is_resource($lockProbe)) {
        @chmod($runningLock, 0666);
        fclose($lockProbe);
    }
    clearstatcache(true, $runningLock);
    if (!is_writable($runningLock)) {
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $runningLock = '/tmp/palweb-migration-running-' . (string)$effectiveUid . '.lock';
        $lockProbe = @fopen($runningLock, 'c');
        if (is_resource($lockProbe)) {
            @chmod($runningLock, 0666);
            fclose($lockProbe);
        }
        clearstatcache(true, $runningLock);
    }
    if (!is_writable($runningLock)) {
        out_json(['ok' => false, 'msg' => 'No se puede abrir el bloqueo de migración; revisa permisos de /tmp'], 500);
    }
    if (@file_put_contents($logPath, "MIGRATION_LAUNCH:$jobId\n", LOCK_EX) === false) {
        out_json(['ok' => false, 'msg' => 'No se pudo crear el registro de la migración'], 500);
    }
    @chmod($logPath, 0600);
    $jobShell = 'exec 9>>' . escapeshellarg($runningLock)
        . ' 2>>' . escapeshellarg($logPath)
        . ' || { printf "%s\\n" "ERROR: No se pudo abrir el bloqueo de migración: ' . addslashes($runningLock) . '" >> ' . escapeshellarg($logPath)
        . '; code=74; printf "\\nMIGRATION_EXIT:%s\\n" "$code" >> ' . escapeshellarg($logPath)
        . '; exit "$code"; }'
        . '; if ! flock -n 9; then '
        . 'printf "%s\\n" "ERROR: Ya hay otra migración en ejecución; espera a que termine antes de iniciar otra." >> ' . escapeshellarg($logPath)
        . '; code=75'
        . '; else ' . $cmd . ' > ' . escapeshellarg($logPath) . ' 2>&1; code=$?; flock -u 9; fi'
        . '; printf "\\nMIGRATION_EXIT:%s\\n" "$code" >> ' . escapeshellarg($logPath)
        . '; exit "$code"';
    $launcher = 'nohup /usr/bin/setsid sh -c ' . escapeshellarg($jobShell)
        . ' > /dev/null 2>&1 & echo $!';
    $process = proc_open($launcher, $descriptors, $pipes, '/var/www', $env);
    if (!is_resource($process)) {
        out_json(['ok' => false, 'msg' => 'No se pudo iniciar el trabajo de migración'], 500);
    }
    $pid = (int)trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    file_put_contents($metaPath, json_encode([
        'pid' => $pid,
        'log' => $logPath,
        'created_at' => time(),
        'step' => $step,
        'target_mode' => $targetMode,
        'mode' => $mode,
        'dest' => $dest,
        'setup_ssl' => $setupSsl,
        'ssl_domain' => $sslDomain,
        'process_group' => $pid
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($metaPath, 0600);
    out_json(['ok' => true, 'started' => true, 'job_id' => $jobId, 'poll_ms' => 1000]);
}

$process = proc_open($cmd, $descriptors, $pipes, '/var/www', $env);
if (!is_resource($process)) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    out_json(['ok' => false, 'msg' => 'No se pudo iniciar la migración'], 500);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

if ($streamMode) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ob_implicit_flush(true);

    $buffers = ['stdout' => '', 'stderr' => ''];
    $emitStream = static function (string $stream, string $chunk) use (&$buffers): void {
        if ($chunk === '') {
            return;
        }
        $buffers[$stream] .= $chunk;
        while (($pos = strpos($buffers[$stream], "\n")) !== false) {
            $line = substr($buffers[$stream], 0, $pos);
            $buffers[$stream] = substr($buffers[$stream], $pos + 1);
            echo json_encode([
                'type' => 'log',
                'stream' => $stream,
                'message' => rtrim($line, "\r")
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            flush();
        }
    };

    $lastHeartbeat = microtime(true);
    while (true) {
        $emitStream('stdout', (string)stream_get_contents($pipes[1]));
        $emitStream('stderr', (string)stream_get_contents($pipes[2]));
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $lastHeartbeat >= 5) {
            echo json_encode(['type' => 'heartbeat', 'at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE) . "\n";
            flush();
            $lastHeartbeat = microtime(true);
        }
        usleep(100000);
    }
    $emitStream('stdout', (string)stream_get_contents($pipes[1]));
    $emitStream('stderr', (string)stream_get_contents($pipes[2]));
    foreach (['stdout', 'stderr'] as $streamName) {
        if ($buffers[$streamName] !== '') {
            echo json_encode([
                'type' => 'log',
                'stream' => $streamName,
                'message' => $buffers[$streamName]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    flock($lock, LOCK_UN);
    fclose($lock);
    echo json_encode([
        'type' => 'complete',
        'ok' => $code === 0,
        'exit_code' => $code,
        'step' => $step === 'all' ? 'all' : (int)$step,
        'ran_at' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

$output = '';
while (true) {
    $output .= stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);
    $status = proc_get_status($process);
    if (!$status['running']) {
        break;
    }
    usleep(100000);
}
$output .= stream_get_contents($pipes[1]);
$output .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
flock($lock, LOCK_UN);
fclose($lock);

out_json([
    'ok' => $code === 0,
    'exit_code' => $code,
    'step' => $step === 'all' ? 'all' : (int)$step,
    'output' => $output,
    'ran_at' => date('Y-m-d H:i:s')
]);
