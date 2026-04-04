<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

if (!defined('DISABLE_MENU_UNIT_CONVERTER')) {
    define('DISABLE_MENU_UNIT_CONVERTER', true);
}

if (empty($_SESSION['affiliate_csrf_token'])) {
    $_SESSION['affiliate_csrf_token'] = bin2hex(random_bytes(24));
}

$affiliateView = [
    'title' => 'RAC · Red de Afiliados Cuba',
    'manifest' => '/affiliate_network_manifest.json',
    'icon' => '/affiliate_network_icon.svg',
    'styles' => '/affiliate_network/styles.css',
    'csrf' => $_SESSION['affiliate_csrf_token'],
    'scripts' => [
        '/affiliate_network/js/core.js',
        '/affiliate_network/js/api.js',
        '/affiliate_network/js/render.js',
        '/affiliate_network/app.js',
    ],
];
