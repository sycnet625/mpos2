<?php
require_once __DIR__ . '/domain.php';

if (!function_exists('aff_view_build_version')) {
    function aff_view_build_version(): string
    {
        $cacheFile = '/var/www/tmp/rac_build_version.cache';
        $now = time();
        if (is_file($cacheFile) && ($now - (int)@filemtime($cacheFile)) < 300) {
            $cached = trim((string)@file_get_contents($cacheFile));
            if ($cached !== '') {
                return $cached;
            }
        }

        $default = 'v1 · build 1';
        $count = trim((string)@shell_exec('git -C /var/www rev-list --count HEAD 2>/dev/null'));
        $hash = trim((string)@shell_exec('git -C /var/www rev-parse --short HEAD 2>/dev/null'));
        if ($count === '' || !ctype_digit($count)) {
            $version = $default;
        } else {
            $version = 'v1 · build ' . $count . ($hash !== '' ? ' · ' . $hash : '');
        }

        @file_put_contents($cacheFile, $version);
        return $version;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!aff_is_authenticated() && empty($_SESSION['admin_logged_in'])) {
    header('Location: /affiliate_network_login.php');
    exit;
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
    'session_ttl_minutes' => (int)(AFF_SESSION_TTL_SECONDS / 60),
    'build_version' => aff_view_build_version(),
    'scripts' => [
        '/affiliate_network/js/core.js',
        '/affiliate_network/js/api.js',
        '/affiliate_network/js/render.js',
        '/affiliate_network/app.js',
    ],
];
