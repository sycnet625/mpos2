<?php
declare(strict_types=1);

define('FB_BOT_API_LIB_ONLY', true);
require_once __DIR__ . '/fb_bot_api.php';

$interval = max(10, (int)(getenv('FB_BOT_WORKER_INTERVAL') ?: 15));
$hostname = gethostname() ?: 'localhost';

function fb_worker_refresh_connection(): void {
    global $pdo;
    require __DIR__ . '/db.php';
}

function fb_worker_ensure_connection(): void {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        fb_worker_refresh_connection();
        return;
    }
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        fb_worker_log('[fb-worker] reconectando MySQL: ' . $e->getMessage());
        fb_worker_refresh_connection();
    }
}

fwrite(STDOUT, '[' . date('c') . "] [fb-worker] iniciado en {$hostname} con intervalo {$interval}s\n");
fb_worker_log("[fb-worker] iniciado en {$hostname} con intervalo {$interval}s");

while (true) {
    try {
        fb_worker_ensure_connection();
        fb_ensure_tables($pdo);
        $cfg = fb_cfg($pdo);
        $res = fb_process_queue($pdo, $cfg);
        $processed = (int)($res['processed'] ?? 0);
        if ($processed > 0) {
            fwrite(STDOUT, '[' . date('c') . "] [fb-worker] campañas procesadas: {$processed}\n");
            fb_worker_log("[fb-worker] campañas procesadas: {$processed}");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] [fb-worker] error: ' . $e->getMessage() . "\n");
        fb_worker_log('[fb-worker] error: ' . $e->getMessage());
        if (stripos($e->getMessage(), 'server has gone away') !== false || stripos($e->getMessage(), 'Lost connection') !== false) {
            try {
                fb_worker_refresh_connection();
                fb_worker_log('[fb-worker] MySQL reconectado tras error');
            } catch (Throwable $inner) {
                fb_worker_log('[fb-worker] fallo reconectando MySQL: ' . $inner->getMessage());
            }
        }
    }
    sleep($interval);
}
