<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function out_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_valid_dest(string $dest): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+$/', $dest);
}

if ($action === 'check') {
    $script = '/var/www/migrar_espejo.sh';
    $exists = file_exists($script) && is_executable($script);
    $sshpass = trim((string)shell_exec('command -v sshpass 2>/dev/null'));
    out_json([
        'ok' => true,
        'script_ready' => $exists,
        'script_path' => $script,
        'sshpass_available' => $sshpass !== ''
    ]);
}

if ($action !== 'run_step') {
    out_json(['ok' => false, 'msg' => 'Acción inválida'], 400);
}

$dest = trim((string)($_POST['dest'] ?? ''));
$sshPort = trim((string)($_POST['ssh_port'] ?? '22'));
$mode = trim((string)($_POST['mode'] ?? 'dry-run'));
$syncUsers = trim((string)($_POST['sync_users'] ?? 'yes'));
$step = trim((string)($_POST['step'] ?? ''));
$sshPassword = (string)($_POST['ssh_password'] ?? '');

if (!is_valid_dest($dest)) {
    out_json(['ok' => false, 'msg' => 'Destino inválido. Usa formato usuario@host'], 400);
}
if (!preg_match('/^\d{1,5}$/', $sshPort)) {
    out_json(['ok' => false, 'msg' => 'Puerto SSH inválido'], 400);
}
if (!in_array($mode, ['dry-run', 'run'], true)) {
    out_json(['ok' => false, 'msg' => 'Modo inválido'], 400);
}
if (!in_array($syncUsers, ['yes', 'no'], true)) {
    out_json(['ok' => false, 'msg' => 'sync_users inválido'], 400);
}
if (!preg_match('/^[1-7]$/', $step)) {
    out_json(['ok' => false, 'msg' => 'Paso inválido (1..7)'], 400);
}

$scriptPath = '/var/www/migrar_espejo.sh';
if (!file_exists($scriptPath)) {
    out_json(['ok' => false, 'msg' => 'No existe migrar_espejo.sh'], 500);
}

$cmd = '/usr/bin/env bash ' . escapeshellarg($scriptPath)
    . ' --dest ' . escapeshellarg($dest)
    . ' --ssh-port ' . escapeshellarg($sshPort)
    . ' --mode ' . escapeshellarg($mode)
    . ' --sync-users ' . escapeshellarg($syncUsers)
    . ' --only-step ' . escapeshellarg($step);

if ($sshPassword !== '') {
    $cmd .= ' --ssh-password ' . escapeshellarg($sshPassword);
}

$cmd .= ' 2>&1';

$output = [];
$code = 0;
exec($cmd, $output, $code);

out_json([
    'ok' => $code === 0,
    'exit_code' => $code,
    'step' => (int)$step,
    'output' => implode("\n", $output),
    'ran_at' => date('Y-m-d H:i:s')
]);
