<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(90);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    exit('No autenticado.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$html = (string)($_POST['html'] ?? '');
$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($_POST['filename'] ?? 'pagina_web'));
$filename = trim($filename, '_') ?: 'pagina_web';
$pageWidth = max(800, min(2400, intval($_POST['page_width'] ?? 1365)));
$pageHeight = max(600, min(30000, intval($_POST['page_height'] ?? 1200)));

if ($html === '' || strlen($html) > 20 * 1024 * 1024) {
    http_response_code(400);
    exit('Contenido HTML inválido.');
}

$wkhtmltopdf = '/usr/bin/wkhtmltopdf';
if (!is_executable($wkhtmltopdf)) {
    http_response_code(500);
    exit('wkhtmltopdf no está disponible.');
}

$baseTag = '<base href="file://' . __DIR__ . '/">';
$pdfStyle = '<style>'
    . '.web-pdf-exclude,.modal-backdrop,#palweb-float-nav{display:none!important}'
    . 'body{overflow:visible!important}'
    . '</style>';
$html = preg_replace('/<head>/i', '<head>' . $baseTag . $pdfStyle, $html, 1);

$tempBase = tempnam(sys_get_temp_dir(), 'palweb_web_pdf_');
$htmlFile = $tempBase . '.html';
$pdfFile = $htmlFile . '.pdf';

if ($tempBase === false || !@rename($tempBase, $htmlFile) || file_put_contents($htmlFile, $html) === false) {
    @unlink($tempBase);
    @unlink($htmlFile);
    http_response_code(500);
    exit('No se pudo preparar la página.');
}

$command = escapeshellarg($wkhtmltopdf)
    . ' --quiet --enable-local-file-access --javascript-delay 500'
    . ' --viewport-size ' . $pageWidth . 'x' . $pageHeight
    . ' --page-width ' . $pageWidth . 'px --page-height ' . $pageHeight . 'px'
    . ' --margin-top 0 --margin-right 0 --margin-bottom 0 --margin-left 0 '
    . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile) . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
@unlink($htmlFile);

if ($exitCode !== 0 || !is_file($pdfFile) || filesize($pdfFile) < 100) {
    @unlink($pdfFile);
    http_response_code(500);
    exit('No se pudo convertir la página web a PDF.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);
@unlink($pdfFile);
