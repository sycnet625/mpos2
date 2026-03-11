<?php
declare(strict_types=1);

define('FB_BOT_API_LIB_ONLY', true);
require_once __DIR__ . '/fb_bot_api.php';

$interval = max(10, (int)(getenv('FB_BOT_WORKER_INTERVAL') ?: 15));
$hostname = gethostname() ?: 'localhost';

fwrite(STDOUT, '[' . date('c') . "] [fb-worker] iniciado en {$hostname} con intervalo {$interval}s\n");

while (true) {
    try {
        fb_ensure_tables($pdo);
        $cfg = fb_cfg($pdo);
        $res = fb_process_queue($pdo, $cfg);
        $processed = (int)($res['processed'] ?? 0);
        if ($processed > 0) {
            fwrite(STDOUT, '[' . date('c') . "] [fb-worker] campañas procesadas: {$processed}\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] [fb-worker] error: ' . $e->getMessage() . "\n");
    }
    sleep($interval);
}
