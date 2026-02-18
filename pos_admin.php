<?php
// ARCHIVO: /var/www/palweb/api/pos_admin.php
ini_set('display_errors', 0);
ini_set('max_execution_time', 600); // 10 min para backups grandes
ini_set('memory_limit', '512M'); // Aumentado para soportar el Kardex
require_once 'db.php';

// --- CONFIGURACIÓN ---
$backupDir = __DIR__ . '/backups';
if (!file_exists($backupDir)) mkdir($backupDir, 0777, true);

// --- CLASE DE UTILIDAD PARA BACKUP ---
class DbBackup {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

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

    public function restoreSQL($sqlContent) {
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $queries = explode(";\n", $sqlContent);
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && substr($query, 0, 2) != '--') {
                try { $this->pdo->exec($query); } catch (Exception $e) {}
            }
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }
}

$dbUtil = new DbBackup($pdo);
$msg = "";
$msgType = "";

// --- PROCESAR ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ACCIONES EXISTENTES DE BACKUP...
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
            if(file_exists($sqlFile)) unlink($sqlFile);
            
            if(file_exists($tarFile)) {
                $msg = "Backup creado: <b>$filename.tar.gz</b>"; $msgType = "success";
            } else { throw new Exception("Error al comprimir."); }
        }

        if ($action === 'upload_restore') {
            if ($_FILES['sql_file']['error'] === 0) {
                $content = file_get_contents($_FILES['sql_file']['tmp_name']);
                $dbUtil->restoreSQL($content);
                $msg = "Restauración completada."; $msgType = "success";
            } else { throw new Exception("Error al subir archivo."); }
        }

        if ($action === 'restore_local') {
            $file = $_POST['filename'];
            $fullPath = "$backupDir/$file";
            if (file_exists($fullPath)) {
                $cmd = "cd " . escapeshellarg($backupDir) . " && tar -xzf " . escapeshellarg($file);
                shell_exec($cmd);
                $sqlName = str_replace('.tar.gz', '.sql', $file);
                $sqlPath = "$backupDir/$sqlName";
                if (file_exists($sqlPath)) {
                    $content = file_get_contents($sqlPath);
                    $dbUtil->restoreSQL($content);
                    unlink($sqlPath);
                    $msg = "Sistema restaurado al punto: <b>$file</b>"; $msgType = "warning";
                } else { throw new Exception("No se pudo extraer el SQL."); }
            }
        }
        
        if ($action === 'delete_local') {
            $file = $_POST['filename'];
            if(file_exists("$backupDir/$file")) unlink("$backupDir/$file");
            $msg = "Archivo eliminado."; $msgType = "info";
        }

        // --- NUEVO: HERRAMIENTA KARDEX ---
        // Recalcula el stock maestro sumando todo el historial del Kardex
        // Útil si hay discrepancias o después de una restauración parcial
        if ($action === 'recalc_kardex') {
            $pdo->beginTransaction();
            
            // 1. Resetear stock maestro a 0
            $pdo->exec("UPDATE stock_almacen SET cantidad = 0");
            
            // 2. Sumarizar Kardex y actualizar maestro
            // Usamos ON DUPLICATE KEY UPDATE para insertar si falta o actualizar si existe
            $sqlSync = "INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal)
                        SELECT id_almacen, id_producto, SUM(cantidad), 1
                        FROM kardex
                        GROUP BY id_almacen, id_producto
                        ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)";
            
            $stmt = $pdo->prepare($sqlSync);
            $stmt->execute();
            
            $pdo->commit();
            $msg = "✅ Inventario Sincronizado: El stock maestro ahora coincide exactamente con el historial del Kardex.";
            $msgType = "success";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "Error: " . $e->getMessage();
        $msgType = "danger";
    }
}

