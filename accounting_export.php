<?php
// ARCHIVO: /var/www/palweb/api/accounting_export.php
require_once 'db.php';

$type = $_GET['type'] ?? 'diario'; 
$filename = "export_" . $type . "_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

if ($type === 'diario') {
    fputcsv($output, ['ID', 'Fecha', 'Cuenta', 'Debe', 'Haber', 'Detalle']);
    $sql = "SELECT id, fecha, cuenta, debe, haber, detalle FROM contabilidad_diario ORDER BY fecha DESC";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($output, $row);
} 
elseif ($type === 'mayor') {
    // FILTRO JERÁRQUICO: "101%" trae 101, 101.001, etc.
    $cuenta = $_GET['cuenta'] ?? '';
    fputcsv($output, ['Fecha', 'Cuenta', 'Debe', 'Haber', 'Detalle']);
    
    $sql = "SELECT fecha, cuenta, debe, haber, detalle FROM contabilidad_diario WHERE cuenta LIKE ? ORDER BY fecha ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cuenta . '%']); // El % al final hace la magia de la jerarquía
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($output, $row);
}

fclose($output);
exit;

