<?php
// ARCHIVO: reset.php
require_once 'db.php';

$message = "";
$msgType = "";

// --- LÓGICA DE ACCIONES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = $_POST['table'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($table && $action) {
        try {
            // Desactivar chequeo de llaves foráneas para permitir vaciado/borrado sin errores
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            if ($action === 'truncate') {
                $pdo->exec("TRUNCATE TABLE `$table`");
                $message = "La tabla <strong>$table</strong> ha sido vaciada correctamente.";
                $msgType = "warning";
            } elseif ($action === 'drop') {
                $pdo->exec("DROP TABLE `$table`");
                $message = "La tabla <strong>$table</strong> ha sido eliminada permanentemente.";
                $msgType = "danger";
            }

            // Reactivar chequeo
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $msgType = "dark";
        }
    }
}

// --- OBTENER TABLAS Y CONTEOS ---
$tablesData = [];
try {
    // Obtener lista de tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $t) {
        // Contar registros de cada una
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $tablesData[] = ['name' => $t, 'rows' => $count];
    }
} catch (Exception $e) {
    $message = "Error leyendo base de datos: " . $e->getMessage();
    $msgType = "danger";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>DB Reset Tool 💣</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #e9ecef; }
        .card-table { transition: all 0.3s; border: none; border-radius: 15px; }
        .card-table:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
        .stat-number { font-size: 2.5rem; font-weight: 800; color: #343a40; }
        .table-name { font-weight: 600; color: #495057; font-size: 1.1rem; }
        .btn-action { width: 100%; font-weight: bold; border-radius: 0 0 15px 15px; }
        .badge-rows { position: absolute; top: 15px; right: 15px; }
    </style>
</head>
<body class="p-4">

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h1 class="fw-bold"><i class="fas fa-database text-primary"></i> Gestor de Tablas</h1>
            <p class="text-muted">Base de Datos: <strong><?php echo $db; ?></strong> | Total Tablas: <strong><?php echo count($tablesData); ?></strong></p>
        </div>
        <button onclick="location.reload()" class="btn btn-outline-primary"><i class="fas fa-sync-alt"></i> Refrescar</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show shadow-sm" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($tablesData)): ?>
        <div class="alert alert-info text-center py-5">
            <h4><i class="fas fa-folder-open"></i> La base de datos está vacía</h4>
            <p>No hay tablas para mostrar.</p>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($tablesData as $t): ?>
            <div class="col-md-4 col-sm-6">
                <div class="card card-table shadow-sm h-100">
                    <div class="card-body text-center position-relative pt-5 pb-4">
                        
                        <div class="mb-3">
                            <?php if ($t['rows'] > 0): ?>
                                <i class="fas fa-table fa-3x text-primary"></i>
                            <?php else: ?>
                                <i class="far fa-square fa-3x text-muted opacity-25"></i>
                            <?php endif; ?>
                        </div>

                        <h5 class="table-name mb-1"><?php echo $t['name']; ?></h5>
                        
                        <div class="stat-number my-2">
                            <?php echo number_format($t['rows']); ?>
                        </div>
                        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Registros</small>
                    </div>

                    <div class="card-footer p-0 border-0 d-flex overflow-hidden" style="border-radius: 0 0 15px 15px;">
                        
                        <form method="POST" class="w-50" onsubmit="return confirm('⚠️ ¿Estás SEGURO de vaciar <?php echo $t['name']; ?>?\nEsto borrará todos los datos pero mantendrá la estructura.');">
                            <input type="hidden" name="table" value="<?php echo $t['name']; ?>">
                            <input type="hidden" name="action" value="truncate">
                            <button type="submit" class="btn btn-warning w-100 h-100 rounded-0 py-3 fw-bold text-dark border-end">
                                <i class="fas fa-eraser"></i> Vaciar
                            </button>
                        </form>

                        <form method="POST" class="w-50" onsubmit="return confirm('⛔ ¡PELIGRO!\n¿Eliminar tabla <?php echo $t['name']; ?>?\nEsta acción NO se puede deshacer.');">
                            <input type="hidden" name="table" value="<?php echo $t['name']; ?>">
                            <input type="hidden" name="action" value="drop">
                            <button type="submit" class="btn btn-danger w-100 h-100 rounded-0 py-3 fw-bold">
                                <i class="fas fa-trash-alt"></i> Drop
                            </button>
                        </form>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>

<?php include_once 'menu_master.php'; ?>

</body>
</html>

