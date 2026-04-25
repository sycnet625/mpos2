<?php
// Helper único de sesión para todo el POS.
// Tener UNA fuente de verdad evita que cada archivo derive su propio
// algoritmo de fingerprint y rompa la sesión cuando los hashes no coinciden.
//
// Usado por: pos.php, pos_cash.php, pos_security.php, pin_auth.php.

if (!function_exists('pos_canon_client_ip_fragment')) {
    function pos_canon_client_ip_fragment(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4));
        }
        return $ip;
    }
}

if (!function_exists('pos_canon_session_fingerprint')) {
    function pos_canon_session_fingerprint(): string
    {
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180);
        return hash('sha256', pos_canon_client_ip_fragment() . '|' . $ua);
    }
}
