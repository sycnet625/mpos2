<?php
// ARCHIVO: fix_sessions.php
// VERSIÓN: 2.1 (SIN BLOQUEO DE SESIÓN - BORRAR AL TERMINAR)
require_once 'db.php';

// Iniciamos sesión por si acaso, pero no bloqueamos si falla
session_start();

/* BLOQUEO DE SEGURIDAD DESACTIVADO TEMPORALMENTE
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die("Acceso denegado.");
}
*/

$msg = "";
$orphansCount = 0;

// 1. OBTENER CANTIDAD DE HUÉRFANOS
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM ventas_cabecera WHERE id_sesion_caja = 0");
    $orphansCount = $stmt->fetchColumn();
} catch (Exception $e) {
    die("Error DB: " . $e->getMessage());
}

// 2. EJECUTAR REPARACIÓN POR FECHA CONTABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_fix'])) {
    try {
        $pdo->beginTransaction();

        // LOGICA: Coincidencia por FECHA (Día) y SUCURSAL
        // Une la venta con la sesión que tenga la misma fecha contable en la misma sucursal
        $sqlFix = "
            UPDATE ventas_cabecera v
            INNER JOIN caja_sesiones s 
                ON v.id_sucursal = s.id_sucursal 
                AND DATE(v.fecha) = s.fecha_contable
            SET v.id_sesion_caja = s.id
            WHERE v.id_sesion_caja = 0
        ";

        $stmtFix = $pdo->prepare($sqlFix);
        $stmtFix->execute();
        $affected = $stmtFix->rowCount();

        $pdo->commit();
        
        // Recalcular restantes
        $stmt = $pdo->query("SELECT COUNT(*) FROM ventas_cabecera WHERE id_sesion_caja = 0");
        $orphansCount = $stmt->fetchColumn();

        $msg = "<div class='alert alert-success'>
                    <h4 class='alert-heading'><i class='fas fa-check-circle'></i> ¡Reparación Exitosa!</h4>
                    <p>Se han vinculado <strong>$affected</strong> tickets a sus sesiones correctamente.</p>
                    <hr>
                    <p class='mb-0'>Tickets que siguen sin sesión (por no coincidir fecha/sucursal): <strong>$orphansCount</strong></p>
                </div>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>Error crítico: " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Fix Sesiones (Modo Abierto)</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>body { background: #f4f6f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }</style>
</head>
<body>

<div class="card shadow-lg" style="width: 100%; max-width: 500px;">
    <div class="card-header bg-danger text-white text-center">
        <h4 class="m-0"><i class="fas fa-unlock"></i> Reparador (Sin Login)</h4>
    </div>
    <div class="card-body text-center p-4">
        
        <?php if(!empty($msg)) echo $msg; ?>

        <div class="py-3">
            <h1 class="display-4 fw-bold text-warning"><?php echo $orphansCount; ?></h1>
            <p class="text-muted">Ventas "Huérfanas" (id_sesion_caja = 0)</p>
        </div>

        <div class="alert alert-light border small text-start">
            <strong><i class="fas fa-info-circle"></i> Lógica Aplicada:</strong><br>
            Se asignará la sesión cuya <code>fecha_contable</code> coincida exactamente con el día de la venta (`v.fecha`) en la misma sucursal.
        </div>

        <?php if($orphansCount > 0): ?>
        <form method="POST">
            <input type="hidden" name="run_fix" value="1">
            <button type="submit" class="btn btn-danger w-100 py-2 fw-bold">
                <i class="fas fa-link"></i> VINCULAR AHORA
            </button>
        </form>
        <?php else: ?>
            <button class="btn btn-success w-100 disabled"><i class="fas fa-check"></i> Todo arreglado</button>
        <?php endif; ?>
        
        <div class="mt-4 pt-3 border-top">
            <a href="sales_history.php" class="btn btn-link text-decoration-none">Ir al Historial</a>
        </div>
    </div>
</div>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