// Stats
$stats = $pdo->query("SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
$backups = glob("$backupDir/*.tar.gz");
rsort($backups);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel | PalWeb</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .card-stat { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .card-stat:hover { transform: translateY(-5px); }
        .icon-box { font-size: 2rem; opacity: 0.8; }
    </style>
</head>
<body class="p-4">

<div class="container-fluid">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-cogs text-secondary"></i> Administración del Sistema</h3>
            <p class="text-muted mb-0">Mantenimiento, Copias de Seguridad y Salud del Servidor</p>
        </div>
        <div>
            <a href="pos.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Volver al POS</a>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show" role="alert">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 p-3">
                <div class="d-flex justify-content-between">
                    <div><h6 class="text-muted fw-bold text-uppercase">BD Estado</h6><h4 id="health-db" class="mb-0 text-secondary">...</h4></div>
                    <div class="icon-box text-primary"><i class="fas fa-database"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 p-3">
                <div class="d-flex justify-content-between">
                    <div><h6 class="text-muted fw-bold text-uppercase">Ping</h6><h4 id="health-ping" class="mb-0 text-secondary">...</h4></div>
                    <div class="icon-box text-info"><i class="fas fa-stopwatch"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 p-3">
                <div class="d-flex justify-content-between">
                    <div><h6 class="text-muted fw-bold text-uppercase">Disco</h6><h4 id="health-disk" class="mb-0 text-secondary">...</h4></div>
                    <div class="icon-box text-warning"><i class="fas fa-hdd"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-stat bg-white h-100 p-3">
                <div class="d-flex justify-content-between">
                    <div><h6 class="text-muted fw-bold text-uppercase">Uptime</h6><h5 id="health-uptime" class="mb-0 text-dark small">...</h5></div>
                    <div class="icon-box text-success"><i class="fas fa-server"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 border-start border-warning border-5">
        <div class="card-body d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-1"><i class="fas fa-tools text-warning me-2"></i> Integridad de Inventario (Kardex)</h5>
                <p class="text-muted mb-0 small">Utilice esta herramienta si detecta discrepancias entre el historial y el stock actual.</p>
            </div>
            <form method="POST" onsubmit="return confirm('¿Está seguro? Esta acción recalculará todas las existencias basándose en el historial de movimientos.');">
                <input type="hidden" name="action" value="recalc_kardex">
                <button class="btn btn-warning text-dark fw-bold px-4"><i class="fas fa-sync me-2"></i> Sincronizar Stock Maestro</button>
            </form>
        </div>
    </div>

    <div class="row g-4">
        
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold m-0"><i class="fas fa-save text-success"></i> Copias de Seguridad</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-2 mb-4">
                        <form method="POST" class="flex-fill">
                            <input type="hidden" name="action" value="create_local">
                            <button class="btn btn-primary w-100 py-2 fw-bold"><i class="fas fa-file-archive"></i> Crear Backup Local</button>
                        </form>
                        <form method="POST" class="flex-fill">
                            <input type="hidden" name="action" value="download_sql">
                            <button class="btn btn-outline-dark w-100 py-2"><i class="fas fa-download"></i> Descargar SQL</button>
                        </form>
                    </div>
                    <hr>
                    <h6 class="fw-bold text-muted mb-3">Copias Locales</h6>
                    <div class="table-responsive" style="max-height: 250px;">
                        <table class="table table-hover align-middle">
                            <thead class="table-light"><tr><th>Archivo</th><th>Tamaño</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                <?php if(empty($backups)): ?><tr><td colspan="3" class="text-center text-muted">Vacío.</td></tr>
                                <?php else: foreach($backups as $bk): $name = basename($bk); ?>
                                    <tr>
                                        <td><i class="fas fa-file-archive text-warning"></i> <?php echo $name; ?></td>
                                        <td><?php echo round(filesize($bk)/1024, 2)." KB"; ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Restaurar?');">
                                                <input type="hidden" name="action" value="restore_local"><input type="hidden" name="filename" value="<?php echo $name; ?>">
                                                <button class="btn btn-sm btn-warning"><i class="fas fa-undo"></i></button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?');">
                                                <input type="hidden" name="action" value="delete_local"><input type="hidden" name="filename" value="<?php echo $name; ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <hr>
                    <h6 class="fw-bold text-muted mb-2">Restaurar externo</h6>
                    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2" onsubmit="return confirm('¡PELIGRO! Sobreescribirá la BD.');">
                        <input type="hidden" name="action" value="upload_restore">
                        <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                        <button class="btn btn-danger"><i class="fas fa-upload"></i> Restaurar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3">
                    <h5 class="fw-bold m-0"><i class="fas fa-table text-primary"></i> Tablas y Datos</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 500px;">
                        <table class="table table-striped mb-0">
                            <thead class="table-dark sticky-top"><tr><th>Tabla</th><th class="text-center">Filas</th><th class="text-end">Tamaño</th></tr></thead>
                            <tbody>
                                <?php $totalRows = 0; foreach($stats as $row): 
                                    $size = round(($row['data_length'] + $row['index_length']) / 1024, 1);
                                    $totalRows += $row['table_rows']; ?>
                                <tr>
                                    <td class="fw-bold <?php echo $row['table_name'] === 'kardex' ? 'text-warning' : 'text-primary'; ?>">
                                        <?php echo $row['table_name']; ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format($row['table_rows']); ?></td>
                                    <td class="text-end"><?php echo $size; ?> KB</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light"><tr><th class="text-uppercase">Total</th><th class="text-center fs-5"><?php echo number_format($totalRows); ?></th><th></th></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Auto-Health Check
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
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>



<?php include_once 'menu_master.php'; ?>
</body>
</html>
