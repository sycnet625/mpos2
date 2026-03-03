<?php
// =============================================================
// MarcelCraft API — Guardar/Cargar/Listar/Borrar mapas
// Archivos almacenados en /var/www/marinero/maps/
// =============================================================
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$mapsDir = __DIR__ . '/maps';

if (!is_dir($mapsDir)) {
    mkdir($mapsDir, 0755, true);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $files = glob($mapsDir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $result = [];
        foreach ($files as $f) {
            $basename = basename($f);
            $content  = @json_decode(file_get_contents($f), true);
            $result[] = [
                'file'  => $basename,
                'title' => $content['title'] ?? 'Sin título',
                'w'     => $content['w'] ?? '?',
                'h'     => $content['h'] ?? '?',
                'saved' => date('d/m/Y H:i', filemtime($f)),
            ];
        }
        echo json_encode($result);
        break;

    case 'load':
        $file = basename($_GET['file'] ?? '');
        if (!preg_match('/^[\w\-]+\.json$/', $file)) {
            echo json_encode(['error' => 'Nombre de archivo inválido']);
            break;
        }
        $path = $mapsDir . '/' . $file;
        if (!file_exists($path)) {
            echo json_encode(['error' => 'Archivo no encontrado']);
            break;
        }
        echo file_get_contents($path);
        break;

    case 'save':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || !isset($body['map'])) {
            echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
            break;
        }
        $title    = preg_replace('/[^\w\s\-]/', '', $body['title'] ?? 'mapa');
        $title    = trim(preg_replace('/\s+/', '_', $title)) ?: 'mapa';
        $title    = substr($title, 0, 32);
        $filename = date('Ymd_His') . '_' . $title . '.json';
        $path     = $mapsDir . '/' . $filename;
        $ok       = file_put_contents($path, json_encode($body));
        echo json_encode(['ok' => $ok !== false, 'file' => $filename]);
        break;

    case 'delete':
        $body = json_decode(file_get_contents('php://input'), true);
        $file = basename($body['file'] ?? '');
        if (!preg_match('/^[\w\-]+\.json$/', $file)) {
            echo json_encode(['ok' => false, 'error' => 'Nombre de archivo inválido']);
            break;
        }
        $path = $mapsDir . '/' . $file;
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'Archivo no encontrado']);
            break;
        }
        echo json_encode(['ok' => unlink($path)]);
        break;

    default:
        echo json_encode(['error' => 'Acción desconocida']);
}