<?php
require_once __DIR__ . '/domain.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['affiliate_auth']['authenticated'])) {
    header('Location: /affiliate_network_login.php');
    exit;
}

if (!defined('DISABLE_MENU_UNIT_CONVERTER')) {
    define('DISABLE_MENU_UNIT_CONVERTER', true);
}

if (empty($_SESSION['affiliate_csrf_token'])) {
    $_SESSION['affiliate_csrf_token'] = bin2hex(random_bytes(24));
}

$allowedUiRoles = aff_allowed_ui_roles();
$initialRole = aff_ui_role_from_auth();
$authContext = aff_auth_context();

$affiliateView = [
    'title' => 'RAC · Red de Afiliados Cuba',
    'manifest' => '/affiliate_network_manifest.json',
    'icon' => '/affiliate_network_icon.svg',
    'styles' => '/affiliate_network/styles.css',
    'csrf' => $_SESSION['affiliate_csrf_token'],
    'auth' => $authContext,
    'allowed_roles' => $allowedUiRoles,
    'initial_role' => $initialRole,
    'can_admin' => aff_role_allowed(['admin']),
    'scripts' => [
        '/affiliate_network/js/core.js',
        '/affiliate_network/js/api.js',
        '/affiliate_network/js/render.js',
        '/affiliate_network/app.js',
    ],
];
