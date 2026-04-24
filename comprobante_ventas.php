<?php
/**
 * ARCHIVO: comprobante_ventas.php
 * DESCRIPCIÓN: Módulo de visualización y descarga de comprobantes de ventas
 * Genera comprobantes profesionales en HTML/PDF
 */

require_once 'db.php';
require_once 'config_loader.php';
require_once 'helpers/comprobante_generator.php';

session_start();

// Verificar permisos de admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Permitir acceso sin login si viene del POS con PIN
    if (!isset($_SESSION['cajero'])) {
        header('Location: login.php');
        exit;
    }
}

$idVenta = isset($_GET['id']) ? intval($_GET['id']) : null;
$markupPct = isset($_GET['markup_pct']) ? max(0, round(floatval($_GET['markup_pct']), 2)) : 0.0;

if (!$idVenta) {
    die("<h2>❌ Error</h2><p>No se especificó ID de venta.</p><p><a href='dashboard.php'>Volver al dashboard</a></p>");
}

try {
    $generator = new ComprobanteGenerator($pdo, $config);

    // Si solicita PDF
    if (isset($_GET['format']) && $_GET['format'] === 'pdf') {
        $rutaPDF = $generator->generarPDF($idVenta);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="comprobante_' . $idVenta . '.pdf"');
        readfile($rutaPDF);
        unlink($rutaPDF);
        exit;
    }

    // Mostrar HTML (default)
    echo $generator->generarHTML($idVenta, $markupPct);

} catch (Throwable $e) {
    echo "<h2>❌ Error al generar comprobante</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='dashboard.php'>Volver</a></p>";
}
?>
