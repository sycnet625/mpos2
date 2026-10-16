<?php
// Contador atómico por dirección remota; no depende de cookies ni del navegador.
function auth_rate_limit(string $bucket, int $limit = 30, int $window = 600): void
{
    $key = hash('sha256', $bucket . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = sys_get_temp_dir() . '/palweb_auth_v2_' . $key;
    $handle = @fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        http_response_code(503);
        exit('Autenticación temporalmente no disponible.');
    }
    $state = json_decode(stream_get_contents($handle), true);
    $now = time();
    if (!is_array($state) || $now - (int)($state['start'] ?? 0) >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    $blocked = (int)$state['count'] >= $limit;
    if (!$blocked) {
        $state['count']++;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($blocked) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $window - ($now - (int)$state['start'])));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'msg' => 'Demasiados intentos. Espere antes de volver a iniciar sesión.']);
        exit;
    }
}
