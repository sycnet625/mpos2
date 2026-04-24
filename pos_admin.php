<?php
// ARCHIVO: /var/www/palweb/api/pos_admin.php
ini_set('display_errors', 0);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
require_once 'db.php';
require_once 'config_loader.php';

$backupDir = __DIR__ . '/backups';
if (!file_exists($backupDir)) mkdir($backupDir, 0777, true);

class DbBackup {
    private $pdo;
    
    public function __construct($pdo) { $this->pdo = $pdo; }

    public function generateSQL() {
        $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- RESPALDO BASE DE DATOS PALWEB\n-- Fecha: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $create = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create[1] . ";\n\n";

            $rows = $this->pdo->query("SELECT * FROM `$table`");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $values = array_map(function($val) {
                    if ($val === null) return 'NULL';
                    return $this->pdo->quote($val);
                }, array_values($row));
                $sql .= "INSERT INTO `$table` VALUES (" . implode(", ", $values) . ");\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sql;
    }

    private function splitStatements($sqlContent) {
        $lines = preg_split("/\r\n|\n|\r/", $sqlContent);
        $statements = [];
        $buffer = '';
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) continue;
            $buffer .= $line . "\n";
            if (preg_match('/;\s*$/', $trim)) {
                $stmt = trim($buffer);
                if ($stmt !== '') $statements[] = $stmt;
                $buffer = '';
            }
        }
        $tail = trim($buffer);
        if ($tail !== '') $statements[] = $tail;
        return $statements;
    }

    private function detectStatementMeta($query) {
        $query = trim($query);
        $clean = rtrim($query, "; \t\n\r\0\x0B");
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS/i', $clean)) return ['op' => 'SET FK', 'table' => '[sistema]'];
        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`?([a-zA-Z0-9_]+)`?/i', $clean, $m)) return ['op' => 'DROP', 'table' => $m[1]];
        if (preg_match('/^CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $clean, $m)) return ['op' => 'CREATE', 'table' => $m[1]];
        if (preg_match('/^INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $clean, $m)) return ['op' => 'INSERT', 'table' => $m[1]];
        if (preg_match('/^(ALTER|UPDATE|DELETE|TRUNCATE)\s+(TABLE\s+)?`?([a-zA-Z0-9_]+)`?/i', $clean, $m)) return ['op' => strtoupper($m[1]), 'table' => $m[3]];
        return ['op' => 'OTRO', 'table' => '[sin tabla]'];
    }

    private function initTableLog(&$tableLogs, $table) {
        if (!isset($tableLogs[$table])) {
            $tableLogs[$table] = ['table' => $table, 'steps' => [], 'inserts_total' => 0, 'inserts_ok' => 0, 'errors' => 0, 'notes' => []];
        }
    }

    private function noteTable(&$tableLogs, $table, $step, $status, $detail = '') {
        $this->initTableLog($tableLogs, $table);
        $tableLogs[$table]['steps'][] = ['step' => $step, 'status' => $status, 'detail' => $detail];
        if ($step === 'INSERT') {
            $tableLogs[$table]['inserts_total']++;
            if ($status === 'ok' || $status === 'dry-run') $tableLogs[$table]['inserts_ok']++;
        }
        if ($status === 'error') $tableLogs[$table]['errors']++;
        if ($detail !== '') $tableLogs[$table]['notes'][] = $detail;
    }

    public function analyzeSQL($sqlContent, $dryRun = true, callable $progress = null) {
        $tableLogs = [];
        $summary = ['tables' => 0, 'statements' => 0, 'errors' => 0, 'mode' => $dryRun ? 'dry-run' : 'restore', 'processed' => 0];
        $statements = $this->splitStatements($sqlContent);
        $summary['statements'] = count($statements);

        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($statements as $idx => $query) {
            $meta = $this->detectStatementMeta($query);
            $table = $meta['table'];
            $op = $meta['op'];
            if ($dryRun) {
                $this->noteTable($tableLogs, $table, $op, 'dry-run', 'Consulta detectada, no ejecutada');
                $summary['processed'] = $idx + 1;
                $summary['tables'] = count($tableLogs);
                if ($progress) $progress(['current' => $idx + 1, 'total' => $summary['statements'], 'table' => $table, 'op' => $op, 'status' => 'dry-run', 'detail' => 'Consulta detectada, no ejecutada', 'summary' => $summary]);
                continue;
            }
            try {
                $this->pdo->exec($query);
                $this->noteTable($tableLogs, $table, $op, 'ok');
                $eventStatus = 'ok';
                $eventDetail = '';
            } catch (Exception $e) {
                $summary['errors']++;
                $this->noteTable($tableLogs, $table, $op, 'error', $e->getMessage());
                $eventStatus = 'error';
                $eventDetail = $e->getMessage();
            }
            $summary['processed'] = $idx + 1;
            $summary['tables'] = count($tableLogs);
            if ($progress) $progress(['current' => $idx + 1, 'total' => $summary['statements'], 'table' => $table, 'op' => $op, 'status' => $eventStatus, 'detail' => $eventDetail, 'summary' => $summary]);
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $summary['tables'] = count($tableLogs);
        return ['summary' => $summary, 'tables' => array_values($tableLogs)];
    }

    public function restoreSQL($sqlContent, callable $progress = null) {
        return $this->analyzeSQL($sqlContent, false, $progress);
    }
}

function stream_start_json() {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_flush();
    ob_implicit_flush(true);
}

function stream_emit(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @flush();
}

function load_sql_from_uploaded_file(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('Error al subir archivo.');
    $tmp = $file['tmp_name'];
    $name = $file['name'] ?? 'archivo.sql';
    if (preg_match('/\.sql$/i', $name)) return ['name' => $name, 'sql' => file_get_contents($tmp)];
    if (preg_match('/\.(tar\.gz|tgz)$/i', $name)) {
        $tempDir = sys_get_temp_dir() . '/restore_' . uniqid();
        mkdir($tempDir, 0777, true);
        $cmd = "tar -xzf " . escapeshellarg($tmp) . " -C " . escapeshellarg($tempDir) . " 2>&1";
        $out = shell_exec($cmd);
        $files = glob($tempDir . '/*.sql');
        if (!$files) {
            @array_map('unlink', glob($tempDir . '/*'));
            @rmdir($tempDir);
            throw new Exception('No se encontró un SQL dentro del archivo comprimido. ' . trim((string)$out));
        }
        $sql = file_get_contents($files[0]);
        @array_map('unlink', glob($tempDir . '/*'));
        @rmdir($tempDir);
        return ['name' => $name, 'sql' => $sql];
    }
    throw new Exception('Formato no soportado. Use .sql o .tar.gz');
}

function load_sql_from_local_backup(string $backupDir, string $file): array {
    $fullPath = $backupDir . '/' . basename($file);
    if (!file_exists($fullPath)) throw new Exception('Backup no encontrado.');
    $tempDir = sys_get_temp_dir() . '/restore_local_' . uniqid();
    mkdir($tempDir, 0777, true);
    $cmd = "tar -xzf " . escapeshellarg($fullPath) . " -C " . escapeshellarg($tempDir) . " 2>&1";
    $out = shell_exec($cmd);
    $files = glob($tempDir . '/*.sql');
    if (!$files) {
        @array_map('unlink', glob($tempDir . '/*'));
        @rmdir($tempDir);
        throw new Exception('No se pudo extraer el SQL. ' . trim((string)$out));
    }
    $sql = file_get_contents($files[0]);
    @array_map('unlink', glob($tempDir . '/*'));
    @rmdir($tempDir);
    return ['name' => basename($file), 'sql' => $sql];
}

$dbUtil = new DbBackup($pdo);
$msg = "";
$msgType = "";
$restoreReport = null;
$currentHost = $_SERVER['HTTP_HOST'] ?? '';

// ── GET: descarga directa de un .tar.gz local ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_backup') {
    $file = basename($_GET['file'] ?? '');
    $fullPath = $backupDir . '/' . $file;
    if (!$file || !preg_match('/\.tar\.gz$/i', $file) || !file_exists($fullPath)) {
        http_response_code(404); die('Archivo no encontrado.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: no-cache');
    readfile($fullPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (in_array($action, ['ajax_upload_dry_run', 'ajax_upload_restore', 'ajax_restore_local_dry_run', 'ajax_restore_local'], true)) {
            stream_start_json();
            $isDryRun = in_array($action, ['ajax_upload_dry_run', 'ajax_restore_local_dry_run'], true);
            $loaded = str_starts_with($action, 'ajax_upload')
                ? load_sql_from_uploaded_file($_FILES['sql_file'] ?? [])
                : load_sql_from_local_backup($backupDir, $_POST['filename'] ?? '');

            stream_emit(['type' => 'start', 'source' => $loaded['name'], 'mode' => $isDryRun ? 'dry-run' : 'restore', 'message' => ($isDryRun ? 'Iniciando dry-run' : 'Iniciando restauración') . ' de ' . $loaded['name']]);

            $progress = function(array $event) { stream_emit(['type' => 'progress'] + $event); };
            $restoreReport = $isDryRun ? $dbUtil->analyzeSQL($loaded['sql'], true, $progress) : $dbUtil->restoreSQL($loaded['sql'], $progress);
            $restoreReport['source'] = $loaded['name'];

            stream_emit(['type' => 'finish', 'message' => $isDryRun ? "Dry-run completado para {$loaded['name']}" : "Restauración completada desde {$loaded['name']}", 'msgType' => $isDryRun ? 'info' : 'success', 'report' => $restoreReport]);
            exit;
        }

        if ($action === 'download_sql') {
            $sql = $dbUtil->generateSQL();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="backup_palweb_' . date('Y-m-d_H-i') . '.sql"');
            echo $sql;
            exit;
        }

        if ($action === 'create_local') {
            $filename = "backup_" . date('Y-m-d_His');
            $sqlFile = "$backupDir/$filename.sql";
            $tarFile = "$backupDir/$filename.tar.gz";
            file_put_contents($sqlFile, $dbUtil->generateSQL());
            $cmd = "cd " . escapeshellarg($backupDir) . " && tar -czf " . escapeshellarg($filename . ".tar.gz") . " " . escapeshellarg($filename . ".sql");
            shell_exec($cmd);
            if (file_exists($sqlFile)) unlink($sqlFile);
            if (file_exists($tarFile)) { $msg = "Backup creado: <b>$filename.tar.gz</b>"; $msgType = "success"; } else { throw new Exception("Error al comprimir."); }
        }

        if ($action === 'upload_dry_run' || $action === 'upload_restore') {
            $loaded = load_sql_from_uploaded_file($_FILES['sql_file'] ?? []);
            $restoreReport = ($action === 'upload_dry_run') ? $dbUtil->analyzeSQL($loaded['sql'], true) : $dbUtil->restoreSQL($loaded['sql']);
            $restoreReport['source'] = $loaded['name'];
            $msg = ($action === 'upload_dry_run') ? "Dry-run completado para <b>{$loaded['name']}</b>." : "Restauración completada desde <b>{$loaded['name']}</b>.";
            $msgType = ($action === 'upload_dry_run') ? "info" : "success";
        }

        if ($action === 'restore_local_dry_run' || $action === 'restore_local') {
            $file = $_POST['filename'];
            $loaded = load_sql_from_local_backup($backupDir, $file);
            $restoreReport = ($action === 'restore_local_dry_run') ? $dbUtil->analyzeSQL($loaded['sql'], true) : $dbUtil->restoreSQL($loaded['sql']);
            $restoreReport['source'] = $loaded['name'];
            $msg = ($action === 'restore_local_dry_run') ? "Dry-run completado para <b>$file</b>." : "Sistema restaurado al punto: <b>$file</b>";
            $msgType = ($action === 'restore_local_dry_run') ? "info" : "warning";
        }
        
        if ($action === 'delete_local') {
            $file = $_POST['filename'];
            if (file_exists("$backupDir/$file")) unlink("$backupDir/$file");
            $msg = "Archivo eliminado."; $msgType = "info";
        }

        if ($action === 'upload_backup') {
            $f = $_FILES['backup_file'] ?? [];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('Error al subir archivo.');
            $origName = $f['name'] ?? 'backup.tar.gz';
            if (!preg_match('/\.(tar\.gz|tgz)$/i', $origName)) throw new Exception('Solo se aceptan archivos .tar.gz o .tgz');
            // Verificar que el tar.gz contiene al menos un .sql
            $tmpDir = sys_get_temp_dir() . '/verify_upload_' . uniqid();
            mkdir($tmpDir, 0777, true);
            shell_exec("tar -tzf " . escapeshellarg($f['tmp_name']) . " 2>&1");
            $listed = shell_exec("tar -tzf " . escapeshellarg($f['tmp_name']) . " 2>/dev/null");
            @rmdir($tmpDir);
            if (!preg_match('/\.sql/i', (string)$listed)) throw new Exception('El archivo no contiene un .sql válido.');
            // Nombre destino: conservar o renombrar con timestamp si ya existe
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
            $dest = $backupDir . '/' . $safeName;
            if (file_exists($dest)) $dest = $backupDir . '/upload_' . date('Y-m-d_His') . '.tar.gz';
            if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('No se pudo guardar el archivo.');
            $msg = "Backup subido: <b>" . htmlspecialchars(basename($dest)) . "</b>. Ya aparece en la lista de copias locales.";
            $msgType = "success";
        }

        if ($action === 'recalc_kardex') {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE stock_almacen SET cantidad = 0");
            $sqlSync = "INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal) SELECT id_almacen, id_producto, SUM(cantidad), 1 FROM kardex GROUP BY id_almacen, id_producto ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)";
            $stmt = $pdo->prepare($sqlSync);
            $stmt->execute();
            $pdo->commit();
            $msg = "Inventario Sincronizado: El stock maestro ahora coincide exactamente con el historial del Kardex.";
            $msgType = "success";
        }

    } catch (Exception $e) {
        if (in_array($action, ['ajax_upload_dry_run', 'ajax_upload_restore', 'ajax_restore_local_dry_run', 'ajax_restore_local'], true)) {
            stream_start_json();
            stream_emit(['type' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "Error: " . $e->getMessage();
        $msgType = "danger";
    }
}

$stats = $pdo->query("SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
$backups = glob("$backupDir/*.tar.gz");
rsort($backups);
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel | <?= htmlspecialchars(config_loader_system_name()) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        body { background-color: #f4f6f9; }
        .table thead th { white-space: nowrap; }
        #ajaxRestorePanel .progress { height: 12px; }
        #ajaxRestoreLog { max-height: 360px; overflow: auto; font-size: 0.9rem; }
        #ajaxRestoreLog .log-line { border-bottom: 1px solid #eef2f7; padding: 6px 0; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">
    
    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Sistema</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-cogs me-2"></i>Administracion del Sistema</h1>
                <p class="mb-3 text-white-50">Mantenimiento, copias de seguridad y salud del servidor.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-server me-1"></i>Host: <?php echo htmlspecialchars($currentHost ?: '[sin host]'); ?></span>
                    <span class="kpi-chip"><i class="fas fa-database me-1"></i><?= count($stats) ?> tablas</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="pos.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Volver al POS</a>
            </div>
        </div>
    </section>

    <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show mb-4" role="alert">
        <?php echo $msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div id="ajaxRestorePanel" class="glass-card p-4 mb-4 d-none">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Restauracion</div>
                <h2 class="h5 fw-bold mb-0"><i class="fas fa-stream text-info me-2"></i>Restauracion en vivo</h2>
                <small id="ajaxRestoreMeta" class="text-muted">Esperando accion...</small>
            </div>
            <div class="text-end">
                <div id="ajaxRestoreCounters" class="small text-muted">0 / 0 sentencias</div>
                <div id="ajaxRestoreMode" class="small fw-bold text-info">-</div>
            </div>
        </div>
        <div class="progress mb-3" style="height: 20px;">
            <div id="ajaxRestoreProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 0%">0%</div>
        </div>
        <div id="ajaxRestoreSummary" class="small text-muted mb-3">Sin ejecucion.</div>
        <div id="ajaxRestoreLog" class="border rounded bg-white" style="max-height: 300px; overflow:auto;"></div>
    </div>

    <?php if ($restoreReport): ?>
    <div class="glass-card p-4 mb-4 border-start border-primary border-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Resultado</div>
                <h2 class="h5 fw-bold mb-0"><i class="fas fa-clipboard-list text-primary me-2"></i>Log de restauracion SQL</h2>
                <small class="text-muted">Origen: <?php echo htmlspecialchars($restoreReport['source'] ?? 'desconocido'); ?> | Modo: <?php echo htmlspecialchars($restoreReport['summary']['mode'] ?? 'n/a'); ?></small>
            </div>
            <div class="text-end">
                <div class="small text-muted">Tablas: <b><?php echo intval($restoreReport['summary']['tables'] ?? 0); ?></b></div>
                <div class="small text-muted">Sentencias: <b><?php echo intval($restoreReport['summary']['statements'] ?? 0); ?></b></div>
                <div class="small text-muted">Errores: <b class="<?php echo !empty($restoreReport['summary']['errors']) ? 'text-danger' : 'text-success'; ?>"><?php echo intval($restoreReport['summary']['errors'] ?? 0); ?></b></div>
            </div>
        </div>
        <div class="table-responsive border rounded-4 bg-white" style="max-height: 400px; overflow:auto;">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Tabla</th>
                        <th>Pasos</th>
                        <th class="text-center">Inserts</th>
                        <th class="text-center">OK</th>
                        <th class="text-center">Errores</th>
                        <th>Notas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($restoreReport['tables'] ?? []) as $row): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars($row['table']); ?></td>
                        <td>
                            <?php foreach (($row['steps'] ?? []) as $step): ?>
                                <?php $badge = 'secondary'; if (($step['status'] ?? '') === 'ok') $badge = 'success'; elseif (($step['status'] ?? '') === 'error') $badge = 'danger'; elseif (($step['status'] ?? '') === 'dry-run') $badge = 'info'; ?>
                                <span class="badge bg-<?php echo $badge; ?> me-1 mb-1"><?php echo htmlspecialchars(($step['step'] ?? '?') . ' · ' . ($step['status'] ?? '?')); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-center"><?php echo intval($row['inserts_total'] ?? 0); ?></td>
                        <td class="text-center"><?php echo intval($row['inserts_ok'] ?? 0); ?></td>
                        <td class="text-center <?php echo !empty($row['errors']) ? 'text-danger fw-bold' : ''; ?>"><?php echo intval($row['errors'] ?? 0); ?></td>
                        <td class="small text-muted">
                            <?php $notes = array_values(array_unique(array_filter($row['notes'] ?? []))); if (!$notes) echo 'Sin observaciones.'; else foreach($notes as $note) echo '<div>' . htmlspecialchars($note) . '</div>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Estado BD</div>
                    <div id="health-db" class="summary-total text-secondary">...</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Ping</div>
                    <div id="health-ping" class="summary-total text-secondary">...</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Disco Libre</div>
                    <div id="health-disk" class="summary-total text-secondary">...</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="glass-card p-4 h-100">
                <div class="stat-box">
                    <div class="tiny text-muted">Uptime</div>
                    <div id="health-uptime" class="summary-total text-secondary">...</div>
                </div>
            </div>
        </div>
    </div>

    <div class="glass-card p-4 mb-4 border-start border-warning border-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
            <div>
                <div class="section-title">Herramientas</div>
                <h2 class="h5 fw-bold mb-1"><i class="fas fa-tools text-warning me-2"></i>Integridad de Inventario (Kardex)</h2>
                <p class="text-muted mb-0 small">Utilice esta herramienta si detecta discrepancias entre el historial y el stock actual.</p>
            </div>
            <form method="POST" onsubmit="return confirm('Esta accion recalculara todas las existencias. Continuar?');">
                <input type="hidden" name="action" value="recalc_kardex">
                <button class="btn btn-warning text-dark fw-bold"><i class="fas fa-sync me-1"></i> Sincronizar Stock Maestro</button>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Respaldo</div>
                        <h2 class="h5 fw-bold mb-0"><i class="fas fa-save text-success me-2"></i>Copias de Seguridad</h2>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <form method="POST" class="flex-fill">
                        <input type="hidden" name="action" value="create_local">
                        <button class="btn btn-primary w-100 fw-bold"><i class="fas fa-file-archive me-1"></i> Crear Backup Local</button>
                    </form>
                    <form method="POST" class="flex-fill">
                        <input type="hidden" name="action" value="download_sql">
                        <button class="btn btn-outline-dark w-100"><i class="fas fa-download me-1"></i> Descargar SQL</button>
                    </form>
                </div>

                <div class="mb-3">
                    <div class="section-title mb-2">Copias Locales</div>
                    <div class="table-responsive border rounded-4 bg-white" style="max-height: 250px; overflow:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th>Archivo</th><th>Tamaño</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backups)): ?>
                                <tr><td colspan="3" class="text-center text-muted">Sin backups locales.</td></tr>
                                <?php else: foreach ($backups as $bk): $name = basename($bk); ?>
                                <tr>
                                    <td class="fw-bold"><i class="fas fa-file-archive text-warning me-1"></i> <?php echo $name; ?></td>
                                    <td><?php echo round(filesize($bk)/1024, 2)." KB"; ?></td>
                                    <td class="text-end text-nowrap">
                                        <a href="pos_admin.php?action=download_backup&file=<?php echo urlencode($name); ?>" class="btn btn-sm btn-success" title="Descargar .tar.gz"><i class="fas fa-download"></i></a>
                                        <form method="POST" class="d-inline ajax-local-restore-form">
                                            <input type="hidden" name="action" value="restore_local_dry_run">
                                            <input type="hidden" name="filename" value="<?php echo $name; ?>">
                                            <button class="btn btn-sm btn-outline-primary" title="Dry run"><i class="fas fa-vial"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline ajax-local-restore-form" onsubmit="return confirm('Restaurar?');">
                                            <input type="hidden" name="action" value="restore_local">
                                            <input type="hidden" name="filename" value="<?php echo $name; ?>">
                                            <button class="btn btn-sm btn-warning" title="Restaurar"><i class="fas fa-undo"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminar?');">
                                            <input type="hidden" name="action" value="delete_local">
                                            <input type="hidden" name="filename" value="<?php echo $name; ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="section-title mb-2">Subir copia al servidor</div>
                    <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="action" value="upload_backup">
                        <input type="file" name="backup_file" class="form-control" accept=".tar.gz,.tgz" required style="flex:1;">
                        <button class="btn btn-outline-success fw-bold"><i class="fas fa-cloud-upload-alt me-1"></i> Subir</button>
                    </form>
                    <div class="tiny text-muted mt-2">Sube un <code>.tar.gz</code> al servidor. Aparecerá en la lista de copias locales para restaurar cuando quieras.</div>
                </div>

                <div>
                    <div class="section-title mb-2">Restaurar externo</div>
                    <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2" id="externalRestoreForm">
                        <input type="file" name="sql_file" class="form-control" accept=".sql,.tar.gz,.tgz" required style="flex:1;">
                        <button class="btn btn-outline-primary" name="action" value="upload_dry_run"><i class="fas fa-vial"></i> Dry-run</button>
                        <button class="btn btn-danger" name="action" value="upload_restore" onclick="return confirm('PELIGRO! Sobreescribira la BD.');"><i class="fas fa-upload"></i> Restaurar</button>
                    </form>
                    <div class="tiny text-muted mt-2">Formatos: <code>.sql</code>, <code>.tar.gz</code>. Use Dry-run primero.</div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="glass-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <div class="section-title">Datos</div>
                        <h2 class="h5 fw-bold mb-0"><i class="fas fa-table text-primary me-2"></i>Tablas y Datos</h2>
                    </div>
                </div>
                <div class="table-responsive border rounded-4 bg-white" style="max-height: 500px; overflow:auto;">
                    <table class="table table-striped mb-0">
                        <thead class="table-dark sticky-top">
                            <tr><th>Tabla</th><th class="text-center">Filas</th><th class="text-end">Tamaño</th></tr>
                        </thead>
                        <tbody>
                            <?php $totalRows = 0; foreach ($stats as $row): $size = round(($row['data_length'] + $row['index_length']) / 1024, 1); $totalRows += $row['table_rows']; ?>
                            <tr>
                                <td class="fw-bold <?php echo $row['table_name'] === 'kardex' ? 'text-warning' : 'text-primary'; ?>"><?php echo $row['table_name']; ?></td>
                                <td class="text-center"><?php echo number_format($row['table_rows']); ?></td>
                                <td class="text-end"><?php echo $size; ?> KB</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr><th class="text-uppercase">Total</th><th class="text-center"><?php echo number_format($totalRows); ?></th><th></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() { fetchHealthData(); setInterval(fetchHealthData, 10000); });
async function fetchHealthData() {
    try {
        const res = await fetch('pos_health.php');
        const data = await res.json();
        document.getElementById('health-db').innerHTML = (data.services && data.services.db === 'OK') ? '<span class="text-success"><i class="fas fa-check-circle"></i> ONLINE</span>' : '<span class="text-danger">ERROR</span>';
        document.getElementById('health-ping').innerText = (data.response_time_ms || 0) + ' ms';
        const freeGb = ((data.services ? data.services.disk_free_mb : 0) / 1024).toFixed(2);
        document.getElementById('health-disk').innerText = freeGb + ' GB Libres';
        document.getElementById('health-uptime').innerText = (data.server && data.server.uptime) ? data.server.uptime : 'N/A';
    } catch (e) { console.error("Health check failed", e); }
}

const ajaxRestorePanel = document.getElementById('ajaxRestorePanel');
const ajaxRestoreMeta = document.getElementById('ajaxRestoreMeta');
const ajaxRestoreCounters = document.getElementById('ajaxRestoreCounters');
const ajaxRestoreMode = document.getElementById('ajaxRestoreMode');
const ajaxRestoreSummary = document.getElementById('ajaxRestoreSummary');
const ajaxRestoreLog = document.getElementById('ajaxRestoreLog');
const ajaxRestoreProgressBar = document.getElementById('ajaxRestoreProgressBar');

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, function (m) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m];
    });
}

function resetAjaxRestoreUi(mode, source) {
    ajaxRestorePanel.classList.remove('d-none');
    ajaxRestoreMeta.textContent = 'Origen: ' + source;
    ajaxRestoreMode.textContent = mode;
    ajaxRestoreSummary.textContent = 'Preparando...';
    ajaxRestoreCounters.textContent = '0 / 0 sentencias';
    ajaxRestoreProgressBar.style.width = '0%';
    ajaxRestoreProgressBar.textContent = '0%';
    ajaxRestoreProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
    ajaxRestoreLog.innerHTML = '';
}

function appendRestoreLogLine(event) {
    const badgeClass = event.status === 'ok' ? 'success' : (event.status === 'error' ? 'danger' : 'info');
    const line = document.createElement('div');
    line.className = 'log-line';
    line.innerHTML = `<div class="d-flex justify-content-between gap-3"><div><span class="badge bg-${badgeClass} me-2">${escapeHtml(event.op)} · ${escapeHtml(event.status)}</span><b>${escapeHtml(event.table)}</b></div><div class="text-muted">${event.current || 0}/${event.total || 0}</div></div>${event.detail ? `<div class="text-muted mt-1">${escapeHtml(event.detail)}</div>` : ''}`;
    ajaxRestoreLog.prepend(line);
}

function setRestoreProgress(current, total, errors) {
    const percent = total > 0 ? Math.round((current / total) * 100) : 0;
    ajaxRestoreCounters.textContent = `${current} / ${total} sentencias`;
    ajaxRestoreProgressBar.style.width = `${percent}%`;
    ajaxRestoreProgressBar.textContent = `${percent}%`;
    ajaxRestoreSummary.textContent = `Procesadas ${current} de ${total} sentencias. Errores: ${errors}`;
}

async function runRestoreAjax(formData, modeLabel, sourceLabel) {
    resetAjaxRestoreUi(modeLabel, sourceLabel);
    const res = await fetch('pos_admin.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } });
    if (!res.ok || !res.body) throw new Error(`HTTP ${res.status}`);
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let idx;
        while ((idx = buffer.indexOf('\n')) >= 0) {
            const line = buffer.slice(0, idx).trim();
            buffer = buffer.slice(idx + 1);
            if (!line) continue;
            const event = JSON.parse(line);
            if (event.type === 'start') {
                ajaxRestoreMeta.textContent = `Origen: ${event.source}`;
                ajaxRestoreMode.textContent = event.mode;
                ajaxRestoreSummary.textContent = event.message;
            } else if (event.type === 'progress') {
                appendRestoreLogLine(event);
                setRestoreProgress(event.current || 0, event.total || 0, event.summary?.errors || 0);
            } else if (event.type === 'finish') {
                const errors = event.report?.summary?.errors || 0;
                setRestoreProgress(event.report?.summary?.processed || event.report?.summary?.statements || 0, event.report?.summary?.statements || 0, errors);
                ajaxRestoreSummary.textContent = event.message;
                ajaxRestoreProgressBar.className = 'progress-bar bg-' + (errors ? 'warning' : 'success');
            } else if (event.type === 'error') {
                ajaxRestoreSummary.textContent = event.message;
                ajaxRestoreProgressBar.className = 'progress-bar bg-danger';
                throw new Error(event.message);
            }
        }
    }
}

document.getElementById('externalRestoreForm')?.addEventListener('submit', async function(ev) {
    ev.preventDefault();
    const submitter = ev.submitter;
    if (!submitter) return;
    const fd = new FormData(this);
    const action = submitter.value === 'upload_dry_run' ? 'ajax_upload_dry_run' : 'ajax_upload_restore';
    fd.set('action', action);
    const fileName = this.querySelector('input[name="sql_file"]').files[0]?.name || 'archivo';
    try {
        await runRestoreAjax(fd, submitter.value === 'upload_dry_run' ? 'dry-run' : 'restore', fileName);
    } catch (e) {
        alert('Error en restauracion AJAX: ' + e.message);
    }
});

document.querySelectorAll('.ajax-local-restore-form').forEach(form => {
    form.addEventListener('submit', async function(ev) {
        ev.preventDefault();
        const fd = new FormData(this);
        const filename = fd.get('filename') || 'backup';
        const action = fd.get('action') === 'restore_local_dry_run' ? 'ajax_restore_local_dry_run' : 'ajax_restore_local';
        fd.set('action', action);
        try {
            await runRestoreAjax(fd, action.includes('dry_run') ? 'dry-run' : 'restore', filename);
        } catch (e) {
            alert('Error en restauracion AJAX: ' + e.message);
        }
    });
});
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>
