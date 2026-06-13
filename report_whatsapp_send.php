<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(90);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'No autenticado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'msg' => 'Método no permitido.']);
    exit;
}

if (!defined('POSBOT_API_ROOT')) {
    define('POSBOT_API_ROOT', __DIR__);
}
require_once POSBOT_API_ROOT . '/posbot_api/bootstrap.php';
require_once POSBOT_API_ROOT . '/posbot_api/helpers/runtime.php';

function generarPdfReporte(array $params): string {
    $wkhtmltopdf = '/usr/bin/wkhtmltopdf';
    if (!is_executable($wkhtmltopdf)) {
        throw new RuntimeException('wkhtmltopdf no está disponible para generar el PDF.');
    }

    $originalGet = $_GET;
    $_GET = $params;
    ob_start();
    try {
        include __DIR__ . '/report_print.php';
        $html = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $_GET = $originalGet;
        throw $e;
    }
    $_GET = $originalGet;

    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException('No se pudo generar el contenido del reporte.');
    }

    $pdfStyle = '<style>.actions{display:none!important}.page{box-shadow:none!important}</style>';
    $html = preg_replace('/<head>/i', '<head><base href="file://' . __DIR__ . '/">' . $pdfStyle, $html, 1);
    $tempBase = tempnam(sys_get_temp_dir(), 'palweb_report_');
    if ($tempBase === false) {
        throw new RuntimeException('No se pudo preparar el reporte temporal.');
    }
    $htmlFile = $tempBase . '.html';
    $pdfFile = $htmlFile . '.pdf';
    if (!@rename($tempBase, $htmlFile) || file_put_contents($htmlFile, $html) === false) {
        @unlink($tempBase);
        @unlink($htmlFile);
        throw new RuntimeException('No se pudo preparar el reporte temporal.');
    }

    $command = escapeshellarg($wkhtmltopdf)
        . ' --quiet --enable-local-file-access --javascript-delay 1200 '
        . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    @unlink($htmlFile);

    if ($exitCode !== 0 || !is_file($pdfFile) || filesize($pdfFile) < 100) {
        @unlink($pdfFile);
        throw new RuntimeException('No se pudo generar el PDF del reporte.');
    }

    return $pdfFile;
}

try {
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $mode = ($_POST['report_mode'] ?? '') === 'session' ? 'session' : 'range';
    if (strlen($phone) < 8 || strlen($phone) > 15) {
        throw new RuntimeException('El número debe incluir el código de país.');
    }

    $bridge = bot_validate_bridge_for_campaign();
    if (empty($bridge['ok'])) {
        $state = trim((string)($bridge['state'] ?? 'desconocido'));
        throw new RuntimeException("WhatsApp Web no está conectado. Estado: {$state}.");
    }

    if ($mode === 'session') {
        $id = intval($_POST['session_id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Sesión de caja inválida.');
        }
        $params = ['mode' => 'session', 'id' => $id];
        $filename = "reporte_caja_{$id}.pdf";
        $caption = "Reporte de caja #{$id}";
    } else {
        $start = trim($_POST['start'] ?? '');
        $end = trim($_POST['end'] ?? '');
        $scope = ($_POST['scope'] ?? '') === 'global' ? 'global' : 'local';
        $startDate = DateTime::createFromFormat('Y-m-d', $start);
        $endDate = DateTime::createFromFormat('Y-m-d', $end);
        if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
            throw new RuntimeException('Rango de fechas inválido.');
        }
        if ($startDate > $endDate) {
            throw new RuntimeException('La fecha inicial no puede ser posterior a la final.');
        }
        $params = ['mode' => 'range', 'start' => $start, 'end' => $end, 'scope' => $scope];
        $filename = "reporte_{$start}_al_{$end}.pdf";
        $caption = "Reporte financiero del {$start} al {$end}";
    }

    $pdfFile = generarPdfReporte($params);
    $pdfContent = file_get_contents($pdfFile);
    @unlink($pdfFile);
    if ($pdfContent === false || $pdfContent === '') {
        throw new RuntimeException('No se pudo leer el PDF generado.');
    }

    $job = [
        'id' => 'report_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
        'target_id' => $phone,
        'type' => 'document',
        'file_base64' => base64_encode($pdfContent),
        'filename' => $filename,
        'mimetype' => 'application/pdf',
        'caption' => $caption,
    ];
    if (!bot_enqueue_bridge_job($job)) {
        throw new RuntimeException('No se pudo encolar el PDF en WhatsApp.');
    }

    echo json_encode([
        'status' => 'ok',
        'msg' => 'PDF generado y encolado para enviar por WhatsApp.',
        'job_id' => $job['id'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
