<?php
// onat_download.php — Sirve archivos generados de onat_archivos/ con auth de sesión.
ini_set('display_errors', 0);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/db.php';

$id   = intval($_GET['id'] ?? 0);
$type = strtolower((string)($_GET['type'] ?? 'xlsx'));

if (!in_array($type, ['xlsx','pdf'], true)) {
    http_response_code(400);
    exit('Tipo inválido.');
}

$col = $type === 'pdf' ? 'archivo_pdf' : 'archivo_xlsx';
$stmt = $pdo->prepare("SELECT id_empresa, modelo, $col AS path FROM onat_declaraciones WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['path'])) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

$absPath = __DIR__ . '/' . ltrim($row['path'], '/');
$realBase = realpath(__DIR__ . '/onat_archivos');
$realFile = realpath($absPath);
if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

$filename = $row['modelo'] . '.' . $type;
$mime = $type === 'pdf'
    ? 'application/pdf'
    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realFile));
readfile($realFile);
exit;
