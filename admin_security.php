<?php
// Autorización de administración verificada en servidor, incluso para sesiones antiguas.
require_once __DIR__ . '/pos_security.php';
pos_security_bootstrap_session();
header('Cache-Control: no-store');
if (($_SESSION['admin_logged_in'] ?? false) !== true || empty($_SESSION['user_id'])) {
    pos_security_error('Acceso de administrador requerido.', 403);
}
require_once __DIR__ . '/db.php';
$adminCheck = $pdo->prepare('SELECT rol, activo FROM users WHERE id = ? LIMIT 1');
$adminCheck->execute([(int)$_SESSION['user_id']]);
$adminAccount = $adminCheck->fetch(PDO::FETCH_ASSOC);
if (!$adminAccount || (int)$adminAccount['activo'] !== 1
    || !in_array(strtolower(trim((string)$adminAccount['rol'])), ['admin', 'administrador', 'administrator'], true)) {
    unset($_SESSION['admin_logged_in'], $_SESSION['admin_user']);
    pos_security_error('Acceso de administrador requerido.', 403);
}
pos_security_ensure_csrf_token();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $csrfInput = $_POST;
    if (!$csrfInput) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($decoded)) $csrfInput = $decoded;
    }
    pos_security_require_csrf($csrfInput);
}

function admin_security_form_token(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(pos_security_ensure_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
