<?php

if (!function_exists('pos_security_bootstrap_session')) {
    function pos_security_bootstrap_session(): void
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }
}

if (!function_exists('pos_security_is_authenticated')) {
    function pos_security_is_authenticated(): bool
    {
        return !empty($_SESSION['cajero']) || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true);
    }
}

if (!function_exists('pos_security_client_ip_fragment')) {
    function pos_security_client_ip_fragment(): string
    {
        // CRITICAL: debe coincidir EXACTAMENTE con pos_client_ip_fragment() en pos.php
        // y poscash_client_ip_fragment() en pos_cash.php — distinto algoritmo provoca
        // "Sesion invalida. Vuelva a autenticarse." al cobrar.
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

if (!function_exists('pos_security_session_fingerprint')) {
    function pos_security_session_fingerprint(): string
    {
        // CRITICAL: debe coincidir EXACTAMENTE con pos_session_fingerprint() en pos.php
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180);
        return hash('sha256', pos_security_client_ip_fragment() . '|' . $ua);
    }
}

if (!function_exists('pos_security_ensure_csrf_token')) {
    function pos_security_ensure_csrf_token(): string
    {
        if (empty($_SESSION['pos_csrf_token']) || !is_string($_SESSION['pos_csrf_token'])) {
            $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['pos_csrf_token'];
    }
}

if (!function_exists('pos_security_enforce_session')) {
    function pos_security_enforce_session(bool $optionalAuth = true): void
    {
        if (!pos_security_is_authenticated()) {
            if ($optionalAuth) {
                pos_security_ensure_csrf_token();
                return;
            }
            pos_security_error('Sesion requerida.', 401);
        }

        $expected = (string)($_SESSION['pos_session_fingerprint'] ?? '');
        $current = pos_security_session_fingerprint();

        if ($expected !== '' && !hash_equals($expected, $current)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
                session_destroy();
            }
            pos_security_error('Sesion invalida. Vuelva a autenticarse.', 403);
        }

        $_SESSION['pos_session_fingerprint'] = $current;

        $lastRegeneratedAt = (int)($_SESSION['pos_session_regenerated_at'] ?? 0);
        if ($lastRegeneratedAt <= 0 || (time() - $lastRegeneratedAt) > 1800) {
            // false: no destruir la sesión antigua para evitar matar requests paralelos
            session_regenerate_id(false);
            $_SESSION['pos_session_regenerated_at'] = time();
            $_SESSION['pos_session_fingerprint'] = $current;
        }

        pos_security_ensure_csrf_token();
    }
}

if (!function_exists('pos_security_json_input')) {
    function pos_security_json_input(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('pos_security_error')) {
    function pos_security_error(string $message, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'msg' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('pos_security_require_auth')) {
    function pos_security_require_auth(): void
    {
        if (!pos_security_is_authenticated()) {
            pos_security_error('Sesion requerida.', 401);
        }
    }
}

if (!function_exists('pos_security_require_csrf')) {
    function pos_security_require_csrf(array $input = []): void
    {
        $sessionToken = (string)($_SESSION['pos_csrf_token'] ?? '');
        $providedToken = '';

        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $providedToken = trim($_SERVER['HTTP_X_CSRF_TOKEN']);
        }
        if ($providedToken === '' && isset($input['csrf_token']) && is_string($input['csrf_token'])) {
            $providedToken = trim($input['csrf_token']);
        }

        if ($sessionToken === '' || $providedToken === '' || !hash_equals($sessionToken, $providedToken)) {
            pos_security_error('Token CSRF invalido.', 403);
        }
    }
}

if (!function_exists('pos_security_clean_text')) {
    function pos_security_clean_text($value, int $maxLen = 255): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return mb_substr($value, 0, $maxLen, 'UTF-8');
    }
}

if (!function_exists('pos_security_clean_code')) {
    function pos_security_clean_code($value, int $maxLen = 64): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $pattern = '/^[A-Za-z0-9_.\-\/]{1,' . max(1, min($maxLen, 120)) . '}$/';
        return preg_match($pattern, $value) ? $value : '';
    }
}

