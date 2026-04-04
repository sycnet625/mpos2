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

$affiliateView = [
    'title' => 'RAC · Red de Afiliados Cuba',
    'manifest' => '/affiliate_network_manifest.json',
    'icon' => '/affiliate_network_icon.svg',
    'styles' => '/affiliate_network/styles.css',
    'script' => '/affiliate_network/app.js',
];
