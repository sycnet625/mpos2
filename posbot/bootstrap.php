<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
$posBotBuildPatterns = [
    __DIR__ . '/*.php',
    __DIR__ . '/../pos_bot.php',
    __DIR__ . '/../pos_bot_api.php',
    __DIR__ . '/../wa_web_bridge/bridge.js',
    __DIR__ . '/../posbot_api/*.php',
    __DIR__ . '/../posbot_api/helpers/*.php',
    __DIR__ . '/../posbot_api/router/*.php',
];
$posBotBuildFiles = [];
foreach ($posBotBuildPatterns as $posBotBuildPattern) {
    foreach ((array) glob($posBotBuildPattern) as $posBotBuildFile) {
        $posBotBuildFiles[$posBotBuildFile] = $posBotBuildFile;
    }
}
$posBotBuildTs = 0;
foreach ($posBotBuildFiles as $posBotBuildFile) {
    $posBotFileTs = @filemtime($posBotBuildFile) ?: 0;
    if ($posBotFileTs > $posBotBuildTs) {
        $posBotBuildTs = $posBotFileTs;
    }
}
if ($posBotBuildTs <= 0) {
    $posBotBuildTs = time();
}
$gitHead = @trim((string)@shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --short HEAD 2>/dev/null'));
if ($gitHead !== '' && preg_match('/^[a-f0-9]{7,}$/i', $gitHead)) {
    $posBotVersion = 'v 1.1.' . strtolower($gitHead);
} else {
    $posBotVersion = 'v 1.1.' . str_pad((string) (int) (intdiv($posBotBuildTs, 60) % 10000), 4, '0', STR_PAD_LEFT);
}
